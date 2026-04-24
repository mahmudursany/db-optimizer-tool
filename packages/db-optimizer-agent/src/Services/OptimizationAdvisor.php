<?php

namespace Mdj\DbOptimizer\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;

class OptimizationAdvisor
{
    /**
     * @param  array<string, mixed>  $metric
     * @return array<int, array<string, mixed>>
     */
    public function recommend(QueryExecuted $event, array $metric): array
    {
        if (! (bool) config('db_optimizer.advanced_suggestions', true)) {
            return [];
        }

        $recommendations = [];
        $sql = (string) ($metric['raw_sql'] ?? $event->sql);
        $normalizedSql = $this->normalizeSql($event->sql);
        $sourceCurrent = trim((string) Arr::get($metric, 'source_code.current', ''));
        $currentLaravel = $sourceCurrent !== '' ? $sourceCurrent : $this->buildLaravelBuilderFromSql($sql);
        $sourceHasNPlusOnePattern = $this->sourceLooksLikeNPlusOne($currentLaravel);

        if (str_starts_with($normalizedSql, 'select') && str_contains($normalizedSql, 'select *') && ! $sourceHasNPlusOnePattern) {
            $optimized = preg_replace('/\bselect\s+\*/i', 'SELECT id /* add required columns */', $sql) ?? $sql;
            $recommendations[] = $this->make(
                'Select only needed columns',
                'Avoid SELECT * to reduce IO and memory.',
                $sql,
                $optimized,
                92,
                true,
                currentLaravel: $currentLaravel,
                optimizedLaravel: $this->optimizeSelectAllLaravel($currentLaravel),
            );
        }

        if ((bool) preg_match('/\bexists\s*\(\s*select\s+\*/i', $normalizedSql)) {
            $optimized = preg_replace('/\bexists\s*\(\s*select\s+\*/i', 'EXISTS (SELECT 1', $sql) ?? $sql;
            $recommendations[] = $this->make(
                'Use SELECT 1 inside EXISTS',
                'EXISTS checks row presence only; selecting columns is unnecessary.',
                $sql,
                $optimized,
                86,
                true,
                currentLaravel: $currentLaravel,
                optimizedLaravel: str_contains($currentLaravel, '->exists()')
                    ? $currentLaravel
                    : "// Prefer exists() for presence checks\n".$currentLaravel,
            );
        }

        if (str_starts_with($normalizedSql, 'select count(')) {
            $optimized = $this->rewriteCountToExists($sql);
            $recommendations[] = $this->make(
                'Use EXISTS instead of COUNT when checking presence',
                'For boolean checks, EXISTS can stop at first match and is usually cheaper.',
                $sql,
                $optimized,
                88,
                true,
                currentLaravel: $currentLaravel,
                optimizedLaravel: $this->rewriteLaravelCountToExists($currentLaravel),
            );
        }

        if ((bool) Arr::get($metric, 'detectors.n_plus_one.is_suspected', false) || $sourceHasNPlusOnePattern) {
            $optimizedNPlusOne = $this->rewriteNPlusOneLaravel($currentLaravel);

            $recommendations[] = $this->make(
                'Resolve N+1 via eager loading',
                'Repeated single-row relation queries detected.',
                '-- repeated relation query pattern --',
                "Model::query()->with(['relationName'])->get();",
                120,
                false,
                currentLaravel: $currentLaravel,
                optimizedLaravel: $optimizedNPlusOne,
            );
        }

        if (! empty(Arr::get($metric, 'detectors.missing_indexes', []))) {
            $first = Arr::first((array) Arr::get($metric, 'detectors.missing_indexes', []));
            $table = is_array($first) ? (string) ($first['table'] ?? 'table_name') : 'table_name';
            $column = is_array($first) ? (string) ($first['column'] ?? 'column_name') : 'column_name';

            if ($table !== '' && $column !== '' && strtolower($column) !== 'id') {
                $idxName = $this->safeIndexName($table, $column);
                $guardedSql = $this->buildGuardedIndexSql($table, $column, $idxName);

                $recommendations[] = $this->make(
                    'Add index for filter/join column',
                    'Missing leading index detected for a WHERE/JOIN column. Run the guarded SQL below; it will skip safely if an index already exists.',
                    $sql,
                    "ALTER TABLE `{$table}` ADD INDEX `{$idxName}` (`{$column}`);",
                    95,
                    false,
                    executableSql: $guardedSql,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: "// Keep application query as-is, add index in MySQL\n// Run the 'Executable SQL (Safe/Guarded)' block below.",
                );
            }
        }

        if ((bool) Arr::get($metric, 'detectors.cache_candidate.is_candidate', false)) {
            $recommendations[] = $this->make(
                'Cache repeated read query',
                'Static repeated SELECT detected with identical bindings.',
                $sql,
                "Cache::remember('db-opt-key', 300, fn () => DB::select(\"{$this->escapeForPhpString($event->sql)}\"));",
                84,
                true,
                currentLaravel: $currentLaravel,
                optimizedLaravel: "Cache::remember('db-opt-key', 300, fn () =>\n    {$currentLaravel}\n);",
            );
        }

        if (str_contains($normalizedSql, ' offset ')) {
            $recommendations[] = $this->make(
                'Prefer keyset pagination over OFFSET',
                'Large OFFSET values degrade performance on big datasets.',
                $sql,
                'SELECT ... WHERE id < :last_seen_id ORDER BY id DESC LIMIT 12',
                76,
                false,
                currentLaravel: $currentLaravel,
                optimizedLaravel: "// Keyset pagination example\nDB::table('table_name')\n    ->where('id', '<', \$lastSeenId)\n    ->orderByDesc('id')\n    ->limit(12)\n    ->get();",
            );
        }

        if (isset($metric['explain'])) {
            $recommendations[] = $this->make(
                'Tune query based on EXPLAIN',
                'Slow query plan indicates scan/sort bottlenecks.',
                $sql,
                '-- add/selective indexes on WHERE + JOIN columns; avoid filesort/temporary --',
                93,
                false,
                currentLaravel: $currentLaravel,
                optimizedLaravel: null,
            );
        }

        foreach ($recommendations as &$recommendation) {
            if ((bool) config('db_optimizer.auto_apply_safe_optimizations', false) && ($recommendation['safe_auto_apply'] ?? false)) {
                $recommendation['auto_apply_eligible'] = true;
                $recommendation['auto_applied'] = false;
            }
        }
        unset($recommendation);

        usort($recommendations, static fn (array $a, array $b): int => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));

        return array_slice($recommendations, 0, max(1, (int) config('db_optimizer.recommendation_limit', 8)));
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($sql))) ?? strtolower(trim($sql));
    }

    private function rewriteCountToExists(string $sql): string
    {
        $normalized = strtolower($sql);
        $fromPos = strpos($normalized, ' from ');

        if ($fromPos === false) {
            return 'SELECT EXISTS(SELECT 1 FROM your_table WHERE ...) AS exists_flag';
        }

        $tail = substr($sql, $fromPos + 6);

        return 'SELECT EXISTS(SELECT 1 FROM '.$tail.' LIMIT 1) AS exists_flag';
    }

    /**
     * @return array<string, mixed>
     */
    private function make(
        string $title,
        string $description,
        string $currentSql,
        string $optimizedSql,
        int $priority,
        bool $safeAutoApply,
        ?string $executableSql = null,
        ?string $currentLaravel = null,
        ?string $optimizedLaravel = null,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'current_sql' => $currentSql,
            'optimized_sql' => $optimizedSql,
            'executable_sql' => $executableSql,
            'current_laravel' => $currentLaravel,
            'optimized_laravel' => $optimizedLaravel,
            'priority' => $priority,
            'safe_auto_apply' => $safeAutoApply,
            'auto_apply_eligible' => false,
        ];
    }

    private function safeIndexName(string $table, string $column): string
    {
        return 'idx_'.preg_replace('/[^a-zA-Z0-9_]+/', '_', $table.'_'.$column);
    }

    private function buildGuardedIndexSql(string $table, string $column, string $indexName): string
    {
        $tableEsc = str_replace("'", "''", $table);
        $columnEsc = str_replace("'", "''", $column);

        return <<<SQL
-- Safe to run multiple times on MySQL
SET @dbopt_idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = '{$tableEsc}'
      AND column_name = '{$columnEsc}'
      AND seq_in_index = 1
);

SET @dbopt_stmt := IF(
    @dbopt_idx_exists = 0,
    'ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`{$column}`)',
    'SELECT "skip: index already exists for {$table}.{$column}"'
);

PREPARE dbopt_query FROM @dbopt_stmt;
EXECUTE dbopt_query;
DEALLOCATE PREPARE dbopt_query;
SQL;
    }

    private function optimizeSelectAllLaravel(string $currentLaravel): string
    {
        if ($currentLaravel === '') {
            return $currentLaravel;
        }

        if (str_contains($currentLaravel, "->select('*')")) {
            return str_replace("->select('*')", "->select(['id']) // add required columns", $currentLaravel);
        }

        if (! str_contains($currentLaravel, '->select(')) {
            if (str_contains($currentLaravel, '->first()')) {
                return str_replace('->first()', "->select(['id']) // add required columns\n    ->first()", $currentLaravel);
            }

            if (str_contains($currentLaravel, '->paginate(')) {
                return preg_replace('/->paginate\(([^\)]*)\)/', "->select(['id']) // add required columns\n    ->paginate($1)", $currentLaravel) ?? $currentLaravel;
            }

            if (str_contains($currentLaravel, '->get()')) {
                return str_replace('->get()', "->select(['id']) // add required columns\n    ->get()", $currentLaravel);
            }
        }

        return $currentLaravel;
    }

    private function rewriteLaravelCountToExists(string $currentLaravel): string
    {
        if ($currentLaravel === '') {
            return $currentLaravel;
        }

        if (str_contains($currentLaravel, '->count()')) {
            return str_replace('->count();', '->exists();', $currentLaravel);
        }

        return "// Use exists() if checking only presence\n".$currentLaravel;
    }

    private function rewriteNPlusOneLaravel(string $currentLaravel): string
    {
        if ($currentLaravel === '') {
            return "// N+1 hint\nModel::query()->with(['relationName'])->get();";
        }

        $lines = preg_split('/\R/', $currentLaravel) ?: [];

        if ($lines === []) {
            return "// N+1 hint\nModel::query()->with(['relationName'])->get();";
        }

        $relationsByVar = [];
        $foreachItemToCollection = [];
        $removeIndexes = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*foreach\s*\(\s*\$(\w+)\s+as\s+\$(\w+)\s*\)/', $line, $m)) {
                $collectionVar = $m[1];
                $itemVar = $m[2];
                $foreachItemToCollection[$itemVar] = $collectionVar;
            }
        }

        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*\$(\w+)->([a-zA-Z_][\w]*)\s*;\s*(?:\/\/.*)?$/', $line, $m)) {
                $sourceVar = $m[1];
                $relation = $m[2];

                $var = $foreachItemToCollection[$sourceVar] ?? $sourceVar;

                if (! isset($relationsByVar[$var])) {
                    $relationsByVar[$var] = [];
                }

                if (! in_array($relation, $relationsByVar[$var], true)) {
                    $relationsByVar[$var][] = $relation;
                }

                $removeIndexes[] = $i;
            }
        }

        if ($relationsByVar === []) {
            if (str_contains($currentLaravel, '->get()') && ! str_contains($currentLaravel, '->with(')) {
                return str_replace('->get()', "->with(['relationName'])->get()", $currentLaravel);
            }

            return "// Add eager loading in the base query\n".$currentLaravel;
        }

        $filtered = [];

        foreach ($lines as $i => $line) {
            if (! in_array($i, $removeIndexes, true)) {
                $filtered[] = $line;
            }
        }

        foreach ($relationsByVar as $var => $relations) {
            $filtered = $this->injectWithForVariable($filtered, (string) $var, (array) $relations);
        }

        return implode("\n", $filtered);
    }

    private function sourceLooksLikeNPlusOne(string $source): bool
    {
        if ($source === '') {
            return false;
        }

        $hasForeach = (bool) preg_match('/\bforeach\s*\(/', $source);
        $lazyCalls = preg_match_all('/^\s*\$\w+->[a-zA-Z_][\w]*\s*;\s*(?:\/\/.*)?$/m', $source);

        if ($hasForeach && $lazyCalls >= 1) {
            return true;
        }

        return (bool) preg_match('/^\s*\$\w+->[a-zA-Z_][\w]*\s*;\s*(?:\/\/.*)?$/m', $source);
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $relations
     * @return array<int, string>
     */
    private function injectWithForVariable(array $lines, string $var, array $relations): array
    {
        if ($relations === []) {
            return $lines;
        }

        $withCode = count($relations) === 1
            ? "->with('{$relations[0]}')"
            : "->with(['".implode("', '", $relations)."'])";

        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (! preg_match('/\$'.preg_quote($var, '/').'\s*=\s*/', $lines[$i])) {
                continue;
            }

            $start = $i;
            $end = $i;

            while ($end < $count - 1 && ! str_contains($lines[$end], ';')) {
                $end++;
            }

            $block = implode("\n", array_slice($lines, $start, $end - $start + 1));

            if (str_contains($block, '->with(')) {
                continue;
            }

            if ($start === $end) {
                $updated = preg_replace('/->(first|get|paginate\s*\([^;]*\))\s*;/', $withCode.'->$1;', $lines[$start]);

                if (is_string($updated) && $updated !== $lines[$start]) {
                    $lines[$start] = $updated;
                }

                continue;
            }

            for ($j = $start; $j <= $end; $j++) {
                if (preg_match('/->(first|get|paginate\s*\([^\)]*\))\s*;/', $lines[$j])) {
                    preg_match('/^(\s*)/', $lines[$j], $indentMatch);
                    $indent = $indentMatch[1] ?? '    ';
                    array_splice($lines, $j, 0, [$indent.$withCode]);
                    $count++;
                    $end++;
                    break;
                }
            }
        }

        return $lines;
    }

    private function buildLaravelBuilderFromSql(string $sql): string
    {
        $flatSql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

        if (! str_starts_with(strtolower($flatSql), 'select ')) {
            return 'DB::statement("'.addslashes($flatSql).'");';
        }

        if (! preg_match('/select\s+(.+?)\s+from\s+`?([a-zA-Z_][\w]*)`?/i', $flatSql, $selectAndFrom)) {
            return 'DB::select("'.addslashes($flatSql).'");';
        }

        $selectRaw = trim($selectAndFrom[1] ?? '*');
        $table = $selectAndFrom[2] ?? 'table_name';
        $builder = ["DB::table('{$table}')"];

        $joins = [];
        preg_match_all('/\bjoin\s+`?([a-zA-Z_][\w]*)`?\s+on\s+([^\s]+)\s*=\s*([^\s]+)(?:\s|$)/i', $flatSql, $joinMatches, PREG_SET_ORDER);
        foreach ($joinMatches as $join) {
            $joinTable = $join[1] ?? null;
            $left = isset($join[2]) ? trim($join[2], '` ') : null;
            $right = isset($join[3]) ? trim($join[3], '` ') : null;

            if ($joinTable && $left && $right) {
                $joins[] = "    ->join('{$joinTable}', '{$left}', '=', '{$right}')";
            }
        }

        $whereChains = [];
        if (preg_match('/\bwhere\b\s+(.+?)(?:\border\s+by\b|\blimit\b|\boffset\b|$)/i', $flatSql, $wherePart)) {
            $whereExpr = trim($wherePart[1] ?? '');
            $conditions = preg_split('/\s+and\s+/i', $whereExpr) ?: [];

            foreach ($conditions as $condition) {
                if (preg_match('/`?([a-zA-Z_][\w\.]+)`?\s*(=|>=|<=|>|<|!=|<>)\s*(.+)$/i', trim($condition), $c)) {
                    $column = trim($c[1], '` ');
                    $operator = $c[2] ?? '=';
                    $value = trim($c[3] ?? '?', ' ');
                    $whereChains[] = "    ->where('{$column}', '{$operator}', {$this->toPhpLiteral($value)})";
                }
            }
        }

        if (! empty($joins)) {
            $builder = array_merge($builder, $joins);
        }

        if (! empty($whereChains)) {
            $builder = array_merge($builder, $whereChains);
        }

        if (preg_match('/\border\s+by\s+`?([a-zA-Z_][\w\.]*)`?\s*(asc|desc)?/i', $flatSql, $order)) {
            $orderBy = trim($order[1] ?? '', '` ');
            $direction = strtolower($order[2] ?? 'asc');
            $builder[] = $direction === 'desc'
                ? "    ->orderByDesc('{$orderBy}')"
                : "    ->orderBy('{$orderBy}')";
        }

        if (str_contains(strtolower($flatSql), ' distinct')) {
            $builder[] = '    ->distinct()';
        }

        if ($selectRaw === '*') {
            $builder[] = "    ->select('*')";
        } else {
            $columns = array_map(static function (string $column): string {
                return trim(trim($column), '`');
            }, explode(',', $selectRaw));
            $columns = array_values(array_filter($columns, static fn (string $c): bool => $c !== ''));
            $columnPhp = implode(', ', array_map(static fn (string $c): string => "'{$c}'", $columns));
            $builder[] = "    ->select([{$columnPhp}])";
        }

        if (preg_match('/\blimit\s+(\d+)/i', $flatSql, $limit)) {
            $builder[] = '    ->limit('.(int) ($limit[1] ?? 0).')';
        }

        return implode("\n", $builder)."\n    ->get();";
    }

    private function toPhpLiteral(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '?') {
            return '$value';
        }

        if (is_numeric($trimmed)) {
            return $trimmed;
        }

        $trimmed = trim($trimmed, "'\"");

        if (strtolower($trimmed) === 'null') {
            return 'null';
        }

        return "'".addslashes($trimmed)."'";
    }

    private function escapeForPhpString(string $sql): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $sql);
    }
}

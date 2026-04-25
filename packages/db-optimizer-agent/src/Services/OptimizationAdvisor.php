<?php

namespace Mdj\DbOptimizer\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;

class OptimizationAdvisor
{
    public function __construct(
        private readonly SourceCodeOptimizer $codeOptimizer,
    ) {}

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
        $rawSql          = (string) ($metric['raw_sql'] ?? $event->sql);
        $normalizedSql   = $this->normalizeSql($event->sql);
        $sourceCurrent   = trim((string) Arr::get($metric, 'source_code.current', ''));

        // ── N+1 detection ──────────────────────────────────────────────────────
        $nPlusOneDetected = (bool) Arr::get($metric, 'detectors.n_plus_one.is_suspected', false);
        $repetition       = (int)  Arr::get($metric, 'detectors.n_plus_one.repetition', 1);

        if ($nPlusOneDetected) {
            $recommendations[] = $this->buildNPlusOneRecommendation(
                $event->sql, $rawSql, $sourceCurrent, $repetition, $metric
            );
        }

        // Skip other checks for N+1 repeated queries (they are noise)
        if (! $nPlusOneDetected) {
            $currentLaravel = $sourceCurrent !== ''
                ? $sourceCurrent
                : $this->buildLaravelBuilderFromSql($rawSql);

            // ── SELECT * ──────────────────────────────────────────────────────
            if (str_starts_with($normalizedSql, 'select') && str_contains($normalizedSql, 'select *')) {
                [$optimizedLaravel, $changed] = $this->codeOptimizer->rewrite($currentLaravel);

                // If SourceCodeOptimizer rewrote the function (N+1 fix + select hint), use that.
                // Otherwise fall back to a simple select column hint.
                if (! $changed) {
                    $optimizedLaravel = $this->optimizeSelectAllLaravel($currentLaravel);
                }

                $optimizedSql = preg_replace('/\bselect\s+\*/i', 'SELECT id, name /* add required columns */', $rawSql) ?? $rawSql;

                $recommendations[] = $this->make(
                    'Select only needed columns',
                    'Avoid SELECT * — specify only the columns your view actually uses to reduce IO and memory.',
                    $rawSql,
                    $optimizedSql,
                    92,
                    true,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: $optimizedLaravel,
                );
            }

            // ── EXISTS (SELECT *) ──────────────────────────────────────────────
            if ((bool) preg_match('/\bexists\s*\(\s*select\s+\*/i', $normalizedSql)) {
                $optimized = preg_replace('/\bexists\s*\(\s*select\s+\*/i', 'EXISTS (SELECT 1', $rawSql) ?? $rawSql;
                $recommendations[] = $this->make(
                    'Use SELECT 1 inside EXISTS',
                    'EXISTS checks row presence only; selecting columns wastes resources.',
                    $rawSql,
                    $optimized,
                    86,
                    true,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: str_contains($currentLaravel, '->exists()')
                        ? $currentLaravel
                        : "// Prefer ->exists() for presence checks\n" . $currentLaravel,
                );
            }

            // ── COUNT → EXISTS ─────────────────────────────────────────────────
            if (str_starts_with($normalizedSql, 'select count(')) {
                $recommendations[] = $this->make(
                    'Use EXISTS instead of COUNT when checking presence',
                    'For boolean checks, EXISTS stops at the first match and is faster than COUNT.',
                    $rawSql,
                    $this->rewriteCountToExists($rawSql),
                    88,
                    true,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: $this->rewriteLaravelCountToExists($currentLaravel),
                );
            }

            // Missing indexes recommendation removed as per user request

            // ── Cache candidate ────────────────────────────────────────────────
            if ((bool) Arr::get($metric, 'detectors.cache_candidate.is_candidate', false)) {
                $recommendations[] = $this->make(
                    'Cache repeated read query',
                    'This SELECT ran multiple times with identical bindings — a great cache candidate.',
                    $rawSql,
                    "Cache::remember('db-opt-key', 300, fn () => DB::select(\"{$this->escapeForPhpString($event->sql)}\"));",
                    84,
                    true,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: "Cache::remember('db-opt-key', 300, fn () =>\n    {$currentLaravel}\n);",
                );
            }

            // ── OFFSET pagination ──────────────────────────────────────────────
            if (str_contains($normalizedSql, ' offset ')) {
                $recommendations[] = $this->make(
                    'Prefer keyset pagination over OFFSET',
                    'Large OFFSET scans rows before discarding them. Use cursor/keyset pagination for big tables.',
                    $rawSql,
                    "SELECT ... WHERE id < :last_seen_id ORDER BY id DESC LIMIT :per_page",
                    76,
                    false,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: "// Keyset (cursor) pagination\n\$items = Model::where('id', '<', \$lastSeenId)\n    ->orderByDesc('id')\n    ->limit(15)\n    ->get();",
                );
            }

            // ── Slow query (EXPLAIN) ───────────────────────────────────────────
            if (isset($metric['explain'])) {
                $summary = (string) ($metric['explain']['summary'] ?? '');
                $recommendations[] = $this->make(
                    'Tune query based on EXPLAIN',
                    "Slow query plan detected. {$summary}",
                    $rawSql,
                    '-- Add indexes on WHERE + JOIN columns; avoid filesort and temporary tables.',
                    93,
                    false,
                    currentLaravel: $currentLaravel,
                    optimizedLaravel: null,
                );
            }
        }

        // Sort by priority
        usort($recommendations, static fn ($a, $b) => ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0)));

        return array_slice($recommendations, 0, max(1, (int) config('db_optimizer.recommendation_limit', 8)));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // N+1 recommendation builder
    // ──────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $metric */
    private function buildNPlusOneRecommendation(
        string $sql,
        string $rawSql,
        string $sourceCurrent,
        int $repetition,
        array $metric,
    ): array {
        $table    = $this->extractTableFromSql($sql);
        $relation = $this->guessRelationName($sql, $table);
        $idColumn = $this->extractWhereIdColumn($sql);

        // ── If we have source code, do a FULL intelligent rewrite ─────────────
        if ($sourceCurrent !== '') {
            [$rewritten, $changed] = $this->codeOptimizer->rewrite($sourceCurrent);

            $optimizedLaravel = $changed
                ? "// ✅ Optimized — all lazy relations eager-loaded, N+1 eliminated\n{$rewritten}"
                : $this->buildNPlusOneOptimizedLaravel('', $table, $relation);
        } else {
            $optimizedLaravel = $this->buildNPlusOneOptimizedLaravel('', $table, $relation);
        }

        $currentLaravel = $sourceCurrent !== ''
            ? $sourceCurrent
            : $this->buildNPlusOneCurrentLaravel($table, $relation, $idColumn, $repetition);

        $description = sprintf(
            'Query ran %d× in this request — classic N+1. '
            . 'Each iteration fires a new SELECT instead of loading all related `%s` records at once.',
            $repetition,
            $table ?: 'related',
        );

        return $this->make(
            'Resolve N+1 via eager loading',
            $description,
            $rawSql,
            $this->buildNPlusOneOptimizedSql($sql, $table, $idColumn),
            120,
            false,
            currentLaravel: $currentLaravel,
            optimizedLaravel: $optimizedLaravel,
        );
    }

    private function buildNPlusOneCurrentLaravel(string $table, string $relation, string $idColumn, int $repetition): string
    {
        $parent = 'Post';

        return <<<PHP
// ⚠ N+1 detected — this query ran {$repetition}× in one request
\$items = {$parent}::all(); // loads N parent records

foreach (\$items as \$item) {
    \$item->{$relation}; // ← fires a new SELECT per iteration!
    // Actual query: SELECT * FROM `{$table}` WHERE `{$idColumn}` = ?
}
PHP;
    }

    private function buildNPlusOneOptimizedLaravel(string $sourceCurrent, string $table, string $relation): string
    {
        $parent = 'Post';

        return <<<PHP
// ✅ Optimized — 1 query instead of N
\$items = {$parent}::with('{$relation}')->get();

foreach (\$items as \$item) {
    \$item->{$relation}; // already loaded — zero extra queries
}
PHP;
    }

    private function buildNPlusOneOptimizedSql(string $sql, string $table, string $idColumn): string
    {
        $parentTable = $this->guessParentTable($idColumn);

        if ($parentTable && $table) {
            return "-- Replace N repeated queries with ONE\n"
                . "SELECT `{$table}`.*\n"
                . "FROM `{$table}`\n"
                . "WHERE `{$idColumn}` IN (/* parent IDs from first query */);";
        }

        return "SELECT * FROM `{$table}` WHERE `{$idColumn}` IN (/* parent IDs */);";
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SQL analysis helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function extractTableFromSql(string $sql): string
    {
        return preg_match('/\bfrom\s+`?([a-zA-Z_][\w]*)`?/i', $sql, $m) ? $m[1] : '';
    }

    private function extractWhereIdColumn(string $sql): string
    {
        if (preg_match('/\bwhere\b.+?`?([a-zA-Z_][\w]*(?:_id|id))`?\s*=\s*\?/i', $sql, $m)) {
            return $m[1];
        }

        return 'id';
    }

    private function guessRelationName(string $sql, string $table): string
    {
        $idCol = $this->extractWhereIdColumn($sql);

        if ($idCol !== 'id' && str_ends_with(strtolower($idCol), '_id')) {
            return substr($idCol, 0, -3);
        }

        return $table ? rtrim($table, 's') : 'relation';
    }

    private function guessParentTable(string $idColumn): string
    {
        if (str_ends_with(strtolower($idColumn), '_id')) {
            return substr($idColumn, 0, -3) . 's';
        }

        return '';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Laravel builder helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function optimizeSelectAllLaravel(string $src): string
    {
        if ($src === '') {
            return $src;
        }

        foreach (["->select('*')", '->get()', '->first()', '->paginate('] as $terminal) {
            if (str_contains($src, $terminal)) {
                $hint = "->select(['id' /* add required columns */])";

                return str_replace(
                    $terminal,
                    $terminal === "->select('*')" ? "->select(['id' /* add columns */])" : "{$hint}\n    {$terminal}",
                    $src,
                );
            }
        }

        return $src;
    }

    private function rewriteLaravelCountToExists(string $src): string
    {
        if (str_contains($src, '->count()')) {
            return str_replace('->count();', '->exists();', $src);
        }

        return "// Use ->exists() for presence checks\n" . $src;
    }

    private function rewriteCountToExists(string $sql): string
    {
        $fromPos = strpos(strtolower($sql), ' from ');

        if ($fromPos === false) {
            return 'SELECT EXISTS(SELECT 1 FROM your_table WHERE ...) AS `exists_flag`';
        }

        return 'SELECT EXISTS(SELECT 1 FROM ' . substr($sql, $fromPos + 6) . ' LIMIT 1) AS `exists_flag`';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SQL → Laravel builder (fallback when no source code available)
    // ──────────────────────────────────────────────────────────────────────────

    private function buildLaravelBuilderFromSql(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

        if (! str_starts_with(strtolower($flat), 'select ')) {
            return 'DB::statement("' . addslashes($flat) . '");';
        }

        if (! preg_match('/select\s+(.+?)\s+from\s+`?([a-zA-Z_][\w]*)`?/i', $flat, $m)) {
            return 'DB::select("' . addslashes($flat) . '");';
        }

        $selectRaw = trim($m[1] ?? '*');
        $table     = $m[2] ?? 'table_name';
        $lines     = ["DB::table('{$table}')"];

        // JOINs
        preg_match_all('/\bjoin\s+`?([a-zA-Z_][\w]*)`?\s+on\s+([^\s]+)\s*=\s*([^\s]+)/i', $flat, $joins, PREG_SET_ORDER);
        foreach ($joins as $j) {
            $lines[] = "    ->join('{$j[1]}', '" . trim($j[2], '` ') . "', '=', '" . trim($j[3], '` ') . "')";
        }

        // WHEREs
        if (preg_match('/\bwhere\b\s+(.+?)(?:\border\s+by\b|\blimit\b|\boffset\b|$)/i', $flat, $wp)) {
            foreach (preg_split('/\s+and\s+/i', trim($wp[1])) ?: [] as $cond) {
                if (preg_match('/`?([a-zA-Z_][\w\.]+)`?\s*(=|>=|<=|>|<|!=)\s*(.+)$/i', trim($cond), $c)) {
                    $lines[] = "    ->where('" . trim($c[1], '` ') . "', '{$c[2]}', " . $this->toPhpLiteral(trim($c[3])) . ")";
                }
            }
        }

        // ORDER BY
        if (preg_match('/\border\s+by\s+`?([a-zA-Z_][\w\.]*)`?\s*(asc|desc)?/i', $flat, $o)) {
            $dir = strtolower($o[2] ?? 'asc');
            $lines[] = $dir === 'desc' ? "    ->orderByDesc('" . trim($o[1], '` ') . "')" : "    ->orderBy('" . trim($o[1], '` ') . "')";
        }

        // SELECT columns
        if ($selectRaw === '*') {
            $lines[] = "    ->select('*')";
        } else {
            $cols    = array_filter(array_map(static fn ($c) => trim(trim($c), '`'), explode(',', $selectRaw)));
            $colsPhp = implode(', ', array_map(static fn ($c) => "'{$c}'", $cols));
            $lines[] = "    ->select([{$colsPhp}])";
        }

        // LIMIT
        if (preg_match('/\blimit\s+(\d+)/i', $flat, $lm)) {
            $lines[] = '    ->limit(' . (int) $lm[1] . ')';
        }

        return implode("\n", $lines) . "\n    ->get();";
    }

    private function toPhpLiteral(string $v): string
    {
        if ($v === '?') return '$value';
        if (is_numeric($v)) return $v;
        if (strtolower(trim($v, "'\"")) === 'null') return 'null';
        return "'" . addslashes(trim($v, "'\"")) . "'";
    }

    private function escapeForPhpString(string $sql): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $sql);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Index helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function safeIndexName(string $table, string $column): string
    {
        return 'idx_' . preg_replace('/[^a-zA-Z0-9_]+/', '_', $table . '_' . $column);
    }

    private function buildGuardedIndexSql(string $table, string $column, string $indexName): string
    {
        $te = str_replace("'", "''", $table);
        $ce = str_replace("'", "''", $column);

        return <<<SQL
-- Safe to run multiple times
SET @dbopt_idx_exists := (
    SELECT COUNT(1) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = '{$te}'
      AND column_name  = '{$ce}'
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

    // ──────────────────────────────────────────────────────────────────────────
    // Shared
    // ──────────────────────────────────────────────────────────────────────────

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($sql))) ?? strtolower(trim($sql));
    }

    /** @return array<string, mixed> */
    private function make(
        string $title,
        string $description,
        string $currentSql,
        string $optimizedSql,
        int $priority,
        bool $safeAutoApply,
        ?string $executableSql   = null,
        ?string $currentLaravel  = null,
        ?string $optimizedLaravel = null,
    ): array {
        return [
            'title'               => $title,
            'description'         => $description,
            'current_sql'         => $currentSql,
            'optimized_sql'       => $optimizedSql,
            'executable_sql'      => $executableSql,
            'current_laravel'     => $currentLaravel,
            'optimized_laravel'   => $optimizedLaravel,
            'priority'            => $priority,
            'safe_auto_apply'     => $safeAutoApply,
            'auto_apply_eligible' => false,
        ];
    }
}

<?php

namespace Mdj\DbOptimizer\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Mdj\DbOptimizer\Support\QueryOriginResolver;
use Throwable;

class QueryInterceptor
{
    private bool $runningExplain = false;

    /** @var array<string, int> */
    private array $fingerprintCounters = [];

    /** @var array<string, int> */
    private array $exactQueryCounters = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $indexesByTable = [];

    public function __construct(
        private readonly QueryOriginResolver $originResolver,
        private readonly QueryMetricsStore $metricsStore,
        private readonly OptimizationAdvisor $advisor,
    ) {
    }

    public function capture(QueryExecuted $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($this->shouldIgnoreSql($event->sql)) {
            return;
        }

        $sampleRate = (float) config('db_optimizer.sample_rate', 1.0);

        if ($sampleRate < 1 && mt_rand() / mt_getrandmax() > $sampleRate) {
            return;
        }

        $normalizedSql = $this->normalizeSql($event->sql);
        $fingerprint = md5($normalizedSql);
        $fingerprintCount = $this->incrementCounter($this->fingerprintCounters, $fingerprint);

        $exactSignature = md5($event->sql.'|'.serialize($event->bindings));
        $exactCount = $this->incrementCounter($this->exactQueryCounters, $exactSignature);

        $origin = $this->originResolver->resolve();
        $sourceCode = $this->extractSourceCodeSnippet(
            is_string($origin['file'] ?? null) ? $origin['file'] : null,
            isset($origin['line']) ? (int) $origin['line'] : null,
        );

        $metric = [
            'connection' => $event->connectionName,
            'database' => method_exists($event->connection, 'getDatabaseName') ? $event->connection->getDatabaseName() : null,
            'sql' => $event->sql,
            'bindings' => $event->bindings,
            'time_ms' => round($event->time, 3),
            'raw_sql' => $this->toRawSql($event->sql, $event->bindings),
            'fingerprint' => $fingerprint,
            'origin' => $origin,
            'source_code' => [
                'current' => $sourceCode,
            ],
            'detectors' => [
                'n_plus_one' => $this->nPlusOneSignal($event->sql, $fingerprintCount, $origin),
                'missing_indexes' => $this->suggestMissingIndexes($event->sql, $event->connectionName),
                'cache_candidate' => $this->cacheSignal($event->sql, $exactCount),
            ],
        ];

        if ((float) $event->time >= (float) config('db_optimizer.slow_query_threshold_ms', 50)) {
            $metric['explain'] = $this->buildExplainSummary($event);
        }

        $metric['recommendations'] = $this->advisor->recommend($event, $metric);

        $this->metricsStore->record($metric);
    }

    public function flushRequestSnapshot(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->metricsStore->flushSnapshot([
            'url' => request()?->fullUrl(),
            'method' => request()?->method(),
            'route' => request()?->route()?->getName() ?? request()?->path(),
            'query_count' => $this->metricsStore->count(),
        ]);

        $this->fingerprintCounters = [];
        $this->exactQueryCounters = [];
    }

    private function isEnabled(): bool
    {
        if (! (bool) config('db_optimizer.enabled', false)) {
            return false;
        }

        if (app()->runningUnitTests() && ! (bool) config('db_optimizer.capture_testing', false)) {
            return false;
        }

        if (app()->runningInConsole() && ! (bool) config('db_optimizer.capture_console', false)) {
            return false;
        }

        return true;
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim(strtolower($sql))) ?? strtolower(trim($sql));
    }

    /**
     * @param  array<string, int>  $bucket
     */
    private function incrementCounter(array &$bucket, string $key): int
    {
        $bucket[$key] = ($bucket[$key] ?? 0) + 1;

        return $bucket[$key];
    }

    /**
     * @return array{is_suspected: bool, reason: string|null, repetition: int, origin_file: string|null, origin_line: int|null}
     */
    private function nPlusOneSignal(string $sql, int $repetition, array $origin): array
    {
        $threshold = (int) config('db_optimizer.n_plus_one_repeat_threshold', 5);
        $isSelect = str_starts_with(strtolower(ltrim($sql)), 'select');
        $hasWhereBinding = (bool) preg_match('/\bwhere\b.+\=\s*\?/i', $sql);
        $looksLikeRelationLoad = (bool) preg_match('/\bwhere\b.+(?:_id|id)\b\s*=\s*\?/i', $sql);
        $looksLikeSingleRow = $isSelect && $hasWhereBinding && $looksLikeRelationLoad;

        $suspected = $repetition >= $threshold && $looksLikeSingleRow;

        return [
            'is_suspected' => $suspected,
            'reason' => $suspected ? 'Repeated single-row fetch pattern detected. Consider eager loading with with().' : null,
            'repetition' => $repetition,
            'origin_file' => Arr::get($origin, 'file'),
            'origin_line' => Arr::get($origin, 'line'),
        ];
    }

    /**
     * @return array{is_candidate: bool, reason: string|null, repetition: int}
     */
    private function cacheSignal(string $sql, int $repetition): array
    {
        $threshold = (int) config('db_optimizer.cache_candidate_repeat_threshold', 8);
        $isSelect = str_starts_with(strtolower(ltrim($sql)), 'select');
        $isVolatile = (bool) preg_match('/\b(now\(|rand\(|uuid\(|current_timestamp)\b/i', $sql);

        $isCandidate = $isSelect && ! $isVolatile && $repetition >= $threshold;

        return [
            'is_candidate' => $isCandidate,
            'reason' => $isCandidate ? 'Query executed repeatedly with identical bindings. Consider Cache::remember().' : null,
            'repetition' => $repetition,
        ];
    }

    /**
     * @return array<int, array{table: string, column: string, reason: string}>
     */
    private function suggestMissingIndexes(string $sql, string $connectionName): array
    {
        $references = $this->extractColumnReferences($sql);

        if ($references === []) {
            return [];
        }

        $suggestions = [];

        foreach ($references as $reference) {
            $table = $reference['table'];
            $column = $reference['column'];

            if (! $table || ! $column) {
                continue;
            }

            if ($this->hasLeadingIndex($connectionName, $table, $column)) {
                continue;
            }

            $key = $table.'.'.$column;

            $suggestions[$key] = [
                'table' => $table,
                'column' => $column,
                'reason' => 'Column used in WHERE/JOIN without leading index.',
            ];
        }

        return array_values($suggestions);
    }

    /**
     * @return array<int, array{table: string|null, column: string|null}>
     */
    private function extractColumnReferences(string $sql): array
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        preg_match_all('/\b(from|join)\s+`?([a-zA-Z_][\w]*)`?(?:\s+(?:as\s+)?`?([a-zA-Z_][\w]*)`?)?/i', $sql, $tableMatches, PREG_SET_ORDER);

        $aliasToTable = [];
        $defaultTable = null;

        foreach ($tableMatches as $match) {
            $table = $match[2] ?? null;
            $alias = $match[3] ?? $table;

            if ($defaultTable === null && strtolower((string) ($match[1] ?? '')) === 'from') {
                $defaultTable = $table;
            }

            if ($table && $alias) {
                $aliasToTable[$alias] = $table;
            }
        }

        preg_match_all('/(?:`?([a-zA-Z_][\w]*)`?\.)?`?([a-zA-Z_][\w]*)`?\s*(?:>=|<=|=|>|<|like|in\s*\()/i', $sql, $columnMatches, PREG_SET_ORDER);

        $results = [];

        foreach ($columnMatches as $match) {
            $alias = $match[1] ?? null;
            $column = $match[2] ?? null;
            $table = $alias !== null ? ($aliasToTable[$alias] ?? $alias) : $defaultTable;

            if ($column === null) {
                continue;
            }

            if (in_array(strtolower($column), ['null', 'true', 'false'], true)) {
                continue;
            }

            $results[] = [
                'table' => $table,
                'column' => $column,
            ];
        }

        return $results;
    }

    private function hasLeadingIndex(string $connectionName, string $table, string $column): bool
    {
        $connectionKey = $connectionName ?: config('database.default');

        if (! isset($this->indexesByTable[$connectionKey][$table])) {
            $this->indexesByTable[$connectionKey][$table] = $this->fetchTableIndexes($connectionName, $table);
        }

        foreach ($this->indexesByTable[$connectionKey][$table] as $indexInfo) {
            if (($indexInfo['seq_in_index'] ?? null) === 1 && strcasecmp((string) ($indexInfo['column_name'] ?? ''), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchTableIndexes(string $connectionName, string $table): array
    {
        try {
            $connection = DB::connection($connectionName);

            if ($connection->getDriverName() !== 'mysql') {
                return [];
            }

            return array_map(
                static fn ($row) => (array) $row,
                $connection->select(
                    'SELECT index_name, column_name, seq_in_index FROM information_schema.statistics WHERE table_schema = database() AND table_name = ?',
                    [$table],
                )
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildExplainSummary(QueryExecuted $event): ?array
    {
        if ($this->runningExplain) {
            return null;
        }

        try {
            $connection = DB::connection($event->connectionName);

            if ($connection->getDriverName() !== 'mysql') {
                return null;
            }

            $this->runningExplain = true;
            $rows = $connection->select('EXPLAIN '.$event->sql, $event->bindings);
            $this->runningExplain = false;

            if ($rows === []) {
                return null;
            }

            $plans = array_map(static fn ($row) => (array) $row, $rows);

            return [
                'summary' => $this->humanizeExplain($plans),
                'raw' => $plans,
            ];
        } catch (Throwable $e) {
            $this->runningExplain = false;

            return [
                'summary' => 'EXPLAIN failed: '.$e->getMessage(),
                'raw' => [],
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $plans
     */
    private function humanizeExplain(array $plans): string
    {
        $notes = [];

        foreach ($plans as $plan) {
            $table = (string) ($plan['table'] ?? 'unknown');
            $type = strtolower((string) ($plan['type'] ?? 'unknown'));
            $rows = (int) ($plan['rows'] ?? 0);
            $key = $plan['key'] ?? null;
            $extra = strtolower((string) ($plan['Extra'] ?? $plan['extra'] ?? ''));

            if ($type === 'all') {
                $notes[] = "Table {$table} is doing a full scan (type=ALL).";
            }

            if ($rows > 10000) {
                $notes[] = "Table {$table} scans about {$rows} rows.";
            }

            if ($key === null) {
                $notes[] = "Table {$table} is not using an index key.";
            }

            if (str_contains($extra, 'using filesort')) {
                $notes[] = "Table {$table} uses filesort.";
            }

            if (str_contains($extra, 'using temporary')) {
                $notes[] = "Table {$table} uses a temporary table.";
            }
        }

        if ($notes === []) {
            return 'No major EXPLAIN red flags detected.';
        }

        return implode(' ', array_unique($notes));
    }

    private function toRawSql(string $sql, array $bindings): string
    {
        if ($bindings === []) {
            return $sql;
        }

        $quotedBindings = array_map(function (mixed $binding): string {
            if ($binding === null) {
                return 'null';
            }

            if (is_bool($binding)) {
                return $binding ? '1' : '0';
            }

            if (is_numeric($binding)) {
                return (string) $binding;
            }

            return "'".str_replace("'", "''", (string) $binding)."'";
        }, $bindings);

        $segments = explode('?', $sql);
        $result = '';

        foreach ($segments as $index => $segment) {
            $result .= $segment;

            if (array_key_exists($index, $quotedBindings)) {
                $result .= $quotedBindings[$index];
            }
        }

        return $result;
    }

    private function shouldIgnoreSql(string $sql): bool
    {
        $normalized = $this->normalizeSql($sql);

        if (str_starts_with($normalized, 'explain ')) {
            return true;
        }

        return str_contains($normalized, 'from information_schema.statistics');
    }

    private function extractSourceCodeSnippet(?string $relativeFile, ?int $line): ?string
    {
        if (! is_string($relativeFile) || $relativeFile === '' || $line === null || $line < 1) {
            return null;
        }

        $absolutePath = str_starts_with($relativeFile, '/')
            ? $relativeFile
            : base_path($relativeFile);

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        $lines = @file($absolutePath, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines) || $lines === []) {
            return null;
        }

        $total = count($lines);
        $start = max(1, $line - 6);
        $end = min($total, $line + 12);

        $slice = array_slice($lines, $start - 1, $end - $start + 1);

        return implode("\n", $slice);
    }
}

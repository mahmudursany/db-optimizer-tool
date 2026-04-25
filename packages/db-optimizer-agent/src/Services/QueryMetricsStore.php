<?php

namespace Mdj\DbOptimizer\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class QueryMetricsStore
{
    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    public function record(array $metric): void
    {
        $this->buffer[] = $metric;
    }

    public function count(): int
    {
        return count($this->buffer);
    }

    public function all(): Collection
    {
        return collect($this->buffer);
    }

    public function flushSnapshot(array $meta): void
    {
        if ($this->buffer === []) {
            return;
        }

        // ── Only persist queries that have at least one actionable issue ───────
        $actionable = array_values(array_filter(
            $this->buffer,
            static function (array $q): bool {
                // Slow query (has EXPLAIN result)
                if (isset($q['explain'])) {
                    return true;
                }

                // N+1 suspected
                if ((bool) ($q['detectors']['n_plus_one']['is_suspected'] ?? false)) {
                    return true;
                }

                // Missing indexes suggested
                $missingIndexes = $q['detectors']['missing_indexes'] ?? [];
                if (is_array($missingIndexes) && $missingIndexes !== []) {
                    return true;
                }

                // Has a recommendation with meaningful optimized SQL
                $recommendations = $q['recommendations'] ?? [];
                if (is_array($recommendations) && $recommendations !== []) {
                    return true;
                }

                return false;
            }
        ));

        // Nothing actionable in this request — skip writing
        if ($actionable === []) {
            $this->buffer = [];

            return;
        }

        // Strip heavy fields from each query to keep JSON lean
        $slim = array_map(static function (array $q): array {
            // Remove raw bindings array (can be huge) — raw_sql already has values interpolated
            unset($q['bindings']);

            // Trim source_code snippet — keep only the current snippet, not duplicates
            if (isset($q['source_code']['current'])) {
                $q['source_code'] = ['current' => $q['source_code']['current']];
            }

            return $q;
        }, $actionable);

        $disk = (string) config('db_optimizer.storage_disk', 'local');
        $path = trim((string) config('db_optimizer.storage_path', 'db-optimizer'), '/');

        $payload = [
            'captured_at'  => now()->toIso8601String(),
            'meta'         => $meta,
            'queries'      => $slim,
        ];

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line !== false) {
            Storage::disk($disk)->append($path.'/queries-'.now()->format('Y-m-d').'.ndjson', $line);
        }

        $this->buffer = [];
    }
}

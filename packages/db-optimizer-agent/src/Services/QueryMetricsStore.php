<?php

namespace Mdj\DbOptimizer\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

        // ── Step 1: Deduplicate by fingerprint ───────────────────────────────
        // When a query runs N times (N+1 pattern), each execution is recorded
        // separately with an increasing repetition counter. We only want to keep
        // the LAST entry per fingerprint (highest repetition, is_suspected=true).
        $deduped = [];
        foreach ($this->buffer as $q) {
            $fp = (string) ($q['fingerprint'] ?? md5($q['sql'] ?? ''));
            // Always overwrite — last recorded entry has the highest repetition count
            $deduped[$fp] = $q;
        }

        // ── Step 2: Keep only queries with actionable issues ─────────────────
        $actionable = array_values(array_filter(
            $deduped,
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

                // Has at least one recommendation
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

        // ── Step 3: Slim down each query record ──────────────────────────────
        $slim = array_map(static function (array $q): array {
            // raw_sql already has bindings interpolated — drop the raw array
            unset($q['bindings']);

            // Keep only the 'current' source snippet
            if (isset($q['source_code']['current'])) {
                $q['source_code'] = ['current' => $q['source_code']['current']];
            }

            return $q;
        }, $actionable);

        // ── Step 4: Cross-request deduplication (by fingerprint) ──────────────
        // To avoid bloating the NDJSON with the same issues repeatedly,
        // we keep track of which fingerprints were already recorded today.
        $cacheKey = 'db_optimizer_fingerprints_'.now()->format('Y-m-d');
        $recorded = Cache::get($cacheKey, []);
        
        $newQueries = [];
        foreach ($slim as $q) {
            $fp = (string) ($q['fingerprint'] ?? '');
            if ($fp !== '' && ! in_array($fp, $recorded, true)) {
                $newQueries[] = $q;
                $recorded[] = $fp;
            }
        }

        if ($newQueries === []) {
            $this->buffer = [];
            return;
        }

        // Update cache (expire in 24h)
        Cache::put($cacheKey, $recorded, now()->addDay());

        $disk = (string) config('db_optimizer.storage_disk', 'local');
        $path = trim((string) config('db_optimizer.storage_path', 'db-optimizer'), '/');

        $payload = [
            'captured_at' => now()->toIso8601String(),
            'meta'        => $meta,
            'queries'     => array_values($newQueries),
        ];

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line !== false) {
            Storage::disk($disk)->append($path.'/queries-'.now()->format('Y-m-d').'.ndjson', $line);
        }

        $this->buffer = [];
    }
}

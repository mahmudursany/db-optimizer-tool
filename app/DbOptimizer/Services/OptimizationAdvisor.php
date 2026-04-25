<?php

namespace App\DbOptimizer\Services;

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
        $sql = $this->normalizeSql($event->sql);
        $recommendationLimit = max(1, (int) config('db_optimizer.recommendation_limit', 8));

        if ($this->looksLikeSelectAll($sql)) {
            $recommendations[] = $this->makeRecommendation(
                type: 'query_refactor',
                title: 'Avoid select *',
                description: 'Select only the columns you actually need. Narrow selects reduce IO, memory use, and transfer time.',
                codeHint: "->select(['id', 'name', 'status'])",
                priority: 90,
                confidence: 0.92,
                safeAutoApply: true,
            );
        }

        if ((bool) Arr::get($metric, 'detectors.n_plus_one.is_suspected', false)) {
            $table = preg_match('/\bfrom\s+`?([a-zA-Z_][\w]*)`?/i', $event->sql, $m) ? $m[1] : '';
            $model = $table ? \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($table)) : 'YourModel';

            $recommendations[] = $this->makeRecommendation(
                type: 'eager_loading',
                title: 'Use eager loading',
                description: 'The same relation query is repeating. Load relations up front with with() or load().',
                codeHint: "{$model}::with(['relation_name'])->get()",
                priority: 100,
                confidence: 0.97,
                safeAutoApply: false,
            );
        }

        if ((bool) Arr::get($metric, 'detectors.cache_candidate.is_candidate', false)) {
            $recommendations[] = $this->makeRecommendation(
                type: 'cache',
                title: 'Cache repeated read query',
                description: 'This SELECT is repeated with the same bindings. Cache the result with Cache::remember().',
                codeHint: "Cache::remember('key', 300, fn () => DB::table(...)->get())",
                priority: 85,
                confidence: 0.94,
                safeAutoApply: true,
            );
        }

        if ($this->hasHeavyJoinPattern($sql, $metric)) {
            $recommendations[] = $this->makeRecommendation(
                type: 'join_tuning',
                title: 'Review heavy joins',
                description: 'The query uses multiple joins or a full scan. Add indexes on join keys and consider splitting the query if the result set is large.',
                codeHint: "// add indexes on foreign keys used in JOIN / WHERE",
                priority: 80,
                confidence: 0.88,
                safeAutoApply: false,
            );
        }

        if ($this->hasLikeWildcardPattern($sql)) {
            $recommendations[] = $this->makeRecommendation(
                type: 'search',
                title: 'Refactor wildcard search',
                description: 'Leading-wildcard LIKE patterns prevent normal index use. Consider fulltext search, prefix search, or search columns.',
                codeHint: 'where("name", "like", $term."%")',
                priority: 78,
                confidence: 0.86,
                safeAutoApply: false,
            );
        }

        if ($this->looksLikeCountCheck($sql)) {
            $recommendations[] = $this->makeRecommendation(
                type: 'exists',
                title: 'Use exists instead of count',
                description: 'If you are only checking presence, exists() is cheaper than count() > 0.',
                codeHint: "->exists()",
                priority: 82,
                confidence: 0.9,
                safeAutoApply: true,
            );
        }

        if ($this->hasLargeInClause($event)) {
            $recommendations[] = $this->makeRecommendation(
                type: 'batching',
                title: 'Chunk large IN lists',
                description: 'A large IN clause can be expensive. Batch the IDs or move them to a temp table / join strategy.',
                codeHint: 'collect($ids)->chunk(100)',
                priority: 75,
                confidence: 0.84,
                safeAutoApply: false,
            );
        }

        if (isset($metric['explain']) && is_array($metric['explain'])) {
            $recommendations[] = $this->makeRecommendation(
                type: 'index',
                title: 'Review EXPLAIN plan',
                description: 'The query is slow enough to require EXPLAIN. Add/adjust indexes based on scanned rows, filesort, and temporary table usage.',
                codeHint: 'Add an index on the most selective WHERE/JOIN column pair.',
                priority: 95,
                confidence: 0.91,
                safeAutoApply: false,
            );
        }

        if ((bool) config('db_optimizer.auto_apply_safe_optimizations', false)) {
            foreach ($recommendations as &$recommendation) {
                if (($recommendation['safe_auto_apply'] ?? false) === true) {
                    $recommendation['auto_applied'] = false;
                    $recommendation['auto_apply_eligible'] = true;
                }
            }
            unset($recommendation);
        }

        usort($recommendations, static function (array $a, array $b): int {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        });

        return array_slice($recommendations, 0, $recommendationLimit);
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($sql))) ?? strtolower(trim($sql));
    }

    private function looksLikeSelectAll(string $sql): bool
    {
        return str_starts_with($sql, 'select') && str_contains($sql, 'select *');
    }

    private function hasHeavyJoinPattern(string $sql, array $metric): bool
    {
        $joinCount = substr_count($sql, ' join ');
        $isSlow = (float) Arr::get($metric, 'time_ms', 0) >= (float) config('db_optimizer.slow_query_threshold_ms', 50);
        $fullScan = isset($metric['explain']) && is_array($metric['explain']) && str_contains(strtolower((string) Arr::get($metric, 'explain.summary', '')), 'full scan');

        return $joinCount >= 2 || ($joinCount >= 1 && ($isSlow || $fullScan));
    }

    private function hasLikeWildcardPattern(string $sql): bool
    {
        return (bool) preg_match('/like\s+["\']?%/i', $sql) || (bool) preg_match('/like\s+\?/i', $sql);
    }

    private function looksLikeCountCheck(string $sql): bool
    {
        return str_starts_with($sql, 'select count(') || str_contains($sql, 'count(*)');
    }

    private function hasLargeInClause(QueryExecuted $event): bool
    {
        $bindings = is_array($event->bindings) ? $event->bindings : [];

        return count($bindings) >= 25 && (bool) preg_match('/\bin\s*\(/i', $event->sql);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRecommendation(
        string $type,
        string $title,
        string $description,
        string $codeHint,
        int $priority,
        float $confidence,
        bool $safeAutoApply,
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'code_hint' => $codeHint,
            'priority' => $priority,
            'confidence' => round($confidence, 2),
            'safe_auto_apply' => $safeAutoApply,
            'auto_apply_eligible' => false,
        ];
    }
}

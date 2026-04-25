<?php

namespace Mdj\DbOptimizer\Services;

/**
 * Analyses a PHP source snippet and rewrites it to:
 *  1. Inject ->with([...]) for every detected lazy relation access
 *  2. Remove the now-redundant lazy-load lines
 *  3. Add ->select([...]) hints as inline comments
 */
class SourceCodeOptimizer
{
    // Known column names that look like relation names but aren't
    private const COLUMN_NAMES = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'email', 'password', 'name', 'status', 'type',
        'title', 'body', 'content', 'slug', 'price',
        'quantity', 'published', 'approved', 'order_level',
    ];

    /**
     * Rewrite the source. Returns [rewritten, changed].
     *
     * @return array{0: string, 1: bool}
     */
    public function rewrite(string $source): array
    {
        $lines = preg_split('/\R/', $source) ?: [];

        if (count($lines) < 3) {
            return [$source, false];
        }

        // ── Phase 1: build foreach item→collection map ────────────────────────
        $itemToCollection = $this->buildItemToCollectionMap($lines);

        // ── Phase 2: collect all lazy relation accesses ───────────────────────
        [$collectionRelations, $objectRelations, $lazyIndexes] =
            $this->collectLazyLoads($lines, $itemToCollection);

        $allRelations = $this->mergeRelations($collectionRelations, $objectRelations);

        if (empty($allRelations)) {
            return [$source, false];
        }

        // ── Phase 3: inject ->with() into the originating queries ─────────────
        $lines = $this->injectWithClauses($lines, $allRelations);

        // ── Phase 4: remove the now-redundant lazy-load lines ─────────────────
        $lines = $this->removeLazyLines($lines, $lazyIndexes);

        // ── Phase 5: inject select() hints ────────────────────────────────────
        $lines = $this->injectSelectClauses($lines);

        $rewritten = implode("\n", $lines);

        return [$rewritten, $rewritten !== $source];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Phase 1 – foreach mapping
    // ──────────────────────────────────────────────────────────────────────────

    /** @return array<string, string>  itemVar => collectionVar */
    private function buildItemToCollectionMap(array $lines): array
    {
        $map = [];

        foreach ($lines as $line) {
            if (preg_match('/\bforeach\s*\(\s*\$(\w+)\s+as\s+\$(\w+)\s*\)/', $line, $m)) {
                $map[$m[2]] = $m[1]; // $item => $collection
            }
        }

        return $map;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Phase 2 – lazy load collection
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $itemToCollection
     * @return array{0: array<string, string[]>, 1: array<string, string[]>, 2: array<int, true>}
     */
    private function collectLazyLoads(array $lines, array $itemToCollection): array
    {
        $collectionRelations = [];  // collectionVar => [relation, ...]
        $objectRelations     = [];  // objectVar     => [relation, ...]
        $lazyIndexes         = [];  // line indexes to remove

        $pattern = '/^\s*(?:\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\s*=\s*)?\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)->([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*;\s*(?:\/\/.*)?$/';
        $pureAccessPattern = '/^\s*\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*->[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\s*;\s*(?:\/\/.*)?$/';

        foreach ($lines as $i => $line) {
            // Pattern: optional $assignment = $var->relation; (with optional trailing // comment)
            if (! preg_match($pattern, $line, $m)) {
                continue;
            }

            $var      = $m[1];
            $relation = $m[2];

            if ($this->looksLikeColumn($relation)) {
                continue;
            }

            if (isset($itemToCollection[$var])) {
                // Inside a foreach — lazy load on collection item
                $col = $itemToCollection[$var];
                $collectionRelations[$col][] = $relation;
            } else {
                // Direct lazy load on a single object
                $objectRelations[$var][] = $relation;
            }

            if (preg_match($pureAccessPattern, $line)) {
                $lazyIndexes[$i] = true;
            }
        }

        // deduplicate
        foreach ($collectionRelations as &$rels) {
            $rels = array_values(array_unique($rels));
        }
        foreach ($objectRelations as &$rels) {
            $rels = array_values(array_unique($rels));
        }

        return [$collectionRelations, $objectRelations, $lazyIndexes];
    }

    private function looksLikeColumn(string $name): bool
    {
        return in_array(strtolower($name), self::COLUMN_NAMES, true);
    }

    /** @return array<string, string[]> */
    private function mergeRelations(array $collectionRelations, array $objectRelations): array
    {
        $all = $collectionRelations;

        foreach ($objectRelations as $var => $rels) {
            if (isset($all[$var])) {
                $all[$var] = array_values(array_unique(array_merge($all[$var], $rels)));
            } else {
                $all[$var] = $rels;
            }
        }

        return $all;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Phase 3 – inject ->with() into queries
    // ──────────────────────────────────────────────────────────────────────────

    private function injectWithClauses(array $lines, array $allRelations): array
    {
        foreach ($allRelations as $var => $relations) {
            $relations = array_values(array_unique($relations));

            if (empty($relations)) {
                continue;
            }

            $formattedRelations = array_map(function($rel) {
                return "'{$rel}:id /* add needed columns */'";
            }, $relations);
            
            $withInner = implode(",\n        ", $formattedRelations);
            $withCall = count($relations) === 1 
                ? "with(" . $formattedRelations[0] . ")" 
                : "with([\n        " . $withInner . "\n    ])";

            // Find: $var = SomeModel:: (the assignment line)
            $assignLine = null;

            for ($i = 0; $i < count($lines); $i++) {
                if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*[A-Z]/', $lines[$i])) {
                    $assignLine = $i;
                    break;
                }
            }

            if ($assignLine === null) {
                continue;
            }

            // Find end of this assignment (the line ending in ;)
            $end = $assignLine;
            while ($end < count($lines) - 1 && ! preg_match('/;\s*(?:\/\/.*)?$/', $lines[$end])) {
                $end++;
            }

            // Check if ->with() already present in this block
            $block = implode(' ', array_slice($lines, $assignLine, $end - $assignLine + 1));

            if (str_contains($block, '->with(')) {
                continue;
            }

            // Inject right after `ModelName::` on the assignment line
            if (preg_match('/([A-Z][\w\\\\]*::)/', $lines[$assignLine])) {
                $lines[$assignLine] = preg_replace(
                    '/([A-Z][\w\\\\]*::)(?!with\()/',
                    '$1' . $withCall . "\n    ->",
                    $lines[$assignLine],
                    1
                ) ?? $lines[$assignLine];

                continue;
            }
        }

        return $lines;
    }

    private function injectSelectClauses(array $lines): array
    {
        for ($i = 0; $i < count($lines); $i++) {
            $hasSelect = false;
            for ($j = max(0, $i - 5); $j <= $i; $j++) {
                if (str_contains($lines[$j], '->select(') || str_contains($lines[$j], '::select(')) {
                    $hasSelect = true;
                    break;
                }
            }
            
            if (!$hasSelect) {
                foreach (['->get(', '->first(', '->paginate(', '->all('] as $term) {
                    if (str_contains($lines[$i], $term)) {
                        $lines[$i] = str_replace($term, "->select(['id' /* add needed columns */])\n    " . $term, $lines[$i]);
                        break;
                    }
                }
            }
        }
        return $lines;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Phase 4 – remove lazy-load lines
    // ──────────────────────────────────────────────────────────────────────────

    private function removeLazyLines(array $lines, array $lazyIndexes): array
    {
        if (empty($lazyIndexes)) {
            return $lines;
        }

        $result = [];

        foreach ($lines as $i => $line) {
            if (! isset($lazyIndexes[$i])) {
                $result[] = $line;
            }
        }

        return $result;
    }
}

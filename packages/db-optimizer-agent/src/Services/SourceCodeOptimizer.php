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

        foreach ($lines as $i => $line) {
            // Pattern: $var->relation;   (with optional trailing // comment)
            if (! preg_match('/^\s*\$(\w+)->([a-zA-Z_]\w*)\s*;\s*(?:\/\/.*)?$/', $line, $m)) {
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

            $lazyIndexes[$i] = true;
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

            $withCall = count($relations) === 1
                ? "with('{$relations[0]}')"
                : "with(['" . implode("', '", $relations) . "'])";

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
                    '$1' . $withCall . '->',
                    $lines[$assignLine],
                    1
                ) ?? $lines[$assignLine];

                continue;
            }

            // Fallback: insert a line before the terminal method (->get/first/paginate)
            for ($j = $assignLine; $j <= $end; $j++) {
                if (preg_match('/^\s*->(get|first|paginate|all)\s*\(/', $lines[$j])) {
                    preg_match('/^(\s*)/', $lines[$j], $indentM);
                    $indent = $indentM[1] ?? '        ';
                    array_splice($lines, $j, 0, [$indent . '->' . $withCall]);
                    break;
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

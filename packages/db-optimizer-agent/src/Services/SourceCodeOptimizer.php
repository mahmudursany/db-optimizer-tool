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

        // ── Phase 6: inject architecture warnings ─────────────────────────────
        $lines = $this->injectArchitectureWarnings($lines);

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
        $aliases             = [];  // var => ['parent' => parentVar, 'rel' => relation]

        $accessPattern = '/^\s*(?:(?:\$([a-zA-Z0-9_\x7f-\xff]+)\s*=\s*)(?:\$[a-zA-Z0-9_\x7f-\xff]+\s*\?\s*)?)?\$([a-zA-Z0-9_\x7f-\xff]+)->([a-zA-Z0-9_\x7f-\xff]+)\s*(?:\:\s*null\s*)?;\s*(?:\/\/.*)?$/';
        $pureAccessPattern = '/^\s*\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*->[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\s*;\s*(?:\/\/.*)?$/';

        foreach ($lines as $i => $line) {
            if (! preg_match($accessPattern, $line, $m)) {
                continue;
            }

            $assignedVar = $m[1] ?? '';
            $var         = $m[2];
            $relation    = $m[3];

            if ($this->looksLikeColumn($relation)) {
                continue;
            }

            if (!empty($assignedVar)) {
                $aliases[$assignedVar] = ['parent' => $var, 'rel' => $relation];
            }

            if (isset($itemToCollection[$var])) {
                $col = $itemToCollection[$var];
                $collectionRelations[$col][] = $relation;
            } else {
                $objectRelations[$var][] = $relation;
            }

            if (preg_match($pureAccessPattern, $line)) {
                $lazyIndexes[$i] = true;
            }
        }

        // Resolve nested aliases
        $resolvedCollectionRels = [];
        $resolvedObjectRels     = [];

        foreach ($objectRelations as $var => $rels) {
            foreach ($rels as $rel) {
                $currentVar = $var;
                $path       = $rel;
                while (isset($aliases[$currentVar])) {
                    $alias      = $aliases[$currentVar];
                    $path       = $alias['rel'] . '.' . $path;
                    $currentVar = $alias['parent'];
                }
                $resolvedObjectRels[$currentVar][] = $path;
            }
        }

        foreach ($collectionRelations as $col => $rels) {
            foreach ($rels as $rel) {
                $currentVar = $col;
                $path       = $rel;
                while (isset($aliases[$currentVar])) {
                    $alias      = $aliases[$currentVar];
                    $path       = $alias['rel'] . '.' . $path;
                    $currentVar = $alias['parent'];
                }
                $resolvedCollectionRels[$currentVar][] = $path;
            }
        }

        foreach ($resolvedCollectionRels as &$rels) {
            $rels = array_values(array_unique($rels));
        }
        foreach ($resolvedObjectRels as &$rels) {
            $rels = array_values(array_unique($rels));
        }

        return [$resolvedCollectionRels, $resolvedObjectRels, $lazyIndexes];
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

            // Filter out redundant parent relations (e.g., if 'user.shop' exists, remove 'user')
            $filteredRelations = [];
            foreach ($relations as $rel) {
                $isParentOfAnother = false;
                foreach ($relations as $otherRel) {
                    if ($rel !== $otherRel && str_starts_with($otherRel, $rel . '.')) {
                        $isParentOfAnother = true;
                        break;
                    }
                }
                if (!$isParentOfAnother) {
                    $filteredRelations[] = $rel;
                }
            }

            if (empty($filteredRelations)) {
                continue;
            }

            $formattedRelations = array_map(function($rel) {
                return "'{$rel}:id /* add columns */'";
            }, $filteredRelations);
            
            $withInner = implode(",\n        ", $formattedRelations);
            $withCall = count($filteredRelations) === 1 
                ? "with(" . $formattedRelations[0] . ")" 
                : "with([\n        " . $withInner . "\n    ])";

            // Find ALL assignments: $var = SomeModel::
            $assignLines = [];

            for ($i = 0; $i < count($lines); $i++) {
                if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*[A-Z]/', $lines[$i])) {
                    $assignLines[] = $i;
                }
            }

            if (empty($assignLines)) {
                continue;
            }

            foreach ($assignLines as $assignLine) {
                // Check if ->with() already present in this block
                $end = $assignLine;
                while ($end < count($lines) - 1 && ! preg_match('/;\s*(?:\/\/.*)?$/', $lines[$end])) {
                    $end++;
                }
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
                }
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
                foreach (['->get(', '->first(', '->paginate('] as $term) {
                    if (str_contains($lines[$i], $term)) {
                        $lines[$i] = str_replace($term, "->select(['id' /* add ALL foreign keys & needed columns */])\n    " . $term, $lines[$i]);
                        break;
                    }
                }
            }
        }
        return $lines;
    }

    private function injectArchitectureWarnings(array $lines): array
    {
        for ($i = 0; $i < count($lines); $i++) {
            if (preg_match('/(?:->|::)find\s*\(\s*\$/', $lines[$i])) {
                $injectLine = $i;
                for ($j = $i; $j >= max(0, $i - 3); $j--) {
                    if (preg_match('/=\s*[A-Z][\w\\\\]*::/', $lines[$j])) {
                        $injectLine = $j;
                        break;
                    }
                }
                
                $inLoop = false;
                for ($j = max(0, $injectLine - 10); $j < $injectLine; $j++) {
                    if (preg_match('/\b(foreach|while|for)\b/', $lines[$j])) {
                        $inLoop = true;
                        break;
                    }
                }
                
                if ($inLoop) {
                    if (isset($lines[$injectLine - 1]) && str_contains($lines[$injectLine - 1], 'ARCHITECTURE WARNING')) {
                        continue;
                    }
                    if (isset($lines[$injectLine - 2]) && str_contains($lines[$injectLine - 2], 'ARCHITECTURE WARNING')) {
                        continue;
                    }
                    
                    preg_match('/^(\s*)/', $lines[$injectLine], $indentM);
                    $indent = $indentM[1] ?? '        ';
                    array_splice($lines, $injectLine, 0, [$indent . '// ⚠️ ARCHITECTURE WARNING: Calling find() inside a loop is an N+1 pattern.']);
                    array_splice($lines, $injectLine + 1, 0, [$indent . '// Consider refactoring to use ->whereIn(\'id\', $ids) outside the loop!']);
                    $i += 2;
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

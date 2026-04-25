<?php
namespace Mdj\DbOptimizer\Services;

class TestSourceCodeOptimizer
{
    private const COLUMN_NAMES = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'email', 'password', 'name', 'status', 'type',
        'title', 'body', 'content', 'slug', 'price',
        'quantity', 'published', 'approved', 'order_level',
    ];

    public function rewrite(string $source): array
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $itemToCollection = $this->buildItemToCollectionMap($lines);
        [$collectionRelations, $objectRelations, $lazyIndexes] = $this->collectLazyLoads($lines, $itemToCollection);
        $allRelations = $this->mergeRelations($collectionRelations, $objectRelations);

        if (empty($allRelations)) {
            return [$source, false];
        }

        $lines = $this->injectWithClauses($lines, $allRelations);
        $lines = $this->removeLazyLines($lines, $lazyIndexes);
        
        // Add select() hints
        $lines = $this->injectSelectClauses($lines);

        $rewritten = implode("\n", $lines);
        return [$rewritten, $rewritten !== $source];
    }

    private function buildItemToCollectionMap(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            if (preg_match('/\bforeach\s*\(\s*\$(\w+)\s+as\s+\$(\w+)\s*\)/', $line, $m)) {
                $map[$m[2]] = $m[1];
            }
        }
        return $map;
    }

    private function collectLazyLoads(array $lines, array $itemToCollection): array
    {
        $collectionRelations = [];
        $objectRelations = [];
        $lazyIndexes = [];
        foreach ($lines as $i => $line) {
            if (! preg_match('/^\s*\$(\w+)->([a-zA-Z_]\w*)\s*;\s*(?:\/\/.*)?$/', $line, $m)) {
                continue;
            }
            $var = $m[1];
            $relation = $m[2];
            if (in_array(strtolower($relation), self::COLUMN_NAMES, true)) continue;

            if (isset($itemToCollection[$var])) {
                $col = $itemToCollection[$var];
                $collectionRelations[$col][] = $relation;
            } else {
                $objectRelations[$var][] = $relation;
            }
            $lazyIndexes[$i] = true;
        }
        return [$collectionRelations, $objectRelations, $lazyIndexes];
    }

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

    private function injectWithClauses(array $lines, array $allRelations): array
    {
        foreach ($allRelations as $var => $relations) {
            $relations = array_values(array_unique($relations));
            if (empty($relations)) continue;

            // Make it look "experienced": with(['rel:id /* cols */'])
            $formattedRelations = array_map(function($rel) {
                return "'{$rel}:id /* add needed columns */'";
            }, $relations);
            
            $withInner = implode(",\n        ", $formattedRelations);
            $withCall = count($relations) === 1 
                ? "with(" . $formattedRelations[0] . ")" 
                : "with([\n        " . $withInner . "\n    ])";

            $assignLine = null;
            for ($i = 0; $i < count($lines); $i++) {
                if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*[A-Z]/', $lines[$i])) {
                    $assignLine = $i;
                    break;
                }
            }
            if ($assignLine === null) continue;

            if (preg_match('/([A-Z][\w\\\\]*::)/', $lines[$assignLine])) {
                $lines[$assignLine] = preg_replace(
                    '/([A-Z][\w\\\\]*::)(?!with\()/',
                    '$1' . $withCall . "\n    ->",
                    $lines[$assignLine],
                    1
                );
            }
        }
        return $lines;
    }
    
    private function injectSelectClauses(array $lines): array
    {
        // Simple select injector
        for ($i = 0; $i < count($lines); $i++) {
            if (preg_match('/^\s*->(get|first|paginate|all)\s*\(/', $lines[$i])) {
                // Look back to see if we already have a select
                $hasSelect = false;
                for ($j = max(0, $i - 5); $j < $i; $j++) {
                    if (str_contains($lines[$j], '->select(')) {
                        $hasSelect = true;
                        break;
                    }
                }
                if (!$hasSelect) {
                    preg_match('/^(\s*)/', $lines[$i], $indentM);
                    $indent = $indentM[1] ?? '    ';
                    array_splice($lines, $i, 0, [$indent . "->select(['id' /* add needed columns */])"]);
                    $i++; // skip the newly inserted line
                }
            }
        }
        return $lines;
    }

    private function removeLazyLines(array $lines, array $lazyIndexes): array
    {
        $result = [];
        foreach ($lines as $i => $line) {
            if (! isset($lazyIndexes[$i])) {
                $result[] = $line;
            }
        }
        return $result;
    }
}

$source = <<<PHP
public function listingByCategory(Request \$request, \$category_slug)
{
    \$category = Category::where('slug', \$category_slug)->first(); // Query 1

    if (\$category != null) {

        \$category->parent; // Query 2 - parent load করছে আলাদাভাবে

        \$categories = Category::where('parent_id', \$category->id)
            ->orderBy('order_level', 'desc')
            ->get(); // Query 3

        foreach (\$categories as \$cat) {
            \$cat->translations; // Query 4...N - প্রতিটা category তে আলাদা query
            \$cat->parent;       // Query N+1... - আবার প্রতিটাতে আলাদা query
        }

        \$category_ids = CategoryUtility::children_ids(\$category->id);
        \$category_ids[] = \$category->id;

        \$products = Product::whereIn('category_id', \$category_ids)
            ->where('published', 1)
            ->where('approved', '1')
            ->where('auction_product', 0)
            ->orderBy('id', 'desc')
            ->paginate(12); // Query N+2

        foreach (\$products as \$product) {
            \$product->category;       // প্রতিটা product এ আলাদা query
            \$product->user;           // প্রতিটা product এ আলাদা query
            \$product->thumbnail;      // প্রতিটা product এ আলাদা query
        }

        return view('frontend.all_category', compact('categories', 'category', 'products'));
    }

    abort(404);
}
PHP;

$optimizer = new TestSourceCodeOptimizer();
[$rewritten, $changed] = $optimizer->rewrite($source);
echo $rewritten;

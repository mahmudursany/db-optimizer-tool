<?php
$line = "        \$category->parent; // Query 2 - parent load করছে আলাদাভাবে";
if (preg_match('/^\s*\$(\w+)->([a-zA-Z_]\w*)\s*;\s*(?:\/\/.*)?$/', $line, $m)) {
    echo "Matched: " . $m[1] . " -> " . $m[2] . "\n";
} else {
    echo "Failed\n";
}

<?php
$filteredRelations = ['category', 'user.shop', 'stocks'];
$formattedRelations = array_map(function($rel) {
    return "'{$rel}:id /* add needed columns e.g., name */'";
}, $filteredRelations);
print_r($formattedRelations);

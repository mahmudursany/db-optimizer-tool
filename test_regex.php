<?php
$sourceCode = "Category::all();\nDB::table('languages')->get();";
$replaced = preg_replace_callback('/(::|\->)(get|first|all|paginate|cursor|chunk)\s*\(/', function($matches) {
            $method = $matches[2];
            $prefix = $matches[1];
            return "->select(['id' /* add required columns */])\n    {$prefix}{$method}(";
        }, $sourceCode);
echo $replaced;

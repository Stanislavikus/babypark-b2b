<?php

declare(strict_types=1);

[$script, $manifestPath, $version, $distUrl, $sha1, $outputPath] = $argv;

$manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$manifest['version'] = $version;
$manifest['dist'] = [
    'url' => $distUrl,
    'type' => 'zip',
    'shasum' => $sha1,
];

$repository = [
    'packages' => [
        $manifest['name'] => [
            $version => $manifest,
        ],
    ],
];

file_put_contents(
    $outputPath,
    json_encode($repository, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);

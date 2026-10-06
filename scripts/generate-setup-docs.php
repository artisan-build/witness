#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\SetupInstructions;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$check = in_array('--check', $argv, true);
$unknown = array_values(array_diff(array_slice($argv, 1), ['--check']));

if ($unknown !== []) {
    fwrite(STDERR, 'Usage: php scripts/generate-setup-docs.php [--check]'.PHP_EOL);
    exit(2);
}

$artifacts = [
    $root.'/docs/setup.md' => SetupInstructions::setupMarkdown(),
    $root.'/docs/scalpels-handoff.md' => SetupInstructions::scalpelsHandoffMarkdown(),
];

foreach ($artifacts as $path => $expected) {
    if ($check) {
        if (! is_file($path) || file_get_contents($path) !== $expected) {
            fwrite(STDERR, str_replace($root.'/', '', $path).' is stale. Run php scripts/generate-setup-docs.php locally.'.PHP_EOL);
            exit(1);
        }

        continue;
    }

    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fwrite(STDERR, 'Unable to create '.$directory.PHP_EOL);
        exit(1);
    }

    if (file_put_contents($path, $expected, LOCK_EX) !== strlen($expected)) {
        fwrite(STDERR, 'Unable to write '.$path.PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, $check ? 'Setup documentation is current.'.PHP_EOL : 'Generated setup documentation.'.PHP_EOL);

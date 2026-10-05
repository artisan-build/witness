#!/usr/bin/env php
<?php

declare(strict_types=1);

$json = in_array('--json', $argv, true);

function finish(array $result, bool $json, int $code): never
{
    if ($json) {
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        echo ($result['ok'] ? 'OK: ' : 'ERROR: ').$result['message'].PHP_EOL;
        foreach ($result['facts'] ?? [] as $name => $fact) {
            $value = is_array($fact['value']) ? implode(', ', $fact['value']) : (string) ($fact['value'] ?? 'missing');
            echo strtoupper($fact['attribution']).": {$name} = {$value}".PHP_EOL;
        }
    }
    exit($code);
}

function misuse(bool $json, string $message): never
{
    finish(['ok' => false, 'message' => $message, 'usage' => 'inspect.php [--root=.] [--json]'], $json, 2);
}

$options = getopt('', ['root:', 'json']);
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '--json' && ! str_starts_with($argument, '--root=')) {
        misuse($json, "Unknown argument: {$argument}");
    }
}
$root = rtrim(is_string($options['root'] ?? null) ? $options['root'] : '.', DIRECTORY_SEPARATOR);
$manifestFile = $root.'/config/built-for-cloud.php';
$composerFile = $root.'/composer.json';
if (! is_file($manifestFile) || ! is_file($composerFile)) {
    finish(['ok' => false, 'message' => 'Both config/built-for-cloud.php and composer.json are required.'], $json, 1);
}

$autoload = $root.'/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$config = require $manifestFile;
$composer = json_decode((string) file_get_contents($composerFile), true);
if (! is_array($config) || ! is_array($config['manifest'] ?? null) || ! is_array($composer)) {
    finish(['ok' => false, 'message' => 'Manifest or composer.json has an invalid shape.'], $json, 1);
}

$facts = [];
foreach (['name', 'slug', 'description', 'icon', 'product_url'] as $key) {
    $value = $config['manifest'][$key] ?? null;
    $facts["manifest.{$key}"] = [
        'value' => is_string($value) && trim($value) !== '' ? $value : null,
        'attribution' => is_string($value) && trim($value) !== '' ? 'linked' : 'unattributed',
        'source' => 'config/built-for-cloud.php',
    ];
}

$package = is_string($composer['name'] ?? null) ? $composer['name'] : null;
$facts['composer.name'] = ['value' => $package, 'attribution' => $package === null ? 'unattributed' : 'linked', 'source' => 'composer.json'];
$repository = is_string($composer['homepage'] ?? null) ? $composer['homepage'] : null;
$repositoryAttribution = $repository === null ? 'unattributed' : 'linked';
if ($repository === null && is_string($package) && preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $package) === 1) {
    $repository = 'https://github.com/'.$package;
    $repositoryAttribution = 'inferred';
}
$facts['repository_url'] = ['value' => $repository, 'attribution' => $repositoryAttribution, 'source' => $repositoryAttribution === 'linked' ? 'composer.json homepage' : ($repositoryAttribution === 'inferred' ? 'composer.json name heuristic' : null)];

$scripts = is_array($composer['scripts'] ?? null) ? array_values(array_intersect(['setup', 'dev', 'test', 'ready'], array_keys($composer['scripts']))) : [];
$facts['composer_scripts'] = ['value' => $scripts, 'attribution' => $scripts === [] ? 'unattributed' : 'linked', 'source' => 'composer.json scripts'];

$routeFiles = glob($root.'/routes/*.php') ?: [];
$facts['route_files'] = ['value' => array_map('basename', $routeFiles), 'attribution' => $routeFiles === [] ? 'unattributed' : 'linked', 'source' => 'routes/*.php'];

finish(['ok' => true, 'message' => 'Repository facts inspected; inferred values remain guesses.', 'facts' => $facts], $json, 0);

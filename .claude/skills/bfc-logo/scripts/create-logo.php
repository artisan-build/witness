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
        foreach ($result['attribution'] ?? [] as $field => $attribution) {
            echo strtoupper($attribution).": {$field}".PHP_EOL;
        }
    }
    exit($code);
}

function misuse(bool $json, string $message): never
{
    finish(['ok' => false, 'message' => $message, 'usage' => 'create-logo.php [--root=.] [--initials=AB] [--background=#112233] [--foreground=#ffffff] [--json]'], $json, 2);
}

$options = getopt('', ['root:', 'initials:', 'background:', 'foreground:', 'json']);
$known = ['--json', '--root=', '--initials=', '--background=', '--foreground='];
foreach (array_slice($argv, 1) as $argument) {
    $recognized = false;
    foreach ($known as $option) {
        $recognized = $recognized || $argument === $option || str_starts_with($argument, $option);
    }
    if (! $recognized) {
        misuse($json, "Unknown argument: {$argument}");
    }
}

$root = rtrim(is_string($options['root'] ?? null) ? $options['root'] : '.', DIRECTORY_SEPARATOR);
$manifestFile = $root.'/config/built-for-cloud.php';
if (! is_file($manifestFile)) {
    finish(['ok' => false, 'message' => "Manifest not found: {$manifestFile}"], $json, 1);
}
$config = require $manifestFile;
$name = is_array($config) && is_string($config['manifest']['name'] ?? null) ? trim($config['manifest']['name']) : '';
$icon = is_array($config) && is_string($config['manifest']['icon'] ?? null) ? $config['manifest']['icon'] : '';
if ($name === '' || preg_match('#^/[A-Za-z0-9._/-]+\.svg$#', $icon) !== 1 || str_contains($icon, '\\') || array_intersect(explode('/', $icon), ['.', '..']) !== []) {
    finish(['ok' => false, 'message' => 'Manifest name or icon is missing or invalid; icon must be a root-relative .svg path without traversal.'], $json, 1);
}

$initials = is_string($options['initials'] ?? null) ? strtoupper(trim($options['initials'])) : '';
$initialsAttribution = 'linked';
if ($initials === '') {
    preg_match_all('/[\pL\pN]+/u', $name, $words);
    $initials = implode('', array_map(static fn (string $word): string => strtoupper(substr($word, 0, 1)), array_slice($words[0], 0, 2)));
    $initialsAttribution = 'inferred';
}
if ($initials === '' || strlen($initials) > 3) {
    misuse($json, 'Initials must contain one to three visible characters.');
}

$background = is_string($options['background'] ?? null) ? $options['background'] : '#111827';
$foreground = is_string($options['foreground'] ?? null) ? $options['foreground'] : '#ffffff';
foreach (['background' => $background, 'foreground' => $foreground] as $label => $color) {
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
        misuse($json, "{$label} must be a six-digit hex color.");
    }
}

$public = $root.'/public';
if (! is_dir($public) && ! mkdir($public, 0755, true)) {
    finish(['ok' => false, 'message' => "Could not create directory: {$public}"], $json, 1);
}
$publicPath = realpath($public);
if ($publicPath === false) {
    finish(['ok' => false, 'message' => "Could not resolve public directory: {$public}"], $json, 1);
}
$target = $publicPath.'/'.ltrim($icon, '/');
if (file_exists($target)) {
    finish(['ok' => false, 'message' => "Asset already exists and was not overwritten: {$target}", 'file' => $target], $json, 1);
}
$directory = dirname($target);
if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
    finish(['ok' => false, 'message' => "Could not create directory: {$directory}"], $json, 1);
}
$directoryPath = realpath($directory);
if ($directoryPath === false || ($directoryPath !== $publicPath && ! str_starts_with($directoryPath, $publicPath.DIRECTORY_SEPARATOR))) {
    finish(['ok' => false, 'message' => 'Manifest icon resolves outside the app public directory.'], $json, 1);
}
$target = $directoryPath.'/'.basename($target);

$safeName = htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
$safeInitials = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');
$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-labelledby="title">
  <title>{$safeName}</title>
  <rect width="512" height="512" rx="112" fill="{$background}"/>
  <text x="256" y="278" fill="{$foreground}" font-family="ui-sans-serif, system-ui, sans-serif" font-size="190" font-weight="700" text-anchor="middle" dominant-baseline="middle">{$safeInitials}</text>
</svg>
SVG;
if (file_put_contents($target, $svg.PHP_EOL, LOCK_EX) === false) {
    finish(['ok' => false, 'message' => "Could not write {$target}."], $json, 1);
}

finish([
    'ok' => true,
    'message' => "Created {$target}",
    'file' => $target,
    'initials' => $initials,
    'attribution' => ['name' => 'linked', 'icon' => 'linked', 'initials' => $initialsAttribution],
], $json, 0);

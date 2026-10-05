#!/usr/bin/env php
<?php

declare(strict_types=1);

const MANIFEST_KEYS = ['name', 'slug', 'description', 'icon', 'product_url'];
const UI_AFFORDANCES = [
    'landing_page',
    'member_management',
    'personal_credentials',
    'installation_credentials',
    'session_management',
    'managed_transitions',
];
const UI_KEYS = [...UI_AFFORDANCES, 'credential_purposes'];

$json = in_array('--json', $argv, true);

function finish(array $result, bool $json, int $code): never
{
    if ($json) {
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        echo ($result['ok'] ? 'OK: ' : 'ERROR: ').$result['message'].PHP_EOL;
    }

    exit($code);
}

function usage(bool $json, string $message): never
{
    finish(['ok' => false, 'message' => $message, 'usage' => 'manifest.php (--check | --write --name=... --slug=... --description=... --icon=https://... --product-url=https://scalpels.app/...) [--root=.] [--json]'], $json, 2);
}

function validAbsoluteHttpsUrl(mixed $url): bool
{
    return is_string($url)
        && filter_var($url, FILTER_VALIDATE_URL) !== false
        && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
        && is_string(parse_url($url, PHP_URL_HOST));
}

function validProductUrl(mixed $url): bool
{
    return validAbsoluteHttpsUrl($url)
        && strtolower((string) parse_url($url, PHP_URL_HOST)) === 'scalpels.app';
}

$options = getopt('', ['check', 'write', 'name:', 'slug:', 'description:', 'icon:', 'product-url:', 'root:', 'json']);
$known = ['--check', '--write', '--json', '--name=', '--slug=', '--description=', '--icon=', '--product-url=', '--root='];
foreach (array_slice($argv, 1) as $argument) {
    $recognized = false;
    foreach ($known as $option) {
        $recognized = $recognized || $argument === $option || str_starts_with($argument, $option);
    }
    if (! $recognized) {
        usage($json, "Unknown argument: {$argument}");
    }
}

$checking = isset($options['check']);
$writing = isset($options['write']);
if ($checking === $writing) {
    usage($json, 'Choose exactly one of --check or --write.');
}

$root = is_string($options['root'] ?? null) ? rtrim($options['root'], DIRECTORY_SEPARATOR) : '.';
if ($root === '') {
    $root = DIRECTORY_SEPARATOR;
}
$file = ($root === DIRECTORY_SEPARATOR ? '' : $root).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'built-for-cloud.php';

if ($writing) {
    $values = [];
    foreach (['name', 'slug', 'description', 'icon', 'product-url'] as $key) {
        if (! is_string($options[$key] ?? null) || trim($options[$key]) === '') {
            usage($json, "--{$key} is required and cannot be empty.");
        }
        $values[$key] = trim($options[$key]);
    }

    $errors = [];
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $values['slug']) !== 1) {
        $errors[] = 'slug must contain lowercase letters, digits, and single hyphens only';
    }
    if (! validProductUrl($values['product-url'])) {
        $errors[] = 'product_url must be an absolute HTTPS URL on scalpels.app';
    }
    if (! validAbsoluteHttpsUrl($values['icon'])) {
        $errors[] = 'icon must be an absolute HTTPS URL';
    }
    if ($errors !== []) {
        finish(['ok' => false, 'message' => implode('; ', $errors), 'errors' => $errors], $json, 1);
    }

    $manifest = [
        'name' => $values['name'],
        'slug' => $values['slug'],
        'description' => $values['description'],
        'icon' => $values['icon'],
        'product_url' => $values['product-url'],
    ];
    $manifestExport = var_export($manifest, true);
    $uiExport = var_export(array_fill_keys(UI_AFFORDANCES, false) + ['credential_purposes' => []], true);
    $content = <<<PHP
        <?php

        declare(strict_types=1);

        return [
            'manifest' => {$manifestExport},

            'credentials' => [
                'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
                'declaration' => null,
                'session_guard' => null,
                'app_purposes' => [],
            ],

            'ui' => {$uiExport},
        ];
        PHP;
    $directory = dirname($file);
    if ((! is_dir($directory) && ! mkdir($directory, 0755, true)) || file_put_contents($file, $content) === false) {
        finish(['ok' => false, 'message' => "Could not write {$file}."], $json, 1);
    }
}

if (! is_file($file)) {
    finish(['ok' => false, 'message' => "Manifest not found: {$file}"], $json, 1);
}

$autoload = ($root === DIRECTORY_SEPARATOR ? '' : $root).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$config = require $file;
$errors = [];
$status = 'configured';
if (! is_array($config) || array_keys($config) !== ['manifest', 'credentials', 'ui']) {
    $errors[] = 'top-level keys must be exactly manifest, credentials, and ui';
} else {
    if (! is_array($config['manifest']) || array_keys($config['manifest']) !== MANIFEST_KEYS) {
        $errors[] = 'manifest keys do not match the frozen shape';
    } else {
        $unconfigured = array_filter($config['manifest'], fn (mixed $value): bool => $value !== null) === [];
        $status = $unconfigured ? 'unconfigured' : 'configured';

        if (! $unconfigured) {
            foreach (MANIFEST_KEYS as $key) {
                if (! is_string($config['manifest'][$key]) || trim($config['manifest'][$key]) === '') {
                    $errors[] = "manifest.{$key} must be a non-empty string";
                }
            }
            if (is_string($config['manifest']['slug'] ?? null) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $config['manifest']['slug']) !== 1) {
                $errors[] = 'manifest.slug has an invalid format';
            }
            if (! validProductUrl($config['manifest']['product_url'] ?? null)) {
                $errors[] = 'manifest.product_url must be an absolute HTTPS URL on scalpels.app';
            }
            if (! validAbsoluteHttpsUrl($config['manifest']['icon'] ?? null)) {
                $errors[] = 'manifest.icon must be an absolute HTTPS URL';
            }
        }
    }
    if (! is_array($config['ui'])) {
        $errors[] = 'ui must be an array';
    } else {
        $uiKeys = array_keys($config['ui']);
        $expectedUiKeys = UI_KEYS;
        sort($uiKeys);
        sort($expectedUiKeys);

        if ($uiKeys !== $expectedUiKeys) {
            $errors[] = 'ui keys do not match the frozen shape';
        } elseif (array_filter(UI_AFFORDANCES, fn (string $key): bool => ! is_bool($config['ui'][$key])) !== []) {
            $errors[] = 'ui affordances must be boolean';
        } elseif (! is_array($config['ui']['credential_purposes'])
            || ! array_is_list($config['ui']['credential_purposes'])
            || array_filter($config['ui']['credential_purposes'], fn (mixed $purpose): bool => ! is_string($purpose)) !== []) {
            $errors[] = 'ui.credential_purposes must be a list of strings';
        }
    }
}

if ($errors !== []) {
    finish(['ok' => false, 'message' => implode('; ', $errors), 'errors' => $errors], $json, 1);
}

finish(['ok' => true, 'status' => $status, 'message' => "Manifest is valid: {$file}", 'file' => $file, 'manifest' => $config['manifest'], 'ui' => $config['ui']], $json, 0);

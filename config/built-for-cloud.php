<?php

declare(strict_types=1);
use App\Http\Controllers\SenderDashboardController;
use App\Support\McpDeploymentContext;

$managedConsoleIssuer = env('BUILT_FOR_CLOUD_CONSOLE_ISSUER');
$managedConsoleAudience = env('BUILT_FOR_CLOUD_CONSOLE_AUDIENCE');

return [
    'dashboard' => SenderDashboardController::class,

    'manifest' => [
        'name' => 'Witness',
        'slug' => 'witness',
        'description' => 'Self-hosted simple e-signature for PDFs with an auditable signing trail.',
        'icon' => 'https://scalpels.app/img/products/transparent/witness.png',
        'product_url' => 'https://scalpels.app/products/witness',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [],
    ],

    'console' => [
        'issuer' => is_string($managedConsoleIssuer) && $managedConsoleIssuer !== '' ? $managedConsoleIssuer : null,
        'audience' => McpDeploymentContext::audience($managedConsoleAudience, config('app.key')),
        'assertion_max_ttl_seconds' => env('BUILT_FOR_CLOUD_CONSOLE_ASSERTION_MAX_TTL', 120),
        'clock_skew_seconds' => env('BUILT_FOR_CLOUD_CONSOLE_CLOCK_SKEW', 5),
    ],

    'mcp' => [
        'path' => '/mcp',
        'write_path' => null,
        'destructive_path' => '/mcp/destructive',
        'delegated' => McpDeploymentContext::delegated($managedConsoleIssuer, $managedConsoleAudience),
        'two_phase' => [
            'cache_store' => 'database',
            'ttl_seconds' => 300,
        ],
    ],

    'ui' => [
        'landing_page' => true,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => [],
    ],
];

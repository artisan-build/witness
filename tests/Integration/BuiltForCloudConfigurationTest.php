<?php

use App\Http\Controllers\SenderDashboardController;
use App\Support\McpDeploymentContext;
use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('owns the application manifest UI overlay and sender dashboard target', function (): void {
    /** @var array<string, mixed> $appConfig */
    $appConfig = require config_path('built-for-cloud.php');
    $managedIssuer = env('BUILT_FOR_CLOUD_CONSOLE_ISSUER');
    $managedAudience = env('BUILT_FOR_CLOUD_CONSOLE_AUDIENCE');

    expect($appConfig)->toBe([
        'dashboard' => SenderDashboardController::class,
        'manifest' => [
            'name' => 'Witness',
            'slug' => 'witness',
            'description' => 'Self-hosted simple e-signature for PDFs with an auditable signing trail.',
            'icon' => 'https://scalpels.app/img/products/transparent/witness.png',
            'product_url' => 'https://scalpels.app/products/witness',
        ],
        'credentials' => [
            'guard' => 'bfc',
            'declaration' => null,
            'session_guard' => null,
            'app_purposes' => [],
        ],
        'console' => [
            'issuer' => is_string($managedIssuer) && $managedIssuer !== '' ? $managedIssuer : null,
            'audience' => McpDeploymentContext::audience($managedAudience, config('app.key')),
            'assertion_max_ttl_seconds' => 120,
            'clock_skew_seconds' => 5,
        ],
        'mcp' => [
            'path' => '/mcp',
            'write_path' => null,
            'destructive_path' => '/mcp/destructive',
            'delegated' => McpDeploymentContext::delegated($managedIssuer, $managedAudience),
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
    ]);
});

it('merges package defaults and auto-discovers the released provider', function (): void {
    expect(config('built-for-cloud.manifest'))->toBe([
        'name' => 'Witness',
        'slug' => 'witness',
        'description' => 'Self-hosted simple e-signature for PDFs with an auditable signing trail.',
        'icon' => 'https://scalpels.app/img/products/transparent/witness.png',
        'product_url' => 'https://scalpels.app/products/witness',
    ])->and(config('built-for-cloud.ui'))->toBe([
        'landing_page' => true,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => [],
    ])->and(config('built-for-cloud.dashboard'))->toBe(SenderDashboardController::class)
        ->and(config('built-for-cloud.product'))->toBe(config('app.name'))
        ->and(config('built-for-cloud.credentials.guard'))->toBe('bfc')
        ->and(config('built-for-cloud.console.audience'))->toBe(
            McpDeploymentContext::audience(env('BUILT_FOR_CLOUD_CONSOLE_AUDIENCE'), config('app.key')),
        )
        ->and(config('built-for-cloud.mcp.delegated'))->toBe(
            McpDeploymentContext::delegated(
                env('BUILT_FOR_CLOUD_CONSOLE_ISSUER'),
                env('BUILT_FOR_CLOUD_CONSOLE_AUDIENCE'),
            ),
        )
        ->and(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(app()->getLoadedProviders())->toHaveKey(BuiltForCloudServiceProvider::class, true);
});

it('derives a stable per-install audience while preserving the managed deployment identity', function (): void {
    $first = McpDeploymentContext::audience(null, 'base64:first-install-key');

    expect($first)->toBe(McpDeploymentContext::audience(null, 'base64:first-install-key'))
        ->not->toBe(McpDeploymentContext::audience(null, 'base64:second-install-key'))
        ->not->toContain((string) config('app.url'))
        ->and(McpDeploymentContext::audience('managed-deployment-id', 'base64:first-install-key'))
        ->toBe('managed-deployment-id')
        ->and(McpDeploymentContext::audience(null, null))->toBeNull()
        ->and(McpDeploymentContext::delegated('https://console.example.test', 'managed-deployment-id'))->toBeTrue()
        ->and(McpDeploymentContext::delegated(null, 'managed-deployment-id'))->toBeFalse()
        ->and(McpDeploymentContext::delegated('https://console.example.test', null))->toBeFalse();
});

it('runs fresh package-owned migrations on PostgreSQL', function (): void {
    expect(Artisan::call('migrate:fresh', [
        '--database' => 'pgsql',
        '--force' => true,
    ]))->toBe(0)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('bfc_authority'))->toBeTrue()
        ->and(Schema::hasTable('credentials'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'normalized_email'))->toBeTrue()
        ->and(glob(database_path('migrations/*users*')) ?: [])->toBe([])
        ->and(class_exists('App\\Models\\User'))->toBeFalse();
});

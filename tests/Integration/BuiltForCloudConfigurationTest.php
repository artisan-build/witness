<?php

use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('owns only the D-UI-3 application configuration overlay', function (): void {
    /** @var array<string, mixed> $appConfig */
    $appConfig = require config_path('built-for-cloud.php');

    expect($appConfig)->toBe([
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
    ])->and(config('built-for-cloud.product'))->toBe(config('app.name'))
        ->and(config('built-for-cloud.credentials.guard'))->toBe('bfc')
        ->and(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(app()->getLoadedProviders())->toHaveKey(BuiltForCloudServiceProvider::class, true);
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

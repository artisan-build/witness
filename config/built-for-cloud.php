<?php

declare(strict_types=1);
use App\Http\Controllers\SenderDashboardController;

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

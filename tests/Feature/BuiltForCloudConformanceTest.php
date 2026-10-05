<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Commands\ConsoleReKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleRetireKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\CreateAdminCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialActivateCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialListCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialMintCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRevokeCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRotateCommand;
use ArtisanBuild\BuiltForCloud\Commands\FreshCommand;
use ArtisanBuild\BuiltForCloud\Commands\HmacRewrapCommand;
use ArtisanBuild\BuiltForCloud\Commands\InstallOperatorCredentialCommand;
use ArtisanBuild\BuiltForCloud\Commands\OutboxDrainCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipMintClaimCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipRemintOwnerTokenCommand;
use ArtisanBuild\BuiltForCloud\Commands\PruneCredentialAuthorizationsCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand;
use ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand;
use ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use Composer\InstalledVersions;

uses(ContractAssertions::class);

it('passes the version 1 reference-consumer conformance spec', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    $packageRoot = InstalledVersions::getInstallPath('artisan-build/built-for-cloud');
    expect($packageRoot)->toBeString();

    $purposeMappings = [];
    foreach ((array) config('built-for-cloud.credentials.app_purposes', []) as $appPurpose => $purpose) {
        $purposeMappings[$appPurpose] = CredentialPurpose::from($purpose);
    }
    ksort($purposeMappings);

    $sourceRoots = [app_path(), base_path('bootstrap'), config_path(), base_path('routes')];
    sort($sourceRoots);
    $providerFiles = [app_path('Providers/AppServiceProvider.php'), $packageRoot.'/src/BuiltForCloudServiceProvider.php'];
    sort($providerFiles);

    $sorted = static function (array $members): array {
        sort($members);

        return $members;
    };

    $expected = [
        'runtime.meta' => [],
        'runtime.auth_schema' => [],
        'runtime.credential_listing' => [],
        'runtime.transport_parity' => [],
        'thin_host' => [],
        'credential_paths' => $sorted([
            'path:Basic|ArtisanBuild\BuiltForCloud\Auth\BasicAuthenticator',
            'path:Bearer|ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator',
            'path:HMAC|Http\Middleware\VerifyHmacSignature+Hmac\HmacVerifier',
            'path:MCP|Http\Middleware\AuthenticateMcp:store-bearer+v4.public',
            'path:asymmetric|Actions\MintCredential::mintEnrollment+CompleteAsymmetricEnrollment+AsymmetricVerificationKeys',
            'path:device|Http\Controllers\DeviceAuthorizations+Actions\StartDeviceAuthorization/DecideDeviceAuthorization/PollDeviceAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:enrollment|OnboardingToken+POST:/bfc/claim,/bfc/onboarding/issue,/exchange,/verify',
            'path:loopback|Http\Controllers\LoopbackAuthorizations+Actions\StartLoopbackAuthorization/DecideLoopbackAuthorization/ExchangeLoopbackAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:system|SubjectType::Operator/Application/Installation+AuditActorType::CliOperator',
        ]),
        'credential_writers' => $sorted([
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSecretBearing',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithPendingSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithSecret',
            'ArtisanBuild\BuiltForCloud\OwnerCredentialMinter::mintFromHash',
            'ArtisanBuild\BuiltForCloud\UnifiedStoreCredentialMinter::mint',
        ]),
        'legacy_removal' => [],
        'system_authority' => $sorted([
            ConsoleReKeyCommand::class,
            ConsoleRetireKeyCommand::class,
            CreateAdminCommand::class,
            CredentialActivateCommand::class,
            CredentialListCommand::class,
            CredentialMintCommand::class,
            CredentialRevokeCommand::class,
            CredentialRotateCommand::class,
            FreshCommand::class,
            HmacRewrapCommand::class,
            InstallOperatorCredentialCommand::class,
            OutboxDrainCommand::class,
            OwnershipMintClaimCommand::class,
            OwnershipRemintOwnerTokenCommand::class,
            PruneCredentialAuthorizationsCommand::class,
            SigningRootProvisionCommand::class,
            SubjectOffboardCommand::class,
            WarnExpiringCredentialsCommand::class,
            DeliverOwnershipWebhook::class,
            'Closure@package/src/SystemAuthoritySchedule.php:27',
        ]),
        'no_signing_path' => [],
        'ui_config_reads' => $sorted([
            'ArtisanBuild\BuiltForCloud\AppPurposeRegistry|built-for-cloud.credentials.app_purposes|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\ManageTransitions|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.installation_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.member_management|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.personal_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.session_management|1',
            'ArtisanBuild\BuiltForCloud\LandingManifest|built-for-cloud.manifest|1',
            'ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationStore|built-for-cloud.manifest.slug|1',
            'ArtisanBuild\BuiltForCloud\UiCredentialPurposes|built-for-cloud.ui.credential_purposes|1',
        ]),
        'mcp_delegated' => [],
    ];

    $report = (new FleetConformance($this))->assert(new ConsumerConformance(
        consumer: 'witness',
        consumerRoot: base_path(),
        packageRoot: $packageRoot,
        sourceRoots: $sourceRoots,
        providerFiles: $providerFiles,
        runtimeAssertions: ['auth_schema', 'credential_listing', 'meta', 'transport_parity'],
        capabilities: ['credentials', 'tokens'],
        purposeMappings: $purposeMappings,
        mcpServer: null,
        expected: $expected,
    ));

    expect($report->passed)->toBeTrue()
        ->and(array_keys($report->families))->toBe(ConsumerConformance::FAMILIES);
});

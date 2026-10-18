<?php

declare(strict_types=1);

use App\Listeners\CompleteReadyEnvelope;
use App\Mcp\Tools\EnvelopeStatus;
use App\Mcp\Tools\ListEnvelopes;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\Remind;
use App\Mcp\Tools\SendFromTemplate;
use App\Mcp\Tools\VoidEnvelope;
use App\Mcp\WitnessMcpServer;
use App\Models\Envelope;
use App\Models\Team;
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
use ArtisanBuild\BuiltForCloud\Commands\SigningRootEnsureCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand;
use ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand;
use ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedEnforcement;
use Composer\InstalledVersions;
use Laravel\Mcp\ResponseFactory;
use Tests\Support\ManagedMcpContext;

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
    $mcpTools = $sorted([
        EnvelopeStatus::class,
        ListEnvelopes::class,
        ListTemplates::class,
        Remind::class,
        SendFromTemplate::class,
        VoidEnvelope::class,
    ]);

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
            CompleteReadyEnvelope::class,
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
            SigningRootEnsureCommand::class,
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
        'mcp_delegated' => $mcpTools,
    ];

    $issuer = 'https://bfc-enforcement.test';
    ManagedMcpContext::configure($issuer);
    $primaryUser = ManagedMcpContext::user($issuer, 'bfc-enforcement-primary');
    ManagedMcpContext::user($issuer, 'bfc-enforcement-secondary');
    $primaryTeam = Team::factory()->create();
    $primaryTeam->users()->attach($primaryUser);
    config(['built-for-cloud.mcp.delegated' => true]);

    McpDelegatedEnforcement::assertHonors(
        WitnessMcpServer::class,
        $mcpTools,
        negativeAuthorization: static function (
            ActingPrincipal $first,
            ActingPrincipal $second,
            Closure $invoke,
        ) use ($primaryTeam, $primaryUser): void {
            expect($first->identifier())->not->toBe($second->identifier());

            $envelope = Envelope::factory()->forOwner($primaryTeam, $primaryUser)->create([
                'title' => 'Primary delegated subject only',
            ]);
            $response = $invoke(ListEnvelopes::class);

            expect($response)->toBeInstanceOf(ResponseFactory::class)
                ->and($response->getStructuredContent()['envelopes'])->toBe([])
                ->and(collect($response->getStructuredContent()['envelopes'])->pluck('id')->all())
                ->not->toContain($envelope->getKey());
        },
        arguments: [
            SendFromTemplate::class => [
                'template_id' => PHP_INT_MAX,
                'roles' => [[
                    'role' => 'Signer',
                    'name' => 'Delegated enforcement probe',
                    'email' => 'delegated-probe@example.test',
                ]],
            ],
            VoidEnvelope::class => [
                'envelope_id' => PHP_INT_MAX,
                'reason' => 'Delegated enforcement probe.',
            ],
        ],
    );
    ManagedMcpContext::restoreStandaloneAuthority();

    $report = (new FleetConformance($this))->assert(new ConsumerConformance(
        consumer: 'witness',
        consumerRoot: base_path(),
        packageRoot: $packageRoot,
        sourceRoots: $sourceRoots,
        providerFiles: $providerFiles,
        runtimeAssertions: ['auth_schema', 'credential_listing', 'meta', 'transport_parity'],
        capabilities: ['credentials', 'mcp-delegated', 'tokens'],
        purposeMappings: $purposeMappings,
        mcpServer: WitnessMcpServer::class,
        expected: $expected,
    ));

    expect($report->passed)->toBeTrue()
        ->and(array_keys($report->families))->toBe(ConsumerConformance::FAMILIES);
});

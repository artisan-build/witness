<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Authorization\WitnessMcpAbility;
use App\Enums\EnvelopeStatus;
use App\Support\McpDeploymentContext;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseCallTool;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class McpDeploymentContextRouteTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        foreach ($this->consoleEnvironment() as $name => $value) {
            $this->setEnvironment($name, $value);
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->previousEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                $_ENV = array_diff_key($_ENV, [$name => true]);
                $_SERVER = array_diff_key($_SERVER, [$name => true]);

                continue;
            }

            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public function test_booted_context_drives_the_production_two_phase_route(): void
    {
        $environment = $this->consoleEnvironment();
        $managedIssuer = $environment['BUILT_FOR_CLOUD_CONSOLE_ISSUER'];
        $managedAudience = $environment['BUILT_FOR_CLOUD_CONSOLE_AUDIENCE'];
        $issuer = $managedIssuer !== '' ? $managedIssuer : null;
        $audience = McpDeploymentContext::audience($managedAudience, config('app.key'));
        $delegated = McpDeploymentContext::delegated($managedIssuer, $managedAudience);

        self::assertSame($issuer, config('built-for-cloud.console.issuer'));
        self::assertSame($audience, config('built-for-cloud.console.audience'));
        self::assertSame($delegated, config('built-for-cloud.mcp.delegated'));
        $this->getJson('/bfc/meta')->assertOk()->assertJsonPath(
            'capabilities',
            fn (array $capabilities): bool => in_array('mcp-delegated', $capabilities, true) === $delegated,
        );

        [$envelope] = createSigningEnvelope();
        $plaintext = 'managed-mcp-'.Str::random(32);
        Credential::query()->create([
            'kind' => CredentialKind::Bearer,
            'purpose' => CredentialPurpose::Mcp,
            'subject_type' => SubjectType::UserPrincipal,
            'subject_ref' => 'user:'.Str::uuid(),
            'name' => 'Deployment-context MCP test credential',
            'abilities' => [WitnessMcpAbility::Destructive->value],
            'user_id' => (string) $envelope->sender_id,
            'secret_hash' => hash('sha256', $plaintext),
            'status' => CredentialStatus::Active,
        ]);
        $arguments = [
            'envelope_id' => $envelope->getKey(),
            'reason' => 'Deployment context route proof.',
        ];

        $preview = $this->callTool($plaintext, $arguments)->assertOk();
        $confirmation = $preview->json('result._meta.two_phase.confirmation');
        self::assertIsString($confirmation);
        self::assertNotSame('', $confirmation);
        self::assertSame('preview', $preview->json('result._meta.two_phase.phase'));
        self::assertSame(EnvelopeStatus::Sent, $envelope->fresh()->status);

        $otherDeployment = McpDeploymentContext::audience(null, 'base64:cross-deployment-key');
        self::assertIsString($otherDeployment);
        self::assertNotSame($audience, $otherDeployment);
        config(['built-for-cloud.console.audience' => $otherDeployment]);
        $this->callTool($plaintext, [...$arguments, 'confirm' => $confirmation])
            ->assertStatus(400)
            ->assertJsonPath('error.message', 'confirmation_mismatched');
        self::assertSame(EnvelopeStatus::Sent, $envelope->fresh()->status);
        config(['built-for-cloud.console.audience' => $audience]);

        $executed = $this->callTool($plaintext, [...$arguments, 'confirm' => $confirmation])->assertOk();
        self::assertSame('executed', $executed->json('result._meta.two_phase.phase'));
        self::assertSame(EnvelopeStatus::Voided, $envelope->fresh()->status);

        $this->callTool($plaintext, [...$arguments, 'confirm' => $confirmation])
            ->assertStatus(400)
            ->assertJsonPath('error.code', TwoPhaseCallTool::REFUSAL_CODE)
            ->assertJsonPath('error.message', 'confirmation_spent');
    }

    /** @return array{BUILT_FOR_CLOUD_CONSOLE_ISSUER: string, BUILT_FOR_CLOUD_CONSOLE_AUDIENCE: string} */
    abstract protected function consoleEnvironment(): array;

    /** @param array<string, mixed> $arguments */
    private function callTool(string $plaintext, array $arguments): TestResponse
    {
        return $this->postJson('/mcp/destructive', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'void',
                'arguments' => $arguments,
            ],
        ], ['Authorization' => 'Bearer '.$plaintext]);
    }

    private function setEnvironment(string $name, string $value): void
    {
        $this->previousEnvironment[$name] = getenv($name);
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

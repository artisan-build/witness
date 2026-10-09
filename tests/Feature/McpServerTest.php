<?php

declare(strict_types=1);

use App\Actions\InstantiateAndSendTemplate;
use App\Actions\SendEnvelope;
use App\Authorization\WitnessMcpAbility;
use App\Enums\EnvelopeStatus as EnvelopeStatusEnum;
use App\Enums\FieldType;
use App\Enums\SignerStatus;
use App\Mail\SigningInvitation;
use App\Mcp\Tools\EnvelopeStatus;
use App\Mcp\Tools\ListEnvelopes;
use App\Mcp\Tools\ListTemplates;
use App\Mcp\Tools\Remind;
use App\Mcp\Tools\SendFromTemplate;
use App\Mcp\Tools\VoidEnvelope;
use App\Mcp\WitnessMcpServer;
use App\Models\Envelope;
use App\Models\Team;
use App\Models\Template;
use App\Support\McpDeploymentContext;
use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAbilityRegistry;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseCallTool;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;
use Tests\Support\PdfFixture;

function bindWitnessMcpCredential(?User $user, array $abilities): Credential
{
    $credential = new Credential;
    $credential->forceFill([
        'id' => 9001,
        'purpose' => CredentialPurpose::Mcp,
        'status' => CredentialStatus::Active,
        'abilities' => $abilities,
        'user_id' => $user === null ? null : (string) $user->getKey(),
    ]);

    $request = resolve('request');
    expect($request)->toBeInstanceOf(HttpRequest::class);
    $request->setUserResolver(static fn (): Credential => $credential);

    return $credential;
}

/** @param list<string> $abilities
 * @return array{credential: Credential, plaintext: string}
 */
function createWitnessMcpCredential(?User $user, array $abilities): array
{
    $plaintext = 'witness-mcp-'.Str::random(32);
    $credential = Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => $user === null ? SubjectType::Installation : SubjectType::UserPrincipal,
        'subject_ref' => ($user === null ? 'installation:' : 'user:').Str::uuid(),
        'name' => 'Witness MCP test credential',
        'abilities' => null,
        'user_id' => $user === null ? null : (string) $user->getKey(),
        'secret_hash' => hash('sha256', $plaintext),
        'status' => CredentialStatus::Active,
    ]);

    DB::table('credentials')->where('id', $credential->getKey())->update([
        'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
    ]);

    return ['credential' => $credential->refresh(), 'plaintext' => $plaintext];
}

/** @param array<string, mixed> $params */
function witnessMcpRpc(string $path, string $plaintext, string $method, array $params = [], int|string $id = 1): TestResponse
{
    return test()->postJson($path, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => $method,
        'params' => $params,
    ], ['Authorization' => 'Bearer '.$plaintext]);
}

/** @param array<string, mixed> $arguments */
function callWitnessMcpTool(string $path, string $plaintext, string $tool, array $arguments, int|string $id = 1): TestResponse
{
    return witnessMcpRpc($path, $plaintext, 'tools/call', [
        'name' => $tool,
        'arguments' => $arguments,
    ], $id);
}

function witnessDelegatedAssertion(): string
{
    config([
        'built-for-cloud.console.issuer' => 'https://witness-console.test',
        'built-for-cloud.console.audience' => 'witness-test-deployment',
    ]);

    if (! app()->bound('witness.testing.console-key')) {
        do {
            $key = AsymmetricSecretKey::generate(new Version4);
        } while (strlen($key->raw()) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);

        (new ConsoleKeyring)->activate(
            (new ConsoleKeyring)->add('witness-test-key', $key->getPublicKey()->toHexString())->key_id,
        );
        app()->instance('witness.testing.console-key', $key);
    }

    /** @var AsymmetricSecretKey $key */
    $key = resolve('witness.testing.console-key');
    $now = CarbonImmutable::now();

    return (new Builder)
        ->setVersion(new Version4)
        ->setPurpose(Purpose::public())
        ->setKey($key)
        ->setClaims([
            'iss' => 'https://witness-console.test',
            'sub' => 'operator-test',
            'aud' => 'witness-test-deployment',
            'iat' => $now->toAtomString(),
            'nbf' => $now->toAtomString(),
            'exp' => $now->addSeconds(90)->toAtomString(),
            'jti' => 'mint_'.bin2hex(random_bytes(8)),
            'display_name' => 'Witness Operator',
            'role' => 'admin',
            'purpose' => 'mcp',
        ])
        ->setFooterArray(['kid' => 'witness-test-key'])
        ->toString();
}

it('registers the exact effect-scoped MCP doors and six conforming tools', function (): void {
    $managedIssuer = env('BUILT_FOR_CLOUD_CONSOLE_ISSUER');
    $managedAudience = env('BUILT_FOR_CLOUD_CONSOLE_AUDIENCE');
    $delegated = McpDeploymentContext::delegated($managedIssuer, $managedAudience);
    $routes = collect(resolve('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('POST', $route->methods(), true) && str_starts_with($route->uri(), 'mcp'))
        ->values();

    expect($routes)->toHaveCount(2)
        ->and($routes->pluck('uri')->all())->toBe(['mcp', 'mcp/destructive'])
        ->and($routes[0]->middleware())->toContain('bfc.mcp:product,read')
        ->and($routes[1]->middleware())->toContain('bfc.mcp:product,destructive')
        ->and(collect($routes)->flatMap->middleware()->filter(fn (string $middleware): bool => str_starts_with($middleware, 'bfc.ability:')))->toBeEmpty()
        ->and(config('built-for-cloud.mcp.path'))->toBe('/mcp')
        ->and(config('built-for-cloud.mcp.write_path'))->toBeNull()
        ->and(config('built-for-cloud.mcp.destructive_path'))->toBe('/mcp/destructive')
        ->and(config('built-for-cloud.mcp.delegated'))->toBe($delegated)
        ->and(config('built-for-cloud.console.issuer'))->toBe(is_string($managedIssuer) && $managedIssuer !== '' ? $managedIssuer : null)
        ->and(config('built-for-cloud.console.audience'))->toBe(McpDeploymentContext::audience($managedAudience, config('app.key')))
        ->and(config('built-for-cloud.mcp.two_phase.cache_store'))->toBe('database')
        ->and(resolve(CredentialAbilityRegistry::class)->all())->toBe([
            WitnessMcpAbility::Read->value,
            WitnessMcpAbility::Destructive->value,
        ]);

    $metadata = $this->getJson('/bfc/meta')->assertOk();
    expect($metadata->json('capabilities'))->toContain('mcp-serve', 'mcp-effect-scoped')
        ->and(in_array('mcp-delegated', $metadata->json('capabilities'), true))->toBe($delegated)
        ->and($metadata->json('endpoints'))->toMatchArray([
            'mcp' => '/mcp',
            'mcp_destructive' => '/mcp/destructive',
        ]);

    McpDelegatedTools::assertConforms(WitnessMcpServer::class);
    $discovered = McpDelegatedTools::discover(WitnessMcpServer::class);

    expect($discovered['tools'])->toBe([
        EnvelopeStatus::class,
        ListEnvelopes::class,
        ListTemplates::class,
        Remind::class,
        SendFromTemplate::class,
        VoidEnvelope::class,
    ]);

    foreach ([ListTemplates::class, ListEnvelopes::class, EnvelopeStatus::class, SendFromTemplate::class, Remind::class, VoidEnvelope::class] as $toolClass) {
        $tool = resolve($toolClass)->toArray();
        expect($tool['inputSchema']['additionalProperties'])->toBeFalse()
            ->and($tool['_meta']['classification'])->toBe('content');
    }

    foreach ([SendFromTemplate::class, Remind::class, VoidEnvelope::class] as $toolClass) {
        $tool = resolve($toolClass)->toArray();
        expect($tool['_meta']['effect'])->toBe('destructive')
            ->and($tool['_meta'][TwoPhase::META_KEY]['confirmationArgument'])->toBe('confirm')
            ->and($tool['inputSchema']['properties'])->toHaveKey('confirm');
    }
});

it('exposes exact tool inventories and refuses destructive calls through the read door', function (): void {
    $user = UserFactory::new()->create();
    $credential = createWitnessMcpCredential($user, [
        WitnessMcpAbility::Read->value,
        WitnessMcpAbility::Destructive->value,
    ]);

    $read = witnessMcpRpc('/mcp', $credential['plaintext'], 'tools/list')->assertOk();
    $destructive = witnessMcpRpc('/mcp/destructive', $credential['plaintext'], 'tools/list')->assertOk();

    $snapshot = static fn (TestResponse $response): array => collect($response->json('result.tools'))
        ->mapWithKeys(fn (array $tool): array => [$tool['name'] => [
            'classification' => data_get($tool, '_meta.classification'),
            'effect' => data_get($tool, '_meta.effect'),
            'two_phase' => data_get($tool, '_meta.two_phase'),
        ]])
        ->all();

    expect($snapshot($read))->toBe([
        'list_templates' => ['classification' => 'content', 'effect' => 'read', 'two_phase' => null],
        'list_envelopes' => ['classification' => 'content', 'effect' => 'read', 'two_phase' => null],
        'envelope_status' => ['classification' => 'content', 'effect' => 'read', 'two_phase' => null],
    ])->and($snapshot($destructive))->toBe([
        'list_templates' => ['classification' => 'content', 'effect' => 'read', 'two_phase' => null],
        'list_envelopes' => ['classification' => 'content', 'effect' => 'read', 'two_phase' => null],
        'envelope_status' => ['classification' => 'content', 'effect' => 'read', 'two_phase' => null],
        'send_from_template' => ['classification' => 'content', 'effect' => 'destructive', 'two_phase' => ['confirmationArgument' => 'confirm', 'protocolVersion' => 1]],
        'remind' => ['classification' => 'content', 'effect' => 'destructive', 'two_phase' => ['confirmationArgument' => 'confirm', 'protocolVersion' => 1]],
        'void' => ['classification' => 'content', 'effect' => 'destructive', 'two_phase' => ['confirmationArgument' => 'confirm', 'protocolVersion' => 1]],
    ]);

    callWitnessMcpTool('/mcp', $credential['plaintext'], 'void', [
        'envelope_id' => 1,
        'reason' => 'Must not execute.',
    ])->assertStatus(400)->assertExactJson([
        'jsonrpc' => '2.0',
        'id' => 1,
        'error' => [
            'code' => RequestEffectCeiling::REFUSAL_CODE,
            'message' => RequestEffectCeiling::REFUSAL_MESSAGE,
        ],
    ]);
});

it('refuses unbound and wrong-ability credentials before any Witness domain query', function (): void {
    bindWitnessMcpCredential(null, [WitnessMcpAbility::Read->value]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/\b(templates|envelopes|teams|team_user|signers|audit_events)\b/', $query->sql) === 1) {
            $queries[] = $query->sql;
        }
    });

    $response = resolve(ListTemplates::class)->handle(new Request);
    expect($response->isError())->toBeTrue()->and($queries)->toBeEmpty();

    $user = UserFactory::new()->create();
    bindWitnessMcpCredential($user, ['witness.read.lookalike']);
    $response = resolve(ListEnvelopes::class)->handle(new Request);
    expect($response->isError())->toBeTrue()->and($queries)->toBeEmpty();

    $revokedPrincipal = UserFactory::new()->create();
    $revokedTeam = Team::factory()->create();
    $revokedTeam->users()->attach($revokedPrincipal);
    $revoked = bindWitnessMcpCredential($revokedPrincipal, [WitnessMcpAbility::Read->value]);
    $revoked->forceFill(['revoked_at' => now()]);
    $response = resolve(ListTemplates::class)->handle(new Request);
    expect($response->isError())->toBeTrue()->and($queries)->toBeEmpty();

    $pendingPrincipal = UserFactory::new()->create();
    $pendingTeam = Team::factory()->create();
    $pendingTeam->users()->attach($pendingPrincipal);
    $pending = bindWitnessMcpCredential($pendingPrincipal, [WitnessMcpAbility::Read->value]);
    $pending->forceFill(['status' => CredentialStatus::Pending]);
    $response = resolve(ListTemplates::class)->handle(new Request);
    expect($response->isError())->toBeTrue()->and($queries)->toBeEmpty();

    $expiredPrincipal = UserFactory::new()->create();
    $expiredTeam = Team::factory()->create();
    $expiredTeam->users()->attach($expiredPrincipal);
    $expired = bindWitnessMcpCredential($expiredPrincipal, [WitnessMcpAbility::Read->value]);
    $expired->forceFill(['expires_at' => now()->subHour()]);
    $response = resolve(ListEnvelopes::class)->handle(new Request);
    expect($response->isError())->toBeTrue()->and($queries)->toBeEmpty();
});

it('fails closed at every concrete route entry point for inexact abilities and unbound principals', function (): void {
    $user = UserFactory::new()->create();
    $tools = [
        ['list_templates', []],
        ['list_envelopes', []],
        ['envelope_status', ['envelope_id' => PHP_INT_MAX]],
        ['send_from_template', ['template_id' => PHP_INT_MAX, 'roles' => [['role' => 'Signer', 'name' => 'Test Signer', 'email' => 'signer@example.test']]]],
        ['remind', ['envelope_id' => PHP_INT_MAX]],
        ['void', ['envelope_id' => PHP_INT_MAX, 'reason' => 'Authorization probe.']],
    ];
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/\b(templates|envelopes|teams|team_user|signers|audit_events)\b/', $query->sql) === 1) {
            $queries[] = $query->sql;
        }
    });

    foreach ([[], ['witness'], ['witness.read.extra'], ['witness_read']] as $abilities) {
        $credential = createWitnessMcpCredential($user, $abilities);
        foreach ($tools as [$tool, $arguments]) {
            callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], $tool, $arguments)
                ->assertOk()
                ->assertJsonPath('result.isError', true);
        }
    }

    $readOnly = createWitnessMcpCredential($user, [WitnessMcpAbility::Read->value]);
    foreach (array_slice($tools, 3) as [$tool, $arguments]) {
        callWitnessMcpTool('/mcp/destructive', $readOnly['plaintext'], $tool, $arguments)
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    $destructiveOnly = createWitnessMcpCredential($user, [WitnessMcpAbility::Destructive->value]);
    foreach (array_slice($tools, 0, 3) as [$tool, $arguments]) {
        callWitnessMcpTool('/mcp/destructive', $destructiveOnly['plaintext'], $tool, $arguments)
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    $unbound = createWitnessMcpCredential(null, [WitnessMcpAbility::Read->value, WitnessMcpAbility::Destructive->value]);
    foreach ($tools as [$tool, $arguments]) {
        callWitnessMcpTool('/mcp/destructive', $unbound['plaintext'], $tool, $arguments)
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    foreach ($tools as [$tool, $arguments]) {
        callWitnessMcpTool('/mcp/destructive', witnessDelegatedAssertion(), $tool, $arguments)
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    expect($queries)->toBeEmpty();
});

it('refuses a product-role-revoked bound principal before dispatching any tool', function (): void {
    $user = UserFactory::new()->create();
    $credential = createWitnessMcpCredential($user, [WitnessMcpAbility::Read->value, WitnessMcpAbility::Destructive->value]);
    DB::table('users')->where('id', $user->getKey())->update(['role' => 'revoked']);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/\b(templates|envelopes|teams|team_user|signers|audit_events)\b/', $query->sql) === 1) {
            $queries[] = $query->sql;
        }
    });

    foreach (['list_templates', 'list_envelopes', 'envelope_status', 'send_from_template', 'remind', 'void'] as $tool) {
        callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], $tool, [])->assertUnauthorized();
    }

    expect($queries)->toBeEmpty();
});

it('bounds deterministic reads to caller-owned records in current teams', function (): void {
    $sender = UserFactory::new()->create();
    $other = UserFactory::new()->create();
    $currentTeam = Team::factory()->create();
    $removedTeam = Team::factory()->create();
    $foreignTeam = Team::factory()->create();
    $currentTeam->users()->attach($sender);
    $removedTeam->users()->attach($sender);
    $foreignTeam->users()->attach($other);
    $visibleTemplate = Template::factory()->forOwner($currentTeam, $sender)->create(['name' => 'Visible template']);
    Template::factory()->forOwner($removedTeam, $sender)->create(['name' => 'Removed template']);
    Template::factory()->forOwner($foreignTeam, $other)->create(['name' => 'Foreign template']);
    $visibleEnvelope = Envelope::factory()->forOwner($currentTeam, $sender)->create(['title' => 'Visible envelope']);
    Envelope::factory()->forOwner($removedTeam, $sender)->create(['title' => 'Removed envelope']);
    Envelope::factory()->forOwner($foreignTeam, $other)->create(['title' => 'Foreign envelope']);
    $removedTeam->users()->detach($sender);
    bindWitnessMcpCredential($sender, [WitnessMcpAbility::Read->value]);

    $templates = resolve(ListTemplates::class)->handle(new Request);
    $envelopes = resolve(ListEnvelopes::class)->handle(new Request(['status' => EnvelopeStatusEnum::Draft->value]));
    $status = resolve(EnvelopeStatus::class)->handle(new Request(['envelope_id' => $visibleEnvelope->getKey()]));
    $foreignStatus = resolve(EnvelopeStatus::class)->handle(new Request(['envelope_id' => $visibleEnvelope->getKey() + 2]));

    expect($templates)->toBeInstanceOf(ResponseFactory::class)
        ->and($templates->getStructuredContent()['templates'])->toHaveCount(1)
        ->and($templates->getStructuredContent()['templates'][0]['id'])->toBe($visibleTemplate->getKey())
        ->and($envelopes)->toBeInstanceOf(ResponseFactory::class)
        ->and($envelopes->getStructuredContent()['envelopes'])->toHaveCount(1)
        ->and($envelopes->getStructuredContent()['envelopes'][0]['id'])->toBe($visibleEnvelope->getKey())
        ->and($status)->toBeInstanceOf(ResponseFactory::class)
        ->and($status->getStructuredContent()['envelope']['id'])->toBe($visibleEnvelope->getKey())
        ->and($foreignStatus->isError())->toBeTrue();

    $encoded = json_encode($status->getStructuredContent(), JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('storage_path')->not->toContain('link_token')->not->toContain('otp_')->not->toContain('hash');
});

it('rejects unknown and invalid arguments without confirmations or raw exceptions', function (): void {
    $user = UserFactory::new()->create();
    $credential = createWitnessMcpCredential($user, [WitnessMcpAbility::Read->value, WitnessMcpAbility::Destructive->value]);
    $calls = [
        ['/mcp', 'list_templates', ['unexpected' => true]],
        ['/mcp', 'list_envelopes', ['status' => 'not-a-status']],
        ['/mcp', 'envelope_status', ['envelope_id' => 0]],
        ['/mcp/destructive', 'send_from_template', ['template_id' => 1, 'roles' => [], 'unexpected' => true]],
        ['/mcp/destructive', 'remind', ['envelope_id' => 1, 'unexpected' => true]],
        ['/mcp/destructive', 'void', ['envelope_id' => 1, 'reason' => '   ']],
    ];

    foreach ($calls as [$path, $tool, $arguments]) {
        $response = callWitnessMcpTool($path, $credential['plaintext'], $tool, $arguments);
        expect($response->getContent())->not->toContain('confirmation')
            ->not->toContain('trace')
            ->not->toContain('Illuminate\\')
            ->not->toContain('App\\');
        expect($response->status())->toBeIn([200, 400]);
    }
});

it('uses the same closed validation before destructive preview and execution', function (): void {
    $user = UserFactory::new()->create();
    bindWitnessMcpCredential($user, [WitnessMcpAbility::Destructive->value]);
    $cases = [
        [resolve(SendFromTemplate::class), new Request([
            'template_id' => 1,
            'roles' => [['role' => 'Signer', 'name' => "Invalid\x0BName", 'email' => 'signer@example.test']],
        ])],
        [resolve(Remind::class), new Request(['envelope_id' => 1, 'unexpected' => true])],
        [resolve(VoidEnvelope::class), new Request(['envelope_id' => 1, 'reason' => '   '])],
    ];

    foreach ($cases as [$tool, $request]) {
        expect(fn () => $tool->preview($request))->toThrow(ValidationException::class)
            ->and(fn () => $tool->handle($request))->toThrow(ValidationException::class);
    }
});

it('reports audit truncation only when a 501st ordered event exists', function (): void {
    [$envelope] = createSigningEnvelope([FieldType::Signature]);
    $credential = createWitnessMcpCredential($envelope->sender, [WitnessMcpAbility::Read->value]);
    $insertThrough = function (int $lastSequence) use ($envelope): void {
        $firstSequence = $envelope->auditEvents()->max('sequence') + 1;
        $now = now();
        $rows = [];
        for ($sequence = $firstSequence; $sequence <= $lastSequence; $sequence++) {
            $rows[] = [
                'envelope_id' => $envelope->getKey(),
                'verb_event_id' => 1_000_000 + $sequence,
                'sequence' => $sequence,
                'type' => 'created',
                'occurred_at' => $now,
                'actor_type' => 'sender',
                'actor_id' => (string) $envelope->sender_id,
                'payload' => '{}',
                'canonical_event' => '{}',
                'prev_hash' => str_repeat('0', 64),
                'hash' => str_repeat('1', 64),
                'created_at' => $now,
            ];
        }

        DB::statement('ALTER TABLE audit_events DISABLE TRIGGER witness_audit_events_guard');
        try {
            DB::table('audit_events')->insert($rows);
        } finally {
            DB::statement('ALTER TABLE audit_events ENABLE TRIGGER witness_audit_events_guard');
        }
    };

    $insertThrough(500);
    $exact = callWitnessMcpTool('/mcp', $credential['plaintext'], 'envelope_status', [
        'envelope_id' => $envelope->getKey(),
    ])->assertOk();
    expect($exact->json('result.structuredContent.audit_events'))->toHaveCount(500)
        ->and($exact->json('result.structuredContent.audit_events_truncated'))->toBeFalse();

    $insertThrough(501);
    $overflow = callWitnessMcpTool('/mcp', $credential['plaintext'], 'envelope_status', [
        'envelope_id' => $envelope->getKey(),
    ])->assertOk();
    expect($overflow->json('result.structuredContent.audit_events'))->toHaveCount(500)
        ->and($overflow->json('result.structuredContent.audit_events_truncated'))->toBeTrue()
        ->and(data_get($overflow->json('result.structuredContent.audit_events'), '499.sequence'))->toBe(500);
});

it('keeps short token and OTP canaries out of responses logs and session data', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    $tokenCanary = 'tok7A2';
    $otpCanary = 'otp4B6';
    DB::transaction(function () use ($signer, $tokenCanary, $otpCanary): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        DB::table('signers')->where('id', $signer->getKey())->update([
            'link_token_hash' => $tokenCanary,
            'otp_hash' => $otpCanary,
            'updated_at' => now(),
        ]);
    });
    $handler = new TestHandler;
    $logger = new MonologLogger('witness-mcp-test');
    $logger->pushHandler($handler);
    Log::swap(new IlluminateLogger($logger, resolve('events')));
    $credential = createWitnessMcpCredential($envelope->sender, [WitnessMcpAbility::Read->value]);

    $success = callWitnessMcpTool('/mcp', $credential['plaintext'], 'envelope_status', [
        'envelope_id' => $envelope->getKey(),
    ])->assertOk();
    $error = callWitnessMcpTool('/mcp', $credential['plaintext'], 'envelope_status', [
        'envelope_id' => $envelope->getKey(),
        'unexpected' => 'rejected',
    ]);
    $observed = json_encode([
        'success' => $success->json(),
        'error' => $error->json(),
        'logs' => $handler->getRecords(),
        'session' => resolve('session')->all(),
    ], JSON_THROW_ON_ERROR);

    expect(strlen($tokenCanary))->toBeLessThanOrEqual(9)
        ->and(strlen($otpCanary))->toBeLessThanOrEqual(9)
        ->and($observed)->not->toContain($tokenCanary)->not->toContain($otpCanary)
        ->and($success->json('result.structuredContent.signers.0'))->not->toHaveKeys(['link_token_hash', 'otp_hash']);
});

it('instantiates and sends the NDA-style template without exposing or reusing its private document', function (): void {
    Storage::fake('local');
    Mail::fake();
    $sender = UserFactory::new()->create();
    $team = Team::factory()->create();
    $team->users()->attach($sender);
    $source = 'templates/nda.pdf';
    $pdf = PdfFixture::make(2);
    Storage::put($source, $pdf);
    $template = Template::factory()->forOwner($team, $sender)->create([
        'name' => 'NDA',
        'title' => 'Mutual NDA',
        'storage_path' => $source,
        'size_bytes' => strlen($pdf),
        'page_count' => 2,
        'original_sha256' => hash('sha256', $pdf),
    ]);
    $client = $template->roles()->create(['label' => 'Client', 'position' => 0]);
    $company = $template->roles()->create(['label' => 'Company', 'position' => 1]);
    $client->fields()->create(['page' => 1, 'type' => FieldType::Signature, 'required' => true, 'position_x' => .1, 'position_y' => .2, 'width' => .3, 'height' => .1]);
    $company->fields()->create(['page' => 2, 'type' => FieldType::Initials, 'required' => true, 'position_x' => .5, 'position_y' => .6, 'width' => .2, 'height' => .1]);

    $envelope = resolve(InstantiateAndSendTemplate::class)->handle($sender, $template->getKey(), [
        ['role' => 'Client', 'name' => 'Client Person', 'email' => 'client@example.test'],
        ['role' => 'Company', 'name' => 'Company Person', 'email' => 'company@example.test'],
    ], 'Review this NDA.', null);

    $envelope->load(['document', 'signers.fields']);
    expect($envelope->status)->toBe(EnvelopeStatusEnum::Sent)
        ->and($envelope->document->storage_path)->not->toBe($source)
        ->and(Storage::get($envelope->document->storage_path))->toBe($pdf)
        ->and($envelope->signers->pluck('role_label')->all())->toBe(['Client', 'Company'])
        ->and($envelope->fields()->count())->toBe(2);

    expect(fn () => resolve(InstantiateAndSendTemplate::class)->handle($sender, $template->getKey(), [
        ['role' => 'Client', 'name' => 'Only One', 'email' => 'only@example.test'],
    ], null, null))->toThrow(ValidationException::class);

    $unsendable = Template::factory()->forOwner($team, $sender)->create([
        'name' => 'Unsendable',
        'storage_path' => $source,
        'size_bytes' => strlen($pdf),
        'page_count' => 2,
        'original_sha256' => hash('sha256', $pdf),
    ]);
    $unsendable->roles()->create(['label' => 'Signer', 'position' => 0]);
    expect(fn () => resolve(InstantiateAndSendTemplate::class)->handle($sender, $unsendable->getKey(), [
        ['role' => 'Signer', 'name' => 'No Fields', 'email' => 'no-fields@example.test'],
    ], null, null))->toThrow(DomainException::class);
    expect(Envelope::query()->count())->toBe(1)->and(Storage::allFiles())->toHaveCount(2);
});

it('previews and confirms template sending exactly once through the destructive route', function (): void {
    Storage::fake('local');
    Mail::fake();
    $sender = UserFactory::new()->create();
    $team = Team::factory()->create();
    $team->users()->attach($sender);
    $pdf = PdfFixture::make(2);
    Storage::put('templates/route-nda.pdf', $pdf);
    $template = Template::factory()->forOwner($team, $sender)->create([
        'name' => 'Route NDA',
        'title' => 'Route Mutual NDA',
        'storage_path' => 'templates/route-nda.pdf',
        'size_bytes' => strlen($pdf),
        'page_count' => 2,
        'original_sha256' => hash('sha256', $pdf),
    ]);
    $client = $template->roles()->create(['label' => 'Client', 'position' => 0]);
    $company = $template->roles()->create(['label' => 'Company', 'position' => 1]);
    $client->fields()->create(['page' => 1, 'type' => FieldType::Signature, 'required' => true, 'position_x' => .1, 'position_y' => .2, 'width' => .3, 'height' => .1]);
    $company->fields()->create(['page' => 2, 'type' => FieldType::Initials, 'required' => true, 'position_x' => .5, 'position_y' => .6, 'width' => .2, 'height' => .1]);
    $credential = createWitnessMcpCredential($sender, [WitnessMcpAbility::Destructive->value]);
    $arguments = [
        'template_id' => $template->getKey(),
        'roles' => [
            ['role' => 'Client', 'name' => 'Client Person', 'email' => 'client@example.test'],
            ['role' => 'Company', 'name' => 'Company Person', 'email' => 'company@example.test'],
        ],
        'message' => 'Review this NDA.',
    ];

    $preview = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'send_from_template', $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    expect($preview->json('result._meta.two_phase.phase'))->toBe('preview')
        ->and($confirmation)->toBeString()->not->toBeEmpty()
        ->and(Envelope::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe(['templates/route-nda.pdf']);

    $executed = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'send_from_template', [
        ...$arguments,
        'confirm' => $confirmation,
    ])->assertOk();
    $envelope = Envelope::query()->with(['document', 'signers.fields'])->sole();
    expect($executed->json('result._meta.two_phase.phase'))->toBe('executed')
        ->and($executed->json('result.structuredContent.envelope_id'))->toBe($envelope->getKey())
        ->and($envelope->status)->toBe(EnvelopeStatusEnum::Sent)
        ->and($envelope->document->storage_path)->not->toBe($template->storage_path)
        ->and($envelope->signers()->orderBy('position')->pluck('role_label')->all())->toBe(['Client', 'Company'])
        ->and($envelope->fields()->count())->toBe(2);

    callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'send_from_template', [
        ...$arguments,
        'confirm' => $confirmation,
    ])->assertStatus(400)->assertJsonPath('error.message', 'confirmation_spent');
    expect(Envelope::query()->count())->toBe(1);
});

it('uses the default deployment context to preview and confirm once while refusing cross-deployment use and replay', function (): void {
    createSigningEnvelope();
    $envelope = Envelope::query()->with('sender')->firstOrFail();
    $credential = createWitnessMcpCredential($envelope->sender, [WitnessMcpAbility::Destructive->value]);
    $arguments = ['envelope_id' => $envelope->getKey(), 'reason' => 'The agreement was replaced.'];

    $preview = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'void', $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');

    expect($confirmation)->toBeString()->not->toBeEmpty()
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatusEnum::Sent)
        ->and($preview->json('result._meta.two_phase.phase'))->toBe('preview');

    $deployment = config('built-for-cloud.console.audience');
    $otherDeployment = McpDeploymentContext::audience(null, 'base64:another-install-key');
    expect($deployment)->toBeString()->not->toBeEmpty()
        ->and($otherDeployment)->toBeString()->not->toBe($deployment);
    config(['built-for-cloud.console.audience' => $otherDeployment]);
    callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'void', [
        ...$arguments,
        'confirm' => $confirmation,
    ])->assertStatus(400)->assertJsonPath('error.message', 'confirmation_mismatched');
    config(['built-for-cloud.console.audience' => $deployment]);
    expect($envelope->fresh()->status)->toBe(EnvelopeStatusEnum::Sent);

    callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'void', [
        ...$arguments,
        'reason' => 'Changed after preview.',
        'confirm' => $confirmation,
    ])->assertStatus(400)->assertJsonPath('error.message', 'confirmation_mismatched');
    callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'remind', [
        'envelope_id' => $envelope->getKey(),
        'confirm' => $confirmation,
    ])->assertStatus(400)->assertJsonPath('error.message', 'confirmation_mismatched');
    $otherCredential = createWitnessMcpCredential($envelope->sender, [WitnessMcpAbility::Destructive->value]);
    callWitnessMcpTool('/mcp/destructive', $otherCredential['plaintext'], 'void', [
        ...$arguments,
        'confirm' => $confirmation,
    ])->assertStatus(400)->assertJsonPath('error.message', 'confirmation_mismatched');
    expect($envelope->fresh()->status)->toBe(EnvelopeStatusEnum::Sent);

    $executed = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'void', [...$arguments, 'confirm' => $confirmation])->assertOk();
    expect($envelope->fresh()->status)->toBe(EnvelopeStatusEnum::Voided)
        ->and($executed->json('result._meta.two_phase.phase'))->toBe('executed');

    callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'void', [...$arguments, 'confirm' => $confirmation])
        ->assertStatus(400)
        ->assertJsonPath('error.code', TwoPhaseCallTool::REFUSAL_CODE)
        ->assertJsonPath('error.message', 'confirmation_spent');
    expect($envelope->fresh()->auditEvents()->where('type', 'voided')->count())->toBe(1);
});

it('reminds only eligible signers and reports sent and unknown handoffs truthfully through the route', function (): void {
    [$envelope, $eligible] = createSigningEnvelope([FieldType::Signature], false);
    $terminal = $envelope->signers()->create([
        'name' => 'Already Signed',
        'email' => 'signed@example.test',
        'position' => 1,
    ]);
    $terminal->fields()->create([
        'page' => 1,
        'type' => FieldType::Signature,
        'required' => true,
        'position_x' => .2,
        'position_y' => .2,
        'width' => .3,
        'height' => .08,
    ]);
    resolve(SendEnvelope::class)->handle($envelope->team, $envelope->sender, $envelope->getKey());
    DB::transaction(function () use ($terminal): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        DB::table('signers')->where('id', $terminal->getKey())->update([
            'status' => SignerStatus::Signed->value,
            'signed_at' => now(),
            'updated_at' => now(),
        ]);
    });
    $credential = createWitnessMcpCredential($envelope->sender, [WitnessMcpAbility::Destructive->value]);
    $arguments = ['envelope_id' => $envelope->getKey()];
    $eligibleHash = $eligible->fresh()->link_token_hash;
    $terminalHash = $terminal->fresh()->link_token_hash;

    $preview = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'remind', $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    expect($preview->json('result.structuredContent.eligible_signer_count'))->toBe(1)
        ->and($eligible->fresh()->link_token_hash)->toBe($eligibleHash)
        ->and($terminal->fresh()->link_token_hash)->toBe($terminalHash);

    $sent = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'remind', [
        ...$arguments,
        'confirm' => $confirmation,
    ])->assertOk();
    expect($sent->json('result.structuredContent'))->toMatchArray([
        'envelope_id' => $envelope->getKey(),
        'targeted' => 1,
        'sent' => 1,
        'unknown' => 0,
    ])->and($eligible->fresh()->link_token_hash)->not->toBe($eligibleHash)
        ->and($terminal->fresh()->link_token_hash)->toBe($terminalHash);
    callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'remind', [
        ...$arguments,
        'confirm' => $confirmation,
    ])->assertStatus(400)->assertJsonPath('error.message', 'confirmation_spent');

    $pending = Mockery::mock(PendingMail::class);
    $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('Ambiguous reminder handoff.'));
    Mail::shouldReceive('to')->once()->with($eligible->email)->andReturn($pending);
    $unknownPreview = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'remind', $arguments)->assertOk();
    $unknownConfirmation = $unknownPreview->json('result._meta.two_phase.confirmation');
    $unknown = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], 'remind', [
        ...$arguments,
        'confirm' => $unknownConfirmation,
    ])->assertOk();
    expect($unknown->json('result.structuredContent'))->toMatchArray([
        'envelope_id' => $envelope->getKey(),
        'targeted' => 1,
        'sent' => 0,
        'unknown' => 1,
    ])->and($terminal->fresh()->link_token_hash)->toBe($terminalHash);
});

it('gives destructive tools zero effects for known foreign ids', function (): void {
    Storage::fake('local');
    Mail::fake();
    [$foreignEnvelope] = createSigningEnvelope();
    $foreignEnvelope->load(['team', 'sender']);
    $source = 'templates/foreign.pdf';
    $pdf = PdfFixture::make();
    Storage::put($source, $pdf);
    $foreignTemplate = Template::factory()->forOwner($foreignEnvelope->team, $foreignEnvelope->sender)->create([
        'storage_path' => $source,
        'size_bytes' => strlen($pdf),
        'original_sha256' => hash('sha256', $pdf),
    ]);
    $foreignTemplate->roles()->create(['label' => 'Signer', 'position' => 0]);
    $caller = UserFactory::new()->create();
    $callerTeam = Team::factory()->create();
    $callerTeam->users()->attach($caller);
    bindWitnessMcpCredential($caller, [WitnessMcpAbility::Destructive->value]);
    $filesBefore = Storage::allFiles();
    $envelopesBefore = Envelope::query()->count();
    $auditsBefore = $foreignEnvelope->auditEvents()->count();
    $mailBefore = Mail::sent(SigningInvitation::class)->count();

    $send = resolve(SendFromTemplate::class)->handle(new Request([
        'template_id' => $foreignTemplate->getKey(),
        'roles' => [['role' => 'Signer', 'name' => 'No Access', 'email' => 'none@example.test']],
    ]));
    $remind = resolve(Remind::class)->handle(new Request(['envelope_id' => $foreignEnvelope->getKey()]));
    $void = resolve(VoidEnvelope::class)->handle(new Request([
        'envelope_id' => $foreignEnvelope->getKey(),
        'reason' => 'No access',
    ]));

    expect($send->isError())->toBeTrue()
        ->and($remind->isError())->toBeTrue()
        ->and($void->isError())->toBeTrue()
        ->and(Envelope::query()->count())->toBe($envelopesBefore)
        ->and($foreignEnvelope->fresh()->status)->toBe(EnvelopeStatusEnum::Sent)
        ->and($foreignEnvelope->auditEvents()->count())->toBe($auditsBefore)
        ->and(Storage::allFiles())->toBe($filesBefore)
        ->and(Mail::sent(SigningInvitation::class)->count())->toBe($mailBefore);
});

it('gives all confirmed destructive calls zero effects after team membership is revoked', function (): void {
    Queue::fake();
    [$envelope] = createSigningEnvelope([FieldType::Signature]);
    $envelope->load(['team', 'sender']);
    $pdf = PdfFixture::make();
    Storage::put('templates/revoked.pdf', $pdf);
    $template = Template::factory()->forOwner($envelope->team, $envelope->sender)->create([
        'storage_path' => 'templates/revoked.pdf',
        'size_bytes' => strlen($pdf),
        'original_sha256' => hash('sha256', $pdf),
    ]);
    $role = $template->roles()->create(['label' => 'Signer', 'position' => 0]);
    $role->fields()->create(['page' => 1, 'type' => FieldType::Signature, 'required' => true, 'position_x' => .1, 'position_y' => .2, 'width' => .3, 'height' => .1]);
    $credential = createWitnessMcpCredential($envelope->sender, [WitnessMcpAbility::Destructive->value]);
    $calls = [
        'send_from_template' => [
            'template_id' => $template->getKey(),
            'roles' => [['role' => 'Signer', 'name' => 'No Longer Allowed', 'email' => 'revoked@example.test']],
        ],
        'remind' => ['envelope_id' => $envelope->getKey()],
        'void' => ['envelope_id' => $envelope->getKey(), 'reason' => 'Membership probe.'],
    ];
    $abilityConfirmations = [];
    $membershipConfirmations = [];
    foreach ($calls as $tool => $arguments) {
        $preview = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], $tool, $arguments)->assertOk();
        $abilityConfirmations[$tool] = $preview->json('result._meta.two_phase.confirmation');
        $membershipPreview = callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], $tool, $arguments)->assertOk();
        $membershipConfirmations[$tool] = $membershipPreview->json('result._meta.two_phase.confirmation');
        expect($abilityConfirmations[$tool])->toBeString()->not->toBeEmpty()
            ->and($membershipConfirmations[$tool])->toBeString()->not->toBeEmpty();
    }

    $filesBefore = Storage::allFiles();
    $envelopesBefore = Envelope::query()->count();
    $auditsBefore = $envelope->auditEvents()->count();
    $mailBefore = Mail::sent(SigningInvitation::class)->count();
    DB::table('credentials')->where('id', $credential['credential']->getKey())->update([
        'abilities' => json_encode([WitnessMcpAbility::Read->value], JSON_THROW_ON_ERROR),
    ]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/\b(templates|envelopes|teams|team_user|signers|audit_events)\b/', $query->sql) === 1) {
            $queries[] = $query->sql;
        }
    });
    foreach ($calls as $tool => $arguments) {
        callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], $tool, [
            ...$arguments,
            'confirm' => $abilityConfirmations[$tool],
        ])->assertOk()->assertJsonPath('result.isError', true);
    }
    expect($queries)->toBeEmpty()
        ->and(Envelope::query()->count())->toBe($envelopesBefore)
        ->and($envelope->auditEvents()->count())->toBe($auditsBefore)
        ->and(Storage::allFiles())->toBe($filesBefore)
        ->and(Mail::sent(SigningInvitation::class)->count())->toBe($mailBefore);

    DB::table('credentials')->where('id', $credential['credential']->getKey())->update([
        'abilities' => json_encode([WitnessMcpAbility::Destructive->value], JSON_THROW_ON_ERROR),
    ]);
    $envelope->team->users()->detach($envelope->sender);

    foreach ($calls as $tool => $arguments) {
        callWitnessMcpTool('/mcp/destructive', $credential['plaintext'], $tool, [
            ...$arguments,
            'confirm' => $membershipConfirmations[$tool],
        ])->assertOk()->assertJsonPath('result.isError', true);
    }

    expect(Envelope::query()->count())->toBe($envelopesBefore)
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatusEnum::Sent)
        ->and($envelope->auditEvents()->count())->toBe($auditsBefore)
        ->and(Storage::allFiles())->toBe($filesBefore)
        ->and(Mail::sent(SigningInvitation::class)->count())->toBe($mailBefore);
    Queue::assertNothingPushed();
});

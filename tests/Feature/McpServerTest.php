<?php

declare(strict_types=1);

use App\Actions\InstantiateAndSendTemplate;
use App\Authorization\WitnessMcpAbility;
use App\Enums\EnvelopeStatus as EnvelopeStatusEnum;
use App\Enums\FieldType;
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
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAbilityRegistry;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseCallTool;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\User;
use Database\Factories\UserFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
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

    $request = app('request');
    expect($request)->toBeInstanceOf(HttpRequest::class);
    $request->setUserResolver(static fn (): Credential => $credential);

    return $credential;
}

/** @return array<string, mixed> */
function callWitnessTwoPhaseTool(Credential $credential, array $arguments): array
{
    $id = 'witness-two-phase-'.bin2hex(random_bytes(4));
    $rpc = new JsonRpcRequest($id, 'tools/call', ['name' => 'void', 'arguments' => $arguments]);
    $http = HttpRequest::create('/mcp/destructive', 'POST', content: json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'method' => 'tools/call',
        'params' => ['name' => 'void', 'arguments' => $arguments],
    ], JSON_THROW_ON_ERROR));
    app()->instance('request', $http);
    app()->instance(HttpRequest::class, $http);
    $http->setUserResolver(static fn (): Credential => $credential);
    RequestEffectCeiling::publish($http, Effect::Destructive->value);
    $server = app()->make(WitnessMcpServer::class, ['transport' => new FakeTransporter]);
    $server->start();
    try {
        $response = app(TwoPhaseCallTool::class)->handle($rpc, $server->createContext());
    } catch (JsonRpcException $exception) {
        $response = $exception->toJsonRpcResponse();
    }

    expect($response)->toBeInstanceOf(JsonRpcResponse::class);

    return $response->toArray();
}

it('registers the exact effect-scoped MCP doors and six conforming tools', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
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
        ->and(config('built-for-cloud.mcp.delegated'))->toBeTrue()
        ->and(config('built-for-cloud.mcp.two_phase.cache_store'))->toBe('database')
        ->and(app(CredentialAbilityRegistry::class)->all())->toBe([
            WitnessMcpAbility::Read->value,
            WitnessMcpAbility::Destructive->value,
        ]);

    $metadata = $this->getJson('/bfc/meta')->assertOk();
    expect($metadata->json('capabilities'))->toContain('mcp-serve', 'mcp-delegated', 'mcp-effect-scoped')
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
        $tool = app($toolClass)->toArray();
        expect($tool['inputSchema']['additionalProperties'])->toBeFalse()
            ->and($tool['_meta']['classification'])->toBe('content');
    }

    foreach ([SendFromTemplate::class, Remind::class, VoidEnvelope::class] as $toolClass) {
        $tool = app($toolClass)->toArray();
        expect($tool['_meta']['effect'])->toBe('destructive')
            ->and($tool['_meta'][TwoPhase::META_KEY]['confirmationArgument'])->toBe('confirm')
            ->and($tool['inputSchema']['properties'])->toHaveKey('confirm');
    }
});

it('refuses unbound and wrong-ability credentials before any Witness domain query', function (): void {
    bindWitnessMcpCredential(null, [WitnessMcpAbility::Read->value]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (preg_match('/\b(templates|envelopes|teams|team_user|signers|audit_events)\b/', $query->sql) === 1) {
            $queries[] = $query->sql;
        }
    });

    $response = app(ListTemplates::class)->handle(new Request);
    expect($response->isError())->toBeTrue()->and($queries)->toBeEmpty();

    $user = UserFactory::new()->create();
    bindWitnessMcpCredential($user, ['witness.read.lookalike']);
    $response = app(ListEnvelopes::class)->handle(new Request);
    expect($response->isError())->toBeTrue()->and($queries)->toBeEmpty();
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

    $templates = app(ListTemplates::class)->handle(new Request);
    $envelopes = app(ListEnvelopes::class)->handle(new Request(['status' => EnvelopeStatusEnum::Draft->value]));
    $status = app(EnvelopeStatus::class)->handle(new Request(['envelope_id' => $visibleEnvelope->getKey()]));
    $foreignStatus = app(EnvelopeStatus::class)->handle(new Request(['envelope_id' => $visibleEnvelope->getKey() + 2]));

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

    $envelope = app(InstantiateAndSendTemplate::class)->handle($sender, $template->getKey(), [
        ['role' => 'Client', 'name' => 'Client Person', 'email' => 'client@example.test'],
        ['role' => 'Company', 'name' => 'Company Person', 'email' => 'company@example.test'],
    ], 'Review this NDA.', null);

    $envelope->load(['document', 'signers.fields']);
    expect($envelope->status)->toBe(EnvelopeStatusEnum::Sent)
        ->and($envelope->document->storage_path)->not->toBe($source)
        ->and(Storage::get($envelope->document->storage_path))->toBe($pdf)
        ->and($envelope->signers->pluck('role_label')->all())->toBe(['Client', 'Company'])
        ->and($envelope->fields()->count())->toBe(2);

    expect(fn () => app(InstantiateAndSendTemplate::class)->handle($sender, $template->getKey(), [
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
    expect(fn () => app(InstantiateAndSendTemplate::class)->handle($sender, $unsendable->getKey(), [
        ['role' => 'Signer', 'name' => 'No Fields', 'email' => 'no-fields@example.test'],
    ], null, null))->toThrow(DomainException::class);
    expect(Envelope::query()->count())->toBe(1)->and(Storage::allFiles())->toHaveCount(2);
});

it('previews, confirms once, and refuses replay for a destructive tool', function (): void {
    createSigningEnvelope();
    $envelope = Envelope::query()->with('sender')->firstOrFail();
    $credential = bindWitnessMcpCredential($envelope->sender, [WitnessMcpAbility::Destructive->value]);
    config(['built-for-cloud.console.audience' => 'witness-test-deployment']);
    $arguments = ['envelope_id' => $envelope->getKey(), 'reason' => 'The agreement was replaced.'];

    expect(app(VoidEnvelope::class)->preview(new Request($arguments)))->toBeInstanceOf(ResponseFactory::class);
    $previewPayload = callWitnessTwoPhaseTool($credential, $arguments);
    $confirmation = data_get($previewPayload, 'result._meta.two_phase.confirmation');

    expect($previewPayload['error']['message'] ?? null)->toBeNull()
        ->and($confirmation)->toBeString()->not->toBeEmpty()
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatusEnum::Sent)
        ->and(data_get($previewPayload, 'result._meta.two_phase.phase'))->toBe('preview');

    $executedPayload = callWitnessTwoPhaseTool($credential, [...$arguments, 'confirm' => $confirmation]);
    expect($envelope->fresh()->status)->toBe(EnvelopeStatusEnum::Voided)
        ->and(data_get($executedPayload, 'result._meta.two_phase.phase'))->toBe('executed');

    $replayPayload = callWitnessTwoPhaseTool($credential, [...$arguments, 'confirm' => $confirmation]);
    expect(data_get($replayPayload, 'error.code'))->toBe(-32001)
        ->and($envelope->fresh()->auditEvents()->where('type', 'voided')->count())->toBe(1);
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

    $send = app(SendFromTemplate::class)->handle(new Request([
        'template_id' => $foreignTemplate->getKey(),
        'roles' => [['role' => 'Signer', 'name' => 'No Access', 'email' => 'none@example.test']],
    ]));
    $remind = app(Remind::class)->handle(new Request(['envelope_id' => $foreignEnvelope->getKey()]));
    $void = app(VoidEnvelope::class)->handle(new Request([
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

<?php

declare(strict_types=1);

use App\Models\Envelope;
use App\Models\Team;
use App\Models\Template;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;
use Tests\Support\PublishesDelegatedAssertion;

function senderAuthorizationFixture(): array
{
    Storage::fake('local');
    $owner = UserFactory::new()->create();
    $peer = UserFactory::new()->create();
    $teamA = Team::factory()->create(['name' => 'Selected A']);
    $teamB = Team::factory()->create(['name' => 'Selected B']);
    $teamA->users()->attach([$owner->getKey(), $peer->getKey()]);
    $teamB->users()->attach($owner);
    $envelope = Envelope::factory()->forOwner($teamA, $owner)->create(['title' => 'Owner envelope']);
    $envelope->document()->create([
        'original_name' => 'owner.pdf',
        'storage_path' => 'documents/owner.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen(PdfFixture::make()),
        'page_count' => 1,
        'original_sha256' => hash('sha256', PdfFixture::make()),
    ]);
    $template = Template::factory()->forOwner($teamA, $owner)->create([
        'name' => 'Owner template',
        'storage_path' => 'documents/owner-template.pdf',
    ]);
    Storage::put($envelope->document->storage_path, PdfFixture::make());
    Storage::put($template->storage_path, PdfFixture::make());

    return compact('owner', 'peer', 'teamA', 'teamB', 'envelope', 'template');
}

it('neutrally denies every sender record route across sender and selected-team boundaries', function (): void {
    $fixture = senderAuthorizationFixture();
    $envelope = $fixture['envelope'];
    $template = $fixture['template'];

    $requests = [
        ['GET', route('sender.envelopes.edit', $envelope), []],
        ['PUT', route('sender.envelopes.update', $envelope), ['title' => 'Changed', 'message' => null, 'expires_at' => null]],
        ['PUT', route('sender.envelopes.document', $envelope), fn () => ['document' => UploadedFile::fake()->createWithContent('changed.pdf', PdfFixture::make())]],
        ['PUT', route('sender.envelopes.signers', $envelope), ['signers' => []]],
        ['PUT', route('sender.envelopes.fields', $envelope), ['fields_json' => '[]']],
        ['POST', route('sender.envelopes.send', $envelope), []],
        ['POST', route('sender.envelopes.template', $envelope), ['name' => 'Stolen template']],
        ['GET', route('sender.envelopes.document.show', $envelope), []],
        ['GET', route('sender.templates.edit', $template), []],
        ['PUT', route('sender.templates.update', $template), ['name' => 'Changed', 'title' => 'Changed', 'message' => null]],
        ['PUT', route('sender.templates.document.update', $template), fn () => ['document' => UploadedFile::fake()->createWithContent('changed.pdf', PdfFixture::make())]],
        ['PUT', route('sender.templates.roles', $template), ['roles' => []]],
        ['PUT', route('sender.templates.fields', $template), ['fields_json' => '[]']],
        ['GET', route('sender.templates.send-form', $template), []],
        ['POST', route('sender.templates.send', $template), ['roles' => []]],
        ['GET', route('sender.templates.document', $template), []],
    ];
    $before = [
        'envelope' => $envelope->fresh(['document'])->toArray(),
        'template' => $template->fresh(['roles', 'fields'])->toArray(),
        'envelopes' => Envelope::query()->count(),
        'templates' => Template::query()->count(),
        'files' => Storage::allFiles(),
    ];

    foreach ([
        [$fixture['peer'], $fixture['teamA']],
        [$fixture['owner'], $fixture['teamB']],
    ] as [$actor, $selectedTeam]) {
        $this->actingAsVersioned($actor)->withSession(['witness.team_id' => $selectedTeam->getKey()]);
        $this->get(route('sender.dashboard'))->assertOk()->assertDontSee('Owner envelope');
        $this->get(route('sender.templates.index'))->assertOk()->assertDontSee('Owner template');

        foreach ($requests as [$method, $uri, $payload]) {
            $data = is_callable($payload) ? $payload() : $payload;
            (match ($method) {
                'GET' => $this->get($uri),
                'PUT' => $this->put($uri, $data),
                'POST' => $this->post($uri, $data),
            })->assertNotFound();
        }
    }

    expect($envelope->fresh(['document'])->toArray())->toBe($before['envelope'])
        ->and($template->fresh(['roles', 'fields'])->toArray())->toBe($before['template'])
        ->and(Envelope::query()->count())->toBe($before['envelopes'])
        ->and(Template::query()->count())->toBe($before['templates'])
        ->and(Storage::allFiles())->toBe($before['files']);
});

it('refuses unauthenticated invalid-authority and delegated sender admission without effects', function (): void {
    $fixture = senderAuthorizationFixture();
    $before = [Envelope::query()->count(), Template::query()->count(), Storage::allFiles()];

    $this->get(route('sender.dashboard'))->assertRedirect();
    expect([Envelope::query()->count(), Template::query()->count(), Storage::allFiles()])->toBe($before);

    DB::unprepared('DROP TRIGGER bfc_authority_validate ON bfc_authority');
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->delete();
    $this->actingAsVersioned($fixture['owner'])->withSession(['witness.team_id' => $fixture['teamA']->getKey()])
        ->get(route('sender.dashboard'))->assertNotFound();
    expect([Envelope::query()->count(), Template::query()->count(), Storage::allFiles()])->toBe($before);
});

it('refuses a delegated principal on a sender route without effects', function (): void {
    $fixture = senderAuthorizationFixture();
    $before = [Envelope::query()->count(), Template::query()->count(), Storage::allFiles()];
    resolve('router')->prependMiddlewareToGroup('web', PublishesDelegatedAssertion::class);

    $this->withSession(['witness.team_id' => $fixture['teamA']->getKey()])
        ->get(route('sender.dashboard'))->assertForbidden();

    expect([Envelope::query()->count(), Template::query()->count(), Storage::allFiles()])->toBe($before);
});

<?php

declare(strict_types=1);

use App\Domain\Audit\AuditChainVerifier;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Models\Envelope;
use App\Models\Team;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;

it('reconciles the signer roster: swaps addresses, re-applies order, deletes omissions, refuses foreign ids', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $this->actingAsVersioned($user)->post(route('sender.envelopes.store'), [
        'title' => 'Roster envelope',
        'document' => UploadedFile::fake()->createWithContent('roster.pdf', PdfFixture::make()),
    ])->assertRedirect();
    $envelope = Envelope::query()->latest('id')->firstOrFail();
    $this->put(route('sender.envelopes.signers', $envelope), ['signers' => [
        ['name' => 'First Signer', 'email' => 'first@example.test'],
        ['name' => 'Second Signer', 'email' => 'second@example.test'],
    ]])->assertRedirect();
    $original = $envelope->signers()->orderBy('position')->pluck('id', 'email');
    $other = Envelope::factory()->forOwner($envelope->team, $user)->create();
    $foreignSigner = $other->signers()->create(['name' => 'Foreign', 'email' => 'foreign@example.test']);

    $this->put(route('sender.envelopes.signers', $envelope), ['signers' => [
        ['id' => $original->get('second@example.test'), 'name' => 'Second Renamed', 'email' => 'first@example.test'],
        ['id' => $original->get('first@example.test'), 'name' => 'First Renamed', 'email' => 'second@example.test'],
    ]])->assertRedirect();

    $roster = $envelope->signers()->get();
    expect($roster->pluck('position', 'email')->all())->toBe([
        'first@example.test' => 0,
        'second@example.test' => 1,
    ])
        ->and($roster->sortBy('position')->pluck('name')->all())->toBe(['Second Renamed', 'First Renamed'])
        ->and($roster->where('email', 'first@example.test')->first()->getKey())->toBe($original->get('second@example.test'))
        ->and($envelope->signers()->where('email', 'like', '%@invalid.test')->count())->toBe(0);

    $this->put(route('sender.envelopes.signers', $envelope), ['signers' => [
        ['id' => $original->get('second@example.test'), 'name' => 'Second Renamed', 'email' => 'first@example.test'],
    ]])->assertRedirect();
    expect($envelope->signers()->count())->toBe(1)
        ->and($envelope->signers()->first()->position)->toBe(0);

    $this->put(route('sender.envelopes.signers', $envelope), ['signers' => [
        ['id' => $foreignSigner->getKey(), 'name' => 'Smuggled', 'email' => 'smuggled@example.test'],
    ]])->assertNotFound();
});

it('drives the two-signer sender happy path twice and makes duplicate send idempotent', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $pdf = PdfFixture::make(2);

    for ($run = 1; $run <= 2; $run++) {
        $this->actingAsVersioned($user)->post(route('sender.envelopes.store'), [
            'title' => 'Agreement '.$run,
            'document' => UploadedFile::fake()->createWithContent('agreement.pdf', $pdf),
        ])->assertRedirect();
        $envelope = Envelope::query()->latest('id')->firstOrFail();

        $this->put(route('sender.envelopes.signers', $envelope), [
            'signers' => [
                ['name' => 'Alex Client', 'email' => 'alex@example.test', 'role_label' => 'Client'],
                ['name' => 'Casey Company', 'email' => 'casey@example.test', 'role_label' => 'Company'],
            ],
        ])->assertRedirect();
        $signers = $envelope->signers()->orderBy('position')->get();
        $fields = [
            ['assignee_id' => $signers[0]->getKey(), 'page' => 1, 'type' => 'signature', 'required' => true, 'position_x' => .1, 'position_y' => .1, 'width' => .25, 'height' => .08],
            ['assignee_id' => $signers[1]->getKey(), 'page' => 2, 'type' => 'initials', 'required' => true, 'position_x' => .62, 'position_y' => .72, 'width' => .2, 'height' => .08],
            ['assignee_id' => $signers[0]->getKey(), 'page' => 1, 'type' => 'text', 'required' => true, 'position_x' => .1, 'position_y' => .3, 'width' => .3, 'height' => .06],
            ['assignee_id' => $signers[0]->getKey(), 'page' => 1, 'type' => 'date_signed', 'required' => true, 'position_x' => .1, 'position_y' => .4, 'width' => .2, 'height' => .05],
            ['assignee_id' => $signers[1]->getKey(), 'page' => 2, 'type' => 'checkbox', 'required' => false, 'position_x' => .8, 'position_y' => .2, 'width' => .05, 'height' => .05],
        ];

        $this->put(route('sender.envelopes.fields', $envelope), ['fields_json' => json_encode($fields, JSON_THROW_ON_ERROR)])->assertRedirect();
        $this->post(route('sender.envelopes.send', $envelope))->assertRedirect();
        $this->post(route('sender.envelopes.send', $envelope))->assertRedirect();

        $envelope->refresh();
        expect($envelope->status)->toBe(EnvelopeStatus::Sent)
            ->and($envelope->fields()->get()->map(fn ($field) => $field->type->value)->sort()->values()->all())->toBe(collect(FieldType::cases())->map->value->sort()->values()->all())
            ->and($envelope->auditEvents()->count())->toBe(4)
            ->and($envelope->audit_event_count)->toBe(4)
            ->and(resolve(AuditChainVerifier::class)->verify($envelope)->valid)->toBeTrue();

        $this->put(route('sender.envelopes.fields', $envelope), ['fields_json' => '[]'])->assertStatus(409);
    }

    expect(Envelope::query()->count())->toBe(2)
        ->and(Envelope::query()->whereHas('signers', fn ($query) => $query->where('email', 'alex@example.test'))->count())->toBe(2);
});

it('rejects invalid placement ownership geometry pages and incomplete send requirements', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $this->actingAsVersioned($user)->post(route('sender.envelopes.store'), [
        'title' => 'Placement validation',
        'document' => UploadedFile::fake()->createWithContent('placement.pdf', PdfFixture::make()),
    ]);
    $envelope = Envelope::query()->firstOrFail();
    $this->put(route('sender.envelopes.signers', $envelope), ['signers' => [
        ['name' => 'Signer One', 'email' => 'one@example.test'],
        ['name' => 'Signer Two', 'email' => 'two@example.test'],
    ]]);
    $signers = $envelope->signers()->orderBy('position')->get();
    $other = Envelope::factory()->forOwner($envelope->team, $user)->create();
    $otherSigner = $other->signers()->create(['name' => 'Other', 'email' => 'other@example.test']);
    $base = ['assignee_id' => $signers[0]->getKey(), 'page' => 1, 'type' => 'signature', 'required' => true, 'position_x' => .1, 'position_y' => .1, 'width' => .2, 'height' => .1];

    foreach ([
        ['position_x' => -0.1],
        ['position_y' => 1.1],
        ['width' => 0],
        ['height' => -0.1],
        ['position_x' => .9, 'width' => .2],
        ['position_y' => .95, 'height' => .1],
        ['page' => 2],
        ['assignee_id' => $otherSigner->getKey()],
    ] as $change) {
        $this->put(route('sender.envelopes.fields', $envelope), [
            'fields_json' => json_encode([array_replace($base, $change)], JSON_THROW_ON_ERROR),
        ])->assertSessionHasErrors();
    }

    $this->put(route('sender.envelopes.fields', $envelope), [
        'fields_json' => json_encode([$base], JSON_THROW_ON_ERROR),
    ])->assertRedirect();
    $this->post(route('sender.envelopes.send', $envelope))->assertSessionHasErrors('send');
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft);
});

it('requires explicit team selection and gives cross-team ids neutral not-found responses', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $teamA = Team::factory()->create(['name' => 'Team A']);
    $teamB = Team::factory()->create(['name' => 'Team B']);
    $teamA->users()->attach($user);
    $teamB->users()->attach($user);
    $envelopeA = Envelope::factory()->forOwner($teamA, $user)->create(['title' => 'Private A']);
    $envelopeB = Envelope::factory()->forOwner($teamB, $user)->create(['title' => 'Visible B']);

    $this->actingAsVersioned($user)->get(route('sender.dashboard'))->assertRedirect(route('sender.teams.select'));
    $this->post(route('sender.teams.store'), ['team_id' => $teamB->getKey()])->assertRedirect(route('sender.dashboard'));
    $this->get(route('sender.dashboard'))->assertOk()->assertSee('Visible B')->assertDontSee('Private A');
    $this->get(route('sender.envelopes.edit', $envelopeA))->assertNotFound();
    $this->put(route('sender.envelopes.update', $envelopeA), ['title' => 'No', 'message' => null, 'expires_at' => null])->assertNotFound();
    $this->put(route('sender.envelopes.signers', $envelopeA), ['signers' => []])->assertNotFound();
    $this->put(route('sender.envelopes.fields', $envelopeA), ['fields_json' => '[]'])->assertNotFound();
    $this->post(route('sender.envelopes.send', $envelopeA))->assertNotFound();
    $this->get(route('sender.envelopes.document.show', $envelopeA))->assertNotFound();
    $this->get(route('sender.envelopes.edit', $envelopeB))->assertOk();
});

it('refuses a session team the user is not a member of and falls back to the own team', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $own = Team::factory()->create(['name' => 'Own Team']);
    $own->users()->attach($user);
    $this->actingAsVersioned($user)->post(route('sender.envelopes.store'), [
        'title' => 'Solo Draft',
        'document' => UploadedFile::fake()->createWithContent('solo.pdf', PdfFixture::make()),
    ])->assertRedirect();
    $foreign = Team::factory()->create(['name' => 'Foreign Team']);
    $foreign->users()->attach(UserFactory::new()->create());

    $this->actingAsVersioned($user)->withSession(['witness.team_id' => $foreign->getKey()])
        ->get(route('sender.dashboard'))->assertOk()->assertSee('Solo Draft');

    $multiUser = UserFactory::new()->create();
    $multiA = Team::factory()->create(['name' => 'Multi A']);
    $multiB = Team::factory()->create(['name' => 'Multi B']);
    $multiA->users()->attach($multiUser);
    $multiB->users()->attach($multiUser);
    $this->actingAsVersioned($multiUser)->withSession(['witness.team_id' => $foreign->getKey()])
        ->get(route('sender.dashboard'))->assertRedirect(route('sender.teams.select'));
});

<?php

declare(strict_types=1);

use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Team;
use App\Models\Template;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;

it('creates role-bound template fields then maps and copies them into an unchanged sendable draft', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $pdf = PdfFixture::make(2);
    $this->actingAsVersioned($user)->post(route('sender.templates.store'), [
        'name' => 'NDA',
        'title' => 'Mutual NDA',
        'message' => 'Please review.',
        'document' => UploadedFile::fake()->createWithContent('nda.pdf', $pdf),
    ])->assertRedirect();
    $template = Template::query()->firstOrFail();

    $this->put(route('sender.templates.roles', $template), ['roles' => [
        ['label' => 'Client'],
        ['label' => 'Company'],
    ]])->assertRedirect();
    $roles = $template->roles()->orderBy('position')->get();
    $fields = [
        ['assignee_id' => $roles[0]->getKey(), 'page' => 1, 'type' => 'signature', 'required' => true, 'position_x' => .1, 'position_y' => .12, 'width' => .25, 'height' => .05],
        ['assignee_id' => $roles[1]->getKey(), 'page' => 2, 'type' => 'initials', 'required' => true, 'position_x' => .62, 'position_y' => .72, 'width' => .2, 'height' => .08],
    ];
    $this->put(route('sender.templates.fields', $template), ['fields_json' => json_encode($fields, JSON_THROW_ON_ERROR)])->assertRedirect();
    $templateBefore = $template->fresh(['roles.fields'])->toArray();

    $this->post(route('sender.templates.send', $template), [
        'roles' => [
            $roles[0]->getKey() => ['name' => 'Client Person', 'email' => 'client@example.test'],
            $roles[1]->getKey() => ['name' => 'Company Person', 'email' => 'company@example.test'],
        ],
        'message' => 'Specific NDA message',
    ])->assertRedirect();

    $envelope = Envelope::query()->with(['document', 'signers.fields'])->firstOrFail();
    expect($envelope->document->storage_path)->not->toBe($template->storage_path)
        ->and(Storage::get($envelope->document->storage_path))->toBe($pdf)
        ->and($envelope->signers->pluck('role_label')->all())->toBe(['Client', 'Company'])
        ->and($envelope->fields()->orderBy('page')->get()->map->only(['page', 'type', 'required', 'position_x', 'position_y', 'width', 'height'])->values()->all())
        ->toBe($template->fields()->orderBy('page')->get()->map->only(['page', 'type', 'required', 'position_x', 'position_y', 'width', 'height'])->values()->all());

    $this->post(route('sender.envelopes.send', $envelope))->assertRedirect();
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Sent)
        ->and($template->fresh(['roles.fields'])->toArray())->toBe($templateBefore);
});

it('keeps templates scoped to the explicitly selected team for list view and mutations', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $teamA = Team::factory()->create(['name' => 'Team A']);
    $teamB = Team::factory()->create(['name' => 'Team B']);
    $teamA->users()->attach($user);
    $teamB->users()->attach($user);
    $templateA = Template::factory()->forOwner($teamA, $user)->create(['name' => 'Private A']);
    $templateB = Template::factory()->forOwner($teamB, $user)->create(['name' => 'Visible B']);

    $this->actingAsVersioned($user)->withSession(['witness.team_id' => $teamB->getKey()]);
    $this->get(route('sender.templates.index'))->assertOk()->assertSee('Visible B')->assertDontSee('Private A');
    $this->get(route('sender.templates.edit', $templateA))->assertNotFound();
    $this->put(route('sender.templates.update', $templateA), ['name' => 'No', 'title' => 'No', 'message' => null])->assertNotFound();
    $this->put(route('sender.templates.roles', $templateA), ['roles' => []])->assertNotFound();
    $this->put(route('sender.templates.fields', $templateA), ['fields_json' => '[]'])->assertNotFound();
    $this->get(route('sender.templates.send-form', $templateA))->assertNotFound();
    $this->get(route('sender.templates.document', $templateA))->assertNotFound();
    $this->get(route('sender.templates.edit', $templateB))->assertOk();
});

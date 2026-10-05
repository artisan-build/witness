<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Actions\SendEnvelope;
use App\Enums\FieldType;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Field;
use App\Models\Signer;
use App\Models\Team;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateRole;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Schema;

it('stores enum-backed statuses and types in ordinary string columns', function (): void {
    expect(Schema::getColumnType('envelopes', 'status'))->toBe('varchar')
        ->and(Schema::getColumnType('signers', 'status'))->toBe('varchar')
        ->and(Schema::getColumnType('fields', 'type'))->toBe('varchar')
        ->and(Schema::getColumnType('template_fields', 'type'))->toBe('varchar');
});

it('freezes document and field mutations after send', function (): void {
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $envelope = app(CreateDraftEnvelope::class)->handle(
        team: $team,
        sender: $sender,
        title: 'Freeze test',
        originalName: 'freeze.pdf',
        storagePath: 'documents/freeze.pdf',
        sizeBytes: 4096,
        pageCount: 2,
        originalSha256: hash('sha256', 'freeze test document'),
    );
    $signer = Signer::factory()->for($envelope)->create([
        'email' => 'freeze-signer@example.test',
    ]);
    $field = Field::factory()->forSigner($signer)->create();

    app(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    expect(fn () => $envelope->document->update(['original_name' => 'changed.pdf']))
        ->toThrow(DomainException::class, 'Documents cannot be changed')
        ->and(fn () => $field->update(['position_x' => '0.4000000000']))
        ->toThrow(DomainException::class, 'Fields cannot be changed')
        ->and(fn () => $field->delete())
        ->toThrow(DomainException::class, 'Fields cannot be changed')
        ->and(fn () => Document::factory()->for($envelope)->create())
        ->toThrow(DomainException::class, 'Documents cannot be changed')
        ->and(fn () => Field::factory()->forSigner($signer)->create())
        ->toThrow(DomainException::class, 'Fields cannot be changed');
});

it('requires explicit distinct-team scoping for envelopes and templates', function (): void {
    $teamA = Team::factory()->create(['name' => 'Team A']);
    $teamB = Team::factory()->create(['name' => 'Team B']);
    $senderA = UserFactory::new()->create();
    $senderB = UserFactory::new()->create();
    $teamA->users()->attach($senderA);
    $teamB->users()->attach($senderB);

    $envelopeA = app(CreateDraftEnvelope::class)->handle(
        $teamA,
        $senderA,
        'Team A envelope',
        'team-a.pdf',
        'documents/team-a.pdf',
        100,
        1,
        hash('sha256', 'team A document'),
    );
    $envelopeB = app(CreateDraftEnvelope::class)->handle(
        $teamB,
        $senderB,
        'Team B envelope',
        'team-b.pdf',
        'documents/team-b.pdf',
        100,
        1,
        hash('sha256', 'team B document'),
    );
    $templateA = Template::factory()->forOwner($teamA, $senderA)->create(['name' => 'Team A template']);
    $templateB = Template::factory()->forOwner($teamB, $senderB)->create(['name' => 'Team B template']);

    expect(Envelope::query()->forTeam($teamB)->pluck('id')->all())->toBe([$envelopeB->getKey()])
        ->and(Envelope::query()->forTeam($teamB)->find($envelopeA->getKey()))->toBeNull()
        ->and(Template::query()->forTeam($teamB)->pluck('id')->all())->toBe([$templateB->getKey()])
        ->and(Template::query()->forTeam($teamB)->find($templateA->getKey()))->toBeNull();
});

it('binds template fields to stable roles without signer rows', function (): void {
    $template = Template::factory()->create(['name' => 'Role-bound NDA']);
    $client = TemplateRole::factory()->for($template)->create(['label' => 'Client', 'position' => 1]);
    $company = TemplateRole::factory()->for($template)->create(['label' => 'Company', 'position' => 2]);
    $clientField = TemplateField::factory()->forRole($client)->create(['type' => FieldType::Signature]);
    $companyField = TemplateField::factory()->forRole($company)->create(['type' => FieldType::DateSigned]);

    expect($clientField->fresh()->role->is($client))->toBeTrue()
        ->and($companyField->fresh()->role->is($company))->toBeTrue()
        ->and($template->roles()->pluck('label')->all())->toBe(['Client', 'Company'])
        ->and(Signer::query()->count())->toBe(0);
});

it('allows the same signer email on multiple envelopes from one sender', function (): void {
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);

    $first = Envelope::factory()->forOwner($team, $sender)->create(['title' => 'First']);
    $second = Envelope::factory()->forOwner($team, $sender)->create(['title' => 'Second']);
    Signer::factory()->for($first)->create(['email' => 'repeat-signer@example.test']);
    Signer::factory()->for($second)->create(['email' => 'repeat-signer@example.test']);

    expect(Signer::query()->where('email', 'repeat-signer@example.test')->count())->toBe(2);
});

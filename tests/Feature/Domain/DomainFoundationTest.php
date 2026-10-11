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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
    $envelope = resolve(CreateDraftEnvelope::class)->handle(
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

    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

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

it('refuses document deletion and keeps document envelope identity immutable', function (): void {
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $envelope = resolve(CreateDraftEnvelope::class)->handle(
        team: $team,
        sender: $sender,
        title: 'Delete freeze test',
        originalName: 'delete-freeze.pdf',
        storagePath: 'documents/delete-freeze.pdf',
        sizeBytes: 1024,
        pageCount: 1,
        originalSha256: hash('sha256', 'delete freeze document'),
    );

    $draftDocument = $envelope->document;
    $signer = Signer::factory()->for($envelope)->create(['email' => 'delete-freeze-signer@example.test']);
    $signer->fields()->create([
        'page' => 1,
        'type' => 'signature',
        'required' => true,
        'position_x' => .1,
        'position_y' => .1,
        'width' => .2,
        'height' => .1,
    ]);
    $draftDocument->envelope_id = $envelope->getKey() + 1;

    expect(fn () => $draftDocument->save())
        ->toThrow(DomainException::class, 'envelope identity is immutable');

    $draftDocument->refresh();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    expect(fn () => $envelope->document->delete())
        ->toThrow(DomainException::class, 'Documents cannot be changed')
        ->and(fn () => Document::factory()->for($envelope)->create(['original_name' => 'second.pdf']))
        ->toThrow(DomainException::class, 'Documents cannot be changed');
});

it('requires explicit distinct-team scoping for envelopes and templates', function (): void {
    $teamA = Team::factory()->create(['name' => 'Team A']);
    $teamB = Team::factory()->create(['name' => 'Team B']);
    $senderA = UserFactory::new()->create();
    $senderB = UserFactory::new()->create();
    $teamA->users()->attach($senderA);
    $teamB->users()->attach($senderB);

    $envelopeA = resolve(CreateDraftEnvelope::class)->handle(
        $teamA,
        $senderA,
        'Team A envelope',
        'team-a.pdf',
        'documents/team-a.pdf',
        100,
        1,
        hash('sha256', 'team A document'),
    );
    $envelopeB = resolve(CreateDraftEnvelope::class)->handle(
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

function createFieldFreezeFixture(): array
{
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $envelope = resolve(CreateDraftEnvelope::class)->handle(
        team: $team,
        sender: $sender,
        title: 'Field freeze test',
        originalName: 'field-freeze.pdf',
        storagePath: 'documents/field-freeze.pdf',
        sizeBytes: 4096,
        pageCount: 1,
        originalSha256: hash('sha256', 'field freeze test document'),
    );
    $signer = Signer::factory()->for($envelope)->create();
    $field = Field::factory()->forSigner($signer)->create();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    return [$envelope, $signer, $field];
}

it('freezes every field definition column after send', function (string $column, mixed $value): void {
    [$envelope, $signer, $field] = createFieldFreezeFixture();

    expect(fn () => $field->update([$column => $value]))
        ->toThrow(DomainException::class, 'Fields cannot be changed');
})->with([
    'page' => ['page', 2],
    'type' => ['type', FieldType::Text],
    'required' => ['required', false],
    'position_x' => ['position_x', '0.4000000000'],
    'position_y' => ['position_y', '0.4000000000'],
    'width' => ['width', '0.4000000000'],
    'height' => ['height', '0.4000000000'],
]);

it('binds each field to a signer of its own envelope and refuses container reassignment', function (): void {
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $envelopeA = Envelope::factory()->forOwner($team, $sender)->create(['title' => 'Field binding A']);
    $envelopeB = Envelope::factory()->forOwner($team, $sender)->create(['title' => 'Field binding B']);
    $signerA = Signer::factory()->for($envelopeA)->create();
    $signerB = Signer::factory()->for($envelopeB)->create();

    $mismatched = new Field([
        'page' => 1,
        'type' => FieldType::Signature,
        'required' => true,
        'position_x' => '0.1000000000',
        'position_y' => '0.2000000000',
        'width' => '0.3000000000',
        'height' => '0.1000000000',
    ]);
    $mismatched->signer_id = $signerA->getKey();
    $mismatched->envelope_id = $envelopeB->getKey();

    $fieldForSignerSwap = Field::factory()->forSigner($signerA)->create();
    $fieldForEnvelopeSwap = Field::factory()->forSigner($signerA)->create();

    expect(fn () => $mismatched->save())
        ->toThrow(DomainException::class, 'A field signer must belong to the same envelope.')
        ->and(fn () => $fieldForSignerSwap->forceFill(['signer_id' => $signerB->getKey()])->save())
        ->toThrow(DomainException::class, 'Field container identity is immutable.')
        ->and(fn () => $fieldForEnvelopeSwap->forceFill(['envelope_id' => $envelopeB->getKey()])->save())
        ->toThrow(DomainException::class, 'Field container identity is immutable.');
});

it('refuses builder-level field container reassignment', function (): void {
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $envelopeA = Envelope::factory()->forOwner($team, $sender)->create(['title' => 'Binding recheck']);
    $envelopeB = Envelope::factory()->forOwner($team, $sender)->create(['title' => 'Binding recheck other']);
    $signerA = Signer::factory()->for($envelopeA)->create();
    $signerB = Signer::factory()->for($envelopeB)->create();
    $field = Field::factory()->forSigner($signerA)->create();

    expect(fn () => DB::table('fields')->where('id', $field->getKey())->update(['signer_id' => $signerB->getKey()]))
        ->toThrow(QueryException::class, 'Field container identity is immutable');
});

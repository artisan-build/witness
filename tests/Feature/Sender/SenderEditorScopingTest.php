<?php

declare(strict_types=1);

use App\Livewire\Sender\EnvelopeEditor;
use App\Livewire\Sender\TemplateEditor;
use App\Models\Envelope;
use App\Models\Team;
use App\Models\Template;
use App\Support\SelectedTeam;
use ArtisanBuild\BuiltForCloud\User;
use Database\Factories\UserFactory;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Re-creates the selected-team context a real request gets from
 * EnsureSelectedTeam, scoped to this test's lifecycle.
 */
function selectEditorTeam(Team $team): void
{
    resolve(SelectedTeam::class)->set($team);
}

/**
 * Self-contained fixture: an owner with a peer inside teamA and a second
 * teamB the owner also belongs to, plus an envelope and a template owned
 * by the owner in teamA.
 *
 * @return array{owner: User, peer: User, teamA: Team, teamB: Team, envelope: Envelope, template: Template, other: Envelope}
 */
function editorScopingFixture(): array
{
    $owner = UserFactory::new()->create();
    $peer = UserFactory::new()->create();
    $teamA = Team::factory()->create(['name' => 'Editor team A']);
    $teamB = Team::factory()->create(['name' => 'Editor team B']);
    $teamA->users()->attach([$owner->getKey(), $peer->getKey()]);
    $teamB->users()->attach($owner);

    $envelope = Envelope::factory()->forOwner($teamA, $owner)->create(['title' => 'Owner envelope']);
    $signer = $envelope->signers()->create([
        'name' => 'Editor signer',
        'email' => 'editor-signer@example.test',
    ]);
    $signer->fields()->create([
        'page' => 1,
        'type' => 'signature',
        'required' => true,
        'position_x' => .1,
        'position_y' => .1,
        'width' => .2,
        'height' => .1,
    ]);

    $template = Template::factory()->forOwner($teamA, $owner)->create(['name' => 'Owner template']);
    $template->roles()->create(['label' => 'Client', 'position' => 0]);

    $other = Envelope::factory()->forOwner($teamA, $owner)->create(['title' => 'Other envelope']);

    return compact('owner', 'peer', 'teamA', 'teamB', 'envelope', 'template', 'other');
}

it('re-scopes the livewire editors to the selected member team and owning sender', function (): void {
    ['owner' => $owner, 'peer' => $peer, 'teamA' => $teamA, 'teamB' => $teamB, 'envelope' => $envelope, 'template' => $template] = editorScopingFixture();

    selectEditorTeam($teamA);
    $this->actingAsVersioned($owner);

    expect(Livewire::test(EnvelopeEditor::class, ['envelopeId' => $envelope->getKey()])
        ->set('step', 'fields')
        ->assertSee('data-placement-data', false))
        ->toBeObject()
        ->and(Livewire::test(TemplateEditor::class, ['templateId' => $template->getKey()])
            ->assertSee('data-placement-data', false))
        ->toBeObject();

    $this->actingAsVersioned($peer);
    expect(Livewire::withQueryParams(['step' => 'fields'])->test(EnvelopeEditor::class, ['envelopeId' => $envelope->getKey()])->html())
        ->not->toContain('data-placement-data')
        ->not->toContain('Editor signer')
        ->and(Livewire::test(TemplateEditor::class, ['templateId' => $template->getKey()])->html())
        ->not->toContain('data-placement-data')
        ->not->toContain('Owner template');

    selectEditorTeam($teamB);
    $this->actingAsVersioned($owner);
    expect(Livewire::withQueryParams(['step' => 'fields'])->test(EnvelopeEditor::class, ['envelopeId' => $envelope->getKey()])->html())
        ->not->toContain('data-placement-data')
        ->not->toContain('Editor signer')
        ->and(Livewire::test(TemplateEditor::class, ['templateId' => $template->getKey()])->html())
        ->not->toContain('data-placement-data')
        ->not->toContain('Owner template');
});

it('refuses a client-supplied record id swap on the locked editor properties', function (): void {
    ['owner' => $owner, 'teamA' => $teamA, 'envelope' => $envelope, 'other' => $other] = editorScopingFixture();

    selectEditorTeam($teamA);
    $this->actingAsVersioned($owner);

    $component = Livewire::test(EnvelopeEditor::class, ['envelopeId' => $envelope->getKey()]);
    expect(fn () => $component->set('envelopeId', $other->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

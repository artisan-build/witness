<?php

declare(strict_types=1);

use App\Enums\EnvelopeFilters;
use App\Models\Envelope;
use App\Models\Team;
use Database\Factories\UserFactory;

it('counts envelopes by what they need next and marks the current group', function (): void {
    [$sent] = createSigningEnvelope();
    Envelope::factory()->forOwner($sent->team, $sent->sender)->create(['title' => 'Counted draft']);

    $content = $this->actingAsVersioned($sent->sender)->withSession(['witness.team_id' => $sent->team_id])
        ->get(route('sender.dashboard', ['status' => EnvelopeFilters::Waiting->value]))
        ->assertOk()
        ->getContent();
    $document = new DOMDocument;
    @$document->loadHTML((string) $content);
    $xpath = new DOMXPath($document);
    $count = fn (EnvelopeFilters $filter): string => trim((string) $xpath->query('//*[@data-testid="envelope-filter-'.$filter->value.'"]//*[contains(@class, "witness-count")]')->item(0)?->textContent);

    expect($count(EnvelopeFilters::Drafts))->toBe('1')
        ->and($count(EnvelopeFilters::Waiting))->toBe('1')
        ->and($count(EnvelopeFilters::Completed))->toBe('0')
        ->and($xpath->query('//*[@data-testid="envelope-filter-waiting"][@aria-current="page"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-testid="envelope-filter-drafts"][@aria-current="page"]')->length)->toBe(0);
});

it('keeps the envelope counts on Setup, which sits outside team selection', function (): void {
    [$sent] = createSigningEnvelope();
    $sent->sender->forceFill(['role' => 'owner'])->save();

    $content = $this->actingAsVersioned($sent->sender)->withSession(['witness.team_id' => $sent->team_id])
        ->get(route('sender.setup'))
        ->assertOk()
        ->getContent();
    $document = new DOMDocument;
    @$document->loadHTML((string) $content);

    expect(trim((string) (new DOMXPath($document))->query('//*[@data-testid="envelope-filter-waiting"]//*[contains(@class, "witness-count")]')->item(0)?->textContent))->toBe('1');
});

it('shows Setup only to admins and the team switcher only to people in more than one team', function (): void {
    $admin = UserFactory::new()->create(['role' => 'owner']);
    $team = Team::factory()->create(['name' => 'First team']);
    $team->users()->attach($admin);

    $this->actingAsVersioned($admin)->withSession(['witness.team_id' => $team->getKey()])
        ->get(route('sender.dashboard'))
        ->assertOk()
        ->assertSee('data-testid="setup-navigation"', false)
        ->assertDontSee('data-testid="selected-team"', false);

    Team::factory()->create()->users()->attach($admin);

    $this->get(route('sender.dashboard'))
        ->assertOk()
        ->assertSee('data-testid="selected-team"', false)
        ->assertSee('Team: First team');
});

it('moves a draft on to the step the sender chose to continue to', function (): void {
    [$draft] = createSigningEnvelope(send: false);

    $this->actingAsVersioned($draft->sender)->withSession(['witness.team_id' => $draft->team_id])
        ->put(route('sender.envelopes.update', $draft), ['title' => 'Renamed draft', 'next' => 'signers'])
        ->assertRedirect(route('sender.envelopes.edit', ['envelope' => $draft, 'step' => 'signers']));

    $this->from(route('sender.envelopes.edit', $draft))
        ->put(route('sender.envelopes.update', $draft), ['title' => 'Renamed again', 'next' => 'somewhere-else'])
        ->assertRedirect(route('sender.envelopes.edit', $draft));
});

it('shows a sent envelope as a status page rather than the draft steps', function (): void {
    [$sent, $signer] = createSigningEnvelope();

    $this->actingAsVersioned($sent->sender)->withSession(['witness.team_id' => $sent->team_id])
        ->get(route('sender.envelopes.edit', ['envelope' => $sent, 'step' => 'fields']))
        ->assertOk()
        ->assertSee('data-page="status"', false)
        ->assertDontSee('data-testid="envelope-steps"', false)
        ->assertSee('data-testid="signer-progress"', false)
        ->assertSeeText('0 of 1 signed')
        ->assertSeeText($signer->name)
        ->assertSee('data-testid="void-envelope"', false)
        ->assertDontSee('data-testid="delete-envelope"', false);
});

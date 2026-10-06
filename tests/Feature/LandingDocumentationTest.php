<?php

declare(strict_types=1);

use App\Models\Envelope;
use App\Models\Team;
use App\Support\SetupInstructions;
use Database\Factories\UserFactory;
use Symfony\Component\Process\Process;

it('renders the package landing publicly without management data', function (): void {
    $user = UserFactory::new()->create();
    $team = Team::factory()->create();
    $team->users()->attach($user);
    Envelope::factory()->forOwner($team, $user)->create(['title' => 'Private management title']);

    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="landing"', false)
        ->assertSee('data-testid="landing-ui-entry"', false)
        ->assertSee('data-testid="landing-verify-entry"', false)
        ->assertSee('Send a PDF. Collect signatures. Keep the proof.')
        ->assertDontSee('Private management title')
        ->assertDontSee($user->email);
});

it('drives setup UI and both checked-in artifacts from the canonical source', function (): void {
    $admin = UserFactory::new()->create(['role' => 'owner']);
    $response = $this->actingAsVersioned($admin)->get(route('sender.setup'))
        ->assertOk()
        ->assertSee('data-testid="setup-instructions"', false);

    foreach (SetupInstructions::sections() as $section) {
        $response->assertSee('data-testid="setup-instruction-'.$section['id'].'"', false)
            ->assertSee($section['title']);
    }

    expect(file_get_contents(base_path('docs/setup.md')))->toBe(SetupInstructions::setupMarkdown())
        ->and(file_get_contents(base_path('docs/scalpels-handoff.md')))->toBe(SetupInstructions::scalpelsHandoffMarkdown());

    $check = new Process([PHP_BINARY, base_path('scripts/generate-setup-docs.php'), '--check'], base_path());
    $check->run();
    expect($check->isSuccessful())->toBeTrue()
        ->and($check->getOutput())->toContain('Setup documentation is current.');
});

it('contains every required operator claim in the canonical instructions', function (): void {
    $content = collect(SetupInstructions::sections())
        ->flatMap(fn (array $section): array => [
            $section['title'],
            ...$section['paragraphs'],
            ...$section['bullets'],
            $section['link']['label'] ?? '',
            $section['link']['url'] ?? '',
        ])
        ->implode("\n");

    expect($content)->toContain(
        'permanent custom domain',
        'Signing links and the verify URL printed on every Certificate of Completion preserve the current host permanently.',
        SetupInstructions::CUSTOM_DOMAIN_URL,
        'Scalpels sending is the default.',
        'change MAIL_MAILER',
        'configured default mailer unchanged',
        'Amazon SES',
        '10 MiB or smaller',
        'private signed download link',
        'simple electronic signature',
        'does not provide legal advice',
        'wills, codicils, testamentary trusts, family-law matters, court documents, and notices of default or eviction',
        'someone who controls the database and recomputes the chain',
        'final PDF delivered to each party is the durable anchor',
        'At /verify',
        'A non-match reveals nothing.',
        'The upload is not stored',
        'throttled by IP',
    );
});

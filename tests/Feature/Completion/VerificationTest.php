<?php

declare(strict_types=1);

use App\Enums\MailDeliveryPurpose;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureUserIsAuthenticated;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\HostileAuthUser;

it('matches only an exact completed final PDF and stores no verification upload', function (): void {
    [$envelope] = completeFixtureEnvelope('classic-1.4-letter.pdf');
    $final = Storage::get($envelope->document->final_storage_path);
    $original = Storage::get($envelope->document->storage_path);
    $mutation = $final;
    $mutation[strlen($mutation) - 10] = chr(ord($mutation[strlen($mutation) - 10]) ^ 1);
    $filesBefore = Storage::allFiles();

    $this->get(route('verify.index'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('data-testid="verify-upload"', false);
    $this->post(route('verify.store'), [
        'verification_document' => UploadedFile::fake()->createWithContent('completed.pdf', $final),
    ])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSeeText($envelope->title)
        ->assertSeeText('Alex Signer')
        ->assertSeeText('Verified completed document');

    foreach ([$original, $mutation, "%PDF-1.4\nnot-a-match\n%%EOF\n"] as $bytes) {
        $this->post(route('verify.store'), [
            'verification_document' => UploadedFile::fake()->createWithContent('candidate.pdf', $bytes),
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSeeText('No completed document match')
            ->assertDontSeeText($envelope->title)
            ->assertDontSeeText('Alex Signer');
    }

    expect(Storage::allFiles())->toBe($filesBefore);
});

it('prevents storage of verification validation and throttle responses', function (): void {
    $this->post(route('verify.store'), [])
        ->assertRedirect()
        ->assertHeader('Cache-Control', 'no-store, private');

    for ($request = 1; $request <= 9; $request++) {
        $this->get(route('verify.index'))->assertOk();
    }
    $this->get(route('verify.index'))
        ->assertTooManyRequests()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('admits the 20 MiB verification boundary and renders an error above it', function (): void {
    $this->post(route('verify.store'), [
        'verification_document' => UploadedFile::fake()->create('boundary.pdf', 20 * 1024, 'application/pdf'),
    ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $this->from(route('verify.index'))->followingRedirects()->post(route('verify.store'), [
        'verification_document' => UploadedFile::fake()->create('too-large.pdf', (20 * 1024) + 1, 'application/pdf'),
    ])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('data-testid="verify-error"', false);
});

it('does not resolve a human user and throttles verification by IP', function (): void {
    $user = UserFactory::new()->create();
    config([
        'auth.defaults.guard' => 'hostile',
        'auth.guards.hostile' => ['driver' => 'session', 'provider' => 'hostile-users'],
        'auth.providers.hostile-users' => ['driver' => 'eloquent', 'model' => HostileAuthUser::class],
    ]);
    Auth::forgetGuards();
    $guard = Auth::guard('hostile');
    $this->withSession([$guard->getName() => $user->getKey()]);

    for ($request = 1; $request <= 10; $request++) {
        $this->get(route('verify.index'))->assertOk();
    }
    $this->get(route('verify.index'))->assertTooManyRequests();

    $route = Route::getRoutes()->getByName('verify.store');
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:verification-public')
        ->and($route->gatherMiddleware())->not->toContain(
            EnsureUserIsAuthenticated::class,
        );
});

it('scopes sender final downloads and serves signed recipient links privately', function (): void {
    [$envelope] = completeFixtureEnvelope('classic-1.4-letter.pdf');
    $team = $envelope->team;
    $owner = $envelope->sender;
    $peer = UserFactory::new()->create();
    $team->users()->attach($peer);
    $otherTeam = Team::factory()->create();
    $otherTeam->users()->attach($owner);
    $route = route('sender.envelopes.final-document', $envelope);

    $this->actingAsVersioned($owner)
        ->withSession(['witness.team_id' => $team->getKey()])
        ->get($route)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAsVersioned($peer)
        ->withSession(['witness.team_id' => $team->getKey()])
        ->get($route)
        ->assertNotFound();
    $this->actingAsVersioned($owner)
        ->withSession(['witness.team_id' => $otherTeam->getKey()])
        ->get($route)
        ->assertNotFound();

    $delivery = $envelope->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::FinalDocument->value)
        ->where('recipient_kind', 'signer')
        ->firstOrFail();
    $signedUrl = URL::signedRoute('final.recipient.download', ['delivery' => $delivery->getKey()]);
    $this->get($signedUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('final.recipient.download', $delivery))->assertForbidden();
});

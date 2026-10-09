<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Actions\SendEnvelope;
use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Models\Envelope;
use App\Models\PendingFileDeletion;
use App\Models\Signer;
use App\Models\Team;
use App\Models\Template;
use App\Services\PdfDocumentService;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use Database\Factories\UserFactory;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfFixture;

function createHardenedEnvelope(bool $complete = true): array
{
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $pdf = PdfFixture::make(2);
    $envelope = resolve(CreateDraftEnvelope::class)->handle(
        $team,
        $sender,
        'Locked agreement',
        'locked.pdf',
        'documents/locked.pdf',
        strlen($pdf),
        2,
        hash('sha256', $pdf),
    );

    if (! $complete) {
        return [$team, $sender, $envelope, null];
    }

    $signer = $envelope->signers()->create([
        'name' => 'Locked signer',
        'email' => 'locked@example.test',
        'position' => 0,
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

    return [$team, $sender, $envelope, $signer];
}

it('refuses an incomplete draft inside the locked send action', function (): void {
    [$team, $sender, $envelope] = createHardenedEnvelope(false);

    expect(fn () => resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey()))
        ->toThrow(DomainException::class, 'at least one signer');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Draft)
        ->and($envelope->auditEvents()->count())->toBe(0);
});

it('freezes envelope definitions and reserves signer progress for domain actions', function (): void {
    [$team, $sender, $envelope, $signer] = createHardenedEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    expect(fn () => $envelope->fresh()->update(['title' => 'Changed']))
        ->toThrow(DomainException::class, 'cannot be changed after send')
        ->and(fn () => DB::transaction(
            fn () => Envelope::query()->whereKey($envelope)->update(['message' => 'Changed']),
        ))->toThrow(QueryException::class, 'frozen after send')
        ->and(fn () => $envelope->signers()->create([
            'name' => 'Late signer',
            'email' => 'late@example.test',
        ]))->toThrow(DomainException::class, 'draft envelopes')
        ->and(fn () => DB::transaction(fn () => DB::table('signers')->insert([
            'envelope_id' => $envelope->getKey(),
            'name' => 'Builder signer',
            'email' => 'builder@example.test',
            'status' => SignerStatus::Pending->value,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'draft envelopes')
        ->and(fn () => $signer->fresh()->update(['name' => 'Changed signer']))
        ->toThrow(DomainException::class, 'cannot be changed after send')
        ->and(fn () => DB::transaction(
            fn () => Signer::query()->whereKey($signer)->update(['email' => 'changed@example.test']),
        ))->toThrow(QueryException::class, 'frozen after send');

    expect(fn () => DB::transaction(
        fn () => Signer::query()->whereKey($signer)->update(['status' => SignerStatus::Viewed]),
    ))->toThrow(QueryException::class, 'locked domain action');

    expect($signer->fresh()->status)->toBe(SignerStatus::Pending)
        ->and($envelope->fresh()->title)->toBe('Locked agreement');
});

it('freezes every signer definition column and refuses model-level removal after send', function (): void {
    [$team, $sender, $envelope, $signer] = createHardenedEnvelope();
    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey());

    expect(fn () => $signer->fresh()->update(['email' => 'moved@example.test']))
        ->toThrow(DomainException::class, 'Signer definitions cannot be changed after send.')
        ->and(fn () => $signer->fresh()->update(['role_label' => 'Moved role']))
        ->toThrow(DomainException::class, 'Signer definitions cannot be changed after send.')
        ->and(fn () => $signer->fresh()->update(['position' => 3]))
        ->toThrow(DomainException::class, 'Signer definitions cannot be changed after send.')
        ->and(fn () => $signer->fresh()->delete())
        ->toThrow(DomainException::class, 'Signers cannot be removed after send.')
        ->and(fn () => $envelope->fresh()->update(['expires_at' => now()->addDays(31)]))
        ->toThrow(DomainException::class, 'cannot be changed after send');
});

it('returns a controlled conflict before storing for a document-less draft', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $team = Team::factory()->create();
    $team->users()->attach($user);
    $envelope = Envelope::factory()->forOwner($team, $user)->create();

    $this->actingAsVersioned($user)->withSession(['witness.team_id' => $team->getKey()])
        ->put(route('sender.envelopes.document', $envelope), [
            'document' => UploadedFile::fake()->createWithContent('replacement.pdf', PdfFixture::make()),
        ])->assertStatus(409);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($envelope->document()->exists())->toBeFalse();
});

it('durably records failed deletion and retries on the recorded disk', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('documents/stale.pdf', 'stale');
    $failing = Mockery::mock(Storage::disk('local'))->makePartial();
    $failing->shouldReceive('delete')->once()->with('documents/stale.pdf')->andReturnFalse();
    Storage::set('local', $failing);

    expect(resolve(PdfDocumentService::class)->deleteOrQueue('documents/stale.pdf'))->toBeFalse();

    $pending = PendingFileDeletion::query()->sole();
    expect($pending->disk)->toBe('local')
        ->and($pending->attempts)->toBe(1)
        ->and($pending->last_attempted_at)->not->toBeNull();

    Storage::fake('local');
    Storage::disk('local')->put('documents/stale.pdf', 'stale');
    Storage::fake('public');
    config()->set('filesystems.default', 'public');
    resolve(PdfDocumentService::class)->retryPending();

    Storage::disk('local')->assertMissing('documents/stale.pdf');
    expect(PendingFileDeletion::query()->count())->toBe(0);
});

it('runs scheduled private file cleanup inside system authority', function (): void {
    $event = collect(resolve(Schedule::class)->events())->first(
        fn (mixed $event): bool => $event instanceof CallbackEvent
            && $event->description === 'witness:cleanup-private-files',
    );
    expect($event)->toBeInstanceOf(CallbackEvent::class);
    assert($event instanceof CallbackEvent);

    $authorityStates = [];
    DB::listen(function (QueryExecuted $query) use (&$authorityStates): void {
        if (str_contains($query->sql, 'pending_file_deletions')) {
            $authorityStates[] = resolve(SystemAuthorityContext::class)->active();
        }
    });

    $event->run(app());

    expect($authorityStates)->not->toBeEmpty()
        ->and(array_unique($authorityStates))->toBe([true])
        ->and(resolve(SystemAuthorityContext::class)->active())->toBeFalse();
});

it('cleans each exact displaced template object across repeated replacements', function (): void {
    Storage::fake('local');
    $user = UserFactory::new()->create();
    $team = Team::factory()->create();
    $team->users()->attach($user);
    $template = Template::factory()->forOwner($team, $user)->create([
        'storage_path' => 'documents/original.pdf',
    ]);
    Storage::put($template->storage_path, PdfFixture::make());
    $this->actingAsVersioned($user)->withSession(['witness.team_id' => $team->getKey()]);

    $paths = [$template->storage_path];
    foreach ([2, 3] as $pages) {
        $this->put(route('sender.templates.document.update', $template), [
            'document' => UploadedFile::fake()->createWithContent("replacement-{$pages}.pdf", PdfFixture::make($pages)),
        ])->assertRedirect();
        $paths[] = $template->fresh()->storage_path;
    }

    Storage::disk('local')->assertMissing($paths[0]);
    Storage::disk('local')->assertMissing($paths[1]);
    Storage::disk('local')->assertExists($paths[2]);
    expect(Storage::disk('local')->allFiles())->toBe([$paths[2]])
        ->and(PendingFileDeletion::query()->count())->toBe(0);
});

it('sandboxes authenticated raw PDF responses', function (): void {
    Storage::fake('local');
    [$team, $sender, $envelope] = createHardenedEnvelope();
    Storage::put($envelope->document->storage_path, PdfFixture::make(2));

    $this->actingAsVersioned($sender)->withSession(['witness.team_id' => $team->getKey()])
        ->get(route('sender.envelopes.document.show', $envelope))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
});

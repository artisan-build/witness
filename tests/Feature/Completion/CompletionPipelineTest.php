<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Actions\SaveSignerFields;
use App\Actions\SendEnvelope;
use App\Actions\SignEnvelope;
use App\Actions\UpdateFieldResponse;
use App\Domain\Audit\AuditChainVerifier;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Events\EnvelopeReadyForCompletion;
use App\Mail\CompletedDocument;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Team;
use App\Services\EnvelopeCompletionService;
use App\Services\PdfCompletionRenderer;
use ArtisanBuild\BuiltForCloud\User;
use Com\Tecnick\Pdf\Import\ImportUnsupportedFeatureException;
use Com\Tecnick\Pdf\Tcpdf;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/** @return array{Envelope, Signer} */
function completeFixtureEnvelope(
    string $fixture,
    string $signerEmail = 'alex@example.test',
    ?Team $team = null,
    ?User $sender = null,
): array {
    Storage::fake('local');
    Mail::fake();
    $bytes = file_get_contents(base_path('tests/Fixtures/Pdf/'.$fixture));
    $manifest = json_decode(
        file_get_contents(base_path('tests/Fixtures/Pdf/manifest.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $pageCount = count($manifest['fixtures'][$fixture]['pages']);
    $team ??= Team::factory()->create();
    $sender ??= UserFactory::new()->create([
        'name' => 'Morgan Sender',
        'email' => 'morgan@example.test',
    ]);
    $team->users()->syncWithoutDetaching([$sender->getKey()]);
    $path = 'documents/source-'.$fixture;
    Storage::put($path, $bytes);
    $envelope = resolve(CreateDraftEnvelope::class)->handle(
        $team,
        $sender,
        'Completion '.$fixture,
        $fixture,
        $path,
        strlen($bytes),
        $pageCount,
        hash('sha256', $bytes),
    );
    $signer = $envelope->signers()->create([
        'name' => 'Alex Signer',
        'email' => $signerEmail,
        'position' => 0,
    ]);

    for ($page = 1; $page <= $pageCount; $page++) {
        $signer->fields()->create([
            'page' => $page,
            'type' => FieldType::Text,
            'required' => true,
            'position_x' => .10,
            'position_y' => .12,
            'width' => .25,
            'height' => .05,
        ]);
        $signer->fields()->create([
            'page' => $page,
            'type' => FieldType::Signature,
            'required' => true,
            'position_x' => .62,
            'position_y' => .72,
            'width' => .20,
            'height' => .08,
        ]);
    }

    resolve(SendEnvelope::class)->handle($team, $sender, $envelope->getKey(), '192.0.2.10', 'Completion test');
    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update([
            'status' => SignerStatus::Viewed,
            'otp_verified_at' => now()->subMinutes(2),
            'consented_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);
    });
    $envelope = $envelope->refresh();
    $signer = $signer->refresh();
    EnvelopeLifecycleRecorded::record(
        $envelope,
        AuditEventType::CodeVerified,
        AuditActorType::Signer,
        $signer->getKey(),
        '192.0.2.11',
        'Completion test',
    );
    EnvelopeLifecycleRecorded::record(
        $envelope,
        AuditEventType::Consented,
        AuditActorType::Signer,
        $signer->getKey(),
        '192.0.2.11',
        'Completion test',
    );

    $responses = [];
    foreach ($signer->fields()->orderBy('id')->get() as $field) {
        $responses[$field->getKey()] = $field->type === FieldType::Text
            ? ['text' => 'Approved (literal) \\ / [safe]']
            : ['kind' => 'typed', 'typed' => 'Alex Signer'];
    }
    resolve(SaveSignerFields::class)->handle($signer, $responses, '192.0.2.11', 'Completion test');
    Event::fake([EnvelopeReadyForCompletion::class]);
    resolve(SignEnvelope::class)->handle($signer->refresh(), '192.0.2.11', 'Completion test');
    resolve(EnvelopeCompletionService::class)->complete($envelope->getKey());

    return [$envelope->refresh(), $signer->refresh()];
}

it('completes the required PDF corpus with strict page geometry and idempotent delivery', function (string $fixture): void {
    [$envelope] = completeFixtureEnvelope($fixture);
    $document = $envelope->document;
    $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/Pdf/manifest.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->completed_at)->not->toBeNull()
        ->and($document->final_storage_path)->not->toBeNull()
        ->and(Storage::exists($document->final_storage_path))->toBeTrue();
    $final = Storage::get($document->final_storage_path);
    expect(hash('sha256', $final))->toBe($document->final_sha256)
        ->and($document->final_sha256)->not->toBe($document->original_sha256)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Completed)->count())->toBe(1)
        ->and($envelope->auditEvents()->where('type', AuditEventType::FinalDelivered)->count())->toBe(2)
        ->and(resolve(AuditChainVerifier::class)->verify($envelope)->valid)->toBeTrue();

    $pdf = new Tcpdf;
    $source = $pdf->setImportSourceData($final, ['strict_limits' => true]);
    $sourcePages = count($manifest['fixtures'][$fixture]['pages']);
    expect($pdf->getSourcePageCount($source))->toBeGreaterThan($sourcePages)
        ->and($pdf->getWarnings())->toBe([])
        ->and(str_contains($final, '/Subtype /Image'))->toBeTrue();

    $deliveries = $envelope->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::FinalDocument->value)
        ->orderBy('id')
        ->get();
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('status')->all())->each->toBe(MailDeliveryStatus::Sent);
    Mail::assertSent(CompletedDocument::class, 2);
    Mail::assertSent(CompletedDocument::class, function (CompletedDocument $mail) use ($envelope): bool {
        $messageEnvelope = $mail->envelope();

        return $mail->hasTo($envelope->sender->email)
            && $messageEnvelope->from?->name === $envelope->sender->name.' via Witness'
            && $messageEnvelope->replyTo[0]->address === $envelope->sender->email;
    });
    Mail::assertSent(
        CompletedDocument::class,
        fn (CompletedDocument $mail): bool => $mail->hasTo('alex@example.test'),
    );

    resolve(EnvelopeCompletionService::class)->complete($envelope->getKey());
    expect($envelope->auditEvents()->where('type', AuditEventType::Completed)->count())->toBe(1)
        ->and($envelope->auditEvents()->where('type', AuditEventType::FinalDelivered)->count())->toBe(2)
        ->and($envelope->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument->value)->count())->toBe(2);
    Mail::assertSent(CompletedDocument::class, 2);
})->with([
    'classic PDF 1.4' => 'classic-1.4-letter.pdf',
    'object stream PDF 1.7' => 'object-stream-1.7-a4.pdf',
    'rotated mixed sizes' => 'rotated-mixed-sizes.pdf',
    'image-only scan' => 'scanned-image-letter.pdf',
]);

it('rejects the encrypted completion fixture before any final artifact is written', function (): void {
    $bytes = file_get_contents(base_path('tests/Fixtures/Pdf/encrypted-password.pdf'));
    Storage::fake('local');
    Storage::put('documents/encrypted.pdf', $bytes);
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);
    $envelope = Envelope::factory()->forOwner($team, $sender)->create();
    $envelope->document()->create([
        'original_name' => 'encrypted-password.pdf',
        'storage_path' => 'documents/encrypted.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($bytes),
        'page_count' => 1,
        'original_sha256' => hash('sha256', $bytes),
    ]);

    expect(fn () => resolve(PdfCompletionRenderer::class)->render($envelope))
        ->toThrow(ImportUnsupportedFeatureException::class)
        ->and($envelope->document->fresh()->final_storage_path)->toBeNull();
});

it('completes twice for the same sender and signer email', function (): void {
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create([
        'name' => 'Morgan Sender',
        'email' => 'same-sender@example.test',
    ]);
    $team->users()->attach($sender);
    [$first] = completeFixtureEnvelope('classic-1.4-letter.pdf', 'repeat@example.test', $team, $sender);
    [$second] = completeFixtureEnvelope('object-stream-1.7-a4.pdf', 'repeat@example.test', $team, $sender);

    expect($first->status)->toBe(EnvelopeStatus::Completed)
        ->and($second->status)->toBe(EnvelopeStatus::Completed)
        ->and($first->getKey())->not->toBe($second->getKey())
        ->and($first->document->final_sha256)->not->toBe($second->document->final_sha256);
});

it('paginates a long certificate event list deterministically', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature, FieldType::Text]);
    $field = $signer->fields()->where('type', FieldType::Text)->firstOrFail();
    resolve(UpdateFieldResponse::class)->handle(
        $envelope,
        $signer,
        $field,
        ['kind' => 'text', 'value' => 'Long certificate proof'],
        now(),
    );
    $signature = $signer->fields()->where('type', FieldType::Signature)->firstOrFail();
    resolve(UpdateFieldResponse::class)->handle(
        $envelope,
        $signer,
        $signature,
        ['kind' => 'typed', 'text' => 'Pagination Signer'],
        now(),
    );

    for ($attempt = 1; $attempt <= 80; $attempt++) {
        EnvelopeLifecycleRecorded::record(
            $envelope,
            AuditEventType::CodeFailed,
            AuditActorType::Signer,
            $signer->getKey(),
            '192.0.2.12',
            'Certificate pagination test '.$attempt,
            ['attempt' => $attempt],
        );
    }
    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update([
            'status' => SignerStatus::Signed,
            'signed_at' => now(),
            'updated_at' => now(),
        ]);
    });
    EnvelopeLifecycleRecorded::record(
        $envelope,
        AuditEventType::Signed,
        AuditActorType::Signer,
        $signer->getKey(),
        '192.0.2.12',
        'Certificate pagination test',
    );

    $completed = resolve(PdfCompletionRenderer::class)->render($envelope->refresh());
    expect($completed->certificatePageCount)->toBeGreaterThan(1)
        ->and($completed->sourcePageCount)->toBe(2);
    $pdf = new Tcpdf;
    $source = $pdf->setImportSourceData($completed->bytes, ['strict_limits' => true]);
    expect($pdf->getSourcePageCount($source))->toBe(
        $completed->sourcePageCount + $completed->certificatePageCount,
    );
});

it('leaves no completion state or delivery after failure and retries safely', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update([
            'status' => SignerStatus::Viewed,
            'otp_verified_at' => now()->subMinutes(2),
            'consented_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);
    });
    foreach ([AuditEventType::CodeVerified, AuditEventType::Consented] as $type) {
        EnvelopeLifecycleRecorded::record(
            $envelope,
            $type,
            AuditActorType::Signer,
            $signer->getKey(),
            '192.0.2.13',
            'Retry test',
        );
    }
    $field = $signer->fields()->firstOrFail();
    resolve(SaveSignerFields::class)->handle($signer->refresh(), [
        $field->getKey() => ['kind' => 'typed', 'typed' => 'Retry Signer'],
    ], '192.0.2.13', 'Retry test');
    Event::fake([EnvelopeReadyForCompletion::class]);
    resolve(SignEnvelope::class)->handle($signer->refresh(), '192.0.2.13', 'Retry test');

    $original = Storage::get($envelope->document->storage_path);
    Storage::put($envelope->document->storage_path, $original.'tampered');
    expect(fn () => resolve(EnvelopeCompletionService::class)->complete($envelope->getKey()))
        ->toThrow(RuntimeException::class, 'integrity')
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Sent)
        ->and($envelope->document->fresh()->final_storage_path)->toBeNull()
        ->and($envelope->auditEvents()->where('type', AuditEventType::Completed)->count())->toBe(0)
        ->and($envelope->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument->value)->count())->toBe(0);
    Mail::assertSent(CompletedDocument::class, 0);

    Storage::put($envelope->document->storage_path, $original);
    resolve(EnvelopeCompletionService::class)->complete($envelope->getKey());
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Completed)->count())->toBe(1)
        ->and($envelope->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument->value)->count())->toBe(2);
    Mail::assertSent(CompletedDocument::class, 2);
});

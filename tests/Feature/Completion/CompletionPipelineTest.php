<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Actions\SaveSignerFields;
use App\Actions\SendEnvelope;
use App\Actions\SignEnvelope;
use App\Actions\StoreFinalDocument;
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
use App\Services\CompletedPdf;
use App\Services\EnvelopeCompletionService;
use App\Services\OutboundMailDelivery;
use App\Services\PdfCompletionRenderer;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use ArtisanBuild\BuiltForCloud\User;
use Com\Tecnick\Pdf\Import\ImportUnsupportedFeatureException;
use Com\Tecnick\Pdf\Tcpdf;
use Database\Factories\UserFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\RenderedPdfInspector;

/** @return array{Envelope, Signer, CompletedPdf} */
function completeFixtureEnvelope(
    string $fixture,
    string $signerEmail = 'alex@example.test',
    ?Team $team = null,
    ?User $sender = null,
    bool $resetStorage = true,
    bool $deliver = true,
): array {
    if ($resetStorage) {
        Storage::fake('local');
    }
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
    $rendered = resolve(PdfCompletionRenderer::class)->render($envelope->refresh());
    if ($deliver) {
        resolve(EnvelopeCompletionService::class)->complete($envelope->getKey());
    } else {
        $finalPath = 'documents/'.$envelope->team_id.'/completed/'.Str::uuid().'.pdf';
        Storage::put($finalPath, $rendered->bytes);
        resolve(StoreFinalDocument::class)->handle(
            $envelope,
            $finalPath,
            $rendered->sha256,
            $rendered->contentSha256,
            $rendered->certificateChainHead,
        );
    }

    return [$envelope->refresh(), $signer->refresh(), $rendered];
}

it('runs the queued completion listener inside system authority', function (): void {
    config(['queue.default' => 'sync']);
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
    resolve(SaveSignerFields::class)->handle(
        $signer->refresh(),
        [$signer->fields()->sole()->getKey() => ['kind' => 'typed', 'typed' => 'Alex Signer']],
        '192.0.2.11',
        'Completion authority test',
    );
    Event::fakeFor(
        fn () => resolve(SignEnvelope::class)->handle(
            $signer->refresh(),
            '192.0.2.11',
            'Completion authority test',
        ),
        [EnvelopeReadyForCompletion::class],
    );

    $authorityStates = [];
    DB::listen(function (QueryExecuted $query) use (&$authorityStates): void {
        if (str_contains($query->sql, 'envelopes')) {
            $authorityStates[] = resolve(SystemAuthorityContext::class)->active();
        }
    });

    event(new EnvelopeReadyForCompletion($envelope->getKey()));

    expect($authorityStates)->not->toBeEmpty()
        ->and(array_unique($authorityStates))->toBe([true])
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Completed)
        ->and(resolve(SystemAuthorityContext::class)->active())->toBeFalse();
});

it('completes the required PDF corpus with strict page geometry and idempotent delivery', function (string $fixture): void {
    [$envelope, , $rendered] = completeFixtureEnvelope($fixture);
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

    $inspector = new RenderedPdfInspector($final);
    foreach ($manifest['fixtures'][$fixture]['pages'] as $index => $expectedPage) {
        $page = $inspector->pages()[$index];
        expect(abs($page['width'] - $expectedPage['displayed'][0]))->toBeLessThanOrEqual(0.01)
            ->and(abs($page['height'] - $expectedPage['displayed'][1]))->toBeLessThanOrEqual(0.01)
            ->and($page['rotation'])->toBe(0);

        $rectangles = $inspector->clippingRectangles($index + 1);
        expect($rectangles)->toHaveCount(2);
        foreach ([[.10, .12, .25, .05], [.62, .72, .20, .08]] as $placementIndex => $fractions) {
            $expected = [
                'x' => $fractions[0] * $page['width'],
                'y' => $page['height'] - (($fractions[1] + $fractions[3]) * $page['height']),
                'width' => $fractions[2] * $page['width'],
                'height' => $fractions[3] * $page['height'],
            ];
            foreach ($expected as $coordinate => $value) {
                expect(abs($rectangles[$placementIndex][$coordinate] - $value))->toBeLessThanOrEqual(0.5);
            }
        }

        $sourceForm = $inspector->sourceForm($index + 1);
        $expectedMatrix = match ($expectedPage['rotation']) {
            90 => [0, -1, 1, 0, 0, $expectedPage['crop_box'][2] - $expectedPage['crop_box'][0]],
            270 => [0, 1, -1, 0, $expectedPage['crop_box'][3] - $expectedPage['crop_box'][1], 0],
            default => [1, 0, 0, 1, 0, 0],
        };
        expect($sourceForm['dictionary'])->toContain('/Matrix ['.implode(' ', $expectedMatrix));

        if ($fixture === 'scanned-image-letter.pdf') {
            expect($final)->toContain('/Width 1200')->toContain('/Height 1553')
                ->and($sourceForm['content'])->toMatch('/\/[A-Za-z0-9]+ Do/');
        } else {
            expect($sourceForm['content'])
                ->toContain('1 0 0 rg')
                ->toContain('0 1 0 rg')
                ->toContain('0 0 1 rg')
                ->toContain('1 0 1 rg')
                ->toContain('1 0.5 0 rg')
                ->toContain(match ($fixture) {
                    'classic-1.4-letter.pdf' => 'CLASSIC-1.4',
                    'object-stream-1.7-a4.pdf' => 'OBJECT-STREAM-1.7',
                    default => 'PAGE '.($index + 1).' MediaBox',
                });
        }
    }

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

it('uses each configured default mailer and selects attachment versus signed-link delivery by size', function (string $mailer): void {
    config(['mail.default' => $mailer]);
    [$envelope] = completeFixtureEnvelope('classic-1.4-letter.pdf');
    Mail::assertSent(CompletedDocument::class, fn (CompletedDocument $mail): bool => $mail->mailer === $mailer && count($mail->attachments()) === 1);

    expect(EnvelopeCompletionService::attachesFinal(10 * 1024 * 1024))->toBeTrue()
        ->and(EnvelopeCompletionService::attachesFinal((10 * 1024 * 1024) + 1))->toBeFalse();
    $delivery = $envelope->mailDeliveries()->firstOrFail();
    $link = URL::signedRoute('final.recipient.download', ['delivery' => $delivery->getKey()]);
    $linkMail = new CompletedDocument($envelope, $envelope->document, $delivery, false, $link);
    expect($linkMail->attachments())->toBe([])
        ->and($linkMail->render())->toContain($link);
})->with(['scalpels', 'array']);

it('completes twice for the same sender and signer email', function (): void {
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create([
        'name' => 'Morgan Sender',
        'email' => 'same-sender@example.test',
    ]);
    $team->users()->attach($sender);
    [$first] = completeFixtureEnvelope('classic-1.4-letter.pdf', 'repeat@example.test', $team, $sender);
    [$second] = completeFixtureEnvelope('object-stream-1.7-a4.pdf', 'repeat@example.test', $team, $sender, false);

    expect($first->status)->toBe(EnvelopeStatus::Completed)
        ->and($second->status)->toBe(EnvelopeStatus::Completed)
        ->and($first->getKey())->not->toBe($second->getKey())
        ->and($first->document->final_sha256)->not->toBe($second->document->final_sha256);
    foreach ([$first, $second] as $completedEnvelope) {
        foreach ([
            $completedEnvelope->document->storage_path => $completedEnvelope->document->original_sha256,
            $completedEnvelope->document->final_storage_path => $completedEnvelope->document->final_sha256,
        ] as $path => $hash) {
            expect(Storage::exists($path))->toBeTrue()
                ->and(hash('sha256', Storage::get($path)))->toBe($hash);
        }
    }
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

it('redispatches pending final deliveries exactly once', function (): void {
    [$envelope] = completeFixtureEnvelope('classic-1.4-letter.pdf', deliver: false);
    expect($envelope->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument->value)
        ->where('status', MailDeliveryStatus::Pending->value)->count())->toBe(2)
        ->and($envelope->auditEvents()->where('type', AuditEventType::FinalDelivered)->count())->toBe(0);
    Mail::assertSent(CompletedDocument::class, 0);

    Event::fake([EnvelopeReadyForCompletion::class]);
    resolve(EnvelopeCompletionService::class)->dispatchReady();
    Event::assertDispatchedTimes(EnvelopeReadyForCompletion::class, 1);
    resolve(EnvelopeCompletionService::class)->complete($envelope->getKey());
    resolve(EnvelopeCompletionService::class)->complete($envelope->getKey());

    Mail::assertSent(CompletedDocument::class, 2);
    expect($envelope->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument->value)
        ->where('status', MailDeliveryStatus::Sent->value)->count())->toBe(2)
        ->and($envelope->auditEvents()->where('type', AuditEventType::FinalDelivered)->count())->toBe(2);
});

it('marks a stale ambiguous final handoff unknown without false delivery or duplicate mail', function (): void {
    [$envelope] = completeFixtureEnvelope('classic-1.4-letter.pdf', deliver: false);
    $ambiguous = $envelope->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument->value)->firstOrFail();
    $ambiguous->update([
        'status' => MailDeliveryStatus::Sending,
        'attempted_at' => now()->subMinutes(11),
    ]);

    resolve(OutboundMailDelivery::class)->reconcileStaleClaims();
    expect($ambiguous->fresh()->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($envelope->auditEvents()->where('type', AuditEventType::FinalDelivered)->count())->toBe(0);
    resolve(EnvelopeCompletionService::class)->complete($envelope->getKey());

    Mail::assertSent(CompletedDocument::class, 1);
    expect($ambiguous->fresh()->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($envelope->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument->value)->count())->toBe(2)
        ->and($envelope->auditEvents()->where('type', AuditEventType::FinalDelivered)->count())->toBe(1);
});

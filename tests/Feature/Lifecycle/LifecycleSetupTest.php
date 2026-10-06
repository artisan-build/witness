<?php

declare(strict_types=1);

use App\Actions\PurgeEnvelope;
use App\Actions\RetryEnvelopeCompletion;
use App\Actions\RotateSigningInvitation;
use App\Actions\VoidEnvelope;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeReadyForCompletion;
use App\Listeners\CompleteReadyEnvelope;
use App\Mail\CompletedDocument;
use App\Mail\LifecycleNotification;
use App\Mail\SetupTestMail;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\PendingFileDeletion;
use App\Services\EnvelopeCompletionService;
use App\Services\EnvelopeLifecycleService;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublishesDelegatedAssertion;

it('defaults expiry and performs truthful manual reminders with a rotated link', function (): void {
    [$envelope, $signer, $oldToken] = createSigningEnvelope([FieldType::Signature]);
    expect($envelope->expires_at?->isSameSecond($envelope->sent_at?->addDays(30)))->toBeTrue();

    $oldHash = $signer->link_token_hash;
    expect(resolve(RotateSigningInvitation::class)->handle(
        $envelope->team,
        $envelope->sender,
        (int) $envelope->getKey(),
        (int) $signer->getKey(),
        '192.0.2.2',
        'Reminder agent',
    ))->toBeTrue();

    $event = $envelope->auditEvents()->where('type', AuditEventType::Reminded)->sole();
    expect($signer->fresh()->link_token_hash)->not->toBe($oldHash)
        ->and($event->actor_type)->toBe(AuditActorType::Sender)
        ->and($event->actor_id)->toBe((string) $envelope->sender_id)
        ->and($event->payload)->toMatchArray(['signer_id' => $signer->getKey(), 'generation' => 2]);
    $this->get(route('sign.open', $oldToken))->assertOk()->assertSee('This signing link is unavailable');
});

it('expires before scheduled reminders and leaves every signing link neutral', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->travel(31)->days();

    resolve(EnvelopeLifecycleService::class)->runDue();

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Expired)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Expired)->count())->toBe(1)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Reminded)->count())->toBe(0);
    $this->get(route('sign.open', $token))->assertOk()->assertSee('This signing link is unavailable');
});

it('voids once under owner scope and preserves a truthful lifecycle result when notification is handed off', function (): void {
    [$envelope] = createSigningEnvelope([FieldType::Signature]);
    Mail::fake();
    resolve(VoidEnvelope::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey(), 'Agreement withdrawn');
    resolve(VoidEnvelope::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey(), 'Repeated request');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Voided)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Voided)->count())->toBe(1);
    Mail::assertSent(LifecycleNotification::class, 1);
});

it('marks an exhausted fully signed completion failed and retries without changing evidence', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        DB::table('signers')->where('id', $signer->getKey())->update(['status' => SignerStatus::Signed->value, 'signed_at' => now()]);
    });
    $count = $envelope->auditEvents()->count();
    resolve(CompleteReadyEnvelope::class)->failed(new EnvelopeReadyForCompletion((int) $envelope->getKey()), new RuntimeException('secret detail'));
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::CompletionFailed)
        ->and($envelope->auditEvents()->count())->toBe($count);

    Event::fake([EnvelopeReadyForCompletion::class]);
    resolve(RetryEnvelopeCompletion::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey());
    resolve(RetryEnvelopeCompletion::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey());
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Sent)
        ->and($envelope->auditEvents()->count())->toBe($count);
    Event::assertDispatched(EnvelopeReadyForCompletion::class, 1);
});

it('never automatically retries an unknown final handoff and creates an explicit replacement generation', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.field_response_write = 'on'");
        DB::table('fields')->where('signer_id', $signer->getKey())->update([
            'value' => json_encode(['kind' => 'typed', 'text' => 'Alex Signer'], JSON_THROW_ON_ERROR),
            'completed_at' => now(),
        ]);
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        DB::table('signers')->where('id', $signer->getKey())->update(['status' => SignerStatus::Signed->value, 'signed_at' => now()]);
    });
    resolve(EnvelopeCompletionService::class)->complete((int) $envelope->getKey());
    Mail::assertSent(CompletedDocument::class, function (CompletedDocument $mail): bool {
        parse_str((string) parse_url($mail->downloadUrl, PHP_URL_QUERY), $query);

        return isset($query['expires'], $query['signature']);
    });
    $unknown = $signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument)->sole();
    $unknown->update(['status' => MailDeliveryStatus::Unknown, 'unknown_at' => now()]);

    Event::fake([EnvelopeReadyForCompletion::class]);
    resolve(EnvelopeCompletionService::class)->dispatchReady();
    Event::assertNotDispatched(EnvelopeReadyForCompletion::class);

    resolve(EnvelopeCompletionService::class)->resendUnknown((int) $envelope->getKey(), (int) $unknown->getKey());
    expect($unknown->fresh()->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument)->orderBy('generation')->pluck('status')->all())
        ->toBe([MailDeliveryStatus::Unknown, MailDeliveryStatus::Sent]);
});

it('purges a terminal aggregate and all private and Verbs material under the exact envelope setting', function (): void {
    [$envelope] = createSigningEnvelope([FieldType::Signature]);
    resolve(VoidEnvelope::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey(), 'Delete fixture');
    $document = $envelope->document;
    $eventIds = $envelope->auditEvents()->pluck('verb_event_id');
    expect(fn () => DB::transaction(fn () => DB::table('envelopes')->where('id', $envelope->getKey())->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(function () use ($envelope): void {
        DB::select("SELECT set_config('witness.purge_envelope', ?, true)", [(string) ($envelope->getKey() + 1)]);
        DB::table('envelopes')->where('id', $envelope->getKey())->delete();
    }))->toThrow(QueryException::class);

    resolve(PurgeEnvelope::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey());

    expect(Envelope::query()->find($envelope->getKey()))->toBeNull()
        ->and(AuditEvent::query()->where('envelope_id', $envelope->getKey())->count())->toBe(0)
        ->and(DB::table('verb_events')->whereIn('id', $eventIds)->count())->toBe(0)
        ->and(Storage::exists($document->storage_path))->toBeFalse()
        ->and(PendingFileDeletion::query()->count())->toBe(0);
});

it('admits only an installation admin and uses either configured default mailer for test mail', function (string $mailer, string $label): void {
    $admin = UserFactory::new()->create(['role' => 'owner']);
    $member = UserFactory::new()->create(['role' => 'member']);
    config()->set('mail.default', $mailer);
    config()->set('mail.mailers.'.$mailer, ['transport' => 'array']);
    config()->set('app.url', 'https://witness.laravel.cloud');
    Mail::fake();

    $this->actingAsVersioned($member)->get(route('sender.setup'))->assertForbidden();
    $this->actingAsVersioned($admin)->get(route('sender.setup'))
        ->assertOk()->assertSee($label)->assertSee('Laravel Cloud custom-domain instructions');
    $this->post(route('sender.setup.test-mail'))->assertRedirect();
    Mail::assertSent(SetupTestMail::class, 1);
})->with([
    ['scalpels', 'Sent by Scalpels'],
    ['array', 'Your own mail configuration (array)'],
]);

it('refuses delegated setup administration', function (): void {
    resolve('router')->prependMiddlewareToGroup('web', PublishesDelegatedAssertion::class);
    $this->get(route('sender.setup'))->assertForbidden();
});

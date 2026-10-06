<?php

declare(strict_types=1);

use App\Actions\PurgeEnvelope;
use App\Actions\RetryEnvelopeCompletion;
use App\Actions\RotateSigningInvitation;
use App\Actions\SendEnvelope;
use App\Actions\VoidEnvelope;
use App\Domain\Audit\AuditChainVerifier;
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
use App\Models\Team;
use App\Services\EnvelopeCompletionService;
use App\Services\EnvelopeLifecycleService;
use App\Services\PdfDocumentService;
use App\States\EnvelopeState;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PublishesDelegatedAssertion;
use Thunk\Verbs\Contracts\BrokersEvents;

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

it('preserves explicit expiry and enforces reminder cadence, off state, authority, terminal state, and unknown honesty', function (): void {
    [$explicit] = createSigningEnvelope([FieldType::Signature], false);
    $expiry = now()->addDays(12)->startOfSecond();
    $explicit->update(['expires_at' => $expiry, 'reminder_interval_days' => null]);
    resolve(SendEnvelope::class)->handle($explicit->team, $explicit->sender, (int) $explicit->getKey());
    expect($explicit->fresh()->expires_at?->equalTo($expiry))->toBeTrue();

    $this->travel(4)->days();
    resolve(SystemAuthorityContext::class)->run(fn () => resolve(EnvelopeLifecycleService::class)->runDue());
    expect($explicit->auditEvents()->where('type', AuditEventType::Reminded)->count())->toBe(0);

    [$due, $active] = createSigningEnvelope([FieldType::Signature], false);
    $terminal = $due->signers()->create([
        'name' => 'Already signed',
        'email' => 'terminal@example.test',
        'position' => 1,
    ]);
    $terminal->fields()->create([
        'page' => 1,
        'type' => FieldType::Signature,
        'required' => true,
        'position_x' => .1,
        'position_y' => .3,
        'width' => .3,
        'height' => .08,
    ]);
    resolve(SendEnvelope::class)->handle($due->team, $due->sender, (int) $due->getKey());
    DB::transaction(function () use ($terminal): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        DB::table('signers')->where('id', $terminal->getKey())->update(['status' => SignerStatus::Signed->value, 'signed_at' => now()]);
        DB::statement("SET LOCAL witness.signer_progress_write = 'off'");
    });
    $oldHash = $active->link_token_hash;
    expect(fn () => resolve(RotateSigningInvitation::class)->handle(
        $due->team,
        null,
        (int) $due->getKey(),
        (int) $active->getKey(),
    ))->toThrow(DomainException::class);

    $this->travel(4)->days();
    resolve(SystemAuthorityContext::class)->run(fn () => resolve(EnvelopeLifecycleService::class)->runDue());
    expect($active->fresh()->link_token_hash)->not->toBe($oldHash)
        ->and($due->auditEvents()->where('type', AuditEventType::Reminded)->sole()->actor_type)->toBe(AuditActorType::System)
        ->and($terminal->mailDeliveries()->count())->toBe(1);

    $generation = $active->mailDeliveries()->max('generation');
    resolve(SystemAuthorityContext::class)->run(fn () => resolve(EnvelopeLifecycleService::class)->runDue());
    expect($active->mailDeliveries()->max('generation'))->toBe($generation);
    $active->mailDeliveries()->latest('generation')->firstOrFail()->update([
        'status' => MailDeliveryStatus::Unknown,
        'unknown_at' => now(),
    ]);
    $this->travel(4)->days();
    resolve(SystemAuthorityContext::class)->run(fn () => resolve(EnvelopeLifecycleService::class)->runDue());
    expect($active->mailDeliveries()->max('generation'))->toBe($generation);
});

it('requires active system authority before scheduled lifecycle work has any effect', function (): void {
    [$envelope] = createSigningEnvelope([FieldType::Signature]);
    $this->travel(31)->days();
    Mail::fake();
    $auditCount = AuditEvent::query()->count();
    $verbCount = DB::table('verb_events')->count();
    $deliveryCount = DB::table('mail_deliveries')->count();

    expect(fn () => resolve(EnvelopeLifecycleService::class)->runDue())
        ->toThrow(DomainException::class, 'active system authority');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Sent)
        ->and(AuditEvent::query()->count())->toBe($auditCount)
        ->and(DB::table('verb_events')->count())->toBe($verbCount)
        ->and(DB::table('mail_deliveries')->count())->toBe($deliveryCount);
    Mail::assertNothingSent();
});

it('does not automatically replace an unresolved invitation handoff', function (MailDeliveryStatus $status): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    $delivery = $signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::SigningInvitation)->sole();
    $delivery->update([
        'status' => $status,
        'attempted_at' => $status === MailDeliveryStatus::Sending ? now() : null,
        'unknown_at' => $status === MailDeliveryStatus::Unknown ? now() : null,
    ]);
    $hash = $signer->link_token_hash;
    $generation = $delivery->generation;
    $this->travel(4)->days();
    Mail::fake();

    resolve(SystemAuthorityContext::class)->run(fn () => resolve(EnvelopeLifecycleService::class)->runDue());

    expect($signer->fresh()->link_token_hash)->toBe($hash)
        ->and($signer->mailDeliveries()->max('generation'))->toBe($generation)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Reminded)->count())->toBe(0);
    Mail::assertNothingSent();

    expect(resolve(RotateSigningInvitation::class)->handle(
        $envelope->team,
        $envelope->sender,
        (int) $envelope->getKey(),
        (int) $signer->getKey(),
    ))->toBeTrue()
        ->and($signer->fresh()->link_token_hash)->not->toBe($hash)
        ->and($signer->mailDeliveries()->max('generation'))->toBe($generation + 1);
})->with([
    MailDeliveryStatus::Pending,
    MailDeliveryStatus::Sending,
    MailDeliveryStatus::Unknown,
]);

it('expires before scheduled reminders and leaves every signing link neutral', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->travel(31)->days();

    $transitionCapability = DB::transaction(function (): string {
        resolve(SystemAuthorityContext::class)->run(fn () => resolve(EnvelopeLifecycleService::class)->runDue());

        return (string) DB::scalar("SELECT current_setting('witness.envelope_transition', true)");
    });

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Expired)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Expired)->count())->toBe(1)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Reminded)->count())->toBe(0)
        ->and(resolve(AuditChainVerifier::class)->verify($envelope)->valid)->toBeTrue()
        ->and($transitionCapability)->toBe('off');
    Mail::assertSent(LifecycleNotification::class, fn (LifecycleNotification $mail): bool => $mail->outcome === 'expired');
    $this->get(route('sign.open', $token))->assertOk()->assertSee('This signing link is unavailable');
});

it('voids once under owner scope and preserves a truthful lifecycle result when notification is handed off', function (): void {
    [$envelope] = createSigningEnvelope([FieldType::Signature]);
    Mail::fake();
    resolve(VoidEnvelope::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey(), 'Agreement withdrawn');
    resolve(VoidEnvelope::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey(), 'Repeated request');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Voided)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Voided)->count())->toBe(1)
        ->and(resolve(AuditChainVerifier::class)->verify($envelope)->valid)->toBeTrue();
    Mail::assertSent(LifecycleNotification::class, 1);
});

it('marks an exhausted fully signed completion failed and retries without changing evidence', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        DB::table('signers')->where('id', $signer->getKey())->update(['status' => SignerStatus::Signed->value, 'signed_at' => now()]);
    });
    $count = $envelope->auditEvents()->count();
    expect(fn () => resolve(RetryEnvelopeCompletion::class)->handle(
        $envelope->team,
        $envelope->sender,
        (int) $envelope->getKey(),
    ))->toThrow(DomainException::class);
    $this->actingAsVersioned($envelope->sender)->withSession(['witness.team_id' => $envelope->team_id])
        ->post(route('sender.envelopes.retry-completion', $envelope))
        ->assertSessionHasErrors('completion')
        ->assertSessionMissing('status');
    $transitionCapability = DB::transaction(function () use ($envelope): string {
        resolve(CompleteReadyEnvelope::class)->failed(new EnvelopeReadyForCompletion((int) $envelope->getKey()), new RuntimeException('secret detail'));

        return (string) DB::scalar("SELECT current_setting('witness.envelope_transition', true)");
    });
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::CompletionFailed)
        ->and($envelope->auditEvents()->count())->toBe($count)
        ->and($transitionCapability)->toBe('off');

    $peer = UserFactory::new()->create();
    $envelope->team->users()->attach($peer);
    $otherTeam = Team::factory()->create();
    $otherTeam->users()->attach($envelope->sender);
    expect(fn () => resolve(RetryEnvelopeCompletion::class)->handle($envelope->team, $peer, (int) $envelope->getKey()))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => resolve(RetryEnvelopeCompletion::class)->handle($otherTeam, $envelope->sender, (int) $envelope->getKey()))
        ->toThrow(ModelNotFoundException::class);

    Event::fake([EnvelopeReadyForCompletion::class]);
    resolve(RetryEnvelopeCompletion::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey());
    expect(fn () => resolve(RetryEnvelopeCompletion::class)->handle(
        $envelope->team,
        $envelope->sender,
        (int) $envelope->getKey(),
    ))->toThrow(DomainException::class);
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

    $peer = UserFactory::new()->create();
    $envelope->team->users()->attach($peer);
    $otherTeam = Team::factory()->create();
    $otherTeam->users()->attach($envelope->sender);
    $before = $signer->mailDeliveries()->count();
    expect(fn () => resolve(EnvelopeCompletionService::class)->resendUnknown(
        $envelope->team,
        $peer,
        (int) $envelope->getKey(),
        (int) $unknown->getKey(),
    ))->toThrow(ModelNotFoundException::class)
        ->and(fn () => resolve(EnvelopeCompletionService::class)->resendUnknown(
            $otherTeam,
            $envelope->sender,
            (int) $envelope->getKey(),
            (int) $unknown->getKey(),
        ))->toThrow(ModelNotFoundException::class)
        ->and($signer->mailDeliveries()->count())->toBe($before);

    resolve(EnvelopeCompletionService::class)->resendUnknown(
        $envelope->team,
        $envelope->sender,
        (int) $envelope->getKey(),
        (int) $unknown->getKey(),
    );
    expect($unknown->fresh()->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument)->orderBy('generation')->pluck('status')->all())
        ->toBe([MailDeliveryStatus::Unknown, MailDeliveryStatus::Sent]);
});

it('lets only the selected-team owning sender reissue the latest successful final-document link', function (): void {
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
    $delivery = $signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument)->sole();
    $finalPath = $envelope->document->fresh()->final_storage_path;
    $peer = UserFactory::new()->create();
    $envelope->team->users()->attach($peer);
    $otherTeam = Team::factory()->create();
    $otherTeam->users()->attach($envelope->sender);

    expect(fn () => resolve(EnvelopeCompletionService::class)->reissueSent(
        $envelope->team,
        $peer,
        (int) $envelope->getKey(),
        (int) $delivery->getKey(),
    ))->toThrow(ModelNotFoundException::class)
        ->and(fn () => resolve(EnvelopeCompletionService::class)->reissueSent(
            $otherTeam,
            $envelope->sender,
            (int) $envelope->getKey(),
            (int) $delivery->getKey(),
        ))->toThrow(ModelNotFoundException::class)
        ->and($signer->mailDeliveries()->count())->toBe(2);

    $this->actingAsVersioned($envelope->sender)->withSession(['witness.team_id' => $envelope->team_id])
        ->post(route('sender.envelopes.deliveries.reissue-link', [$envelope, $delivery]))
        ->assertRedirect()
        ->assertSessionHas('status');

    $deliveries = $signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::FinalDocument)->orderBy('generation')->get();
    expect($deliveries->pluck('status')->all())->toBe([MailDeliveryStatus::Sent, MailDeliveryStatus::Sent])
        ->and($deliveries->pluck('generation')->all())->toBe([1, 2])
        ->and($envelope->document->fresh()->final_storage_path)->toBe($finalPath)
        ->and($envelope->auditEvents()->where('type', AuditEventType::FinalDelivered)->where('payload->delivery_id', $deliveries->last()->getKey())->count())->toBe(1)
        ->and(fn () => resolve(EnvelopeCompletionService::class)->reissueSent(
            $envelope->team,
            $envelope->sender,
            (int) $envelope->getKey(),
            (int) $delivery->getKey(),
        ))->toThrow(DomainException::class)
        ->and($signer->mailDeliveries()->count())->toBe(3);
});

it('purges a terminal aggregate and all private and Verbs material under the exact envelope setting', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    $originalPath = $envelope->document->storage_path;
    $finalPath = 'documents/final-purge.pdf';
    $drawnPath = 'signatures/drawn-purge.png';
    Storage::put($finalPath, 'final');
    Storage::put($drawnPath, 'drawn');
    DB::transaction(function () use ($envelope, $signer, $finalPath, $drawnPath): void {
        DB::statement("SET LOCAL witness.final_document_write = 'on'");
        DB::table('documents')->where('envelope_id', $envelope->getKey())->update([
            'final_storage_path' => $finalPath,
            'final_sha256' => hash('sha256', 'final'),
        ]);
        DB::statement("SET LOCAL witness.final_document_write = 'off'");
        DB::statement("SET LOCAL witness.field_response_write = 'on'");
        DB::table('fields')->where('signer_id', $signer->getKey())->update([
            'value' => json_encode(['kind' => 'drawn', 'path' => $drawnPath], JSON_THROW_ON_ERROR),
            'completed_at' => now(),
        ]);
        DB::statement("SET LOCAL witness.field_response_write = 'off'");
    });
    resolve(VoidEnvelope::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey(), 'Delete fixture');
    $eventIds = $envelope->auditEvents()->pluck('verb_event_id');
    $snapshotId = (int) $eventIds->first();
    DB::table('verb_snapshots')->insert([
        'id' => $snapshotId,
        'state_id' => $envelope->getKey(),
        'type' => EnvelopeState::class,
        'data' => '{}',
        'last_event_id' => $eventIds->last(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    [$other] = createSigningEnvelope([FieldType::Signature]);
    resolve(VoidEnvelope::class)->handle($other->team, $other->sender, (int) $other->getKey(), 'Other fixture');
    expect(fn () => DB::transaction(fn () => DB::table('documents')->where('envelope_id', $envelope->getKey())->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(function () use ($envelope): void {
        DB::select("SELECT set_config('witness.purge_envelope', ?, true)", [(string) ($envelope->getKey() + 1)]);
        DB::table('documents')->where('envelope_id', $envelope->getKey())->delete();
    }))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('envelopes')->where('id', $envelope->getKey())->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(function () use ($envelope): void {
        DB::select("SELECT set_config('witness.purge_envelope', ?, true)", [(string) ($envelope->getKey() + 1)]);
        DB::table('envelopes')->where('id', $envelope->getKey())->delete();
    }))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(function () use ($envelope, $other): void {
        DB::select("SELECT set_config('witness.purge_envelope', ?, true)", [(string) $envelope->getKey()]);
        DB::table('envelopes')->where('id', $other->getKey())->delete();
    }))->toThrow(QueryException::class);
    $peer = UserFactory::new()->create();
    $envelope->team->users()->attach($peer);
    expect(fn () => resolve(PurgeEnvelope::class)->handle($envelope->team, $peer, (int) $envelope->getKey()))
        ->toThrow(ModelNotFoundException::class)
        ->and(Envelope::query()->whereKey($envelope)->exists())->toBeTrue();

    $failing = Mockery::mock(Storage::disk('local'))->makePartial();
    $failing->shouldReceive('delete')->with($finalPath)->once()->andReturnFalse();
    Storage::set('local', $failing);

    resolve(PurgeEnvelope::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey());

    expect(Envelope::query()->find($envelope->getKey()))->toBeNull()
        ->and(AuditEvent::query()->where('envelope_id', $envelope->getKey())->count())->toBe(0)
        ->and(DB::table('verb_events')->whereIn('id', $eventIds)->count())->toBe(0)
        ->and(DB::table('verb_state_events')->whereIn('event_id', $eventIds)->count())->toBe(0)
        ->and(DB::table('verb_snapshots')->where('state_id', $envelope->getKey())->count())->toBe(0)
        ->and(DB::table('signers')->where('envelope_id', $envelope->getKey())->count())->toBe(0)
        ->and(DB::table('fields')->where('envelope_id', $envelope->getKey())->count())->toBe(0)
        ->and(DB::table('mail_deliveries')->where('envelope_id', $envelope->getKey())->count())->toBe(0)
        ->and(Storage::exists($originalPath))->toBeFalse()
        ->and(Storage::exists($drawnPath))->toBeFalse()
        ->and(PendingFileDeletion::query()->sole()->path)->toBe($finalPath);

    Storage::fake('local');
    Storage::put($finalPath, 'final');
    resolve(PdfDocumentService::class)->retryPending();
    Storage::assertMissing($finalPath);
    resolve(BrokersEvents::class)->replay();
    expect(PendingFileDeletion::query()->count())->toBe(0);
});

it('requires the distinct confirmation step and explicit destructive submission before purging', function (): void {
    [$envelope] = createSigningEnvelope([FieldType::Signature]);
    $originalPath = $envelope->document->storage_path;
    $this->actingAsVersioned($envelope->sender)->withSession(['witness.team_id' => $envelope->team_id]);

    $this->get(route('sender.envelopes.delete', $envelope))->assertConflict();
    expect(Envelope::query()->whereKey($envelope)->exists())->toBeTrue()
        ->and(Storage::exists($originalPath))->toBeTrue();

    resolve(VoidEnvelope::class)->handle($envelope->team, $envelope->sender, (int) $envelope->getKey(), 'Delete through HTTP');
    $this->get(route('sender.envelopes.delete', $envelope))
        ->assertOk()
        ->assertSee('data-testid="delete-envelope-confirmation"', false)
        ->assertSee(route('sender.envelopes.destroy', $envelope), false);

    $this->delete(route('sender.envelopes.destroy', $envelope))
        ->assertSessionHasErrors('confirmation');
    $this->delete(route('sender.envelopes.destroy', $envelope), ['confirmation' => 'delete'])
        ->assertSessionHasErrors('confirmation');
    expect(Envelope::query()->whereKey($envelope)->exists())->toBeTrue()
        ->and(Storage::exists($originalPath))->toBeTrue();

    $this->delete(route('sender.envelopes.destroy', $envelope), ['confirmation' => 'DELETE'])
        ->assertRedirect(route('sender.dashboard'));
    expect(Envelope::query()->whereKey($envelope)->exists())->toBeFalse()
        ->and(Storage::exists($originalPath))->toBeFalse();
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

it('renders non-cloud setup state and hands test mail only to the selected array mailer', function (): void {
    $admin = UserFactory::new()->create(['role' => 'owner']);
    config()->set('app.url', 'https://sign.example.test');
    config()->set('mail.default', 'selected');
    config()->set('mail.mailers.selected', ['transport' => 'array']);
    config()->set('mail.mailers.unselected', ['transport' => 'array']);

    $this->actingAsVersioned($admin)->get(route('sender.setup'))
        ->assertOk()
        ->assertSee('Custom domain configured.')
        ->assertSee('data-testid="setup-domain"', false)
        ->assertSee('data-testid="setup-mail"', false);
    $this->post(route('sender.setup.test-mail'))->assertRedirect();

    $selected = Mail::mailer('selected')->getSymfonyTransport();
    $unselected = Mail::mailer('unselected')->getSymfonyTransport();
    expect($selected)->toBeInstanceOf(ArrayTransport::class)
        ->and($unselected)->toBeInstanceOf(ArrayTransport::class);
    assert($selected instanceof ArrayTransport && $unselected instanceof ArrayTransport);
    expect($selected->messages())->toHaveCount(1)
        ->and($unselected->messages())->toHaveCount(0);
});

it('filters the owner dashboard and renders structural audit evidence without secret material', function (): void {
    [$sent, $signer] = createSigningEnvelope([FieldType::Signature]);
    $draft = Envelope::factory()->forOwner($sent->team, $sent->sender)->create(['title' => 'Private draft title']);

    $this->actingAsVersioned($sent->sender)->withSession(['witness.team_id' => $sent->team_id])
        ->get(route('sender.dashboard', ['status' => EnvelopeStatus::Sent->value]))
        ->assertOk()
        ->assertSee($sent->title)
        ->assertDontSee($draft->title)
        ->assertSee('data-testid="status-filter"', false)
        ->assertSee($signer->name.': '.$signer->status->value);

    $this->get(route('sender.envelopes.edit', $sent))
        ->assertOk()
        ->assertSee('data-testid="audit-log"', false)
        ->assertSee(AuditEventType::Sent->value)
        ->assertDontSee((string) $signer->link_token_hash)
        ->assertDontSee('storage_path');
});

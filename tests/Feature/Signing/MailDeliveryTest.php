<?php

declare(strict_types=1);

use App\Actions\DeclineEnvelope;
use App\Actions\RotateSigningCode;
use App\Actions\RotateSigningInvitation;
use App\Actions\SendEnvelope;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Exceptions\SigningAccessDenied;
use App\Mail\SigningCode;
use App\Mail\SigningDeclined;
use App\Mail\SigningInvitation;
use App\Models\MailDelivery;
use App\Models\Signer;
use App\Services\OutboundMailDelivery;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

it('commits multi-signer invitation state before handoff and rotates an unknown generation', function (): void {
    [$envelope, $firstSigner] = createSigningEnvelope([FieldType::Signature], false);
    $secondSigner = $envelope->signers()->create([
        'name' => 'Taylor Signer',
        'email' => 'taylor@example.test',
        'position' => 1,
    ]);
    $secondSigner->fields()->create([
        'page' => 1,
        'type' => FieldType::Signature,
        'required' => true,
        'position_x' => .2,
        'position_y' => .2,
        'width' => .3,
        'height' => .08,
    ]);
    $mailManager = Mail::getFacadeRoot()->manager;
    $capturedTokens = [];

    Mail::shouldReceive('to')->twice()->andReturnUsing(function (string $email) use ($firstSigner, $secondSigner, &$capturedTokens): PendingMail {
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andReturnUsing(
            function (SigningInvitation $mail) use ($email, $firstSigner, $secondSigner, &$capturedTokens): void {
                $signer = $email === $firstSigner->email ? $firstSigner : $secondSigner;
                $capturedTokens[$signer->getKey()] = $mail->linkToken;
                expect($mail->signer->is($signer))->toBeTrue()
                    ->and($signer->fresh()->link_token_hash)->not->toBeNull()
                    ->and($signer->mailDeliveries()->latest('generation')->firstOrFail()->status)
                    ->toBe(MailDeliveryStatus::Sending);

                if ($signer->is($secondSigner)) {
                    throw new RuntimeException('Ambiguous invitation handoff.');
                }
            },
        );

        return $pending;
    });

    resolve(SendEnvelope::class)->handle($envelope->team, $envelope->sender, $envelope->getKey());
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Sent)
        ->and($firstSigner->mailDeliveries()->firstOrFail()->status)->toBe(MailDeliveryStatus::Sent)
        ->and($secondSigner->mailDeliveries()->firstOrFail()->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($envelope->auditEvents()->where('type', AuditEventType::EmailSent)->count())->toBe(1)
        ->and($firstSigner->fresh()->link_token_hash)->not->toBe($secondSigner->fresh()->link_token_hash)
        ->and($firstSigner->fresh()->link_token_hash)->toBe(hash('sha256', $capturedTokens[$firstSigner->getKey()]))
        ->and($secondSigner->fresh()->link_token_hash)->toBe(hash('sha256', $capturedTokens[$secondSigner->getKey()]))
        ->and(fn () => resolve(SendEnvelope::class)->handle(
            $envelope->team,
            $envelope->sender,
            $envelope->getKey(),
        ))->toThrow(DomainException::class, 'draft envelope');

    $oldHash = $secondSigner->fresh()->link_token_hash;
    Mail::swap($mailManager);
    Mail::fake();
    expect(resolve(RotateSigningInvitation::class)->handle(
        $envelope->team,
        $envelope->sender,
        $envelope->getKey(),
        $secondSigner->getKey(),
    ))->toBeTrue();
    $deliveries = $secondSigner->mailDeliveries()->orderBy('generation')->get();
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[0]->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($deliveries[1]->status)->toBe(MailDeliveryStatus::Sent)
        ->and($secondSigner->fresh()->link_token_hash)->not->toBe($oldHash)
        ->and($envelope->auditEvents()->where('type', AuditEventType::EmailSent)->count())->toBe(1)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Reminded)->count())->toBe(1);
    Mail::assertSent(SigningInvitation::class, 1);
});

it('marks an ambiguous OTP handoff unknown and requires route-level code rotation', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $mailManager = Mail::getFacadeRoot()->manager;
    $pending = Mockery::mock(PendingMail::class);
    $pending->shouldReceive('send')->once()->andReturnUsing(function (SigningCode $mail) use ($signer): void {
        expect($signer->fresh()->otp_hash)->not->toBeNull()
            ->and($signer->mailDeliveries()
                ->where('purpose', MailDeliveryPurpose::SigningCode->value)
                ->firstOrFail()->status)->toBe(MailDeliveryStatus::Sending);
        throw new RuntimeException('Ambiguous OTP handoff.');
    });
    Mail::shouldReceive('to')->once()->with($signer->email)->andReturn($pending);

    $this->get(route('sign.open', $token))->assertRedirect(route('sign.show'));
    $firstHash = $signer->fresh()->otp_hash;
    expect($signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->firstOrFail()->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($envelope->auditEvents()->where('type', AuditEventType::CodeSent)->count())->toBe(0);
    $this->get(route('sign.show'))
        ->assertOk()
        ->assertSee('We could not confirm that the code was delivered')
        ->assertSee('Send a new code');

    Mail::swap($mailManager);
    Mail::fake();
    $this->post(route('sign.code.rotate'))->assertRedirect(route('sign.show'));
    $deliveries = $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->orderBy('generation')
        ->get();
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[0]->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($deliveries[1]->status)->toBe(MailDeliveryStatus::Sent)
        ->and($signer->fresh()->otp_hash)->not->toBe($firstHash)
        ->and($envelope->auditEvents()->where('type', AuditEventType::CodeSent)->count())->toBe(1);
    Mail::assertSent(SigningCode::class, fn (SigningCode $mail): bool => Hash::check($mail->code, $signer->fresh()->otp_hash));
});

it('refuses code rotation while the latest delivery is not an unknown handoff', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $firstHash = $signer->fresh()->otp_hash;
    expect($signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->firstOrFail()->status)->toBe(MailDeliveryStatus::Sent);

    $this->post(route('sign.code.rotate'))->assertRedirect()->assertSessionHasErrors('code');
    expect($signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::SigningCode->value)->count())->toBe(1)
        ->and($signer->fresh()->otp_hash)->toBe($firstHash)
        ->and($envelope->auditEvents()->where('type', AuditEventType::CodeSent)->count())->toBe(1);
});

it('refuses a fourth code rotation under the per-signer issue throttle', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->firstOrFail()->update(['status' => MailDeliveryStatus::Unknown]);
    $firstHash = $signer->fresh()->otp_hash;
    $signerKey = 'sign-code-signer:'.$signer->getKey();
    RateLimiter::hit($signerKey, 600);
    RateLimiter::hit($signerKey, 600);
    RateLimiter::hit($signerKey, 600);

    $this->post(route('sign.code.rotate'))->assertRedirect()->assertSessionHasErrors('code');
    expect($signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::SigningCode->value)->count())->toBe(1)
        ->and($signer->fresh()->otp_hash)->toBe($firstHash)
        ->and($envelope->auditEvents()->where('type', AuditEventType::CodeSent)->count())->toBe(1);
});

it('refuses code rotation for a signer that is no longer viewable', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->firstOrFail()->update(['status' => MailDeliveryStatus::Unknown]);
    $firstHash = $signer->fresh()->otp_hash;
    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update(['status' => SignerStatus::Signed, 'signed_at' => now()]);
    });

    $this->post(route('sign.code.rotate'))->assertOk()->assertSee('data-testid="signer-neutral"', false);
    expect($signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::SigningCode->value)->count())->toBe(1)
        ->and($signer->fresh()->otp_hash)->toBe($firstHash);
});

it('refuses rotation at the action level for a signer that is no longer viewable', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect(route('sign.show'));
    $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->firstOrFail()->update(['status' => MailDeliveryStatus::Unknown]);
    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update(['status' => SignerStatus::Signed, 'signed_at' => now()]);
    });

    expect(fn () => resolve(RotateSigningCode::class)->handle($signer->refresh(), '192.0.2.30', 'Rotation admission test'))
        ->toThrow(SigningAccessDenied::class)
        ->and($signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::SigningCode->value)->count())->toBe(1);
});

it('keeps a failed decline notification unknown without a retry seam and renders truthful status', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $mailManager = Mail::getFacadeRoot()->manager;
    $pending = Mockery::mock(PendingMail::class);
    $pending->shouldReceive('send')->once()->andReturnUsing(function (SigningDeclined $mail) use ($signer): void {
        expect($signer->mailDeliveries()
            ->where('purpose', MailDeliveryPurpose::DeclineNotification->value)
            ->firstOrFail()->status)->toBe(MailDeliveryStatus::Sending);
        throw new RuntimeException('Decline notification failed.');
    });
    Mail::shouldReceive('to')->once()->with($envelope->sender->email)->andReturn($pending);

    $this->post(route('sign.decline'), ['reason' => 'Terms do not match.'])
        ->assertOk()
        ->assertSee('data-testid="signer-declined"', false)
        ->assertSeeText('Your decline has been recorded. The sender can see it in Witness.')
        ->assertDontSeeText('The sender has been notified.');
    $delivery = $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::DeclineNotification->value)
        ->firstOrFail();
    expect($delivery->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Declined)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Declined)->count())->toBe(1);
    resolve(DeclineEnvelope::class)->handle($signer->fresh(), 'Terms do not match.', null, null);
    expect($signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::DeclineNotification->value)
        ->count())->toBe(1);

    Mail::swap($mailManager);
    Mail::fake();
    $this->actingAsVersioned($envelope->sender)
        ->withSession(['witness.team_id' => $envelope->team_id])
        ->get(route('sender.envelopes.edit', $envelope))
        ->assertOk()
        ->assertSeeText('Decline notification: unknown');
    expect($envelope->auditEvents()->where('type', AuditEventType::Declined)->count())->toBe(1);
});

it('shows a sent decline notification in the sender envelope view', function (): void {
    [$envelope, , $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.decline'), ['reason' => 'I cannot accept.'])->assertOk();

    $this->actingAsVersioned($envelope->sender)
        ->withSession(['witness.team_id' => $envelope->team_id])
        ->get(route('sender.envelopes.edit', $envelope))
        ->assertOk()
        ->assertSeeText('Decline notification: sent');
});

it('permits only one clean claim for a durable delivery generation', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    $delivery = MailDelivery::query()
        ->where('signer_id', $signer->getKey())
        ->where('purpose', MailDeliveryPurpose::SigningInvitation->value)
        ->firstOrFail();
    $handoffs = 0;

    expect(resolve(OutboundMailDelivery::class)->deliver(
        $delivery,
        function () use (&$handoffs): void {
            $handoffs++;
        },
        AuditEventType::EmailSent,
        AuditActorType::System,
    ))->toBeFalse()
        ->and($handoffs)->toBe(0)
        ->and($envelope->auditEvents()->where('type', AuditEventType::EmailSent)->count())->toBe(1);
});

it('reconciles stale pending and sending generations under scheduled system authority', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    $pending = $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningInvitation->value)
        ->firstOrFail();
    $pending->update(['status' => MailDeliveryStatus::Pending, 'attempted_at' => null, 'sent_at' => null]);
    DB::table('mail_deliveries')->where('id', $pending->getKey())->update([
        'created_at' => now()->subMinutes(11),
        'updated_at' => now()->subMinutes(11),
    ]);
    $sending = resolve(OutboundMailDelivery::class)->create($envelope, $signer, MailDeliveryPurpose::SigningCode);
    $sending->update([
        'status' => MailDeliveryStatus::Sending,
        'attempted_at' => now()->subMinutes(11),
    ]);
    $fresh = resolve(OutboundMailDelivery::class)->create($envelope, $signer, MailDeliveryPurpose::DeclineNotification);
    $event = collect(resolve(Schedule::class)->events())->first(
        fn (mixed $event): bool => $event instanceof CallbackEvent
            && $event->description === 'witness:cleanup-private-files',
    );
    expect($event)->toBeInstanceOf(CallbackEvent::class);
    assert($event instanceof CallbackEvent);

    $authorityStates = [];
    $lockOrder = [];
    DB::listen(function (QueryExecuted $query) use (&$authorityStates, &$lockOrder): void {
        if (str_contains($query->sql, 'mail_deliveries')) {
            $authorityStates[] = resolve(SystemAuthorityContext::class)->active();
        }
        if (str_contains(strtolower($query->sql), 'for update')) {
            foreach (['envelopes', 'signers', 'mail_deliveries'] as $table) {
                if (str_contains($query->sql, $table)) {
                    $lockOrder[] = $table;
                }
            }
        }
    });

    $event->run(app());

    expect($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and(array_unique($authorityStates))->toBe([true])
        ->and($lockOrder)->toBe([
            'envelopes', 'signers', 'mail_deliveries',
            'envelopes', 'signers', 'mail_deliveries',
        ])
        ->and($pending->fresh()->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($sending->fresh()->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($fresh->fresh()->status)->toBe(MailDeliveryStatus::Pending);

    $handoffs = 0;
    expect(resolve(OutboundMailDelivery::class)->deliver(
        $pending,
        function () use (&$handoffs): void {
            $handoffs++;
        },
        AuditEventType::EmailSent,
        AuditActorType::System,
    ))->toBeFalse()
        ->and($handoffs)->toBe(0);
});

it('lets a signer send a new code after scheduled stale pending reconciliation', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect(route('sign.show'));
    $delivery = $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->firstOrFail();
    $delivery->update([
        'status' => MailDeliveryStatus::Pending,
        'attempted_at' => null,
        'sent_at' => null,
    ]);
    DB::table('mail_deliveries')->where('id', $delivery->getKey())->update([
        'created_at' => now()->subMinutes(11),
        'updated_at' => now()->subMinutes(11),
    ]);
    $event = collect(resolve(Schedule::class)->events())->first(
        fn (mixed $event): bool => $event instanceof CallbackEvent
            && $event->description === 'witness:cleanup-private-files',
    );
    assert($event instanceof CallbackEvent);
    $event->run(app());

    Mail::fake();
    $this->get(route('sign.show'))
        ->assertOk()
        ->assertSeeText('Send a new code');
    $this->post(route('sign.code.rotate'))->assertRedirect(route('sign.show'));
    expect($signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->orderBy('generation')
        ->pluck('status')->all())->toBe([
            MailDeliveryStatus::Unknown,
            MailDeliveryStatus::Sent,
        ]);
});

it('lets the owning sender rotate links for sent non-terminal signers and refuses terminal signers', function (): void {
    [$envelope, $signer, $oldToken] = createSigningEnvelope([FieldType::Signature]);
    $firstDelivery = $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningInvitation->value)
        ->firstOrFail();
    $firstDelivery->update(['status' => MailDeliveryStatus::Unknown, 'unknown_at' => now()]);
    $oldHash = $signer->link_token_hash;
    $this->actingAsVersioned($envelope->sender)
        ->withSession(['witness.team_id' => $envelope->team_id]);

    $this->get(route('sender.envelopes.edit', $envelope))
        ->assertOk()
        ->assertSeeText('Resend link');
    $this->post(route('sender.envelopes.signers.resend-link', [$envelope, $signer]))
        ->assertRedirect()
        ->assertSessionHas('status', 'A new signing link was handed to the configured mailer.');
    $secondHash = $signer->fresh()->link_token_hash;
    expect($secondHash)->not->toBe($oldHash)
        ->and($signer->mailDeliveries()
            ->where('purpose', MailDeliveryPurpose::SigningInvitation->value)
            ->orderBy('generation')
            ->pluck('status')->all())->toBe([
                MailDeliveryStatus::Unknown,
                MailDeliveryStatus::Sent,
            ]);
    $this->get(route('sign.open', $oldToken))
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false);

    $newToken = null;
    Mail::assertSent(SigningInvitation::class, function (SigningInvitation $mail) use (&$newToken, $secondHash): bool {
        if (hash('sha256', $mail->linkToken) === $secondHash) {
            $newToken = $mail->linkToken;

            return true;
        }

        return false;
    });
    expect($newToken)->toBeString();
    $this->get(route('sign.open', $newToken))->assertRedirect(route('sign.show'));

    $this->actingAsVersioned($envelope->sender)
        ->withSession(['witness.team_id' => $envelope->team_id])
        ->post(route('sender.envelopes.signers.resend-link', [$envelope, $signer]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($signer->fresh()->status)->toBe(SignerStatus::Viewed)
        ->and($signer->mailDeliveries()
            ->where('purpose', MailDeliveryPurpose::SigningInvitation->value)
            ->count())->toBe(3);

    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update(['status' => SignerStatus::Signed]);
    });
    $this->get(route('sender.envelopes.edit', $envelope))
        ->assertOk()
        ->assertDontSeeText('Resend link');
    $this->from(route('sender.envelopes.edit', $envelope))
        ->post(route('sender.envelopes.signers.resend-link', [$envelope, $signer]))
        ->assertRedirect(route('sender.envelopes.edit', $envelope))
        ->assertSessionHasErrors('resend');
    expect($signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningInvitation->value)
        ->count())->toBe(3);
});

it('refuses code rotation for a declined envelope whose expiry is still in the future', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect(route('sign.show'));
    $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->firstOrFail()->update(['status' => MailDeliveryStatus::Unknown->value]);
    DB::transaction(function () use ($envelope): void {
        DB::statement("SET LOCAL witness.envelope_transition = 'on'");
        DB::table('envelopes')->where('id', $envelope->getKey())
            ->update(['status' => EnvelopeStatus::Declined->value, 'updated_at' => now()]);
        DB::statement("SET LOCAL witness.envelope_transition = 'off'");
    });
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Declined)
        ->and($envelope->fresh()->expires_at?->isPast())->toBeFalse()
        ->and($signer->fresh()->status)->toBe(SignerStatus::Viewed);

    expect(fn () => resolve(RotateSigningCode::class)->handle($signer->refresh(), '192.0.2.31', 'Rotation admission probe'))
        ->toThrow(SigningAccessDenied::class)
        ->and($signer->mailDeliveries()->where('purpose', MailDeliveryPurpose::SigningCode->value)->count())->toBe(1);
});

it('refuses code rotation while the latest delivery is a fresh sending handoff', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect(route('sign.show'));
    $hashBefore = $signer->fresh()->otp_hash;
    $latest = $signer->fresh()->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->orderByDesc('generation')
        ->firstOrFail();
    DB::table('mail_deliveries')->where('id', $latest->getKey())->update(['status' => MailDeliveryStatus::Sending->value, 'attempted_at' => now()]);

    try {
        resolve(RotateSigningCode::class)->handle($signer->fresh(), '192.0.2.31', 'Rotation admission probe');
        test()->fail('A fresh in-flight code delivery was rotated anyway.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['code'][0])->toContain('still in progress');
    }

    expect($signer->fresh()->otp_hash)->toBe($hashBefore)
        ->and($latest->fresh()->status)->toBe(MailDeliveryStatus::Sending)
        ->and($signer->fresh()->mailDeliveries()->where('purpose', MailDeliveryPurpose::SigningCode->value)->count())->toBe(1);
    Mail::assertSent(SigningCode::class, 1);
});

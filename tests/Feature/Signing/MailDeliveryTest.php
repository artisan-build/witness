<?php

declare(strict_types=1);

use App\Actions\DeclineEnvelope;
use App\Actions\RetryDeclineNotification;
use App\Actions\RotateSigningInvitation;
use App\Actions\SendEnvelope;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Mail\SigningCode;
use App\Mail\SigningDeclined;
use App\Mail\SigningInvitation;
use App\Models\MailDelivery;
use App\Services\OutboundMailDelivery;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

it('commits multi-signer invitation state before handoff and rotates only the unknown generation', function (): void {
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

    Mail::shouldReceive('to')->twice()->andReturnUsing(function (string $email) use ($firstSigner, $secondSigner): PendingMail {
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andReturnUsing(
            function (SigningInvitation $mail) use ($email, $firstSigner, $secondSigner): void {
                $signer = $email === $firstSigner->email ? $firstSigner : $secondSigner;
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
        $secondSigner->getKey(),
    ))->toBeTrue();
    $deliveries = $secondSigner->mailDeliveries()->orderBy('generation')->get();
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[0]->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($deliveries[1]->status)->toBe(MailDeliveryStatus::Sent)
        ->and($secondSigner->fresh()->link_token_hash)->not->toBe($oldHash)
        ->and($envelope->auditEvents()->where('type', AuditEventType::EmailSent)->count())->toBe(2);
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

it('marks a failed decline notification unknown and retries it without repeating decline state', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect();
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
        ->assertSee('data-testid="signer-declined"', false);
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
    expect(resolve(RetryDeclineNotification::class)->handle(
        $envelope->team,
        $envelope->sender,
        $signer->getKey(),
    ))->toBeTrue();
    expect($signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::DeclineNotification->value)
        ->orderBy('generation')
        ->pluck('status')->all())->toBe([
            MailDeliveryStatus::Unknown,
            MailDeliveryStatus::Sent,
        ])->and($envelope->auditEvents()->where('type', AuditEventType::Declined)->count())->toBe(1);
    Mail::assertSent(SigningDeclined::class, 1);
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

it('requires a stale claim before explicit secret rotation can recover a crashed handoff', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect(route('sign.show'));
    $delivery = $signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->firstOrFail();
    $delivery->update([
        'status' => MailDeliveryStatus::Sending,
        'attempted_at' => now()->subMinutes(9),
    ]);

    $this->from(route('sign.show'))->post(route('sign.code.rotate'))
        ->assertRedirect(route('sign.show'))
        ->assertSessionHasErrors('code');
    expect($signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->count())->toBe(1);

    $delivery->update(['attempted_at' => now()->subMinutes(11)]);
    Mail::fake();
    $this->get(route('sign.open', $token))->assertRedirect(route('sign.show'));
    $this->post(route('sign.code.rotate'))->assertRedirect(route('sign.show'));
    expect($signer->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode->value)
        ->orderBy('generation')
        ->pluck('status')->all())->toBe([
            MailDeliveryStatus::Unknown,
            MailDeliveryStatus::Sent,
        ]);
});

<?php

declare(strict_types=1);

use App\Actions\DeclineEnvelope;
use App\Actions\SaveSignerFields;
use App\Actions\SignEnvelope;
use App\Actions\VerifySigningCode;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\SignerStatus;
use App\Exceptions\SigningAccessDenied;
use App\Mail\SigningCode;
use App\Mail\SigningDeclined;
use App\Mail\SigningInvitation;
use App\Models\Signer;
use ArtisanBuild\BuiltForCloud\Mail\ScalpelsTransport;
use Illuminate\Database\QueryException;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

it('keeps invalid, cross-envelope, sender-session, and terminal access neutral', function (): void {
    [$envelopeA, $signerA, $tokenA] = createSigningEnvelope([FieldType::Signature]);
    [$envelopeB, $signerB, $tokenB] = createSigningEnvelope([FieldType::Signature]);
    $neutral = $this->get(route('sign.open', 'tok123456'))->assertOk()->getContent();

    $this->actingAsVersioned($envelopeA->sender)
        ->get(route('sign.show'))
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false);

    openAndVerifySigner($this, $tokenA);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect();
    $foreignField = $signerB->fields()->firstOrFail();
    $ownField = $signerA->fields()->firstOrFail();
    $this->post(route('sign.fields'), [
        'fields' => [$foreignField->getKey() => ['kind' => 'typed', 'typed' => 'Not mine']],
    ])->assertOk()->assertSee('data-testid="signer-neutral"', false);

    expect($foreignField->fresh()->value)->toBeNull()
        ->and($ownField->fresh()->value)->toBeNull();

    openAndVerifySigner($this, $tokenA);
    resolve(DeclineEnvelope::class)->handle($signerA->fresh(), 'I cannot agree.', '198.51.100.8', 'Security test');
    $terminalA = $this->get(route('sign.open', $tokenA))->assertOk()->getContent();
    $stillValidB = $this->get(route('sign.open', $tokenB));

    expect($terminalA)->toBe($neutral)
        ->and($stillValidB->isRedirect(route('sign.show')))->toBeTrue()
        ->and($envelopeA->fresh()->status)->toBe(EnvelopeStatus::Declined)
        ->and($envelopeB->fresh()->status)->toBe(EnvelopeStatus::Sent);
});

it('enforces consent before field writes or signing even when UI steps are skipped', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $field = $signer->fields()->firstOrFail();

    expect(fn () => resolve(SaveSignerFields::class)->handle(
        $signer->fresh(),
        [$field->getKey() => ['kind' => 'typed', 'typed' => 'Skipped consent']],
        '192.0.2.4',
        'Consent bypass test',
    ))->toThrow(SigningAccessDenied::class)
        ->and(fn () => resolve(SignEnvelope::class)->handle(
            $signer->fresh(),
            '192.0.2.4',
            'Consent bypass test',
        ))->toThrow(SigningAccessDenied::class)
        ->and($field->fresh()->value)->toBeNull()
        ->and($signer->fresh()->status)->toBe(SignerStatus::Viewed)
        ->and($signer->envelope->auditEvents()->where('type', AuditEventType::Consented)->count())->toBe(0);
});

it('expires, isolates, and rotates OTPs without exposing plaintext state', function (): void {
    [, $signerA, $tokenA] = createSigningEnvelope([FieldType::Signature]);
    [, $signerB, $tokenB] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $tokenA))->assertRedirect();
    $codeA = null;
    Mail::assertSent(SigningCode::class, function (SigningCode $mail) use ($signerA, &$codeA): bool {
        if ($mail->signer->is($signerA)) {
            $codeA = $mail->code;
        }

        return $mail->signer->is($signerA);
    });
    $firstHash = $signerA->fresh()->otp_hash;

    $this->get(route('sign.open', $tokenB))->assertRedirect();
    $this->post(route('sign.verify'), ['code' => $codeA])->assertSessionHasErrors('code');
    expect($signerA->fresh()->otp_attempts)->toBe(0)
        ->and($signerB->fresh()->otp_attempts)->toBe(1);

    DB::transaction(function () use ($signerA): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signerA)->update(['otp_expires_at' => now()->subSecond()]);
    });
    $this->get(route('sign.open', $tokenA))->assertRedirect();
    $signerA->refresh();
    expect($signerA->otp_hash)->not->toBe($firstHash)
        ->and($signerA->otp_expires_at->isFuture())->toBeTrue()
        ->and(Hash::check($codeA, $signerA->otp_hash))->toBeFalse()
        ->and(json_encode($signerA->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($codeA);
});

it('declines once, encrypts the reason, notifies once, and keeps audit payloads secret-free', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect();
    $reason = 'Terms do not match.';

    $this->post(route('sign.decline'), ['reason' => $reason])
        ->assertOk()
        ->assertSee('data-testid="signer-declined"', false);
    resolve(DeclineEnvelope::class)->handle($signer->fresh(), $reason, '192.0.2.9', 'Duplicate decline');

    $rawReason = DB::table('signers')->where('id', $signer->getKey())->value('decline_reason');
    $events = $envelope->auditEvents()->where('type', AuditEventType::Declined)->get();
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Declined)
        ->and($signer->fresh()->status)->toBe(SignerStatus::Declined)
        ->and($signer->fresh()->decline_reason)->toBe($reason)
        ->and($rawReason)->not->toContain($reason)
        ->and($events)->toHaveCount(1)
        ->and(json_encode($events->first()->payload, JSON_THROW_ON_ERROR))->not->toContain($reason);
    Mail::assertSent(SigningDeclined::class, 1);
});

it('rejects executable typed content and malformed drawn signatures without persistence', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect();
    $field = $signer->fields()->firstOrFail();

    $this->post(route('sign.fields'), ['fields' => [
        $field->getKey() => ['kind' => 'typed', 'typed' => '<svg onload=alert(1)>'],
    ]])->assertSessionHasErrors('fields');
    expect(session('_old_input.fields'))->toBeNull()
        ->and($field->fresh()->value)->toBeNull();

    $this->post(route('sign.fields'), ['fields' => [
        $field->getKey() => ['kind' => 'drawn', 'drawn' => 'data:image/png;base64,bm90LXBuZw=='],
    ]])->assertSessionHasErrors('fields');
    expect(session('_old_input.fields'))->toBeNull()
        ->and($field->fresh()->value)->toBeNull();
});

it('redacts short rejected codes from exception traces and durable non-secret columns', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect();
    $canary = 'otpCAN9';

    try {
        resolve(VerifySigningCode::class)->handle($signer->fresh(), $canary, '192.0.2.5', 'Leak probe');
        test()->fail('The rejected code unexpectedly verified.');
    } catch (ValidationException $exception) {
        $traceStrings = collect($exception->getTrace())
            ->flatMap(fn (array $frame): array => array_values(array_filter($frame['args'] ?? [], 'is_string')))
            ->implode('|');
        expect($traceStrings)->not->toContain($canary);
    }

    $attributes = $signer->fresh()->getAttributes();
    unset($attributes['otp_hash']);
    expect(json_encode($attributes, JSON_THROW_ON_ERROR))->not->toContain($canary)
        ->and(json_encode($signer->envelope->auditEvents()->pluck('payload'), JSON_THROW_ON_ERROR))->not->toContain($canary);
});

it('rejects direct signer progress writes and uses the configured default mailer', function (): void {
    [, $signer] = createSigningEnvelope([FieldType::Signature]);

    expect(fn () => DB::transaction(
        fn () => Signer::query()->whereKey($signer)->update(['status' => SignerStatus::Signed]),
    ))->toThrow(QueryException::class, 'locked domain action');

    Mail::assertSent(SigningCode::class, 0);
    Mail::assertSent(SigningInvitation::class, function ($mail): bool {
        return in_array($mail->mailer, [null, config('mail.default')], true);
    });
});

it('resolves both supported default mailer configurations without Witness transport code', function (): void {
    $manager = resolve(MailManager::class);
    config(['mail.default' => 'scalpels']);
    $manager->purge();
    expect($manager->mailer()->getSymfonyTransport())->toBeInstanceOf(ScalpelsTransport::class);

    config(['mail.default' => 'array']);
    $manager->purge();
    expect($manager->mailer()->getSymfonyTransport())->toBeInstanceOf(ArrayTransport::class);
});

it('throttles public signer routes by IP without resolving a human user', function (): void {
    for ($request = 1; $request <= 30; $request++) {
        $this->get(route('sign.open', 'bad'.$request))->assertOk();
    }

    $this->get(route('sign.open', 'bad31'))->assertTooManyRequests();
});

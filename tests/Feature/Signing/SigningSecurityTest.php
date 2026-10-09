<?php

declare(strict_types=1);

use App\Actions\CreateDraftEnvelope;
use App\Actions\DeclineEnvelope;
use App\Actions\OpenSigningLink;
use App\Actions\RecordSigningConsent;
use App\Actions\RotateSigningCode;
use App\Actions\SaveSignerFields;
use App\Actions\SendEnvelope;
use App\Actions\SignEnvelope;
use App\Actions\VerifySigningCode;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeReadyForCompletion;
use App\Exceptions\SigningAccessDenied;
use App\Mail\SigningCode;
use App\Mail\SigningDeclined;
use App\Mail\SigningInvitation;
use App\Models\Envelope;
use App\Models\Signer;
use App\Models\Team;
use App\Services\SigningSession;
use ArtisanBuild\BuiltForCloud\Mail\ScalpelsTransport;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\HostileAuthUser;

it('makes double submission idempotent with exactly one signed audit event', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect(route('sign.show'));
    $signature = $signer->fields()->where('type', FieldType::Signature)->firstOrFail();
    $this->post(route('sign.fields'), ['fields' => [
        $signature->getKey() => ['kind' => 'typed', 'typed' => 'Alex Signer'],
    ]])->assertRedirect(route('sign.show'))->assertSessionHasNoErrors();
    Event::fake([EnvelopeReadyForCompletion::class]);

    $first = resolve(SignEnvelope::class)->handle($signer->fresh(), '192.0.2.21', 'Idempotence probe');
    $second = resolve(SignEnvelope::class)->handle($signer->fresh(), '192.0.2.21', 'Idempotence probe');

    expect($first->is($second))->toBeTrue()
        ->and($signer->fresh()->status)->toBe(SignerStatus::Signed)
        ->and($signer->fresh()->signed_at)->not->toBeNull()
        ->and($envelope->auditEvents()->where('type', AuditEventType::Signed)->count())->toBe(1);
});

it('keeps invalid, cross-envelope, sender-session, and terminal access neutral', function (): void {
    [$envelopeA, $signerA, $tokenA] = createSigningEnvelope([FieldType::Signature]);
    [$envelopeB, $signerB, $tokenB] = createSigningEnvelope([FieldType::Signature]);
    $initialAuditCounts = [$envelopeA->auditEvents()->count(), $envelopeB->auditEvents()->count()];
    $this->get(route('sign.open', 'tok123456'))
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false)
        ->assertSee('This signing link is unavailable')
        ->assertDontSee($envelopeA->title)
        ->assertDontSee($envelopeB->title)
        ->assertDontSee($signerA->email)
        ->assertDontSee($signerB->email);
    expect([$envelopeA->auditEvents()->count(), $envelopeB->auditEvents()->count()])->toBe($initialAuditCounts);

    $this->actingAsVersioned($envelopeA->sender)
        ->get(route('sign.show'))
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false)
        ->assertSee('This signing link is unavailable')
        ->assertDontSee($envelopeA->title)
        ->assertDontSee($signerA->email);
    expect([$envelopeA->auditEvents()->count(), $envelopeB->auditEvents()->count()])->toBe($initialAuditCounts);

    openAndVerifySigner($this, $tokenA);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect();
    $foreignField = $signerB->fields()->firstOrFail();
    $ownField = $signerA->fields()->firstOrFail();
    $auditCountBeforeForeignWrite = $envelopeA->auditEvents()->count();
    $this->post(route('sign.fields'), [
        'fields' => [$foreignField->getKey() => ['kind' => 'typed', 'typed' => 'Not mine']],
    ])->assertOk()
        ->assertSee('data-testid="signer-neutral"', false)
        ->assertSee('This signing link is unavailable')
        ->assertDontSee($envelopeB->title)
        ->assertDontSee($signerB->email);

    expect($foreignField->fresh()->value)->toBeNull()
        ->and($ownField->fresh()->value)->toBeNull()
        ->and($envelopeA->auditEvents()->count())->toBe($auditCountBeforeForeignWrite);

    openAndVerifySigner($this, $tokenA);
    resolve(DeclineEnvelope::class)->handle($signerA->fresh(), 'I cannot agree.', '198.51.100.8', 'Security test');
    $terminalAuditCount = $envelopeA->auditEvents()->count();
    $this->get(route('sign.open', $tokenA))
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false)
        ->assertSee('This signing link is unavailable')
        ->assertDontSee($envelopeA->title)
        ->assertDontSee($signerA->email)
        ->assertDontSee('I cannot agree.');
    $stillValidB = $this->get(route('sign.open', $tokenB));

    expect($stillValidB->isRedirect(route('sign.show')))->toBeTrue()
        ->and($envelopeA->fresh()->status)->toBe(EnvelopeStatus::Declined)
        ->and($signerA->fresh()->decline_reason)->toBe('I cannot agree.')
        ->and($envelopeA->auditEvents()->count())->toBe($terminalAuditCount)
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

it('allows decline after OTP verification without consent and refuses it before verification', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect(route('sign.show'));

    $this->post(route('sign.decline'), ['reason' => 'Not verified yet.'])
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false);
    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Sent)
        ->and($signer->fresh()->status)->toBe(SignerStatus::Viewed)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Consented)->count())->toBe(0);

    $code = null;
    Mail::assertSent(SigningCode::class, function (SigningCode $mail) use (&$code, $signer): bool {
        if ($mail->signer->is($signer)) {
            $code = $mail->code;
        }

        return $mail->signer->is($signer);
    });
    $this->post(route('sign.verify'), ['code' => $code])->assertRedirect(route('sign.show'));
    $this->get(route('sign.show'))
        ->assertOk()
        ->assertSee('data-testid="signer-consent"', false)
        ->assertSee('data-testid="signer-decline"', false);
    $this->post(route('sign.decline'), ['reason' => 'I do not consent.'])
        ->assertOk()
        ->assertSeeText('Your decline has been recorded. The sender can see it in Witness.');

    expect($envelope->fresh()->status)->toBe(EnvelopeStatus::Declined)
        ->and($signer->fresh()->status)->toBe(SignerStatus::Declined)
        ->and($signer->fresh()->consented_at)->toBeNull()
        ->and($envelope->auditEvents()->where('type', AuditEventType::Consented)->count())->toBe(0)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Declined)->count())->toBe(1);
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
    Mail::assertSent(SigningDeclined::class, function (SigningDeclined $mail) use ($envelope): bool {
        $metadata = $mail->envelope();

        return $metadata->from?->name === $envelope->sender->name.' via Witness'
            && $metadata->replyTo[0]->address === $envelope->sender->email;
    });
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

    $validPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    $this->post(route('sign.fields'), ['fields' => [
        $field->getKey() => [
            'kind' => 'drawn',
            'drawn' => 'data:image/png;base64,'.base64_encode($validPng.'<script>alert(1)</script>'),
        ],
    ]])->assertSessionHasErrors('fields');
    expect($field->fresh()->value)->toBeNull()
        ->and(Storage::allFiles('signatures'))->toBeEmpty();

    $this->post(route('sign.fields'), ['fields' => [
        $field->getKey() => [
            'kind' => 'drawn',
            'drawn' => 'data:image/PNG;base64,'.base64_encode($validPng),
        ],
    ]])->assertSessionHasErrors('fields');
    expect($field->fresh()->value)->toBeNull()
        ->and(Storage::allFiles('signatures'))->toBeEmpty();
});

it('sets anti-framing policy on every signer HTML and redirect surface while sandboxing PDFs', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $assertHtmlPolicy = function ($response): void {
        $response->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    };

    $assertHtmlPolicy($this->get(route('sign.open', 'invalid-token')));
    $assertHtmlPolicy($this->get(route('sign.open', $token)));
    $assertHtmlPolicy($this->get(route('sign.show')));
    $assertHtmlPolicy($this->post(route('sign.verify'), ['code' => 'invalid']));
    $code = null;
    Mail::assertSent(SigningCode::class, function (SigningCode $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });
    $assertHtmlPolicy($this->post(route('sign.verify'), ['code' => $code]));
    $assertHtmlPolicy($this->get(route('sign.show')));
    $assertHtmlPolicy($this->post(route('sign.consent'), ['consent' => '1']));
    $assertHtmlPolicy($this->get(route('sign.show')));
    $pdf = $this->get(route('sign.document'))->assertOk();
    $pdf->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeaderMissing('X-Frame-Options');
    $field = $signer->fields()->firstOrFail();
    $assertHtmlPolicy($this->post(route('sign.fields'), ['fields' => [
        $field->getKey() => ['kind' => 'typed', 'typed' => 'Alex Signer', 'drawn' => ''],
    ]]));
    $assertHtmlPolicy($this->post(route('sign.complete'), ['intent' => '1']));

    [, , $declineToken] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $declineToken);
    $this->post(route('sign.consent'), ['consent' => '1']);
    $assertHtmlPolicy($this->post(route('sign.decline'), ['reason' => 'No thank you.']));
});

it('keeps human authentication irrelevant across every public signer endpoint', function (): void {
    [$envelope] = createSigningEnvelope([FieldType::Signature]);
    config([
        'auth.defaults.guard' => 'hostile',
        'auth.guards.hostile' => ['driver' => 'session', 'provider' => 'hostile-users'],
        'auth.providers.hostile-users' => ['driver' => 'eloquent', 'model' => HostileAuthUser::class],
    ]);
    Auth::forgetGuards();
    $guard = Auth::guard('hostile');
    $this->withSession([$guard->getName() => $envelope->sender_id]);
    $requests = [
        fn () => $this->get(route('sign.show')),
        fn () => $this->post(route('sign.verify'), ['code' => '123456']),
        fn () => $this->post(route('sign.code.rotate')),
        fn () => $this->post(route('sign.consent'), ['consent' => '1']),
        fn () => $this->get(route('sign.document')),
        fn () => $this->post(route('sign.fields'), ['fields' => []]),
        fn () => $this->post(route('sign.complete'), ['intent' => '1']),
        fn () => $this->post(route('sign.decline'), ['reason' => 'No']),
        fn () => $this->get(route('sign.open', 'invalid-token')),
    ];

    foreach ($requests as $request) {
        $request()->assertOk()
            ->assertSee('data-testid="signer-neutral"', false)
            ->assertDontSee($envelope->title);
    }
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
            ->flatMap(fn (array $frame): array => array_values(array_filter($frame['args'] ?? [], is_string(...))))
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
    Mail::assertSent(SigningInvitation::class, fn ($mail): bool => in_array($mail->mailer, [null, config('mail.default')], true));
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

it('deletes the stored png when a sibling field fails validation in the same save', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature, FieldType::Text]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect();
    $fields = $signer->fields()->orderBy('id')->get();
    $validPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

    $this->post(route('sign.fields'), ['fields' => [
        $fields[0]->getKey() => ['kind' => 'drawn', 'drawn' => 'data:image/png;base64,'.base64_encode($validPng)],
        $fields[1]->getKey() => ['kind' => 'text', 'text' => '<svg onload=alert(1)>'],
    ]])->assertSessionHasErrors('fields');

    expect($fields[0]->fresh()->value)->toBeNull()
        ->and(Storage::allFiles('signatures'))->toBeEmpty();

    $this->post(route('sign.fields'), ['fields' => [
        $fields[0]->getKey() => ['kind' => 'drawn', 'drawn' => 'data:image/png;base64,'.base64_encode($validPng)],
    ]])->assertRedirect();
    expect($filesAfterFirstSave = Storage::allFiles('signatures'))->toHaveCount(1);

    $this->post(route('sign.fields'), ['fields' => [
        $fields[0]->getKey() => ['kind' => 'drawn', 'drawn' => 'data:image/png;base64,'.base64_encode($validPng)],
    ]])->assertRedirect();
    expect(Storage::allFiles('signatures'))->toHaveCount(1)
        ->and(Storage::allFiles('signatures'))->not->toBe($filesAfterFirstSave);
});

it('skips untouched blank signature controls without aborting sibling saves', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature, FieldType::Text]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect();
    $fields = $signer->fields()->orderBy('id')->get();

    $this->post(route('sign.fields'), ['fields' => [
        $fields[0]->getKey() => ['kind' => 'drawn', 'drawn' => ''],
        $fields[1]->getKey() => ['kind' => 'text', 'text' => 'Typed value'],
    ]])->assertRedirect();

    expect($fields[1]->fresh()->value['value'])->toBe('Typed value')
        ->and($fields[0]->fresh()->value)->toBeNull();
});

it('locks a signer after five failed codes and refuses further verification', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect();
    $signer = $signer->fresh();

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        try {
            resolve(VerifySigningCode::class)->handle($signer->fresh(), 'wrong-code', '192.0.2.7', 'Lock probe');
            test()->fail('An invalid code unexpectedly verified.');
        } catch (ValidationException $exception) {
            expect($signer->fresh()->otp_locked_at)->toBeNull()
                ->and($exception->errors()['code'][0])->not->toContain('locked');
        }
    }

    try {
        resolve(VerifySigningCode::class)->handle($signer->fresh(), 'wrong-code', '192.0.2.7', 'Lock probe');
        test()->fail('The fifth invalid code did not lock the signer.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['code'][0])->toContain('locked');
    }

    expect($signer->fresh()->otp_locked_at)->not->toBeNull()
        ->and($signer->fresh()->otp_attempts)->toBe(5);

    expect(fn () => resolve(VerifySigningCode::class)->handle($signer->fresh(), 'wrong-code', '192.0.2.7', 'Lock probe'))
        ->toThrow(SigningAccessDenied::class);
});

it('cannot replay a code after it has been verified and resets attempts', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect();
    $code = null;
    Mail::assertSent(SigningCode::class, function (SigningCode $mail) use (&$code): bool {
        $code ??= $mail->code;

        return true;
    });

    try {
        resolve(VerifySigningCode::class)->handle($signer->fresh(), 'wrong-code', '192.0.2.8', 'Replay probe');
        test()->fail('An invalid code unexpectedly verified.');
    } catch (ValidationException) {
    }

    try {
        resolve(VerifySigningCode::class)->handle($signer->fresh(), 'wrong-code', '192.0.2.8', 'Replay probe');
        test()->fail('An invalid code unexpectedly verified.');
    } catch (ValidationException) {
    }

    $verified = resolve(VerifySigningCode::class)->handle($signer->fresh(), $code, '192.0.2.8', 'Replay probe');
    expect($verified->otp_verified_at)->not->toBeNull()
        ->and($verified->otp_hash)->toBeNull()
        ->and($verified->otp_attempts)->toBe(0);

    expect(fn () => resolve(VerifySigningCode::class)->handle($signer->fresh(), $code, '192.0.2.8', 'Replay probe'))
        ->toThrow(SigningAccessDenied::class);

    $failedAttempts = $verified->envelope->auditEvents()
        ->where('type', AuditEventType::CodeFailed)
        ->orderBy('sequence')
        ->get()
        ->map(fn ($event) => $event->payload['attempt']);

    expect($failedAttempts->all())->toBe([1, 2]);
});

function boundTokenSession(Signer $signer, string $tokenHash): Request
{
    $request = Request::create('/sign');
    $request->setLaravelSession(resolve('session.store'));
    resolve(SigningSession::class)->bind($request, $signer, $tokenHash);

    return $request;
}

it('binds and regenerates token-bound sessions and forgets tampered or invalid bindings', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $session = resolve(SigningSession::class);
    $tokenHash = hash('sha256', $token);

    $request = Request::create('/sign');
    $request->setLaravelSession(resolve('session.store'));
    $idBeforeBind = $request->session()->getId();
    $session->bind($request, $signer, $tokenHash);

    expect($request->session()->getId())->not->toBe($idBeforeBind)
        ->and($session->signer($request)->is($signer))->toBeTrue();

    $request->session()->put('witness.signer', ['signer_id' => $signer->getKey(), 'token_hash' => 'forged-token-hash']);
    expect(fn () => $session->signer($request))->toThrow(SigningAccessDenied::class)
        ->and($request->session()->has('witness.signer'))->toBeFalse();

    $request->session()->put('witness.signer', ['signer_id' => 999999999, 'token_hash' => $tokenHash]);
    expect(fn () => $session->signer($request))->toThrow(SigningAccessDenied::class);
});

it('denies sessions on draft and expired envelopes', function (): void {
    $team = Team::factory()->create();
    $sender = UserFactory::new()->create();
    $team->users()->attach($sender);

    $draft = Envelope::factory()->forOwner($team, $sender)->create(['title' => 'Draft session']);
    $draftSigner = Signer::factory()->for($draft)->create(['link_token_hash' => hash('sha256', 'draft-token')]);
    $session = resolve(SigningSession::class);
    $draftRequest = boundTokenSession($draftSigner, hash('sha256', 'draft-token'));
    expect(fn () => $session->signer($draftRequest))->toThrow(SigningAccessDenied::class);

    $expired = resolve(CreateDraftEnvelope::class)->handle(
        team: $team,
        sender: $sender,
        title: 'Expired session',
        originalName: 'expired-session.pdf',
        storagePath: 'documents/expired-session.pdf',
        sizeBytes: 128,
        pageCount: 1,
        originalSha256: hash('sha256', 'expired session document'),
        expiresAt: now()->subDay(),
    );
    $expiredSigner = Signer::factory()->for($expired)->create(['link_token_hash' => hash('sha256', 'expired-token')]);
    $expiredSigner->fields()->create([
        'page' => 1,
        'type' => 'signature',
        'required' => true,
        'position_x' => .1,
        'position_y' => .1,
        'width' => .2,
        'height' => .1,
    ]);
    resolve(SendEnvelope::class)->handle($team, $sender, $expired->getKey());
    $expiredRequest = boundTokenSession($expiredSigner, hash('sha256', 'expired-token'));
    expect(fn () => $session->signer($expiredRequest))->toThrow(SigningAccessDenied::class);
});

it('returns the neutral surface once the signer has declined', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.decline'), ['reason' => 'No.'])->assertOk();
    expect($signer->fresh()->status)->toBe(SignerStatus::Declined);

    $terminalRequest = boundTokenSession($signer->fresh(), hash('sha256', $token));
    expect(fn () => resolve(SigningSession::class)->signer($terminalRequest))->toThrow(SigningAccessDenied::class);

    $this->get(route('sign.show'))
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false)
        ->assertDontSee($envelope->title);
});

it('returns the neutral surface once the signer has signed', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect(route('sign.show'));
    $signature = $signer->fields()->where('type', FieldType::Signature)->firstOrFail();
    $this->post(route('sign.fields'), ['fields' => [
        $signature->getKey() => ['kind' => 'typed', 'typed' => 'Alex Signer'],
    ]])->assertRedirect(route('sign.show'))->assertSessionHasNoErrors();
    Event::fake([EnvelopeReadyForCompletion::class]);
    $this->post(route('sign.complete'), ['intent' => '1'])->assertOk();
    expect($signer->fresh()->status)->toBe(SignerStatus::Signed);

    $terminalRequest = boundTokenSession($signer->fresh(), hash('sha256', $token));
    expect(fn () => resolve(SigningSession::class)->signer($terminalRequest))->toThrow(SigningAccessDenied::class);

    $this->get(route('sign.show'))
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false)
        ->assertDontSee($envelope->title);
});

it('refuses consent before the OTP is verified and records one consent event with metadata', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect();

    expect(fn () => resolve(RecordSigningConsent::class)->handle($signer->fresh(), '192.0.2.6', 'Consent probe'))
        ->toThrow(SigningAccessDenied::class);

    openAndVerifySigner($this, $token);
    $consented = resolve(RecordSigningConsent::class)->handle($signer->fresh(), '192.0.2.6', 'Consent probe');

    expect($consented->consented_at)->not->toBeNull()
        ->and($consented->envelope->auditEvents()->where('type', AuditEventType::Consented)->count())->toBe(1);

    $event = $consented->envelope->auditEvents()->where('type', AuditEventType::Consented)->firstOrFail();
    expect($event->payload['disclosure_version'])->toBe('esign-v1')
        ->and($event->ip_address)->toBe('192.0.2.6')
        ->and($event->user_agent)->toBe('Consent probe');

    $again = resolve(RecordSigningConsent::class)->handle($signer->fresh(), '192.0.2.6', 'Consent probe');
    expect($again->consented_at)->not->toBeNull()
        ->and($again->envelope->auditEvents()->where('type', AuditEventType::Consented)->count())->toBe(1);
});

it('does not resend or rotate a live code and bounds reissues per signer', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect();

    Mail::assertSent(SigningCode::class, 1);
    $firstHash = $signer->fresh()->otp_hash;
    expect($signer->fresh()->status)->toBe(SignerStatus::Viewed)
        ->and($signer->fresh()->viewed_at)->not->toBeNull()
        ->and($signer->fresh()->envelope->auditEvents()->where('type', AuditEventType::Opened)->count())->toBe(1);

    $this->get(route('sign.open', $token))->assertRedirect();
    Mail::assertSent(SigningCode::class, 1);
    expect($signer->fresh()->otp_hash)->toBe($firstHash)
        ->and($signer->fresh()->otp_expires_at->isFuture())->toBeTrue();

    foreach ([1, 2] as $round) {
        DB::transaction(function () use ($signer): void {
            DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
            Signer::query()->whereKey($signer)->update(['otp_expires_at' => now()->subSecond()]);
        });
        $this->get(route('sign.open', $token))->assertRedirect();
    }
    Mail::assertSent(SigningCode::class, 3);

    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update(['otp_expires_at' => now()->subSecond()]);
    });
    expect(fn () => resolve(OpenSigningLink::class)->handle($token, '192.0.2.30', 'Reissue throttle probe'))
        ->toThrow(ValidationException::class);
    Mail::assertSent(SigningCode::class, 3);

    [$secondEnvelope, $secondSigner, $secondToken] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $secondToken))->assertRedirect();
    Mail::assertSent(SigningCode::class, fn (SigningCode $mail): bool => $mail->signer->is($secondSigner));
    expect($secondSigner->fresh()->otp_hash)->not->toBeNull();
});

it('bounds total code issues per ip across signers', function (): void {
    $codes = 0;

    foreach (range(1, 10) as $index) {
        [, $signer, $token] = createSigningEnvelope([FieldType::Signature], signerName: "Ip pool signer {$index}");
        $this->get(route('sign.open', $token))->assertRedirect();
        $signer->refresh();
        if ($signer->otp_hash !== null) {
            $codes++;
        }
    }

    expect($codes)->toBe(10);

    [, $eleventhSigner, $eleventhToken] = createSigningEnvelope([FieldType::Signature], signerName: 'Ip pool signer 11');
    expect(fn () => resolve(OpenSigningLink::class)->handle($eleventhToken, '127.0.0.1', 'Ip throttle probe'))
        ->toThrow(ValidationException::class);
});

it('refuses to sign while a required checkbox is unchecked', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope([FieldType::Signature, FieldType::Checkbox]);
    openAndVerifySigner($this, $token);
    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect(route('sign.show'));
    $fields = $signer->fields()->orderBy('id')->get();
    $validPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);

    $this->post(route('sign.fields'), ['fields' => [
        $fields[0]->getKey() => ['kind' => 'drawn', 'drawn' => 'data:image/png;base64,'.base64_encode($validPng)],
        $fields[1]->getKey() => ['kind' => 'checkbox', 'checked' => false],
    ]])->assertRedirect(route('sign.show'))->assertSessionHasNoErrors();
    expect($fields[1]->fresh()->value['checked'])->toBeFalse()
        ->and($fields[1]->fresh()->completed_at)->not->toBeNull();

    $this->post(route('sign.complete'), ['intent' => '1'])->assertSessionHasErrors('intent');

    expect($signer->fresh()->status)->not->toBe(SignerStatus::Signed)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Signed)->count())->toBe(0);
});

it('rotates only an unknown delivery for a viewed signer under the issue throttle', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    [, $pendingSigner] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect();
    $firstHash = $signer->fresh()->otp_hash;

    expect(fn () => resolve(RotateSigningCode::class)->handle($pendingSigner->fresh(), '192.0.2.41', 'Rotate probe'))
        ->toThrow(SigningAccessDenied::class);

    $latest = $signer->fresh()->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode)
        ->orderByDesc('generation')
        ->firstOrFail();
    DB::table('mail_deliveries')->where('id', $latest->getKey())->update(['status' => MailDeliveryStatus::Sending->value, 'attempted_at' => now()->subMinutes(30)]);

    $rotated = resolve(RotateSigningCode::class)->handle($signer->fresh(), '192.0.2.41', 'Rotate probe');

    $newest = $signer->fresh()->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode)
        ->orderByDesc('generation')
        ->firstOrFail();
    expect($rotated)->toBeTrue()
        ->and($latest->fresh()->status)->toBe(MailDeliveryStatus::Unknown)
        ->and($newest->fresh()->status)->toBe(MailDeliveryStatus::Sent)
        ->and($signer->fresh()->otp_hash)->not->toBe($firstHash);
    Mail::assertSent(SigningCode::class, 2);

    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update(['otp_expires_at' => now()->subSecond()]);
    });
    $this->get(route('sign.open', $token))->assertRedirect();
    Mail::assertSent(SigningCode::class, 3);

    $latest = $signer->fresh()->mailDeliveries()
        ->where('purpose', MailDeliveryPurpose::SigningCode)
        ->orderByDesc('generation')
        ->firstOrFail();
    DB::table('mail_deliveries')->where('id', $latest->getKey())->update(['status' => MailDeliveryStatus::Sending->value, 'attempted_at' => now()->subMinutes(30)]);
    DB::transaction(function () use ($signer): void {
        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update(['otp_expires_at' => now()->subSecond()]);
    });

    try {
        resolve(RotateSigningCode::class)->handle($signer->fresh(), '192.0.2.41', 'Rotate probe');
        test()->fail('The fourth code issue was not throttled.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['code'][0])->toContain('Please wait');
    }

    Mail::assertSent(SigningCode::class, 3);
});

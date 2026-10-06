<?php

declare(strict_types=1);

use App\Actions\SendEnvelope;
use App\Actions\SignEnvelope;
use App\Domain\Audit\AuditChainVerifier;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\SignerStatus;
use App\Events\EnvelopeReadyForCompletion;
use App\Mail\SigningCode;
use App\Mail\SigningInvitation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

it('completes every signer field type and exposes the PR4 completion seam', function (): void {
    [$envelope, $signer, $token] = createSigningEnvelope();
    expect($token)->toBeString()->not->toBe('')
        ->and($signer->link_token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode($signer->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($token);

    $code = openAndVerifySigner($this, $token);
    $signer->refresh();
    expect($signer->otp_hash)->toBeNull()
        ->and($signer->otp_verified_at)->not->toBeNull()
        ->and(json_encode($signer->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($code);
    $this->post(route('sign.verify'), ['code' => $code])
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false);
    expect($signer->envelope->auditEvents()->where('type', AuditEventType::CodeVerified)->count())->toBe(1);

    $this->post(route('sign.consent'), ['consent' => '1'])->assertRedirect(route('sign.show'));
    $this->get(route('sign.show'))
        ->assertOk()
        ->assertSee('data-testid="signer-pdf-viewer"', false)
        ->assertSee('data-testid="signer-fields"', false)
        ->assertSee('data-field-type="signature"', false)
        ->assertSee('data-field-type="initials"', false)
        ->assertSee('data-testid="signer-intent"', false)
        ->assertSee('data-testid="signer-decline"', false);

    $fields = $signer->fields()->get()->keyBy(fn ($field) => $field->type->value);
    $png = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $responses = [
        $fields[FieldType::Signature->value]->getKey() => ['kind' => 'typed', 'typed' => 'Alex Signer'],
        $fields[FieldType::Initials->value]->getKey() => ['kind' => 'drawn', 'drawn' => $png],
        $fields[FieldType::Text->value]->getKey() => ['text' => 'Approved'],
        $fields[FieldType::Checkbox->value]->getKey() => ['checked' => '1'],
    ];
    $this->post(route('sign.fields'), ['fields' => $responses])
        ->assertRedirect(route('sign.show'))->assertSessionHasNoErrors();
    $this->post(route('sign.fields'), ['fields' => $responses])
        ->assertRedirect(route('sign.show'))->assertSessionHasNoErrors();

    Event::fake([EnvelopeReadyForCompletion::class]);
    $this->post(route('sign.complete'), ['intent' => '1'])
        ->assertOk()
        ->assertSee('data-testid="signer-complete"', false);

    $signer->refresh();
    $envelope->refresh();
    resolve(SignEnvelope::class)->handle($signer, '192.0.2.20', 'Duplicate sign test');
    $drawn = $signer->fields()->where('type', FieldType::Initials)->firstOrFail();
    expect($signer->status)->toBe(SignerStatus::Signed)
        ->and($signer->fields()->whereNull('completed_at')->count())->toBe(0)
        ->and($fields[FieldType::DateSigned->value]->fresh()->value['value'])->toBe(now()->utc()->toDateString())
        ->and($drawn->value)->toHaveKeys(['kind', 'path', 'sha256'])
        ->and(Storage::exists($drawn->value['path']))->toBeTrue()
        ->and($envelope->status)->toBe(EnvelopeStatus::Sent)
        ->and(resolve(AuditChainVerifier::class)->verify($envelope)->valid)->toBeTrue();

    $types = $envelope->auditEvents()->pluck('type');
    expect($types->filter(fn ($type) => $type === AuditEventType::FieldCompleted))->toHaveCount(5)
        ->and($types->filter(fn ($type) => $type === AuditEventType::Signed))->toHaveCount(1);
    $signerEvents = $envelope->auditEvents()
        ->whereIn('type', [AuditEventType::Consented, AuditEventType::FieldCompleted, AuditEventType::Signed])
        ->get();
    expect($signerEvents->pluck('actor_id')->unique()->all())->toBe([(string) $signer->getKey()])
        ->and($signerEvents->pluck('ip_address')->unique()->all())->toBe(['127.0.0.1'])
        ->and($signerEvents->pluck('user_agent')->unique()->all())->toBe(['Witness signer test']);
    Event::assertDispatched(EnvelopeReadyForCompletion::class, fn ($event) => $event->envelopeId === $envelope->getKey());

    $terminal = $this->get(route('sign.open', $token))->assertOk()->getContent();
    $invalid = $this->get(route('sign.open', str_repeat('x', 64)))->assertOk()->getContent();
    expect($terminal)->toBe($invalid)->not->toContain($token);
});

it('provisions and sends each signer link only once across duplicate send attempts', function (): void {
    [$envelope, $signer] = createSigningEnvelope([FieldType::Signature]);
    $tokenHash = $signer->link_token_hash;

    expect(fn () => resolve(SendEnvelope::class)->handle(
        $envelope->team,
        $envelope->sender,
        $envelope->getKey(),
    ))->toThrow(DomainException::class, 'draft envelope');

    expect($signer->fresh()->link_token_hash)->toBe($tokenHash)
        ->and($envelope->auditEvents()->where('type', AuditEventType::Sent)->count())->toBe(1)
        ->and($envelope->auditEvents()->where('type', AuditEventType::EmailSent)->count())->toBe(1);
    Mail::assertSent(SigningInvitation::class, 1);
});

it('locks an OTP after five failures without flashing or retaining rejected codes', function (): void {
    [, $signer, $token] = createSigningEnvelope([FieldType::Signature]);
    $this->get(route('sign.open', $token))->assertRedirect(route('sign.show'));
    $issuedCode = null;
    Mail::assertSent(SigningCode::class, function (SigningCode $mail) use (&$issuedCode): bool {
        $issuedCode = $mail->code;

        return true;
    });

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $response = $this->from(route('sign.show'))->post(route('sign.verify'), ['code' => '123456']);
        $response->assertRedirect(route('sign.show'))->assertSessionHasErrors('code');
        expect(session('_old_input.code'))->toBeNull();
    }

    $signer->refresh();
    expect($signer->otp_attempts)->toBe(5)
        ->and($signer->otp_locked_at)->not->toBeNull()
        ->and(Hash::check($issuedCode, $signer->otp_hash))->toBeTrue()
        ->and($signer->envelope->auditEvents()->where('type', AuditEventType::CodeFailed)->count())->toBe(5);

    $this->post(route('sign.verify'), ['code' => $issuedCode])
        ->assertOk()
        ->assertSee('data-testid="signer-neutral"', false)
        ->assertDontSee($issuedCode);
});

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Exceptions\SigningAccessDenied;
use App\Mail\SigningCode;
use App\Models\Envelope;
use App\Models\Signer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class OpenSigningLink
{
    /** @return array{signer: Signer, token_hash: string} */
    public function handle(#[\SensitiveParameter] string $token, ?string $ipAddress, ?string $userAgent): array
    {
        $tokenHash = hash('sha256', $token);
        $candidate = Signer::query()->where('link_token_hash', $tokenHash)->first();

        if ($candidate === null) {
            throw new SigningAccessDenied;
        }

        return DB::transaction(function () use ($candidate, $tokenHash, $ipAddress, $userAgent): array {
            $envelope = Envelope::query()->with('sender')->lockForUpdate()->findOrFail($candidate->envelope_id);
            $signer = Signer::query()->lockForUpdate()->findOrFail($candidate->getKey());

            if ($envelope->status !== EnvelopeStatus::Sent
                || ($envelope->expires_at !== null && $envelope->expires_at->isPast())
                || $signer->link_token_hash === null
                || ! hash_equals($signer->link_token_hash, $tokenHash)
                || in_array($signer->status, [SignerStatus::Signed, SignerStatus::Declined], true)) {
                throw new SigningAccessDenied;
            }

            if ($signer->viewed_at === null) {
                DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
                Signer::query()->whereKey($signer)->update([
                    'status' => SignerStatus::Viewed,
                    'viewed_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::statement("SET LOCAL witness.signer_progress_write = 'off'");
                EnvelopeLifecycleRecorded::record(
                    $envelope,
                    AuditEventType::Opened,
                    AuditActorType::Signer,
                    $signer->getKey(),
                    $ipAddress,
                    $userAgent,
                );
                $signer->refresh();
            }

            if ($signer->otp_hash === null || $signer->otp_expires_at === null || $signer->otp_expires_at->isPast()) {
                $this->issueCode($envelope, $signer, $ipAddress, $userAgent);
            }

            return ['signer' => $signer->refresh(), 'token_hash' => $tokenHash];
        });
    }

    private function issueCode(Envelope $envelope, Signer $signer, ?string $ipAddress, ?string $userAgent): void
    {
        $signerKey = 'sign-code-signer:'.$signer->getKey();
        $ipKey = 'sign-code-ip:'.hash('sha256', $ipAddress ?? 'unknown');

        if (RateLimiter::tooManyAttempts($signerKey, 3) || RateLimiter::tooManyAttempts($ipKey, 10)) {
            throw ValidationException::withMessages(['code' => 'Please wait before requesting another code.']);
        }

        RateLimiter::hit($signerKey, 600);
        RateLimiter::hit($ipKey, 600);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
        Signer::query()->whereKey($signer)->update([
            'otp_hash' => Hash::make($code),
            'otp_sent_at' => now(),
            'otp_expires_at' => now()->addMinutes(10),
            'otp_attempts' => 0,
            'otp_locked_at' => null,
            'updated_at' => now(),
        ]);
        DB::statement("SET LOCAL witness.signer_progress_write = 'off'");

        Mail::to($signer->email)->send(new SigningCode($envelope, $signer, $code));
        EnvelopeLifecycleRecorded::record(
            $envelope,
            AuditEventType::CodeSent,
            AuditActorType::Signer,
            $signer->getKey(),
            $ipAddress,
            $userAgent,
        );
    }
}

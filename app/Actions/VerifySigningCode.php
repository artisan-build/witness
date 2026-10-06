<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Exceptions\SigningAccessDenied;
use App\Models\Envelope;
use App\Models\Signer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class VerifySigningCode
{
    public function handle(Signer $boundSigner, #[\SensitiveParameter] string $code, ?string $ipAddress, ?string $userAgent): Signer
    {
        $result = DB::transaction(function () use ($boundSigner, $code, $ipAddress, $userAgent): array {
            $envelope = Envelope::query()->lockForUpdate()->findOrFail($boundSigner->envelope_id);
            $signer = Signer::query()->lockForUpdate()->findOrFail($boundSigner->getKey());

            if ($envelope->status !== EnvelopeStatus::Sent
                || $signer->link_token_hash === null
                || $signer->otp_hash === null
                || $signer->otp_expires_at === null
                || $signer->otp_expires_at->isPast()
                || $signer->otp_locked_at !== null) {
                throw new SigningAccessDenied;
            }

            $signerKey = 'sign-verify-signer:'.$signer->getKey();
            $ipKey = 'sign-verify-ip:'.hash('sha256', $ipAddress ?? 'unknown');

            if (RateLimiter::tooManyAttempts($signerKey, 10) || RateLimiter::tooManyAttempts($ipKey, 30)) {
                throw ValidationException::withMessages(['code' => 'Too many verification attempts. Please wait.']);
            }

            RateLimiter::hit($signerKey, 600);
            RateLimiter::hit($ipKey, 600);

            if (! Hash::check($code, $signer->otp_hash)) {
                $attempts = $signer->otp_attempts + 1;
                DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
                Signer::query()->whereKey($signer)->update([
                    'otp_attempts' => $attempts,
                    'otp_locked_at' => $attempts >= 5 ? now() : null,
                    'updated_at' => now(),
                ]);
                DB::statement("SET LOCAL witness.signer_progress_write = 'off'");
                EnvelopeLifecycleRecorded::record(
                    $envelope,
                    AuditEventType::CodeFailed,
                    AuditActorType::Signer,
                    $signer->getKey(),
                    $ipAddress,
                    $userAgent,
                    ['attempt' => $attempts],
                );

                return [
                    'signer' => $signer->refresh(),
                    'error' => $attempts >= 5
                        ? 'This code is locked. Open the link again after the code expires.'
                        : 'The verification code is not valid.',
                ];
            }

            DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
            Signer::query()->whereKey($signer)->update([
                'otp_hash' => null,
                'otp_expires_at' => null,
                'otp_attempts' => 0,
                'otp_locked_at' => null,
                'otp_verified_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement("SET LOCAL witness.signer_progress_write = 'off'");
            EnvelopeLifecycleRecorded::record(
                $envelope,
                AuditEventType::CodeVerified,
                AuditActorType::Signer,
                $signer->getKey(),
                $ipAddress,
                $userAgent,
            );

            return ['signer' => $signer->refresh(), 'error' => null];
        });

        if ($result['error'] !== null) {
            throw ValidationException::withMessages(['code' => $result['error']]);
        }

        return $result['signer'];
    }
}

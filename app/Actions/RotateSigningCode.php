<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Exceptions\SigningAccessDenied;
use App\Mail\SigningCode;
use App\Models\Envelope;
use App\Models\MailDelivery;
use App\Models\Signer;
use App\Services\OutboundMailDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class RotateSigningCode
{
    public function __construct(private OutboundMailDelivery $mailDelivery) {}

    public function handle(Signer $boundSigner, ?string $ipAddress, ?string $userAgent): bool
    {
        [$envelope, $signer, $code, $delivery] = DB::transaction(function () use ($boundSigner, $ipAddress): array {
            $envelope = Envelope::query()->with('sender')->lockForUpdate()->findOrFail($boundSigner->envelope_id);
            $signer = Signer::query()->lockForUpdate()->findOrFail($boundSigner->getKey());
            $latest = MailDelivery::query()
                ->where('signer_id', $signer->getKey())
                ->where('purpose', MailDeliveryPurpose::SigningCode->value)
                ->latest('generation')
                ->lockForUpdate()
                ->first();
            if ($latest !== null) {
                $latest = $this->mailDelivery->markStaleClaimUnknown($latest);
            }

            if ($envelope->status !== EnvelopeStatus::Sent || $envelope->expires_at?->isPast()
                || $signer->status !== SignerStatus::Viewed) {
                throw new SigningAccessDenied;
            }
            if ($latest?->status !== MailDeliveryStatus::Unknown) {
                throw ValidationException::withMessages(['code' => 'Code delivery is still in progress.']);
            }

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
            $signer->refresh();

            return [
                $envelope,
                $signer,
                $code,
                $this->mailDelivery->create($envelope, $signer, MailDeliveryPurpose::SigningCode),
            ];
        });

        return $this->mailDelivery->deliver(
            $delivery,
            fn () => Mail::to($signer->email)->send(new SigningCode($envelope, $signer, $code)),
            AuditEventType::CodeSent,
            AuditActorType::Signer,
            $signer->getKey(),
            $ipAddress,
            $userAgent,
        );
    }
}

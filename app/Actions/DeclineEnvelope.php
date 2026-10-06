<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Exceptions\SigningAccessDenied;
use App\Mail\SigningDeclined;
use App\Models\Envelope;
use App\Models\Signer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class DeclineEnvelope
{
    public function handle(
        Signer $boundSigner,
        #[\SensitiveParameter] string $reason,
        ?string $ipAddress,
        ?string $userAgent,
    ): Signer {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000 || strip_tags($reason) !== $reason) {
            throw ValidationException::withMessages(['reason' => 'Enter a plain-text reason of 2,000 characters or fewer.']);
        }

        $notify = false;
        $signer = DB::transaction(function () use ($boundSigner, $reason, $ipAddress, $userAgent, &$notify): Signer {
            $envelope = Envelope::query()->with('sender')->lockForUpdate()->findOrFail($boundSigner->envelope_id);
            $signer = Signer::query()->lockForUpdate()->findOrFail($boundSigner->getKey());

            if ($envelope->status === EnvelopeStatus::Declined && $signer->status === SignerStatus::Declined) {
                return $signer;
            }

            if ($envelope->status !== EnvelopeStatus::Sent
                || $signer->status !== SignerStatus::Viewed
                || $signer->consented_at === null) {
                throw new SigningAccessDenied;
            }

            EnvelopeLifecycleRecorded::record(
                $envelope,
                AuditEventType::Declined,
                AuditActorType::Signer,
                $signer->getKey(),
                $ipAddress,
                $userAgent,
            );

            DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
            Signer::query()->whereKey($signer)->update([
                'status' => SignerStatus::Declined,
                'declined_at' => now(),
                'decline_reason' => Crypt::encryptString($reason),
                'updated_at' => now(),
            ]);
            DB::statement("SET LOCAL witness.signer_progress_write = 'off'");
            DB::statement("SET LOCAL witness.envelope_transition = 'on'");
            Envelope::query()->whereKey($envelope)->update([
                'status' => EnvelopeStatus::Declined,
                'updated_at' => now(),
            ]);
            DB::statement("SET LOCAL witness.envelope_transition = 'off'");
            $notify = true;

            return $signer->refresh()->load('envelope.sender');
        });

        if ($notify) {
            Mail::to($signer->envelope->sender->email)->send(new SigningDeclined(
                $signer->envelope,
                $signer,
                $reason,
            ));
        }

        return $signer;
    }
}

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

final class RecordSigningConsent
{
    public function handle(Signer $boundSigner, ?string $ipAddress, ?string $userAgent): Signer
    {
        return DB::transaction(function () use ($boundSigner, $ipAddress, $userAgent): Signer {
            $envelope = Envelope::query()->lockForUpdate()->findOrFail($boundSigner->envelope_id);
            $signer = Signer::query()->lockForUpdate()->findOrFail($boundSigner->getKey());

            if ($envelope->status !== EnvelopeStatus::Sent
                || $signer->otp_verified_at === null
                || $signer->link_token_hash === null) {
                throw new SigningAccessDenied;
            }

            if ($signer->consented_at !== null) {
                return $signer;
            }

            DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
            Signer::query()->whereKey($signer)->update([
                'consented_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement("SET LOCAL witness.signer_progress_write = 'off'");
            EnvelopeLifecycleRecorded::record(
                $envelope,
                AuditEventType::Consented,
                AuditActorType::Signer,
                $signer->getKey(),
                $ipAddress,
                $userAgent,
                ['disclosure_version' => 'esign-v1'],
            );

            return $signer->refresh();
        });
    }
}

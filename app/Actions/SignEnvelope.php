<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\SignerStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Events\EnvelopeReadyForCompletion;
use App\Exceptions\SigningAccessDenied;
use App\Models\Envelope;
use App\Models\Field;
use App\Models\Signer;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SignEnvelope
{
    public function handle(Signer $boundSigner, ?string $ipAddress, ?string $userAgent): Signer
    {
        $lastSigner = false;

        $signer = DB::transaction(function () use ($boundSigner, $ipAddress, $userAgent, &$lastSigner): Signer {
            $envelope = Envelope::query()->lockForUpdate()->findOrFail($boundSigner->envelope_id);
            $signer = Signer::query()->lockForUpdate()->findOrFail($boundSigner->getKey());
            $fields = Field::query()
                ->where('envelope_id', $envelope->getKey())
                ->where('signer_id', $signer->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($signer->status === SignerStatus::Signed) {
                return $signer;
            }

            if ($envelope->status !== EnvelopeStatus::Sent
                || $signer->consented_at === null
                || $signer->otp_verified_at === null
                || $signer->status === SignerStatus::Declined) {
                throw new SigningAccessDenied;
            }

            $incomplete = $fields->where('required', true)->contains(function (Field $field): bool {
                if ($field->completed_at === null || $field->value === null) {
                    return true;
                }

                return $field->type === FieldType::Checkbox && ($field->value['checked'] ?? false) !== true;
            });

            if ($incomplete) {
                throw new DomainException('Complete every required field before signing.');
            }

            DB::statement("SET LOCAL witness.signer_progress_write = 'on'");
            Signer::query()->whereKey($signer)->update([
                'status' => SignerStatus::Signed,
                'signed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::statement("SET LOCAL witness.signer_progress_write = 'off'");
            EnvelopeLifecycleRecorded::record(
                $envelope,
                AuditEventType::Signed,
                AuditActorType::Signer,
                $signer->getKey(),
                $ipAddress,
                $userAgent,
            );

            $lastSigner = ! Signer::query()
                ->where('envelope_id', $envelope->getKey())
                ->where('status', '!=', SignerStatus::Signed->value)
                ->exists();

            return $signer->refresh();
        });

        if ($lastSigner) {
            event(new EnvelopeReadyForCompletion((int) $signer->envelope_id));
        }

        return $signer;
    }
}

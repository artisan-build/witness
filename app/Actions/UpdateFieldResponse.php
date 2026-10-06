<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Models\Envelope;
use App\Models\Field;
use App\Models\Signer;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final class UpdateFieldResponse
{
    /** @param array<string, mixed>|null $value */
    public function handle(
        Envelope $envelope,
        Signer $signer,
        Field $field,
        ?array $value,
        ?CarbonInterface $completedAt,
    ): Field {
        return DB::transaction(function () use ($envelope, $signer, $field, $value, $completedAt): Field {
            $lockedEnvelope = Envelope::query()->lockForUpdate()->findOrFail($envelope->getKey());
            $lockedSigner = Signer::query()
                ->where('envelope_id', $lockedEnvelope->getKey())
                ->lockForUpdate()
                ->findOrFail($signer->getKey());
            $lockedField = Field::query()
                ->where('envelope_id', $lockedEnvelope->getKey())
                ->where('signer_id', $lockedSigner->getKey())
                ->lockForUpdate()
                ->findOrFail($field->getKey());

            if ($lockedEnvelope->status !== EnvelopeStatus::Sent
                || $lockedSigner->status === SignerStatus::Signed
                || $lockedSigner->status === SignerStatus::Declined
                || (int) $lockedSigner->envelope_id !== (int) $lockedEnvelope->getKey()
                || (int) $lockedField->envelope_id !== (int) $lockedEnvelope->getKey()) {
                throw new DomainException('A field response must target its signer on a sent envelope.');
            }

            DB::statement("SET LOCAL witness.field_response_write = 'on'");
            $lockedField->value = $value;
            $lockedField->completed_at = $completedAt?->toImmutable();
            $lockedField->save();
            DB::statement("SET LOCAL witness.field_response_write = 'off'");

            return $lockedField->refresh();
        });
    }
}

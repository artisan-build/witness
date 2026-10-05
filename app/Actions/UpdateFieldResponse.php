<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnvelopeStatus;
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

            if ($lockedEnvelope->status !== EnvelopeStatus::Sent
                || (int) $signer->envelope_id !== (int) $lockedEnvelope->getKey()
                || (int) $field->envelope_id !== (int) $lockedEnvelope->getKey()
                || (int) $field->signer_id !== (int) $signer->getKey()) {
                throw new DomainException('A field response must target its signer on a sent envelope.');
            }

            DB::statement("SET LOCAL witness.field_response_write = 'on'");
            $field->value = $value;
            $field->completed_at = $completedAt?->toImmutable();
            $field->save();
            DB::statement("SET LOCAL witness.field_response_write = 'off'");

            return $field->refresh();
        });
    }
}

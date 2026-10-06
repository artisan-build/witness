<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Models\Envelope;
use App\Models\MailDelivery;
use App\Models\Signer;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OutboundMailDelivery
{
    public function create(Envelope $envelope, Signer $signer, MailDeliveryPurpose $purpose): MailDelivery
    {
        $generation = ((int) MailDelivery::query()
            ->where('signer_id', $signer->getKey())
            ->where('purpose', $purpose->value)
            ->max('generation')) + 1;

        $delivery = new MailDelivery([
            'purpose' => $purpose,
            'generation' => $generation,
            'status' => MailDeliveryStatus::Pending,
        ]);
        $delivery->envelope_id = $envelope->getKey();
        $delivery->signer_id = $signer->getKey();
        $delivery->save();

        return $delivery;
    }

    /**
     * @param  Closure(): void  $handoff
     * @param  array<string, mixed>  $payload
     */
    public function deliver(
        MailDelivery $delivery,
        #[\SensitiveParameter] Closure $handoff,
        ?AuditEventType $eventType,
        AuditActorType $actorType,
        int|string|null $actorId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $payload = [],
    ): bool {
        $claimed = DB::transaction(function () use ($delivery): bool {
            Envelope::query()->lockForUpdate()->findOrFail($delivery->envelope_id);
            Signer::query()->lockForUpdate()->findOrFail($delivery->signer_id);
            $locked = MailDelivery::query()->lockForUpdate()->findOrFail($delivery->getKey());

            if ($locked->status !== MailDeliveryStatus::Pending) {
                return false;
            }

            $locked->update([
                'status' => MailDeliveryStatus::Sending,
                'attempted_at' => now(),
            ]);

            return true;
        });

        if (! $claimed) {
            return false;
        }

        try {
            $handoff();

            DB::transaction(function () use (
                $delivery,
                $eventType,
                $actorType,
                $actorId,
                $ipAddress,
                $userAgent,
                $payload,
            ): void {
                $envelope = Envelope::query()->lockForUpdate()->findOrFail($delivery->envelope_id);
                Signer::query()->lockForUpdate()->findOrFail($delivery->signer_id);
                $locked = MailDelivery::query()->lockForUpdate()->findOrFail($delivery->getKey());

                if ($locked->status !== MailDeliveryStatus::Sending) {
                    return;
                }

                $locked->update([
                    'status' => MailDeliveryStatus::Sent,
                    'sent_at' => now(),
                ]);
                if ($eventType !== null) {
                    EnvelopeLifecycleRecorded::record(
                        $envelope,
                        $eventType,
                        $actorType,
                        $actorId,
                        $ipAddress,
                        $userAgent,
                        array_merge($payload, [
                            'delivery_id' => $locked->getKey(),
                            'purpose' => $locked->purpose->value,
                            'generation' => $locked->generation,
                        ]),
                    );
                }
            });

            return true;
        } catch (Throwable) {
            DB::transaction(function () use ($delivery): void {
                Envelope::query()->lockForUpdate()->findOrFail($delivery->envelope_id);
                Signer::query()->lockForUpdate()->findOrFail($delivery->signer_id);
                MailDelivery::query()
                    ->whereKey($delivery)
                    ->where('status', MailDeliveryStatus::Sending->value)
                    ->update([
                        'status' => MailDeliveryStatus::Unknown,
                        'unknown_at' => now(),
                        'updated_at' => now(),
                    ]);
            });

            return false;
        }
    }
}

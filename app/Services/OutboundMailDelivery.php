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
use ArtisanBuild\BuiltForCloud\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OutboundMailDelivery
{
    private const int STALE_CLAIM_MINUTES = 10;

    public function create(Envelope $envelope, Signer $signer, MailDeliveryPurpose $purpose): MailDelivery
    {
        $generation = ((int) MailDelivery::query()
            ->where('signer_id', $signer->getKey())
            ->where('purpose', $purpose->value)
            ->max('generation')) + 1;

        $delivery = new MailDelivery([
            'purpose' => $purpose,
            'recipient_kind' => 'signer',
            'recipient_name' => $signer->name,
            'recipient_email' => $signer->email,
            'generation' => $generation,
            'status' => MailDeliveryStatus::Pending,
        ]);
        $delivery->envelope_id = $envelope->getKey();
        $delivery->signer_id = $signer->getKey();
        $delivery->save();

        return $delivery;
    }

    public function firstOrCreateFinal(Envelope $envelope, Signer|User $recipient): MailDelivery
    {
        $isSigner = $recipient instanceof Signer;

        $attributes = [
            'envelope_id' => $envelope->getKey(),
            'purpose' => MailDeliveryPurpose::FinalDocument->value,
            'recipient_kind' => $isSigner ? 'signer' : 'sender',
            'recipient_email' => $recipient->email,
            'generation' => 1,
        ];
        $delivery = MailDelivery::query()->where($attributes)->first();

        if ($delivery !== null) {
            return $delivery;
        }

        $delivery = new MailDelivery(array_merge($attributes, [
            'recipient_name' => $recipient->name,
            'status' => MailDeliveryStatus::Pending,
        ]));
        $delivery->envelope_id = $envelope->getKey();
        $delivery->signer_id = $isSigner ? $recipient->getKey() : null;
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
            $this->lockSigner($delivery);
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

            $finalized = DB::transaction(function () use (
                $delivery,
                $eventType,
                $actorType,
                $actorId,
                $ipAddress,
                $userAgent,
                $payload,
            ): bool {
                $envelope = Envelope::query()->lockForUpdate()->findOrFail($delivery->envelope_id);
                $this->lockSigner($delivery);
                $locked = MailDelivery::query()->lockForUpdate()->findOrFail($delivery->getKey());

                if ($locked->status !== MailDeliveryStatus::Sending) {
                    return false;
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

                return true;
            });

            return $finalized;
        } catch (Throwable) {
            DB::transaction(function () use ($delivery): void {
                Envelope::query()->lockForUpdate()->findOrFail($delivery->envelope_id);
                $this->lockSigner($delivery);
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

    public function reconcileStaleClaims(): void
    {
        $cutoff = now()->subMinutes(self::STALE_CLAIM_MINUTES);
        $deliveryIds = MailDelivery::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($query) use ($cutoff): void {
                    $query->where('status', MailDeliveryStatus::Pending->value)
                        ->where('created_at', '<=', $cutoff);
                })->orWhere(function ($query) use ($cutoff): void {
                    $query->where('status', MailDeliveryStatus::Sending->value)
                        ->where('attempted_at', '<=', $cutoff);
                });
            })
            ->orderBy('id')
            ->pluck('id');

        foreach ($deliveryIds as $deliveryId) {
            DB::transaction(function () use ($deliveryId): void {
                $candidate = MailDelivery::query()->find($deliveryId);
                if ($candidate === null) {
                    return;
                }

                Envelope::query()->lockForUpdate()->findOrFail($candidate->envelope_id);
                $this->lockSigner($candidate);
                $delivery = MailDelivery::query()->lockForUpdate()->findOrFail($deliveryId);
                $this->markStaleClaimUnknown($delivery);
            });
        }
    }

    /** The caller must already hold the envelope, signer, and delivery row locks. */
    public function markStaleClaimUnknown(MailDelivery $delivery): MailDelivery
    {
        $cutoff = now()->subMinutes(self::STALE_CLAIM_MINUTES);
        $stalePending = $delivery->status === MailDeliveryStatus::Pending
            && $delivery->purpose !== MailDeliveryPurpose::FinalDocument
            && $delivery->created_at->lte($cutoff);
        $staleSending = $delivery->status === MailDeliveryStatus::Sending
            && $delivery->attempted_at?->lte($cutoff);

        if ($stalePending || $staleSending) {
            $delivery->update([
                'status' => MailDeliveryStatus::Unknown,
                'unknown_at' => now(),
            ]);
        }

        return $delivery;
    }

    private function lockSigner(MailDelivery $delivery): void
    {
        if ($delivery->signer_id !== null) {
            Signer::query()->lockForUpdate()->findOrFail($delivery->signer_id);
        }
    }
}

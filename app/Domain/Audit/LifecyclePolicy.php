<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\Envelope;
use App\Models\Signer;
use DomainException;

final class LifecyclePolicy
{
    public function assertRecordable(
        Envelope $envelope,
        AuditEventType $type,
        AuditActorType $actorType,
        ?string $actorId,
    ): void {
        $this->assertSupported($type);
        $this->assertActor($envelope, $actorType, $actorId);

        if ($actorType !== AuditActorType::Sender) {
            throw new DomainException("The {$type->value} event requires the envelope sender.");
        }

        $envelope->refresh();

        if ($envelope->status !== EnvelopeStatus::Sent) {
            throw new DomainException('PR1 lifecycle events are recorded only while sending a frozen envelope.');
        }

        $expectedCount = $type === AuditEventType::Created ? 0 : 1;

        if ($envelope->audit_event_count !== $expectedCount) {
            throw new DomainException("The {$type->value} event is not valid at this audit position.");
        }
    }

    public function assertSupported(AuditEventType $type): void
    {
        if (! in_array($type, [AuditEventType::Created, AuditEventType::Sent], true)) {
            throw new DomainException("The {$type->value} lifecycle event is not implemented in PR1.");
        }
    }

    public function assertActor(Envelope $envelope, AuditActorType $actorType, ?string $actorId): void
    {
        match ($actorType) {
            AuditActorType::Sender => $this->assertSender($envelope, $actorId),
            AuditActorType::Signer => $this->assertSigner($envelope, $actorId),
            AuditActorType::System => $this->assertSystem($actorId),
        };
    }

    private function assertSender(Envelope $envelope, ?string $actorId): void
    {
        if ($actorId === null || ! hash_equals((string) $envelope->sender_id, $actorId)) {
            throw new DomainException('The sender audit actor must own the envelope.');
        }
    }

    private function assertSigner(Envelope $envelope, ?string $actorId): void
    {
        if ($actorId === null || ! Signer::query()
            ->whereKey($actorId)
            ->where('envelope_id', $envelope->getKey())
            ->exists()) {
            throw new DomainException('The signer audit actor must belong to the envelope.');
        }
    }

    private function assertSystem(?string $actorId): void
    {
        if ($actorId !== null) {
            throw new DomainException('System audit actors cannot have an actor id.');
        }
    }
}

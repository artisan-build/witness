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

        $envelope->refresh();

        if (in_array($type, [AuditEventType::Created, AuditEventType::Sent], true)) {
            if ($actorType !== AuditActorType::Sender) {
                throw new DomainException("The {$type->value} event requires the envelope sender.");
            }

            if ($envelope->status !== EnvelopeStatus::Sent) {
                throw new DomainException('Send lifecycle events require a frozen envelope.');
            }

            $expectedCount = $type === AuditEventType::Created ? 0 : 1;

            if ($envelope->audit_event_count !== $expectedCount) {
                throw new DomainException("The {$type->value} event is not valid at this audit position.");
            }

            return;
        }

        if ($envelope->status !== EnvelopeStatus::Sent) {
            throw new DomainException('Signing lifecycle events require an active sent envelope.');
        }

        $signerEvents = [
            AuditEventType::Opened,
            AuditEventType::CodeSent,
            AuditEventType::CodeVerified,
            AuditEventType::CodeFailed,
            AuditEventType::Consented,
            AuditEventType::FieldCompleted,
            AuditEventType::Signed,
            AuditEventType::Declined,
        ];

        if (in_array($type, $signerEvents, true) && $actorType !== AuditActorType::Signer) {
            throw new DomainException("The {$type->value} event requires an envelope signer.");
        }

        if ($type === AuditEventType::EmailSent && $actorType !== AuditActorType::System) {
            throw new DomainException('The email_sent event requires the system actor.');
        }
    }

    public function assertSupported(AuditEventType $type): void
    {
        if (! in_array($type, [
            AuditEventType::Created,
            AuditEventType::Sent,
            AuditEventType::EmailSent,
            AuditEventType::Opened,
            AuditEventType::CodeSent,
            AuditEventType::CodeVerified,
            AuditEventType::CodeFailed,
            AuditEventType::Consented,
            AuditEventType::FieldCompleted,
            AuditEventType::Signed,
            AuditEventType::Declined,
        ], true)) {
            throw new DomainException("The {$type->value} lifecycle event is not implemented yet.");
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

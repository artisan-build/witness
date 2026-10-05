<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Events\EnvelopeLifecycleRecorded;
use App\Models\AuditEvent;
use App\Models\Envelope;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class AuditChain
{
    public function __construct(
        private AuditCanonicalizer $canonicalizer,
        private AuditHasher $hasher,
    ) {}

    public function append(EnvelopeLifecycleRecorded $event): AuditEvent
    {
        return DB::transaction(function () use ($event): AuditEvent {
            $existing = AuditEvent::query()->where('verb_event_id', $event->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $envelope = Envelope::query()
                ->with('document')
                ->lockForUpdate()
                ->findOrFail($event->envelope_id);

            $document = $envelope->document;

            if ((int) $envelope->team_id !== $event->team_id || $document === null) {
                throw new DomainException('The lifecycle event does not match its envelope.');
            }

            if (! $this->hasher->matches($document->original_sha256, $event->original_document_sha256)) {
                throw new DomainException('The lifecycle event document hash does not match its envelope.');
            }

            $previous = AuditEvent::query()
                ->where('envelope_id', $envelope->getKey())
                ->orderByDesc('sequence')
                ->first();

            $sequence = ($previous->sequence ?? 0) + 1;
            $previousHash = $previous->hash
                ?? $this->hasher->genesis((int) $envelope->getKey(), $document->original_sha256);
            $occurredAt = CarbonImmutable::parse($event->occurred_at)->utc();
            $canonical = $this->canonicalizer->encode(
                envelopeId: (int) $envelope->getKey(),
                verbEventId: $event->id,
                sequence: $sequence,
                type: $event->type,
                occurredAt: $occurredAt,
                actorType: $event->actor_type,
                actorId: $event->actor_id,
                ipAddress: $event->ip_address,
                userAgent: $event->user_agent,
                payload: $event->payload,
            );

            $auditEvent = new AuditEvent;
            $auditEvent->envelope_id = $envelope->getKey();
            $auditEvent->verb_event_id = $event->id;
            $auditEvent->sequence = $sequence;
            $auditEvent->type = $event->type;
            $auditEvent->occurred_at = $occurredAt;
            $auditEvent->actor_type = $event->actor_type;
            $auditEvent->actor_id = $event->actor_id;
            $auditEvent->ip_address = $event->ip_address;
            $auditEvent->user_agent = $event->user_agent;
            $auditEvent->payload = $event->payload;
            $auditEvent->canonical_event = $canonical;
            $auditEvent->prev_hash = $previousHash;
            $auditEvent->hash = $this->hasher->eventHash($previousHash, $canonical);
            $auditEvent->created_at = now();
            $auditEvent->save();

            return $auditEvent;
        });
    }
}

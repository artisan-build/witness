<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use App\Models\Envelope;
use DomainException;

/**
 * Detects accidental or unauthorized changes that do not include recomputing the chain.
 * A database controller can rewrite both the events and hashes; the delivered final PDF is the external anchor.
 */
final readonly class AuditChainVerifier
{
    public function __construct(
        private AuditCanonicalizer $canonicalizer,
        private AuditHasher $hasher,
    ) {}

    public function verify(Envelope $envelope): AuditVerificationResult
    {
        $document = $envelope->document()->first();

        if ($document === null) {
            throw new DomainException('An envelope must have an original document before its audit chain can be verified.');
        }

        $previousHash = $this->hasher->genesis((int) $envelope->getKey(), $document->original_sha256);
        $expectedSequence = 1;

        /** @var AuditEvent $event */
        foreach ($envelope->auditEvents()->get() as $event) {
            if ($event->sequence !== $expectedSequence) {
                return AuditVerificationResult::invalid(
                    $expectedSequence,
                    (int) $event->getKey(),
                    "Expected sequence {$expectedSequence}, found {$event->sequence}.",
                );
            }

            $canonical = $this->canonicalizer->encode(
                envelopeId: (int) $envelope->getKey(),
                verbEventId: $event->verb_event_id,
                sequence: $event->sequence,
                type: $event->type,
                occurredAt: $event->occurred_at,
                actorType: $event->actor_type,
                actorId: $event->actor_id,
                ipAddress: $event->ip_address,
                userAgent: $event->user_agent,
                payload: $event->payload,
            );

            if ($canonical !== $event->canonical_event) {
                return AuditVerificationResult::invalid(
                    $event->sequence,
                    (int) $event->getKey(),
                    'Stored canonical event data does not match the event columns.',
                );
            }

            if (! $this->hasher->matches($event->prev_hash, $previousHash)) {
                return AuditVerificationResult::invalid(
                    $event->sequence,
                    (int) $event->getKey(),
                    'The predecessor hash does not match the preceding chain head.',
                );
            }

            $calculatedHash = $this->hasher->eventHash($previousHash, $canonical);

            if (! $this->hasher->matches($event->hash, $calculatedHash)) {
                return AuditVerificationResult::invalid(
                    $event->sequence,
                    (int) $event->getKey(),
                    'The event hash does not match its canonical data.',
                );
            }

            $previousHash = $event->hash;
            $expectedSequence++;
        }

        return AuditVerificationResult::valid($previousHash);
    }
}

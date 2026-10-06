<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\AuditCanonicalizer;
use App\Domain\Audit\AuditHasher;
use App\Domain\Audit\LifecyclePolicy;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\States\EnvelopeState;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Thunk\Verbs\Attributes\Autodiscovery\StateId;
use Thunk\Verbs\Attributes\Hooks\Once;
use Thunk\Verbs\Event;
use Thunk\Verbs\Lifecycle\StateManager;
use Thunk\Verbs\Models\VerbEvent;
use Thunk\Verbs\Support\PendingEvent;

class EnvelopeLifecycleRecorded extends Event
{
    #[StateId(EnvelopeState::class)]
    public int $envelope_id;

    public int $team_id;

    public string $original_document_sha256;

    public AuditEventType $type;

    public string $occurred_at;

    public AuditActorType $actor_type;

    public ?string $actor_id = null;

    public ?string $ip_address = null;

    public ?string $user_agent = null;

    /** @var array<string, mixed> */
    public array $payload = [];

    /** @param array<string, mixed> $payload */
    public static function record(
        Envelope $envelope,
        AuditEventType $type,
        AuditActorType $actorType,
        int|string|null $actorId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $payload = [],
        ?CarbonImmutable $occurredAt = null,
    ): AuditEvent {
        try {
            return DB::transaction(function () use (
                $envelope,
                $type,
                $actorType,
                $actorId,
                $ipAddress,
                $userAgent,
                $payload,
                $occurredAt,
            ): AuditEvent {
                $envelope = Envelope::query()->with('document')->lockForUpdate()->findOrFail($envelope->getKey());
                $document = $envelope->document;

                if ($document === null) {
                    throw new LogicException('Lifecycle events require an original envelope document.');
                }

                $actorId = $actorId === null ? null : (string) $actorId;
                resolve(LifecyclePolicy::class)->assertRecordable($envelope, $type, $actorType, $actorId);

                $result = parent::make(
                    envelope_id: (int) $envelope->getKey(),
                    team_id: (int) $envelope->team_id,
                    original_document_sha256: $document->original_sha256,
                    type: $type,
                    occurred_at: ($occurredAt ?? now()->toImmutable())->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    actor_type: $actorType,
                    actor_id: $actorId,
                    ip_address: $ipAddress,
                    user_agent: $userAgent,
                    payload: $payload,
                )->commit();

                if (! $result instanceof AuditEvent) {
                    throw new LogicException('The lifecycle event did not append an audit event.');
                }

                return $result;
            });
        } finally {
            resolve(StateManager::class)->reset();
        }
    }

    /** @return PendingEvent<static> */
    public static function make(mixed ...$args): PendingEvent
    {
        throw new LogicException('Use EnvelopeLifecycleRecorded::record() so lifecycle persistence is atomic.');
    }

    public static function fire(mixed ...$arguments): never
    {
        throw new LogicException('Use EnvelopeLifecycleRecorded::record() so lifecycle persistence is atomic.');
    }

    public static function commit(mixed ...$arguments): never
    {
        throw new LogicException('Use EnvelopeLifecycleRecorded::record() so lifecycle persistence is atomic.');
    }

    public function validate(EnvelopeState $state): void
    {
        $envelope = Envelope::query()->findOrFail($this->envelope_id);
        resolve(LifecyclePolicy::class)->assertRecordable(
            $envelope,
            $this->type,
            $this->actor_type,
            $this->actor_id,
        );

        match ($this->type) {
            AuditEventType::Created => $this->assert(
                $state->status === null,
                'An envelope can only be created once.',
            ),
            AuditEventType::Sent => $this->assert(
                $state->status === EnvelopeStatus::Draft->value,
                'Only a draft envelope can be sent.',
            ),
            AuditEventType::EmailSent,
            AuditEventType::Opened,
            AuditEventType::CodeSent,
            AuditEventType::CodeVerified,
            AuditEventType::CodeFailed,
            AuditEventType::Consented,
            AuditEventType::FieldCompleted,
            AuditEventType::Signed,
            AuditEventType::Declined,
            AuditEventType::Completed => $this->assert(
                $state->status === EnvelopeStatus::Sent->value,
                'Signing events require an active sent envelope.',
            ),
            AuditEventType::FinalDelivered => $this->assert(
                $state->status === EnvelopeStatus::Completed->value,
                'Final delivery events require a completed envelope.',
            ),
            default => throw new LogicException('Unsupported lifecycle event reached Verbs validation.'),
        };
    }

    public function apply(EnvelopeState $state): void
    {
        if ($this->type === AuditEventType::Created) {
            $state->team_id = $this->team_id;
            $state->status = EnvelopeStatus::Draft->value;
            $state->original_document_sha256 = $this->original_document_sha256;
        }

        if ($this->type === AuditEventType::Sent) {
            $state->status = EnvelopeStatus::Sent->value;
        }

        if ($this->type === AuditEventType::Declined) {
            $state->status = EnvelopeStatus::Declined->value;
        }

        if ($this->type === AuditEventType::Completed) {
            $state->status = EnvelopeStatus::Completed->value;
        }
    }

    #[Once]
    public function handle(
        AuditCanonicalizer $canonicalizer,
        AuditHasher $hasher,
        LifecyclePolicy $policy,
    ): AuditEvent {
        return DB::transaction(function () use ($canonicalizer, $hasher, $policy): AuditEvent {
            $event = VerbEvent::query()->find($this->id)?->event();

            if (! $event instanceof self) {
                throw new DomainException('The audit projection requires its persisted lifecycle event.');
            }

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

            if (! $hasher->matches($document->original_sha256, $event->original_document_sha256)) {
                throw new DomainException('The lifecycle event document hash does not match its envelope.');
            }

            $policy->assertRecordable($envelope, $event->type, $event->actor_type, $event->actor_id);

            $previous = AuditEvent::query()
                ->where('envelope_id', $envelope->getKey())
                ->orderByDesc('sequence')
                ->first();

            $sequence = ($previous->sequence ?? 0) + 1;
            $previousHash = $previous->hash
                ?? $hasher->genesis((int) $envelope->getKey(), $document->original_sha256);

            if ($envelope->audit_event_count !== $sequence - 1) {
                throw new DomainException('The envelope audit anchor is out of sync with its chain.');
            }

            $occurredAt = CarbonImmutable::parse($event->occurred_at)->utc();
            $canonical = $canonicalizer->encode(
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
            $auditEvent->hash = $hasher->eventHash($previousHash, $canonical);
            $auditEvent->created_at = now();
            $auditEvent->save();

            DB::statement("SET LOCAL witness.audit_append = 'on'");
            DB::table('envelopes')->where('id', $envelope->getKey())->update([
                'audit_event_count' => $sequence,
                'audit_chain_head' => $auditEvent->hash,
            ]);
            DB::statement("SET LOCAL witness.audit_append = 'off'");

            return $auditEvent;
        });
    }
}

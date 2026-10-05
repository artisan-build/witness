<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Audit\AuditChain;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\States\EnvelopeState;
use Carbon\CarbonImmutable;
use LogicException;
use Thunk\Verbs\Attributes\Autodiscovery\StateId;
use Thunk\Verbs\Event;

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
        $document = $envelope->document()->first();

        if ($document === null) {
            throw new LogicException('Lifecycle events require an original envelope document.');
        }

        $result = static::commit(
            envelope_id: (int) $envelope->getKey(),
            team_id: (int) $envelope->team_id,
            original_document_sha256: $document->original_sha256,
            type: $type,
            occurred_at: ($occurredAt ?? now()->toImmutable())->utc()->format('Y-m-d\TH:i:s.u\Z'),
            actor_type: $actorType,
            actor_id: $actorId === null ? null : (string) $actorId,
            ip_address: $ipAddress,
            user_agent: $userAgent,
            payload: $payload,
        );

        if (! $result instanceof AuditEvent) {
            throw new LogicException('The lifecycle event did not append an audit event.');
        }

        return $result;
    }

    public function validate(EnvelopeState $state): void
    {
        match ($this->type) {
            AuditEventType::Created => $this->assert(
                $state->status === null,
                'An envelope can only be created once.',
            ),
            AuditEventType::Sent => $this->assert(
                $state->status === EnvelopeStatus::Draft->value,
                'Only a draft envelope can be sent.',
            ),
            default => $this->assert(
                $state->status !== null,
                'An envelope must exist before lifecycle activity can be recorded.',
            ),
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
    }

    public function handle(AuditChain $chain): AuditEvent
    {
        return $chain->append($this);
    }
}

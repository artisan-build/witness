<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\AuditAppender;
use App\Domain\Audit\LifecyclePolicy;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\States\EnvelopeState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;
use Thunk\Verbs\Attributes\Autodiscovery\StateId;
use Thunk\Verbs\Event;
use Thunk\Verbs\Lifecycle\StateManager;
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
        } catch (Throwable $throwable) {
            resolve(StateManager::class)->reset();

            throw $throwable;
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
    }

    public function handle(AuditAppender $chain): AuditEvent
    {
        return $chain->append($this);
    }
}

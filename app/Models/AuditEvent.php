<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $envelope_id
 * @property int $verb_event_id
 * @property int $sequence
 * @property AuditEventType $type
 * @property CarbonImmutable $occurred_at
 * @property AuditActorType $actor_type
 * @property string|null $actor_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed> $payload
 * @property string $canonical_event
 * @property string $prev_hash
 * @property string $hash
 * @property CarbonImmutable $created_at
 *
 * @mixin IdeHelperAuditEvent
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['*'];

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Audit events are append-only and cannot be updated.');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Audit events are append-only and cannot be updated.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Audit events are append-only and cannot be deleted.');
    }

    protected function casts(): array
    {
        return [
            'verb_event_id' => 'integer',
            'sequence' => 'integer',
            'type' => AuditEventType::class,
            'occurred_at' => 'immutable_datetime',
            'actor_type' => AuditActorType::class,
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

final readonly class AuditCanonicalizer
{
    public function __construct(private CanonicalJson $json) {}

    /** @param array<string, mixed> $payload */
    public function encode(
        int $envelopeId,
        int $verbEventId,
        int $sequence,
        AuditEventType $type,
        CarbonImmutable $occurredAt,
        AuditActorType $actorType,
        ?string $actorId,
        ?string $ipAddress,
        ?string $userAgent,
        array $payload,
    ): string {
        return $this->json->encode([
            'actor' => [
                'id' => $actorId,
                'type' => $actorType->value,
            ],
            'envelope_id' => $envelopeId,
            'ip_address' => $ipAddress,
            'occurred_at' => $occurredAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'payload' => $payload,
            'sequence' => $sequence,
            'type' => $type->value,
            'user_agent' => $userAgent,
            'verb_event_id' => $verbEventId,
        ]);
    }
}

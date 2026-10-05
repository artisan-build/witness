<?php

declare(strict_types=1);

namespace App\Domain\Audit;

final readonly class AuditVerificationResult
{
    private function __construct(
        public bool $valid,
        public ?int $failingSequence,
        public ?int $failingEventId,
        public ?string $reason,
        public ?string $chainHead,
    ) {}

    public static function valid(string $chainHead): self
    {
        return new self(true, null, null, null, $chainHead);
    }

    public static function invalid(int $sequence, ?int $eventId, string $reason): self
    {
        return new self(false, $sequence, $eventId, $reason, null);
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum EnvelopeStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case CompletionFailed = 'completion_failed';
    case Completed = 'completed';
    case Declined = 'declined';
    case Voided = 'voided';
    case Expired = 'expired';

    /** The status as a sender reads it. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Waiting for signatures',
            self::CompletionFailed => 'Completion failed',
            self::Completed => 'Completed',
            self::Declined => 'Declined',
            self::Voided => 'Voided',
            self::Expired => 'Expired',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isDeletable(): bool
    {
        return in_array($this, [self::Completed, self::Voided, self::Declined], true);
    }
}

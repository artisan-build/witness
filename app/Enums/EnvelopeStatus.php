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

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isDeletable(): bool
    {
        return in_array($this, [self::Completed, self::Voided, self::Declined], true);
    }
}

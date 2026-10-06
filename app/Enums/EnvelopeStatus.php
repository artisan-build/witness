<?php

declare(strict_types=1);

namespace App\Enums;

enum EnvelopeStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Completed = 'completed';
    case Declined = 'declined';
    case Voided = 'voided';
    case Expired = 'expired';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}

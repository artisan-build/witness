<?php

declare(strict_types=1);

namespace App\Enums;

enum SignerStatus: string
{
    case Pending = 'pending';
    case Viewed = 'viewed';
    case Signed = 'signed';
    case Declined = 'declined';
}

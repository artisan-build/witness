<?php

declare(strict_types=1);

namespace App\Enums;

enum MailDeliveryStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Unknown = 'unknown';
}

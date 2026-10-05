<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditActorType: string
{
    case Sender = 'sender';
    case Signer = 'signer';
    case System = 'system';
}

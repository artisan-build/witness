<?php

declare(strict_types=1);

namespace App\Authorization;

enum WitnessMcpAbility: string
{
    case Read = 'witness.read';
    case Destructive = 'witness.destructive';
}

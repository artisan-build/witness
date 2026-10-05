<?php

declare(strict_types=1);

namespace App\Enums;

enum FieldType: string
{
    case Signature = 'signature';
    case Initials = 'initials';
    case DateSigned = 'date_signed';
    case Text = 'text';
    case Checkbox = 'checkbox';
}

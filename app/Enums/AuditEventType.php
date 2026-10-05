<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditEventType: string
{
    case Created = 'created';
    case Sent = 'sent';
    case EmailSent = 'email_sent';
    case Opened = 'opened';
    case CodeSent = 'code_sent';
    case CodeVerified = 'code_verified';
    case CodeFailed = 'code_failed';
    case Consented = 'consented';
    case FieldCompleted = 'field_completed';
    case Signed = 'signed';
    case Declined = 'declined';
    case Reminded = 'reminded';
    case Voided = 'voided';
    case Expired = 'expired';
    case Completed = 'completed';
    case FinalDelivered = 'final_delivered';
}

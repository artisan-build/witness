<?php

declare(strict_types=1);

namespace App\Enums;

enum MailDeliveryPurpose: string
{
    case SigningInvitation = 'signing_invitation';
    case SigningCode = 'signing_code';
    case DeclineNotification = 'decline_notification';
    case FinalDocument = 'final_document';
}

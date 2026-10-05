<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Events\EnvelopeLifecycleRecorded;
use App\Models\AuditEvent;

interface AuditAppender
{
    public function append(EnvelopeLifecycleRecorded $event): AuditEvent;
}

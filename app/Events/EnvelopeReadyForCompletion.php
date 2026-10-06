<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class EnvelopeReadyForCompletion implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $envelopeId) {}
}

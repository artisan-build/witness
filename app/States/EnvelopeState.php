<?php

declare(strict_types=1);

namespace App\States;

use Thunk\Verbs\State;

class EnvelopeState extends State
{
    public ?int $team_id = null;

    public ?string $status = null;

    public ?string $original_document_sha256 = null;
}

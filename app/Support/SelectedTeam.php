<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Team;
use LogicException;

final class SelectedTeam
{
    private ?Team $team = null;

    public function set(Team $team): void
    {
        $this->team = $team;
    }

    public function current(): ?Team
    {
        return $this->team;
    }

    public function get(): Team
    {
        return $this->team ?? throw new LogicException('No sender team has been selected.');
    }
}

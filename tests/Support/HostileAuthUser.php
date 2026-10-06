<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use RuntimeException;

final class HostileAuthUser extends Authenticatable
{
    public function newQuery(): Builder
    {
        throw new RuntimeException('A public signer route resolved the human authentication provider.');
    }
}

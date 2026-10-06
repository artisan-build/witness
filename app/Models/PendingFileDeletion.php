<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin IdeHelperPendingFileDeletion
 */
class PendingFileDeletion extends Model
{
    protected $fillable = ['disk', 'path', 'attempts', 'last_attempted_at'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
        ];
    }
}

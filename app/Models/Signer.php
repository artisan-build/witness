<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignerStatus;
use Database\Factories\SignerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperSigner
 */
class Signer extends Model
{
    /** @use HasFactory<SignerFactory> */
    use HasFactory;

    protected $fillable = [
        'envelope_id',
        'name',
        'email',
        'role_label',
        'status',
        'link_token_hash',
        'otp_hash',
        'otp_expires_at',
        'otp_attempts',
        'otp_locked_at',
        'viewed_at',
        'signed_at',
    ];

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return HasMany<Field, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }

    protected function casts(): array
    {
        return [
            'status' => SignerStatus::class,
            'otp_expires_at' => 'immutable_datetime',
            'otp_attempts' => 'integer',
            'otp_locked_at' => 'immutable_datetime',
            'viewed_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
        ];
    }
}

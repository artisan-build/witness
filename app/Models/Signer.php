<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use Database\Factories\SignerFactory;
use DomainException;
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
        'name',
        'email',
        'role_label',
        'position',
        'status',
        'link_token_hash',
        'otp_hash',
        'otp_expires_at',
        'otp_attempts',
        'otp_locked_at',
        'viewed_at',
        'signed_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (Signer $signer): void {
            if ($signer->isDirty('envelope_id')) {
                throw new DomainException('Signer envelope identity is immutable.');
            }
        });
        static::deleting(function (Signer $signer): void {
            $isFrozen = Envelope::query()
                ->whereKey($signer->envelope_id)
                ->where('status', '!=', EnvelopeStatus::Draft->value)
                ->exists();

            if ($isFrozen) {
                throw new DomainException('Signers cannot be removed after send.');
            }
        });
    }

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
            'position' => 'integer',
            'otp_expires_at' => 'immutable_datetime',
            'otp_attempts' => 'integer',
            'otp_locked_at' => 'immutable_datetime',
            'viewed_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
        ];
    }
}

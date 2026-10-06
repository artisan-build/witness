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
        'otp_sent_at',
        'otp_expires_at',
        'otp_attempts',
        'otp_locked_at',
        'otp_verified_at',
        'viewed_at',
        'consented_at',
        'signed_at',
        'declined_at',
        'decline_reason',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Signer $signer) => $signer->assertDraftEnvelope('Signers can only be added to draft envelopes.'));
        static::updating(function (Signer $signer): void {
            if ($signer->isDirty('envelope_id')) {
                throw new DomainException('Signer envelope identity is immutable.');
            }

            if ($signer->isDirty(['name', 'email', 'role_label', 'position'])) {
                $signer->assertDraftEnvelope('Signer definitions cannot be changed after send.');
            }
        });
        static::deleting(fn (Signer $signer) => $signer->assertDraftEnvelope('Signers cannot be removed after send.'));
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

    private function assertDraftEnvelope(string $message): void
    {
        $isDraft = Envelope::query()
            ->whereKey($this->envelope_id)
            ->where('status', EnvelopeStatus::Draft->value)
            ->exists();

        if (! $isDraft) {
            throw new DomainException($message);
        }
    }

    protected function casts(): array
    {
        return [
            'status' => SignerStatus::class,
            'position' => 'integer',
            'otp_sent_at' => 'immutable_datetime',
            'otp_expires_at' => 'immutable_datetime',
            'otp_attempts' => 'integer',
            'otp_locked_at' => 'immutable_datetime',
            'otp_verified_at' => 'immutable_datetime',
            'viewed_at' => 'immutable_datetime',
            'consented_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'decline_reason' => 'encrypted',
        ];
    }
}

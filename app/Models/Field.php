<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use Database\Factories\FieldFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Field extends Model
{
    /** @use HasFactory<FieldFactory> */
    use HasFactory;

    protected $fillable = [
        'envelope_id',
        'signer_id',
        'page',
        'type',
        'required',
        'position_x',
        'position_y',
        'width',
        'height',
        'value',
        'completed_at',
    ];

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /** @return BelongsTo<Signer, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(Signer::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Field $field): void {
            $field->setEnvelopeFromSignerIfMissing();
            $field->assertDraftEnvelope();
            $field->assertSignerBelongsToEnvelope();
        });
        static::updating(function (Field $field): void {
            $field->assertDraftEnvelope(includeOriginal: true);
            $field->assertSignerBelongsToEnvelope();
        });
        static::deleting(fn (Field $field) => $field->assertDraftEnvelope());
    }

    private function setEnvelopeFromSignerIfMissing(): void
    {
        if ($this->envelope_id === null) {
            $this->envelope_id = Signer::query()->findOrFail($this->signer_id)->envelope_id;
        }
    }

    private function assertDraftEnvelope(bool $includeOriginal = false): void
    {
        $envelopeIds = [(int) $this->envelope_id];

        if ($includeOriginal && $this->isDirty('envelope_id')) {
            $envelopeIds[] = (int) $this->getOriginal('envelope_id');
        }

        $hasFrozenEnvelope = Envelope::query()
            ->whereKey(array_unique($envelopeIds))
            ->where('status', '!=', EnvelopeStatus::Draft->value)
            ->exists();

        if ($hasFrozenEnvelope) {
            throw new DomainException('Fields cannot be changed after an envelope leaves draft.');
        }
    }

    private function assertSignerBelongsToEnvelope(): void
    {
        $belongs = Signer::query()
            ->whereKey($this->signer_id)
            ->where('envelope_id', $this->envelope_id)
            ->exists();

        if (! $belongs) {
            throw new DomainException('A field signer must belong to the same envelope.');
        }
    }

    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'required' => 'boolean',
            'position_x' => 'decimal:10',
            'position_y' => 'decimal:10',
            'width' => 'decimal:10',
            'height' => 'decimal:10',
            'value' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}

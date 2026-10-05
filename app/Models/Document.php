<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvelopeStatus;
use Database\Factories\DocumentFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperDocument
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'envelope_id',
        'original_name',
        'storage_path',
        'mime_type',
        'size_bytes',
        'page_count',
        'original_sha256',
        'final_storage_path',
        'final_sha256',
    ];

    /** @return BelongsTo<Envelope, $this> */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    protected static function booted(): void
    {
        static::creating(fn (Document $document) => $document->assertDraftEnvelope());
        static::updating(fn (Document $document) => $document->assertDraftEnvelope(includeOriginal: true));
        static::deleting(fn (Document $document) => $document->assertDraftEnvelope());
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
            throw new DomainException('Documents cannot be changed after an envelope leaves draft.');
        }
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'page_count' => 'integer',
        ];
    }
}

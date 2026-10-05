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
        static::updating(fn (Document $document) => $document->assertUpdateAllowed());
        static::deleting(fn (Document $document) => $document->assertDraftEnvelope());
    }

    private function assertDraftEnvelope(): void
    {
        $hasFrozenEnvelope = Envelope::query()
            ->whereKey($this->envelope_id)
            ->where('status', '!=', EnvelopeStatus::Draft->value)
            ->exists();

        if ($hasFrozenEnvelope) {
            throw new DomainException('Documents cannot be changed after an envelope leaves draft.');
        }
    }

    private function assertUpdateAllowed(): void
    {
        if ($this->isDirty('envelope_id')) {
            throw new DomainException('Document envelope identity is immutable.');
        }

        $sourceColumns = [
            'original_name',
            'storage_path',
            'mime_type',
            'size_bytes',
            'page_count',
            'original_sha256',
        ];

        if ($this->isDirty($sourceColumns)) {
            $this->assertDraftEnvelope();
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

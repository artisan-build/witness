<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EnvelopeStatus;
use App\Models\Document;
use App\Models\Envelope;
use DomainException;
use Illuminate\Support\Facades\DB;

final class StoreFinalDocument
{
    public function handle(Envelope $envelope, string $storagePath, string $sha256): Document
    {
        return DB::transaction(function () use ($envelope, $storagePath, $sha256): Document {
            $document = Document::query()
                ->where('envelope_id', $envelope->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedEnvelope = Envelope::query()->lockForUpdate()->findOrFail($envelope->getKey());

            if ($lockedEnvelope->status !== EnvelopeStatus::Sent
                || (int) $document->envelope_id !== (int) $lockedEnvelope->getKey()) {
                throw new DomainException('A final document can only be stored for a sent envelope.');
            }

            DB::statement("SET LOCAL witness.final_document_write = 'on'");
            $document->final_storage_path = $storagePath;
            $document->final_sha256 = $sha256;
            $document->save();
            DB::statement("SET LOCAL witness.final_document_write = 'off'");

            return $document->refresh();
        });
    }
}

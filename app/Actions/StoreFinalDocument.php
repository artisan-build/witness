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
            $lockedEnvelope = Envelope::query()
                ->with('document')
                ->lockForUpdate()
                ->findOrFail($envelope->getKey());

            if ($lockedEnvelope->status !== EnvelopeStatus::Sent || $lockedEnvelope->document === null) {
                throw new DomainException('A final document can only be stored for a sent envelope.');
            }

            DB::statement("SET LOCAL witness.final_document_write = 'on'");
            $lockedEnvelope->document->final_storage_path = $storagePath;
            $lockedEnvelope->document->final_sha256 = $sha256;
            $lockedEnvelope->document->save();
            DB::statement("SET LOCAL witness.final_document_write = 'off'");

            return $lockedEnvelope->document->refresh();
        });
    }
}

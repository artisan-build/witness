<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FieldType;
use App\Models\Envelope;
use DomainException;

final class EnvelopeSendValidator
{
    public function assertSendable(Envelope $envelope): void
    {
        $document = $envelope->document;

        if ($document === null
            || $document->page_count < 1
            || $document->page_count > 100
            || $document->size_bytes < 1
            || $document->size_bytes > PdfDocumentService::MAX_BYTES
            || preg_match('/\A[0-9a-f]{64}\z/', $document->original_sha256) !== 1) {
            throw new DomainException('An envelope requires a valid original document before send.');
        }

        if ($envelope->signers->isEmpty()) {
            throw new DomainException('An envelope requires at least one signer before send.');
        }

        $signerIds = $envelope->signers->modelKeys();

        foreach ($envelope->fields as $field) {
            if (! in_array($field->signer_id, $signerIds, true)
                || $field->page < 1
                || $field->page > $document->page_count
                || (float) $field->position_x < 0
                || (float) $field->position_y < 0
                || (float) $field->width <= 0
                || (float) $field->height <= 0
                || (float) $field->position_x + (float) $field->width > 1
                || (float) $field->position_y + (float) $field->height > 1) {
                throw new DomainException('Every field must be assigned within this envelope and its document pages.');
            }
        }

        foreach ($envelope->signers as $signer) {
            $hasRequiredSignature = $signer->fields
                ->where('required', true)
                ->contains(fn ($field): bool => in_array($field->type, [FieldType::Signature, FieldType::Initials], true));

            if (! $hasRequiredSignature) {
                throw new DomainException('Every signer needs a required signature or initials field before send.');
            }
        }
    }
}

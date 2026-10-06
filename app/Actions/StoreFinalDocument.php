<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeLifecycleRecorded;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Signer;
use App\Services\OutboundMailDelivery;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class StoreFinalDocument
{
    public function __construct(private OutboundMailDelivery $mailDelivery) {}

    public function handle(
        Envelope $envelope,
        string $storagePath,
        string $sha256,
        ?string $contentSha256 = null,
        ?string $certificateChainHead = null,
    ): Document {
        $hashes = [$sha256, $contentSha256 ?? $sha256];
        if ($certificateChainHead !== null) {
            $hashes[] = $certificateChainHead;
        }
        foreach ($hashes as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new DomainException('Final document hashes must be lowercase SHA-256 values.');
            }
        }

        if (! Storage::exists($storagePath)
            || ! hash_equals($sha256, hash('sha256', Storage::get($storagePath)))) {
            throw new DomainException('The stored final document failed its integrity check.');
        }

        return DB::transaction(function () use (
            $envelope,
            $storagePath,
            $sha256,
            $contentSha256,
            $certificateChainHead,
        ): Document {
            $lockedEnvelope = Envelope::query()->lockForUpdate()->findOrFail($envelope->getKey());
            $document = Document::query()
                ->where('envelope_id', $lockedEnvelope->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $signers = Signer::query()
                ->where('envelope_id', $lockedEnvelope->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedEnvelope->status === EnvelopeStatus::Completed) {
                if ($document->final_storage_path === null || $document->final_sha256 === null) {
                    throw new DomainException('A completed envelope is missing its final document metadata.');
                }

                return $document;
            }

            if ($lockedEnvelope->status !== EnvelopeStatus::Sent
                || (int) $document->envelope_id !== (int) $lockedEnvelope->getKey()
                || $document->final_storage_path !== null
                || $document->final_sha256 !== null
                || $signers->isEmpty()
                || $signers->contains(fn (Signer $signer): bool => $signer->status !== SignerStatus::Signed)) {
                throw new DomainException('A final document can only complete a fully signed sent envelope.');
            }

            DB::statement("SET LOCAL witness.final_document_write = 'on'");
            $document->final_storage_path = $storagePath;
            $document->final_sha256 = $sha256;
            $document->save();
            DB::statement("SET LOCAL witness.final_document_write = 'off'");

            $completedAt = now()->toImmutable();
            EnvelopeLifecycleRecorded::record(
                $lockedEnvelope,
                AuditEventType::Completed,
                AuditActorType::System,
                payload: [
                    'final_sha256' => $sha256,
                    'final_content_sha256' => $contentSha256 ?? $sha256,
                    'certificate_chain_head' => $certificateChainHead ?? $lockedEnvelope->audit_chain_head,
                ],
                occurredAt: $completedAt,
            );

            $lockedEnvelope->loadMissing('sender');
            $this->mailDelivery->firstOrCreateFinal($lockedEnvelope, $lockedEnvelope->sender);
            foreach ($signers as $signer) {
                $this->mailDelivery->firstOrCreateFinal($lockedEnvelope, $signer);
            }

            DB::statement("SET LOCAL witness.envelope_transition = 'on'");
            DB::table('envelopes')->where('id', $lockedEnvelope->getKey())->update([
                'status' => EnvelopeStatus::Completed->value,
                'completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);
            DB::statement("SET LOCAL witness.envelope_transition = 'off'");

            return $document->refresh();
        });
    }
}

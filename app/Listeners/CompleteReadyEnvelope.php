<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\EnvelopeStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeReadyForCompletion;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Signer;
use App\Services\EnvelopeCompletionService;
use ArtisanBuild\BuiltForCloud\Contracts\SystemAuthorityQueueEntry;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class CompleteReadyEnvelope implements ShouldQueueAfterCommit, SystemAuthorityQueueEntry
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(private readonly EnvelopeCompletionService $completion) {}

    public function handle(EnvelopeReadyForCompletion $event): void
    {
        try {
            $this->completion->complete($event->envelopeId);
        } catch (Throwable $exception) {
            Log::error('Envelope completion failed.', [
                'envelope_id' => $event->envelopeId,
                'exception_class' => $exception::class,
            ]);

            throw new RuntimeException('Envelope completion failed.');
        }
    }

    public function failed(EnvelopeReadyForCompletion $event, Throwable $exception): void
    {
        DB::transaction(function () use ($event): void {
            $envelope = Envelope::query()->lockForUpdate()->find($event->envelopeId);
            if ($envelope === null || $envelope->status !== EnvelopeStatus::Sent) {
                return;
            }
            $document = Document::query()->where('envelope_id', $event->envelopeId)->lockForUpdate()->first();
            $signers = Signer::query()->where('envelope_id', $event->envelopeId)->orderBy('id')->lockForUpdate()->get();
            if ($document?->final_storage_path !== null || $signers->isEmpty()
                || $signers->contains(fn (Signer $signer) => $signer->status !== SignerStatus::Signed)) {
                return;
            }
            try {
                DB::statement("SET LOCAL witness.envelope_transition = 'on'");
                DB::table('envelopes')->where('id', $event->envelopeId)->update([
                    'status' => EnvelopeStatus::CompletionFailed->value,
                    'updated_at' => now(),
                ]);
            } finally {
                DB::statement("SET LOCAL witness.envelope_transition = 'off'");
            }
        });
    }
}

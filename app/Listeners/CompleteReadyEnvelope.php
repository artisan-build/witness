<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EnvelopeReadyForCompletion;
use App\Services\EnvelopeCompletionService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class CompleteReadyEnvelope implements ShouldQueueAfterCommit
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
}

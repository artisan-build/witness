<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\StoreFinalDocument;
use App\Enums\AuditActorType;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\MailDeliveryPurpose;
use App\Enums\MailDeliveryStatus;
use App\Enums\SignerStatus;
use App\Events\EnvelopeReadyForCompletion;
use App\Mail\CompletedDocument;
use App\Models\Envelope;
use App\Models\MailDelivery;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class EnvelopeCompletionService
{
    private const int ATTACHMENT_LIMIT = 10 * 1024 * 1024;

    public function __construct(
        private PdfCompletionRenderer $renderer,
        private StoreFinalDocument $storeFinalDocument,
        private PdfDocumentService $pdfDocuments,
        private OutboundMailDelivery $mailDelivery,
    ) {}

    public function complete(int $envelopeId): void
    {
        $envelope = Envelope::query()->with(['document', 'sender'])->findOrFail($envelopeId);
        $candidatePath = null;

        try {
            if ($envelope->status === EnvelopeStatus::Sent) {
                $completedPdf = $this->renderer->render($envelope);
                $candidatePath = 'documents/'.$envelope->team_id.'/completed/'.Str::uuid().'.pdf';

                if (! Storage::put($candidatePath, $completedPdf->bytes, ['visibility' => 'private'])) {
                    throw new RuntimeException('The final document could not be stored.');
                }

                $storedBytes = Storage::get($candidatePath);
                if (! hash_equals($completedPdf->sha256, hash('sha256', $storedBytes))) {
                    throw new RuntimeException('The stored final document failed validation.');
                }

                $document = $this->storeFinalDocument->handle(
                    $envelope,
                    $candidatePath,
                    $completedPdf->sha256,
                    $completedPdf->contentSha256,
                    $completedPdf->certificateChainHead,
                );

                if ($document->final_storage_path !== $candidatePath) {
                    $this->pdfDocuments->deleteOrQueue($candidatePath);
                }
                $candidatePath = null;
            }

            $this->deliverPending($envelopeId);
        } catch (Throwable $exception) {
            if ($candidatePath !== null) {
                $this->pdfDocuments->deleteOrQueue($candidatePath);
            }

            throw $exception;
        }
    }

    public function dispatchReady(): void
    {
        $ready = Envelope::query()
            ->where('status', EnvelopeStatus::Sent->value)
            ->whereHas('signers')
            ->whereDoesntHave('signers', fn ($query) => $query->where('status', '!=', SignerStatus::Signed->value))
            ->orderBy('id')
            ->pluck('id');
        $pendingDelivery = Envelope::query()
            ->where('status', EnvelopeStatus::Completed->value)
            ->whereHas('mailDeliveries', fn ($query) => $query
                ->where('purpose', MailDeliveryPurpose::FinalDocument->value)
                ->where('status', MailDeliveryStatus::Pending->value))
            ->orderBy('id')
            ->pluck('id');

        $ready->merge($pendingDelivery)
            ->unique()
            ->each(fn (int $envelopeId) => event(new EnvelopeReadyForCompletion($envelopeId)));
    }

    public static function attachesFinal(int $bytes): bool
    {
        return $bytes <= self::ATTACHMENT_LIMIT;
    }

    private function deliverPending(int $envelopeId): void
    {
        $envelope = Envelope::query()->with(['document', 'sender'])->findOrFail($envelopeId);
        $document = $envelope->document;
        if ($envelope->status !== EnvelopeStatus::Completed
            || $document?->final_storage_path === null
            || $document->final_sha256 === null) {
            return;
        }

        MailDelivery::query()
            ->where('envelope_id', $envelopeId)
            ->where('purpose', MailDeliveryPurpose::FinalDocument->value)
            ->where('status', MailDeliveryStatus::Pending->value)
            ->orderBy('id')
            ->each(function (MailDelivery $delivery) use ($envelope, $document): void {
                $this->mailDelivery->deliver(
                    $delivery,
                    function () use ($delivery, $envelope, $document): void {
                        $path = $document->final_storage_path;
                        if ($path === null || ! Storage::exists($path)) {
                            throw new RuntimeException('The final document is unavailable for delivery.');
                        }

                        $bytes = Storage::get($path);
                        if (! hash_equals((string) $document->final_sha256, hash('sha256', $bytes))) {
                            throw new RuntimeException('The final document failed its delivery integrity check.');
                        }

                        Mail::mailer((string) config('mail.default'))
                            ->to(new Address($delivery->recipient_email, $delivery->recipient_name))->send(
                                new CompletedDocument(
                                    $envelope,
                                    $document,
                                    $delivery,
                                    self::attachesFinal(strlen($bytes)),
                                    URL::signedRoute('final.recipient.download', ['delivery' => $delivery->getKey()]),
                                ),
                            );
                    },
                    AuditEventType::FinalDelivered,
                    AuditActorType::System,
                    payload: [
                        'recipient_kind' => $delivery->recipient_kind,
                        'recipient_email_sha256' => hash('sha256', mb_strtolower($delivery->recipient_email)),
                    ],
                );
            });
    }
}

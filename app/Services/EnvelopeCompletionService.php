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
use App\Models\Signer;
use App\Models\Team;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\DB;
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

    public function resendUnknown(Team $team, User $sender, int $envelopeId, int $deliveryId): void
    {
        $replacement = DB::transaction(function () use ($team, $sender, $envelopeId, $deliveryId): MailDelivery {
            $envelope = Envelope::query()
                ->forTeam($team)
                ->where('sender_id', $sender->getKey())
                ->lockForUpdate()
                ->findOrFail($envelopeId);
            $delivery = MailDelivery::query()->where('envelope_id', $envelopeId)->findOrFail($deliveryId);
            if ($delivery->signer_id !== null) {
                Signer::query()->lockForUpdate()->findOrFail($delivery->signer_id);
            }
            $delivery = MailDelivery::query()->where('envelope_id', $envelopeId)->lockForUpdate()->findOrFail($deliveryId);
            if ($envelope->status !== EnvelopeStatus::Completed
                || $delivery->purpose !== MailDeliveryPurpose::FinalDocument
                || $delivery->status !== MailDeliveryStatus::Unknown) {
                throw new \DomainException('Only an unknown final-document handoff can be deliberately resent.');
            }

            return $this->mailDelivery->createFinalGeneration($delivery);
        });

        $this->deliverPending($envelopeId, $replacement->getKey());
    }

    public function reissueSent(Team $team, User $sender, int $envelopeId, int $deliveryId): bool
    {
        $replacement = DB::transaction(function () use ($team, $sender, $envelopeId, $deliveryId): MailDelivery {
            $envelope = Envelope::query()
                ->forTeam($team)
                ->where('sender_id', $sender->getKey())
                ->lockForUpdate()
                ->findOrFail($envelopeId);
            $candidate = MailDelivery::query()
                ->where('envelope_id', $envelopeId)
                ->where('purpose', MailDeliveryPurpose::FinalDocument->value)
                ->findOrFail($deliveryId);
            if ($candidate->signer_id !== null) {
                Signer::query()->lockForUpdate()->findOrFail($candidate->signer_id);
            }
            $latest = MailDelivery::query()
                ->where('envelope_id', $envelopeId)
                ->where('purpose', MailDeliveryPurpose::FinalDocument->value)
                ->where('recipient_kind', $candidate->recipient_kind)
                ->where('recipient_email', $candidate->recipient_email)
                ->orderByDesc('generation')
                ->lockForUpdate()
                ->firstOrFail();
            if ($envelope->status !== EnvelopeStatus::Completed
                || $latest->getKey() !== $candidate->getKey()
                || $latest->status !== MailDeliveryStatus::Sent) {
                throw new \DomainException('Only the latest successful final-document delivery can receive a fresh link.');
            }

            return $this->mailDelivery->createFinalGeneration($latest);
        });

        $this->deliverPending($envelopeId, $replacement->getKey());

        return $replacement->fresh()->status === MailDeliveryStatus::Sent;
    }

    private function deliverPending(int $envelopeId, ?int $onlyDeliveryId = null): void
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
            ->when($onlyDeliveryId !== null, fn ($query) => $query->whereKey($onlyDeliveryId))
            ->orderBy('id')
            ->each(function (MailDelivery $delivery) use ($envelope, $document): void {
                $path = $document->final_storage_path;
                if ($path === null || ! Storage::exists($path)) {
                    throw new RuntimeException('The final document is unavailable for delivery.');
                }

                $bytes = Storage::get($path);
                if (! hash_equals((string) $document->final_sha256, hash('sha256', $bytes))) {
                    throw new RuntimeException('The final document failed its delivery integrity check.');
                }

                $recipient = new Address($delivery->recipient_email, $delivery->recipient_name);
                $mailable = new CompletedDocument(
                    $envelope,
                    $document,
                    $delivery,
                    self::attachesFinal(strlen($bytes)),
                    URL::temporarySignedRoute('final.recipient.download', now()->addDays(7), ['delivery' => $delivery->getKey()]),
                );
                $mailable->render();
                $pendingMail = Mail::mailer((string) config('mail.default'))->to($recipient);

                $this->mailDelivery->deliver(
                    $delivery,
                    fn () => $pendingMail->send($mailable),
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

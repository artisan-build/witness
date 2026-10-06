<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Document;
use App\Models\Envelope as WitnessEnvelope;
use App\Models\MailDelivery;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class CompletedDocument extends Mailable
{
    public function __construct(
        public readonly WitnessEnvelope $witnessEnvelope,
        public readonly Document $document,
        public readonly MailDelivery $delivery,
        public readonly bool $attachFinal,
        #[\SensitiveParameter] public readonly string $downloadUrl,
    ) {}

    public function envelope(): Envelope
    {
        $sender = $this->witnessEnvelope->sender;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $sender->name.' via Witness'),
            replyTo: [new Address($sender->email, $sender->name)],
            subject: 'Completed: '.$this->witnessEnvelope->title,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.completed-document');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if (! $this->attachFinal || $this->document->final_storage_path === null) {
            return [];
        }

        return [
            Attachment::fromStorageDisk(
                (string) config('filesystems.default'),
                $this->document->final_storage_path,
            )->as('completed-'.$this->witnessEnvelope->getKey().'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Envelope as WitnessEnvelope;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class LifecycleNotification extends Mailable
{
    public function __construct(
        public readonly WitnessEnvelope $witnessEnvelope,
        public readonly string $outcome,
    ) {}

    public function envelope(): Envelope
    {
        $sender = $this->witnessEnvelope->sender;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $sender->name.' via Witness'),
            replyTo: [new Address($sender->email, $sender->name)],
            subject: ucfirst($this->outcome).': '.$this->witnessEnvelope->title,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.lifecycle-notification');
    }
}

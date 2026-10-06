<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Envelope as WitnessEnvelope;
use App\Models\Signer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SigningInvitation extends Mailable
{
    public function __construct(
        public readonly WitnessEnvelope $witnessEnvelope,
        public readonly Signer $signer,
        #[\SensitiveParameter] public readonly string $linkToken,
    ) {}

    public function envelope(): Envelope
    {
        $sender = $this->witnessEnvelope->sender;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $sender->name.' via Witness'),
            replyTo: [new Address($sender->email, $sender->name)],
            subject: 'Signature requested: '.$this->witnessEnvelope->title,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.signing-invitation');
    }
}

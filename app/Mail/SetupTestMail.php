<?php

declare(strict_types=1);

namespace App\Mail;

use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class SetupTestMail extends Mailable
{
    public function __construct(public readonly User $recipient) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->recipient->name.' via Witness'),
            replyTo: [new Address($this->recipient->email, $this->recipient->name)],
            subject: 'Witness test email',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.setup-test');
    }
}

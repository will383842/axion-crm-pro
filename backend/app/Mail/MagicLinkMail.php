<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $link,
        public readonly int $ttlMinutes = 15,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Lien de connexion — ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.magic-link',
            text: 'emails.magic-link-text',
            with: ['link' => $this->link, 'ttl' => $this->ttlMinutes],
        );
    }
}

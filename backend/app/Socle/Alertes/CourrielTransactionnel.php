<?php

namespace App\Socle\Alertes;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Courriel transactionnel (jamais de prospection) : un objet, un texte simple. Ex. : code de validation d'une campagne. */
final class CourrielTransactionnel extends Mailable
{
    public function __construct(public readonly string $objet, public readonly string $corps) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->objet);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>' . nl2br(e($this->corps)) . '</p>');
    }
}

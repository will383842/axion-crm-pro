<?php

namespace App\Socle\Alertes;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Le courrier d'une alerte : un titre, un corps, un lien éventuel. Texte simple, lisible sur un téléphone. */
final class AlerteMail extends Mailable
{
    public function __construct(
        public readonly string $titre,
        public readonly string $corps,
        public readonly ?string $lien = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Alerte] ' . $this->titre);
    }

    public function content(): Content
    {
        $html = '<p><strong>' . e($this->titre) . '</strong></p><p>' . nl2br(e($this->corps)) . '</p>';
        if ($this->lien !== null && $this->lien !== '') {
            $html .= '<p>Ouvrir dans la console : ' . e((string) config('app.url')) . e($this->lien) . '</p>';
        }

        return new Content(htmlString: $html);
    }
}

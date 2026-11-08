<?php

namespace App\Envoi\Imap;

/** Applique plusieurs récepteurs dans l'ordre ; la première exception interrompt la chaîne (rien n'est caché). */
final class RecepteurRetoursEnChaine implements RecepteurRetours
{
    /** @var list<RecepteurRetours> */
    private array $recepteurs;

    public function __construct(RecepteurRetours ...$recepteurs)
    {
        $this->recepteurs = array_values($recepteurs);
    }

    public function recevoir(string $workspaceId, RetourDetecte $retour): void
    {
        foreach ($this->recepteurs as $r) {
            $r->recevoir($workspaceId, $retour);
        }
    }
}

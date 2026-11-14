<?php

namespace App\Envoi\Temoins;

use DateTimeImmutable;

/**
 * Lit, en LECTURE SEULE, le dossier des indésirables (Spam, Junk, Courrier indésirable selon le fournisseur) d'un témoin
 * et rend les Message-ID des messages reçus depuis $depuis. Aucun marquage, aucun déplacement. Une panne technique est
 * une EXCEPTION (jamais un « rien trouvé » : ce silence ferait passer une journée pour propre).
 */
interface LecteurIndesirables
{
    /** @return list<string> Message-ID sans chevrons */
    public function messageIdsEnIndesirables(AccesTemoin $acces, DateTimeImmutable $depuis): array;
}

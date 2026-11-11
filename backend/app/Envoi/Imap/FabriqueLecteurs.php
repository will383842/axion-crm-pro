<?php

namespace App\Envoi\Imap;

/**
 * Fabrique de lecteurs : UN lecteur par boîte. Un lecteur réel (`LecteurBoiteWebklex`) ne sert qu'une boîte
 * (ses identifiants, sa connexion) : la relève en demande donc un à la fabrique pour chaque boîte relevée, et
 * la panne d'une boîte ne touche jamais le lecteur d'une autre. Aucun secret ici : l'implémentation réelle
 * les tire du gestionnaire de secrets (non livrée dans le dépôt).
 */
interface FabriqueLecteurs
{
    public function fabrique(string $boite): LecteurBoite;
}

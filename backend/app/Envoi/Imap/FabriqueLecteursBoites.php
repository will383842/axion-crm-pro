<?php

namespace App\Envoi\Imap;

use App\Support\WorkspaceContext;
use Closure;
use RuntimeException;

/**
 * Fabrique RÉELLE de lecteurs, INERTE : tant que `ENVOI_REEL_ACTIF` et `ENVOI_RELEVE_ACTIF` ne sont pas tous deux vrais, elle lève
 * AVANT toute lecture de base et tout déchiffrement. Sinon elle lit l'accès IMAP posé pour la boîte (`DepotAccesImap`, seul
 * endroit qui déchiffre) et rend un `LecteurBoiteWebklex` : aucune connexion à la construction (elle n'a lieu qu'à la première
 * lecture). Messages fixes : jamais d'hôte, d'identifiant ni de mot de passe.
 */
final class FabriqueLecteursBoites implements FabriqueLecteurs
{
    public const MESSAGE_ETEINTE = 'relève éteinte';

    /** @param  ?Closure  $protocole  faux protocole (tests uniquement) ; null = protocole réel */
    public function __construct(private readonly DepotAccesImap $acces, private readonly ?Closure $protocole = null) {}

    public function fabrique(string $boite): LecteurBoite
    {
        if (config('envoi.reel_actif') !== true || config('envoi.releve_actif') !== true) {
            throw new RuntimeException(self::MESSAGE_ETEINTE);
        }
        $ws = WorkspaceContext::current();
        if ($ws === null) {
            throw new RuntimeException('relève sans espace');
        }
        $p = $this->acces->parametres($ws, $boite);
        if ($p === null) {
            throw new AccesImapAbsent;
        }
        if (! HotesImapBoites::hoteAutorise($p['host']) || $p['port'] !== 993) {
            throw new RuntimeException('accès IMAP hors liste');
        }

        return new LecteurBoiteWebklex($p, $boite, 'INBOX', $this->protocole);
    }
}

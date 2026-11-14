<?php

namespace App\Envoi\Temoins;

use App\Envoi\Imap\LecteurBoite;
use App\Envoi\Imap\LecteurBoiteWebklex;
use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Lecteur réel : s'appuie sur {@see LecteurBoiteWebklex} (EXAMINE + BODY.PEEK[], jamais d'écriture) sur chaque dossier
 * d'indésirables possible du fournisseur. L'hôte vient UNIQUEMENT de {@see HotesTemoins} (refus sinon). Un dossier absent
 * chez ce fournisseur est sauté ; si AUCUN dossier n'a pu être lu, c'est une panne (exception), jamais « vide ».
 */
final class LecteurIndesirablesWebklex implements LecteurIndesirables
{
    /** Taille d'une page d'UID bruts. */
    private const LIMITE = 200;

    /** Pages au plus par dossier (10 000 messages) : au-delà, la fenêtre n'est pas couverte, donc PANNE (jamais un faux vert). */
    private const PAGES_MAX = 50;

    /** @param ?Closure(AccesTemoin, string): LecteurBoite $fabrique tests : lecteur de dossier sans réseau */
    public function __construct(private readonly ?Closure $fabrique = null) {}

    public function messageIdsEnIndesirables(AccesTemoin $acces, DateTimeImmutable $depuis): array
    {
        if (! HotesTemoins::hoteAutorise($acces->hote) || $acces->hote !== HotesTemoins::pour($acces->fournisseur)['hote']) {
            throw new RuntimeException('Hôte de témoin hors liste.');
        }
        $ids = [];
        $lu = false;
        foreach (HotesTemoins::pour($acces->fournisseur)['dossiers'] as $dossier) {
            try {
                $lecteur = $this->fabrique !== null
                    ? ($this->fabrique)($acces, $dossier)
                    : new LecteurBoiteWebklex(['host' => $acces->hote, 'port' => 993, 'encryption' => 'ssl', 'username' => $acces->adresse, 'password' => $acces->motDePasse], 'temoin', $dossier);
                $lot = $lecteur->lire('temoin', 0, $depuis, self::LIMITE);
            } catch (Throwable) {
                continue; // dossier inexistant chez ce fournisseur (nom différent) : on essaie le suivant
            }
            $lu = true;
            // Les UID sont lus par ordre croissant et la page est coupée AVANT le filtre de date : on pagine tant que
            // `suite` est vrai, pour atteindre les messages récents. Une panne en cours de route remonte (hors du try).
            for ($page = 1; ; $page++) {
                foreach ($lot->messages as $m) {
                    if ($m->messageId !== null && $m->ecarte === null) {
                        $ids[] = trim($m->messageId, " \t\r\n<>");
                    }
                }
                if (! $lot->suite) {
                    break;
                }
                if ($page >= self::PAGES_MAX) {
                    throw new RuntimeException('Fenêtre de lecture non couverte : dossier trop volumineux.');
                }
                $lot = $lecteur->lire('temoin', $lot->dernierUid, $depuis, self::LIMITE);
            }
        }
        if (! $lu) {
            throw new RuntimeException('Aucun dossier d\'indésirables lisible.');
        }

        return $ids;
    }
}

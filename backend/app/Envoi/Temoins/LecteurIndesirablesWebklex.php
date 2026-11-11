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
    private const LIMITE = 200;

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
            foreach ($lot->messages as $m) {
                if ($m->messageId !== null && $m->ecarte === null) {
                    $ids[] = trim($m->messageId, " \t\r\n<>");
                }
            }
        }
        if (! $lu) {
            throw new RuntimeException('Aucun dossier d\'indésirables lisible.');
        }

        return $ids;
    }
}

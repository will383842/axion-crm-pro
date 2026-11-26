<?php

namespace App\Crm\Sites;

use App\Crm\Presse\LecturePageAccueil;
use App\Services\Domain\DomainFinderService;
use Throwable;

/**
 * LA PREUVE PAR LE SIREN d'une adresse candidate : le SIREN de la fiche doit
 * figurer sur l'accueil, sinon sur les mentions légales ; la page d'ARRIVÉE
 * doit rester sur le même domaine (une redirection vers un autre domaine ne
 * prouve rien). Même logique que `crm:entreprises:chercher-site-prouve`
 * (copiée : l'ancien fichier n'est pas modifié).
 *
 * Toute lecture passe par `LecturePageAccueil` : robots.txt, garde SSRF après
 * résolution DNS, redirections revérifiées, corps borné. Aucun corps de page
 * n'est gardé après la lecture.
 */
final class PreuveSiren
{
    public function __construct(
        private readonly LecturePageAccueil $lecteur,
        private readonly DomainFinderService $finder,
    ) {}

    /** `PREUVE_ACCUEIL`, `PREUVE_MENTIONS`, ou null (rien de prouvé). */
    public function prouver(string $siren, string $cible): ?string
    {
        $accueil = $this->lire($cible);
        if ($accueil === null || VerificationSite::motifArrivee($cible, $accueil['finale']) !== null) {
            return null;
        }
        if ($this->contient($accueil['corps'], $siren)) {
            return VerificationSite::PREUVE_ACCUEIL;
        }
        $base = VerificationSite::memeSite($accueil['finale'], $cible) ? $accueil['finale'] : $cible;
        $mentions = VerificationSite::lienMentions(DomainFinderService::tronquer($accueil['corps']), $accueil['finale'])
            ?? VerificationSite::mentionsParDefaut($base);
        unset($accueil);
        if ($mentions === null) {
            return null;
        }
        $page = $this->lire($mentions);
        if ($page === null || VerificationSite::motifArrivee($cible, $page['finale']) !== null) {
            return null;
        }

        return $this->contient($page['corps'], $siren) ? VerificationSite::PREUVE_MENTIONS : null;
    }

    private function contient(string $corps, string $siren): bool
    {
        return $siren !== '' && in_array($siren, $this->finder->sirensDansPage($corps), true);
    }

    /** @return array{corps: string, finale: string}|null null : pas lue (robots, SSRF, panne) */
    private function lire(string $url): ?array
    {
        try {
            $lu = $this->lecteur->lire([$url], true)[$url] ?? null;
        } catch (Throwable) {
            return null;
        }
        if ($lu === null || $lu['statut'] !== LecturePageAccueil::STATUT_LU) {
            return null;
        }

        return ['corps' => $lu['corps'] ?? '', 'finale' => $lu['finale'] ?? $url];
    }
}

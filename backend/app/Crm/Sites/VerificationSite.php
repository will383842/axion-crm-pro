<?php

namespace App\Crm\Sites;

use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Presse\SiteMedia;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * VÉRIFIER UN SITE DEVINÉ PAR UNE PREUVE FORTE (lot N6, 03/10/2026) — les
 * règles de `crm:entreprises:verifier-sites`, sans réseau ni base.
 *
 * ── LA PREUVE ────────────────────────────────────────────────────────────
 *
 * Un site deviné (`SiteFiable`) devient VÉRIFIÉ si, et seulement si, le
 * SIREN de l'entreprise figure sur sa page d'accueil OU sur sa page de
 * mentions légales (lien trouvé sur l'accueil, sur le même site ; à défaut
 * `/mentions-legales`). Une page n'est une preuve QUE si son adresse
 * d'ARRIVÉE, après redirections, est sur le même domaine enregistrable que
 * le site deviné (`motifArrivee`, même garde que `SiteMedia::juger`) : un
 * domaine parqué ou revendu qui renvoie vers un annuaire portant le SIREN ne
 * valide rien (#314, R1). La présence du SIREN est jugée par la règle de #305
 * (`DomainFinderService::sirensDansPage`, arbitre `contientSiren`). Le nom,
 * la ville, le code postal ne suffisent JAMAIS ici : c'est la preuve faible
 * qui a laissé passer france.fr, paris.fr, maison.fr.
 *
 * ── LE MARQUEUR ──────────────────────────────────────────────────────────
 *
 * `companies.metadata.site_entreprise` = {statut, url, preuve?, motif?, le, v},
 * la clé que `SiteFiable` lit déjà, avec le vocabulaire de `SiteMedia` :
 *   - `verifie`          SIREN trouvé (`preuve` : accueil ou mentions) ;
 *   - `non-conforme`     page lue, SIREN absent : le site reste NON VÉRIFIÉ ;
 *   - `injoignable`      erreur réseau, code HTTP hors 2xx, page illisible
 *                        (`motif` : illisible), adresse refusée (SSRF) ;
 *   - `robots-interdit`  robots.txt interdit l'accueil : rien n'est lu.
 * Seul `verifie` sort la fiche de « non vérifié ». Le site lui-même n'est
 * JAMAIS effacé ni réécrit, aucune ligne n'est supprimée.
 *
 * ── LA FENÊTRE ───────────────────────────────────────────────────────────
 *
 * Lancement permis du mardi au samedi, de 08:00 à 19:00, heure de PARIS,
 * jamais les 1er, 2 et 3 du mois (`horsFenetre`) ; `--forcer` passe outre
 * pour un essai.
 */
final class VerificationSite
{
    /** Version des règles, écrite dans le marqueur (`v`). */
    public const VERSION = 1;

    public const PREUVE_ACCUEIL = 'siren-accueil';

    public const PREUVE_MENTIONS = 'siren-mentions';

    public const MOTIF_ILLISIBLE = 'illisible';

    public const MOTIF_ADRESSE = 'adresse-invalide';

    /** Arrivée (après redirections) sur un AUTRE domaine enregistrable (#314, R1). */
    public const MOTIF_REDIRECTION = SiteMedia::MOTIF_REDIRECTION;

    /** Arrivée chez un parkeur / une place de marché de domaines. */
    public const MOTIF_PARKING = SiteMedia::MOTIF_PARKING;

    /** Clé du curseur persistant (`curseurs_traitements.traitement`). */
    public const TRAITEMENT = 'entreprises:verifier-sites';

    public const FUSEAU = 'Europe/Paris';

    public const HEURE_DEBUT = '08:00';

    public const HEURE_FIN = '19:00';

    /** Jours ISO permis : mardi (2) → samedi (6). */
    public const JOURS_PERMIS = [2, 3, 4, 5, 6];

    /** Jours du mois interdits (clôtures, envois du début de mois). */
    public const QUANTIEMES_INTERDITS = [1, 2, 3];

    /** Adresse essayée quand l'accueil ne porte aucun lien de mentions légales. */
    public const CHEMIN_MENTIONS = '/mentions-legales';

    /** Statuts écrits par ce traitement. */
    public const STATUTS = [SiteMedia::VERIFIE, SiteMedia::NON_CONFORME, SiteMedia::INJOIGNABLE, SiteMedia::ROBOTS_INTERDIT];

    /**
     * Null si `$quand` est dans la fenêtre de lancement, sinon la raison du
     * refus (en clair, pour la console).
     */
    public static function horsFenetre(CarbonInterface $quand): ?string
    {
        $paris = $quand->copy()->setTimezone(self::FUSEAU);
        if (in_array($paris->day, self::QUANTIEMES_INTERDITS, true)) {
            return 'jamais les 1er, 2 et 3 du mois';
        }
        if (! in_array($paris->dayOfWeekIso, self::JOURS_PERMIS, true)) {
            return 'du mardi au samedi seulement';
        }
        $hm = $paris->format('H:i');
        if ($hm < self::HEURE_DEBUT || $hm >= self::HEURE_FIN) {
            return 'de ' . self::HEURE_DEBUT . ' à ' . self::HEURE_FIN . ' (heure de Paris) seulement';
        }

        return null;
    }

    /** Clé du curseur : une par périmètre (toutes les fiches, ou une audience). */
    public static function cleCurseur(?int $audience): string
    {
        return self::TRAITEMENT . ($audience === null ? '' : ':audience:' . $audience);
    }

    /**
     * La requête d'un paquet : fiches vivantes de l'espace, au site deviné non
     * vérifié (`SiteFiable::nonVerifieSql`, mot pour mot — c'est le prédicat
     * de l'index `idx_companies_site_non_verifie_id`), avec un SIREN et un
     * site, après le curseur, dans l'ordre des identifiants. `$audience` :
     * membres de cette audience seulement (sonde par l'index unique
     * `audience_members (audience_id, company_id, contact_id)`). Rend aussi
     * le marqueur en place (`statut_avant`, `url_avant`, `motif_avant`,
     * `v_avant`) : un marqueur identique n'est pas réécrit (#314).
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public static function selectionSql(string $workspaceId, int $curseur, int $limite, ?int $audience): array
    {
        $liaisons = [$workspaceId, $curseur];
        $membres = '';
        if ($audience !== null) {
            $membres = ' AND EXISTS (SELECT 1 FROM audience_members am WHERE am.audience_id = ? AND am.company_id = c.id)';
            $liaisons[] = $audience;
        }
        $liaisons[] = $limite;

        return [
            "SELECT c.id, c.siren, c.website,
                    c.metadata -> '" . SiteFiable::CLE . "' ->> 'statut' AS statut_avant,
                    c.metadata -> '" . SiteFiable::CLE . "' ->> 'url' AS url_avant,
                    c.metadata -> '" . SiteFiable::CLE . "' ->> 'motif' AS motif_avant,
                    c.metadata -> '" . SiteFiable::CLE . "' ->> 'v' AS v_avant
               FROM companies c
              WHERE c.workspace_id = ?
                AND c.deleted_at IS NULL
                AND c.id > ?
                AND " . SiteFiable::nonVerifieSql('c') . "
                AND c.siren IS NOT NULL
                AND c.website IS NOT NULL AND btrim(c.website) <> ''{$membres}
              ORDER BY c.id
              LIMIT ?",
            $liaisons,
        ];
    }

    /**
     * Le lien de mentions légales de la page `$html` lue à `$base`, sur le
     * MÊME site (hôte égal, `www.` ignoré), ou null. Ordre de préférence :
     * « mentions légales », puis « informations légales » / « mentions » /
     * « legal notice », puis CGV / CGU / conditions générales.
     */
    public static function lienMentions(string $html, string $base): ?string
    {
        if (preg_match_all('#<a\b[^>]{0,500}?\bhref\s*=\s*(["\'])([^"\'<>]{0,2000})\1[^>]{0,500}>(.{0,500}?)</a\s*>#is', $html, $liens, PREG_SET_ORDER) < 1) {
            return null;
        }
        $choix = null;
        $meilleur = 0;
        $courante = LecturePageAccueil::cible($base);
        foreach ($liens as $lien) {
            $href = html_entity_decode(trim($lien[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $texte = Str::lower(Str::ascii(html_entity_decode(strip_tags($lien[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $cle = $texte . ' ' . Str::lower(Str::ascii(rawurldecode($href)));
            $score = match (true) {
                preg_match('/mentions?[\s_-]*legales?/', $cle) === 1 => 3,
                preg_match('/informations?[\s_-]*legales?|legal[\s_-]*notice|\bmentions\b|impressum|imprint/', $cle) === 1 => 2,
                preg_match('/\bcgv\b|\bcgu\b|conditions[\s_-]*generales/', $cle) === 1 => 1,
                default => 0,
            };
            if ($score <= $meilleur || $href === '' || str_starts_with($href, '#')) {
                continue;
            }
            $url = LecturePageAccueil::resoudre($base, $href);
            $url = $url === null ? null : LecturePageAccueil::cible($url);
            if ($url === null || $url === $courante || ! self::memeSite($url, $base)) {
                continue;
            }
            $choix = $url;
            $meilleur = $score;
        }

        return $choix;
    }

    /** L'adresse des mentions légales par défaut, à la racine du site de `$url`. */
    public static function mentionsParDefaut(string $url): ?string
    {
        $cible = LecturePageAccueil::cible($url);

        return $cible === null ? null : LecturePageAccueil::origine($cible) . self::CHEMIN_MENTIONS;
    }

    /**
     * Null si l'adresse d'ARRIVÉE `$finale` (après redirections) peut servir
     * de preuve pour le site deviné `$cible` : même domaine enregistrable
     * (`www.` ou non, http → https, sous-domaine du même domaine acceptés),
     * hors parkeur. Sinon le motif du refus (`redirection` ou `parking`) :
     * la page n'est PAS celle du site deviné, son SIREN ne prouve rien.
     */
    public static function motifArrivee(string $cible, string $finale): ?string
    {
        $hote = (string) parse_url($cible, PHP_URL_HOST);
        $arrivee = (string) parse_url($finale, PHP_URL_HOST);
        if ($hote === '' || $arrivee === '') {
            return self::MOTIF_REDIRECTION;
        }
        if (SiteMedia::estHoteParking($arrivee)) {
            return self::MOTIF_PARKING;
        }

        return SiteMedia::domaineEnregistrable($arrivee) === SiteMedia::domaineEnregistrable($hote) ? null : self::MOTIF_REDIRECTION;
    }

    /** Même hôte, `www.` ignoré. */
    public static function memeSite(string $a, string $b): bool
    {
        $h = static fn (string $u): string => (string) preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));

        return $h($a) !== '' && $h($a) === $h($b);
    }
}

<?php

namespace App\Crm\Sites;

use App\Crm\FichesProtegees;
use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Presse\SiteMedia;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * CHERCHER LE VRAI SITE D'UNE ENTREPRISE, PROUVÉ PAR LE SIREN (suite du lot
 * N6, 04/10/2026) — les règles de `crm:entreprises:chercher-site-prouve`,
 * sans réseau ni écriture.
 *
 * Essai à blanc en production du 04/10 (200 fiches) : 29 vérifiées, 148 NON
 * CONFORMES (74 % : le site deviné est celui d'une autre entreprise), 15
 * INJOIGNABLES, 8 ignorées (robots.txt). Décision de Will : corriger les deux
 * problèmes, au même rythme prudent.
 *
 * ── 1. LES CANDIDATS (fiches non conformes) ──────────────────────────────
 *
 * Des domaines construits à partir de la DÉNOMINATION, de l'ENSEIGNE
 * (`companies.enseigne`) et du SIGLE (`metadata.sigle`, INSEE) : minuscules,
 * accents retirés, mots vides juridiques et articles ôtés (SARL, SAS, SA,
 * EURL, SCI…), au plus 4 mots ; chaque nom donne la forme collée et la forme
 * à tirets ; `.fr` d'abord, `.com` ensuite ; l'ancien domaine deviné est
 * exclu ; AU PLUS `MAX` (6) candidats par fiche. Un candidat n'est retenu QUE
 * par la preuve forte de N6 : le SIREN sur son accueil ou ses mentions
 * légales, page d'ARRIVÉE sur le même domaine (`VerificationSite`). Le nom
 * ne suffit jamais.
 *
 * ── 2. LES RÉESSAIS (fiches injoignables) ────────────────────────────────
 *
 * Au plus `REESSAIS_MAX` (3) réessais, à `INTERVALLE_JOURS` (3) jours au
 * moins l'un de l'autre (le premier : 3 jours après le jugement de N6), avec
 * les variantes www / sans www et https / http (`variantesReessai`, 3 au
 * plus ; un http qui renvoie vers https est suivi). Après 3 réessais sans
 * réponse, la fiche passe aux candidats.
 *
 * ── LE MARQUEUR (`companies.metadata.site_entreprise`) ───────────────────
 *
 *   - candidat prouvé : `{statut: trouve-verifie, url, preuve, origine:
 *     candidat, ancien: {url, statut, motif?, reessais?}, le, v}` et
 *     `companies.website` = le candidat. L'ANCIEN site deviné n'est jamais
 *     effacé : il reste dans `ancien` (`QuarantaineSite` s'en sert) ;
 *   - aucun candidat prouvé : le marqueur N6 est gardé, complété de
 *     `candidats: {essayes, le, v}` — la fiche reste SANS SITE VÉRIFIÉ ;
 *   - réessai : `reessais` (compteur) et `reessai_le` (date du dernier).
 *
 * Jamais remplacé : un site dont `field_origins` connaît la provenance
 * (saisi à la main, source déclarée, import) — un site deviné n'en a pas —
 * ni celui d'une fiche protégée (`FichesProtegees`).
 */
final class CandidatsSite
{
    /** Candidats lus au plus par fiche. */
    public const MAX = 6;

    /** Extensions essayées, dans l'ordre (`crm.sites_candidats.extensions` en test). */
    public const EXTENSIONS = ['fr', 'com'];

    public const REESSAIS_MAX = 3;

    public const INTERVALLE_JOURS = 3;

    /** `origine` du marqueur d'un site trouvé par candidat. */
    public const ORIGINE = 'candidat';

    /** Version des règles des candidats, écrite dans `candidats.v`. */
    public const VERSION = 1;

    /** Clé du curseur persistant (`curseurs_traitements.traitement`). */
    public const TRAITEMENT = 'entreprises:chercher-site-prouve';

    /** Mots du nom qui ne font pas un domaine : formes juridiques, articles. */
    public const MOTS_VIDES = [
        'sarl', 'sarlu', 'sas', 'sasu', 'sa', 'eurl', 'eirl', 'ei', 'snc', 'sci', 'scp', 'scm', 'scop', 'scea', 'sccv',
        'selarl', 'selas', 'selafa', 'sca', 'scs', 'earl', 'gaec', 'gie', 'sem', 'spl', 'ste', 'societe', 'cie',
        'et', 'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'au', 'aux', 'en', 'a',
    ];

    /** Longueur minimale d'une étiquette de domaine candidate (sigle compris). */
    private const ETIQUETTE_MIN = 3;

    /** Les mots d'un nom, normalisés pour un domaine (au plus 4). @return list<string> */
    public static function mots(?string $nom): array
    {
        $s = Str::lower(Str::ascii(str_replace('&', ' ', (string) $nom)));
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
        $mots = array_filter(
            explode(' ', $s),
            static fn (string $m): bool => strlen($m) >= 2 && ! in_array($m, self::MOTS_VIDES, true),
        );

        return array_values(array_slice($mots, 0, 4));
    }

    /**
     * Les domaines candidats d'une fiche (hôtes nus, sans schéma), au plus
     * `$max`, dans l'ordre où ils sont essayés.
     *
     * @param  list<string>|null  $extensions  défaut : `crm.sites_candidats.extensions`, sinon `EXTENSIONS`
     * @return list<string>
     */
    public static function domaines(?string $denomination, ?string $enseigne, ?string $sigle, ?string $siteActuel, ?array $extensions = null, int $max = self::MAX): array
    {
        $extensions ??= self::extensions();
        $noms = [];
        foreach ([$denomination, $enseigne] as $nom) {
            $mots = self::mots($nom);
            if ($mots !== []) {
                $noms[] = array_values(array_unique([implode('', $mots), implode('-', $mots)]));
            }
        }
        $s = (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii((string) $sigle)));
        if ($s !== '') {
            $noms[] = [$s];
        }
        $actuel = $siteActuel === null ? '' : (string) parse_url((string) LecturePageAccueil::cible($siteActuel), PHP_URL_HOST);
        $exclu = $actuel === '' ? '' : SiteMedia::domaineEnregistrable($actuel);

        $sortie = [];
        foreach ($extensions as $ext) {
            foreach ($noms as $formes) {
                foreach ($formes as $etiquette) {
                    if (strlen($etiquette) < self::ETIQUETTE_MIN || strlen($etiquette) > 63) {
                        continue;
                    }
                    $hote = $etiquette . '.' . $ext;
                    if ($hote === $exclu || in_array($hote, $sortie, true)) {
                        continue;
                    }
                    $sortie[] = $hote;
                    if (count($sortie) >= $max) {
                        return $sortie;
                    }
                }
            }
        }

        return $sortie;
    }

    /**
     * Les adresses essayées au réessai d'un site injoignable, au plus 3 :
     * l'adresse jugée par N6, puis https avec / sans `www.`, puis http (un
     * http qui renvoie vers https est suivi par le lecteur).
     *
     * @return list<string>
     */
    public static function variantesReessai(string $cible): array
    {
        $hote = strtolower((string) parse_url($cible, PHP_URL_HOST));
        if ($hote === '') {
            return [$cible];
        }
        $autre = str_starts_with($hote, 'www.') ? substr($hote, 4) : 'www.' . $hote;
        $variantes = array_values(array_unique([$cible, 'https://' . $hote . '/', 'https://' . $autre . '/', 'http://' . $hote . '/']));

        return array_slice($variantes, 0, 3);
    }

    /** Un réessai est-il dû ? Moins de 3 faits, et le dernier jugement date d'au moins 3 jours. */
    public static function reessaiDu(int $faits, ?string $dernier, CarbonInterface $aujourdhui): bool
    {
        if ($faits >= self::REESSAIS_MAX) {
            return false;
        }
        if ($dernier === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dernier) !== 1) {
            return true;
        }
        $echeance = Carbon::parse($dernier, VerificationSite::FUSEAU)->startOfDay()->addDays(self::INTERVALLE_JOURS);

        return $aujourdhui->copy()->setTimezone(VerificationSite::FUSEAU)->startOfDay()->greaterThanOrEqualTo($echeance);
    }

    /** Clé du curseur : une par périmètre (toutes les fiches, ou une audience). */
    public static function cleCurseur(?int $audience): string
    {
        return self::TRAITEMENT . ($audience === null ? '' : ':audience:' . $audience);
    }

    /**
     * La requête d'un paquet : fiches vivantes au site deviné NON VÉRIFIÉ
     * (`SiteFiable::nonVerifieSql`, mot pour mot : c'est le prédicat de
     * l'index partiel `idx_companies_site_non_verifie_id`, qui sert le
     * parcours dans l'ordre des identifiants), déjà jugées par N6
     * `non-conforme` ou `injoignable`, pas encore cherchées par candidats,
     * avec un SIREN et un site. Rend aussi ce qu'il faut pour construire les
     * candidats et décider sans relire la base : noms, marqueur en place,
     * provenance du site (`field_origins`), protection de la fiche.
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
        $m = "c.metadata -> '" . SiteFiable::CLE . "'";

        return [
            "SELECT c.id, c.siren, c.website, c.denomination, c.enseigne, c.metadata ->> 'sigle' AS sigle,
                    {$m} ->> 'statut' AS statut_avant,
                    {$m} ->> 'url' AS url_avant,
                    {$m} ->> 'motif' AS motif_avant,
                    {$m} ->> 'le' AS le_avant,
                    {$m} ->> 'reessais' AS reessais_avant,
                    {$m} ->> 'reessai_le' AS reessai_le_avant,
                    (c.field_origins -> 'website') IS NOT NULL AS site_de_source,
                    NOT " . FichesProtegees::conditionSql('c.id') . " AS protegee
               FROM companies c
              WHERE c.workspace_id = ?
                AND c.deleted_at IS NULL
                AND c.id > ?
                AND " . SiteFiable::nonVerifieSql('c') . "
                AND {$m} ->> 'statut' IN ('" . SiteMedia::NON_CONFORME . "', '" . SiteMedia::INJOIGNABLE . "')
                AND ({$m} -> 'candidats') IS NULL
                AND c.siren IS NOT NULL
                AND c.website IS NOT NULL AND btrim(c.website) <> ''{$membres}
              ORDER BY c.id
              LIMIT ?",
            $liaisons,
        ];
    }

    /** @return list<string> */
    private static function extensions(): array
    {
        $ext = config('crm.sites_candidats.extensions', self::EXTENSIONS);

        return is_array($ext) && $ext !== [] ? array_values(array_filter($ext, 'is_string')) : self::EXTENSIONS;
    }
}

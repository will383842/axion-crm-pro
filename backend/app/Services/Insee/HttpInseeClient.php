<?php

namespace App\Services\Insee;

use App\Contracts\InseeClient;
use App\Crm\Insee\FamillesInsee;
use App\Data\Sources\InseeCompanyData;
use App\Services\Http\SsrfGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * INSEE Sirene API V3.11 — `https://api.insee.fr/api-sirene/3.11`.
 *
 * Deux modes d'auth supportés selon le plan souscrit sur portail-api.insee.fr :
 *
 *  1. Plan "Accès public" (gratuit, 30 req/min) → API Key simple dans header
 *     `X-INSEE-Api-Key-Integration`. Configurable via `.env` :
 *        INSEE_API_KEY=<clé>
 *
 *  2. Plan "Accès authentifié" (gratuit, 500 req/min) → OAuth2 client_credentials.
 *        INSEE_CLIENT_ID=<consumer key>
 *        INSEE_CLIENT_SECRET=<consumer secret>
 *
 * Le client détecte automatiquement le mode selon les vars d'env présentes.
 */
class HttpInseeClient implements InseeClient
{
    private const BASE_URL = 'https://api.insee.fr/api-sirene/3.11';

    /**
     * Taille d'une page du flux des modifications. 500 et non le maximum
     * Sirene (1000) : incident mémoire du 03/10/2026 (128 Mo épuisés pendant
     * un rattrapage) — le pic d'une page (corps brut + décodage) est divisé
     * par deux, pour ≈ 14 000 unités par minute au quota public.
     */
    public const PAGE_SIRENE = 500;

    /**
     * Taille d'une page de l'import des familles (`iterateFamille`) : le
     * maximum Sirene. La requête ne rend que des unités ACTIVES de la
     * période en cours, restreintes aux `CHAMPS_UNITES` : la page est bien
     * plus légère que celle du flux des modifications, et une page trop
     * lourde est de toute façon redemandée plus petite.
     */
    public const PAGE_FAMILLE = 1000;

    /**
     * Curseurs du flux mémorisés pour détecter une pagination en boucle
     * (réserve 3 de #313) : les DERNIERS seulement — la mémoire reste
     * constante quelle que soit la longueur du flux. Une boucle plus longue
     * est arrêtée par le plafond de pages (`PAGES_MARGE`, `PAGES_MAX`).
     */
    public const CURSEURS_VUS = 64;

    /** Identifiants par requête groupée (`siren:… OR siren:…`) : URL ≈ 2 Ko. */
    public const PAR_REQUETE = 100;

    /**
     * Pages du flux, au plus, AU-DELÀ du total annoncé par Sirene
     * (`header.total`) — relecture sécurité #313, réserve 3. Sans total lu,
     * `PAGES_MAX` borne seul.
     */
    private const PAGES_MARGE = 5;

    /** Plafond ABSOLU de pages d'un flux (20 M d'unités) : la boucle s'arrête toujours. */
    public const PAGES_MAX = 20000;

    /**
     * Taille maximale d'une réponse Sirene, lue PAR MORCEAUX et jamais au-delà
     * (réserve 5 de #313, réserve 1 de #320) : le corps de 8 Mo et son
     * décodage (≈ 3 à 5 fois le JSON) tiennent ensemble dans la marge que
     * laisse la garde mémoire à 128 Mo. Au-delà, le flux redemande la MÊME
     * page plus petite (`PAGE_SIRENE_PLANCHER`) au lieu d'épuiser la mémoire.
     */
    public const REPONSE_MAX_OCTETS = 8 * 1024 * 1024;

    /**
     * Taille de page la plus petite du flux : une page encore trop lourde à
     * cette taille lève (passage `echouee`, curseur gardé, reprise possible).
     */
    public const PAGE_SIRENE_PLANCHER = 25;

    /** Pages légères d'affilée avant de redoubler une taille de page réduite. */
    public const PAGES_AVANT_REMONTEE = 10;

    /**
     * Les SEULS champs que lit `MiseAJourMensuelle` (paramètre `champs` de
     * Sirene 3.11) : chaque période de l'historique ne voyage plus qu'avec
     * eux. Toute lecture d'un nouveau champ d'unité doit l'ajouter ici.
     * Sirene ne sait pas rendre la seule période courante d'une recherche
     * (`periode(…)` dans `q` filtre les UNITÉS, pas les périodes rendues) :
     * la réduction à la période courante reste faite après décodage.
     */
    public const CHAMPS_UNITES = [
        'siren', 'statutDiffusionUniteLegale', 'etatAdministratifUniteLegale',
        'prenom1UniteLegale', 'trancheEffectifsUniteLegale', 'categorieEntreprise',
        'dateDebut', 'denominationUniteLegale', 'nomUniteLegale',
        'activitePrincipaleUniteLegale', 'categorieJuridiqueUniteLegale', 'nicSiegeUniteLegale',
    ];

    /** Faux si Sirene a refusé `champs` (400) : la suite se passe du paramètre. */
    private bool $champsAcceptes = true;

    private int $delaiMs = 2100;

    private ?float $derniereRequete = null;

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * C19-010 — L'OPPOSITION « NON DIFFUSIBLE » VAUT SUR LES TROIS VOIES.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Sirene v3.11 ne retire pas la fiche d'une unite opposee : il la rend avec
     * `statutDiffusionUniteLegale` a `'P'` (diffusion partielle) ou `'N'` (non
     * diffusible), et MASQUE les champs nominatifs par la chaine litterale
     * `[ND]`. C'est a l'appelant d'ecarter la fiche.
     *
     * Ce filtre existait depuis l'origine — mais sur UNE SEULE des trois voies
     * de ce client (la branche `/siret` de `iterateByCriteria()`). Les deux
     * autres (`fetchBySiren()` ici, et la branche `/siren`) rendaient l'unite
     * opposee telle quelle. Mesure du 2026-08-20 sur le banc, AVANT reparation :
     * `fetchBySiren()` sur une unite `statutDiffusionUniteLegale = 'N'` rendait
     * un `InseeCompanyData` porteur de `denomination = '[ND] [ND]'`, que
     * `WaterfallOrchestrator::step1_insee()` ECRIT sur `companies.denomination`
     * et que `ProspectionCollect` upserte en base ; et la voie `/siren` rendait
     * 3 unites sur 3 la ou 1 seule etait diffusible.
     *
     * Le correctif ne reecrit rien : il PORTE le filtre de la branche `/siret`
     * sur les deux voies qui l'ignoraient, via `estDiffusible()` ci-dessous.
     *
     * Garde : `tests/Feature/Rgpd/OppositionInseeNonDiffusibleTest.php`.
     */
    public function fetchBySiren(string $siren): ?InseeCompanyData
    {
        SsrfGuard::ensure(self::BASE_URL);

        $resp = $this->authHttp()
            ->timeout(15)
            ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException)
            ->get(self::BASE_URL . "/siren/{$siren}");

        if ($resp->status() === 404) {
            return null;
        }
        if ($resp->failed()) {
            throw new \RuntimeException("INSEE API error {$resp->status()}: " . $resp->body());
        }

        $u = $resp->json('uniteLegale', []);

        // C19-010 — opposition a diffusion : on rend `null`, exactement comme
        // pour un siren inconnu. Les deux appelants le traitent DEJA :
        // `WaterfallOrchestrator::step1_insee()` sort sans rien ecrire, et
        // `FranceTravailDiscoveryClient::filterActiveByInsee()` ecarte le
        // candidat. Aucun appelant n'avait donc besoin d'etre modifie.
        if (! self::estDiffusible(is_array($u) ? $u : [])) {
            return null;
        }

        $periodes = $u['periodesUniteLegale'][0] ?? [];

        return new InseeCompanyData(
            siren: $siren,
            denomination: $periodes['denominationUniteLegale']
                ?? trim(($periodes['prenom1UniteLegale'] ?? '') . ' ' . ($periodes['nomUniteLegale'] ?? '')),
            naf: $periodes['activitePrincipaleUniteLegale'] ?? null,
            legalForm: $periodes['categorieJuridiqueUniteLegale'] ?? null,
            effectifRange: $u['trancheEffectifsUniteLegale'] ?? null,
            createdAt: $u['dateCreationUniteLegale'] ?? null,
            raw: $u,
            etatAdministratif: $periodes['etatAdministratifUniteLegale']
                ?? $u['etatAdministratifUniteLegale']
                ?? null,
        );
    }

    public function searchByCriteria(array $criteria): array
    {
        $limit = (int) ($criteria['limit'] ?? 1000);
        $results = [];
        foreach ($this->iterateByCriteria($criteria) as $company) {
            $results[] = $company;
            if ($limit > 0 && count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Itère TOUTES les entreprises correspondant aux critères, en paginant par
     * curseur (générateur → pas de chargement total en mémoire). Permet de
     * récupérer un DÉPARTEMENT ENTIER avec sauvegarde au fil de l'eau
     * (cf. commande prospection:collect). Respecte le rate-limit INSEE.
     *
     * `$criteria['req_delay_ms']` : délai entre requêtes (défaut 2100ms ≈ 30 req/min,
     * plan « Accès public »). Baisser si plan « Accès authentifié » (500 req/min).
     *
     * @param  array<string,mixed>  $criteria
     * @return \Generator<int, InseeCompanyData>
     */
    public function iterateByCriteria(array $criteria): \Generator
    {
        // /siret (champs *Etablissement) si filtre géo, sinon /siren (*UniteLegale).
        $hasGeo = ! empty($criteria['department']) || ! empty($criteria['commune']);
        $endpoint = $hasGeo ? '/siret' : '/siren';
        $resultsKey = $hasGeo ? 'etablissements' : 'unitesLegales';

        $q = $this->buildQuery($criteria, $hasGeo);
        $cursor = '*';
        $pageSize = 1000; // max INSEE Sirene v3.11 → 10× moins de requêtes
        $delayMs = (int) ($criteria['req_delay_ms'] ?? 2100);
        // Entreprises commerciales seulement : garde EI (cat. jur. 1xxx) + sociétés
        // commerciales (5xxx : SARL, SAS, SA, SNC, SCA…). Exclut SCI (65xx),
        // associations (9xxx), administrations (7xxx), mutuelles/coopératives (6xxx).
        $commercialOnly = (bool) ($criteria['commercial_only'] ?? true);
        $seenSirens = []; // dédup : un dépt renvoie plusieurs siret pour le même siren
        $retries = 0;     // tentatives sur le curseur courant (429/5xx) — BORNÉ

        do {
            $resp = $this->authHttp()
                ->timeout(30)
                ->retry(2, 2000, fn ($e) => $e instanceof ConnectionException)
                ->get(self::BASE_URL . $endpoint, [
                    'q' => $q,
                    'curseur' => $cursor,
                    'nombre' => $pageSize,
                    'tri' => $hasGeo ? 'siret' : 'siren',
                ]);

            // Rate-limit atteint → attendre puis retenter le même curseur (BORNÉ :
            // évite une boucle infinie si le quota est saturé durablement).
            if ($resp->status() === 429) {
                if (++$retries > 30) {
                    throw new \RuntimeException('INSEE 429 persistant (quota/limite atteint ?) après 30 tentatives.');
                }
                sleep(20);

                continue;
            }
            // Erreur serveur transitoire (5xx) → petit backoff + retry borné.
            if ($resp->serverError()) {
                if (++$retries > 8) {
                    throw new \RuntimeException("INSEE {$resp->status()} persistant après 8 tentatives sur {$endpoint}.");
                }
                sleep(5);

                continue;
            }
            if ($resp->failed()) {
                throw new \RuntimeException(
                    "INSEE search error {$resp->status()} on {$endpoint} (q={$q}) — "
                    . mb_substr((string) $resp->body(), 0, 1000),
                );
            }
            $retries = 0; // page réussie → on remet le compteur à zéro
            $data = $resp->json();

            if ($hasGeo) {
                foreach ($data[$resultsKey] ?? [] as $etab) {
                    // Le périmètre de l'import (siège, actif, diffusible,
                    // société 5xxx) : `estDansPerimetreImport()`, partagé
                    // avec la mise à jour mensuelle (lot N8). Comportement de
                    // CETTE branche inchangé.
                    if (! is_array($etab) || ! self::estDansPerimetreImport($etab, $commercialOnly)) {
                        continue;
                    }
                    $siren = (string) ($etab['siren'] ?? (is_array($etab['uniteLegale'] ?? null) ? ($etab['uniteLegale']['siren'] ?? '') : ''));
                    if ($siren === '' || isset($seenSirens[$siren])) {
                        continue;
                    }
                    $seenSirens[$siren] = true;
                    yield self::donneesEtablissement($etab);
                }
            } else {
                foreach ($data[$resultsKey] ?? [] as $u) {
                    // C19-010 — LA VOIE QUI N'AVAIT AUCUN FILTRE.
                    // `prospection:collect` n'emprunte cette branche que sans
                    // critère géographique, mais `LaunchZoneScrapingJob` passe par
                    // `searchByCriteria()` qui délègue ici : une recherche par NAF
                    // seul ramenait TOUTES les unités opposées, dénomination
                    // « [ND] » comprise, jusqu'à l'upsert dans `companies`.
                    if (! self::estDiffusible(is_array($u) ? $u : [])) {
                        continue;
                    }
                    $periodes = $u['periodesUniteLegale'][0] ?? [];
                    yield new InseeCompanyData(
                        siren: (string) ($u['siren'] ?? ''),
                        denomination: $periodes['denominationUniteLegale'] ?? null,
                        naf: $periodes['activitePrincipaleUniteLegale'] ?? null,
                        legalForm: $periodes['categorieJuridiqueUniteLegale'] ?? null,
                        effectifRange: $u['trancheEffectifsUniteLegale'] ?? null,
                        etatAdministratif: $periodes['etatAdministratifUniteLegale']
                            ?? $u['etatAdministratifUniteLegale'] ?? null,
                    );
                }
            }

            $nextCursor = $data['header']['curseurSuivant'] ?? null;
            // Fin de pagination : INSEE renvoie le MÊME curseur (ou null/'*') sur la
            // DERNIÈRE page. SANS ce test → boucle infinie qui re-traite la dernière page.
            if ($nextCursor === null || $nextCursor === '' || $nextCursor === '*' || $nextCursor === $cursor) {
                break;
            }
            $cursor = $nextCursor;
            if ($delayMs > 0) {
                usleep($delayMs * 1000); // respecte le rate-limit avant la page suivante
            }
        } while (true);
    }

    /**
     * LE PÉRIMÈTRE DE L'IMPORT INITIAL (`prospection:collect`), écrit UNE fois
     * — la collecte par département et la mise à jour mensuelle (lot N8)
     * créent les MÊMES fiches.
     *
     * Un établissement de la voie `/siret` y entre s'il est : le SIÈGE ; d'une
     * unité ACTIVE (`A`) ; DIFFUSIBLE (unité ET établissement, C19-010) ; et,
     * par défaut, d'une SOCIÉTÉ commerciale (catégorie juridique 5xxx : SARL,
     * SAS, SA, SNC, SCA…) — ni entrepreneur individuel (1xxx), ni SCI (65xx),
     * ni association (9xxx), ni administration (7xxx).
     *
     * @param  array<string, mixed>  $etab  un élément de `etablissements`
     */
    public static function estDansPerimetreImport(array $etab, bool $commercialOnly = true): bool
    {
        // Sièges seulement (Sirene v3.11 refuse ce filtre dans `q`).
        if (! ($etab['etablissementSiege'] ?? false)) {
            return false;
        }
        $u = is_array($etab['uniteLegale'] ?? null) ? $etab['uniteLegale'] : [];
        if (($u['etatAdministratifUniteLegale'] ?? null) !== 'A') {
            return false;
        }
        // Diffusibles seulement (RGPD) : exclut les « [ND] » — personnes qui
        // ont refusé la diffusion publique de leurs données INSEE.
        if (! self::estDiffusible($u)) {
            return false;
        }
        // C19-010, RENFORT : l'ÉTABLISSEMENT porte son propre statut de
        // diffusion (`statutDiffusionEtablissement`, mêmes valeurs O/P/N).
        // Même défaut `'O'` : une réponse qui ne porte pas le champ reste
        // collectée (témoin dédié). Lu par le même `estDiffusible()`.
        if (! self::estDiffusible($etab)) {
            return false;
        }
        if ($commercialOnly) {
            $periodes = is_array($u['periodesUniteLegale'][0] ?? null) ? $u['periodesUniteLegale'][0] : $u;
            $cj = (string) ($periodes['categorieJuridiqueUniteLegale'] ?? $u['categorieJuridiqueUniteLegale'] ?? '');
            if ($cj === '' || $cj[0] !== '5') {
                return false;
            }
        }

        return true;
    }

    /**
     * LE PÉRIMÈTRE DES CRÉATIONS PAR FAMILLE (décision du 04/10/2026) : le
     * périmètre de l'import ci-dessus SANS sa restriction aux sociétés 5xxx
     * (siège, actif, diffusible — unité ET établissement), puis la famille
     * de la catégorie juridique et, pour 6 et 9, des salariés
     * (`FamillesInsee::admise`). Jamais la famille 1. Sert l'import des
     * familles et les créations de la mise à jour mensuelle ;
     * `prospection:collect` garde `estDansPerimetreImport()` inchangé.
     *
     * @param  array<string, mixed>  $etab  un élément de `etablissements`
     * @param  ?string  $famille  imposée (import d'une famille), ou null : toutes
     */
    public static function estDansPerimetreFamilles(array $etab, ?string $famille = null): bool
    {
        if (! self::estDansPerimetreImport($etab, false)) {
            return false;
        }
        $u = is_array($etab['uniteLegale'] ?? null) ? $etab['uniteLegale'] : [];
        $periodes = is_array($u['periodesUniteLegale'][0] ?? null) ? $u['periodesUniteLegale'][0] : $u;

        return FamillesInsee::admise(
            $periodes['categorieJuridiqueUniteLegale'] ?? $u['categorieJuridiqueUniteLegale'] ?? null,
            $u['trancheEffectifsUniteLegale'] ?? null,
            $famille,
        );
    }

    /**
     * Un établissement (siège) de la voie `/siret`, mis en forme pour la
     * collecte — adresse comprise, disponible dès la récupération INSEE.
     *
     * @param  array<string, mixed>  $etab
     */
    public static function donneesEtablissement(array $etab): InseeCompanyData
    {
        $u = is_array($etab['uniteLegale'] ?? null) ? $etab['uniteLegale'] : [];
        $periodes = is_array($u['periodesUniteLegale'][0] ?? null) ? $u['periodesUniteLegale'][0] : $u;
        $adr = is_array($etab['adresseEtablissement'] ?? null) ? $etab['adresseEtablissement'] : [];
        $rue = trim(implode(' ', array_filter([
            $adr['numeroVoieEtablissement'] ?? '',
            $adr['typeVoieEtablissement'] ?? '',
            $adr['libelleVoieEtablissement'] ?? '',
        ])));

        return new InseeCompanyData(
            siren: (string) ($etab['siren'] ?? $u['siren'] ?? ''),
            denomination: $periodes['denominationUniteLegale']
                ?? trim(($periodes['prenom1UniteLegale'] ?? '') . ' ' . ($periodes['nomUniteLegale'] ?? '')),
            naf: $periodes['activitePrincipaleUniteLegale'] ?? null,
            legalForm: $periodes['categorieJuridiqueUniteLegale'] ?? null,
            effectifRange: $u['trancheEffectifsUniteLegale'] ?? null,
            address: $rue !== '' ? $rue : null,
            postcode: $adr['codePostalEtablissement'] ?? null,
            city: $adr['libelleCommuneEtablissement'] ?? null,
            insee: $adr['codeCommuneEtablissement'] ?? null,
            createdAt: $u['dateCreationUniteLegale'] ?? null,
            raw: $etab,
            etatAdministratif: $u['etatAdministratifUniteLegale']
                ?? $periodes['etatAdministratifUniteLegale'] ?? null,
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * LOT N8 — LE FLUX DES MODIFICATIONS SIRENE (`crm:insee:mise-a-jour-mensuelle`)
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Toutes les unités légales dont `dateDernierTraitementUniteLegale` est
     * postérieure ou égale à `$depuis` : créations, modifications, fermetures,
     * passages en non diffusible. AUCUN filtre de diffusion ni d'état : c'est
     * précisément ce qu'il faut voir pour MARQUER une fiche fermée ou opposée.
     * Les unités rendues sont BRUTES (les `[ND]` compris) : l'appelant ne doit
     * jamais en recopier un champ nominatif — `MiseAJourMensuelle` ne lit que
     * le statut d'une unité non diffusible.
     *
     * Pagination par CURSEUR Sirene (`curseur=*`, puis `header.curseurSuivant`
     * jusqu'à ce qu'il se répète). Chaque page rend son propre curseur : un
     * appelant qui le mémorise reprend exactement là (reprise après coupure).
     * Le quota est respecté par `avecDelaiEntreRequetes()` (≈ 30 req/min par
     * défaut, plan « Accès public »).
     *
     * MÉMOIRE CONSTANTE (incident du 03/10/2026) : une seule page vit à la
     * fois — la réponse décodée est libérée avant le `yield`, la page rendue
     * est vidée dès la reprise (`PageSirene::liberer`), chaque unité ne garde
     * que sa période COURANTE (la seule lue par l'appelant) et seuls les
     * `CURSEURS_VUS` derniers curseurs sont retenus.
     *
     * @param  string  $depuis  date AAAA-MM-JJ
     * @return \Generator<int, PageSirene>
     */
    public function iterateModificationsDepuis(string $depuis, string $curseur = '*'): \Generator
    {
        if (! self::estDateIso($depuis)) {
            throw new \InvalidArgumentException("Date Sirene invalide : « {$depuis} » (attendu AAAA-MM-JJ).");
        }

        yield from $this->fluxUnites('dateDernierTraitementUniteLegale:[' . $depuis . ' TO *]', $curseur, self::PAGE_SIRENE);
    }

    /**
     * IMPORT DES FAMILLES (`crm:insee:importer-familles`, 04/10/2026) : les
     * unités légales ACTIVES et DIFFUSIBLES d'une famille de catégories
     * juridiques (`FamillesInsee::requete`), par pages de `PAGE_FAMILLE`,
     * curseur Sirene — même générateur que le flux des modifications : une
     * page à la fois, période courante seule, taille réduite d'elle-même sur
     * une page trop lourde, curseurs en boucle et pages en trop arrêtés.
     *
     * @return \Generator<int, PageSirene>
     */
    public function iterateFamille(string $famille, string $curseur = '*'): \Generator
    {
        yield from $this->fluxUnites(FamillesInsee::requete($famille), $curseur, self::PAGE_FAMILLE);
    }

    /**
     * Le flux paginé d'une recherche `/siren` (`$q`), à partir de `$curseur`,
     * par pages de `$taille` au plus.
     *
     * @return \Generator<int, PageSirene>
     */
    private function fluxUnites(string $q, string $curseur, int $taille): \Generator
    {
        // Réserve 3 (#313) : la fin ne dépend plus du seul curseur répété.
        // Un curseur DÉJÀ VU (A→B→A…) ou un nombre de pages au-delà du total
        // annoncé lèvent : le passage reste « echouee », visible, au lieu de
        // boucler — même lancé à la main sans `--duree-max`.
        /** @var array<string, true> $vus les `CURSEURS_VUS` derniers, dans l'ordre */
        $vus = [$curseur => true];
        $plafond = self::PAGES_MAX;
        $total = null;
        $pages = 0;
        // Taille de page COURANTE : réduite (÷ 2, jusqu'au plancher) quand
        // une page dépasse `REPONSE_MAX_OCTETS`, redoublée après
        // `PAGES_AVANT_REMONTEE` pages légères. Le curseur désigne une
        // POSITION du flux : redemander le même curseur plus petit ne perd
        // ni ne répète aucune unité.
        $nombre = $taille;
        $legeres = 0;

        while (true) {
            if (++$pages > $plafond) {
                throw new \RuntimeException("Flux Sirene : plus de {$plafond} pages lues — arrêt (curseurs incohérents ?).");
            }
            try {
                $data = $this->appelUnites([
                    'q' => $q,
                    'curseur' => $curseur,
                    'nombre' => $nombre,
                    'tri' => 'siren',
                    // Les champs nuls ne voyagent pas : l'appelant lit tout par
                    // `?? null`, une valeur absente vaut une valeur nulle.
                    'masquerValeursNulles' => 'true',
                ]);
            } catch (InseeErreurHttp $e) {
                if (! $e->tropVolumineuse || $nombre <= self::PAGE_SIRENE_PLANCHER) {
                    throw $e;
                }
                // Page trop lourde (unités à long historique) : la MÊME page,
                // deux fois plus petite. Fini : ÷ 2 jusqu'au plancher, puis lève.
                $nombre = max(self::PAGE_SIRENE_PLANCHER, intdiv($nombre, 2));
                $legeres = 0;
                $pages--;
                if (is_int($total)) {
                    $plafond = self::plafondDePages($total, $nombre);
                }
                Log::info('[INSEE] flux Sirene : page trop volumineuse, redemandée plus petite', ['nombre' => $nombre]);

                continue;
            }
            $unites = [];
            foreach (is_array($data['unitesLegales'] ?? null) ? $data['unitesLegales'] : [] as $u) {
                if (is_array($u)) {
                    $unites[] = self::periodeCouranteSeulement($u);
                }
            }
            $suivant = $data['header']['curseurSuivant'] ?? null;
            $annonce = $data['header']['total'] ?? null;
            if ($pages === 1 && is_int($annonce) && $annonce >= 0) {
                $total = $annonce;
                $plafond = self::plafondDePages($total, $nombre);
            }
            unset($data);
            if ($nombre < $taille && ++$legeres >= self::PAGES_AVANT_REMONTEE) {
                $nombre = min($taille, $nombre * 2);
                $legeres = 0;
            }
            // Fin : Sirene rend le MÊME curseur (ou rien) sur la dernière page.
            if (! is_string($suivant) || $suivant === '' || $suivant === '*' || $suivant === $curseur) {
                yield new PageSirene($curseur, null, $unites);

                return;
            }
            if (isset($vus[$suivant])) {
                throw new \RuntimeException('Flux Sirene : curseur déjà vu — arrêt (pagination en boucle).');
            }
            $vus[$suivant] = true;
            if (count($vus) > self::CURSEURS_VUS) {
                unset($vus[array_key_first($vus)]);
            }

            $page = new PageSirene($curseur, $suivant, $unites);
            unset($unites);
            yield $page;
            // Le générateur garde la page rendue jusqu'au `yield` suivant :
            // on la VIDE avant de lire la suivante (une page à la fois).
            $page->liberer();
            unset($page);

            $curseur = $suivant;
        }
    }

    /**
     * Plafond de pages d'un flux de `$total` unités lues par pages de
     * `$nombre` au moins (réserve 3 de #313) : une taille réduite RELÈVE le
     * plafond, sans jamais dépasser `PAGES_MAX`.
     */
    private static function plafondDePages(int $total, int $nombre): int
    {
        return min(self::PAGES_MAX, intdiv($total, max(1, $nombre)) + 1 + self::PAGES_MARGE);
    }

    /**
     * Une requête `/siren` du lot N8 restreinte aux `CHAMPS_UNITES`. Si Sirene
     * refuse le paramètre (400 : nom de champ inconnu d'une future version),
     * la requête est refaite UNE fois sans lui, et la suite s'en passe : le
     * flux reste juste, seulement plus lourd.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function appelUnites(array $params): array
    {
        if ($this->champsAcceptes) {
            try {
                return $this->appelSirene('/siren', $params + ['champs' => implode(',', self::CHAMPS_UNITES)]);
            } catch (InseeErreurHttp $e) {
                if ($e->statut !== 400) {
                    throw $e;
                }
                $this->champsAcceptes = false;
                Log::warning('[INSEE] Sirene refuse le paramètre « champs » : requêtes complètes à la place');
            }
        }

        return $this->appelSirene('/siren', $params);
    }

    /**
     * Une unité du flux, réduite à sa période COURANTE (`periodesUniteLegale[0]`,
     * la seule que lit `MiseAJourMensuelle`) : l'historique complet d'une
     * unité ancienne compte des dizaines de périodes, inutiles ici.
     *
     * @param  array<string, mixed>  $u
     * @return array<string, mixed>
     */
    private static function periodeCouranteSeulement(array $u): array
    {
        if (is_array($u['periodesUniteLegale'] ?? null) && count($u['periodesUniteLegale']) > 1) {
            $u['periodesUniteLegale'] = [$u['periodesUniteLegale'][0] ?? []];
        }

        return $u;
    }

    /**
     * Les unités légales de SIREN donnés, BRUTES (sans filtre de diffusion ni
     * d'état, comme le flux ci-dessus) — une requête par paquet de
     * `PAR_REQUETE` SIREN. Sert la passe prioritaire de la mise à jour
     * mensuelle (fiches de provenance tiers).
     *
     * @param  list<string>  $sirens
     * @return list<array<string, mixed>>
     */
    public function unitesParSiren(array $sirens): array
    {
        $unites = [];
        foreach (array_chunk(self::identifiants($sirens, 9), self::PAR_REQUETE) as $paquet) {
            $data = $this->appelUnites([
                'q' => implode(' OR ', array_map(static fn (string $s): string => 'siren:' . $s, $paquet)),
                'nombre' => count($paquet),
            ]);
            foreach (is_array($data['unitesLegales'] ?? null) ? $data['unitesLegales'] : [] as $u) {
                if (is_array($u)) {
                    $unites[] = self::periodeCouranteSeulement($u);
                }
            }
            unset($data);
        }

        return $unites;
    }

    /**
     * Les établissements de SIRET donnés (voie `/siret`, adresse comprise),
     * indexés par SIRET — une requête par paquet de `PAR_REQUETE`. BRUTS :
     * l'appelant applique `estDansPerimetreImport()`.
     *
     * @param  list<string>  $sirets
     * @return array<string, array<string, mixed>>
     */
    public function etablissementsParSiret(array $sirets): array
    {
        $etabs = [];
        foreach (array_chunk(self::identifiants($sirets, 14), self::PAR_REQUETE) as $paquet) {
            $data = $this->appelSirene('/siret', [
                'q' => implode(' OR ', array_map(static fn (string $s): string => 'siret:' . $s, $paquet)),
                'nombre' => count($paquet),
            ]);
            foreach (is_array($data['etablissements'] ?? null) ? $data['etablissements'] : [] as $e) {
                if (is_array($e) && is_string($e['siret'] ?? null)) {
                    $etabs[$e['siret']] = $e;
                }
            }
            unset($data);
        }

        return $etabs;
    }

    /**
     * Délai minimal entre deux requêtes des méthodes du lot N8 (défaut
     * 2 100 ms ≈ 28 req/min, sous le plafond de 30 du plan « Accès public »).
     */
    public function avecDelaiEntreRequetes(int $millisecondes): static
    {
        $this->delaiMs = max(0, $millisecondes);

        return $this;
    }

    /**
     * Une requête Sirene, quota respecté, 429 et 5xx retentés (BORNÉ), 404 =
     * « aucun résultat » (Sirene 3.11 répond 404 à une recherche vide) —
     * SEULEMENT si le corps est bien celui de Sirene (`header`) : un 404 d'un
     * autre serveur (mauvais chemin, passerelle) lève (avis exactitude R10).
     * Les erreurs ne portent que le statut et le chemin (`InseeErreurHttp`),
     * et une réponse trop lourde est refusée AVANT d'être décodée.
     *
     * @param  array<string, scalar>  $params
     * @return array<string, mixed>
     */
    private function appelSirene(string $chemin, array $params): array
    {
        SsrfGuard::ensure(self::BASE_URL);
        $tentatives = 0;

        while (true) {
            $this->respecterQuota();
            $resp = $this->authHttp()
                ->timeout(30)
                ->retry(2, 2000, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get(self::BASE_URL . $chemin, $params);

            if ($resp->status() === 404) {
                $data = $this->decoder($resp, $chemin);
                if (is_array($data['header'] ?? null)) {
                    return [];
                }
                throw new InseeErreurHttp(404, $chemin, '(réponse qui n est pas celle de Sirene)');
            }
            if ($resp->status() === 429) {
                if (++$tentatives > 30) {
                    throw new InseeErreurHttp(429, $chemin, '(persistant après 30 tentatives, quota atteint ?)');
                }
                Sleep::for(20)->seconds();

                continue;
            }
            if ($resp->serverError()) {
                if (++$tentatives > 8) {
                    throw new InseeErreurHttp($resp->status(), $chemin, '(persistant après 8 tentatives)');
                }
                Sleep::for(5)->seconds();

                continue;
            }
            if ($resp->failed()) {
                // Statut et chemin seulement : jamais le corps (réserve 7).
                throw new InseeErreurHttp($resp->status(), $chemin);
            }

            return $this->decoder($resp, $chemin);
        }
    }

    /**
     * Le corps JSON d'une réponse, sa taille BORNÉE avant décodage (réserve 5
     * de #313, réserve 1 de #320) : `Content-Length` d'abord, puis le corps
     * lu PAR MORCEAUX et jamais au-delà de `REPONSE_MAX_OCTETS` — une réponse
     * sans longueur annoncée ne peut plus remplir la mémoire. Le corps est lu
     * UNE fois (incident mémoire du 03/10/2026).
     *
     * @return array<string, mixed>
     */
    private function decoder(Response $resp, string $chemin): array
    {
        $annonce = $resp->header('Content-Length');
        if ($annonce !== '' && is_numeric($annonce) && (int) $annonce > self::REPONSE_MAX_OCTETS) {
            throw new InseeErreurHttp($resp->status(), $chemin, '(réponse trop volumineuse)', tropVolumineuse: true);
        }
        $flux = $resp->toPsrResponse()->getBody();
        if ($flux->isSeekable()) {
            $flux->rewind();
        }
        $corps = '';
        while (! $flux->eof()) {
            $morceau = $flux->read(65536);
            if ($morceau === '') {
                break;
            }
            $corps .= $morceau;
            if (strlen($corps) > self::REPONSE_MAX_OCTETS) {
                unset($corps, $morceau);
                throw new InseeErreurHttp($resp->status(), $chemin, '(réponse trop volumineuse)', tropVolumineuse: true);
            }
        }
        unset($morceau);
        $data = json_decode($corps, true);
        unset($corps);

        return is_array($data) ? $data : [];
    }

    private function respecterQuota(): void
    {
        if ($this->delaiMs > 0 && $this->derniereRequete !== null) {
            $resteUs = $this->delaiMs * 1000 - (int) ((microtime(true) - $this->derniereRequete) * 1_000_000);
            if ($resteUs > 0) {
                Sleep::usleep($resteUs);
            }
        }
        $this->derniereRequete = microtime(true);
    }

    /**
     * @param  list<string>  $valeurs
     * @return list<string> les identifiants de `$longueur` chiffres, dédoublonnés
     */
    private static function identifiants(array $valeurs, int $longueur): array
    {
        $propres = [];
        foreach ($valeurs as $v) {
            $v = trim($v);
            if (preg_match('/^\d{' . $longueur . '}$/', $v) === 1) {
                $propres[$v] = true;
            }
        }

        return array_keys($propres);
    }

    public static function estDateIso(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * C19-010 — LE SEUL ENDROIT OÙ SE DÉCIDE « CETTE PERSONNE A-T-ELLE DIT NON ».
     *
     * `statutDiffusionUniteLegale`, Sirene v3.11 :
     *   - `'O'` : diffusible → on collecte.
     *   - `'P'` : diffusion PARTIELLE (introduite en 2023 pour les personnes
     *             physiques) → les champs nominatifs sont masqués `[ND]`.
     *   - `'N'` : non diffusible → tout est masqué.
     *
     * Le test s'écrit `!== 'O'` et NON `=== 'N'` : le rétrécir en `=== 'N'`
     * laisserait passer toutes les diffusions partielles. La garde
     * `OppositionInseeNonDiffusibleTest` verrouille les deux cas.
     *
     * Le défaut est `'O'` : une réponse qui ne porte pas le champ (rejeu, mock,
     * source tierce) reste collectée. Faire l'inverse rendrait la collecte
     * muette sur un détail de forme — un témoin dédié fixe ce choix.
     *
     * Lit AUSSI `statutDiffusionEtablissement` quand le bloc le porte
     * (établissement de la voie `/siret`, ou unité à laquelle l'appelant a
     * joint le statut de son siège — `MiseAJourMensuelle`, relecture #313
     * réserve 6) : l'opposition vaut aux deux niveaux. Même défaut `'O'`.
     *
     * @param  array<string, mixed>  $bloc  le bloc `uniteLegale` ou un établissement de la réponse INSEE
     */
    public static function estDiffusible(array $bloc): bool
    {
        return ($bloc['statutDiffusionUniteLegale'] ?? 'O') === 'O'
            && ($bloc['statutDiffusionEtablissement'] ?? 'O') === 'O';
    }

    /**
     * Construit un client HTTP authentifié selon le mode disponible (API Key prioritaire).
     */
    private function authHttp(): PendingRequest
    {
        $apiKey = (string) env('INSEE_API_KEY', '');
        if ($apiKey !== '') {
            return Http::withHeaders(['X-INSEE-Api-Key-Integration' => $apiKey]);
        }
        // Fallback OAuth2 (plan authentifié, 500 req/min)
        $token = $this->getOAuthToken();

        return Http::withToken($token);
    }

    /**
     * Construit la query Lucene INSEE Sirene v3.11.
     *
     * @param  array<string,mixed>  $criteria
     * @param  bool  $forSiretEndpoint  true si endpoint /siret (champs *Etablissement),
     *                                  false si /siren (champs *UniteLegale)
     */
    private function buildQuery(array $criteria, bool $forSiretEndpoint = false): string
    {
        $parts = [];

        // NAF — champ different selon endpoint
        if (! empty($criteria['naf'])) {
            if ($forSiretEndpoint) {
                $parts[] = 'activitePrincipaleEtablissement:"' . $criteria['naf'] . '"';
            } else {
                $parts[] = 'periode(activitePrincipaleUniteLegale:"' . $criteria['naf'] . '")';
            }
        }

        // Effectif — uniqueLegale uniquement (les établissements n'ont pas de tranche effectif propre)
        if (! empty($criteria['effectif_min']) || ! empty($criteria['effectif_max'])) {
            $parts[] = 'trancheEffectifsUniteLegale:[' . ($criteria['effectif_min'] ?? '01') . ' TO ' . ($criteria['effectif_max'] ?? '53') . ']';
        }

        // Sociétés commerciales seulement (cat. jur. 5xxx) — filtré DÈS la requête INSEE
        // → ~4× moins de fiches à paginer (ex. Isère 797k → 199k). La forme juridique
        // précise est revérifiée côté PHP (filtre $commercialOnly).
        if (($criteria['commercial_only'] ?? true) && $forSiretEndpoint) {
            $parts[] = 'categorieJuridiqueUniteLegale:5*';
        }

        // Département — INSEE Sirene v3.11 n'a PAS de champ codeDepartementEtablissement.
        // Il faut filtrer via codeCommuneEtablissement avec wildcard préfixe.
        // Codes commune INSEE = 5 chars :
        //   - métropole : 2 chiffres dept + 3 chiffres commune  (Paris = 75001..75056)
        //   - DROM      : 3 chiffres dept + 2 chiffres commune  (Mayotte 976)
        // IMPORTANT : etablissementSiege + etatAdministratifEtablissement NE SONT PAS
        // autorisés dans le param `q` de Sirene v3.11 (testé via curl : HTTP 400).
        // Ils sont filtrés côté PHP après réception dans searchByCriteria().
        if (! empty($criteria['department']) && $forSiretEndpoint) {
            $dept = preg_replace('/[^0-9A-Za-z]/', '', (string) $criteria['department']);
            // Corse : codes 2A/2B → INSEE indexe en 2A/2B donc on garde tel quel
            $parts[] = 'codeCommuneEtablissement:' . $dept . '*';
        }

        // Commune (code INSEE 5 chars exact) — endpoint /siret
        if (! empty($criteria['commune']) && $forSiretEndpoint) {
            $commune = preg_replace('/[^0-9A-Za-z]/', '', (string) $criteria['commune']);
            $parts[] = 'codeCommuneEtablissement:' . $commune;
        }

        return implode(' AND ', $parts) ?: '*';
    }

    private function getOAuthToken(): string
    {
        return Cache::remember('insee:token', 3500, function () {
            $client = (string) env('INSEE_CLIENT_ID', '');
            $secret = (string) env('INSEE_CLIENT_SECRET', '');
            if ($client === '' || $secret === '') {
                throw new \LogicException(
                    'INSEE auth requires either INSEE_API_KEY (plan public) ' .
                    'or INSEE_CLIENT_ID + INSEE_CLIENT_SECRET (plan authentifié).',
                );
            }
            $resp = Http::withBasicAuth($client, $secret)
                ->asForm()
                ->timeout(15)
                ->post('https://api.insee.fr/token', ['grant_type' => 'client_credentials']);
            if ($resp->failed()) {
                throw new \RuntimeException('INSEE OAuth error: ' . $resp->status());
            }

            return (string) $resp->json('access_token');
        });
    }
}

<?php

namespace App\Console\Commands;

use App\Crm\Brave\QuotaBrave;
use App\Crm\Brave\RechercheBrave;
use App\Crm\Federations\AppartenanceSite;
use App\Crm\FichesProtegees;
use App\Services\Domain\DomainFinderService;
use App\Services\Http\SsrfGuard;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * LE SITE WEB DES FÉDÉRATIONS SANS CONTACT — par Brave, pour zéro euro.
 *
 * Décisions de Will (29/09) : trouver le site des organisations
 * professionnelles importées par `crm:import-federations` (fiches protégées
 * `src:scraping-federations-2026`) qui n'ont aucun contact, avec le SEUL
 * crédit gratuit mensuel de l'API Brave Search. Plafond STRICT
 * (`CRM_BRAVE_QUOTA_MENSUEL`, 900 par défaut) : `QuotaBrave` réserve chaque
 * requête avant de l'envoyer, la requête N+1 ne part jamais.
 *
 * ── QUI EST CHERCHÉ, DANS QUEL ORDRE ──────────────────────────────────────
 *
 * Une ligne `federations` de l'espace business (celui de l'import), sur une
 * fiche vivante portant le tag protégé, SANS site (`website` vide), dont la
 * contactabilité est `aucun_contact` ou `site_ou_linkedin_seulement`, de
 * niveau national, régional ou départemental (le niveau `local` n'est pas
 * dans le périmètre décidé). Ordre :
 *   1. national, puis régional, puis départemental ;
 *   2. à niveau égal, les organismes AVEC salariés d'abord (tranche INSEE
 *      `effectif_range` renseignée, ni `NN` ni `00`) ;
 *   3. puis l'identifiant, pour un ordre stable.
 *
 * ── CE QUE LA COMMANDE ÉCRIT, ET RIEN D'AUTRE ─────────────────────────────
 *
 * `companies.website`, `website_status`, `website_method =
 * 'brave-federations'` et `website_checked_at`. Rien d'autre sur la fiche
 * (`updated_at` compris : `app.conserver_updated_at`), aucune ligne
 * `contacts`, `company_tag` ni `federations`. Seule conséquence en base :
 * `quality_score`, recalculé par son déclencheur quand `website` change —
 * c'est une colonne dérivée, pas une donnée. L'`UPDATE` exige encore, au
 * moment d'écrire, une fiche sans site, protégée et fédération : une fiche
 * qui a reçu un site entre-temps n'est pas écrasée.
 *
 * Un site n'est retenu que si sa page d'accueil passe `AppartenanceSite`
 * (SIREN, ou nom distinctif ET sigle ; département pour une section
 * départementale), et s'il n'est pas déjà le site d'une AUTRE fédération de
 * l'espace — c'est, presque toujours, celui de la tête de réseau.
 *
 * ── REPRISE ──────────────────────────────────────────────────────────────
 *
 * Une fiche cherchée est marquée (`website_method = 'brave-federations'`,
 * `found` ou `not_found`) : le passage suivant la saute, et une exécution
 * interrompue reprend où elle en était. Une requête en ERREUR (délai, 5xx)
 * ne marque pas la fiche — elle est comptée au quota, et reprise au passage
 * suivant. `--reessayer` reprend aussi les `not_found` déjà cherchés.
 *
 * ── JOURNAL ──────────────────────────────────────────────────────────────
 *
 * Des COMPTEURS seulement, à l'écran comme au journal : ni nom d'organisme,
 * ni URL, ni la clé (le dépôt et les journaux de workflow sont publics).
 */
class CrmFederationsTrouverSites extends Command
{
    public const SIGNATURE_PLANIFIEE = 'crm:federations:trouver-sites';

    public const METHODE = 'brave-federations';

    protected $signature = self::SIGNATURE_PLANIFIEE . '
                            {--dry-run : Compter les fiches ciblées et le quota restant, sans AUCUNE requête Brave ni écriture}
                            {--limite=0 : Au plus N fiches (donc N requêtes Brave) ; 0 = jusqu\'au plafond du mois}
                            {--reessayer : Reprendre aussi les fiches déjà cherchées sans succès (not_found)}
                            {--pause-ms=1100 : Pause entre deux requêtes Brave (offre gratuite : 1 requête par seconde)}';

    protected $description = 'Cherche par Brave (crédit gratuit, plafond mensuel strict) le site web des fédérations sans contact.';

    /** Contactabilités visées (fiches sans moyen de joindre l'organisme par e-mail ni téléphone). */
    public const CONTACTABILITES = ['aucun_contact', 'site_ou_linkedin_seulement'];

    /** Niveaux visés, dans l'ordre de priorité. */
    public const NIVEAUX = ['national', 'regional', 'departemental'];

    /** Résultats Brave vérifiés au plus par fiche (une page d'accueil chacun). */
    private const CANDIDATS_PAR_FICHE = 3;

    private const LOT = 50;

    /** Verrou consultatif : deux passages simultanés dépenseraient deux fois le crédit sur les mêmes fiches. */
    private const VERROU = 'crm:federations:trouver-sites';

    /**
     * Hôtes jamais retenus : réseaux sociaux, annuaires, agrégateurs, moteurs.
     * Un hôte est exclu s'il EST l'un d'eux ou en est un sous-domaine.
     *
     * @var list<string>
     */
    public const HOTES_EXCLUS = [
        // Réseaux sociaux et encyclopédies
        'linkedin.com', 'facebook.com', 'twitter.com', 'x.com', 'youtube.com', 'instagram.com',
        'tiktok.com', 'pinterest.com', 'wikipedia.org', 'wikidata.org', 'viadeo.com', 'threads.net',
        // Annuaires d'entreprises et d'associations
        'societe.com', 'verif.com', 'pappers.fr', 'manageo.fr', 'infogreffe.fr', 'pagesjaunes.fr',
        'annuaire-entreprises.data.gouv.fr', 'data.gouv.fr', 'journal-officiel.gouv.fr', 'kompass.com',
        'corporama.com', 'societeinfo.com', 'infonet.fr', 'entreprises.lefigaro.fr', 'dirigeants.bfmtv.com',
        'net1901.org', 'helloasso.com', 'europages.fr', 'cylex-france.fr', '118712.fr', '118000.fr',
        'mappy.com', 'yelp.fr', 'hoodspot.fr', 'justacote.com', 'lagazettefrance.fr', 'score3.fr',
        'b-reputation.com', 'bilansgratuits.fr', 'entreprise.data.gouv.fr', 'annuaire.laposte.fr',
        // Emploi et agrégateurs
        'indeed.com', 'indeed.fr', 'hellowork.com', 'welcometothejungle.com', 'glassdoor.fr',
        // Moteurs
        'google.com', 'google.fr', 'bing.com', 'duckduckgo.com', 'brave.com', 'qwant.com', 'yahoo.com',
    ];

    /** @var array<string, int> */
    private array $compteurs = [];

    public function handle(QuotaBrave $quota, RechercheBrave $brave, AppartenanceSite $appartenance, DomainFinderService $finder): int
    {
        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $espace = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($espace === null) {
            $this->error('Espace business introuvable (crm.ingest.business_workspace).');

            return self::FAILURE;
        }
        $espace = (string) $espace;

        $dryRun = (bool) $this->option('dry-run');
        $limite = max(0, (int) $this->option('limite'));
        $reessayer = (bool) $this->option('reessayer');
        $pauseMs = max(0, (int) $this->option('pause-ms'));

        if ($dryRun) {
            return $this->essaiABlanc($espace, $quota, $limite, $reessayer);
        }

        if (! $brave->cleConfiguree()) {
            $this->error('Clé Brave absente (BRAVE_SEARCH_API_KEY) : aucune requête envoyée.');

            return self::FAILURE;
        }

        $verrou = DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS pris', [self::VERROU]);
        if ($verrou === null || ! (bool) $verrou->pris) {
            $this->error('Un autre passage est en cours : rien n\'a été envoyé.');

            return self::FAILURE;
        }

        $this->compteurs = array_fill_keys([
            'fiches_traitees', 'requetes_envoyees', 'sites_trouves', 'sans_site_retenu',
            'resultats_exclus', 'candidats_rejetes', 'sites_deja_pris', 'erreurs_brave', 'modifiees_entre_temps',
        ], 0);
        $arret = 'plus_de_fiche';
        $code = self::SUCCESS;

        try {
            WorkspaceContext::run($espace, function () use ($espace, $brave, $appartenance, $finder, $limite, $reessayer, $pauseMs, &$arret, &$code): void {
                // Fiches déjà vues pendant CE passage (cherchées, ou en erreur —
                // celles-ci seront reprises au passage suivant) : sans cela,
                // `--reessayer` relirait sans fin les mêmes `not_found`.
                /** @var list<int> $vues */
                $vues = [];
                while (true) {
                    $fiches = $this->selection($espace, $reessayer, $vues, self::LOT);
                    if ($fiches === []) {
                        return;
                    }

                    foreach ($fiches as $fiche) {
                        if ($limite > 0 && $this->compteurs['fiches_traitees'] >= $limite) {
                            $arret = 'limite';

                            return;
                        }
                        if ($this->compteurs['requetes_envoyees'] > 0 && $pauseMs > 0) {
                            usleep($pauseMs * 1000);
                        }

                        $reponse = $brave->chercher($this->requete($fiche));
                        if ($reponse['etat'] === 'plafond') {
                            $arret = 'plafond';

                            return;
                        }
                        $this->compteurs['requetes_envoyees']++;
                        $this->compteurs['fiches_traitees']++;
                        $vues[] = (int) $fiche->id;

                        if ($reponse['etat'] === 'bloque') {
                            $arret = 'brave_http_' . (int) $reponse['code'];
                            $code = self::FAILURE;

                            return;
                        }
                        if ($reponse['etat'] === 'erreur') {
                            $this->compteurs['erreurs_brave']++;

                            continue;
                        }

                        $site = $this->premierSiteVerifie($espace, $fiche, $reponse['urls'], $appartenance, $finder);
                        $this->ecrire($espace, (int) $fiche->id, $site);
                    }
                }
            });
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtext(?))', [self::VERROU]);
        }

        $this->bilan($quota, $arret);

        return $code;
    }

    /**
     * Les fiches à chercher, dans l'ordre de priorité.
     *
     * @param  list<int>  $vues
     * @return list<stdClass>
     */
    private function selection(string $espace, bool $reessayer, array $vues, int $nombre): array
    {
        [$where, $liaisons] = $this->perimetre($espace, $reessayer);
        if ($vues !== []) {
            $where .= ' AND c.id NOT IN (' . implode(', ', array_fill(0, count($vues), '?')) . ')';
            $liaisons = array_merge($liaisons, $vues);
        }
        $liaisons[] = $nombre;

        /** @var list<stdClass> $lignes */
        $lignes = DB::select(
            'SELECT c.id, c.siren, c.denomination, c.city, c.city_name, c.department_code,
                    f.niveau, f.sigle, f.nom_developpe, d.name AS departement_nom
               FROM federations f
               JOIN companies c ON c.id = f.company_id
               LEFT JOIN departments d ON d.code = c.department_code
              WHERE ' . $where . '
              ORDER BY ' . self::ordre() . '
              LIMIT ?',
            $liaisons,
        );

        return $lignes;
    }

    /**
     * Le périmètre — le MÊME pour l'essai à blanc et le vrai passage.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function perimetre(string $espace, bool $reessayer): array
    {
        $where = 'f.workspace_id = ? AND c.workspace_id = ? AND c.deleted_at IS NULL
                  AND (c.website IS NULL OR btrim(c.website) = \'\')
                  AND f.contactabilite IN (' . self::marques(self::CONTACTABILITES) . ')
                  AND f.niveau IN (' . self::marques(self::NIVEAUX) . ')
                  AND EXISTS (SELECT 1 FROM company_tag ct JOIN tags t ON t.id = ct.tag_id
                               WHERE ct.company_id = c.id AND t.slug = ?)';
        $liaisons = [$espace, $espace, ...self::CONTACTABILITES, ...self::NIVEAUX, FichesProtegees::TAG_FEDERATIONS];

        // Reprise : une fiche déjà cherchée n'est pas recherchée (sauf
        // `--reessayer`, qui reprend les `not_found`).
        // Avec `--reessayer`, les `not_found` reviennent ; les `found` ont un
        // site, la condition sur `website` les écarte déjà.
        if (! $reessayer) {
            $where .= ' AND c.website_method IS DISTINCT FROM ?';
            $liaisons[] = self::METHODE;
        }

        return [$where, $liaisons];
    }

    /** national → régional → départemental, puis avec salariés d'abord, puis l'identifiant. */
    private static function ordre(): string
    {
        return "CASE f.niveau WHEN 'national' THEN 0 WHEN 'regional' THEN 1 ELSE 2 END,
                CASE WHEN c.effectif_range ~ '^[0-9]{2}$' AND c.effectif_range <> '00' THEN 0 ELSE 1 END,
                c.id";
    }

    /** @param  list<string>  $valeurs */
    private static function marques(array $valeurs): string
    {
        return implode(', ', array_fill(0, count($valeurs), '?'));
    }

    /**
     * Nom développé, sigle, puis le département (section départementale) ou
     * la ville ; les plus gros annuaires et réseaux exclus d'emblée.
     */
    public function requete(stdClass $fiche): string
    {
        $nom = AppartenanceSite::nom($fiche);
        $morceaux = [$nom];

        $sigle = trim((string) ($fiche->sigle ?? ''));
        if ($sigle !== '' && ! str_contains(mb_strtolower($nom), mb_strtolower($sigle))) {
            $morceaux[] = $sigle;
        }

        $ville = trim((string) ($fiche->city_name ?? $fiche->city ?? ''));
        $departement = trim((string) ($fiche->departement_nom ?? ''));
        $lieu = $fiche->niveau === 'departemental' && $departement !== '' ? $departement : $ville;
        if ($lieu !== '' && ! str_contains(mb_strtolower($nom), mb_strtolower($lieu))) {
            $morceaux[] = $lieu;
        }

        $morceaux[] = '-site:societe.com -site:pappers.fr -site:linkedin.com -site:facebook.com';

        return implode(' ', $morceaux);
    }

    /**
     * Le premier résultat dont l'hôte passe toutes les gardes, ou null.
     *
     * @param  list<string>  $urls
     */
    private function premierSiteVerifie(string $espace, stdClass $fiche, array $urls, AppartenanceSite $appartenance, DomainFinderService $finder): ?string
    {
        $vus = [];
        foreach ($urls as $url) {
            if (count($vus) >= self::CANDIDATS_PAR_FICHE) {
                break;
            }
            $hote = self::hote($url);
            if ($hote === null || isset($vus[$hote])) {
                continue;
            }
            if (self::estExclu($hote) || ! SsrfGuard::check($url)['ok']) {
                $this->compteurs['resultats_exclus']++;

                continue;
            }
            $vus[$hote] = true;

            if ($this->dejaPris($espace, (int) $fiche->id, $hote)) {
                $this->compteurs['sites_deja_pris']++;

                continue;
            }

            $corps = $finder->recupererAccueil((string) parse_url($url, PHP_URL_HOST));
            if ($corps !== null && $appartenance->verifier($corps, $fiche)) {
                return 'https://' . $hote . '/';
            }
            $this->compteurs['candidats_rejetes']++;
        }

        return null;
    }

    /** L'hôte en minuscules, sans `www.`, pour une URL http(s) ; null sinon. */
    public static function hote(string $url): ?string
    {
        $schema = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $hote = parse_url($url, PHP_URL_HOST);
        if (! in_array($schema, ['http', 'https'], true) || ! is_string($hote) || $hote === '') {
            return null;
        }

        return (string) preg_replace('/^www\./', '', strtolower($hote));
    }

    public static function estExclu(string $hote): bool
    {
        if (str_ends_with($hote, '.gouv.fr') || str_contains($hote, 'annuaire')) {
            return true;
        }
        foreach (self::HOTES_EXCLUS as $exclu) {
            if ($hote === $exclu || str_ends_with($hote, '.' . $exclu)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ce site est-il déjà celui d'une AUTRE fédération de l'espace ? Un site
     * partagé est celui d'une tête de réseau (ou d'un réseau) : jamais celui
     * d'une section.
     */
    private function dejaPris(string $espace, int $companyId, string $hote): bool
    {
        return DB::table('federations as f')
            ->join('companies as c', 'c.id', '=', 'f.company_id')
            ->where('f.workspace_id', $espace)
            ->where('c.id', '<>', $companyId)
            ->whereNotNull('c.website')
            ->whereRaw("lower(regexp_replace(btrim(c.website), '^[a-z]+://(www\\.)?([^/:?#]+).*$', '\\2', 'i')) = ?", [$hote])
            ->exists();
    }

    /**
     * Les QUATRE colonnes autorisées, et rien d'autre. `updated_at` n'est pas
     * touché ; la fiche doit être encore sans site, fédération et protégée.
     */
    private function ecrire(string $espace, int $companyId, ?string $site): void
    {
        $ecrites = DB::transaction(function () use ($espace, $companyId, $site): int {
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");

            return DB::update(
                'UPDATE companies c
                    SET website = COALESCE(?, c.website),
                        website_status = ?,
                        website_method = ?,
                        website_checked_at = now()
                  WHERE c.id = ? AND c.workspace_id = ? AND c.deleted_at IS NULL
                    AND (c.website IS NULL OR btrim(c.website) = \'\')
                    AND EXISTS (SELECT 1 FROM federations f WHERE f.company_id = c.id AND f.workspace_id = ?)
                    AND EXISTS (SELECT 1 FROM company_tag ct JOIN tags t ON t.id = ct.tag_id
                                 WHERE ct.company_id = c.id AND t.slug = ?)',
                [$site, $site !== null ? 'found' : 'not_found', self::METHODE, $companyId, $espace, $espace, FichesProtegees::TAG_FEDERATIONS],
            );
        });

        if ($ecrites === 0) {
            $this->compteurs['modifiees_entre_temps']++;
        } elseif ($site !== null) {
            $this->compteurs['sites_trouves']++;
        } else {
            $this->compteurs['sans_site_retenu']++;
        }
    }

    private function essaiABlanc(string $espace, QuotaBrave $quota, int $limite, bool $reessayer): int
    {
        $parNiveau = WorkspaceContext::run($espace, function () use ($espace, $reessayer): array {
            [$where, $liaisons] = $this->perimetre($espace, $reessayer);

            return DB::select(
                'SELECT f.niveau,
                        count(*) AS fiches,
                        count(*) FILTER (WHERE c.effectif_range ~ \'^[0-9]{2}$\' AND c.effectif_range <> \'00\') AS avec_salaries
                   FROM federations f
                   JOIN companies c ON c.id = f.company_id
                  WHERE ' . $where . '
                  GROUP BY f.niveau',
                $liaisons,
            );
        });

        $total = 0;
        $lignes = [];
        foreach (self::NIVEAUX as $niveau) {
            $ligne = collect($parNiveau)->firstWhere('niveau', $niveau);
            $fiches = (int) ($ligne->fiches ?? 0);
            $total += $fiches;
            $lignes[] = [$niveau, $fiches, (int) ($ligne->avec_salaries ?? 0)];
        }

        $restantes = $quota->restantes();
        $prevues = min($total, $restantes, $limite > 0 ? $limite : PHP_INT_MAX);

        $this->info('[À BLANC] aucune requête Brave, aucune écriture.');
        $this->table(['niveau', 'fiches ciblées', 'dont avec salariés'], $lignes);
        $this->line("Fiches ciblées : {$total}");
        $this->line("Quota du mois : {$quota->consommees()} / {$quota->plafond()} requêtes consommées, {$restantes} restantes");
        $this->line("Requêtes qu'un passage enverrait : {$prevues}");

        Log::info('crm.federations.trouver_sites.essai_a_blanc', [
            'fiches_ciblees' => $total,
            'quota_restant' => $restantes,
            'requetes_prevues' => $prevues,
        ]);

        return self::SUCCESS;
    }

    private function bilan(QuotaBrave $quota, string $arret): void
    {
        $message = match (true) {
            $arret === 'plafond' => "Plafond mensuel atteint ({$quota->consommees()} / {$quota->plafond()}) : arrêt, aucune requête de plus.",
            $arret === 'limite' => 'Limite --limite atteinte : arrêt.',
            str_starts_with($arret, 'brave_http_') => 'Brave a refusé (HTTP ' . substr($arret, 11) . ') : arrêt. Vérifier la clé et le crédit du mois.',
            default => 'Plus aucune fiche à chercher.',
        };
        $arret === 'plus_de_fiche' || $arret === 'limite' ? $this->info($message) : $this->warn($message);

        foreach ($this->compteurs as $nom => $valeur) {
            $this->line("  {$nom} : {$valeur}");
        }
        $this->line("  quota_du_mois : {$quota->consommees()} / {$quota->plafond()}");

        Log::info('crm.federations.trouver_sites', $this->compteurs + [
            'arret' => $arret,
            'quota_consomme' => $quota->consommees(),
            'quota_plafond' => $quota->plafond(),
        ]);
    }
}

<?php

namespace App\Services\Domain;

use App\Crm\Brave\QuotaBrave;
use App\Crm\Brave\RechercheBrave;
use App\Crm\Sites\SiteFiable;
use App\Models\Company;
use App\Models\Media;
use App\Services\Http\ProxiedHttpClient;
use App\Services\Http\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sentry\State\Hub;

/**
 * Trouve le site web officiel d'une entreprise en cascade (3 stratégies).
 *
 * Stratégie 1 : signals.legal.siteweb (déjà rempli par AnnuaireEntreprises)
 * Stratégie 2 : Brave Search API (sprint H1 — remplace DuckDuckGo scrape)
 * Stratégie 3 : Pages Jaunes HTML — uniquement si MOCK_SCRAPERS=false
 *               (passage via Webshare proxy si WEBSHARE_ENABLED=true)
 *
 * Timeout 10s par source. Fail silently et passe à la suivante.
 * Skip silently les réseaux sociaux et annuaires d'entreprises.
 *
 * Garantie graceful degradation : pas de BRAVE_SEARCH_API_KEY → skip Brave silently.
 */
class DomainFinderService
{
    private const BLACKLIST_HOSTS = [
        'linkedin.com', 'facebook.com', 'twitter.com', 'x.com',
        'youtube.com', 'instagram.com', 'tiktok.com', 'pinterest.com',
        'societe.com', 'verif.com', 'pappers.fr', 'manageo.fr',
        'infogreffe.fr', 'annuaire-entreprises.data.gouv.fr', 'pagesjaunes.fr',
        'duckduckgo.com', 'google.com', 'bing.com', 'brave.com',
    ];

    private const HTTP_TIMEOUT_SECONDS = 10;

    // Vérification de domaine deviné : court + fail-fast (des millions d'entreprises).
    private const GUESS_TIMEOUT = 4;

    private const GUESS_CONNECT_TIMEOUT = 2;

    /** `website_method` d'un site lu dans `signals.legal.siteweb` (annuaire des entreprises). */
    public const METHODE_ANNUAIRE = 'annuaire';

    /** `website_method` d'un site rendu par la recherche Brave. */
    public const METHODE_BRAVE = 'brave';

    /** `website_method` d'un site trouvé sur Pages Jaunes. */
    public const METHODE_PAGES_JAUNES = 'pages-jaunes';

    /** Taille maximale d'un corps HTTP soumis à `verifyBody()` (1,5 Mo). */
    public const CORPS_MAX_OCTETS = 1_572_864;

    /** Candidats SIREN examinés au plus par page (`sirensDansPage`). */
    public const SIRENS_CANDIDATS_MAX = 2000;

    /** Octets de texte gardés AVANT une suite de chiffres pour la juger (`sirensDansPage`). */
    private const SIRENS_CONTEXTE_OCTETS = 48;

    /**
     * Mots retirés du nom pour la VÉRIFICATION (`verifyBody`) seulement :
     * formes juridiques, mots de structure et articles. « SELARL ZZ
     * Martin » doit se reconnaître sur une page titrée « Cabinet ZZ
     * Martin ». `nameTokens()` (donc `candidateDomains()`) garde sa propre
     * liste, inchangée.
     *
     * @var list<string>
     */
    public const MOTS_VIDES_VERIFICATION = [
        'sarl', 'sarlu', 'sas', 'sasu', 'sa', 'selarl', 'selas', 'eurl', 'snc', 'sci', 'scop', 'scea', 'gaec',
        'scm', 'sca', 'earl', 'gie', 'sccv', 'ste', 'societe', 'ets', 'etablissements', 'association', 'groupe',
        'holding', 'au', 'aux', 'en', 'et', 'la', 'le', 'les', 'l', 'de', 'du', 'des', 'd',
    ];

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * `$brave` null (le cas du conteneur : un paramètre nullable à défaut
     * n'est plus auto-résolu depuis Laravel 11, cf. `AppServiceProvider`) :
     * résolu à l'usage. Les tests unitaires injectent un quota factice en
     * liant `QuotaBrave` dans le conteneur, sans base.
     */
    public function __construct(private readonly ?RechercheBrave $brave = null) {}

    /**
     * Cherche le site web officiel d'une company.
     * Retourne l'URL canonique `https://domain.fr/` ou null.
     */
    public function find(Company $company): ?string
    {
        return $this->findAvecMethode($company)['url'] ?? null;
    }

    /**
     * Comme `find()`, mais dit AUSSI par quelle stratégie le site a été
     * trouvé, pour `companies.website_method` (lot N4, 03/10/2026) : jusque-là,
     * l'enrichissement (`WaterfallOrchestrator::step3b_find_domain`) écrivait
     * un site DEVINÉ sans méthode, et il passait pour un site fiable.
     *
     * Méthodes : `annuaire` (signals.legal.siteweb), `brave`,
     * `SiteFiable::METHODE_DEVINEE` (devinette — NON VÉRIFIÉ au sens de
     * `SiteFiable`), `pages-jaunes`.
     *
     * @return array{url: string, methode: string}|null
     */
    public function findAvecMethode(Company $company): ?array
    {
        // Stratégie 1 : signals.legal.siteweb (toujours en priorité)
        $signals = $company->signals ?? [];
        $existing = $signals['legal']['siteweb'] ?? null;
        if ($existing && is_string($existing) && filter_var($existing, FILTER_VALIDATE_URL)) {
            // C19-001 — `signals.legal.siteweb` est de la DONNÉE : il est rempli
            // par l'import AnnuaireEntreprises, donc par un tiers. `find()`
            // n'émet aucune requête ici, mais ce qu'il rend est écrit dans
            // `companies.website`, puis re-scrapé par MentionsLegales et par la
            // passe 3. Laisser passer `http://169.254.169.254/` ici, c'est
            // empoisonner toute la chaîne depuis un seul champ.
            // `filter_var(…, FILTER_VALIDATE_URL)` ne protège de rien : il
            // accepte `http://127.0.0.1/` sans broncher.
            if (! SsrfGuard::check($existing)['ok']) {
                Log::warning('DomainFinder: signals.legal.siteweb refuse par la garde SSRF', [
                    'company_id' => $company->id,
                    'siteweb' => $existing,
                ]);

                return null;
            }

            return self::trouve($this->canonicalize($existing), self::METHODE_ANNUAIRE);
        }

        if (! $company->denomination) {
            return null;
        }
        $ville = $company->city_name ?? $company->city ?? '';

        // Stratégie 2 : Brave Search API (graceful skip si pas de clé)
        $url = $this->searchBrave($company->denomination, $ville);
        if ($url) {
            return self::trouve($url, self::METHODE_BRAVE);
        }

        // Stratégie 3 : domain-guessing (DNS + HTTP) — 100% GRATUIT, sans clé, scalable.
        // Devine le domaine depuis le nom + vérifie que le site est bien l'entreprise.
        // Le site reste NON VÉRIFIÉ (`SiteFiable`) tant que le lot de
        // vérification n'a pas posé `metadata.site_entreprise.statut`.
        $url = $this->guessDomain($company);
        if ($url) {
            return self::trouve($url, SiteFiable::METHODE_DEVINEE);
        }

        // Stratégie 4 : Pages Jaunes — uniquement quand scrapers réels activés
        if (config('services.scrapers.mock', true) === false) {
            return self::trouve($this->searchPagesJaunes($company->denomination, $ville), self::METHODE_PAGES_JAUNES);
        }

        return null;
    }

    /** @return array{url: string, methode: string}|null */
    private static function trouve(?string $url, string $methode): ?array
    {
        return $url === null || $url === '' ? null : ['url' => $url, 'methode' => $methode];
    }

    /**
     * Devine le domaine officiel depuis le nom de l'entreprise, sans aucune API :
     * génère des candidats (`nomcomplet.fr`, `nom-complet.fr`, `premiermot.fr`…),
     * vérifie l'existence (DNS) puis que la page mentionne bien l'entreprise
     * (règle stricte de `verifyBody()` : SIREN, ou nom dans le titre et code
     * postal ou ville) pour éviter les faux positifs. Le site trouvé reste
     * NON VÉRIFIÉ (`SiteFiable`).
     */
    /**
     * Génère les domaines candidats pour un jeu de mots (nomcomplet.fr, nom-complet.fr,
     * premiermot.fr, + .com), filtrés (longueur, blacklist).
     *
     * Mode `$extended` = 2e passage (pass 2) sur les `not_found` : ajoute des variantes
     * secondaires (TLD alternatifs .com/.net/.eu, hyphen.com, premier mot .com, deux
     * premiers mots collés, acronyme des initiales) pour remonter la couverture.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function candidateDomains(array $tokens, bool $extended = false): array
    {
        if (count($tokens) === 0) {
            return [];
        }
        $joined = implode('', $tokens);
        $hyphen = implode('-', $tokens);
        $first = $tokens[0];
        // 4 candidats les plus probables (priorité, pass 1).
        $out = ["{$joined}.fr"];
        if ($hyphen !== $joined) {
            $out[] = "{$hyphen}.fr";
        }
        $out[] = "{$joined}.com";
        if (count($tokens) > 1 && mb_strlen($first) >= 4) {
            $out[] = "{$first}.fr";
        }

        if ($extended) {
            // Variantes secondaires — testées seulement au 2e passage (not_found).
            if ($hyphen !== $joined) {
                $out[] = "{$hyphen}.com";
            }
            $out[] = "{$joined}.net";
            $out[] = "{$joined}.eu";
            if (count($tokens) > 1 && mb_strlen($first) >= 4) {
                $out[] = "{$first}.com";
            }
            if (count($tokens) >= 2) {
                $two = $tokens[0] . $tokens[1];
                $out[] = "{$two}.fr";
                $out[] = "{$two}.com";
            }
            if (count($tokens) >= 3) {
                $acr = implode('', array_map(static fn ($t) => mb_substr($t, 0, 1), $tokens));
                if (mb_strlen($acr) >= 3) {
                    $out[] = "{$acr}.fr";
                    $out[] = "{$acr}.com";
                }
            }
        }

        return array_values(array_filter(
            array_unique($out),
            fn ($d) => mb_strlen($d) >= 5 && ! $this->isBlacklisted($d),
        ));
    }

    private function guessDomain(Company $company): ?string
    {
        $tokens = $this->nameTokens((string) $company->denomination);
        foreach ($this->candidateDomains($tokens) as $domain) {
            if (! @checkdnsrr($domain, 'A') && ! @checkdnsrr($domain, 'AAAA')) {
                continue;
            }
            if ($this->verifyCandidate($domain, $company, $tokens)) {
                return $this->canonicalize("https://{$domain}/");
            }
        }

        return null;
    }

    /**
     * Version CONCURRENTE (Http::pool) : teste les domaines de PLUSIEURS entreprises
     * EN PARALLÈLE — indispensable à l'échelle (4M en quelques heures). Pas de DNS
     * séquentiel : le pool gère résolution + connexion, les domaines morts échouent
     * vite (connectTimeout court). 1 requête par domaine = crawl poli.
     *
     * @param  iterable<Company>  $companies
     * @param  bool  $extended  2e passage (pass 2) : teste les variantes secondaires.
     * @return array<int, string|null> id entreprise => url trouvée (ou null)
     */
    public function guessDomainsBatch(iterable $companies, bool $extended = false): array
    {
        $result = [];
        $reqs = [];
        $n = 0;
        foreach ($companies as $c) {
            $result[$c->id] = null;
            $tokens = $this->nameTokens((string) $c->denomination);
            foreach ($this->candidateDomains($tokens, $extended) as $domain) {
                // PAS de pré-filtre DNS ici (checkdnsrr) : mesuré en prod, il DIVISE
                // le débit par ~2,5 (~1/s/job au lieu de ~2,6/s). Le resolver du serveur
                // est lent et checkdnsrr est séquentiel → 4 lookups bloquants/entreprise
                // dominent. Le pool HTTP gère mieux : les NXDOMAIN échouent vite au
                // resolve (bien avant le connectTimeout) sans sérialiser.
                $reqs['k' . ($n++)] = [
                    'c' => $c,
                    'domain' => $domain,
                    'tokens' => $tokens,
                ];
            }
        }
        if ($reqs === []) {
            return $result;
        }

        foreach (array_chunk($reqs, 400, true) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                $out = [];
                foreach ($chunk as $key => $it) {
                    $out[] = $pool->as($key)
                        ->timeout(self::GUESS_TIMEOUT)
                        ->connectTimeout(self::GUESS_CONNECT_TIMEOUT)
                        // C19-003 — pas de contrôle SSRF à l'ENTRÉE ici, et c'est
                        // un choix mesuré, pas un oubli : `candidateDomains()` ne
                        // fabrique que des `slug.fr` / `slug.com` construits à
                        // partir de la dénomination, jamais une IP ni un nom
                        // interdit — un contrôle d'entrée y serait provablement
                        // sans effet, et il coûterait ce que le commentaire
                        // ci-dessus a déjà mesuré : le pré-filtre DNS séquentiel
                        // DIVISAIT le débit par ~2,5. La redirection, elle, est
                        // hors de notre contrôle : le domaine deviné peut
                        // appartenir à n'importe qui et répondre
                        // `302 → 169.254.169.254`. Elle, on la vérifie.
                        ->withOptions(SsrfGuard::redirectOptions())
                        ->withHeaders(['User-Agent' => self::USER_AGENT])
                        ->get("https://{$it['domain']}/");
                }

                return $out;
            });

            foreach ($chunk as $key => $it) {
                $cid = $it['c']->id;
                if ($result[$cid] !== null) {
                    continue; // déjà trouvé pour cette entreprise
                }
                $resp = $responses[$key] ?? null;
                if (! $resp || $resp instanceof \Throwable) {
                    continue;
                }
                try {
                    if ($resp->successful() && $this->verifyBody(self::tronquer((string) $resp->body()), $it['c'], $it['tokens'], $it['domain'])) {
                        $result[$cid] = $this->canonicalize("https://{$it['domain']}/");
                    }
                } catch (\Throwable $e) {
                    // réponse illisible → ignore
                }
            }
        }

        return $result;
    }

    /**
     * PASSE 3 — RE-VALIDATION concurrente des sites déjà trouvés (Http::pool).
     *
     * Re-teste l'URL EXISTANTE `$company->website` de chaque entreprise (1 requête
     * par entreprise) pour détecter les sites disparus (domaine expiré, hébergement
     * coupé). Même pattern concurrent + timeouts courts que `guessDomainsBatch`.
     *
     * RÈGLE « vivant » CONSERVATRICE : l'entreprise est VIVANTE dès qu'on obtient
     * N'IMPORTE QUELLE réponse HTTP (l'objet réponse existe — même 4xx/5xx = le
     * serveur répond). Elle est MORTE seulement si la requête LÈVE une exception
     * (connexion refusée / DNS introuvable / timeout) → pas de réponse du tout.
     * On préfère un faux « vivant » à un faux « mort » (on ne jette pas un lead).
     *
     * @param  iterable<Company>  $companies
     * @return array<int, bool> id entreprise => vivant (true) / mort (false)
     */
    public function revalidateBatch(iterable $companies): array
    {
        $result = [];
        $reqs = [];
        $n = 0;
        foreach ($companies as $c) {
            $url = is_string($c->website) ? trim($c->website) : '';
            if ($url === '') {
                continue; // pas de site à re-valider → on ne se prononce pas
            }

            // ── C19-001 — LA GARDE SSRF, ENFIN BRANCHÉE ─────────────────────
            // C'est ICI la surface la plus directe des trois : `companies.website`
            // lu tel quel et appelé, sans aucun contrôle. La passe 3 tourne sur
            // des millions de lignes, 400 requêtes par salve : une seule ligne
            // empoisonnée en base suffisait à faire frapper la boucle locale ou
            // le réseau privé de l'hôte. On refuse ET on marque « mort » — un
            // site interne n'est pas un lead.
            $verdict = SsrfGuard::check($url);
            if (! $verdict['ok']) {
                Log::warning('DomainFinder passe 3: website refuse par la garde SSRF', [
                    'company_id' => $c->id,
                    'website' => $url,
                    'motif' => $verdict['reason'],
                ]);
                $result[$c->id] = false;

                continue;
            }

            $result[$c->id] = false;
            $reqs['k' . ($n++)] = ['id' => $c->id, 'url' => $url];
        }
        if ($reqs === []) {
            return $result;
        }

        foreach (array_chunk($reqs, 400, true) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                $out = [];
                foreach ($chunk as $key => $it) {
                    $out[] = $pool->as($key)
                        ->timeout(self::GUESS_TIMEOUT)
                        ->connectTimeout(self::GUESS_CONNECT_TIMEOUT)
                        // C19-003 — l'URL de départ a été contrôlée plus haut ;
                        // chaque saut de redirection l'est ici.
                        ->withOptions(SsrfGuard::redirectOptions())
                        ->withHeaders(['User-Agent' => self::USER_AGENT])
                        ->get($it['url']);
                }

                return $out;
            });

            foreach ($chunk as $key => $it) {
                $resp = $responses[$key] ?? null;
                // Une exception (ConnectionException, DNS, timeout) arrive ici sous
                // forme de Throwable dans le pool → MORT. Un objet réponse (2xx…5xx)
                // = le serveur a répondu → VIVANT (règle conservatrice).
                $result[$it['id']] = ($resp !== null && ! $resp instanceof \Throwable);
            }
        }

        return $result;
    }

    /**
     * Récupère la page d'accueil et confirme qu'elle appartient bien à
     * l'entreprise — règle stricte de `verifyBody()`.
     *
     * @param  list<string>  $tokens
     */
    private function verifyCandidate(string $domain, Company $company, array $tokens): bool
    {
        $body = $this->recupererAccueil($domain);

        return $body !== null && $this->verifyBody($body, $company, $tokens, $domain);
    }

    /**
     * La page d'accueil `https://{domaine}/`, ou null (échec, réponse non 2xx).
     *
     * Extraite de `verifyCandidate()` (2026-09-29) pour que
     * `crm:federations:trouver-sites` lise le MÊME corps que `verifyBody()`
     * et y applique sa règle plus stricte (sigle, département) sans
     * télécharger la page deux fois. ⚠️ Aucun contrôle SSRF à l'ENTRÉE ici
     * (cf. C19-003 plus bas) : un domaine qui ne vient pas de
     * `candidateDomains()` doit être passé à `SsrfGuard::check()` par
     * l'appelant AVANT.
     */
    public function recupererAccueil(string $domain): ?string
    {
        try {
            $resp = Http::timeout(self::GUESS_TIMEOUT)
                ->connectTimeout(self::GUESS_CONNECT_TIMEOUT)
                // C19-003 — site jumeau séquentiel de `guessDomainsBatch()` :
                // même domaine deviné, même redirection possible, même garde.
                ->withOptions(SsrfGuard::redirectOptions())
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get("https://{$domain}/");
        } catch (\Throwable $e) {
            return null;
        }

        return $resp->successful() ? self::tronquer((string) $resp->body()) : null;
    }

    /**
     * Le corps HTTP borné à `CORPS_MAX_OCTETS` (1,5 Mo), coupé sur une
     * frontière de caractère : une page d'accueil n'a pas besoin de plus
     * pour porter son nom, son SIREN ou son adresse, et `verifyBody()`
     * passe plusieurs expressions régulières sur tout le texte.
     */
    public static function tronquer(string $corps): string
    {
        return strlen($corps) > self::CORPS_MAX_OCTETS ? mb_strcut($corps, 0, self::CORPS_MAX_OCTETS, 'UTF-8') : $corps;
    }

    /**
     * Confirme qu'une page HTML appartient bien à l'entreprise — règle STRICTE
     * (lot N4, 03/10/2026). La page est acceptée si :
     *
     *  1. elle contient le SIREN de l'entreprise (ou son SIRET, qui le
     *     commence), écrit d'un bloc ou espacé, non collé à un autre chiffre
     *     — sauf derrière `FR` + clé (numéro de TVA intracommunautaire) ;
     *  2. OU le NOM est dans l'identité de la page (<title>, og:site_name,
     *     <h1>) — TOUS ses mots, en mots entiers, une fois retiré ce qui
     *     recopie l'adresse du domaine essayé (`$domaine`) — ET le code
     *     postal OU la ville de l'entreprise apparaissent dans la page.
     *
     * Sinon : refusée. L'ancienne règle (deux mots du nom n'importe où, ou un
     * mot et la ville) acceptait france.fr, paris.fr, maison.fr pour des
     * milliers de fiches ; elle ne survit que dans `correspondanceLache()`,
     * pour `AppartenanceSite` qui la complète d'une règle plus stricte.
     *
     * Un site deviné accepté ici reste NON VÉRIFIÉ (`SiteFiable`) : cette
     * règle ferme le robinet, elle ne vaut pas vérification.
     *
     * Les mots du nom sont tirés de la dénomination par la MÊME
     * normalisation que la page (`normaliserMots` : `Str::ascii` puis
     * minuscules — « CŒUR » et « coeur » se rencontrent), sans les
     * `MOTS_VIDES_VERIFICATION`. `$tokens` ne sert que si la dénomination
     * est vide.
     *
     * @param  list<string>  $tokens  `nameTokens()` du nom
     */
    public function verifyBody(string $rawBody, Company|Media $company, array $tokens, ?string $domaine = null): bool
    {
        if (! mb_check_encoding($rawBody, 'UTF-8')) {
            $rawBody = (string) mb_convert_encoding($rawBody, 'UTF-8', 'Windows-1252');
        }
        $nom = trim((string) $company->denomination);
        $mots = $nom !== '' ? $this->motsPourVerification($nom) : $this->motsPourVerification(implode(' ', $tokens));
        $texte = $this->texteVisible($rawBody);
        if (mb_strlen($texte) < 200) {
            return false;
        }
        if ($this->contientSiren($texte, (string) $company->siren)) {
            return true;
        }

        return $this->nomDansIdentite($rawBody, $mots, $domaine)
            && $this->contientLieu($texte, $company);
    }

    /**
     * Les SIREN qu'une page PROUVE (lot N6, `crm:entreprises:verifier-sites`) :
     * chaque suite de 9 chiffres du texte visible (séparateurs permis comme
     * dans `contientSiren`) que `contientSiren()` accepte — la MÊME règle que
     * `verifyBody()`, arbitre unique : jamais collé à un autre chiffre sauf
     * derrière `FR` + clé, suivi de chiffres accepté (SIRET).
     *
     * Rendre l'ensemble plutôt qu'un oui / non permet de lire UNE fois une page
     * partagée par des milliers de fiches (france.fr) et d'en garder quelques
     * numéros, jamais le texte. Corps tronqué à `CORPS_MAX_OCTETS`. Au plus
     * `SIRENS_CANDIDATS_MAX` candidats examinés (page faite de chiffres).
     *
     * @return list<string>
     */
    public function sirensDansPage(string $rawBody): array
    {
        $rawBody = self::tronquer($rawBody);
        if (! mb_check_encoding($rawBody, 'UTF-8')) {
            $rawBody = (string) mb_convert_encoding($rawBody, 'UTF-8', 'Windows-1252');
        }
        $texte = $this->texteVisible($rawBody);
        if (preg_match_all('/\d(?:[\s.\x{00A0}\x{202F}-]{0,2}\d){8,}/u', $texte, $m, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        // Chaque candidat est jugé par `contientSiren` sur un EXTRAIT (la suite
        // et ce qui la précède : assez pour « FR » + clé et les séparateurs),
        // jamais sur la page entière — une page faite de chiffres ne coûte pas
        // 2 000 balayages de 1,5 Mo.
        $prouves = [];
        $examines = 0;
        foreach ($m[0] as [$suite, $position]) {
            $debut = max(0, (int) $position - self::SIRENS_CONTEXTE_OCTETS);
            $extrait = mb_strcut($texte, $debut, (int) $position - $debut + strlen($suite), 'UTF-8');
            $chiffres = (string) preg_replace('/\D/', '', $suite);
            for ($i = 0, $n = strlen($chiffres) - 9; $i <= $n; $i++) {
                $candidat = substr($chiffres, $i, 9);
                if (! isset($prouves[$candidat]) && $this->contientSiren($extrait, $candidat)) {
                    $prouves[$candidat] = true;
                }
                if (++$examines >= self::SIRENS_CANDIDATS_MAX) {
                    break 2;
                }
            }
        }

        return array_map('strval', array_keys($prouves));
    }

    /**
     * L'ANCIENNE règle, permissive : SIREN, OU ≥2 mots du nom n'importe où,
     * OU (1 mot + la ville). ⛔ Ne suffit JAMAIS seule à accepter un site
     * deviné : seule `AppartenanceSite` (fédérations, résultats Brave) s'en
     * sert, comme premier filtre avant sa propre règle d'identité, plus
     * stricte (sigle, mots distinctifs, département).
     *
     * @param  list<string>  $tokens
     */
    public function correspondanceLache(string $rawBody, Company|Media $company, array $tokens): bool
    {
        $body = mb_strtolower(strip_tags($rawBody));
        if (mb_strlen($body) < 200) {
            return false;
        }
        $siren = (string) $company->siren;
        if ($siren !== '' && str_contains(preg_replace('/\s+/', '', $body), $siren)) {
            return true;
        }
        $hits = 0;
        foreach ($tokens as $t) {
            if (mb_strlen($t) >= 3 && str_contains($body, $t)) {
                $hits++;
            }
        }
        $ville = $this->stripAccents(mb_strtolower((string) ($company->city_name ?? $company->city ?? '')));
        $villeOk = mb_strlen($ville) >= 3 && str_contains($this->stripAccents($body), $ville);

        return $hits >= 2 || ($hits >= 1 && $villeOk);
    }

    /** Le texte visible de la page (sans scripts ni styles), entités décodées. */
    private function texteVisible(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $texte = strip_tags(str_replace('<', ' <', $html));

        return trim(html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Les mots d'un nom pour la VÉRIFICATION : `normaliserMots`, sans les
     * `MOTS_VIDES_VERIFICATION` (mots entiers), deux caractères au moins,
     * quatre au plus.
     *
     * @return list<string>
     */
    public function motsPourVerification(string $nom): array
    {
        $mots = array_filter(
            explode(' ', $this->normaliserMots($nom)),
            static fn (string $m): bool => strlen($m) >= 2 && ! in_array($m, self::MOTS_VIDES_VERIFICATION, true),
        );

        return array_slice(array_values(array_unique($mots)), 0, 4);
    }

    /** Minuscules, sans accents, tout ce qui n'est ni lettre ni chiffre devient une espace. */
    private function normaliserMots(string $texte): string
    {
        $texte = mb_strtolower(Str::ascii(html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $texte));
    }

    /**
     * Le SIREN (9 chiffres) figure dans le texte, d'un bloc ou ses chiffres
     * séparés par espaces (dont insécables), points ou tirets (deux au plus
     * entre deux chiffres) : `941234567`, `941 234 567`, `941.234.567`,
     * `941-234-567`. Jamais précédé d'un autre chiffre, séparateurs compris
     * (un numéro de téléphone `06 12 34 56 78` contient `612345678`), sauf
     * derrière `FR` + clé de TVA. Suivi de chiffres : c'est le SIRET, accepté.
     */
    private function contientSiren(string $texte, string $siren): bool
    {
        $siren = (string) preg_replace('/\D/', '', $siren);
        if (strlen($siren) !== 9) {
            return false;
        }
        $sep = '[\s.\x{00A0}\x{202F}-]';
        if (preg_match_all('/' . implode($sep . '{0,2}', str_split($siren)) . '/u', $texte, $m, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }
        foreach ($m[0] as [, $position]) {
            $avant = (string) preg_replace('/' . $sep . '+$/u', '', substr($texte, 0, (int) $position));
            if (preg_match('/\d$/', $avant) !== 1 || preg_match('/fr' . $sep . '*\d\d$/iu', $avant) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * TOUS les mots du nom (`motsPourVerification`) figurent, en mots entiers, dans
     * l'identité de la page : <title>, og:site_name, <h1>. Ce qui recopie
     * l'adresse du domaine essayé en est retiré d'abord : une page de
     * parking titrée « boulangerie-martin.fr » ne prouve rien.
     *
     * @param  list<string>  $mots
     */
    private function nomDansIdentite(string $html, array $mots, ?string $domaine): bool
    {
        if ($mots === []) {
            return false;
        }

        $morceaux = [];
        if (preg_match('#<title\b[^>]*>(.*?)</title\s*>#is', $html, $m) === 1) {
            $morceaux[] = $m[1];
        }
        if (preg_match_all('#<h1\b[^>]*>(.*?)</h1\s*>#is', $html, $m) > 0) {
            array_push($morceaux, ...$m[1]);
        }
        if (preg_match_all('#<meta\b[^>]*>#i', $html, $m) > 0) {
            foreach ($m[0] as $balise) {
                if (preg_match('#\b(?:property|name)\s*=\s*["\']og:site_name["\']#i', $balise) === 1
                    && preg_match('#\bcontent\s*=\s*(["\'])(.*?)\1#is', $balise, $c) === 1) {
                    $morceaux[] = $c[2];
                }
            }
        }
        $identite = ' ' . $this->normaliserMots(strip_tags(str_replace('<', ' <', implode(' ', $morceaux)))) . ' ';

        if ($domaine !== null && $domaine !== '') {
            $hote = $this->normaliserMots((string) preg_replace('/^www\./i', '', $domaine));
            if ($hote !== '') {
                $identite = str_replace(' ' . $hote . ' ', ' ', $identite);
            }
        }

        foreach ($mots as $mot) {
            if (! str_contains($identite, ' ' . $mot . ' ')) {
                return false;
            }
        }

        return true;
    }

    /** Le code postal (5 chiffres, nombre entier) OU la ville (mots entiers) de l'entreprise figurent dans la page. */
    private function contientLieu(string $texte, Company|Media $company): bool
    {
        $cp = (string) preg_replace('/\D/', '', (string) ($company->postcode ?? ''));
        if (strlen($cp) === 5 && preg_match('/(?<!\d)' . $cp . '(?!\d)/', $texte) === 1) {
            return true;
        }
        $ville = $this->normaliserMots((string) ($company->city_name ?? $company->city ?? ''));
        if (mb_strlen(str_replace(' ', '', $ville)) < 3) {
            return false;
        }

        return str_contains(' ' . $this->normaliserMots($texte) . ' ', ' ' . $ville . ' ');
    }

    /**
     * Découpe le nom en mots normalisés (sans accents, sans forme juridique).
     *
     * @return list<string>
     */
    public function nameTokens(string $name): array
    {
        $s = $this->stripAccents(mb_strtolower($name));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $stop = [
            'sarl', 'sas', 'sasu', 'sa', 'eurl', 'snc', 'sci', 'sarlu', 'scop',
            'earl', 'gie', 'sccv', 'et', 'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd',
        ];
        $words = array_filter(
            explode(' ', (string) $s),
            fn ($w) => $w !== '' && mb_strlen($w) >= 2 && ! in_array($w, $stop, true),
        );

        return array_values(array_slice($words, 0, 4));
    }

    private function stripAccents(string $s): string
    {
        $from = ['à', 'â', 'ä', 'á', 'ã', 'å', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'í', 'ì', 'ô', 'ö', 'ò', 'ó', 'õ', 'ù', 'û', 'ü', 'ú', 'ç', 'ñ'];
        $to = ['a', 'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'n'];

        return str_replace($from, $to, $s);
    }

    /**
     * Brave Search API — remplace l'ancien scrape DuckDuckGo (banni rapidement).
     * Renvoie le 1er résultat non-blacklist.
     *
     * 2026-09-29 — PAR `RechercheBrave`, sous l'usage `enrichissement` : la
     * requête est RÉSERVÉE dans le quota mensuel avant de partir
     * (`CRM_BRAVE_QUOTA_ENRICHISSEMENT`, 0 par défaut). À 0, ou sans clé,
     * rien n'est envoyé et `find()` passe à la stratégie suivante — c'est le
     * comportement d'avant que la clé soit posée en production. Plus de
     * `->retry(2)` : chaque nouvel essai était une requête facturée, jusqu'à
     * trois par fiche, que le compteur n'aurait pas vue.
     */
    private function searchBrave(string $denomination, string $ville): ?string
    {
        $reponse = ($this->brave ?? app(RechercheBrave::class))
            ->chercher(sprintf('%s %s site officiel', $denomination, $ville), QuotaBrave::ENRICHISSEMENT, 5);
        if ($reponse['etat'] !== 'ok') {
            // Sans clé, quota épuisé ou erreur : on passe, en silence (compteurs
            // seulement, jamais la requête — elle porte le nom de l'entreprise).
            Log::debug('DomainFinder Brave saute', ['etat' => $reponse['etat'], 'code' => $reponse['code']]);

            return null;
        }

        foreach ($reponse['urls'] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (! $host || $this->isBlacklisted($host)) {
                continue;
            }
            // C19-001 — cette URL vient d'une API TIERCE (Brave). Elle est
            // aussi « issue de la donnée » qu'un champ de la base : la
            // liste noire ci-dessus filtre LinkedIn et les annuaires, elle
            // ne dit rien de 169.254.169.254. La valeur rendue devient
            // `companies.website`.
            if (! SsrfGuard::check($url)['ok']) {
                Log::warning('DomainFinder Brave: resultat refuse par la garde SSRF', ['url' => $url]);

                continue;
            }

            return $this->canonicalize($url);
        }

        return null;
    }

    /**
     * Pages Jaunes scraping — uniquement Phase B (Will valide).
     * Passe via Webshare proxy si activé pour éviter blacklist IP Hetzner.
     */
    private function searchPagesJaunes(string $denomination, string $ville): ?string
    {
        $denomSlug = $this->slugify($denomination);
        $villeSlug = $this->slugify($ville);
        if (! $denomSlug || ! $villeSlug) {
            return null;
        }

        $url = sprintf('https://www.pagesjaunes.fr/recherche/%s/%s', $villeSlug, $denomSlug);

        try {
            // Sprint H1 — Webshare proxy si activé (évite blacklist IP Hetzner sur PJ)
            $response = app(ProxiedHttpClient::class)->request(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            if (preg_match('/<a[^>]+class="[^"]*company-website[^"]*"[^>]+href="([^"]+)"/i', $response->body(), $m)) {
                $href = $m[1];
                $host = parse_url($href, PHP_URL_HOST);
                // C19-001 — `$href` est extrait du HTML d'un TIERS (Pages Jaunes,
                // ou de ce que renvoie le proxy Webshare). C'est la définition
                // même d'une URL issue de la donnée, et elle devient
                // `companies.website`.
                if ($host && ! $this->isBlacklisted($host) && SsrfGuard::check($href)['ok']) {
                    return $this->canonicalize($href);
                }
            }
        } catch (\Throwable $e) {
            if (class_exists(Hub::class)) {
                \Sentry\captureException($e);
            }
            Log::debug('DomainFinder PagesJaunes failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function isBlacklisted(string $host): bool
    {
        $host = strtolower($host);
        foreach (self::BLACKLIST_HOSTS as $b) {
            if (str_contains($host, $b)) {
                return true;
            }
        }

        return false;
    }

    private function canonicalize(string $url): string
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return $url;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = preg_replace('/^www\./i', '', strtolower($parts['host']));

        return sprintf('%s://%s/', $scheme, $host);
    }

    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/i', '-', $s);

        return trim((string) $s, '-');
    }
}

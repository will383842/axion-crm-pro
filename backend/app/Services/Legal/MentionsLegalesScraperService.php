<?php

namespace App\Services\Legal;

use App\Models\Company;
use App\Services\Email\MxEmailValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scrape la page « Mentions Légales » (ou variantes) d'un site web pour extraire,
 * de façon EXHAUSTIVE (Sprint H17, 2026-07-08) :
 *  - TOUS les emails visibles (plus seulement le 1er), chacun devenant une fiche
 *    contact : dirigeant connu, personne nommée, ou boîte « service »
 *    (commercial@, compta@, rh@…) avec un rôle déduit.
 *  - TOUS les téléphones visibles (format national 0X… et international +33…).
 *  - email contact générique (companies.email_generic) + téléphone principal
 *    (companies.phone) pour rétro-compat.
 *  - La liste COMPLETE des emails/téléphones dans signals.contact_channels
 *    (aucun canal perdu, même s'il ne devient pas une fiche contact).
 *
 * Doctrine « 0 email douteux » : chaque email est validé (MX), les invalides et
 * jetables sont rejetés. 100 % gratuit (HTTP + DNS), aucun crédit consommé.
 *
 * Skip silently pages JS-rendered (< 500 octets de texte parsé).
 */
class MentionsLegalesScraperService
{
    /**
     * Sprint H10 (2026-05-18) — Élargi de 8 à 18 paths.
     * Ordre : pages les plus probables d'avoir email/phone visibles d'abord
     * (contact > mentions légales > a-propos > home). Early exit dès qu'on a
     * accumulé assez de contenu (cf. fetchAnyMentionsLegalesPage()).
     */
    private const PATHS = [
        // 1. Pages contact (les plus fréquentes pour email + tel)
        '/contact',
        '/contact.html',
        '/contactez-nous',
        '/contact-us',
        '/nous-contacter',
        '/contact/',
        // 2. Mentions légales (info juridique + email obligatoire FR)
        '/mentions-legales',
        '/mentions-legales.html',
        '/legal',
        '/imprint',
        '/a-propos/mentions-legales',
        // 3. À propos / équipe (souvent dirigeants + email)
        '/a-propos',
        '/about',
        '/about-us',
        '/equipe',
        '/team',
        // 4. CGV / CGU (souvent email contact en bas)
        '/cgv',
        '/cgu',
        '/conditions-generales',
        // 5. Home page (footer contient souvent email + tel)
        '/',
    ];

    private const HTTP_TIMEOUT_SECONDS = 10;

    /**
     * Sprint H1 — Pool d'User-Agents rotation aléatoire pour réduire fingerprint.
     * Chrome/Safari/Firefox récents 2025 réalistes.
     */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
    ];

    /** Boîtes techniques inutiles pour la prospection — jamais capturées. */
    private const EMAIL_BLACKLIST_PREFIXES = ['no-reply@', 'noreply@', 'postmaster@', 'abuse@', 'webmaster@'];

    private const MIN_BODY_LENGTH = 500;

    /**
     * Sprint H17 — Déduction du rôle d'une boîte « service » depuis le préfixe
     * (partie avant le @). Ordre significatif : Commercial avant Communication
     * (sinon « commercial » matcherait « comm »). Les mots-clés courts (< 4)
     * exigent une égalité exacte pour éviter les faux positifs (ex. « dg » dans
     * « budget »).
     *
     * @var array<string, array<int, string>>
     */
    private const SERVICE_ROLE_MAP = [
        'Commercial'             => ['commercial', 'commerciale', 'sales', 'vente', 'ventes', 'devis'],
        'Comptabilité'           => ['compta', 'comptabilite', 'facturation', 'facture', 'billing', 'finance', 'finances'],
        'Ressources humaines'    => ['rh', 'recrut', 'recrutement', 'emploi', 'job', 'jobs', 'career', 'careers', 'candidature'],
        'Direction'              => ['direction', 'ceo', 'gerance', 'gerant', 'pdg', 'dg', 'president', 'presidente'],
        'Support / SAV'          => ['sav', 'support', 'aide', 'help', 'assistance', 'serviceclient', 'service-client'],
        'Communication / Presse' => ['presse', 'press', 'media', 'medias', 'communication', 'comm'],
        'Marketing'              => ['marketing', 'mkt', 'digital'],
        'Achats'                 => ['achat', 'achats', 'purchasing', 'procurement', 'fournisseur', 'fournisseurs'],
        'Contact général'        => ['contact', 'info', 'infos', 'information', 'accueil', 'hello', 'bonjour', 'welcome', 'mail', 'societe'],
    ];

    public function __construct(
        private readonly ?MxEmailValidator $emailValidator = null,
    ) {}

    /**
     * Scrape le site et capture TOUS les emails + téléphones.
     * Retourne true si au moins un canal utile (email ou téléphone) a été trouvé.
     */
    public function scrape(Company $company): bool
    {
        if (! $company->website) {
            return false;
        }

        $body = $this->fetchAnyMentionsLegalesPage($company->website);
        if ($body === null) {
            return false;
        }

        $emails = $this->extractAllUsableEmails($body);
        $phones = $this->extractAllPhones($body);

        $signals = $company->signals ?? [];
        $dirigeants = $signals['legal']['dirigeants'] ?? [];
        if (! is_array($dirigeants)) {
            $dirigeants = [];
        }

        $acceptedEmails = [];   // tous les emails validés (fiches + liste complète)
        $genericEmail = null;   // 1er email « service » → email_generic (rétro-compat)

        foreach ($emails as $email) {
            // Validation MX — on ne garde JAMAIS un email invalide/jetable.
            $emailStatus = 'unknown';
            $validation = null;
            if ($this->emailValidator !== null) {
                $validation = $this->emailValidator->validate($email);
                if (in_array($validation['status'], ['invalid', 'disposable'], true)) {
                    continue;
                }
                $emailStatus = $this->mapMxStatus($validation['status']);
            }

            $class = $this->classifyEmail($email, $dirigeants);
            $this->persistContact($company, $email, $emailStatus, $validation, $class);

            $acceptedEmails[] = $email;
            if ($genericEmail === null && $class['kind'] === 'service') {
                $genericEmail = $email;
            }
        }

        // Fallback email_generic : à défaut de boîte « service », le 1er accepté.
        if ($genericEmail === null && ! empty($acceptedEmails)) {
            $genericEmail = $acceptedEmails[0];
        }

        // Backfill company-level — n'écrase jamais l'existant.
        if (! $company->email_generic && $genericEmail) {
            $company->email_generic = $genericEmail;
        }
        if (! $company->phone && ! empty($phones)) {
            $company->phone = $phones[0];
        }

        // Liste COMPLETE conservée — aucun email/téléphone perdu.
        $channels = $signals['contact_channels'] ?? [];
        $channels['emails'] = array_values(array_unique(array_merge($channels['emails'] ?? [], $acceptedEmails)));
        $channels['phones'] = array_values(array_unique(array_merge($channels['phones'] ?? [], $phones)));
        $channels['scraped_at'] = now()->toIso8601String();
        $signals['contact_channels'] = $channels;
        $company->signals = $signals;

        $company->save();

        return ! empty($acceptedEmails) || ! empty($phones);
    }

    /**
     * Récupère le TEXTE brut agrégé des pages ours/contact/mentions-légales/équipe
     * d'un site (mêmes 18 paths, rotation UA, délais polis, early-exit). Réutilisé
     * par `journalists:scrape-ours` pour envoyer ce texte à l'extraction LLM.
     *
     * Retourne null si aucune page exploitable (≥ 500 octets de texte) n'est trouvée.
     */
    public function fetchPagesText(string $website): ?string
    {
        $html = $this->fetchAnyMentionsLegalesPage($website);
        if ($html === null) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));

        return $text !== '' ? $text : null;
    }

    /**
     * Sprint H10 — Itère sur les paths, fusionne tous les bodies utiles trouvés
     * (concat des HTML des pages contact + mentions + about + home) pour avoir
     * un maximum de signaux email/phone à parser ensuite. Stop early si on a
     * déjà accumulé suffisamment de contenu (10K chars) ou 4 pages.
     */
    private function fetchAnyMentionsLegalesPage(string $website): ?string
    {
        $base = rtrim($website, '/');
        $accumulated = '';
        $pagesFound = 0;
        foreach (self::PATHS as $path) {
            $body = $this->fetch($base . $path);
            if ($body !== null && strlen(strip_tags($body)) >= self::MIN_BODY_LENGTH) {
                $accumulated .= "\n\n<!-- page: {$path} -->\n" . $body;
                $pagesFound++;
                // Early exit : assez de contenu accumulé pour parser
                // (évite de marteler le site, ~3 pages suffisent largement)
                if (strlen($accumulated) >= 10000 || $pagesFound >= 4) {
                    break;
                }
            }
        }
        return $accumulated !== '' ? $accumulated : null;
    }

    private function fetch(string $url): ?string
    {
        $ua = self::USER_AGENTS[array_rand(self::USER_AGENTS)];

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => $ua,
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->retry(2, 1000, function (\Throwable $e) {
                    return $e instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            // Random delay 200-800ms entre paths pour ne pas marteler le serveur.
            // Skip si test/Http::fake (microsleep mesurable en perf-critical tests).
            if (app()->environment('production', 'staging')) {
                usleep(random_int(200_000, 800_000));
            }

            return $response->body();
        } catch (\Throwable $e) {
            if (class_exists(\Sentry\State\Hub::class)) {
                \Sentry\captureException($e);
            }
            Log::debug('MentionsLegales fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Extensions de fichiers qui matchent le regex email mais sont du bruit (ex. image@2x.png). */
    private const EMAIL_FALSE_TLDS = [
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp', 'ico', 'css', 'js',
        'json', 'xml', 'map', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'webm', 'pdf', 'zip',
    ];

    /**
     * Extrait TOUS les emails exploitables du body (dédupliqués, minuscules),
     * en filtrant le bruit (assets image) et les boîtes techniques blacklistées.
     *
     * @return array<int, string>
     */
    private function extractAllUsableEmails(string $body): array
    {
        if (! preg_match_all('/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/', $body, $matches)) {
            return [];
        }
        $out = [];
        foreach ($matches[0] as $raw) {
            $email = strtolower($raw);
            if (isset($out[$email]) || ! $this->looksLikeRealEmail($email)) {
                continue;
            }
            $skip = false;
            foreach (self::EMAIL_BLACKLIST_PREFIXES as $prefix) {
                if (str_starts_with($email, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if (! $skip) {
                $out[$email] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Rejette les faux emails captés dans le HTML : noms d'images (`img@2x.png`),
     * assets (`x@1x.svg`), et tout ce dont le TLD est une extension de fichier.
     */
    private function looksLikeRealEmail(string $email): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        // Motif « retina » : quelque-chose@2x.png / @3x.jpg …
        if (preg_match('/@\d+x\./', $email)) {
            return false;
        }
        $domain = (string) substr(strrchr($email, '@') ?: '@', 1);
        $tld = str_contains($domain, '.') ? substr((string) strrchr($domain, '.'), 1) : '';
        return ! in_array($tld, self::EMAIL_FALSE_TLDS, true);
    }

    /**
     * Extrait TOUS les téléphones (dédupliqués, normalisés en 0XXXXXXXXX) :
     * format national 0X XX XX XX XX + format international +33 X XX XX XX XX.
     *
     * @return array<int, string>
     */
    private function extractAllPhones(string $body): array
    {
        $out = [];
        // Format national : 0X XX XX XX XX (séparateurs espace/point/tiret tolérés)
        if (preg_match_all('/\b0[1-9](?:[\s.\-]?\d{2}){4}\b/', $body, $m)) {
            foreach ($m[0] as $raw) {
                $out[(string) preg_replace('/[\s.\-]/', '', $raw)] = true;
            }
        }
        // Format international : +33 X XX XX XX XX → normalisé en 0X…
        if (preg_match_all('/\+33[\s.\-]?[1-9](?:[\s.\-]?\d{2}){4}\b/', $body, $m2)) {
            foreach ($m2[0] as $raw) {
                $digits = (string) preg_replace('/\D/', '', $raw);      // 33XXXXXXXXX
                $digits = (string) preg_replace('/^33/', '0', $digits); // 0XXXXXXXXX
                $out[$digits] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Classe un email : dirigeant connu, personne nommée, ou boîte service.
     *
     * @param  array<int, array{first_name?: string|null, last_name?: string, role?: string}>  $dirigeants
     * @return array{first_name: string|null, last_name: string, role: string, kind: string}
     */
    private function classifyEmail(string $email, array $dirigeants): array
    {
        $local = strtolower(strstr($email, '@', true) ?: '');

        // 1. Dirigeant connu (nom ou prénom présent dans le préfixe).
        foreach ($dirigeants as $rep) {
            $first = strtolower((string) ($rep['first_name'] ?? ''));
            $last = strtolower((string) ($rep['last_name'] ?? ''));
            if (($last !== '' && strlen($last) >= 3 && str_contains($local, $last))
                || ($first !== '' && strlen($first) >= 3 && str_contains($local, $first))) {
                return [
                    'first_name' => $rep['first_name'] ?? null,
                    'last_name'  => (string) ($rep['last_name'] ?? 'Dirigeant'),
                    'role'       => $rep['role'] ?? 'dirigeant',
                    'kind'       => 'dirigeant',
                ];
            }
        }

        // 2. Boîte service (commercial@, compta@, rh@…).
        $roleLabel = $this->roleLabelFromLocalPart($local);
        if ($roleLabel !== null) {
            return [
                'first_name' => null,
                'last_name'  => ucfirst($local),
                'role'       => $roleLabel,
                'kind'       => 'service',
            ];
        }

        // 3. Personne nommée (jean.dupont, m.martin).
        $person = $this->parsePersonName($local);
        if ($person !== null) {
            return [
                'first_name' => $person['first_name'],
                'last_name'  => $person['last_name'],
                'role'       => 'à qualifier',
                'kind'       => 'person',
            ];
        }

        // 4. Fallback : boîte générique non catégorisée.
        return [
            'first_name' => null,
            'last_name'  => ucfirst($local),
            'role'       => 'Service',
            'kind'       => 'service',
        ];
    }

    /** Déduit un libellé de rôle depuis le préfixe email, ou null si ce n'est pas une boîte service connue. */
    private function roleLabelFromLocalPart(string $local): ?string
    {
        foreach (self::SERVICE_ROLE_MAP as $label => $keywords) {
            foreach ($keywords as $kw) {
                if ($local === $kw) {
                    return $label;
                }
                if (strlen($kw) >= 4 && str_contains($local, $kw)) {
                    return $label;
                }
            }
        }
        return null;
    }

    /**
     * Parse un préfixe « prénom.nom » (séparateurs . _ -) en nom exploitable.
     *
     * @return array{first_name: string, last_name: string}|null
     */
    private function parsePersonName(string $local): ?array
    {
        if (preg_match('/^([a-zà-ÿ]+)[._\-]([a-zà-ÿ]{2,})$/u', $local, $m)) {
            return ['first_name' => ucfirst($m[1]), 'last_name' => ucfirst($m[2])];
        }
        return null;
    }

    /** Mappe le statut du validateur MX vers l'enum contacts.email_status. */
    private function mapMxStatus(string $status): string
    {
        return match ($status) {
            'verified' => 'valid',
            'risky'    => 'catchall',
            'role'     => 'role',
            default    => 'unknown',
        };
    }

    /**
     * Insère une fiche contact (idempotent via UNIQUE(workspace_id, normalized_hash)).
     *
     * @param  array{first_name: string|null, last_name: string, role: string, kind: string}  $class
     */
    private function persistContact(Company $company, string $email, string $emailStatus, ?array $validation, array $class): void
    {
        try {
            DB::table('contacts')->insertOrIgnore([[
                'workspace_id'     => $company->workspace_id,
                'company_id'       => $company->id,
                'first_name'       => $class['first_name'],
                'last_name'        => $class['last_name'],
                'role'             => $class['role'],
                'email'            => $email,
                'email_status'     => $emailStatus,
                'discovery_source' => 'mentions-legales',
                'sources'          => json_encode(['mentions-legales']),
                'metadata'         => json_encode([
                    'kind'          => $class['kind'],
                    'mx_validation' => $validation,
                ]),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]]);
        } catch (\Throwable $e) {
            Log::warning('contact insert (all-channels) failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

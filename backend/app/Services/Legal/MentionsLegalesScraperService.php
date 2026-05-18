<?php

namespace App\Services\Legal;

use App\Models\Company;
use App\Services\Email\MxEmailValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scrape la page « Mentions Légales » (ou variantes) d'un site web pour extraire :
 *  - email contact générique (companies.email_generic)
 *  - téléphone (companies.phone)
 *  - éventuellement contacts dirigeants (table contacts via insertOrIgnore)
 *    si le nom matche un dirigeant remonté par AnnuaireEntreprises.
 *
 * Skip silently pages JS-rendered (< 500 octets de texte parsé).
 */
class MentionsLegalesScraperService
{
    /**
     * Sprint H10 (2026-05-18) — Élargi de 8 à 18 paths.
     * Ordre : pages les plus probables d'avoir email/phone visibles d'abord
     * (contact > mentions légales > a-propos > home). Early exit dès qu'on a
     * email_generic + phone (cf. scrape() pour la logique de short-circuit).
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

    private const EMAIL_BLACKLIST_PREFIXES = ['no-reply@', 'noreply@', 'postmaster@', 'abuse@', 'webmaster@'];

    private const MIN_BODY_LENGTH = 500;

    public function __construct(
        private readonly ?MxEmailValidator $emailValidator = null,
    ) {}

    /**
     * Scrape mentions légales et met à jour la company.
     * Retourne true si au moins une info utile a été trouvée.
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

        $found = false;

        // Email générique — Sprint H7 : on n'accepte que les emails validés MX
        // (filtre les domaines invalides + disposables + role-based).
        if (! $company->email_generic) {
            $email = $this->extractFirstUsableEmail($body);
            if ($email) {
                $accept = true;
                if ($this->emailValidator !== null) {
                    $status = $this->emailValidator->quickStatus($email);
                    // role/disposable/invalid → on rejette pour email_generic
                    // (verified et risky sont OK : un email générique peut être chez
                    // un free provider légitimement pour une TPE)
                    if (in_array($status, ['invalid', 'disposable', 'role'], true)) {
                        $accept = false;
                        Log::debug('Skipping email_generic via MX validator', [
                            'email'  => $email,
                            'status' => $status,
                        ]);
                    }
                }
                if ($accept) {
                    $company->email_generic = $email;
                    $found = true;
                }
            }
        }

        // Téléphone
        if (! $company->phone) {
            $phone = $this->extractFirstPhone($body);
            if ($phone) {
                $company->phone = $phone;
                $found = true;
            }
        }

        if ($found) {
            $company->save();
        }

        // Contacts dirigeants : si on a déjà des noms (signals.legal.dirigeants) on les match
        $signals = $company->signals ?? [];
        $dirigeants = $signals['legal']['dirigeants'] ?? [];
        if (! empty($dirigeants) && is_array($dirigeants)) {
            $this->matchEmailsToContacts($company, $body, $dirigeants);
        }

        return $found;
    }

    /**
     * Sprint H10 — Itère sur les paths, fusionne tous les bodies utiles trouvés
     * (concat des HTML des pages contact + mentions + about + home) pour avoir
     * un maximum de signaux email/phone à parser ensuite. Stop early si on a
     * déjà accumulé suffisamment de contenu (10K chars).
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

    private function extractFirstUsableEmail(string $body): ?string
    {
        if (! preg_match_all('/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/', $body, $matches)) {
            return null;
        }
        foreach ($matches[0] as $email) {
            $lower = strtolower($email);
            $skip = false;
            foreach (self::EMAIL_BLACKLIST_PREFIXES as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if (! $skip) {
                return $lower;
            }
        }
        return null;
    }

    private function extractFirstPhone(string $body): ?string
    {
        if (preg_match('/\b0[1-9](?:[\s.\-]?\d{2}){4}\b/', $body, $m)) {
            return preg_replace('/[\s.\-]/', '', $m[0]);
        }
        return null;
    }

    /**
     * Si un dirigeant connu (depuis annuaire) apparaît dans le HTML avec un email proche,
     * créer le contact correspondant.
     *
     * @param  array<int, array{first_name?: string, last_name: string, role?: string}>  $dirigeants
     */
    private function matchEmailsToContacts(Company $company, string $body, array $dirigeants): void
    {
        if (! preg_match_all('/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/', $body, $emailMatches)) {
            return;
        }
        $emails = array_unique(array_map('strtolower', $emailMatches[0]));

        foreach ($dirigeants as $rep) {
            $first = strtolower((string) ($rep['first_name'] ?? ''));
            $last = strtolower((string) ($rep['last_name'] ?? ''));
            if (! $last) {
                continue;
            }
            $candidates = [];
            foreach ($emails as $email) {
                $local = strtolower(strstr($email, '@', true) ?: '');
                if ($local === '') {
                    continue;
                }
                $skip = false;
                foreach (self::EMAIL_BLACKLIST_PREFIXES as $prefix) {
                    if (str_starts_with($email, $prefix)) { $skip = true; break; }
                }
                if ($skip) { continue; }
                if ($first && str_contains($local, $first)) {
                    $candidates[] = $email;
                } elseif (str_contains($local, $last)) {
                    $candidates[] = $email;
                }
            }
            if (empty($candidates)) {
                continue;
            }
            // Sprint H7 — Validation MX maison avant insert (pas de pattern spéculatif :
            // seuls les emails RÉELS trouvés dans le HTML sont stockés, et tagués selon
            // le résultat MX validator → la base ne contient QUE des emails fiables).
            $bestEmail = $candidates[0];
            $emailStatus = 'unknown';
            $validation = null;
            if ($this->emailValidator !== null) {
                $validation = $this->emailValidator->validate($bestEmail);
                $emailStatus = match ($validation['status']) {
                    'verified'   => 'valid',
                    'risky'      => 'catchall',
                    'role'       => 'role',
                    'disposable' => 'invalid',
                    'invalid'    => 'invalid',
                    default      => 'unknown',
                };
                if ($emailStatus === 'invalid') {
                    Log::debug('Skipping invalid email from mentions-legales', [
                        'email'  => $bestEmail,
                        'reason' => $validation['reason'] ?? null,
                    ]);
                    continue;
                }
            }
            try {
                DB::table('contacts')->insertOrIgnore([[
                    'workspace_id'      => $company->workspace_id,
                    'company_id'        => $company->id,
                    'first_name'        => $rep['first_name'] ?? null,
                    'last_name'         => $rep['last_name'],
                    'role'              => $rep['role'] ?? 'dirigeant',
                    'email'             => $bestEmail,
                    'email_status'      => $emailStatus,
                    'discovery_source'  => 'mentions-legales',
                    'sources'           => json_encode(['mentions-legales']),
                    'metadata'          => json_encode([
                        'matched_dirigeant' => $rep,
                        'mx_validation'     => $validation,
                    ]),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]]);
            } catch (\Throwable $e) {
                Log::warning('contact insert from mentions-legales failed', [
                    'rep' => $rep, 'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

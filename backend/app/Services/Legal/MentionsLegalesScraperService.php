<?php

namespace App\Services\Legal;

use App\Models\Company;
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
    private const PATHS = [
        '/mentions-legales',
        '/mentions-legales.html',
        '/legal',
        '/imprint',
        '/a-propos/mentions-legales',
        '/cgv',
        '/cgu',
        '/conditions-generales',
    ];

    private const HTTP_TIMEOUT_SECONDS = 10;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const EMAIL_BLACKLIST_PREFIXES = ['no-reply@', 'noreply@', 'postmaster@', 'abuse@', 'webmaster@'];

    private const MIN_BODY_LENGTH = 500;

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

        // Email générique
        if (! $company->email_generic) {
            $email = $this->extractFirstUsableEmail($body);
            if ($email) {
                $company->email_generic = $email;
                $found = true;
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

    private function fetchAnyMentionsLegalesPage(string $website): ?string
    {
        $base = rtrim($website, '/');
        foreach (self::PATHS as $path) {
            $body = $this->fetch($base . $path);
            if ($body !== null && strlen(strip_tags($body)) >= self::MIN_BODY_LENGTH) {
                return $body;
            }
        }
        return null;
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }
            return $response->body();
        } catch (\Throwable $e) {
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
            try {
                DB::table('contacts')->insertOrIgnore([[
                    'workspace_id'      => $company->workspace_id,
                    'company_id'        => $company->id,
                    'first_name'        => $rep['first_name'] ?? null,
                    'last_name'         => $rep['last_name'],
                    'role'              => $rep['role'] ?? 'dirigeant',
                    'email'             => $candidates[0],
                    'email_status'      => 'unknown',
                    'discovery_source'  => 'mentions-legales',
                    'sources'           => json_encode(['mentions-legales']),
                    'metadata'          => json_encode(['matched_dirigeant' => $rep]),
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

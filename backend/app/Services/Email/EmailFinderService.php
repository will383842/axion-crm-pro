<?php

namespace App\Services\Email;

use App\Contracts\SmtpProber;
use App\Data\Email\SmtpProbeResult;
use App\Services\Dedup\DeduplicationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Email finder — génère 18 patterns candidats puis cascade SMTP N1-N5.
 * Score 0-100 retourné. Cf. spec/06_email_finder_validation.md.
 *
 * Hardening Sprint Pipeline 360° (2026-05-17) :
 * - Rate limit Redis 50 probes/h/domain (clef `smtp_probe_rate:{domain}`).
 * - Skip blacklist gros providers (toujours catchall, infos inutiles).
 * - Catch \Throwable autour de chaque probe → mark invalid au lieu de crash.
 */
class EmailFinderService
{
    /**
     * Providers grand public → toujours catchall, probe inutile.
     */
    public const CATCHALL_PROVIDERS = [
        'gmail.com', 'outlook.fr', 'outlook.com',
        'yahoo.fr', 'yahoo.com',
        'free.fr', 'orange.fr', 'wanadoo.fr',
        'hotmail.fr', 'hotmail.com', 'laposte.net',
        'live.fr', 'live.com', 'icloud.com',
    ];

    private const PROBE_RATE_LIMIT_PER_HOUR = 50;

    private const PROBE_RATE_LIMIT_TTL_SECONDS = 3600;
    public const PATTERNS = [
        '{first}.{last}@{domain}',
        '{f}.{last}@{domain}',
        '{first}{last}@{domain}',
        '{f}{last}@{domain}',
        '{first}_{last}@{domain}',
        '{f}_{last}@{domain}',
        '{first}-{last}@{domain}',
        '{f}-{last}@{domain}',
        '{first}@{domain}',
        '{last}@{domain}',
        '{last}.{first}@{domain}',
        '{last}{first}@{domain}',
        '{last}.{f}@{domain}',
        '{last}{f}@{domain}',
        '{first}.{last2}@{domain}',
        '{f}{last3}@{domain}',
        'contact@{domain}',
        'info@{domain}',
    ];

    public function __construct(
        private readonly SmtpProber $prober,
        private readonly DeduplicationService $dedup,
    ) {}

    /**
     * @return list<SmtpProbeResult> ordonné par score DESC
     */
    public function find(string $firstName, string $lastName, string $domain, ?string $knownPattern = null): array
    {
        if (! $this->validDomain($domain)) {
            return [];
        }

        // Skip blacklist gros providers grand public — toujours catchall, infos inutiles
        if (in_array(strtolower($domain), self::CATCHALL_PROVIDERS, true)) {
            return [];
        }

        $candidates = $knownPattern
            ? [$this->renderPattern($knownPattern, $firstName, $lastName, $domain)]
            : $this->generateCandidates($firstName, $lastName, $domain);

        // Dedup contre opt-out + cache validation
        $candidates = array_values(array_unique($candidates));
        $results = [];

        foreach ($candidates as $email) {
            if ($this->dedup->isOptedOut(email: $email)) {
                continue;
            }
            $cached = $this->dedup->getEmailValidationCache($email);
            if ($cached) {
                $results[] = new SmtpProbeResult(
                    email: $email,
                    status: (string) $cached->status,
                    score: (int) $cached->score,
                    mxHost: $cached->mx_host,
                    isCatchAll: (bool) $cached->is_catchall,
                    isDisposable: (bool) $cached->is_disposable,
                    isRole: (bool) $cached->is_role,
                );
                continue;
            }

            // Rate limit Redis 50 probes/h/domain
            if (! $this->canProbeDomain($domain)) {
                Log::info('EmailFinder rate limit reached', ['domain' => $domain]);
                break;
            }

            try {
                $probe = $this->prober->probe($email);
            } catch (\Throwable $e) {
                Log::warning('SMTP probe threw, marking invalid', [
                    'email' => $email, 'error' => $e->getMessage(),
                ]);
                $probe = new SmtpProbeResult(
                    email: $email,
                    status: 'invalid',
                    score: 0,
                    mxHost: null,
                    isCatchAll: false,
                    isDisposable: false,
                    isRole: false,
                );
            }

            $this->dedup->setEmailValidationCache(
                $email, $probe->status, $probe->score, $probe->mxHost,
                $probe->isCatchAll, $probe->isDisposable, $probe->isRole,
            );
            $results[] = $probe;
            if ($probe->status === 'valid' && $probe->score >= 90) {
                break;
            }
        }

        usort($results, fn ($a, $b) => $b->score <=> $a->score);
        return $results;
    }

    /**
     * Vérifie + incrémente le rate limit Redis (50 probes/h/domain).
     * Fail-open : si Redis KO, on autorise (mieux que bloquer le pipeline).
     */
    private function canProbeDomain(string $domain): bool
    {
        $key = 'smtp_probe_rate:' . strtolower($domain);
        try {
            $count = (int) Redis::incr($key);
            if ($count === 1) {
                Redis::expire($key, self::PROBE_RATE_LIMIT_TTL_SECONDS);
            }
            return $count <= self::PROBE_RATE_LIMIT_PER_HOUR;
        } catch (\Throwable $e) {
            Log::debug('Redis rate limit check failed, fail-open', [
                'domain' => $domain, 'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /** @return list<string> */
    public function generateCandidates(string $firstName, string $lastName, string $domain): array
    {
        $first  = $this->normalize($firstName);
        $last   = $this->normalize($lastName);
        $f      = $first ? mb_substr($first, 0, 1) : '';
        $last2  = mb_substr($last, 0, 2);
        $last3  = mb_substr($last, 0, 3);

        $vars = [
            '{first}' => $first, '{last}' => $last,
            '{f}'     => $f,
            '{last2}' => $last2, '{last3}' => $last3,
            '{domain}'=> $domain,
        ];

        $out = [];
        foreach (self::PATTERNS as $tpl) {
            $email = strtolower(strtr($tpl, $vars));
            if ($this->validEmail($email)) {
                $out[] = $email;
            }
        }
        return array_values(array_unique($out));
    }

    public function renderPattern(string $pattern, string $firstName, string $lastName, string $domain): string
    {
        return strtolower(strtr($pattern, [
            '{first}' => $this->normalize($firstName),
            '{last}'  => $this->normalize($lastName),
            '{f}'     => mb_substr($this->normalize($firstName), 0, 1),
            '{last2}' => mb_substr($this->normalize($lastName), 0, 2),
            '{last3}' => mb_substr($this->normalize($lastName), 0, 3),
            '{domain}'=> $domain,
        ]));
    }

    public function detectPatternFromKnownEmails(array $emails, string $domain): ?string
    {
        // Si on a au moins 2 emails connus, on détecte le pattern dominant.
        $candidates = array_count_values(array_filter(array_map(function ($email) use ($domain) {
            $local = explode('@', $email)[0] ?? '';
            // Identifier les délimiteurs : . _ -
            return preg_match('/^[a-z]\.?[a-z]+$/i', $local) ? '{f}.{last}@{domain}'
                : (preg_match('/^[a-z]+\.[a-z]+$/i', $local) ? '{first}.{last}@{domain}'
                : (preg_match('/^[a-z][a-z]+$/i', $local) ? '{f}{last}@{domain}'
                : null));
        }, $emails)));
        arsort($candidates);
        return array_key_first($candidates);
    }

    private function normalize(string $input): string
    {
        $input = preg_replace('/[^\p{L}-]+/u', '', $input) ?? '';
        $input = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $input) ?: $input;
        return strtolower(str_replace(['-', "'"], ['', ''], $input));
    }

    private function validEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 254;
    }

    private function validDomain(string $domain): bool
    {
        return $domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain) === 1;
    }
}

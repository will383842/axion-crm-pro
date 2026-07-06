<?php

namespace App\Console\Commands;

use App\Models\Journalist;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * L4 — Extraction des contacts rédaction depuis les MENTIONS LÉGALES des sites
 * médias. v1 = « directeur / directrice de la publication » (mention LÉGALEMENT
 * OBLIGATOIRE sur tout site de presse français → fiable) + « rédacteur en chef ».
 *
 * ⚠️ DONNÉE PERSONNELLE (RGPD). Base légale = intérêt légitime B2B relations
 * presse. GATÉ par MEDIA_JOURNALISTS_ENABLED (refus si false). Chaque journaliste
 * porte `source_url` (transparence) ; `opt_out` + soft-delete gèrent opposition
 * et effacement. Scraping poli (1-2 requêtes/média, User-Agent identifié).
 *
 * REPRENABLE : ne traite que les médias avec site et SANS journaliste. Le volume
 * pouvant être grand, se lance en lots via --limit.
 */
class JournalistsScrapeOurs extends Command
{
    protected $signature = 'journalists:scrape-ours {--limit=200} {--batch=25}';

    protected $description = 'Extrait le directeur de publication / rédac chef depuis les mentions légales des médias (RGPD, gaté).';

    private const UA = 'Mozilla/5.0 (compatible; AxionMediaBot/1.0; +https://app.axion-crm-pro.com)';

    private const LEGAL_HINTS = ['mentions-legales', 'mentions_legales', 'mentionslegales', 'ours', 'qui-sommes-nous', 'legal', 'a-propos', 'about', 'contact'];

    public function handle(): int
    {
        if (! config('services.media.journalists_enabled', false)) {
            $this->error('Refusé : MEDIA_JOURNALISTS_ENABLED=false (donnée personnelle RGPD). Activez le flag après validation.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $processed = 0;
        $created = 0;

        $medias = Media::query()
            ->whereNotNull('website')
            ->doesntHave('journalists')
            ->limit($limit)
            ->get();

        foreach ($medias as $media) {
            $processed++;
            $people = $this->extractFromLegalPage($media->website);
            foreach ($people as $p) {
                $created += $this->upsertJournalist($media, $p['name'], $p['role'], $p['url']);
            }
            if ($processed % 25 === 0) {
                $this->info("  … {$processed} médias traités · {$created} contacts créés");
            }
        }

        $this->info("✅ Terminé : {$processed} médias · {$created} contacts rédaction extraits.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{name:string,role:string,url:string}>
     */
    private function extractFromLegalPage(string $siteUrl): array
    {
        $home = $this->fetch($siteUrl);
        if ($home === null) {
            return [];
        }
        // Trouve le lien vers une page « mentions légales / ours ».
        $legalUrl = $this->findLegalUrl($siteUrl, $home) ?? $siteUrl;
        $html = $legalUrl === $siteUrl ? $home : ($this->fetch($legalUrl) ?? $home);
        $text = $this->plain($html);

        $out = [];
        $namePat = "([A-ZÉÈÀ][\\p{L}\\-']+(?:\\s+[A-ZÉÈÀ][\\p{L}\\-']+){1,3})";
        $rules = [
            ['role' => 'Directeur de la publication', 're' => "/directeur(?:rice)?\\s+de\\s+(?:la\\s+)?publication\\s*[:\\-—]?\\s*{$namePat}/iu"],
            ['role' => 'Rédacteur en chef', 're' => "/r[ée]dacteur(?:rice)?\\s+en\\s+chef\\s*[:\\-—]?\\s*{$namePat}/iu"],
        ];
        foreach ($rules as $rule) {
            if (preg_match($rule['re'], $text, $m) && ! empty($m[1])) {
                $name = trim(preg_replace('/\s+/', ' ', $m[1]));
                if (mb_strlen($name) >= 5 && str_word_count($name) >= 2) {
                    $out[] = ['name' => $name, 'role' => $rule['role'], 'url' => $legalUrl];
                }
            }
        }

        return $out;
    }

    private function findLegalUrl(string $base, string $html): ?string
    {
        if (! preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $href) {
            $low = mb_strtolower($href);
            foreach (self::LEGAL_HINTS as $hint) {
                if (str_contains($low, $hint)) {
                    return $this->absolute($base, $href);
                }
            }
        }

        return null;
    }

    private function upsertJournalist(Media $media, string $name, string $role, string $sourceUrl): int
    {
        $parts = preg_split('/\s+/', $name, 2);
        $first = $parts[0] ?? null;
        $last = $parts[1] ?? null;

        $exists = Journalist::query()
            ->where('workspace_id', $media->workspace_id)
            ->where('media_id', $media->id)
            ->where('last_name', $last)
            ->where('first_name', $first)
            ->exists();
        if ($exists) {
            return 0;
        }

        Journalist::create([
            'workspace_id' => $media->workspace_id,
            'media_id'     => $media->id,
            'company_id'   => $media->company_id,
            'first_name'   => $first,
            'last_name'    => $last,
            'role'         => $role,
            'source'       => 'mentions-legales',
            'source_url'   => mb_substr($sourceUrl, 0, 500),
            'opt_out'      => false,
        ]);

        return 1;
    }

    private function fetch(string $url): ?string
    {
        try {
            $resp = Http::timeout(8)->connectTimeout(4)->withHeaders(['User-Agent' => self::UA])->get($url);

            return $resp->successful() ? (string) $resp->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function plain(string $html): string
    {
        return preg_replace('/\s+/', ' ', (string) strip_tags($html));
    }

    private function absolute(string $base, string $href): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $root = preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);
        if (str_starts_with($href, '/')) {
            return $root . $href;
        }

        return rtrim($base, '/') . '/' . ltrim($href, '/');
    }
}

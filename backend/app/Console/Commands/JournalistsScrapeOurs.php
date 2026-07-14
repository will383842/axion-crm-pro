<?php

namespace App\Console\Commands;

use App\Contracts\LLMClient;
use App\Data\LLM\LLMRequestData;
use App\Models\Journalist;
use App\Models\Media;
use App\Services\Legal\MentionsLegalesScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * L4 — Extraction des contacts rédaction depuis les pages OURS / mentions légales /
 * équipe des sites médias, via EXTRACTION LLM (Mistral).
 *
 * ⚠️ Remplace l'ancienne extraction par REGEX (directeur de publication / rédac chef),
 * trop fragile (~1 bon contact sur 14 : mise en page libre, intitulés variés, noms
 * multi-mots, HTML bruité). On récupère désormais le TEXTE agrégé des pages
 * (via {@see MentionsLegalesScraperService::fetchPagesText()}, 18 paths, UA rotation,
 * délais polis) puis on le confie au routeur LLM (use case `extract_journalists_from_page`,
 * Mistral small, mode JSON) qui renvoie une liste structurée de personnes nommées.
 *
 * ⚠️ DONNÉE PERSONNELLE (RGPD). Base légale = intérêt légitime B2B relations presse.
 * GATÉ par MEDIA_JOURNALISTS_ENABLED (refus si false). Chaque journaliste porte
 * `source_url` (transparence) ; `opt_out` + soft-delete gèrent opposition et
 * effacement (on ne recrée jamais un contact effacé/opposé → check `withTrashed`).
 *
 * REPRENABLE : ne traite que les médias avec site et SANS journaliste
 * (`doesntHave('journalists')`). Le volume pouvant être grand, se lance en lots
 * via --limit. Try/catch par média : un échec HTTP/LLM n'interrompt jamais le lot.
 * Coût maîtrisé : Mistral small + texte tronqué à 8000 caractères par média.
 */
class JournalistsScrapeOurs extends Command
{
    protected $signature = 'journalists:scrape-ours {--limit=200} {--batch=25} {--editorial : Cible uniquement la presse éditoriale (journal/revue/portail/agence/tv/radio/blog), pas les boîtes de production}';

    /** Types de médias où l'on trouve des journalistes/signatures (exclut prod. audiovisuelle + émissions). */
    private const EDITORIAL_TYPES = [
        'presse_journal', 'presse_revue', 'presse_autre', 'portail_web',
        'agence_presse', 'tv', 'radio', 'blog',
    ];

    protected $description = 'Extrait les journalistes (dir. publication / rédac chef / …) des pages ours/mentions légales des médias via LLM Mistral (RGPD, gaté).';

    /** Borne dure sur le texte envoyé au LLM (coût + le routeur tronque déjà ext_* à 8000). */
    private const MAX_TEXT_CHARS = 8000;

    public function __construct(
        private readonly LLMClient $llm,
        private readonly MentionsLegalesScraperService $scraper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('services.media.journalists_enabled', false)) {
            $this->error('Refusé : MEDIA_JOURNALISTS_ENABLED=false (donnée personnelle RGPD). Activez le flag après validation.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $processed = 0;
        $created = 0;

        $query = Media::query()
            ->whereNotNull('website')
            ->doesntHave('journalists');

        if ($this->option('editorial')) {
            // Cible la vraie presse : là où il y a des signatures. Exclut les boîtes de
            // production (media_family='audiovisual_production') et les émissions.
            $query->where('media_family', 'editorial')
                ->whereIn('media_type', self::EDITORIAL_TYPES);
        }

        $medias = $query
            ->orderByRaw("CASE media_type WHEN 'presse_journal' THEN 1 WHEN 'agence_presse' THEN 2 WHEN 'presse_revue' THEN 3 WHEN 'portail_web' THEN 4 ELSE 5 END")
            ->limit($limit)
            ->get();

        foreach ($medias as $media) {
            $processed++;
            try {
                $created += $this->processMedia($media);
            } catch (\Throwable $e) {
                // Un échec (HTTP, LLM, parse) sur un média ne casse jamais le lot.
                Log::warning('journalists:scrape-ours media failed', [
                    'media_id' => $media->id,
                    'website'  => $media->website,
                    'error'    => $e->getMessage(),
                ]);
            }

            if ($processed % 25 === 0) {
                $this->info("  … {$processed} médias traités · {$created} contacts créés");
            }
        }

        $this->info("✅ Terminé : {$processed} médias · {$created} journalistes extraits via LLM.");

        return self::SUCCESS;
    }

    private function processMedia(Media $media): int
    {
        $website = (string) $media->website;
        if ($website === '') {
            return 0;
        }

        $text = $this->scraper->fetchPagesText($website);
        if ($text === null) {
            return 0;
        }
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        }

        $sourceUrl = rtrim($website, '/');
        $people = $this->extractViaLlm($text);

        // Aucune personne renvoyée → on ne crée aucun bruit.
        $created = 0;
        foreach ($people as $p) {
            $created += $this->upsertJournalist($media, $p, $sourceUrl);
        }

        return $created;
    }

    /**
     * @return list<array{first_name:?string,last_name:?string,role:?string,beat:?string}>
     */
    private function extractViaLlm(string $text): array
    {
        $resp = $this->llm->complete(new LLMRequestData(
            useCaseSlug: 'extract_journalists_from_page',
            variables: ['ext_page_text' => $text],
        ));

        return $this->parsePeople($resp->text);
    }

    /**
     * Parse défensif : json_object Mistral renvoie {"journalists":[...]}, mais on
     * tolère aussi un tableau racine, d'autres clés usuelles, et des blocs ```json.
     *
     * @return list<array{first_name:?string,last_name:?string,role:?string,beat:?string}>
     */
    private function parsePeople(string $raw): array
    {
        $json = $this->decodeLlmJson($raw);
        if ($json === null) {
            return [];
        }

        $list = null;
        if (array_is_list($json)) {
            $list = $json;
        } else {
            foreach (['journalists', 'people', 'contacts', 'members'] as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    $list = $json[$key];
                    break;
                }
            }
        }
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $first = $this->cleanField($row['first_name'] ?? $row['firstName'] ?? null);
            $last = $this->cleanField($row['last_name'] ?? $row['lastName'] ?? null);
            // Le nom de famille est requis (unique index dedup + fiabilité).
            if ($last === null) {
                continue;
            }
            $out[] = [
                'first_name' => $first,
                'last_name'  => $last,
                'role'       => $this->cleanField($row['role'] ?? null),
                'beat'       => $this->cleanField($row['beat'] ?? null),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|list<mixed>|null */
    private function decodeLlmJson(string $raw): ?array
    {
        $text = trim($raw);
        // Retire un éventuel bloc markdown ```json … ```
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Dernier recours : extrait le premier objet/tableau JSON dans le texte.
        if (preg_match('/[\{\[].*[\}\]]/s', $text, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function cleanField(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $clean !== '' ? mb_substr($clean, 0, 160) : null;
    }

    /**
     * @param  array{first_name:?string,last_name:?string,role:?string,beat:?string}  $p
     */
    private function upsertJournalist(Media $media, array $p, string $sourceUrl): int
    {
        $last = $p['last_name'];
        if ($last === null) {
            return 0;
        }
        $first = $p['first_name'];

        // Dédup + RGPD : withTrashed pour ne JAMAIS recréer un contact effacé (droit
        // à l'effacement) ou déjà présent (reprise idempotente).
        $exists = Journalist::withTrashed()
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
            'role'         => $p['role'],
            'beat'         => $p['beat'],
            'source'       => 'ours-llm',
            'source_url'   => mb_substr($sourceUrl, 0, 500),
            'opt_out'      => false,
        ]);

        return 1;
    }
}

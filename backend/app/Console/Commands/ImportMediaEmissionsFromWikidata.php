<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Import des ÉMISSIONS TV / radio françaises + leurs présentateurs / producteurs
 * depuis Wikidata (endpoint SPARQL public https://query.wikidata.org/sparql).
 *
 * Deux passes SPARQL :
 *   - TV    : ?prog wdt:P31/wdt:P279* wd:Q15416   (émission de télévision)
 *   - radio : ?prog wdt:P31/wdt:P279* wd:Q1555508 (émission de radio)
 * filtrées France via wdt:P495 (pays d'origine) OU wdt:P17 (pays) = wd:Q142.
 *
 * Pour chaque émission on récupère : le diffuseur d'origine (wdt:P449 → chaîne),
 * le genre (wdt:P136 → editorial_theme), et les personnes rattachées :
 *   présentateur wdt:P371 · producteur wdt:P162 (rôle stocké dans journalists.role).
 * Les labels FR sont résolus via SERVICE wikibase:label (fallback en).
 *
 * Modèle relationnel :
 *   media (media_type='tv'|'radio')            = la CHAÎNE (parent)
 *     └─ media (media_type='tv_emission')      = l'ÉMISSION (parent_media_id → chaîne)
 *          └─ journalists (role=présentateur|producteur)
 *
 * Idempotent : la chaîne est matchée sur son nom normalisé parmi les médias
 * tv/radio existants (sinon créée, source='wikidata') ; l'émission est dédupliquée
 * sur socials->>'wikidata_id' PUIS sur (parent_media_id, nom normalisé) ; les
 * personnes via l'index unique (workspace_id, media_id, last_name, first_name).
 * Un 2e run n'ajoute donc aucun doublon.
 *
 * ⚠️ Wikidata impose un User-Agent descriptif (WMF User-Agent policy) — sans quoi
 * le service renvoie 403. On envoie « AxionCRM/1.0 (contact@axion-ia.com) ».
 */
class ImportMediaEmissionsFromWikidata extends Command
{
    protected $signature = 'media:import-emissions-wikidata {--limit=0 : Nombre max d\'émissions à traiter (0 = toutes)} {--dry-run : Compte sans écrire en base} {--workspace= : UUID du workspace cible}';

    protected $description = 'Importe les émissions TV/radio FR + présentateurs/producteurs depuis Wikidata (SPARQL).';

    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';

    private const USER_AGENT = 'AxionCRM/1.0 (contact@axion-ia.com)';

    /** Nombre d'émissions (DISTINCT ?prog) récupérées par requête SPARQL. */
    private const PAGE_SIZE = 200;

    /**
     * Deux passes : émission TV (Q15416) puis émission radio (Q1555508).
     *
     * @var array<int,array{qid:string,channel_type:string}>
     */
    private const PASSES = [
        ['qid' => 'Q15416', 'channel_type' => 'tv'],
        ['qid' => 'Q1555508', 'channel_type' => 'radio'],
    ];

    /** Cache des chaînes résolues cette exécution : nom normalisé → id media. */
    private array $channelCache = [];

    /**
     * Index chaînes tv/radio par nom AGRESSIVEMENT normalisé → id, construit une
     * fois par workspace (clé « workspaceId|aggNorm »). Alimenté aussi à la volée
     * quand on crée une chaîne, pour ne jamais recréer un doublon dans le même run.
     *
     * @var array<string,int>
     */
    private array $channelIndex = [];

    /** Workspaces dont l'index chaînes a déjà été chargé. @var array<string,bool> */
    private array $channelIndexBuilt = [];

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $workspaceId = $this->option('workspace') ?: Workspace::query()->orderBy('created_at')->value('id');
        if (! $workspaceId) {
            $this->error('Aucun workspace cible (base vide ?). Passez --workspace=UUID.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN : aucune écriture en base.');
        }

        $stats = [
            'emissions'    => 0,
            'emissions_new' => 0,
            'channels_new' => 0,
            'journalists'  => 0,
            'journalists_new' => 0,
        ];

        foreach (self::PASSES as $pass) {
            $this->info("Passe {$pass['channel_type']} (Wikidata {$pass['qid']})…");
            $offset = 0;

            while (true) {
                if ($limit > 0 && $stats['emissions'] >= $limit) {
                    break 1;
                }
                $pageSize = self::PAGE_SIZE;
                if ($limit > 0) {
                    $pageSize = min($pageSize, $limit - $stats['emissions']);
                }

                $rows = $this->querySparql($pass['qid'], $pageSize, $offset);
                if ($rows === null) {
                    return self::FAILURE;
                }
                if ($rows === []) {
                    break; // fin de pagination pour cette passe
                }

                $emissions = $this->groupRows($rows);
                $offset += $pageSize;

                foreach ($emissions as $emission) {
                    $this->processEmission($emission, $pass['channel_type'], $workspaceId, $dryRun, $stats);
                    if ($limit > 0 && $stats['emissions'] >= $limit) {
                        break;
                    }
                }

                // Politesse : Wikidata limite ~5 req/s par agent — micro-pause.
                usleep(300_000);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '✓ %d émissions traitées (%d nouvelles) · %d chaînes créées · %d personnes (%d nouvelles).',
            $stats['emissions'],
            $stats['emissions_new'],
            $stats['channels_new'],
            $stats['journalists'],
            $stats['journalists_new'],
        ));
        if ($dryRun) {
            $this->warn('DRY-RUN : rien n\'a été persisté.');
        }

        return self::SUCCESS;
    }

    /**
     * Exécute une requête SPARQL paginée (LIMIT/OFFSET sur les émissions DISTINCT).
     *
     * @return array<int,array<string,mixed>>|null  bindings, ou null en cas d'échec HTTP
     */
    private function querySparql(string $classQid, int $limit, int $offset): ?array
    {
        $query = $this->buildSparql($classQid, $limit, $offset);

        try {
            $resp = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'application/sparql-results+json',
            ])->timeout(90)->retry(3, 3000, throw: false)->get(self::SPARQL_ENDPOINT, [
                'query'  => $query,
                'format' => 'json',
            ]);
        } catch (\Throwable $e) {
            $this->error('Exception HTTP Wikidata : ' . $e->getMessage());

            return null;
        }

        if ($resp->status() === 429) {
            $this->error('Wikidata a renvoyé 429 (rate limit). Réessayez plus tard ou baissez le débit.');

            return null;
        }
        if (! $resp->successful()) {
            $this->error("Échec SPARQL HTTP {$resp->status()}.");

            return null;
        }

        $json = $resp->json();
        $bindings = $json['results']['bindings'] ?? null;

        return is_array($bindings) ? $bindings : [];
    }

    private function buildSparql(string $classQid, int $limit, int $offset): string
    {
        return <<<SPARQL
            SELECT ?prog ?progLabel ?genreLabel ?broadcasterLabel ?person ?personLabel ?role WHERE {
              {
                SELECT DISTINCT ?prog WHERE {
                  ?prog wdt:P31/wdt:P279* wd:{$classQid} .
                  { ?prog wdt:P495 wd:Q142 } UNION { ?prog wdt:P17 wd:Q142 }
                } ORDER BY ?prog LIMIT {$limit} OFFSET {$offset}
              }
              OPTIONAL { ?prog wdt:P136 ?genre. }
              OPTIONAL { ?prog wdt:P449 ?broadcaster. }
              OPTIONAL {
                { ?prog wdt:P371 ?person. BIND("présentateur" AS ?role) }
                UNION
                { ?prog wdt:P162 ?person. BIND("producteur" AS ?role) }
              }
              SERVICE wikibase:label { bd:serviceParam wikibase:language "fr,en". }
            }
            SPARQL;
    }

    /**
     * Regroupe les lignes SPARQL plates par émission (une émission = plusieurs
     * lignes à cause des OPTIONAL multi-valués genre/diffuseur/personne).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,array<string,mixed>>  indexé par QID d'émission
     */
    private function groupRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $progUri = $r['prog']['value'] ?? null;
            if (! $progUri) {
                continue;
            }
            $qid = $this->qidFromUri($progUri);
            $label = trim((string) ($r['progLabel']['value'] ?? ''));
            // Pas de libellé FR/EN → Wikidata renvoie le QID comme label : on saute.
            if ($label === '' || preg_match('/^Q\d+$/', $label)) {
                continue;
            }

            if (! isset($out[$qid])) {
                $out[$qid] = [
                    'qid'         => $qid,
                    'uri'         => $progUri,
                    'name'        => $label,
                    'genre'       => null,
                    'broadcaster' => null,
                    'people'      => [], // clé "role|personUri" → [role, name, uri]
                ];
            }

            if (isset($r['genreLabel']['value']) && $out[$qid]['genre'] === null) {
                $g = trim((string) $r['genreLabel']['value']);
                if ($g !== '' && ! preg_match('/^Q\d+$/', $g)) {
                    $out[$qid]['genre'] = mb_substr($g, 0, 120);
                }
            }
            if (isset($r['broadcasterLabel']['value']) && $out[$qid]['broadcaster'] === null) {
                $b = trim((string) $r['broadcasterLabel']['value']);
                if ($b !== '' && ! preg_match('/^Q\d+$/', $b)) {
                    $out[$qid]['broadcaster'] = mb_substr($b, 0, 240);
                }
            }

            $personUri = $r['person']['value'] ?? null;
            $personName = trim((string) ($r['personLabel']['value'] ?? ''));
            $role = trim((string) ($r['role']['value'] ?? ''));
            if ($personUri && $personName !== '' && $role !== '' && ! preg_match('/^Q\d+$/', $personName)) {
                $key = $role . '|' . $personUri;
                $out[$qid]['people'][$key] = [
                    'role' => $role,
                    'name' => $personName,
                    'uri'  => $personUri,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $emission
     * @param  array<string,int>  $stats
     */
    private function processEmission(array $emission, string $channelType, string $workspaceId, bool $dryRun, array &$stats): void
    {
        $stats['emissions']++;

        if ($dryRun) {
            // Compte les nouveautés sans écrire.
            if (! $this->emissionExists($emission, $workspaceId)) {
                $stats['emissions_new']++;
            }
            foreach ($emission['people'] as $p) {
                $stats['journalists']++;
            }

            return;
        }

        DB::transaction(function () use ($emission, $channelType, $workspaceId, &$stats) {
            $channelId = $this->resolveChannel($emission['broadcaster'], $channelType, $workspaceId, $stats);
            $emissionId = $this->upsertEmission($emission, $channelId, $channelType, $workspaceId, $stats);

            foreach ($emission['people'] as $p) {
                $stats['journalists']++;
                $this->upsertJournalist($p, $emissionId, $workspaceId, $stats);
            }
        });
    }

    /**
     * Trouve/crée la chaîne (media_type tv|radio) par nom normalisé.
     *
     * @param  array<string,int>  $stats
     */
    private function resolveChannel(?string $broadcaster, string $channelType, string $workspaceId, array &$stats): ?int
    {
        if (! $broadcaster) {
            return null;
        }
        $norm = $this->normalize($broadcaster);
        if ($norm === '') {
            return null;
        }

        $cacheKey = $workspaceId . '|' . $norm;
        if (isset($this->channelCache[$cacheKey])) {
            return $this->channelCache[$cacheKey];
        }

        // Match sur un média chaîne existant (tv/radio) — d'abord exact lower(name)
        // (index-friendly), puis repli PHP sur nom normalisé (sans accents/ponctuation)
        // pour ne dépendre d'aucune extension Postgres (unaccent).
        $existing = DB::table('media')
            ->where('workspace_id', $workspaceId)
            ->whereIn('media_type', ['tv', 'radio'])
            ->whereNull('deleted_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($broadcaster)])
            ->value('id');

        if (! $existing) {
            $existing = $this->matchChannelInPhp($broadcaster, $channelType, $workspaceId);
        }

        // Dernier recours avant de créer une chaîne fantôme : match sur nom
        // AGRESSIVEMENT normalisé (sans articles « la/le/les », sans suffixe
        // « (chaîne de télévision) », sans ponctuation) contre toutes les tv/radio.
        if (! $existing) {
            $existing = $this->matchChannelAggressive($broadcaster, $workspaceId);
        }

        if ($existing) {
            $this->channelCache[$cacheKey] = (int) $existing;

            return (int) $existing;
        }

        $now = now();
        $id = DB::table('media')->insertGetId([
            'workspace_id'  => $workspaceId,
            'name'          => mb_substr($broadcaster, 0, 240),
            'media_type'    => $channelType,
            'media_family'  => 'editorial',
            'diffusion_zone' => 'national',
            'website_status' => 'pending',
            'enrich_status' => 'pending',
            'source'        => 'wikidata',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $stats['channels_new']++;
        $this->channelCache[$cacheKey] = (int) $id;
        // Alimente l'index agressif pour éviter tout doublon plus loin dans le run.
        $agg = $this->aggressiveNormalize($broadcaster);
        if ($agg !== '') {
            $this->channelIndex[$workspaceId . '|' . $agg] = (int) $id;
        }

        return (int) $id;
    }

    /**
     * Match d'une chaîne sur nom AGRESSIVEMENT normalisé, via un index chargé une
     * fois par workspace. Retire accents/ponctuation, articles de tête (la/le/les/l')
     * et suffixes parenthétiques (« (chaîne de télévision) », « (radio) »…).
     */
    private function matchChannelAggressive(string $broadcaster, string $workspaceId): ?int
    {
        $this->buildChannelIndex($workspaceId);
        $agg = $this->aggressiveNormalize($broadcaster);
        if ($agg === '') {
            return null;
        }

        return $this->channelIndex[$workspaceId . '|' . $agg] ?? null;
    }

    /** Charge (une fois par workspace) l'index tv/radio → id sur nom agressif. */
    private function buildChannelIndex(string $workspaceId): void
    {
        if (isset($this->channelIndexBuilt[$workspaceId])) {
            return;
        }
        $this->channelIndexBuilt[$workspaceId] = true;

        DB::table('media')
            ->select('id', 'name')
            ->where('workspace_id', $workspaceId)
            ->whereIn('media_type', ['tv', 'radio'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use ($workspaceId) {
                foreach ($rows as $r) {
                    $agg = $this->aggressiveNormalize((string) $r->name);
                    if ($agg === '') {
                        continue;
                    }
                    // Premier arrivé gagne (id le plus petit = plus ancien/canonique).
                    $this->channelIndex[$workspaceId . '|' . $agg] ??= (int) $r->id;
                }
            });
    }

    /**
     * Normalisation AGRESSIVE d'un nom de chaîne pour le rapprochement :
     * minuscules, sans accents, sans suffixe parenthétique, sans article de tête,
     * alphanumérique compacté (espaces supprimés).
     */
    private function aggressiveNormalize(string $s): string
    {
        $s = $this->normalize($s); // minuscule, sans accents, alphanum + espaces
        // Retire un suffixe descriptif type « la chaine (chaine de television) »
        // (le parenthésé a déjà été aplati en mots par normalize()).
        $s = preg_replace('/\b(chaine|station|radio|television|tv) de (television|radio)\b/', ' ', $s) ?? $s;
        // Retire l'article de tête.
        $s = preg_replace('/^(la|le|les|l|the)\s+/', '', $s) ?? $s;
        // Compacte tout (les variantes d'espacement ne doivent pas départager).
        $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? '';

        return trim($s);
    }

    /**
     * Repli PHP pour matcher une chaîne sans dépendre de l'extension unaccent.
     */
    private function matchChannelInPhp(string $broadcaster, string $channelType, string $workspaceId): ?int
    {
        $norm = $this->normalize($broadcaster);
        $candidates = DB::table('media')
            ->select('id', 'name')
            ->where('workspace_id', $workspaceId)
            ->whereIn('media_type', ['tv', 'radio'])
            ->whereNull('deleted_at')
            ->whereRaw('lower(name) LIKE ?', ['%' . mb_strtolower(mb_substr($broadcaster, 0, 40)) . '%'])
            ->limit(50)
            ->get();
        foreach ($candidates as $c) {
            if ($this->normalize((string) $c->name) === $norm) {
                return (int) $c->id;
            }
        }

        return null;
    }

    /**
     * Insère/retrouve l'émission (dédup wikidata_id puis parent+nom normalisé).
     *
     * @param  array<string,mixed>  $emission
     * @param  array<string,int>  $stats
     */
    private function upsertEmission(array $emission, ?int $channelId, string $channelType, string $workspaceId, array &$stats): int
    {
        // 1) dédup sur le QID Wikidata (socials->wikidata_id).
        $existing = DB::table('media')
            ->where('workspace_id', $workspaceId)
            ->where('media_type', 'tv_emission')
            ->whereRaw("socials->>'wikidata_id' = ?", [$emission['qid']])
            ->whereNull('deleted_at')
            ->value('id');
        if ($existing) {
            return (int) $existing;
        }

        // 2) dédup sur (parent, nom normalisé).
        $norm = $this->normalize($emission['name']);
        $q = DB::table('media')
            ->where('workspace_id', $workspaceId)
            ->where('media_type', 'tv_emission')
            ->whereNull('deleted_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($emission['name'])]);
        if ($channelId) {
            $q->where('parent_media_id', $channelId);
        } else {
            $q->whereNull('parent_media_id');
        }
        $existing = $q->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $now = now();
        $id = DB::table('media')->insertGetId([
            'workspace_id'    => $workspaceId,
            'parent_media_id' => $channelId,
            'name'            => mb_substr($emission['name'], 0, 240),
            'media_type'      => 'tv_emission',
            'media_family'    => 'editorial',
            'editorial_theme' => $emission['genre'],
            'diffusion_zone'  => 'national',
            // On conserve le label diffuseur brut : il permet à
            // media:link-emissions-to-channels de rattacher a posteriori une
            // émission restée orpheline (chaîne pas encore en base au 1er import).
            'socials'         => json_encode(array_filter([
                'wikidata_id' => $emission['qid'],
                'broadcaster' => $emission['broadcaster'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE),
            'enrich_status'   => 'pending',
            'source'          => 'wikidata',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $stats['emissions_new']++;

        return (int) $id;
    }

    /**
     * Insère/retrouve un présentateur/producteur (index unique workspace+media+nom).
     *
     * @param  array{role:string,name:string,uri:string}  $person
     * @param  array<string,int>  $stats
     */
    private function upsertJournalist(array $person, int $emissionId, string $workspaceId, array &$stats): void
    {
        [$first, $last] = $this->splitName($person['name']);
        if ($last === null) {
            return; // nom mono-token inexploitable → on ignore
        }

        $existing = DB::table('journalists')
            ->where('workspace_id', $workspaceId)
            ->where('media_id', $emissionId)
            ->where('last_name', $last)
            ->where('first_name', $first)
            ->whereNull('deleted_at')
            ->value('id');
        if ($existing) {
            return;
        }

        $now = now();
        DB::table('journalists')->insert([
            'workspace_id' => $workspaceId,
            'media_id'     => $emissionId,
            'first_name'   => $first !== null ? mb_substr($first, 0, 120) : null,
            'last_name'    => mb_substr($last, 0, 120),
            'role'         => mb_substr($person['role'], 0, 160),
            'source'       => 'wikidata',
            'source_url'   => mb_substr($person['uri'], 0, 500),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $stats['journalists_new']++;
    }

    /**
     * Vérifie (dry-run) si une émission existe déjà, sans écrire.
     *
     * @param  array<string,mixed>  $emission
     */
    private function emissionExists(array $emission, string $workspaceId): bool
    {
        return DB::table('media')
            ->where('workspace_id', $workspaceId)
            ->where('media_type', 'tv_emission')
            ->whereRaw("socials->>'wikidata_id' = ?", [$emission['qid']])
            ->whereNull('deleted_at')
            ->exists();
    }

    private function qidFromUri(string $uri): string
    {
        $parts = explode('/', $uri);

        return end($parts);
    }

    /**
     * Normalisation nom : minuscules, sans accents, alphanum + espaces simples.
     */
    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';

        return trim($s);
    }

    /**
     * Découpe « Prénom Nom(s) » → [prénom, nom]. Dernier token = nom de famille.
     *
     * @return array{0:?string,1:?string}
     */
    private function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/', ' ', $full) ?? '');
        if ($full === '') {
            return [null, null];
        }
        $parts = explode(' ', $full);
        if (count($parts) === 1) {
            return [null, null]; // mono-token : pas exploitable en prénom/nom
        }
        $last = array_pop($parts);
        $first = implode(' ', $parts);

        return [$first, $last];
    }
}

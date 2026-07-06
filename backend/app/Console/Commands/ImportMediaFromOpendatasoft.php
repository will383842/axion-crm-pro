<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ingestion des registres officiels de la CPPAP (data.culture.gouv.fr, portail
 * Opendatasoft) dans la table `media`. Sources :
 *   - cppap  : Liste des publications de presse (~4 864 titres)
 *   - spel   : Services de presse en ligne reconnus (~1 331, AVEC url du site)
 *   - agences: Agences de presse agréées (~176)
 *
 * Idempotent = full-refresh PAR SOURCE (DELETE source=… puis ré-insertion) →
 * relançable sans doublon. Récupère TOUT le dataset via l'endpoint /exports/json.
 *
 * ⚠️ Périodicité (quotidien/hebdo/mensuel) NON fournie par ces jeux de données →
 * reste NULL à ce stade (à affiner plus tard).
 */
class ImportMediaFromOpendatasoft extends Command
{
    protected $signature = 'media:import-opendatasoft {source : cppap|spel|agences} {--workspace=}';

    protected $description = 'Importe les médias depuis les registres CPPAP (publications, services en ligne, agences).';

    /** @var array<string,array<string,mixed>> */
    private const SOURCES = [
        'cppap' => [
            'dataset'    => 'liste-des-publications-de-presse',
            'source_tag' => 'cppap',
            'media_type' => 'presse_autre',
            'map'        => ['name' => 'titre', 'publisher' => 'editeur', 'department' => 'departement', 'cppap' => 'ndeg_cppap'],
        ],
        'spel' => [
            'dataset'    => 'liste-des-services-de-presse-en-ligne-reconnus',
            'source_tag' => 'spel',
            'media_type' => 'portail_web',
            'map'        => ['name' => 'service', 'publisher' => 'editeur', 'department' => 'departement', 'cppap' => 'numero_cppap', 'website' => 'url'],
        ],
        'agences' => [
            'dataset'    => 'liste-des-agences-de-presse-agreees',
            'source_tag' => 'agence',
            'media_type' => 'agence_presse',
            'map'        => ['name' => 'identification_denomination_sociale'],
        ],
    ];

    private const PORTAL = 'https://data.culture.gouv.fr';

    public function handle(): int
    {
        $key = $this->argument('source');
        if (! isset(self::SOURCES[$key])) {
            $this->error("Source inconnue « {$key} ». Valeurs : cppap | spel | agences.");

            return self::FAILURE;
        }
        $cfg = self::SOURCES[$key];

        $workspaceId = $this->option('workspace') ?: Workspace::query()->orderBy('created_at')->value('id');
        if (! $workspaceId) {
            $this->error('Aucun workspace cible (base vide ?). Passez --workspace=UUID.');

            return self::FAILURE;
        }

        // On ne récupère QUE les champs utilisés (via ?select=) : exclut notamment
        // les polygones `geom`/`centroid` (dataset publications de presse) qui font
        // exploser le payload → timeout. Payload minimal = import rapide et fiable.
        $select = implode(',', array_values(array_unique($cfg['map'])));
        $url = self::PORTAL . "/api/explore/v2.1/catalog/datasets/{$cfg['dataset']}/exports/json?select=" . rawurlencode($select);
        $this->info("Téléchargement {$cfg['dataset']} …");

        $resp = Http::timeout(120)->retry(2, 2000)->acceptJson()->get($url);
        if (! $resp->successful()) {
            $this->error("Échec HTTP {$resp->status()} sur {$url}");

            return self::FAILURE;
        }
        $records = $resp->json();
        if (! is_array($records)) {
            $this->error('Réponse inattendue (JSON non-liste).');

            return self::FAILURE;
        }
        $this->info(count($records) . ' enregistrements reçus.');

        $now = now();
        $rows = [];
        foreach ($records as $rec) {
            $name = trim((string) ($rec[$cfg['map']['name']] ?? ''));
            if ($name === '' || $name === '-') {
                continue;
            }
            $website = isset($cfg['map']['website']) ? $this->normalizeUrl($rec[$cfg['map']['website']] ?? null) : null;
            $rows[] = [
                'workspace_id'    => $workspaceId,
                'name'            => mb_substr($name, 0, 240),
                'media_type'      => $cfg['media_type'],
                'publisher'       => isset($cfg['map']['publisher']) ? mb_substr(trim((string) ($rec[$cfg['map']['publisher']] ?? '')), 0, 240) ?: null : null,
                'department_code' => isset($cfg['map']['department']) ? mb_substr(trim((string) ($rec[$cfg['map']['department']] ?? '')), 0, 5) ?: null : null,
                'cppap_number'    => isset($cfg['map']['cppap']) ? mb_substr(trim((string) ($rec[$cfg['map']['cppap']] ?? '')), 0, 40) ?: null : null,
                'website'         => $website,
                'website_status'  => $website ? 'found' : 'pending',
                'enrich_status'   => 'pending',
                'source'          => $cfg['source_tag'],
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        // Full-refresh idempotent par source (transaction).
        $inserted = 0;
        DB::transaction(function () use ($cfg, $workspaceId, $rows, &$inserted) {
            DB::table('media')
                ->where('source', $cfg['source_tag'])
                ->where('workspace_id', $workspaceId)
                ->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('media')->insert($chunk);
                $inserted += count($chunk);
            }
        });

        $this->info("✓ {$inserted} médias importés (source={$cfg['source_tag']}).");
        $withSite = collect($rows)->whereNotNull('website')->count();
        if ($withSite > 0) {
            $this->info("  dont {$withSite} avec un site web fourni par la source.");
        }

        return self::SUCCESS;
    }

    private function normalizeUrl(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '-') {
            return null;
        }
        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . ltrim($raw, '/');
        }

        return filter_var($raw, FILTER_VALIDATE_URL) ? mb_substr($raw, 0, 500) : null;
    }
}

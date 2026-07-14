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
 * ⚠️ PÉRIODICITÉ — enquête 2026-07-14 (audit).
 * Le dataset `liste-des-publications-de-presse` (data.culture.gouv.fr) expose
 * UNIQUEMENT : titre, editeur, forme_juridique, departement, ndeg_cppap, geom,
 * centroid. AUCUN champ périodicité/rythme/parution. La seule piste restante est
 * la LETTRE du n° CPPAP (ex. « 1028 G 81012 »), mais après vérification cette
 * lettre encode le RÉGIME (postal/fiscal, cat. de la publication) et NON la
 * périodicité — aucun mapping lettre→périodicité fiable n'a pu être confirmé.
 * Décision : on laisse `periodicity` = NULL plutôt que de fabriquer un classement
 * faux (un faux quotidien/hebdo est pire qu'un NULL honnête). Le point de
 * dérivation est centralisé dans {@see self::derivePeriodicity()} : si une source
 * fiable apparaît un jour, on ne modifie que cette méthode + on relance
 * `media:backfill-periodicity`.
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
            $cppap = isset($cfg['map']['cppap']) ? mb_substr(trim((string) ($rec[$cfg['map']['cppap']] ?? '')), 0, 40) ?: null : null;
            $rows[] = [
                'workspace_id'    => $workspaceId,
                'name'            => mb_substr($name, 0, 240),
                'media_type'      => $cfg['media_type'],
                'media_family'    => 'editorial',
                'periodicity'     => self::derivePeriodicity($cppap, $rec),
                'publisher'       => isset($cfg['map']['publisher']) ? mb_substr(trim((string) ($rec[$cfg['map']['publisher']] ?? '')), 0, 240) ?: null : null,
                'department_code' => isset($cfg['map']['department']) ? mb_substr(trim((string) ($rec[$cfg['map']['department']] ?? '')), 0, 5) ?: null : null,
                'cppap_number'    => $cppap,
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

    /**
     * Valeurs normalisées de périodicité (SSOT) — utilisées si une source fiable
     * devient disponible. Ordre = fréquence décroissante.
     *
     * @var list<string>
     */
    public const PERIODICITIES = [
        'quotidien', 'hebdomadaire', 'bimensuel', 'mensuel',
        'bimestriel', 'trimestriel', 'semestriel', 'annuel', 'autre',
    ];

    /**
     * Dérive la périodicité normalisée d'un titre CPPAP/SPEL.
     *
     * ⚠️ ÉTAT 2026-07-14 : AUCUNE source fiable disponible (cf. docblock de classe).
     *  - Le dataset ne porte pas de champ périodicité (on tolère malgré tout un champ
     *    `periodicite`/`rythme`/`parution` dans $rec au cas où l'API l'ajouterait un
     *    jour ; il serait alors normalisé via {@see self::normalizePeriodicity()}).
     *  - La lettre du n° CPPAP encode le RÉGIME, pas la périodicité → NON utilisée.
     * → Retour NULL par défaut (on refuse de fabriquer un faux classement).
     *
     * @param  array<string,mixed>  $rec  enregistrement source brut (best-effort)
     */
    public static function derivePeriodicity(?string $cppap, array $rec = []): ?string
    {
        // Cas 1 (futur-proof) : l'API expose enfin un champ périodicité explicite.
        foreach (['periodicite', 'rythme', 'parution', 'frequence', 'periodicity'] as $field) {
            if (isset($rec[$field]) && trim((string) $rec[$field]) !== '') {
                $norm = self::normalizePeriodicity((string) $rec[$field]);
                if ($norm !== null) {
                    return $norm;
                }
            }
        }

        // Cas 2 : dérivation depuis la lettre du n° CPPAP — NON FIABLE (régime, pas
        // périodicité) → volontairement laissé NULL. Ne PAS deviner ici.
        unset($cppap);

        return null;
    }

    /**
     * Mappe un libellé de périodicité brut (FR) vers le vocabulaire SSOT, ou null.
     */
    public static function normalizePeriodicity(string $raw): ?string
    {
        $s = mb_strtolower(trim($raw));
        if ($s === '') {
            return null;
        }

        return match (true) {
            str_contains($s, 'quotidien') || str_contains($s, 'journalier')        => 'quotidien',
            str_contains($s, 'hebdomadaire') || str_contains($s, 'hebdo')          => 'hebdomadaire',
            str_contains($s, 'bimensuel')                                          => 'bimensuel',
            str_contains($s, 'bimestriel')                                         => 'bimestriel',
            str_contains($s, 'trimestriel')                                        => 'trimestriel',
            str_contains($s, 'semestriel')                                         => 'semestriel',
            str_contains($s, 'mensuel')                                            => 'mensuel',
            str_contains($s, 'annuel') || str_contains($s, 'annuelle')             => 'annuel',
            default                                                                => 'autre',
        };
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

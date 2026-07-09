<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ingestion des RADIOS FM + CHAÎNES TV *autorisées* par l'ARCOM dans la table
 * `media`, au niveau STATION/SERVICE (1 ligne = 1 radio / 1 chaîne).
 *
 * ── Source retenue ───────────────────────────────────────────────────────────
 * ARCOM — « Base de données sur la transparence des médias » (transposition
 * EMFA / European Media Freedom Act). Fichier XLSX officiel listant TOUS les
 * services de radio et de télévision autorisés pour une diffusion par voie
 * hertzienne terrestre (FM / DAB+ / TNT), classés par ordre alphabétique.
 *   https://www.arcom.fr/nous-connaitre-notre-institution/regulation-europeenne-et-internationale/base-de-donnees-sur-la-transparence-des-medias
 *
 * Colonnes du fichier (A..K) :
 *   A Nom du service de médias        → media.name
 *   B Nature du service (TV/Radio)    → media.media_type (radio|tv)
 *   C Catégorie (radio: A..E ; TV: NC)→ media.diffusion_zone (dérivée, cf. ci-dessous)
 *   D Dénomination sociale (éditeur)  → media.publisher (+ rattachement company)
 *   E Forme juridique
 *   F Adresse du siège social
 *   G Code postal du siège            → media.postcode + department_code (dérivé)
 *   H Commune du siège                → media.city
 *   I Pays du siège
 *   J/K Propriétaires directs/indirects (non ingérés)
 *
 * ── Pourquoi cette source et pas l'ANFR ─────────────────────────────────────
 * L'open data ANFR (« installations radioélectriques > 5 W ») est au niveau
 * ANTENNE/ÉMETTEUR (~172 000 lignes techniques) : c'est du bruit, sans nom de
 * station exploitable pour la FM. La base ARCOM EMFA est nativement au niveau
 * STATION (~1 244 services) avec le nom exact du service → qualité > quantité.
 *
 * ── Zone géographique ────────────────────────────────────────────────────────
 * Le fichier ne porte PAS de zone de diffusion explicite, mais :
 *   • pour la RADIO, la *catégorie* ARCOM encode le rayon de diffusion :
 *       A = associative locale        → local
 *       B = commerciale locale indép. → local
 *       C = locale/régionale réseau   → régional
 *       D = thématique nationale      → national
 *       E = généraliste nationale     → national
 *   • le code postal / la commune du SIÈGE SOCIAL donnent department_code +
 *     region_code (localisation factuelle de l'éditeur — pour les radios
 *     locales/associatives c'est aussi, en pratique, la zone couverte).
 * On ne fabrique donc aucune donnée : diffusion_zone = catégorie ARCOM,
 * department_code/city = siège social déclaré. Pour la TV (catégorie « NC »),
 * la zone reste NULL (le fichier ne permet pas de trancher national/local).
 *
 * ── Idempotence ──────────────────────────────────────────────────────────────
 * Full-refresh transactionnel PAR source (`DELETE source='arcom'` puis
 * ré-insertion) — comme media:import-opendatasoft. Relançable sans doublon.
 * Déduplication intra-lot par (nom normalisé + type).
 */
class ImportMediaFromArcom extends Command
{
    protected $signature = 'media:import-arcom
        {--limit=0 : Nombre max de stations à insérer (0 = toutes)}
        {--dry-run : Analyse et affiche les stats sans écrire en base}
        {--workspace= : UUID du workspace cible (défaut = premier créé)}
        {--url= : URL du XLSX ARCOM (défaut = base transparence des médias officielle)}
        {--match-companies : Tente de rattacher chaque média à une company existante (nom exact normalisé)}';

    protected $description = 'Importe les radios FM + chaînes TV autorisées par l\'ARCOM (niveau station, zone géo) dans `media`.';

    /** URL du fichier XLSX officiel (chemin daté côté ARCOM — surchargeable via --url). */
    public const DEFAULT_URL = 'https://www.arcom.fr/sites/default/files/2025-08/Arcom-base-donnees-transparence-des-medias.xlsx';

    public function handle(): int
    {
        $workspaceId = $this->option('workspace') ?: Workspace::query()->orderBy('created_at')->value('id');
        if (! $workspaceId) {
            $this->error('Aucun workspace cible (base vide ?). Passez --workspace=UUID.');

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: self::DEFAULT_URL);
        $this->info("Téléchargement du fichier ARCOM …\n  {$url}");

        $resp = Http::timeout(180)->retry(2, 2000)->get($url);
        if (! $resp->successful()) {
            $this->error("Échec HTTP {$resp->status()} sur {$url}");
            $this->line('  → Le chemin ARCOM est daté (ex. 2025-08). Vérifiez l\'URL à jour sur la page « Base de données sur la transparence des médias » et passez-la via --url=.');

            return self::FAILURE;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'arcom_') . '.xlsx';
        file_put_contents($tmp, $resp->body());

        try {
            $matrix = self::extractRows($tmp);
        } catch (\Throwable $e) {
            @unlink($tmp);
            $this->error('Impossible de parser le XLSX : ' . $e->getMessage());

            return self::FAILURE;
        }
        @unlink($tmp);

        $this->info(count($matrix) . ' lignes lues dans le fichier.');

        $now = now();
        $result = self::mapAndDedup($matrix, (string) $workspaceId, $now);
        $rows = $result['rows'];
        $duplicates = $result['duplicates'];
        $skipped = $result['skipped'];

        $limit = (int) $this->option('limit');
        if ($limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        // ── Stats ────────────────────────────────────────────────────────────
        $byType = [];
        $byZone = [];
        foreach ($rows as $r) {
            $byType[$r['media_type']] = ($byType[$r['media_type']] ?? 0) + 1;
            $z = $r['diffusion_zone'] ?? '(inconnue)';
            $byZone[$z] = ($byZone[$z] ?? 0) + 1;
        }
        $this->line('  Stations distinctes retenues : ' . count($rows));
        $this->line('  Doublons (nom+type) fusionnés : ' . $duplicates);
        $this->line('  Lignes ignorées (nom vide)    : ' . $skipped);
        foreach ($byType as $t => $n) {
            $this->line("    · type={$t} : {$n}");
        }
        foreach ($byZone as $z => $n) {
            $this->line("    · zone={$z} : {$n}");
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY-RUN : aucune écriture en base.');
            foreach (array_slice($rows, 0, 5) as $r) {
                $this->line(sprintf(
                    '    ex. %-28s %-6s zone=%-11s dept=%-3s %s',
                    mb_substr($r['name'], 0, 28),
                    $r['media_type'],
                    $r['diffusion_zone'] ?? '-',
                    $r['department_code'] ?? '-',
                    $r['city'] ?? ''
                ));
            }

            return self::SUCCESS;
        }

        // ── Rattachement company (optionnel, best-effort, une seule requête) ──
        if ($this->option('match-companies')) {
            $matched = $this->matchCompanies($rows, (string) $workspaceId);
            $this->info("  Rattachement company : {$matched} médias associés à une entreprise.");
        }

        // ── Full-refresh idempotent (transaction) ────────────────────────────
        $inserted = 0;
        DB::transaction(function () use ($workspaceId, $rows, &$inserted) {
            DB::table('media')
                ->where('source', 'arcom')
                ->where('workspace_id', $workspaceId)
                ->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('media')->insert($chunk);
                $inserted += count($chunk);
            }
        });

        $this->info("✓ {$inserted} stations ARCOM importées (source=arcom).");

        return self::SUCCESS;
    }

    /**
     * Extrait la matrice brute (lignes de données, hors en-tête) d'un XLSX ARCOM,
     * sans dépendance externe (ZipArchive + SimpleXML natifs). Chaque ligne est
     * un tableau indexé par colonne 0..N (A=0, B=1, …).
     *
     * @return array<int,array<int,string>>
     */
    public static function extractRows(string $xlsxPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            throw new \RuntimeException('Archive XLSX illisible.');
        }

        // Shared strings (le format ARCOM stocke le texte via <sst>).
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && $ss !== '') {
            $x = simplexml_load_string($ss);
            if ($x !== false) {
                foreach ($x->si as $si) {
                    $t = '';
                    if (isset($si->t)) {
                        $t = (string) $si->t;
                    } else {
                        foreach ($si->r as $r) {
                            $t .= (string) $r->t;
                        }
                    }
                    $shared[] = $t;
                }
            }
        }

        // Première feuille : sheet1.xml, sinon la première trouvée.
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_starts_with((string) $name, 'xl/worksheets/') && str_ends_with((string) $name, '.xml')) {
                    $sheetXml = $zip->getFromIndex($i);
                    break;
                }
            }
        }
        $zip->close();
        if ($sheetXml === false || $sheetXml === '') {
            throw new \RuntimeException('Feuille de calcul introuvable dans le XLSX.');
        }

        $x = simplexml_load_string($sheetXml);
        if ($x === false) {
            throw new \RuntimeException('Feuille de calcul XML invalide.');
        }

        $matrix = [];
        $first = true;
        foreach ($x->sheetData->row as $row) {
            if ($first) { // en-tête
                $first = false;

                continue;
            }
            $cells = [];
            $maxCol = -1;
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex($ref);
                $type = (string) $c['t'];
                if ($type === 'inlineStr') {
                    $val = (string) $c->is->t;
                } elseif ($type === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } else { // 'str' (formule) ou numérique
                    $val = (string) $c->v;
                }
                $cells[$col] = trim($val);
                $maxCol = max($maxCol, $col);
            }
            if ($maxCol < 0) {
                continue;
            }
            $line = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $line[$i] = $cells[$i] ?? '';
            }
            $matrix[] = $line;
        }

        return $matrix;
    }

    /**
     * Mappe la matrice ARCOM vers des lignes `media` prêtes à insérer, en
     * dédupliquant par (nom normalisé + type). Fonction pure (aucun accès DB).
     *
     * @param  array<int,array<int,string>>  $matrix
     * @return array{rows:array<int,array<string,mixed>>,duplicates:int,skipped:int}
     */
    public static function mapAndDedup(array $matrix, string $workspaceId, mixed $now): array
    {
        $rows = [];
        $seen = [];
        $duplicates = 0;
        $skipped = 0;

        foreach ($matrix as $line) {
            $name = trim((string) ($line[0] ?? ''));
            if ($name === '' || $name === '-') {
                $skipped++;

                continue;
            }
            $type = self::mapType((string) ($line[1] ?? ''));
            if ($type === null) {
                $skipped++;

                continue;
            }

            $key = self::dedupKey($name, $type);
            if (isset($seen[$key])) {
                $duplicates++;

                continue;
            }
            $seen[$key] = true;

            $category = trim((string) ($line[2] ?? ''));
            $publisher = trim((string) ($line[3] ?? ''));
            $postcode = preg_replace('/\s+/', '', (string) ($line[6] ?? ''));
            $city = trim((string) ($line[7] ?? ''));
            $country = trim((string) ($line[8] ?? ''));

            $isFrance = ($country === '' || stripos($country, 'france') !== false);
            $dept = $isFrance ? self::deptFromPostcode($postcode) : null;

            $rows[] = [
                'workspace_id'    => $workspaceId,
                'name'            => mb_substr($name, 0, 240),
                'media_type'      => $type,
                'diffusion_zone'  => self::zoneFromCategory($type, $category),
                'publisher'       => $publisher !== '' ? mb_substr($publisher, 0, 240) : null,
                'department_code' => $dept,
                'region_code'     => $dept ? self::regionFromDept($dept) : null,
                'city'            => $city !== '' ? mb_substr($city, 0, 160) : null,
                'postcode'        => $postcode !== '' ? mb_substr($postcode, 0, 10) : null,
                'enrich_status'   => 'pending',
                'source'          => 'arcom',
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        return ['rows' => $rows, 'duplicates' => $duplicates, 'skipped' => $skipped];
    }

    /**
     * Rattache best-effort les lignes à une company existante (match exact sur
     * la dénomination normalisée). Une seule requête (ANY) → pas de N+1.
     * Mute $rows en place (ajoute company_id + siren). Retourne le nb rattaché.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function matchCompanies(array &$rows, string $workspaceId): int
    {
        $publishers = [];
        foreach ($rows as $r) {
            if (! empty($r['publisher'])) {
                $publishers[$r['publisher']] = true;
            }
        }
        if ($publishers === []) {
            return 0;
        }
        $names = array_keys($publishers);

        // Map dénomination_normalized → {id, siren} pour ce workspace.
        $rowsDb = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereRaw('denomination_normalized = ANY(SELECT normalize_name(x) FROM unnest(?::text[]) AS x)', [
                '{' . implode(',', array_map(static fn ($n) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $n) . '"', $names)) . '}',
            ])
            ->select('id', 'siren', 'denomination_normalized')
            ->get();

        $lookup = [];
        foreach ($rowsDb as $c) {
            $lookup[$c->denomination_normalized] ??= ['id' => $c->id, 'siren' => $c->siren];
        }
        if ($lookup === []) {
            return 0;
        }

        $matched = 0;
        foreach ($rows as &$r) {
            if (empty($r['publisher'])) {
                continue;
            }
            $norm = self::normalizeForMatch((string) $r['publisher']);
            if (isset($lookup[$norm])) {
                $r['company_id'] = $lookup[$norm]['id'];
                $r['siren'] = $lookup[$norm]['siren'];
                $matched++;
            }
        }
        unset($r);

        return $matched;
    }

    // ─────────────────────────── Helpers purs ───────────────────────────────

    /** 'Radio' → 'radio' ; 'TV'/'Télévision' → 'tv' ; sinon null. */
    public static function mapType(string $nature): ?string
    {
        $n = mb_strtolower(trim($nature));
        if ($n === 'radio') {
            return 'radio';
        }
        if ($n === 'tv' || str_starts_with($n, 'tele') || str_starts_with($n, 'télé')) {
            return 'tv';
        }

        return null;
    }

    /**
     * diffusion_zone dérivée de la catégorie ARCOM (radio uniquement).
     * A,B → local ; C → régional ; D,E → national. TV/NC/inconnu → null.
     */
    public static function zoneFromCategory(string $type, string $category): ?string
    {
        if ($type !== 'radio') {
            return null;
        }

        return match (strtoupper(trim($category))) {
            'A', 'B' => 'local',
            'C'      => 'régional',
            'D', 'E' => 'national',
            default  => null,
        };
    }

    /** Code postal FR → code département (2 chiffres, 2A/2B Corse, 3 chiffres DOM). */
    public static function deptFromPostcode(?string $postcode): ?string
    {
        $pc = preg_replace('/\D+/', '', (string) $postcode);
        if ($pc === null || strlen($pc) < 4) {
            return null;
        }
        $pc = str_pad($pc, 5, '0', STR_PAD_LEFT);
        $p2 = substr($pc, 0, 2);
        if ($p2 === '20') { // Corse
            return ((int) substr($pc, 0, 5)) < 20200 ? '2A' : '2B';
        }
        if ($p2 === '97' || $p2 === '98') { // DOM/COM
            return substr($pc, 0, 3);
        }

        return $p2;
    }

    /** Département → code région INSEE (métropole + DOM). COM → null. */
    public static function regionFromDept(?string $dept): ?string
    {
        if ($dept === null || $dept === '') {
            return null;
        }
        static $map = null;
        if ($map === null) {
            $map = [];
            $regions = [
                '84' => ['01', '03', '07', '15', '26', '38', '42', '43', '63', '69', '73', '74'],
                '27' => ['21', '25', '39', '58', '70', '71', '89', '90'],
                '53' => ['22', '29', '35', '56'],
                '24' => ['18', '28', '36', '37', '41', '45'],
                '94' => ['2A', '2B'],
                '44' => ['08', '10', '51', '52', '54', '55', '57', '67', '68', '88'],
                '32' => ['02', '59', '60', '62', '80'],
                '11' => ['75', '77', '78', '91', '92', '93', '94', '95'],
                '28' => ['14', '27', '50', '61', '76'],
                '75' => ['16', '17', '19', '23', '24', '33', '40', '47', '64', '79', '86', '87'],
                '76' => ['09', '11', '12', '30', '31', '32', '34', '46', '48', '65', '66', '81', '82'],
                '52' => ['44', '49', '53', '72', '85'],
                '93' => ['04', '05', '06', '13', '83', '84'],
                // DOM (codes région INSEE)
                '01' => ['971'],
                '02' => ['972'],
                '03' => ['973'],
                '04' => ['974'],
                '06' => ['976'],
            ];
            foreach ($regions as $region => $depts) {
                foreach ($depts as $d) {
                    $map[$d] = $region;
                }
            }
        }

        return $map[$dept] ?? null;
    }

    /** Clé de déduplication intra-lot : nom normalisé + type. */
    private static function dedupKey(string $name, string $type): string
    {
        return self::normalizeForMatch($name) . '|' . $type;
    }

    /** Normalisation légère (minuscule, sans accents, espaces compactés). */
    private static function normalizeForMatch(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'û' => 'u', 'ü' => 'u', 'ù' => 'u', 'ú' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        $s = preg_replace('/\s+/', ' ', $s);

        return trim((string) $s);
    }

    /** Référence de cellule (« B12 ») → index colonne 0-based. */
    private static function colIndex(string $ref): int
    {
        if (! preg_match('/^([A-Z]+)/', $ref, $m)) {
            return 0;
        }
        $col = $m[1];
        $n = 0;
        for ($i = 0, $len = strlen($col); $i < $len; $i++) {
            $n = $n * 26 + (ord($col[$i]) - 64);
        }

        return $n - 1;
    }
}

<?php

namespace App\Console\Commands;

use App\Crm\Doublons\FusionFiches;
use App\Crm\FichesProtegees;
use App\Support\TotalListe;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Écarte du CRM les pharmacies (NAF 47.73Z) qui n'existent plus.
 *
 * ── RÈGLE DE FER : RIEN N'EST JAMAIS SUPPRIMÉ ───────────────────────────────
 * Aucun `DELETE`, aucun `TRUNCATE`. « Écarter » = poser `deleted_at` (la
 * corbeille existante) et, dans `metadata`, `ecartee_motif` + `ecartee_le`.
 * Tout se rétablit en vidant `deleted_at`.
 *
 * ── LES DÉCISIONS ───────────────────────────────────────────────────────────
 *   1. `insee_ferme_le` non nul            → corbeille, motif `insee_ferme` ;
 *   2. SIREN connu de FINESS, officines (catégorie 620) toutes inactives, alors
 *      que l'INSEE ne dit pas fermée (`insee_ferme_le` nul) → SEULEMENT
 *      l'étiquette `metadata.a_verifier_fermee`, JAMAIS la corbeille (décision
 *      prise) ; comptées à part (« finess inactif, INSEE ouvert »). Le motif
 *      `finess_inactif` reste donc à 0 en corbeille ; sa fourchette est gardée ;
 *   3. SIREN absent de FINESS (parapharmacie, matériel médical…) → seulement
 *      `metadata.type_etablissement = non_officine`, SANS corbeille.
 *   Les SIREN vides ou invalides sont ignorés pour les motifs FINESS.
 *
 * ── LES FICHES PROTÉGÉES ────────────────────────────────────────────────────
 * Une fiche protégée n'est JAMAIS mise à la corbeille : étiquette
 * `a_verifier_fermee` seulement. Protégée = une ligne liée dans l'une des
 * tables de `FusionFiches::REFERENCES` / `REFERENCES_SANS_CLE` (contacts,
 * affaires, audiences, listes manuelles, personnes, praticiens, journalistes,
 * médias, organisateurs, fédérations, OPCO, activités de rendez-vous /
 * échanges / e-mails, événements métier), une étiquette protégée
 * (`FichesProtegees`), un `console_client_ref`, une `relation_type` autre que
 * `prospect`, ou un `lifecycle_stage` avancé. Les envois / échanges rattachés
 * à un contact sont couverts par le contact lui-même.
 *
 * ── LA SOURCE FINESS ────────────────────────────────────────────────────────
 * Un CSV (colonnes `siret`, `categorie`, `etat`, séparateur `,` ou `;`).
 * Refusé s'il est tronqué, s'il compte moins de 20 000 lignes de catégorie 620
 * ou moins de 90 % de lignes actives. Sans `--finess`, seul le motif INSEE
 * s'applique.
 *
 * ── FOURCHETTES DE SÛRETÉ ───────────────────────────────────────────────────
 * Avant toute écriture les totaux sont calculés ; `--apply` est refusé hors
 * bornes (corbeille INSEE ≤ 150, corbeille FINESS ≤ 500, non_officine ≤ 6000),
 * relevables volontairement par `--max-insee`, `--max-finess`, `--max-non-officine`.
 *
 * ── TRAÇABILITÉ, VERROU, RESTAURATION ───────────────────────────────────────
 * Chaque mise à la corbeille laisse une ligne dans `activities` (une par fiche,
 * `subject_type = company`, motif dans `payload`) : `prospection collect`
 * réécrit `metadata` en entier. `--restaurer` (motif optionnel `--motif=`)
 * vide `deleted_at` des seules fiches écartées par cette commande. Verrou
 * `crm:pharmacies:ecarter-fermees` : refus s'il est pris. Après écriture, le
 * total de liste de l'espace est oublié (`TotalListe::oublier`).
 *
 * ── SÛRETÉ ──────────────────────────────────────────────────────────────────
 * Dry-run par défaut. Recherches bornées par `workspace_id` ; correspondance
 * FINESS par SIREN (index unique `(workspace_id, siren)`), en mémoire — jamais
 * de balayage par SIRET. Paquets par curseur d'id. La sortie ne contient que
 * des totaux : ni nom, ni adresse.
 */
class CrmPharmaciesEcarterFermees extends Command
{
    protected $signature = 'crm:pharmacies:ecarter-fermees
        {--apply : ecrire (sans cette option : essai a blanc, totaux seulement)}
        {--finess= : chemin d un CSV FINESS (siret, categorie, etat) pour le motif finess_inactif}
        {--workspace= : limiter a un espace de travail (uuid)}
        {--taille-lot=500 : fiches par paquet}
        {--max-insee=150 : fourchette : corbeille INSEE maximale}
        {--max-finess=500 : fourchette : corbeille FINESS maximale}
        {--max-non-officine=6000 : fourchette : fiches non_officine maximales}
        {--restaurer : rétablir (deleted_at vide) les fiches ecartees par cette commande}
        {--motif= : avec --restaurer : limiter a un motif (insee_ferme, finess_inactif)}';

    protected $description = 'Ecarte (corbeille, jamais de suppression) les pharmacies fermees. Dry-run par defaut.';

    private const VERROU = 'crm:pharmacies:ecarter-fermees';

    private const SOURCE_LOG = 'pharmacies:ecarter-fermees';

    private const MIN_OFFICINES_620 = 20000;

    private const MIN_ACTIVES_PCT = 90;

    private const MOTIFS_CORBEILLE = ['insee_ferme', 'finess_inactif'];

    /**
     * Gestes AUTOMATIQUES de la timeline : seuls à ne PAS protéger une fiche.
     * Toute autre activité `subject_type = company` (tâche, changement d'étape,
     * rendez-vous, échange, presse, événement…) ou toute ligne saisie par un
     * utilisateur (`user_id` non nul) la protège.
     */
    private const ACTIVITES_AUTOMATIQUES = ['scraped', 'enriched', 'reclassified', 'email_hard_bounced'];

    /** @var list<array{0: string, 1: string, 2: bool}>|null [table, colonne, a workspace_id] */
    private ?array $liens = null;

    /** @var array<string, int> cumul écrit pendant --apply (bornes par paquet) */
    private array $cumul = [];

    /** @var array<string, array{actives: int, inactives: int}> SIREN → officines 620 */
    private array $officines = [];

    /** @var array<string, true> SIREN connus de FINESS (toutes catégories) */
    private array $connus = [];

    /** @var array{lignes620: int, actives620: int, lignes: int} */
    private array $statsFiness = ['lignes620' => 0, 'actives620' => 0, 'lignes' => 0];

    public function handle(): int
    {
        $verrou = Cache::lock(self::VERROU, 3600);
        if (! $verrou->get()) {
            $this->error('REFUS : une autre execution est en cours (verrou ' . self::VERROU . ').');

            return self::FAILURE;
        }

        try {
            return $this->executer();
        } finally {
            $verrou->release();
        }
    }

    private function executer(): int
    {
        $apply = (bool) $this->option('apply');
        $chemin = $this->option('finess');
        $finess = is_string($chemin) && $chemin !== '';

        $espaces = DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id');
        $demande = $this->option('workspace');
        if (is_string($demande) && $demande !== '') {
            $espaces = $espaces->filter(fn ($id) => (string) $id === $demande)->values();
            if ($espaces->isEmpty()) {
                $this->error('REFUS : espace de travail inconnu.');

                return self::FAILURE;
            }
        }

        if ((bool) $this->option('restaurer')) {
            return $this->restaurer($espaces, $apply);
        }

        if ($finess && ! $this->chargerFiness($chemin)) {
            return self::FAILURE;
        }

        $taille = max(1, (int) $this->option('taille-lot'));
        $total = $this->parcourir($espaces, $taille, false, $finess);

        $hors = $this->horsBornes($total);
        $this->bilan($total, $apply);
        foreach ($hors as $ligne) {
            $this->warn('HORS FOURCHETTE : ' . $ligne);
        }

        if (! $apply) {
            $this->warn('DRY-RUN : rien n\'a ete ecrit. Relancer avec --apply pour appliquer.');

            return self::SUCCESS;
        }
        if ($hors !== []) {
            $this->error('REFUS : apply hors fourchette, rien n\'a ete ecrit (relever --max-* volontairement).');

            return self::FAILURE;
        }

        $this->cumul = $this->vide();
        try {
            $this->parcourir($espaces, $taille, true, $finess);
        } catch (\RuntimeException $e) {
            $this->error('REFUS : ' . $e->getMessage());

            return self::FAILURE;
        }
        foreach ($espaces as $espace) {
            TotalListe::oublier((string) $espace);
        }
        $this->info('APPLIQUE.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function vide(): array
    {
        return ['examinees' => 0, 'insee_ferme' => 0, 'finess_inactif' => 0, 'finess_inactif_insee_ouvert' => 0, 'insee_ferme_finess_actif' => 0, 'restauree_ignoree' => 0, 'a_verifier' => 0, 'non_officine' => 0];
    }

    /**
     * @param  iterable<mixed>  $espaces
     * @return array<string, int>
     */
    private function parcourir(iterable $espaces, int $taille, bool $apply, bool $finess): array
    {
        $total = $this->vide();
        foreach ($espaces as $espace) {
            $bilan = WorkspaceContext::run(
                (string) $espace,
                fn (): array => $this->traiterEspace((string) $espace, $taille, $apply, $finess),
            );
            foreach ($bilan as $cle => $n) {
                $total[$cle] += $n;
            }
        }

        return $total;
    }

    /**
     * @param  array<string, int>  $t
     * @return list<string>
     */
    private function horsBornes(array $t): array
    {
        $hors = [];
        $bornes = [
            ['insee_ferme', 'max-insee', 'corbeille INSEE'],
            ['finess_inactif', 'max-finess', 'corbeille FINESS'],
            ['non_officine', 'max-non-officine', 'non_officine'],
        ];
        foreach ($bornes as [$cle, $option, $libelle]) {
            $max = max(0, (int) $this->option($option));
            if ($t[$cle] > $max) {
                $hors[] = "{$libelle} = {$t[$cle]} > {$max} (--{$option})";
            }
        }

        return $hors;
    }

    /** @param array<string, int> $total */
    private function bilan(array $total, bool $apply): void
    {
        $this->info(($apply ? 'APPLICATION' : 'DRY-RUN') . ' — bilan (totaux uniquement)');
        $this->line('fiches 47.73Z examinees           : ' . $total['examinees']);
        $this->line('ecartees (corbeille) insee_ferme   : ' . $total['insee_ferme']);
        $this->line('ecartees (corbeille) finess_inactif: ' . $total['finess_inactif']);
        $this->line('finess inactif, INSEE ouvert (etiquette seule): ' . $total['finess_inactif_insee_ouvert']);
        $this->line('INSEE ferme, FINESS actif (etiquette seule): ' . $total['insee_ferme_finess_actif']);
        $this->line('deja restaurees (ignorees)         : ' . $total['restauree_ignoree']);
        $this->line('a verifier (liens, sans corbeille) : ' . $total['a_verifier']);
        $this->line('marquees non_officine (sans corbeille): ' . $total['non_officine']);
    }

    private function chargerFiness(string $chemin): bool
    {
        $f = is_file($chemin) ? fopen($chemin, 'r') : false;
        if ($f === false) {
            $this->error('REFUS : fichier FINESS illisible.');

            return false;
        }
        $entete = fgets($f);
        $sep = ($entete !== false && substr_count($entete, ';') > substr_count($entete, ',')) ? ';' : ',';
        $cols = array_map(fn ($c) => strtolower(trim((string) $c, " \t\n\r\0\x0B\xEF\xBB\xBF\"")), explode($sep, (string) $entete));
        $iSiret = array_search('siret', $cols, true);
        $iCat = array_search('categorie', $cols, true);
        $iEtat = array_search('etat', $cols, true);
        if ($iSiret === false || $iCat === false || $iEtat === false) {
            fclose($f);
            $this->error('REFUS : colonnes siret, categorie, etat requises dans le CSV FINESS.');

            return false;
        }
        $requis = max($iSiret, $iCat, $iEtat) + 1;
        $tronque = false;
        while (($l = fgetcsv($f, 0, $sep)) !== false) {
            if ($l === [null]) {
                continue;
            }
            if (count($l) < $requis) {
                $tronque = true;

                continue;
            }
            $this->statsFiness['lignes']++;
            $siren = substr(preg_replace('/\D/', '', (string) $l[$iSiret]) ?? '', 0, 9);
            $cat = trim((string) $l[$iCat]);
            $actif = trim((string) $l[$iEtat]) === 'A';
            if ($cat === '620') {
                $this->statsFiness['lignes620']++;
                $actif && $this->statsFiness['actives620']++;
            }
            if (strlen($siren) !== 9) {
                continue;
            }
            $this->connus[$siren] = true;
            if ($cat === '620') {
                $this->officines[$siren] ??= ['actives' => 0, 'inactives' => 0];
                $actif ? $this->officines[$siren]['actives']++ : $this->officines[$siren]['inactives']++;
            }
        }
        // Un fichier coupé net : dernier octet autre qu'un saut de ligne.
        fseek($f, -1, SEEK_END);
        $finNette = in_array(fgetc($f), ["\n", "\r"], true);
        fclose($f);

        if ($tronque || ! $finNette) {
            $this->error('REFUS : CSV FINESS tronque (ligne incomplete).');

            return false;
        }
        $n = $this->statsFiness['lignes620'];
        if ($n < self::MIN_OFFICINES_620) {
            $this->error("REFUS : CSV FINESS suspect, {$n} lignes de categorie 620 (minimum " . self::MIN_OFFICINES_620 . ').');

            return false;
        }
        $pct = (int) floor(100 * $this->statsFiness['actives620'] / $n);
        if ($pct < self::MIN_ACTIVES_PCT) {
            $this->error("REFUS : CSV FINESS suspect, {$pct} % de lignes 620 actives (minimum " . self::MIN_ACTIVES_PCT . ' %).');

            return false;
        }

        return true;
    }

    /** @return array<string, int> */
    private function traiterEspace(string $espace, int $taille, bool $apply, bool $finess): array
    {
        $bilan = $this->vide();

        // Une seule lecture, par l'index (workspace_id, naf) : ~25 000 entiers.
        // Une pagination `id > ? ORDER BY id LIMIT` pourrait parcourir toute la
        // clé primaire de companies ; ici le découpage se fait en PHP.
        $tous = array_map(
            static fn ($r): int => (int) $r->id,
            DB::select(
                "SELECT id FROM companies
                 WHERE workspace_id = ? AND naf IN ('47.73Z', '4773Z') AND deleted_at IS NULL
                 ORDER BY id",
                [$espace],
            ),
        );

        foreach (array_chunk($tous, $taille) as $ids) {
            $marques = implode(',', array_fill(0, count($ids), '?'));
            /** @var list<\stdClass> $fiches */
            $fiches = DB::select(
                "SELECT id, siren, insee_ferme_le, lifecycle_stage, metadata
                 FROM companies
                 WHERE workspace_id = ? AND deleted_at IS NULL AND id IN ({$marques})
                 ORDER BY id",
                array_merge([$espace], $ids),
            );
            if ($fiches === []) {
                continue;
            }
            $restaurees = $this->dejaRestaurees($espace, $ids);
            $protegees = $this->protegees($espace, array_map(fn ($f) => (int) $f->id, $fiches));

            /** @var array<string, list<int>> $corbeille */
            $corbeille = [];
            $verifier = [];
            $nonOfficine = [];
            $protegeesLot = 0;
            foreach ($fiches as $f) {
                $bilan['examinees']++;
                if (isset($restaurees[(int) $f->id])) {
                    // Rétablie par la commande : jamais remise à la corbeille.
                    $bilan['restauree_ignoree']++;

                    continue;
                }
                $motif = $this->motif($f, $finess);
                if ($motif === null) {
                    continue;
                }
                $id = (int) $f->id;
                if ($motif === 'non_officine') {
                    $nonOfficine[] = $id;

                    continue;
                }
                $protegee = isset($protegees[$id])
                    || ($f->lifecycle_stage !== null && $f->lifecycle_stage !== 'nouveau');
                if ($motif === 'insee_ferme_finess_actif') {
                    // INSEE fermée mais FINESS actif : doute → étiquette seule.
                    if ($protegee) {
                        $verifier[] = $id;
                        $protegeesLot++;
                    } else {
                        $bilan['insee_ferme_finess_actif']++;
                        $verifier[] = $id;
                    }

                    continue;
                }
                if ($motif === 'finess_inactif') {
                    // Décision : l'INSEE ne dit pas fermée → étiquette seule,
                    // comptée à part (ni corbeille, ni « a_verifier » des liens).
                    $bilan['finess_inactif_insee_ouvert']++;
                    $verifier[] = $id;

                    continue;
                }
                if ($protegee) {
                    $verifier[] = $id;
                    $protegeesLot++;
                } else {
                    $corbeille[$motif][] = $id;
                }
            }

            $bilan['insee_ferme'] += count($corbeille['insee_ferme'] ?? []);
            $bilan['finess_inactif'] += count($corbeille['finess_inactif'] ?? []);
            $bilan['a_verifier'] += $protegeesLot;
            $bilan['non_officine'] += count($nonOfficine);

            if ($apply) {
                foreach (['insee_ferme', 'finess_inactif', 'non_officine'] as $cle) {
                    $this->cumul[$cle] += match ($cle) {
                        'non_officine' => count($nonOfficine),
                        default => count($corbeille[$cle] ?? []),
                    };
                }
                $hors = $this->horsBornes($this->cumul);
                if ($hors !== []) {
                    throw new \RuntimeException('cumul hors fourchette avant ecriture du paquet (' . implode(' ; ', $hors) . '), paquet non ecrit.');
                }
                DB::transaction(function () use ($corbeille, $verifier, $nonOfficine, $espace): void {
                    foreach ($corbeille as $motif => $ids) {
                        $this->ecarter($ids, $espace, $motif);
                    }
                    $this->etiqueter($verifier, $espace, ['a_verifier_fermee' => true]);
                    $this->etiqueter($nonOfficine, $espace, ['type_etablissement' => 'non_officine']);
                });
            }
        }

        return $bilan;
    }

    /** @return 'insee_ferme'|'insee_ferme_finess_actif'|'finess_inactif'|'non_officine'|null */
    private function motif(\stdClass $f, bool $finess): ?string
    {
        $siren = (string) $f->siren;
        if ($f->insee_ferme_le !== null) {
            $o = $finess && preg_match('/^\d{9}$/', $siren) === 1 ? ($this->officines[$siren] ?? null) : null;

            return $o !== null && $o['actives'] > 0 ? 'insee_ferme_finess_actif' : 'insee_ferme';
        }
        if (! $finess || preg_match('/^\d{9}$/', $siren) !== 1) {
            return null;
        }
        if (! isset($this->connus[$siren])) {
            $deja = json_decode((string) $f->metadata, true);

            return is_array($deja) && ($deja['type_etablissement'] ?? null) === 'non_officine' ? null : 'non_officine';
        }
        $o = $this->officines[$siren] ?? null;

        return $o !== null && $o['inactives'] > 0 && $o['actives'] === 0 ? 'finess_inactif' : null;
    }

    /**
     * Tables liées à une fiche, calculées UNE fois (Schema::hasColumn).
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function liens(): array
    {
        if ($this->liens !== null) {
            return $this->liens;
        }
        // Hors company_tag, scraper_runs, finess_etablissements : données de la
        // fiche, pas du travail humain.
        $ignorees = ['company_tag', 'scraper_runs', 'finess_etablissements'];
        $refs = array_merge(FusionFiches::REFERENCES, array_keys(FusionFiches::REFERENCES_SANS_CLE));
        $liens = [];
        foreach ($refs as $ref) {
            [$table, $colonne] = explode('.', $ref);
            if (in_array($table, $ignorees, true) || ! Schema::hasColumn($table, $colonne)) {
                continue;
            }
            $liens[] = [$table, $colonne, Schema::hasColumn($table, 'workspace_id')];
        }

        return $this->liens = $liens;
    }

    /**
     * Fiches du paquet dont la DERNIÈRE ligne de journal de cette commande est
     * « restauree ».
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function dejaRestaurees(string $espace, array $ids): array
    {
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $lignes = DB::select(
            "SELECT a.subject_id FROM activities a
             WHERE a.workspace_id = ? AND a.subject_type = 'company' AND a.subject_id IN ({$marques})
               AND a.payload->>'source' = ? AND a.payload->>'action' = 'restauree'
               AND a.id = (SELECT max(b.id) FROM activities b
                           WHERE b.workspace_id = a.workspace_id AND b.subject_type = 'company'
                             AND b.subject_id = a.subject_id AND b.payload->>'source' = ?)",
            array_merge([$espace], $ids, [self::SOURCE_LOG, self::SOURCE_LOG]),
        );
        $out = [];
        foreach ($lignes as $l) {
            $out[(int) $l->subject_id] = true;
        }

        return $out;
    }

    /**
     * Fiches du paquet qu'un humain a touchées : un EXISTS par table liée
     * (listes de `FusionFiches`), plus étiquettes protégées, client console,
     * relation autre que prospect.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function protegees(string $espace, array $ids): array
    {
        $liste = implode(',', array_map('intval', $ids));
        $ou = [
            'NOT (' . FichesProtegees::conditionSql('c.id') . ')',
            'c.console_client_ref IS NOT NULL',
            "(c.relation_type IS NOT NULL AND c.relation_type <> 'prospect')",
        ];

        // `x.workspace_id = c.workspace_id` : les index commencent par
        // workspace_id (PostgreSQL 16 ne saute pas la première colonne).
        foreach ($this->liens() as [$table, $colonne, $aEspace]) {
            $ws = $aEspace ? 'x.workspace_id = c.workspace_id AND ' : '';
            if ($table === 'activities') {
                $auto = implode(', ', array_map(static fn (string $k): string => "'" . $k . "'", self::ACTIVITES_AUTOMATIQUES));
                $ou[] = "EXISTS (SELECT 1 FROM activities x WHERE {$ws}x.subject_id = c.id AND x.subject_type = 'company'
                    AND (x.user_id IS NOT NULL
                         OR (COALESCE(x.kind, '') NOT IN ({$auto})
                             AND COALESCE(x.payload->>'source', '') <> '" . self::SOURCE_LOG . "')))";

                continue;
            }
            if ($table === 'business_events') {
                // `resource_id` est un TEXTE : on convertit le côté fiche.
                $ou[] = "EXISTS (SELECT 1 FROM business_events x WHERE {$ws}x.resource_id = c.id::text AND x.resource_type = 'company')";

                continue;
            }
            $ou[] = "EXISTS (SELECT 1 FROM {$table} x WHERE {$ws}x.{$colonne} = c.id)";
        }

        $sql = 'SELECT c.id FROM companies c WHERE c.workspace_id = ? AND c.id IN (' . $liste . ') AND (' . implode(' OR ', $ou) . ')';
        $out = [];
        foreach (DB::select($sql, [$espace]) as $r) {
            $out[(int) $r->id] = true;
        }

        return $out;
    }

    /**
     * Mise à la corbeille (UPDATE, jamais DELETE) + une ligne de journal par
     * fiche réellement écartée.
     *
     * @param  list<int>  $ids
     */
    private function ecarter(array $ids, string $espace, string $motif): void
    {
        if ($ids === []) {
            return;
        }
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $meta = json_encode(['ecartee_motif' => $motif, 'ecartee_le' => now()->toDateString()]);
        $touchees = DB::select(
            "UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb, deleted_at = now()
             WHERE workspace_id = ? AND deleted_at IS NULL AND id IN ({$marques}) RETURNING id, deleted_at::text AS instant",
            array_merge([$meta, $espace], $ids),
        );
        if ($touchees === []) {
            return;
        }
        // Même transaction : un seul instant pour tout le paquet.
        $instant = (string) $touchees[0]->instant;
        $faits = array_values(array_map(fn ($r) => (int) $r->id, $touchees));
        $marquesFaits = implode(',', array_fill(0, count($faits), '?'));
        DB::update(
            "UPDATE companies SET metadata = metadata || ?::jsonb WHERE workspace_id = ? AND id IN ({$marquesFaits})",
            array_merge([json_encode(['ecartee_instant' => $instant]), $espace], $faits),
        );
        $this->journaliser($faits, $espace, ['action' => 'ecartee', 'motif' => $motif, 'deleted_at' => $instant]);
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $payload
     */
    private function journaliser(array $ids, string $espace, array $payload): void
    {
        $lignes = array_map(fn (int $id): array => [
            'workspace_id' => $espace, 'type' => 'note', 'kind' => 'reclassified',
            'subject_type' => 'company', 'subject_id' => $id,
            'title' => 'Pharmacies : ' . $payload['action'],
            'payload' => json_encode($payload + ['source' => self::SOURCE_LOG]),
            'occurred_at' => now(), 'created_at' => now(),
        ], $ids);
        foreach (array_chunk($lignes, 500) as $paquet) {
            DB::table('activities')->insert($paquet);
        }
    }

    /**
     * Étiquette sans corbeille : fusion dans `metadata`.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $meta
     */
    private function etiqueter(array $ids, string $espace, array $meta): void
    {
        if ($ids === []) {
            return;
        }
        $marques = implode(',', array_fill(0, count($ids), '?'));
        DB::update(
            "UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb
             WHERE workspace_id = ? AND deleted_at IS NULL AND id IN ({$marques})",
            array_merge([json_encode($meta), $espace], $ids),
        );
    }

    /**
     * Rétablit (deleted_at = NULL) les seules fiches écartées par cette
     * commande : reconnues au journal `activities` (instant exact de la corbeille).
     *
     * @param  iterable<mixed>  $espaces
     */
    private function restaurer(iterable $espaces, bool $apply): int
    {
        $motif = $this->option('motif');
        $motif = is_string($motif) && $motif !== '' ? $motif : null;
        if ($motif !== null && ! in_array($motif, self::MOTIFS_CORBEILLE, true)) {
            $this->error('REFUS : motif inconnu (insee_ferme, finess_inactif).');

            return self::FAILURE;
        }
        $motifs = $motif === null ? self::MOTIFS_CORBEILLE : [$motif];
        $marques = implode(',', array_fill(0, count($motifs), '?'));

        $n = 0;
        foreach ($espaces as $espace) {
            $espace = (string) $espace;
            // Rétablit seulement une fiche mise à la corbeille par cette commande
            // à CET instant (deleted_at = instant du journal) ET dont la dernière
            // ligne de journal de la commande est « ecartee ». Le journal fait foi
            // (metadata est réécrite par la collecte).
            $filtre = "workspace_id = ? AND deleted_at IS NOT NULL
                AND EXISTS (SELECT 1 FROM activities a WHERE a.workspace_id = companies.workspace_id
                      AND a.subject_type = 'company' AND a.subject_id = companies.id
                      AND a.payload->>'source' = ? AND a.payload->>'action' = 'ecartee'
                      AND a.payload->>'motif' IN ({$marques})
                      AND a.payload->>'deleted_at' = companies.deleted_at::text
                      AND a.id = (SELECT max(b.id) FROM activities b
                                  WHERE b.workspace_id = companies.workspace_id AND b.subject_type = 'company'
                                    AND b.subject_id = companies.id AND b.payload->>'source' = ?))";
            $params = array_merge([$espace, self::SOURCE_LOG], $motifs, [self::SOURCE_LOG]);
            if (! $apply) {
                $n += (int) DB::selectOne("SELECT count(*) AS n FROM companies WHERE {$filtre}", $params)->n;

                continue;
            }
            $touchees = DB::select(
                "UPDATE companies SET deleted_at = NULL,
                    metadata = (COALESCE(metadata, '{}'::jsonb) - 'ecartee_motif' - 'ecartee_le' - 'ecartee_instant') || ?::jsonb
                 WHERE {$filtre} RETURNING id",
                array_merge([json_encode(['restauree_le' => now()->toDateString()])], $params),
            );
            $ids = array_values(array_map(fn ($r) => (int) $r->id, $touchees));
            $this->journaliser($ids, $espace, ['action' => 'restauree', 'motif' => $motif ?? 'tous']);
            $ids === [] || TotalListe::oublier($espace);
            $n += count($ids);
        }
        $this->info(($apply ? 'RESTAUREES' : 'DRY-RUN restauration') . ' : ' . $n . ' fiche(s)');
        if (! $apply) {
            $this->warn('DRY-RUN : rien n\'a ete ecrit. Relancer avec --apply pour restaurer.');
        }

        return self::SUCCESS;
    }
}

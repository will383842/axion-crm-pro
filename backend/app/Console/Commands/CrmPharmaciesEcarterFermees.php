<?php

namespace App\Console\Commands;

use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
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
 * ── LES TROIS DÉCISIONS ─────────────────────────────────────────────────────
 *   1. `insee_ferme_le` non nul            → corbeille, motif `insee_ferme` ;
 *   2. SIREN connu de FINESS, officines (catégorie 620) toutes inactives et
 *      aucune active                        → corbeille, motif `finess_inactif` ;
 *   3. SIREN absent de FINESS (parapharmacie, matériel médical…) → seulement
 *      `metadata.type_etablissement = non_officine`, SANS corbeille.
 *
 * ── LES FICHES TOUCHÉES PAR LE COMMERCIAL ───────────────────────────────────
 * Une fiche qui a un contact, une affaire (deal), un envoi/échange rattaché à
 * l'un de ses contacts, une campagne ou un statut de relation avancé n'est
 * JAMAIS mise à la corbeille : elle reçoit `metadata.a_verifier_fermee = true`
 * et est comptée à part.
 *
 * ── LA SOURCE FINESS ────────────────────────────────────────────────────────
 * La table `finess_etablissements` (PR 348) n'est pas sur `main` : on lit donc
 * un CSV (colonnes `siret`, `categorie`, `etat`, séparateur `,` ou `;`), comme
 * le fait l'import FINESS. Sans `--finess`, seul le motif INSEE s'applique.
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
        {--taille-lot=500 : fiches par paquet}';

    protected $description = 'Ecarte (corbeille, jamais de suppression) les pharmacies fermees. Dry-run par defaut.';

    /** Tables portant `company_id` : une ligne ici protège la fiche. */
    private const LIENS_COMPANY = ['contacts', 'deals', 'audience_members'];

    /** Tables portant `contact_id` : échanges / envois / campagnes rattachés via un contact. */
    private const LIENS_CONTACT = ['email_sends', 'email_threads', 'linkedin_messages'];

    /** @var array<string, array{actives: int, inactives: int}> SIREN → officines 620 */
    private array $officines = [];

    /** @var array<string, true> SIREN connus de FINESS (toutes catégories) */
    private array $connus = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chemin = $this->option('finess');
        $finess = is_string($chemin) && $chemin !== '';

        if ($finess && ! $this->chargerFiness($chemin)) {
            return self::FAILURE;
        }

        $taille = max(1, (int) $this->option('taille-lot'));
        $espaces = DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id');
        if (is_string($this->option('workspace')) && $this->option('workspace') !== '') {
            $espaces = $espaces->filter(fn ($id) => (string) $id === $this->option('workspace'))->values();
        }

        $total = ['examinees' => 0, 'insee_ferme' => 0, 'finess_inactif' => 0, 'a_verifier' => 0, 'non_officine' => 0];
        foreach ($espaces as $espace) {
            $bilan = WorkspaceContext::run(
                (string) $espace,
                fn (): array => $this->traiterEspace((string) $espace, $taille, $apply, $finess),
            );
            foreach ($bilan as $cle => $n) {
                $total[$cle] += $n;
            }
        }

        $this->info(($apply ? 'APPLIQUE' : 'DRY-RUN') . ' — bilan (totaux uniquement)');
        $this->line('fiches 47.73Z examinees           : ' . $total['examinees']);
        $this->line('ecartees (corbeille) insee_ferme   : ' . $total['insee_ferme']);
        $this->line('ecartees (corbeille) finess_inactif: ' . $total['finess_inactif']);
        $this->line('a verifier (liens, sans corbeille) : ' . $total['a_verifier']);
        $this->line('marquees non_officine (sans corbeille): ' . $total['non_officine']);
        if (! $apply) {
            $this->warn('DRY-RUN : rien n\'a ete ecrit. Relancer avec --apply pour appliquer.');
        }

        return self::SUCCESS;
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
        while (($l = fgetcsv($f, 0, $sep)) !== false) {
            $siren = substr(preg_replace('/\D/', '', (string) ($l[$iSiret] ?? '')) ?? '', 0, 9);
            if (strlen($siren) !== 9) {
                continue;
            }
            $this->connus[$siren] = true;
            if (trim((string) ($l[$iCat] ?? '')) === '620') {
                $this->officines[$siren] ??= ['actives' => 0, 'inactives' => 0];
                trim((string) ($l[$iEtat] ?? '')) === 'A'
                    ? $this->officines[$siren]['actives']++
                    : $this->officines[$siren]['inactives']++;
            }
        }
        fclose($f);

        return true;
    }

    /** @return array{examinees: int, insee_ferme: int, finess_inactif: int, a_verifier: int, non_officine: int} */
    private function traiterEspace(string $espace, int $taille, bool $apply, bool $finess): array
    {
        $bilan = ['examinees' => 0, 'insee_ferme' => 0, 'finess_inactif' => 0, 'a_verifier' => 0, 'non_officine' => 0];
        $curseur = 0;

        while (true) {
            /** @var list<\stdClass> $fiches */
            $fiches = DB::select(
                "SELECT id, siren, insee_ferme_le, lifecycle_stage, metadata
                 FROM companies
                 WHERE workspace_id = ? AND id > ? AND deleted_at IS NULL
                   AND replace(naf, '.', '') = '4773Z'
                 ORDER BY id LIMIT {$taille}",
                [$espace, $curseur],
            );
            if ($fiches === []) {
                break;
            }
            $curseur = (int) end($fiches)->id;
            $protegees = $this->protegees(array_map(fn ($f) => (int) $f->id, $fiches));

            $corbeille = ['insee_ferme' => [], 'finess_inactif' => []];
            $verifier = [];
            $nonOfficine = [];
            foreach ($fiches as $f) {
                $bilan['examinees']++;
                $motif = $this->motif($f, $finess);
                if ($motif === null) {
                    continue;
                }
                if ($motif === 'non_officine') {
                    $nonOfficine[] = (int) $f->id;

                    continue;
                }
                $protegee = isset($protegees[(int) $f->id])
                    || ($f->lifecycle_stage !== null && $f->lifecycle_stage !== 'nouveau');
                $protegee ? $verifier[] = (int) $f->id : $corbeille[$motif][] = (int) $f->id;
            }

            $bilan['insee_ferme'] += count($corbeille['insee_ferme']);
            $bilan['finess_inactif'] += count($corbeille['finess_inactif']);
            $bilan['a_verifier'] += count($verifier);
            $bilan['non_officine'] += count($nonOfficine);

            if ($apply) {
                DB::transaction(function () use ($corbeille, $verifier, $nonOfficine, $espace): void {
                    foreach ($corbeille as $motif => $ids) {
                        $this->ecrire($ids, $espace, true, ['ecartee_motif' => $motif, 'ecartee_le' => now()->toDateString()]);
                    }
                    $this->ecrire($verifier, $espace, false, ['a_verifier_fermee' => true]);
                    $this->ecrire($nonOfficine, $espace, false, ['type_etablissement' => 'non_officine']);
                });
            }
        }

        return $bilan;
    }

    /** @return 'insee_ferme'|'finess_inactif'|'non_officine'|null */
    private function motif(\stdClass $f, bool $finess): ?string
    {
        if ($f->insee_ferme_le !== null) {
            return 'insee_ferme';
        }
        if (! $finess) {
            return null;
        }
        $siren = (string) $f->siren;
        if (! isset($this->connus[$siren])) {
            $deja = json_decode((string) $f->metadata, true);

            return is_array($deja) && ($deja['type_etablissement'] ?? null) === 'non_officine' ? null : 'non_officine';
        }
        $o = $this->officines[$siren] ?? null;

        return $o !== null && $o['inactives'] > 0 && $o['actives'] === 0 ? 'finess_inactif' : null;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function protegees(array $ids): array
    {
        $liste = implode(',', array_map('intval', $ids));
        $out = [];
        foreach (self::LIENS_COMPANY as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                foreach (DB::select("SELECT DISTINCT company_id AS id FROM {$table} WHERE company_id IN ({$liste})") as $r) {
                    $out[(int) $r->id] = true;
                }
            }
        }
        foreach (self::LIENS_CONTACT as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'contact_id')) {
                $sql = "SELECT DISTINCT c.company_id AS id FROM contacts c JOIN {$table} t ON t.contact_id = c.id
                        WHERE c.company_id IN ({$liste})";
                foreach (DB::select($sql) as $r) {
                    $out[(int) $r->id] = true;
                }
            }
        }

        return $out;
    }

    /**
     * Seul `UPDATE` de la commande : fusion dans `metadata`, `deleted_at` en option.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $meta
     */
    private function ecrire(array $ids, string $espace, bool $corbeille, array $meta): void
    {
        if ($ids === []) {
            return;
        }
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $set = "metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb" . ($corbeille ? ', deleted_at = now()' : '');
        DB::update(
            "UPDATE companies SET {$set} WHERE workspace_id = ? AND deleted_at IS NULL AND id IN ({$marques})",
            array_merge([json_encode($meta), $espace], $ids),
        );
    }
}

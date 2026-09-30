<?php

namespace App\Console\Commands;

use App\Crm\Console\CompteursHub;
use App\Crm\Emails\QualificationEmail;
use App\Crm\EspaceProspection;
use App\Crm\FichesProtegees;
use App\Crm\Relations\LigneRelation;
use App\Crm\Relations\PromotionRelation;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SplFileObject;
use stdClass;
use Throwable;

/**
 * IMPORT DU STATUT DE RELATION — clients, contacts, rendez-vous du site
 * (chantier B, 2026-10-01).
 *
 * Les 4,3 M de fiches valent `prospect` / `nouveau` : on ne sait pas qui est
 * client, ni l'exclure d'une prospection. Cette commande lit un fichier JSON
 * Lines (format : `App\Crm\Relations\LigneRelation`) et PROMEUT les fiches
 * qu'elle reconnaît (règle : `App\Crm\Relations\PromotionRelation`).
 *
 * ── Le rapprochement, dans cet ordre ─────────────────────────────────────────
 *
 *  1. par SIREN (unique dans l'espace) ;
 *  2. sinon par e-mail EXACT (casse ignorée) d'une personne (`contacts.email`)
 *     ou de l'adresse générique (`companies.email_generic`) — si l'adresse est
 *     portée par PLUSIEURS fiches, la ligne est « ambiguë » et rien n'est
 *     posé ;
 *  3. sinon par le DOMAINE de l'e-mail = domaine du site de la fiche
 *     (`companies.website`, `www.` ignoré) — seulement si UNE SEULE fiche
 *     correspond, et jamais pour une messagerie grand public (gmail, orange…) ;
 *  4. sinon la ligne est comptée « non rapprochée » et NE CRÉE RIEN : pas de
 *     fiche pour l'e-mail d'un particulier.
 *
 * Les fiches à la corbeille (`deleted_at`) ne sont jamais rapprochées.
 *
 * ── Ce qui la rend sûre ──────────────────────────────────────────────────────
 *
 *  - on ne RÉTROGRADE jamais, `client` l'emporte, une relation posée à la main
 *    (`relation_saisie_manuelle_at`) n'est jamais touchée ;
 *  - rien n'est supprimé, rien n'est créé : seules `relation_type` et
 *    `lifecycle_stage` des fiches reconnues sont écrites ;
 *  - PAR PAQUETS de lignes (`--paquet`), chacun dans SA transaction courte
 *    (`lock_timeout` 5 s) ; l'`UPDATE` exige que le type, l'étape et la marque
 *    manuelle n'aient pas bougé depuis la lecture (sinon : « modifiée
 *    entre-temps », la relance suffit) ;
 *  - IDEMPOTENTE : relancée sur le même fichier, elle n'écrit plus rien ;
 *  - journalisée : une entrée de la chaîne d'audit par paquet écrit, une de
 *    fin ; une activité `stage_changed` dans la timeline de chaque fiche
 *    promue (origine : la `source` de la ligne) ;
 *  - `updated_at` BOUGE, à dessein : devenir client est une vraie
 *    modification de la fiche, pas un reclassement
 *    (`app.conserver_updated_at` n'est pas posé) ;
 *  - aucune donnée nominative à l'écran ni au journal : des nombres, et des
 *    NUMÉROS de ligne (jamais un e-mail ni une dénomination).
 *
 * Les fiches PROTÉGÉES (`FichesProtegees`) sont rapprochées comme les autres :
 * poser « client » sur un participant GOFAB le PROTÈGE d'une prospection, il
 * ne lui retire rien. Elles sont comptées à part (`dont_protegees`).
 *
 * `--dry-run` lit et calcule TOUT avec le même code, n'écrit RIEN, et annonce
 * les mêmes compteurs que l'exécution (y compris quand une même fiche revient
 * dans plusieurs paquets).
 */
class CrmRelationsImporter extends Command
{
    protected $signature = 'crm:relations:importer
                            {fichier : Fichier JSON Lines, une ligne par relation (hors dépôt)}
                            {--dry-run : Tout lire et calculer, ne RIEN écrire}
                            {--workspace= : Identifiant ou slug de l\'espace (défaut : l\'espace business)}
                            {--paquet=500 : Lignes par paquet (1 à 2000) — une transaction par paquet}
                            {--compteurs-seulement : N\'afficher que des nombres (pas de numéros de ligne)}';

    protected $description = 'Importe le statut de relation (client, partenaire…) depuis un fichier JSONL — promotion seulement, rien de créé ni de supprimé.';

    private const PAQUET_MAX = 2000;

    /** Numéros de ligne affichés au plus, par motif. */
    private const LIGNES_AFFICHEES = 20;

    /** @var array<string, int> */
    private array $compteurs = [];

    /** @var array<string, int> motif => n */
    private array $rejets = [];

    /** @var array<string, list<int>> motif => numéros de ligne (affichage) */
    private array $lignesParMotif = [];

    /**
     * L'état PRÉVU de chaque fiche déjà rencontrée — ce qui rend l'essai à
     * blanc honnête quand une fiche revient dans un paquet suivant.
     *
     * @var array<int, array{relation_type: string, lifecycle_stage: string}>
     */
    private array $prevu = [];

    public function handle(AuditHashChain $audit): int
    {
        $chemin = (string) $this->argument('fichier');
        if (! is_file($chemin) || ! is_readable($chemin)) {
            $this->error("Fichier illisible : {$chemin}");

            return self::FAILURE;
        }
        $designation = is_string($this->option('workspace')) && $this->option('workspace') !== ''
            ? $this->option('workspace')
            : (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = EspaceProspection::resoudre($designation);
        if ($workspaceId === null) {
            $this->error("Espace introuvable : « {$designation} ».");

            return self::FAILURE;
        }
        $paquet = (int) $this->option('paquet');
        if ($paquet < 1 || $paquet > self::PAQUET_MAX) {
            $this->error('--paquet : entre 1 et ' . self::PAQUET_MAX . '.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $discret = (bool) $this->option('compteurs-seulement');
        $operateur = self::operateur();
        $this->reinitialiser();

        $this->info(sprintf(
            '%s — espace %s, paquets de %d lignes.',
            $dryRun ? '[À BLANC] rien ne sera écrit' : 'Import du statut de relation',
            $discret ? '(masqué)' : $workspaceId,
            $paquet,
        ));

        $erreur = null;
        $derniereLigne = 0;
        try {
            WorkspaceContext::run($workspaceId, function () use ($chemin, $workspaceId, $paquet, $dryRun, $audit, $operateur, &$erreur, &$derniereLigne): void {
                $fichier = new SplFileObject($chemin, 'r');
                $lot = [];
                $numero = 0;
                while (! $fichier->eof()) {
                    $brut = $fichier->fgets();
                    $numero++;
                    if (trim($brut) === '') {
                        continue;
                    }
                    $this->compteurs['lignes_lues']++;
                    $ligne = LigneRelation::lire($numero, trim($brut));
                    if (is_string($ligne)) {
                        $this->rejeter($ligne, $numero);

                        continue;
                    }
                    $lot[] = $ligne;
                    if (count($lot) >= $paquet) {
                        if (! $this->traiterPaquet($workspaceId, $lot, $dryRun, $audit, $operateur, $erreur)) {
                            return;
                        }
                        $derniereLigne = $numero;
                        $lot = [];
                    }
                }
                if ($lot !== [] && $this->traiterPaquet($workspaceId, $lot, $dryRun, $audit, $operateur, $erreur)) {
                    $derniereLigne = $numero;
                }
            });
        } finally {
            if (! $dryRun) {
                try {
                    $this->auditer($audit, $workspaceId, $operateur, 'RELATIONS_IMPORT_FIN', $erreur === null ? 200 : 500, [
                        'erreur' => $erreur, 'compteurs' => $this->compteurs, 'rejets' => $this->rejets,
                    ], $erreur === null ? 'terminé' : "arrêté après la ligne {$derniereLigne}");
                } catch (Throwable $e) {
                    Log::error('crm:relations:importer : entrée d\'audit de fin NON écrite', ['erreur' => get_class($e)]);
                    $erreur ??= 'audit de fin : ' . get_class($e);
                }
            }
        }

        $this->afficherBilan($dryRun, $discret);
        Log::info('crm:relations:importer fin', ['a_blanc' => $dryRun, 'erreur' => $erreur, 'compteurs' => $this->compteurs, 'rejets' => $this->rejets]);

        if ($erreur !== null) {
            $this->error("ÉCHEC ({$erreur}) : le paquet en cours est annulé en entier ; les paquets précédents (jusqu'à la ligne {$derniereLigne}) sont acquis et journalisés. La relance est sans risque : l'import est idempotent.");

            return self::FAILURE;
        }
        $this->info($dryRun ? '[À BLANC] terminé : rien n\'a été écrit.' : 'Import terminé.');

        return self::SUCCESS;
    }

    private function reinitialiser(): void
    {
        $this->compteurs = array_fill_keys([
            'lignes_lues', 'lignes_rejetees', 'paquets',
            'rapprochees_par_siren', 'rapprochees_par_email', 'rapprochees_par_domaine',
            'ambigues', 'non_rapprochees', 'domaines_webmail_ecartes',
            'fiches_distinctes', 'dont_protegees',
            'deja_a_jour', 'verrouillees_a_la_main', 'demandes_refusees_sans_recul',
            'fiches_a_modifier', 'fiches_modifiees', 'modifiees_entre_temps',
        ], 0);
        $this->rejets = [];
        $this->lignesParMotif = [];
        $this->prevu = [];
    }

    private function rejeter(string $motif, int $numero): void
    {
        $this->compteurs['lignes_rejetees']++;
        $this->rejets[$motif] = ($this->rejets[$motif] ?? 0) + 1;
        $this->noter($motif, $numero);
    }

    private function noter(string $motif, int $numero): void
    {
        if (count($this->lignesParMotif[$motif] ?? []) < self::LIGNES_AFFICHEES) {
            $this->lignesParMotif[$motif][] = $numero;
        }
    }

    /**
     * Rapproche, calcule et (hors essai à blanc) écrit UN paquet. Rend false si
     * le paquet a échoué (il est alors annulé en entier).
     *
     * @param  list<LigneRelation>  $lot
     */
    private function traiterPaquet(string $workspaceId, array $lot, bool $dryRun, AuditHashChain $audit, string $operateur, ?string &$erreur): bool
    {
        $avant = $this->compteurs;
        $prevuAvant = $this->prevu;
        try {
            $cibles = $this->rapprocher($workspaceId, $lot);
            $plan = $this->planifier($workspaceId, $cibles);
            if (! $dryRun && $plan !== []) {
                DB::transaction(function () use ($workspaceId, $plan, $audit, $operateur, $avant): void {
                    DB::statement("SET LOCAL lock_timeout = '5s'");
                    $ecrites = $this->ecrire($workspaceId, $plan);
                    $this->compteurs['fiches_modifiees'] += count($ecrites);
                    $this->compteurs['modifiees_entre_temps'] += count($plan) - count($ecrites);
                    $this->journaliser($workspaceId, $plan, $ecrites);
                    $ids = array_keys($plan);
                    $this->auditer($audit, $workspaceId, $operateur, 'RELATIONS_IMPORT_PAQUET', 200, [
                        'ids' => [min($ids), max($ids)],
                        'fiches_modifiees' => $this->compteurs['fiches_modifiees'] - $avant['fiches_modifiees'],
                        'rapprochees' => $this->compteurs['rapprochees_par_siren'] + $this->compteurs['rapprochees_par_email'] + $this->compteurs['rapprochees_par_domaine']
                            - $avant['rapprochees_par_siren'] - $avant['rapprochees_par_email'] - $avant['rapprochees_par_domaine'],
                    ], 'paquet ' . ($this->compteurs['paquets'] + 1));
                    DB::afterCommit(static function () use ($workspaceId): void {
                        CompteursHub::oublier($workspaceId);
                    });
                });
            }
        } catch (Throwable $e) {
            $erreur = $e instanceof QueryException ? 'SQLSTATE ' . $e->getCode() : get_class($e);
            Log::error('crm:relations:importer : paquet annulé', ['erreur' => $erreur]);
            $this->compteurs = $avant;
            $this->prevu = $prevuAvant;

            return false;
        }
        $this->compteurs['paquets']++;
        $this->line(sprintf('  paquet %d : %d lignes', $this->compteurs['paquets'], count($lot)));

        return true;
    }

    /**
     * Les fiches reconnues : company_id => lignes qui la désignent.
     *
     * @param  list<LigneRelation>  $lot
     * @return array<int, list<LigneRelation>>
     */
    private function rapprocher(string $workspaceId, array $lot): array
    {
        $cibles = [];
        $restantes = [];

        // 1. SIREN
        $sirens = array_values(array_unique(array_filter(array_map(static fn (LigneRelation $l): ?string => $l->siren, $lot))));
        $parSiren = [];
        if ($sirens !== []) {
            foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereIn('siren', $sirens)->whereNull('deleted_at')->get(['id', 'siren']) as $f) {
                $parSiren[(string) $f->siren] = (int) $f->id;
            }
        }
        foreach ($lot as $l) {
            if ($l->siren !== null && isset($parSiren[$l->siren])) {
                $cibles[$parSiren[$l->siren]][] = $l;
                $this->compteurs['rapprochees_par_siren']++;
            } else {
                $restantes[] = $l;
            }
        }

        // 2. E-mail exact (personne ou générique)
        $emails = array_values(array_unique(array_filter(array_map(static fn (LigneRelation $l): ?string => $l->email, $restantes))));
        $parEmail = [];
        if ($emails !== []) {
            foreach (DB::table('contacts')->where('workspace_id', $workspaceId)->whereIn('email', $emails)->whereNull('deleted_at')->get(['company_id', 'email']) as $c) {
                $parEmail[QualificationEmail::normaliser((string) $c->email)][(int) $c->company_id] = true;
            }
            // Servie par `idx_companies_email_generic_minuscules` (expression ET prédicat partiel).
            foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereNotNull('email_generic')
                ->whereIn(DB::raw('lower(email_generic)'), $emails)->whereNull('deleted_at')->get(['id', 'email_generic']) as $f) {
                $parEmail[QualificationEmail::normaliser((string) $f->email_generic)][(int) $f->id] = true;
            }
        }
        $pourDomaine = [];
        foreach ($restantes as $l) {
            $fiches = $l->email === null ? [] : array_keys($parEmail[$l->email] ?? []);
            if (count($fiches) === 1) {
                $cibles[$fiches[0]][] = $l;
                $this->compteurs['rapprochees_par_email']++;
            } elseif (count($fiches) > 1) {
                $this->compteurs['ambigues']++;
                $this->noter('ambigue', $l->numero);
            } else {
                $pourDomaine[] = $l;
            }
        }

        // 3. Domaine de l'e-mail = domaine du site (jamais un webmail)
        $domaines = [];
        $aChercher = [];
        foreach ($pourDomaine as $l) {
            $domaine = $l->domaine();
            if ($domaine !== null && QualificationEmail::estWebmail($domaine)) {
                $this->compteurs['domaines_webmail_ecartes']++;
                $domaine = null;
            }
            if ($domaine === null) {
                $this->compteurs['non_rapprochees']++;
                $this->noter('non_rapprochee', $l->numero);

                continue;
            }
            $aChercher[] = $l;
            $domaines[$domaine] = true;
        }
        $parDomaine = [];
        if ($domaines !== []) {
            $expression = self::expressionDomaineDuSite('website');
            foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereNotNull('website')->whereNull('deleted_at')
                ->whereIn(DB::raw($expression), array_keys($domaines))
                ->get(['id', DB::raw($expression . ' AS domaine')]) as $f) {
                $parDomaine[(string) $f->domaine][(int) $f->id] = true;
            }
        }
        foreach ($aChercher as $l) {
            $fiches = array_keys($parDomaine[(string) $l->domaine()] ?? []);
            if (count($fiches) === 1) {
                $cibles[$fiches[0]][] = $l;
                $this->compteurs['rapprochees_par_domaine']++;
            } elseif (count($fiches) > 1) {
                $this->compteurs['ambigues']++;
                $this->noter('ambigue', $l->numero);
            } else {
                $this->compteurs['non_rapprochees']++;
                $this->noter('non_rapprochee', $l->numero);
            }
        }

        return $cibles;
    }

    /**
     * Le domaine d'un site web, en SQL : sans schéma, sans `www.`, sans chemin
     * ni port, en minuscules. `https://www.Exemple.fr/contact` → `exemple.fr`.
     */
    public static function expressionDomaineDuSite(string $colonne): string
    {
        return "lower(regexp_replace(regexp_replace(btrim({$colonne}), '^([a-zA-Z][a-zA-Z0-9+.-]*://)?(www\\.)?', '', 'i'), '[/:?#].*$', ''))";
    }

    /**
     * Ce qu'il faut écrire : company_id => état lu et état voulu.
     *
     * @param  array<int, list<LigneRelation>>  $cibles
     * @return array<int, array{lu_relation: string, lu_etape: string, relation_type: string, lifecycle_stage: string, sources: list<string>}>
     */
    private function planifier(string $workspaceId, array $cibles): array
    {
        if ($cibles === []) {
            return [];
        }
        $fiches = DB::table('companies as c')
            ->where('c.workspace_id', $workspaceId)
            ->whereIn('c.id', array_keys($cibles))
            ->whereNull('c.deleted_at')
            ->get(['c.id', 'c.relation_type', 'c.lifecycle_stage', 'c.relation_saisie_manuelle_at', DB::raw('NOT ' . FichesProtegees::conditionSql('c.id') . ' AS protegee')]);

        $plan = [];
        foreach ($fiches as $f) {
            $id = (int) $f->id;
            if (! isset($this->prevu[$id])) {
                $this->compteurs['fiches_distinctes']++;
                if ((bool) $f->protegee) {
                    $this->compteurs['dont_protegees']++;
                }
            }
            if ($f->relation_saisie_manuelle_at !== null) {
                $this->compteurs['verrouillees_a_la_main'] += count($cibles[$id]);
                $this->prevu[$id] ??= ['relation_type' => (string) $f->relation_type, 'lifecycle_stage' => (string) $f->lifecycle_stage];

                continue;
            }
            $lu = ['relation_type' => (string) $f->relation_type, 'lifecycle_stage' => (string) $f->lifecycle_stage];
            // Ce que les paquets précédents ont prévu (essai à blanc) ou déjà
            // écrit (exécution) : c'est de là que l'on part.
            $depart = $this->prevu[$id] ?? $lu;
            $etat = $depart;
            $sources = [];
            foreach ($cibles[$id] as $l) {
                $suivant = PromotionRelation::appliquer($etat['relation_type'], $etat['lifecycle_stage'], $l->relationType, $l->lifecycleStage);
                $voulu = ['relation_type' => $l->relationType ?? $suivant['relation_type'], 'lifecycle_stage' => $l->lifecycleStage ?? $suivant['lifecycle_stage']];
                if ($suivant === $etat) {
                    $voulu === $etat ? $this->compteurs['deja_a_jour']++ : $this->compteurs['demandes_refusees_sans_recul']++;
                } elseif ($suivant['relation_type'] !== $voulu['relation_type'] || $suivant['lifecycle_stage'] !== $voulu['lifecycle_stage']) {
                    // Promue en partie : l'autre moitié aurait été un recul.
                    $this->compteurs['demandes_refusees_sans_recul']++;
                }
                if ($suivant !== $etat) {
                    $sources[] = $l->source;
                }
                $etat = $suivant;
            }
            $this->prevu[$id] = $etat;
            if ($etat !== $lu) {
                $this->compteurs['fiches_a_modifier']++;
                $plan[$id] = [
                    'lu_relation' => $lu['relation_type'],
                    'lu_etape' => $lu['lifecycle_stage'],
                    'relation_type' => $etat['relation_type'],
                    'lifecycle_stage' => $etat['lifecycle_stage'],
                    'sources' => array_values(array_unique($sources)),
                ];
            }
        }

        return $plan;
    }

    /**
     * @param  array<int, array{lu_relation: string, lu_etape: string, relation_type: string, lifecycle_stage: string, sources: list<string>}>  $plan
     * @return array<int, true> identifiants réellement écrits
     */
    private function ecrire(string $workspaceId, array $plan): array
    {
        $liaisons = [];
        foreach ($plan as $id => $p) {
            array_push($liaisons, $id, $p['relation_type'], $p['lifecycle_stage'], $p['lu_relation'], $p['lu_etape']);
        }
        $liaisons[] = $workspaceId;
        $lignes = DB::select(
            'UPDATE companies AS c
             SET relation_type = v.relation_type, lifecycle_stage = v.lifecycle_stage
             FROM (VALUES ' . implode(', ', array_fill(0, count($plan), '(?::bigint, ?::text, ?::text, ?::text, ?::text)')) . ') AS v(id, relation_type, lifecycle_stage, lu_relation, lu_etape)
             WHERE c.id = v.id AND c.workspace_id = ?::uuid AND c.deleted_at IS NULL
               AND c.relation_type = v.lu_relation AND c.lifecycle_stage = v.lu_etape
               AND c.relation_saisie_manuelle_at IS NULL
             RETURNING c.id',
            $liaisons,
        );
        $ecrites = [];
        foreach ($lignes as $l) {
            if ($l instanceof stdClass) {
                $ecrites[(int) $l->id] = true;
            }
        }

        return $ecrites;
    }

    /**
     * Une activité `stage_changed` par fiche promue — la timeline dit d'où
     * vient le changement.
     *
     * @param  array<int, array{lu_relation: string, lu_etape: string, relation_type: string, lifecycle_stage: string, sources: list<string>}>  $plan
     * @param  array<int, true>  $ecrites
     */
    private function journaliser(string $workspaceId, array $plan, array $ecrites): void
    {
        $maintenant = now();
        $lignes = [];
        foreach ($plan as $id => $p) {
            if (! isset($ecrites[$id])) {
                continue;
            }
            $lignes[] = [
                'workspace_id' => $workspaceId,
                'type' => 'stage_changed',
                'kind' => 'stage_changed',
                'occurred_at' => $maintenant,
                'subject_type' => 'company',
                'subject_id' => $id,
                'user_id' => null,
                'title' => 'Relation : ' . $p['lu_relation'] . ' → ' . $p['relation_type'] . ' ; étape : ' . $p['lu_etape'] . ' → ' . $p['lifecycle_stage'],
                'payload' => json_encode([
                    'relation' => ['from' => $p['lu_relation'], 'to' => $p['relation_type']],
                    'etape' => ['from' => $p['lu_etape'], 'to' => $p['lifecycle_stage']],
                    'source' => 'crm:relations:importer',
                    'origines' => $p['sources'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $maintenant,
            ];
        }
        if ($lignes !== []) {
            DB::table('activities')->insert($lignes);
        }
    }

    private function afficherBilan(bool $dryRun, bool $discret): void
    {
        $this->newLine();
        $this->info($dryRun ? '═══ BILAN DE L\'ESSAI À BLANC (rien n\'a été écrit) ═══' : '═══ BILAN DE L\'IMPORT ═══');
        $compteurs = $this->compteurs;
        if ($dryRun) {
            unset($compteurs['fiches_modifiees'], $compteurs['modifiees_entre_temps']);
        }
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($compteurs),
            array_values($compteurs),
        ));
        if ($this->rejets !== []) {
            ksort($this->rejets);
            $this->line('Lignes rejetées, par motif :');
            $this->table(['motif', 'nombre'], array_map(
                static fn (string $motif, int $n): array => [$motif, $n],
                array_keys($this->rejets),
                array_values($this->rejets),
            ));
        }
        if (! $discret && $this->lignesParMotif !== []) {
            $this->line('Numéros de ligne (' . self::LIGNES_AFFICHEES . ' au plus par motif) :');
            foreach ($this->lignesParMotif as $motif => $numeros) {
                sort($numeros);
                $this->line("  {$motif} : " . implode(', ', $numeros));
            }
        }
        $this->line('Aucune fiche créée ni supprimée : seuls le type de relation et l\'étape des fiches reconnues sont écrits.');
    }

    /** « utilisateur@hôte » du processus qui a lancé la commande. */
    private static function operateur(): string
    {
        $utilisateur = get_current_user();
        $hote = gethostname();

        return ($utilisateur !== '' ? $utilisateur : '?') . '@' . ($hote !== false ? $hote : '?');
    }

    /** @param  array<string, mixed>  $details */
    private function auditer(AuditHashChain $audit, string $workspaceId, string $operateur, string $evenement, int $statut, array $details, string $resume): void
    {
        $audit->record([
            'workspace_id' => $workspaceId,
            'user_id' => null,
            'method' => $evenement,
            'path' => 'artisan crm:relations:importer — ' . $resume,
            'status' => $statut,
            'ip' => null,
            'user_agent' => 'cli ' . $operateur,
            'payload_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR)),
        ]);
    }
}

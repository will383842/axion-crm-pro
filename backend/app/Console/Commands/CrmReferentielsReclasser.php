<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use App\Crm\EspaceProspection;
use App\Crm\FichesProtegees;
use App\Crm\Referentiels\Classement;
use App\Crm\Referentiels\EtiquettesClassement;
use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * RECLASSEMENT DE MASSE — secteur, taille, nature, région, et leurs étiquettes.
 *
 * Réapplique à TOUTES les fiches d'un espace le calcul unique
 * (`App\Crm\Referentiels\Classement`), celui que la collecte INSEE et
 * l'enrichissement appliquent désormais aux fiches nouvelles :
 *
 *   - `sector_main` depuis le code NAF, lu dans SA nomenclature (rév. 2,
 *     rév. 1, NAP 1973) — 472 785 fiches à code de 1993 étaient lues comme de
 *     la rév. 2 et rangées dans un mauvais secteur. Quand le code ne dit rien
 *     (`non_classe`), un secteur VALIDE déjà posé est conservé
 *     (`interprofessionnel`, secteur représenté) ;
 *   - `naf_nomenclature`, `naf_rev2` (le code d'origine `naf` n'est jamais
 *     réécrit) ;
 *   - `size_category` dans les quatre tailles (`micro` → `tpe`,
 *     `grande`/`grande_entreprise` → `grand_groupe`) ;
 *   - `entity_nature` = `entreprise` pour les fiches INSEE qui n'en ont pas ;
 *   - `region_code` depuis le département ;
 *   - une chaîne vide (`''`) dans une colonne de classement devient NULL ;
 *   - les étiquettes automatiques `sector-…`, `size-…`, `region-…`
 *     RESYNCHRONISÉES avec la fiche. Jamais touchées : les étiquettes `src:`,
 *     les verrouillées (`is_locked`), les manuelles (`kind = manual` ou posées
 *     par un utilisateur).
 *
 * ── CE QUI REND LA COMMANDE SÛRE SUR 4,3 M DE FICHES ──────────────────────
 *
 *  - PAR LOTS (`--lot`, 2 000 par défaut), chacun dans SA transaction courte,
 *    avec `lock_timeout` : jamais un `UPDATE` géant qui verrouillerait la
 *    table, jamais une transaction de plusieurs minutes.
 *  - Curseur par identifiant : chaque lot annonce le dernier id traité ; une
 *    exécution interrompue se REPREND par `--depuis-id=<cet id>`.
 *  - IDEMPOTENTE : une fiche déjà juste n'est pas réécrite.
 *  - Une fiche MODIFIÉE entre la lecture et l'écriture de son lot n'est pas
 *    écrasée : l'`UPDATE` exige que TOUTES les données lues (code NAF,
 *    effectif, catégorie INSEE, taille, nature, secteur, département, région,
 *    pays, source) n'aient pas bougé. Elle est comptée « modifiée
 *    entre-temps », et ses étiquettes ne sont PAS touchées ; la rejouer suffit.
 *  - `updated_at` n'est PAS touché (`app.conserver_updated_at`, cf. migration
 *    `2026_09_28_000001`) : reclasser n'est pas modifier la fiche.
 *  - Les fiches PROTÉGÉES (`FichesProtegees`) sont exclues — à la lecture ET
 *    dans l'`UPDATE` et le `DELETE` eux-mêmes.
 *  - Sous RLS : tout se fait dans le contexte de l'espace (`WorkspaceContext`),
 *    et chaque requête filtre AUSSI `workspace_id`.
 *  - Journalisée AU FIL DE L'EAU : une entrée de la chaîne d'audit par lot
 *    écrit (intervalle d'identifiants, qui a lancé la commande), et une entrée
 *    de fin même en cas d'échec.
 *  - Les AUDIENCES qui citent une ancienne valeur sont signalées AVANT toute
 *    écriture. Une audience d'EXCLUSION (bloc `not`, `neq`, `not_in`) qui en
 *    cite une ne viserait plus « personne de moins » mais TOUT LE MONDE :
 *    l'exécution réelle refuse alors de partir, sauf `--accepter-audiences`.
 *
 * ── L'ESSAI À BLANC NE MENT PAS ──────────────────────────────────────────
 *
 * `--dry-run` n'écrit RIEN — pas même dans une transaction annulée : il LIT
 * chaque lot et calcule, avec le même code que l'exécution réelle, ce qui
 * serait écrit, y compris les étiquettes obsolètes qui seraient supprimées et
 * la décision de la garde B15-008.
 *
 * `--compteurs-seulement` : pour les journaux des workflows GitHub (dépôt
 * PUBLIC) — aucun nom d'audience, seulement des nombres.
 */
class CrmReferentielsReclasser extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'crm:referentiels:reclasser
                            {--dry-run : Tout lire et tout calculer, ne RIEN écrire, et afficher le bilan avant/après}
                            {--workspace= : Identifiant ou slug de l\'espace (défaut : celui de prospection:collect)}
                            {--lot=2000 : Nombre de fiches par lot (1 à 3500)}
                            {--depuis-id=0 : Reprendre APRÈS cette fiche (dernier id annoncé par une exécution interrompue)}
                            {--max-lots=0 : S\'arrêter après N lots (0 = jusqu\'au bout)}
                            {--pause-ms=0 : Pause entre deux lots, pour ménager la base}
                            {--sans-etiquettes : Ne pas resynchroniser les étiquettes sector-/size-/region-}
                            {--accepter-audiences : Partir malgré des audiences d\'exclusion qui citent une valeur obsolète}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}
                            {--force : Lever le plafond de proportion de la suppression des étiquettes obsolètes}';

    protected $description = 'Reclasse toutes les fiches (secteur, taille, nature, région, étiquettes) selon le référentiel unique.';

    /** Colonnes que la commande écrit. */
    private const COLONNES = ['sector_main', 'naf_nomenclature', 'naf_rev2', 'size_category', 'entity_nature', 'region_code'];

    /**
     * Colonnes LUES et comparées dans l'`UPDATE` (garde de concurrence) :
     * tout ce dont dépend le calcul, plus ce qu'il écrit.
     */
    private const GARDE = [
        'naf' => 'c.naf',
        'effectif_range' => 'c.effectif_range',
        'categorie_entreprise' => "c.metadata->>'categorie_entreprise'",
        'size_category' => 'c.size_category',
        'entity_nature' => 'c.entity_nature',
        'sector_main' => 'c.sector_main',
        'department_code' => 'c.department_code',
        'region_code' => 'c.region_code',
        'country_code' => 'c.country_code',
        'discovery_source' => 'c.discovery_source',
    ];

    /** 17 paramètres liés par fiche : 3 500 × 17 reste sous la limite de 65 535. */
    private const LOT_MAX = 3500;

    private const COULEURS = ['sector' => 'violet', 'size' => 'amber', 'geo' => 'sky'];

    /** @var array<string, array<string, array<int|string, int>>> dimension => [avant|apres => [valeur => n]] */
    private array $repartitions = [];

    /** @var array<string, int> */
    private array $compteurs = [];

    /** @var array<string, int> méthode de calcul du secteur => n */
    private array $methodes = [];

    /** @var array<string, int> slug => tag_id (familles sector-/size-/region- de l'espace) */
    private array $tagIds = [];

    /** @var array<string, true> slugs dont le nom a déjà été aligné pendant cette exécution */
    private array $tagsAlignes = [];

    public function handle(AuditHashChain $audit): int
    {
        $designation = is_string($this->option('workspace')) ? $this->option('workspace') : null;
        $workspaceId = EspaceProspection::resoudre($designation);
        if ($workspaceId === null) {
            $this->error('Espace introuvable : « ' . ($designation ?? '(défaut)') . ' ».');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $lot = max(1, min(self::LOT_MAX, (int) $this->option('lot')));
        $depuis = max(0, (int) $this->option('depuis-id'));
        $maxLots = max(0, (int) $this->option('max-lots'));
        $pauseMs = max(0, (int) $this->option('pause-ms'));
        $etiquettes = ! (bool) $this->option('sans-etiquettes');
        $discret = (bool) $this->option('compteurs-seulement');
        $operateur = self::operateur();

        $this->reinitialiser();
        $this->info(sprintf(
            '%s — espace %s, lots de %d, à partir de l\'id %d%s.',
            $dryRun ? '[À BLANC] rien ne sera écrit' : 'Reclassement',
            $workspaceId,
            $lot,
            $depuis,
            $etiquettes ? ', étiquettes comprises' : ', SANS les étiquettes',
        ));

        // ── Avant toute écriture : le bon espace, et les audiences ──────────
        $refus = WorkspaceContext::run($workspaceId, fn (): ?string => $this->espaceSansFicheInsee($workspaceId));
        if ($refus !== null) {
            $this->error($refus);

            return self::FAILURE;
        }

        $audiences = WorkspaceContext::run($workspaceId, fn (): array => $this->audiencesObsoletes($workspaceId));
        $this->afficherAudiences($audiences, $discret);
        $exclusions = count(array_filter($audiences, static fn (array $a): bool => $a['exclusion']));
        if ($exclusions > 0 && ! $dryRun && ! (bool) $this->option('accepter-audiences')) {
            $this->error(
                "REFUS : {$exclusions} audience(s) d'EXCLUSION citent une valeur obsolète. Après reclassement, "
                . 'elles n\'excluraient plus personne et viseraient donc TOUTE la base. Réécrivez-les à l\'écran, '
                . 'ou relancez avec --accepter-audiences. Rien n\'a été écrit.',
            );

            return self::FAILURE;
        }

        $dernier = $depuis;
        $termine = false;
        $erreur = null;
        try {
            WorkspaceContext::run($workspaceId, function () use ($workspaceId, $dryRun, $lot, $maxLots, $pauseMs, $etiquettes, $audit, $operateur, &$dernier, &$termine, &$erreur): void {
                $this->compteurs['fiches_protegees_exclues'] = $this->compterProtegees($workspaceId);
                if ($etiquettes) {
                    $this->chargerTags($workspaceId);
                }

                $lots = 0;
                while (true) {
                    $fiches = $this->lireLot($workspaceId, $dernier, $lot);
                    if ($fiches === []) {
                        $termine = true;

                        return;
                    }
                    $ids = array_map(static fn (stdClass $f): int => (int) $f->id, $fiches);
                    $bas = min($ids);
                    $haut = max($ids);
                    $avant = $this->compteurs;

                    try {
                        $this->traiterLot($workspaceId, $fiches, $dryRun, $etiquettes);
                    } catch (QueryException $e) {
                        // Le lot est annulé en entier (sa transaction) ; tout ce
                        // qui précède est acquis. Seul le code d'état part au
                        // journal : le message SQL peut citer des valeurs.
                        Log::error('crm:referentiels:reclasser : lot refusé par la base', [
                            'apres_id' => $dernier, 'sqlstate' => $e->getCode(),
                        ]);
                        $erreur = (string) $e->getCode();

                        return;
                    }

                    $lots++;
                    $dernier = $haut;
                    $this->compteurs['lots']++;
                    if (! $dryRun) {
                        $this->auditer($audit, $workspaceId, $operateur, 'RECLASSEMENT_REFERENTIELS_LOT', 200, [
                            'ids' => [$bas, $haut],
                            'fiches_modifiees' => $this->compteurs['fiches_modifiees'] - $avant['fiches_modifiees'],
                            'etiquettes_ajoutees' => $this->compteurs['etiquettes_ajoutees'] - $avant['etiquettes_ajoutees'],
                            'etiquettes_retirees' => $this->compteurs['etiquettes_retirees'] - $avant['etiquettes_retirees'],
                        ], "ids {$bas}-{$haut}");
                    }
                    $this->line(sprintf(
                        '  lot %d : %d fiches, ids %d à %d — %d à modifier%s',
                        $lots,
                        count($fiches),
                        $bas,
                        $haut,
                        $this->compteurs['fiches_a_modifier'],
                        $dryRun ? '' : sprintf(' (%d modifiées)', $this->compteurs['fiches_modifiees']),
                    ));
                    Log::info('crm:referentiels:reclasser lot', [
                        'a_blanc' => $dryRun, 'lot' => $lots, 'ids' => [$bas, $haut],
                        'fiches_lues' => $this->compteurs['fiches_lues'],
                        'fiches_a_modifier' => $this->compteurs['fiches_a_modifier'],
                        'fiches_modifiees' => $this->compteurs['fiches_modifiees'],
                    ]);

                    if ($maxLots > 0 && $lots >= $maxLots) {
                        return;
                    }
                    if ($pauseMs > 0) {
                        usleep($pauseMs * 1000);
                    }
                }
            });

            if ($termine && $etiquettes) {
                WorkspaceContext::run($workspaceId, function () use ($workspaceId, $dryRun): void {
                    $this->etiquettesObsoletes($workspaceId, $dryRun);
                });
            }
        } catch (Throwable $e) {
            $erreur ??= get_class($e);

            throw $e;
        } finally {
            if (! $dryRun) {
                // L'entrée de FIN, même quand la commande échoue ou est
                // interrompue par une exception : la chaîne dit où l'on s'est
                // arrêté, et qui avait lancé la commande.
                $this->auditer($audit, $workspaceId, $operateur, 'RECLASSEMENT_REFERENTIELS_FIN', $erreur === null ? 200 : 500, [
                    'termine' => $termine, 'dernier_id' => $dernier, 'erreur' => $erreur, 'compteurs' => $this->compteurs,
                ], $termine ? 'terminé' : "arrêté après l'id {$dernier}");
            }
        }

        $this->afficherBilan($dryRun, $etiquettes);
        Log::info('crm:referentiels:reclasser fin', [
            'a_blanc' => $dryRun, 'termine' => $termine, 'dernier_id' => $dernier,
            'erreur' => $erreur, 'compteurs' => $this->compteurs, 'operateur' => $operateur,
        ]);

        if ($erreur !== null) {
            $this->error("ÉCHEC : la base a refusé un lot (SQLSTATE {$erreur}). Rien n'a été écrit pour ce lot ; tout ce qui précède est acquis.");
            $this->error("Reprendre avec : --depuis-id={$dernier}");

            return self::FAILURE;
        }
        if (! $termine) {
            $this->warn("Arrêt demandé après {$this->compteurs['lots']} lot(s). Reprendre avec : --depuis-id={$dernier}");
        } else {
            $this->info($dryRun ? '[À BLANC] terminé : rien n\'a été écrit.' : 'Reclassement terminé.');
        }

        return self::SUCCESS;
    }

    private function reinitialiser(): void
    {
        $this->repartitions = [];
        $this->methodes = [];
        $this->tagIds = [];
        $this->tagsAlignes = [];
        $this->compteurs = [
            'lots' => 0,
            'fiches_lues' => 0,
            'fiches_a_modifier' => 0,
            'fiches_modifiees' => 0,
            'fiches_modifiees_entre_temps' => 0,
            'etiquettes_a_ajouter' => 0,
            'etiquettes_ajoutees' => 0,
            'etiquettes_a_retirer' => 0,
            'etiquettes_retirees' => 0,
            'fiches_protegees_exclues' => 0,
            'etiquettes_obsoletes_a_supprimer' => 0,
            'etiquettes_obsoletes_supprimees' => 0,
            'garde_b15008_refuserait' => 0,
            'audiences_obsoletes' => 0,
            'audiences_exclusion_obsoletes' => 0,
        ];
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
            'path' => 'artisan crm:referentiels:reclasser — ' . $resume,
            'status' => $statut,
            'ip' => null,
            'user_agent' => 'cli ' . $operateur,
            'payload_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * Refuse de tourner sur un espace qui n'a AUCUNE fiche INSEE quand un
     * autre en porte : ce serait un reclassement à vide, qui annoncerait
     * « 0 à modifier » pendant que les vraies fiches restent mal rangées.
     * (Sous le rôle applicatif, la RLS masque les autres espaces : la garde n'y
     * voit que celui-ci — elle ne peut alors que laisser passer.)
     */
    private function espaceSansFicheInsee(string $workspaceId): ?string
    {
        $aDesFiches = DB::table('companies')->where('workspace_id', $workspaceId)
            ->where('discovery_source', 'insee')->exists();
        if ($aDesFiches) {
            return null;
        }
        $ailleurs = DB::table('companies')->where('workspace_id', '<>', $workspaceId)
            ->where('discovery_source', 'insee')->value('workspace_id');

        return $ailleurs === null ? null
            : "REFUS : l'espace visé n'a aucune fiche INSEE, alors que l'espace {$ailleurs} en porte. "
                . 'Préciser --workspace (la collecte écrit dans ' . (EspaceProspection::parDefaut() ?? '?') . ').';
    }

    private function compterProtegees(string $workspaceId): int
    {
        return (int) DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('company_tag')
                    ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                    ->whereColumn('company_tag.company_id', 'companies.id')
                    ->whereIn('tags.slug', FichesProtegees::TAGS);
            })
            ->count();
    }

    /** @return list<stdClass> */
    private function lireLot(string $workspaceId, int $apresId, int $taille): array
    {
        $colonnes = [];
        foreach (self::GARDE as $alias => $expression) {
            $colonnes[] = "{$expression} AS {$alias}";
        }
        $lignes = DB::select(
            'SELECT c.id, c.naf_nomenclature, c.naf_rev2, ' . implode(', ', $colonnes) . '
             FROM companies c
             WHERE c.workspace_id = ? AND c.id > ? AND ' . FichesProtegees::conditionSql('c.id') . '
             ORDER BY c.id
             LIMIT ' . $taille,
            [$workspaceId, $apresId],
        );

        $fiches = [];
        foreach ($lignes as $ligne) {
            if ($ligne instanceof stdClass) {
                $fiches[] = $ligne;
            }
        }

        return $fiches;
    }

    /**
     * Calcule le lot (bilan compris) puis, hors essai à blanc, l'écrit dans
     * UNE transaction courte.
     *
     * @param  list<stdClass>  $fiches
     */
    private function traiterLot(string $workspaceId, array $fiches, bool $dryRun, bool $etiquettes): void
    {
        /** @var list<array{fiche: stdClass, nouveau: array<string, ?string>}> $aModifier */
        $aModifier = [];
        /** @var array<int, array{secteur: ?string, taille: ?string, region: ?string}> $classementFinal */
        $classementFinal = [];

        foreach ($fiches as $f) {
            $this->compteurs['fiches_lues']++;
            $calcul = Classement::pourFiche([
                'naf' => self::brut($f->naf),
                'effectif_range' => self::brut($f->effectif_range),
                'categorie_entreprise' => self::brut($f->categorie_entreprise),
                'size_category' => self::brut($f->size_category),
                'entity_nature' => self::brut($f->entity_nature),
                'sector_main' => self::brut($f->sector_main),
                'department_code' => self::brut($f->department_code),
                'region_code' => self::brut($f->region_code),
                'country_code' => self::brut($f->country_code),
                'discovery_source' => self::brut($f->discovery_source),
            ]);
            $this->methodes[$calcul['methode_secteur']] = ($this->methodes[$calcul['methode_secteur']] ?? 0) + 1;

            $nouveau = [];
            $change = false;
            foreach (self::COLONNES as $colonne) {
                // La valeur BRUTE (une chaîne vide reste une chaîne vide) : c'est
                // elle qu'on compare, et c'est ainsi qu'un `''` est bien réécrit.
                $actuel = self::brut($f->{$colonne});
                $nouveau[$colonne] = Classement::valeurAEcrire($calcul[$colonne], $actuel);
                if ($nouveau[$colonne] !== $actuel) {
                    $change = true;
                }
            }

            $this->compter('secteur', self::brut($f->sector_main), $nouveau['sector_main']);
            $this->compter('taille', self::brut($f->size_category), $nouveau['size_category']);
            $this->compter('nature', self::brut($f->entity_nature), $nouveau['entity_nature']);
            $this->compter('region', self::brut($f->region_code), $nouveau['region_code']);
            $this->compter('nomenclature', self::brut($f->naf_nomenclature), $nouveau['naf_nomenclature']);

            if ($change) {
                $this->compteurs['fiches_a_modifier']++;
                $aModifier[] = ['fiche' => $f, 'nouveau' => $nouveau];
            }
            $classementFinal[(int) $f->id] = [
                'secteur' => $nouveau['sector_main'],
                'taille' => $nouveau['size_category'],
                'region' => $nouveau['region_code'],
            ];
        }

        $plan = $etiquettes ? $this->planEtiquettes($classementFinal) : ['ajouts' => [], 'retraits' => []];
        $this->compteurs['etiquettes_a_ajouter'] += count($plan['ajouts']);
        $this->compteurs['etiquettes_a_retirer'] += count($plan['retraits']);

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($workspaceId, $aModifier, $plan): void {
            // Un verrou qui ne vient pas en 5 s fait échouer CE lot (et la
            // commande, qui dit où reprendre) plutôt que de faire la queue
            // devant tout le trafic de la console.
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");

            // Les étiquettes ne suivent QUE les fiches qui sont justes après ce
            // lot : déjà justes, ou effectivement réécrites. Une fiche
            // « modifiée entre-temps » garde ses étiquettes jusqu'au prochain
            // passage — jamais une étiquette qui contredirait la fiche.
            $ecartees = [];
            if ($aModifier !== []) {
                $ecrites = $this->ecrireFiches($workspaceId, $aModifier);
                $this->compteurs['fiches_modifiees'] += count($ecrites);
                foreach ($aModifier as ['fiche' => $f]) {
                    if (! isset($ecrites[(int) $f->id])) {
                        $ecartees[(int) $f->id] = true;
                        $this->compteurs['fiches_modifiees_entre_temps']++;
                    }
                }
            }
            $retraits = array_values(array_filter($plan['retraits'], static fn (array $r): bool => ! isset($ecartees[$r['company_id']])));
            $ajouts = array_values(array_filter($plan['ajouts'], static fn (array $a): bool => ! isset($ecartees[$a['company_id']])));
            if ($retraits !== []) {
                $this->compteurs['etiquettes_retirees'] += $this->retirerEtiquettes($retraits);
            }
            if ($ajouts !== []) {
                $this->compteurs['etiquettes_ajoutees'] += $this->ajouterEtiquettes($workspaceId, $ajouts);
            }
        });
    }

    /**
     * @param  list<array{fiche: stdClass, nouveau: array<string, ?string>}>  $aModifier
     * @return array<int, true> identifiants effectivement réécrits
     */
    private function ecrireFiches(string $workspaceId, array $aModifier): array
    {
        $gabarit = '(?::bigint, ?::text, ?::text, ?::varchar, ?::text, ?::text, ?::text'
            . str_repeat(', ?::text', count(self::GARDE)) . ')';
        $valeurs = [];
        $liaisons = [];
        foreach ($aModifier as ['fiche' => $f, 'nouveau' => $n]) {
            $valeurs[] = $gabarit;
            array_push(
                $liaisons,
                (int) $f->id,
                $n['sector_main'],
                $n['naf_nomenclature'],
                $n['naf_rev2'],
                $n['size_category'],
                $n['entity_nature'],
                $n['region_code'],
            );
            // Ce qu'on a LU, brut (`''` compris) : l'écriture n'a lieu que si
            // rien n'a bougé depuis.
            foreach (array_keys(self::GARDE) as $cle) {
                $liaisons[] = self::brut($f->{$cle});
            }
        }
        $liaisons[] = $workspaceId;

        $noms = ['id', 'sector_main', 'naf_nomenclature', 'naf_rev2', 'size_category', 'entity_nature', 'region_code'];
        $gardes = [];
        foreach (self::GARDE as $cle => $expression) {
            $noms[] = 'lu_' . $cle;
            $gardes[] = "{$expression} IS NOT DISTINCT FROM v.lu_{$cle}";
        }

        $lignes = DB::select(
            'UPDATE companies AS c
             SET sector_main = v.sector_main, naf_nomenclature = v.naf_nomenclature, naf_rev2 = v.naf_rev2,
                 size_category = v.size_category, entity_nature = v.entity_nature, region_code = v.region_code
             FROM (VALUES ' . implode(', ', $valeurs) . ') AS v(' . implode(', ', $noms) . ')
             WHERE c.id = v.id AND c.workspace_id = ?
               AND ' . implode("\n               AND ", $gardes) . '
               AND ' . FichesProtegees::conditionSql('c.id') . '
             RETURNING c.id',
            $liaisons,
            false,
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
     * Ce qu'il faut ajouter et retirer pour que les étiquettes des familles
     * `sector-`, `size-`, `region-` reflètent la fiche.
     *
     * @param  array<int, array{secteur: ?string, taille: ?string, region: ?string}>  $classements
     * @return array{ajouts: list<array{company_id: int, slug: string}>, retraits: list<array{company_id: int, tag_id: int}>}
     */
    private function planEtiquettes(array $classements): array
    {
        $attachees = [];
        if ($classements !== []) {
            $lignes = DB::table('company_tag as ct')
                ->join('tags as t', 't.id', '=', 'ct.tag_id')
                ->whereIn('ct.company_id', array_keys($classements))
                ->where(function (QueryBuilder $q): void {
                    foreach (EtiquettesClassement::PREFIXES as $prefixe) {
                        $q->orWhere('t.slug', 'like', $prefixe . '%');
                    }
                })
                ->get(['ct.company_id', 'ct.tag_id', 't.slug', 't.kind', 't.is_locked', 'ct.assigned_by']);
            foreach ($lignes as $l) {
                $attachees[(int) $l->company_id][] = $l;
            }
        }

        $ajouts = [];
        $retraits = [];
        foreach ($classements as $companyId => $c) {
            $desirees = EtiquettesClassement::desirees($c['secteur'], $c['taille'], $c['region']);
            $presentes = [];
            foreach ($attachees[$companyId] ?? [] as $l) {
                $slug = (string) $l->slug;
                $presentes[$slug] = true;
                if (isset($desirees[$slug]) || ! self::retirable($l)) {
                    continue;
                }
                $retraits[] = ['company_id' => $companyId, 'tag_id' => (int) $l->tag_id];
            }
            foreach (array_keys($desirees) as $slug) {
                if (! isset($presentes[$slug])) {
                    $ajouts[] = ['company_id' => $companyId, 'slug' => $slug];
                }
            }
        }

        return ['ajouts' => $ajouts, 'retraits' => $retraits];
    }

    /**
     * Même règle que `AutoTaggerService::syncTags()` : on ne retire que ce que
     * l'automate a posé. Jamais une étiquette manuelle, verrouillée, ni `src:`.
     */
    private static function retirable(stdClass $lien): bool
    {
        if ((string) $lien->assigned_by === 'user' || (string) $lien->kind === 'manual') {
            return false;
        }
        if ((bool) $lien->is_locked) {
            return false;
        }

        return ! str_starts_with((string) $lien->slug, 'src:');
    }

    /** @param  list<array{company_id: int, tag_id: int}>  $retraits */
    private function retirerEtiquettes(array $retraits): int
    {
        $n = 0;
        foreach (array_chunk($retraits, 1000) as $paquet) {
            $valeurs = implode(', ', array_fill(0, count($paquet), '(?::bigint, ?::bigint)'));
            $liaisons = [];
            foreach ($paquet as $r) {
                array_push($liaisons, $r['company_id'], $r['tag_id']);
            }
            $n += DB::delete(
                "DELETE FROM company_tag ct USING (VALUES {$valeurs}) AS v(company_id, tag_id)
                 WHERE ct.company_id = v.company_id AND ct.tag_id = v.tag_id
                   AND " . FichesProtegees::conditionSql('ct.company_id'),
                $liaisons,
            );
        }

        return $n;
    }

    /** @param  list<array{company_id: int, slug: string}>  $ajouts */
    private function ajouterEtiquettes(string $workspaceId, array $ajouts): int
    {
        $n = 0;
        $maintenant = now();
        foreach (array_chunk($ajouts, 1000) as $paquet) {
            $lignes = [];
            foreach ($paquet as $a) {
                $lignes[] = [
                    'company_id' => $a['company_id'],
                    'tag_id' => $this->tagId($workspaceId, $a['slug']),
                    'workspace_id' => $workspaceId,
                    'assigned_at' => $maintenant,
                    'assigned_by' => 'auto-rule',
                ];
            }
            $n += DB::table('company_tag')->insertOrIgnore($lignes);
        }

        return $n;
    }

    private function chargerTags(string $workspaceId): void
    {
        $lignes = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where(function (QueryBuilder $q): void {
                foreach (EtiquettesClassement::PREFIXES as $prefixe) {
                    $q->orWhere('slug', 'like', $prefixe . '%');
                }
            })
            ->get(['id', 'slug']);
        foreach ($lignes as $l) {
            $this->tagIds[(string) $l->slug] = (int) $l->id;
        }
    }

    /**
     * L'identifiant de l'étiquette, créée si besoin — et son NOM aligné sur
     * le référentiel une fois par exécution (`Région 84` devient
     * `Région : Auvergne-Rhône-Alpes`), SEULEMENT si c'est une étiquette
     * automatique non verrouillée : un nom choisi à la main ne se renomme pas.
     */
    private function tagId(string $workspaceId, string $slug): int
    {
        if (isset($this->tagIds[$slug], $this->tagsAlignes[$slug])) {
            return $this->tagIds[$slug];
        }

        $spec = $this->specTag($slug);
        $maintenant = now();
        DB::table('tags')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'slug' => $slug,
            'name' => $spec['name'],
            'color' => self::COULEURS[$spec['category']] ?? 'slate',
            'category' => $spec['category'],
            'kind' => 'auto',
            'rules' => '[]',
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
        DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('slug', $slug)
            ->where('kind', 'auto')
            ->where('is_locked', false)
            ->where('name', '<>', $spec['name'])
            ->update(['name' => $spec['name']]);
        $id = (int) DB::table('tags')->where('workspace_id', $workspaceId)->where('slug', $slug)->value('id');
        $this->tagIds[$slug] = $id;
        $this->tagsAlignes[$slug] = true;

        return $id;
    }

    /** @return array{name: string, category: string} */
    private function specTag(string $slug): array
    {
        foreach (array_keys(Taxonomy::SECTEURS) as $cle) {
            if (EtiquettesClassement::slugSecteur($cle) === $slug) {
                return EtiquettesClassement::desirees($cle, null, null)[$slug];
            }
        }
        foreach (array_keys(Taxonomy::TAILLES) as $cle) {
            if (EtiquettesClassement::slugTaille($cle) === $slug) {
                return EtiquettesClassement::desirees(null, $cle, null)[$slug];
            }
        }
        foreach (array_keys(Taxonomy::REGIONS) as $code) {
            if (EtiquettesClassement::slugRegion((string) $code) === $slug) {
                return EtiquettesClassement::desirees(null, null, (string) $code)[$slug];
            }
        }
        // Une valeur conservée hors référentiel (région étrangère…) : même
        // nommage que l'automate, libellé brut.
        foreach (['sector-' => 'sector', 'size-' => 'size', 'region-' => 'geo'] as $prefixe => $categorie) {
            if (str_starts_with($slug, $prefixe)) {
                return ['name' => $slug, 'category' => $categorie];
            }
        }

        return ['name' => $slug, 'category' => 'custom'];
    }

    /** @return list<string> slugs de famille qui correspondent au référentiel */
    private static function slugsValides(): array
    {
        $valides = [];
        foreach (array_keys(Taxonomy::SECTEURS) as $cle) {
            $valides[] = EtiquettesClassement::slugSecteur($cle);
        }
        foreach (array_keys(Taxonomy::TAILLES) as $cle) {
            $valides[] = EtiquettesClassement::slugTaille($cle);
        }
        foreach (array_keys(Taxonomy::REGIONS) as $code) {
            $valides[] = EtiquettesClassement::slugRegion((string) $code);
        }

        return $valides;
    }

    /**
     * Les étiquettes `sector-`/`size-` qui ne correspondent plus à AUCUNE
     * valeur du référentiel (`sector-it-saas`, `size-micro`…) : supprimées quand
     * plus aucune fiche ne les porte. Jamais une verrouillée ni une manuelle.
     *
     * À blanc : on CHIFFRE celles que l'exécution supprimerait — celles
     * qu'aucun lien intouchable (posé par un utilisateur, ou sur une fiche
     * protégée) ne retiendra — et ce que dirait la garde B15-008.
     */
    private function etiquettesObsoletes(string $workspaceId, bool $dryRun): void
    {
        $candidates = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('kind', 'auto')
            ->where('is_locked', false)
            ->where(function (QueryBuilder $q): void {
                $q->where('slug', 'like', 'sector-%')->orWhere('slug', 'like', 'size-%');
            })
            ->whereNotIn('slug', self::slugsValides());

        if ($dryRun) {
            $supprimables = (clone $candidates)->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')->from('company_tag as ct')->whereColumn('ct.tag_id', 'tags.id')
                    ->where(function (QueryBuilder $q): void {
                        $q->where('ct.assigned_by', 'user')
                            ->orWhereRaw('NOT (' . FichesProtegees::conditionSql('ct.company_id') . ')');
                    });
            });
        } else {
            $supprimables = (clone $candidates)->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')->from('company_tag')->whereColumn('company_tag.tag_id', 'tags.id');
            });
        }

        $n = (clone $supprimables)->count();
        $this->compteurs['etiquettes_obsoletes_a_supprimer'] = $n;

        // Garde commune des commandes qui suppriment (B15-008) : un plafond de
        // proportion, qui refuse si ce « ménage » visait une grande part des
        // étiquettes — ce serait un détecteur qui se trompe, pas un ménage.
        $total = (int) DB::table('tags')->where('workspace_id', $workspaceId)->count();
        $autorise = $n === 0 || $this->ecritureAutoriseeSansOperateur('tags', $n, $total, 'supprimer');
        if (! $autorise) {
            $this->compteurs['garde_b15008_refuserait'] = 1;
        }
        if ($dryRun || ! $autorise || $n === 0) {
            return;
        }

        $this->compteurs['etiquettes_obsoletes_supprimees'] = $supprimables->delete();
    }

    /**
     * Les audiences enregistrées qui citent une valeur de secteur, de taille
     * ou une étiquette que le reclassement rend obsolète (`it_saas`, `micro`,
     * `sector-services-pro`, `nature-entreprise`…).
     *
     * Deux familles, et la seconde est la dangereuse :
     *  - une audience d'INCLUSION (« secteur = it_saas ») ne vise plus
     *    personne ;
     *  - une audience d'EXCLUSION (bloc `not`, opérateurs `neq`, `not_in`)
     *    n'exclut plus rien : elle s'ÉLARGIT à toute la base.
     *
     * Elles ne sont PAS réécrites d'office — `commerce` se partage désormais en
     * trois secteurs, c'est un choix de ciblage, pas une traduction.
     *
     * @return list<array{id: string, nom: string, valeurs: string, exclusion: bool}>
     */
    private function audiencesObsoletes(string $workspaceId): array
    {
        $secteurs = array_keys(Taxonomy::SECTEURS);
        $tailles = array_keys(Taxonomy::TAILLES);
        $slugsValides = self::slugsValides();
        $sortie = [];

        $lignes = DB::table('email_audiences')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'criteria']);
        foreach ($lignes as $a) {
            $criteres = json_decode((string) $a->criteria, true);
            if (! is_array($criteres)) {
                continue;
            }
            $fautives = [];
            $exclusion = false;
            foreach (['all', 'any', 'not'] as $bloc) {
                foreach (is_array($criteres[$bloc] ?? null) ? $criteres[$bloc] : [] as $cond) {
                    if (! is_array($cond) || ! is_string($cond['field'] ?? null)) {
                        continue;
                    }
                    $champ = $cond['field'];
                    $op = is_string($cond['op'] ?? null) ? $cond['op'] : '';
                    $valeurs = is_array($cond['value'] ?? null) ? $cond['value'] : [$cond['value'] ?? null];
                    foreach ($valeurs as $v) {
                        if (! is_string($v)) {
                            continue;
                        }
                        $hors = match ($champ) {
                            'sector_main' => ! in_array($v, $secteurs, true),
                            'size_category' => ! in_array($v, $tailles, true),
                            'tags' => $v === 'nature-entreprise'
                                || ((str_starts_with($v, 'sector-') || str_starts_with($v, 'size-'))
                                    && ! in_array($v, $slugsValides, true)),
                            default => false,
                        };
                        if ($hors) {
                            $fautives[] = "{$champ}={$v}";
                            if ($bloc === 'not' || in_array($op, ['neq', 'not_in'], true)) {
                                $exclusion = true;
                            }
                        }
                    }
                }
            }
            if ($fautives !== []) {
                $sortie[] = [
                    'id' => (string) $a->id,
                    'nom' => (string) $a->name,
                    'valeurs' => implode(', ', array_unique($fautives)),
                    'exclusion' => $exclusion,
                ];
            }
        }

        $this->compteurs['audiences_obsoletes'] = count($sortie);
        $this->compteurs['audiences_exclusion_obsoletes'] = count(array_filter($sortie, static fn (array $a): bool => $a['exclusion']));

        return $sortie;
    }

    /** @param  list<array{id: string, nom: string, valeurs: string, exclusion: bool}>  $audiences */
    private function afficherAudiences(array $audiences, bool $discret): void
    {
        if ($audiences === []) {
            $this->line('Audiences : aucune ne cite une valeur obsolète.');

            return;
        }
        $exclusions = count(array_filter($audiences, static fn (array $a): bool => $a['exclusion']));
        $this->warn(sprintf(
            '%d audience(s) citent une valeur obsolète, dont %d d\'EXCLUSION (elles s\'élargiraient à toute la base).',
            count($audiences),
            $exclusions,
        ));
        if ($discret) {
            // Journaux publics : aucun nom d'audience.
            return;
        }
        $lignes = [];
        foreach ($audiences as $a) {
            $lignes[] = [$a['id'], $a['nom'], $a['exclusion'] ? 'EXCLUSION' : 'inclusion', $a['valeurs']];
        }
        $this->table(['id', 'audience', 'type', 'valeurs obsolètes'], $lignes);
    }

    private function compter(string $dimension, ?string $avant, ?string $apres): void
    {
        $a = $avant ?? '(vide)';
        $b = $apres ?? '(vide)';
        $this->repartitions[$dimension]['avant'][$a] = ($this->repartitions[$dimension]['avant'][$a] ?? 0) + 1;
        $this->repartitions[$dimension]['apres'][$b] = ($this->repartitions[$dimension]['apres'][$b] ?? 0) + 1;
    }

    private function afficherBilan(bool $dryRun, bool $etiquettes): void
    {
        $this->newLine();
        $this->info($dryRun ? '═══ BILAN DE L\'ESSAI À BLANC (rien n\'a été écrit) ═══' : '═══ BILAN DU RECLASSEMENT ═══');

        $libelles = [
            'secteur' => Taxonomy::SECTEURS,
            'taille' => Taxonomy::TAILLES,
            'nature' => Taxonomy::ENTITY_NATURES,
            'region' => Taxonomy::REGIONS,
            'nomenclature' => [],
        ];
        $titres = [
            'secteur' => 'Secteur (sector_main)',
            'taille' => 'Taille (size_category)',
            'nature' => 'Nature (entity_nature)',
            'region' => 'Région (region_code)',
            'nomenclature' => 'Nomenclature du code NAF (naf_nomenclature)',
        ];
        foreach ($titres as $dimension => $titre) {
            $avant = $this->repartitions[$dimension]['avant'] ?? [];
            $apres = $this->repartitions[$dimension]['apres'] ?? [];
            // `(string)` : une clé « 84 » devient un entier dans un tableau PHP.
            $valeurs = array_values(array_unique(array_map(
                static fn (int|string $v): string => (string) $v,
                array_merge(array_keys($avant), array_keys($apres)),
            )));
            usort($valeurs, static fn (string $x, string $y): int => ($apres[$y] ?? 0) <=> ($apres[$x] ?? 0) ?: strcmp($x, $y));
            $lignes = [];
            $horsReferentiel = 0;
            foreach ($valeurs as $v) {
                $connu = $v === '(vide)' || $libelles[$dimension] === [] || array_key_exists($v, $libelles[$dimension]);
                if (! $connu) {
                    $horsReferentiel += $apres[$v] ?? 0;
                }
                $lignes[] = [
                    $v,
                    $libelles[$dimension][$v] ?? ($connu ? '' : '⚠ hors référentiel'),
                    $avant[$v] ?? 0,
                    $apres[$v] ?? 0,
                    sprintf('%+d', ($apres[$v] ?? 0) - ($avant[$v] ?? 0)),
                ];
            }
            $this->newLine();
            $this->line("<comment>{$titre}</comment>");
            $this->table(['valeur', 'libellé', 'avant', 'après', 'écart'], $lignes);
            if ($libelles[$dimension] !== []) {
                $this->line("  valeurs hors référentiel restantes après : {$horsReferentiel}");
            }
        }

        $this->newLine();
        $this->line('<comment>Méthode de calcul du secteur</comment>');
        arsort($this->methodes);
        $this->table(['méthode', 'fiches'], array_map(
            static fn (string $m, int $n): array => [$m, $n],
            array_keys($this->methodes),
            array_values($this->methodes),
        ));

        $this->newLine();
        $compteurs = $this->compteurs;
        if ($dryRun) {
            unset($compteurs['fiches_modifiees'], $compteurs['fiches_modifiees_entre_temps'],
                $compteurs['etiquettes_ajoutees'], $compteurs['etiquettes_retirees'],
                $compteurs['etiquettes_obsoletes_supprimees']);
        }
        if (! $etiquettes) {
            unset($compteurs['etiquettes_a_ajouter'], $compteurs['etiquettes_a_retirer'],
                $compteurs['etiquettes_ajoutees'], $compteurs['etiquettes_retirees'],
                $compteurs['etiquettes_obsoletes_a_supprimer'], $compteurs['etiquettes_obsoletes_supprimees'],
                $compteurs['garde_b15008_refuserait']);
        }
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($compteurs),
            array_values($compteurs),
        ));
    }

    /**
     * La valeur telle que la base la rend : une chaîne vide RESTE une chaîne
     * vide (ce n'est pas NULL, et la garde de concurrence doit la comparer
     * telle quelle).
     */
    private static function brut(mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        return is_scalar($valeur) ? (string) $valeur : null;
    }
}

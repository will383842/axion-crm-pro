<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
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

/**
 * RECLASSEMENT DE MASSE — secteur, taille, nature, région, et leurs étiquettes.
 *
 * Réapplique à TOUTES les fiches d'un espace le calcul unique
 * (`App\Crm\Referentiels\Classement`), celui que la collecte INSEE et
 * l'enrichissement appliquent désormais aux fiches nouvelles :
 *
 *   - `sector_main` depuis le code NAF, lu dans SA nomenclature (rév. 2,
 *     rév. 1, NAP 1973) — 472 785 fiches à code de 1993 étaient lues comme de
 *     la rév. 2 et rangées dans un mauvais secteur ;
 *   - `naf_nomenclature`, `naf_rev2` (le code d'origine `naf` n'est jamais
 *     réécrit) ;
 *   - `size_category` dans les quatre tailles (`micro` → `tpe`,
 *     `grande`/`grande_entreprise` → `grand_groupe`) ;
 *   - `entity_nature` = `entreprise` pour les fiches INSEE qui n'en ont pas ;
 *   - `region_code` depuis le département (les fiches collectées n'en avaient
 *     pas : la région n'était posée qu'à l'enrichissement) ;
 *   - les étiquettes automatiques `sector-…`, `size-…`, `region-…`
 *     RESYNCHRONISÉES avec la fiche (772 k fiches « commerce » pour 147 k
 *     étiquettes, avant). Jamais touchées : les étiquettes `src:`, les
 *     verrouillées (`is_locked`), les manuelles (`kind = manual` ou posées par
 *     un utilisateur).
 *
 * ── CE QUI REND LA COMMANDE SÛRE SUR 4,3 M DE FICHES ──────────────────────
 *
 *  - PAR LOTS (`--lot`, 2 000 par défaut), chacun dans SA transaction courte,
 *    avec `lock_timeout` : jamais un `UPDATE` géant qui verrouillerait la
 *    table, jamais une transaction de plusieurs minutes.
 *  - Curseur par identifiant : chaque lot annonce le dernier id traité ; une
 *    exécution interrompue se REPREND par `--depuis-id=<cet id>`.
 *  - IDEMPOTENTE : une fiche déjà juste n'est pas réécrite (on compare avant
 *    d'écrire) ; rejouer la commande du début ne fait que vérifier.
 *  - Une fiche MODIFIÉE entre la lecture et l'écriture de son lot (un
 *    enrichissement passait par là) n'est pas écrasée : l'`UPDATE` exige que
 *    les données d'entrée soient restées celles qu'on a lues. Elle est comptée
 *    « modifiée entre-temps » ; la rejouer suffit.
 *  - `updated_at` n'est PAS touché (`app.conserver_updated_at`, cf. migration
 *    `2026_09_28_000001`) : reclasser n'est pas modifier la fiche.
 *  - Les fiches PROTÉGÉES (`FichesProtegees`) sont exclues : aucun automatisme
 *    ne les touche.
 *  - Sous RLS : tout se fait dans le contexte de l'espace (`WorkspaceContext`),
 *    et chaque requête filtre AUSSI `workspace_id`.
 *
 * ── L'ESSAI À BLANC NE MENT PAS ──────────────────────────────────────────
 *
 * `--dry-run` n'écrit RIEN — pas même dans une transaction annulée : il LIT
 * chaque lot et calcule, avec le même code que l'exécution réelle, ce qui
 * serait écrit. Le bilan AVANT/APRÈS par secteur, par taille, par nature et par
 * région est donc celui que produira l'exécution (à la concurrence près : ce
 * qui aura changé entre les deux passages).
 */
class CrmReferentielsReclasser extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'crm:referentiels:reclasser
                            {--dry-run : Tout lire et tout calculer, ne RIEN écrire, et afficher le bilan avant/après}
                            {--workspace= : Slug de l\'espace (défaut : l\'espace business)}
                            {--lot=2000 : Nombre de fiches par lot (1 à 4000)}
                            {--depuis-id=0 : Reprendre APRÈS cette fiche (dernier id annoncé par une exécution interrompue)}
                            {--max-lots=0 : S\'arrêter après N lots (0 = jusqu\'au bout)}
                            {--pause-ms=0 : Pause entre deux lots, pour ménager la base}
                            {--sans-etiquettes : Ne pas resynchroniser les étiquettes sector-/size-/region-}
                            {--force : Lever le plafond de proportion de la suppression des étiquettes obsolètes}';

    protected $description = 'Reclasse toutes les fiches (secteur, taille, nature, région, étiquettes) selon le référentiel unique.';

    /** Colonnes que la commande écrit. */
    private const COLONNES = ['sector_main', 'naf_nomenclature', 'naf_rev2', 'size_category', 'entity_nature', 'region_code'];

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
        $slug = (string) ($this->option('workspace') ?: config('crm.ingest.business_workspace', 'axion-ia'));
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;

        $dryRun = (bool) $this->option('dry-run');
        // 4 000 au plus : 13 paramètres liés par fiche, sous la limite de
        // 65 535 de PostgreSQL.
        $lot = max(1, min(4000, (int) $this->option('lot')));
        $depuis = max(0, (int) $this->option('depuis-id'));
        $maxLots = max(0, (int) $this->option('max-lots'));
        $pauseMs = max(0, (int) $this->option('pause-ms'));
        $etiquettes = ! (bool) $this->option('sans-etiquettes');

        $this->reinitialiser();
        $this->info(sprintf(
            '%s — espace %s, lots de %d, à partir de l\'id %d%s.',
            $dryRun ? '[À BLANC] rien ne sera écrit' : 'Reclassement',
            $slug,
            $lot,
            $depuis,
            $etiquettes ? ', étiquettes comprises' : ', SANS les étiquettes',
        ));

        $resultat = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $dryRun, $lot, $depuis, $maxLots, $pauseMs, $etiquettes): array {
            $this->compteurs['fiches_protegees_exclues'] = $this->compterProtegees($workspaceId);
            if ($etiquettes) {
                $this->chargerTags($workspaceId);
            }

            $dernier = $depuis;
            $lots = 0;
            while (true) {
                $fiches = $this->lireLot($workspaceId, $dernier, $lot);
                if ($fiches === []) {
                    return ['termine' => true, 'dernier' => $dernier, 'erreur' => null];
                }
                $idsLot = array_map(static fn (stdClass $f): int => (int) $f->id, $fiches);
                $haut = max($idsLot);

                try {
                    $this->traiterLot($workspaceId, $fiches, $dryRun, $etiquettes);
                } catch (QueryException $e) {
                    // Le lot est annulé en entier (sa transaction) ; tout ce
                    // qui précède est acquis. Seul le code d'état part au
                    // journal : le message SQL peut citer des valeurs.
                    Log::error('crm:referentiels:reclasser : lot refusé par la base', [
                        'apres_id' => $dernier, 'sqlstate' => $e->getCode(),
                    ]);

                    return ['termine' => false, 'dernier' => $dernier, 'erreur' => (string) $e->getCode()];
                }

                $lots++;
                $dernier = $haut;
                $this->compteurs['lots']++;
                $this->line(sprintf(
                    '  lot %d : %d fiches, jusqu\'à l\'id %d — %d à modifier%s',
                    $lots,
                    count($fiches),
                    $dernier,
                    $this->compteurs['fiches_a_modifier'],
                    $dryRun ? '' : sprintf(' (%d modifiées)', $this->compteurs['fiches_modifiees']),
                ));
                Log::info('crm:referentiels:reclasser lot', [
                    'a_blanc' => $dryRun, 'lot' => $lots, 'jusqu_a_id' => $dernier,
                    'fiches_lues' => $this->compteurs['fiches_lues'],
                    'fiches_a_modifier' => $this->compteurs['fiches_a_modifier'],
                    'fiches_modifiees' => $this->compteurs['fiches_modifiees'],
                ]);

                if ($maxLots > 0 && $lots >= $maxLots) {
                    return ['termine' => false, 'dernier' => $dernier, 'erreur' => null];
                }
                if ($pauseMs > 0) {
                    usleep($pauseMs * 1000);
                }
            }
        });

        $termine = (bool) $resultat['termine'];
        $dernier = (int) $resultat['dernier'];
        $erreur = $resultat['erreur'];

        $orphelines = 0;
        if ($termine && ! $dryRun && $etiquettes) {
            $orphelines = WorkspaceContext::run($workspaceId, fn (): int => $this->retirerEtiquettesObsoletes($workspaceId));
        }
        $this->compteurs['etiquettes_obsoletes_supprimees'] = $orphelines;

        $audiences = WorkspaceContext::run($workspaceId, fn (): array => $this->audiencesAReecrire($workspaceId));

        $this->afficherBilan($dryRun, $etiquettes, $audiences);

        if (! $dryRun) {
            $audit->record([
                'workspace_id' => $workspaceId,
                'user_id' => null,
                'method' => 'RECLASSEMENT_REFERENTIELS',
                'path' => 'artisan crm:referentiels:reclasser',
                'status' => $erreur === null ? 200 : 500,
                'ip' => null,
                'user_agent' => null,
                'payload_hash' => hash('sha256', json_encode([$this->compteurs, $dernier], JSON_THROW_ON_ERROR)),
            ]);
        }
        Log::info('crm:referentiels:reclasser fin', [
            'a_blanc' => $dryRun, 'termine' => $termine, 'dernier_id' => $dernier,
            'erreur' => $erreur, 'compteurs' => $this->compteurs,
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
            'etiquettes_obsoletes_supprimees' => 0,
        ];
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
        $lignes = DB::select(
            "SELECT c.id, c.naf, c.effectif_range, c.metadata->>'categorie_entreprise' AS categorie_entreprise,
                    c.size_category, c.entity_nature, c.discovery_source, c.department_code,
                    c.region_code, c.country_code, c.sector_main, c.naf_nomenclature, c.naf_rev2
             FROM companies c
             WHERE c.workspace_id = ? AND c.id > ? AND " . FichesProtegees::conditionSql('c.id') . '
             ORDER BY c.id
             LIMIT ' . $taille,
            [$workspaceId, $apresId],
        );

        return array_values(array_filter($lignes, static fn ($l): bool => $l instanceof stdClass));
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
                'naf' => self::texte($f->naf),
                'effectif_range' => self::texte($f->effectif_range),
                'categorie_entreprise' => self::texte($f->categorie_entreprise),
                'size_category' => self::texte($f->size_category),
                'entity_nature' => self::texte($f->entity_nature),
                'discovery_source' => self::texte($f->discovery_source),
                'department_code' => self::texte($f->department_code),
                'region_code' => self::texte($f->region_code),
                'country_code' => self::texte($f->country_code),
            ]);
            $this->methodes[$calcul['methode_secteur']] = ($this->methodes[$calcul['methode_secteur']] ?? 0) + 1;

            $nouveau = [];
            $change = false;
            foreach (self::COLONNES as $colonne) {
                $actuel = self::texte($f->{$colonne});
                // Ce que le calcul ne sait pas établir (null) n'efface JAMAIS
                // une valeur posée — même règle que l'enrichissement.
                $valeur = $calcul[$colonne] ?? $actuel;
                $nouveau[$colonne] = $valeur;
                if ($valeur !== $actuel) {
                    $change = true;
                }
            }

            $this->compter('secteur', self::texte($f->sector_main), $nouveau['sector_main']);
            $this->compter('taille', self::texte($f->size_category), $nouveau['size_category']);
            $this->compter('nature', self::texte($f->entity_nature), $nouveau['entity_nature']);
            $this->compter('region', self::texte($f->region_code), $nouveau['region_code']);
            $this->compter('nomenclature', self::texte($f->naf_nomenclature), $nouveau['naf_nomenclature']);

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

            if ($aModifier !== []) {
                $modifiees = $this->ecrireFiches($workspaceId, $aModifier);
                $this->compteurs['fiches_modifiees'] += $modifiees;
                $this->compteurs['fiches_modifiees_entre_temps'] += count($aModifier) - $modifiees;
            }
            if ($plan['retraits'] !== []) {
                $this->compteurs['etiquettes_retirees'] += $this->retirerEtiquettes($plan['retraits']);
            }
            if ($plan['ajouts'] !== []) {
                $this->compteurs['etiquettes_ajoutees'] += $this->ajouterEtiquettes($workspaceId, $plan['ajouts']);
            }
        });
    }

    /**
     * @param  list<array{fiche: stdClass, nouveau: array<string, ?string>}>  $aModifier
     */
    private function ecrireFiches(string $workspaceId, array $aModifier): int
    {
        $valeurs = [];
        $liaisons = [];
        foreach ($aModifier as ['fiche' => $f, 'nouveau' => $n]) {
            $valeurs[] = '(?::bigint, ?::text, ?::text, ?::varchar, ?::text, ?::text, ?::text,'
                . ' ?::text, ?::text, ?::text, ?::text, ?::text, ?::text)';
            array_push(
                $liaisons,
                (int) $f->id,
                $n['sector_main'],
                $n['naf_nomenclature'],
                $n['naf_rev2'],
                $n['size_category'],
                $n['entity_nature'],
                $n['region_code'],
                // Ce qu'on a LU : l'écriture n'a lieu que si rien n'a bougé.
                self::texte($f->naf),
                self::texte($f->effectif_range),
                self::texte($f->size_category),
                self::texte($f->entity_nature),
                self::texte($f->department_code),
                self::texte($f->region_code),
            );
        }
        $liaisons[] = $workspaceId;

        return DB::update(
            'UPDATE companies AS c
             SET sector_main = v.sector_main, naf_nomenclature = v.naf_nomenclature, naf_rev2 = v.naf_rev2,
                 size_category = v.size_category, entity_nature = v.entity_nature, region_code = v.region_code
             FROM (VALUES ' . implode(', ', $valeurs) . ') AS v(id, sector_main, naf_nomenclature, naf_rev2,
                   size_category, entity_nature, region_code, lu_naf, lu_effectif, lu_taille, lu_nature, lu_dept, lu_region)
             WHERE c.id = v.id AND c.workspace_id = ?
               AND c.naf IS NOT DISTINCT FROM v.lu_naf
               AND c.effectif_range IS NOT DISTINCT FROM v.lu_effectif
               AND c.size_category IS NOT DISTINCT FROM v.lu_taille
               AND c.entity_nature IS NOT DISTINCT FROM v.lu_nature
               AND c.department_code IS NOT DISTINCT FROM v.lu_dept
               AND c.region_code IS NOT DISTINCT FROM v.lu_region',
            $liaisons,
        );
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
            $ids = array_keys($classements);
            $lignes = DB::table('company_tag as ct')
                ->join('tags as t', 't.id', '=', 'ct.tag_id')
                ->whereIn('ct.company_id', $ids)
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
                 WHERE ct.company_id = v.company_id AND ct.tag_id = v.tag_id",
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
     * `Région : Auvergne-Rhône-Alpes`).
     */
    private function tagId(string $workspaceId, string $slug): int
    {
        if (isset($this->tagIds[$slug], $this->tagsAlignes[$slug])) {
            return $this->tagIds[$slug];
        }

        $spec = $this->specTag($slug);
        $maintenant = now();
        DB::table('tags')->upsert(
            [[
                'workspace_id' => $workspaceId,
                'slug' => $slug,
                'name' => $spec['name'],
                'color' => self::COULEURS[$spec['category']] ?? 'slate',
                'category' => $spec['category'],
                'kind' => 'auto',
                'rules' => '[]',
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]],
            ['workspace_id', 'slug'],
            ['name'],
        );
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

    /**
     * Les étiquettes `sector-`/`size-`/`region-` qui ne correspondent plus à
     * AUCUNE valeur du référentiel (`sector-it-saas`, `size-micro`…) et que plus
     * aucune fiche ne porte. Jamais une verrouillée ni une manuelle.
     */
    private function retirerEtiquettesObsoletes(string $workspaceId): int
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

        $obsoletes = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('kind', 'auto')
            ->where('is_locked', false)
            ->where(function (QueryBuilder $q): void {
                $q->where('slug', 'like', 'sector-%')->orWhere('slug', 'like', 'size-%');
            })
            ->whereNotIn('slug', $valides)
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')->from('company_tag')->whereColumn('company_tag.tag_id', 'tags.id');
            });

        // Garde commune des commandes qui suppriment (B15-008) : un plafond de
        // proportion, qui refuse si ce « ménage » visait une grande part des
        // étiquettes — ce serait un détecteur qui se trompe, pas un ménage.
        $total = (int) DB::table('tags')->where('workspace_id', $workspaceId)->count();
        if (! $this->ecritureAutoriseeSansOperateur('tags', (clone $obsoletes)->count(), $total, 'supprimer')) {
            return 0;
        }

        return $obsoletes->delete();
    }

    /**
     * Les audiences enregistrées qui citent une valeur de secteur ou de taille
     * hors référentiel (ou une étiquette `sector-`/`size-` obsolète) : après le
     * reclassement, elles ne viseraient plus personne. Elles ne sont PAS
     * réécrites d'office — `commerce` se partage désormais en trois secteurs,
     * c'est un choix de ciblage, pas une traduction.
     *
     * @return list<array{id: string, nom: string, valeurs: string}>
     */
    private function audiencesAReecrire(string $workspaceId): array
    {
        $secteurs = array_keys(Taxonomy::SECTEURS);
        $tailles = array_keys(Taxonomy::TAILLES);
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
            foreach (['all', 'any', 'not'] as $bloc) {
                foreach (is_array($criteres[$bloc] ?? null) ? $criteres[$bloc] : [] as $cond) {
                    if (! is_array($cond)) {
                        continue;
                    }
                    $champ = $cond['field'] ?? null;
                    if (! is_string($champ)) {
                        continue;
                    }
                    $valeurs = is_array($cond['value'] ?? null) ? $cond['value'] : [$cond['value'] ?? null];
                    foreach ($valeurs as $v) {
                        if (! is_string($v)) {
                            continue;
                        }
                        $hors = match ($champ) {
                            'sector_main' => ! in_array($v, $secteurs, true),
                            'size_category' => ! in_array($v, $tailles, true),
                            'tags' => (str_starts_with($v, 'sector-') || str_starts_with($v, 'size-'))
                                && ! in_array($v, array_merge(
                                    array_map([EtiquettesClassement::class, 'slugSecteur'], $secteurs),
                                    array_map([EtiquettesClassement::class, 'slugTaille'], $tailles),
                                ), true),
                            default => false,
                        };
                        if ($hors) {
                            $fautives[] = "{$champ}={$v}";
                        }
                    }
                }
            }
            if ($fautives !== []) {
                $sortie[] = ['id' => (string) $a->id, 'nom' => (string) $a->name, 'valeurs' => implode(', ', array_unique($fautives))];
            }
        }

        return $sortie;
    }

    private function compter(string $dimension, ?string $avant, ?string $apres): void
    {
        $a = $avant ?? '(vide)';
        $b = $apres ?? '(vide)';
        $this->repartitions[$dimension]['avant'][$a] = ($this->repartitions[$dimension]['avant'][$a] ?? 0) + 1;
        $this->repartitions[$dimension]['apres'][$b] = ($this->repartitions[$dimension]['apres'][$b] ?? 0) + 1;
    }

    /** @param  list<array{id: string, nom: string, valeurs: string}>  $audiences */
    private function afficherBilan(bool $dryRun, bool $etiquettes, array $audiences): void
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
                $compteurs['etiquettes_obsoletes_supprimees']);
        }
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($compteurs),
            array_values($compteurs),
        ));

        if ($audiences !== []) {
            $this->newLine();
            $this->warn(count($audiences) . ' audience(s) citent des valeurs hors référentiel — à réécrire à l\'écran :');
            $this->table(['id', 'audience', 'valeurs obsolètes'], array_map(
                static fn (array $a): array => [$a['id'], $a['nom'], $a['valeurs']],
                $audiences,
            ));
        }
    }

    private static function texte(mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $v = is_scalar($valeur) ? (string) $valeur : '';

        return $v === '' ? null : $v;
    }
}

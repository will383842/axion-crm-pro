<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use App\Crm\EspaceProspection;
use App\Crm\Etiquettes\FamillesEtiquettes;
use App\Crm\FichesProtegees;
use App\Crm\Referentiels\Classement;
use App\Crm\Referentiels\EtiquettesClassement;
use App\Crm\Referentiels\Metiers;
use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Closure;
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
 *   - les étiquettes automatiques `sector-…`, `size-…`, `region-…` et, depuis
 *     le chantier 2 (2026-09-29), `metier-…` (le métier lu dans `naf_rev2`,
 *     `App\Crm\Referentiels\Metiers`) RESYNCHRONISÉES avec la fiche. Jamais
 *     touchées : les étiquettes `src:`, les verrouillées (`is_locked`), les
 *     manuelles (`kind = manual` ou posées par un utilisateur), celles de l'IA
 *     (`kind = llm`, même si leur slug ressemble à une famille).
 *
 * ── LE RANGEMENT DES ÉTIQUETTES (chantier 2, 2026-09-29) ─────────────────
 *
 * Après le dernier lot, et seulement quand la commande est allée au bout :
 *   - les étiquettes IA (`kind = llm`) encore rangées en `intent` passent dans
 *     leur catégorie `ia` — par paquets, sans en supprimer une seule ;
 *   - le MÉNAGE, sous la garde B15-008 (plafond de proportion, `--force`) :
 *       · les étiquettes `sector-`/`size-`/`metier-` qui ne correspondent plus
 *         au référentiel, dès qu'aucune fiche ne les porte ;
 *       · les étiquettes ORPHELINES : automatiques ou IA, non verrouillées,
 *         sans namespace (jamais une `src:`, jamais une gouvernée), sans règle,
 *         qu'AUCUNE fiche ni AUCUN candidat ne porte, et qu'aucune audience
 *         enregistrée ne cite. La liste est arrêtée AVANT les lots : l'essai à
 *         blanc chiffre ce que l'exécution supprimera.
 *     Une étiquette portée n'est JAMAIS supprimée : la suppression elle-même
 *     porte la condition « aucun lien », relue au moment d'écrire.
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
 *    dans l'`UPDATE` et le `DELETE` eux-mêmes — sauf `--inclure-protegees`
 *    (voir plus bas).
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
 *
 * ── `--inclure-protegees` (2026-09-29) ───────────────────────────────────
 *
 * Reclasse AUSSI les fiches protégées (organisateurs d'événements,
 * fédérations), que la commande exclut par défaut. Ce que l'option touche,
 * et RIEN d'autre : les six colonnes de classement (`COLONNES`) et les
 * étiquettes automatiques `sector-`/`size-`/`region-`/`metier-` — par le même `UPDATE`,
 * les mêmes plans d'étiquettes et les mêmes gardes que pour toute fiche.
 * Aucune ligne `contacts`, aucune coordonnée, aucune fiche : la commande n'a
 * aucune requête vers eux, avec ou sans l'option (Will, 27/09 : les contacts
 * de ces fiches ne se suppriment pas).
 *
 * Pour les fiches protégées seulement, la NATURE n'est jamais DEVINÉE
 * (`entreprise` pour une fiche INSEE qui n'en a pas) : un organisateur ou une
 * fédération rattaché à une fiche INSEE n'est pas une société commerciale.
 * Déjà renseignée, elle ne bouge jamais — c'est la règle commune.
 *
 * Pour TOUTES les fiches (seules les fédérations la déclenchent aujourd'hui) :
 * B4 — un secteur VALIDE choisi par l'import des fédérations
 * (`field_origins.sector_main` = `federations-2026`) n'est jamais écrasé, même
 * par un code NAF qui parle : c'est le secteur REPRÉSENTÉ, que seul cet
 * import a le droit de corriger.
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
                            {--sans-etiquettes : Ne pas resynchroniser les étiquettes sector-/size-/region-/metier-, ni les ranger}
                            {--accepter-audiences : Partir malgré des audiences d\'exclusion qui citent une valeur obsolète}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}
                            {--force : Lever le plafond de proportion de la suppression des étiquettes obsolètes et orphelines}
                            {--inclure-protegees : Reclasser AUSSI les fiches protégées (classement et étiquettes sector-/size-/region-/metier- seulement)}';

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
        // B4 : le secteur choisi par l'import des fédérations ne s'écrase pas.
        'origine_secteur' => "c.field_origins->>'sector_main'",
    ];

    /** 18 paramètres liés par fiche : 3 500 × 18 reste sous la limite de 65 535. */
    private const LOT_MAX = 3500;

    private const COULEURS = ['sector' => 'violet', 'size' => 'amber', 'geo' => 'sky'];

    /** Taille des paquets du rangement des étiquettes (table `tags`). */
    private const PAQUET_RANGEMENT = 1000;

    /** @var array<string, array<string, array<int|string, int>>> dimension => [avant|apres => [valeur => n]] */
    private array $repartitions = [];

    /** @var array<string, int> */
    private array $compteurs = [];

    /** @var array<string, int> méthode de calcul du secteur => n */
    private array $methodes = [];

    /** @var array<string, int> slug => tag_id (familles sector-/size-/region-/metier- de l'espace) */
    private array $tagIds = [];

    /** @var array<string, true> slugs dont le nom a déjà été aligné pendant cette exécution */
    private array $tagsAlignes = [];

    /** `--inclure-protegees` : les fiches protégées sont reclassées aussi. */
    private bool $inclureProtegees = false;

    /** @var list<int> étiquettes orphelines, arrêtées AVANT les lots */
    private array $orphelines = [];

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
        $this->inclureProtegees = (bool) $this->option('inclure-protegees');
        $this->info(sprintf(
            '%s — espace %s, lots de %d, à partir de l\'id %d%s%s.',
            $dryRun ? '[À BLANC] rien ne sera écrit' : 'Reclassement',
            // Journaux publics (`--compteurs-seulement`) : pas d'identifiant.
            $discret ? '(masqué)' : $workspaceId,
            $lot,
            $depuis,
            $etiquettes ? ', étiquettes comprises' : ', SANS les étiquettes',
            $this->inclureProtegees ? ', fiches PROTÉGÉES comprises (classement seulement)' : '',
        ));

        // ── Avant toute écriture : le bon espace, et les audiences ──────────
        $refus = WorkspaceContext::run($workspaceId, fn (): ?string => $this->espaceSansFicheInsee($workspaceId, $discret));
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
        $auditFinEchoue = null;
        try {
            WorkspaceContext::run($workspaceId, function () use ($workspaceId, $dryRun, $lot, $maxLots, $pauseMs, $etiquettes, $audit, $operateur, &$dernier, &$termine, &$erreur): void {
                $protegees = $this->compterProtegees($workspaceId);
                $this->compteurs[$this->inclureProtegees ? 'fiches_protegees_incluses' : 'fiches_protegees_exclues'] = $protegees;
                if ($etiquettes) {
                    $this->chargerTags($workspaceId);
                    // Arrêtée AVANT toute écriture : l'essai à blanc et
                    // l'exécution visent la même liste.
                    $this->orphelines = $this->etiquettesOrphelines($workspaceId);
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

                    // L'entrée d'audit du lot est écrite DANS la transaction du
                    // lot, en dernier : un lot validé a toujours sa trace, et un
                    // audit qui échoue annule le lot au lieu de le laisser
                    // passer sans trace.
                    $tracer = function () use ($audit, $workspaceId, $operateur, $avant, $bas, $haut): void {
                        $this->auditer($audit, $workspaceId, $operateur, 'RECLASSEMENT_REFERENTIELS_LOT', 200, [
                            'ids' => [$bas, $haut],
                            'fiches_modifiees' => $this->compteurs['fiches_modifiees'] - $avant['fiches_modifiees'],
                            'etiquettes_ajoutees' => $this->compteurs['etiquettes_ajoutees'] - $avant['etiquettes_ajoutees'],
                            'etiquettes_retirees' => $this->compteurs['etiquettes_retirees'] - $avant['etiquettes_retirees'],
                        ], "ids {$bas}-{$haut}");
                    };

                    try {
                        $this->traiterLot($workspaceId, $fiches, $dryRun, $etiquettes, $tracer);
                    } catch (Throwable $e) {
                        // Le lot est annulé en entier (sa transaction, audit
                        // compris) ; tout ce qui précède est acquis. Seul le code
                        // d'état (ou la classe) part au journal : le message SQL
                        // peut citer des valeurs.
                        $erreur = $e instanceof QueryException ? 'SQLSTATE ' . $e->getCode() : get_class($e);
                        Log::error('crm:referentiels:reclasser : lot annulé', [
                            'apres_id' => $dernier, 'erreur' => $erreur,
                        ]);
                        // Les compteurs du lot annulé ne comptent pas.
                        $this->compteurs = $avant;

                        return;
                    }

                    $lots++;
                    $dernier = $haut;
                    $this->compteurs['lots']++;
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
                    $this->rangerEtiquettesIa($workspaceId, $dryRun);
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
                // arrêté, et qui avait lancé la commande. Si ELLE échoue, on ne
                // masque pas l'exception d'origine : on le dit, et la commande
                // sort en échec.
                try {
                    $this->auditer($audit, $workspaceId, $operateur, 'RECLASSEMENT_REFERENTIELS_FIN', $erreur === null ? 200 : 500, [
                        'termine' => $termine, 'dernier_id' => $dernier, 'erreur' => $erreur, 'compteurs' => $this->compteurs,
                        'inclure_protegees' => $this->inclureProtegees,
                    ], $termine ? 'terminé' : "arrêté après l'id {$dernier}");
                } catch (Throwable $e) {
                    $auditFinEchoue = get_class($e);
                    Log::error('crm:referentiels:reclasser : entrée d\'audit de fin NON écrite', [
                        'dernier_id' => $dernier, 'erreur' => $auditFinEchoue,
                    ]);
                }
            }
        }

        $this->afficherBilan($dryRun, $etiquettes, $discret);
        Log::info('crm:referentiels:reclasser fin', [
            'a_blanc' => $dryRun, 'termine' => $termine, 'dernier_id' => $dernier,
            'erreur' => $erreur, 'compteurs' => $this->compteurs, 'operateur' => $operateur,
        ]);

        if ($erreur !== null) {
            $this->error("ÉCHEC : un lot a été annulé ({$erreur}). Rien n'a été écrit pour ce lot ; tout ce qui précède est acquis et journalisé.");
            $this->error("Reprendre avec : --depuis-id={$dernier}");

            return self::FAILURE;
        }
        if ($auditFinEchoue !== null) {
            $this->error("ÉCHEC : l'entrée d'audit de fin n'a pas pu être écrite ({$auditFinEchoue}). Les lots, eux, sont écrits ET journalisés un par un.");

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
        $this->orphelines = [];
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
            'fiches_protegees_incluses' => 0,
            'natures_protegees_non_devinees' => 0,
            'secteurs_import_conserves' => 0,
            'etiquettes_obsoletes_a_supprimer' => 0,
            'etiquettes_obsoletes_supprimees' => 0,
            'etiquettes_orphelines_a_supprimer' => 0,
            'etiquettes_orphelines_supprimees' => 0,
            'etiquettes_ia_a_ranger' => 0,
            'etiquettes_ia_rangees' => 0,
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
    private function espaceSansFicheInsee(string $workspaceId, bool $discret = false): ?string
    {
        // Hors corbeille (`deleted_at`) : une fiche supprimée ne fait pas un
        // espace de prospection.
        $aDesFiches = DB::table('companies')->where('workspace_id', $workspaceId)
            ->where('discovery_source', 'insee')->whereNull('deleted_at')->exists();
        if ($aDesFiches) {
            return null;
        }
        $ailleurs = DB::table('companies')->where('workspace_id', '<>', $workspaceId)
            ->where('discovery_source', 'insee')->whereNull('deleted_at')->value('workspace_id');

        if ($ailleurs === null) {
            return null;
        }
        if ($discret) {
            return "REFUS : l'espace visé n'a aucune fiche INSEE, alors qu'un autre espace en porte. Préciser --workspace.";
        }

        return "REFUS : l'espace visé n'a aucune fiche INSEE, alors que l'espace {$ailleurs} en porte. "
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

    /**
     * La condition « fiche non protégée » sur `$colonneId` — ou `TRUE` sous
     * `--inclure-protegees`. UNE seule bascule, posée dans CHACUNE des
     * requêtes qui lisent ou écrivent (lecture du lot, `UPDATE`, retrait et
     * ajout d'étiquettes, estimation du ménage).
     */
    private function horsProtegees(string $colonneId): string
    {
        return $this->inclureProtegees ? 'TRUE' : FichesProtegees::conditionSql($colonneId);
    }

    /** @return list<stdClass> */
    private function lireLot(string $workspaceId, int $apresId, int $taille): array
    {
        $colonnes = [];
        foreach (self::GARDE as $alias => $expression) {
            $colonnes[] = "{$expression} AS {$alias}";
        }
        $lignes = DB::select(
            'SELECT c.id, c.naf_nomenclature, c.naf_rev2, ' . implode(', ', $colonnes) . ',
                    NOT ' . FichesProtegees::conditionSql('c.id') . ' AS protegee
             FROM companies c
             WHERE c.workspace_id = ? AND c.id > ? AND ' . $this->horsProtegees('c.id') . '
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
    private function traiterLot(string $workspaceId, array $fiches, bool $dryRun, bool $etiquettes, ?Closure $tracer = null): void
    {
        /** @var list<array{fiche: stdClass, nouveau: array<string, ?string>}> $aModifier */
        $aModifier = [];
        /** @var array<int, array{secteur: ?string, taille: ?string, region: ?string, naf_rev2: ?string}> $classementFinal */
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

            // B4 : un secteur valide choisi par l'import des fédérations reste.
            $secteurActuel = self::brut($f->sector_main);
            if (self::brut($f->origine_secteur) === CrmImportFederations::ORIGINE_SECTEUR
                && $secteurActuel !== null
                && $secteurActuel !== Taxonomy::SECTEUR_NON_CLASSE
                && array_key_exists($secteurActuel, Taxonomy::SECTEURS)
            ) {
                if ($calcul['sector_main'] !== $secteurActuel) {
                    $this->compteurs['secteurs_import_conserves']++;
                }
                $calcul['sector_main'] = $secteurActuel;
            }
            // Fiche protégée : la nature n'est jamais DEVINÉE — vide, elle
            // reste vide ; renseignée, elle ne bouge de toute façon jamais
            // (`Classement::nature`).
            if ((bool) $f->protegee && self::brut($f->entity_nature) === null && $calcul['entity_nature'] !== null) {
                $calcul['entity_nature'] = null;
                $this->compteurs['natures_protegees_non_devinees']++;
            }

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
            $this->compter('metier', Metiers::pourNafRev2(self::brut($f->naf_rev2)), Metiers::pourNafRev2($nouveau['naf_rev2']));

            if ($change) {
                $this->compteurs['fiches_a_modifier']++;
                $aModifier[] = ['fiche' => $f, 'nouveau' => $nouveau];
            }
            $classementFinal[(int) $f->id] = [
                'secteur' => $nouveau['sector_main'],
                'taille' => $nouveau['size_category'],
                'region' => $nouveau['region_code'],
                'naf_rev2' => $nouveau['naf_rev2'],
            ];
        }

        $plan = $etiquettes ? $this->planEtiquettes($classementFinal) : ['ajouts' => [], 'retraits' => []];
        $this->compteurs['etiquettes_a_ajouter'] += count($plan['ajouts']);
        $this->compteurs['etiquettes_a_retirer'] += count($plan['retraits']);

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($workspaceId, $aModifier, $plan, $tracer): void {
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
            // En DERNIER : le verrou de la chaîne d'audit n'est tenu que le
            // temps du COMMIT de ce lot.
            if ($tracer !== null) {
                $tracer();
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
               AND ' . $this->horsProtegees('c.id') . '
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
     * `sector-`, `size-`, `region-`, `metier-` reflètent la fiche.
     *
     * @param  array<int, array{secteur: ?string, taille: ?string, region: ?string, naf_rev2: ?string}>  $classements
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
            $desirees = EtiquettesClassement::desirees($c['secteur'], $c['taille'], $c['region'], $c['naf_rev2']);
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
     *
     * Ni une étiquette de l'IA (`kind = llm`) : son slug est libre, et un
     * « size-matters » proposé par le modèle n'est pas une étiquette de taille.
     * Seule une étiquette `kind = auto` appartient aux familles tenues ici.
     */
    private static function retirable(stdClass $lien): bool
    {
        if ((string) $lien->assigned_by === 'user' || (string) $lien->kind !== 'auto') {
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
                   AND " . $this->horsProtegees('ct.company_id'),
                $liaisons,
            );
        }

        return $n;
    }

    /**
     * Les ajouts passent par un `INSERT … SELECT` gardé : une fiche devenue
     * protégée entre la lecture du lot et cette écriture n'en reçoit aucun.
     *
     * @param  list<array{company_id: int, slug: string}>  $ajouts
     */
    private function ajouterEtiquettes(string $workspaceId, array $ajouts): int
    {
        $n = 0;
        $maintenant = now();
        foreach (array_chunk($ajouts, 1000) as $paquet) {
            $valeurs = [];
            $liaisons = [$workspaceId, $maintenant];
            foreach ($paquet as $a) {
                $valeurs[] = '(?::bigint, ?::bigint)';
                array_push($liaisons, $a['company_id'], $this->tagId($workspaceId, $a['slug']));
            }
            $n += DB::affectingStatement(
                "INSERT INTO company_tag (company_id, tag_id, workspace_id, assigned_at, assigned_by)
                 SELECT v.company_id, v.tag_id, ?::uuid, ?::timestamptz, 'auto-rule'
                 FROM (VALUES " . implode(', ', $valeurs) . ') AS v(company_id, tag_id)
                 WHERE ' . $this->horsProtegees('v.company_id') . '
                 ON CONFLICT DO NOTHING',
                $liaisons,
            );
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
                return EtiquettesClassement::desirees($cle, null, null, null)[$slug];
            }
        }
        foreach (array_keys(Taxonomy::TAILLES) as $cle) {
            if (EtiquettesClassement::slugTaille($cle) === $slug) {
                return EtiquettesClassement::desirees(null, $cle, null, null)[$slug];
            }
        }
        foreach (array_keys(Taxonomy::REGIONS) as $code) {
            if (EtiquettesClassement::slugRegion((string) $code) === $slug) {
                return EtiquettesClassement::desirees(null, null, (string) $code, null)[$slug];
            }
        }
        foreach (Metiers::table() as $code => $metier) {
            if (EtiquettesClassement::slugMetier($metier) === $slug) {
                return EtiquettesClassement::desirees(null, null, null, $code)[$slug];
            }
        }
        // Une valeur conservée hors référentiel (région étrangère…) : même
        // nommage que l'automate, libellé brut.
        foreach (['sector-' => 'sector', 'size-' => 'size', 'region-' => 'geo', 'metier-' => 'sector'] as $prefixe => $categorie) {
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
        foreach (array_keys(Metiers::liste()) as $cle) {
            $valides[] = EtiquettesClassement::slugMetier($cle);
        }

        return $valides;
    }

    /**
     * LE MÉNAGE DES ÉTIQUETTES, sous la garde B15-008 — deux ensembles :
     *
     *  1. OBSOLÈTES : les `sector-`/`size-`/`metier-` qui ne correspondent plus
     *     à AUCUNE valeur du référentiel (`sector-it-saas`, `size-micro`…),
     *     supprimées quand plus aucune fiche ne les porte ;
     *  2. ORPHELINES : la liste arrêtée avant les lots (`etiquettesOrphelines`).
     *
     * Jamais une verrouillée, une manuelle, une `src:`. À blanc : on CHIFFRE
     * — pour les obsolètes, celles qu'aucun lien intouchable (posé par un
     * utilisateur, ou sur une fiche protégée) ne retiendra — et ce que dirait
     * la garde B15-008.
     *
     * Hors essai à blanc, la suppression se fait par paquets de
     * `PAQUET_RANGEMENT`, chacun dans sa transaction courte avec
     * `lock_timeout`, et CHAQUE `DELETE` relit « aucun lien, ni fiche ni
     * candidat » : une étiquette posée entre-temps n'est pas supprimée.
     */
    private function etiquettesObsoletes(string $workspaceId, bool $dryRun): void
    {
        $candidates = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('kind', 'auto')
            ->where('is_locked', false)
            ->where(function (QueryBuilder $q): void {
                $q->where('slug', 'like', 'sector-%')
                    ->orWhere('slug', 'like', 'size-%')
                    ->orWhere('slug', 'like', EtiquettesClassement::PREFIXE_METIER . '%');
            })
            ->whereNotIn('slug', self::slugsValides());

        if ($dryRun) {
            $supprimables = (clone $candidates)->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')->from('company_tag as ct')->whereColumn('ct.tag_id', 'tags.id')
                    ->where(function (QueryBuilder $q): void {
                        $q->where('ct.assigned_by', 'user')
                            ->orWhereRaw('NOT (' . $this->horsProtegees('ct.company_id') . ')');
                    });
            });
        } else {
            $supprimables = (clone $candidates)->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')->from('company_tag')->whereColumn('company_tag.tag_id', 'tags.id');
            });
        }

        $obsoletes = array_values(array_map(static fn (mixed $id): int => (int) $id, $supprimables->pluck('tags.id')->all()));
        $orphelines = array_values(array_diff($this->orphelines, $obsoletes));
        $this->compteurs['etiquettes_obsoletes_a_supprimer'] = count($obsoletes);
        $this->compteurs['etiquettes_orphelines_a_supprimer'] = count($orphelines);
        $n = count($obsoletes) + count($orphelines);

        // Garde commune des commandes qui suppriment (B15-008) : un plafond de
        // proportion, qui refuse si ce « ménage » visait une grande part des
        // étiquettes — ce serait un détecteur qui se trompe, pas un ménage. Elle
        // porte sur les DEUX ensembles ensemble : c'est la même table.
        $total = (int) DB::table('tags')->where('workspace_id', $workspaceId)->count();
        $autorise = $n === 0 || $this->ecritureAutoriseeSansOperateur('tags', $n, $total, 'supprimer');
        if (! $autorise) {
            $this->compteurs['garde_b15008_refuserait'] = 1;
        }
        if ($dryRun || ! $autorise || $n === 0) {
            return;
        }

        $this->compteurs['etiquettes_obsoletes_supprimees'] = $this->supprimerEtiquettes($workspaceId, $obsoletes);
        $this->compteurs['etiquettes_orphelines_supprimees'] = $this->supprimerEtiquettes($workspaceId, $orphelines);
    }

    /**
     * Les étiquettes ORPHELINES de l'espace, arrêtées AVANT les lots :
     *
     *  - automatiques ou IA (`kind` `auto` ou `llm`) — jamais une manuelle ;
     *  - non verrouillées — jamais une étiquette du référentiel gouverné ;
     *  - SANS namespace (`tags.namespace`, colonne générée) — jamais une
     *    `src:` (même déverrouillée, cf. le stock d'avant `is_locked`), jamais
     *    une gouvernée (`famille:`, `secteur:`…) ;
     *  - hors référentiel du classement (`slugsValides`) ;
     *  - sans règle (`rules` vide) — une règle est une définition, pas un reste ;
     *  - portées par AUCUNE fiche et AUCUN candidat ;
     *  - citées par AUCUNE audience enregistrée (champ `tags`, même en
     *    corbeille : restaurer une audience ne doit pas la trouver vide).
     *
     * Supprimer une telle étiquette ne retire rien à personne : l'automate la
     * recrée, sous le même slug, le jour où une fiche y correspond (les
     * audiences visent les étiquettes par leur SLUG, pas par leur id).
     *
     * @return list<int>
     */
    private function etiquettesOrphelines(string $workspaceId): array
    {
        $citees = $this->slugsCitesParLesAudiences($workspaceId);

        $requete = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->whereIn('kind', ['auto', 'llm'])
            ->where('is_locked', false)
            ->whereNull('namespace')
            // Une étiquette du RÉFÉRENTIEL (`sector-btp`, `metier-coiffeurs`…)
            // reste, même inemployée : les lots peuvent la poser à l'instant,
            // et la supprimer pour la recréer n'apprendrait rien. Hors
            // référentiel, `sector-`/`size-`/`metier-` relèvent des OBSOLÈTES.
            ->whereNotIn('slug', self::slugsValides())
            ->where(function (QueryBuilder $q): void {
                $q->whereNull('rules')->orWhereRaw("rules IN ('[]'::jsonb, '{}'::jsonb)");
            })
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')->from('company_tag')->whereColumn('company_tag.tag_id', 'tags.id');
            })
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')->from('candidate_tag')->whereColumn('candidate_tag.tag_id', 'tags.id');
            });
        if ($citees !== []) {
            $requete->whereNotIn('slug', $citees);
        }
        $ids = $requete->orderBy('id')->pluck('id')->all();

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }

    /**
     * Tous les slugs qu'une audience de l'espace cite dans une condition
     * `tags`, quel que soit le bloc (`all`, `any`, `not`) — corbeille comprise.
     *
     * @return list<string>
     */
    private function slugsCitesParLesAudiences(string $workspaceId): array
    {
        $slugs = [];
        $criteres = DB::table('email_audiences')->where('workspace_id', $workspaceId)->pluck('criteria');
        foreach ($criteres as $brut) {
            $c = json_decode((string) $brut, true);
            if (! is_array($c)) {
                continue;
            }
            foreach (['all', 'any', 'not'] as $bloc) {
                foreach (is_array($c[$bloc] ?? null) ? $c[$bloc] : [] as $cond) {
                    if (! is_array($cond) || ($cond['field'] ?? null) !== 'tags') {
                        continue;
                    }
                    foreach (is_array($cond['value'] ?? null) ? $cond['value'] : [$cond['value'] ?? null] as $v) {
                        if (is_string($v) && $v !== '') {
                            $slugs[$v] = true;
                        }
                    }
                }
            }
        }

        return array_map('strval', array_keys($slugs));
    }

    /**
     * Supprime, par paquets bornés, celles de ces étiquettes qui ne sont
     * TOUJOURS portées par personne — et toujours pas verrouillées — au
     * moment d'écrire. Les identifiants viennent de listes déjà filtrées
     * (famille, `kind`, namespace) : ne sont relues ici que les conditions
     * qu'une autre écriture a pu changer ENTRE l'inventaire et la suppression.
     *
     * @param  list<int>  $ids
     */
    private function supprimerEtiquettes(string $workspaceId, array $ids): int
    {
        $n = 0;
        foreach (array_chunk($ids, self::PAQUET_RANGEMENT) as $paquet) {
            $n += (int) DB::transaction(function () use ($workspaceId, $paquet): int {
                DB::statement("SET LOCAL lock_timeout = '5s'");

                return DB::table('tags')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $paquet)
                    ->where('is_locked', false)
                    ->whereNotExists(function (QueryBuilder $sub): void {
                        $sub->selectRaw('1')->from('company_tag')->whereColumn('company_tag.tag_id', 'tags.id');
                    })
                    ->whereNotExists(function (QueryBuilder $sub): void {
                        $sub->selectRaw('1')->from('candidate_tag')->whereColumn('candidate_tag.tag_id', 'tags.id');
                    })
                    ->delete();
            });
        }

        return $n;
    }

    /**
     * Les étiquettes proposées par l'IA (`kind = llm`) encore rangées en
     * `intent` passent dans leur catégorie, `ia` (règle de nommage :
     * `FamillesEtiquettes`). Aucune n'est supprimée, aucun lien n'est touché,
     * le NOM ne change pas. Seules celles restées dans la catégorie par
     * défaut de l'ancien automate (`intent`) : une catégorie choisie à la main
     * est gardée. Par paquets bornés, sans toucher `updated_at`.
     */
    private function rangerEtiquettesIa(string $workspaceId, bool $dryRun): void
    {
        $ids = array_map(static fn (mixed $id): int => (int) $id, DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('kind', 'llm')
            ->where('is_locked', false)
            ->where('category', 'intent')
            ->orderBy('id')
            ->pluck('id')
            ->all());
        $this->compteurs['etiquettes_ia_a_ranger'] = count($ids);
        if ($dryRun) {
            return;
        }

        foreach (array_chunk($ids, self::PAQUET_RANGEMENT) as $paquet) {
            $this->compteurs['etiquettes_ia_rangees'] += (int) DB::transaction(function () use ($workspaceId, $paquet): int {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                DB::statement("SET LOCAL app.conserver_updated_at = 'on'");

                return DB::table('tags')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $paquet)
                    ->update(['category' => FamillesEtiquettes::CATEGORIE_IA]);
            });
        }
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
                                || ((str_starts_with($v, 'sector-') || str_starts_with($v, 'size-')
                                    || str_starts_with($v, EtiquettesClassement::PREFIXE_METIER))
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

    private function afficherBilan(bool $dryRun, bool $etiquettes, bool $discret = false): void
    {
        $this->newLine();
        $this->info($dryRun ? '═══ BILAN DE L\'ESSAI À BLANC (rien n\'a été écrit) ═══' : '═══ BILAN DU RECLASSEMENT ═══');

        $libelles = [
            'secteur' => Taxonomy::SECTEURS,
            'taille' => Taxonomy::TAILLES,
            'nature' => Taxonomy::ENTITY_NATURES,
            'region' => Taxonomy::REGIONS,
            'nomenclature' => [],
            'metier' => Metiers::liste(),
        ];
        $titres = [
            'secteur' => 'Secteur (sector_main)',
            'taille' => 'Taille (size_category)',
            'nature' => 'Nature (entity_nature)',
            'region' => 'Région (region_code)',
            'nomenclature' => 'Nomenclature du code NAF (naf_nomenclature)',
            'metier' => 'Métier (étiquette metier-, depuis naf_rev2 ; « (vide) » = pas de métier)',
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
            $masquees = ['avant' => 0, 'apres' => 0];
            foreach ($valeurs as $v) {
                $connu = $v === '(vide)' || array_key_exists($v, $libelles[$dimension])
                    || ($dimension === 'nomenclature' && in_array($v, Taxonomy::NAF_NOMENCLATURES, true));
                if (! $connu) {
                    $horsReferentiel += $apres[$v] ?? 0;
                }
                if (! $connu && $discret) {
                    // Journaux publics : une valeur brute hors référentiel
                    // n'est jamais affichée, seulement comptée.
                    $masquees['avant'] += $avant[$v] ?? 0;
                    $masquees['apres'] += $apres[$v] ?? 0;

                    continue;
                }
                $lignes[] = [
                    $v,
                    $libelles[$dimension][$v] ?? ($connu ? '' : '⚠ hors référentiel'),
                    $avant[$v] ?? 0,
                    $apres[$v] ?? 0,
                    sprintf('%+d', ($apres[$v] ?? 0) - ($avant[$v] ?? 0)),
                ];
            }
            if ($masquees['avant'] + $masquees['apres'] > 0) {
                $lignes[] = [
                    '(hors référentiel)',
                    '',
                    $masquees['avant'],
                    $masquees['apres'],
                    sprintf('%+d', $masquees['apres'] - $masquees['avant']),
                ];
            }
            $this->newLine();
            $this->line("<comment>{$titre}</comment>");
            $this->table(['valeur', 'libellé', 'avant', 'après', 'écart'], $lignes);
            $this->line("  valeurs hors référentiel restantes après : {$horsReferentiel}");
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
                $compteurs['etiquettes_obsoletes_supprimees'], $compteurs['etiquettes_orphelines_supprimees'],
                $compteurs['etiquettes_ia_rangees']);
        }
        if (! $etiquettes) {
            unset($compteurs['etiquettes_a_ajouter'], $compteurs['etiquettes_a_retirer'],
                $compteurs['etiquettes_ajoutees'], $compteurs['etiquettes_retirees'],
                $compteurs['etiquettes_obsoletes_a_supprimer'], $compteurs['etiquettes_obsoletes_supprimees'],
                $compteurs['etiquettes_orphelines_a_supprimer'], $compteurs['etiquettes_orphelines_supprimees'],
                $compteurs['etiquettes_ia_a_ranger'], $compteurs['etiquettes_ia_rangees'],
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

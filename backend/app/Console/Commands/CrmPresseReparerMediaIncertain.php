<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use App\Crm\Campagnes\GardePresse;
use App\Crm\FichesProtegees;
use App\Crm\Presse\MediaIncertain;
use App\Crm\Presse\QualificationPresse;
use App\Services\Audit\AuditHashChain;
use App\Support\AuditLogger;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * RÉPARER LES FICHES BASCULÉES À TORT EN PRESSE PAR LEUR SEUL CODE NAF
 * (constat en production du 2026-09-30).
 *
 * Le premier passage réel de `crm:presse:harmoniser` a passé en
 * `presse_media` / `media` des fiches dont la seule « preuve » de média était
 * une ligne `media` déduite du code NAF (`naf-extract`) 63.12Z ou 58.19Z : des
 * sociétés web et informatiques, de vrais prospects. L'harmonisation ne le
 * fait plus (`MediaIncertain`) ; cette commande rend à celles qui l'ont été
 * leur nature et leur relation d'AVANT.
 *
 * ── Quelles fiches ──────────────────────────────────────────────────────
 * Les fiches vivantes qui sont des MÉDIAS INCERTAINS (`MediaIncertain` : NAF
 * 63.12 / 58.19, toutes leurs lignes `media` venues de `naf-extract` — une
 * fiche qui porte une ligne CPPAP, SPEL, agence, kit presse, liste presse…
 * n'est JAMAIS lue) ET qui ont été touchées par l'harmonisation : l'état
 * d'avant est gardé dans `metadata.harmonisation_presse`, ou, à défaut, la
 * fiche porte `presse_media` / `media` et le tag de provenance presse.
 *
 * ── L'état d'AVANT, et seulement lui ────────────────────────────────────
 * `QualificationPresse::qualifier` garde, à la première bascule, la nature et
 * la relation d'avant dans `companies.metadata.harmonisation_presse`
 * (`nature_avant`, `relation_avant`), jamais réécrites ensuite. C'est la
 * source retenue : elle est posée DANS la même écriture que la bascule.
 *
 *  - RELATION : touchée seulement si elle vaut encore `presse_media` et n'a
 *    pas été saisie à la main (`relation_saisie_manuelle_at`) — sinon quelqu'un
 *    l'a posée depuis, on n'y touche pas (`relations_saisies_a_la_main`,
 *    `relations_changees_depuis`). `relation_avant` connue : elle est remise
 *    telle quelle. Inconnue (pas de métadonnée) : `prospect`,
 *    SEULEMENT si aucune fusion n'implique la fiche ; sinon comptée, intacte
 *    (`relations_avant_inconnues`).
 *  - NATURE : touchée seulement si elle vaut encore `media`. `nature_avant`
 *    connue et différente de `media` : remise. Sinon l'état d'avant n'est pas
 *    certain (le funnel pose `media` sur une nature VIDE avant que
 *    `qualifier` ne la lise : `nature_avant = media` ne distingue pas une
 *    nature vide d'une nature posée à la main) : `entreprise` — la valeur de
 *    toute fiche Sirene — SEULEMENT si la fiche a un SIREN, aucune fusion et
 *    aucune relation saisie à la main ; sinon comptée, intacte
 *    (`natures_avant_inconnues`).
 *  - ÉTIQUETTES : la synchro automatique ordinaire retire `nature-media` et
 *    pose `media-possible:a-verifier` (chantier F).
 *  - PROVENANCE : par défaut, le tag `src:scraping-presse-2026` n'est PAS
 *    retiré — lever une protection est une décision de Will
 *    (`FichesProtegees`), jamais un effet de bord ; il est compté
 *    (`provenance_presse_gardee`). Sous l'option EXPLICITE
 *    `--lever-provenance-posee-par-harmonisation` (décision de Will), il est
 *    retiré d'une fiche RÉPARÉE seulement s'il est PROUVÉ que l'harmonisation
 *    l'y a posé (`poseeParHarmonisation`) ; sinon gardé et compté. La levée
 *    passe par `RefuseUneSuppressionMassive` (confirmation ou `--force`) et est
 *    journalisée (`metadata.reparation_media_incertain.provenance_levee`,
 *    `business_events` `company.presse_provenance_levee`, sceau d'audit).
 *
 * Rien d'autre n'est supprimé : ni fiche, ni contact, ni ligne `media`, ni
 * journaliste, ni aucune autre étiquette.
 *
 * ── Journal ─────────────────────────────────────────────────────────────
 * Chaque fiche réparée reçoit `metadata.reparation_media_incertain` (date,
 * valeurs remises, valeurs trouvées — posé une fois) et une ligne
 * `business_events` (`company.presse_media_incertain_reparee`, identifiant et
 * valeurs, jamais de nom). Le passage réel est scellé dans la chaîne d'audit
 * (`AuditHashChain`, empreinte du bilan).
 *
 * ── Essai à blanc PAR DÉFAUT ────────────────────────────────────────────
 * Sans `--appliquer`, tout est parcouru par le MÊME chemin, paquet par
 * paquet, et chaque paquet est ANNULÉ : les compteurs sont ceux du réel.
 * `--dry-run` le dit explicitement (incompatible avec `--appliquer`).
 * Par paquets (`--paquet`, 500 par défaut : serveur 2 CPU), reprenable
 * (`--depuis-id`), IDEMPOTENTE : une fiche réparée ne porte plus `presse_media`
 * ni `media` — la repasser n'écrit rien (`deja_reparees`).
 */
class CrmPresseReparerMediaIncertain extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'crm:presse:reparer-media-incertain
                            {--appliquer : Écrire (sans cette option : essai à blanc, rien n\'est écrit)}
                            {--dry-run : Essai à blanc explicite (c\'est déjà le défaut)}
                            {--depuis-id= : Reprendre à la fiche d\'identifiant N (incluse)}
                            {--limite= : Ne traiter que les N premières fiches}
                            {--paquet=500 : Fiches validées par transaction}
                            {--lever-provenance-posee-par-harmonisation : Retirer le tag src:scraping-presse-2026 d\'une fiche réparée, SEULEMENT s\'il est prouvé que l\'harmonisation l\'a posé}
                            {--force : Avec --appliquer et la levée, ne pas demander de confirmation (automatisation assumée)}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}';

    protected $description = 'Rend leur nature et leur relation d\'avant aux fiches basculées en presse par leur seul code NAF (63.12Z / 58.19Z).';

    public const EVENEMENT = 'company.presse_media_incertain_reparee';

    public const CLE_JOURNAL = 'reparation_media_incertain';

    public const EVENEMENT_LEVEE = 'company.presse_provenance_levee';

    private bool $leverProvenance = false;

    /** @var array<string, int> */
    private array $bilan = [];

    private string $workspaceId = '';

    public function handle(AuditHashChain $audit): int
    {
        $discret = (bool) $this->option('compteurs-seulement');
        $appliquer = (bool) $this->option('appliquer');
        if ($appliquer && (bool) $this->option('dry-run')) {
            $this->error('--appliquer et --dry-run sont incompatibles.');

            return self::FAILURE;
        }

        $limite = $this->entierOption('limite');
        $depuis = $this->entierOption('depuis-id');
        $paquet = $this->entierOption('paquet');
        if ($limite === false || $depuis === false || $paquet === false || $paquet === null) {
            $this->error('--limite, --depuis-id et --paquet doivent être des entiers positifs.');

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error($discret ? 'Espace business introuvable.' : "Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $this->workspaceId = (string) $workspaceId;

        $this->bilan = array_fill_keys([
            'fiches_lues', 'paquets', 'fiches_reparees', 'deja_reparees',
            'relations_remises', 'relations_prospect_par_defaut', 'relations_saisies_a_la_main',
            'relations_changees_depuis', 'relations_avant_inconnues',
            'natures_remises', 'natures_entreprise_par_defaut', 'natures_changees_depuis', 'natures_avant_inconnues',
            'provenance_presse_gardee', 'provenance_presse_levee',
        ], 0);

        // La levée RETIRE un lien fiche-étiquette (`company_tag`) : même barrière
        // que toute commande qui supprime (`RefuseUneSuppressionMassive`) —
        // confirmation, ou `--force`, et plafond de proportion. À blanc, rien
        // n'est retiré (le paquet est annulé) : pas de barrière.
        $this->leverProvenance = (bool) $this->option('lever-provenance-posee-par-harmonisation');
        if ($this->leverProvenance && $appliquer) {
            $auPlus = (int) DB::table('companies as c')
                ->where('c.workspace_id', $this->workspaceId)->whereNull('c.deleted_at')
                ->whereRaw(MediaIncertain::conditionSql('c.id', 'c'))
                ->whereExists(static fn ($q) => $q->selectRaw('1')->from('company_tag as ct')->join('tags as t', 't.id', '=', 'ct.tag_id')
                    ->whereColumn('ct.company_id', 'c.id')->where('t.slug', FichesProtegees::TAG_PRESSE))
                ->count();
            // Le plafond se mesure sur l'ESPACE traité, pas sur les liens de
            // tous les espaces (relecture A09 de #268).
            $total = (int) DB::table('company_tag')->where('workspace_id', $this->workspaceId)->count();
            if ($auPlus > 0 && ! $this->suppressionAutorisee('company_tag (tag ' . FichesProtegees::TAG_PRESSE . ', au plus)', $auPlus, $total)) {
                return self::FAILURE;
            }
        }

        $dernier = null;
        $interruption = null;
        try {
            WorkspaceContext::run($this->workspaceId, function () use ($depuis, $limite, $paquet, $appliquer, &$dernier): void {
                $curseur = ($depuis ?? 1) - 1;
                while (true) {
                    $restant = $limite === null ? $paquet : min($paquet, $limite - $this->bilan['fiches_lues']);
                    if ($restant <= 0) {
                        break;
                    }
                    $ids = $this->candidates($curseur, $restant);
                    if ($ids === []) {
                        break;
                    }
                    $this->traiterPaquet($ids, $appliquer);
                    $curseur = max($ids);
                    $dernier = $curseur;
                }
            });
        } catch (Throwable $e) {
            $interruption = $e;
        }

        if ($appliquer && $this->bilan['paquets'] > 0) {
            $audit->record([
                'workspace_id' => $this->workspaceId,
                'user_id' => null,
                'method' => 'REPARER_PRESSE_MEDIA_INCERTAIN',
                'path' => 'artisan crm:presse:reparer-media-incertain',
                'status' => 200,
                'ip' => null,
                'user_agent' => null,
                'payload_hash' => hash('sha256', json_encode($this->bilan, JSON_THROW_ON_ERROR)),
            ]);
        }

        if ($interruption !== null) {
            $reprise = $dernier === null || $discret ? '' : ' Reprendre avec --depuis-id=' . ($dernier + 1) . '.';
            $this->error("INTERROMPU après {$this->bilan['paquets']} paquet(s)"
                . ($appliquer ? ' validé(s) : ils restent en base (idempotente).' . $reprise : ' (à blanc : rien n\'a été écrit).'));
            if ($discret) {
                Log::error('crm:presse:reparer-media-incertain interrompu', ['exception' => $interruption]);

                throw new RuntimeException('crm:presse:reparer-media-incertain interrompu (détail masqué : --compteurs-seulement, voir le journal du serveur).');
            }

            throw $interruption;
        }

        $this->info($appliquer ? 'Réparation appliquée.' : '[À BLANC] rien n\'a été écrit (--appliquer pour écrire).');
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($this->bilan),
            array_values($this->bilan),
        ));
        if (! $discret && $dernier !== null) {
            $this->line("Dernière fiche traitée : {$dernier} (reprendre avec --depuis-id=" . ($dernier + 1) . ').');
        }

        return self::SUCCESS;
    }

    /**
     * Les fiches suivantes à examiner : médias incertains touchés par
     * l'harmonisation (métadonnée d'avant, ou presse + provenance presse).
     *
     * @return list<int>
     */
    private function candidates(int $curseur, int $combien): array
    {
        $lignes = DB::select(
            'SELECT c.id
               FROM companies c
              WHERE c.workspace_id = ?
                AND c.deleted_at IS NULL
                AND c.id > ?
                AND (
                      (c.metadata -> ?) IS NOT NULL
                   OR ((c.relation_type = ? OR c.entity_nature = ?)
                       AND EXISTS (SELECT 1 FROM company_tag ct JOIN tags t ON t.id = ct.tag_id
                                    WHERE ct.company_id = c.id AND t.slug = ?))
                )
                AND ' . MediaIncertain::conditionSql('c.id', 'c') . '
              ORDER BY c.id
              LIMIT ?',
            [
                $this->workspaceId, $curseur, QualificationPresse::CLE_AVANT,
                QualificationPresse::RELATION, QualificationPresse::NATURE, FichesProtegees::TAG_PRESSE,
                $combien,
            ],
        );

        return array_values(array_map(static fn (\stdClass $l): int => (int) $l->id, $lignes));
    }

    /** @param  list<int>  $ids */
    private function traiterPaquet(array $ids, bool $appliquer): void
    {
        $delta = [];
        DB::beginTransaction();
        try {
            // Rendre l'état d'avant n'est pas une modification de la fiche : le
            // tri « récent » du hub n'en est pas brouillé (comme l'harmonisation).
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
            foreach ($ids as $id) {
                $this->compter($delta, 'fiches_lues');
                $this->reparer($id, $delta);
            }
            if ($appliquer) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        foreach ($delta as $cle => $n) {
            $this->bilan[$cle] = ($this->bilan[$cle] ?? 0) + $n;
        }
        $this->bilan['paquets']++;
    }

    /** @param  array<string, int>  $delta */
    private function reparer(int $id, array &$delta): void
    {
        $f = DB::table('companies')->where('workspace_id', $this->workspaceId)->where('id', $id)
            ->whereNull('deleted_at')->lockForUpdate()
            ->first(['id', 'siren', 'entity_nature', 'relation_type', 'relation_saisie_manuelle_at', 'metadata']);
        // Relue sous verrou : la règle doit toujours tenir (une ligne CPPAP a
        // pu être rattachée entre-temps).
        if ($f === null || ! MediaIncertain::fiche($id)) {
            return;
        }

        $meta = json_decode(is_string($f->metadata) ? $f->metadata : '{}', true);
        $meta = is_array($meta) ? $meta : [];
        $avant = is_array($meta[QualificationPresse::CLE_AVANT] ?? null) ? $meta[QualificationPresse::CLE_AVANT] : null;
        $manuelle = $f->relation_saisie_manuelle_at !== null;
        // Une fusion EN COURS (non annulée) implique la fiche, gardée ou absorbée.
        $fusion = DB::table('fusions_fiches')->where('workspace_id', $this->workspaceId)->whereNull('annulee_at')
            ->where(static fn ($q) => $q->where('garde_id', $id)->orWhere('absorbee_id', $id))->exists();

        $maj = [];

        // ── Relation ────────────────────────────────────────────────────
        if ($f->relation_type === QualificationPresse::RELATION) {
            if ($manuelle) {
                $this->compter($delta, 'relations_saisies_a_la_main');
            } elseif ($avant !== null && is_string($avant['relation_avant'] ?? null) && $avant['relation_avant'] !== QualificationPresse::RELATION) {
                // `relation_type` est NOT NULL : une relation d'avant absente ou
                // vide n'est pas un état connu.
                $maj['relation_type'] = $avant['relation_avant'];
                $this->compter($delta, 'relations_remises');
            } elseif (! $fusion) {
                $maj['relation_type'] = 'prospect';
                $this->compter($delta, 'relations_prospect_par_defaut');
            } else {
                $this->compter($delta, 'relations_avant_inconnues');
            }
        } elseif ($avant !== null && ($avant['relation_avant'] ?? null) !== $f->relation_type && ! isset($meta[self::CLE_JOURNAL])) {
            // Plus `presse_media`, et pas remise par nous : changée depuis.
            $this->compter($delta, 'relations_changees_depuis');
        }

        // ── Nature ──────────────────────────────────────────────────────
        if ($f->entity_nature === QualificationPresse::NATURE) {
            $natureAvant = $avant !== null && array_key_exists('nature_avant', $avant) ? $avant['nature_avant'] : QualificationPresse::NATURE;
            $siren = is_string($f->siren) && preg_match('/^\d{9}$/', trim($f->siren)) === 1;
            if ($natureAvant !== QualificationPresse::NATURE) {
                $maj['entity_nature'] = is_string($natureAvant) ? $natureAvant : null;
                $this->compter($delta, 'natures_remises');
            } elseif ($siren && ! $fusion && ! $manuelle) {
                $maj['entity_nature'] = 'entreprise';
                $this->compter($delta, 'natures_entreprise_par_defaut');
            } else {
                $this->compter($delta, 'natures_avant_inconnues');
            }
        } elseif ($avant !== null && ($avant['nature_avant'] ?? null) !== $f->entity_nature && ! isset($meta[self::CLE_JOURNAL])) {
            $this->compter($delta, 'natures_changees_depuis');
        }

        if ($maj === []) {
            if (isset($meta[self::CLE_JOURNAL])) {
                $this->compter($delta, 'deja_reparees');
                $this->provenance($id, $delta);
            }
            // L'étiquette « média possible » suit la règle, réparée ou non.
            QualificationPresse::etiqueter($id);

            return;
        }

        if (! isset($meta[self::CLE_JOURNAL])) {
            $meta[self::CLE_JOURNAL] = [
                'le' => now()->toDateString(),
                'nature_trouvee' => $f->entity_nature,
                'relation_trouvee' => $f->relation_type,
                'nature_remise' => $maj['entity_nature'] ?? $f->entity_nature,
                'relation_remise' => array_key_exists('relation_type', $maj) ? $maj['relation_type'] : $f->relation_type,
            ];
            $maj['metadata'] = json_encode($meta, JSON_THROW_ON_ERROR);
        }
        DB::table('companies')->where('id', $id)->update($maj + ['updated_at' => now()]);
        $this->compter($delta, 'fiches_reparees');

        $this->provenance($id, $delta);
        QualificationPresse::etiqueter($id);

        AuditLogger::log(self::EVENEMENT, [
            'workspace_id' => $this->workspaceId,
            'resource_type' => 'company',
            'resource_id' => (string) $id,
            'actor_user_id' => null,
            'nature_trouvee' => $f->entity_nature,
            'relation_trouvee' => $f->relation_type,
            'nature_remise' => $maj['entity_nature'] ?? $f->entity_nature,
            'relation_remise' => array_key_exists('relation_type', $maj) ? $maj['relation_type'] : $f->relation_type,
            'etat_avant_connu' => $avant !== null,
        ]);
    }

    /**
     * Le tag de provenance presse d'une fiche RÉPARÉE : levé seulement sous
     * `--lever-provenance-posee-par-harmonisation` ET avec la preuve que
     * l'harmonisation elle-même l'a posé ; sinon gardé et compté.
     *
     * @param  array<string, int>  $delta
     */
    private function provenance(int $id, array &$delta): void
    {
        $lien = DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('company_tag.company_id', $id)->where('tags.slug', FichesProtegees::TAG_PRESSE)
            ->first(['company_tag.tag_id', 'company_tag.assigned_at', 'company_tag.assigned_by']);
        if ($lien === null) {
            return;
        }
        if (! $this->leverProvenance || ! $this->poseeParHarmonisation($id, $lien)) {
            $this->compter($delta, 'provenance_presse_gardee');

            return;
        }

        DB::table('company_tag')->where('company_id', $id)->where('tag_id', (int) $lien->tag_id)->delete();

        $meta = json_decode((string) (DB::table('companies')->where('id', $id)->whereNull('deleted_at')->value('metadata') ?? '{}'), true);
        $meta = is_array($meta) ? $meta : [];
        $journal = is_array($meta[self::CLE_JOURNAL] ?? null) ? $meta[self::CLE_JOURNAL] : [];
        $journal['provenance_levee'] = [
            'le' => now()->toDateString(),
            'tag' => FichesProtegees::TAG_PRESSE,
            'pose_le' => (string) $lien->assigned_at,
        ];
        $meta[self::CLE_JOURNAL] = $journal;
        DB::table('companies')->where('id', $id)->update(['metadata' => json_encode($meta, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

        AuditLogger::log(self::EVENEMENT_LEVEE, [
            'workspace_id' => $this->workspaceId,
            'resource_type' => 'company',
            'resource_id' => (string) $id,
            'actor_user_id' => null,
            'tag' => FichesProtegees::TAG_PRESSE,
            'pose_le' => (string) $lien->assigned_at,
        ]);
        $this->compter($delta, 'provenance_presse_levee');
    }

    /**
     * La PREUVE que c'est l'harmonisation qui a posé le tag sur cette fiche,
     * et rien d'autre — toutes ces conditions à la fois :
     *  - l'harmonisation a basculé la fiche (`metadata.harmonisation_presse`
     *    présent : la fiche n'était pas de la presse avant) et la relation
     *    n'est plus `presse_media` (réparée) ;
     *  - le tag a été posé par l'automate (`assigned_by = auto-rule`) ;
     *  - la fiche a des passages de la porte commune pour `presse-2026`, et
     *    TOUS sont ceux de l'harmonisation (`presse-2026:media:<id>:…`) — aucun
     *    d'une liste presse importée (`presse-2026:liste:…`) — et chacun désigne
     *    une ligne `media` `naf-extract` de CETTE fiche ;
     *  - le tag a été posé au même moment que le premier de ces passages
     *    (± 5 minutes : même transaction de la porte commune) ;
     *  - aucun contact de la presse sur la fiche
     *    (`GardePresse::estContactPresseSql`, corbeille comprise) : ces
     *    personnes relèvent du régime de la presse.
     * Un passage purgé (`PruneScraperRuns`) fait tomber la preuve : on garde.
     */
    private function poseeParHarmonisation(int $id, \stdClass $lien): bool
    {
        $fiche = DB::table('companies')->where('id', $id)->whereNull('deleted_at')->first(['metadata', 'relation_type']);
        $meta = $fiche === null ? null : json_decode((string) $fiche->metadata, true);
        if (! is_array($meta) || ! is_array($meta[QualificationPresse::CLE_AVANT] ?? null) || $fiche->relation_type === QualificationPresse::RELATION) {
            return false;
        }
        if ($lien->assigned_by !== 'auto-rule' || $lien->assigned_at === null) {
            return false;
        }

        $passages = DB::table('scraper_runs')->where('workspace_id', $this->workspaceId)
            ->where('company_id', $id)->where('source', QualificationPresse::SOURCE)
            ->orderBy('finished_at')->get(['dedup_key', 'finished_at']);
        if ($passages->isEmpty()) {
            return false;
        }
        $prefixe = 'pivot:' . QualificationPresse::SOURCE . ':' . QualificationPresse::SOURCE . ':media:';
        foreach ($passages as $p) {
            if (preg_match('/^' . preg_quote($prefixe, '/') . '(\d+):/', (string) $p->dedup_key, $m) !== 1) {
                return false;
            }
            $ligneNaf = DB::table('media')->where('workspace_id', $this->workspaceId)->where('id', (int) $m[1])
                ->where('company_id', $id)->where('source', MediaIncertain::SOURCE_NAF)->whereNull('deleted_at')->exists();
            if (! $ligneNaf) {
                return false;
            }
        }

        $ecart = abs(strtotime((string) $lien->assigned_at) - strtotime((string) $passages->first()->finished_at));
        if ($ecart > 300) {
            return false;
        }

        // Définition UNIQUE du contact de presse (`GardePresse`), corbeille comprise.
        // Corbeille COMPRISE, voulu : un journaliste retiré reste la preuve que
        // la fiche a porté des personnes de la presse.
        $contactPresse = DB::selectOne(
            'SELECT EXISTS (SELECT 1 FROM contacts WHERE contacts.company_id = ? AND '
            . GardePresse::estContactPresseSql('contacts') . ') AS e',
            [$id],
        );

        return ! (bool) ($contactPresse->e ?? true);
    }

    /** @param  array<string, int>  $delta */
    private function compter(array &$delta, string $cle, int $n = 1): void
    {
        $delta[$cle] = ($delta[$cle] ?? 0) + $n;
    }

    /** null : option absente ; false : option invalide. */
    private function entierOption(string $nom): int|false|null
    {
        $v = $this->option($nom);
        if ($v === null) {
            return null;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 1) {
            return false;
        }

        return (int) $v;
    }
}

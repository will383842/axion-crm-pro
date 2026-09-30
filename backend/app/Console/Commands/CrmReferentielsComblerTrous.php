<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\FichesProtegees;
use App\Crm\Referentiels\Classement;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * COMBLER LES TROUS DU CLASSEMENT — sans rien inventer (chantier C, 2026-10-01).
 *
 * Mesuré en production le 30/09 : 100 fiches sans `entity_nature` (92 GOFAB,
 * 5 implantations à l'étranger, 3 campagnes), 641 sans `region_code`.
 *
 * Ce que la commande ÉCRIT (et rien d'autre) :
 *
 *  - `entity_nature`, quand elle est vide, par `Classement::natureDeduite()` :
 *    catégorie juridique, puis code NAF d'organisation, puis SIREN sans rien
 *    qui le contredise. Sinon la fiche reste sans nature, et elle est comptée ;
 *  - `region_code`, quand elle est vide, pour une fiche FRANÇAISE seulement,
 *    par `Classement::regionFrancaise()` : le département, sinon le code
 *    postal. Sinon la fiche reste sans région, et elle est comptée.
 *
 * Ce qu'elle COMPTE sans rien écrire :
 *
 *  - les fiches ÉTRANGÈRES (`country_code` ≠ FR) sans région : `region_code`
 *    est un code INSEE de région française, NULL y est la vérité. « Étranger »
 *    se cible par le pays (`country_code`, champ d'audience) ;
 *  - les fiches sans TAILLE, en deux groupes : entreprises (effectif inconnu)
 *    et organisations (la taille d'entreprise ne s'y applique pas). Aucun
 *    effectif n'est deviné : les deux se ciblent ou s'excluent par
 *    « taille non renseignée » (`size_category` `is_null`) croisée avec la
 *    nature.
 *
 * Les étiquettes `region-…` des fiches complétées sont posées au prochain
 * passage de `crm:referentiels:reclasser` (ou de l'enrichissement) : cette
 * commande n'écrit que deux colonnes.
 *
 * Mêmes garanties que le reclassement : par lots, transaction courte et
 * `lock_timeout` 5 s, curseur reprenable, `UPDATE` gardé (une fiche modifiée
 * entre-temps n'est pas écrasée), `updated_at` conservé
 * (`app.conserver_updated_at`), audit par lot et de fin, RLS. Les fiches
 * PROTÉGÉES sont exclues sauf `--inclure-protegees` ; même alors, la nature
 * d'un organisateur ou d'une fédération n'est jamais déduite
 * (`FichesProtegees::TAGS_NATURE_NON_DEVINEE`). Rien n'est supprimé.
 */
class CrmReferentielsComblerTrous extends Command
{
    protected $signature = 'crm:referentiels:combler-trous
                            {--dry-run : Tout lire et calculer, ne RIEN écrire}
                            {--workspace= : Identifiant ou slug de l\'espace (défaut : celui de prospection:collect)}
                            {--lot=2000 : Fiches par lot (1 à 3000)}
                            {--depuis-id=0 : Reprendre APRÈS cette fiche}
                            {--max-lots=0 : S\'arrêter après N lots (0 = jusqu\'au bout)}
                            {--pause-ms=0 : Pause entre deux lots}
                            {--inclure-protegees : Traiter AUSSI les fiches protégées (nature et région seulement)}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics)}';

    protected $description = 'Complète la nature et la région des fiches qui n\'en ont pas, sans rien deviner ; compte les étrangères et les tailles inconnues.';

    private const LOT_MAX = 3000;

    /** Colonnes LUES et comparées dans l'`UPDATE` (garde de concurrence). */
    private const GARDE = [
        'entity_nature', 'region_code', 'department_code', 'postcode', 'country_code',
        'legal_form', 'naf', 'naf_rev2', 'siren',
    ];

    /** @var array<string, int> */
    private array $compteurs = [];

    private bool $inclureProtegees = false;

    public function handle(AuditHashChain $audit): int
    {
        $designation = is_string($this->option('workspace')) ? $this->option('workspace') : null;
        $workspaceId = EspaceProspection::resoudre($designation);
        if ($workspaceId === null) {
            $this->error('Espace introuvable : « ' . ($designation ?? '(défaut)') . ' ».');

            return self::FAILURE;
        }
        $lot = (int) $this->option('lot');
        if ($lot < 1 || $lot > self::LOT_MAX) {
            $this->error('--lot : entre 1 et ' . self::LOT_MAX . '.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $discret = (bool) $this->option('compteurs-seulement');
        $maxLots = max(0, (int) $this->option('max-lots'));
        $pauseMs = max(0, (int) $this->option('pause-ms'));
        $dernier = max(0, (int) $this->option('depuis-id'));
        $this->inclureProtegees = (bool) $this->option('inclure-protegees');
        $operateur = self::operateur();
        $this->compteurs = array_fill_keys([
            'lots', 'fiches_lues', 'fiches_a_modifier', 'fiches_modifiees', 'modifiees_entre_temps',
            'natures_deduites_forme_juridique', 'natures_deduites_naf', 'natures_deduites_siren',
            'natures_laissees_vides', 'natures_protegees_non_deduites',
            'regions_par_departement', 'regions_par_code_postal', 'regions_fr_sans_donnee_geographique',
        ], 0);

        $this->info(sprintf(
            '%s — espace %s, lots de %d, à partir de l\'id %d%s.',
            $dryRun ? '[À BLANC] rien ne sera écrit' : 'Comblement des trous',
            $discret ? '(masqué)' : $workspaceId,
            $lot,
            $dernier,
            $this->inclureProtegees ? ', fiches PROTÉGÉES comprises' : '',
        ));

        $termine = false;
        $erreur = null;
        $etat = [];
        try {
            WorkspaceContext::run($workspaceId, function () use ($workspaceId, $lot, $maxLots, $pauseMs, $dryRun, $audit, $operateur, &$dernier, &$termine, &$erreur, &$etat): void {
                $etat = $this->etatDeLaBase($workspaceId);
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
                        $plan = $this->planifier($fiches);
                        if (! $dryRun && $plan !== []) {
                            DB::transaction(function () use ($workspaceId, $plan, $audit, $operateur, $bas, $haut, $avant): void {
                                DB::statement("SET LOCAL lock_timeout = '5s'");
                                DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
                                $ecrites = $this->ecrire($workspaceId, $plan);
                                $this->compteurs['fiches_modifiees'] += $ecrites;
                                $this->compteurs['modifiees_entre_temps'] += count($plan) - $ecrites;
                                $this->auditer($audit, $workspaceId, $operateur, 'COMBLER_TROUS_LOT', 200, [
                                    'ids' => [$bas, $haut],
                                    'fiches_modifiees' => $this->compteurs['fiches_modifiees'] - $avant['fiches_modifiees'],
                                ], "ids {$bas}-{$haut}");
                            });
                        }
                    } catch (Throwable $e) {
                        $erreur = $e instanceof QueryException ? 'SQLSTATE ' . $e->getCode() : get_class($e);
                        Log::error('crm:referentiels:combler-trous : lot annulé', ['apres_id' => $dernier, 'erreur' => $erreur]);
                        $this->compteurs = $avant;

                        return;
                    }
                    $lots++;
                    $this->compteurs['lots']++;
                    $dernier = $haut;
                    $this->line(sprintf('  lot %d : ids %d à %d — %d à modifier', $lots, $bas, $haut, $this->compteurs['fiches_a_modifier']));
                    if ($maxLots > 0 && $lots >= $maxLots) {
                        return;
                    }
                    if ($pauseMs > 0) {
                        usleep($pauseMs * 1000);
                    }
                }
            });
        } finally {
            if (! $dryRun) {
                try {
                    $this->auditer($audit, $workspaceId, $operateur, 'COMBLER_TROUS_FIN', $erreur === null ? 200 : 500, [
                        'termine' => $termine, 'dernier_id' => $dernier, 'erreur' => $erreur, 'compteurs' => $this->compteurs,
                        'inclure_protegees' => $this->inclureProtegees,
                    ], $termine ? 'terminé' : "arrêté après l'id {$dernier}");
                } catch (Throwable $e) {
                    Log::error('crm:referentiels:combler-trous : entrée d\'audit de fin NON écrite', ['erreur' => get_class($e)]);
                    $erreur ??= 'audit de fin : ' . get_class($e);
                }
            }
        }

        $this->afficherBilan($dryRun, $etat);
        if ($erreur !== null) {
            $this->error("ÉCHEC ({$erreur}). Le lot en cours est annulé ; tout ce qui précède est acquis et journalisé. Reprendre avec : --depuis-id={$dernier}");

            return self::FAILURE;
        }
        if (! $termine) {
            $this->warn("Arrêt demandé. Reprendre avec : --depuis-id={$dernier}");
        } else {
            $this->info($dryRun ? '[À BLANC] terminé : rien n\'a été écrit.' : 'Comblement terminé.');
        }

        return self::SUCCESS;
    }

    /**
     * Le constat, AVANT toute écriture : ce que la commande ne comble pas et
     * qu'elle doit dire (étrangères, tailles inconnues).
     *
     * @return array<string, int>
     */
    private function etatDeLaBase(string $workspaceId): array
    {
        $ligne = DB::selectOne(
            "SELECT
                count(*) FILTER (WHERE c.entity_nature IS NULL) AS sans_nature,
                count(*) FILTER (WHERE c.region_code IS NULL AND c.country_code <> 'FR') AS etrangeres_sans_region,
                count(*) FILTER (WHERE c.region_code IS NULL AND c.country_code = 'FR') AS francaises_sans_region,
                count(*) FILTER (WHERE c.size_category IS NULL AND c.entity_nature = 'entreprise') AS entreprises_sans_taille,
                count(*) FILTER (WHERE c.size_category IS NULL AND c.entity_nature <> 'entreprise') AS organisations_sans_taille,
                count(*) FILTER (WHERE c.size_category IS NULL AND c.entity_nature IS NULL) AS sans_taille_ni_nature
             FROM companies c
             WHERE c.workspace_id = ? AND c.deleted_at IS NULL AND " . $this->horsProtegees('c.id'),
            [$workspaceId],
        );
        $etat = [];
        foreach ((array) $ligne as $cle => $n) {
            $etat[(string) $cle] = (int) $n;
        }

        return $etat;
    }

    private function horsProtegees(string $colonneId): string
    {
        return $this->inclureProtegees ? 'TRUE' : FichesProtegees::conditionSql($colonneId);
    }

    /** @return list<stdClass> */
    private function lireLot(string $workspaceId, int $apresId, int $taille): array
    {
        $colonnes = implode(', ', array_map(static fn (string $c): string => 'c.' . $c, self::GARDE));
        $lignes = DB::select(
            "SELECT c.id, {$colonnes},
                    NOT " . FichesProtegees::conditionSql('c.id', FichesProtegees::TAGS_NATURE_NON_DEVINEE) . " AS nature_protegee
             FROM companies c
             WHERE c.workspace_id = ? AND c.id > ? AND c.deleted_at IS NULL
               AND (c.entity_nature IS NULL OR (c.region_code IS NULL AND c.country_code = 'FR'))
               AND " . $this->horsProtegees('c.id') . '
             ORDER BY c.id
             LIMIT ' . $taille,
            [$workspaceId, $apresId],
        );

        return array_values(array_filter($lignes, static fn (mixed $l): bool => $l instanceof stdClass));
    }

    /**
     * @param  list<stdClass>  $fiches
     * @return list<array{fiche: stdClass, nature: ?string, region: ?string}>
     */
    private function planifier(array $fiches): array
    {
        $plan = [];
        foreach ($fiches as $f) {
            $this->compteurs['fiches_lues']++;
            $nature = self::texte($f->entity_nature);
            $region = self::texte($f->region_code);
            $change = false;

            if ($nature === null) {
                if ((bool) $f->nature_protegee) {
                    $this->compteurs['natures_protegees_non_deduites']++;
                } else {
                    [$deduite, $motif] = Classement::natureDeduite(self::texte($f->legal_form), self::texte($f->naf), self::texte($f->naf_rev2), self::texte($f->siren));
                    if ($deduite !== null) {
                        $nature = $deduite;
                        $change = true;
                        $this->compteurs['natures_deduites_' . $motif]++;
                    } else {
                        $this->compteurs['natures_laissees_vides']++;
                    }
                }
            }

            if ($region === null && self::texte($f->country_code) === 'FR') {
                [$deduite, $motif] = Classement::regionFrancaise(self::texte($f->department_code), self::texte($f->postcode));
                if ($deduite !== null) {
                    $region = $deduite;
                    $change = true;
                    $this->compteurs['regions_par_' . $motif]++;
                } else {
                    $this->compteurs['regions_fr_sans_donnee_geographique']++;
                }
            }

            if ($change) {
                $this->compteurs['fiches_a_modifier']++;
                $plan[] = ['fiche' => $f, 'nature' => $nature, 'region' => $region];
            }
        }

        return $plan;
    }

    /**
     * @param  list<array{fiche: stdClass, nature: ?string, region: ?string}>  $plan
     */
    private function ecrire(string $workspaceId, array $plan): int
    {
        $gabarit = '(?::bigint, ?::text, ?::text' . str_repeat(', ?::text', count(self::GARDE)) . ')';
        $liaisons = [];
        foreach ($plan as $p) {
            array_push($liaisons, (int) $p['fiche']->id, $p['nature'], $p['region']);
            // Ce qu'on a LU, brut (`''` compris) : l'écriture n'a lieu que si
            // rien n'a bougé depuis.
            foreach (self::GARDE as $colonne) {
                $brut = $p['fiche']->{$colonne};
                $liaisons[] = is_scalar($brut) ? (string) $brut : null;
            }
        }
        $liaisons[] = $workspaceId;
        $noms = array_merge(['id', 'nature', 'region'], array_map(static fn (string $c): string => 'lu_' . $c, self::GARDE));
        $gardes = array_map(static fn (string $c): string => "c.{$c}::text IS NOT DISTINCT FROM v.lu_{$c}", self::GARDE);

        $lignes = DB::select(
            'UPDATE companies AS c
             SET entity_nature = v.nature, region_code = v.region
             FROM (VALUES ' . implode(', ', array_fill(0, count($plan), $gabarit)) . ') AS v(' . implode(', ', $noms) . ')
             WHERE c.id = v.id AND c.workspace_id = ?::uuid AND c.deleted_at IS NULL
               AND ' . implode("\n               AND ", $gardes) . '
               AND ' . $this->horsProtegees('c.id') . '
             RETURNING c.id',
            $liaisons,
        );

        return count($lignes);
    }

    /** @param  array<string, int>  $etat */
    private function afficherBilan(bool $dryRun, array $etat): void
    {
        $this->newLine();
        $this->info($dryRun ? '═══ BILAN DE L\'ESSAI À BLANC (rien n\'a été écrit) ═══' : '═══ BILAN DU COMBLEMENT ═══');
        $compteurs = $this->compteurs;
        if ($dryRun) {
            unset($compteurs['fiches_modifiees'], $compteurs['modifiees_entre_temps']);
        }
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($compteurs),
            array_values($compteurs),
        ));
        $this->line('Constat AVANT, sur les fiches traitées (comptées, jamais écrites pour les tailles et les étrangères) :');
        $this->table(['constat', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($etat),
            array_values($etat),
        ));
        $this->line('Étrangères : ciblables par le pays (country_code). Tailles inconnues : « taille non renseignée » (size_category vide). Rien n\'est deviné, rien n\'est supprimé.');
    }

    private static function texte(mixed $valeur): ?string
    {
        if (! is_scalar($valeur)) {
            return null;
        }
        $v = trim((string) $valeur);

        return $v === '' ? null : $v;
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
            'path' => 'artisan crm:referentiels:combler-trous — ' . $resume,
            'status' => $statut,
            'ip' => null,
            'user_agent' => 'cli ' . $operateur,
            'payload_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR)),
        ]);
    }
}

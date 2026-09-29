<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Etiquettes\FamillesEtiquettes;
use App\Crm\Referentiels\Metiers;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INVENTAIRE DES ÉTIQUETTES — LECTURE SEULE (chantier 2, 2026-09-29).
 *
 * Répond, sur la base réelle, aux questions du rangement :
 *   - combien d'étiquettes par TYPE de famille (gouvernée, automatique, IA,
 *     manuelle, sans famille — règle `FamillesEtiquettes`) et par famille ;
 *   - combien sont PORTÉES, combien ne le sont par AUCUNE fiche ni aucun
 *     candidat ;
 *   - la répartition catégorie × `kind` ;
 *   - les étiquettes IA encore rangées hors de leur catégorie `ia` ;
 *   - les étiquettes dont la catégorie n'est pas celle de leur famille ;
 *   - avec `--naf-sans-metier`, les sous-classes NAF rév. 2 les plus portées
 *     par des fiches SANS métier (ce qui manque à la table des métiers).
 *
 * N'écrit RIEN (aucune requête d'écriture, aucune transaction) : c'est l'outil
 * qu'on lance AVANT `crm:referentiels:reclasser` pour savoir ce que le
 * rangement va toucher.
 *
 * Dépôt PUBLIC, journaux des workflows publics : par défaut, AUCUN slug n'est
 * affiché — une étiquette manuelle ou IA peut porter un nom propre. `--details`
 * liste des exemples de slugs, sur un poste de confiance seulement.
 */
class CrmEtiquettesInventaire extends Command
{
    protected $signature = 'crm:etiquettes:inventaire
                            {--workspace= : Identifiant ou slug de l\'espace (défaut : celui de prospection:collect)}
                            {--naf-sans-metier : Chercher aussi les sous-classes NAF les plus fréquentes sans métier (lit toutes les fiches)}
                            {--details : Afficher des exemples de slugs (JAMAIS dans un journal public)}';

    protected $description = 'Inventaire des étiquettes (familles, portées ou non, IA, catégories) — lecture seule.';

    /** Nombre d'exemples de slugs affichés par ligne avec `--details`. */
    private const EXEMPLES = 5;

    public function handle(): int
    {
        $designation = is_string($this->option('workspace')) ? $this->option('workspace') : null;
        $workspaceId = EspaceProspection::resoudre($designation);
        if ($workspaceId === null) {
            $this->error('Espace introuvable : « ' . ($designation ?? '(défaut)') . ' ».');

            return self::FAILURE;
        }

        WorkspaceContext::run($workspaceId, function () use ($workspaceId): void {
            $this->inventaire($workspaceId);
            if ((bool) $this->option('naf-sans-metier')) {
                $this->nafSansMetier($workspaceId);
            }
        });

        return self::SUCCESS;
    }

    private function inventaire(string $workspaceId): void
    {
        /** @var array<int, int> $liens tag_id => nombre de fiches */
        $liens = [];
        foreach (DB::table('company_tag')->where('workspace_id', $workspaceId)
            ->groupBy('tag_id')->selectRaw('tag_id, count(*) AS n')->get() as $l) {
            $liens[(int) $l->tag_id] = (int) $l->n;
        }
        /** @var array<int, true> $candidats */
        $candidats = [];
        foreach (DB::table('candidate_tag')->where('workspace_id', $workspaceId)->distinct()->pluck('tag_id') as $id) {
            $candidats[(int) $id] = true;
        }

        $tags = DB::table('tags')->where('workspace_id', $workspaceId)->orderBy('id')
            ->get(['id', 'slug', 'kind', 'category', 'is_locked']);

        $details = (bool) $this->option('details');
        $parFamille = [];
        $parCategorie = [];
        $iaHorsCategorie = 0;
        $categorieInattendue = [];
        $total = ['etiquettes' => 0, 'portees' => 0, 'jamais_portees' => 0, 'liens' => 0];
        foreach ($tags as $t) {
            $slug = (string) $t->slug;
            $kind = (string) $t->kind;
            $categorie = (string) $t->category;
            $id = (int) $t->id;
            $n = $liens[$id] ?? 0;
            $portee = $n > 0 || isset($candidats[$id]);

            $f = FamillesEtiquettes::famille($slug, $kind);
            $cle = $f['type'] . ' | ' . ($f['famille'] ?? '—');
            $parFamille[$cle] ??= ['etiquettes' => 0, 'portees' => 0, 'jamais_portees' => 0, 'verrouillees' => 0, 'liens' => 0, 'exemples' => []];
            $parFamille[$cle]['etiquettes']++;
            $parFamille[$cle][$portee ? 'portees' : 'jamais_portees']++;
            $parFamille[$cle]['verrouillees'] += (bool) $t->is_locked ? 1 : 0;
            $parFamille[$cle]['liens'] += $n;
            if ($details && count($parFamille[$cle]['exemples']) < self::EXEMPLES) {
                $parFamille[$cle]['exemples'][] = $slug;
            }

            $parCategorie["{$categorie} | {$kind}"] = ($parCategorie["{$categorie} | {$kind}"] ?? 0) + 1;

            if ($kind === 'llm' && $categorie !== FamillesEtiquettes::CATEGORIE_IA) {
                $iaHorsCategorie++;
            }
            $attendue = FamillesEtiquettes::categorieAttendue($slug, $kind);
            if ($attendue !== null && $attendue !== $categorie && $kind !== 'llm') {
                $cleCat = "{$f['type']} | " . ($f['famille'] ?? '—') . " : {$categorie} au lieu de {$attendue}";
                $categorieInattendue[$cleCat] = ($categorieInattendue[$cleCat] ?? 0) + 1;
            }

            $total['etiquettes']++;
            $total[$portee ? 'portees' : 'jamais_portees']++;
            $total['liens'] += $n;
        }

        ksort($parFamille);
        $this->info('═══ ÉTIQUETTES PAR FAMILLE (règle FamillesEtiquettes) ═══');
        $entetes = ['type | famille', 'étiquettes', 'portées', 'jamais portées', 'verrouillées', 'liens fiches'];
        if ($details) {
            $entetes[] = 'exemples';
        }
        $lignes = [];
        foreach ($parFamille as $cle => $v) {
            $ligne = [$cle, $v['etiquettes'], $v['portees'], $v['jamais_portees'], $v['verrouillees'], $v['liens']];
            if ($details) {
                $ligne[] = implode(', ', $v['exemples']);
            }
            $lignes[] = $ligne;
        }
        $this->table($entetes, $lignes);

        ksort($parCategorie);
        $this->newLine();
        $this->info('═══ CATÉGORIE × KIND ═══');
        $this->table(['catégorie | kind', 'étiquettes'], array_map(
            static fn (string $c, int $n): array => [$c, $n],
            array_keys($parCategorie),
            array_values($parCategorie),
        ));

        $this->newLine();
        $this->info('═══ ÉCARTS À LA RÈGLE ═══');
        $sansFamille = 0;
        foreach ($parFamille as $cle => $v) {
            if (str_starts_with($cle, FamillesEtiquettes::TYPE_SANS_FAMILLE)) {
                $sansFamille += $v['etiquettes'];
            }
        }
        $ecarts = [
            ['etiquettes_sans_famille', $sansFamille],
            ['etiquettes_ia_hors_categorie_ia', $iaHorsCategorie],
        ];
        ksort($categorieInattendue);
        foreach ($categorieInattendue as $cle => $n) {
            $ecarts[] = ["categorie_inattendue ({$cle})", $n];
        }
        $this->table(['écart', 'nombre'], $ecarts);

        $this->newLine();
        $this->table(['compteur', 'nombre'], [
            ['etiquettes', $total['etiquettes']],
            ['etiquettes_portees', $total['portees']],
            ['etiquettes_jamais_portees', $total['jamais_portees']],
            ['liens_fiches', $total['liens']],
        ]);
    }

    /**
     * Les sous-classes NAF rév. 2 les plus portées par des fiches sans métier :
     * ce que la table des métiers ne couvre pas. Codes et nombres seulement.
     */
    private function nafSansMetier(string $workspaceId): void
    {
        $couvertes = array_keys(Metiers::table());
        $lignes = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->whereNotNull('naf_rev2')
            ->whereNotIn('naf_rev2', $couvertes)
            ->groupBy('naf_rev2')
            ->selectRaw('naf_rev2, count(*) AS n')
            ->orderByDesc('n')
            ->limit(40)
            ->get();

        $this->newLine();
        $this->info('═══ SOUS-CLASSES NAF LES PLUS FRÉQUENTES SANS MÉTIER (40 premières) ═══');
        $this->table(['naf_rev2', 'fiches'], $lignes->map(
            static fn (object $l): array => [(string) $l->naf_rev2, (int) $l->n],
        )->all());
    }
}

<?php

/**
 * 🔴 LES DEUX ÉVALUATEURS D'AUDIENCE DOIVENT RENDRE LE MÊME ENSEMBLE.
 *
 * `AudienceBuilderService` porte DEUX évaluateurs des mêmes critères :
 *
 *   · le chemin SQL — `buildQuery()` / `buildPositive()` / `negate()`, celui
 *     que `refresh()` emprunte pour matérialiser les membres ;
 *   · le chemin EN MÉMOIRE — `companyMatchesCriteria()` / `evalCondition()`,
 *     celui du waterfall step12 (`evaluateForCompany()`), qui décide en RAM à
 *     quelles audiences une fiche fraîchement enrichie appartient.
 *
 * Le défaut qu'a corrigé la PR #169 le 2026-08-17 n'était pas « `neq` ramène
 * ou non les NULL ». C'était que **le contenu d'une même audience dépendait du
 * chemin qui l'avait calculée** : `refresh()` en SQL n'en retenait pas les
 * mêmes fiches que step12 en mémoire. Une audience dont le contenu change
 * selon le chemin de calcul n'est pas une audience : ce sont deux audiences
 * qui portent le même nom.
 *
 * Ce test ne fige donc PAS une valeur de vérité. Il fige la SYMÉTRIE — le seul
 * invariant qui vaille. La sémantique retenue (NULL inclus par `neq` /
 * `not_in`, arbitrage du 2026-08-18) n'en est qu'une conséquence : si demain
 * Will décide l'inverse, ce test reste vert **à condition que les deux
 * évaluateurs bougent ensemble**, et rougit si un seul bouge.
 *
 * ── Ce qui est couvert ────────────────────────────────────────────────────
 * Les dix opérateurs de valeur (`eq, neq, in, not_in, gt, lt, gte, lte,
 * is_null, is_not_null`), les deux champs particuliers (`tags` et `has_email`,
 * bâtis sur EXISTS — jamais UNKNOWN), **et chacun sous le combinateur `not`**.
 *
 * La symétrie sous `not` est la plus fragile des deux : sous `where`, un
 * prédicat UNKNOWN élimine la ligne exactement comme FALSE, et l'écart ne se
 * voit pas. Sous `NOT`, `NOT UNKNOWN` reste UNKNOWN — la ligne est éliminée
 * **alors qu'elle aurait dû être gardée**. C'est ce que compense la liste
 * `NULL_SENSITIVE_OPS` + `negate()` ; rien d'autre ne la garde.
 *
 * ── Pourquoi tant de fiches à colonne NULL ────────────────────────────────
 * Parce qu'une base de prospection collectée en est essentiellement faite
 * (3,49 M de fiches encore `pending` en production). Un jeu d'essai sans NULL
 * rendrait ce test vert quoi qu'il arrive : les deux évaluateurs ne divergent
 * QUE sur les inconnus.
 */

use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Les fiches d'essai. Chaque colonne interrogée porte des NULL en quantité —
 * c'est là, et seulement là, que les deux évaluateurs peuvent diverger.
 *
 * @return array<string, int> repère lisible => id
 */
function symetrieJeuDEssai(string $workspaceId): array
{
    $lignes = [
        // repère              département  taille   secteur  confiance  statut
        'isere-pme-A' => ['38', 'PME', 'btp', 'A', 'ready_for_outreach'],
        'isere-tpe-B' => ['38', 'TPE', 'sante', 'B', 'pending'],
        'rhone-pme-C' => ['69', 'PME', 'btp', 'C', 'pending'],
        'rhone-null-null' => ['69', null, null, null, 'pending'],
        'paris-eti-B' => ['75', 'ETI', 'droit', 'B', 'partial_email'],
        'nulldep-pme-A' => [null, 'PME', 'btp', 'A', 'pending'],
        'nulldep-null-C' => [null, null, 'sante', 'C', 'pending'],
        'nulldep-tout-null' => [null, null, null, null, 'pending'],
        'nulldep-tout-null-2' => [null, null, null, null, 'archived_no_email'],
        'isere-null-conf' => ['38', 'PME', 'droit', null, 'pending'],
        'rhone-null-taille' => ['69', null, 'btp', 'B', 'ready_for_outreach'],
        'paris-null-secteur' => ['75', 'TPE', null, 'A', 'pending'],
    ];

    $ids = [];
    foreach ($lignes as $repere => [$dept, $taille, $secteur, $confiance, $statut]) {
        $fiche = Company::create([
            'workspace_id' => $workspaceId,
            'siren' => (string) random_int(100000000, 999999999),
            'denomination' => $repere,
            'department_code' => $dept,
            'size_category' => $taille,
            'sector_main' => $secteur,
            'prospection_status' => $statut,
            'signals' => [],
            'metadata' => [],
        ]);

        // `best_email_confidence` est VOLONTAIREMENT hors `$fillable` (score
        // posé par l'enrichissement, jamais saisi) : une affectation de masse
        // le laisserait tomber EN SILENCE.
        $fiche->forceFill(['best_email_confidence' => $confiance])->save();

        $ids[$repere] = (int) $fiche->id;
    }

    return $ids;
}

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-symetrie',
        'name' => 'WS symétrie',
        'settings' => [],
    ]);

    $this->ids = symetrieJeuDEssai($this->workspace->id);
    $this->service = app(AudienceBuilderService::class);

    // `tags` : une seule fiche porte le tag visé. Le prédicat est bâti sur
    // EXISTS, donc jamais UNKNOWN — sa symétrie sous `not` doit tenir sans
    // l'aide de `NULL_SENSITIVE_OPS`, et ce test le constate.
    $tag = Tag::create([
        'workspace_id' => $this->workspace->id,
        'slug' => 'secteur:btp',
        'name' => 'BTP',
        'category' => 'sector',
        'rules' => [],
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $this->ids['isere-pme-A'],
        'tag_id' => $tag->id,
    ]);

    // `has_email` : deux chemins vers « joignable » — un contact contactable,
    // ou l'`email_generic` de l'entreprise. Les deux doivent se comporter
    // pareil des deux côtés.
    Company::whereKey($this->ids['rhone-pme-C'])->update(['email_generic' => 'contact@rhone.fr']);
    DB::table('contacts')->insert([
        'workspace_id' => $this->workspace->id,
        'company_id' => $this->ids['paris-eti-B'],
        'last_name' => 'Joignable',
        'email' => 'personne@paris.fr',
        'email_status' => 'valid',
        'sources' => '[]',
        'metadata' => '{}',
    ]);
    // Un contact NON contactable : la fiche ne doit pas compter comme jointe.
    DB::table('contacts')->insert([
        'workspace_id' => $this->workspace->id,
        'company_id' => $this->ids['isere-tpe-B'],
        'last_name' => 'Injoignable',
        'email' => 'mort@isere.fr',
        'email_status' => 'invalid',
        'sources' => '[]',
        'metadata' => '{}',
    ]);
});

/**
 * Le chemin SQL : exactement celui qu'emprunte `refresh()`.
 *
 * @param  array<string, mixed>  $criteria
 * @return list<int>
 */
function symetrieIdsSql(AudienceBuilderService $service, string $workspaceId, array $criteria): array
{
    $ids = $service->buildPublicQuery($workspaceId, $criteria)->pluck('id')->all();
    $ids = array_map('intval', $ids);
    sort($ids);

    return array_values($ids);
}

/**
 * Le chemin EN MÉMOIRE : exactement celui qu'emprunte le waterfall step12.
 *
 * 🔴 On passe par `evaluateForCompany()`, l'entrée PUBLIQUE réellement appelée
 * en production, et non par une réimplémentation du prédicat. Réécrire la
 * comparaison ici ferait un test qui se garde lui-même : il resterait vert
 * pendant que le vrai chemin dériverait.
 *
 * @param  array<string, mixed>  $criteria
 * @param  array<string, int>  $ids
 * @return list<int>
 */
function symetrieIdsMemoire(
    AudienceBuilderService $service,
    string $workspaceId,
    array $criteria,
    array $ids,
): array {
    $audience = EmailAudience::create([
        'workspace_id' => $workspaceId,
        'name' => 'sonde-' . Str::random(8),
        'criteria' => $criteria,
        'is_active' => true,
        'auto_refresh' => true,
    ]);

    $retenus = [];
    foreach ($ids as $id) {
        // Instance FRAÎCHE : `evalCondition()` lit les attributs et les
        // relations du modèle ; un cache de relation d'une itération
        // précédente fausserait la mesure.
        $fiche = Company::findOrFail($id);
        if (in_array($audience->id, $service->evaluateForCompany($fiche), true)) {
            $retenus[] = (int) $id;
        }
    }

    // On retire la sonde : `evaluateForCompany()` balaie TOUTES les audiences
    // actives du workspace, une sonde oubliée polluerait le cas suivant.
    $audience->forceDelete();

    sort($retenus);

    return array_values($retenus);
}

/**
 * Les cas couverts. Chaque entrée est jouée DEUX fois : sous `all`, puis sous
 * `not`.
 *
 * @return list<array{field: string, op: string, value: mixed}>
 */
function symetrieCas(): array
{
    return [
        // ── Les dix opérateurs de valeur, sur des colonnes NULLABLES ──────
        ['field' => 'department_code', 'op' => 'eq', 'value' => '38'],
        ['field' => 'department_code', 'op' => 'neq', 'value' => '38'],
        ['field' => 'department_code', 'op' => 'in', 'value' => ['38', '69']],
        ['field' => 'department_code', 'op' => 'not_in', 'value' => ['38', '69']],
        // Ordonnés sur `best_email_confidence` (A > B > C, CHAR(1) nullable).
        // 🔴 Pas sur `department_code` : PHP compare « 09 » et « 9 » comme des
        // NOMBRES (chaînes numériques), Postgres comme du TEXTE. Cet écart-là
        // est réel mais il n'est pas celui que ce test garde — le mesurer ici
        // ferait rougir la symétrie pour une autre cause.
        ['field' => 'best_email_confidence', 'op' => 'gt', 'value' => 'A'],
        ['field' => 'best_email_confidence', 'op' => 'lt', 'value' => 'C'],
        ['field' => 'best_email_confidence', 'op' => 'gte', 'value' => 'B'],
        ['field' => 'best_email_confidence', 'op' => 'lte', 'value' => 'B'],
        ['field' => 'best_email_confidence', 'op' => 'is_null', 'value' => null],
        ['field' => 'best_email_confidence', 'op' => 'is_not_null', 'value' => null],

        // Les mêmes opérateurs sur d'autres colonnes nullables : une symétrie
        // qui ne tiendrait que pour une colonne ne tiendrait pas.
        ['field' => 'size_category', 'op' => 'eq', 'value' => 'PME'],
        ['field' => 'size_category', 'op' => 'neq', 'value' => 'PME'],
        ['field' => 'sector_main', 'op' => 'in', 'value' => ['btp']],
        ['field' => 'sector_main', 'op' => 'not_in', 'value' => ['btp', 'sante']],
        ['field' => 'department_code', 'op' => 'is_null', 'value' => null],
        ['field' => 'department_code', 'op' => 'is_not_null', 'value' => null],

        // Colonne NOT NULL : le témoin. Si celui-ci rougissait, l'écart ne
        // viendrait pas des NULL.
        ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'pending'],
        ['field' => 'prospection_status', 'op' => 'neq', 'value' => 'pending'],

        // ── Les deux champs particuliers (EXISTS, jamais UNKNOWN) ─────────
        ['field' => 'tags', 'op' => 'contains_any', 'value' => ['secteur:btp']],
        ['field' => 'tags', 'op' => 'contains_any', 'value' => ['secteur:inexistant']],
        // Liste VIDE : ne vise personne, et surtout ne doit pas être « ignorée »
        // — une condition retirée de la requête rendrait TOUT le workspace.
        ['field' => 'tags', 'op' => 'contains_any', 'value' => []],
        ['field' => 'has_email', 'op' => 'eq', 'value' => true],
        ['field' => 'has_email', 'op' => 'eq', 'value' => false],
    ];
}

test('🔴 SQL et mémoire rendent le MÊME ensemble — dix opérateurs, deux combinateurs', function () {
    $ecarts = [];

    foreach (symetrieCas() as $cas) {
        foreach (['all', 'not'] as $combinateur) {
            $criteria = [$combinateur => [$cas]];

            $sql = symetrieIdsSql($this->service, $this->workspace->id, $criteria);
            $memoire = symetrieIdsMemoire($this->service, $this->workspace->id, $criteria, $this->ids);

            if ($sql === $memoire) {
                continue;
            }

            $reperes = array_flip($this->ids);
            $nommer = fn (array $liste): string => $liste === []
                ? '(vide)'
                : implode(', ', array_map(fn (int $id): string => $reperes[$id] ?? (string) $id, $liste));

            $ecarts[] = sprintf(
                "ASYMÉTRIE · combinateur=%s · op=%s · champ=%s · valeur=%s\n"
                . "        SQL     rend : %s\n"
                . "        mémoire rend : %s\n"
                . '        seulement SQL : %s | seulement mémoire : %s',
                $combinateur,
                $cas['op'],
                $cas['field'],
                json_encode($cas['value'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $nommer($sql),
                $nommer($memoire),
                $nommer(array_values(array_diff($sql, $memoire))),
                $nommer(array_values(array_diff($memoire, $sql))),
            );
        }
    }

    expect($ecarts)->toBe([], sprintf(
        "%d opérateur(s) en écart entre le chemin SQL (refresh) et le chemin en mémoire (step12).\n"
        . "Une audience dont le contenu dépend du chemin de calcul n'est pas une audience.\n\n%s\n",
        count($ecarts),
        implode("\n\n", $ecarts),
    ));
});

/**
 * Le test ci-dessus compare deux ensembles. Il resterait vert si les DEUX
 * étaient vides — c'est-à-dire si le jeu d'essai ne discriminait rien.
 *
 * Ce témoin établit que le jeu d'essai est effectivement peuplé de fiches à
 * colonne NULL, et que la sémantique arbitrée le 2026-08-18 (NULL inclus par
 * `neq` / `not_in`) est bien celle qui est en place. Sans lui, la symétrie
 * pourrait être celle de deux évaluateurs également aveugles.
 */
test('témoin — le jeu d’essai discrimine, et la sémantique arbitrée est en place', function () {
    $sansDepartement = collect($this->ids)->keys()->filter(fn (string $r) => str_starts_with($r, 'nulldep'))->count();
    expect($sansDepartement)->toBe(4);

    // `neq '38'` INCLUT les fiches sans département (arbitrage du 2026-08-18,
    // décision 2 : on garde la sémantique livrée par la PR #169).
    $neq = symetrieIdsSql($this->service, $this->workspace->id, [
        'all' => [['field' => 'department_code', 'op' => 'neq', 'value' => '38']],
    ]);
    expect($neq)->toContain($this->ids['nulldep-tout-null']);

    // Et le prédicat trie tout de même : les fiches en 38 en sont absentes.
    expect($neq)->not->toContain($this->ids['isere-pme-A']);

    // Sous `not`, l'inverse — et c'est cette moitié-là que `negate()` garde.
    $notEq = symetrieIdsSql($this->service, $this->workspace->id, [
        'not' => [['field' => 'department_code', 'op' => 'eq', 'value' => '38']],
    ]);
    expect($notEq)->toContain($this->ids['nulldep-tout-null'])
        ->and($notEq)->not->toContain($this->ids['isere-pme-A']);
});

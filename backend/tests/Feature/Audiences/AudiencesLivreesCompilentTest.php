<?php

/**
 * D26-001 — LE REVERS DU REFUS : CE QUI EST LIVRE DOIT ENCORE COMPILER.
 *
 * Depuis le correctif D26-001, un critere mal forme n'est plus efface : il est
 * REFUSE. C'est la bonne reponse, mais elle a un cout qu'il faut garder.
 *
 * `audiences:full-refresh` tourne toutes les nuits a 04:00 (routes/console.php)
 * et appelle `AudienceBuilderService::refresh()` sur chaque audience active a
 * auto_refresh. Le service leve desormais sur un critere invalide, la commande
 * compte l'echec et rend FAILURE. Autrement dit : une SEULE audience livree
 * mal ecrite ferait rougir le refresh de toutes les nuits.
 *
 * Le depot en livre sept, par deux seeders :
 *   · DefaultAudiencesSeeder — 3 audiences, executees sur CHAQUE workspace ;
 *     sans elles la segmentation reste vide (constat prod « email_audiences=0 »).
 *   · DemoAudiencesSeeder — 4 audiences d'exemple.
 *
 * Ces sept jeux de criteres n'etaient garantis par RIEN : avant le correctif,
 * un champ mal orthographie y aurait ete silencieusement efface, et l'audience
 * « PME IT Ile-de-France » aurait vise les 4 295 349 fiches du workspace.
 * Le meme oubli, aujourd'hui, se voit — et ce test le voit AVANT la nuit.
 *
 * ── Pourquoi ce test ne peut pas verdir a vide ───────────────────────────────
 *
 * Une boucle « pour chaque audience, verifier » est verte quand il n'y a AUCUNE
 * audience. Deux garde-fous l'en empechent :
 *   1. le nombre d'audiences semees est assert AVANT la boucle (7 attendues) ;
 *   2. un TEMOIN insere une audience deliberement cassee et exige que la MEME
 *      boucle la trouve. Une boucle qui ne verrait rien echouerait la.
 */

use App\Models\EmailAudience;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audiences\CritereAudienceInvalide;
use Database\Seeders\DefaultAudiencesSeeder;
use Database\Seeders\DemoAudiencesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Passe chaque audience du workspace au validateur et rend la liste de celles
 * qui sont refusees, nommees, avec le motif. On rend une LISTE plutot qu'un
 * booleen : quand ce test rougira, il devra dire LAQUELLE et POURQUOI, sinon
 * le lecteur devra rejouer les seeders a la main pour le savoir.
 *
 * @return list<string>
 */
function auditerCriteresLivres(string $workspaceId): array
{
    $refusees = [];

    foreach (EmailAudience::query()->where('workspace_id', $workspaceId)->get() as $audience) {
        try {
            AudienceBuilderService::validerCriteres(
                is_array($audience->criteria) ? $audience->criteria : [],
            );
        } catch (CritereAudienceInvalide $e) {
            $refusees[] = $audience->name . ' -> ' . $e->getMessage();
        }
    }

    return $refusees;
}

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-audiences-livrees',
        'name' => 'WS audiences livrees',
        'settings' => [],
    ]);

    // Les deux seeders bouclent sur TOUS les workspaces existants : un seul
    // workspace en base, donc exactement une serie de chaque.
    $this->seed(DefaultAudiencesSeeder::class);
    $this->seed(DemoAudiencesSeeder::class);
});

test('les sept audiences livrees par les seeders compilent toutes', function () {
    // TEMOIN 1 — la boucle a bien de quoi mordre. Si un seeder cessait de
    // semer, le test qui suit serait vert sans avoir rien verifie.
    $semees = EmailAudience::query()->where('workspace_id', $this->workspace->id)->count();
    expect($semees)->toBe(7);

    $refusees = auditerCriteresLivres($this->workspace->id);

    // 🔴 Si ceci rougit : une audience LIVREE ne compile plus. Consequence
    // concrete, pas theorique — `audiences:full-refresh` rendra FAILURE
    // toutes les nuits a 04:00 tant que le critere n'est pas corrige.
    expect($refusees)->toBe([]);
});

test('TEMOIN : la meme boucle trouve une audience deliberement cassee', function () {
    // Sans ce temoin, le test precedent serait indistinguable d'une boucle
    // qui n'inspecte rien. On casse ici une audience de la meme facon que le
    // ferait une faute de frappe reelle : un champ hors liste blanche.
    EmailAudience::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'ZZ temoin casse',
        'criteria' => ['all' => [['field' => 'departement_code', 'op' => 'eq', 'value' => '75']]],
        'is_active' => true,
        'auto_refresh' => true,
    ]);

    $refusees = auditerCriteresLivres($this->workspace->id);

    expect($refusees)->toHaveCount(1);
    // Sous-chaine SANS lettre accentuee : le message du refus est en francais.
    $this->assertStringContainsString('ZZ temoin casse', $refusees[0]);
    $this->assertStringContainsString('departement_code', $refusees[0]);
});

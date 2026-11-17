<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\Support\OriginesJeu;

/*
 * Lot X1-L2b-D — écriture du filtre « réseau d'origine » par l'assistant de création (cahier X1-L2-bis, PR #648, § 6).
 * Requêtes HTTP réelles (propriétaire de l'espace), PostgreSQL réel ; la colonne fait foi, jamais la saisie. Aucun envoi, aucun drapeau touché
 * (seul `config([...])` en mémoire). Fixtures `*.example.test`.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const CFO = '/api/v1/envoi/brouillons';

beforeEach(function () {
    config(['origines.lecture_enabled' => false, 'origines.v3_enabled' => false]);
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
});

function cfoBrouillon($t): array
{
    $r = $t->actingAs($t->owner)->postJson(CFO, ['nom' => 'Campagne fictive'])->assertCreated();

    return [$r->json('id'), (int) $r->json('version')];
}

function cfoEtape1($t, string $id, int $v, array $origine)
{
    return $t->actingAs($t->owner)->putJson(CFO . "/{$id}/etapes/1", ['cible' => ['type' => 'liste', 'reference' => '1', 'libelle' => 'Liste fictive'] + $origine], ['If-Match' => (string) $v]);
}

function cfoColonne(string $id): ?string
{
    return DB::table('envoi_campagnes')->where('id', $id)->value('filtre_origine');
}

function cfoVersion(string $id): int
{
    return (int) DB::table('envoi_campagnes')->where('id', $id)->value('version');
}

function cfoAllumer(string $ws): void
{
    OriginesJeu::allumer();
    OriginesJeu::ouvrir($ws);
}

test('1 filtre actif : 200, colonne = {"reseaux":["linkedin"]}, version + 1, GET rend cible.origine', function () {
    cfoAllumer($this->ws->id);
    [$id, $v] = cfoBrouillon($this);
    $r = cfoEtape1($this, $id, $v, ['origine' => ['reseaux' => [' linkedin ', 'facebook', 'linkedin']]])->assertOk();
    expect(json_decode(cfoColonne($id), true))->toBe(['reseaux' => ['facebook', 'linkedin']])
        ->and(cfoVersion($id))->toBe($v + 1)
        ->and($r->json('contenu.cible.origine'))->toBe(['reseaux' => ['facebook', 'linkedin']]);
    $g = $this->actingAs($this->owner)->getJson(CFO . "/{$id}")->assertOk();
    expect($g->json('contenu.cible.origine'))->toBe(['reseaux' => ['facebook', 'linkedin']])
        ->and(ContratOpenApiEnvoi::ecarts('creation.yaml', 'Brouillon', $g->json()))->toBe([]);
});

test('2 drapeau éteint (défaut) : 422 filtre_origine_inactif, colonne NULL, version inchangée', function () {
    [$id, $v] = cfoBrouillon($this);
    cfoEtape1($this, $id, $v, ['origine' => ['reseaux' => ['linkedin']]])->assertStatus(422)->assertJsonPath('error', 'filtre_origine_inactif');
    expect(cfoColonne($id))->toBeNull()->and(cfoVersion($id))->toBe($v);
});

test('3 fonction d\'espace fermée : 422 ; espace B ouvert, espace A fermé : 422 dans A', function () {
    OriginesJeu::allumer();
    [$id, $v] = cfoBrouillon($this);
    cfoEtape1($this, $id, $v, ['origine' => ['reseaux' => ['linkedin']]])->assertStatus(422)->assertJsonPath('error', 'filtre_origine_inactif');
    $b = $this->espace(true);
    OriginesJeu::ouvrir($b->id);
    cfoEtape1($this, $id, $v, ['origine' => ['reseaux' => ['linkedin']]])->assertStatus(422)->assertJsonPath('error', 'filtre_origine_inactif');
    expect(cfoColonne($id))->toBeNull()->and(cfoVersion($id))->toBe($v);
});

test('4 origine: null avec drapeau éteint : 200, colonne NULL ; clé absente : colonne inchangée', function () {
    cfoAllumer($this->ws->id);
    [$id, $v] = cfoBrouillon($this);
    $v = (int) cfoEtape1($this, $id, $v, ['origine' => ['reseaux' => ['linkedin']]])->assertOk()->json('version');
    // clé absente : inchangée
    $v = (int) cfoEtape1($this, $id, $v, [])->assertOk()->json('version');
    expect(json_decode(cfoColonne($id), true))->toBe(['reseaux' => ['linkedin']]);
    config(['origines.lecture_enabled' => false]);
    $r = cfoEtape1($this, $id, $v, ['origine' => null])->assertOk();
    expect(cfoColonne($id))->toBeNull()->and($r->json('contenu.cible.origine'))->toBeNull();
});

test('5 formes invalides : 422 validation, rien écrit', function () {
    cfoAllumer($this->ws->id);
    [$id, $v] = cfoBrouillon($this);
    foreach ([['reseaux' => array_map(fn ($i) => "r{$i}", range(1, 11))], ['reseaux' => ['LinkedIn']], ['reseaux' => ['x@example.test']], ['reseaux' => []], ['reseaux' => 'linkedin'], ['autre' => 1], ['reseaux' => ['linkedin'], 'plus' => 1], 'linkedin'] as $i => $origine) {
        cfoEtape1($this, $id, $v, ['origine' => $origine])->assertStatus(422)->assertJsonPath('error', 'validation');
        expect(cfoColonne($id))->toBeNull("forme #{$i}")->and(cfoVersion($id))->toBe($v);
    }
});

test('6 contrôles : absent sans filtre ; ok actif ; bloquant si le drapeau s\'éteint, et la validation est refusée', function () {
    [$id, $v] = cfoBrouillon($this);
    $codes = fn () => array_column($this->actingAs($this->owner)->getJson(CFO . "/{$id}/controles")->assertOk()->json('controles'), 'etat', 'code');
    expect($codes())->not->toHaveKey('filtre_origine')->and($codes())->toHaveCount(7);
    cfoAllumer($this->ws->id);
    cfoEtape1($this, $id, $v, ['origine' => ['reseaux' => ['linkedin']]])->assertOk();
    $r = $this->actingAs($this->owner)->getJson(CFO . "/{$id}/controles")->assertOk();
    $par = array_column($r->json('controles'), null, 'code');
    expect($par['filtre_origine']['etat'])->toBe('ok')->and($par['filtre_origine']['detail'])->toBe('Filtre par réseau : linkedin')
        ->and($par['filtre_origine_estimation']['etat'])->toBe('attention')
        ->and(ContratOpenApiEnvoi::ecarts('creation.yaml', 'Controle', $par['filtre_origine']))->toBe([])
        ->and(ContratOpenApiEnvoi::ecarts('creation.yaml', 'Controle', $par['filtre_origine_estimation']))->toBe([]);
    config(['origines.lecture_enabled' => false]);
    $r = $this->actingAs($this->owner)->getJson(CFO . "/{$id}/controles")->assertOk();
    $par = array_column($r->json('controles'), null, 'code');
    expect($par['filtre_origine']['etat'])->toBe('bloquant')->and($par['filtre_origine']['detail'])->toContain('inactif');
    $this->actingAs($this->owner)->postJson(CFO . "/{$id}/validation/code")->assertStatus(422)->assertJsonPath('error', 'controles_bloquants');
});

test('7 la saisie seule ne suffit pas : presenter rend origine null, la colonne reste NULL', function () {
    cfoAllumer($this->ws->id);
    [$id, $v] = cfoBrouillon($this);
    $saisie = ['contenu' => ['cible' => ['type' => 'liste', 'reference' => '1', 'libelle' => 'L', 'origine' => ['reseaux' => ['linkedin']]]], 'etapes_faites' => [1]];
    DB::table('envoi_campagnes')->where('id', $id)->update(['saisie' => json_encode($saisie), 'version' => $v + 1]);
    $g = $this->actingAs($this->owner)->getJson(CFO . "/{$id}")->assertOk();
    expect(cfoColonne($id))->toBeNull()->and($g->json('contenu.cible.origine'))->toBeNull();
    $c = array_column($this->actingAs($this->owner)->getJson(CFO . "/{$id}/controles")->assertOk()->json('controles'), 'etat', 'code');
    expect($c)->not->toHaveKey('filtre_origine');
});

test('8 DepotCampagnes::modifier : écrit en modes app et proprio ; reliée ou figée = CampagneRefusee, jamais d\'erreur brute', function () {
    $ws = $this->ws->id;
    foreach (['app', 'proprio'] as $mode) {
        $id = app(DepotCampagnes::class)->creer($ws, 'Campagne ' . $mode, ['type' => 'liste', 'reference' => '1', 'libelle' => 'L'], [], 1, [], 'le fondateur');
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $n = app(DepotCampagnes::class)->modifier($ws, $id, 1, ['filtre_origine' => ['reseaux' => ['linkedin', 'facebook']]]);
        $n = app(DepotCampagnes::class)->modifier($ws, $id, $n, ['filtre_origine' => null]);
        app(DepotCampagnes::class)->modifier($ws, $id, $n, ['filtre_origine' => ['reseaux' => ['x']]]);
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
        expect(json_decode(cfoColonne($id), true))->toBe(['reseaux' => ['x']], $mode);
        foreach ([['reseaux' => []], ['reseaux' => ['LinkedIn']], 'x', ['reseaux' => ['a'], 'z' => 1]] as $mauvais) {
            expect(fn () => app(DepotCampagnes::class)->modifier($ws, $id, cfoVersion($id), ['filtre_origine' => $mauvais]))->toThrow(CampagneRefusee::class);
        }
    }
    // reliée (la base répond P0001) : convertie en refus propre. Brouillon relié forcé hors déclencheurs (fixture seulement).
    $id = app(DepotCampagnes::class)->creer($ws, 'Reliée', ['type' => 'liste', 'reference' => '1', 'libelle' => 'L'], [], 1, [], 'le fondateur');
    $seq = (string) Str::uuid();
    DB::statement('SET session_replication_role = replica');
    DB::table('envoi_sequences')->insert(['id' => $seq, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);
    DB::table('envoi_campagnes')->where('id', $id)->update(['sequence_id' => $seq]);
    DB::statement('SET session_replication_role = origin');
    expect(fn () => app(DepotCampagnes::class)->modifier($ws, $id, 1, ['filtre_origine' => ['reseaux' => ['linkedin']]]))->toThrow(CampagneRefusee::class);
    expect(cfoColonne($id))->toBeNull();
});

test('9 réponses conformes à creation.yaml (Brouillon et Controle) avec un filtre posé', function () {
    cfoAllumer($this->ws->id);
    [$id, $v] = cfoBrouillon($this);
    $r = cfoEtape1($this, $id, $v, ['origine' => ['reseaux' => ['linkedin']]])->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'Brouillon', $r->json()))->toBe([]);
});

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
 * Lot PONT-LINKEDIN PO-C — écriture du filtre « mot / guide » par l'assistant de création (calque de X1-L2b-D ; cahier #691 § 4).
 * HTTP réel (propriétaire de l'espace), PostgreSQL réel ; la colonne fait foi, jamais la saisie. Aucun envoi, aucun drapeau touché
 * (seul `config([...])` en mémoire). Fixtures `*.example.test`.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const AFC = '/api/v1/envoi/brouillons';

beforeEach(function () {
    config(['origines.lecture_enabled' => false, 'origines.v3_enabled' => false]);
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
});

function afcBrouillon($t): array
{
    $r = $t->actingAs($t->owner)->postJson(AFC, ['nom' => 'Campagne fictive'])->assertCreated();

    return [$r->json('id'), (int) $r->json('version')];
}

function afcEtape1($t, string $id, int $v, array $origine, ?object $compte = null)
{
    return $t->actingAs($compte ?? $t->owner)->putJson(AFC . "/{$id}/etapes/1", ['cible' => ['type' => 'liste', 'reference' => '1', 'libelle' => 'Liste fictive'] + $origine], ['If-Match' => (string) $v]);
}

function afcCol(string $id, string $col): ?array
{
    $v = DB::table('envoi_campagnes')->where('id', $id)->value($col);

    return $v === null ? null : json_decode($v, true);
}

function afcVersion(string $id): int
{
    return (int) DB::table('envoi_campagnes')->where('id', $id)->value('version');
}

function afcAllumer(string $ws): void
{
    OriginesJeu::allumer();
    OriginesJeu::ouvrir($ws);
}

test('1 porte ouverte : 200, deux colonnes posées dans UNE seule version, GET rend cible.origine depuis les colonnes', function () {
    afcAllumer($this->ws->id);
    [$id, $v] = afcBrouillon($this);
    $r = afcEtape1($this, $id, $v, ['origine' => ['reseaux' => ['linkedin'], 'mots' => [' alpha ', 'beta', 'alpha'], 'guides' => ['guide-1']]])->assertOk();
    expect(afcCol($id, 'filtre_origine'))->toBe(['reseaux' => ['linkedin']])
        ->and(afcCol($id, 'filtre_origine_contenu'))->toBe(['mots' => ['alpha', 'beta'], 'guides' => ['guide-1']])
        ->and(afcVersion($id))->toBe($v + 1)
        ->and($r->json('contenu.cible.origine'))->toBe(['reseaux' => ['linkedin'], 'mots' => ['alpha', 'beta'], 'guides' => ['guide-1']]);
    $g = $this->actingAs($this->owner)->getJson(AFC . "/{$id}")->assertOk();
    expect($g->json('contenu.cible.origine'))->toBe(['reseaux' => ['linkedin'], 'mots' => ['alpha', 'beta'], 'guides' => ['guide-1']])
        ->and(ContratOpenApiEnvoi::ecarts('creation.yaml', 'Brouillon', $g->json()))->toBe([]);
});

test('2 mots seuls : filtre_origine NULL, contenu posé ; remplacer par un réseau seul efface le contenu', function () {
    afcAllumer($this->ws->id);
    [$id, $v] = afcBrouillon($this);
    $v = (int) afcEtape1($this, $id, $v, ['origine' => ['mots' => ['alpha']]])->assertOk()->json('version');
    expect(afcCol($id, 'filtre_origine'))->toBeNull()->and(afcCol($id, 'filtre_origine_contenu'))->toBe(['mots' => ['alpha']]);
    afcEtape1($this, $id, $v, ['origine' => ['reseaux' => ['linkedin']]])->assertOk();
    expect(afcCol($id, 'filtre_origine'))->toBe(['reseaux' => ['linkedin']])->and(afcCol($id, 'filtre_origine_contenu'))->toBeNull();
});

test('3 porte fermée et mots / guides présents : 422 filtre_origine_inactif, rien écrit, version inchangée', function () {
    [$id, $v] = afcBrouillon($this);
    foreach ([['mots' => ['alpha']], ['guides' => ['guide-1']], ['reseaux' => ['linkedin'], 'mots' => ['alpha']]] as $origine) {
        afcEtape1($this, $id, $v, ['origine' => $origine])->assertStatus(422)->assertJsonPath('error', 'filtre_origine_inactif');
        expect(afcCol($id, 'filtre_origine'))->toBeNull()->and(afcCol($id, 'filtre_origine_contenu'))->toBeNull()->and(afcVersion($id))->toBe($v);
    }
    // espace B ouvert, espace A fermé : refus dans A
    OriginesJeu::allumer();
    $b = $this->espace(true);
    OriginesJeu::ouvrir($b->id);
    afcEtape1($this, $id, $v, ['origine' => ['guides' => ['guide-1']]])->assertStatus(422)->assertJsonPath('error', 'filtre_origine_inactif');
    expect(afcCol($id, 'filtre_origine_contenu'))->toBeNull()->and(afcVersion($id))->toBe($v);
});

test('4 origine: null porte fermée : 200, les DEUX colonnes à NULL ; clé absente : inchangé', function () {
    afcAllumer($this->ws->id);
    [$id, $v] = afcBrouillon($this);
    $v = (int) afcEtape1($this, $id, $v, ['origine' => ['reseaux' => ['linkedin'], 'mots' => ['alpha']]])->assertOk()->json('version');
    $v = (int) afcEtape1($this, $id, $v, [])->assertOk()->json('version');
    expect(afcCol($id, 'filtre_origine'))->toBe(['reseaux' => ['linkedin']])->and(afcCol($id, 'filtre_origine_contenu'))->toBe(['mots' => ['alpha']]);
    config(['origines.lecture_enabled' => false]);
    $r = afcEtape1($this, $id, $v, ['origine' => null])->assertOk();
    expect(afcCol($id, 'filtre_origine'))->toBeNull()->and(afcCol($id, 'filtre_origine_contenu'))->toBeNull()->and($r->json('contenu.cible.origine'))->toBeNull();
});

test('5 formes invalides : 422 validation, rien écrit', function () {
    afcAllumer($this->ws->id);
    [$id, $v] = afcBrouillon($this);
    $trop = array_map(fn ($i) => "m{$i}", range(1, 11));
    foreach ([['mots' => $trop], ['mots' => ['Alpha']], ['mots' => ['x@example.test']], ['mots' => []], ['mots' => 'alpha'], ['guides' => [1]], ['guides' => $trop],
        ['mots' => ['alpha'], 'plus' => 1], ['reseaux' => ['LinkedIn'], 'mots' => ['alpha']], [], 'alpha'] as $i => $origine) {
        afcEtape1($this, $id, $v, ['origine' => $origine])->assertStatus(422)->assertJsonPath('error', 'validation');
        expect(afcCol($id, 'filtre_origine'))->toBeNull("forme #{$i}")->and(afcCol($id, 'filtre_origine_contenu'))->toBeNull("forme #{$i}")->and(afcVersion($id))->toBe($v);
    }
});

test('6 saisie seule sans colonne : presenter rend origine null et aucun contrôle de filtre', function () {
    afcAllumer($this->ws->id);
    [$id, $v] = afcBrouillon($this);
    $saisie = ['contenu' => ['cible' => ['type' => 'liste', 'reference' => '1', 'libelle' => 'L', 'origine' => ['mots' => ['alpha']]]], 'etapes_faites' => [1]];
    DB::table('envoi_campagnes')->where('id', $id)->update(['saisie' => json_encode($saisie), 'version' => $v + 1]);
    $g = $this->actingAs($this->owner)->getJson(AFC . "/{$id}")->assertOk();
    expect(afcCol($id, 'filtre_origine_contenu'))->toBeNull()->and($g->json('contenu.cible.origine'))->toBeNull();
    $c = array_column($this->actingAs($this->owner)->getJson(AFC . "/{$id}/controles")->assertOk()->json('controles'), 'etat', 'code');
    expect($c)->not->toHaveKey('filtre_origine');
});

test('7 contrôles : le contrôle filtre_origine couvre la colonne de contenu ; bloquant si la porte se ferme', function () {
    afcAllumer($this->ws->id);
    [$id, $v] = afcBrouillon($this);
    afcEtape1($this, $id, $v, ['origine' => ['mots' => ['alpha'], 'guides' => ['guide-1']]])->assertOk();
    $par = array_column($this->actingAs($this->owner)->getJson(AFC . "/{$id}/controles")->assertOk()->json('controles'), null, 'code');
    expect($par['filtre_origine']['etat'])->toBe('ok')->and($par['filtre_origine']['detail'])->toContain('alpha')->toContain('guide-1')
        ->and($par['filtre_origine_estimation']['etat'])->toBe('attention')
        ->and(ContratOpenApiEnvoi::ecarts('creation.yaml', 'Controle', $par['filtre_origine']))->toBe([]);
    config(['origines.lecture_enabled' => false]);
    $par = array_column($this->actingAs($this->owner)->getJson(AFC . "/{$id}/controles")->assertOk()->json('controles'), null, 'code');
    expect($par['filtre_origine']['etat'])->toBe('bloquant');
    $this->actingAs($this->owner)->postJson(AFC . "/{$id}/validation/code")->assertStatus(422)->assertJsonPath('error', 'controles_bloquants');
});

test('8 IDOR : campagne d\'un autre espace ⇒ 404, rien écrit', function () {
    afcAllumer($this->ws->id);
    [$id, $v] = afcBrouillon($this);
    $b = $this->espace(true);
    OriginesJeu::ouvrir($b->id);
    $autre = $this->compte($b, 'owner');
    afcEtape1($this, $id, $v, ['origine' => ['mots' => ['alpha']]], $autre)->assertNotFound();
    expect(afcCol($id, 'filtre_origine_contenu'))->toBeNull()->and(afcVersion($id))->toBe($v);
});

test('9 DepotCampagnes::modifier : écrit en modes app et proprio ; reliée = CampagneRefusee, jamais d\'erreur brute', function () {
    $ws = $this->ws->id;
    foreach (['app', 'proprio'] as $mode) {
        $id = app(DepotCampagnes::class)->creer($ws, 'Campagne ' . $mode, ['type' => 'liste', 'reference' => '1', 'libelle' => 'L'], [], 1, [], 'le fondateur');
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $n = app(DepotCampagnes::class)->modifier($ws, $id, 1, ['filtre_origine' => ['reseaux' => ['linkedin']], 'filtre_origine_contenu' => ['mots' => ['beta', 'alpha']]]);
        $n = app(DepotCampagnes::class)->modifier($ws, $id, $n, ['filtre_origine_contenu' => null]);
        app(DepotCampagnes::class)->modifier($ws, $id, $n, ['filtre_origine_contenu' => ['guides' => ['g']]]);
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
        expect(afcCol($id, 'filtre_origine_contenu'))->toBe(['guides' => ['g']], $mode)->and(afcCol($id, 'filtre_origine'))->toBe(['reseaux' => ['linkedin']]);
        foreach ([['mots' => []], ['mots' => ['Alpha']], 'x', ['mots' => ['a'], 'z' => 1], []] as $mauvais) {
            expect(fn () => app(DepotCampagnes::class)->modifier($ws, $id, afcVersion($id), ['filtre_origine_contenu' => $mauvais]))->toThrow(CampagneRefusee::class);
        }
    }
    $id = app(DepotCampagnes::class)->creer($ws, 'Reliée', ['type' => 'liste', 'reference' => '1', 'libelle' => 'L'], [], 1, [], 'le fondateur');
    $seq = (string) Str::uuid();
    DB::statement('SET session_replication_role = replica');
    DB::table('envoi_sequences')->insert(['id' => $seq, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);
    DB::table('envoi_campagnes')->where('id', $id)->update(['sequence_id' => $seq]);
    DB::statement('SET session_replication_role = origin');
    expect(fn () => app(DepotCampagnes::class)->modifier($ws, $id, 1, ['filtre_origine_contenu' => ['mots' => ['alpha']]]))->toThrow(CampagneRefusee::class);
    expect(afcCol($id, 'filtre_origine_contenu'))->toBeNull();
});

test('10 contrat : creation.yaml décrit mots et guides ; le .d.ts suit le YAML', function () {
    $yaml = (string) file_get_contents(base_path('openapi/envoi/creation.yaml'));
    $dts = (string) file_get_contents(base_path('../frontend/src/features/envoi/api/creation.d.ts'));
    expect($yaml)->toContain('mots:')->and($yaml)->toContain('guides:')
        ->and($dts)->toContain('mots?: string[]')->and($dts)->toContain('guides?: string[]');
});

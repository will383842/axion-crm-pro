<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Campagnes\RaisonBlocage;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;

/**
 * B8-D — audience demandée toujours présente dans les cibles ; capacités de l'assistant lues des refus du serveur.
 * Préfixe `cau`. Chaque test est joué sous le rôle applicatif (RLS) ET sous le propriétaire.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const CAU_CIBLES = '/api/v1/envoi/creation/cibles';
const CAU_CONTEXTE = '/api/v1/envoi/creation/contexte';

beforeEach(function () {
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
});

function cauSous(string $mode, string $ws, \Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

function cauAudience(string $ws, string $nom, array $plus = []): int
{
    DB::statement('SET session_replication_role = replica');
    try {
        return (int) DB::table('email_audiences')->insertGetId($plus + ['workspace_id' => $ws, 'name' => $nom, 'member_count' => 3]);
    } finally {
        DB::statement('SET session_replication_role = origin');
    }
}

function cauGet($t, string $mode, string $url)
{
    return cauSous($mode, $t->ws->id, fn () => $t->actingAs($t->owner)->getJson($url));
}

/** @return list<string> références des cibles de type audience, dans l'ordre */
function cauRefs($r): array
{
    return array_values(array_map(fn ($c) => $c['reference'], array_filter($r->json('data'), fn ($c) => $c['type'] === 'audience')));
}

test('cau 1 : 60 audiences, la 60e par nom demandée ⇒ présente, en tête, une seule fois', function (string $mode) {
    $ids = [];
    for ($i = 1; $i <= 60; $i++) {
        $ids[$i] = cauAudience($this->ws->id, sprintf('Audience %02d', $i));
    }
    $sans = cauGet($this, $mode, CAU_CIBLES)->assertOk();
    expect(cauRefs($sans))->toHaveCount(50)->and(cauRefs($sans))->not->toContain((string) $ids[60]);

    $r = cauGet($this, $mode, CAU_CIBLES . '?audience=' . $ids[60])->assertOk();
    $refs = cauRefs($r);
    expect($refs[0])->toBe((string) $ids[60])->and(array_count_values($refs)[(string) $ids[60]])->toBe(1);
    // elle est aussi la toute première cible de la réponse
    expect($r->json('data.0.reference'))->toBe((string) $ids[60])->and($r->json('data.0.type'))->toBe('audience');
})->with(['app', 'proprio']);

test('cau 2 : audience d\'un autre espace, supprimée ou inactive ⇒ absente, 200', function (string $mode) {
    $autre = $this->espace(true);
    $etrangere = cauAudience($autre->id, 'Étrangère');
    $supprimee = cauAudience($this->ws->id, 'Supprimée', ['deleted_at' => now()]);
    $inactive = cauAudience($this->ws->id, 'Inactive', ['is_active' => false]);
    foreach ([$etrangere, $supprimee, $inactive] as $id) {
        $r = cauGet($this, $mode, CAU_CIBLES . '?audience=' . $id)->assertOk();
        expect(cauRefs($r))->not->toContain((string) $id);
    }
})->with(['app', 'proprio']);

test('cau 3 : audience non numérique ou trop longue ⇒ ignorée, 200', function (string $mode) {
    $id = cauAudience($this->ws->id, 'Ordinaire');
    foreach (['abc', '1e3', str_repeat('9', 18), str_repeat('1', 40), '-1'] as $v) {
        $r = cauGet($this, $mode, CAU_CIBLES . '?audience=' . $v)->assertOk();
        expect(cauRefs($r))->toBe([(string) $id]);
    }
})->with(['app', 'proprio']);

test('cau 4 : capacités toutes fausses, lues des refus du serveur', function (string $mode) {
    $c = cauGet($this, $mode, CAU_CONTEXTE)->assertOk();
    expect($c->json('capacites'))->toBe(['ab' => false, 'boites' => false, 'plafond' => false])
        ->and($c->json())->toHaveKeys(['maintenant', 'fuseau']);
    // le lien : les valeurs suivent la constante que lit aussi la matérialisation
    expect($c->json('capacites.boites'))->toBe(RaisonBlocage::REGLAGES_APPLIQUES)
        ->and($c->json('capacites.plafond'))->toBe(RaisonBlocage::REGLAGES_APPLIQUES);
    // et le prédicat partagé avec la matérialisation refuse bien des boîtes tant que la constante est fausse
    $c1 = (object) ['saisie' => '{"contenu":{}}', 'boites' => '{"00000000-0000-0000-0000-000000000001"}'];
    expect(RaisonBlocage::reglageNonApplique($c1))->toBe(! RaisonBlocage::REGLAGES_APPLIQUES);
})->with(['app', 'proprio']);

test('cau 5 : droits inchangés — sans users.manage ⇒ 403 sur cibles et contexte', function () {
    $lecteur = $this->compte($this->ws, 'viewer');
    $this->actingAs($lecteur)->getJson(CAU_CIBLES)->assertForbidden();
    $this->actingAs($lecteur)->getJson(CAU_CONTEXTE)->assertForbidden();
});

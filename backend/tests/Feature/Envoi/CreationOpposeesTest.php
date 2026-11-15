<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Conformite\RegistreOppositionManuel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;

/**
 * G4-ENVOI (D14) — une adresse opposée n'est jamais sélectionnable pour un envoi : estimation, exemples, 422.
 * Adresses en `*.example.test` uniquement (dépôt PUBLIC). Aucun envoi réel.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const OPP_BROUILLONS = '/api/v1/envoi/brouillons';

beforeEach(function () {
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
});

function oppHash(string $v): string
{
    return hash('sha256', mb_strtolower(trim($v)));
}

/** 10 contacts dans une liste ; 3 sont opposés (STOP, registre posé par la classe de R13, art. 21). Rend [liste, ids des opposés, ids propres]. */
function oppListe($t, int $nb = 10): array
{
    $ws = $t->ws->id;
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . Str::random(5)]);
    $ids = [];
    DB::statement('SET session_replication_role = replica');
    for ($i = 1; $i <= $nb; $i++) {
        $id = (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => 900 + $i, 'first_name' => "Prenom{$i}", 'last_name' => "Nom{$i}", 'email' => "p{$i}@opp.example.test"]);
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'contact_id' => $id, 'origine' => 'coche']);
        $ids[$i] = $id;
    }
    DB::statement('SET session_replication_role = origin');

    $lien = DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $ws, 'empreinte_adresse' => oppHash('p1@opp.example.test'), 'portee' => 'business']);
    DB::table('envoi_oppositions')->insert(['workspace_id' => $ws, 'lien_id' => $lien, 'empreinte_adresse' => oppHash('p1@opp.example.test'), 'canal' => 'one_click']);
    (new RegistreOppositionManuel)->inscrire($ws, 'adresse', 'p2@opp.example.test', 'test', 'test');
    DB::table('opt_out')->insert(['email_hash' => oppHash('p3@opp.example.test'), 'scope' => 'business', 'source' => 'gdpr_art21_console', 'reason' => 'gdpr_art21']);

    return [$liste, [$ids[1], $ids[2], $ids[3]], array_slice($ids, 3)];
}

function oppBrouillon($t): array
{
    $r = $t->actingAs($t->owner)->postJson(OPP_BROUILLONS, ['nom' => 'Campagne opposées'])->assertCreated();

    return [$r->json('id'), (int) $r->json('version')];
}

test('estimation : 3 opposées sur 10 ⇒ exclus = 3 et exclusions.opposition = 3 ; exclusions_evaluees reste faux', function () {
    [$liste] = oppListe($this);
    [$id] = oppBrouillon($this);
    $r = $this->actingAs($this->owner)->postJson(OPP_BROUILLONS . "/{$id}/cible/estimation", ['type' => 'liste', 'reference' => (string) $liste])->assertOk();

    expect($r->json('total'))->toBe(10)
        ->and($r->json('exclus'))->toBe(3)
        ->and($r->json('restant'))->toBe(7)
        ->and($r->json('exclusions.opposition'))->toBe(3)
        ->and($r->json('exclusions_evaluees'))->toBeFalse();
});

test('estimation : aucune opposée ⇒ exclus = 0', function () {
    $ws = $this->ws->id;
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Propre']);
    DB::statement('SET session_replication_role = replica');
    $c = (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => 1, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'propre@opp.example.test']);
    DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'contact_id' => $c, 'origine' => 'coche']);
    DB::statement('SET session_replication_role = origin');
    [$id] = oppBrouillon($this);
    $r = $this->actingAs($this->owner)->postJson(OPP_BROUILLONS . "/{$id}/cible/estimation", ['type' => 'liste', 'reference' => (string) $liste])->assertOk();

    expect($r->json('total'))->toBe(1)->and($r->json('exclus'))->toBe(0)->and($r->json('restant'))->toBe(1);
});

test('exemples : une adresse opposée n\'est jamais proposée', function () {
    [, $opposes, $propres] = oppListe($this);
    $r = $this->actingAs($this->owner)->getJson('/api/v1/envoi/creation/destinataires-exemples')->assertOk();
    $vus = array_map('intval', array_column($r->json('data'), 'id'));

    expect($vus)->toHaveCount(3)->and(array_intersect($vus, $opposes))->toBe([])->and(array_diff($vus, $propres))->toBe([]);
});

test('identifiant opposé passé explicitement ⇒ 422 adresse_opposee (étape cible) ; un identifiant propre passe', function () {
    [$liste, $opposes, $propres] = oppListe($this);
    [$id, $v] = oppBrouillon($this);
    $cible = fn (array $contacts) => ['cible' => ['type' => 'liste', 'reference' => (string) $liste, 'contact_ids' => $contacts]];
    $put = fn (array $corps, int $v) => $this->actingAs($this->owner)->putJson(OPP_BROUILLONS . "/{$id}/etapes/1", $corps, ['If-Match' => (string) $v]);

    foreach ($opposes as $o) {
        $put($cible([$propres[0], $o]), $v)->assertStatus(422)->assertJsonPath('error', 'adresse_opposee');
    }
    // rien n'a été enregistré : la version n'a pas bougé
    $put($cible([$propres[0], $propres[1]]), $v)->assertOk();
});

test('garde avec témoin : la réponse ne dit pas pourquoi (aucun motif par personne)', function () {
    [$liste, $opposes, $propres] = oppListe($this);
    [$id, $v] = oppBrouillon($this);
    $interdits = ['stop', 'art21', 'liste_refus', 'motif', 'opposee'];
    $fuite = static fn (string $corps): bool => array_filter($interdits, static fn (string $m): bool => str_contains(strtolower($corps), $m)) !== [];

    $est = $this->actingAs($this->owner)->postJson(OPP_BROUILLONS . "/{$id}/cible/estimation", ['type' => 'liste', 'reference' => (string) $liste])->assertOk();
    $refus = $this->actingAs($this->owner)->putJson(OPP_BROUILLONS . "/{$id}/etapes/1", ['cible' => ['type' => 'liste', 'reference' => (string) $liste, 'contact_ids' => [$opposes[0]]]], ['If-Match' => (string) $v])->assertStatus(422);
    $ex = $this->actingAs($this->owner)->getJson('/api/v1/envoi/creation/destinataires-exemples')->assertOk();

    expect($fuite($est->getContent()))->toBeFalse()
        ->and($fuite(str_replace('adresse_opposee', '', $refus->getContent())))->toBeFalse()
        ->and($fuite($ex->getContent()))->toBeFalse()
        // témoin : une réponse qui ajoute le motif fait rougir la garde
        ->and($fuite(json_encode(['exclusions' => ['opposition' => 3], 'motifs' => ['liste_refus']])))->toBeTrue();
});

test('cloisonnement : un domaine du registre d\'un autre espace n\'exclut rien ici', function () {
    [$liste] = oppListe($this);
    $autre = $this->espace(true);
    (new RegistreOppositionManuel)->inscrire($autre->id, 'domaine', 'opp.example.test', 'test', 'test');
    [$id] = oppBrouillon($this);
    $r = $this->actingAs($this->owner)->postJson(OPP_BROUILLONS . "/{$id}/cible/estimation", ['type' => 'liste', 'reference' => (string) $liste])->assertOk();

    expect($r->json('exclus'))->toBe(3);
});

test('lecture seule : l\'estimation n\'écrit rien (ni opposition, ni file vers le site)', function () {
    [$liste] = oppListe($this);
    [$id] = oppBrouillon($this);
    $avant = [DB::table('envoi_oppositions')->count(), DB::table('envoi_oppositions_registre')->count(), DB::table('opt_out')->count(), DB::table('email_suppressions')->count()];
    $this->actingAs($this->owner)->postJson(OPP_BROUILLONS . "/{$id}/cible/estimation", ['type' => 'liste', 'reference' => (string) $liste])->assertOk();

    expect([DB::table('envoi_oppositions')->count(), DB::table('envoi_oppositions_registre')->count(), DB::table('opt_out')->count(), DB::table('email_suppressions')->count()])->toBe($avant);
});

<?php

namespace Tests\Feature\Envoi\Conformite;

use App\Crm\FichesProtegees;
use App\Envoi\Desinscription\EnregistrerOpposition;
use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\Support\DoublonsFixtures as F;

/**
 * R05b — GET /envoi/puis-je-ecrire?adresse= : `peut` + `motif` codé regroupé, rien d'autre. Aucun oracle « contact connu ».
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const R05P = '/api/v1/envoi/puis-je-ecrire';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-16 09:00:00', 'UTC'));
    $this->espace = $this->espace(true);
    $this->admin = $this->compte($this->espace, 'admin');
});

afterEach(fn () => Carbon::setTestNow());

/** Espace en règle pour écrire à $adresse : domaine d'envoi, contact vérifié, article 14 donné. */
function r05pFicheEnRegle(object $t, string $adresse): void
{
    FonctionsEspace::regler($t->espace->id, 'federations', true);
    $maintenant = Carbon::now()->format('c');
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $t->espace->id, 'nom' => 'zz-envoi.example.invalid', 'achete_le' => '2026-01-01',
        'dmarc_verifie_le' => $maintenant, 'spf_verifie_le' => $maintenant, 'dkim_verifie_le' => $maintenant,
    ]);
    $e = F::fiche($t->espace->id, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => Carbon::now()->subDays(3)->format('c')]);
    F::lier($t->espace->id, $e, F::tag($t->espace->id, FichesProtegees::TAG_FEDERATIONS));
    F::contact($t->espace->id, $e, 'P', 'Dupont', [
        'email' => $adresse, 'email_status' => 'valid',
        'created_at' => Carbon::now()->subDays(3)->format('c'), 'first_info_at' => Carbon::now()->subDays(3)->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $t->espace->id, 'empreinte_adresse' => ListeSuppression::empreinte($adresse),
        'verdict' => 'valide', 'verifie_le' => Carbon::now()->subDays(2)->format('c'), 'source' => 'test',
    ]);
}

test('sans session 401 ; sans produit envoi 404 ; 403 pour operator et viewer', function () {
    $this->getJson(R05P . '?adresse=a@zz.example.invalid')->assertUnauthorized();
    $this->actingAs($this->compte($this->espace(false), 'viewer'))->getJson(R05P . '?adresse=a@zz.example.invalid')->assertNotFound();
    foreach (['operator', 'viewer'] as $role) {
        $this->actingAs($this->compte($this->espace, $role))->getJson(R05P . '?adresse=a@zz.example.invalid')->assertForbidden();
    }
});

test('adresse absente ou mal formée : 422', function (string $q) {
    $this->actingAs($this->admin)->getJson(R05P . $q)->assertUnprocessable();
})->with(['', '?adresse=', '?adresse=pas-une-adresse', '?adresse[]=a@b.example.invalid']);

test('adresse en règle : peut vrai, motif null, et RIEN d’autre dans la réponse', function () {
    r05pFicheEnRegle($this, 'ok@zz-client.example.invalid');
    $r = $this->actingAs($this->admin)->getJson(R05P . '?adresse=OK@zz-client.example.invalid')->assertOk();
    expect($r->json())->toBe(['peut' => true, 'motif' => null])
        ->and(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'ReponsePuisJeEcrire', $r->json()))->toBe([]);
});

test('opposition de l’espace : peut faux, motif « opposition »', function () {
    $ref = InscriptionsEnvoi::creer($this->espace->id, 'non@zz-client.example.invalid');
    app(EnregistrerOpposition::class)->executer($this->espace->id, $ref, 'page');
    $r = $this->actingAs($this->admin)->getJson(R05P . '?adresse=non@zz-client.example.invalid')->assertOk();
    expect($r->json())->toBe(['peut' => false, 'motif' => 'opposition'])
        ->and(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'ReponsePuisJeEcrire', $r->json()))->toBe([]);
});

test('motifs regroupés : un contact écarté ou une condition d’envoi non remplie ne dévoile que le groupe', function () {
    r05pFicheEnRegle($this, 'ecarte@zz-client.example.invalid');
    DB::table('contacts')->where('email', 'ecarte@zz-client.example.invalid')->update(['email_status' => 'invalid']);
    DB::table('envoi_verdicts_adresse')->where('workspace_id', $this->espace->id)->update(['verdict' => 'invalide']);
    $r = $this->actingAs($this->admin)->getJson(R05P . '?adresse=ecarte@zz-client.example.invalid')->assertOk()->json();
    expect($r['peut'])->toBeFalse()->and($r['motif'])->toBeIn(['contact_ecarte', 'conditions_envoi', 'adresse_non_verifiee', 'opposition'])
        ->and(array_keys($r))->toBe(['peut', 'motif']);
});

test('PAS D’ORACLE : adresse inconnue et adresse d’un AUTRE espace donnent une réponse strictement identique', function () {
    $autre = $this->espace(true);
    $autreAdmin = $this->compte($autre, 'admin');
    // L'autre espace connaît l'adresse, l'a vérifiée, et la tient même en opposition.
    $f = F::fiche($autre->id, 'Fiche autre', ['country_code' => 'FR']);
    F::contact($autre->id, $f, 'Z', 'Autre', ['email' => 'zoe@zz-autre.example.invalid', 'email_status' => 'valid']);
    DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $autre->id, 'empreinte_adresse' => ListeSuppression::empreinte('zoe@zz-autre.example.invalid'), 'verdict' => 'valide', 'verifie_le' => now()->subDay()->format('c'), 'source' => 'test']);
    $ref = InscriptionsEnvoi::creer($autre->id, 'zoe@zz-autre.example.invalid');
    app(EnregistrerOpposition::class)->executer($autre->id, $ref, 'page');

    $chezAutre = $this->actingAs($autreAdmin)->getJson(R05P . '?adresse=zoe@zz-autre.example.invalid')->assertOk()->json();
    expect($chezAutre['motif'])->toBe('opposition'); // témoin : chez l'autre espace la réponse est bien « opposition »

    $inconnue = $this->actingAs($this->admin)->getJson(R05P . '?adresse=jamais-vue@zz-nulle-part.example.invalid');
    $autreEspace = $this->actingAs($this->admin)->getJson(R05P . '?adresse=zoe@zz-autre.example.invalid');
    expect($autreEspace->getStatusCode())->toBe($inconnue->getStatusCode())
        ->and($autreEspace->getContent())->toBe($inconnue->getContent())
        ->and($autreEspace->json())->not->toBe($chezAutre);
});

test('l’adresse n’apparaît jamais en clair dans les journaux ni dans le journal d’audit', function () {
    $vus = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$vus) {
        $vus[] = $m->message . json_encode($m->context);
    });
    r05pFicheEnRegle($this, 'secret@zz-client.example.invalid');
    $this->actingAs($this->admin)->getJson(R05P . '?adresse=secret@zz-client.example.invalid')->assertOk();
    $this->actingAs($this->admin)->getJson(R05P . '?adresse=secret@zz-client.example.invalid&x=1')->assertOk();
    expect(implode('|', $vus))->not->toContain('secret@')->not->toContain('zz-client');
    $audit = DB::table('audit_logs')->get()->map(fn ($x) => json_encode($x))->implode('|');
    expect($audit)->not->toContain('secret@')->and($audit)->not->toContain('secret%40');
});

test('limite par minute : au-delà, 429', function () {
    $dernier = null;
    for ($i = 0; $i < 31; $i++) {
        $dernier = $this->actingAs($this->admin)->getJson(R05P . '?adresse=n' . $i . '@zz-client.example.invalid');
        if ($dernier->getStatusCode() === 429) {
            break;
        }
    }
    expect($dernier->getStatusCode())->toBe(429)->and($i)->toBe(30);
});

test('lecture seule : aucune écriture dans les drapeaux, aucun ServeurEnvoi, route en GET seulement', function () {
    r05pFicheEnRegle($this, 'ok@zz-client.example.invalid');
    $d = DB::table('envoi_drapeaux')->count();
    $o = DB::table('envoi_oppositions')->count();
    $this->actingAs($this->admin)->getJson(R05P . '?adresse=ok@zz-client.example.invalid')->assertOk();
    expect(DB::table('envoi_drapeaux')->count())->toBe($d)->and(DB::table('envoi_oppositions')->count())->toBe($o)->and($this->resolutions)->toBe(0);
    $this->actingAs($this->admin)->postJson(R05P)->assertStatus(405);
});

<?php

namespace Tests\Feature\Envoi\Conformite;

use App\Envoi\Desinscription\EnregistrerOpposition;
use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\Support\DoublonsFixtures as F;

/**
 * R05a — conformité en LECTURE : GET /envoi/desinscriptions et GET /envoi/opposition.
 * Données fictives (.test, .example.invalid). Aucune suppression nulle part.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const R05_CLE = 'cle-de-test-seulement-0123456789';

/** Inscrit une opposition par lien dans l'espace ; renvoie la référence du lien. */
function r05Opposer(Workspace $espace, string $adresse, ?int $contactId = null, string $canal = 'page'): string
{
    $ref = InscriptionsEnvoi::creer($espace->id, $adresse, 'business', $contactId);
    app(EnregistrerOpposition::class)->executer($espace->id, $ref, $canal);

    return $ref;
}

beforeEach(function () {
    config(['envoi.cle_desinscription' => R05_CLE, 'envoi.desinscription_validite_jours' => 30]);
    $this->espace = $this->espace(true);
    $this->admin = $this->compte($this->espace, 'admin');
});

test('sans session : 401 ; sans produit envoi : 404 (avant toute permission)', function (string $uri) {
    $this->getJson($uri)->assertUnauthorized();
    $sans = $this->compte($this->espace(false), 'viewer');
    $this->actingAs($sans)->getJson($uri)->assertNotFound();
})->with(['/api/v1/envoi/desinscriptions', '/api/v1/envoi/opposition']);

test('403 sans users.manage (operator et viewer)', function (string $role, string $uri) {
    $this->actingAs($this->compte($this->espace, $role))->getJson($uri)->assertForbidden();
})->with([['operator'], ['viewer']])->with(['/api/v1/envoi/desinscriptions', '/api/v1/envoi/opposition']);

test('desinscriptions : réponse conforme au contrat, sans empreinte ni adresse en clair quand la fiche manque', function () {
    $ref = r05Opposer($this->espace, 'inconnu@zz-r05.example.invalid');
    $json = $this->actingAs($this->admin)->getJson('/api/v1/envoi/desinscriptions')->assertOk()->json();

    expect($json['data'])->toHaveCount(1)
        ->and($json['data'][0]['adresse'])->toBeNull()
        ->and($json['data'][0]['adresse_masquee'])->toBeString()->not->toBe('')
        ->and($json['data'][0]['source'])->toBe('lien')
        ->and($json['data'][0]['campagne_id'])->toBeNull()
        ->and($json['meta'])->toMatchArray(['page' => 1, 'total' => 1, 'pages' => 1]);
    $brut = json_encode($json);
    expect($brut)->not->toContain('inconnu@zz-r05')
        ->and($brut)->not->toContain(hash('sha256', 'inconnu@zz-r05.example.invalid'))
        ->and($brut)->not->toContain($ref);
    foreach ($json['data'] as $ligne) {
        expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'Desinscription', $ligne))->toBe([]);
    }
    $dossier = ContratOpenApiEnvoi::ecarts('commun.yaml', 'Pagination', $json['meta']);
    expect($dossier)->toBe([]);
});

test('desinscriptions : l’adresse n’est retrouvée que par la fiche du CRM de CET espace (et seulement avec contacts.view_pii)', function () {
    $ficheId = F::fiche($this->espace->id, 'Boulangerie Fictive');
    $contact = F::contact($this->espace->id, $ficheId, 'Paul', 'Test', ['email' => 'paul@zz-r05.example.invalid']);
    r05Opposer($this->espace, 'paul@zz-r05.example.invalid', $contact, 'one_click');

    $admin = $this->actingAs($this->admin)->getJson('/api/v1/envoi/desinscriptions')->assertOk()->json('data.0');
    expect($admin['adresse'])->toBe('paul@zz-r05.example.invalid')
        ->and($admin['adresse_masquee'])->not->toContain('paul@')
        ->and($admin['adresse_masquee'])->toContain('zz-r05.example.invalid');
    expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'Desinscription', $admin))->toBe([]);

    // Un compte avec users.manage mais SANS contacts.view_pii ne reçoit jamais l'adresse en clair.
    $restreint = $this->compte($this->espace, 'viewer');
    setPermissionsTeamId($this->espace->id);
    $restreint->givePermissionTo('users.manage');
    expect($restreint->can('contacts.view_pii'))->toBeFalse();
    $lue = $this->actingAs($restreint)->getJson('/api/v1/envoi/desinscriptions')->assertOk()->json('data.0');
    expect($lue['adresse'])->toBeNull()->and($lue['adresse_masquee'])->toContain('zz-r05.example.invalid');
    $this->actingAs($restreint)->getJson('/api/v1/envoi/desinscriptions?q=paul')->assertOk()->assertJsonPath('meta.total', 0);
});

test('isolement : un autre espace ne voit rien, et une fiche d’un autre espace ne révèle aucune adresse', function () {
    $autre = $this->espace(true);
    $ficheAutre = F::fiche($autre->id, 'Autre Fiche');
    $contactAutre = F::contact($autre->id, $ficheAutre, 'Zoe', 'Autre', ['email' => 'zoe@zz-autre.example.invalid']);
    r05Opposer($autre, 'zoe@zz-autre.example.invalid', $contactAutre);
    // Même identifiant de contact référencé depuis MON espace : la fiche de l'autre espace ne doit pas servir.
    r05Opposer($this->espace, 'x@zz-r05.example.invalid', $contactAutre);

    $mien = $this->actingAs($this->admin)->getJson('/api/v1/envoi/desinscriptions')->assertOk()->json();
    expect($mien['meta']['total'])->toBe(1)
        ->and($mien['data'][0]['adresse'])->toBeNull()
        ->and(json_encode($mien))->not->toContain('zoe@');
    $chezAutre = $this->actingAs($this->compte($autre, 'admin'))->getJson('/api/v1/envoi/desinscriptions')->assertOk()->json();
    expect($chezAutre['meta']['total'])->toBe(1)->and($chezAutre['data'][0]['adresse'])->toBe('zoe@zz-autre.example.invalid');
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/opposition')->assertOk()->assertJsonPath('resume.total', 1);
});

test('une visite GET de la page de désinscription, sans POST, n’apparaît dans aucune liste', function () {
    $ref = InscriptionsEnvoi::creer($this->espace->id, 'robot@zz-r05.example.invalid');
    $jeton = JetonDesinscription::emettre($this->espace->id, $ref);
    $this->get('/desinscription/' . $jeton)->assertOk();

    $this->actingAs($this->admin)->getJson('/api/v1/envoi/desinscriptions')->assertOk()->assertJsonPath('meta.total', 0);
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/opposition')->assertOk()->assertJsonPath('resume.total', 0);
    $this->post('/desinscription/' . $jeton)->assertOk();
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/desinscriptions')->assertOk()->assertJsonPath('meta.total', 1);
});

test('pagination et recherche ; rien n’est jamais supprimé par la lecture', function () {
    for ($i = 1; $i <= 5; $i++) {
        $f = F::fiche($this->espace->id, "Fiche $i");
        $c = F::contact($this->espace->id, $f, 'P', "N$i", ['email' => "p$i@zz-r05.example.invalid"]);
        r05Opposer($this->espace, "p$i@zz-r05.example.invalid", $c);
    }
    $avant = DB::table('envoi_oppositions')->count();
    $p2 = $this->actingAs($this->admin)->getJson('/api/v1/envoi/desinscriptions?par_page=2&page=2')->assertOk()->json();
    expect($p2['data'])->toHaveCount(2)->and($p2['meta'])->toMatchArray(['page' => 2, 'par_page' => 2, 'total' => 5, 'pages' => 3]);
    $q = $this->actingAs($this->admin)->getJson('/api/v1/envoi/desinscriptions?q=p3@')->assertOk()->json();
    expect($q['data'])->toHaveCount(1)->and($q['data'][0]['adresse'])->toBe('p3@zz-r05.example.invalid');
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/desinscriptions?par_page=500')->assertUnprocessable();
    expect(DB::table('envoi_oppositions')->count())->toBe($avant);
});

test('opposition : lignes et résumé conformes au contrat, sans adresse ni empreinte', function () {
    r05Opposer($this->espace, 'a@zz-r05.example.invalid');
    r05Opposer($this->espace, 'b@zz-r05.example.invalid', null, 'one_click');
    $json = $this->actingAs($this->admin)->getJson('/api/v1/envoi/opposition')->assertOk()->json();

    expect($json['resume'])->toMatchArray(['total' => 2, 'ce_mois' => 2, 'ce_mois_par_lien' => 2])
        ->and($json['data'])->toHaveCount(2);
    foreach ($json['data'] as $ligne) {
        expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'Opposition', $ligne))->toBe([])
            ->and($ligne['portee'])->toBe('adresse')->and($ligne['source'])->toBe('lien');
    }
    expect(json_encode($json))->not->toContain('@zz-r05')->not->toContain(hash('sha256', 'a@zz-r05.example.invalid'));
});

test('aucune route de ce lot ne supprime, n’écrit ni ne touche aux drapeaux ou aux envois', function () {
    $uris = array_filter($this->routesEnvoi(), fn (array $r) => in_array($r[1], ['/api/v1/envoi/desinscriptions', '/api/v1/envoi/opposition'], true));
    foreach ($uris as [$methode, $uri]) {
        // R13 ajoute UNE écriture, et une seule, sur le registre : POST /envoi/opposition (couvert par OppositionManuelleTest).
        expect($methode)->toBe($methode === 'POST' && $uri === '/api/v1/envoi/opposition' ? 'POST' : 'GET');
    }
    expect($uris)->not->toBeEmpty();
    $drapeaux = DB::table('envoi_drapeaux')->count();
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/desinscriptions')->assertOk();
    $this->actingAs($this->admin)->getJson('/api/v1/envoi/opposition')->assertOk();
    expect(DB::table('envoi_drapeaux')->count())->toBe($drapeaux)->and($this->resolutions)->toBe(0);
});

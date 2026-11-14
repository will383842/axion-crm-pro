<?php

namespace Tests\Feature\Envoi\Routes;

use App\Envoi\ListesNoires\ResolveurDns as ResolveurListes;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ContratOpenApiEnvoi;

uses(BaseRoutesEnvoi::class)->group('envoi');

/** Marqueurs « secrets » semés en base : aucun ne doit jamais ressortir. */
const SECRETS_SEMES = ['TUNNEL-SECRET', 'IDENTIFIANT-CHIFFRE-XYZ', 'HOTE-SMTP-SECRET', 'MOTIF-INTERNE', 'CLE-DKIM-PRIVEE', '10.1.2.3'];

/** @return array{espace: Workspace, autre: Workspace, domaine: int, domaineAutre: int, serveur: int, serveurAutre: int} */
function semerInfra(object $t): array
{
    $nouvel = \Closure::bind(fn () => $this->espace(true), $t, BaseRoutesEnvoi::class);
    $espace = $nouvel();
    $autre = $nouvel();
    $ids = [];
    foreach (['espace' => $espace, 'autre' => $autre] as $cle => $ws) {
        WorkspaceContext::run($ws->id, function () use ($ws, $cle, &$ids) {
            $nom = $cle === 'espace' ? 'envoi-a.exemple.test' : 'envoi-b.exemple.test';
            $ids[$cle]['domaine'] = (int) DB::table('envoi_domaines')->insertGetId(['workspace_id' => $ws->id, 'nom' => $nom, 'achete_le' => '2026-10-01']);
            DB::table('envoi_boites')->insert([
                'workspace_id' => $ws->id, 'adresse' => 'a@' . $nom, 'hote' => 'HOTE-SMTP-SECRET.example.invalid', 'port' => 587,
                'identifiants_chiffres' => 'IDENTIFIANT-CHIFFRE-XYZ',
            ]);
            DB::table('envoi_controles_dns')->insert([
                'workspace_id' => $ws->id, 'domaine' => $nom, 'controle_le' => '2026-11-10 08:00:00+00', 'pret' => true, 'pause' => false,
                'resultats' => json_encode(['spf_present' => 'ok', 'dkim_present' => 'ok']),
            ]);
            DB::table('envoi_controles_listes')->insert([
                ['workspace_id' => $ws->id, 'type' => 'domaine', 'ressource' => $nom, 'statut' => 'liste', 'zone' => 'dbl.exemple.test', 'pause' => true, 'controle_le' => '2026-11-10 09:00:00+00'],
                ['workspace_id' => $ws->id, 'type' => 'ip', 'ressource' => '198.51.100.7', 'statut' => 'non_liste', 'zone' => 'zen.exemple.test', 'pause' => false, 'controle_le' => '2026-11-10 09:00:00+00'],
                ['workspace_id' => $ws->id, 'type' => 'ip', 'ressource' => '10.1.2.3', 'statut' => 'non_liste', 'zone' => 'zen.exemple.test', 'pause' => false, 'controle_le' => '2026-11-10 09:00:00+00'],
            ]);
            $ids[$cle]['serveur'] = (int) DB::table('envoi_serveurs')->insertGetId([
                'workspace_id' => $ws->id, 'source' => 'srv-' . $cle, 'url_base' => 'https://TUNNEL-SECRET.example.invalid:8443',
            ]);
            DB::table('envoi_config_versions')->insert([
                ['workspace_id' => $ws->id, 'empreinte' => str_repeat('a', 64), 'statut' => 'appliquee', 'motif' => 'MOTIF-INTERNE', 'cree_le' => '2026-11-09 10:00:00+00'],
                ['workspace_id' => $ws->id, 'empreinte' => str_repeat('b', 64), 'statut' => 'invalide', 'motif' => 'CLE-DKIM-PRIVEE', 'cree_le' => '2026-11-10 10:00:00+00'],
            ]);
        });
    }

    return ['espace' => $espace, 'autre' => $autre, 'domaine' => $ids['espace']['domaine'], 'domaineAutre' => $ids['autre']['domaine'],
        'serveur' => $ids['espace']['serveur'], 'serveurAutre' => $ids['autre']['serveur']];
}

/** @param list<string> $ecarts */
function sansEcart(array $ecarts): void
{
    expect($ecarts)->toBe([]);
}

function sansSecret(string $json): void
{
    foreach (SECRETS_SEMES as $secret) {
        expect($json)->not->toContain($secret);
    }
    expect(preg_match('/\b(10|127)\.\d+\.\d+\.\d+\b|\b192\.168\.|\b172\.(1[6-9]|2\d|3[01])\.|hmac|identifiants?_chiffres|url_base|BEGIN [A-Z ]*KEY|dkim_prive|"port"\s*:|"hote"|tunnel/i', $json))->toBe(0);
}

test('GET /envoi/domaines suit le contrat, sans secret, et ne montre que son espace', function () {
    $s = semerInfra($this);
    $r = $this->actingAs($this->compte($s['espace'], 'admin'))->getJson('/api/v1/envoi/domaines')->assertOk();
    $j = $r->json();
    expect($j['data'])->toHaveCount(1)->and($j['data'][0]['nom'])->toBe('envoi-a.exemple.test')
        ->and($j['data'][0]['boites'])->toBe(1)->and($j['data'][0]['listes_noires'])->toBe(1)
        ->and($j['data'][0]['etat'])->toBe('rouge')
        ->and($j['data'][0]['dmarc_prochaine_etape'])->toBeNull()->and($j['data'][0]['chauffe'])->toBeNull()
        ->and($j['garde_dispersion']['limite'])->toBeGreaterThanOrEqual(1);
    sansEcart(ContratOpenApiEnvoi::ecarts('commun.yaml', 'DomaineResume', $j['data'][0]));
    expect($j['expirations'])->toBe([]);
    sansSecret($r->getContent());
    expect($r->getContent())->not->toContain('envoi-b');
});

test('GET /envoi/domaines/{id} : fiche conforme, jamais celle d’un autre espace', function () {
    $s = semerInfra($this);
    $u = $this->compte($s['espace'], 'admin');
    $r = $this->actingAs($u)->getJson('/api/v1/envoi/domaines/' . $s['domaine'])->assertOk();
    $j = $r->json();
    sansEcart(ContratOpenApiEnvoi::ecarts('commun.yaml', 'DomaineResume', $j['domaine']));
    foreach ($j['listes_noires'] as $l) {
        sansEcart(ContratOpenApiEnvoi::ecarts('infrastructure.yaml', 'ListeNoire', $l));
    }
    expect($j['listes_noires'])->toHaveCount(1)->and($j['listes_noires'][0]['present'])->toBeTrue()
        ->and($j['dns'])->toBe([])->and($j['jours'])->toBe([])->and($j['expire_le'])->toBeNull();
    sansSecret($r->getContent());
    $this->actingAs($u)->getJson('/api/v1/envoi/domaines/' . $s['domaineAutre'])->assertNotFound();
    $this->actingAs($u)->getJson('/api/v1/envoi/domaines/pas-un-id')->assertNotFound();
});

test('GET /envoi/listes-noires : lecture en base seulement, jamais d’interrogation en direct', function () {
    $this->app->bind(ResolveurListes::class, fn () => throw new RuntimeException('Interrogation DNS en direct interdite.'));
    $s = semerInfra($this);
    $r = $this->actingAs($this->compte($s['espace'], 'owner'))->getJson('/api/v1/envoi/listes-noires')->assertOk();
    $j = $r->json();
    expect($j['data'])->toHaveCount(2);
    foreach ($j['data'] as $l) {
        sansEcart(ContratOpenApiEnvoi::ecarts('infrastructure.yaml', 'ListeNoire', $l));
    }
    expect(array_column($j['data'], 'liste'))->toContain('dbl.exemple.test');
    // Une IP publique surveillée est rendue ; une IP privée ne l'est jamais.
    expect(array_column($j['data'], 'element'))->toContain('198.51.100.7')->not->toContain('10.1.2.3');
    sansSecret($r->getContent());
});

test('GET /envoi/serveurs : nom et état seulement, ni hôte, ni tunnel, ni IP', function () {
    $s = semerInfra($this);
    $r = $this->actingAs($this->compte($s['espace'], 'admin'))->getJson('/api/v1/envoi/serveurs')->assertOk();
    $j = $r->json();
    expect($j['data'])->toHaveCount(1)->and($j['data'][0]['nom'])->toBe('srv-espace')->and($j['data'][0]['etat'])->toBe('gris');
    sansEcart(ContratOpenApiEnvoi::ecarts('commun.yaml', 'ServeurResume', $j['data'][0]));
    sansSecret($r->getContent());
});

test('GET /envoi/serveurs/{id}/sante : valeurs non calculables à null, isolement entre espaces', function () {
    $s = semerInfra($this);
    $u = $this->compte($s['espace'], 'admin');
    $r = $this->actingAs($u)->getJson('/api/v1/envoi/serveurs/' . $s['serveur'] . '/sante')->assertOk();
    $j = $r->json();
    sansEcart(ContratOpenApiEnvoi::ecarts('infrastructure.yaml', 'SanteServeur', $j));
    expect($j['serveur_id'])->toBe((string) $s['serveur'])->and($j['en_file'])->toBeNull()->and($j['test'])->toBeNull()
        ->and($j['en_ligne_depuis_jours'])->toBeNull()->and($j['paliers'])->toBe([]);
    sansSecret($r->getContent());
    $this->actingAs($u)->getJson('/api/v1/envoi/serveurs/' . $s['serveurAutre'] . '/sante')->assertNotFound();
    $this->actingAs($u)->getJson('/api/v1/envoi/serveurs/xyz/sante')->assertNotFound();
});

test('GET /envoi/configuration : numéro, statut, date, auteur, empreinte — jamais le contenu', function () {
    $s = semerInfra($this);
    $r = $this->actingAs($this->compte($s['espace'], 'admin'))->getJson('/api/v1/envoi/configuration')->assertOk();
    $j = $r->json();
    sansEcart(ContratOpenApiEnvoi::ecarts('infrastructure.yaml', 'Configuration', $j));
    expect($j['courante']['numero'])->toBe(1)->and($j['courante']['resultat'])->toBe('appliquee')
        ->and($j['courante']['empreinte'])->toBe(str_repeat('a', 64))->and($j['courante']['par'])->toBeNull()
        ->and($j['prete'])->toBeNull()->and($j['historique'])->toHaveCount(2)
        ->and($j['historique'][0]['numero'])->toBe(2)->and($j['historique'][0]['resultat'])->toBe('invalide');
    foreach ($j['historique'] as $v) {
        expect(array_keys($v))->toEqualCanonicalizing(['numero', 'creee_le', 'appliquee_le', 'par', 'resultat', 'changements', 'empreinte'])
            ->and($v['changements'])->toBe([]);
    }
    sansSecret($r->getContent());
});

test('GET /envoi/configuration sans aucune version : courante à null', function () {
    $espace = $this->espace(true);
    $j = $this->actingAs($this->compte($espace, 'admin'))->getJson('/api/v1/envoi/configuration')->assertOk()->json();
    sansEcart(ContratOpenApiEnvoi::ecarts('infrastructure.yaml', 'Configuration', $j));
    expect($j['courante'])->toBeNull()->and($j['historique'])->toBe([]);
});

test('espace vide : les listes sont vides, le contrat tient', function () {
    $u = $this->compte($this->espace(true), 'admin');
    foreach (['domaines', 'listes-noires', 'serveurs'] as $chemin) {
        expect($this->actingAs($u)->getJson("/api/v1/envoi/$chemin")->assertOk()->json('data'))->toBe([]);
    }
});

test('sans users.manage : 403 (operator, viewer) ; sans produit envoi : 404 ; sans session : 401', function () {
    $s = semerInfra($this);
    $uris = ['domaines', 'domaines/' . $s['domaine'], 'listes-noires', 'serveurs', 'serveurs/' . $s['serveur'] . '/sante', 'configuration'];
    foreach (['operator', 'viewer'] as $role) {
        $u = $this->compte($s['espace'], $role);
        foreach ($uris as $uri) {
            $this->actingAs($u)->getJson("/api/v1/envoi/$uri")->assertForbidden();
        }
    }
    $sans = $this->compte($this->espace(false), 'owner');
    foreach ($uris as $uri) {
        $this->actingAs($sans)->getJson("/api/v1/envoi/$uri")->assertNotFound();
    }
    foreach ($uris as $uri) {
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/envoi/$uri")->assertUnauthorized();
    }
});

test('ces routes ne font qu’une lecture : aucune écriture, aucun ServeurEnvoi', function () {
    $s = semerInfra($this);
    $u = $this->compte($s['espace'], 'owner');
    $avant = DB::table('envoi_drapeaux')->count();
    foreach (['domaines', 'listes-noires', 'serveurs', 'configuration'] as $uri) {
        $this->actingAs($u)->getJson("/api/v1/envoi/$uri")->assertOk();
        $this->actingAs($u)->postJson("/api/v1/envoi/$uri", [])->assertStatus(405);
    }
    expect(DB::table('envoi_drapeaux')->count())->toBe($avant)->and($this->resolutions)->toBe(0);
});

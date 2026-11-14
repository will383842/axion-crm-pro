<?php

namespace Tests\Feature\Envoi\Routes;

use App\Envoi\Configuration\Applicateur;
use App\Envoi\Configuration\ServeurEnvoiConfiguration;
use App\Envoi\Dns\ResolveurDns;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\ContratOpenApiEnvoi;

uses(BaseRoutesEnvoi::class)->group('envoi');

/** R15 : écritures limitées. Résolveur DNS FAUX (aucune requête réseau), domaines fictifs, IP de documentation. */
final class FauxResolveurDnsR15 implements ResolveurDns
{
    public int $appels = 0;

    /** @param array<string, list<string>> $txt */
    public function __construct(private array $txt = [], private array $a = [], private array $ptr = [], private ?string $mtaSts = null) {}

    public function txt(string $nom): array
    {
        $this->appels++;

        return $this->txt[$nom] ?? [];
    }

    public function a(string $nom): array
    {
        $this->appels++;

        return $this->a[$nom] ?? [];
    }

    public function aaaa(string $nom): array
    {
        $this->appels++;

        return [];
    }

    public function ptr(string $ip): array
    {
        $this->appels++;

        return $this->ptr[$ip] ?? [];
    }

    public function politiqueMtaSts(string $domaine): ?string
    {
        $this->appels++;

        return $this->mtaSts;
    }
}

const DOM_R15 = 'envoi-fictif-r15.fr';

/** @return array{espace: Workspace, autre: Workspace, owner: User, domaine: int, domaineAutre: int, serveur: int, serveurAutre: int} */
function semerR15(object $t): array
{
    $nouvel = \Closure::bind(fn () => $this->espace(true), $t, BaseRoutesEnvoi::class);
    $espace = $nouvel();
    $autre = $nouvel();
    $ids = [];
    foreach (['espace' => $espace, 'autre' => $autre] as $cle => $ws) {
        WorkspaceContext::run($ws->id, function () use ($ws, $cle, &$ids) {
            $nom = $cle === 'espace' ? DOM_R15 : 'envoi-autre-r15.fr';
            $ids[$cle]['domaine'] = (int) DB::table('envoi_domaines')->insertGetId(['workspace_id' => $ws->id, 'nom' => $nom, 'achete_le' => '2026-10-01', 'flux' => 'prospection']);
            $ids[$cle]['serveur'] = (int) DB::table('envoi_serveurs')->insertGetId(['workspace_id' => $ws->id, 'source' => 'srv-' . $cle, 'url_base' => 'https://serveur-fictif.example.invalid:8443']);
        });
    }
    $acces = \Closure::bind(fn () => $this->compte($espace, 'owner'), $t, BaseRoutesEnvoi::class);

    return ['espace' => $espace, 'autre' => $autre, 'owner' => $acces(), 'domaine' => $ids['espace']['domaine'], 'domaineAutre' => $ids['autre']['domaine'],
        'serveur' => $ids['espace']['serveur'], 'serveurAutre' => $ids['autre']['serveur']];
}

/** Applicateur et adaptateur du serveur : tout appel est compté PUIS refusé. */
function espionsApplication(): \stdClass
{
    $e = new \stdClass;
    $e->appels = 0;
    app()->bind(Applicateur::class, function () use ($e) {
        $e->appels++;
        throw new \RuntimeException('Applicateur interdit');
    });
    app()->bind(ServeurEnvoiConfiguration::class, function () use ($e) {
        $e->appels++;
        throw new \RuntimeException('Adaptateur de configuration interdit');
    });

    return $e;
}

function compterDomaines(string $ws, string $nom): int
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->where('nom', $nom)->count());
}

test('POST /envoi/domaines : domaine et flux enregistrés, plan DNS rendu, aucun DNS interrogé ni écrit', function () {
    $s = semerR15($this);
    $faux = new FauxResolveurDnsR15;
    $this->app->instance(ResolveurDns::class, $faux);
    $r = $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines', ['nom' => 'Bücher-Fictif-R15.fr', 'flux' => 'lettre'])->assertCreated();
    $j = $r->json();
    expect($j['domaine']['nom'])->toBe('xn--bcher-fictif-r15-jzb.fr')->and($j['dns'])->not->toBeEmpty()->and($faux->appels)->toBe(0);
    expect(compterDomaines($s['espace']->id, 'xn--bcher-fictif-r15-jzb.fr'))->toBe(1);
    expect(WorkspaceContext::run($s['espace']->id, fn () => DB::table('envoi_domaines')->where('nom', 'xn--bcher-fictif-r15-jzb.fr')->value('flux')))->toBe('lettre');
    foreach ($j['dns'] as $ligne) {
        expect(ContratOpenApiEnvoi::ecarts('infrastructure.yaml', 'EnregistrementDns', $ligne))->toBe([]);
    }
    expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'DomaineResume', $j['domaine']))->toBe([]);
    // Modèles à jetons : jamais d'adresse réelle ni de clé.
    expect($r->getContent())->toContain('{IP_ENVOI}')->and($r->getContent())->not->toMatch('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/');
});

test('rejeu de POST /envoi/domaines : pas de doublon, même identifiant', function () {
    $s = semerR15($this);
    $a = $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines', ['nom' => 'rejeu-fictif-r15.fr', 'flux' => 'prospection'])->assertCreated()->json();
    $b = $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines', ['nom' => 'REJEU-fictif-r15.FR.', 'flux' => 'prospection'])->assertOk()->json();
    expect($b['domaine']['id'])->toBe($a['domaine']['id']);
    expect(compterDomaines($s['espace']->id, 'rejeu-fictif-r15.fr'))->toBe(1);
});

test('POST /envoi/domaines : IP, domaine réservé, flux absent ou inconnu refusés (422), sans citer la saisie', function (array $corps) {
    $s = semerR15($this);
    $r = $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines', $corps)->assertStatus(422);
    foreach ($corps as $v) {
        expect($r->getContent())->not->toContain((string) $v);
    }
})->with([
    'ip' => [['nom' => '192.0.2.44', 'flux' => 'prospection']],
    'local' => [['nom' => 'poste-fictif.local', 'flux' => 'prospection']],
    'localhost' => [['nom' => 'localhost', 'flux' => 'prospection']],
    'internal' => [['nom' => 'poste-fictif.internal', 'flux' => 'prospection']],
    'test' => [['nom' => 'poste-fictif.test', 'flux' => 'prospection']],
    'sans flux' => [['nom' => 'sans-flux-r15.fr']],
    'flux inconnu' => [['nom' => 'flux-inconnu-r15.fr', 'flux' => 'pub']],
]);

test('chaque POST d’écriture : 403 pour l’opérateur, l’administrateur et le lecteur ; rien n’est écrit', function () {
    $s = semerR15($this);
    $avant = WorkspaceContext::run($s['espace']->id, fn () => DB::table('envoi_domaines')->count());
    $chemins = [
        '/api/v1/envoi/domaines' => ['nom' => 'refuse-fictif-r15.fr', 'flux' => 'prospection'],
        '/api/v1/envoi/domaines/' . $s['domaine'] . '/dns/verifier' => [],
        '/api/v1/envoi/domaines/' . $s['domaine'] . '/dmarc' => ['politique' => 'none'],
        '/api/v1/envoi/serveurs/' . $s['serveur'] . '/adresses-ip' => ['adresse' => '192.0.2.5', 'flux' => 'prospection', 'domaine_id' => $s['domaine']],
        '/api/v1/envoi/serveurs/' . $s['serveur'] . '/test' => [],
        '/api/v1/envoi/configuration/appliquer' => [],
        '/api/v1/envoi/configuration/versions/1/revenir' => [],
    ];
    foreach (['operator', 'admin', 'viewer'] as $role) {
        $u = \Closure::bind(fn () => $this->compte($s['espace'], $role), $this, BaseRoutesEnvoi::class)();
        foreach ($chemins as $chemin => $corps) {
            $this->actingAs($u)->postJson($chemin, $corps)->assertForbidden();
        }
    }
    expect(WorkspaceContext::run($s['espace']->id, fn () => DB::table('envoi_domaines')->count()))->toBe($avant);
});

test('POST dns/verifier : ControleurDns avec le résolveur faux ; date de vérification posée si tout est prêt', function () {
    $s = semerR15($this);
    $nom = DOM_R15;
    config(['envoi.dns.domaines' => [$nom => ['selecteur' => 's1', 'helo' => 'mail.' . $nom, 'ip' => '192.0.2.10']]]);
    $faux = new FauxResolveurDnsR15(
        txt: [
            $nom => ['v=spf1 ip4:192.0.2.10 -all'],
            's1._domainkey.' . $nom => ['v=DKIM1; k=rsa; p=' . base64_encode(str_repeat('k', 300))],
            '_dmarc.' . $nom => ['v=DMARC1; p=none; rua=mailto:rapports@' . $nom],
        ],
        a: ['mail.' . $nom => ['192.0.2.10']],
        ptr: ['192.0.2.10' => ['mail.' . $nom]],
    );
    $this->app->instance(ResolveurDns::class, $faux);
    $r = $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines/' . $s['domaine'] . '/dns/verifier')->assertOk();
    expect($faux->appels)->toBeGreaterThan(0)->and($r->json('pret'))->toBeTrue();
    expect(WorkspaceContext::run($s['espace']->id, fn () => DB::table('envoi_domaines')->where('id', $s['domaine'])->value('spf_verifie_le')))->not->toBeNull();
    expect(WorkspaceContext::run($s['espace']->id, fn () => DB::table('envoi_controles_dns')->where('domaine', $nom)->count()))->toBe(1);
});

test('POST dns/verifier sur le domaine d’un autre espace : 404, et le résolveur n’est pas interrogé', function () {
    $s = semerR15($this);
    $faux = new FauxResolveurDnsR15;
    $this->app->instance(ResolveurDns::class, $faux);
    $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines/' . $s['domaineAutre'] . '/dns/verifier')->assertNotFound();
    $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines/999999999/dns/verifier')->assertNotFound();
    expect($faux->appels)->toBe(0);
});

test('POST dns/verifier : limité en débit (429 au-delà de 6 par minute)', function () {
    $s = semerR15($this);
    $this->app->instance(ResolveurDns::class, new FauxResolveurDnsR15);
    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines/' . $s['domaine'] . '/dns/verifier')->assertOk();
    }
    $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines/' . $s['domaine'] . '/dns/verifier')->assertStatus(429);
});

test('POST dmarc : un rappel est enregistré, rien n’est publié ni interrogé', function () {
    $s = semerR15($this);
    $faux = new FauxResolveurDnsR15;
    $this->app->instance(ResolveurDns::class, $faux);
    $rappel = now()->addDays(14)->toIso8601String();
    $r = $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines/' . $s['domaine'] . '/dmarc', ['politique' => 'quarantine', 'rappel_le' => $rappel])->assertOk();
    expect($r->json('rappel.politique'))->toBe('quarantine')->and($r->json('applique'))->toBeFalse()->and($faux->appels)->toBe(0);
    $l = WorkspaceContext::run($s['espace']->id, fn () => DB::table('envoi_domaines_rappels_dmarc')->where('domaine_id', $s['domaine'])->get());
    expect($l)->toHaveCount(1)->and($l[0]->politique)->toBe('quarantine');
    $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines/' . $s['domaine'] . '/dmarc', ['politique' => 'tout'])->assertStatus(422);
    $this->actingAs($s['owner'])->postJson('/api/v1/envoi/domaines/' . $s['domaineAutre'] . '/dmarc', ['politique' => 'none'])->assertNotFound();
});

test('POST adresses-ip : une version « prête » est préparée, aucune application, aucune adresse conservée', function () {
    $s = semerR15($this);
    $espion = espionsApplication();
    $corps = ['adresse' => '192.0.2.5', 'flux' => 'prospection', 'domaine_id' => $s['domaine']];
    $r = $this->actingAs($s['owner'])->postJson('/api/v1/envoi/serveurs/' . $s['serveur'] . '/adresses-ip', $corps)->assertOk();
    expect($r->getContent())->not->toContain('192.0.2.5')->and($r->json('prete.resultat'))->toBe('prete')->and($r->json('courante'))->toBeNull();
    expect(ContratOpenApiEnvoi::ecarts('infrastructure.yaml', 'Configuration', $r->json()))->toBe([]);
    $v = WorkspaceContext::run($s['espace']->id, fn () => DB::table('envoi_config_versions')->where('workspace_id', $s['espace']->id)->get());
    expect($v)->toHaveCount(1)->and($v[0]->statut)->toBe('prete')->and(json_encode($v))->not->toContain('192.0.2.5');
    expect($espion->appels)->toBe(0)->and($this->resolutions)->toBe(0);
});

test('POST adresses-ip : adresse invalide, flux ou domaine incohérent (422) ; serveur ou domaine d’un autre espace (404)', function () {
    $s = semerR15($this);
    espionsApplication();
    $url = '/api/v1/envoi/serveurs/' . $s['serveur'] . '/adresses-ip';
    $base = ['adresse' => '192.0.2.5', 'flux' => 'prospection', 'domaine_id' => $s['domaine']];
    $this->actingAs($s['owner'])->postJson($url, ['adresse' => 'pas-une-ip'] + $base)->assertStatus(422);
    $this->actingAs($s['owner'])->postJson($url, ['adresse' => '127.0.0.1'] + $base)->assertStatus(422);
    $this->actingAs($s['owner'])->postJson($url, ['flux' => 'lettre'] + $base)->assertStatus(422);
    $this->actingAs($s['owner'])->postJson($url, ['domaine_id' => $s['domaineAutre']] + $base)->assertNotFound();
    $this->actingAs($s['owner'])->postJson('/api/v1/envoi/serveurs/' . $s['serveurAutre'] . '/adresses-ip', $base)->assertNotFound();
    expect(DB::table('envoi_config_versions')->where('workspace_id', $s['espace']->id)->count())->toBe(0);
});

test('POST serveurs/{id}/test : 409 « envoi fermé », aucun envoi, aucun serveur résolu', function () {
    $s = semerR15($this);
    $espion = espionsApplication();
    $r = $this->actingAs($s['owner'])->postJson('/api/v1/envoi/serveurs/' . $s['serveur'] . '/test')->assertStatus(409);
    expect($r->json('error'))->toBe('envoi_ferme')->and($r->json('message'))->toContain('fermé');
    expect($this->resolutions)->toBe(0)->and($espion->appels)->toBe(0);
    foreach (['envoi_tentatives', 'envoi_injections_smtp', 'envoi_messages_envoyes'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    $this->actingAs($s['owner'])->postJson('/api/v1/envoi/serveurs/' . $s['serveurAutre'] . '/test')->assertNotFound();
});

test('POST configuration/appliquer et versions/{n}/revenir : 409 au palier 0 et zéro appel Applicateur', function () {
    $s = semerR15($this);
    $espion = espionsApplication();
    foreach (['/api/v1/envoi/configuration/appliquer', '/api/v1/envoi/configuration/versions/1/revenir'] as $chemin) {
        $r = $this->actingAs($s['owner'])->postJson($chemin)->assertStatus(409);
        expect($r->json('error'))->toBe('envoi_ferme');
    }
    expect($espion->appels)->toBe(0)->and($this->resolutions)->toBe(0);
    expect(DB::table('envoi_config_versions')->where('workspace_id', $s['espace']->id)->count())->toBe(0);
});

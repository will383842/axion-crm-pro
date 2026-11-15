<?php

use Symfony\Component\Yaml\Yaml;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\TestCase;

uses(TestCase::class)->group('envoi');

/** S2-CONTRATS : contrat seul (conformité, pilotage, infrastructure, séquences). Aucune route servie ici. */
/** @return array<string, mixed> */
function yamlEnvoi(string $zone): array
{
    return Yaml::parseFile(dirname(__DIR__, 3) . "/openapi/envoi/$zone.yaml");
}

/** @return array<string, string> */
function trousDeclares(): array
{
    $t = [];
    foreach (glob(dirname(__DIR__, 2) . '/Support/Couverture/trous/*.php') ?: [] as $f) {
        $t = array_merge($t, require $f);
    }

    return $t;
}

test('les deux nouveaux chemins sont déclarés au contrat ET comme trous', function () {
    expect(yamlEnvoi('conformite')['paths'])->toHaveKey('/envoi/opposition/import');
    expect(yamlEnvoi('pilotage')['paths'])->toHaveKey('/envoi/feu-vert/decision');
    expect(trousDeclares())->toHaveKeys(['POST /envoi/opposition/import', 'POST /envoi/feu-vert/decision']);
});

test('import des oppositions : corps JSON seulement (jamais multipart), 1 048 576 octets au plus', function () {
    $op = yamlEnvoi('conformite')['paths']['/envoi/opposition/import']['post'] ?? [];
    $contenu = $op['requestBody']['content'] ?? [];
    expect(array_keys($contenu))->toBe(['application/json']);
    expect($contenu['application/json']['schema']['$ref'])->toBe('#/components/schemas/ImportOppositionsDemande');
    $s = yamlEnvoi('conformite')['components']['schemas']['ImportOppositionsDemande'];
    expect($s['required'])->toBe(['etape', 'contenu']);
    expect($s['properties']['etape']['enum'])->toBe(['simulation', 'confirmation']);
    expect($s['properties']['contenu']['maxLength'])->toBe(1048576);
    expect($s['properties'])->toHaveKey('jeton');
    expect(array_keys($op['responses']))->toContain(200, 403, 409, 422);
});

test('décision du feu vert : If-Match, 412 et 428 propres à « accorde »', function () {
    $op = yamlEnvoi('pilotage')['paths']['/envoi/feu-vert/decision']['post'] ?? [];
    $en_tetes = array_filter($op['parameters'] ?? [], static fn ($p) => ($p['in'] ?? '') === 'header');
    expect(array_column($en_tetes, 'name'))->toContain('If-Match');
    expect(array_keys($op['responses']))->toContain(200, 403, 409, 412, 428);
    expect($op['description'])->toContain('accorde')->toContain('retire');
    $s = $op['requestBody']['content']['application/json']['schema'];
    expect($s['properties']['decision']['enum'])->toBe(['accorde', 'retire']);
});

test('GET feu-vert : empreinte_liste et decision facultatifs, sans nombre de points figé', function () {
    $op = yamlEnvoi('pilotage')['paths']['/envoi/feu-vert']['get'];
    $s = $op['responses'][200]['content']['application/json']['schema'];
    expect($s['properties'])->toHaveKeys(['empreinte_liste', 'decision']);
    expect($s['required'])->not->toContain('empreinte_liste');
    expect($s['required'])->not->toContain('decision');
    expect($op['description'])->not->toMatch('/\d+\s+points/')->toContain('total');
});

test('domaine : champ adaptateur facultatif en lecture', function () {
    $d = yamlEnvoi('commun')['components']['schemas']['DomaineResume'];
    expect($d['properties']['adaptateur']['enum'])->toBe(['faux', 'kumo', 'smtp']);
    expect($d['required'])->not->toContain('adaptateur');
});

test('garde : les réponses actuelles de /envoi/feu-vert et /envoi/domaines restent conformes (champs ajoutés facultatifs)', function () {
    $domaine = [
        'id' => 'd1', 'nom' => 'a.example.test', 'etat' => 'vert', 'boites' => 0, 'dmarc' => null,
        'dmarc_prochaine_etape' => null, 'listes_noires' => 0, 'ajoute_le' => '2026-01-01T00:00:00Z', 'chauffe' => null,
    ];
    expect(ContratOpenApiEnvoi::ecartsReponse('infrastructure.yaml', '/envoi/domaines', 'get', 200, [
        'data' => [$domaine], 'garde_dispersion' => ['utilises' => 1, 'limite' => 3], 'expirations' => [],
    ]))->toBe([]);
    expect(ContratOpenApiEnvoi::ecartsReponse('infrastructure.yaml', '/envoi/domaines', 'get', 200, [
        'data' => [$domaine + ['adaptateur' => 'faux']], 'garde_dispersion' => ['utilises' => 1, 'limite' => 3], 'expirations' => [],
    ]))->toBe([]);
    $liste = ['points' => [], 'faits' => 0, 'total' => 0, 'bloquants_restants' => 0, 'verrous' => [
        'envoi_reel_actif' => false, 'mode_temoins' => true, 'signature_presente' => false, 'signature_date' => null,
        'fournisseurs_temoins_couverts' => 0, 'fournisseurs_temoins_requis' => 6,
    ]];
    expect(ContratOpenApiEnvoi::ecartsReponse('pilotage.yaml', '/envoi/feu-vert', 'get', 200, $liste))->toBe([]);
    expect(ContratOpenApiEnvoi::ecartsReponse('pilotage.yaml', '/envoi/feu-vert', 'get', 200, $liste + ['empreinte_liste' => str_repeat('a', 64), 'decision' => null]))->toBe([]);
});

test('codes déclarés : 501 reglages boîte, 409 et 403 reculer, 403 sur les lectures T-BOITES/T-CHAUFFE/T-SEQ', function () {
    $i = yamlEnvoi('infrastructure')['paths'];
    expect($i['/envoi/boites/{id}/reglages']['put']['responses'])->toHaveKey('501');
    expect($i['/envoi/chauffe/{id}/reculer']['post']['responses'])->toHaveKeys(['403', '409']);
    expect($i['/envoi/boites']['get']['responses'])->toHaveKey('403');
    expect($i['/envoi/boites/{id}']['get']['responses'])->toHaveKey('403');
    expect($i['/envoi/chauffe']['get']['responses'])->toHaveKey('403');
    $s = yamlEnvoi('sequences')['paths'];
    expect($s['/envoi/sequences']['get']['responses'])->toHaveKey('403');
    expect($s['/envoi/sequences/{id}']['get']['responses'])->toHaveKey('403');
});

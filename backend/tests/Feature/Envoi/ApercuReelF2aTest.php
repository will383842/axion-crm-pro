<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Campagnes\SelectionRefusee;
use App\Envoi\Creation\ApercuReel;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;

/**
 * Envoi F2a-2 — aperçu sur 3 vrais destinataires et vrai compte des champs manquants (lecture seule, aucun envoi).
 * Cahier F2a (PR #608), §2.6 et §2.8 test 10. Données FICTIVES (exemple.test). Service rejoué sous le rôle applicatif ET le propriétaire.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const AP = '/api/v1/envoi';

beforeEach(function () {
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
    $ws = $this->ws->id;
    WorkspaceContext::set($ws);
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste F2a-2']);
    $this->contacts = [];
    // 5 contacts : 1 Alice, 2 sans prénom, 3 OPPOSÉ (Carla), 4 sans prénom, 5 Eva.
    foreach ([['ALICE', 'Paris'], [null, null], ['Carla', 'Lyon'], ['  ', 'Nantes'], ['EVA', 'Lille']] as $i => [$prenom, $ville]) {
        $co = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => '55' . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT) . '1', 'denomination' => 'Société ' . ($i + 1), 'city' => $ville]);
        $this->contacts[$i + 1] = (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $co, 'first_name' => $prenom, 'last_name' => 'Nom' . ($i + 1), 'email' => 'p' . ($i + 1) . '.nom@client' . ($i + 1) . '.example.test']);
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'contact_id' => null, 'company_id' => $co, 'origine' => 'coche']);
    }
    $h = ListeSuppression::empreinte('p3.nom@client3.example.test');
    $lien = DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $ws, 'empreinte_adresse' => $h, 'portee' => 'business']);
    DB::table('envoi_oppositions')->insert(['workspace_id' => $ws, 'lien_id' => $lien, 'empreinte_adresse' => $h, 'canal' => 'one_click']);

    $messages = app(ServiceMessagesTypes::class);
    $m = $messages->creer($ws, 'Premier contact', 'Bonjour {{prenom|Madame, Monsieur}}', 'Ville : {{ville|votre ville}}. {{prenom|Madame, Monsieur}}, bonne journée.', 'test');
    $this->messageId = $m['id'];
    $this->brouillon = app(DepotCampagnes::class)->creer(
        $ws,
        'Brouillon F2a-2',
        ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Cible'],
        [],
        1,
        ['contenu' => ['sequence' => [['etape' => 1, 'message_id' => $m['id']]]], 'etapes_faites' => [1, 2]],
        'test',
    );
    $this->service = fn (?SelectionDestinataires $s = null, int $taille = 500, int $lots = 10) => new ApercuReel($s ?? new SelectionDestinataires, $messages, $taille, $lots);
    $this->mode = function (string $mode, callable $f) {
        if ($mode === 'app') {
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        try {
            return $f();
        } finally {
            if ($mode === 'app') {
                DB::statement('RESET ROLE');
            }
        }
    };
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (\Throwable) {
    }
});

test('10. exemples : 3 premiers retenus dans l\'ordre des clés, l\'opposé jamais, deux appels identiques, aucune adresse', function (string $mode) {
    $a = ($this->mode)($mode, fn () => ($this->service)()->exemples($this->ws->id, $this->brouillon));
    $b = ($this->mode)($mode, fn () => ($this->service)()->exemples($this->ws->id, $this->brouillon));
    expect($a)->toBe($b)->and(array_column($a, 'id'))->toBe([$this->contacts[1], $this->contacts[2], $this->contacts[4]])
        ->and(array_column($a, 'libelle'))->toBe(['Destinataire 1', 'Destinataire 2', 'Destinataire 3'])
        ->and(json_encode($a))->not->toContain('@')->and(array_column($a, 'id'))->not->toContain($this->contacts[3]);
})->with(['app', 'proprio']);

test('10. compter : prenom manquants 2 sur 4 (l\'opposé n\'est pas compté), non partiel', function (string $mode) {
    $c = ($this->mode)($mode, fn () => ($this->service)()->compter($this->ws->id, $this->brouillon, ['prenom', 'ville']));
    expect($c['prenom'])->toBe(['manquants' => 2, 'sur' => 4, 'partiel' => false])->and($c['ville'])->toBe(['manquants' => 1, 'sur' => 4, 'partiel' => false]);
})->with(['app', 'proprio']);

test('10. rendre : même chemin que l\'envoi (valeurs nettoyées, repli sinon), replis listés, pas d\'adresse', function (string $mode) {
    $r = ($this->mode)($mode, fn () => ($this->service)()->rendre($this->ws->id, $this->brouillon, 1));
    expect($r)->toHaveCount(3)->and($r[0]['objet'])->toBe('Bonjour Alice')->and($r[0]['corps'])->toBe('Ville : Paris. Alice, bonne journée.')->and($r[0]['replis'])->toBe([])
        ->and($r[1]['objet'])->toBe('Bonjour Madame, Monsieur')->and($r[1]['corps'])->toBe('Ville : votre ville. Madame, Monsieur, bonne journée.')->and($r[1]['replis'])->toBe(['prenom', 'ville'])
        ->and($r[2]['replis'])->toBe(['prenom'])->and($r[2]['corps'])->toContain('Nantes')
        ->and(json_encode($r))->not->toContain('@')->and(array_keys($r[0]))->toBe(['id', 'libelle', 'objet', 'corps', 'replis']);
})->with(['app', 'proprio']);

test('cloisonnement : un autre espace ne voit ni exemples ni aperçu du brouillon', function (string $mode) {
    $autre = $this->espace(true)->id;
    expect(fn () => ($this->mode)($mode, fn () => ($this->service)()->exemples($autre, $this->brouillon)))->toThrow(SelectionRefusee::class);
})->with(['app', 'proprio']);

test('échec fermé : lecture d\'opposition en panne ⇒ refus, aucun destinataire ni aperçu', function () {
    $panne = new SelectionDestinataires(function () {
        throw new \RuntimeException('panne');
    });
    expect(fn () => ($this->service)($panne)->exemples($this->ws->id, $this->brouillon))->toThrow(SelectionRefusee::class)
        ->and(fn () => ($this->service)($panne)->rendre($this->ws->id, $this->brouillon, 1))->toThrow(SelectionRefusee::class)
        ->and(fn () => ($this->service)($panne)->compter($this->ws->id, $this->brouillon, ['prenom']))->toThrow(SelectionRefusee::class);
});

test('compter : au-delà du plafond de lots ⇒ partiel', function () {
    $c = ($this->service)(null, 2, 1)->compter($this->ws->id, $this->brouillon, ['prenom']);
    expect($c['prenom']['partiel'])->toBeTrue()->and($c['prenom']['sur'])->toBeLessThanOrEqual(2);
});

test('route aperçu : 3 destinataires conformes, 422 si étape hors séquence, 403 sans users.manage, 404 autre espace', function () {
    $r = $this->actingAs($this->owner)->postJson(AP . "/brouillons/{$this->brouillon}/apercu", ['etape' => 1])->assertOk();
    expect($r->json('destinataires'))->toHaveCount(3)->and($r->json('destinataires.0.objet'))->toBe('Bonjour Alice')->and(json_encode($r->json()))->not->toContain('@');
    expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'ApercuReel', $r->json()))->toBe([]);
    $this->actingAs($this->owner)->postJson(AP . "/brouillons/{$this->brouillon}/apercu", ['etape' => 2])->assertStatus(422);
    $this->actingAs($this->owner)->postJson(AP . "/brouillons/{$this->brouillon}/apercu", [])->assertStatus(422);
    $this->actingAs($this->compte($this->ws, 'viewer'))->postJson(AP . "/brouillons/{$this->brouillon}/apercu", ['etape' => 1])->assertForbidden();
    $autre = $this->compte($this->espace(true), 'owner');
    $this->actingAs($autre)->postJson(AP . "/brouillons/{$this->brouillon}/apercu", ['etape' => 1])->assertNotFound();
});

test('route destinataires-exemples : avec brouillon_id la cible réelle (sans adresse) ; sans brouillon_id liste vide', function () {
    $r = $this->actingAs($this->owner)->getJson(AP . "/creation/destinataires-exemples?brouillon_id={$this->brouillon}")->assertOk();
    expect(array_column($r->json('data'), 'id'))->toBe([(string) $this->contacts[1], (string) $this->contacts[2], (string) $this->contacts[4]]);
    foreach ($r->json('data') as $d) {
        expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'DestinataireExemple', $d))->toBe([]);
    }
    expect(json_encode($r->json()))->not->toContain('@');
    $this->actingAs($this->owner)->getJson(AP . '/creation/destinataires-exemples')->assertOk()->assertJsonStructure(['data']);
});

test('route champs : vrais comptes avec brouillon_id (prenom 2 sur 4), manquants_connus faux sans, nom inconnu bloquant', function () {
    $corps = ['cible' => ['type' => 'liste', 'reference' => '1'], 'sequence' => [['etape' => 1, 'message_id' => $this->messageId]]];
    $r = $this->actingAs($this->owner)->postJson(AP . '/creation/champs', $corps + ['brouillon_id' => $this->brouillon])->assertOk();
    $prenom = collect($r->json('champs'))->firstWhere('nom', 'prenom');
    expect($prenom['manquants'])->toBe(2)->and($prenom['sur'])->toBe(4)->and($prenom['partiel'])->toBeFalse()->and($prenom['manquants_connus'])->toBeTrue()->and($prenom['bloquant'])->toBeFalse()
        ->and($prenom['repli'])->toBe('Madame, Monsieur');
    expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'ChampUtilise', $prenom))->toBe([]);
    $sans = collect($this->actingAs($this->owner)->postJson(AP . '/creation/champs', $corps)->assertOk()->json('champs'))->firstWhere('nom', 'prenom');
    expect($sans['manquants_connus'])->toBeFalse()->and($sans['manquants'])->toBe(0);

    // Version ancienne figée avec un nom inconnu : le champ est signalé bloquant.
    DB::table('envoi_modele_versions')->insert(['workspace_id' => $this->ws->id, 'modele_id' => $this->messageId, 'numero' => 2, 'objet' => 'Bonjour {{societe|x}}', 'corps' => 'Corps', 'auteur' => 'test']);
    $inconnu = collect($this->actingAs($this->owner)->postJson(AP . '/creation/champs', $corps + ['brouillon_id' => $this->brouillon])->assertOk()->json('champs'))->firstWhere('nom', 'societe');
    expect($inconnu['bloquant'])->toBeTrue();
    $this->actingAs($this->compte($this->ws, 'viewer'))->postJson(AP . '/creation/champs', $corps)->assertForbidden();
});

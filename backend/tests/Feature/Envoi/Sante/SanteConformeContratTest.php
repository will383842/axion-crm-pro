<?php

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Sante\DepotSanteDb;
use App\Models\User;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => $this->seed(PermissionsAndRolesSeeder::class));

/** @return array{0: string, 1: User} espace + administrateur */
function espaceSanteContrat(): array
{
    $ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $ws, 'name' => 'T', 'slug' => 'm2-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'm2-' . Str::random(6) . '@example.test', 'name' => 'M2',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole('admin');

    return [$ws, $user];
}

function boiteSanteContrat(string $ws, string $adresse, string $etat = 'active'): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_boites')->insert([
        'id' => $id, 'workspace_id' => $ws, 'adresse' => $adresse, 'hote' => 'smtp-secret.example.test', 'port' => 587,
        'identifiants_chiffres' => 'chiffre-secret', 'etat' => $etat, 'limite_jour' => 20, 'limite_heure' => 5,
    ]);

    return $id;
}

/** Espace sain : un serveur avec un relevé OK, un domaine contrôlé, une boîte, un test du matin complet. */
function espaceSainContrat(string $ws, array $temoins = DepotSocleDb::FOURNISSEURS_TEMOINS): string
{
    DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => 'src', 'url_base' => 'https://envoi.example.test']);
    (new DepotSanteDb)->noterReleve($ws, 'src', new DateTimeImmutable('now', new DateTimeZone('UTC')), true, ['disque_libre_pct' => 80.0]);
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'prospection.example', 'achete_le' => '2026-01-01']);
    DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'prospection.example', 'controle_le' => now(), 'pret' => true]);
    foreach ($temoins as $f) {
        DB::table('envoi_temoins_journees')->insert(['workspace_id' => $ws, 'jour' => now('Europe/Paris')->toDateString(), 'fournisseur' => $f, 'reception' => 'inbox', 'auth_alignee' => true, 'bouton_vu' => true, 'post_effectif' => true, 'auteur' => 'test']);
    }

    return boiteSanteContrat($ws, 'a@prospection.example');
}

function lireSanteContrat($test, User $user): array
{
    $json = $test->actingAs($user)->getJson('/api/v1/envoi/sante')->assertOk()->json();
    $erreurs = ContratOpenApiEnvoi::ecartsReponse('pilotage.yaml', '/envoi/sante', 'get', 200, $json);
    expect($erreurs)->toBe([], implode("\n", $erreurs));

    return $json;
}

test('le validateur refuse l’ancienne forme {serveurs, constats_espace, pauses, seuils}', function () {
    $ancienne = ['serveurs' => [], 'constats_espace' => [], 'pauses' => [], 'seuils' => []];
    expect(ContratOpenApiEnvoi::ecartsReponse('pilotage.yaml', '/envoi/sante', 'get', 200, $ancienne))->not->toBe([]);
});

test('espace vide : réponse conforme au contrat, feu inconnu (gris), test du matin jamais fait, aucune pause', function () {
    [, $user] = espaceSanteContrat();
    $j = lireSanteContrat($this, $user);

    expect($j['feu'])->toBe('gris')
        ->and($j['boites'])->toBe([])->and($j['domaines'])->toBe([])->and($j['serveurs'])->toBe([])
        ->and($j['test_du_matin'])->toBe(['le' => null, 'temoins_ok' => 0, 'temoins_total' => 6, 'etat' => 'gris'])
        ->and($j['pauses'])->toBe([])->and($j['verifie_le'])->toBeNull()
        ->and($j['envoyes_14_jours'])->toHaveCount(14);
});

test('feu inconnu : un serveur sans aucun relevé est « gris », jamais vert', function () {
    [$ws, $user] = espaceSanteContrat();
    DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => 'src', 'url_base' => 'https://envoi.example.test']);
    boiteSanteContrat($ws, 'a@prospection.example');
    $j = lireSanteContrat($this, $user);

    expect($j['serveurs'][0]['etat'])->toBe('gris')->and($j['feu'])->not->toBe('vert')
        ->and($j['boites'][0]['etat'])->not->toBe('vert');
});

test('espace sain, aucune pause : tout est calculé, feu vert, pauses vides', function () {
    [$ws, $user] = espaceSanteContrat();
    $boite = espaceSainContrat($ws);
    (new DepotChauffe)->incrementer($ws, $boite, now('Europe/Paris')->toDateTimeImmutable(), 'gmail', 'envoyes', 4);
    $j = lireSanteContrat($this, $user);

    expect($j['feu'])->toBe('vert')->and($j['pauses'])->toBe([])
        ->and($j['serveurs'][0]['etat'])->toBe('vert')
        ->and($j['domaines'][0])->toMatchArray(['nom' => 'prospection.example', 'etat' => 'vert', 'boites' => 1, 'dmarc' => null])
        ->and($j['boites'][0])->toMatchArray(['adresse' => 'a@prospection.example', 'etat' => 'vert', 'statut' => 'prete', 'quota_jour' => 20, 'envoyes_aujourdhui' => 4, 'taux_refus' => null, 'domaine_id' => $j['domaines'][0]['id']])
        ->and($j['test_du_matin'])->toMatchArray(['temoins_ok' => 6, 'temoins_total' => 6, 'etat' => 'vert'])
        ->and($j['volume_jour'])->toBe(['possibles' => 20, 'envoyes' => 4])
        ->and(array_sum(array_column($j['envoyes_14_jours'], 'envoyes')))->toBe(4);
    $gmail = collect($j['rythme_messageries'])->firstWhere('envoyes', 4);
    expect($gmail['etat'])->toBe('normal')->and($gmail['par_heure'])->toBeNull();
});

test('pause active : motif, fournisseur et fin apparaissent, le feu n’est plus vert, la messagerie est freinée', function () {
    [$ws, $user] = espaceSanteContrat();
    espaceSainContrat($ws);
    (new DepotChauffe)->ouvrirPause($ws, 'indesirable_chez_un_temoin', 'gmail', new DateTimeImmutable('now'), 48);
    $j = lireSanteContrat($this, $user);

    expect($j['pauses'])->toHaveCount(1)
        ->and($j['pauses'][0])->toMatchArray(['motif' => 'indesirable_chez_un_temoin', 'portee' => 'fournisseur', 'fournisseur' => 'gmail'])
        ->and($j['pauses'][0]['fin'])->toBeString()
        ->and($j['feu'])->not->toBe('vert')->and($j['alertes'])->toBeGreaterThanOrEqual(1)
        ->and(collect($j['rythme_messageries'])->pluck('etat')->all())->toContain('freine');
});

test('pause globale sans fin : feu rouge, fin nulle', function () {
    [$ws, $user] = espaceSanteContrat();
    espaceSainContrat($ws);
    (new DepotChauffe)->ouvrirPause($ws, 'tableau_de_bord_non_valide', null, new DateTimeImmutable('now'));
    $j = lireSanteContrat($this, $user);

    expect($j['feu'])->toBe('rouge')->and($j['pauses'][0]['fin'])->toBeNull()->and($j['pauses'][0]['fournisseur'])->toBeNull()
        ->and($j['boites'][0]['etat'])->toBe('rouge');
});

test('isolement : rien d’un autre espace ; aucun hôte, identifiant ni URL dans la réponse ; lecture seule', function () {
    [$ws, $user] = espaceSanteContrat();
    espaceSainContrat($ws);
    [$autre] = espaceSanteContrat();
    boiteSanteContrat($autre, 'autre-secret@autre-domaine.example');
    (new DepotChauffe)->ouvrirPause($autre, 'reports_en_hausse_sur_une_heure', 'microsoft', new DateTimeImmutable('now'), 1);
    (new DepotSanteDb)->ouvrir($autre, 'src', 'ntp', 'attention', 'detail-autre-espace', new DateTimeImmutable('now'));
    $avant = [DB::table('envoi_pauses')->count(), DB::table('envoi_releves_sante')->count(), DB::table('envoi_constats_sante')->count()];

    $j = lireSanteContrat($this, $user);
    $brut = json_encode($j);

    expect($j['pauses'])->toBe([])->and($j['boites'])->toHaveCount(1)
        ->and($brut)->not->toContain('autre-secret')->not->toContain('autre-domaine')->not->toContain('detail-autre-espace')
        ->not->toContain('smtp-secret')->not->toContain('chiffre-secret')->not->toContain('envoi.example.test')->not->toContain('https://');
    expect([DB::table('envoi_pauses')->count(), DB::table('envoi_releves_sante')->count(), DB::table('envoi_constats_sante')->count()])->toBe($avant);
});

test('arrêt général armé : feu rouge, boîtes rouges, messageries freinées', function () {
    [$ws, $user] = espaceSanteContrat();
    espaceSainContrat($ws);
    (new DepotSocleDb)->armerToutArreter($ws, 'test');
    $j = lireSanteContrat($this, $user);

    expect($j['feu'])->toBe('rouge')->and($j['boites'][0]['etat'])->toBe('rouge')->and($j['boites'][0]['statut'])->not->toBe('prete')
        ->and(collect($j['rythme_messageries'])->pluck('etat')->unique()->all())->toBe(['freine'])
        ->and($j['alertes'])->toBeGreaterThanOrEqual(1);
});

test('le rouge passe avant le gris : pause globale ou constat critique sans aucun serveur déclaré', function () {
    [$ws, $user] = espaceSanteContrat();
    (new DepotChauffe)->ouvrirPause($ws, 'tableau_de_bord_non_valide', null, new DateTimeImmutable('now'));
    expect(lireSanteContrat($this, $user)['feu'])->toBe('rouge');

    [$ws2, $user2] = espaceSanteContrat();
    (new DepotSanteDb)->ouvrir($ws2, '(espace)', 'liste_noire:ip:203.0.113.9', 'critique', 'x', new DateTimeImmutable('now'));
    expect(lireSanteContrat($this, $user2)['feu'])->toBe('rouge');
});

test('valeurs non mesurables : dmarc, taux de refus et version de configuration restent null, jamais flatteurs', function () {
    [$ws, $user] = espaceSanteContrat();
    espaceSainContrat($ws);
    $j = lireSanteContrat($this, $user);
    expect($j['domaines'][0]['dmarc'])->toBeNull()->and($j['boites'][0]['taux_refus'])->toBeNull()
        ->and($j['serveurs'][0]['version_configuration'])->toBeNull();

    DB::table('envoi_config_versions')->insert(['workspace_id' => $ws, 'empreinte' => str_repeat('a', 64), 'statut' => 'appliquee']);
    expect(lireSanteContrat($this, $user)['serveurs'][0]['version_configuration'])->toBe(1);
});

test('test du matin : 6 témoins requis ; vert seulement si les 6 sont bons, inconnu s’il en manque, rouge si un est en indésirables', function () {
    [$ws, $user] = espaceSanteContrat();
    espaceSainContrat($ws, ['gmail', 'google_workspace', 'outlook_com', 'microsoft_365']);
    $j = lireSanteContrat($this, $user);
    expect($j['test_du_matin'])->toMatchArray(['temoins_ok' => 4, 'temoins_total' => 6, 'etat' => 'gris'])->and($j['feu'])->not->toBe('vert');

    DB::table('envoi_temoins_journees')->insert(['workspace_id' => $ws, 'jour' => now('Europe/Paris')->toDateString(), 'fournisseur' => 'yahoo', 'reception' => 'indesirables', 'auth_alignee' => true, 'bouton_vu' => true, 'post_effectif' => true, 'auteur' => 'test']);
    $j = lireSanteContrat($this, $user);
    expect($j['test_du_matin'])->toMatchArray(['temoins_ok' => 4, 'temoins_total' => 6, 'etat' => 'rouge'])->and($j['feu'])->toBe('rouge');
});

test('boîtes : domaine rouge => boîte non verte ; pause d’une seule messagerie => boîtes inchangées ; santé inconnue => statut « inconnu »', function () {
    [$ws, $user] = espaceSanteContrat();
    $boite = espaceSainContrat($ws);
    (new DepotChauffe)->ouvrirPause($ws, 'reports_en_hausse_sur_une_heure', 'gmail', new DateTimeImmutable('now'), 1);
    $b = lireSanteContrat($this, $user)['boites'][0];
    expect($b)->toMatchArray(['etat' => 'vert', 'statut' => 'prete']);

    DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'prospection.example', 'controle_le' => now()->addMinute(), 'pret' => false]);
    $b = lireSanteContrat($this, $user)['boites'][0];
    expect($b['etat'])->toBe('rouge')->and($b['statut'])->not->toBe('prete');

    [$ws2, $user2] = espaceSanteContrat();
    boiteSanteContrat($ws2, 'x@inconnu.example');
    $b = lireSanteContrat($this, $user2)['boites'][0];
    expect($b)->toMatchArray(['etat' => 'gris', 'statut' => 'inconnu', 'domaine_id' => null]);
});

test('arrêt général armé : boîtes suspendues et volume possible du jour à 0 (rien ne peut partir)', function () {
    [$ws, $user] = espaceSanteContrat();
    espaceSainContrat($ws);
    DB::table('envoi_drapeaux')->insert(['workspace_id' => $ws, 'tout_arreter' => true]);
    $j = lireSanteContrat($this, $user);

    expect($j['boites'][0])->toMatchArray(['etat' => 'rouge', 'statut' => 'suspendue'])
        ->and($j['volume_jour']['possibles'])->toBe(0);
});

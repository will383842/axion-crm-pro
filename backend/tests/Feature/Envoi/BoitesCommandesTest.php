<?php

use App\Envoi\Imap\DepotAccesImap;
use App\Envoi\Smtp\ResolveurHote;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot D1-L3 — commandes envoi:boite-creer, envoi:boite-secret, envoi:boite-reprendre. Secrets par l'entrée standard seulement.
 * Sécurité jouée sous le rôle applicatif ET sous le propriétaire. Aucune connexion réelle. Données fictives.
 */
uses(TestCase::class, RefreshDatabase::class);

const BCM_ID = 'releve@societe-a.example.test';
const BCM_MDP = 'motdepasse-temoin-zz';

function bcmEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Bcm', 'slug' => 'bcm-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function bcmBoite(string $ws, ?string $etat = null, ?string $motif = null): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_boites')->insert(['id' => $id, 'workspace_id' => $ws, 'adresse' => 'b-' . substr($id, 0, 6) . '@societe-a.example.test',
        'hote' => 'smtp.societe-a.example.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'chiffre-fictif']
        + ($etat === null ? [] : ['etat' => $etat, 'pause_motif' => $motif]));

    return $id;
}

/** Appelle la commande, les lignes de $stdin arrivant par l'entrée standard (jamais en argument), selon le rôle. */
function bcmAppeler(string $mode, string $ws, string $commande, array $params, array $stdin = []): int
{
    $flux = fopen('php://memory', 'r+');
    fwrite($flux, $stdin === [] ? '' : implode("\n", $stdin) . "\n");
    rewind($flux);
    app()->instance('envoi.flux_entree', $flux);
    if ($mode === 'app') {
        WorkspaceContext::set($ws);
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return Artisan::call($commande, $params + ['--no-interaction' => true]);
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
        app()->forgetInstance('envoi.flux_entree');
        fclose($flux);
    }
}

function bcmLignes(string $boite): array
{
    return DB::table('envoi_boites_acces_imap')->where('boite_id', $boite)->orderBy('id')->get()->all();
}

test('D1-L3 C1 boite-secret par l’entrée standard : une ligne active, chiffrée, déchiffrable', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = bcmEspace();
        $b = bcmBoite($ws);
        expect(bcmAppeler($mode, $ws, 'envoi:boite-secret', ['workspace' => $ws, 'boite' => $b, 'hote_imap' => 'imap.gmail.com'], [BCM_ID, BCM_MDP]))->toBe(0, $mode);
        $l = bcmLignes($b);
        expect($l)->toHaveCount(1)->and($l[0]->actif)->toBeTrue()->and($l[0]->hote)->toBe('imap.gmail.com');
        expect($l[0]->identifiant_chiffre)->not->toContain(BCM_ID)->and($l[0]->identifiant_chiffre)->not->toContain(BCM_MDP);
        expect(json_decode(Crypt::decryptString($l[0]->identifiant_chiffre), true))->toBe(['u' => BCM_ID, 's' => BCM_MDP]);
    }
});

test('D1-L3 C2 ni la sortie, ni les journaux, ni les exceptions ne montrent l’identifiant ou le mot de passe', function () {
    $ws = bcmEspace();
    $b = bcmBoite($ws);
    Log::spy();
    $sorties = '';
    $sorties .= bcmAppeler('proprio', $ws, 'envoi:boite-secret', ['workspace' => $ws, 'boite' => $b, 'hote_imap' => 'imap.gmail.com'], [BCM_ID, BCM_MDP]) . Artisan::output();
    $sorties .= bcmAppeler('proprio', $ws, 'envoi:boite-secret', ['workspace' => $ws, 'boite' => (string) Str::uuid(), 'hote_imap' => 'imap.gmail.com'], [BCM_ID, BCM_MDP]) . Artisan::output();
    $sorties .= bcmAppeler('proprio', $ws, 'envoi:boite-secret', ['workspace' => $ws, 'boite' => $b, 'hote_imap' => 'imap.societe-a.example.test'], [BCM_ID, BCM_MDP]) . Artisan::output();
    expect($sorties)->not->toContain(BCM_MDP)->and($sorties)->not->toContain(BCM_ID);
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('info');
});

test('D1-L3 C3 hôte hors liste : code 1, aucune ligne, la liste permise est rappelée', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = bcmEspace();
        $b = bcmBoite($ws);
        expect(bcmAppeler($mode, $ws, 'envoi:boite-secret', ['workspace' => $ws, 'boite' => $b, 'hote_imap' => 'imap.societe-a.example.test'], [BCM_ID, BCM_MDP]))->toBe(1, $mode);
        expect(Artisan::output())->toContain('imap.gmail.com');
        expect(bcmLignes($b))->toHaveCount(0);
    }
});

test('D1-L3 C3b identifiants de workspace ou de boîte invalides : refus sans requête', function () {
    DB::enableQueryLog();
    expect(bcmAppeler('proprio', 'pas-un-uuid', 'envoi:boite-secret', ['workspace' => 'pas-un-uuid', 'boite' => (string) Str::uuid(), 'hote_imap' => 'imap.gmail.com'], [BCM_ID, BCM_MDP]))->toBe(1);
    expect(bcmAppeler('proprio', (string) Str::uuid(), 'envoi:boite-reprendre', ['workspace' => (string) Str::uuid(), 'boite' => 'x'], []))->toBe(1);
    expect(DB::getQueryLog())->toBe([]);
    $ws = bcmEspace();
    $b = bcmBoite($ws);
    expect(bcmAppeler('proprio', $ws, 'envoi:boite-secret', ['workspace' => $ws, 'boite' => $b, 'hote_imap' => 'imap.gmail.com'], ['pas-une-adresse', BCM_MDP]))->toBe(1);
    expect(bcmAppeler('proprio', $ws, 'envoi:boite-secret', ['workspace' => $ws, 'boite' => $b, 'hote_imap' => 'imap.gmail.com'], [BCM_ID, '']))->toBe(1);
    expect(bcmLignes($b))->toHaveCount(0);
});

test('D1-L3 C4 --retirer : ligne inactive sans secret, sans rien lire sur l’entrée standard', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = bcmEspace();
        $b = bcmBoite($ws);
        bcmAppeler($mode, $ws, 'envoi:boite-secret', ['workspace' => $ws, 'boite' => $b, 'hote_imap' => 'imap.gmail.com'], [BCM_ID, BCM_MDP]);
        expect(bcmAppeler($mode, $ws, 'envoi:boite-secret', ['workspace' => $ws, 'boite' => $b, 'hote_imap' => 'imap.gmail.com', '--retirer' => true], []))->toBe(0, $mode);
        $l = bcmLignes($b);
        expect($l)->toHaveCount(2)->and($l[1]->actif)->toBeFalse()->and($l[1]->identifiant_chiffre)->toBeNull();
        expect(app(DepotAccesImap::class)->decrire($ws, $b))->toBeNull();
        expect(app(DepotAccesImap::class)->parametres($ws, $b))->toBeNull();
    }
});

test('D1-L3 C5 boîte de l’autre espace : code 1, aucune ligne (rôle applicatif ET propriétaire)', function () {
    foreach (['app', 'proprio'] as $mode) {
        $a = bcmEspace();
        $boiteB = bcmBoite(bcmEspace());
        expect(bcmAppeler($mode, $a, 'envoi:boite-secret', ['workspace' => $a, 'boite' => $boiteB, 'hote_imap' => 'imap.gmail.com'], [BCM_ID, BCM_MDP]))->toBe(1, $mode);
        expect(bcmAppeler($mode, $a, 'envoi:boite-secret', ['workspace' => $a, 'boite' => $boiteB, 'hote_imap' => 'imap.gmail.com', '--retirer' => true], []))->toBe(1, $mode);
        expect(bcmAppeler($mode, $a, 'envoi:boite-reprendre', ['workspace' => $a, 'boite' => $boiteB], []))->toBe(1, $mode);
        expect(bcmLignes($boiteB))->toHaveCount(0);
        expect(DB::table('envoi_boites')->where('id', $boiteB)->value('etat'))->toBe('active');
    }
});

test('D1-L3 C6 boite-reprendre : la pause manuelle est levée, la pause automatique jamais', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = bcmEspace();
        $manuelle = bcmBoite($ws, 'en_pause', 'suspension_manuelle');
        $auto = bcmBoite($ws, 'en_pause', 'auto_3x5xx_10min');
        expect(bcmAppeler($mode, $ws, 'envoi:boite-reprendre', ['workspace' => $ws, 'boite' => $manuelle], []))->toBe(0, $mode);
        expect(DB::table('envoi_boites')->where('id', $manuelle)->value('etat'))->toBe('active');
        expect(bcmAppeler($mode, $ws, 'envoi:boite-reprendre', ['workspace' => $ws, 'boite' => $auto], []))->toBe(1, $mode);
        $l = DB::table('envoi_boites')->where('id', $auto)->first();
        expect($l->etat)->toBe('en_pause')->and($l->pause_motif)->toBe('auto_3x5xx_10min');
    }
});

test('D1-L3 C7 boite-creer : secret SMTP par l’entrée standard, hôte privé refusé', function () {
    app()->instance(ResolveurHote::class, new class implements ResolveurHote
    {
        public function resoudre(string $hote): array
        {
            return ['93.184.216.34'];
        }
    });
    foreach (['app', 'proprio'] as $mode) {
        $ws = bcmEspace();
        $p = ['workspace' => $ws, 'adresse' => 'envoi@societe-a.example.test', 'hote_smtp' => 'smtp.societe-a.example.test', 'port_smtp' => '587', 'chiffrement' => 'starttls'];
        expect(bcmAppeler($mode, $ws, 'envoi:boite-creer', $p, ['smtp-user@societe-a.example.test', 'mdp-smtp-zz']))->toBe(0, $mode);
        $sortie = Artisan::output();
        expect(Str::isUuid(trim($sortie)) || preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-/', $sortie) === 1)->toBeTrue();
        expect($sortie)->not->toContain('mdp-smtp-zz')->and($sortie)->not->toContain('smtp-user@');
        $l = DB::table('envoi_boites')->where('workspace_id', $ws)->first();
        expect(json_decode(Crypt::decryptString($l->identifiants_chiffres), true))->toBe(['u' => 'smtp-user@societe-a.example.test', 's' => 'mdp-smtp-zz']);
        expect(bcmAppeler($mode, $ws, 'envoi:boite-creer', ['hote_smtp' => '10.0.0.1'] + $p, ['u@societe-a.example.test', 'mdp-smtp-zz']))->toBe(1, $mode);
        expect(Artisan::output())->not->toContain('mdp-smtp-zz');
        expect(DB::table('envoi_boites')->where('workspace_id', $ws)->count())->toBe(1);
        expect(bcmAppeler($mode, $ws, 'envoi:boite-creer', ['adresse' => 'pas-une-adresse'] + $p, ['u@societe-a.example.test', 'mdp-smtp-zz']))->toBe(1, $mode);
    }
});

test('D1-L3 C8 garde statique : aucune signature de commande ne porte de secret en argument ou option', function () {
    foreach (['EnvoiBoiteCreer', 'EnvoiBoiteSecret', 'EnvoiBoiteReprendre'] as $classe) {
        $src = (string) file_get_contents(app_path("Console/Commands/{$classe}.php"));
        preg_match('/\$signature = \'([^\']+)\'/', $src, $m);
        $params = trim((string) preg_replace('/^\S+/', '', $m[1] ?? '')); // sans le nom de la commande
        expect($m[1] ?? '')->not->toBe('')->and(strtolower($params))->not->toMatch('/password|secret|mot|passe/');
    }
});

test('D1-L3 C9 aucune connexion réseau, aucun protocole IMAP dans les commandes ni le dépôt', function () {
    Http::preventStrayRequests();
    foreach (['Console/Commands/EnvoiBoiteCreer.php', 'Console/Commands/EnvoiBoiteSecret.php', 'Console/Commands/EnvoiBoiteReprendre.php', 'Envoi/Imap/DepotAccesImap.php'] as $f) {
        $src = (string) file_get_contents(app_path($f));
        expect($src)->not->toContain('Webklex')->and($src)->not->toContain('ImapProtocol')->and($src)->not->toContain('fsockopen')->and($src)->not->toContain('Http::');
    }
});

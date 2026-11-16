<?php

use App\Envoi\Imap\DepotAccesImap;
use App\Envoi\Imap\HotesImapBoites;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot D1-L3 — dépôt des accès IMAP des boîtes. Chaque test est joué SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE) ET SOUS LE
 * PROPRIÉTAIRE (RLS contournée : seul le filtre explicite `workspace_id` protège). Données fictives, aucun vrai secret.
 */
uses(TestCase::class, RefreshDatabase::class);

const DAI_MODES = ['app', 'proprio'];
const DAI_ID = 'releve@societe-a.example.test';
const DAI_MDP = 'motdepasse-temoin-zz';

function daiEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Dai', 'slug' => 'dai-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function daiBoite(string $ws): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_boites')->insert(['id' => $id, 'workspace_id' => $ws, 'adresse' => 'b-' . substr($id, 0, 6) . '@societe-a.example.test',
        'hote' => 'smtp.societe-a.example.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'chiffre-fictif']);

    return $id;
}

function daiSous(string $mode, string $ws, Closure $f): mixed
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

test('D1-L3 H1 la liste fermée des hôtes est celle du CHECK de la table', function () {
    expect(HotesImapBoites::LISTE)->toBe(['imap.gmail.com', 'imap.mail.yahoo.com', 'imap.orange.fr', 'outlook.office365.com']);
    expect(HotesImapBoites::hoteAutorise('imap.gmail.com'))->toBeTrue();
    expect(HotesImapBoites::hoteAutorise('imap.societe-a.example.test'))->toBeFalse();
    expect(HotesImapBoites::hoteAutorise(''))->toBeFalse();
});

test('D1-L3 D1 poser puis décrire (sans déchiffrer) puis paramètres (rôle applicatif ET propriétaire)', function () {
    $ws = daiEspace();
    $b = daiBoite($ws);
    $depot = new DepotAccesImap;
    foreach (DAI_MODES as $mode) {
        daiSous($mode, $ws, fn () => $depot->poser($ws, $b, 'imap.gmail.com', DAI_ID, DAI_MDP));
        expect(daiSous($mode, $ws, fn () => $depot->decrire($ws, $b)))->toBe(['hote' => 'imap.gmail.com', 'port' => 993, 'dossier' => 'INBOX'], $mode);
        expect(daiSous($mode, $ws, fn () => $depot->parametres($ws, $b)))
            ->toBe(['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl', 'username' => DAI_ID, 'password' => DAI_MDP], $mode);
    }
    $brut = (string) DB::table('envoi_boites_acces_imap')->where('boite_id', $b)->value('identifiant_chiffre');
    expect($brut)->not->toContain(DAI_ID)->and($brut)->not->toContain(DAI_MDP);
    expect(json_decode(Crypt::decryptString($brut), true))->toBe(['u' => DAI_ID, 's' => DAI_MDP]);
});

test('D1-L3 D2 retirer ajoute une ligne inactive : la dernière ligne fait foi, puis une repose réactive', function () {
    $ws = daiEspace();
    $b = daiBoite($ws);
    $depot = new DepotAccesImap;
    foreach (DAI_MODES as $mode) {
        daiSous($mode, $ws, fn () => $depot->poser($ws, $b, 'imap.orange.fr', DAI_ID, DAI_MDP));
        daiSous($mode, $ws, fn () => $depot->retirer($ws, $b));
        expect(daiSous($mode, $ws, fn () => $depot->decrire($ws, $b)))->toBeNull($mode);
        expect(daiSous($mode, $ws, fn () => $depot->parametres($ws, $b)))->toBeNull($mode);
    }
    $derniere = DB::table('envoi_boites_acces_imap')->where('boite_id', $b)->orderByDesc('id')->first();
    expect($derniere->actif)->toBeFalse()->and($derniere->identifiant_chiffre)->toBeNull();
    expect(DB::table('envoi_boites_acces_imap')->where('boite_id', $b)->count())->toBe(4);
    $depot->poser($ws, $b, 'imap.orange.fr', DAI_ID, DAI_MDP);
    expect($depot->decrire($ws, $b))->not->toBeNull();
});

test('D1-L3 D3 hôte hors liste, identifiant ou mot de passe vide : refus à message fixe, aucune ligne', function () {
    $ws = daiEspace();
    $b = daiBoite($ws);
    $depot = new DepotAccesImap;
    foreach (DAI_MODES as $mode) {
        foreach ([['imap.societe-a.example.test', DAI_ID, DAI_MDP], ['imap.gmail.com', '', DAI_MDP], ['imap.gmail.com', DAI_ID, ''], ['imap.gmail.com', 'pas-une-adresse', DAI_MDP]] as [$h, $i, $m]) {
            try {
                daiSous($mode, $ws, fn () => $depot->poser($ws, $b, $h, $i, $m));
                $this->fail('refus attendu');
            } catch (InvalidArgumentException $e) {
                expect($e->getMessage())->not->toContain(DAI_MDP)->and($e->getMessage())->not->toContain(DAI_ID);
            }
        }
    }
    expect(DB::table('envoi_boites_acces_imap')->count())->toBe(0);
});

test('D1-L3 D4 cloisonnement : la boîte d’un autre espace est refusée et reste invisible (rôle applicatif ET propriétaire)', function () {
    $a = daiEspace();
    $b = daiEspace();
    $boiteB = daiBoite($b);
    $depot = new DepotAccesImap;
    foreach (DAI_MODES as $mode) {
        expect(fn () => daiSous($mode, $a, fn () => $depot->poser($a, $boiteB, 'imap.gmail.com', DAI_ID, DAI_MDP)))->toThrow(InvalidArgumentException::class);
        expect(fn () => daiSous($mode, $a, fn () => $depot->retirer($a, $boiteB)))->toThrow(InvalidArgumentException::class);
    }
    expect(DB::table('envoi_boites_acces_imap')->count())->toBe(0);
    $depot->poser($b, $boiteB, 'imap.gmail.com', DAI_ID, DAI_MDP);
    foreach (DAI_MODES as $mode) {
        expect(daiSous($mode, $a, fn () => $depot->decrire($a, $boiteB)))->toBeNull($mode);
        expect(daiSous($mode, $a, fn () => $depot->parametres($a, $boiteB)))->toBeNull($mode);
    }
});

test('D1-L3 D5 une ligne illisible lève un message fixe, sans le message d’origine', function () {
    $ws = daiEspace();
    $b = daiBoite($ws);
    DB::table('envoi_boites_acces_imap')->insert(['workspace_id' => $ws, 'boite_id' => $b, 'hote' => 'imap.gmail.com', 'port' => 993, 'dossier' => 'INBOX',
        'identifiant_chiffre' => 'pas-un-chiffre-valide', 'actif' => true]);
    try {
        (new DepotAccesImap)->parametres($ws, $b);
        $this->fail('exception attendue');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('accès IMAP illisible')->and($e->getPrevious())->toBeNull();
    }
});

test('D1-L3 D6 garde statique : le dépôt ne référence ni Webklex ni aucun protocole IMAP', function () {
    $src = (string) file_get_contents(app_path('Envoi/Imap/DepotAccesImap.php'));
    expect($src)->not->toContain('Webklex')->and($src)->not->toContain('ImapProtocol')->and($src)->not->toContain('Http::');
});

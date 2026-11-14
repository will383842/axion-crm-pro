<?php

use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

function espaceFeuVertDur(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'fvd-' . substr($id, 0, 8), 'settings' => json_encode(['produits' => ['envoi' => 'actif']]), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

test('durcissement 1 : preuve trop courte (< 10 après nettoyage) ou générique refusée', function (string $texte) {
    expect(fn () => app(DepotPreuves::class)->ajouter(espaceFeuVertDur(), 'manuel.port25', $texte, 'responsable'))->toThrow(InvalidArgumentException::class);
})->with([
    'ok' => ['ok'], 'neuf caractères' => ['abcdefghi'], 'dix blancs + 3 lettres' => ['          abc          '],
    'phrase fixe apostrophe droite' => ['Fait, constaté depuis l\'écran.'],
    'phrase fixe apostrophe typographique' => ['Fait, constaté depuis l’écran.'],
    'phrase fixe en majuscules, sans ponctuation' => ['FAIT CONSTATÉ DEPUIS L’ÉCRAN'],
    'phrase fixe entourée de blancs' => ["  Fait, constaté depuis l'écran.  "],
]);

test('durcissement 1 : une preuve de 10 caractères ou plus, non générique, est acceptée', function () {
    $ws = espaceFeuVertDur();
    app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', 'Courrier du 10/11, réf. exemple-0042', 'responsable');
    expect(app(DepotPreuves::class)->dernieres($ws)['manuel.port25']['fait'])->toBeTrue();
});

test('durcissement 3 : détection stricte d’adresse e-mail, dans la preuve et dans l’auteur', function (string $texte) {
    $ws = espaceFeuVertDur();
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', 'Preuve valable : ' . $texte, 'responsable'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', 'Preuve valable de dix signes', $texte))->toThrow(InvalidArgumentException::class);
})->with([
    'simple' => ['jean@exemple.test'],
    'espaces autour de @' => ['jean @ exemple.test'],
    'espace avant @' => ['jean @exemple.test'],
    'espace après @' => ['jean@ exemple.test'],
    'arobase pleine chasse' => ["jean\u{FF20}exemple.test"],
    'petite arobase' => ["jean\u{FE6B}exemple.test"],
    'chez, point en lettres' => ['jean CHEZ exemple point test'],
    'arobase en lettres' => ['jean arobase exemple.test'],
    'chez épelé' => ['jean chez exemple point test'],
    'arobase en lettres, majuscules' => ['jean AROBASE exemple point test'],
    'M3 (at) entre parenthèses' => ['jean(at)exemple.test'],
    'M3 (at) espacé, majuscules' => ['jean ( AT ) exemple.test'],
    'M4 [at] entre crochets' => ['jean[at]exemple.test'],
    'M4 [at] espacé' => ['jean [ at ] exemple point test'],
    'M4 {at} entre accolades' => ['jean{at}exemple.test'],
    'M4 (arobase) entre parenthèses' => ['jean(arobase)exemple.test'],
    'M4 chez épelé avec « dot » et domaine .invalid' => ['jean chez exemple dot invalid'],
]);

test('M5 : « chez … point » dans une phrase ordinaire n’est plus un faux positif', function (string $phrase) {
    $ws = espaceFeuVertDur();
    app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', $phrase, 'responsable');
    expect(app(DepotPreuves::class)->dernieres($ws)['manuel.port25']['fait'])->toBeTrue();
})->with([
    'point final' => ['Port 25 ouvert chez ovh point final du ticket'],
    'point de contact' => ['Vu chez scaleway point de contact joignable'],
    'at dans un mot' => ['Constaté chez l’hébergeur : saturation, format, chat ok'],
    'at isolé sans crochets' => ['Rendez-vous at 10h chez l’hébergeur, réf. 0042'],
]);

test('M6 : la détection d’adresse déguisée vaut aussi pour l’auteur', function (string $auteur) {
    $ws = espaceFeuVertDur();
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', 'Preuve valable de dix signes', $auteur))->toThrow(InvalidArgumentException::class);
    expect(DepotPreuves::auteurAffichable($auteur))->toBe('responsable');
})->with(['jean(at)exemple.test', 'jean[at]exemple.test', 'jean arobase exemple.test']);

test('durcissement 3 : « chez » dans une phrase ordinaire reste accepté', function (string $phrase) {
    $ws = espaceFeuVertDur();
    app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', $phrase, 'responsable');
    expect(app(DepotPreuves::class)->dernieres($ws)['manuel.port25']['fait'])->toBeTrue();
})->with([
    'hébergeur' => ['Constaté chez l’hébergeur le 10 novembre'],
    'ticket chez un nom de domaine' => ['Ticket ouvert chez ovh.com'],
    'port 25 chez un hébergeur' => ['Port 25 ouvert chez Scaleway'],
]);

test('durcissement 3 : le refus « chez » ne vise que les formes épelées (point / dot en lettres)', function (string $texte) {
    $ws = espaceFeuVertDur();
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', 'Preuve valable : ' . $texte, 'responsable'))->toThrow(InvalidArgumentException::class);
})->with(['jean chez exemple point test', 'jean CHEZ exemple dot test']);

test('durcissement 3 : une chaîne UTF-8 invalide est refusée (preuve et auteur)', function () {
    $ws = espaceFeuVertDur();
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', "Courrier \xC3\x28 de l'hébergeur", 'responsable'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', 'Preuve valable de dix signes', "resp\xC3\x28"))->toThrow(InvalidArgumentException::class);
});

test('durcissement 4 : les caractères de format \p{Cf} sont refusés (preuve et auteur), même en bord de chaîne', function (string $c) {
    $ws = espaceFeuVertDur();
    foreach (['avant', 'milieu', 'fin'] as $place) {
        $t = match ($place) {
            'avant' => $c . 'Courrier de l’hébergeur reçu', 'milieu' => 'Courrier de' . $c . ' l’hébergeur reçu', 'fin' => 'Courrier de l’hébergeur reçu' . $c,
        };
        expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', $t, 'responsable'))->toThrow(InvalidArgumentException::class);
        expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', 'Preuve valable de dix signes', 'Respon' . $c . 'sable'))->toThrow(InvalidArgumentException::class);
    }
    expect(DB::table('envoi_feu_vert_preuves')->count())->toBe(0);
})->with(['U+200B' => ["\u{200B}"], 'U+202E' => ["\u{202E}"], 'U+FEFF' => ["\u{FEFF}"]]);

test('durcissement 5 : le détail d’un point manuel ne dit « vrai serveur » que s’il attend le serveur réel', function () {
    $ws = espaceFeuVertDur();
    $liste = collect(app(ListeFeuVert::class)->points($ws))->keyBy('code');
    $capacite = $liste['manuel.capacite_reponses']; // serveur_reel = false
    $ptr = $liste['manuel.ptr_fcrdns'];             // serveur_reel = true
    expect($capacite->detail)->not->toContain('serveur')->and($capacite->etat)->toBe('a_faire');
    expect($ptr->detail)->toContain('vrai serveur')->and($ptr->etat)->toBe('attend_serveur_reel');

    app(DepotPreuves::class)->ajouter($ws, 'manuel.capacite_reponses', 'Capacité chiffrée : 20 réponses par jour', 'responsable');
    $apres = collect(app(ListeFeuVert::class)->points($ws))->keyBy('code')['manuel.capacite_reponses'];
    expect($apres->fait)->toBeTrue()->and($apres->detail)->not->toContain('serveur');
});

test('durcissement 6 : défaire exige que la DERNIÈRE ligne soit « fait »', function () {
    $ws = espaceFeuVertDur();
    $d = app(DepotPreuves::class);
    $d->ajouter($ws, 'manuel.port25', 'Première preuve déposée ici', 'responsable');
    $d->defaire($ws, 'manuel.port25', 'Première preuve périmée', 'responsable');
    // Une ligne « fait » a existé, mais la dernière est « défait » : un second défaire est refusé.
    expect(fn () => $d->defaire($ws, 'manuel.port25', 'Deuxième défaire inutile', 'responsable'))->toThrow(InvalidArgumentException::class);
    expect(DB::table('envoi_feu_vert_preuves')->where('workspace_id', $ws)->count())->toBe(2);
    // Refait puis défait : redevient possible.
    $d->ajouter($ws, 'manuel.port25', 'Seconde preuve déposée ici', 'responsable');
    $d->defaire($ws, 'manuel.port25', 'Seconde preuve périmée', 'responsable');
    expect(DB::table('envoi_feu_vert_preuves')->where('workspace_id', $ws)->count())->toBe(4);
});

test('durcissement 7 : clé de désinscription vide ou absente → auto.cle_desinscription non fait', function (mixed $valeur) {
    $ws = espaceFeuVertDur();
    config(['envoi.cle_desinscription' => $valeur]);
    $p = collect(app(ListeFeuVert::class)->points($ws))->firstWhere('code', 'auto.cle_desinscription');
    expect($p->fait)->toBeFalse()->and($p->bloquant)->toBeTrue()->and($p->detail)->not->toBe('');
})->with(['chaîne vide' => [''], 'null' => [null], 'blancs seuls' => ['                                ']]);

test('durcissement 7 bis : clé de désinscription VRAIMENT absente de la config (clé non définie) → non fait', function () {
    $ws = espaceFeuVertDur();
    $envoi = config('envoi');
    unset($envoi['cle_desinscription']);
    config(['envoi' => $envoi]);
    expect(array_key_exists('cle_desinscription', config('envoi')))->toBeFalse();
    $p = collect(app(ListeFeuVert::class)->points($ws))->firstWhere('code', 'auto.cle_desinscription');
    expect($p->fait)->toBeFalse()->and($p->bloquant)->toBeTrue()->and($p->detail)->not->toBe('');
});

test('durcissement 8 : un motif de défaire de moins de 10 caractères est refusé, 10 est accepté', function () {
    $ws = espaceFeuVertDur();
    $d = app(DepotPreuves::class);
    $d->ajouter($ws, 'manuel.port25', 'Première preuve déposée ici', 'responsable');
    expect(fn () => $d->defaire($ws, 'manuel.port25', 'abcdefghi', 'responsable'))->toThrow(InvalidArgumentException::class);
    $d->defaire($ws, 'manuel.port25', 'abcdefghij', 'responsable');
    expect(DB::table('envoi_feu_vert_preuves')->where('workspace_id', $ws)->count())->toBe(2);
});

test('durcissement 9 : ajouter et défaire s’exécutent dans une transaction avec un verrou par espace et par point', function () {
    $ws = espaceFeuVertDur();
    $d = app(DepotPreuves::class);
    $requetes = [];
    DB::listen(function ($q) use (&$requetes) {
        $requetes[] = ['sql' => $q->sql, 'niveau' => DB::transactionLevel()];
    });
    $d->ajouter($ws, 'manuel.port25', 'Première preuve déposée ici', 'responsable');
    $d->defaire($ws, 'manuel.port25', 'Première preuve périmée', 'responsable');
    $verrous = array_keys(array_filter($requetes, fn ($r) => str_contains($r['sql'], 'pg_advisory_xact_lock')));
    $ecritures = array_keys(array_filter($requetes, fn ($r) => str_contains($r['sql'], 'insert into "envoi_feu_vert_preuves"')));
    expect($verrous)->toHaveCount(2)->and($ecritures)->toHaveCount(2);
    // Le verrou précède l'écriture, et la lecture « dernière ligne » du défaire est faite APRÈS le verrou.
    expect($verrous[0])->toBeLessThan($ecritures[0])->and($verrous[1])->toBeLessThan($ecritures[1]);
    $lectureDefaire = array_keys(array_filter($requetes, fn ($r) => str_contains($r['sql'], 'DISTINCT ON (code)')));
    expect(max($lectureDefaire))->toBeGreaterThan($verrous[1])->and(min(array_filter($lectureDefaire, fn ($i) => $i > $verrous[1])))->toBeLessThan($ecritures[1]);
});

test('preuve : `par` et `le` sont lus avec la dernière ligne', function () {
    $ws = espaceFeuVertDur();
    app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', 'Courrier du 10/11, réf. exemple-0042', 'Responsable Test');
    $p = collect(app(ListeFeuVert::class)->points($ws))->firstWhere('code', 'manuel.port25');
    expect($p->preuve)->toBe('Courrier du 10/11, réf. exemple-0042')->and($p->preuvePar)->toBe('Responsable Test')
        ->and($p->preuveLe)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

test('auteur affichable : nom propre gardé, e-mail ou invalide → « responsable »', function (?string $nom, string $attendu) {
    expect(DepotPreuves::auteurAffichable($nom))->toBe($attendu);
})->with([
    'nom' => ['Responsable Test', 'Responsable Test'], 'nom avec blancs' => ['  Responsable Test  ', 'Responsable Test'], 'e-mail' => ['jean@exemple.test', 'responsable'],
    'null' => [null, 'responsable'], 'vide' => ['', 'responsable'], 'trop long' => [str_repeat('a', 101), 'responsable'],
    'format' => ["Respon\u{200B}sable", 'responsable'], 'chez épelé' => ['jean chez exemple point test', 'responsable'],
]);

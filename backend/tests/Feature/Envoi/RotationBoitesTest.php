<?php

/**
 * X11-B — rotation entre boîtes, boîte figée par inscription. Chaque cas est rejoué SOUS LE RÔLE APPLICATIF (pgsql_app, SET LOCAL ROLE)
 * ET SOUS LE PROPRIÉTAIRE. Porte fermée, FauxServeurEnvoi, adresses en example.invalid, aucune personne réelle.
 */

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\PasserelleLocale;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sequence\ChoixBoite;
use App\Envoi\Sequence\ComposeurSequence;
use App\Envoi\Sequence\DemandeComposition;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ParametresExpedition;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Sequence\SourceExpeditionDb;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

const ROT_DOMAINE = 'zz-rotation.example.invalid';

function rotSous(string $mode, string $ws, Closure $f): mixed
{
    WorkspaceContext::set($ws);
    if ($mode === 'proprio') {
        return $f();
    }
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

function rotBoite(string $ws, string $local, string $etat = 'active', string $cree = '2026-01-01 00:00:00+00', string $domaine = ROT_DOMAINE): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_boites')->insert([
        'id' => $id, 'workspace_id' => $ws, 'adresse' => "{$local}@{$domaine}", 'hote' => 'smtp.example.test', 'port' => 587,
        'identifiants_chiffres' => 'chiffre-de-test', 'etat' => $etat, 'cree_le' => $cree,
    ]);

    return $id;
}

function rotInscription(string $ws, ?string $sequence = null, ?string $boite = null, int $contact = 1): stdClass
{
    $sequence ??= LienCampagneSequence::creerSequence(DB::connection(), $ws, null, 2);
    $id = (string) Str::uuid();
    DB::table('envoi_inscriptions')->insert([
        'id' => $id, 'workspace_id' => $ws, 'sequence_id' => $sequence, 'contact_id' => $contact, 'entreprise_id' => 7000 + $contact,
        'empreinte_adresse' => str_repeat('c', 60) . sprintf('%04d', $contact), 'prochaine_echeance' => '2026-11-16 08:00:00+00',
        'version_figee' => json_encode(['fenetre' => ['debut' => 8, 'fin' => 20], 'etapes' => [['sujet' => 'S', 'corps' => 'C', 'delai_jours_ouvres' => 0], ['sujet' => 'S2', 'corps' => 'C2', 'delai_jours_ouvres' => 2]]]),
        'boite_id' => $boite,
    ]);

    return DB::table('envoi_inscriptions')->where('id', $id)->first();
}

beforeEach(function () {
    $this->ws = F::espace('zz-rot');
    $this->jour = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    $this->choix = fn () => new ChoixBoite(new DepotChauffe, new SourceExpeditionDb(app(DepotReglages::class)));
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

it('2 boîtes, 10 inscriptions => 5 et 5 (rotation par envoyés du jour)', function (string $mode) {
    $b1 = rotBoite($this->ws, 'a');
    $b2 = rotBoite($this->ws, 'b', cree: '2026-01-02 00:00:00+00');
    rotSous($mode, $this->ws, function () use ($b1, $b2) {
        $n = [$b1 => 0, $b2 => 0];
        for ($i = 1; $i <= 10; $i++) {
            $ins = rotInscription($this->ws, null, null, $i);
            $b = ($this->choix)()->pour($this->ws, $ins, ROT_DOMAINE, $this->jour);
            $n[$b['id']]++;
            app(DepotChauffe::class)->incrementer($this->ws, $b['id'], $this->jour, 'autre', 'envoyes');
        }
        expect($n)->toBe([$b1 => 5, $b2 => 5]);
    });
})->with(['app', 'proprio']);

it('étape 2 : la même boîte que l\'étape 1, même si une autre est moins employée', function (string $mode) {
    $b1 = rotBoite($this->ws, 'a');
    rotBoite($this->ws, 'b', cree: '2026-01-02 00:00:00+00');
    rotSous($mode, $this->ws, function () use ($b1) {
        app(DepotChauffe::class)->incrementer($this->ws, $b1, $this->jour, 'autre', 'envoyes', 9);
        $ins = rotInscription($this->ws, null, $b1);
        expect(($this->choix)()->pour($this->ws, $ins, ROT_DOMAINE, $this->jour)['id'])->toBe($b1);
    });
})->with(['app', 'proprio']);

it('boîte figée suspendue => ATTENTE, jamais l\'autre boîte', function (string $mode) {
    $b1 = rotBoite($this->ws, 'a', 'en_pause');
    rotBoite($this->ws, 'b');
    rotSous($mode, $this->ws, function () use ($b1) {
        $ins = rotInscription($this->ws, null, $b1);
        try {
            ($this->choix)()->pour($this->ws, $ins, ROT_DOMAINE, $this->jour);
            $this->fail('ATTENTE attendue');
        } catch (EnvoiRefuse $e) {
            expect($e->estAttente())->toBeTrue();
        }
    });
})->with(['app', 'proprio']);

it('Sequenceur : boîte du fil suspendue => reportée, reports inchangé, rien injecté', function (string $mode) {
    $b1 = rotBoite($this->ws, 'a', 'en_pause');
    rotBoite($this->ws, 'b');
    $faux = new FauxServeurEnvoi;
    rotSous($mode, $this->ws, function () use ($b1, $faux) {
        $ins = rotInscription($this->ws, null, $b1);
        DB::table('envoi_domaines')->insert(['workspace_id' => $this->ws, 'nom' => ROT_DOMAINE, 'achete_le' => '2026-01-01']);
        $seq = new Sequenceur(
            new PasserelleLocale($faux, new DepotSocleDb, true, 300, fn () => $this->jour, $this->ws, 300, fn () => WorkspaceContext::current()),
            new LecteurFicheDb,
            new class implements ComposeurSequence
            {
                public function composer(DemandeComposition $d): string
                {
                    return "From: {$d->expediteur}\r\n\r\n{$d->corps}";
                }
            },
            new ParametresExpedition(ROT_DOMAINE, 'a@' . ROT_DOMAINE, 'ZZ SAS'),
            choixBoite: ($this->choix)(),
        );
        expect($seq->traiter($this->ws, $ins->id, $this->jour))->toBe(Sequenceur::REPORTE);
        expect($faux->appels)->toBe(0);
        expect((int) DB::table('envoi_inscriptions')->where('id', $ins->id)->value('reports'))->toBe(0);
        expect(DB::table('envoi_inscriptions')->where('id', $ins->id)->value('boite_id'))->toBe($b1);
    });
})->with(['app', 'proprio']);

it('campagne limitée à la boîte 2 => toutes sur 2', function (string $mode) {
    $b1 = rotBoite($this->ws, 'a');
    $b2 = rotBoite($this->ws, 'b', cree: '2026-01-02 00:00:00+00');
    $campagne = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $campagne, 'workspace_id' => $this->ws, 'nom' => 'Campagne fictive', 'cible' => json_encode(['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive']),
        'boites' => '{' . $b2 . '}', 'nb_etapes' => 2, 'saisie' => json_encode(['objet' => 'Bonjour']), 'cree_par' => 'responsable']);
    $v = 1;
    foreach (['a_valider', 'programmee'] as $s) {
        DB::table('envoi_campagnes')->where('id', $campagne)->update(['statut' => $s, 'version' => ++$v]);
    }
    $r = LienCampagneSequence::poser(DB::connection(), $this->ws, $campagne, $v, 2);
    rotSous($mode, $this->ws, function () use ($r, $b1, $b2) {
        for ($i = 1; $i <= 4; $i++) {
            $b = ($this->choix)()->pour($this->ws, rotInscription($this->ws, $r['sequence'], null, $i), ROT_DOMAINE, $this->jour);
            expect($b['id'])->toBe($b2)->not->toBe($b1);
        }
    });
})->with(['app', 'proprio']);

it('boîte d\'un autre espace ou d\'un autre domaine jamais choisie ; aucune boîte => ATTENTE', function (string $mode) {
    $autre = F::espace('zz-rot-autre');
    rotBoite($autre, 'x');
    rotBoite($this->ws, 'y', domaine: 'zz-autre.example.invalid');
    rotSous($mode, $this->ws, function () {
        expect(fn () => ($this->choix)()->pour($this->ws, rotInscription($this->ws), ROT_DOMAINE, $this->jour))
            ->toThrow(EnvoiRefuse::class);
    });
})->with(['app', 'proprio']);

it('course : la boîte déjà posée par l\'autre ouvrier gagne (WHERE boite_id IS NULL)', function (string $mode) {
    $b1 = rotBoite($this->ws, 'a');
    $b2 = rotBoite($this->ws, 'b', cree: '2026-01-02 00:00:00+00');
    rotSous($mode, $this->ws, function () use ($b1) {
        $perime = rotInscription($this->ws); // lecture périmée : boite_id NULL
        app(DepotChauffe::class)->incrementer($this->ws, $b1, $this->jour, 'autre', 'envoyes', 3); // ce 2e ouvrier choisirait b2
        DB::table('envoi_inscriptions')->where('id', $perime->id)->update(['boite_id' => $b1]); // l'autre ouvrier a gagné avec b1
        $b = ($this->choix)()->pour($this->ws, $perime, ROT_DOMAINE, $this->jour);
        expect($b['id'])->toBe($b1)->and(DB::table('envoi_inscriptions')->where('id', $perime->id)->value('boite_id'))->toBe($b1);
    });
})->with(['app', 'proprio']);

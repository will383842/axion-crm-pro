<?php

use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\Imap\LecteurBoite;
use App\Envoi\Imap\LotBoite;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\JourEnvoi;
use App\Envoi\Temoins\AccesTemoin;
use App\Envoi\Temoins\DepotAccesTemoins;
use App\Envoi\Temoins\LecteurIndesirables;
use App\Envoi\Temoins\LecteurIndesirablesWebklex;
use App\Envoi\Temoins\ReleveTemoins;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-03 10:00:00', 'UTC'));
    config(['envoi.cle_empreinte' => 'cle-de-test-seulement', 'envoi.reel_actif' => true, 'envoi.temoins_releve_actif' => true]);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire(['example.test' => 'gmail', 'yahoo.example.test' => 'autre']));
    app()->forgetInstance(GardeChauffe::class);
});

afterEach(fn () => Carbon::setTestNow());

function espaceAuditR16(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'aud-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function envoyerAuditR16(string $ws, string $destinataire, string $messageId): void
{
    WorkspaceContext::run($ws, function () use ($ws, $destinataire, $messageId) {
        $boite = DB::table('envoi_boites')->where('workspace_id', $ws)->value('id') ?? (string) Str::uuid();
        if (! DB::table('envoi_boites')->where('id', $boite)->exists()) {
            DB::table('envoi_boites')->insert(['id' => $boite, 'workspace_id' => $ws, 'adresse' => 'zz-audit@example.invalid', 'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre']);
        }
        $injection = (string) Str::uuid();
        DB::table('envoi_injections_smtp')->insert(['workspace_id' => $ws, 'boite_id' => $boite, 'id_injection' => $injection, 'cle_idempotence' => 'zz-' . $injection]);
        DB::table('envoi_messages_envoyes')->insert(['workspace_id' => $ws, 'message_id_empreinte' => hash('sha256', 'mid:' . $messageId), 'id_injection' => $injection, 'empreinte_destinataire' => ListeSuppression::empreinte($destinataire)]);
    });
}

function temoinAuditR16(string $ws, string $adresse, string $messageId, string $fournisseur = 'gmail'): void
{
    app(DepotAccesTemoins::class)->poser($ws, $fournisseur, $adresse, 'mdp-de-test');
    envoyerAuditR16($ws, $adresse, $messageId);
}

/** Dossier IMAP simulé avec la vraie sémantique : UID croissants, page coupée AVANT le filtre de date, `suite` sur les UID bruts. */
function dossierSimuleR16(int $anciens, ?string $messageIdRecent, bool $sansFin = false): LecteurBoite
{
    return new class($anciens, $messageIdRecent, $sansFin) implements LecteurBoite
    {
        public function __construct(private int $anciens, private ?string $recent, private bool $sansFin) {}

        public function uidValidity(string $boite): int
        {
            return 1;
        }

        public function lire(string $boite, int $uidApres, ?DateTimeImmutable $depuis = null, int $limite = 100): LotBoite
        {
            $dernier = $this->sansFin ? $uidApres + $limite + 1 : $this->anciens + ($this->recent !== null ? 1 : 0);
            $uids = range($uidApres + 1, min($dernier, $uidApres + $limite));
            $messages = [];
            foreach ($uids as $uid) {
                $recuLe = $uid <= $this->anciens || $this->sansFin ? new DateTimeImmutable('2026-11-01 00:00:00') : new DateTimeImmutable('2026-12-03 09:00:00');
                if ($depuis !== null && $recuLe < $depuis) {
                    continue;
                }
                $id = $uid <= $this->anciens || $this->sansFin ? "tiers-{$uid}@example.invalid" : $this->recent;
                $messages[] = new MessageBrut($uid, $id, null, [], 'x@example.invalid', $recuLe, '', '');
            }

            return new LotBoite($messages, $uids[count($uids) - 1] ?? $uidApres, $dernier > $uidApres + $limite);
        }
    };
}

function releveAvecDossierR16(string $ws, LecteurBoite $dossier): array
{
    $lecteur = new LecteurIndesirablesWebklex(fn (AccesTemoin $a, string $d): LecteurBoite => $dossier);

    return (new ReleveTemoins(app(DepotAccesTemoins::class), $lecteur, app(GardeChauffe::class)))->relever($ws, now()->toDateTimeImmutable());
}

function journeesAutoAuditR16(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->where('auteur', 'releve_auto')->get()->all());
}

function pausesAuditR16(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->where('workspace_id', $ws)->get()->all());
}

// ---------- B1 : fenêtre de 48 h entièrement couverte, sinon panne ----------

test('B1 : 250 anciens + 1 des nôtres récent dans le dossier : pause ouverte', function () {
    $ws = espaceAuditR16();
    temoinAuditR16($ws, 'temoin@example.test', 'nous@envoi.example.invalid');
    $bilan = releveAvecDossierR16($ws, dossierSimuleR16(250, '<nous@envoi.example.invalid>'));
    expect($bilan['indesirables'])->toBe(1)->and($bilan['pannes'])->toBe(0)
        ->and(pausesAuditR16($ws))->toHaveCount(1)->and(journeesAutoAuditR16($ws))->toHaveCount(1);
});

test('B1 : fenêtre non couverte (dossier sans fin) : panne signalée, jamais un faux vert', function () {
    $ws = espaceAuditR16();
    temoinAuditR16($ws, 'temoin@example.test', 'nous@envoi.example.invalid');
    $bilan = releveAvecDossierR16($ws, dossierSimuleR16(0, null, true));
    expect($bilan['pannes'])->toBe(1)->and($bilan['indesirables'])->toBe(0)->and(journeesAutoAuditR16($ws))->toBeEmpty();
});

// ---------- M1 : un constat peut freiner, jamais libérer ----------

function joursVertsAuditR16(string $ws): void
{
    WorkspaceContext::run($ws, function () use ($ws) {
        foreach (DepotSocleDb::FOURNISSEURS_TEMOINS as $f) {
            foreach ([0, 1, 2] as $recul) {
                DB::table('envoi_temoins_journees')->insert(['workspace_id' => $ws, 'jour' => JourEnvoi::de()->modify("-{$recul} days")->format('Y-m-d'), 'fournisseur' => $f, 'reception' => 'inbox', 'auth_alignee' => true, 'bouton_vu' => true, 'post_effectif' => true, 'auteur' => 'saisie']);
            }
        }
    });
}

function troisJoursAuditR16(string $ws): bool
{
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        if ($p->code === 'auto.temoins_3_jours') {
            return $p->fait;
        }
    }
    throw new RuntimeException('point absent');
}

test('M1 : une journée rouge releve_auto reste rouge même si une saisie « inbox » suit', function () {
    $ws = espaceAuditR16();
    joursVertsAuditR16($ws);
    expect(troisJoursAuditR16($ws))->toBeTrue();
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_temoins_journees')->insert(['workspace_id' => $ws, 'jour' => JourEnvoi::de()->format('Y-m-d'), 'fournisseur' => 'gmail', 'reception' => 'indesirables', 'auth_alignee' => false, 'bouton_vu' => false, 'post_effectif' => false, 'auteur' => 'releve_auto']);
    });
    expect(troisJoursAuditR16($ws))->toBeFalse();
    // Saisie manuelle « inbox » posée APRÈS : la journée reste rouge.
    WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->insert(['workspace_id' => $ws, 'jour' => JourEnvoi::de()->format('Y-m-d'), 'fournisseur' => 'gmail', 'reception' => 'inbox', 'auth_alignee' => true, 'bouton_vu' => true, 'post_effectif' => true, 'auteur' => 'quelquun']));
    expect(troisJoursAuditR16($ws))->toBeFalse();
});

test('M1 : une ligne verte au nom de releve_auto ne libère jamais', function () {
    $ws = espaceAuditR16();
    WorkspaceContext::run($ws, function () use ($ws) {
        foreach (DepotSocleDb::FOURNISSEURS_TEMOINS as $f) {
            foreach ([0, 1, 2] as $recul) {
                DB::table('envoi_temoins_journees')->insert(['workspace_id' => $ws, 'jour' => JourEnvoi::de()->modify("-{$recul} days")->format('Y-m-d'), 'fournisseur' => $f, 'reception' => 'inbox', 'auth_alignee' => true, 'bouton_vu' => true, 'post_effectif' => true, 'auteur' => 'releve_auto']);
            }
        }
    });
    expect(troisJoursAuditR16($ws))->toBeFalse();
});

test('M1 : la saisie manuelle refuse l’auteur releve_auto (erreur claire, rien d’écrit)', function () {
    $ws = espaceAuditR16();
    foreach (['releve_auto', ' RELEVE_AUTO '] as $auteur) {
        $code = Artisan::call('envoi:temoins-journee', ['workspace' => $ws, 'fournisseur' => 'gmail', '--reception' => 'inbox', '--auth-alignee' => true, '--bouton-vu' => true, '--post-effectif' => true, '--auteur' => $auteur, '--no-interaction' => true]);
        expect($code)->toBe(1)->and(Artisan::output())->toContain('releve_auto');
    }
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->count()))->toBe(0);
});

// ---------- M2 : une panne reste celle de son espace ----------

function ligneIllisibleAuditR16(string $ws, string $fournisseur = 'gmail'): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_acces')->insert([
        'workspace_id' => $ws, 'empreinte_temoin' => hash('sha256', 'illisible-' . $ws), 'fournisseur' => $fournisseur,
        'hote' => 'imap.gmail.com', 'identifiant_chiffre' => 'ceci-nest-pas-chiffre',
    ]));
}

test('M2 : une ligne illisible n’arrête pas les autres lignes de l’espace', function () {
    $ws = espaceAuditR16();
    ligneIllisibleAuditR16($ws, 'orange');
    temoinAuditR16($ws, 'temoin@example.test', 'nous@envoi.example.invalid');
    $lecteur = new class implements LecteurIndesirables
    {
        public function messageIdsEnIndesirables(AccesTemoin $acces, DateTimeImmutable $depuis): array
        {
            return ['nous@envoi.example.invalid'];
        }
    };
    $bilan = (new ReleveTemoins(app(DepotAccesTemoins::class), $lecteur, app(GardeChauffe::class)))->relever($ws, now()->toDateTimeImmutable());
    expect($bilan['pannes'])->toBe(1)->and($bilan['indesirables'])->toBe(1)->and(pausesAuditR16($ws))->toHaveCount(1);
});

test('M2 : un espace en erreur n’arrête pas les suivants ; échec signalé sans secret', function () {
    $mauvais = espaceAuditR16();
    $bon = espaceAuditR16();
    ligneIllisibleAuditR16($mauvais);
    temoinAuditR16($bon, 'temoin@example.test', 'nous@envoi.example.invalid');
    Log::spy();
    app()->instance(LecteurIndesirables::class, new class implements LecteurIndesirables
    {
        public function messageIdsEnIndesirables(AccesTemoin $acces, DateTimeImmutable $depuis): array
        {
            return ['nous@envoi.example.invalid'];
        }
    });
    expect(Artisan::call('envoi:temoins-releve'))->toBe(1);
    expect(pausesAuditR16($bon))->toHaveCount(1)->and(journeesAutoAuditR16($bon))->toHaveCount(1)->and(pausesAuditR16($mauvais))->toBeEmpty();
    Log::shouldHaveReceived('error')->withArgs(fn ($m, $ctx = []) => ! str_contains(json_encode([$m, $ctx]), 'ceci-nest-pas-chiffre') && ! str_contains(json_encode([$m, $ctx]), 'temoin@example.test'));
});

// ---------- m1 : verrou consultatif de l'espace, deux connexions ----------

test('m1 : verrou de l’espace tenu par une autre connexion : panne signalée, rien d’écrit ; libéré ensuite', function () {
    $ws = espaceAuditR16();
    temoinAuditR16($ws, 'temoin@example.test', 'nous@envoi.example.invalid');
    $autre = DB::connectUsing('autre_connexion_r16', config('database.connections.' . config('database.default')));
    expect((bool) $autre->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS ok', ['envoi_temoins:' . $ws])->ok)->toBeTrue();
    try {
        $bilan = releveAvecDossierR16($ws, dossierSimuleR16(0, '<nous@envoi.example.invalid>'));
        expect($bilan['pannes'])->toBe(1)->and($bilan['indesirables'])->toBe(0)->and(pausesAuditR16($ws))->toBeEmpty();
    } finally {
        $autre->select('SELECT pg_advisory_unlock(hashtext(?))', ['envoi_temoins:' . $ws]);
    }
    $bilan = releveAvecDossierR16($ws, dossierSimuleR16(0, '<nous@envoi.example.invalid>'));
    expect($bilan['indesirables'])->toBe(1);
    // Le verrou est rendu après la relève.
    expect((bool) $autre->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS ok', ['envoi_temoins:' . $ws])->ok)->toBeTrue();
    $autre->select('SELECT pg_advisory_unlock(hashtext(?))', ['envoi_temoins:' . $ws]);
});

// ---------- m4 : espace inexistant ----------

test('m4 : espace inexistant pour envoi:temoins-acces : erreur claire, aucune exception', function () {
    $flux = fopen('php://memory', 'r+');
    fwrite($flux, "temoin@example.test\nmdp-de-test\n");
    rewind($flux);
    app()->instance('envoi.flux_entree', $flux);
    $code = Artisan::call('envoi:temoins-acces', ['workspace' => (string) Str::uuid(), 'fournisseur' => 'gmail', '--no-interaction' => true]);
    expect($code)->toBe(1)->and(Artisan::output())->not->toContain('mdp-de-test')->and(Artisan::output())->not->toContain('temoin@example.test');
});

// ---------- M1 (suite) : une ligne verte déjà posée sous releve_auto ne doit pas faire taire le constat ----------

test('M1 : ligne verte releve_auto déjà présente (index unique) : le constat n’est pas perdu en silence, panne signalée, pause ouverte', function () {
    $ws = espaceAuditR16();
    temoinAuditR16($ws, 'temoin@example.test', 'nous@envoi.example.invalid');
    WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->insert(['workspace_id' => $ws, 'jour' => '2026-12-03', 'fournisseur' => 'gmail', 'reception' => 'inbox', 'auth_alignee' => true, 'bouton_vu' => true, 'post_effectif' => true, 'auteur' => 'releve_auto']));
    $bilan = releveAvecDossierR16($ws, dossierSimuleR16(0, '<nous@envoi.example.invalid>'));
    expect(pausesAuditR16($ws))->toHaveCount(1)->and($bilan['pannes'])->toBe(1);
});

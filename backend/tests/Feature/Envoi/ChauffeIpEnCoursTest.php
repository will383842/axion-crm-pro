<?php

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\DepotIps;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Lot IP-ENCOURS — le plafond d'une IP est tenu aussi par les réservations EN COURS (envoi pas encore noté), pour que deux
 * ouvriers qui partent ensemble ne dépassent pas le quota de l'IP. Joué sous le propriétaire ET sous le rôle applicatif.
 * Données fictives : domaines *.example.test, IP privées. Aucun envoi réel.
 */

const IPE_JOUR = '2026-11-10';
const IPE_CLE = 'cle-de-test-ip-en-cours';
const IPE_IP = '10.0.0.1';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 11:00:00', 'UTC'));
    config(['envoi.cle_empreinte' => IPE_CLE]);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire(['gmail.com' => 'gmail']));
});

afterEach(fn () => Carbon::setTestNow());

function ipeJour(): DateTimeImmutable
{
    return new DateTimeImmutable(IPE_JOUR, new DateTimeZone('Europe/Paris'));
}

function ipeEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Ipe', 'slug' => 'ipe-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    Artisan::call('envoi:temoins-off', ['workspace' => $id, '--auteur' => 'test']);
    WorkspaceContext::run($id, fn () => DB::table('envoi_journal_commandes')->insert([
        'workspace_id' => $id, 'commande' => 'tableau_valide', 'auteur' => 'test', 'details' => '{}', 'jour' => IPE_JOUR, 'cree_le' => '2026-11-10 08:00:00+01',
    ]));

    return $id;
}

/** Domaine `$domaine` avec sa boîte `a@domaine` ; relié à l'IP (ou à aucune). */
function ipeDomaine(string $ws, string $domaine, ?string $ip = IPE_IP): void
{
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $domaine, 'achete_le' => '2026-01-01', 'flux' => 'prospection']);
    WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $ws, 'adresse' => "a@{$domaine}", 'hote' => 'smtp.example.test', 'port' => 587, 'identifiants_chiffres' => 'chiffre-de-test',
    ]));
    if ($ip !== null) {
        $depot = app(DepotIps::class);
        if (! in_array($ip, $depot->ipsParFlux($ws)['prospection'], true)) {
            $depot->ajouter($ws, $ip, 'prospection', 'test');
        }
        $depot->lier($ws, $domaine, $ip, 'test');
    }
}

/** Plafond de l'IP = 20 (recul en P1) dont $notes déjà notés : reste (20 - $notes) place(s). */
function ipePlafondIp(string $ws, int $notes): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_reculs')->insert([
        'workspace_id' => $ws, 'ressource_type' => 'ip', 'ressource' => IPE_IP, 'motif' => 'test', 'auteur' => 'test', 'date_recul' => IPE_JOUR,
    ]));
    app(DepotChauffe::class)->incrementer($ws, IPE_IP, ipeJour(), 'autre', 'envoyes', $notes, BudgetChauffe::RESSOURCE_IP);
}

function ipeReserver(string $ws, string $cle, array $ressources): void
{
    expect(app(DepotSocle::class)->reserverInjection($ws, ipeJour(), $cle, new DateTimeImmutable('2026-11-10 10:00:00'), $ressources))->toBeTrue();
}

/** Exécute $f sous le rôle choisi (contexte d'espace posé), puis restaure. */
function ipeSous(string $mode, string $ws, Closure $f): mixed
{
    DB::beginTransaction();
    try {
        WorkspaceContext::set($ws);
        if ($mode === 'app') {
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }

        return $f();
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
        DB::commit();
    }
}

function ipeEnCours(string $ws, ?string $boite, ?string $domaine, ?string $ip): array
{
    return app(DepotSocle::class)->reservationsEnCours($ws, ipeJour(), $boite, $domaine, $ip);
}

test('IP-ENCOURS 1 deux réservations ouvertes sur 2 boîtes de la même IP : l\'IP en compte 2 ; sans IP, 0', function (string $mode) {
    $ws = ipeEspace();
    ipeReserver($ws, 'r1', ['boite' => 'b1', 'domaine' => 'a.example.test', 'ip' => IPE_IP]);
    ipeReserver($ws, 'r2', ['boite' => 'b2', 'domaine' => 'b.example.test', 'ip' => IPE_IP]);
    ipeReserver($ws, 'r3', ['boite' => 'b3', 'domaine' => 'c.example.test', 'ip' => '10.0.0.2']);
    $c = ipeSous($mode, $ws, fn () => ipeEnCours($ws, 'b1', 'a.example.test', IPE_IP));
    expect($c)->toBe(['boite' => 1, 'domaine' => 1, 'ip' => 2]);
    expect(ipeSous($mode, $ws, fn () => ipeEnCours($ws, 'b1', 'a.example.test', null)))->toBe(['boite' => 1, 'domaine' => 1, 'ip' => 0]);
    expect(ipeSous($mode, $ws, fn () => ipeEnCours($ws, null, null, '10.0.0.3')['ip']))->toBe(0);
})->with(['app', 'proprio']);

test('IP-ENCOURS 2 une réservation libérée ne compte plus pour l\'IP', function (string $mode) {
    $ws = ipeEspace();
    ipeReserver($ws, 'r1', ['boite' => 'b1', 'domaine' => 'a.example.test', 'ip' => IPE_IP]);
    expect(ipeSous($mode, $ws, fn () => ipeEnCours($ws, null, null, IPE_IP)['ip']))->toBe(1);
    expect(ipeSous($mode, $ws, fn () => app(DepotSocle::class)->concluireInjection($ws, ipeJour(), 'r1', 'liberee')))->toBeTrue();
    expect(ipeSous($mode, $ws, fn () => ipeEnCours($ws, null, null, IPE_IP)['ip']))->toBe(0);
})->with(['app', 'proprio']);

test('IP-ENCOURS 3 la même IP réservée dans l\'espace B ne compte pas dans A', function (string $mode) {
    $a = ipeEspace();
    $b = ipeEspace();
    ipeReserver($b, 'r1', ['boite' => 'b1', 'domaine' => 'a.example.test', 'ip' => IPE_IP]);
    ipeReserver($b, 'r2', ['boite' => 'b2', 'domaine' => 'b.example.test', 'ip' => IPE_IP]);
    ipeReserver($a, 'r1', ['boite' => 'b1', 'domaine' => 'a.example.test', 'ip' => IPE_IP]);
    expect(ipeSous($mode, $a, fn () => ipeEnCours($a, null, null, IPE_IP)['ip']))->toBe(1);
    expect(ipeSous($mode, $b, fn () => ipeEnCours($b, null, null, IPE_IP)['ip']))->toBe(2);
})->with(['app', 'proprio']);

/**
 * Serveur dont l'injection « dure » : pendant ce temps la réservation est ouverte et l'envoi PAS encore noté. Écrit dans un
 * fichier pour que le test ET les ouvriers (processus séparés) utilisent le même code.
 */
const IPE_SERVEUR = <<<'PHP'
<?php
use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\RenvoiParMemeCle;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Simulateur\FauxServeurEnvoi;

if (! class_exists('IpeServeurLent', false)) {
    final class IpeServeurLent implements RenvoiParMemeCle, ServeurEnvoi
    {
        public function __construct(private readonly FauxServeurEnvoi $faux, private readonly Closure $pendant) {}

        public function injecter(MessageAEnvoyer $message): ResultatInjection
        {
            ($this->pendant)($message);

            return $this->faux->injecter($message);
        }

        public function etatEnvoi(string $idInjection): EtatMessage { return $this->faux->etatEnvoi($idInjection); }

        public function pause(): void {}

        public function reprise(): void {}

        public function modeTemoins(): bool { return $this->faux->modeTemoins(); }

        public function etat(): EtatServeur { return $this->faux->etat(); }

        public function sante(): SanteServeur { return $this->faux->sante(); }

        public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements { return $this->faux->lireEvenements($curseur, $limite); }
    }
}
PHP;

function ipeFichier(string $nom, string $code): string
{
    $chemin = sys_get_temp_dir() . "/ipe-{$nom}.php";
    file_put_contents($chemin, $code);

    return $chemin;
}

function ipeServeur(FauxServeurEnvoi $faux, Closure $pendant): ServeurEnvoi
{
    require_once ipeFichier('serveur', IPE_SERVEUR);

    return new IpeServeurLent($faux, $pendant);
}

function ipePasserelle(ServeurEnvoi $serveur): PasserelleLocale
{
    $maintenant = new DateTimeImmutable('2026-11-10 10:00:00', new DateTimeZone('Europe/Paris'));
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null);
    $p = new PasserelleLocale($serveur, app(DepotSocle::class), true, 300, fn () => $maintenant, '', 300, null, $garde);
    $p->sante();

    return $p;
}

/** Détails de la réservation ouverte de l'espace (lus pendant l'injection : la réservation est alors `reservee`). */
function ipeDetailsOuverts(string $ws): array
{
    return json_decode((string) WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'injection')->value('details')), true);
}

test('IP-ENCOURS 4 la passerelle écrit l\'IP dans la réservation, et un second envoi pendant le premier est refusé', function (string $mode) {
    $ws = ipeEspace();
    ipeDomaine($ws, 'a.example.test');
    ipeDomaine($ws, 'b.example.test');
    ipePlafondIp($ws, 19); // reste UNE place sur l'IP
    // Les serveurs se construisent SOUS le rôle choisi : leur niveau de transaction « de base » est alors celui de l'appelant.
    [$premier, $second, $enVol, $appelsB] = ipeSous($mode, $ws, function () use ($ws) {
        $fauxB = new FauxServeurEnvoi;
        $secondeP = ipePasserelle($fauxB);
        $enVol = [];
        $second = null;
        $premiere = ipePasserelle(ipeServeur(new FauxServeurEnvoi, function () use ($ws, $secondeP, &$enVol, &$second) {
            $enVol = ipeDetailsOuverts($ws);
            $second = $secondeP->injecter(new MessageAEnvoyer($ws, 'x@gmail.com', 'a@b.example.test', 'S', 'C', 'cle-b'));
        }));

        return [$premiere->injecter(new MessageAEnvoyer($ws, 'x@gmail.com', 'a@a.example.test', 'S', 'C', 'cle-a')), $second, $enVol, $fauxB->appels];
    });
    expect($premier->accepte)->toBeTrue()
        ->and($enVol['ip'] ?? null)->toBe(IPE_IP)
        ->and($second?->accepte)->toBeFalse()
        ->and($appelsB)->toBe(0)
        ->and(WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'injection')->count()))->toBe(1);
})->with(['app', 'proprio']);

test('IP-ENCOURS 5 sans IP connue : rien n\'est écrit pour l\'IP (le compte reste 0, comme avant)', function (string $mode) {
    $ws = ipeEspace();
    ipeDomaine($ws, 'a.example.test', null);
    [$resultat, $enVol] = ipeSous($mode, $ws, function () use ($ws) {
        $enVol = [];
        $p = ipePasserelle(ipeServeur(new FauxServeurEnvoi, function () use ($ws, &$enVol) {
            $enVol = ipeDetailsOuverts($ws);
        }));

        return [$p->injecter(new MessageAEnvoyer($ws, 'x@gmail.com', 'a@a.example.test', 'S', 'C', 'cle-a')), $enVol];
    });
    expect($resultat->accepte)->toBeTrue()->and($enVol)->not->toHaveKey('ip');
})->with(['app', 'proprio']);

// ── Deux processus PHP réels (modèle de PlafondConcurrenceTest et ChauffeConcurrenceTest) ───────────────────────────

/** Ouvrier : tables TEMPORAIRES pour tout ce qui est en ajout seul ; le journal des réservations et le verrou du jour sont PARTAGÉS. */
const IPE_OUVRIER = <<<'PHP'
<?php
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

require $argv[1] . '/tests/Support/garde_environnement_ouvrier.php';
require $argv[1] . '/vendor/autoload.php';
$app = require $argv[1] . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[, , $serveurPhp, $ws, $jour, $cle, $depart, $domaine, $notes, $pause] = $argv;
require $serveurPhp;
while (microtime(true) < (float) $depart) {
    usleep(500);
}
foreach (['envoi_sante_battement', 'envoi_boites', 'envoi_chauffe_jours', 'envoi_chauffe_reculs', 'envoi_ips', 'envoi_domaines'] as $t) {
    DB::statement("CREATE TEMP TABLE {$t} (LIKE public.{$t} INCLUDING ALL)");
}
$ip = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'ip.' . $ws)->toString();
DB::statement("INSERT INTO envoi_ips (id, workspace_id, ref, flux, auteur) VALUES (?, ?, '10.0.0.1', 'prospection', 'test')", [$ip, $ws]);
DB::statement("INSERT INTO envoi_domaines (id, workspace_id, nom, achete_le, ip_id) VALUES (1, ?, ?, '2026-01-01', ?)", [$ws, $domaine, $ip]);
DB::statement("INSERT INTO envoi_boites (id, workspace_id, adresse, hote, port, identifiants_chiffres) VALUES (?, ?, ?, 'smtp.example.test', 587, 'chiffre-de-test')", [Uuid::uuid4()->toString(), $ws, "a@{$domaine}"]);
DB::statement("INSERT INTO envoi_chauffe_reculs (workspace_id, ressource_type, ressource, motif, auteur, date_recul) VALUES (?, 'ip', '10.0.0.1', 'test', 'test', ?)", [$ws, $jour]);
DB::statement("INSERT INTO envoi_chauffe_jours (workspace_id, boite, jour, fournisseur, envoyes, ressource_type) VALUES (?, 'ip:10.0.0.1', ?, 'autre', ?, 'ip')", [$ws, $jour, (int) $notes]);
$faux = new FauxServeurEnvoi;
$serveur = new IpeServeurLent($faux, fn () => usleep((int) ((float) $pause * 1_000_000))); // l'envoi « dure » : l'autre ouvrier passe ses portes pendant ce temps
$maintenant = new DateTimeImmutable($jour . ' 10:00:00', new DateTimeZone('Europe/Paris'));
$garde = new GardeChauffe(new DepotChauffe, new DepotSocleDb, new ResolveurEnMemoire(['example.test' => 'gmail']), null);
$passerelle = new PasserelleLocale($serveur, new DepotSocleDb, true, 300, fn () => $maintenant, $ws, 300, fn () => WorkspaceContext::current(), $garde);
$passerelle->sante();
$r = WorkspaceContext::run($ws, fn () => $passerelle->injecter(new MessageAEnvoyer($ws, 'temoin@example.test', "a@{$domaine}", 'S', 'C', $cle)));
echo json_encode(['accepte' => $r->accepte]), "\n";
PHP;

/** @return list<bool> accepté ou non, pour chacun des deux ouvriers (domaines différents, même IP) */
function ipeDeuxOuvriers(string $ws, string $mode): array
{
    $ouvrier = ipeFichier('ouvrier', IPE_OUVRIER);
    $serveur = ipeFichier('serveur', IPE_SERVEUR);
    $depart = microtime(true) + 3;
    $env = array_merge(getenv(), ['ENVOI_CLE_EMPREINTE' => IPE_CLE, 'APP_ENV' => 'testing', 'CRM_DB_APP_ROLE_ENABLED' => $mode === 'app' ? 'true' : 'false']);
    $procs = [];
    foreach (['a', 'b'] as $x) {
        $cmd = [PHP_BINARY, $ouvrier, base_path(), $serveur, $ws, IPE_JOUR, "cle-{$x}", (string) $depart, "{$x}.example.test", '19', '1.5'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        expect(is_resource($p))->toBeTrue();
        $procs[] = [$p, $pipes];
    }
    $resultats = [];
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        expect(proc_close($p))->toBe(0, "ouvrier en échec : {$err} {$out}");
        $resultats[] = (bool) (json_decode(trim($out), true)['accepte'] ?? false);
    }

    return $resultats;
}

test('IP-ENCOURS 6 deux processus réels, quota d\'IP à 20 dont 19 notés : UN SEUL passe, jamais 21 sur l\'IP', function (string $mode) {
    $owner = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $owner->table('workspaces')->insert(['id' => $ws, 'name' => 'ConcIp', 'slug' => 'concip-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
    $owner->table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::calculer('temoin@example.test', IPE_CLE), 'libelle' => 'test']);
    try {
        $acceptes = ipeDeuxOuvriers($ws, $mode);
        expect(count(array_filter($acceptes)))->toBe(1);
        // Une seule réservation posée : l'autre ouvrier a vu la première (en vol, avec son IP) sous le même verrou et a reporté.
        expect($owner->table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'injection')->count())->toBe(1);
    } finally {
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
        $owner->table('envoi_temoins')->where('workspace_id', $ws)->delete();
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        $owner->table('workspaces')->where('id', $ws)->delete();
        $owner->table('envoi_compteur_global')->where('jour', IPE_JOUR)->delete(); // compteur agrégé, sans donnée de personne
        // Le journal est en ajout seul (sans clé étrangère) : ses lignes de test restent.
    }
})->with(['app', 'proprio']);

<?php

/**
 * D1-L4 (cahier #617, section 5) : verrou par boîte, délai de grâce, borne de temps de la relève.
 * AUCUN réseau : lecteurs en mémoire, données FICTIVES (example.test). Les tests G1 et G3 tournent sous le rôle
 * applicatif (RLS) ET sous le propriétaire (G7).
 */

use App\Envoi\Imap\BoiteOccupee;
use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\LecteurBoite;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\LecteurBoiteWebklex;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\MessageOrigine;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RegistreMessagesEnvoyesMemoire;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\ReleveImapCommande;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

uses(TestCase::class, RefreshDatabase::class);

/** Fabrique de test : un lecteur par boîte, appels comptés, rappel optionnel à chaque demande. */
final class FabriqueVerrouPourTest implements FabriqueLecteurs
{
    /** @var array<string, LecteurBoiteMemoire> */
    public array $lecteurs = [];

    public int $demandes = 0;

    public ?Closure $aChaqueDemande = null;

    public function fabrique(string $boite): LecteurBoite
    {
        $this->demandes++;
        if ($this->aChaqueDemande !== null) {
            ($this->aChaqueDemande)($boite);
        }

        return $this->lecteurs[$boite] ??= new LecteurBoiteMemoire;
    }
}

function vgMessage(int $uid, DateTimeImmutable $recuLe, ?string $messageId = null): MessageBrut
{
    return new MessageBrut(
        $uid,
        $messageId ?? "m{$uid}@zz-client.example.test",
        'envoi-1@d.example.test',
        [],
        'contact@zz-client.example.test',
        $recuLe,
        'Re: bonjour',
        'Merci.',
        [],
        null,
        null,
        null,
    );
}

/** Horodatage relatif à l'horloge de test (Carbon::setTestNow), pas à l'horloge réelle. */
function vgIl(int $secondes): DateTimeImmutable
{
    return new DateTimeImmutable(now()->addSeconds($secondes)->toIso8601String());
}

/** Lecture sous le contexte d'espace (RLS : le rôle applicatif ne voit rien sans lui). */
function vgLire(Closure $f): mixed
{
    return WorkspaceContext::run((string) $GLOBALS['vg_ws'], $f);
}

function vgCle(string $ws, string $boite): string
{
    return 'envoi:releve:' . $ws . ':' . $boite;
}

/** Une AUTRE connexion à la base : prend ou teste un verrou de session comme le ferait un autre processus. */
function vgAutreConnexion(): Connection
{
    return (new ConnectionFactory(app()))->make(config('database.connections.' . config('database.default')), 'vg_autre');
}

function vgPrendre(Connection $c, string $cle): bool
{
    return (bool) $c->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS ok', [$cle])->ok;
}

function vgLiberer(Connection $c, string $cle): void
{
    $c->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$cle]);
}

function vgCurseur(string $boite): ?int
{
    $v = vgLire(fn () => DB::table('envoi_curseurs_imap')->where('boite', $boite)->value('dernier_uid'));

    return $v === null ? null : (int) $v;
}

/** Joue $f sous le mode : « app » = rôle applicatif non propriétaire (RLS), « proprio » = propriétaire. */
function vgSous(string $mode, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    DB::statement('SET ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00', 'UTC'));
    $this->ws = $GLOBALS['vg_ws'] = F::espace('zz-releve-verrou');
    config(['crm.ingest.business_workspace' => F::slug($this->ws), 'envoi.reel_actif' => true, 'envoi.releve_actif' => true]);
    $this->boites = [(string) Str::uuid(), (string) Str::uuid()];
    sort($this->boites);
    foreach ($this->boites as $i => $id) {
        DB::table('envoi_boites')->insert([
            'id' => $id, 'workspace_id' => $this->ws, 'adresse' => "zz-boite{$i}@test.invalid",
            'hote' => 'smtp.example.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre',
        ]);
    }
    $this->injection = (string) Str::uuid();
    DB::table('envoi_injections_smtp')->insert([
        'workspace_id' => $this->ws, 'boite_id' => $this->boites[0], 'id_injection' => $this->injection, 'cle_idempotence' => 'zz-' . $this->injection,
    ]);
    $this->fabrique = new FabriqueVerrouPourTest;
    $this->registre = new RegistreMessagesEnvoyesMemoire;
    $this->releve = new ReleveImap($this->fabrique, $this->registre, new RecepteurRetoursCrm);
    $this->app->bind(ReleveImap::class, fn () => $this->releve);
    $this->autre = vgAutreConnexion();
});

afterEach(function () {
    Carbon::setTestNow();
    $this->autre->select('SELECT pg_advisory_unlock_all()');
    $this->autre->disconnect();
});

test('G1 : verrou pris ailleurs ⇒ relever ne lit rien, curseur inchangé ; la commande rend 0 et compte « occupee » (rôle applicatif ET propriétaire)', function (string $mode) {
    [$boite] = $this->boites;
    $lecteur = $this->fabrique->fabrique($boite);
    $lecteur->deposer($boite, vgMessage(1, vgIl(-86400)));
    $this->fabrique->demandes = 0;
    expect(vgPrendre($this->autre, vgCle($this->ws, $boite)))->toBeTrue();

    vgSous($mode, function () use ($boite) {
        expect(fn () => $this->releve->relever($this->ws, $boite))->toThrow(BoiteOccupee::class);
        expect($this->fabrique->demandes)->toBe(0)->and(vgCurseur($boite))->toBeNull();
        expect(vgLire(fn () => DB::table('envoi_evenements')->where('boite', $boite)->count()))->toBe(0);

        $code = Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);
        expect($code)->toBe(0)->and(Artisan::output())->toContain('occupee=1');
    });
    expect(vgCurseur($boite))->toBeNull();
})->with(['app', 'proprio']);

test('G1 : la clé du verrou porte la boîte — une AUTRE boîte du même espace reste relevable', function () {
    [$a, $b] = $this->boites;
    $this->fabrique->fabrique($b)->deposer($b, vgMessage(1, vgIl(-86400)));
    vgPrendre($this->autre, vgCle($this->ws, $a));

    $bilan = $this->releve->relever($this->ws, $b);

    expect($bilan->lus)->toBe(1)->and(vgCurseur($b))->toBe(1);
});

test('G2 : le verrou est libéré même si le lecteur lève ; la relève suivante est possible', function () {
    [$boite] = $this->boites;
    $lecteur = $this->fabrique->fabrique($boite);
    $lecteur->deposer($boite, vgMessage(1, vgIl(-86400)));
    $lecteur->mettreEnPanne($boite);

    expect(fn () => $this->releve->relever($this->ws, $boite))->toThrow(RuntimeException::class);
    expect(vgPrendre($this->autre, vgCle($this->ws, $boite)))->toBeTrue('verrou resté pris après la panne');
    vgLiberer($this->autre, vgCle($this->ws, $boite));

    $lecteur->retablir($boite);
    expect($this->releve->relever($this->ws, $boite)->lus)->toBe(1);
});

test('G3 : un message de 60 s n\'est pas traité (curseur avant lui) ; 4 min plus tard il est traité ET rattaché, le registre ayant été écrit entre-temps (rôle applicatif ET propriétaire)', function (string $mode) {
    [$boite] = $this->boites;
    $lecteur = $this->fabrique->fabrique($boite);
    $lecteur->deposer($boite, vgMessage(1, vgIl(-86400)));
    // Une réponse automatique très rapide : reçue AVANT que le séquenceur n'écrive le registre (temps C).
    $lecteur->deposer($boite, new MessageBrut(
        2,
        'reponse-rapide@zz-client.example.test',
        'envoi-1@d.example.test',
        [],
        'contact@zz-client.example.test',
        vgIl(-60),
        'Re: bonjour',
        'Merci.',
        [],
        null,
        null,
        null,
    ));

    vgSous($mode, function () use ($boite) {
        $premier = $this->releve->relever($this->ws, $boite);
        expect($premier->lus)->toBe(1)->and(vgCurseur($boite))->toBe(1);
        expect(vgLire(fn () => DB::table('envoi_evenements')->where('boite', $boite)->count()))->toBe(1);

        $this->registre->noter('envoi-1@d.example.test', new MessageOrigine($this->injection, 'contact@zz-client.example.test'), $this->ws);
        Carbon::setTestNow(now()->addMinutes(4));
        $second = $this->releve->relever($this->ws, $boite);

        expect($second->lus)->toBe(1)->and($second->rattaches)->toBe(1)->and($second->aRattacher)->toBe(0)->and(vgCurseur($boite))->toBe(2);
        expect(vgLire(fn () => DB::table('envoi_evenements')->where('boite', $boite)->where('uid', 2)->value('statut')))->toBe('rattache');
    });
})->with(['app', 'proprio']);

test('G4 : recuLe à +2 jours ⇒ traité au premier passage (pas de blocage) ; +1 heure ⇒ relu plus tard', function () {
    [$boite] = $this->boites;
    $lecteur = $this->fabrique->fabrique($boite);
    $lecteur->deposer($boite, vgMessage(1, vgIl(2 * 86400)));
    $lecteur->deposer($boite, vgMessage(2, vgIl(3600)));

    $bilan = $this->releve->relever($this->ws, $boite);

    expect($bilan->lus)->toBe(1)->and(vgCurseur($boite))->toBe(1);
});

test('G5 : rattraper avec le verrou tenu ⇒ refusé, aucun curseur posé', function () {
    [$boite] = $this->boites;
    $this->fabrique->fabrique($boite)->deposer($boite, vgMessage(1, vgIl(-86400)));
    vgPrendre($this->autre, vgCle($this->ws, $boite));

    expect(fn () => $this->releve->rattraper($this->ws, $boite, new DateTimeImmutable('now')))->toThrow(BoiteOccupee::class);
    expect(vgCurseur($boite))->toBeNull()->and(vgLire(fn () => DB::table('envoi_evenements')->where('boite', $boite)->count()))->toBe(0);
});

test('G6 : borne de temps atteinte ⇒ la boîte suivante n\'est pas commencée, « hors_borne » = 1, code 0', function () {
    [$a, $b] = $this->boites;
    $this->fabrique->aChaqueDemande = function () {
        Carbon::setTestNow(now()->addSeconds(50)); // la première boîte est lente
    };
    $this->fabrique->fabrique($a)->deposer($a, vgMessage(1, vgIl(-86400)));
    $this->fabrique->fabrique($b)->deposer($b, vgMessage(1, vgIl(-86400)));
    $this->fabrique->demandes = 0;

    $code = Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);

    expect($code)->toBe(0)->and(Artisan::output())->toContain('hors_borne=1')
        ->and(vgCurseur($a))->toBe(1)->and(vgCurseur($b))->toBeNull();
});

test('délais réseau : le lecteur réel règle le délai de la bibliothèque (15 s) avant de se connecter', function () {
    $vu = (object) ['timeout' => null];
    $protocole = new class(Config::make(), true, 'ssl', $vu) extends ImapProtocol
    {
        public function __construct($config, $cert, $enc, private object $vu)
        {
            parent::__construct($config, $cert, $enc);
        }

        public function createStream($transport, string $host, int $port, int $timeout)
        {
            $this->vu->timeout = $timeout;
            throw new RuntimeException('arrêt avant tout réseau');
        }
    };
    $lecteur = new LecteurBoiteWebklex(['host' => 'imap.zz.example.test', 'username' => 'u', 'password' => 'p'], 'b', 'INBOX', fn () => $protocole);

    try {
        $lecteur->uidValidity('b');
    } catch (Throwable) {
        // attendu : arrêt avant tout réseau
    }
    expect($vu->timeout)->toBe(LecteurBoiteWebklex::DELAI_RESEAU_SECONDES)->toBe(15);
});

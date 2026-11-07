<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Smtp\AdaptateurSmtp;
use App\Envoi\Smtp\BoitePauseAutomatique;
use App\Envoi\Smtp\BoiteSmtp;
use App\Envoi\Smtp\DepotBoites;
use App\Envoi\Smtp\EnvoiSmtpRefuse;
use App\Envoi\Smtp\ResolveurHote;
use App\Envoi\Smtp\SourceMessageSigne;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

require_once __DIR__ . '/../../Envoi/KitConformiteServeurEnvoi.php';

uses(TestCase::class, RefreshDatabase::class);

const BRUT_DKIM = "DKIM-Signature: v=1; a=rsa-sha256; d=example.test; s=s1; bh=AAAA; b=BBBB\r\nFrom: from@example.test\r\nTo: temoin@example.test\r\nSubject: S\r\n\r\nCorps du message\r\n";

const SECRET_TEST = 'SECRET-DE-TEST-UNIQUE';

/**
 * Dérogations de TEST, jamais honorées hors APP_ENV=testing (cf. GardeHoteSmtp) :
 *  - la boucle locale 127.0.0.1 (faux serveur SMTP) ;
 *  - l'injection dans la transaction englobante de RefreshDatabase.
 */
beforeEach(function () {
    config(['envoi.smtp_boucle_tests' => true, 'envoi.smtp_tolere_transaction_englobante' => true]);
});

/** Résolveur FAUX : aucun DNS réel. Une réponse par appel (la dernière se répète) : sert à simuler un rebinding. */
final class ResolveurFaux implements ResolveurHote
{
    /** @var array<string, int> */
    public array $appels = [];

    /** @param array<string, list<list<string>>> $table */
    public function __construct(private array $table = []) {}

    public function resoudre(string $hote): array
    {
        $n = $this->appels[$hote] = ($this->appels[$hote] ?? 0) + 1;
        $reponses = $this->table[$hote] ?? throw new RuntimeException("Hôte non résolu (faux résolveur) : {$hote}");

        return $reponses[min($n, count($reponses)) - 1];
    }
}

function smtpEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'T', 'slug' => 'smtp-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** @return array{0: string, 1: string} [workspace, boite] — boucle locale, SANS TLS, SANS identifiants envoyés (tests seulement). */
function smtpBoite(string $hote = '127.0.0.1', int $port = 2525, string $secret = SECRET_TEST, ?string $ws = null): array
{
    $ws ??= smtpEspace();
    $boite = (new DepotBoites(new ResolveurFaux))->creer($ws, 'from@example.test', $hote, $port, 'aucun', 'utilisateur', $secret);

    return [$ws, $boite];
}

/** Boîte « de production » : hôte public (résolu par le faux résolveur), STARTTLS. */
function smtpBoitePublique(ResolveurFaux $r, ?string $ws = null, string $chiffrement = 'starttls', int $port = 587): array
{
    $ws ??= smtpEspace();
    $boite = (new DepotBoites($r))->creer($ws, 'from@example.test', 'smtp.exemple.test', $port, $chiffrement, 'utilisateur', SECRET_TEST);

    return [$ws, $boite];
}

function smtpResolveurPublic(): ResolveurFaux
{
    return new ResolveurFaux(['smtp.exemple.test' => [['93.184.216.34']]]);
}

function smtpSource(?string $brut = null): SourceMessageSigne
{
    return new class($brut ?? BRUT_DKIM) implements SourceMessageSigne
    {
        public function __construct(private string $brut) {}

        public function brut(MessageAEnvoyer $message): string
        {
            return $this->brut;
        }
    };
}

function smtpMsg(string $ws, string $cle = 'k1', string $destinataire = 'temoin@example.test', string $expediteur = 'from@example.test'): MessageAEnvoyer
{
    return new MessageAEnvoyer($ws, $destinataire, $expediteur, 'S', 'C', $cle);
}

function smtpAdaptateur(string $ws, string $boite, ?Closure $fabrique = null, ?string $brut = null, ?ResolveurHote $resolveur = null): AdaptateurSmtp
{
    return new AdaptateurSmtp($ws, $boite, smtpSource($brut), new DepotBoites($resolveur ?? new ResolveurFaux), app(DepotSocle::class), $fabrique, $resolveur ?? new ResolveurFaux);
}

/** Toutes les lectures passent par le contexte d'espace (jamais de lecture « nue »). @return list<object> */
function smtpLignes(string $ws, ?string $cle = null): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_injections_smtp')
        ->where('workspace_id', $ws)->when($cle !== null, fn ($q) => $q->where('cle_idempotence', $cle))->orderBy('id')->get()->all());
}

function smtpEtat(string $ws, string $cle): ?string
{
    return smtpLignes($ws, $cle)[0]->etat ?? null;
}

function smtpEtatBoite(string $ws, string $boite): ?string
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('workspace_id', $ws)->where('id', $boite)->value('etat'));
}

/** Transport factice : aucun réseau. */
function smtpTransport(Closure $reaction): Closure
{
    return fn (BoiteSmtp $b) => new class($reaction) implements TransportInterface
    {
        public function __construct(private Closure $reaction) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            ($this->reaction)($message);

            return null;
        }

        public function __toString(): string
        {
            return 'faux://';
        }
    };
}

/**
 * Lance le faux serveur SMTP local, exécute $corps($port, $fichier) puis le FERME
 * TOUJOURS (finally) : processus terminé, tubes fermés, fichiers supprimés.
 *
 * @template T
 *
 * @param  Closure(int, string): T  $corps
 * @return T
 */
function smtpAvecFauxServeur(string $mode, Closure $corps): mixed
{
    $fichier = (string) tempnam(sys_get_temp_dir(), 'smtp');
    $pipes = [];
    $p = proc_open([PHP_BINARY, __DIR__ . '/../../Support/faux_smtp_serveur.php', $mode, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($p)) {
        throw new RuntimeException('Faux serveur SMTP non démarré.');
    }
    try {
        $port = (int) trim((string) fgets($pipes[1]));
        expect($port)->toBeGreaterThan(0);

        return $corps($port, $fichier);
    } finally {
        @proc_terminate($p);
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        @proc_close($p);
        @unlink($fichier);
        @unlink($fichier . '.cmd');
    }
}

kitConformiteServeurEnvoi(function () {
    $ws = '00000000-0000-4000-8000-000000000001'; // espace imposé par le kit
    DB::table('workspaces')->insertOrIgnore(['id' => $ws, 'name' => 'Kit', 'slug' => 'kit-smtp', 'created_at' => now(), 'updated_at' => now()]);
    [, $boite] = smtpBoite(ws: $ws, secret: 's');

    return smtpAdaptateur($ws, $boite, smtpTransport(fn () => null));
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. Secret : jamais en clair, jamais dans un dump, une sérialisation ni une trace
// ─────────────────────────────────────────────────────────────────────────────

test('identifiants chiffrés en base, jamais en clair', function () {
    [$ws, $boite] = smtpBoite();
    $brut = (string) WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('id', $boite)->value('identifiants_chiffres'));
    expect($brut)->not->toContain(SECRET_TEST)->and($brut)->not->toBe('');
    expect((new DepotBoites(new ResolveurFaux))->trouver($ws, $boite)->secret())->toBe(SECRET_TEST);
});

test('le secret n’apparaît dans aucune représentation : print_r, var_export, var_dump, json, (array), serialize, __sleep', function () {
    [$ws, $boite] = smtpBoite();
    $b = (new DepotBoites(new ResolveurFaux))->trouver($ws, $boite);
    expect($b->secret())->toBe(SECRET_TEST); // témoin : le secret est bien là pour l'usage légitime

    ob_start();
    var_dump($b);
    var_dump((array) $b);
    $dump = (string) ob_get_clean();

    $vues = [
        print_r($b, true), var_export($b, true), json_encode($b), $dump,
        print_r((array) $b, true), var_export((array) $b, true), json_encode((array) $b),
        (string) json_encode(get_object_vars($b)),
    ];
    foreach ($vues as $vue) {
        expect($vue)->not->toContain(SECRET_TEST);
    }

    expect(fn () => serialize($b))->toThrow(LogicException::class);
    expect(fn () => $b->__sleep())->toThrow(LogicException::class);
    expect(fn () => $b->__serialize())->toThrow(LogicException::class);
    expect(print_r($b->__debugInfo(), true))->not->toContain(SECRET_TEST)->and(print_r($b->__debugInfo(), true))->toContain('***');
});

test('un refus à la création ne laisse pas le secret dans la trace de l’exception', function () {
    $r = new ResolveurFaux;
    try {
        (new DepotBoites($r))->creer(smtpEspace(), 'from@example.test', '10.0.0.1', 587, 'starttls', 'u', SECRET_TEST);
        $trace = 'aucune exception';
    } catch (Throwable $e) {
        $trace = (string) $e . $e->getTraceAsString() . print_r(array_map(fn ($t) => $t['args'] ?? [], $e->getTrace()), true);
    }
    expect($trace)->not->toBe('aucune exception')->and($trace)->not->toContain(SECRET_TEST);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. TLS : jamais d'identifiants en clair ; STARTTLS exigé
// ─────────────────────────────────────────────────────────────────────────────

test('STARTTLS est EXIGÉ (setRequireTls) et le certificat reste validé', function () {
    $r = smtpResolveurPublic();
    [$ws, $id] = smtpBoitePublique($r, chiffrement: 'starttls', port: 587);
    $boite = (new DepotBoites($r))->trouver($ws, $id);
    $t = AdaptateurSmtp::transportParDefaut($boite, $r);
    expect($t)->toBeInstanceOf(EsmtpTransport::class)
        ->and($t->isTlsRequired())->toBeTrue()
        ->and($t->getUsername())->toBe('utilisateur');
    $opts = $t->getStream()->getStreamOptions();
    expect($opts['ssl']['peer_name'] ?? null)->toBe('smtp.exemple.test') // le nom, pas l'IP : le certificat est validé contre le nom
        ->and($opts['ssl']['verify_peer'] ?? true)->toBeTrue()
        ->and($opts['ssl']['verify_peer_name'] ?? true)->toBeTrue();
});

test('TLS implicite (465) : flux chiffré, identifiants permis', function () {
    $r = smtpResolveurPublic();
    [$ws, $id] = smtpBoitePublique($r, chiffrement: 'tls', port: 465);
    $t = AdaptateurSmtp::transportParDefaut((new DepotBoites($r))->trouver($ws, $id), $r);
    expect($t->getStream()->isTLS())->toBeTrue()->and($t->getUsername())->toBe('utilisateur');
});

test('mode « aucun » : AUCUN identifiant n’est jamais envoyé, même si le serveur propose AUTH', function () {
    smtpAvecFauxServeur('ok', function (int $port, string $fichier) {
        [$ws, $boite] = smtpBoite('127.0.0.1', $port);
        $transport = AdaptateurSmtp::transportParDefaut((new DepotBoites(new ResolveurFaux))->trouver($ws, $boite), new ResolveurFaux);
        expect($transport->getUsername())->toBe('')->and($transport->getPassword())->toBe('');
        expect(smtpAdaptateur($ws, $boite)->injecter(smtpMsg($ws))->accepte)->toBeTrue();
        $commandes = (string) file_get_contents($fichier . '.cmd');
        expect($commandes)->toContain('EHLO')->and(strtoupper($commandes))->not->toContain('AUTH')
            ->and($commandes)->not->toContain(base64_encode("\0utilisateur\0" . SECRET_TEST));
    });
});

test('« aucun » est refusé hors boucle locale de test : à la création, au transport, et par la base', function () {
    $r = smtpResolveurPublic();
    // 1. à la création, hôte public
    expect(fn () => (new DepotBoites($r))->creer(smtpEspace(), 'from@example.test', 'smtp.exemple.test', 587, 'aucun', 'u', 's'))->toThrow(EnvoiSmtpRefuse::class);
    // 2. même la boucle locale, si la dérogation de test est retirée
    config(['envoi.smtp_boucle_tests' => false]);
    expect(fn () => (new DepotBoites(new ResolveurFaux))->creer(smtpEspace(), 'from@example.test', '127.0.0.1', 2525, 'aucun', 'u', 's'))->toThrow(EnvoiSmtpRefuse::class);
    // 3. au transport : une boîte « aucun » construite à la main n'ouvre aucune connexion
    $boite = new BoiteSmtp((string) Str::uuid(), (string) Str::uuid(), 'from@example.test', '127.0.0.1', 2525, 'aucun', 'u', 's', false);
    expect(fn () => AdaptateurSmtp::transportParDefaut($boite, new ResolveurFaux))->toThrow(EnvoiSmtpRefuse::class);
    // 4. la base elle-même refuse « aucun » sur un hôte public (contrainte additive 000042)
    $ws = smtpEspace();
    expect(fn () => DB::transaction(fn () => DB::table('envoi_boites')->insert([
        'workspace_id' => $ws, 'adresse' => 'from@example.test', 'hote' => 'smtp.exemple.test', 'port' => 587,
        'chiffrement' => 'aucun', 'identifiants_chiffres' => 'x',
    ])))->toThrow(QueryException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. SSRF : ports, hôtes privés, rebinding — résolveur FAUX, aucun réseau réel
// ─────────────────────────────────────────────────────────────────────────────

test('ports hors liste blanche (25, 465, 587, 2525) refusés, aucune ligne créée', function (int $port) {
    $r = smtpResolveurPublic();
    $ws = smtpEspace();
    expect(fn () => (new DepotBoites($r))->creer($ws, 'from@example.test', 'smtp.exemple.test', $port, 'starttls', 'u', 's'))->toThrow(EnvoiSmtpRefuse::class);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->count()))->toBe(0);
})->with([22, 80, 110, 143, 3306, 5432, 6379, 8080, 9200, 11211, 65535]);

test('ports de la liste blanche acceptés', function (int $port) {
    $r = smtpResolveurPublic();
    [$ws, $id] = smtpBoitePublique($r, chiffrement: $port === 465 ? 'tls' : 'starttls', port: $port);
    expect((new DepotBoites($r))->trouver($ws, $id)?->port)->toBe($port);
})->with([25, 465, 587, 2525]);

test('hôtes privés, bouclage, link-local, métadonnées refusés (littéraux)', function (string $hote) {
    config(['envoi.smtp_boucle_tests' => false]);
    $ws = smtpEspace();
    expect(fn () => (new DepotBoites(new ResolveurFaux))->creer($ws, 'from@example.test', $hote, 587, 'starttls', 'u', 's'))->toThrow(EnvoiSmtpRefuse::class);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->count()))->toBe(0);
})->with([
    '127.0.0.1', '127.1.2.3', '0.0.0.0', '10.0.0.1', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '169.254.0.1',
    '100.64.0.1', '192.0.0.8', '198.18.0.1', '224.0.0.1', '255.255.255.255',
    '::1', '::', 'fe80::1', 'fd00:ec2::254', 'fc00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::7f00:1', 'ff02::1',
    'localhost', 'LOCALHOST', 'sous.localhost', 'metadata.google.internal', 'service.internal', 'imprimante.local',
]);

test('un nom public qui RÉSOUT vers une adresse privée est refusé (une seule adresse privée suffit)', function (array $adresses) {
    config(['envoi.smtp_boucle_tests' => false]);
    $r = new ResolveurFaux(['smtp.exemple.test' => [$adresses]]);
    expect(fn () => (new DepotBoites($r))->creer(smtpEspace(), 'from@example.test', 'smtp.exemple.test', 587, 'starttls', 'u', 's'))->toThrow(EnvoiSmtpRefuse::class);
})->with([
    'bouclage' => [['127.0.0.1']], 'privé' => [['10.1.2.3']], 'métadonnées' => [['169.254.169.254']],
    'mixte' => [['93.184.216.34', '192.168.0.9']], 'v6 bouclage' => [['::1']], 'v6 ULA' => [['fd12:3456::1']],
    'aucune' => [[]],
]);

test('un nom non résoluble est refusé', function () {
    expect(fn () => (new DepotBoites(new ResolveurFaux))->creer(smtpEspace(), 'from@example.test', 'inconnu.exemple.test', 587, 'starttls', 'u', 's'))->toThrow(EnvoiSmtpRefuse::class);
});

test('REBINDING : l’hôte était public à la création, privé à la connexion => aucune connexion, aucune ligne', function () {
    // 1re résolution (création de la boîte) : publique ; 2e (au moment de la connexion) : privée.
    $r = new ResolveurFaux(['smtp.exemple.test' => [['93.184.216.34'], ['10.0.0.5']]]);
    [$ws, $id] = smtpBoitePublique($r);
    $s = smtpAdaptateur($ws, $id, null, resolveur: $r);

    expect(fn () => $s->injecter(smtpMsg($ws, 'k-rebind')))->toThrow(EnvoiSmtpRefuse::class);
    expect($r->appels['smtp.exemple.test'])->toBe(2);
    expect(smtpLignes($ws))->toBeEmpty(); // refus AVANT tout passage à injection_en_cours
});

test('la connexion est ÉPINGLÉE sur l’adresse contrôlée (pas de seconde résolution par le transport)', function () {
    $r = new ResolveurFaux(['smtp.exemple.test' => [['93.184.216.34'], ['10.0.0.5']]]);
    [$ws, $id] = smtpBoitePublique(new ResolveurFaux(['smtp.exemple.test' => [['93.184.216.34']]]));
    $boite = (new DepotBoites(new ResolveurFaux(['smtp.exemple.test' => [['93.184.216.34']]])))->trouver($ws, $id);
    $t = AdaptateurSmtp::transportParDefaut($boite, $r);
    expect($t->getStream()->getHost())->toBe('93.184.216.34');
    expect($r->appels['smtp.exemple.test'])->toBe(1);
    // et le même contrôle, 2e réponse privée : refus net
    expect(fn () => AdaptateurSmtp::transportParDefaut($boite, $r))->toThrow(EnvoiSmtpRefuse::class);
});

test('adresse IPv6 publique : épinglée entre crochets', function () {
    $r = new ResolveurFaux(['smtp.exemple.test' => [['2606:2800:220:1:248:1893:25c8:1946']]]);
    [$ws, $id] = smtpBoitePublique($r);
    $t = AdaptateurSmtp::transportParDefaut((new DepotBoites($r))->trouver($ws, $id), $r);
    expect($t->getStream()->getHost())->toBe('[2606:2800:220:1:248:1893:25c8:1946]');
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Comportement nominal
// ─────────────────────────────────────────────────────────────────────────────

test('le message signé DKIM est transmis octet pour octet', function () {
    [$ws, $boite] = smtpBoite();
    $vu = null;
    $r = smtpAdaptateur($ws, $boite, smtpTransport(function (RawMessage $m) use (&$vu) {
        $vu = implode('', iterator_to_array($m->toIterable(), false));
    }))->injecter(smtpMsg($ws));
    expect($r->accepte)->toBeTrue()->and($vu)->toBe(BRUT_DKIM);
    expect(smtpEtat($ws, 'k1'))->toBe('accepte');
});

test('S21 coupure entre la fin de transmission et l’accusé : zéro doublon, zéro perte (a_verifier)', function () {
    smtpAvecFauxServeur('coupure_apres_data', function (int $port, string $fichier) {
        [$ws, $boite] = smtpBoite('127.0.0.1', $port);
        $s = smtpAdaptateur($ws, $boite);

        $r = $s->injecter(smtpMsg($ws, 'k-s21'));
        expect($r->accepte)->toBeFalse();
        $ligne = smtpLignes($ws, 'k-s21')[0];
        expect($ligne->etat)->toBe('a_verifier');
        // Zéro perte : le serveur a bien reçu le message (une fois, intact).
        expect(file_get_contents($fichier))->toContain('Corps du message')->and(substr_count((string) file_get_contents($fichier), 'DKIM-Signature'))->toBe(1);

        // Zéro doublon : ni retentative automatique de la même clé, ni autre envoi sur cette boîte.
        expect($s->injecter(smtpMsg($ws, 'k-s21'))->accepte)->toBeFalse();
        expect($s->injecter(smtpMsg($ws, 'autre'))->accepte)->toBeFalse();
        expect(smtpLignes($ws))->toHaveCount(1)->and(smtpLignes($ws)[0]->etat)->toBe('a_verifier');
        expect($s->etatEnvoi((string) $ligne->id_injection))->toBe(EtatMessage::Inconnu);
    });
});

test('faux serveur SMTP : accord 250 => accepté', function () {
    smtpAvecFauxServeur('ok', function (int $port, string $fichier) {
        [$ws, $boite] = smtpBoite('127.0.0.1', $port);
        $r = smtpAdaptateur($ws, $boite)->injecter(smtpMsg($ws));
        expect($r->accepte)->toBeTrue();
        expect(file_get_contents($fichier))->toContain('DKIM-Signature: v=1');
    });
});

test('réponse 4xx : report avec délai, aucun renvoi avant l’échéance', function () {
    smtpAvecFauxServeur('451_apres_data', function (int $port) {
        [$ws, $boite] = smtpBoite('127.0.0.1', $port);
        $s = smtpAdaptateur($ws, $boite);
        $r = $s->injecter(smtpMsg($ws));
        expect($r->accepte)->toBeFalse()->and($r->reporteAu)->not->toBeNull();
        expect($r->reporteAu > new DateTimeImmutable('+10 minutes'))->toBeTrue();
        expect(smtpEtat($ws, 'k1'))->toBe('reporte')->and(smtpLignes($ws, 'k1')[0]->code_smtp)->toBe(451);
        expect($s->injecter(smtpMsg($ws))->accepte)->toBeFalse(); // avant l'échéance : pas de connexion
    });
});

test('réponse 5xx : échec définitif, jamais rejoué', function () {
    smtpAvecFauxServeur('550_apres_data', function (int $port) {
        [$ws, $boite] = smtpBoite('127.0.0.1', $port);
        $s = smtpAdaptateur($ws, $boite);
        expect(fn () => $s->injecter(smtpMsg($ws)))->toThrow(RuntimeException::class);
        expect(smtpEtat($ws, 'k1'))->toBe('echec');
        expect(fn () => $s->injecter(smtpMsg($ws)))->toThrow(RuntimeException::class);
    });
});

test('le code SMTP vient de UnexpectedResponseException::getCode(), pas du texte du message', function () {
    [$ws, $boite] = smtpBoite();
    $s4 = smtpAdaptateur($ws, $boite, smtpTransport(fn () => throw new UnexpectedResponseException('libellé sans code reconnaissable', 451)));
    $r = $s4->injecter(smtpMsg($ws, 'k4'));
    expect($r->accepte)->toBeFalse()->and(smtpEtat($ws, 'k4'))->toBe('reporte')->and(smtpLignes($ws, 'k4')[0]->code_smtp)->toBe(451);

    // Un texte qui MENT sur le code ne trompe pas le classement : le code numérique fait foi.
    $menteur = smtpAdaptateur($ws, $boite, smtpTransport(fn () => throw new UnexpectedResponseException('Expected response code "250" but got code "550", with message "x".', 421)));
    expect($menteur->injecter(smtpMsg($ws, 'k4b'))->accepte)->toBeFalse();
    expect(smtpEtat($ws, 'k4b'))->toBe('reporte');

    $s5 = smtpAdaptateur($ws, $boite, smtpTransport(fn () => throw new UnexpectedResponseException('libellé sans code reconnaissable', 554)));
    expect(fn () => $s5->injecter(smtpMsg($ws, 'k5')))->toThrow(RuntimeException::class);
    expect(smtpEtat($ws, 'k5'))->toBe('echec')->and(smtpLignes($ws, 'k5')[0]->code_smtp)->toBe(554);
});

test('3 × 5xx en 10 minutes : pause automatique de la boîte + alerte', function () {
    Event::fake([BoitePauseAutomatique::class]);
    [$ws, $boite] = smtpBoite();
    $s = smtpAdaptateur($ws, $boite, smtpTransport(fn () => throw new UnexpectedResponseException('refus', 550)));
    foreach (['a', 'b', 'c'] as $k) {
        expect(fn () => $s->injecter(smtpMsg($ws, $k)))->toThrow(RuntimeException::class);
    }
    expect(smtpEtatBoite($ws, $boite))->toBe('en_pause');
    Event::assertDispatched(BoitePauseAutomatique::class, fn ($e) => $e->boiteId === $boite && $e->nombre5xx === 3);
    expect($s->injecter(smtpMsg($ws, 'd'))->accepte)->toBeFalse(); // en pause : rien ne part
});

test('deux 5xx seulement : pas de pause', function () {
    [$ws, $boite] = smtpBoite();
    $s = smtpAdaptateur($ws, $boite, smtpTransport(fn () => throw new UnexpectedResponseException('refus', 550)));
    foreach (['a', 'b'] as $k) {
        expect(fn () => $s->injecter(smtpMsg($ws, $k)))->toThrow(RuntimeException::class);
    }
    expect(smtpEtatBoite($ws, $boite))->toBe('active');
});

test('un seul envoi à la fois par boîte', function () {
    [$ws, $boite] = smtpBoite();
    $appels = 0;
    $s = smtpAdaptateur($ws, $boite, smtpTransport(function () use (&$appels) {
        $appels++;
    }));
    WorkspaceContext::run($ws, fn () => DB::table('envoi_injections_smtp')->insert(['workspace_id' => $ws, 'boite_id' => $boite, 'cle_idempotence' => 'en-vol', 'etat' => 'injection_en_cours']));
    expect($s->injecter(smtpMsg($ws, 'second'))->accepte)->toBeFalse()->and($appels)->toBe(0);
});

test('verrou consultatif tenu par une autre session : report, aucun envoi', function () {
    [$ws, $boite] = smtpBoite();
    $appels = 0;
    $c = config('database.connections.' . config('database.default'));
    $autre = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']), $c['username'], $c['password']);
    try {
        $autre->prepare('SELECT pg_advisory_lock(hashtextextended(?, 0))')->execute(['envoi:boite:' . $boite]);
        $r = smtpAdaptateur($ws, $boite, smtpTransport(function () use (&$appels) {
            $appels++;
        }))->injecter(smtpMsg($ws));
    } finally {
        $autre = null;
    }
    expect($r->accepte)->toBeFalse()->and($appels)->toBe(0);
});

test('processus tué en cours d’injection : devient a_verifier, jamais renvoyé', function () {
    [$ws, $boite] = smtpBoite();
    $appels = 0;
    $s = smtpAdaptateur($ws, $boite, smtpTransport(function () use (&$appels) {
        $appels++;
    }));
    WorkspaceContext::run($ws, fn () => DB::table('envoi_injections_smtp')->insert(['workspace_id' => $ws, 'boite_id' => $boite, 'cle_idempotence' => 'k', 'etat' => 'injection_en_cours', 'maj_le' => now()->subHour()]));
    expect($s->marquerOrphelinesAVerifier(5))->toBe(1);
    expect(smtpEtat($ws, 'k'))->toBe('a_verifier');
    expect($s->injecter(smtpMsg($ws, 'k'))->accepte)->toBeFalse()->and($appels)->toBe(0);
});

test('aucun effacement : DELETE refusé sur boîtes et injections', function () {
    [$ws, $boite] = smtpBoite();
    smtpAdaptateur($ws, $boite, smtpTransport(fn () => null))->injecter(smtpMsg($ws));
    WorkspaceContext::run($ws, function () {
        expect(fn () => DB::transaction(fn () => DB::table('envoi_injections_smtp')->delete()))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_boites')->delete()))->toThrow(QueryException::class);
    });
});

test('limite par heure : au-delà, report sans envoi', function () {
    [$ws, $boite] = smtpBoite();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->where('id', $boite)->update(['limite_heure' => 1]));
    $s = smtpAdaptateur($ws, $boite, smtpTransport(fn () => null));
    expect($s->injecter(smtpMsg($ws, 'a'))->accepte)->toBeTrue();
    expect($s->injecter(smtpMsg($ws, 'b'))->accepte)->toBeFalse();
});

test('la limite est évaluée SOUS le verrou de la boîte (pas avant)', function () {
    [$ws, $boite] = smtpBoite();
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    smtpAdaptateur($ws, $boite, smtpTransport(fn () => null))->injecter(smtpMsg($ws));
    $verrou = collect($sql)->search(fn ($q) => str_contains($q, 'pg_try_advisory_lock'));
    $limite = collect($sql)->search(fn ($q) => str_contains($q, 'limite_jour'));
    expect($verrou)->not->toBeFalse()->and($limite)->not->toBeFalse()->and($limite)->toBeGreaterThan($verrou);
    $deverrou = collect($sql)->search(fn ($q) => str_contains($q, 'pg_advisory_unlock'));
    expect($deverrou)->toBeGreaterThan($limite);
});

test('aucune mesure d’ouverture dans le code de l’adaptateur', function () {
    foreach (glob(app_path('Envoi/Smtp/*.php')) as $f) {
        $t = strtolower((string) file_get_contents($f));
        // Seuls les commentaires de garde mentionnent « ouverture ».
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $t);
        expect($code)->not->toContain('pixel')->and($code)->not->toContain('tracking');
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Doublon : transaction englobante, état commité avant le réseau, validations préalables
// ─────────────────────────────────────────────────────────────────────────────

test('injecter() est REFUSÉ dans une transaction englobante : aucun envoi, aucune ligne', function () {
    config(['envoi.smtp_tolere_transaction_englobante' => false]);
    [$ws, $boite] = smtpBoite();
    $appels = 0;
    $s = smtpAdaptateur($ws, $boite, smtpTransport(function () use (&$appels) {
        $appels++;
    }));
    expect(DB::transactionLevel())->toBeGreaterThan(0); // RefreshDatabase : le cas est réellement exercé
    expect(fn () => $s->injecter(smtpMsg($ws)))->toThrow(EnvoiSmtpRefuse::class);
    expect($appels)->toBe(0)->and(smtpLignes($ws))->toBeEmpty();
});

test('la dérogation de transaction n’est honorée que sous APP_ENV=testing', function () {
    [$ws, $boite] = smtpBoite();
    config(['envoi.smtp_tolere_transaction_englobante' => true]);
    $this->app->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => smtpAdaptateur($ws, $boite, smtpTransport(fn () => null))->injecter(smtpMsg($ws)))->toThrow(EnvoiSmtpRefuse::class);
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }
});

test('l’état injection_en_cours est posé AVANT le réseau, sans transaction ouverte par l’adaptateur', function () {
    [$ws, $boite] = smtpBoite();
    $niveau = DB::transactionLevel();
    $vu = [];
    smtpAdaptateur($ws, $boite, smtpTransport(function () use (&$vu, $ws) {
        $vu = ['etat' => smtpEtat($ws, 'k-avant'), 'niveau' => DB::transactionLevel()];
    }))->injecter(smtpMsg($ws, 'k-avant'));
    expect($vu)->toBe(['etat' => 'injection_en_cours', 'niveau' => $niveau]);
    expect(smtpEtat($ws, 'k-avant'))->toBe('accepte');
});

test('enveloppe invalide : refus AVANT injection_en_cours (aucune ligne, aucun réseau)', function (string $dest) {
    [$ws, $boite] = smtpBoite();
    $appels = 0;
    $s = smtpAdaptateur($ws, $boite, smtpTransport(function () use (&$appels) {
        $appels++;
    }));
    expect(fn () => $s->injecter(smtpMsg($ws, 'k-inv', $dest)))->toThrow(EnvoiSmtpRefuse::class);
    expect($appels)->toBe(0)->and(smtpLignes($ws))->toBeEmpty();
})->with(['pas-une-adresse', '', "a@example.test\r\nRCPT TO:<b@example.test>", 'a@@example.test', '<a@example.test>, b@example.test']);

test('expéditeur différent de l’adresse de la boîte : refus, aucune ligne', function () {
    [$ws, $boite] = smtpBoite();
    $appels = 0;
    $s = smtpAdaptateur($ws, $boite, smtpTransport(function () use (&$appels) {
        $appels++;
    }));
    expect(fn () => $s->injecter(smtpMsg($ws, 'k-exp', 'temoin@example.test', 'usurpe@example.test')))->toThrow(EnvoiSmtpRefuse::class);
    expect($appels)->toBe(0)->and(smtpLignes($ws))->toBeEmpty();
    // La casse ne change pas l'adresse.
    expect($s->injecter(smtpMsg($ws, 'k-casse', 'temoin@example.test', 'FROM@Example.Test'))->accepte)->toBeTrue();
});

test('CR ou LF isolés dans le message brut : refus (contrebande SMTP), aucune ligne', function (string $brut) {
    [$ws, $boite] = smtpBoite();
    $appels = 0;
    $s = smtpAdaptateur($ws, $boite, smtpTransport(function () use (&$appels) {
        $appels++;
    }), $brut);
    expect(fn () => $s->injecter(smtpMsg($ws, 'k-crlf')))->toThrow(EnvoiSmtpRefuse::class);
    expect($appels)->toBe(0)->and(smtpLignes($ws))->toBeEmpty();
})->with([
    'LF isolé' => ["From: a@example.test\nTo: b@example.test\r\n\r\ncorps\r\n"],
    'CR isolé' => ["From: a@example.test\rTo: b@example.test\r\n\r\ncorps\r\n"],
    'LF.LF' => ["From: a@example.test\r\n\r\ncorps\n.\nMAIL FROM:<x@example.test>\r\n"],
    'CR en fin' => ["From: a@example.test\r\n\r\ncorps\r"],
    'vide' => [''],
]);

test('CRLF stricts : accepté', function () {
    [$ws, $boite] = smtpBoite();
    expect(smtpAdaptateur($ws, $boite, smtpTransport(fn () => null), "A: b\r\n\r\nc\r\n\r\n")->injecter(smtpMsg($ws, 'k-ok'))->accepte)->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Base : clé étrangère composite (workspace_id, boite_id)
// ─────────────────────────────────────────────────────────────────────────────

test('clé étrangère composite : une injection ne peut pas viser la boîte d’un AUTRE espace', function () {
    [$wsA, $boiteA] = smtpBoite();
    $wsB = smtpEspace();
    expect(fn () => DB::transaction(fn () => DB::table('envoi_injections_smtp')->insert([
        'workspace_id' => $wsB, 'boite_id' => $boiteA, 'cle_idempotence' => 'croise', 'etat' => 'a_injecter',
    ])))->toThrow(QueryException::class);
    // témoin : la même ligne, dans le bon espace, passe.
    expect(DB::table('envoi_injections_smtp')->insert(['workspace_id' => $wsA, 'boite_id' => $boiteA, 'cle_idempotence' => 'ok', 'etat' => 'a_injecter']))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. RLS : isolation PROUVÉE avec le rôle applicatif (axion_app), pas avec le superuser
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Le rôle de connexion des tests est SUPERUSER : la RLS ne mord pas sur lui.
 * On bascule donc, DANS la transaction de RefreshDatabase, sur le rôle
 * non-propriétaire `axion_app` (SET LOCAL ROLE) : les lignes semées plus tôt
 * restent visibles (même transaction) et la RLS s'applique pour de bon.
 * Toutes les lectures/écritures passent par WorkspaceContext::run.
 */
function smtpSousRoleApplicatif(Closure $corps): mixed
{
    expect((string) DB::selectOne('SELECT rolsuper::text AS s FROM pg_roles WHERE rolname = ?', ['axion_app'])->s)->toBe('false');
    DB::statement('SET LOCAL ROLE axion_app');
    try {
        expect((string) DB::selectOne('SELECT current_user AS u')->u)->toBe('axion_app');

        return $corps();
    } finally {
        DB::statement('RESET ROLE');
    }
}

test('RLS : deux espaces, chacun ne voit et ne modifie QUE ses boîtes et ses injections', function () {
    [$wsA, $boiteA] = smtpBoite();
    [$wsB, $boiteB] = smtpBoite();
    foreach ([[$wsA, $boiteA], [$wsB, $boiteB]] as [$ws, $b]) {
        expect(smtpAdaptateur($ws, $b, smtpTransport(fn () => null))->injecter(smtpMsg($ws, 'k-rls'))->accepte)->toBeTrue();
    }

    // TÉMOIN : en superuser, les deux espaces ont bien des lignes (sinon « 0 vu » ne prouverait rien).
    expect(DB::table('envoi_injections_smtp')->whereIn('workspace_id', [$wsA, $wsB])->count())->toBe(2);
    expect(DB::table('envoi_boites')->whereIn('workspace_id', [$wsA, $wsB])->count())->toBe(2);

    smtpSousRoleApplicatif(function () use ($wsA, $wsB, $boiteA, $boiteB) {
        // Sans contexte : rien.
        WorkspaceContext::clear();
        expect(DB::table('envoi_injections_smtp')->count())->toBe(0)->and(DB::table('envoi_boites')->count())->toBe(0);

        // Contexte A : uniquement A.
        WorkspaceContext::run($wsA, function () use ($wsA, $boiteA, $boiteB) {
            expect(DB::table('envoi_injections_smtp')->pluck('workspace_id')->unique()->all())->toBe([$wsA]);
            expect(DB::table('envoi_boites')->pluck('id')->all())->toBe([$boiteA]);
            expect(DB::table('envoi_boites')->where('id', $boiteB)->exists())->toBeFalse();
            // Écrire chez B depuis A : refusé par WITH CHECK ; modifier B depuis A : 0 ligne.
            expect(fn () => DB::transaction(fn () => DB::table('envoi_boites')->insert([
                'workspace_id' => (string) DB::table('envoi_boites')->value('workspace_id') === $wsA ? Str::uuid()->toString() : $wsA,
                'adresse' => 'x@example.test', 'hote' => '127.0.0.1', 'port' => 2525, 'chiffrement' => 'aucun', 'identifiants_chiffres' => 'x',
            ])))->toThrow(QueryException::class);
            expect(DB::table('envoi_boites')->where('id', $boiteB)->update(['etat' => 'en_pause']))->toBe(0);
        });
        // Contexte B : uniquement B.
        WorkspaceContext::run($wsB, function () use ($wsB, $boiteB) {
            expect(DB::table('envoi_injections_smtp')->pluck('workspace_id')->unique()->all())->toBe([$wsB]);
            expect(DB::table('envoi_boites')->pluck('id')->all())->toBe([$boiteB]);
        });

        // L'adaptateur lui-même ne voit pas la boîte de l'autre espace.
        expect((new DepotBoites(new ResolveurFaux))->trouver($wsA, $boiteB))->toBeNull();
        expect((new DepotBoites(new ResolveurFaux))->trouver($wsB, $boiteA))->toBeNull();
    });

    // La boîte de B n'a pas bougé (vu en superuser).
    expect(DB::table('envoi_boites')->where('id', $boiteB)->value('etat'))->toBe('active');
});

test('RLS : l’adaptateur fonctionne réellement sous le rôle applicatif (lecture, écriture, verrou)', function () {
    [$ws, $boite] = smtpBoite();
    $autre = smtpEspace();
    smtpSousRoleApplicatif(function () use ($ws, $boite, $autre) {
        $s = smtpAdaptateur($ws, $boite, smtpTransport(fn () => null));
        expect($s->injecter(smtpMsg($ws, 'k-app'))->accepte)->toBeTrue();
        expect(smtpEtat($ws, 'k-app'))->toBe('accepte');
        $s->pause();
        expect($s->etat()->enPause)->toBeTrue();
        // Un autre espace ne peut pas piloter cette boîte.
        expect(fn () => smtpAdaptateur($autre, $boite, smtpTransport(fn () => null))->injecter(smtpMsg($ws, 'k-x')))->toThrow(EnvoiRefuse::class);
        expect(smtpAdaptateur($autre, $boite)->sante()->ok)->toBeFalse();
    });
});

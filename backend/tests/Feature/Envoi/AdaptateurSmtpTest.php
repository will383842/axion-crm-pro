<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Smtp\AdaptateurSmtp;
use App\Envoi\Smtp\BoitePauseAutomatique;
use App\Envoi\Smtp\BoiteSmtp;
use App\Envoi\Smtp\DepotBoites;
use App\Envoi\Smtp\SourceMessageSigne;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

require_once __DIR__ . '/../../Envoi/KitConformiteServeurEnvoi.php';

uses(TestCase::class, RefreshDatabase::class);

const BRUT_DKIM = "DKIM-Signature: v=1; a=rsa-sha256; d=example.test; s=s1; bh=AAAA; b=BBBB\r\nFrom: from@example.test\r\nTo: temoin@example.test\r\nSubject: S\r\n\r\nCorps du message\r\n";

function smtpEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'T', 'slug' => 'smtp-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** @return array{0: string, 1: string} [workspace, boite] */
function smtpBoite(string $hote = '127.0.0.1', int $port = 2525, string $secret = 'SECRET-DE-TEST-UNIQUE'): array
{
    $ws = smtpEspace();
    $boite = app(DepotBoites::class)->creer($ws, 'from@example.test', $hote, $port, 'aucun', 'utilisateur', $secret);

    return [$ws, $boite];
}

function smtpSource(): SourceMessageSigne
{
    return new class implements SourceMessageSigne
    {
        public function brut(MessageAEnvoyer $message): string
        {
            return BRUT_DKIM;
        }
    };
}

function smtpMsg(string $ws, string $cle = 'k1'): MessageAEnvoyer
{
    return new MessageAEnvoyer($ws, 'temoin@example.test', 'from@example.test', 'S', 'C', $cle);
}

function smtpAdaptateur(string $ws, string $boite, ?Closure $fabrique = null): AdaptateurSmtp
{
    return new AdaptateurSmtp($ws, $boite, smtpSource(), app(DepotBoites::class), app(DepotSocle::class), $fabrique);
}

/** Transport factice : aucun réseau. */
function smtpTransport(Closure $reaction): Closure
{
    return fn (BoiteSmtp $b) => new class($reaction) implements TransportInterface
    {
        public function __construct(private Closure $reaction) {}

        public function send(RawMessage $message, $envelope = null)
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

/** Lance le faux serveur SMTP local. @return array{0: resource, 1: int, 2: string} */
function smtpFauxServeur(string $mode): array
{
    $fichier = tempnam(sys_get_temp_dir(), 'smtp');
    $p = proc_open([PHP_BINARY, __DIR__ . '/../../Support/faux_smtp_serveur.php', $mode, $fichier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));

    return [$p, $port, $fichier];
}

kitConformiteServeurEnvoi(function () {
    $ws = '00000000-0000-4000-8000-000000000001'; // espace imposé par le kit
    DB::table('workspaces')->insertOrIgnore(['id' => $ws, 'name' => 'Kit', 'slug' => 'kit-smtp', 'created_at' => now(), 'updated_at' => now()]);
    $boite = app(DepotBoites::class)->creer($ws, 'from@example.test', '127.0.0.1', 2525, 'aucun', 'u', 's');

    return smtpAdaptateur($ws, $boite, smtpTransport(fn () => null));
});

test('identifiants chiffrés en base, jamais en clair, jamais dans un dump', function () {
    [$ws, $boite] = smtpBoite(secret: 'SECRET-DE-TEST-UNIQUE');
    $brut = (string) DB::table('envoi_boites')->where('id', $boite)->value('identifiants_chiffres');
    expect($brut)->not->toContain('SECRET-DE-TEST-UNIQUE')->and($brut)->not->toBe('');
    $b = app(DepotBoites::class)->trouver($ws, $boite);
    expect($b->secret())->toBe('SECRET-DE-TEST-UNIQUE');
    expect(print_r($b, true) . var_export($b, true) . json_encode($b))->not->toContain('SECRET-DE-TEST-UNIQUE');
});

test('le message signé DKIM est transmis octet pour octet', function () {
    [$ws, $boite] = smtpBoite();
    $vu = null;
    $r = smtpAdaptateur($ws, $boite, smtpTransport(function (RawMessage $m) use (&$vu) {
        $vu = implode('', iterator_to_array($m->toIterable(), false));
    }))->injecter(smtpMsg($ws));
    expect($r->accepte)->toBeTrue()->and($vu)->toBe(BRUT_DKIM);
    expect(DB::table('envoi_injections_smtp')->where('etat', 'accepte')->count())->toBe(1);
});

test('S21 coupure entre la fin de transmission et l’accusé : zéro doublon, zéro perte (a_verifier)', function () {
    [$proc, $port, $fichier] = smtpFauxServeur('coupure_apres_data');
    [$ws, $boite] = smtpBoite('127.0.0.1', $port);
    $s = smtpAdaptateur($ws, $boite);

    $r = $s->injecter(smtpMsg($ws, 'k-s21'));
    expect($r->accepte)->toBeFalse();
    $ligne = DB::table('envoi_injections_smtp')->where('cle_idempotence', 'k-s21')->first();
    expect($ligne->etat)->toBe('a_verifier');
    // Zéro perte : le serveur a bien reçu le message (une fois, intact).
    proc_close($proc);
    expect(file_get_contents($fichier))->toContain('Corps du message')->and(substr_count((string) file_get_contents($fichier), 'DKIM-Signature'))->toBe(1);

    // Zéro doublon : ni retentative automatique de la même clé, ni autre envoi sur cette boîte.
    expect($s->injecter(smtpMsg($ws, 'k-s21'))->accepte)->toBeFalse();
    expect($s->injecter(smtpMsg($ws, 'autre'))->accepte)->toBeFalse();
    expect(DB::table('envoi_injections_smtp')->count())->toBe(1);
    expect(DB::table('envoi_injections_smtp')->value('etat'))->toBe('a_verifier');
    expect($s->etatEnvoi((string) $ligne->id_injection))->toBe(EtatMessage::Inconnu);
    @unlink($fichier);
});

test('faux serveur SMTP : accord 250 => accepté', function () {
    [$proc, $port, $fichier] = smtpFauxServeur('ok');
    [$ws, $boite] = smtpBoite('127.0.0.1', $port);
    $r = smtpAdaptateur($ws, $boite)->injecter(smtpMsg($ws));
    proc_close($proc);
    expect($r->accepte)->toBeTrue();
    expect(file_get_contents($fichier))->toContain('DKIM-Signature: v=1');
    @unlink($fichier);
});

test('réponse 4xx : report avec délai, aucun renvoi avant l’échéance', function () {
    [$proc, $port, $fichier] = smtpFauxServeur('451_apres_data');
    [$ws, $boite] = smtpBoite('127.0.0.1', $port);
    $s = smtpAdaptateur($ws, $boite);
    $r = $s->injecter(smtpMsg($ws));
    proc_close($proc);
    expect($r->accepte)->toBeFalse()->and($r->reporteAu)->not->toBeNull();
    expect($r->reporteAu > new DateTimeImmutable('+10 minutes'))->toBeTrue();
    expect(DB::table('envoi_injections_smtp')->value('etat'))->toBe('reporte');
    expect($s->injecter(smtpMsg($ws))->accepte)->toBeFalse(); // avant l'échéance : pas de connexion
    @unlink($fichier);
});

test('réponse 5xx : échec définitif, jamais rejoué', function () {
    [$proc, $port, $fichier] = smtpFauxServeur('550_apres_data');
    [$ws, $boite] = smtpBoite('127.0.0.1', $port);
    $s = smtpAdaptateur($ws, $boite);
    expect(fn () => $s->injecter(smtpMsg($ws)))->toThrow(RuntimeException::class);
    proc_close($proc);
    expect(DB::table('envoi_injections_smtp')->value('etat'))->toBe('echec');
    expect(fn () => $s->injecter(smtpMsg($ws)))->toThrow(RuntimeException::class);
    @unlink($fichier);
});

test('3 × 5xx en 10 minutes : pause automatique de la boîte + alerte', function () {
    Event::fake([BoitePauseAutomatique::class]);
    [$ws, $boite] = smtpBoite();
    $s = smtpAdaptateur($ws, $boite, smtpTransport(fn () => throw new TransportException('Expected response code "250" but got code "550", with message "550 refuse".')));
    foreach (['a', 'b', 'c'] as $k) {
        expect(fn () => $s->injecter(smtpMsg($ws, $k)))->toThrow(RuntimeException::class);
    }
    expect(DB::table('envoi_boites')->where('id', $boite)->value('etat'))->toBe('en_pause');
    Event::assertDispatched(BoitePauseAutomatique::class, fn ($e) => $e->boiteId === $boite && $e->nombre5xx === 3);
    expect($s->injecter(smtpMsg($ws, 'd'))->accepte)->toBeFalse(); // en pause : rien ne part
});

test('deux 5xx seulement : pas de pause', function () {
    [$ws, $boite] = smtpBoite();
    $s = smtpAdaptateur($ws, $boite, smtpTransport(fn () => throw new TransportException('Expected response code "250" but got code "550", with message "x".')));
    foreach (['a', 'b'] as $k) {
        expect(fn () => $s->injecter(smtpMsg($ws, $k)))->toThrow(RuntimeException::class);
    }
    expect(DB::table('envoi_boites')->where('id', $boite)->value('etat'))->toBe('active');
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
    $autre->prepare('SELECT pg_advisory_lock(hashtextextended(?, 0))')->execute(['envoi:boite:' . $boite]);
    $r = smtpAdaptateur($ws, $boite, smtpTransport(function () use (&$appels) {
        $appels++;
    }))->injecter(smtpMsg($ws));
    $autre = null;
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
    expect(DB::table('envoi_injections_smtp')->value('etat'))->toBe('a_verifier');
    expect($s->injecter(smtpMsg($ws, 'k'))->accepte)->toBeFalse()->and($appels)->toBe(0);
});

test('aucun effacement : DELETE refusé sur boîtes et injections', function () {
    [$ws, $boite] = smtpBoite();
    smtpAdaptateur($ws, $boite, smtpTransport(fn () => null))->injecter(smtpMsg($ws));
    WorkspaceContext::run($ws, function () {
        expect(fn () => DB::transaction(fn () => DB::table('envoi_injections_smtp')->delete()))->toThrow(Illuminate\Database\QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_boites')->delete()))->toThrow(Illuminate\Database\QueryException::class);
    });
});

test('étanchéité : un autre espace ne voit ni la boîte ni les injections', function () {
    [$ws, $boite] = smtpBoite();
    smtpAdaptateur($ws, $boite, smtpTransport(fn () => null))->injecter(smtpMsg($ws));
    $autre = smtpEspace();
    expect(app(DepotBoites::class)->trouver($autre, $boite))->toBeNull();
    expect(WorkspaceContext::run($autre, fn () => DB::table('envoi_injections_smtp')->count()))->toBe(0);
    expect(fn () => smtpAdaptateur($autre, $boite, smtpTransport(fn () => null))->injecter(smtpMsg($ws)))->toThrow(App\Envoi\Exceptions\EnvoiRefuse::class);
});

test('limite par heure : au-delà, report sans envoi', function () {
    [$ws, $boite] = smtpBoite();
    DB::table('envoi_boites')->where('id', $boite)->update(['limite_heure' => 1]);
    $s = smtpAdaptateur($ws, $boite, smtpTransport(fn () => null));
    expect($s->injecter(smtpMsg($ws, 'a'))->accepte)->toBeTrue();
    expect($s->injecter(smtpMsg($ws, 'b'))->accepte)->toBeFalse();
});

test('aucune mesure d’ouverture dans le code de l’adaptateur', function () {
    foreach (glob(app_path('Envoi/Smtp/*.php')) as $f) {
        $t = strtolower((string) file_get_contents($f));
        // Seuls les commentaires de garde mentionnent « ouverture ».
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $t);
        expect($code)->not->toContain('pixel')->and($code)->not->toContain('tracking');
    }
});

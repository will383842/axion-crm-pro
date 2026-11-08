<?php

/**
 * Envoi K11 — rebonds et plaintes par la réception D2 (fourre-tout bounce.<domaine>, VERP rb-<jeton>, ARF).
 * Scénarios S09 à S13 du banc (plan 26-SPEC-CONTRAT-ENVOI-ET-BANC, l.257-261) : S10 = ARF + hostile + abuse@ (3 plaintes, 48 h), S11 = réponse humaine, S12 = STOP, S13 = absence. AUCUN réseau, AUCUN envoi : lecteur et registre en mémoire.
 * Fixtures FICTIVES (dépôt public) : example.test uniquement.
 */

use App\Envoi\Chauffe\CompteurRetours;
use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Imap\AnalyseArf;
use App\Envoi\Imap\AnalyseDsn;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\Classificateur;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\LecteurBoiteWebklex;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\MessageOrigine;
use App\Envoi\Imap\RecepteurRetours;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RecepteurRetoursEnChaine;
use App\Envoi\Imap\RecepteurRetoursFreins;
use App\Envoi\Imap\RegistreMessagesEnvoyesDb;
use App\Envoi\Imap\RegistreMessagesEnvoyesMemoire;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\RetourDetecte;
use App\Envoi\Imap\Verp;
use App\Envoi\JourEnvoi;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\FauxProtocoleImap;
use Tests\Support\FauxServeurImap as Faux;
use Tests\TestCase;
use Webklex\PHPIMAP\Config;

uses(TestCase::class, RefreshDatabase::class);

const K11_BOITE = 'boite-k11';
const K11_ADRESSE = 'contact@client.example.test';

/** Adresse VERP falsifiée : le caractère du jeton est TOUJOURS remplacé par un autre (jamais identique à la vraie). */
function k11Falsifie(string $adresse): string
{
    return substr_replace($adresse, $adresse[5] === '0' ? '1' : '0', 5, 1);
}

beforeEach(function () {
    config(['envoi.cle_empreinte' => 'cle-de-test-k11']);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire); // jamais de DNS en test
    $this->ws = F::espace('zz-k11');
    config(['crm.ingest.business_workspace' => F::slug($this->ws)]);
    $this->lecteur = new LecteurBoiteMemoire;
    $this->registre = new RegistreMessagesEnvoyesMemoire;
    $this->inj = k11Injection($this->ws);
    $this->registre->noter('envoi-k11@d.example.test', new MessageOrigine($this->inj, K11_ADRESSE), $this->ws);
    DB::table('envoi_domaines')->insert(['workspace_id' => $this->ws, 'nom' => 'd.example.test', 'achete_le' => '2026-01-01']);
    $this->recepteur = new class(new RecepteurRetoursCrm) implements RecepteurRetours
    {
        public int $appels = 0;

        /** @var list<RetourDetecte> */
        public array $vus = [];

        public function __construct(private readonly RecepteurRetours $reel) {}

        public function recevoir(string $workspaceId, RetourDetecte $retour): void
        {
            $this->appels++;
            $this->vus[] = $retour;
            $this->reel->recevoir($workspaceId, $retour);
        }
    };
    $this->releve = new ReleveImap($this->lecteur, $this->registre, $this->recepteur);
    $this->verp = Verp::adressePour($this->inj, 'd.example.test');
});

function k11Injection(string $ws): string
{
    $boite = (string) Str::uuid();
    DB::table('envoi_boites')->insert([
        'id' => $boite, 'workspace_id' => $ws, 'adresse' => 'k11-' . substr($boite, 0, 8) . '@test.invalid',
        'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre',
    ]);
    $id = (string) Str::uuid();
    DB::table('envoi_injections_smtp')->insert(['workspace_id' => $ws, 'boite_id' => $boite, 'id_injection' => $id, 'cle_idempotence' => 'k11-' . $id]);

    return $id;
}

/** @param array<string, mixed> $s */
function k11Msg(int $uid, array $s = []): MessageBrut
{
    $a = array_merge([
        'mid' => "k{$uid}@retour.example.test", 'irt' => null, 'de' => 'MAILER-DAEMON@retour.example.test',
        'sujet' => 'Rapport', 'corps' => '', 'h' => [], 'dsn' => null, 'dsnMid' => null, 'dest' => null,
        'arf' => null, 'arfDest' => null, 'locales' => [],
    ], $s);

    return new MessageBrut(
        $uid,
        $a['mid'],
        $a['irt'],
        [],
        $a['de'],
        new DateTimeImmutable('2026-12-02 10:00:00', new DateTimeZone('UTC')),
        $a['sujet'],
        $a['corps'],
        $a['h'],
        $a['dsn'],
        $a['dsnMid'],
        $a['dest'],
        typeArf: $a['arf'],
        destinataireArf: $a['arfDest'],
        adressesLocales: $a['locales'],
    );
}

function k11Releve(object $t, MessageBrut ...$ms): void
{
    foreach ($ms as $m) {
        $t->lecteur->deposer(K11_BOITE, $m);
    }
    $t->releve->relever($t->ws, K11_BOITE);
}

function k11Plainte(): bool
{
    return DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(K11_ADRESSE))->count() > 0
        && ListeSuppression::estSupprimee(K11_ADRESSE);
}

test('VERP : jeton signé, aller-retour, falsification et mauvais domaine refusés', function () {
    $inj = (string) Str::uuid();
    $adresse = Verp::adressePour($inj, 'd.example.test');
    expect($adresse)->toStartWith('rb-')->toEndWith('@bounce.d.example.test')
        ->and(Verp::idInjectionDepuis($adresse))->toBe($inj)
        ->and(Verp::idInjectionDepuis(strtoupper($adresse)))->toBe($inj)
        ->and(Verp::idInjectionDepuis(k11Falsifie($adresse)))->toBeNull()
        ->and(Verp::idInjectionDepuis(str_replace('@bounce.', '@', $adresse)))->toBeNull()
        ->and(Verp::idInjectionDepuis('rb-abc@bounce.d.example.test'))->toBeNull();
    expect(fn () => Verp::adressePour('pas-un-uuid', 'd.example.test'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Verp::adressePour(str_repeat('a', 32), 'd.example.test'))->toThrow(InvalidArgumentException::class);
    config(['envoi.cle_empreinte' => 'autre-cle']);
    expect(Verp::idInjectionDepuis($adresse))->toBeNull();
    config(['envoi.cle_empreinte' => '']);
    expect(Verp::idInjectionDepuis($adresse))->toBeNull(); // sans clé : jamais de rattachement
});

test('ARF : lecture du rapport (JMRP et Yahoo CFL)', function () {
    $jmrp = "Feedback-Type: abuse\nUser-Agent: JMRP/1.0\nOriginal-Mail-From: <rb-abc@bounce.d.example.test>\nOriginal-Rcpt-To: <contact@client.example.test>\n\nMessage-ID: <envoi-k11@d.example.test>\n";
    expect(AnalyseArf::depuisBrut($jmrp))->toBe([
        'type' => 'abuse', 'destinataire' => 'contact@client.example.test',
        'messageId' => 'envoi-k11@d.example.test', 'adresses' => ['rb-abc@bounce.d.example.test'],
    ]);
    expect(AnalyseArf::estRapport('multipart/report; report-type=feedback-report; boundary=x'))->toBeTrue()
        ->and(AnalyseArf::estRapport('multipart/report; report-type=delivery-status'))->toBeFalse();
});

test('classification : ARF = plainte (même « Auto-Submitted »), not-spam sans effet, abuse@ = plainte', function () {
    $auto = ['auto-submitted' => 'auto-generated'];
    expect(Classificateur::classer(k11Msg(1, ['arf' => 'abuse', 'h' => $auto])))->toBe(ClasseRetour::Hostile)
        ->and(Classificateur::classer(k11Msg(2, ['arf' => 'fraud'])))->toBe(ClasseRetour::Hostile)
        ->and(Classificateur::classer(k11Msg(3, ['arf' => 'not-spam'])))->toBe(ClasseRetour::AutoReponse)
        ->and(Classificateur::classer(k11Msg(4, ['corps' => 'Merci de corriger', 'locales' => ['Abuse@d.example.test']]), ['d.example.test']))->toBe(ClasseRetour::Hostile)
        ->and(Classificateur::classer(k11Msg(4, ['corps' => 'Merci', 'locales' => ['abuse@bounce.d.example.test']]), ['d.example.test']))->toBe(ClasseRetour::Hostile)
        ->and(Classificateur::classer(k11Msg(7, ['corps' => 'Merci', 'locales' => ['abuse@autre.example.test']]), ['d.example.test']))->toBe(ClasseRetour::ReponseHumaine)
        ->and(Classificateur::classer(k11Msg(8, ['corps' => 'Merci', 'locales' => ['abuse@d.example.test']])))->toBe(ClasseRetour::ReponseHumaine)
        ->and(Classificateur::classer(k11Msg(5, ['h' => $auto, 'locales' => ['abuse@d.example.test']]), ['d.example.test']))->toBe(ClasseRetour::AutoReponse)
        ->and(Classificateur::classer(k11Msg(6, ['corps' => 'Merci', 'locales' => ['contact@d.example.test']])))->toBe(ClasseRetour::ReponseHumaine);
});

test('S09 : rebond asynchrone reçu sur bounce.<domaine>, rattaché par le jeton VERP seul', function () {
    $mort = 'mort@client.example.test';
    $inj = k11Injection($this->ws);
    $this->registre->noterInjection($inj, new MessageOrigine($inj, $mort));
    $contact = DB::table('contacts')->count();
    k11Releve($this, k11Msg(1, ['dsn' => '5.1.1', 'dest' => $mort, 'locales' => [Verp::adressePour($inj, 'd.example.test')]]));

    expect(DB::table('envoi_evenements')->where('classe', 'rebond_dur')->where('statut', 'rattache')->where('id_injection', $inj)->count())->toBe(1)
        ->and(DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte($mort))->count())->toBeGreaterThan(0)
        ->and(DB::table('contacts')->count())->toBe($contact);
});

test('ARF JMRP rattaché par Original-Mail-From VERP => plainte, contact gardé', function () {
    k11Releve($this, k11Msg(1, ['arf' => 'abuse', 'arfDest' => K11_ADRESSE, 'locales' => [$this->verp]]));

    expect(DB::table('envoi_evenements')->where('classe', 'hostile')->where('statut', 'rattache')->count())->toBe(1)
        ->and(k11Plainte())->toBeTrue();
});

test('ARF Yahoo CFL avec SEUL le Message-ID cité (sans VERP signé) => « à rattacher », AUCUNE opposition', function () {
    k11Releve($this, k11Msg(1, ['arf' => 'abuse', 'dsnMid' => 'envoi-k11@d.example.test']));

    expect(DB::table('envoi_evenements')->where('statut', 'a_rattacher')->count())->toBe(1)
        ->and(DB::table('envoi_evenements')->where('statut', 'rattache')->count())->toBe(0)
        ->and($this->recepteur->appels)->toBe(0)
        ->and(DB::table('opt_out')->count())->toBe(0)
        ->and(ListeSuppression::estSupprimee(K11_ADRESSE))->toBeFalse();
});

test('ARF : In-Reply-To / References pointant un message connu ne rattachent pas non plus (VERP signé seul)', function () {
    k11Releve($this, k11Msg(1, ['arf' => 'abuse', 'irt' => 'envoi-k11@d.example.test', 'arfDest' => K11_ADRESSE]));

    expect(DB::table('envoi_evenements')->where('statut', 'a_rattacher')->count())->toBe(1)
        ->and(DB::table('opt_out')->count())->toBe(0);
});

test('ARF avec Feedback-Type illisible => « à rattacher », pas une plainte', function () {
    k11Releve($this, k11Msg(1, ['arf' => 'illisible', 'arfDest' => K11_ADRESSE, 'locales' => [$this->verp]]));

    expect(Classificateur::classer(k11Msg(2, ['arf' => 'illisible'])))->not->toBe(ClasseRetour::Hostile)
        ->and(DB::table('envoi_evenements')->where('statut', 'a_rattacher')->count())->toBe(1)
        ->and(DB::table('envoi_evenements')->where('classe', 'hostile')->count())->toBe(0)
        ->and(DB::table('opt_out')->count())->toBe(0);
});

test('réponse hostile rattachée par Message-ID => plainte', function () {
    k11Releve($this, k11Msg(1, ['irt' => 'envoi-k11@d.example.test', 'de' => K11_ADRESSE, 'corps' => 'Je vais porter plainte.']));

    expect(k11Plainte())->toBeTrue();
});

test('message reçu sur abuse@ (notre domaine) rattaché => plainte', function () {
    k11Releve($this, k11Msg(1, ['irt' => 'envoi-k11@d.example.test', 'de' => K11_ADRESSE, 'corps' => 'Cessez ces envois svp.', 'locales' => ['abuse@d.example.test']]));

    expect(k11Plainte())->toBeTrue();
});

test('ARF sans jeton ni Message-ID connu, jeton falsifié ou destinataire différent => « à rattacher », AUCUNE opposition', function () {
    $faux = k11Falsifie($this->verp);
    k11Releve(
        $this,
        k11Msg(1, ['arf' => 'abuse', 'arfDest' => K11_ADRESSE]),
        k11Msg(2, ['arf' => 'abuse', 'locales' => [$faux]]),
        k11Msg(3, ['arf' => 'abuse', 'arfDest' => 'autre@client.example.test', 'locales' => [$this->verp]]),
    );

    expect(DB::table('envoi_evenements')->where('statut', 'a_rattacher')->count())->toBe(3)
        ->and(DB::table('opt_out')->count())->toBe(0)
        ->and(ListeSuppression::estSupprimee(K11_ADRESSE))->toBeFalse();
});

test('rejeu et renumérotation UIDVALIDITY : ni doublon d\'événement, ni second effet', function () {
    $this->lecteur->definirUidValidity(K11_BOITE, 5);
    k11Releve($this, k11Msg(1, ['arf' => 'abuse', 'locales' => [$this->verp]]));
    $this->lecteur->renumeroter(K11_BOITE, 6);
    $bilan = $this->releve->relever($this->ws, K11_BOITE);

    // Preuve indépendante de l'ignorance des doublons en base : le récepteur n'est appelé qu'UNE fois.
    expect($bilan->doublons)->toBe(1)->and($bilan->nouveaux)->toBe(0)
        ->and($this->recepteur->appels)->toBe(1)
        ->and(DB::table('envoi_evenements')->count())->toBe(1)
        ->and(DB::table('opt_out')->count())->toBe(1)
        ->and(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(K11_ADRESSE))->count())->toBe(1);
});

test('un VERP valide l\'emporte sur le Message-ID cité d\'une autre injection', function () {
    $autre = k11Injection($this->ws);
    $this->registre->noter('autre-envoi@d.example.test', new MessageOrigine($autre, 'autre@client.example.test'));
    k11Releve($this, k11Msg(1, ['arf' => 'abuse', 'dsnMid' => 'autre-envoi@d.example.test', 'locales' => [$this->verp]]));

    expect(DB::table('envoi_evenements')->where('statut', 'rattache')->where('id_injection', $this->inj)->count())->toBe(1)
        ->and(DB::table('envoi_evenements')->where('id_injection', $autre)->count())->toBe(0)
        ->and(k11Plainte())->toBeTrue()
        ->and(ListeSuppression::estSupprimee('autre@client.example.test'))->toBeFalse();
});

test('AnalyseDsn::enTetes garde TOUS les Delivered-To (liste)', function () {
    $e = AnalyseDsn::enTetes("Delivered-To: a@d.example.test\r\nDelivered-To: rb-x@bounce.d.example.test\r\nTo: b@d.example.test\r\n");
    expect($e['delivered-to'])->toBe('a@d.example.test, rb-x@bounce.d.example.test')
        ->and(AnalyseArf::adressesDesEnTetes($e))->toContain('a@d.example.test', 'rb-x@bounce.d.example.test', 'b@d.example.test');
});

test('VERP présent dans le SECOND Delivered-To : rattaché', function () {
    $e = AnalyseDsn::enTetes("Delivered-To: relais@d.example.test\r\nDelivered-To: {$this->verp}\r\n");
    k11Releve($this, k11Msg(1, ['arf' => 'abuse', 'h' => $e, 'locales' => AnalyseArf::adressesDesEnTetes($e)]));

    expect(DB::table('envoi_evenements')->where('statut', 'rattache')->count())->toBe(1)->and(k11Plainte())->toBeTrue();
});

function k11ArfBrut(string $verp, string $mid, string $typeOrigine = 'message/rfc822'): string
{
    $b = 'BND';

    return "From: fai@retour.example.test\r\nTo: {$verp}\r\nDelivered-To: {$verp}\r\nSubject: Rapport abus\r\nMessage-ID: <rapport-1@retour.example.test>\r\nDate: Wed, 02 Dec 2026 10:00:00 +0000\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: multipart/report; report-type=feedback-report; boundary=\"{$b}\"\r\n\r\n"
        . "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nCeci est un rapport d'abus.\r\n"
        . "--{$b}\r\nContent-Type: message/feedback-report\r\n\r\nFeedback-Type: abuse\r\nUser-Agent: JMRP/1.0\r\nOriginal-Mail-From: <{$verp}>\r\nOriginal-Rcpt-To: <" . K11_ADRESSE . ">\r\n\r\n"
        . "--{$b}\r\nContent-Type: {$typeOrigine}\r\n\r\nFrom: d@d.example.test\r\nTo: " . K11_ADRESSE . "\r\nSubject: Offre\r\nMessage-ID: <{$mid}>\r\n\r\nBonjour\r\n"
        . "--{$b}--\r\n";
}

test('ARF BRUT multipart/report lu par le lecteur réel (faux serveur IMAP) : type, Original-Mail-From, Message-ID cité', function (string $typeOrigine) {
    Faux::reinitialiser();
    Faux::deposer(1, k11ArfBrut($this->verp, 'envoi-k11@d.example.test', $typeOrigine));
    $lecteur = new LecteurBoiteWebklex(
        ['host' => 'imap.k11.example.test', 'username' => 'u', 'password' => 'p'],
        K11_BOITE,
        'INBOX',
        static fn () => new FauxProtocoleImap(Config::make(), true, 'ssl'),
    );
    $m = $lecteur->lire(K11_BOITE, 0)->messages[0];

    expect($m->typeArf)->toBe('abuse')
        ->and($m->destinataireArf)->toBe(K11_ADRESSE)
        ->and($m->messageIdDsn)->toBe('envoi-k11@d.example.test')
        ->and($m->adressesLocales)->toContain($this->verp);

    (new ReleveImap($lecteur, $this->registre, $this->recepteur))->relever($this->ws, K11_BOITE);
    expect(DB::table('envoi_evenements')->where('classe', 'hostile')->where('statut', 'rattache')->count())->toBe(1)
        ->and(k11Plainte())->toBeTrue();
})->with(['message/rfc822', 'text/rfc822-headers']);

test('RegistreMessagesEnvoyesDb::retrouverParInjection : requête, filtre par espace, adresse introuvable => null', function () {
    DB::table('envoi_messages_envoyes')->insert([
        'workspace_id' => $this->ws, 'message_id_empreinte' => hash('sha256', 'mid:db-1@d.example.test'),
        'id_injection' => $this->inj, 'empreinte_destinataire' => ListeSuppression::empreinte(K11_ADRESSE),
    ]);
    $autreWs = F::espace('zz-k11-autre');
    $registre = new RegistreMessagesEnvoyesDb(fn (string $ws, string $e): ?string => $e === ListeSuppression::empreinte(K11_ADRESSE) ? K11_ADRESSE : null);

    $o = $registre->retrouverParInjection($this->ws, $this->inj);
    expect($o)->toBeInstanceOf(MessageOrigine::class)->and($o->adresse)->toBe(K11_ADRESSE)->and($o->idInjection)->toBe($this->inj)
        ->and($registre->retrouverParInjection($autreWs, $this->inj))->toBeNull()
        ->and($registre->retrouverParInjection($this->ws, (string) Str::uuid()))->toBeNull()
        ->and((new RegistreMessagesEnvoyesDb(fn () => null))->retrouverParInjection($this->ws, $this->inj))->toBeNull();
});

test('registre mémoire : le filtre par espace est respecté (comme la base)', function () {
    $r = new RegistreMessagesEnvoyesMemoire;
    $inj = (string) Str::uuid();
    $r->noterInjection($inj, new MessageOrigine($inj, K11_ADRESSE), 'ws-a');
    expect($r->retrouverParInjection('ws-a', $inj))->not->toBeNull()
        ->and($r->retrouverParInjection('ws-b', $inj))->toBeNull();
});

test('rebond MOU 4.x.x rattaché par VERP : événement rattaché, AUCUNE opposition', function () {
    k11Releve($this, k11Msg(1, ['dsn' => '4.2.2', 'dest' => K11_ADRESSE, 'locales' => [$this->verp]]));

    expect(DB::table('envoi_evenements')->where('classe', 'rebond_mou')->where('statut', 'rattache')->where('id_injection', $this->inj)->count())->toBe(1)
        ->and(DB::table('opt_out')->count())->toBe(0)
        ->and(ListeSuppression::estSupprimee(K11_ADRESSE))->toBeFalse();
});

test('plainte d\'un AUTRE espace : le VERP de l\'espace A relevé dans l\'espace B => « à rattacher », aucune opposition', function () {
    $autre = F::espace('zz-k11-b');
    $this->registre->noterInjection($this->inj, new MessageOrigine($this->inj, K11_ADRESSE), $this->ws); // appartient à l'espace A
    $this->releve->relever($autre, K11_BOITE); // curseur de l'espace B
    $this->lecteur->deposer(K11_BOITE, k11Msg(1, ['arf' => 'abuse', 'arfDest' => K11_ADRESSE, 'locales' => [$this->verp]]));
    $this->releve->relever($autre, K11_BOITE);

    expect(DB::table('envoi_evenements')->where('workspace_id', $autre)->where('statut', 'a_rattacher')->count())->toBe(1)
        ->and(DB::table('envoi_evenements')->where('statut', 'rattache')->count())->toBe(0)
        ->and($this->recepteur->appels)->toBe(0)
        ->and(DB::table('opt_out')->count())->toBe(0);
});

/** Récepteur de bout en bout : opposition (CRM) PUIS compteurs des freins (L7). */
function k11ChaineFreins(object $t): ReleveImap
{
    $chaine = new RecepteurRetoursEnChaine(
        new RecepteurRetoursCrm,
        new RecepteurRetoursFreins(new CompteurRetours(app(DepotChauffe::class), app(ResolveurFournisseur::class), fn () => new DateTimeImmutable('2026-12-02 11:00:00', new DateTimeZone('Europe/Paris')))),
    );

    return new ReleveImap($t->lecteur, $t->registre, $chaine);
}

test('S10 : ARF, puis réponse hostile, puis abuse@ => 3 plaintes comptées ; la 1re = palier tenu, la 2e en 7 jours = arrêt 48 h', function () {
    $releve = k11ChaineFreins($this);
    $maintenant = new DateTimeImmutable('2026-12-02 12:00:00', new DateTimeZone('Europe/Paris'));
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null);
    $durees = [];
    $evaluer = function () use ($garde, $maintenant, &$durees): array {
        $decisions = $garde->evaluer($this->ws, $maintenant);
        $durees = array_map(fn ($d) => $d->dureeHeures, $decisions);

        return array_map(fn ($d) => $d->motif, $decisions);
    };
    $depot = app(DepotChauffe::class);
    $jour = JourEnvoi::de($maintenant);

    // 1) ARF (VERP signé)
    $this->lecteur->deposer(K11_BOITE, k11Msg(1, ['arf' => 'abuse', 'arfDest' => K11_ADRESSE, 'locales' => [$this->verp]]));
    $releve->relever($this->ws, K11_BOITE);
    expect($depot->plaintes7Jours($this->ws, $jour))->toBe(1)
        ->and($evaluer())->toBe([]); // palier tenu : alerte de relecture, AUCUNE pause

    // 2) réponse hostile
    $this->lecteur->deposer(K11_BOITE, k11Msg(2, ['irt' => 'envoi-k11@d.example.test', 'de' => K11_ADRESSE, 'corps' => 'Je vais porter plainte.']));
    $releve->relever($this->ws, K11_BOITE);
    expect($depot->plaintes7Jours($this->ws, $jour))->toBe(2)
        ->and($evaluer())->toBe([DecisionFrein::PLAINTES_ARRET]) // la 2e en 7 jours : arrêt 48 h
        ->and($durees)->toBe([48]); // durée de la pause L7 (tout l'espace)

    // 3) message sur abuse@
    $this->lecteur->deposer(K11_BOITE, k11Msg(3, ['irt' => 'envoi-k11@d.example.test', 'de' => K11_ADRESSE, 'corps' => 'Cessez ces envois svp.', 'locales' => ['abuse@d.example.test']]));
    $releve->relever($this->ws, K11_BOITE);
    expect($depot->totaux7Jours($this->ws, $jour)['plaintes'])->toBe(3)
        ->and(DB::table('envoi_evenements')->where('classe', 'hostile')->where('statut', 'rattache')->count())->toBe(3)
        ->and(k11Plainte())->toBeTrue()
        ->and(DB::table('envoi_pauses')->where('workspace_id', $this->ws)->count())->toBeGreaterThan(0);
});

test('S10 : plainte d\'un autre espace => compteurs de l\'espace concerné SEULEMENT (décision B5 en attente : défaut = limité à l\'espace)', function () {
    $autre = F::espace('zz-k11-c');
    $releve = k11ChaineFreins($this);
    $this->lecteur->deposer(K11_BOITE, k11Msg(1, ['arf' => 'abuse', 'arfDest' => K11_ADRESSE, 'locales' => [$this->verp]]));
    $this->lecteur->deposer(K11_BOITE, k11Msg(2, ['irt' => 'envoi-k11@d.example.test', 'de' => K11_ADRESSE, 'corps' => 'Je vais porter plainte.']));
    $releve->relever($this->ws, K11_BOITE);
    $jour = JourEnvoi::de(new DateTimeImmutable('2026-12-02 12:00:00', new DateTimeZone('Europe/Paris')));

    expect(app(DepotChauffe::class)->plaintes7Jours($this->ws, $jour))->toBe(2)
        ->and(app(DepotChauffe::class)->plaintes7Jours($autre, $jour))->toBe(0);
});

test('S11 : réponse humaine => classée « Réponses », rattachée, transmise au séquenceur, aucune opposition', function () {
    k11Releve($this, k11Msg(1, ['irt' => 'envoi-k11@d.example.test', 'de' => K11_ADRESSE, 'corps' => 'Bonjour, oui cela m\'intéresse, pouvez-vous me rappeler ?']));

    expect(DB::table('envoi_evenements')->where('classe', 'reponse_humaine')->where('statut', 'rattache')->count())->toBe(1)
        ->and($this->recepteur->vus)->toHaveCount(1)
        ->and($this->recepteur->vus[0]->classe)->toBe(ClasseRetour::ReponseHumaine)
        ->and(DB::table('opt_out')->count())->toBe(0);
});

test('S12 : STOP => opposition', function () {
    k11Releve($this, k11Msg(1, ['irt' => 'envoi-k11@d.example.test', 'de' => K11_ADRESSE, 'corps' => 'STOP']));

    expect(DB::table('envoi_evenements')->where('classe', 'desinscription')->where('statut', 'rattache')->count())->toBe(1)
        ->and(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(K11_ADRESSE))->count())->toBe(1);
});

test('S13 : absence du bureau => auto-réponse (pause), AUCUNE opposition', function () {
    k11Releve($this, k11Msg(1, ['irt' => 'envoi-k11@d.example.test', 'de' => K11_ADRESSE, 'sujet' => 'Absence du bureau', 'corps' => 'Je suis absent jusqu\'au 15.', 'h' => ['auto-submitted' => 'auto-replied']]));

    expect(DB::table('envoi_evenements')->where('classe', 'auto_reponse')->where('statut', 'rattache')->count())->toBe(1)
        ->and($this->recepteur->vus[0]->classe)->toBe(ClasseRetour::AutoReponse)
        ->and(DB::table('opt_out')->count())->toBe(0)
        ->and(ListeSuppression::estSupprimee(K11_ADRESSE))->toBeFalse();
});

test('ARF BRUT sans Feedback-Type lisible, lu par le lecteur réel => type « illisible », jamais « other »', function () {
    Faux::reinitialiser();
    Faux::deposer(1, str_replace('Feedback-Type: abuse', 'Feedback-Type: ???', k11ArfBrut($this->verp, 'envoi-k11@d.example.test')));
    $lecteur = new LecteurBoiteWebklex(['host' => 'imap.k11.example.test', 'username' => 'u', 'password' => 'p'], K11_BOITE, 'INBOX', static fn () => new FauxProtocoleImap(Config::make(), true, 'ssl'));
    expect($lecteur->lire(K11_BOITE, 0)->messages[0]->typeArf)->toBe('illisible');
    (new ReleveImap($lecteur, $this->registre, $this->recepteur))->relever($this->ws, K11_BOITE);
    expect(DB::table('opt_out')->count())->toBe(0)->and(DB::table('envoi_evenements')->where('statut', 'a_rattacher')->count())->toBe(1);
});

function k11Lecteur(): LecteurBoiteWebklex
{
    return new LecteurBoiteWebklex(['host' => 'imap.k11.example.test', 'username' => 'u', 'password' => 'p'], K11_BOITE, 'INBOX', static fn () => new FauxProtocoleImap(Config::make(), true, 'ssl'));
}

test('RÈGLE DE FER : ARF dont l\'ANALYSE ÉCHOUE (UTF-8 invalide) + In-Reply-To connu => « à rattacher », opt_out = 0', function () {
    Faux::reinitialiser();
    $brut = str_replace(
        ["Subject: Rapport abus\r\n", "Ceci est un rapport d'abus."],
        ["Subject: Rapport abus\r\nIn-Reply-To: <envoi-k11@d.example.test>\r\nReferences: <envoi-k11@d.example.test>\r\n", "Rapport \xFF\xFE invalide"],
        k11ArfBrut($this->verp, 'envoi-k11@d.example.test'),
    );
    $brut = str_replace("Content-Type: text/plain; charset=UTF-8\r\n\r\nRapport", "Content-Type: text/html; charset=UTF-8\r\n\r\n<p>Rapport", $brut);
    Faux::deposer(1, $brut);
    $lecteur = k11Lecteur();
    $m = $lecteur->lire(K11_BOITE, 0)->messages[0];

    expect($m->analyseEchouee)->toBeTrue()
        ->and($m->typeArf)->toBe('illisible');

    (new ReleveImap($lecteur, $this->registre, $this->recepteur))->relever($this->ws, K11_BOITE);
    expect(DB::table('opt_out')->count())->toBe(0)
        ->and(DB::table('envoi_evenements')->where('statut', 'a_rattacher')->count())->toBe(1)
        ->and($this->recepteur->appels)->toBe(0);
});

test('ARF dont le VERP n\'est QUE dans Original-Mail-From (To / Delivered-To = fbl@) : rattaché', function () {
    Faux::reinitialiser();
    $brut = str_replace([": {$this->verp}\r\n"], [": fbl@d.example.test\r\n"], k11ArfBrut($this->verp, 'envoi-k11@d.example.test'));
    expect($brut)->not->toContain("To: {$this->verp}")->and($brut)->toContain("Original-Mail-From: <{$this->verp}>");
    Faux::deposer(1, $brut);
    $lecteur = k11Lecteur();
    $m = $lecteur->lire(K11_BOITE, 0)->messages[0];

    expect($m->adressesLocales)->toContain($this->verp);

    (new ReleveImap($lecteur, $this->registre, $this->recepteur))->relever($this->ws, K11_BOITE);
    expect(DB::table('envoi_evenements')->where('statut', 'rattache')->count())->toBe(1)->and(k11Plainte())->toBeTrue();
});

test('rebond / réponse (non-ARF) : le VERP l\'emporte sur le Message-ID cité d\'une AUTRE injection', function () {
    $autre = k11Injection($this->ws);
    $this->registre->noter('autre-envoi@d.example.test', new MessageOrigine($autre, 'autre@client.example.test'), $this->ws);
    k11Releve($this, k11Msg(1, ['irt' => 'autre-envoi@d.example.test', 'de' => K11_ADRESSE, 'corps' => 'STOP', 'locales' => [$this->verp]]));

    expect(DB::table('envoi_evenements')->where('statut', 'rattache')->where('id_injection', $this->inj)->count())->toBe(1)
        ->and(DB::table('envoi_evenements')->where('id_injection', $autre)->count())->toBe(0)
        ->and(ListeSuppression::estSupprimee('autre@client.example.test'))->toBeFalse();
});

test('index (workspace_id, id_injection) présent sur envoi_messages_envoyes', function () {
    $def = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'envoi_messages_envoyes'"))->pluck('indexdef')->implode("\n");
    expect($def)->toContain('(workspace_id, id_injection)');
});

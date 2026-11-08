<?php

/**
 * Envoi P0 L6 — points des audits (lecture seule prouvée sur le fil, pannes,
 * UTF-8 invalide, DSN, rattrapage, freins L7…). AUCUN réseau. Fixtures FICTIVES.
 * Horloge figée, UTC.
 */

use App\Envoi\Contrat\EvenementEnvoi;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\Classificateur;
use App\Envoi\Imap\EchecAnalyse;
use App\Envoi\Imap\ExtraitTexte;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\LecteurBoiteWebklex;
use App\Envoi\Imap\LotBoite;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\MessageOrigine;
use App\Envoi\Imap\RecepteurRetours;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RecepteurRetoursEnChaine;
use App\Envoi\Imap\RecepteurRetoursFreins;
use App\Envoi\Imap\RegistreMessagesEnvoyesDb;
use App\Envoi\Imap\RegistreMessagesEnvoyesMemoire;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\ReleveIncomplete;
use App\Envoi\Imap\RetourDetecte;
use App\Envoi\Retours\AppliquerRetour;
use App\Support\ListeSuppression;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\FauxProtocoleImap;
use Tests\Support\FauxServeurImap as Faux;
use Tests\TestCase;
use Webklex\PHPIMAP\Config;

uses(TestCase::class, RefreshDatabase::class);

const AUDIT_BOITE = 'boite-zz-2';
const AUDIT_ADRESSE = 'contact@zz-client.example.invalid';
const AUDIT_MSG_ENVOYE = 'envoi-1@zz.example.invalid';

beforeEach(function () {
    CarbonImmutable::setTestNow(new DateTimeImmutable('2026-12-06 10:00:00', new DateTimeZone('UTC')));
    $this->ws = F::espace('zz-audit');
    config(['crm.ingest.business_workspace' => F::slug($this->ws)]);
    Faux::reinitialiser();
    $this->idInjection = auditInjection($this->ws);
    $this->registre = new RegistreMessagesEnvoyesMemoire;
    $this->registre->noter(AUDIT_MSG_ENVOYE, new MessageOrigine($this->idInjection, AUDIT_ADRESSE));
    $this->lecteur = new LecteurBoiteMemoire;
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function auditInjection(string $ws): string
{
    $boite = (string) Str::uuid();
    DB::table('envoi_boites')->insert([
        'id' => $boite, 'workspace_id' => $ws, 'adresse' => 'zz-' . substr($boite, 0, 8) . '@test.invalid',
        'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre',
    ]);
    $id = (string) Str::uuid();
    DB::table('envoi_injections_smtp')->insert([
        'workspace_id' => $ws, 'boite_id' => $boite, 'id_injection' => $id, 'cle_idempotence' => 'zz-' . $id,
    ]);

    return $id;
}

function auditLecteurReel(): LecteurBoiteWebklex
{
    return new LecteurBoiteWebklex(
        ['host' => 'imap.zz.example.invalid', 'username' => 'u', 'password' => 'p'],
        AUDIT_BOITE,
        'INBOX',
        static fn () => new FauxProtocoleImap(Config::make(), true, 'ssl'),
    );
}

function auditMsg(int $uid, string $date = '2026-12-02 10:00:00', ?string $mid = null, ?string $irt = AUDIT_MSG_ENVOYE, string $corps = 'Merci.', string $sujet = 'Re: bonjour', ?string $dsn = null, ?string $dest = null, ?string $dsnMid = null, array $h = []): MessageBrut
{
    return new MessageBrut($uid, $mid ?? "a{$uid}@zz-client.example.invalid", $irt, [], AUDIT_ADRESSE, new DateTimeImmutable($date, new DateTimeZone('UTC')), $sujet, $corps, $h, $dsn, $dsnMid, $dest);
}

function auditCompte(string $ws): int
{
    return DB::table('envoi_evenements')->where('workspace_id', $ws)->count();
}

// ───────────── (1) lecture seule PROUVÉE sur le fil ─────────────

test('1 : la boîte est lue en LECTURE SEULE — commandes enregistrées : EXAMINE + BODY.PEEK[], jamais SELECT/STORE/RFC822', function () {
    Faux::deposer(1, Faux::brut('a1@zz-client.example.invalid', 'STOP, merci'));
    Faux::deposer(2, Faux::brut('a2@zz-client.example.invalid', 'Bonjour, je regarde.'));

    $lecteur = auditLecteurReel();
    expect($lecteur->uidValidity(AUDIT_BOITE))->toBe(777);
    $lot = $lecteur->lire(AUDIT_BOITE, 0);

    expect($lot->messages)->toHaveCount(2)
        ->and($lot->messages[0]->corps)->toContain('STOP')
        ->and($lot->messages[0]->messageId)->toBe('a1@zz-client.example.invalid')
        ->and($lot->suite)->toBeFalse();

    $commandes = Faux::commandes();
    $toutes = implode("\n", $commandes);
    expect($commandes)->not->toBe([])
        ->and($toutes)->toContain('EXAMINE')
        ->and($toutes)->toContain('BODY.PEEK[]');
    foreach ($commandes as $c) {
        expect($c)->not->toMatch('/^SELECT\b/i')
            ->not->toMatch('/\bSTORE\b/i')
            ->not->toMatch('/\b(EXPUNGE|COPY|MOVE|APPEND|DELETE|CREATE|RENAME)\b/i')
            ->not->toMatch('/\bRFC822(?!\.SIZE)\b/')       // ni RFC822, ni RFC822.TEXT, ni RFC822.HEADER
            ->not->toMatch('/(?<!PEEK)\bBODY\[/');          // BODY[] sans PEEK pose \Seen
    }
});

// ───────────── (2) panne passagère ≠ message illisible ─────────────

test('2 : une panne passagère pendant le téléchargement n\'écarte JAMAIS un message et n\'avance pas le curseur ; le STOP n\'est pas perdu', function () {
    Faux::deposer(1, Faux::brut('a1@zz-client.example.invalid', 'Merci.'));
    Faux::deposer(2, Faux::brut('a2@zz-client.example.invalid', 'STOP, ne me contactez plus'));
    Faux::$panneSurCorps = 2;

    $releve = new ReleveImap(auditLecteurReel(), $this->registre, new RecepteurRetoursCrm);
    expect(fn () => $releve->relever($this->ws, AUDIT_BOITE))->toThrow(RuntimeException::class);
    expect(DB::table('envoi_evenements')->where('classe', 'reponse_humaine')->where('uid', 2)->count())->toBe(0)
        ->and(DB::table('envoi_evenements')->where('statut', 'a_rattacher')->where('uid', 2)->count())->toBe(0)
        ->and(DB::table('envoi_evenements')->where('uid', 2)->count())->toBe(0)
        ->and((int) DB::table('envoi_curseurs_imap')->where('boite', AUDIT_BOITE)->value('dernier_uid'))->toBeLessThan(2);

    Faux::$panneSurCorps = null;           // le réseau revient : le message est RELU
    Faux::$commandes = [];
    (new ReleveImap(auditLecteurReel(), $this->registre, new RecepteurRetoursCrm))->relever($this->ws, AUDIT_BOITE);
    expect(DB::table('envoi_evenements')->where('uid', 2)->value('classe'))->toBe('desinscription')
        ->and(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(AUDIT_ADRESSE))->count())->toBeGreaterThan(0);
});

test('2 : une panne de la recherche reste une exception technique, rien n\'est noté', function () {
    Faux::deposer(1, Faux::brut('a1@zz-client.example.invalid', 'STOP'));
    Faux::$panneSurRecherche = true;
    $releve = new ReleveImap(auditLecteurReel(), $this->registre, new RecepteurRetoursCrm);
    expect(fn () => $releve->relever($this->ws, AUDIT_BOITE))->toThrow(RuntimeException::class);
    expect(auditCompte($this->ws))->toBe(0);
});

test('2 : un message dont le MIME est réellement illisible est écarté (à rattacher), la boîte continue', function () {
    Faux::deposer(1, "pas un message du tout\x00\xFF"); // aucun en-tête : illisible
    Faux::deposer(2, Faux::brut('a2@zz-client.example.invalid', 'STOP'));
    $releve = new ReleveImap(auditLecteurReel(), $this->registre, new RecepteurRetoursCrm);
    $bilan = $releve->relever($this->ws, AUDIT_BOITE);
    expect($bilan->lus)->toBe(2)
        ->and(DB::table('envoi_evenements')->where('uid', 1)->value('statut'))->toBe('a_rattacher')
        ->and(DB::table('envoi_evenements')->where('uid', 2)->value('classe'))->toBe('desinscription');
});

// ───────────── (3) AppliquerRetour (freins L7) ─────────────

test('3 : L6 appelle AppliquerRetour (freins L7) EN PLUS de crm:campagne:retours', function () {
    $recu = [];
    $freins = new class($recu) implements AppliquerRetour
    {
        public function __construct(private array &$recu) {}

        public function appliquer(string $workspaceId, EvenementEnvoi $evenement): void
        {
            $this->recu[] = [$workspaceId, $evenement];
        }
    };
    $recepteur = new RecepteurRetoursEnChaine(new RecepteurRetoursCrm, new RecepteurRetoursFreins($freins));
    $this->lecteur->deposer(AUDIT_BOITE, auditMsg(1, corps: 'STOP'));
    (new ReleveImap($this->lecteur, $this->registre, $recepteur))->relever($this->ws, AUDIT_BOITE);

    expect(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(AUDIT_ADRESSE))->count())->toBeGreaterThan(0)
        ->and($recu)->toHaveCount(1)
        ->and($recu[0][0])->toBe($this->ws)
        ->and($recu[0][1])->toBeInstanceOf(EvenementEnvoi::class)
        ->and($recu[0][1]->type)->toBe('desinscription')
        ->and($recu[0][1]->idInjection)->toBe($this->idInjection)
        ->and($recu[0][1]->adresse)->toBe(AUDIT_ADRESSE)
        ->and($recu[0][1]->id)->toMatch('/^[0-9a-f]{64}$/');
});

test('3 : le constructeur marque le mot de passe #[SensitiveParameter] ; la boîte demandée est contrôlée', function () {
    $marques = array_filter(
        (new ReflectionClass(LecteurBoiteWebklex::class))->getConstructor()->getParameters(),
        static fn (ReflectionParameter $x): bool => $x->getAttributes(SensitiveParameter::class) !== [],
    );
    expect($marques)->not->toBe([]);
    expect(fn () => auditLecteurReel()->uidValidity('une-autre-boite'))->toThrow(InvalidArgumentException::class);
    expect(fn () => auditLecteurReel()->lire('une-autre-boite', 0))->toThrow(InvalidArgumentException::class);
});

// ───────────── (7) rattrapage : pagination sur les UID bruts ─────────────

test('7 : le rattrapage pagine sur les UID bruts jusqu\'à la fin réelle, même si le lot de 200 est filtré par la date', function () {
    $sauvegarde = new DateTimeImmutable('2026-12-01 03:00:00', new DateTimeZone('UTC'));
    for ($i = 1; $i <= 450; $i++) {   // 449 vieux messages (hors fenêtre), le STOP récent est le 450e
        $this->lecteur->deposer(AUDIT_BOITE, auditMsg($i, $i < 450 ? '2026-06-01 10:00:00' : '2026-12-03 10:00:00', ['corps' => $i < 450 ? 'Merci.' : 'STOP']));
    }
    $bilan = (new ReleveImap($this->lecteur, $this->registre, new RecepteurRetoursCrm))->rattraper($this->ws, AUDIT_BOITE, $sauvegarde);

    expect($bilan->lus)->toBe(1)
        ->and(DB::table('envoi_evenements')->where('uid', 450)->value('classe'))->toBe('desinscription')
        ->and(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(AUDIT_ADRESSE))->count())->toBeGreaterThan(0);
});

test('7 : le lecteur en mémoire reproduit le comportement : lot de 200 UID bruts, filtré après coup, « suite » vrai', function () {
    for ($i = 1; $i <= 205; $i++) {
        $this->lecteur->deposer(AUDIT_BOITE, auditMsg($i, '2026-06-01 10:00:00'));
    }
    $lot = $this->lecteur->lire(AUDIT_BOITE, 0, new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')), 200);
    expect($lot)->toBeInstanceOf(LotBoite::class)
        ->and($lot->messages)->toBe([])
        ->and($lot->dernierUid)->toBe(200)
        ->and($lot->suite)->toBeTrue();
    $fin = $this->lecteur->lire(AUDIT_BOITE, 200, new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')), 200);
    expect($fin->dernierUid)->toBe(205)->and($fin->suite)->toBeFalse();
});

test('7 : lotPlein suit les UID bruts (le curseur avance sur un lot entièrement filtré)', function () {
    for ($i = 1; $i <= 205; $i++) {
        $this->lecteur->deposer(AUDIT_BOITE, auditMsg($i));
    }
    $bilan = (new ReleveImap($this->lecteur, $this->registre, new RecepteurRetoursCrm))->relever($this->ws, AUDIT_BOITE);
    expect($bilan->lotPlein)->toBeTrue()
        ->and((int) DB::table('envoi_curseurs_imap')->value('dernier_uid'))->toBe(200);
});

// ───────────── (5) échecs de regex : jamais « réponse humaine » ─────────────

test('5 : UTF-8 invalide = STOP par prudence, jamais « réponse humaine »', function () {
    $invalide = "Bonjour \xC3\x28 \xFF ceci est invalide";
    expect(Classificateur::classer(auditMsg(1, corps: $invalide)))->toBe(ClasseRetour::Desinscription)
        ->and(Classificateur::classer(auditMsg(1, sujet: "Re: \xFF")))->toBe(ClasseRetour::Desinscription);
    expect(Classificateur::classer(new MessageBrut(1, 'x@y', null, [], AUDIT_ADRESSE, new DateTimeImmutable('now', new DateTimeZone('UTC')), 's', 'texte banal', analyseEchouee: true)))
        ->toBe(ClasseRetour::Desinscription);
});

test('5 : HTML invalide ou trop coûteux : l\'extraction échoue bruyamment, pas de chaîne vide silencieuse', function () {
    expect(fn () => ExtraitTexte::choisir('', "\xFF<p>STOP</p>"))->toThrow(EchecAnalyse::class);
    expect(ExtraitTexte::choisir('', '<p>Bonjour</p>'))->toContain('Bonjour');
});

test('5 : un message HTML invalide lu par le lecteur est marqué « analyse échouée » → STOP prudent', function () {
    Faux::deposer(1, "From: a@zz-client.example.invalid\r\nSubject: x\r\nMessage-ID: <h1@zz-client.example.invalid>\r\nIn-Reply-To: <" . AUDIT_MSG_ENVOYE . ">\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Bonjour \xFF\xFE</p>\r\n");
    $lot = auditLecteurReel()->lire(AUDIT_BOITE, 0);
    $m = $lot->messages[0];
    expect($m->ecarte)->toBeNull()->and(Classificateur::classer($m))->not->toBe(ClasseRetour::ReponseHumaine);
});

// ───────────── (6) DSN ─────────────

test('6 : un rebond DUR sans Final-Recipient n\'est PAS appliqué à l\'adresse d\'origine (douteux, à rattacher)', function () {
    $this->lecteur->deposer(AUDIT_BOITE, auditMsg(1, corps: '', sujet: 'Undelivered', dsn: '5.1.1', dsnMid: AUDIT_MSG_ENVOYE));
    (new ReleveImap($this->lecteur, $this->registre, new RecepteurRetoursCrm))->relever($this->ws, AUDIT_BOITE);
    expect(DB::table('envoi_evenements')->where('uid', 1)->value('statut'))->toBe('a_rattacher')
        ->and(DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte(AUDIT_ADRESSE))->count())->toBe(0);
});

test('6 : un 5.7.x (politique, réputation) n\'est PAS un rebond dur d\'adresse', function () {
    foreach (['5.7.1', '5.7.0', '5.7.606'] as $code) {
        expect(Classificateur::classer(auditMsg(1, dsn: $code, dest: AUDIT_ADRESSE)))->not->toBe(ClasseRetour::RebondDur, $code);
    }
    $this->lecteur->deposer(AUDIT_BOITE, auditMsg(1, corps: 'rejected as spam', dsn: '5.7.1', dsnMid: AUDIT_MSG_ENVOYE, dest: AUDIT_ADRESSE));
    (new ReleveImap($this->lecteur, $this->registre, new RecepteurRetoursCrm))->relever($this->ws, AUDIT_BOITE);
    expect(DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte(AUDIT_ADRESSE))->count())->toBe(0)
        ->and(DB::table('envoi_evenements')->where('uid', 1)->value('code_dsn'))->toBe('5.7.1');
    expect(Classificateur::classer(auditMsg(1, dsn: '5.1.1', dest: AUDIT_ADRESSE)))->toBe(ClasseRetour::RebondDur);
});

// ───────────── (12) mots hostiles, Precedence ─────────────

test('12 : « aucune plainte », « dans mes spams » ne sont pas hostiles ; STOP prime ; Precedence bulk n\'écrase pas un STOP', function () {
    expect(Classificateur::classer(auditMsg(1, corps: 'Je n\'ai aucune plainte, merci pour votre message.')))->toBe(ClasseRetour::ReponseHumaine)
        ->and(Classificateur::classer(auditMsg(1, corps: 'Votre mail était dans mes spams, je l\'ai retrouvé.')))->toBe(ClasseRetour::ReponseHumaine)
        ->and(Classificateur::classer(auditMsg(1, corps: 'Il faut le signaler à mon assistante, c\'est illégal de ne pas répondre vite ? non.')))->toBe(ClasseRetour::ReponseHumaine);
    expect(Classificateur::classer(auditMsg(1, corps: 'STOP. Sinon je contacte mon avocat.')))->toBe(ClasseRetour::Desinscription)
        ->and(Classificateur::classer(auditMsg(1, corps: 'Je vais porter plainte.')))->toBe(ClasseRetour::Hostile);
    expect(Classificateur::classer(auditMsg(1, corps: 'STOP', h: ['precedence' => 'bulk'])))->toBe(ClasseRetour::Desinscription)
        ->and(Classificateur::classer(auditMsg(1, corps: 'unsubscribe me', h: ['precedence' => 'junk'])))->toBe(ClasseRetour::Desinscription)
        ->and(Classificateur::classer(auditMsg(1, corps: 'Bonjour à tous.', h: ['precedence' => 'bulk'])))->toBe(ClasseRetour::AutoReponse);
});

// ───────────── (11) rattachement ultérieur, ajout seul ─────────────

test('11 : envoi_rattachements : ajout seul lié à l\'événement, un seul rattachement par événement', function () {
    $this->lecteur->deposer(AUDIT_BOITE, auditMsg(1, irt: 'inconnu@ailleurs.example.invalid'));
    (new ReleveImap($this->lecteur, $this->registre, new RecepteurRetoursCrm))->relever($this->ws, AUDIT_BOITE);
    $evenement = DB::table('envoi_evenements')->where('uid', 1)->first();
    expect($evenement->statut)->toBe('a_rattacher');

    DB::table('envoi_rattachements')->insert([
        'workspace_id' => $this->ws, 'evenement_id' => $evenement->id, 'id_injection' => $this->idInjection,
        'empreinte_destinataire' => ListeSuppression::empreinte(AUDIT_ADRESSE), 'decide_par' => 'revue-humaine',
    ]);
    expect(DB::table('envoi_rattachements')->count())->toBe(1)
        ->and(DB::table('envoi_evenements')->where('id', $evenement->id)->value('statut'))->toBe('a_rattacher'); // l'événement, lui, ne change JAMAIS

    $refuse = static function (string $sql): bool {
        DB::statement('SAVEPOINT s');
        try {
            DB::statement($sql);

            return false;
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT s');

            return true;
        }
    };
    expect($refuse('UPDATE envoi_rattachements SET decide_par = \'x\''))->toBeTrue()
        ->and($refuse('DELETE FROM envoi_rattachements'))->toBeTrue()
        ->and($refuse('TRUNCATE envoi_rattachements'))->toBeTrue();
    expect(fn () => DB::transaction(fn () => DB::table('envoi_rattachements')->insert([
        'workspace_id' => $this->ws, 'evenement_id' => $evenement->id, 'id_injection' => $this->idInjection,
        'empreinte_destinataire' => ListeSuppression::empreinte(AUDIT_ADRESSE), 'decide_par' => 'autre',
    ])))->toThrow(QueryException::class);
});

// ───────────── (8) erreur propre à un message ─────────────

/** Récepteur qui échoue pour un UID donné (reconnu à la clé = empreinte du Message-ID). */
function auditRecepteurQuiEchoue(string $messageId): RecepteurRetours
{
    return new class($messageId) implements RecepteurRetours
    {
        public int $appels = 0;

        public function __construct(private string $messageId) {}

        public function recevoir(string $workspaceId, RetourDetecte $retour): void
        {
            $this->appels++;
            if ($retour->cle === hash('sha256', 'mid:' . $this->messageId)) {
                throw new RuntimeException('crm:campagne:retours a échoué');
            }
        }
    };
}

test('8 : un message en erreur ne bloque pas la boîte ; le curseur reste avant lui ; compteur ; mise à l\'écart après N échecs', function () {
    $this->lecteur->deposer(AUDIT_BOITE, auditMsg(1, corps: 'STOP'));                       // échoue
    $this->lecteur->deposer(AUDIT_BOITE, auditMsg(2, corps: 'Merci.'));
    $this->lecteur->deposer(AUDIT_BOITE, auditMsg(3, corps: 'STOP'));                       // réussit malgré l'échec du 1
    $releve = new ReleveImap($this->lecteur, $this->registre, auditRecepteurQuiEchoue('a1@zz-client.example.invalid'));

    expect(fn () => $releve->relever($this->ws, AUDIT_BOITE))->toThrow(ReleveIncomplete::class);
    expect(DB::table('envoi_evenements')->orderBy('uid')->pluck('uid')->map(fn ($u) => (int) $u)->all())->toBe([2, 3])   // 2 et 3 traités
        ->and((int) DB::table('envoi_curseurs_imap')->value('dernier_uid'))->toBe(0)                                      // le curseur ne saute pas le 1
        ->and(DB::table('envoi_echecs_releve')->count())->toBe(1);

    expect(fn () => $releve->relever($this->ws, AUDIT_BOITE))->toThrow(ReleveIncomplete::class);
    expect(DB::table('envoi_echecs_releve')->count())->toBe(2);

    $bilan = $releve->relever($this->ws, AUDIT_BOITE);   // 3e échec : mis à l'écart, plus d'exception
    expect($bilan->enErreur)->toBeGreaterThanOrEqual(1)
        ->and(DB::table('envoi_evenements')->where('uid', 1)->value('statut'))->toBe('a_rattacher')
        ->and((int) DB::table('envoi_curseurs_imap')->value('dernier_uid'))->toBe(3)
        ->and(DB::table('envoi_echecs_releve')->count())->toBe(3);
    expect(json_encode(DB::table('envoi_echecs_releve')->get()))->not->toContain('zz-client.example.invalid');
});

// ───────────── (9) pas de double comptage ; (4) empreinte stable ─────────────

test('9 : un rebond mou n\'est compté qu\'une fois, même après renumérotation des UID (clé = empreinte du message)', function () {
    $appels = [];
    $espion = new class($appels) implements RecepteurRetours
    {
        public function __construct(private array &$appels) {}

        public function recevoir(string $workspaceId, RetourDetecte $retour): void
        {
            $this->appels[] = $retour;
        }
    };
    $this->lecteur->definirUidValidity(AUDIT_BOITE, 10);
    $this->lecteur->deposer(AUDIT_BOITE, auditMsg(1, corps: '', sujet: 'Delayed', dsn: '4.2.2', dsnMid: AUDIT_MSG_ENVOYE, dest: AUDIT_ADRESSE, mid: 'dsn-mou@zz.example.invalid'));
    $releve = new ReleveImap($this->lecteur, $this->registre, $espion);
    $releve->relever($this->ws, AUDIT_BOITE);
    $this->lecteur->renumeroter(AUDIT_BOITE, 11);
    $releve->relever($this->ws, AUDIT_BOITE);
    $releve->rattraper($this->ws, AUDIT_BOITE, new DateTimeImmutable('2026-12-01', new DateTimeZone('UTC')));

    expect($appels)->toHaveCount(1)
        ->and($appels[0]->classe)->toBe(ClasseRetour::RebondMou)
        ->and($appels[0]->cle)->toBe(hash('sha256', 'mid:dsn-mou@zz.example.invalid'));
});

test('4 : message écarté sans Message-ID : empreinte stable, indépendante d\'UIDVALIDITY (hash du contenu)', function () {
    Faux::deposer(1, "pas un message du tout\x00\xFF");
    $releve = new ReleveImap(auditLecteurReel(), $this->registre, new RecepteurRetoursCrm);
    $releve->relever($this->ws, AUDIT_BOITE);
    $empreinte = DB::table('envoi_evenements')->where('uid', 1)->value('empreinte_message');

    Faux::$uidValidity = 778;                 // renumérotation côté serveur
    Faux::$messages = [5 => Faux::$messages[1]];
    $bilan = $releve->relever($this->ws, AUDIT_BOITE);
    expect($bilan->doublons)->toBe(1)
        ->and(auditCompte($this->ws))->toBe(1)
        ->and($empreinte)->toBe(hash('sha256', 'contenu:' . hash('sha256', "pas un message du tout\x00\xFF")));
});

// ───────────── (14) id_injection ─────────────

test('14 : id_injection est une clé étrangère vers envoi_injections_smtp ; un identifiant inventé est refusé', function () {
    $this->lecteur->deposer(AUDIT_BOITE, auditMsg(1, corps: 'STOP'));
    (new ReleveImap($this->lecteur, $this->registre, new RecepteurRetoursCrm))->relever($this->ws, AUDIT_BOITE);
    expect(DB::table('envoi_evenements')->where('uid', 1)->value('id_injection'))->toBe($this->idInjection);
    expect(fn () => DB::transaction(fn () => DB::table('envoi_evenements')->insert([
        'workspace_id' => $this->ws, 'boite' => 'zz-fk', 'uid_validity' => 1, 'uid' => 1,
        'empreinte_message' => hash('sha256', 'fk'), 'classe' => 'reponse_humaine', 'statut' => 'rattache',
        'id_injection' => (string) Str::uuid(), 'empreinte_expediteur' => hash('sha256', 'e'), 'recu_le' => now(),
    ])))->toThrow(QueryException::class);
});

test('14 : implémentation réelle de RegistreMessagesEnvoyes (table envoi_messages_envoyes)', function () {
    DB::table('envoi_messages_envoyes')->insert([
        'workspace_id' => $this->ws, 'message_id_empreinte' => hash('sha256', 'mid:reel-1@zz.example.invalid'),
        'id_injection' => $this->idInjection, 'empreinte_destinataire' => ListeSuppression::empreinte(AUDIT_ADRESSE),
    ]);
    $registre = new RegistreMessagesEnvoyesDb(fn (string $ws, string $empreinte): ?string => $empreinte === ListeSuppression::empreinte(AUDIT_ADRESSE) ? AUDIT_ADRESSE : null);
    $origine = $registre->retrouver($this->ws, ['inconnu@zz', 'reel-1@zz.example.invalid']);
    expect($origine)->toBeInstanceOf(MessageOrigine::class)
        ->and($origine->idInjection)->toBe($this->idInjection)
        ->and($origine->adresse)->toBe(AUDIT_ADRESSE)
        ->and($registre->retrouver($this->ws, ['inconnu@zz']))->toBeNull()
        ->and((new RegistreMessagesEnvoyesDb(fn () => null))->retrouver($this->ws, ['reel-1@zz.example.invalid']))->toBeNull();
    // MessageOrigine exige un UUID : plus de chaîne libre.
    expect(fn () => new MessageOrigine('inj-1', AUDIT_ADRESSE))->toThrow(InvalidArgumentException::class);
});

// ───────────── (13) aucun réseau, pour de vrai ─────────────

test('13 : aucun code réseau hors du lecteur réel, et le lecteur réel ne crée aucune socket lui-même', function () {
    $vus = 0;
    foreach (glob(app_path('Envoi/Imap/*.php')) as $f) {
        $code = (string) file_get_contents($f);
        $vus++;
        expect($code)->not->toMatch('/\b(fsockopen|stream_socket_client|curl_init|file_get_contents\(\s*[\'"]https?:|Http::)/');
        if (basename($f) !== 'LecteurBoiteWebklex.php') {
            expect($code)->not->toContain('Webklex\\');
        }
    }
    expect($vus)->toBeGreaterThan(10)->and(Faux::commandes())->toBe([]);
});

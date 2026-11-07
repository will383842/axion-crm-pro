<?php

/**
 * Envoi P0 L6 — relève IMAP des réponses et rebonds. AUCUN réseau : lecteur et
 * registre en mémoire. Fixtures FICTIVES (dépôt public) : `.example.invalid`.
 */

use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise as P;
use App\Envoi\Imap\AnalyseDsn;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\Classificateur;
use App\Envoi\Imap\ExtraitTexte;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\LecteurBoiteWebklex;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\MessageOrigine;
use App\Envoi\Imap\RecepteurRetours;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RegistreMessagesEnvoyesMemoire;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\RetourDetecte;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const RELEVE_BOITE = 'boite-zz-1';
const RELEVE_ADRESSE = 'contact@zz-client.example.invalid';
const RELEVE_CORPS_SECRET = 'texte-confidentiel-du-corps-zz';

beforeEach(function () {
    $this->ws = F::espace('zz-releve');
    config(['crm.ingest.business_workspace' => F::slug($this->ws)]);
    $this->lecteur = new LecteurBoiteMemoire;
    $this->registre = new RegistreMessagesEnvoyesMemoire;
    $this->registre->noter('envoi-1@zz.example.invalid', new MessageOrigine('inj-1', RELEVE_ADRESSE));
    $this->releve = new ReleveImap($this->lecteur, $this->registre, new RecepteurRetoursCrm);
});

/** @param array<string, mixed> $s */
function releveMsg(int $uid, array $s = []): MessageBrut
{
    $a = array_merge([
        'mid' => "r{$uid}@zz-client.example.invalid", 'irt' => 'envoi-1@zz.example.invalid', 'refs' => [],
        'de' => RELEVE_ADRESSE, 'le' => '2026-12-02 10:00:00', 'sujet' => 'Re: bonjour', 'corps' => 'Merci, je regarde ça.',
        'h' => [], 'dsn' => null, 'dsnMid' => null,
    ], $s);

    return new MessageBrut(
        $uid,
        $a['mid'],
        $a['irt'],
        $a['refs'],
        $a['de'],
        new DateTimeImmutable($a['le'], new DateTimeZone('Europe/Paris')),
        $a['sujet'],
        $a['corps'],
        $a['h'],
        $a['dsn'],
        $a['dsnMid'],
    );
}

function releveDeposer(object $t, MessageBrut ...$ms): void
{
    foreach ($ms as $m) {
        $t->lecteur->deposer(RELEVE_BOITE, $m);
    }
}

function releveCompte(string $ws): int
{
    return DB::table('envoi_evenements')->where('workspace_id', $ws)->count();
}

test('classification : les six classes, fr et en', function () {
    $cas = [
        [['corps' => 'Bonjour, pouvez-vous me rappeler demain ?'], ClasseRetour::ReponseHumaine],
        [['h' => ['auto-submitted' => 'auto-replied'], 'corps' => 'Je suis absent'], ClasseRetour::AutoReponse],
        [['sujet' => 'Absence du bureau'], ClasseRetour::AutoReponse],
        [['sujet' => 'Automatic reply: hello'], ClasseRetour::AutoReponse],
        [['corps' => 'STOP'], ClasseRetour::Desinscription],
        [['corps' => 'Merci de me désinscrire de vos envois.'], ClasseRetour::Desinscription],
        [['corps' => 'Please unsubscribe me.'], ClasseRetour::Desinscription],
        [['corps' => 'Je ne souhaite plus recevoir vos messages'], ClasseRetour::Desinscription],
        [['corps' => 'Remove me from your list'], ClasseRetour::Desinscription],
        [['corps' => 'Ceci est du spam, je vais porter plainte.'], ClasseRetour::Hostile],
        [['corps' => 'I will contact my lawyer'], ClasseRetour::Hostile],
        [['dsn' => '5.1.1'], ClasseRetour::RebondDur],
        [['dsn' => '5.7.1'], ClasseRetour::RebondDur],
        [['dsn' => '4.2.2'], ClasseRetour::RebondMou],
    ];
    foreach ($cas as [$s, $attendu]) {
        expect(Classificateur::classer(releveMsg(1, $s)))->toBe($attendu, json_encode($s));
    }
});

test('classification : le texte cité et les mots collés ne déclenchent pas STOP', function () {
    $cité = "Merci pour l'info.\n> Pour vous désinscrire répondez STOP\n> unsubscribe";
    expect(Classificateur::classer(releveMsg(1, ['corps' => $cité])))->toBe(ClasseRetour::ReponseHumaine);
    expect(Classificateur::classer(releveMsg(1, ['corps' => 'Mon desktop est en panne'])))->toBe(ClasseRetour::ReponseHumaine);
    // une auto-réponse qui cite « unsubscribe » reste une auto-réponse
    expect(Classificateur::classer(releveMsg(1, ['h' => ['auto-submitted' => 'auto-generated'], 'corps' => 'unsubscribe'])))->toBe(ClasseRetour::AutoReponse);
});

test('idempotence : relire deux fois ne crée aucun doublon', function () {
    releveDeposer($this, releveMsg(1), releveMsg(2, ['corps' => 'STOP']));
    $a = $this->releve->relever($this->ws, RELEVE_BOITE);
    $b = $this->releve->relever($this->ws, RELEVE_BOITE);

    expect($a->nouveaux)->toBe(2)->and($b->nouveaux)->toBe(0)->and(releveCompte($this->ws))->toBe(2);
    // même avec un curseur remis à zéro (relecture complète), la clé d'unicité tient
    DB::table('envoi_curseurs_imap')->update(['dernier_uid' => 0]);
    $c = $this->releve->relever($this->ws, RELEVE_BOITE);
    expect($c->doublons)->toBe(2)->and(releveCompte($this->ws))->toBe(2);
});

test('UIDVALIDITY : un changement resynchronise sans doublon ni perte', function () {
    $this->lecteur->definirUidValidity(RELEVE_BOITE, 10);
    releveDeposer($this, releveMsg(1), releveMsg(2));
    $this->releve->relever($this->ws, RELEVE_BOITE);

    $this->lecteur->renumeroter(RELEVE_BOITE, 11); // mêmes messages, UID repartis
    releveDeposer($this, releveMsg(9, ['mid' => 'nouveau@zz-client.example.invalid', 'corps' => 'Un autre message']));
    $bilan = $this->releve->relever($this->ws, RELEVE_BOITE);

    expect($bilan->uidValiditeChangee)->toBeTrue()
        ->and($bilan->nouveaux)->toBe(1)      // le message vraiment nouveau n'est pas perdu
        ->and($bilan->doublons)->toBe(2)      // les deux déjà vus ne sont pas recréés
        ->and(releveCompte($this->ws))->toBe(3)
        ->and((int) DB::table('envoi_curseurs_imap')->value('uid_validity'))->toBe(11);
});

test('rattachement : In-Reply-To, References et Message-ID cité par le DSN', function () {
    $this->registre->noter('envoi-2@zz.example.invalid', new MessageOrigine('inj-2', 'autre@zz-client.example.invalid'));
    $this->registre->noter('envoi-3@zz.example.invalid', new MessageOrigine('inj-3', 'tiers@zz-client.example.invalid'));
    releveDeposer(
        $this,
        releveMsg(1),
        releveMsg(2, ['irt' => null, 'refs' => ['x@y', 'envoi-2@zz.example.invalid']]),
        releveMsg(3, ['irt' => null, 'de' => 'MAILER-DAEMON@zz.example.invalid', 'dsn' => '5.1.1', 'dsnMid' => '<envoi-3@zz.example.invalid>']),
    );
    $this->releve->relever($this->ws, RELEVE_BOITE);

    expect(DB::table('envoi_evenements')->orderBy('uid')->pluck('id_injection')->all())->toBe(['inj-1', 'inj-2', 'inj-3']);
});

test('non rattaché : file « à rattacher », JAMAIS d\'opposition ni de suppression automatique', function () {
    releveDeposer(
        $this,
        releveMsg(1, ['irt' => 'inconnu@ailleurs.example.invalid', 'corps' => 'STOP']),
        releveMsg(2, ['irt' => null, 'dsn' => '5.1.1', 'de' => 'MAILER-DAEMON@zz.example.invalid']),
    );
    $bilan = $this->releve->relever($this->ws, RELEVE_BOITE);

    expect($bilan->aRattacher)->toBe(2)->and($bilan->rattaches)->toBe(0);
    expect(DB::table('envoi_evenements')->where('statut', 'a_rattacher')->count())->toBe(2);
    expect(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(RELEVE_ADRESSE))->count())->toBe(0);
    expect(ListeSuppression::estSupprimee(RELEVE_ADRESSE))->toBeFalse();
});

test('effets rattachés : STOP, hostile et rebond dur retirent l\'adresse', function () {
    releveDeposer($this, releveMsg(1, ['corps' => 'STOP']));
    $this->releve->relever($this->ws, RELEVE_BOITE);
    expect(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(RELEVE_ADRESSE))->count())->toBeGreaterThan(0);

    $this->registre->noter('envoi-9@zz.example.invalid', new MessageOrigine('inj-9', 'mort@zz-client.example.invalid'));
    releveDeposer($this, releveMsg(2, ['irt' => null, 'dsn' => '5.1.1', 'dsnMid' => 'envoi-9@zz.example.invalid', 'de' => 'MAILER-DAEMON@zz.example.invalid']));
    $this->releve->relever($this->ws, RELEVE_BOITE);
    expect(DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte('mort@zz-client.example.invalid'))->count())->toBeGreaterThan(0);
});

test('vie privée : ni corps ni adresse en clair dans les tables', function () {
    releveDeposer($this, releveMsg(1, ['corps' => 'STOP ' . RELEVE_CORPS_SECRET]));
    $this->releve->relever($this->ws, RELEVE_BOITE);

    $tout = json_encode([DB::table('envoi_evenements')->get(), DB::table('envoi_curseurs_imap')->get()]);
    expect($tout)->not->toContain(RELEVE_CORPS_SECRET)->not->toContain('zz-client.example.invalid');
});

test('ajout seul : UPDATE et DELETE refusés', function () {
    releveDeposer($this, releveMsg(1));
    $this->releve->relever($this->ws, RELEVE_BOITE);
    foreach (['UPDATE envoi_evenements SET classe = \'rebond_dur\'', 'DELETE FROM envoi_evenements'] as $sql) {
        DB::statement('SAVEPOINT s');
        try {
            DB::statement($sql);
            $refuse = false;
        } catch (QueryException) {
            $refuse = true;
            DB::statement('ROLLBACK TO SAVEPOINT s');
        }
        expect($refuse)->toBeTrue($sql);
    }
});

test('échec de l\'arrêt : l\'événement n\'est pas noté, il sera relu', function () {
    $echec = new class implements RecepteurRetours
    {
        public function recevoir(string $workspaceId, RetourDetecte $retour): void
        {
            throw new RuntimeException('indisponible');
        }
    };
    releveDeposer($this, releveMsg(1, ['corps' => 'STOP']));
    $releve = new ReleveImap($this->lecteur, $this->registre, $echec);
    expect(fn () => $releve->relever($this->ws, RELEVE_BOITE))->toThrow(RuntimeException::class);
    expect(releveCompte($this->ws))->toBe(0);

    $this->releve->relever($this->ws, RELEVE_BOITE);
    expect(releveCompte($this->ws))->toBe(1);
});

test('espace autre que le commercial : l\'arrêt est refusé bruyamment, jamais oublié', function () {
    config(['crm.ingest.business_workspace' => 'zz-autre-espace-inexistant']);
    releveDeposer($this, releveMsg(1, ['corps' => 'STOP']));
    expect(fn () => $this->releve->relever($this->ws, RELEVE_BOITE))->toThrow(RuntimeException::class);
    expect(releveCompte($this->ws))->toBe(0);
});

test('rattrapage après panne : une opposition reçue entre la sauvegarde et la panne est retrouvée et bloque l\'envoi', function () {
    $maintenant = new DateTimeImmutable('2026-12-06 10:00:00', new DateTimeZone('Europe/Paris')); // panne
    $sauvegarde = new DateTimeImmutable('2026-12-01 03:00:00', new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($this->ws, 'federations', true);
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $this->ws, 'empreinte_adresse' => ListeSuppression::empreinte(RELEVE_ADRESSE),
        'verdict' => 'valide', 'verifie_le' => $maintenant->modify('-5 days')->format('c'), 'source' => 'test',
    ]);
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $this->ws, 'nom' => 'zz-envoi.example.invalid', 'achete_le' => $maintenant->modify('-60 days')->format('Y-m-d'),
        'dmarc_verifie_le' => $maintenant->format('c'), 'spf_verifie_le' => $maintenant->format('c'), 'dkim_verifie_le' => $maintenant->format('c'),
    ]);
    $demande = fn () => new DemandeEnvoi(
        $this->ws,
        RELEVE_ADRESSE,
        [['verification' => 'valide']],
        'FR',
        null,
        'zz-envoi.example.invalid',
        'ZZ Expediteur SAS',
        $maintenant->modify('-5 days'),
        null,
        'federations',
        true,
    );

    // Avant la sauvegarde : une réponse banale. Entre sauvegarde et panne : le STOP.
    releveDeposer(
        $this,
        releveMsg(1, ['le' => '2026-11-20 09:00:00']),
        releveMsg(2, ['le' => '2026-12-03 14:00:00', 'corps' => 'STOP, ne me contactez plus']),
    );
    // La base restaurée ne contient ni l'événement ni l'opposition, mais un curseur DÉJÀ AU-DELÀ
    // (cas piège : il survit à la restauration alors que le STOP n'a jamais été traité).
    DB::table('envoi_curseurs_imap')->insert(['workspace_id' => $this->ws, 'boite' => RELEVE_BOITE, 'uid_validity' => 1, 'dernier_uid' => 99, 'maj_le' => now()]);

    expect(P::verifier($demande(), $maintenant)->refus)->toBeNull(); // l'envoi passerait

    $this->releve->relever($this->ws, RELEVE_BOITE);                  // la relève courante ne voit rien
    expect(P::verifier($demande(), $maintenant)->refus)->toBeNull();

    $bilan = $this->releve->rattraper($this->ws, RELEVE_BOITE, $sauvegarde);

    expect($bilan->nouveaux)->toBeGreaterThanOrEqual(1)
        ->and(DB::table('envoi_evenements')->where('classe', 'desinscription')->count())->toBe(1)
        ->and(P::verifier($demande(), $maintenant)->refus)->toBe('opposition');

    // Rejouer le rattrapage reste sans doublon.
    $this->releve->rattraper($this->ws, RELEVE_BOITE, $sauvegarde);
    expect(DB::table('envoi_evenements')->where('classe', 'desinscription')->count())->toBe(1);
});

test('rattrapage : la fenêtre est de 30 jours avant la sauvegarde, pas plus', function () {
    $sauvegarde = new DateTimeImmutable('2026-12-01 03:00:00', new DateTimeZone('Europe/Paris'));
    releveDeposer(
        $this,
        releveMsg(1, ['le' => '2026-10-20 09:00:00']),   // 42 jours avant : hors fenêtre
        releveMsg(2, ['le' => '2026-11-05 09:00:00']),   // 26 jours avant : dans la fenêtre
    );
    $bilan = $this->releve->rattraper($this->ws, RELEVE_BOITE, $sauvegarde);
    expect($bilan->lus)->toBe(1)->and(ReleveImap::FENETRE_RATTRAPAGE_JOURS)->toBe(30);
});

test('aucun réseau, aucune mesure d\'ouverture : le code n\'en contient pas', function () {
    foreach (glob(app_path('Envoi/Imap/*.php')) as $f) {
        $code = strtolower((string) file_get_contents($f));
        expect($code)->not->toContain('tracking pixel')->not->toContain('read_receipt')->not->toContain('disposition-notification');
    }
    expect(true)->toBeTrue();
});

test('analyse DSN : statut, destinataire et Message-ID d\'origine, sans réseau', function () {
    $brut = "Reporting-MTA: dns; mx.zz.example.invalid\r\n\r\nFinal-Recipient: rfc822; mort@zz-client.example.invalid\r\nAction: failed\r\nStatus: 5.1.1\r\n\r\nReceived: from x\r\nMessage-ID: <envoi-3@zz.example.invalid>\r\nSubject: Bonjour\r\n";
    expect(AnalyseDsn::depuisBrut($brut))->toBe([
        'statut' => '5.1.1', 'messageId' => 'envoi-3@zz.example.invalid', 'destinataire' => 'mort@zz-client.example.invalid',
    ]);
    expect(AnalyseDsn::depuisBrut('Bonjour, merci.')['statut'])->toBeNull();
    expect(AnalyseDsn::enTetes("Auto-Submitted: auto-replied\r\nSubject: x\r\nPrecedence:\r\n bulk\r\n"))
        ->toBe(['auto-submitted' => 'auto-replied', 'precedence' => 'bulk']);
});

// ───────────────────────── Corrections de l'audit (B1 à B5) ─────────────────────────

test('B1 : un message piégé (From multiple, accentué, mal formé) ne bloque pas la boîte : le STOP suivant est appliqué', function () {
    $this->registre->noter('envoi-7@zz.example.invalid', new MessageOrigine('inj-7', 'suivant@zz-client.example.invalid'));
    releveDeposer(
        $this,
        releveMsg(1, ['de' => 'A <a@zz-client.example.invalid>, B <b@zz-client.example.invalid>', 'corps' => 'STOP']),
        releveMsg(2, ['de' => 'Éric <éric@zz-client.example.invalid>', 'corps' => 'STOP']),
        releveMsg(3, ['de' => '<<pas une adresse', 'corps' => 'STOP']),
        releveMsg(4, ['mid' => 'r4@zz-client.example.invalid', 'irt' => 'envoi-7@zz.example.invalid', 'de' => 'suivant@zz-client.example.invalid', 'corps' => 'STOP']),
    );
    $bilan = $this->releve->relever($this->ws, RELEVE_BOITE);

    expect($bilan->lus)->toBe(4)->and(releveCompte($this->ws))->toBe(4);
    expect(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte('suivant@zz-client.example.invalid'))->count())->toBeGreaterThan(0);
    expect((int) DB::table('envoi_curseurs_imap')->value('dernier_uid'))->toBe(4);
});

test('B1 : un arrêt impossible à appliquer pour UN message (adresse d\'origine invalide) le met « à rattacher » sans effet, la boîte continue', function () {
    $this->registre->noter('envoi-8@zz.example.invalid', new MessageOrigine('inj-8', 'adresse invalide'));
    releveDeposer(
        $this,
        releveMsg(1, ['irt' => 'envoi-8@zz.example.invalid', 'corps' => 'STOP']),
        releveMsg(2, ['corps' => 'STOP']),
    );
    $bilan = $this->releve->relever($this->ws, RELEVE_BOITE);

    expect($bilan->enErreur)->toBe(1)
        ->and(DB::table('envoi_evenements')->where('uid', 1)->value('statut'))->toBe('a_rattacher')
        ->and(DB::table('envoi_evenements')->where('uid', 2)->value('statut'))->toBe('rattache')
        ->and(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(RELEVE_ADRESSE))->count())->toBeGreaterThan(0);
});

test('B2 : sans en-tête RFC 3834, un STOP ou un mot hostile du texte non cité prime sur l\'objet « congé »', function () {
    expect(Classificateur::classer(releveMsg(1, ['sujet' => 'Re: Gérez les congés de vos équipes', 'corps' => 'STOP'])))->toBe(ClasseRetour::Desinscription);
    expect(Classificateur::classer(releveMsg(1, ['sujet' => 'Absent du bureau', 'corps' => 'Je vais porter plainte'])))->toBe(ClasseRetour::Hostile);
    // sans mot d'arrêt : l'heuristique d'objet reste une auto-réponse ; l'en-tête normalisé reste prioritaire
    expect(Classificateur::classer(releveMsg(1, ['sujet' => 'Absence du bureau', 'corps' => 'Je reviens lundi.'])))->toBe(ClasseRetour::AutoReponse);
    expect(Classificateur::classer(releveMsg(1, ['h' => ['auto-submitted' => 'auto-replied'], 'sujet' => 'x', 'corps' => 'STOP'])))->toBe(ClasseRetour::AutoReponse);
});

test('B3 : message sans texte brut : le HTML débarrassé de ses balises, borné à 4 000 caractères', function () {
    $html = '<html><head><style>p{color:red}</style><script>alert(1)</script></head><body><p>Merci de me <b>d&eacute;sinscrire</b>.</p>' . str_repeat('<div>x</div>', 3000) . '</body></html>';
    $texte = ExtraitTexte::depuisHtml($html);
    expect($texte)->toContain('Merci de me désinscrire')->not->toContain('<')->not->toContain('alert')->not->toContain('color:red');
    expect(mb_strlen($texte))->toBeLessThanOrEqual(4000);
    expect(Classificateur::classer(releveMsg(1, ['corps' => $texte])))->toBe(ClasseRetour::Desinscription);
    expect(ExtraitTexte::choisir('', $html))->toContain('désinscrire')->and(ExtraitTexte::choisir('texte brut', $html))->toBe('texte brut');
});

test('B4 : lot limité à 200 messages par passage, le curseur avance, le reste vient au passage suivant', function () {
    for ($i = 1; $i <= 205; $i++) {
        releveDeposer($this, releveMsg($i));
    }
    $a = $this->releve->relever($this->ws, RELEVE_BOITE);
    expect($a->lus)->toBe(ReleveImap::LIMITE_LOT)->and($a->lotPlein)->toBeTrue()
        ->and((int) DB::table('envoi_curseurs_imap')->value('dernier_uid'))->toBe(200);
    $b = $this->releve->relever($this->ws, RELEVE_BOITE);
    expect($b->lus)->toBe(5)->and($b->lotPlein)->toBeFalse()->and(releveCompte($this->ws))->toBe(205);
});

test('B4 : un message écarté (trop gros / illisible) est « à rattacher », sans effet, et le curseur avance', function () {
    releveDeposer($this, new MessageBrut(1, 'gros@zz-client.example.invalid', 'envoi-1@zz.example.invalid', [], RELEVE_ADRESSE, new DateTimeImmutable('2026-12-02 10:00:00', new DateTimeZone('Europe/Paris')), 'x', 'STOP', ecarte: 'taille'));
    releveDeposer($this, releveMsg(2));
    $bilan = $this->releve->relever($this->ws, RELEVE_BOITE);
    expect($bilan->enErreur)->toBe(1)
        ->and(DB::table('envoi_evenements')->where('uid', 1)->value('statut'))->toBe('a_rattacher')
        ->and(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte(RELEVE_ADRESSE))->count())->toBe(0)
        ->and((int) DB::table('envoi_curseurs_imap')->value('dernier_uid'))->toBe(2);
});

test('B4/B5 : le lecteur réel ne lit jamais le message entier, n\'enregistre rien et impose TLS', function () {
    $code = (string) file_get_contents(app_path('Envoi/Imap/LecteurBoiteWebklex.php'));
    expect($code)->not->toContain('getRawBody')->not->toContain('->save(')->not->toContain('->saveAttachments')
        ->and($code)->not->toContain("'validate_cert' => false")->not->toContain("'none'")->not->toContain("'notls'");
    expect($code)->toContain('MAX_OCTETS');
    $compo = json_decode((string) file_get_contents(base_path('composer.json')), true);
    expect($compo['require']['webklex/php-imap'])->toBe('6.2.0');
    expect(fn () => new LecteurBoiteWebklex(['host' => 'x.example.invalid', 'encryption' => 'tcp', 'username' => 'u', 'password' => 'p']))->toThrow(InvalidArgumentException::class);
});

test('DSN : report-type entre guillemets accepté ; Message-ID lu seulement dans un vrai rapport', function () {
    expect(AnalyseDsn::estRapport('multipart/report; report-type=delivery-status; boundary="x"'))->toBeTrue()
        ->and(AnalyseDsn::estRapport('multipart/report; report-type="delivery-status"; boundary=x'))->toBeTrue()
        ->and(AnalyseDsn::estRapport('multipart/report; report-type=disposition-notification'))->toBeFalse()
        ->and(AnalyseDsn::estRapport('text/plain'))->toBeFalse();
    // une réponse humaine qui cite un en-tête Message-ID ne doit pas servir au rattachement
    $citation = "Merci.\r\nMessage-ID: <envoi-1@zz.example.invalid>\r\n";
    expect(AnalyseDsn::pourContenu('text/plain', $citation))->toBe(['statut' => null, 'messageId' => null, 'destinataire' => null]);
});

test('DSN : Final-Recipient différent de l\'adresse d\'origine = « à rattacher », sans effet ; identique = rebond appliqué', function () {
    $this->registre->noter('envoi-4@zz.example.invalid', new MessageOrigine('inj-4', 'cible@zz-client.example.invalid'));
    $dsn = fn (int $uid, string $dest) => new MessageBrut($uid, "d{$uid}@zz.example.invalid", null, [], 'MAILER-DAEMON@zz.example.invalid', new DateTimeImmutable('2026-12-02 10:00:00', new DateTimeZone('Europe/Paris')), 'Undelivered', '', [], '5.1.1', 'envoi-4@zz.example.invalid', $dest);
    releveDeposer($this, $dsn(1, 'autre-personne@zz-client.example.invalid'));
    $this->releve->relever($this->ws, RELEVE_BOITE);
    expect(DB::table('envoi_evenements')->where('uid', 1)->value('statut'))->toBe('a_rattacher')
        ->and(DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte('cible@zz-client.example.invalid'))->count())->toBe(0);

    releveDeposer($this, $dsn(2, 'CIBLE@zz-client.example.invalid'));
    $this->releve->relever($this->ws, RELEVE_BOITE);
    expect(DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte('cible@zz-client.example.invalid'))->count())->toBeGreaterThan(0);
});

test('empreinte synthétique stable : sans Message-ID, une renumération ne crée pas de doublon', function () {
    $this->lecteur->definirUidValidity(RELEVE_BOITE, 10);
    releveDeposer($this, releveMsg(1, ['mid' => null]));
    $this->releve->relever($this->ws, RELEVE_BOITE);
    $this->lecteur->renumeroter(RELEVE_BOITE, 11);
    $bilan = $this->releve->relever($this->ws, RELEVE_BOITE);
    expect($bilan->doublons)->toBe(1)->and(releveCompte($this->ws))->toBe(1);
});

<?php

/**
 * D1 — dettes de la relève IMAP (M3) : une seule empreinte d'adresse, un index pour la retrouver, une réponse
 * automatique jamais classée en STOP, une injection sans doublon, et le 3e maillon (rebond -> frein -> pause).
 * AUCUN réseau, AUCUN envoi. Fixtures FICTIVES (`exemple.test`).
 */

use App\Envoi\Chauffe\CompteurRetours;
use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Imap\AdresseParEmpreinteCrm;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\Classificateur;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\MessageOrigine;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RecepteurRetoursEnChaine;
use App\Envoi\Imap\RecepteurRetoursFreins;
use App\Envoi\Imap\RegistreMessagesEnvoyesDb;
use App\Envoi\Imap\RegistreMessagesEnvoyesMemoire;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\Verp;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function d1Msg(int $uid, string $sujet, string $corps, array $entetes = [], ?string $dsn = null, ?string $dest = null, array $locales = []): MessageBrut
{
    return new MessageBrut(
        $uid,
        "d1-{$uid}@retour.exemple.test",
        'envoi-d1@d.exemple.test',
        [],
        'contact@client.exemple.test',
        new DateTimeImmutable('2026-12-02 10:00:00', new DateTimeZone('UTC')),
        $sujet,
        $corps,
        $entetes,
        $dsn,
        null,
        $dest,
        adressesLocales: $locales,
    );
}

function d1Injection(string $ws): string
{
    $boite = (string) Str::uuid();
    DB::table('envoi_boites')->insert([
        'id' => $boite, 'workspace_id' => $ws, 'adresse' => 'd1-' . substr($boite, 0, 8) . '@test.invalid',
        'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre',
    ]);
    $id = (string) Str::uuid();
    DB::table('envoi_injections_smtp')->insert(['workspace_id' => $ws, 'boite_id' => $boite, 'id_injection' => $id, 'cle_idempotence' => 'd1-' . $id]);

    return $id;
}

beforeEach(function () {
    $this->ws = F::espace('zz-d1');
});

// ── 1. Une seule empreinte d'adresse ─────────────────────────────────────────────────────────────────────

test('empreinte : une adresse enregistrée avec une tabulation ou des espaces autour est retrouvée comme en PHP', function (string $brut) {
    $entreprise = F::fiche($this->ws, 'ZZ empreinte');
    F::contact($this->ws, $entreprise, 'Zoe', 'Test', ['email' => $brut]);

    $trouvee = AdresseParEmpreinteCrm::trouver($this->ws, ListeSuppression::empreinte($brut));

    expect($trouvee)->not->toBeNull()
        ->and(ListeSuppression::empreinte((string) $trouvee))->toBe(ListeSuppression::empreinte($brut));
})->with(["\tContact@Client.Exemple.test", "Contact@Client.Exemple.test\r\n", '  contact@client.exemple.test  ', "\x0Bcontact@client.exemple.test"]);

test('empreinte : une adresse proche (autre casse, même empreinte) d\'un AUTRE espace n\'est jamais renvoyée', function () {
    $autre = F::espace('zz-d1-autre');
    F::contact($autre, F::fiche($autre, 'ZZ autre'), 'Zoe', 'Autre', ['email' => 'contact@client.exemple.test']);

    expect(AdresseParEmpreinteCrm::trouver($this->ws, ListeSuppression::empreinte('contact@client.exemple.test')))->toBeNull();
});

test('empreinte : la recherche par empreinte utilise un INDEX (le plan le prouve)', function () {
    F::contact($this->ws, F::fiche($this->ws, 'ZZ index'), 'Zoe', 'Index', ['email' => 'index@client.exemple.test']);

    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_bitmapscan = off');
    $plan = collect(DB::select('EXPLAIN ' . AdresseParEmpreinteCrm::requeteSql(), [$this->ws, ListeSuppression::empreinte('index@client.exemple.test')]))
        ->map(fn ($l) => (string) array_values((array) $l)[0])->implode("\n");

    expect($plan)->toContain('idx_contacts_empreinte_email');
});

// ── 2. Réponse automatique : jamais un STOP ──────────────────────────────────────────────────────────────

test('absence : une réponse automatique qui contient des mots de désinscription n\'est JAMAIS un STOP', function (string $sujet, string $corps) {
    expect(Classificateur::classer(d1Msg(1, $sujet, $corps)))->toBe(ClasseRetour::AutoReponse);
})->with([
    'absence + unsubscribe en pied' => ['Absence du bureau', "Je suis absent jusqu'au 15 décembre.\nTo unsubscribe from this list, click here."],
    'out of office + stop' => ['Out of office: Re: votre offre', 'I am away until Monday. For urgent matters, do not wait: call the front desk. Please stop by next week.'],
    'congé + ne plus recevoir (pied de page)' => ['Réponse automatique : congé', "Je suis en congé jusqu'au 3 janvier, je vous répondrai à mon retour. Vous pouvez demander à ne plus recevoir nos lettres d'information via le lien du pied de page."],
    'accusé de réception' => ['Accusé de réception automatique', 'Votre message a bien été reçu. Pour vous désinscrire de nos notifications, cliquez ici.'],
]);

test('absence : un vrai STOP court, même avec un objet « absent », reste un STOP (prudence)', function () {
    expect(Classificateur::classer(d1Msg(1, 'Re: absence de réponse', 'STOP')))->toBe(ClasseRetour::Desinscription);
});

test('absence : un STOP sans objet automatique reste un STOP', function () {
    expect(Classificateur::classer(d1Msg(1, 'Re: votre offre', 'Merci de ne plus me contacter.')))->toBe(ClasseRetour::Desinscription);
});

test('absence : de bout en bout, aucune opposition ni arrêt de l\'entreprise', function () {
    config(['envoi.cle_empreinte' => 'cle-de-test-d1']);
    $inj = d1Injection($this->ws);
    $registre = new RegistreMessagesEnvoyesMemoire;
    $registre->noter('envoi-d1@d.exemple.test', new MessageOrigine($inj, 'contact@client.exemple.test'), $this->ws);
    config(['crm.ingest.business_workspace' => F::slug($this->ws)]);
    $lecteur = new LecteurBoiteMemoire;
    $lecteur->deposer('boite-d1', d1Msg(1, 'Absence du bureau', "Absent jusqu'au 15.\nUnsubscribe: https://exemple.test/u"));

    (new ReleveImap($lecteur, $registre, new RecepteurRetoursCrm))->relever($this->ws, 'boite-d1');

    expect(DB::table('envoi_evenements')->where('classe', 'auto_reponse')->where('statut', 'rattache')->count())->toBe(1)
        ->and(DB::table('opt_out')->count())->toBe(0)
        ->and(ListeSuppression::estSupprimee('contact@client.exemple.test'))->toBeFalse();
});

// ── 3. L'injection n'a pas de doublon ────────────────────────────────────────────────────────────────────

test('injection : plusieurs lignes du MÊME destinataire pour une injection = une seule origine, sans doublon d\'effet', function () {
    config(['envoi.cle_empreinte' => 'cle-de-test-d1']);
    $inj = d1Injection($this->ws);
    foreach (['a', 'b'] as $mid) {
        DB::table('envoi_messages_envoyes')->insert([
            'workspace_id' => $this->ws, 'message_id_empreinte' => hash('sha256', "mid:{$mid}@d.exemple.test"),
            'id_injection' => $inj, 'empreinte_destinataire' => ListeSuppression::empreinte('contact@client.exemple.test'),
        ]);
    }
    $registre = new RegistreMessagesEnvoyesDb(fn () => 'contact@client.exemple.test');
    $lecteur = new LecteurBoiteMemoire;
    $lecteur->deposer('boite-d1', d1Msg(1, 'Re: offre', 'Bonjour, oui, rappelez-moi s\'il vous plaît.'));
    $releve = new ReleveImap($lecteur, $registre, new RecepteurRetoursEnChaine);
    $releve->relever($this->ws, 'boite-d1');
    $releve->relever($this->ws, 'boite-d1'); // rejeu de la relève

    expect($registre->retrouverParInjection($this->ws, $inj)?->adresse)->toBe('contact@client.exemple.test')
        ->and(DB::table('envoi_evenements')->where('classe', 'reponse_humaine')->count())->toBe(1);
});

test('injection : plusieurs destinataires DIFFÉRENTS restent ambigus (retour « à rattacher »)', function () {
    $inj = d1Injection($this->ws);
    foreach (['un@client.exemple.test', 'deux@client.exemple.test'] as $i => $adresse) {
        DB::table('envoi_messages_envoyes')->insert([
            'workspace_id' => $this->ws, 'message_id_empreinte' => hash('sha256', "mid:m{$i}"),
            'id_injection' => $inj, 'empreinte_destinataire' => ListeSuppression::empreinte($adresse),
        ]);
    }
    $registre = new RegistreMessagesEnvoyesDb(fn () => 'un@client.exemple.test');

    expect($registre->retrouverParInjection($this->ws, $inj))->toBeNull();
});

// ── 4. Le 3e maillon : rebond -> frein -> pause ──────────────────────────────────────────────────────────

test('3e maillon : trois rebonds durs rattachés dans la journée => compteur => frein => pause ouverte', function () {
    config(['envoi.cle_empreinte' => 'cle-de-test-d1']);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire);
    config(['crm.ingest.business_workspace' => F::slug($this->ws)]);
    DB::table('envoi_domaines')->insert(['workspace_id' => $this->ws, 'nom' => 'd.exemple.test', 'achete_le' => '2026-01-01']);
    $maintenant = new DateTimeImmutable('2026-12-02 12:00:00', new DateTimeZone('Europe/Paris'));

    $lecteur = new LecteurBoiteMemoire;
    $registre = new RegistreMessagesEnvoyesMemoire;
    foreach ([1, 2, 3] as $n) {
        $inj = d1Injection($this->ws);
        $adresse = "mort{$n}@client.exemple.test";
        $registre->noter("envoi-d1-{$n}@d.exemple.test", new MessageOrigine($inj, $adresse), $this->ws);
        $lecteur->deposer('boite-d1', d1Msg($n, 'Undelivered Mail Returned to Sender', '', [], '5.1.1', $adresse, [Verp::adressePour($inj, 'd.exemple.test')]));
    }
    $chaine = new RecepteurRetoursEnChaine(
        new RecepteurRetoursCrm,
        new RecepteurRetoursFreins(new CompteurRetours(app(DepotChauffe::class), app(ResolveurFournisseur::class), fn () => $maintenant)),
    );
    (new ReleveImap($lecteur, $registre, $chaine))->relever($this->ws, 'boite-d1');

    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null);
    $motifs = array_map(fn ($d) => $d->motif, $garde->evaluer($this->ws, $maintenant));

    expect(DB::table('envoi_evenements')->where('classe', 'rebond_dur')->where('statut', 'rattache')->count())->toBe(3)
        ->and($motifs)->toContain(DecisionFrein::REBONDS)
        ->and(collect(app(DepotChauffe::class)->pausesActives($this->ws, $maintenant))->pluck('motif')->all())->toContain(DecisionFrein::REBONDS);
});

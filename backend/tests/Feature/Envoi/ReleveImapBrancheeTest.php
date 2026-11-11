<?php

/**
 * M3 — la relève IMAP est BRANCHÉE dans le conteneur : `app(ReleveImap::class)` se résout sans liaison de test,
 * la commande tourne de bout en bout sur des messages FICTIFS. Seule la fabrique de lecteurs est factice
 * (lecteur en mémoire) : AUCUNE connexion réseau, aucun identifiant lu. Fixtures `exemple.test`.
 */

use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\FabriqueLecteurFixe;
use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\FabriqueLecteursNonFournie;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\RecepteurRetours;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RecepteurRetoursEnChaine;
use App\Envoi\Imap\RecepteurRetoursFreins;
use App\Envoi\Imap\RecepteurRetoursSequence;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\ReleveImapCommande;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Un destinataire fictif : fiche + contact + injection + message envoyé enregistré (rattachement par Message-ID). */
function brancheeDestinataire(object $t, string $nom): array
{
    $adresse = "{$nom}@client.exemple.test";
    $entreprise = F::fiche($t->ws, 'ZZ ' . $nom);
    $contact = F::contact($t->ws, $entreprise, 'Zoe', $nom, ['email' => $adresse]);
    $injection = (string) Str::uuid();
    DB::table('envoi_injections_smtp')->insert([
        'workspace_id' => $t->ws, 'boite_id' => $t->boite, 'id_injection' => $injection, 'cle_idempotence' => 'zz-' . $injection,
    ]);
    $messageId = "envoi-{$nom}@d.exemple.test";
    DB::table('envoi_messages_envoyes')->insert([
        'workspace_id' => $t->ws, 'message_id_empreinte' => hash('sha256', 'mid:' . $messageId),
        'id_injection' => $injection, 'empreinte_destinataire' => ListeSuppression::empreinte($adresse),
    ]);

    return compact('adresse', 'entreprise', 'contact', 'injection', 'messageId');
}

/** Une inscription ACTIVE dont l'étape 1 est déjà envoyée sous l'injection donnée. */
function brancheeInscription(object $t, array $d): string
{
    $sequence = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sequence, 'workspace_id' => $t->ws, 'nom' => 'ZZ séquence', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $t->ws, 'sequence_id' => $sequence, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Bonjour', 'corps' => 'Corps fictif']);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $t->ws, 'sequence_id' => $sequence, 'rang' => 2, 'delai_jours_ouvres' => 2, 'sujet' => 'Relance', 'corps' => 'Corps fictif']);
    $id = (string) Str::uuid();
    DB::table('envoi_inscriptions')->insert([
        'id' => $id, 'workspace_id' => $t->ws, 'sequence_id' => $sequence, 'contact_id' => $d['contact'], 'entreprise_id' => $d['entreprise'],
        'empreinte_adresse' => ListeSuppression::empreinte($d['adresse']), 'etape_courante' => 2,
        'prochaine_echeance' => '2026-12-09T10:00:00+01:00', 'version_figee' => json_encode(['etapes' => []]),
    ]);
    DB::table('envoi_messages')->insert([
        'workspace_id' => $t->ws, 'inscription_id' => $id, 'etape' => 1, 'statut' => 'envoye',
        'message_id' => $d['messageId'], 'id_injection' => $d['injection'],
    ]);

    return $id;
}

/** Écartée des envois : liste de suppression OU opposition (opt_out). */
function brancheeEcartee(string $adresse): bool
{
    return ListeSuppression::estSupprimee($adresse)
        || DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte($adresse))->exists();
}

function brancheeMessage(int $uid, array $d, array $s = []): MessageBrut
{
    $a = array_merge(['sujet' => 'Re: bonjour', 'corps' => 'Merci pour votre message.', 'dsn' => null, 'dest' => null, 'de' => $d['adresse']], $s);

    return new MessageBrut(
        $uid,
        "retour-{$uid}@client.exemple.test",
        $a['dsn'] === null ? $d['messageId'] : null,
        [],
        $a['de'],
        new DateTimeImmutable('2026-12-02 10:00:00', new DateTimeZone('UTC')),
        $a['sujet'],
        $a['corps'],
        [],
        $a['dsn'],
        $a['dsn'] === null ? null : $d['messageId'],
        $a['dest'],
    );
}

beforeEach(function () {
    Cache::flush();
    // Les freins résolvent le fournisseur par DNS en production : ici un faux, AUCUN réseau.
    $this->app->instance(ResolveurFournisseur::class, new ResolveurEnMemoire);
    $this->ws = F::espace('zz-releve-branchee');
    config(['crm.ingest.business_workspace' => F::slug($this->ws), 'envoi.reel_actif' => true, 'envoi.releve_actif' => true]);
    $this->boite = (string) Str::uuid();
    DB::table('envoi_boites')->insert([
        'id' => $this->boite, 'workspace_id' => $this->ws, 'adresse' => 'zz-branchee@test.invalid',
        'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre',
    ]);
});

test('la liaison est dans le conteneur : ReleveImap se résout et le récepteur est la chaîne CRM → freins → séquences', function () {
    $this->app->instance(FabriqueLecteurs::class, new FabriqueLecteurFixe(new LecteurBoiteMemoire));

    expect(app(ReleveImap::class))->toBeInstanceOf(ReleveImap::class)
        ->and(app(RecepteurRetours::class))->toBeInstanceOf(RecepteurRetoursEnChaine::class);
    $ordre = (new ReflectionProperty(RecepteurRetoursEnChaine::class, 'recepteurs'))->getValue(app(RecepteurRetours::class));
    expect(array_map(fn ($r) => $r::class, $ordre))->toBe([RecepteurRetoursCrm::class, RecepteurRetoursFreins::class, RecepteurRetoursSequence::class]);
});

test('la commande tourne de bout en bout : rebonds, plainte, réponse humaine, absence, désinscription', function () {
    $lecteur = new LecteurBoiteMemoire;
    $lecteur->definirUidValidity($this->boite, 9);
    $this->app->instance(FabriqueLecteurs::class, new FabriqueLecteurFixe($lecteur));
    $drapeaux = DB::table('envoi_drapeaux')->orderBy('workspace_id')->get()->toJson();

    $dur = brancheeDestinataire($this, 'dur');
    $mou = brancheeDestinataire($this, 'mou');
    $plainte = brancheeDestinataire($this, 'plainte');
    $humain = brancheeDestinataire($this, 'humain');
    $absent = brancheeDestinataire($this, 'absent');
    $stop = brancheeDestinataire($this, 'stop');
    $inscHumain = brancheeInscription($this, $humain);
    $inscAbsent = brancheeInscription($this, $absent);

    $lecteur->deposer($this->boite, brancheeMessage(1, $dur, ['dsn' => '5.1.1', 'dest' => $dur['adresse'], 'de' => 'MAILER-DAEMON@retour.exemple.test']));
    $lecteur->deposer($this->boite, brancheeMessage(2, $mou, ['dsn' => '4.2.2', 'dest' => $mou['adresse'], 'de' => 'MAILER-DAEMON@retour.exemple.test']));
    $lecteur->deposer($this->boite, brancheeMessage(3, $plainte, ['corps' => 'Ceci est du spam, je vais porter plainte.']));
    $lecteur->deposer($this->boite, brancheeMessage(4, $humain));
    $lecteur->deposer($this->boite, brancheeMessage(5, $absent, ['sujet' => 'Réponse automatique : absence du bureau', 'corps' => 'Je suis absent jusqu\'au 15.']));
    $lecteur->deposer($this->boite, brancheeMessage(6, $stop, ['corps' => 'STOP, ne me contactez plus.']));

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(0);

    $classes = DB::table('envoi_evenements')->where('workspace_id', $this->ws)->where('statut', 'rattache')->pluck('classe')->all();
    sort($classes);
    $attendu = array_map(fn (ClasseRetour $c) => $c->value, [
        ClasseRetour::AutoReponse, ClasseRetour::Desinscription, ClasseRetour::Hostile,
        ClasseRetour::RebondDur, ClasseRetour::RebondMou, ClasseRetour::ReponseHumaine,
    ]);
    sort($attendu);
    expect($classes)->toBe($attendu)
        // CRM : rebond dur, plainte et désinscription écartent l'adresse ; la réponse humaine et l'absence non.
        ->and(ListeSuppression::estSupprimee($dur['adresse']))->toBeTrue()
        ->and(brancheeEcartee($plainte['adresse']))->toBeTrue()
        ->and(brancheeEcartee($stop['adresse']))->toBeTrue()
        // Rebond mou : UN seul n'écarte pas l'adresse (seuil de répétition dans ListeSuppression::rebondTemporaire).
        ->and(brancheeEcartee($mou['adresse']))->toBeFalse()
        ->and(brancheeEcartee($humain['adresse']))->toBeFalse()
        ->and(brancheeEcartee($absent['adresse']))->toBeFalse()
        // Séquences (S11) : la réponse humaine ARRÊTE l'inscription ; l'absence ne l'arrête pas (pas de pause, voir la doc).
        ->and(DB::table('envoi_inscriptions')->where('id', $inscHumain)->value('statut'))->toBe('arretee')
        ->and(DB::table('envoi_inscriptions')->where('id', $inscHumain)->value('motif_arret'))->toBe('reponse')
        ->and(DB::table('envoi_inscriptions')->where('id', $inscAbsent)->value('statut'))->toBe('active')
        // Aucune écriture dans envoi_drapeaux.
        ->and(DB::table('envoi_drapeaux')->orderBy('workspace_id')->get()->toJson())->toBe($drapeaux);

    // Rejeu : rien de nouveau.
    $evenements = DB::table('envoi_evenements')->count();
    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(0)
        ->and(DB::table('envoi_evenements')->count())->toBe($evenements);
});

test('sans fabrique réelle : la commande échoue de façon visible et propre, sans réseau', function () {
    expect(app(FabriqueLecteurs::class))->toBeInstanceOf(FabriqueLecteursNonFournie::class);
    $d = brancheeDestinataire($this, 'sansboite');

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(1)
        ->and(Artisan::output())->toContain('aucune fabrique de lecteurs IMAP')
        ->and(DB::table('envoi_curseurs_imap')->count())->toBe(0)
        ->and(DB::table('envoi_evenements')->count())->toBe(0)
        ->and($d['adresse'])->not->toBe('');
});

test('éteinte par défaut : le gabarit d\'environnement garde ENVOI_RELEVE_ACTIF et ENVOI_REEL_ACTIF à faux', function () {
    $env = (string) file_get_contents(base_path('../.env.example'));

    expect($env)->toMatch('/^ENVOI_RELEVE_ACTIF=false$/m')->and($env)->toMatch('/^ENVOI_REEL_ACTIF=false$/m');
});

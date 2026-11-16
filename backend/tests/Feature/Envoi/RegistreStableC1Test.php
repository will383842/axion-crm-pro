<?php

/**
 * Envoi C1-1 — identifiant d'injection STABLE choisi par le CRM (registre `envoi_injections_locales`), écrit dans les deux
 * colonnes de `envoi_messages`, porté par le message et le VERP ; plus d'en-tête X-Envoi-Jeton. Cahier C1, §1.4 et §1.7.
 * Données FICTIVES (example.test), aucun réseau.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Contrat\ConsultationAcceptation;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\RecepteurRetoursSequence;
use App\Envoi\Imap\RegistreMessagesEnvoyesDb;
use App\Envoi\Imap\RetourDetecte;
use App\Envoi\Imap\Verp;
use App\Envoi\PasserelleLocale;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Sequence\SourceExpeditionDb;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ServeurEspion;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const C1_DOMAINE = 'zz-c1.example.test';
const C1_ADRESSE = 'dest@client.example.test';

beforeEach(function () {
    $this->ws = F::espace('zz-registre-c1');
    config(['envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test']]);
    $this->lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($this->ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($this->ws, 'test-registre-c1');
    WorkspaceContext::set($this->ws);
    app(DepotReglages::class)->enregistrerIdentite($this->ws, [
        'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
        'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
    ], 'test');
    $jour = $this->lundi->format('c');
    DB::table('envoi_domaines')->insert(['workspace_id' => $this->ws, 'nom' => C1_DOMAINE, 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);
    DB::table('envoi_boites')->insert(['workspace_id' => $this->ws, 'adresse' => 'contact@' . C1_DOMAINE, 'hote' => 'imap.example.test', 'port' => 587, 'identifiants_chiffres' => 'x']);
    $this->faux = new FauxServeurEnvoi;
    $this->cycle = function (): array {
        $passerelle = new PasserelleLocale($this->faux, new DepotSocleDb, true, 300, fn () => $this->lundi, $this->ws, 300, fn () => WorkspaceContext::current());
        $seq = app(SourceExpeditionDb::class)->sequenceurPour($this->ws, $passerelle, new LecteurFicheDb);

        return $seq->executer($this->ws, $this->lundi);
    };
    config(['envoi.cle_empreinte' => str_repeat('c', 40)]);
    // Cycle avec un serveur au choix (espion) : le même séquenceur de production, sans passerelle.
    $this->cycleAvec = fn ($serveur): array => app(SourceExpeditionDb::class)->sequenceurPour($this->ws, $serveur, new LecteurFicheDb)->executer($this->ws, $this->lundi);
    // Une séquence d'une étape et un contact contactable.
    $sid = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $this->ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $this->ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    // Deuxième étape : après le premier envoi l'inscription reste « active » (sans elle, elle serait terminée et rien ne serait à arrêter).
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $this->ws, 'sequence_id' => $sid, 'rang' => 2, 'delai_jours_ouvres' => 3, 'sujet' => 'Relance', 'corps' => 'Corps 2']);
    $e = F::fiche($this->ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $this->lundi->modify('-3 days')->format('c')]);
    F::lier($this->ws, $e, F::tag($this->ws, FichesProtegees::TAG_FEDERATIONS));
    $contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->ws, 'company_id' => $e, 'last_name' => 'Dupont-test', 'email' => C1_ADRESSE, 'email_status' => 'valid',
        'created_at' => $this->lundi->modify('-3 days')->format('c'), 'first_info_at' => $this->lundi->modify('-3 days')->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $this->ws, 'empreinte_adresse' => ListeSuppression::empreinte(C1_ADRESSE), 'verdict' => 'valide', 'verifie_le' => $this->lundi->modify('-2 days')->format('c'), 'source' => 'test']);
    $this->ins = (new Inscriptions(new LecteurFicheDb))->inscrire($this->ws, $sid, $contact, $this->lundi);
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

function c1Origine(object $t): ?object
{
    $mid = DB::table('envoi_messages')->where('inscription_id', $t->ins)->value('message_id');

    return (new RegistreMessagesEnvoyesDb(fn (string $ws, string $e): ?string => $e === ListeSuppression::empreinte(C1_ADRESSE) ? C1_ADRESSE : null))->retrouver($t->ws, [(string) $mid]);
}

test('1. une réponse humaine, rattachée par l\'identifiant du registre, ARRÊTE la séquence', function () {
    expect(($this->cycle)())->toBe([Sequenceur::ENVOYE => 1]);
    $origine = c1Origine($this);

    RecepteurRetoursSequence::parDefaut()->recevoir($this->ws, new RetourDetecte(ClasseRetour::ReponseHumaine, $origine->idInjection, C1_ADRESSE, null, 'c1-rep'));

    $ins = DB::table('envoi_inscriptions')->where('id', $this->ins)->first();
    expect($ins->statut)->toBe('arretee')->and($ins->motif_arret)->toBe('reponse');
});

test('1b. même scénario sous le rôle applicatif (pgsql_app)', function () {
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    expect(($this->cycle)())->toBe([Sequenceur::ENVOYE => 1]);
    $origine = c1Origine($this);
    RecepteurRetoursSequence::parDefaut()->recevoir($this->ws, new RetourDetecte(ClasseRetour::ReponseHumaine, $origine->idInjection, C1_ADRESSE, null, 'c1-rep-app'));

    expect(DB::table('envoi_inscriptions')->where('id', $this->ins)->value('statut'))->toBe('arretee')
        ->and(DB::table('envoi_messages')->where('inscription_id', $this->ins)->value('id_injection_serveur'))->toBe('sim-1');
});

test('2. id_injection = registre = injections_locales ; l\'identifiant du serveur va dans id_injection_serveur', function () {
    ($this->cycle)();
    $m = DB::table('envoi_messages')->where('inscription_id', $this->ins)->first();
    $reg = DB::table('envoi_messages_envoyes')->where('workspace_id', $this->ws)->first();
    $loc = DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->first();

    expect($m->id_injection)->toBe($reg->id_injection)->toBe($loc->id_injection)
        ->and($m->id_injection_serveur)->toBe('sim-1')
        ->and($loc->adaptateur)->toBe('faux');
});

test('2b. serveur qui rend EXACTEMENT l\'identifiant du registre : id_injection_serveur reste nul', function () {
    $serveur = new ServeurEspion(fn (MessageAEnvoyer $m) => ResultatInjection::accepte($m->idInjection));
    ($this->cycleAvec)($serveur);

    $m = DB::table('envoi_messages')->where('inscription_id', $this->ins)->first();
    expect($m->id_injection)->toBe($serveur->recus[0]->idInjection)->and($m->id_injection_serveur)->toBeNull();
});

test('3. stabilité : un report puis un nouveau cycle gardent le même identifiant', function () {
    $n = 0;
    $serveur = new ServeurEspion(function (MessageAEnvoyer $m) use (&$n) {
        return ++$n === 1 ? ResultatInjection::reporte($this->lundi->modify('+1 day')) : ResultatInjection::accepte('srv-' . $n);
    });
    ($this->cycleAvec)($serveur);
    DB::table('envoi_inscriptions')->where('id', $this->ins)->update(['prochaine_echeance' => $this->lundi->modify('-1 minute')->format('c')]);
    ($this->cycleAvec)($serveur);

    expect($serveur->recus)->toHaveCount(2)
        ->and($serveur->recus[1]->idInjection)->toBe($serveur->recus[0]->idInjection)
        ->and(DB::table('envoi_messages')->where('inscription_id', $this->ins)->value('id_injection'))->toBe($serveur->recus[0]->idInjection)
        ->and(DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->count())->toBe(1);
});

test('3b. reprise d\'une ligne « reserve » ancienne, porte ouverte : même identifiant, serveur déjà accepté', function () {
    $plante = new ServeurEspion(fn () => throw new RuntimeException('plantage simulé'));
    ($this->cycleAvec)($plante);
    $avant = DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->value('id_injection');
    expect($avant)->toBe($plante->recus[0]->idInjection);
    DB::table('envoi_messages')->where('inscription_id', $this->ins)->update(['maj_le' => $this->lundi->modify('-3 hours')->format('c')]);
    DB::table('envoi_inscriptions')->where('id', $this->ins)->update(['prochaine_echeance' => $this->lundi->modify('-1 minute')->format('c')]);

    $deja = new class(fn () => ResultatInjection::accepte('srv-deja')) extends ServeurEspion implements ConsultationAcceptation
    {
        public function dejaAccepte(string $workspaceId, string $cleIdempotence): ?ResultatInjection
        {
            return ResultatInjection::accepte('srv-deja');
        }
    };
    ($this->cycleAvec)($deja);

    $m = DB::table('envoi_messages')->where('inscription_id', $this->ins)->first();
    expect($m->statut)->toBe('envoye')->and($m->id_injection)->toBe($avant)->and($m->id_injection_serveur)->toBe('srv-deja')
        ->and(DB::table('envoi_messages_envoyes')->where('workspace_id', $this->ws)->value('id_injection'))->toBe($avant);
});

test('3c. reprise par dejaParti (garde fermée à la reprise, message déjà accepté) : même identifiant du registre', function () {
    $plante = new ServeurEspion(fn () => throw new RuntimeException('plantage simulé'));
    ($this->cycleAvec)($plante);
    $avant = DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->value('id_injection');
    DB::table('envoi_messages')->where('inscription_id', $this->ins)->update(['maj_le' => $this->lundi->modify('-3 hours')->format('c')]);
    DB::table('envoi_inscriptions')->where('id', $this->ins)->update(['prochaine_echeance' => $this->lundi->modify('-1 minute')->format('c')]);
    // La garde se ferme entre-temps (adresse changée) : seul dejaParti peut conclure « envoyé ».
    DB::table('contacts')->where('workspace_id', $this->ws)->where('email', C1_ADRESSE)->update(['email' => 'autre@client.example.test']);
    $deja = new class(fn () => throw new RuntimeException('ne doit pas être rappelé')) extends ServeurEspion implements ConsultationAcceptation
    {
        public function dejaAccepte(string $workspaceId, string $cleIdempotence): ?ResultatInjection
        {
            return ResultatInjection::accepte('srv-deja');
        }
    };
    ($this->cycleAvec)($deja);

    $m = DB::table('envoi_messages')->where('inscription_id', $this->ins)->first();
    expect($deja->appels)->toBe(0)
        ->and($m->statut)->toBe('envoye')->and($m->id_injection)->toBe($avant)->and($m->id_injection_serveur)->toBe('srv-deja')
        ->and(DB::table('envoi_messages_envoyes')->where('workspace_id', $this->ws)->value('id_injection'))->toBe($avant);
});

test('4. le message porte idInjection (registre) et cheminRetour VERP relisible', function () {
    $serveur = new ServeurEspion(fn (MessageAEnvoyer $m) => ResultatInjection::accepte('srv-1'));
    ($this->cycleAvec)($serveur);
    $m = $serveur->recus[0];
    $registre = DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->value('id_injection');

    expect($m->idInjection)->toBe($registre)
        ->and($m->cheminRetour)->toBe(Verp::adressePour($registre, C1_DOMAINE))
        ->and(Verp::idInjectionDepuis($m->cheminRetour))->toBe($registre);
});

test('5. sans envoi.cle_empreinte : aucun VERP, rien ne casse, l\'envoi du faux serveur est inchangé', function () {
    config(['envoi.cle_empreinte' => '']);
    $serveur = new ServeurEspion(fn (MessageAEnvoyer $m) => ResultatInjection::accepte('srv-1'));

    expect(($this->cycleAvec)($serveur))->toBe([Sequenceur::ENVOYE => 1])
        ->and($serveur->recus[0]->cheminRetour)->toBeNull()
        ->and($serveur->recus[0]->idInjection)->not->toBeNull();
});

test('6. domaine sur smtp : aucune ligne envoi_injections_locales créée par la réservation, ni identifiant porté', function () {
    WorkspaceContext::run($this->ws, fn () => DB::selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$this->ws, C1_DOMAINE, 'smtp', 'auteur-test']));
    $serveur = new ServeurEspion(fn (MessageAEnvoyer $m) => ResultatInjection::accepte('smtp-1'));
    ($this->cycleAvec)($serveur);

    expect($serveur->recus[0]->idInjection)->toBeNull()->and($serveur->recus[0]->cheminRetour)->toBeNull()
        ->and(DB::table('envoi_messages')->where('inscription_id', $this->ins)->value('id_injection'))->toBe('smtp-1')
        ->and(DB::table('envoi_messages')->where('inscription_id', $this->ins)->value('id_injection_serveur'))->toBeNull()
        ->and(DB::table('envoi_injections_locales')->where('cle_idempotence', 'like', 'seq-%')->where('adaptateur', 'smtp')->count())->toBe(0);
});

test('6b. même nom de domaine en smtp dans l\'espace B, faux dans A : A garde son identifiant de registre', function () {
    $b = F::espace('zz-registre-c1-b');
    DB::table('envoi_domaines')->insert(['workspace_id' => $b, 'nom' => C1_DOMAINE, 'achete_le' => '2026-01-01']);
    WorkspaceContext::run($b, fn () => DB::selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$b, C1_DOMAINE, 'smtp', 'auteur-test']));
    // Réécrit la ligne de A après celle de B : sans filtre d'espace, une lecture non bornée verrait « smtp » d'abord.
    DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['achete_le' => '2026-01-02']);
    $serveur = new ServeurEspion(fn (MessageAEnvoyer $m) => ResultatInjection::accepte('srv-1'));
    ($this->cycleAvec)($serveur);

    expect($serveur->recus[0]->idInjection)->not->toBeNull()
        ->and(DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->value('id_injection'))->toBe($serveur->recus[0]->idInjection);
});

test('7. domaine kumo : la ligne du registre porte « kumo » même si le serveur rend sim-…', function () {
    WorkspaceContext::run($this->ws, fn () => DB::selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$this->ws, C1_DOMAINE, 'kumo', 'auteur-test']));
    ($this->cycle)();

    expect(DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->value('adaptateur'))->toBe('kumo');
});

test('9. cloisonnement : le même identifiant dans l\'espace B ne touche aucune inscription de A', function () {
    ($this->cycle)();
    $origine = c1Origine($this);
    $b = F::espace('zz-registre-c1-b');

    RecepteurRetoursSequence::parDefaut()->recevoir($b, new RetourDetecte(ClasseRetour::ReponseHumaine, $origine->idInjection, C1_ADRESSE, null, 'c1-b'));

    expect(DB::table('envoi_inscriptions')->where('id', $this->ins)->value('statut'))->toBe('active');
});

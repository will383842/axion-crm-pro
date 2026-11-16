<?php

/**
 * Envoi C1-2 — lecture des DEUX formes d'identifiant pendant la transition (cahier C1, §1.5 et §1.7 tests 8, 9, 10).
 * Ancienne forme : `envoi_messages.id_injection` = identifiant du serveur (`sim-7`), registre local = UUID de clé `seq-<ins>-1`.
 * Données FICTIVES (example.test), aucun réseau.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\RecepteurRetoursSequence;
use App\Envoi\Imap\RegistreMessagesEnvoyesDb;
use App\Envoi\Imap\RetourDetecte;
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
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const C12_DOMAINE = 'zz-c12.example.test';
const C12_ADRESSE = 'dest@client.example.test';

beforeEach(function () {
    $this->ws = F::espace('zz-registre-c12');
    config(['envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'], 'envoi.cle_empreinte' => str_repeat('c', 40)]);
    $this->lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($this->ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($this->ws, 'test-registre-c12');
    WorkspaceContext::set($this->ws);
    app(DepotReglages::class)->enregistrerIdentite($this->ws, [
        'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
        'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
    ], 'test');
    $jour = $this->lundi->format('c');
    DB::table('envoi_domaines')->insert(['workspace_id' => $this->ws, 'nom' => C12_DOMAINE, 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);
    DB::table('envoi_boites')->insert(['workspace_id' => $this->ws, 'adresse' => 'contact@' . C12_DOMAINE, 'hote' => 'imap.example.test', 'port' => 587, 'identifiants_chiffres' => 'x']);
    $this->faux = new FauxServeurEnvoi;
    $this->cycle = function (): array {
        $passerelle = new PasserelleLocale($this->faux, new DepotSocleDb, true, 300, fn () => $this->lundi, $this->ws, 300, fn () => WorkspaceContext::current());

        return app(SourceExpeditionDb::class)->sequenceurPour($this->ws, $passerelle, new LecteurFicheDb)->executer($this->ws, $this->lundi);
    };
    $sid = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $this->ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $this->ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $this->ws, 'sequence_id' => $sid, 'rang' => 2, 'delai_jours_ouvres' => 3, 'sujet' => 'Relance', 'corps' => 'Corps 2']);
    $e = F::fiche($this->ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $this->lundi->modify('-3 days')->format('c')]);
    F::lier($this->ws, $e, F::tag($this->ws, FichesProtegees::TAG_FEDERATIONS));
    $contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->ws, 'company_id' => $e, 'last_name' => 'Dupont-test', 'email' => C12_ADRESSE, 'email_status' => 'valid',
        'created_at' => $this->lundi->modify('-3 days')->format('c'), 'first_info_at' => $this->lundi->modify('-3 days')->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $this->ws, 'empreinte_adresse' => ListeSuppression::empreinte(C12_ADRESSE), 'verdict' => 'valide', 'verifie_le' => $this->lundi->modify('-2 days')->format('c'), 'source' => 'test']);
    $this->ins = (new Inscriptions(new LecteurFicheDb))->inscrire($this->ws, $sid, $contact, $this->lundi);
    // Un cycle, puis le ré-habillage « ancienne forme » : envoi_messages porte l'identifiant du SERVEUR, le registre garde son UUID.
    expect(($this->cycle)())->toBe([Sequenceur::ENVOYE => 1]);
    $this->uuid = (string) DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->value('id_injection');
    $this->messageId = (string) DB::table('envoi_messages')->where('inscription_id', $this->ins)->value('message_id');
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

function c12AncienneForme(object $t): void
{
    DB::table('envoi_messages')->where('inscription_id', $t->ins)->update(['id_injection' => 'sim-7', 'id_injection_serveur' => null]);
}

function c12Recevoir(string $ws, string $id): void
{
    RecepteurRetoursSequence::parDefaut()->recevoir($ws, new RetourDetecte(ClasseRetour::ReponseHumaine, $id, C12_ADRESSE, null, 'c12-' . $id));
}

function c12Registre(): RegistreMessagesEnvoyesDb
{
    return new RegistreMessagesEnvoyesDb(fn (string $ws, string $e): ?string => $e === ListeSuppression::empreinte(C12_ADRESSE) ? C12_ADRESSE : null);
}

function c12Statut(object $t): string
{
    return (string) DB::table('envoi_inscriptions')->where('id', $t->ins)->value('statut');
}

function c12Scenarios(): void
{
    test('8. ancienne forme : l\'UUID du registre (clé seq-<ins>-1) arrête l\'inscription', function () {
        c12AncienneForme($this);
        c12Recevoir($this->ws, $this->uuid);
        expect(c12Statut($this))->toBe('arretee')->and(DB::table('envoi_inscriptions')->where('id', $this->ins)->value('motif_arret'))->toBe('reponse');
    });

    test('8b. identifiant du serveur (id_injection_serveur) : arrête l\'inscription', function () {
        DB::table('envoi_messages')->where('inscription_id', $this->ins)->update(['id_injection_serveur' => 'srv-42']);
        c12Recevoir($this->ws, 'srv-42');
        expect(c12Statut($this))->toBe('arretee');
    });

    test('8c. identifiant sans aucun rattachement : rien ne bouge', function () {
        c12AncienneForme($this);
        c12Recevoir($this->ws, (string) Str::uuid());
        c12Recevoir($this->ws, 'inconnu-1');
        expect(c12Statut($this))->toBe('active');
    });

    test('8d. VERP Kumo ancien (identifiant serveur) : le registre rend l\'identifiant du REGISTRE et l\'adresse', function () {
        c12AncienneForme($this);
        $origine = c12Registre()->retrouverParInjection($this->ws, 'sim-7');
        expect($origine)->not->toBeNull()->and($origine->idInjection)->toBe($this->uuid)->and($origine->adresse)->toBe(C12_ADRESSE);
    });

    test('8e. forme nouvelle inchangée : l\'UUID du registre rend toujours son adresse', function () {
        expect(c12Registre()->retrouverParInjection($this->ws, $this->uuid)?->idInjection)->toBe($this->uuid);
    });

    test('8f. plusieurs destinataires derrière l\'identifiant du registre : null (ambigu)', function () {
        $ligne = (array) DB::table('envoi_messages_envoyes')->where('workspace_id', $this->ws)->first();
        unset($ligne['id']);
        $ligne['message_id_empreinte'] = hash('sha256', 'mid:autre-' . Str::uuid());
        $ligne['empreinte_destinataire'] = ListeSuppression::empreinte('autre@client.example.test');
        DB::table('envoi_messages_envoyes')->insert($ligne);
        expect(c12Registre()->retrouverParInjection($this->ws, $this->uuid))->toBeNull();
    });

    test('8g. identifiant serveur inconnu ou autre espace : null, sans erreur SQL', function () {
        c12AncienneForme($this);
        expect(c12Registre()->retrouverParInjection($this->ws, 'inconnu-9'))->toBeNull();
    });

    test('9. cloisonnement : même identifiant dans l\'espace B => aucune inscription de A touchée', function () {
        c12AncienneForme($this);
        $b = F::espace('zz-registre-c12-b');
        c12Recevoir($b, $this->uuid);
        c12Recevoir($b, 'sim-7');
        expect(c12Statut($this))->toBe('active')
            ->and(c12Registre()->retrouverParInjection($b, 'sim-7'))->toBeNull();
    });

    test('9b. filtre d\'espace EXPLICITE sur chaque lecture (la RLS est contournée en propriétaire)', function () {
        c12AncienneForme($this);
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (preg_match('/^\s*select .* from "(envoi_messages|envoi_injections_locales|envoi_messages_envoyes)"/i', $q->sql) === 1) {
                $sql[] = $q->sql;
            }
        });
        c12Recevoir($this->ws, $this->uuid);
        c12Registre()->retrouverParInjection($this->ws, 'sim-7');
        c12Registre()->retrouverParInjection($this->ws, $this->uuid);

        expect($sql)->not->toBeEmpty();
        foreach ($sql as $requete) {
            expect($requete)->toContain('"workspace_id" = ?');
        }
    });
}

c12Scenarios();

describe('sous le rôle applicatif (pgsql_app)', function () {
    beforeEach(function () {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    });
    c12Scenarios();
});

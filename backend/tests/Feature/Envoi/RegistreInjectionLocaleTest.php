<?php

/**
 * Envoi L8-COMPO — écriture de `envoi_messages_envoyes` (et de `envoi_injections_locales`) à la conclusion « acceptée ».
 * Transport : FauxServeurEnvoi derrière la PasserelleLocale. Données FICTIVES, aucun réseau.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Imap\RegistreMessagesEnvoyesDb;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const REG_DOMAINE = 'zz-reg.example.test';
const REG_ADRESSE = 'dest@client.example.test';

beforeEach(function () {
    $this->ws = F::espace('zz-registre-l8');
    config(['envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test']]);
    $this->lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($this->ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($this->ws, 'test-registre');
    WorkspaceContext::set($this->ws);
    app(DepotReglages::class)->enregistrerIdentite($this->ws, [
        'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
        'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
    ], 'test');
    $jour = $this->lundi->format('c');
    DB::table('envoi_domaines')->insert(['workspace_id' => $this->ws, 'nom' => REG_DOMAINE, 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);
    DB::table('envoi_boites')->insert(['workspace_id' => $this->ws, 'adresse' => 'contact@' . REG_DOMAINE, 'hote' => 'imap.example.test', 'port' => 587, 'identifiants_chiffres' => 'x']);
    $this->faux = new FauxServeurEnvoi;
    $this->cycle = function (): array {
        $passerelle = new PasserelleLocale($this->faux, new DepotSocleDb, true, 300, fn () => $this->lundi, $this->ws, 300, fn () => WorkspaceContext::current());
        $seq = app(SourceExpeditionDb::class)->sequenceurPour($this->ws, $passerelle, new LecteurFicheDb);

        return $seq->executer($this->ws, $this->lundi);
    };
    // Une séquence d'une étape et un contact contactable.
    $sid = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $this->ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $this->ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    $e = F::fiche($this->ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $this->lundi->modify('-3 days')->format('c')]);
    F::lier($this->ws, $e, F::tag($this->ws, FichesProtegees::TAG_FEDERATIONS));
    $contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->ws, 'company_id' => $e, 'last_name' => 'Dupont-test', 'email' => REG_ADRESSE, 'email_status' => 'valid',
        'created_at' => $this->lundi->modify('-3 days')->format('c'), 'first_info_at' => $this->lundi->modify('-3 days')->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $this->ws, 'empreinte_adresse' => ListeSuppression::empreinte(REG_ADRESSE), 'verdict' => 'valide', 'verifie_le' => $this->lundi->modify('-2 days')->format('c'), 'source' => 'test']);
    $this->ins = (new Inscriptions(new LecteurFicheDb))->inscrire($this->ws, $sid, $contact, $this->lundi);
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('message accepté par le faux serveur : le Message-ID est enregistré puis retrouvé par la relève', function () {
    expect(($this->cycle)())->toBe([Sequenceur::ENVOYE => 1]);

    $ligne = DB::table('envoi_messages')->where('inscription_id', $this->ins)->first();
    $registre = new RegistreMessagesEnvoyesDb(fn (string $ws, string $empreinte): ?string => $empreinte === ListeSuppression::empreinte(REG_ADRESSE) ? REG_ADRESSE : null);
    $origine = $registre->retrouver($this->ws, [$ligne->message_id]);

    expect($origine)->not->toBeNull()->and($origine->adresse)->toBe(REG_ADRESSE);
    // UUID du registre (C1) dans les deux colonnes de rattachement ; l'identifiant du serveur (« sim-N ») va dans id_injection_serveur.
    $local = DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->first();
    expect($local)->not->toBeNull()
        ->and($origine->idInjection)->toBe($local->id_injection)
        ->and($ligne->id_injection)->toBe($local->id_injection)
        ->and($ligne->id_injection_serveur)->toStartWith('sim-')
        ->and($local->cle_idempotence)->toBe('seq-' . $this->ins . '-1')
        ->and(DB::table('envoi_messages_envoyes')->where('workspace_id', $this->ws)->count())->toBe(1);
});

test('rejouer le cycle n\'écrit pas deux fois le registre ni l\'injection locale', function () {
    ($this->cycle)();
    ($this->cycle)();

    expect(DB::table('envoi_messages_envoyes')->where('workspace_id', $this->ws)->count())->toBe(1)->and(DB::table('envoi_injections_locales')->where('workspace_id', $this->ws)->count())->toBe(1);
});

test('un échec d\'écriture du registre n\'est JAMAIS perdu en silence : alerte critique, conclusion conservée', function () {
    DB::unprepared("CREATE FUNCTION zz_registre_en_panne() RETURNS trigger AS \$f\$ BEGIN RAISE EXCEPTION 'panne simulee'; END \$f\$ LANGUAGE plpgsql");
    DB::unprepared('CREATE TRIGGER zz_registre_en_panne BEFORE INSERT ON envoi_messages_envoyes FOR EACH ROW EXECUTE FUNCTION zz_registre_en_panne()');
    Log::spy();

    $bilan = ($this->cycle)();

    // Le message est réellement parti : sa conclusion « envoyé » n'est pas défaite par la panne du registre.
    expect($bilan)->toBe([Sequenceur::ENVOYE => 1])
        ->and($this->faux->appels)->toBe(1)
        ->and(DB::table('envoi_messages')->where('inscription_id', $this->ins)->value('statut'))->toBe('envoye')
        ->and(DB::table('envoi_messages_envoyes')->where('workspace_id', $this->ws)->count())->toBe(0);
    Log::shouldHaveReceived('critical')->withArgs(fn (string $m): bool => str_starts_with($m, 'envoi.registre_ecriture_echouee'))->once();
});

test('garde avec témoin : un id_injection inconnu des deux tables reste refusé', function () {
    expect(fn () => DB::table('envoi_messages_envoyes')->insert([
        'workspace_id' => $this->ws, 'message_id_empreinte' => hash('sha256', 'mid:inconnu'), 'id_injection' => (string) Str::uuid(),
        'empreinte_destinataire' => ListeSuppression::empreinte(REG_ADRESSE),
    ]))->toThrow(QueryException::class);
});

test('espace sans identité : la fabrique refuse, aucune injection, aucun registre', function () {
    $ws = F::espace('zz-registre-sans-identite');
    WorkspaceContext::set($ws);
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'zz-nu.example.test', 'achete_le' => '2026-01-01']);
    DB::table('envoi_boites')->insert(['workspace_id' => $ws, 'adresse' => 'contact@zz-nu.example.test', 'hote' => 'imap.example.test', 'port' => 587, 'identifiants_chiffres' => 'x']);

    expect(fn () => app(SourceExpeditionDb::class)->sequenceurPour($ws, $this->faux, new LecteurFicheDb))->toThrow(RuntimeException::class)
        ->and($this->faux->appels)->toBe(0)
        ->and(DB::table('envoi_messages_envoyes')->where('workspace_id', $ws)->count())->toBe(0);
});

<?php

/**
 * RR-4A — relève hors espace commercial : plus d'exception, effets Envoi seuls (opposition, séquence, freins, réponses).
 * Chaque test tourne sous le rôle applicatif (RLS) ET sous le propriétaire. Données fictives (example.test), aucun réseau.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Imap\FabriqueLecteurFixe;
use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\JourEnvoi;
use App\Envoi\PasserelleLocale;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\SourceExpeditionDb;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const RR4_MODES = ['app', 'proprio'];
const RR4_LUNDI = '2026-11-16 10:00:00';

/** Exécute sous le mode : « app » = rôle applicatif non propriétaire sous RLS, « proprio » = propriétaire. */
function rr4Sous(string $mode, string $ws, Closure $f): mixed
{
    WorkspaceContext::set($ws);
    if ($mode === 'app') {
        DB::statement('SET ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE'); // le rôle ne doit pas fuir vers le test suivant (même transaction)
    }
}
function rr4Boite(string $ws, string $adresse): string
{
    $id = (string) Str::uuid();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->insert([
        'id' => $id, 'workspace_id' => $ws, 'adresse' => $adresse, 'hote' => 'smtp.example.test', 'port' => 587,
        'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz',
    ]));

    return $id;
}
/**
 * Un espace complet : identité, domaine vérifié, boîte, séquence à une étape et DEUX contacts de la même entreprise
 * (inscrits tous deux). Rend aussi `cycle()` : un cycle du séquenceur derrière la PasserelleLocale (faux serveur « sim-N »).
 *
 * @return array<string, mixed>
 */
function rr4Monde(string $ws, string $slug, array $adresses): array
{
    $lundi = new DateTimeImmutable(RR4_LUNDI, new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($ws, 'test-rr4');
    WorkspaceContext::run($ws, function () use ($ws, $slug, $lundi): void {
        app(DepotReglages::class)->enregistrerIdentite($ws, [
            'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
            'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
        ], 'test');
        $jour = $lundi->format('c');
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => "{$slug}.example.test", 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);
    });
    $boite = rr4Boite($ws, "envoi@{$slug}.example.test");
    $faux = new FauxServeurEnvoi;
    $m = ['boite' => $boite, 'faux' => $faux, 'domaine' => "{$slug}.example.test", 'lundi' => $lundi, 'contacts' => [], 'inscriptions' => []];
    WorkspaceContext::run($ws, function () use ($ws, $adresses, $lundi, &$m): void {
        $sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 2, 'delai_jours_ouvres' => 3, 'sujet' => 'Relance', 'corps' => 'Corps']); // l'inscription reste active après l'étape 1
        $e = F::fiche($ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $lundi->modify('-3 days')->format('c')]);
        F::lier($ws, $e, F::tag($ws, FichesProtegees::TAG_FEDERATIONS));
        foreach ($adresses as $adresse) {
            $c = (int) DB::table('contacts')->insertGetId([
                'workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Zoe', 'last_name' => 'Test-' . substr(md5($adresse), 0, 8), 'email' => $adresse, 'email_status' => 'valid',
                'created_at' => $lundi->modify('-3 days')->format('c'), 'first_info_at' => $lundi->modify('-3 days')->format('c'),
            ]);
            DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($adresse), 'verdict' => 'valide', 'verifie_le' => $lundi->modify('-2 days')->format('c'), 'source' => 'test']);
            $m['contacts'][$adresse] = $c;
            $m['inscriptions'][$adresse] = (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $sid, $c, $lundi);
        }
    });
    $m['cycle'] = function () use ($ws, $faux, $lundi): array {
        WorkspaceContext::set($ws);
        $passerelle = new PasserelleLocale($faux, new DepotSocleDb, true, 300, fn () => $lundi, $ws, 300, fn () => WorkspaceContext::current());

        return app(SourceExpeditionDb::class)->sequenceurPour($ws, $passerelle, new LecteurFicheDb)->executer($ws, $lundi);
    };

    return $m;
}
/** Relève de la boîte sous le mode demandé, par le conteneur (même chaîne que la production : CRM, freins, séquences, réponses). */
function rr4Releve(string $mode, string $ws, string $boite, LecteurBoiteMemoire $lecteur): void
{
    app()->instance(FabriqueLecteurs::class, new FabriqueLecteurFixe($lecteur));
    app()->forgetInstance(ReleveImap::class);
    rr4Sous($mode, $ws, fn () => app(ReleveImap::class)->relever($ws, $boite));
}

/** Un retour déposé dans la boîte simulée : STOP / plainte (corps), ou rebond dur (DSN 5.1.1). */
function rr4Depot(LecteurBoiteMemoire $lecteur, string $boite, int $uid, string $de, ?string $inReplyTo, string $corps, ?string $dsn = null): void
{
    $lecteur->deposer($boite, new MessageBrut(
        $uid,
        "retour-{$uid}-{$boite}@retour.example.test",
        $dsn === null ? $inReplyTo : null,
        [],
        $dsn === null ? $de : 'MAILER-DAEMON@retour.example.test',
        new DateTimeImmutable('2026-11-16 09:00:00', new DateTimeZone('UTC')),
        $dsn === null ? 'Re: Sujet' : 'Undelivered',
        $corps,
        [],
        $dsn,
        $dsn === null ? null : $inReplyTo,
        $dsn === null ? null : $de,
    ));
}

/** Monde + cycle + un retour relevé ; rend le monde et l'identifiant du message envoyé à la première adresse. */
function rr4Scenario(string $ws, string $mode, string $slug, array $adresses, string $corps, ?string $dsn = null, bool $releve = true): array
{
    $m = rr4Monde($ws, $slug, $adresses);
    ($m['cycle'])();
    $premier = array_values($m['inscriptions'])[0];
    $m['message_id'] = WorkspaceContext::run($ws, fn () => (string) DB::table('envoi_messages')->where('workspace_id', $ws)->where('inscription_id', $premier)->value('message_id'));
    $m['lecteur'] = new LecteurBoiteMemoire;
    $m['lecteur']->definirUidValidity($m['boite'], 1);
    rr4Depot($m['lecteur'], $m['boite'], 1, $adresses[0], $m['message_id'], $corps, $dsn);
    if ($releve) {
        rr4Releve($mode, $ws, $m['boite'], $m['lecteur']);
    }

    return $m;
}

function rr4Statuts(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions')->where('workspace_id', $ws)->pluck('statut', 'empreinte_adresse')->all());
}

function rr4Count(string $ws, string $table): int
{
    return WorkspaceContext::run($ws, fn () => DB::table($table)->where('workspace_id', $ws)->count());
}

/** Commande factice qui compte ses appels (remplace `crm:campagne:retours`). */
function rr4EspionCrm(): ArrayObject
{
    $appels = new ArrayObject;
    Artisan::command('crm:campagne:retours {file}', function (string $file) use ($appels) {
        $appels[] = $file;

        return 0;
    });

    return $appels;
}

beforeEach(function () {
    $this->a = F::espace('zz-rr4-a');
    $this->b = F::espace('zz-rr4-b');
    Cache::flush();
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire);
    config(['envoi.reel_actif' => true, 'envoi.releve_actif' => true, 'envoi.cle_empreinte' => str_repeat('k', 40),
        'envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app',
        'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'],
        'crm.ingest.business_workspace' => F::slug($this->b)]); // l'espace commercial est B : A relève hors espace commercial
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('H1 STOP relevé hors espace commercial : aucune exception, message noté, opposition « releve », inscription arrêtée, réponse stockée, aucun échec', function () {
    foreach (RR4_MODES as $mode) {
        $x = "stop.{$mode}@zz-client.example.test";
        $avant = rr4Count($this->a, 'envoi_evenements');
        rr4Scenario($this->a, $mode, "h1-{$mode}", [$x], 'STOP, merci');
        expect(rr4Statuts($this->a)[ListeSuppression::empreinte($x)])->toBe('arretee', $mode);
        expect(rr4Count($this->a, 'envoi_evenements'))->toBe($avant + 1, $mode)->and(rr4Count($this->a, 'envoi_echecs_releve'))->toBe(0, $mode)
            ->and(rr4Count($this->a, 'envoi_reponses'))->toBeGreaterThanOrEqual(1);
        expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_oppositions')->where('empreinte_adresse', ListeSuppression::empreinte($x))->where('canal', 'releve')->count()))->toBe(1, $mode);
    }
});

test('H2 plainte et rebond dur hors espace commercial : freins comptés, inscription arrêtée', function () {
    foreach (RR4_MODES as $mode) {
        $p = "plainte.{$mode}@zz-client.example.test";
        $r = "rebond.{$mode}@zz-client.example.test";
        rr4Scenario($this->a, $mode, "h2p-{$mode}", [$p], 'Je vais porter plainte, cessez ces envois.');
        rr4Scenario($this->a, $mode, "h2r-{$mode}", [$r], '', '5.1.1');
        $s = rr4Statuts($this->a);
        expect($s[ListeSuppression::empreinte($p)])->toBe('arretee', $mode)->and($s[ListeSuppression::empreinte($r)])->toBe('arretee', $mode);
        $freins = app(DepotChauffe::class)->totaux7Jours($this->a, JourEnvoi::de(now()->toDateTimeImmutable()), 'domaine');
        expect($freins['plaintes'])->toBeGreaterThanOrEqual(1, $mode)->and($freins['rebonds_durs'])->toBeGreaterThanOrEqual(1, $mode);
    }
});

test('H3 dans l’espace commercial, crm:campagne:retours est toujours appelé ; hors espace commercial, jamais', function () {
    $appels = rr4EspionCrm();
    config(['crm.ingest.business_workspace' => F::slug($this->a)]);
    rr4Scenario($this->a, 'proprio', 'h3-a', ['commercial@zz-client.example.test'], 'STOP');
    expect($appels)->toHaveCount(1);
    config(['crm.ingest.business_workspace' => F::slug($this->b)]);
    rr4Scenario($this->a, 'proprio', 'h3-b', ['horscommercial@zz-client.example.test'], 'STOP');
    expect($appels)->toHaveCount(1);
});

test('H4 la relève de l’Espace B (non commercial) ne touche aucune ligne de l’Espace A', function () {
    foreach (RR4_MODES as $mode) {
        $x = "cloison.{$mode}@zz-client.example.test";
        config(['crm.ingest.business_workspace' => F::slug($this->a)]);
        rr4Scenario($this->a, $mode, "h4-a-{$mode}", [$x], 'bonjour', null, false);
        $avant = rr4Count($this->a, 'envoi_oppositions');
        config(['crm.ingest.business_workspace' => 'zz-aucun-espace-commercial']);
        rr4Scenario($this->b, $mode, "h4-b-{$mode}", [$x], 'STOP');
        expect(rr4Statuts($this->b)[ListeSuppression::empreinte($x)])->toBe('arretee', $mode)
            ->and(rr4Statuts($this->a)[ListeSuppression::empreinte($x)])->toBe('active', $mode)
            ->and(rr4Count($this->a, 'envoi_oppositions'))->toBe($avant, $mode);
    }
});

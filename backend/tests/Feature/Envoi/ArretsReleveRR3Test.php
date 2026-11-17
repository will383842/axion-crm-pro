<?php

/**
 * RR-3A — STOP, plainte et rebond dur RELEVÉS arrêtent la séquence tout de suite (même transaction que la relève).
 * Chaque test tourne sous le rôle applicatif (RLS) ET sous le propriétaire. Données fictives (example.test), aucun réseau.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\FabriqueLecteurFixe;
use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\RecepteurRetoursSequence;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\RetourDetecte;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const RR3_MODES = ['app', 'proprio'];
const RR3_LUNDI = '2026-11-16 10:00:00';

/** Exécute sous le mode : « app » = rôle applicatif non propriétaire sous RLS, « proprio » = propriétaire. */
function rr3Sous(string $mode, string $ws, Closure $f): mixed
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
function rr3Boite(string $ws, string $adresse): string
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
function rr3Monde(string $ws, string $slug, array $adresses): array
{
    $lundi = new DateTimeImmutable(RR3_LUNDI, new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($ws, 'test-rr3');
    WorkspaceContext::run($ws, function () use ($ws, $slug, $lundi): void {
        app(DepotReglages::class)->enregistrerIdentite($ws, [
            'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
            'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
        ], 'test');
        $jour = $lundi->format('c');
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => "{$slug}.example.test", 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);
    });
    $boite = rr3Boite($ws, "envoi@{$slug}.example.test");
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
function rr3Releve(string $mode, string $ws, string $boite, LecteurBoiteMemoire $lecteur): void
{
    app()->instance(FabriqueLecteurs::class, new FabriqueLecteurFixe($lecteur));
    app()->forgetInstance(ReleveImap::class);
    rr3Sous($mode, $ws, fn () => app(ReleveImap::class)->relever($ws, $boite));
}

/** Un retour déposé dans la boîte simulée : STOP / plainte (corps), ou rebond dur (DSN 5.1.1). */
function rr3Depot(LecteurBoiteMemoire $lecteur, string $boite, int $uid, string $de, ?string $inReplyTo, string $corps, ?string $dsn = null): void
{
    $lecteur->deposer($boite, new MessageBrut(
        $uid,
        "retour-{$uid}@retour.example.test",
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
function rr3Scenario(string $ws, string $mode, string $slug, array $adresses, string $corps, ?string $dsn = null, bool $releve = true): array
{
    $m = rr3Monde($ws, $slug, $adresses);
    ($m['cycle'])();
    $premier = array_values($m['inscriptions'])[0];
    $m['message_id'] = WorkspaceContext::run($ws, fn () => (string) DB::table('envoi_messages')->where('workspace_id', $ws)->where('inscription_id', $premier)->value('message_id'));
    $m['lecteur'] = new LecteurBoiteMemoire;
    $m['lecteur']->definirUidValidity($m['boite'], 1);
    rr3Depot($m['lecteur'], $m['boite'], 1, $adresses[0], $m['message_id'], $corps, $dsn);
    if ($releve) {
        rr3Releve($mode, $ws, $m['boite'], $m['lecteur']);
    }

    return $m;
}

function rr3Statuts(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions')->where('workspace_id', $ws)->pluck('statut', 'empreinte_adresse')->all());
}

function rr3Count(string $ws, string $table): int
{
    return WorkspaceContext::run($ws, fn () => DB::table($table)->where('workspace_id', $ws)->count());
}

beforeEach(function () {
    $this->a = F::espace('zz-rr3-a');
    $this->b = F::espace('zz-rr3-b');
    Cache::flush();
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire);
    config(['envoi.reel_actif' => true, 'envoi.releve_actif' => true, 'envoi.cle_empreinte' => str_repeat('k', 40),
        'envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app',
        'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'],
        'crm.ingest.business_workspace' => F::slug($this->a)]);
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('A1 STOP relevé : adresse ET collègue arrêtés, trace « opposition », une opposition canal « releve », empreinte en liste de suppression', function () {
    foreach (RR3_MODES as $mode) {
        $x = "stop.{$mode}@zz-client.example.test";
        $y = "collegue.{$mode}@zz-client.example.test";
        $m = rr3Scenario($this->a, $mode, "stop-{$mode}", [$x, $y], 'STOP, merci');
        $ins = $m['inscriptions'];
        $l = fn (string $id) => WorkspaceContext::run($this->a, fn () => DB::table('envoi_inscriptions')->where('id', $id)->first());
        expect($l($ins[$x])->statut)->toBe('arretee', $mode)->and($l($ins[$x])->motif_arret)->toBe('opposition', $mode)
            ->and($l($ins[$y])->statut)->toBe('arretee', $mode)->and($l($ins[$y])->motif_arret)->toBe('entreprise_arretee', $mode);
        expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_inscriptions_evenements')->where('inscription_id', $ins[$x])->where('evenement', 'opposition')->count()))->toBe(1, $mode);
        expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_oppositions')->where('empreinte_adresse', ListeSuppression::empreinte($x))->where('canal', 'releve')->count()))->toBe(1, $mode);
        expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_oppositions')->where('empreinte_adresse', ListeSuppression::empreinte($x))->where('canal', '<>', 'releve')->count()))->toBe(0, $mode);
    }
});

test('A2 plainte relevée : mêmes arrêts qu’un STOP', function () {
    foreach (RR3_MODES as $mode) {
        $x = "plainte.{$mode}@zz-client.example.test";
        $y = "collegue.{$mode}@zz-client.example.test";
        rr3Scenario($this->a, $mode, "plainte-{$mode}", [$x, $y], 'Je vais porter plainte, cessez ces envois.');
        $s = rr3Statuts($this->a);
        expect($s[ListeSuppression::empreinte($x)])->toBe('arretee', $mode)->and($s[ListeSuppression::empreinte($y)])->toBe('arretee', $mode);
        expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_oppositions')->where('canal', 'releve')->count()))->toBeGreaterThanOrEqual(1);
    }
});

test('A3 rebond dur relevé : l’adresse s’arrête, le collègue de l’entreprise reste actif, aucune opposition', function () {
    foreach (RR3_MODES as $mode) {
        $x = "rebond.{$mode}@zz-client.example.test";
        $y = "collegue.{$mode}@zz-client.example.test";
        rr3Scenario($this->a, $mode, "rebond-{$mode}", [$x, $y], '', '5.1.1');
        $s = rr3Statuts($this->a);
        expect($s[ListeSuppression::empreinte($x)])->toBe('arretee', $mode)->and($s[ListeSuppression::empreinte($y)])->toBe('active', $mode);
        expect(rr3Count($this->a, 'envoi_oppositions'))->toBe(0, $mode);
    }
});

test('A4 réinscription refusée après un STOP relevé (échec fermé de bout en bout)', function () {
    $x = 'refus@zz-client.example.test';
    $m = rr3Scenario($this->a, 'proprio', 'refus', [$x], 'STOP');
    expect(rr3Statuts($this->a)[ListeSuppression::empreinte($x)])->toBe('arretee');
    WorkspaceContext::run($this->a, function () use ($m, $x) {
        $sid = (string) DB::table('envoi_sequences')->where('workspace_id', $this->a)->value('id');
        $c = (int) DB::table('contacts')->where('email', $x)->value('id');
        $nouvelle = (new Inscriptions(new LecteurFicheDb))->inscrire($this->a, $sid, $c, $m['lundi']->modify('+1 day'));
        expect(DB::table('envoi_inscriptions')->where('id', $nouvelle)->value('statut'))->not->toBe('active');
    });
});

test('A5 rejeu du même retour : une seule opposition, une seule trace', function () {
    $x = 'rejeu@zz-client.example.test';
    $m = rr3Scenario($this->a, 'proprio', 'rejeu', [$x], 'STOP');
    $avant = [rr3Count($this->a, 'envoi_oppositions'), rr3Count($this->a, 'envoi_inscriptions_evenements')];
    rr3Releve('proprio', $this->a, $m['boite'], $m['lecteur']);
    expect([rr3Count($this->a, 'envoi_oppositions'), rr3Count($this->a, 'envoi_inscriptions_evenements')])->toBe($avant)->and($avant[0])->toBe(1);
});

test('A6 cloisonnement : un STOP relevé dans l’Espace B ne touche rien de l’Espace A (même adresse)', function () {
    foreach (RR3_MODES as $mode) {
        $x = "cloison.{$mode}@zz-client.example.test";
        $mA = rr3Scenario($this->a, $mode, "cloison-a-{$mode}", [$x], 'bonjour', null, false);
        config(['crm.ingest.business_workspace' => F::slug($this->b)]); // la relève d'un espace non commercial relève de RR-4A
        rr3Scenario($this->b, $mode, "cloison-b-{$mode}", [$x], 'STOP');
        expect(rr3Statuts($this->b)[ListeSuppression::empreinte($x)])->toBe('arretee', $mode)
            ->and(rr3Statuts($this->a)[ListeSuppression::empreinte($x)])->toBe('active', $mode)
            ->and(rr3Count($this->a, 'envoi_oppositions'))->toBe(0, $mode);
    }
});

test('A7 STOP sans inscription rattachée : l’adresse connue d’une inscription active est arrêtée par l’opposition', function () {
    foreach (RR3_MODES as $mode) {
        $x = "orphelin.{$mode}@zz-client.example.test";
        rr3Monde($this->a, "orphelin-{$mode}", [$x]);
        $retour = new RetourDetecte(ClasseRetour::Desinscription, (string) Str::uuid(), $x, null, 'cle-' . $mode);
        rr3Sous($mode, $this->a, fn () => RecepteurRetoursSequence::parDefaut()->recevoir($this->a, $retour));
        expect(rr3Statuts($this->a)[ListeSuppression::empreinte($x)])->toBe('arretee', $mode);
    }
});

test('A9 aucune adresse en clair dans les journaux ni dans les traces touchées', function () {
    $journal = new TestHandler;
    Log::driver()->getLogger()->pushHandler($journal);
    $x = 'discret@zz-client.example.test';
    rr3Scenario($this->a, 'proprio', 'discret', [$x], 'STOP');
    expect(json_encode(array_map(fn ($r) => [$r['message'], $r['context']], $journal->getRecords())))->not->toContain('example.test');
    foreach (['envoi_inscriptions_evenements', 'envoi_oppositions'] as $t) {
        expect(json_encode(WorkspaceContext::run($this->a, fn () => DB::table($t)->get())))->not->toContain('example.test');
    }
});

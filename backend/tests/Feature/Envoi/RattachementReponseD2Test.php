<?php

/**
 * D2 — rattachement manuel d'une réponse (identifiant du registre) et compteur `reponses_7j`.
 * Chaque test est rejoué sous le rôle applicatif (RLS) ET sous le propriétaire (seul le filtre `workspace_id` protège).
 * Données fictives (example.test), aucun réseau.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Imap\FabriqueLecteurFixe;
use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\Verp;
use App\Envoi\PasserelleLocale;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Reponses\ServiceReponses;
use App\Envoi\Reponses\StockerReponse;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\SourceExpeditionDb;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Envoi\Smtp\DepotBoites;
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

const D2_MODES = ['app', 'proprio'];

/** Exécute sous le mode : « app » = rôle applicatif non propriétaire sous RLS, « proprio » = propriétaire. */
function d2Sous(string $mode, string $ws, Closure $f): mixed
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

function d2Boite(string $ws, string $adresse): string
{
    $id = (string) Str::uuid();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->insert([
        'id' => $id, 'workspace_id' => $ws, 'adresse' => $adresse, 'hote' => 'smtp.example.test', 'port' => 587,
        'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz',
    ]));

    return $id;
}

/** Une injection du registre local (faux serveur / Kumo) : l'UUID rendu est celui que la relève fournit. */
function d2Locale(string $ws): string
{
    return WorkspaceContext::run($ws, fn () => (string) DB::table('envoi_injections_locales')->insertGetId(
        ['workspace_id' => $ws, 'cle_idempotence' => 'seq-' . Str::uuid() . '-1', 'adaptateur' => 'faux'],
        'id_injection',
    ));
}

/** Événement « à rattacher » (boîte = UUID de la boîte, comme la relève) + réponse stockée ; rend l'id de la réponse. */
function d2Reponse(string $ws, string $boite): int
{
    $cle = hash('sha256', Str::random(20));

    return WorkspaceContext::run($ws, function () use ($ws, $boite, $cle): int {
        $evenement = DB::table('envoi_evenements')->insertGetId([
            'workspace_id' => $ws, 'boite' => $boite, 'uid_validity' => 1, 'uid' => random_int(1, 1_000_000),
            'empreinte_message' => $cle, 'classe' => 'reponse_humaine', 'statut' => 'a_rattacher',
            'empreinte_expediteur' => ListeSuppression::empreinte('zoe.martin@societe-a.example.test'), 'recu_le' => now(),
        ]);
        app(StockerReponse::class)->enregistrer($ws, $cle, 'Objet de test', 'Merci.', 'zoe.martin@societe-a.example.test', 'reponse_humaine');

        return (int) DB::table('envoi_reponses')->where('evenement_id', $evenement)->value('id');
    });
}

beforeEach(function () {
    $this->a = F::espace('zz-d2-a');
    $this->b = F::espace('zz-d2-b');
    $this->boiteA = d2Boite($this->a, 'envoi@societe-a.example.test');
    $this->boiteB = d2Boite($this->b, 'envoi@societe-b.example.test');
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('R5 rattachement manuel : l’UUID du registre local est accepté (rôle applicatif ET propriétaire)', function () {
    foreach (D2_MODES as $mode) {
        $reponse = d2Reponse($this->a, $this->boiteA);
        $injection = d2Locale($this->a);
        [$etat] = d2Sous($mode, $this->a, fn () => app(ServiceReponses::class)->rattacher($this->a, (string) Str::uuid(), $reponse, $injection));
        expect($etat)->toBe('ok', $mode);
        expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_rattachements')->where('workspace_id', $this->a)->where('id_injection', $injection)->count()))->toBe(1, $mode);
    }
});

test('R5 rattachement manuel : un UUID inconnu, ou celui d’un autre espace, est refusé (inconnue_injection)', function () {
    foreach (D2_MODES as $mode) {
        $reponse = d2Reponse($this->a, $this->boiteA);
        $deB = d2Locale($this->b);
        foreach ([(string) Str::uuid(), $deB] as $injection) {
            [$etat] = d2Sous($mode, $this->a, fn () => app(ServiceReponses::class)->rattacher($this->a, (string) Str::uuid(), $reponse, $injection));
            expect($etat)->toBe('inconnue_injection', $mode);
        }
    }
    expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_rattachements')->count()))->toBe(0);
});

test('R5 rattachement manuel : la forme historique (envoi_injections_smtp) reste acceptée', function () {
    foreach (D2_MODES as $mode) {
        $reponse = d2Reponse($this->a, $this->boiteA);
        $injection = (string) Str::uuid();
        WorkspaceContext::run($this->a, fn () => DB::table('envoi_injections_smtp')->insert(['workspace_id' => $this->a, 'boite_id' => $this->boiteA, 'id_injection' => $injection, 'cle_idempotence' => 'zz-d2-' . $injection]));
        [$etat] = d2Sous($mode, $this->a, fn () => app(ServiceReponses::class)->rattacher($this->a, (string) Str::uuid(), $reponse, $injection));
        expect($etat)->toBe('ok', $mode);
    }
});

test('R6 reponses_7j : la relève écrit l’UUID de la boîte ; deux réponses relevées donnent 2 (les autres boîtes et espaces sont exclus)', function () {
    foreach (D2_MODES as $mode) {
        $boite = d2Boite($this->a, "envoi-{$mode}@societe-a.example.test");
        d2Reponse($this->a, $boite);
        d2Reponse($this->a, $boite);
        d2Reponse($this->a, $this->boiteA);     // autre boîte du même espace
        d2Reponse($this->b, $this->boiteB);     // autre espace
        d2Reponse($this->b, $boite);            // événement de l'espace B portant l'UUID de la boîte de A (colonne texte, sans clé étrangère)
        $resume = d2Sous($mode, $this->a, fn () => app(DepotBoites::class)->resumer($this->a, $boite));
        expect($resume['reponses_7j'])->toBe(2, $mode);
    }
});

test('R6 reponses_7j : une ligne de l’ancienne forme (boîte = adresse) reste comptée', function () {
    $adresse = 'ancienne@societe-a.example.test';
    $boite = d2Boite($this->a, $adresse);
    d2Reponse($this->a, $adresse);
    d2Reponse($this->a, $boite);
    expect(d2Sous('proprio', $this->a, fn () => app(DepotBoites::class)->resumer($this->a, $boite))['reponses_7j'])->toBe(2);
});

test('R6 liste des réponses : boite_id vaut l’UUID de la boîte pour une réponse relevée (UUID ou ancienne forme « adresse »), jamais celle d’un autre espace', function () {
    foreach (D2_MODES as $mode) {
        $adresse = "liste-{$mode}@societe-a.example.test";
        $boite = d2Boite($this->a, $adresse);
        $reponse = d2Reponse($this->a, $boite);       // forme actuelle : boîte = UUID
        $ancienne = d2Reponse($this->a, $adresse);    // ancienne forme : boîte = adresse
        d2Boite($this->b, $adresse);                  // même adresse dans l'Espace B : ne doit jamais être choisie
        $lignes = d2Sous($mode, $this->a, fn () => app(ServiceReponses::class)->lister($this->a, 'a_rattacher', null, null, 1, 50)['data']);
        $parId = array_column($lignes, 'boite_id', 'id');
        expect($parId[$reponse] ?? null)->toBe($boite, $mode)->and($parId[$ancienne] ?? null)->toBe($boite, $mode);
    }
});

// ───────────────────────── D2-2 : preuves R1 à R4 et R7 de la chaîne entière (séquenceur → registre → relève → arrêt) ─────────────────────────

const D2_LUNDI = '2026-11-16 10:00:00';

/**
 * Un espace complet : identité, domaine vérifié, boîte, séquence à une étape et DEUX contacts de la même entreprise
 * (inscrits tous deux). Rend aussi `cycle()` : un cycle du séquenceur derrière la PasserelleLocale (faux serveur « sim-N »).
 *
 * @return array<string, mixed>
 */
function d2Monde(string $ws, string $slug, array $adresses): array
{
    $lundi = new DateTimeImmutable(D2_LUNDI, new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($ws, 'test-d2');
    WorkspaceContext::run($ws, function () use ($ws, $slug, $lundi): void {
        app(DepotReglages::class)->enregistrerIdentite($ws, [
            'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
            'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
        ], 'test');
        $jour = $lundi->format('c');
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => "{$slug}.example.test", 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);
    });
    $boite = d2Boite($ws, "envoi@{$slug}.example.test");
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

/** Une réponse déposée dans un lecteur simulé (aucun réseau). */
function d2Reception(LecteurBoiteMemoire $lecteur, string $boite, int $uid, string $de, ?string $inReplyTo, array $locales = []): void
{
    $lecteur->deposer($boite, new MessageBrut(
        $uid,
        "reponse-{$uid}@retour.example.test",
        $inReplyTo,
        [],
        $de,
        new DateTimeImmutable('2026-11-16 09:00:00', new DateTimeZone('UTC')),
        'Re: Sujet',
        'Merci pour votre message, je vous rappelle.',
        [],
        null,
        null,
        null,
        null,
        null,
        false,
        null,
        null,
        $locales,
    ));
}

/** Relève de la boîte sous le mode demandé, par le conteneur (même chaîne que la production : CRM, freins, séquences, réponses). */
function d2Releve(string $mode, string $ws, string $boite, LecteurBoiteMemoire $lecteur): void
{
    app()->instance(FabriqueLecteurs::class, new FabriqueLecteurFixe($lecteur));
    app()->forgetInstance(ReleveImap::class);
    d2Sous($mode, $ws, fn () => app(ReleveImap::class)->relever($ws, $boite));
}

function d2Statuts(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions')->where('workspace_id', $ws)->pluck('statut', 'empreinte_adresse')->all());
}

describe('D2-2 chaîne entière', function () {
    beforeEach(function () {
        Cache::flush();
        app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire);
        config(['envoi.reel_actif' => true, 'envoi.releve_actif' => true, 'envoi.cle_empreinte' => str_repeat('k', 40),
            'envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app',
            'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'],
            'crm.ingest.business_workspace' => F::slug($this->a)]);
    });

    test('R1 bout en bout : le cycle envoie, la réponse (In-Reply-To) est relevée ⇒ rattachée, inscription arrêtée « reponse », collègue de l’entreprise arrêté (app ET propriétaire)', function () {
        foreach (D2_MODES as $mode) {
            $m = d2Monde($this->a, "societe-a-{$mode}", ["zoe.{$mode}@societe-a.example.test", "luc.{$mode}@societe-a.example.test"]);
            expect(($m['cycle'])())->toBe(['envoye' => 2], $mode);
            [$zoe, $luc] = array_values($m['inscriptions']);
            $messageId = WorkspaceContext::run($this->a, fn () => (string) DB::table('envoi_messages')->where('inscription_id', $zoe)->value('message_id'));
            // « sim-N » est l'identifiant du SERVEUR ; le registre porte l'UUID local : la chaîne ne tient que par C1-1 + C1-2.
            expect(WorkspaceContext::run($this->a, fn () => (string) DB::table('envoi_messages')->where('inscription_id', $zoe)->value('id_injection_serveur')))->toStartWith('sim-');

            $lecteur = new LecteurBoiteMemoire;
            $lecteur->definirUidValidity($m['boite'], 1);
            d2Reception($lecteur, $m['boite'], 1, "zoe.{$mode}@societe-a.example.test", $messageId);
            d2Releve($mode, $this->a, $m['boite'], $lecteur);

            $evenement = WorkspaceContext::run($this->a, fn () => DB::table('envoi_evenements')->where('boite', $m['boite'])->first());
            expect($evenement->statut)->toBe('rattache', $mode)->and($evenement->classe)->toBe('reponse_humaine', $mode);
            $ligne = WorkspaceContext::run($this->a, fn () => DB::table('envoi_inscriptions')->where('id', $zoe)->first());
            expect($ligne->statut)->toBe('arretee', $mode)->and($ligne->motif_arret)->toBe('reponse', $mode);
            // Les collègues de la même entreprise : arrêtés comme le veut `Inscriptions` (mêmes statut et motif que celui qui a répondu).
            $colle = WorkspaceContext::run($this->a, fn () => DB::table('envoi_inscriptions')->where('id', $luc)->first());
            $attendu = WorkspaceContext::run($this->a, fn () => DB::table('envoi_inscriptions')->where('id', $zoe)->value('statut'));
            expect($colle->statut)->toBe($attendu, $mode);
        }
    });

    test('R2 VERP : l’adresse signée rattache ; la même réponse sans VERP ni In-Reply-To reste « à rattacher », aucun arrêt', function () {
        foreach (D2_MODES as $mode) {
            $m = d2Monde($this->a, "verp-{$mode}", ["verp.{$mode}@societe-a.example.test"]);
            ($m['cycle'])();
            $injection = WorkspaceContext::run($this->a, fn () => (string) DB::table('envoi_injections_locales')->where('workspace_id', $this->a)->where('cle_idempotence', 'like', 'seq-' . array_values($m['inscriptions'])[0] . '-%')->value('id_injection'));
            $lecteur = new LecteurBoiteMemoire;
            $lecteur->definirUidValidity($m['boite'], 1);
            d2Reception($lecteur, $m['boite'], 1, "verp.{$mode}@societe-a.example.test", null);             // sans VERP ni In-Reply-To
            d2Releve($mode, $this->a, $m['boite'], $lecteur);
            expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_evenements')->where('boite', $m['boite'])->value('statut')))->toBe('a_rattacher', $mode);
            $empreinte = ListeSuppression::empreinte("verp.{$mode}@societe-a.example.test");
            expect(d2Statuts($this->a)[$empreinte])->toBe('active', $mode);

            d2Reception($lecteur, $m['boite'], 2, "verp.{$mode}@societe-a.example.test", null, [Verp::adressePour($injection, $m['domaine'])]);
            d2Releve($mode, $this->a, $m['boite'], $lecteur);
            expect(d2Statuts($this->a)[$empreinte])->toBe('arretee', $mode);
        }
    });

    test('R3 ancienne forme : id_injection = « sim-7 » dans envoi_messages, UUID local de clé seq-<inscription>-1 au registre ⇒ la relève arrête l’inscription', function () {
        foreach (D2_MODES as $mode) {
            $adresse = "ancien.{$mode}@societe-a.example.test";
            $m = d2Monde($this->a, "ancien-{$mode}", [$adresse]);
            d2AncienneForme($this->a, array_values($m['inscriptions'])[0], "ancien-{$mode}@d.example.test", $adresse, 'sim-7');
            $lecteur = new LecteurBoiteMemoire;
            $lecteur->definirUidValidity($m['boite'], 1);
            d2Reception($lecteur, $m['boite'], 1, $adresse, "ancien-{$mode}@d.example.test");
            d2Releve($mode, $this->a, $m['boite'], $lecteur);
            expect(d2Statuts($this->a)[ListeSuppression::empreinte($adresse)])->toBe('arretee', $mode);
        }
    });

    test('R4 cloisonnement : même Message-ID et même identifiant dans l’Espace B ⇒ rien de A n’est touché ; la boîte de B ne lit rien de A', function () {
        foreach (D2_MODES as $mode) {
            $adresse = "cloison.{$mode}@societe-a.example.test";
            $mA = d2Monde($this->a, "cloison-a-{$mode}", [$adresse]);
            ($mA['cycle'])();
            $messageId = WorkspaceContext::run($this->a, fn () => (string) DB::table('envoi_messages')->where('workspace_id', $this->a)->where('inscription_id', array_values($mA['inscriptions'])[0])->value('message_id'));
            $mB = d2Monde($this->b, "cloison-b-{$mode}", [$adresse]);
            $lecteur = new LecteurBoiteMemoire;
            $lecteur->definirUidValidity($mB['boite'], 1);

            // 1. (la même personne est aussi contact de B : sans filtre d'espace, l'adresse se résoudrait) L'Espace B reçoit une réponse qui cite le Message-ID de A (et n'en a pas lui-même) : « à rattacher », A intact.
            d2Reception($lecteur, $mB['boite'], 1, $adresse, $messageId);
            d2Releve($mode, $this->b, $mB['boite'], $lecteur);
            expect(WorkspaceContext::run($this->b, fn () => DB::table('envoi_evenements')->where('boite', $mB['boite'])->value('statut')))->toBe('a_rattacher', $mode)
                ->and(d2Statuts($this->a)[ListeSuppression::empreinte($adresse)])->toBe('active', $mode);

            // 2. B porte AUSSI ce Message-ID et ce « sim-N » : seule l'inscription de B s'arrête.
            d2AncienneForme($this->b, array_values($mB['inscriptions'])[0], $messageId, $adresse, 'sim-1');
            d2Reception($lecteur, $mB['boite'], 2, $adresse, $messageId);
            d2Releve($mode, $this->b, $mB['boite'], $lecteur);
            expect(d2Statuts($this->b)[ListeSuppression::empreinte($adresse)])->toBe('arretee', $mode)
                ->and(d2Statuts($this->a)[ListeSuppression::empreinte($adresse)])->toBe('active', $mode);
        }
    });

    test('R7 aucune adresse en clair : ni dans envoi_evenements (empreintes seulement), ni dans les journaux de la relève', function () {
        $journal = new TestHandler;
        Log::driver()->getLogger()->pushHandler($journal);
        foreach (D2_MODES as $mode) {
            $adresse = "discret.{$mode}@societe-a.example.test";
            $m = d2Monde($this->a, "discret-{$mode}", [$adresse]);
            ($m['cycle'])();
            $messageId = WorkspaceContext::run($this->a, fn () => (string) DB::table('envoi_messages')->where('workspace_id', $this->a)->where('inscription_id', array_values($m['inscriptions'])[0])->value('message_id'));
            $lecteur = new LecteurBoiteMemoire;
            $lecteur->definirUidValidity($m['boite'], 1);
            d2Reception($lecteur, $m['boite'], 1, $adresse, $messageId);
            d2Reception($lecteur, $m['boite'], 2, $adresse, null); // sans rattachement : « à rattacher »
            d2Releve($mode, $this->a, $m['boite'], $lecteur);
            $evenements = WorkspaceContext::run($this->a, fn () => DB::table('envoi_evenements')->where('boite', $m['boite'])->get());
            expect($evenements)->toHaveCount(2);
            foreach ($evenements as $e) {
                expect(json_encode($e))->not->toContain('example.test')->and(json_encode($e))->not->toContain('@');
            }
        }
        expect(json_encode(array_map(fn ($r) => [$r['message'], $r['context']], $journal->getRecords())))->not->toContain('example.test');
    });
});

/** Lignes posées comme l'ancien code : `envoi_messages.id_injection` = identifiant du SERVEUR, registre = UUID local de clé `seq-<inscription>-1`. */
function d2AncienneForme(string $ws, string $inscription, string $messageId, string $adresse, string $sim): void
{
    WorkspaceContext::run($ws, function () use ($ws, $inscription, $messageId, $adresse, $sim): void {
        $uuid = (string) DB::table('envoi_injections_locales')->insertGetId(['workspace_id' => $ws, 'cle_idempotence' => "seq-{$inscription}-1", 'adaptateur' => 'faux'], 'id_injection');
        DB::table('envoi_messages')->insert(['workspace_id' => $ws, 'inscription_id' => $inscription, 'etape' => 1, 'statut' => 'envoye', 'message_id' => $messageId, 'id_injection' => $sim]);
        DB::table('envoi_messages_envoyes')->insert([
            'workspace_id' => $ws, 'message_id_empreinte' => hash('sha256', 'mid:' . trim($messageId, " \t\r\n<>")), 'id_injection' => $uuid,
            'empreinte_destinataire' => ListeSuppression::empreinte($adresse),
        ]);
    });
}

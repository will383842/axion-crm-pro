<?php

/**
 * X2-T0 — le branchement du transport réel est PRÉPARÉ, jamais actif : drapeau éteint par défaut, porte fermée même avec une
 * implémentation réelle liée, aucune fabrique appelée, aucune requête HTTP. Domaines en `*.example.test`. Aucun réseau.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Desinscription\EnvoiRetraitsDemandes;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DecisionFeuVert;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\PasserelleLocale;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sante\DepotSante;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Sequence\SourceExpeditionDb;
use App\Envoi\Serveur\AiguilleurServeurEnvoi;
use App\Envoi\Serveur\FabriqueServeurKumo;
use App\Envoi\Serveur\ServeurEnvoiKumo;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Serveur\SignatureEnvoi;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Envoi\Smtp\ResolveurHote;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

/** Retrait « réel » (double) : sa seule présence ne doit JAMAIS suffire à ouvrir la porte. */
final class BiRetraitReel implements RetraitEnvoisEnFile
{
    public function retirerPour(string $workspaceId, string $empreinteAdresse): int
    {
        return 0;
    }
}

/**
 * Montage : domaine en adaptateur kumo, feu vert simulé accordé (par DecisionFeuVert), contrôle DNS vert du jour, fabriques espionnes.
 *
 * @return array{ws: string, domaine: string, compteur: ArrayObject<int, string>}
 */
function biMontage(): array
{
    Http::preventStrayRequests();
    $ws = F::espace('zz-bi');
    $domaine = 'bi-' . Str::lower(Str::random(6)) . '.example.test';
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $domaine, 'achete_le' => '2026-01-01']);
    WorkspaceContext::run($ws, fn () => DB::selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$ws, $domaine, 'kumo', 'auteur-test']));
    app()->bind(ListeFeuVert::class, fn () => new class(app(DepotPreuves::class), app(DepotSante::class)) extends ListeFeuVert
    {
        public function points(string $ws): array
        {
            $r = [];
            foreach (CatalogueManuels::POINTS as $code => $c) {
                $r[] = new PointFeuVert($code, $c['libelle'], str_starts_with($code, 'auto.'), true, 'simulé', $c['source'], $c['groupe'], 'fait');
            }

            return $r;
        }
    });
    $liste = app(ListeFeuVert::class);
    expect(app(DecisionFeuVert::class)->decider($ws, 'accorde', 'Décision de test après relecture, réf. exemple-01.', 'responsable', $liste->empreinte($ws, $liste->points($ws)))['statut'])->toBe(200);
    WorkspaceContext::run($ws, fn () => DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => $domaine, 'controle_le' => (new DateTimeImmutable)->format('c'), 'pret' => true, 'pause' => false]));
    $compteur = new ArrayObject;
    $fabrique = function (string $espace, string $d) use ($compteur): ServeurEnvoi {
        $compteur[] = 'fabrique';

        return new FauxServeurEnvoi;
    };
    app()->bind('envoi.serveur_interne', fn ($app) => new AiguilleurServeurEnvoi(
        new FauxServeurEnvoi,
        ['kumo' => $fabrique, 'smtp' => $fabrique],
        fn (): bool => $app->make(PorteEnvoiReelle::class)->ouverte(),
        fn (): ListeFeuVert => $app->make(ListeFeuVert::class),
    ));
    app()->forgetInstance(ServeurEnvoi::class);
    WorkspaceContext::set($ws);

    return ['ws' => $ws, 'domaine' => $domaine, 'compteur' => $compteur];
}

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('(1) reel_actif est faux sans variable d\'environnement ; la porte reste fermée même avec un retrait réel lié', function () {
    expect(config('envoi.reel_actif'))->toBeFalse();
    $defaut = (static function (): array {
        $ancien = getenv('ENVOI_REEL_ACTIF');
        $dansEnv = array_key_exists('ENVOI_REEL_ACTIF', $_ENV);
        $dansServer = array_key_exists('ENVOI_REEL_ACTIF', $_SERVER);
        $env = $_ENV['ENVOI_REEL_ACTIF'] ?? null;
        $server = $_SERVER['ENVOI_REEL_ACTIF'] ?? null;
        putenv('ENVOI_REEL_ACTIF');
        unset($_ENV['ENVOI_REEL_ACTIF'], $_SERVER['ENVOI_REEL_ACTIF']);
        try {
            return require base_path('config/envoi.php');
        } finally {
            // Restauration à l'identique des trois sources (putenv, $_ENV, $_SERVER) : le test ne laisse aucune trace.
            if ($ancien !== false) {
                putenv('ENVOI_REEL_ACTIF=' . $ancien);
            }
            if ($dansEnv) {
                $_ENV['ENVOI_REEL_ACTIF'] = $env;
            }
            if ($dansServer) {
                $_SERVER['ENVOI_REEL_ACTIF'] = $server;
            }
        }
    })();
    expect($defaut['reel_actif'])->toBeFalse();
    app()->instance(RetraitEnvoisEnFile::class, new BiRetraitReel);
    expect(app(RetraitEnvoisEnFile::class))->toBeInstanceOf(BiRetraitReel::class)->and(app(PorteEnvoiReelle::class)->ouverte())->toBeFalse();
});

test('(2) kumo + feu vert + DNS vert, porte fermée : injecter refusé (envoi_reel_inactif) ; les autres méthodes ne construisent rien et ne font aucune requête HTTP', function () {
    $m = biMontage();
    app()->instance(RetraitEnvoisEnFile::class, new BiRetraitReel);
    $message = new MessageAEnvoyer($m['ws'], 'dest@client.example.test', 'from@' . $m['domaine'], 'Sujet', 'Corps', 'cle-bi-1');
    $refus = null;
    try {
        app('envoi.serveur_interne')->injecter($message);
    } catch (EnvoiRefuse $e) {
        $refus = $e;
    }
    expect($refus)->not->toBeNull()->and($refus->getMessage())->toContain('envoi_reel_inactif');
    expect(fn () => app(ServeurEnvoi::class)->injecter($message))->toThrow(EnvoiRefuse::class, 'inactif');
    $serveur = app(ServeurEnvoi::class);
    $serveur->pause();
    $serveur->reprise();
    $serveur->sante();
    $serveur->etat();
    $serveur->etatEnvoi((string) Str::uuid());
    $serveur->lireEvenements(null);
    $interne = app('envoi.serveur_interne'); // l'aiguilleur lui-même : mêmes méthodes, porte fermée
    $interne->reprise();
    $interne->sante();
    $interne->etat();
    $interne->etatEnvoi((string) Str::uuid());
    $interne->lireEvenements(null);
    expect($m['compteur']->count())->toBe(0); // le compteur reste à 0 via la passerelle (pause incluse : porte fermée, rien transmis)
    Http::assertNothingSent();
});

test('(2 bis) l\'aiguilleur lui-même : pause() construit les adaptateurs portés par un domaine MÊME porte fermée (stop = sens sûr), sans requête HTTP', function () {
    $m = biMontage();
    $passerelle = app(ServeurEnvoi::class);
    $passerelle->pause();
    expect($m['compteur']->count())->toBe(0); // via la passerelle : porte fermée, rien n'est construit
    app('envoi.serveur_interne')->pause();
    expect($m['compteur']->count())->toBe(1); // en direct : le stop construit la fabrique kumo même porte fermée (documenté)
    Http::assertNothingSent();
});

test('(2 ter) envoi.serveur_interne : l\'aiguilleur de AiguillageServiceProvider gagne sur le faux serveur ; C3-5 : seule la fabrique kumo est liée (smtp NON lié, décision E4)', function () {
    $reflexion = fn (object $o, string $p) => (new ReflectionProperty($o, $p))->getValue($o);
    $interne = $reflexion(app(ServeurEnvoi::class), 'serveur');
    expect($interne)->toBeInstanceOf(AiguilleurServeurEnvoi::class)->and(app('envoi.serveur_interne'))->toBeInstanceOf(AiguilleurServeurEnvoi::class);
    $fabriques = $reflexion($interne, 'fabriques');
    expect(array_keys($fabriques))->toBe(['kumo'])
        ->and((new ReflectionFunction($fabriques['kumo']))->getClosureThis())->toBeInstanceOf(FabriqueServeurKumo::class);
});

test('(3) dans app/, seul le fournisseur qui construit PasserelleLocale résout envoi.serveur_interne', function () {
    $resolutions = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php' && preg_match('/(?:make|app|resolve)\(\s*[\'"]envoi\.serveur_interne[\'"]/', (string) file_get_contents($f->getPathname())) === 1) {
            $resolutions[] = $f;
        }
    }
    expect(array_map(fn ($f) => $f->getFilename(), $resolutions))->toBe(['EnvoiServiceProvider.php'])
        ->and(file_get_contents($resolutions[0]->getPathname()))->toContain('new PasserelleLocale(' . "\n" . '            $app->make(\'envoi.serveur_interne\')');
});

dataset('modes_bi', ['app', 'proprio']);

/** Source active + clé d'empreinte, SANS aucun secret HMAC ni URL configurés : toute lecture du secret à la construction lèverait. */
function biPreparerKumo(string $ws): void
{
    config(['envoi.cle_empreinte' => str_repeat('k', 40), 'envoi.serveur.secrets' => [], 'envoi.serveur.urls' => [], 'envoi.reel_actif' => false, 'envoi.k1_actif' => false, 'envoi.serveur.tolere_transaction_englobante' => true]);
    WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => 'srv1', 'url_base' => 'https://zz.invalid']));
}

function biRole(string $mode): void
{
    if ($mode === 'app') {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
}

test('(4) M2 : la fabrique réelle instanciée ne fait aucune connexion ; pause(), etat(), sante() porte fermée non plus (interrupteurs faux)', function (string $mode) {
    $m = biMontage();
    biPreparerKumo($m['ws']);
    biRole($mode);
    $fabrique = app(FabriqueServeurKumo::class);
    expect($fabrique($m['ws'], $m['domaine']))->toBeInstanceOf(ServeurEnvoiKumo::class);
    Http::assertNothingSent();

    $reel = new AiguilleurServeurEnvoi(
        new FauxServeurEnvoi,
        ['kumo' => $fabrique(...)],
        fn (): bool => app(PorteEnvoiReelle::class)->ouverte(),
        fn (): ListeFeuVert => app(ListeFeuVert::class),
    );
    expect(config('envoi.reel_actif'))->toBeFalse()->and(config('envoi.k1_actif'))->toBeFalse();
    // Sens sûr : la pause construit la fabrique réelle même porte fermée ; la commande est refusée LOCALEMENT (interrupteurs faux), sans requête.
    expect(fn () => $reel->pause())->toThrow(ServeurEnvoiRefuse::class);
    $reel->etat();
    $reel->sante();
    Http::assertNothingSent();
})->with('modes_bi');

test('(4 bis) fabrique : sans envoi.cle_empreinte ⇒ refus clair, rien de construit ni envoyé', function () {
    $m = biMontage();
    biPreparerKumo($m['ws']);
    config(['envoi.cle_empreinte' => '']);
    expect(fn () => app(FabriqueServeurKumo::class)($m['ws'], $m['domaine']))->toThrow(EnvoiRefuse::class, 'ENVOI_CLE_EMPREINTE');
    Http::assertNothingSent();
});

test('(4 ter) fabrique : aucune source active, ou deux sources actives ⇒ source_ambigue (échec fermé) ; une seule source active parmi une inactive ⇒ construit', function () {
    $m = biMontage();
    biPreparerKumo($m['ws']);
    $ws = $m['ws'];
    $f = app(FabriqueServeurKumo::class);
    WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->where('workspace_id', $ws)->update(['actif' => false]));
    expect(fn () => $f($ws, $m['domaine']))->toThrow(EnvoiRefuse::class, 'source_ambigue');
    WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->insert([
        ['workspace_id' => $ws, 'source' => 'srv2', 'url_base' => 'https://zz.invalid', 'actif' => true],
        ['workspace_id' => $ws, 'source' => 'srv3', 'url_base' => 'https://zz.invalid', 'actif' => true],
    ]));
    expect(fn () => $f($ws, $m['domaine']))->toThrow(EnvoiRefuse::class, 'source_ambigue');
    WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->where('workspace_id', $ws)->where('source', 'srv3')->update(['actif' => false]));
    expect($f($ws, $m['domaine']))->toBeInstanceOf(ServeurEnvoiKumo::class);
    Http::assertNothingSent();
});

test('(4 quater) fabrique : la source d\'un AUTRE espace n\'est jamais utilisée', function () {
    $m = biMontage();
    $autre = F::espace('zz-bi-autre');
    biPreparerKumo($autre); // source active seulement chez l'autre espace
    expect(fn () => app(FabriqueServeurKumo::class)($m['ws'], $m['domaine']))->toThrow(EnvoiRefuse::class, 'source_ambigue');
});

test('C3-5 (5) bout en bout sur le FAUX serveur : interrupteurs vrais DANS le test, porte ouverte, feu vert, DNS vert, passerelle simulée (202 signé) ⇒ id_injection du message = registre C1 = id posté ; puis retrait de cette adresse ⇒ POST retrait avec ce même id', function () {
    $m = biMontage();
    $ws = $m['ws'];
    $adresse = 'dest@client.example.test';
    $lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    $jour = $lundi->format('c');
    $pki = [];
    foreach (['ca', 'cert', 'cle'] as $n) {
        $pki[$n] = (string) tempnam(sys_get_temp_dir(), 'envoi-c35-' . $n);
        file_put_contents($pki[$n], "-----BEGIN FAKE-----\n-----END FAKE-----\n");
    }
    config([
        'crm.ingest.replay_store' => 'array', 'envoi.cle_empreinte' => str_repeat('c', 40), 'envoi.cle_desinscription' => str_repeat('k', 40),
        'envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'],
        'envoi.serveur.secrets' => ['srv1' => 'secret-de-test'], 'envoi.serveur.urls' => ['srv1' => 'https://envoi.example.test'],
        'envoi.serveur.ca_path' => $pki['ca'], 'envoi.serveur.client_cert_path' => $pki['cert'], 'envoi.serveur.client_key_path' => $pki['cle'],
        'envoi.serveur.tolere_transaction_englobante' => true, 'envoi.smtp_tolere_transaction_englobante' => true,
    ]);
    app()->instance(ResolveurHote::class, new class implements ResolveurHote
    {
        public function resoudre(string $hote): array
        {
            return ['93.184.216.34'];
        }
    });
    WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => 'srv1', 'url_base' => 'https://zz.invalid']));
    DB::table('envoi_domaines')->where('workspace_id', $ws)->where('nom', $m['domaine'])->update(['dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);
    DB::table('envoi_boites')->insert(['workspace_id' => $ws, 'adresse' => 'contact@' . $m['domaine'], 'hote' => 'imap.example.test', 'port' => 587, 'identifiants_chiffres' => 'x']);
    FonctionsEspace::regler($ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($ws, 'test-c3-5');
    app(DepotReglages::class)->enregistrerIdentite($ws, [
        'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
        'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
    ], 'test');
    $sid = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 2, 'delai_jours_ouvres' => 3, 'sujet' => 'Relance', 'corps' => 'Corps 2']);
    $e = F::fiche($ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $lundi->modify('-3 days')->format('c')]);
    F::lier($ws, $e, F::tag($ws, FichesProtegees::TAG_FEDERATIONS));
    $contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $ws, 'company_id' => $e, 'last_name' => 'Dupont-test', 'email' => $adresse, 'email_status' => 'valid',
        'created_at' => $lundi->modify('-3 days')->format('c'), 'first_info_at' => $lundi->modify('-3 days')->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($adresse), 'verdict' => 'valide', 'verifie_le' => $lundi->modify('-2 days')->format('c'), 'source' => 'test']);
    $ins = (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $sid, $contact, $lundi);

    // Interrupteurs forcés à vrai DANS ce test seulement ; la passerelle simulée répond 202 (injection) puis 200 (retrait), réponses signées.
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true]);
    Http::fake(function (Request $r) {
        $espace = $r->header('X-Envoi-Workspace')[0];
        $injection = str_ends_with($r->url(), '/v1/injections');
        $statut = $injection ? 202 : 200;
        $corps = json_encode(($injection ? ['id_injection' => json_decode($r->body(), true)['id_injection'], 'etat' => 'en_file'] : ['ok' => true]) + ['workspace_id' => $espace], JSON_THROW_ON_ERROR);
        $sig = SignatureEnvoi::signer('secret-de-test', SignatureEnvoi::chargeReponse($r->header('X-Envoi-Nonce')[0], $espace, $statut, $corps));

        return Http::response($corps, $statut, ['X-Envoi-Signature' => SignatureEnvoi::entete('1', $sig)]);
    });
    $aig = new AiguilleurServeurEnvoi(
        new FauxServeurEnvoi,
        ['kumo' => app(FabriqueServeurKumo::class)(...)],
        fn (): bool => app(PorteEnvoiReelle::class)->ouverte(),
        fn (): ListeFeuVert => app(ListeFeuVert::class),
    );
    expect(app(PorteEnvoiReelle::class)->ouverte())->toBeTrue();
    $passerelle = new PasserelleLocale($aig, new DepotSocleDb, true, 300, fn () => $lundi, $ws, 300, fn () => WorkspaceContext::current());
    $resultat = app(SourceExpeditionDb::class)->sequenceurPour($ws, $passerelle, new LecteurFicheDb)->executer($ws, $lundi);
    expect($resultat)->toBe([Sequenceur::ENVOYE => 1]);

    $posts = [];
    foreach (Http::recorded() as [$requete]) {
        $requete->method() === 'POST' && str_ends_with($requete->url(), '/v1/injections') && $posts[] = json_decode($requete->body(), true);
    }
    $message = DB::table('envoi_messages')->where('inscription_id', $ins)->first();
    $registre = DB::table('envoi_injections_locales')->where('workspace_id', $ws)->where('adaptateur', 'kumo')->value('id_injection');
    expect($posts)->toHaveCount(1)
        ->and($message->id_injection)->toBe($registre)->toBe($posts[0]['id_injection'])
        ->and($posts[0]['rcpt_to'])->toBe($adresse);

    // Retrait (C3-3) de cette adresse : la commande porte ce même identifiant.
    app(RetraitEnvoisEnFile::class)->retirerPour($ws, ListeSuppression::empreinte($adresse));
    expect(app(EnvoiRetraitsDemandes::class)->envoyer()['envoyees'])->toBe(1);
    $retraits = [];
    foreach (Http::recorded() as [$requete]) {
        $requete->method() === 'POST' && str_ends_with($requete->url(), '/v1/commandes/retrait') && $retraits[] = json_decode($requete->body(), true);
    }
    expect($retraits)->toHaveCount(1)->and($retraits[0]['cibles'])->toBe([$registre]);
});

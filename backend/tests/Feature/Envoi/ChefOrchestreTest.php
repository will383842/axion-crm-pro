<?php

/**
 * Envoi L8-ORCH — chef d'orchestre. Transport : FauxServeurEnvoi derrière une PasserelleLocale construite ICI (le test lie lui-même
 * `ServeurEnvoi` : il ne dépend pas de la liaison de production). Aucun réseau, aucun envoi réel, données fictives (`*.example.test`).
 */

use App\Crm\FichesProtegees;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Envoi\Orchestration\ChefOrchestre;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\PasserelleLocale;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sequence\ComposeurSequence;
use App\Envoi\Sequence\DemandeComposition;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ParametresExpedition;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Sequence\SourceExpeditionDb;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const ORCH_ADRESSE = 'dest@client.example.test';

/** Ouvre la porte DANS le test : drapeau actif et retrait / arrêt par entreprise branchés. */
function orchOuvrirPorte(): void
{
    config(['envoi.reel_actif' => true]);
    app()->instance(RetraitEnvoisEnFile::class, new class implements RetraitEnvoisEnFile
    {
        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            return 0;
        }
    });
    app()->instance(ArretParEntreprise::class, new class implements ArretParEntreprise
    {
        public function declencher(string $workspaceId, string $empreinteAdresse): void {}
    });
}

/** Espace actif avec le produit Envoi. */
function orchEspace(bool $produit = true): string
{
    $ws = F::espace('zz-orch');
    if ($produit) {
        DB::table('workspaces')->where('id', $ws)->update(['settings' => json_encode(['produits' => ['envoi' => 'actif']])]);
    }

    return $ws;
}

/**
 * Liste de feu vert simulée : décision par espace (`accorde`, `retire` ou absente) et points rouges par espace.
 *
 * @param  array<string, string>  $decisions
 * @param  array<string, list<string>>  $rouges
 */
function orchListe(array $decisions, array $rouges = []): ListeFeuVert
{
    return new class($decisions, $rouges) extends ListeFeuVert
    {
        /** @param array<string, string> $d @param array<string, list<string>> $r */
        public function __construct(private array $d, private array $r) {}

        public function derniereDecision(string $ws): ?array
        {
            return isset($this->d[$ws]) ? ['id' => 1, 'decision' => $this->d[$ws], 'motif' => 'test', 'le' => '2026-11-16T09:00:00Z'] : null;
        }

        public function points(string $ws): array
        {
            $points = [new PointFeuVert('auto.reel_actif_faux', 'x', true, false, '', 'x', 'g', 'a_faire')];
            foreach ($this->r[$ws] ?? [] as $code) {
                $points[] = new PointFeuVert($code, 'x', true, false, '', 'x', 'g', 'a_faire');
            }

            return $points;
        }
    };
}

/** Un Sequenceur sans aucune inscription : il ne fait rien, mais la fabrique est bien appelée. */
function orchSequenceurVide(): Sequenceur
{
    $composeur = new class implements ComposeurSequence
    {
        public function composer(DemandeComposition $demande): string
        {
            return '';
        }
    };

    return new Sequenceur(new FauxServeurEnvoi, new LecteurFicheDb, $composeur, new ParametresExpedition('zz.example.test', 'a@zz.example.test', 'ZZ'));
}

/** @return array{0: ChefOrchestre, 1: stdClass} le chef et un relevé (espaces vus par la fabrique, contexte au moment de l'appel) */
function orchChef(ListeFeuVert $liste, ?Closure $surFabrique = null, ?DepotSocle $depot = null, ?Closure $horloge = null): array
{
    $releve = new stdClass;
    $releve->appels = [];
    $releve->contextes = [];
    $chef = new ChefOrchestre(
        app(PorteEnvoiReelle::class),
        $depot ?? new DepotSocleDb,
        $liste,
        function (string $ws) use ($releve, $surFabrique): Sequenceur {
            $releve->appels[] = $ws;
            $releve->contextes[] = WorkspaceContext::current();
            if ($surFabrique !== null) {
                $surFabrique($ws);
            }

            return orchSequenceurVide();
        },
        $horloge,
    );

    return [$chef, $releve];
}

afterEach(function () {
    Cache::lock(ChefOrchestre::VERROU)->forceRelease();
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('ENVOI_REEL_ACTIF=false : le cycle sort sans appeler le séquenceur, sans périmer ni écrire d\'échéance', function () {
    $ws = orchEspace();
    $depot = Mockery::mock(DepotSocle::class);
    $depot->shouldNotReceive('perimerToutesLesReservations');
    $depot->shouldNotReceive('toutArreter');
    [$chef, $releve] = orchChef(orchListe([$ws => 'accorde']), null, $depot);
    $avant = DB::table('envoi_inscriptions')->count();

    $bilan = $chef->cycle();

    expect($bilan['porte_ouverte'])->toBeFalse()->and($releve->appels)->toBe([])->and(DB::table('envoi_inscriptions')->count())->toBe($avant);
});

test('la commande envoi:cycle, porte fermée, sort en succès sans rien faire', function () {
    orchEspace();

    expect(Artisan::call('envoi:cycle'))->toBe(0)->and(Artisan::output())->toContain('porte fermée');
});

test('reel_actif = true mais retrait des envois en file absent : rien n\'est fait (même prédicat que la passerelle)', function () {
    config(['envoi.reel_actif' => true]);
    // C3-3 lie le retrait réel par défaut : l'intention du test (« absent => porte fermée ») est posée explicitement.
    app()->bind(RetraitEnvoisEnFile::class, RetraitEnvoisEnFileAbsent::class);
    $ws = orchEspace();
    [$chef, $releve] = orchChef(orchListe([$ws => 'accorde']));

    expect(app(PorteEnvoiReelle::class)->ouverte())->toBeFalse()
        ->and($chef->cycle()['porte_ouverte'])->toBeFalse()
        ->and($releve->appels)->toBe([]);
});

test('porte ouverte : le prédicat du chef est celui de PorteEnvoiReelle', function () {
    orchOuvrirPorte();

    expect(app(PorteEnvoiReelle::class)->ouverte())->toBeTrue();
});

test('décision retirée, absente ou bloquant rouge : séquenceur NON appelé, une seule ligne de journal par jour', function (string $cas) {
    orchOuvrirPorte();
    $ws = orchEspace();
    $liste = match ($cas) {
        'retire' => orchListe([$ws => 'retire']),
        'absente' => orchListe([]),
        'rouge' => orchListe([$ws => 'accorde'], [$ws => ['auto.dns_pret']]),
    };
    [$chef, $releve] = orchChef($liste);
    Log::spy();

    $chef->cycle();
    $b = $chef->cycle();

    expect($releve->appels)->toBe([])->and($b['sautes'])->toBeGreaterThanOrEqual(1);
    Log::shouldHaveReceived('info')->withArgs(fn (string $m, array $c = []): bool => str_starts_with($m, 'envoi.cycle : espace sauté') && ($c['workspace_id'] ?? null) === $ws)->once();
})->with(['retire', 'absente', 'rouge']);

test('seul auto.reel_actif_faux rouge n\'empêche pas le traitement', function () {
    orchOuvrirPorte();
    $ws = orchEspace();
    [$chef, $releve] = orchChef(orchListe([$ws => 'accorde']));

    $chef->cycle();

    expect($releve->appels)->toBe([$ws]);
});

test('deux espaces : chacun traité sous son contexte avec sa propre fabrique ; sans produit Envoi, sous arrêt général ou à la corbeille : sauté', function () {
    orchOuvrirPorte();
    $a = orchEspace();
    $b = orchEspace();
    $sansProduit = orchEspace(false);
    $arret = orchEspace();
    $corbeille = orchEspace();
    DB::table('envoi_drapeaux')->updateOrInsert(['workspace_id' => $arret], ['tout_arreter' => true]);
    DB::table('workspaces')->where('id', $corbeille)->update(['deleted_at' => now()]);
    $decisions = [$a => 'accorde', $b => 'accorde', $sansProduit => 'accorde', $arret => 'accorde', $corbeille => 'accorde'];
    [$chef, $releve] = orchChef(orchListe($decisions));

    $bilan = $chef->cycle();

    $attendus = [$a, $b];
    sort($attendus);
    $vus = $releve->appels;
    sort($vus);
    expect($vus)->toBe($attendus)
        ->and($releve->contextes)->toBe($releve->appels)
        ->and($bilan['traites'])->toBe(2)
        ->and($bilan['sautes'])->toBeGreaterThanOrEqual(2)
        ->and(WorkspaceContext::current())->toBeNull();
});

test('une exception dans un espace n\'arrête pas les autres', function () {
    orchOuvrirPorte();
    $a = orchEspace();
    $b = orchEspace();
    $premier = min($a, $b);
    [$chef, $releve] = orchChef(orchListe([$a => 'accorde', $b => 'accorde']), function (string $ws) use ($premier): void {
        if ($ws === $premier) {
            throw new RuntimeException('panne simulée pour ' . ORCH_ADRESSE);
        }
    });
    Log::spy();

    $bilan = $chef->cycle();

    expect($releve->appels)->toHaveCount(2)->and($bilan['erreurs'])->toBe(1)->and($bilan['traites'])->toBe(1);
    // Le message de l'exception (qui peut contenir une adresse) n'est jamais journalisé.
    Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $c = []): bool => ! str_contains(json_encode($c), '@') && ! str_contains($m, '@'))->once();
});

test('deux exécutions simultanées : une seule travaille', function () {
    orchOuvrirPorte();
    $ws = orchEspace();
    $autre = Cache::lock(ChefOrchestre::VERROU, 60);
    expect($autre->get())->toBeTrue();
    [$chef, $releve] = orchChef(orchListe([$ws => 'accorde']));

    $bilan = $chef->cycle();

    expect($bilan['verrou_pris'] ?? false)->toBeTrue()->and($releve->appels)->toBe([]);
    $autre->release();
    expect($chef->cycle()['traites'])->toBe(1)->and($releve->appels)->toBe([$ws]);
});

test('le verrou est libéré même si le cycle échoue, et la borne de garde est inférieure à son expiration', function () {
    orchOuvrirPorte();
    $ws = orchEspace();
    [$chef] = orchChef(orchListe([$ws => 'accorde']), fn () => throw new RuntimeException('x'));

    $chef->cycle();

    expect(ChefOrchestre::BORNE_SECONDES)->toBeLessThan(ChefOrchestre::VERROU_SECONDES)
        ->and(Cache::lock(ChefOrchestre::VERROU, 5)->get())->toBeTrue();
});

test('un cycle ne dépasse jamais la borne de garde : aucun espace n\'est commencé au-delà', function () {
    orchOuvrirPorte();
    $a = orchEspace();
    $b = orchEspace();
    $t = 1_800_000_000;
    $horloge = function () use (&$t): DateTimeImmutable {
        $t += ChefOrchestre::BORNE_SECONDES;

        return (new DateTimeImmutable)->setTimestamp($t);
    };
    [$chef, $releve] = orchChef(orchListe([$a => 'accorde', $b => 'accorde']), null, null, $horloge);

    $bilan = $chef->cycle();

    expect($bilan['hors_borne'])->toBeGreaterThanOrEqual(1)->and(count($releve->appels))->toBeLessThan(2);
});

test('le bilan journalisé ne contient que des compteurs, aucune adresse', function () {
    orchOuvrirPorte();
    $ws = orchEspace();
    [$chef] = orchChef(orchListe([$ws => 'accorde']));
    Log::spy();

    $chef->cycle();

    Log::shouldHaveReceived('info')->withArgs(function (string $m, array $c = []): bool {
        return str_starts_with($m, 'envoi.cycle : bilan') && ! str_contains(json_encode($c), '@') && array_filter($c, 'is_string') === [];
    })->once();
});

test('un Sequenceur DISTINCT est construit par espace', function () {
    orchOuvrirPorte();
    $a = orchEspace();
    $b = orchEspace();
    $construits = [];
    $chef = new ChefOrchestre(app(PorteEnvoiReelle::class), new DepotSocleDb, orchListe([$a => 'accorde', $b => 'accorde']), function (string $ws) use (&$construits): Sequenceur {
        return $construits[] = orchSequenceurVide();
    });

    $chef->cycle();

    expect($construits)->toHaveCount(2)->and($construits[0])->not->toBe($construits[1]);
});

test('le conteneur fournit un chef d\'orchestre, jamais partagé', function () {
    expect(app(ChefOrchestre::class))->toBeInstanceOf(ChefOrchestre::class)->and(app(ChefOrchestre::class))->not->toBe(app(ChefOrchestre::class));
});

// ── Bout en bout : vraie fabrique (SourceExpeditionDb), vraies tables, faux serveur ──

/** Espace complet (identité, domaine, boîte, séquence, contact, inscription). */
function orchEspaceComplet(?string $flux = 'prospection', ?DateTimeImmutable $lundi = null): string
{
    $lundi ??= new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    $ws = F::espace('zz-orch-complet');
    DB::table('workspaces')->where('id', $ws)->update(['settings' => json_encode(['produits' => ['envoi' => 'actif']])]);
    config(['envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test']]);
    FonctionsEspace::regler($ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($ws, 'test-orch');
    WorkspaceContext::set($ws);
    app(DepotReglages::class)->enregistrerIdentite($ws, [
        'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
        'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
    ], 'test');
    $jour = $lundi->format('c');
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'zz-orch.example.test', 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour, 'flux' => $flux]);
    DB::table('envoi_boites')->insert(['workspace_id' => $ws, 'adresse' => 'contact@zz-orch.example.test', 'hote' => 'imap.example.test', 'port' => 587, 'identifiants_chiffres' => 'x']);
    $sid = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    $e = F::fiche($ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $lundi->modify('-3 days')->format('c')]);
    F::lier($ws, $e, F::tag($ws, FichesProtegees::TAG_FEDERATIONS));
    $contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $ws, 'company_id' => $e, 'last_name' => 'Dupont-test', 'email' => ORCH_ADRESSE, 'email_status' => 'valid',
        'created_at' => $lundi->modify('-3 days')->format('c'), 'first_info_at' => $lundi->modify('-3 days')->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte(ORCH_ADRESSE), 'verdict' => 'valide', 'verifie_le' => $lundi->modify('-2 days')->format('c'), 'source' => 'test']);
    (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $sid, $contact, $lundi);
    WorkspaceContext::clear();

    return $ws;
}

/** Chef avec la vraie fabrique (même construction que le fournisseur) et une passerelle de test sur le faux serveur. */
function orchChefReel(ListeFeuVert $liste, FauxServeurEnvoi $faux, DateTimeImmutable $lundi): ChefOrchestre
{
    $passerelle = new PasserelleLocale($faux, new DepotSocleDb, true, 300, fn () => $lundi, '', 300, fn () => WorkspaceContext::current());
    app()->instance(ServeurEnvoi::class, $passerelle);

    return new ChefOrchestre(app(PorteEnvoiReelle::class), new DepotSocleDb, $liste, fn (string $ws): Sequenceur => app(SourceExpeditionDb::class)->sequenceurPour($ws, $passerelle, new LecteurFicheDb, 'prospection'), fn () => $lundi);
}

test('bout en bout : un espace prêt envoie UN message au faux serveur et écrit le registre sous son contexte', function () {
    orchOuvrirPorte();
    $lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    $ws = orchEspaceComplet('prospection', $lundi);
    $faux = new FauxServeurEnvoi;

    $bilan = orchChefReel(orchListe([$ws => 'accorde']), $faux, $lundi)->cycle();

    expect($bilan['traites'])->toBe(1)->and($bilan['inscriptions'])->toBe(1)->and($faux->appels)->toBe(1);
    WorkspaceContext::run($ws, function () use ($ws): void {
        expect(DB::table('envoi_injections_locales')->where('workspace_id', $ws)->count())->toBe(1)
            ->and(DB::table('envoi_messages_envoyes')->where('workspace_id', $ws)->count())->toBe(1);
    });
});

test('domaine d\'expédition sans flux « prospection » (NULL ou lettre) : refus, rien n\'est injecté', function (?string $flux) {
    orchOuvrirPorte();
    $lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    $ws = orchEspaceComplet($flux, $lundi);
    $faux = new FauxServeurEnvoi;

    $bilan = orchChefReel(orchListe([$ws => 'accorde']), $faux, $lundi)->cycle();

    expect($bilan['erreurs'])->toBe(1)->and($faux->appels)->toBe(0);
})->with([null, 'lettre']);

// ── Sous le rôle applicatif de production (pgsql_app) : la RLS est réelle, les données sont posées par le propriétaire ──

test('sous pgsql_app : les espaces sont lus, le contexte est posé quand la fabrique s\'exécute et le registre local est inscriptible sous ce contexte', function () {
    orchOuvrirPorte();
    $proprio = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-orch-rls-' . substr($ws, 0, 8), 'name' => 'ZZ orch rls', 'settings' => json_encode(['produits' => ['envoi' => 'actif']]), 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $precedente = DB::getDefaultConnection();
    $ecrit = null;
    try {
        DB::setDefaultConnection('pgsql_app');
        $chef = new ChefOrchestre(app(PorteEnvoiReelle::class), new DepotSocleDb, orchListe([$ws => 'accorde']), function (string $espace) use (&$ecrit): Sequenceur {
            // Même table et même contexte que l'écriture du registre par le Sequenceur ; annulé aussitôt (ajout seul).
            DB::beginTransaction();
            try {
                $ecrit = DB::table('envoi_injections_locales')->insert(['workspace_id' => $espace, 'cle_idempotence' => 'orch-rls-test', 'adaptateur' => 'faux']);
            } finally {
                DB::rollBack();
            }

            return orchSequenceurVide();
        });

        $bilan = $chef->cycle();

        expect($bilan['erreurs'])->toBe(0)->and($ecrit)->toBeTrue()->and($bilan['traites'])->toBeGreaterThanOrEqual(1);
        // Hors contexte, le même rôle ne peut pas écrire dans ce registre : la garde du test n'est pas vide.
        expect(fn () => DB::table('envoi_injections_locales')->insert(['workspace_id' => $ws, 'cle_idempotence' => 'orch-rls-sans-contexte', 'adaptateur' => 'faux']))->toThrow(QueryException::class);
    } finally {
        DB::setDefaultConnection($precedente);
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::connection('pgsql_app')->disconnect();
        $proprio->table('workspaces')->where('id', $ws)->delete();
        $proprio->disconnect();
    }
});

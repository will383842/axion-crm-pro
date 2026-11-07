<?php

use App\Envoi\Chauffe\CourbeChauffe;
use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\Fournisseur;
use App\Envoi\Chauffe\Freins;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\Seuils;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Alertes\Alerte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => config(['envoi.cle_empreinte' => 'cle-de-test-seulement']));

function paris(string $quand): DateTimeImmutable
{
    return new DateTimeImmutable($quand, new DateTimeZone('Europe/Paris'));
}

/** Espace prêt à envoyer : mode témoins éteint, tableau validé. */
function espaceChauffe(bool $valide = true): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Chauffe', 'slug' => 'chauffe-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    Artisan::call('envoi:temoins-off', ['workspace' => $id, '--auteur' => 'test']);
    if ($valide) {
        validerTableauLe($id, '2026-11-10 08:00:00');
    }

    return $id;
}

/** Validation datée (le journal est en ajout seul : on l'écrit avec sa date, jamais on ne la corrige). */
function validerTableauLe(string $ws, string $quandParis): void
{
    App\Support\WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->insert([
        'workspace_id' => $ws, 'commande' => 'tableau_valide', 'auteur' => 'test', 'details' => '{}',
        'jour' => substr($quandParis, 0, 10), 'cree_le' => paris($quandParis)->format('Y-m-d H:i:sP'),
    ]));
}

function passerelleChauffe(FauxServeurEnvoi $faux, DateTimeImmutable &$maintenant, ?Alerte $alerte = null): PasserelleLocale
{
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), $alerte);

    return new PasserelleLocale($faux, app(DepotSocle::class), true, 300, function () use (&$maintenant) {
        return $maintenant;
    }, '', 300, null, $garde);
}

function messageChauffe(string $ws, string $dest = 'x@gmail.com', string $boite = 'boite1@example.test'): MessageAEnvoyer
{
    return new MessageAEnvoyer($ws, $dest, $boite, 'S', 'C', (string) Str::uuid());
}

// ── La courbe : une seule table de vérité ────────────────────────────────────

test('courbe : paliers P1 à P3, croissante, jamais au-dessus du plafond dur V0 de 300', function () {
    expect(CourbeChauffe::palier(1))->toBe('P1')
        ->and(CourbeChauffe::palier(14))->toBe('P1')
        ->and(CourbeChauffe::palier(15))->toBe('P2')
        ->and(CourbeChauffe::palier(28))->toBe('P2')
        ->and(CourbeChauffe::palier(29))->toBe('P3')
        ->and(CourbeChauffe::palier(500))->toBe('P3');
    $precedent = 0;
    foreach (range(1, 120) as $jour) {
        $q = CourbeChauffe::quotaBoite($jour);
        expect($q)->toBeGreaterThanOrEqual($precedent)->and($q)->toBeLessThanOrEqual(PasserelleLocale::PLAFOND_MAX);
        $precedent = $q;
    }
    expect(CourbeChauffe::quotaBoite(1))->toBe(5)->and(CourbeChauffe::quotaBoite(14))->toBe(20);
});

test('courbe : le quota d’un espace est borné à 300 par jour, quel que soit le nombre de boîtes', function () {
    expect(CourbeChauffe::quotaEspace(array_fill(0, 100, 60)))->toBe(300)
        ->and(CourbeChauffe::quotaEspace([1, 1]))->toBe(10);
});

test('courbe : le jour de chauffe change à MINUIT PARIS, pas à minuit UTC', function () {
    $debut = paris('2026-11-10 12:00:00');
    expect(CourbeChauffe::jourDeChauffe($debut, paris('2026-11-10 23:59:59')))->toBe(1)
        ->and(CourbeChauffe::jourDeChauffe($debut, paris('2026-11-11 00:00:00')))->toBe(2)
        // 23:30 UTC = 00:30 Paris (hiver) : déjà le lendemain.
        ->and(CourbeChauffe::jourDeChauffe($debut, new DateTimeImmutable('2026-11-10 23:30:00', new DateTimeZone('UTC'))))->toBe(2);
});

// ── Freins : bornes ──────────────────────────────────────────────────────────

test('frein humain : 9 et 10 réponses non traitées = normal, 14 = ralenti, 15 = pause', function () {
    expect(Freins::freinHumain(9))->toBe(Freins::NORMAL)
        ->and(Freins::freinHumain(10))->toBe(Freins::NORMAL)
        ->and(Freins::freinHumain(11))->toBe(Freins::RALENTI)
        ->and(Freins::freinHumain(14))->toBe(Freins::RALENTI)
        ->and(Freins::freinHumain(15))->toBe(Freins::PAUSE)
        ->and(Freins::freinHumain(40))->toBe(Freins::PAUSE);
});

test('frein de remplacement : sous la moitié de la moyenne des 3 fournées, 99 envois = rien, 100 envois = pause', function () {
    $precedentes = [['envoyes' => 100, 'reponses' => 10], ['envoyes' => 100, 'reponses' => 10], ['envoyes' => 100, 'reponses' => 10]]; // 10 %
    expect(Freins::reponsesEnBaisse('gmail', ['envoyes' => 99, 'reponses' => 0], $precedentes))->toBeNull()
        ->and(Freins::reponsesEnBaisse('gmail', ['envoyes' => 100, 'reponses' => 0], $precedentes)?->fournisseur)->toBe('gmail')
        // pile la moitié (5 %) : PAS sous la moitié
        ->and(Freins::reponsesEnBaisse('gmail', ['envoyes' => 100, 'reponses' => 5], $precedentes))->toBeNull()
        ->and(Freins::reponsesEnBaisse('gmail', ['envoyes' => 100, 'reponses' => 4], $precedentes))->not->toBeNull();
    // Moins de 100 envois comparés dans les 3 fournées précédentes : pas de comparaison.
    $petites = [['envoyes' => 33, 'reponses' => 10], ['envoyes' => 33, 'reponses' => 10], ['envoyes' => 33, 'reponses' => 10]];
    expect(Freins::reponsesEnBaisse('gmail', ['envoyes' => 500, 'reponses' => 0], $petites))->toBeNull();
    $assez = [['envoyes' => 34, 'reponses' => 10], ['envoyes' => 33, 'reponses' => 10], ['envoyes' => 33, 'reponses' => 10]];
    expect(Freins::reponsesEnBaisse('gmail', ['envoyes' => 500, 'reponses' => 0], $assez))->not->toBeNull()
        // seulement 2 fournées précédentes : on ne compare pas
        ->and(Freins::reponsesEnBaisse('gmail', ['envoyes' => 500, 'reponses' => 0], array_slice($assez, 0, 2)))->toBeNull();
});

test('rebonds durs et plaintes : seuil strict, échantillon minimum', function () {
    expect(Freins::rebondsDurs(49, 49))->toBeNull()
        ->and(Freins::rebondsDurs(100, 3))->toBeNull()            // 3 % pile : pas au-delà
        ->and(Freins::rebondsDurs(100, 4)?->motif)->toBe(DecisionFrein::REBONDS)
        ->and(Freins::plaintes(99, 99))->toBeNull()
        ->and(Freins::plaintes(1000, 1))->toBeNull()              // 0,1 % pile
        ->and(Freins::plaintes(1000, 2)?->motif)->toBe(DecisionFrein::PLAINTES);
});

test('homme mort : 6 jours passent, 7 pauses, jamais validé pauses ; la limite tombe à minuit Paris', function () {
    $valide = paris('2026-11-01 08:00:00');
    expect(Freins::hommeMort($valide, paris('2026-11-07 23:59:59')))->toBeNull()
        ->and(Freins::hommeMort($valide, paris('2026-11-08 00:00:00'))?->motif)->toBe(DecisionFrein::HOMME_MORT)
        ->and(Freins::hommeMort(null, paris('2026-11-01 08:00:00'))?->motif)->toBe(DecisionFrein::HOMME_MORT);
});

test('un réglage d’espace ne peut que RESSERRER un seuil', function () {
    expect(Seuils::resserre('plaintes_taux_max', 0.001, 0.5))->toBe(0.001)
        ->and(Seuils::resserre('plaintes_taux_max', 0.001, 0.0005))->toBe(0.0005)
        ->and(Seuils::resserre('homme_mort_jours', 7, 30))->toBe(7.0)
        ->and(Seuils::resserre('reponse_ratio_min', 0.5, 0.2))->toBe(0.5)
        ->and(Seuils::resserre('reponse_ratio_min', 0.5, 0.7))->toBe(0.7);
});

test('fournisseur du destinataire : Gmail, Microsoft, autre', function () {
    expect(Fournisseur::de('a@gmail.com'))->toBe('gmail')
        ->and(Fournisseur::de('A@Outlook.fr'))->toBe('microsoft')
        ->and(Fournisseur::de('a@societe.fr'))->toBe('autre');
});

// ── Branchement sur la passerelle (base réelle, faux serveur) ────────────────

test('indésirable chez un témoin Gmail : pause vers Gmail SEULEMENT', function () {
    $ws = espaceChauffe();
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 10:00:00');
    $p = passerelleChauffe($faux, $maintenant);
    expect(app(GardeChauffe::class)->indesirableChezTemoin($ws, 'temoin@gmail.com'))->toBeTrue();

    expect($p->injecter(messageChauffe($ws, 'x@gmail.com'))->accepte)->toBeFalse()
        ->and($faux->appels)->toBe(0)
        ->and($p->injecter(messageChauffe($ws, 'x@outlook.com'))->accepte)->toBeTrue()
        ->and($p->injecter(messageChauffe($ws, 'x@societe.fr'))->accepte)->toBeTrue()
        ->and($faux->appels)->toBe(2);
});

test('la pause d’un espace n’est pas vue par un autre espace (RLS)', function () {
    $a = espaceChauffe();
    $b = espaceChauffe();
    app(GardeChauffe::class)->indesirableChezTemoin($a, 'temoin@gmail.com');
    expect(app(DepotChauffe::class)->pausesActives($a))->toHaveCount(1)
        ->and(app(DepotChauffe::class)->pausesActives($b))->toHaveCount(0);
});

test('quota de chauffe : la boîte jour 1 envoie 5 messages, le 6e est reporté ; le lendemain à minuit Paris elle repart', function () {
    $ws = espaceChauffe();
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 23:59:50');
    $p = passerelleChauffe($faux, $maintenant);
    foreach (range(1, 5) as $i) {
        expect($p->injecter(messageChauffe($ws))->accepte)->toBeTrue();
    }
    $sixieme = $p->injecter(messageChauffe($ws));
    expect($sixieme->accepte)->toBeFalse()->and($faux->appels)->toBe(5);

    $maintenant = paris('2026-11-11 00:00:00'); // minuit Paris : nouveau jour, jour de chauffe 2
    expect($p->injecter(messageChauffe($ws))->accepte)->toBeTrue();
});

test('frein humain en base : 10 réponses = normal, 14 = quota réduit de moitié, 15 = pause', function () {
    $ws = espaceChauffe();
    $depot = app(DepotSocle::class);
    $maintenant = paris('2026-11-10 10:00:00');
    $envoyer = function (int $attendus) use ($ws, &$maintenant) {
        $faux = new FauxServeurEnvoi;
        $p = passerelleChauffe($faux, $maintenant);
        $acceptes = 0;
        foreach (range(1, 8) as $_) {
            $acceptes += $p->injecter(messageChauffe($ws, 'x@societe.fr', 'b-' . $attendus . '@example.test'))->accepte ? 1 : 0;
        }

        return $acceptes;
    };
    foreach (range(1, 10) as $_) {
        $depot->journaliser($ws, 'reponse_recue', 'test');
    }
    expect($envoyer(10))->toBe(5);                       // 10 : plein quota (5)
    foreach (range(1, 4) as $_) {
        $depot->journaliser($ws, 'reponse_recue', 'test');
    }
    expect(app(DepotChauffe::class)->reponsesEnAttente($ws))->toBe(14)
        ->and($envoyer(14))->toBe(2);                    // 14 : floor(5 × 0,5)
    $depot->journaliser($ws, 'reponse_recue', 'test');
    expect($envoyer(15))->toBe(0);                       // 15 : pause
    $depot->journaliser($ws, 'reponse_traitee', 'test');
    expect($envoyer(1415))->toBe(2);                     // retombé à 14 : ralenti, plus en pause
});

test('homme mort en base : jamais validé = pause ; validé = ça passe', function () {
    $ws = espaceChauffe(false);
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 10:00:00');
    $p = passerelleChauffe($faux, $maintenant);
    expect($p->injecter(messageChauffe($ws))->accepte)->toBeFalse()->and($faux->appels)->toBe(0);
    validerTableauLe($ws, '2026-11-10 09:00:00');
    expect($p->injecter(messageChauffe($ws))->accepte)->toBeTrue();
});

test('évaluation : rebonds trop nombreux ouvrent une pause globale, sans doublon, journalisée, avec alerte interne', function () {
    Log::spy();
    $ws = espaceChauffe();
    $maintenant = paris('2026-11-10 10:00:00');
    $depot = app(DepotChauffe::class);
    $depot->incrementer($ws, 'b@example.test', JourEnvoi_jour($maintenant), 'autre', 'envoyes', 100);
    $depot->incrementer($ws, 'b@example.test', JourEnvoi_jour($maintenant), 'autre', 'rebonds_durs', 4);
    $garde = new GardeChauffe($depot, app(DepotSocle::class), app(Alerte::class));
    $ouvertes = $garde->evaluer($ws, $maintenant);
    expect(array_map(fn ($d) => $d->motif, $ouvertes))->toBe([DecisionFrein::REBONDS])
        ->and($garde->evaluer($ws, $maintenant))->toBe([])
        ->and($depot->pausesActives($ws))->toHaveCount(1);
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains($m, 'Envoi en pause'))->once();
    expect(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'pause_auto')->count())->toBe(1);
});

function JourEnvoi_jour(DateTimeImmutable $t): DateTimeImmutable
{
    return App\Envoi\JourEnvoi::de($t);
}

test('évaluation : le taux de réponse Gmail en baisse met en pause Gmail seulement', function () {
    $ws = espaceChauffe();
    $depot = app(DepotChauffe::class);
    $maintenant = paris('2026-11-10 10:00:00');
    foreach (['2026-11-07', '2026-11-08', '2026-11-09'] as $j) {
        $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable($j), 'gmail', 'envoyes', 100);
        $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable($j), 'gmail', 'reponses', 10);
    }
    $depot->incrementer($ws, 'b@example.test', JourEnvoi_jour($maintenant), 'gmail', 'envoyes', 100);
    $depot->incrementer($ws, 'b@example.test', JourEnvoi_jour($maintenant), 'gmail', 'reponses', 2);
    $garde = new GardeChauffe($depot, app(DepotSocle::class), null);
    $ouvertes = $garde->evaluer($ws, $maintenant);
    expect(array_map(fn ($d) => [$d->motif, $d->fournisseur], $ouvertes))->toBe([[DecisionFrein::REPONSES_EN_BAISSE, 'gmail']]);
});

// ── Tout arrêter ─────────────────────────────────────────────────────────────

test('« Tout arrêter » : plus aucune connexion sortante, en bien moins d’une minute (test chronométré)', function () {
    $ws = espaceChauffe();
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 10:00:00');
    $p = passerelleChauffe($faux, $maintenant);
    expect($p->injecter(messageChauffe($ws, 'x@societe.fr'))->accepte)->toBeTrue()
        ->and($faux->appels)->toBe(1);

    $debut = microtime(true);
    Artisan::call('envoi:tout-arreter', ['workspace' => $ws, '--auteur' => 'will']);
    $refuse = 0;
    foreach (range(1, 20) as $_) {
        try {
            $p->injecter(messageChauffe($ws, 'x@societe.fr'));
        } catch (EnvoiRefuse) {
            $refuse++;
        }
    }
    $duree = microtime(true) - $debut;

    expect($duree)->toBeLessThan(60.0)
        ->and($refuse)->toBe(20)
        ->and($faux->appels)->toBe(1)                          // aucune connexion de plus
        ->and(app(DepotSocle::class)->toutArreter($ws))->toBeTrue()
        ->and(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'tout_arreter')->where('auteur', 'will')->count())->toBe(1)
        ->and($faux->etat()->enPause)->toBeTrue();
});

// ── Garde-fous de conception ─────────────────────────────────────────────────

test('aucune mesure d’ouverture et aucun effacement dans le dossier Chauffe', function () {
    foreach (glob(app_path('Envoi/Chauffe/*.php')) ?: [] as $f) {
        $code = '';
        foreach (token_get_all((string) file_get_contents($f)) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= is_array($t) ? $t[1] : $t;
            }
        }
        expect($code)->not->toMatch('/pixel|open_rate|taux_ouverture|->delete\(|DELETE FROM|TRUNCATE|DROP TABLE(?! IF EXISTS)/i');
    }
});

test('les tables L7 sont isolées par espace (RLS forcée) et sans DELETE pour le rôle applicatif', function () {
    foreach (['envoi_chauffe_jours', 'envoi_seuils', 'envoi_pauses'] as $t) {
        $r = DB::selectOne('SELECT relrowsecurity AS rls, relforcerowsecurity AS force FROM pg_class WHERE relname = ?', [$t]);
        expect($r->rls)->toBeTrue()->and($r->force)->toBeTrue();
    }
});

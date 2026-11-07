<?php

use App\Envoi\Chauffe\CourbeChauffe;
use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\CompteurRetours;
use App\Envoi\Chauffe\Fournisseur;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Chauffe\ResolveurMx;
use App\Envoi\Contrat\EvenementEnvoi;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Retours\AppliquerRetour;
use App\Support\WorkspaceContext;
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

beforeEach(function () {
    config(['envoi.cle_empreinte' => 'cle-de-test-seulement']);
    // AUCUN réseau en test : le résolveur de fournisseur est un faux en mémoire.
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire([
        'gmail.com' => 'gmail', 'outlook.com' => 'microsoft', 'outlook.fr' => 'microsoft', 'pro-google.fr' => 'gmail',
    ]));
});

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
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), $alerte);

    $p = new PasserelleLocale($faux, app(DepotSocle::class), true, 300, function () use (&$maintenant) {
        return $maintenant;
    }, '', 300, null, $garde);
    $p->sante(); // l'homme mort (INF-025) : un battement de sante() à l'heure courante

    return $p;
}

/** Jours d'envoi passés d'une boîte (compteurs), pour fabriquer des « jours effectifs ». */
function envoisPasses(string $ws, string $boite, array $jours, int $n = 10, string $fournisseur = 'autre'): void
{
    foreach ($jours as $j) {
        app(DepotChauffe::class)->incrementer($ws, $boite, new DateTimeImmutable($j), $fournisseur, 'envoyes', $n);
    }
}

function jourP(string $j): DateTimeImmutable
{
    return new DateTimeImmutable($j, new DateTimeZone('Europe/Paris'));
}

function messageChauffe(string $ws, string $dest = 'x@gmail.com', string $boite = 'boite1@example.test'): MessageAEnvoyer
{
    return new MessageAEnvoyer($ws, $dest, $boite, 'S', 'C', (string) Str::uuid());
}

// ── B1 : la courbe — jours EFFECTIFS, une seule table de vérité ─────────────

test('courbe : P1 jours 1-5 (10 par boîte, 20 à deux boîtes), P2 jours 6-10 (18 / 35), P3 jours 11-15 (25 / 50)', function () {
    foreach ([1, 5] as $j) {
        expect(CourbeChauffe::palier($j))->toBe('P1')->and(CourbeChauffe::quotaBoite($j))->toBe(10);
    }
    foreach ([6, 10] as $j) {
        expect(CourbeChauffe::palier($j))->toBe('P2')->and(CourbeChauffe::quotaBoite($j))->toBe(18);
    }
    foreach ([11, 15, 16, 500] as $j) {
        expect(CourbeChauffe::palier($j))->toBe('P3')->and(CourbeChauffe::quotaBoite($j))->toBe(25);
    }
    expect(2 * CourbeChauffe::quotaBoite(1))->toBe(20)
        ->and(CourbeChauffe::quotaBoite(6) + 17)->toBe(35)  // 35 au total à deux boîtes : 18 + 17
        ->and(2 * CourbeChauffe::quotaBoite(11))->toBe(50);
    $precedent = 0;
    foreach (range(1, 120) as $jour) {
        $q = CourbeChauffe::quotaBoite($jour);
        expect($q)->toBeGreaterThanOrEqual($precedent)->and($q)->toBeLessThanOrEqual(PasserelleLocale::PLAFOND_MAX);
        $precedent = $q;
    }
});

test('courbe : le total d’une boîte est le total de l’espace divisé — et le plafond dur de 300 reste hors de portée de la courbe', function () {
    expect(method_exists(CourbeChauffe::class, 'quotaEspace'))->toBeFalse()   // code mort retiré
        ->and(CourbeChauffe::quotaBoite(10_000))->toBeLessThan(PasserelleLocale::PLAFOND_MAX)
        ->and(PasserelleLocale::PLAFOND_MAX)->toBe(300);
});

test('jours EFFECTIFS : un jour sans envoi ne compte pas', function () {
    $envois = [jourP('2026-11-02'), jourP('2026-11-04'), jourP('2026-11-05')];
    expect(CourbeChauffe::jourDeChauffe([], jourP('2026-11-02')))->toBe(1)
        ->and(CourbeChauffe::jourDeChauffe($envois, jourP('2026-11-06')))->toBe(4)   // 3 jours effectifs avant, aujourd'hui = 4e
        ->and(CourbeChauffe::jourDeChauffe([jourP('2026-11-01'), jourP('2026-11-02'), jourP('2026-11-03'), jourP('2026-11-04'), jourP('2026-11-05')], jourP('2026-11-06')))->toBe(6);
});

test('jours EFFECTIFS : le jour de chauffe change à MINUIT PARIS, pas à minuit UTC', function () {
    $envois = [jourP('2026-11-10')];
    expect(CourbeChauffe::jourDeChauffe($envois, jourP('2026-11-10 23:59:59')))->toBe(1)
        ->and(CourbeChauffe::jourDeChauffe($envois, jourP('2026-11-11 00:00:00')))->toBe(2)
        // 23:30 UTC = 00:30 Paris (hiver) : déjà le lendemain.
        ->and(CourbeChauffe::jourDeChauffe($envois, new DateTimeImmutable('2026-11-10 23:30:00', new DateTimeZone('UTC'))))->toBe(2);
});

test('inactivité (26 § 4.3) : 6 jours de silence = rien ; 7 jours = recul d’UN palier ; en P1 on ne recule pas', function () {
    $douze = array_map(fn ($i) => jourP('2026-10-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)), range(1, 12)); // 12 jours effectifs : P3
    expect(CourbeChauffe::jourDeChauffe($douze, jourP('2026-10-13')))->toBe(13)            // P3
        ->and(CourbeChauffe::jourDeChauffe($douze, jourP('2026-10-18')))->toBe(13)         // 6 jours de silence : rien
        ->and(CourbeChauffe::palier(CourbeChauffe::jourDeChauffe($douze, jourP('2026-10-19'))))->toBe('P2')   // 7 jours : recul
        ->and(CourbeChauffe::jourDeChauffe($douze, jourP('2026-10-19')))->toBe(6)          // premier jour de P2
        ->and(CourbeChauffe::jourDeChauffe([jourP('2026-10-01'), jourP('2026-10-02')], jourP('2026-12-01')))->toBe(3)  // P1 : pas de recul
        ->and(CourbeChauffe::jourDeChauffe(array_slice($douze, 0, 8), jourP('2026-10-30')))->toBe(1);  // 8 jours (P2) puis silence : recul d'un palier = retour au premier jour de P1
});

// ── B2 : freins — fonctions pures, bornes ────────────────────────────────────

test('frein humain : 9 et 10 réponses non traitées = normal, 14 = ralenti, 15 = pause', function () {
    expect(Freins::freinHumain(9))->toBe(Freins::NORMAL)
        ->and(Freins::freinHumain(10))->toBe(Freins::NORMAL)
        ->and(Freins::freinHumain(11))->toBe(Freins::RALENTI)
        ->and(Freins::freinHumain(14))->toBe(Freins::RALENTI)
        ->and(Freins::freinHumain(15))->toBe(Freins::PAUSE)
        ->and(Freins::freinHumain(40))->toBe(Freins::PAUSE);
});

test('rebonds durs : > 2 % sur 7 jours glissants avec 100 envois minimum, OU 3 rebonds dans la journée (règle absolue)', function () {
    // taux : seuil strict, échantillon minimum
    expect(Freins::rebondsDurs(99, 99, 0))->toBeNull()                         // échantillon trop petit
        ->and(Freins::rebondsDurs(100, 2, 0))->toBeNull()                      // 2 % pile : pas au-delà
        ->and(Freins::rebondsDurs(100, 3, 0)?->motif)->toBe(DecisionFrein::REBONDS)
        ->and(Freins::rebondsDurs(1000, 20, 0))->toBeNull()                    // 2 % pile
        ->and(Freins::rebondsDurs(1000, 21, 0)?->motif)->toBe(DecisionFrein::REBONDS)
        // règle absolue : 3 dans la journée, même sans échantillon, même sous 2 %
        ->and(Freins::rebondsDurs(10, 3, 3)?->motif)->toBe(DecisionFrein::REBONDS)
        ->and(Freins::rebondsDurs(5000, 3, 3)?->motif)->toBe(DecisionFrein::REBONDS)
        ->and(Freins::rebondsDurs(5000, 2, 2))->toBeNull()                     // 2 dans la journée : rien
        ->and(Freins::rebondsDurs(5000, 100, 2))->toBeNull()                   // 2 % pile
        ->and(Freins::rebondsDurs(5000, 101, 2)?->motif)->toBe(DecisionFrein::REBONDS);
});

test('plaintes : 0 = rien, 1 = palier tenu + relecture, 2 en 7 jours = arrêt de 48 h', function () {
    expect(Freins::plaintes(0))->toBeNull()
        ->and(Freins::plaintes(1)?->motif)->toBe(DecisionFrein::PLAINTE_RELECTURE)
        ->and(Freins::plaintes(1)?->dureeHeures)->toBeNull()
        ->and(Freins::plaintes(2)?->motif)->toBe(DecisionFrein::PLAINTES_ARRET)
        ->and(Freins::plaintes(2)?->dureeHeures)->toBe(48)
        ->and(Freins::plaintes(9)?->motif)->toBe(DecisionFrein::PLAINTES_ARRET);
});

test('frein de remplacement : sous la moitié de la moyenne des 3 fournées, 99 envois = rien, 100 envois = pause', function () {
    $precedentes = [['envoyes' => 100, 'reponses' => 10], ['envoyes' => 100, 'reponses' => 10], ['envoyes' => 100, 'reponses' => 10]]; // 10 %
    expect(Freins::reponsesEnBaisse('gmail', ['envoyes' => 99, 'reponses' => 0], $precedentes))->toBeNull()
        ->and(Freins::reponsesEnBaisse('gmail', ['envoyes' => 100, 'reponses' => 0], $precedentes)?->fournisseur)->toBe('gmail')
        // pile la moitié (5 %) : PAS sous la moitié
        ->and(Freins::reponsesEnBaisse('gmail', ['envoyes' => 100, 'reponses' => 5], $precedentes))->toBeNull()
        ->and(Freins::reponsesEnBaisse('gmail', ['envoyes' => 100, 'reponses' => 4], $precedentes))->not->toBeNull();
    $petites = [['envoyes' => 33, 'reponses' => 10], ['envoyes' => 33, 'reponses' => 10], ['envoyes' => 33, 'reponses' => 10]];
    expect(Freins::reponsesEnBaisse('gmail', ['envoyes' => 500, 'reponses' => 0], $petites))->toBeNull();
    $assez = [['envoyes' => 34, 'reponses' => 10], ['envoyes' => 33, 'reponses' => 10], ['envoyes' => 33, 'reponses' => 10]];
    expect(Freins::reponsesEnBaisse('gmail', ['envoyes' => 500, 'reponses' => 0], $assez))->not->toBeNull()
        ->and(Freins::reponsesEnBaisse('gmail', ['envoyes' => 500, 'reponses' => 0], array_slice($assez, 0, 2)))->toBeNull();
});

test('tableau de bord non validé : 6 jours passent, 7 alertent (alerte seulement), jamais validé alerte ; limite à minuit Paris', function () {
    $valide = paris('2026-11-01 08:00:00');
    expect(Freins::hommeMort($valide, paris('2026-11-07 23:59:59')))->toBeNull()
        ->and(Freins::hommeMort($valide, paris('2026-11-08 00:00:00'))?->motif)->toBe(DecisionFrein::HOMME_MORT)
        ->and(Freins::hommeMort(null, paris('2026-11-01 08:00:00'))?->motif)->toBe(DecisionFrein::HOMME_MORT);
});

test('homme mort INF-025 (pur) : 14 min 59 = vivant, 15 min = mort, jamais de battement = mort', function () {
    $b = paris('2026-11-10 10:00:00');
    expect(Freins::santeSilencieuse($b, paris('2026-11-10 10:14:59')))->toBeFalse()
        ->and(Freins::santeSilencieuse($b, paris('2026-11-10 10:15:00')))->toBeTrue()
        ->and(Freins::santeSilencieuse(null, paris('2026-11-10 10:00:00')))->toBeTrue()
        ->and(Freins::dansFenetreEnvoi(paris('2026-11-10 07:59:59')))->toBeFalse()
        ->and(Freins::dansFenetreEnvoi(paris('2026-11-10 08:00:00')))->toBeTrue()
        ->and(Freins::dansFenetreEnvoi(paris('2026-11-10 18:59:59')))->toBeTrue()
        ->and(Freins::dansFenetreEnvoi(paris('2026-11-10 19:00:00')))->toBeFalse();
});

test('un réglage d’espace ne peut que RESSERRER un seuil', function () {
    expect(Seuils::resserre('rebonds_durs_taux_max', 0.02, 0.5))->toBe(0.02)
        ->and(Seuils::resserre('rebonds_durs_taux_max', 0.02, 0.01))->toBe(0.01)
        ->and(Seuils::resserre('homme_mort_jours', 7, 30))->toBe(7.0)
        ->and(Seuils::resserre('reponse_ratio_min', 0.5, 0.2))->toBe(0.5)
        ->and(Seuils::resserre('reponse_ratio_min', 0.5, 0.7))->toBe(0.7);
});

// ── B4 : fournisseur par MX, jamais par le découpage du domaine ──────────────

test('résolveur en mémoire : domaine d’entreprise hébergé chez Google = gmail ; inconnu = autre ; casse ignorée', function () {
    $r = app(ResolveurFournisseur::class);
    expect($r->fournisseurDe('a@pro-google.fr'))->toBe('gmail')
        ->and($r->fournisseurDe('A@Outlook.fr'))->toBe('microsoft')
        ->and($r->fournisseurDe('a@societe.fr'))->toBe('autre');
});

test('résolveur MX : lecture MX → famille, cache en table (un seul appel DNS), échec DNS = autre sans mise en cache', function () {
    $appels = 0;
    $mx = ['google-hote.fr' => ['aspmx.l.google.com', 'alt1.aspmx.l.google.com'], 'ms-hote.fr' => ['x-fr.mail.protection.outlook.com'], 'ovh-hote.fr' => ['mx1.mail.ovh.net']];
    $r = new ResolveurMx(function (string $domaine) use (&$appels, $mx): array {
        $appels++;
        if ($domaine === 'panne.fr') {
            throw new RuntimeException('dns indisponible');
        }

        return $mx[$domaine] ?? [];
    });
    expect($r->fournisseurDe('a@google-hote.fr'))->toBe('gmail')
        ->and($r->fournisseurDe('b@GOOGLE-hote.fr'))->toBe('gmail')   // cache : pas de 2e appel
        ->and($appels)->toBe(1)
        ->and($r->fournisseurDe('a@ms-hote.fr'))->toBe('microsoft')
        ->and($r->fournisseurDe('a@ovh-hote.fr'))->toBe('autre')
        ->and($r->fournisseurDe('a@gmail.com'))->toBe('gmail')        // domaines publics : zéro DNS
        ->and($appels)->toBe(3)
        ->and($r->fournisseurDe('a@panne.fr'))->toBe('autre')
        ->and($r->fournisseurDe('a@panne.fr'))->toBe('autre')
        ->and($appels)->toBe(5)                                       // l'échec n'est PAS mis en cache
        ->and(DB::table('envoi_fournisseur_domaines')->where('domaine', 'google-hote.fr')->value('fournisseur'))->toBe('gmail')
        ->and(DB::table('envoi_fournisseur_domaines')->where('domaine', 'panne.fr')->exists())->toBeFalse();
    // un nouveau résolveur relit la table, sans DNS
    $appels = 0;
    expect((new ResolveurMx(function () use (&$appels): array { $appels++; return []; }))->fournisseurDe('z@ms-hote.fr'))->toBe('microsoft')
        ->and($appels)->toBe(0);
});

test('AUCUN réseau en test : le résolveur MX par défaut refuse de sortir', function () {
    expect(fn () => (new ResolveurMx)->fournisseurDe('a@societe-inconnue.fr'))->toThrow(LogicException::class);
});

test('Fournisseur::de (découpage du domaine) n’existe plus : tout passe par le résolveur', function () {
    expect(method_exists(Fournisseur::class, 'de'))->toBeFalse();
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

test('envoi:temoin-indesirable donne un appelant à indesirableChezTemoin (commande locale, journalisée)', function () {
    $ws = espaceChauffe();
    expect(Artisan::call('envoi:temoin-indesirable', ['workspace' => $ws, 'adresse' => 'temoin@pro-google.fr', '--auteur' => 'will']))->toBe(0);
    $pauses = app(DepotChauffe::class)->pausesActives($ws, paris('2026-11-10 10:00:00'));
    expect($pauses)->toHaveCount(1)->and($pauses[0]->fournisseur)->toBe('gmail')->and($pauses[0]->motif)->toBe(DecisionFrein::INDESIRABLE_TEMOIN)
        ->and(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'pause_auto')->count())->toBe(1);
});

test('la pause d’un espace n’est pas vue par un autre espace (RLS)', function () {
    $a = espaceChauffe();
    $b = espaceChauffe();
    app(GardeChauffe::class)->indesirableChezTemoin($a, 'temoin@gmail.com');
    expect(app(DepotChauffe::class)->pausesActives($a, paris('2026-11-10 10:00:00')))->toHaveCount(1)
        ->and(app(DepotChauffe::class)->pausesActives($b, paris('2026-11-10 10:00:00')))->toHaveCount(0);
});

test('quota de chauffe : la boîte jour 1 envoie 10 messages, le 11e est reporté ; à minuit Paris elle repart (jour effectif 2)', function () {
    $ws = espaceChauffe();
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 23:59:50');
    $p = passerelleChauffe($faux, $maintenant);
    foreach (range(1, 10) as $i) {
        expect($p->injecter(messageChauffe($ws))->accepte)->toBeTrue();
    }
    expect($p->injecter(messageChauffe($ws))->accepte)->toBeFalse()->and($faux->appels)->toBe(10);

    $maintenant = paris('2026-11-11 00:00:00'); // minuit Paris : nouveau jour, jour de chauffe 2
    expect($p->injecter(messageChauffe($ws))->accepte)->toBeTrue();
});

test('chauffe en base : jours effectifs, passage P1 → P2 au 6e jour d’envoi (et pas au 6e jour civil)', function () {
    $ws = espaceChauffe();
    // 5 jours d'envoi, avec un trou de 2 jours (week-end) : le 6e jour effectif est le 12 novembre.
    envoisPasses($ws, 'boite1@example.test', ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-09']);
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 10:00:00'); // 6e jour effectif => P2 : 18
    $p = passerelleChauffe($faux, $maintenant);
    $acceptes = 0;
    foreach (range(1, 20) as $_) {
        $acceptes += $p->injecter(messageChauffe($ws, 'x@societe.fr'))->accepte ? 1 : 0;
    }
    expect($acceptes)->toBe(18);
});

test('chauffe en base : l’adresse expéditeur est normalisée en minuscules pour les quotas', function () {
    $ws = espaceChauffe();
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 10:00:00');
    $p = passerelleChauffe($faux, $maintenant);
    $acceptes = 0;
    foreach (range(1, 14) as $i) {
        $boite = $i % 2 === 0 ? 'Boite1@Example.TEST' : ' boite1@example.test ';
        $acceptes += $p->injecter(messageChauffe($ws, 'x@societe.fr', $boite))->accepte ? 1 : 0;
    }
    expect($acceptes)->toBe(10)
        ->and(DB::table('envoi_chauffe_jours')->where('workspace_id', $ws)->pluck('boite')->unique()->values()->all())->toBe(['boite1@example.test']);
});

test('frein humain en base : 10 réponses = normal, 14 = quota réduit de moitié, 15 = pause', function () {
    $ws = espaceChauffe();
    $depot = app(DepotSocle::class);
    $maintenant = paris('2026-11-10 10:00:00');
    $envoyer = function (string $boite) use ($ws, &$maintenant) {
        $faux = new FauxServeurEnvoi;
        $p = passerelleChauffe($faux, $maintenant);
        $acceptes = 0;
        foreach (range(1, 12) as $_) {
            $acceptes += $p->injecter(messageChauffe($ws, 'x@societe.fr', $boite))->accepte ? 1 : 0;
        }

        return $acceptes;
    };
    foreach (range(1, 10) as $_) {
        $depot->journaliser($ws, 'reponse_recue', 'test');
    }
    expect($envoyer('b-10@example.test'))->toBe(10);       // 10 : plein quota (10)
    foreach (range(1, 4) as $_) {
        $depot->journaliser($ws, 'reponse_recue', 'test');
    }
    expect(app(DepotChauffe::class)->reponsesEnAttente($ws))->toBe(14)
        ->and($envoyer('b-14@example.test'))->toBe(5);     // 14 : floor(10 × 0,5)
    $depot->journaliser($ws, 'reponse_recue', 'test');
    expect($envoyer('b-15@example.test'))->toBe(0);        // 15 : pause
    $depot->journaliser($ws, 'reponse_traitee', 'test');
    expect($envoyer('b-1415@example.test'))->toBe(5);      // retombé à 14 : ralenti, plus en pause
});

// ── B6 : homme mort (INF-025) ────────────────────────────────────────────────

test('homme mort : sans appel à sante() depuis 15 min en fenêtre d’envoi, toutes les files passent en pause', function () {
    $a = espaceChauffe();
    $b = espaceChauffe();
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 10:00:00');
    $p = passerelleChauffe($faux, $maintenant);                        // battement à 10:00
    $maintenant = paris('2026-11-10 10:14:59');
    expect($p->injecter(messageChauffe($a))->accepte)->toBeTrue();
    $maintenant = paris('2026-11-10 10:15:00');
    expect($p->injecter(messageChauffe($a))->accepte)->toBeFalse()      // les DEUX espaces : toutes les files
        ->and($p->injecter(messageChauffe($b))->accepte)->toBeFalse()
        ->and($faux->appels)->toBe(1);
    $p->sante();                                                        // le battement reprend
    expect($p->injecter(messageChauffe($b))->accepte)->toBeTrue();
});

test('homme mort : jamais de battement = pause en fenêtre d’envoi, mais pas hors fenêtre', function () {
    $ws = espaceChauffe();
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 10:00:00');
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null);
    $p = new PasserelleLocale($faux, app(DepotSocle::class), true, 300, fn () => $maintenant, '', 300, null, $garde);   // aucun sante()
    expect($p->injecter(messageChauffe($ws))->accepte)->toBeFalse()->and($faux->appels)->toBe(0);
    $maintenant = paris('2026-11-10 22:00:00');
    expect($p->injecter(messageChauffe($ws))->accepte)->toBeTrue();
});

test('envoi:sante appelle sante() (battement du planificateur) et envoi:evaluer-freins / envoi:sante sont planifiés', function () {
    $console = (string) file_get_contents(base_path('routes/console.php'));
    expect($console)->toContain("Schedule::command('envoi:evaluer-freins')")
        ->and($console)->toContain("Schedule::command('envoi:sante')");
});

test('tableau de bord non validé depuis 7 jours : ALERTE seulement, l’envoi n’est pas arrêté', function () {
    Log::spy();
    $ws = espaceChauffe(false);
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 10:00:00');
    $p = passerelleChauffe($faux, $maintenant);
    expect($p->injecter(messageChauffe($ws))->accepte)->toBeTrue();      // jamais validé : ça part quand même
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), app(Alerte::class));
    expect($garde->evaluer($ws, $maintenant))->toBe([])             // aucune pause ouverte
        ->and(app(DepotChauffe::class)->pausesActives($ws, $maintenant))->toHaveCount(0);
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains($m, 'Tableau de bord'))->once();
    $garde->evaluer($ws, $maintenant);                              // une seule alerte par jour
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains($m, 'Tableau de bord'))->once();
});

// ── B2 en base : rebonds, plaintes, remplacement ─────────────────────────────

test('évaluation : 3 rebonds dans la journée ouvrent une pause globale (règle absolue), sans doublon, journalisée, alertée', function () {
    Log::spy();
    $ws = espaceChauffe();
    $maintenant = paris('2026-11-10 10:00:00');
    $depot = app(DepotChauffe::class);
    $depot->incrementer($ws, 'b@example.test', JourEnvoi_jour($maintenant), 'autre', 'envoyes', 400);
    $depot->incrementer($ws, 'b@example.test', JourEnvoi_jour($maintenant), 'autre', 'rebonds_durs', 3);
    $garde = new GardeChauffe($depot, app(DepotSocle::class), app(ResolveurFournisseur::class), app(Alerte::class));
    $ouvertes = $garde->evaluer($ws, $maintenant);
    expect(array_map(fn ($d) => $d->motif, $ouvertes))->toBe([DecisionFrein::REBONDS])
        ->and($garde->evaluer($ws, $maintenant))->toBe([])
        ->and($depot->pausesActives($ws, $maintenant))->toHaveCount(1)
        ->and($depot->pausesActives($ws, $maintenant)[0]->portee)->toBe('global');
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains($m, 'Envoi en pause'))->once();
    expect(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'pause_auto')->count())->toBe(1);
});

test('évaluation : le taux de rebonds se mesure sur 7 jours GLISSANTS (le 8e jour sort de la fenêtre)', function () {
    $ws = espaceChauffe();
    $depot = app(DepotChauffe::class);
    $maintenant = paris('2026-11-10 10:00:00');
    // 3 rebonds répartis (jamais 3 dans une même journée) sur 100 envois, sur les 7 jours se terminant le 10 : 3 % > 2 %.
    foreach (['2026-11-04' => 1, '2026-11-06' => 1, '2026-11-10' => 1] as $j => $r) {
        $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable($j), 'autre', 'envoyes', 34);
        $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable($j), 'autre', 'rebonds_durs', $r);
    }
    $garde = new GardeChauffe($depot, app(DepotSocle::class), app(ResolveurFournisseur::class), null);
    expect(array_map(fn ($d) => $d->motif, $garde->evaluer($ws, $maintenant)))->toBe([DecisionFrein::REBONDS]);
    // Un lendemain : le 4 novembre sort de la fenêtre → 2 rebonds sur 68 envois, sous l'échantillon minimum, rien de neuf.
    $ws2 = espaceChauffe();
    foreach (['2026-11-03' => 1, '2026-11-06' => 1, '2026-11-10' => 1] as $j => $r) {
        $depot->incrementer($ws2, 'b@example.test', new DateTimeImmutable($j), 'autre', 'envoyes', 34);
        $depot->incrementer($ws2, 'b@example.test', new DateTimeImmutable($j), 'autre', 'rebonds_durs', $r);
    }
    expect($garde->evaluer($ws2, $maintenant))->toBe([]);   // le 3 novembre est hors des 7 jours glissants
});

test('évaluation : 1 plainte = palier tenu + alerte de relecture, AUCUNE pause', function () {
    Log::spy();
    $ws = espaceChauffe();
    $depot = app(DepotChauffe::class);
    envoisPasses($ws, 'boite1@example.test', ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06']);
    $depot->incrementer($ws, 'boite1@example.test', new DateTimeImmutable('2026-11-06'), 'autre', 'plaintes', 1);
    $maintenant = paris('2026-11-09 10:00:00');   // 6e jour effectif : P2 normalement
    $garde = new GardeChauffe($depot, app(DepotSocle::class), app(ResolveurFournisseur::class), app(Alerte::class));
    expect($garde->evaluer($ws, $maintenant))->toBe([])
        ->and($depot->pausesActives($ws, $maintenant))->toHaveCount(0);
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains($m, 'relecture'))->once();
    // Palier tenu : la boîte reste à P1 (10), pas P2 (18).
    $faux = new FauxServeurEnvoi;
    $p = passerelleChauffe($faux, $maintenant);
    $acceptes = 0;
    foreach (range(1, 20) as $_) {
        $acceptes += $p->injecter(messageChauffe($ws, 'x@societe.fr'))->accepte ? 1 : 0;
    }
    expect($acceptes)->toBe(10);
    // Sorti des 7 jours glissants (le 14 : la plainte du 6 est partie), le palier se libère.
    $maintenant = paris('2026-11-14 10:00:00');
    $p = passerelleChauffe($faux, $maintenant);
    $acceptes = 0;
    foreach (range(1, 30) as $_) {
        $acceptes += $p->injecter(messageChauffe($ws, 'x@societe.fr'))->accepte ? 1 : 0;
    }
    expect($acceptes)->toBe(18);
});

test('évaluation : 2 plaintes en 7 jours = arrêt GLOBAL de 48 h, puis la pause expire d’elle-même (sans nouvelle pause pour les mêmes plaintes)', function () {
    $ws = espaceChauffe();
    $depot = app(DepotChauffe::class);
    $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable('2026-11-08'), 'autre', 'envoyes', 50);
    $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable('2026-11-08'), 'autre', 'plaintes', 1);
    $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable('2026-11-10'), 'gmail', 'plaintes', 1);
    $maintenant = paris('2026-11-10 10:00:00');
    $garde = new GardeChauffe($depot, app(DepotSocle::class), app(ResolveurFournisseur::class), null);
    $ouvertes = $garde->evaluer($ws, $maintenant);
    expect(array_map(fn ($d) => $d->motif, $ouvertes))->toBe([DecisionFrein::PLAINTES_ARRET]);

    $faux = new FauxServeurEnvoi;
    $m = paris('2026-11-12 09:59:59');                                   // 47 h 59 min 59 s plus tard
    $p = passerelleChauffe($faux, $m);
    expect($p->injecter(messageChauffe($ws, 'x@societe.fr'))->accepte)->toBeFalse()->and($faux->appels)->toBe(0);
    $m = paris('2026-11-12 10:00:00');                                   // 48 h pile : expirée
    $p->sante();
    expect($p->injecter(messageChauffe($ws, 'x@societe.fr'))->accepte)->toBeTrue();
    expect($garde->evaluer($ws, $m))->toBe([]);                          // mêmes plaintes : pas de nouvel arrêt
    // une NOUVELLE paire de plaintes, après l'arrêt, en rouvre un
    $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable('2026-11-12'), 'autre', 'plaintes', 2);
    expect(array_map(fn ($d) => $d->motif, $garde->evaluer($ws, $m)))->toBe([DecisionFrein::PLAINTES_ARRET]);
});

function JourEnvoi_jour(DateTimeImmutable $t): DateTimeImmutable
{
    return App\Envoi\JourEnvoi::de($t);
}

test('évaluation : le taux de réponse en baisse met en pause le fournisseur concerné, pour gmail, microsoft ET autre', function () {
    foreach (['gmail', 'microsoft', 'autre'] as $f) {
        $ws = espaceChauffe();
        $depot = app(DepotChauffe::class);
        $maintenant = paris('2026-11-10 10:00:00');
        foreach (['2026-11-07', '2026-11-08', '2026-11-09'] as $j) {
            $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable($j), $f, 'envoyes', 100);
            $depot->incrementer($ws, 'b@example.test', new DateTimeImmutable($j), $f, 'reponses', 10);
        }
        $depot->incrementer($ws, 'b@example.test', JourEnvoi_jour($maintenant), $f, 'envoyes', 100);
        $depot->incrementer($ws, 'b@example.test', JourEnvoi_jour($maintenant), $f, 'reponses', 2);
        $garde = new GardeChauffe($depot, app(DepotSocle::class), app(ResolveurFournisseur::class), null);
        $ouvertes = $garde->evaluer($ws, $maintenant);
        expect(array_map(fn ($d) => [$d->motif, $d->fournisseur], $ouvertes))->toBe([[DecisionFrein::REPONSES_EN_BAISSE, $f]]);
    }
});

test('un domaine d’entreprise hébergé chez Google (MX) est compté et freiné comme gmail', function () {
    $ws = espaceChauffe();
    $faux = new FauxServeurEnvoi;
    $maintenant = paris('2026-11-10 10:00:00');
    $p = passerelleChauffe($faux, $maintenant);
    expect($p->injecter(messageChauffe($ws, 'a@pro-google.fr'))->accepte)->toBeTrue()
        ->and(DB::table('envoi_chauffe_jours')->where('workspace_id', $ws)->value('fournisseur'))->toBe('gmail');
});

// ── B3 : les compteurs de retours s'écrivent réellement ──────────────────────

test('AppliquerRetour est branché sur CompteurRetours (sans dépendre du lot Retours)', function () {
    expect(app(AppliquerRetour::class))->toBeInstanceOf(CompteurRetours::class);
});

test('retours : rebond dur, plainte et réponse écrivent les compteurs du bon fournisseur ; un rejeu ne compte pas deux fois', function () {
    $ws = espaceChauffe();
    $maintenant = paris('2026-11-10 10:00:00');
    $c = new CompteurRetours(app(DepotChauffe::class), app(ResolveurFournisseur::class), fn () => $maintenant);
    $evenements = [
        new EvenementEnvoi('ev-1', 'rebond_dur', 'i1', 'a@gmail.com'),
        new EvenementEnvoi('ev-2', 'plainte', 'i2', 'a@societe.fr'),
        new EvenementEnvoi('ev-3', 'reponse', 'i3', 'a@outlook.com'),
        new EvenementEnvoi('ev-4', 'livre', 'i4', 'a@gmail.com'),          // inerte
        new EvenementEnvoi('ev-5', 'ouverture', 'i5', 'a@gmail.com'),      // jamais mesuré
    ];
    foreach ([...$evenements, ...$evenements] as $e) {                      // deux passages : rejeu
        $c->appliquer($ws, $e);
    }
    $depot = app(DepotChauffe::class);
    $jour = JourEnvoi_jour($maintenant);
    expect($depot->totauxDuJour($ws, $jour, 'gmail'))->toBe(['envoyes' => 0, 'rebonds_durs' => 1, 'plaintes' => 0, 'reponses' => 0])
        ->and($depot->totauxDuJour($ws, $jour, 'autre'))->toBe(['envoyes' => 0, 'rebonds_durs' => 0, 'plaintes' => 1, 'reponses' => 0])
        ->and($depot->totauxDuJour($ws, $jour, 'microsoft'))->toBe(['envoyes' => 0, 'rebonds_durs' => 0, 'plaintes' => 0, 'reponses' => 1]);
});

test('retours → freins : trois rebonds durs reçus dans la journée suffisent à mettre l’envoi en pause', function () {
    $ws = espaceChauffe();
    $maintenant = paris('2026-11-10 10:00:00');
    $c = new CompteurRetours(app(DepotChauffe::class), app(ResolveurFournisseur::class), fn () => $maintenant);
    foreach (range(1, 3) as $i) {
        $c->appliquer($ws, new EvenementEnvoi('rb-' . $i, 'rebond_dur', 'i' . $i, 'x' . $i . '@societe.fr'));
    }
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null);
    expect(array_map(fn ($d) => $d->motif, $garde->evaluer($ws, $maintenant)))->toBe([DecisionFrein::REBONDS]);
});

test('envoi:evaluer-freins sans argument évalue tous les espaces', function () {
    $a = espaceChauffe();
    $b = espaceChauffe();
    $depot = app(DepotChauffe::class);
    foreach ([$a, $b] as $ws) {
        $depot->incrementer($ws, 'b@example.test', JourEnvoi_jour(new DateTimeImmutable('now')), 'autre', 'rebonds_durs', 3);
    }
    expect(Artisan::call('envoi:evaluer-freins'))->toBe(0);
    foreach ([$a, $b] as $ws) {
        expect($depot->pausesActives($ws, new DateTimeImmutable('now')))->toHaveCount(1);
    }
});

// ── B5 : Tout arrêter — le MÊME singleton que la commande ────────────────────

test('« Tout arrêter » : après la commande, plus AUCUNE injection acceptée (singleton de l’appli, test chronométré < 1 min)', function () {
    config(['envoi.reel_actif' => true, 'envoi.plafond_jour' => 300, 'envoi.plafond_global_jour' => 300]);
    $ws = espaceChauffe();
    WorkspaceContext::set($ws);
    $faux = new FauxServeurEnvoi;
    app()->instance(FauxServeurEnvoi::class, $faux);      // le transport derrière le singleton du fournisseur de services
    $serveur = app(ServeurEnvoi::class);                  // LE MÊME singleton que celui que la commande résout
    expect($serveur)->toBe(app(ServeurEnvoi::class))->and($serveur)->toBeInstanceOf(PasserelleLocale::class);
    $serveur->sante();
    expect($serveur->injecter(messageChauffe($ws, 'x@societe.fr'))->accepte)->toBeTrue()
        ->and($faux->appels)->toBe(1);

    $debut = microtime(true);
    Artisan::call('envoi:tout-arreter', ['workspace' => $ws, '--auteur' => 'will']);
    $refuse = 0;
    foreach (range(1, 20) as $_) {
        try {
            $serveur->injecter(messageChauffe($ws, 'x@societe.fr'));
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
        ->and($serveur->etat()->enPause)->toBeTrue()            // ceinture : le serveur lui-même est en pause
        ->and($faux->etat()->enPause)->toBeTrue();
});

test('« Tout arrêter » est PAR ESPACE (drapeau en base) ; la pause du serveur, elle, est globale par contrat', function () {
    $a = espaceChauffe();
    $b = espaceChauffe();
    Artisan::call('envoi:tout-arreter', ['workspace' => $a, '--auteur' => 'will']);
    expect(app(DepotSocle::class)->toutArreter($a))->toBeTrue()
        ->and(app(DepotSocle::class)->toutArreter($b))->toBeFalse();
});

test('envoi:reprendre : commande locale journalisée, désarme le drapeau, relance le serveur ; refus si rien n’est armé', function () {
    config(['envoi.reel_actif' => true, 'envoi.plafond_jour' => 300, 'envoi.plafond_global_jour' => 300]);
    $ws = espaceChauffe();
    WorkspaceContext::set($ws);
    $faux = new FauxServeurEnvoi;
    app()->instance(FauxServeurEnvoi::class, $faux);
    $serveur = app(ServeurEnvoi::class);
    $serveur->sante();
    expect(Artisan::call('envoi:reprendre', ['workspace' => $ws, '--auteur' => 'will']))->toBe(1);   // rien d'armé
    Artisan::call('envoi:tout-arreter', ['workspace' => $ws, '--auteur' => 'will']);
    expect(fn () => $serveur->injecter(messageChauffe($ws, 'x@societe.fr')))->toThrow(EnvoiRefuse::class);

    expect(Artisan::call('envoi:reprendre', ['workspace' => $ws, '--auteur' => 'marie']))->toBe(0)
        ->and(app(DepotSocle::class)->toutArreter($ws))->toBeFalse()
        ->and($faux->etat()->enPause)->toBeFalse()
        ->and(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'reprise')->where('auteur', 'marie')->count())->toBe(1)
        ->and($serveur->injecter(messageChauffe($ws, 'x@societe.fr'))->accepte)->toBeTrue();
    // le journal garde les deux traces (ajout seul)
    expect(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'tout_arreter')->count())->toBe(1);
});

test('envoi:reprendre n’est exposé par AUCUNE route (commande locale seulement)', function () {
    foreach (['tout-arreter', 'reprendre', 'lever-pause', 'temoin-indesirable'] as $mot) {
        foreach (app('router')->getRoutes() as $route) {
            expect($route->uri())->not->toContain($mot);
        }
    }
    $api = (string) file_get_contents(base_path('routes/api.php'));
    expect($api)->not->toContain('ToutArreter')->and($api)->not->toContain('EnvoiReprendre');
});

// ── Mineurs ──────────────────────────────────────────────────────────────────

test('envoi:lever-pause : la levée et son journal sont dans UNE transaction (journal en échec = pause toujours active)', function () {
    $ws = espaceChauffe();
    app(GardeChauffe::class)->indesirableChezTemoin($ws, 'temoin@gmail.com');
    $maintenant = new DateTimeImmutable('now');
    $pause = app(DepotChauffe::class)->pausesActives($ws, $maintenant)[0];
    // Le journal refuse désormais d'écrire « pause_levee » (trigger posé dans la transaction du test).
    DB::unprepared("CREATE OR REPLACE FUNCTION test_refuse_levee() RETURNS trigger AS \$f\$ BEGIN
            IF NEW.commande = 'pause_levee' THEN RAISE EXCEPTION 'journal indisponible'; END IF; RETURN NEW; END; \$f\$ LANGUAGE plpgsql");
    DB::unprepared('CREATE TRIGGER test_refuse_levee BEFORE INSERT ON envoi_journal_commandes FOR EACH ROW EXECUTE FUNCTION test_refuse_levee()');
    expect(fn () => Artisan::call('envoi:lever-pause', ['workspace' => $ws, 'pause' => $pause->id, '--auteur' => 'will']))->toThrow(Throwable::class)
        ->and(app(DepotChauffe::class)->pausesActives($ws, $maintenant))->toHaveCount(1);   // la levée a été annulée avec le journal
    DB::unprepared('DROP TRIGGER test_refuse_levee ON envoi_journal_commandes');
    expect(Artisan::call('envoi:lever-pause', ['workspace' => $ws, 'pause' => $pause->id, '--auteur' => 'will']))->toBe(0)
        ->and(app(DepotChauffe::class)->pausesActives($ws, $maintenant))->toHaveCount(0)
        ->and(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'pause_levee')->count())->toBe(1)
        ->and(DB::table('envoi_pauses')->where('workspace_id', $ws)->count())->toBe(1);   // la ligne reste
});

test('index unique partiel : une seule pause active par (espace, motif, fournisseur) ; levée, on peut rouvrir', function () {
    $ws = espaceChauffe();
    $ligne = ['workspace_id' => $ws, 'portee' => 'global', 'fournisseur' => null, 'motif' => 'm'];
    WorkspaceContext::run($ws, function () use ($ligne) {
        DB::table('envoi_pauses')->insert($ligne);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_pauses')->insert($ligne)))->toThrow(Illuminate\Database\QueryException::class);
        $autre = ['portee' => 'fournisseur', 'fournisseur' => 'gmail'] + $ligne;
        DB::table('envoi_pauses')->insert($autre);                       // autre portée : permis
        DB::table('envoi_pauses')->where('motif', 'm')->whereNull('fournisseur')->update(['levee_le' => now()]);
        DB::table('envoi_pauses')->insert($ligne);                       // la précédente est levée : permis
    });
    expect(DB::table('envoi_pauses')->where('workspace_id', $ws)->count())->toBe(3);
});

test('ouvrirPause remplace une pause EXPIRÉE du même motif au lieu de buter sur l’index unique', function () {
    $ws = espaceChauffe();
    $depot = app(DepotChauffe::class);
    $t0 = paris('2026-11-10 10:00:00');
    expect($depot->ouvrirPause($ws, 'x', null, $t0, 48))->toBeTrue()
        ->and($depot->ouvrirPause($ws, 'x', null, $t0->modify('+1 hour'), 48))->toBeFalse()
        ->and($depot->ouvrirPause($ws, 'x', null, $t0->modify('+49 hours'), 48))->toBeTrue()
        ->and(DB::table('envoi_pauses')->where('workspace_id', $ws)->count())->toBe(2);
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

test('les tables L7 sont isolées par espace (RLS forcée) ; le rôle applicatif n’a VRAIMENT aucun DELETE ni TRUNCATE', function () {
    $tables = ['envoi_chauffe_jours', 'envoi_seuils', 'envoi_pauses'];
    foreach ($tables as $t) {
        $r = DB::selectOne('SELECT relrowsecurity AS rls, relforcerowsecurity AS force FROM pg_class WHERE relname = ?', [$t]);
        expect($r->rls)->toBeTrue()->and($r->force)->toBeTrue();
    }
    // Le rôle applicatif est créé s'il manque (annulé avec la transaction du test), puis les migrations
    // L7 sont rejouées (idempotentes) : ainsi les GRANT/REVOKE s'exécutent vraiment, partout.
    $role = (string) config('database.connections.pgsql_app.username', 'axion_app');
    if (DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$role]) === null) {
        DB::unprepared('CREATE ROLE "' . $role . '" NOLOGIN');
    }
    foreach (['2026_11_10_000070_envoi_chauffe_jours', '2026_11_10_000071_envoi_seuils', '2026_11_10_000072_envoi_pauses'] as $m) {
        (require database_path('migrations/' . $m . '.php'))->up();
    }
    foreach (['2026_11_10_000074_envoi_fournisseur_domaines', '2026_11_10_000075_envoi_battement_et_retours'] as $m) {
        (require database_path('migrations/' . $m . '.php'))->up();
    }
    foreach (array_merge($tables, ['envoi_fournisseur_domaines', 'envoi_sante_battement']) as $t) {
        $p = DB::selectOne("SELECT has_table_privilege(?, ?, 'DELETE') AS d, has_table_privilege(?, ?, 'TRUNCATE') AS t,
                                   has_table_privilege(?, ?, 'INSERT') AS i, has_table_privilege(?, ?, 'SELECT') AS s", [$role, $t, $role, $t, $role, $t, $role, $t]);
        expect($p->d)->toBeFalse()->and($p->t)->toBeFalse()->and($p->i)->toBeTrue()->and($p->s)->toBeTrue();
        // et une vraie tentative est refusée par la base.
        DB::unprepared('SAVEPOINT avant_delete');
        $refuse = false;
        try {
            DB::unprepared('SET LOCAL ROLE "' . $role . '"');
            DB::unprepared('DELETE FROM ' . $t);
        } catch (Throwable $e) {
            $refuse = str_contains($e->getMessage(), 'permission denied');
        }
        DB::unprepared('ROLLBACK TO SAVEPOINT avant_delete');
        DB::unprepared('RESET ROLE');
        expect($refuse)->toBeTrue();
    }
});

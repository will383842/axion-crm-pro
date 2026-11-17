<?php

/**
 * Lot L7-C — bout en bout d'une campagne : S6 plainte, S7 arrêt en vol (arrêt et pause), S8 opposé jamais réinscrit (et cloisonnement).
 * Chaque scénario est joué SOUS LE RÔLE APPLICATIF (RLS) ET SOUS LE PROPRIÉTAIRE. Données fictives (`*.example.test`), faux serveur,
 * aucune socket ; `envoi.reel_actif` n'est jamais modifiée. Comportement en vigueur asserté (ESCALADE 5 du cahier) : freins limités
 * à l'espace ; liste de suppression globale par adresse.
 */

use App\Envoi\Campagnes\CompteursCampagne;
use App\Envoi\Campagnes\GestesCampagne;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\JourEnvoi;
use App\Envoi\Retours\AppliquerRetour;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BancCampagne;
use Tests\Support\EspionneFluxBanc;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
    BancCampagne::libererFlux();
});

beforeEach(function () {
    config([
        'envoi.cle_empreinte' => 'cle-de-test-beba', 'envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app',
        'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'], 'envoi.plafond_jour' => 20,
    ]);
    Http::preventStrayRequests();
    Mail::fake();
    BancCampagne::espionnerFlux();
});

/**
 * Campagne de $n entreprises lancée, étape 1 partie pour toutes (jour J).
 *
 * @return array{0: BancCampagne, 1: string} banc, campagne
 */
function bebaMonter(string $mode, int $n = 3, bool $chauffe = false, int $delaiJours = 3): array
{
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire);   // jamais de DNS ; la garde et le compteur de retours le résolvent paresseusement
    app()->forgetInstance(GardeChauffe::class);
    app()->forgetInstance(AppliquerRetour::class);
    $banc = BancCampagne::espace();
    $banc->avecChauffe = $chauffe;
    $id = $banc->campagne($n, 2, $delaiJours);
    BancCampagne::sous($mode, fn () => $banc->lancer($id));
    foreach (['10:00', '10:30', '11:00'] as $heure) {   // l'étalement du premier envoi décale certaines inscriptions de quelques minutes : on rejoue le cycle (idempotent)
        if ($banc->messagesEnvoyes(1)->count() < $n) {
            bebaTic($banc, $mode, BancCampagne::jour($heure));
        }
    }
    expect($banc->messagesEnvoyes(1))->toHaveCount($n);
    Carbon::setTestNow(BancCampagne::jour('12:00'));

    return [$banc, $id];
}

function bebaInscription(BancCampagne $banc, object $ins): object
{
    return DB::table('envoi_inscriptions')->where('workspace_id', $banc->ws)->where('id', $ins->id)->first();
}

function bebaVerp(BancCampagne $banc, object $ins): string
{
    return BancCampagne::verp($banc->idInjectionRegistre($ins->id));
}

/** Un tour de séquenceur à $t ; avec la garde de chauffe réelle, l'horloge de l'application suit et le battement de santé est posé (sinon « santé silencieuse »). */
function bebaTic(BancCampagne $banc, string $mode, DateTimeImmutable $t): void
{
    Carbon::setTestNow($t);
    if ($banc->avecChauffe) {
        app(DepotChauffe::class)->noterBattement($t);
    }
    BancCampagne::sous($mode, fn () => $banc->tic($t));
}

function bebaEtape2(BancCampagne $banc, string $mode): void
{
    bebaTic($banc, $mode, BancCampagne::jour('10:00', 3));
}

function bebaPauses(string $ws): int
{
    return DB::table('envoi_pauses')->where('workspace_id', $ws)->whereNull('levee_le')->count();
}

function bebaEvaluer(string $ws): array
{
    return array_map(fn ($d) => $d->motif, app(GardeChauffe::class)->evaluer($ws, Carbon::now()->toDateTimeImmutable()));
}

test('beba 1 S6 plainte : opposition + suppression « plainte », palier tenu ; étape 2 de A jamais injectée ; not-spam sans effet', function (string $mode) {
    [$banc, $id] = bebaMonter($mode, 2, true);
    $a = $banc->inscriptionDe(1);
    $adresse = $banc->adresseInscrite($a);
    $avant = DB::table('email_suppressions')->count();

    // not-spam : aucun effet
    $notSpam = $banc->plainte(bebaVerp($banc, $a), $adresse, 'not-spam');
    BancCampagne::sous($mode, fn () => $banc->releve($notSpam));
    expect(bebaInscription($banc, $a)->statut)->toBe('active')->and(DB::table('email_suppressions')->count())->toBe($avant)
        ->and(app(DepotChauffe::class)->plaintes7Jours($banc->ws, JourEnvoi::de(Carbon::now()->toDateTimeImmutable())))->toBe(0);

    $plainte = $banc->plainte(bebaVerp($banc, $a), $adresse);
    BancCampagne::sous($mode, fn () => $banc->releve($plainte));
    $apres = bebaInscription($banc, $a);
    expect($apres->statut)->toBe('arretee')
        ->and(ListeSuppression::estSupprimee($adresse))->toBeTrue()
        ->and(DB::table('opt_out')->where('email_hash', ListeSuppression::empreinte($adresse))->exists())->toBeTrue()
        ->and((string) DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte($adresse))->value('reason'))->toBe('complaint')   // « plainte » de la liste de suppression
        ->and(app(DepotChauffe::class)->plaintes7Jours($banc->ws, JourEnvoi::de(Carbon::now()->toDateTimeImmutable())))->toBe(1)
        ->and(BancCampagne::sous($mode, fn () => bebaEvaluer($banc->ws)))->toBe([])   // palier tenu : relecture, AUCUNE pause
        ->and(bebaPauses($banc->ws))->toBe(0);

    bebaEtape2($banc, $mode);
    expect($banc->messagesEnvoyes(2))->toHaveCount(1)   // B seulement
        ->and(DB::table('envoi_messages')->where('workspace_id', $banc->ws)->where('inscription_id', $a->id)->count())->toBe(1)
        ->and(EspionneFluxBanc::$ouvertures)->toBe(0);
})->with(['app', 'proprio']);

test('beba 2 S6 seconde plainte sous 7 jours : arrêt 48 h de l\'ESPACE, 0 injection pour tous, inscriptions restées actives, aucune reprise automatique', function (string $mode) {
    [$banc, $id] = bebaMonter($mode, 3, true, 1);   // étape 2 due le lendemain, DANS les 48 h de la pause
    $a = $banc->inscriptionDe(1);
    $b = $banc->inscriptionDe(2);
    $c = $banc->inscriptionDe(3);
    $pa = $banc->plainte(bebaVerp($banc, $a), $banc->adresseInscrite($a));
    $pb = $banc->plainte(bebaVerp($banc, $b), $banc->adresseInscrite($b));
    BancCampagne::sous($mode, fn () => $banc->releve($pa));
    expect(BancCampagne::sous($mode, fn () => bebaEvaluer($banc->ws)))->toBe([]);
    BancCampagne::sous($mode, fn () => $banc->releve($pb));
    expect(BancCampagne::sous($mode, fn () => bebaEvaluer($banc->ws)))->toBe(['deux_plaintes_en_sept_jours'])->and(bebaPauses($banc->ws))->toBe(1);

    $injectes = count($banc->injectes);
    bebaTic($banc, $mode, BancCampagne::jour('10:00', 1));
    bebaTic($banc, $mode, BancCampagne::jour('14:00', 1));
    expect(count($banc->injectes))->toBe($injectes)->and($banc->messagesEnvoyes(2))->toHaveCount(0)
        ->and(bebaInscription($banc, $c)->statut)->toBe('active')   // rien n'est perdu
        ->and(DB::table('envoi_inscriptions')->where('workspace_id', $banc->ws)->where('statut', 'active')->count())->toBe(1);

    // Aucun geste automatique : une nouvelle évaluation ne lève rien, la pause reste (durée 48 h).
    expect(BancCampagne::sous($mode, fn () => bebaEvaluer($banc->ws)))->toBe([])->and(bebaPauses($banc->ws))->toBe(1);
    $pause = DB::table('envoi_pauses')->where('workspace_id', $banc->ws)->whereNull('levee_le')->first();
    expect((strtotime((string) $pause->expire_le) - strtotime((string) $pause->declenchee_le)) / 3600)->toEqual(48);
    expect(EspionneFluxBanc::$ouvertures)->toBe(0);
})->with(['app', 'proprio']);

test('beba 3 S7 arrêt en vol : inscriptions actives arretee/campagne_arretee, 0 injection ; l\'arretee/reponse garde son motif', function (string $mode) {
    [$banc, $id] = bebaMonter($mode, 3);
    $a = $banc->inscriptionDe(1);
    $reponse = $banc->reponse($banc->messageId($a->id), $banc->adresseInscrite($a));   // lu hors rôle applicatif : sans contexte d'espace, la RLS ne rend rien
    BancCampagne::sous($mode, fn () => $banc->releve($reponse));
    expect(bebaInscription($banc, $a)->motif_arret)->toBe('reponse');

    $injectes = count($banc->injectes);
    BancCampagne::sous($mode, fn () => app(GestesCampagne::class)->arreter($banc->ws, $id, 'banc', null));
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('arretee')
        ->and(bebaInscription($banc, $a)->motif_arret)->toBe('reponse');
    foreach ([2, 3] as $r) {
        $i = bebaInscription($banc, $banc->inscriptionDe($r));
        expect($i->statut)->toBe('arretee')->and($i->motif_arret)->toBe('campagne_arretee');
    }
    bebaEtape2($banc, $mode);
    expect(count($banc->injectes))->toBe($injectes)->and($banc->messagesEnvoyes(2))->toHaveCount(0)
        ->and(BancCampagne::sous($mode, fn () => app(CompteursCampagne::class)->pour($banc->ws, $id))['arretes_campagne'] ?? null)->toBe(2);
    expect(EspionneFluxBanc::$ouvertures)->toBe(0);
})->with(['app', 'proprio']);

test('beba 4 S7 variante pause : 0 injection ; reprendre => étape 2 part à l\'échéance suivante', function (string $mode) {
    [$banc, $id] = bebaMonter($mode, 2);
    BancCampagne::sous($mode, fn () => app(GestesCampagne::class)->pause($banc->ws, $id, 'banc', null));
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('en_pause');
    $injectes = count($banc->injectes);
    bebaEtape2($banc, $mode);
    expect(count($banc->injectes))->toBe($injectes)->and($banc->messagesEnvoyes(2))->toHaveCount(0);

    BancCampagne::sous($mode, fn () => app(GestesCampagne::class)->reprendre($banc->ws, $id, 'banc', null));
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('en_cours');
    bebaTic($banc, $mode, BancCampagne::jour('11:00', 3));
    expect($banc->messagesEnvoyes(2))->toHaveCount(2)
        ->and(DB::table('envoi_inscriptions')->where('workspace_id', $banc->ws)->where('statut', 'arretee')->count())->toBe(0);
})->with(['app', 'proprio']);

test('beba 5 S8 opposé jamais réinscrit : campagne 2 et 3 écartent A (opposition), 0 inscription, fiche corbeille puis restaurée', function (string $mode) {
    [$banc, $id1] = bebaMonter($mode, 2);
    $a = $banc->inscriptionDe(1);
    $adresse = $banc->adresseInscrite($a);
    $stop = $banc->stop($banc->messageId($a->id), $adresse);
    BancCampagne::sous($mode, fn () => $banc->releve($stop));
    expect(bebaInscription($banc, $a)->motif_arret)->toBe('opposition');

    $id2 = $banc->campagneSurMemeCible($id1, 'Campagne 2');
    $m2 = DB::table('envoi_campagnes_materialisations')->where('workspace_id', $banc->ws)->where('campagne_id', $id2)->first();
    $cmp2 = (array) json_decode((string) $m2->compteurs, true);
    expect($m2->etat)->toBe('terminee')->and((int) ($cmp2['opposition'] ?? 0))->toBe(1)->and((int) $cmp2['inscrits'])->toBe(0)   // A écartée (opposition) ; B encore en séquence dans la campagne 1
        ->and((int) $cmp2['deja_en_sequence'])->toBe(1);
    $entrepriseA = $a->entreprise_id;
    $inscritsA = fn (string $camp) => DB::table('envoi_inscriptions')->where('workspace_id', $banc->ws)->where('entreprise_id', $entrepriseA)->whereIn('sequence_id', DB::table('envoi_campagnes_materialisations')->where('campagne_id', $camp)->pluck('sequence_id'))->count();
    expect($inscritsA($id2))->toBe(0);

    BancCampagne::sous($mode, fn () => app(GestesCampagne::class)->arreter($banc->ws, $id1, 'banc', null));
    $id3 = $banc->campagneSurMemeCible($id1, 'Campagne 3');
    $cmp3 = (array) json_decode((string) DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id3)->value('compteurs'), true);
    expect((int) ($cmp3['opposition'] ?? 0))->toBe(1)->and($inscritsA($id3))->toBe(0);

    // Fiche A à la corbeille (douce) puis restaurée : toujours écartée.
    DB::table('companies')->where('id', $entrepriseA)->update(['deleted_at' => now()]);
    DB::table('companies')->where('id', $entrepriseA)->update(['deleted_at' => null]);
    $id4 = $banc->campagneSurMemeCible($id1, 'Campagne 4');
    expect($inscritsA($id4))->toBe(0)
        ->and((int) ((array) json_decode((string) DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id4)->value('compteurs'), true))['opposition'])->toBe(1)
        ->and(EspionneFluxBanc::$ouvertures)->toBe(0);
})->with(['app', 'proprio']);

test('beba 6 S8 cloisonnement : l\'espace B ne reçoit ni événement, ni frein, ni compteur de A ; même adresse => écartée (liste globale, échec fermé)', function (string $mode) {
    [$banc, $id1] = bebaMonter($mode, 2);
    $a = $banc->inscriptionDe(1);
    $adresse = $banc->adresseInscrite($a);
    $stop = $banc->stop($banc->messageId($a->id), $adresse);
    BancCampagne::sous($mode, fn () => $banc->releve($stop));
    $ws = $banc->ws;

    $autre = BancCampagne::espace();   // espace B : autres entreprises, autres adresses
    $idB = $autre->campagne(2, 2, 3);
    $jour = JourEnvoi::de(Carbon::now()->toDateTimeImmutable());
    $mB = (array) json_decode((string) DB::table('envoi_campagnes_materialisations')->where('workspace_id', $autre->ws)->where('campagne_id', $idB)->value('compteurs'), true);
    expect((int) ($mB['opposition'] ?? 0))->toBe(0)->and((int) $mB['inscrits'])->toBe(2)
        ->and(DB::table('envoi_evenements')->where('workspace_id', $autre->ws)->count())->toBe(0)
        ->and(DB::table('envoi_inscriptions_evenements')->where('workspace_id', $autre->ws)->count())->toBe(0)
        ->and(DB::table('envoi_pauses')->where('workspace_id', $autre->ws)->count())->toBe(0)
        ->and(app(DepotChauffe::class)->plaintes7Jours($autre->ws, $jour))->toBe(0)
        ->and(DB::table('envoi_reponses')->where('workspace_id', $autre->ws)->count())->toBe(0);
    $cmpB = BancCampagne::sous($mode, fn () => app(CompteursCampagne::class)->pour($autre->ws, $idB));
    expect($cmpB['opposes'] ?? 0)->toBe(0);

    // Même adresse dans l'espace B : la suppression est globale (par adresse) => écartée, comportement voulu (échec fermé).
    $eB = (int) DB::table('companies')->where('workspace_id', $autre->ws)->orderBy('id')->value('id');
    DB::table('contacts')->where('workspace_id', $autre->ws)->where('company_id', $eB)->update(['email' => $adresse]);
    DB::table('envoi_inscriptions')->where('workspace_id', $autre->ws)->delete();
    $idB2 = $autre->campagneSurMemeCible($idB, 'Campagne B bis');
    $cmp = (array) json_decode((string) DB::table('envoi_campagnes_materialisations')->where('workspace_id', $autre->ws)->where('campagne_id', $idB2)->value('compteurs'), true);
    expect((int) ($cmp['opposition'] ?? 0))->toBe(1);
})->with(['app', 'proprio']);

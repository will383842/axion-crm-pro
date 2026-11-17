<?php

/**
 * Lot L7-B — bout en bout d'une campagne : S2 réponse, S3 STOP, S4 rebond dur (VERP signé), S5 rebond mou. Chaque scénario est joué
 * SOUS LE RÔLE APPLICATIF (RLS) ET SOUS LE PROPRIÉTAIRE. Données fictives (`*.example.test`), faux serveur, aucune socket ;
 * `envoi.reel_actif` n'est jamais modifiée. Entreprise A : 2 contacts, UN seul inscrit (E7) ; entreprise B : 1 contact.
 */

use App\Envoi\Campagnes\CompteursCampagne;
use App\Envoi\Reponses\ServiceReponses;
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
        'envoi.cle_empreinte' => 'cle-de-test-bebr', 'envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app',
        'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'], 'envoi.plafond_jour' => 20,
    ]);
    Http::preventStrayRequests();
    Mail::fake();
    BancCampagne::espionnerFlux();
});

/** Campagne lancée, étape 1 partie pour A et B (jour J). @return array{0: BancCampagne, 1: string, 2: object, 3: object, 4: string} banc, campagne, inscription A, inscription B, adresse inscrite de A */
function bebrMonter(string $mode): array
{
    $banc = BancCampagne::espace();
    $id = $banc->campagne(2, 2, 3, [1]);
    $a = $banc->inscriptionDe(1);
    $b = $banc->inscriptionDe(2);
    expect(DB::table('envoi_inscriptions')->where('workspace_id', $banc->ws)->where('entreprise_id', $a->entreprise_id)->count())->toBe(1)   // E7 : 2 contacts, 1 inscrit
        ->and(DB::table('contacts')->where('workspace_id', $banc->ws)->where('company_id', $a->entreprise_id)->count())->toBe(2);
    BancCampagne::sous($mode, fn () => $banc->lancer($id));
    BancCampagne::sous($mode, fn () => $banc->tic(BancCampagne::jour('10:00')));
    expect($banc->messagesEnvoyes(1))->toHaveCount(2);
    Carbon::setTestNow(BancCampagne::jour('12:00'));   // les retours arrivent dans la journée

    return [$banc, $id, $a, $b, $banc->adresseInscrite($a)];
}

/** @return array<string, int> effets en base : rejouer la relève ne doit rien ajouter */
function bebrEffets(string $ws): array
{
    return [
        'evenements' => DB::table('envoi_evenements')->where('workspace_id', $ws)->count(),
        'traces' => DB::table('envoi_inscriptions_evenements')->where('workspace_id', $ws)->count(),
        'reponses' => DB::table('envoi_reponses')->where('workspace_id', $ws)->count(),
        'suppressions' => DB::table('email_suppressions')->count(),
    ];
}

/** Rejoue la relève (curseur remis à zéro : mêmes messages, mêmes clés boîte/UIDVALIDITY/UID) : aucun effet en double. */
function bebrRejouer(BancCampagne $banc, string $mode): void
{
    $avant = bebrEffets($banc->ws);
    $banc->reinitialiserCurseur();
    BancCampagne::sous($mode, fn () => $banc->releve());
    expect(bebrEffets($banc->ws))->toBe($avant);
}

function bebrInscription(BancCampagne $banc, object $ins): object
{
    return DB::table('envoi_inscriptions')->where('workspace_id', $banc->ws)->where('id', $ins->id)->first();
}

function bebrEtape2(BancCampagne $banc, string $mode): void
{
    BancCampagne::sous($mode, fn () => $banc->tic(BancCampagne::jour('10:00', 3)));
}

test('bebr 1 S2 réponse humaine : A arrêtée (1 seul message à vie), étape 2 pour B seulement, réponse visible', function () {
    foreach (['app', 'proprio'] as $mode) {
        [$banc, , $a, $b, $adresse] = bebrMonter($mode);
        $mid = $banc->messageId($a->id);   // lu hors rôle applicatif : sans contexte d'espace, la RLS ne rend aucune ligne
        BancCampagne::sous($mode, fn () => $banc->releve($banc->reponse($mid, $adresse)));

        $apres = bebrInscription($banc, $a);
        expect($apres->statut)->toBe('arretee')->and($apres->motif_arret)->toBe('reponse')
            ->and(DB::table('envoi_inscriptions_evenements')->where('workspace_id', $banc->ws)->where('inscription_id', $a->id)->where('evenement', 'reponse')->count())->toBe(1);

        bebrEtape2($banc, $mode);
        expect($banc->messagesEnvoyes(2))->toHaveCount(1)->and($banc->messagesEnvoyes(2)->first()->inscription_id)->toBe($b->id)
            ->and(DB::table('envoi_messages')->where('workspace_id', $banc->ws)->where('inscription_id', $a->id)->count())->toBe(1);

        $liste = BancCampagne::sous($mode, fn () => app(ServiceReponses::class)->lister($banc->ws, 'a_traiter', null, null, 1, 20));
        expect($liste['data'])->toHaveCount(1)->and($liste['data'][0]['boite_id'])->toBe($banc->boite)->and($liste['data'][0]['de'])->toBe($adresse);
        bebrRejouer($banc, $mode);
        expect(EspionneFluxBanc::$ouvertures)->toBe(0);
    }
});

test('bebr 2 S3 STOP : opposition écrite, A arrêtée, 0 injection pour A, la fiche n\'est jamais supprimée', function () {
    foreach (['app', 'proprio'] as $mode) {
        [$banc, , $a, , $adresse] = bebrMonter($mode);
        $contacts = DB::table('contacts')->where('workspace_id', $banc->ws)->count();
        $entreprises = DB::table('companies')->where('workspace_id', $banc->ws)->count();
        $mid = $banc->messageId($a->id);
        BancCampagne::sous($mode, fn () => $banc->releve($banc->stop($mid, $adresse)));

        $apres = bebrInscription($banc, $a);
        expect(DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte($adresse))->exists())->toBeTrue()
            ->and($apres->statut)->toBe('arretee')->and($apres->motif_arret)->toBe('opposition')
            ->and(DB::table('envoi_inscriptions_evenements')->where('workspace_id', $banc->ws)->where('inscription_id', $a->id)->where('evenement', 'opposition')->count())->toBe(1);

        $injectes = count($banc->injectes);
        bebrEtape2($banc, $mode);
        expect($banc->messagesEnvoyes(2))->toHaveCount(1)->and(count($banc->injectes))->toBe($injectes + 1)
            ->and(DB::table('envoi_messages')->where('workspace_id', $banc->ws)->where('inscription_id', $a->id)->count())->toBe(1)
            ->and(DB::table('contacts')->where('workspace_id', $banc->ws)->count())->toBe($contacts)
            ->and(DB::table('contacts')->where('workspace_id', $banc->ws)->where('company_id', $a->entreprise_id)->whereNull('deleted_at')->count())->toBe(2)
            ->and(DB::table('companies')->where('workspace_id', $banc->ws)->count())->toBe($entreprises);
        bebrRejouer($banc, $mode);
        expect(EspionneFluxBanc::$ouvertures)->toBe(0);
    }
});

test('bebr 3 S4 rebond dur par VERP signé : A arrêtée, étape 2 jamais injectée, rebonds_durs = 1', function () {
    foreach (['app', 'proprio'] as $mode) {
        [$banc, $id, $a, , $adresse] = bebrMonter($mode);
        $verp = BancCampagne::verp($banc->idInjectionRegistre($a->id));
        BancCampagne::sous($mode, fn () => $banc->releve($banc->rebondDur(null, $adresse, $verp)));   // aucun Message-ID cité : le VERP seul rattache

        $apres = bebrInscription($banc, $a);
        expect(DB::table('envoi_evenements')->where('workspace_id', $banc->ws)->where('classe', 'rebond_dur')->where('statut', 'rattache')->count())->toBe(1)
            ->and($apres->statut)->toBe('arretee')->and($apres->motif_arret)->toBe('rebond_dur');

        bebrEtape2($banc, $mode);
        expect($banc->messagesEnvoyes(2))->toHaveCount(1)->and(DB::table('envoi_messages')->where('workspace_id', $banc->ws)->where('inscription_id', $a->id)->count())->toBe(1);
        $cmp = BancCampagne::sous($mode, fn () => app(CompteursCampagne::class)->pour($banc->ws, $id));
        expect($cmp['rebonds_durs'])->toBe(1);
        bebrRejouer($banc, $mode);
        expect(EspionneFluxBanc::$ouvertures)->toBe(0);
    }
});

test('bebr 4 S5 rebond mou : aucun arrêt, étape 2 envoyée, rebonds_mous = 1 ; suivi d\'un dur : compté dur seulement', function () {
    foreach (['app', 'proprio'] as $mode) {
        [$banc, $id, $a, , $adresse] = bebrMonter($mode);
        $mid = $banc->messageId($a->id);
        BancCampagne::sous($mode, fn () => $banc->releve($banc->rebondMou($mid, $adresse)));
        expect(DB::table('envoi_evenements')->where('workspace_id', $banc->ws)->where('classe', 'rebond_mou')->where('statut', 'rattache')->count())->toBe(1)
            ->and(bebrInscription($banc, $a)->statut)->toBe('active');

        bebrEtape2($banc, $mode);
        expect($banc->messagesEnvoyes(2))->toHaveCount(2);
        $cmp = BancCampagne::sous($mode, fn () => app(CompteursCampagne::class)->pour($banc->ws, $id));
        expect($cmp['rebonds_mous'])->toBe(1)->and($cmp['rebonds_durs'])->toBe(0);
        bebrRejouer($banc, $mode);

        $verp = BancCampagne::verp($banc->idInjectionRegistre($a->id));
        BancCampagne::sous($mode, fn () => $banc->releve($banc->rebondDur(null, $adresse, $verp)));
        $cmp = BancCampagne::sous($mode, fn () => app(CompteursCampagne::class)->pour($banc->ws, $id));
        expect($cmp['rebonds_durs'])->toBe(1)->and($cmp['rebonds_mous'])->toBe(0)->and(EspionneFluxBanc::$ouvertures)->toBe(0);
    }
});

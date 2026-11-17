<?php

/**
 * Lot L7-A — bout en bout d'une campagne : S1 nominal et S9 porte fermée. Chaque scénario est joué SOUS LE RÔLE APPLICATIF (RLS)
 * ET SOUS LE PROPRIÉTAIRE. Données fictives (`*.example.test`), faux serveur, aucune socket ; `envoi.reel_actif` n'est jamais modifiée.
 */

use App\Envoi\Campagnes\CompteursCampagne;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BancCampagne;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

beforeEach(function () {
    config([
        'envoi.cle_empreinte' => 'cle-de-test-bebn', 'envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app',
        'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'], 'envoi.plafond_jour' => 20,
    ]);
    Http::preventStrayRequests();
    Mail::fake();
});

test('bebn 1 S1 nominal : campagne → préparation → lancer → étape 1 → étape 2 (In-Reply-To) → terminée, compteurs justes', function () {
    foreach (['app', 'proprio'] as $mode) {
        $banc = BancCampagne::espace();
        $id = $banc->campagne(2, 2);
        $mat = DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->first();
        expect($mat->etat)->toBe('terminee')->and((int) json_decode($mat->compteurs, true)['inscrits'])->toBe(2);

        $c = BancCampagne::sous($mode, fn () => $banc->lancer($id));
        expect($c->statut)->toBe('en_cours');

        BancCampagne::sous($mode, fn () => $banc->tic(BancCampagne::jour('10:00')));
        expect($banc->serveur->appels)->toBe(2)
            ->and($banc->messagesEnvoyes(1))->toHaveCount(2)
            ->and(DB::table('envoi_messages_envoyes')->where('workspace_id', $banc->ws)->count())->toBe(2);
        $corps = $banc->injectes[0]->corps;
        expect(strtolower($corps))->toContain('desinscri')->and($corps)->toContain('Banc Expediteur')->and($corps)->toContain('article 14');

        BancCampagne::sous($mode, fn () => $banc->tic(BancCampagne::jour('10:00', 3)));
        expect($banc->messagesEnvoyes(2))->toHaveCount(2);
        foreach ($banc->messagesEnvoyes(2) as $m2) {
            $m1 = DB::table('envoi_messages')->where('workspace_id', $banc->ws)->where('inscription_id', $m2->inscription_id)->where('etape', 1)->first();
            expect($m2->in_reply_to)->toBe($m1->message_id);
        }

        BancCampagne::sous($mode, fn () => $banc->tic(BancCampagne::jour('10:00', 4)));
        expect(DB::table('envoi_inscriptions')->where('workspace_id', $banc->ws)->where('statut', 'terminee')->count())->toBe(2);
        $cmp = BancCampagne::sous($mode, fn () => app(CompteursCampagne::class)->pour($banc->ws, $id));
        expect($cmp['envoyes'])->toBe(4)->and($cmp['touches'])->toBe(2)->and(array_sum($cmp['issues']))->toBe(2);
    }
});

test('bebn 2 S9 porte fermée : envoi:cycle réel ⇒ « porte fermée », 0 message, 0 appel, drapeau intact', function () {
    foreach (['app', 'proprio'] as $mode) {
        $banc = BancCampagne::espace();
        $id = $banc->campagne(2, 2);
        BancCampagne::sous($mode, fn () => $banc->lancer($id));
        $avant = config('envoi.reel_actif');
        $banc->serveur = new FauxServeurEnvoi;

        BancCampagne::sous($mode, fn () => Artisan::call('envoi:cycle'));

        expect($avant)->toBeFalse()->and(config('envoi.reel_actif'))->toBeFalse()
            ->and(Artisan::output())->toContain('porte fermée')
            ->and(DB::table('envoi_messages')->where('workspace_id', $banc->ws)->count())->toBe(0)
            ->and($banc->serveur->appels)->toBe(0);
    }
});

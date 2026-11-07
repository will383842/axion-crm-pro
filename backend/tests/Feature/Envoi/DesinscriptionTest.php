<?php

/**
 * Envoi P0 L5 — désinscription (test S22). Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Desinscription\OppositionEnregistree;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const DESINSCR_ADRESSE = 'personne@zz-desinscription.example.invalid';

beforeEach(function () {
    config(['envoi.cle_desinscription' => 'cle-de-test-seulement', 'envoi.desinscription_validite_jours' => 30]);
    $this->ws = F::espace('zz-desinscr');
    $this->ref = InscriptionsEnvoi::creer($this->ws, DESINSCR_ADRESSE);
    $this->jeton = JetonDesinscription::emettre($this->ws, $this->ref);
    $this->chemin = '/desinscription/' . $this->jeton;
});

function desinscrOppositions(string $ws): int
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions')->count());
}

function desinscrOccurrences(): int
{
    return (int) DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte(DESINSCR_ADRESSE))->sum('occurrences');
}

test('S22 GET (avec ou sans suivi de redirections) : un seul bouton, aucune opposition', function () {
    $r = $this->get($this->chemin);
    $r->assertOk();
    expect($r->isRedirection())->toBeFalse();
    expect(substr_count($r->getContent(), '<button'))->toBe(1);
    expect($r->getContent())->toContain('Confirmer la désinscription');

    // « Suivi de redirections » : un robot qui rejoue la page ou suit un éventuel Location.
    $this->followingRedirects()->get($this->chemin)->assertOk();
    $this->head($this->chemin);

    expect(desinscrOppositions($this->ws))->toBe(0);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeFalse();
    expect(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('S22 POST du bouton : une opposition, adresse en liste de suppression, événement émis', function () {
    Event::fake([OppositionEnregistree::class]);
    $this->post($this->chemin)->assertOk()->assertSee('Désinscription confirmée', false);

    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();
    expect(DB::table('crm_outbound_events')->where('email_hash', ConsentOutboundRecorder::hashEmail(DESINSCR_ADRESSE))->where('event_type', 'consent_optout')->count())->toBe(1);
    Event::assertDispatchedTimes(OppositionEnregistree::class, 1);
});

test('S22 POST one-click RFC 8058 : opposition immédiate, sans page', function () {
    $r = $this->post($this->chemin, ['List-Unsubscribe' => 'One-Click']);
    $r->assertOk();
    expect(trim($r->getContent()))->toBe('');
    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();
});

test('S22 rejeu du même jeton : une seule opposition, un seul comptage, un seul événement', function () {
    $this->post($this->chemin)->assertOk();
    $this->post($this->chemin)->assertOk();
    $this->post($this->chemin, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(desinscrOccurrences())->toBe(1);
    expect(DB::table('crm_outbound_events')->count())->toBe(1);
});

test('jetons invalides, falsifiés ou expirés : même refus, sans fuite, sans écriture', function () {
    $autre = F::espace('zz-desinscr-b');
    $expire = JetonDesinscription::emettre($this->ws, $this->ref, new DateTimeImmutable('-2 years'));
    $inconnu = JetonDesinscription::emettre($this->ws, '00000000-0000-4000-8000-000000000000');
    // Même signature, autre espace : la signature couvre l'espace.
    $croise = str_replace($this->ws, $autre, $this->jeton);
    $mauvaiseCle = (function () {
        config(['envoi.cle_desinscription' => 'une-autre-cle']);
        $j = JetonDesinscription::emettre($this->ws, $this->ref);
        config(['envoi.cle_desinscription' => 'cle-de-test-seulement']);

        return $j;
    })();

    $corps = [];
    foreach (['n-importe-quoi', substr($this->jeton, 0, -3), $expire, $inconnu, $croise, $mauvaiseCle, str_repeat('a', 5000)] as $j) {
        foreach (['get', 'post'] as $m) {
            $r = $this->{$m}('/desinscription/' . $j);
            $r->assertNotFound();
            expect($r->getContent())->not->toContain('zz-desinscription')->not->toContain($this->ref);
            $corps[] = $r->getContent();
        }
    }
    expect(array_unique($corps))->toHaveCount(1); // le même refus, quelle que soit la cause
    expect(desinscrOppositions($this->ws))->toBe(0);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeFalse();
});

test('clé absente : la porte reste fermée, rien n’est émis ni accepté', function () {
    config(['envoi.cle_desinscription' => '']);
    expect(fn () => JetonDesinscription::emettre($this->ws, $this->ref))->toThrow(RuntimeException::class);
    $this->post($this->chemin)->assertNotFound();
    expect(desinscrOppositions($this->ws))->toBe(0);
});

test('aucune donnée de personne dans le lien', function () {
    expect($this->jeton)->not->toContain('zz-desinscription')->not->toContain('@')
        ->not->toContain(ListeSuppression::empreinte(DESINSCR_ADRESSE))
        ->not->toContain(base64_encode(DESINSCR_ADRESSE));
    expect(JetonDesinscription::url($this->ws, $this->ref))->toContain('/desinscription/');
});

test('page publique : française, sans image, sans script, sans ressource distante, sans suivi', function () {
    $r = $this->get($this->chemin);
    $html = $r->getContent();
    expect($html)->toContain('<html lang="fr"')->toContain('<title>')
        ->not->toContain('<img')->not->toContain('<script')->not->toContain('<iframe')
        ->not->toContain('<link')->not->toContain('http://')->not->toContain('https://')
        ->not->toContain('@import')->not->toContain('url(');
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
    expect($r->headers->get('Referrer-Policy'))->toBe('no-referrer');
    expect($r->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    expect($r->headers->get('X-Robots-Tag'))->toContain('noindex');
    expect($r->headers->get('Set-Cookie'))->toBeNull();
    expect($html)->not->toContain(DESINSCR_ADRESSE);
});

test('effets : retrait des envois en file et arrêt par entreprise déclenchés une seule fois', function () {
    $retrait = new class implements RetraitEnvoisEnFile
    {
        /** @var list<array{string,string}> */
        public array $appels = [];

        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            $this->appels[] = [$workspaceId, $empreinteAdresse];

            return 2;
        }
    };
    $arret = new class implements ArretParEntreprise
    {
        /** @var list<array{string,string}> */
        public array $appels = [];

        public function declencher(string $workspaceId, string $empreinteAdresse): void
        {
            $this->appels[] = [$workspaceId, $empreinteAdresse];
        }
    };
    app()->instance(RetraitEnvoisEnFile::class, $retrait);
    app()->instance(ArretParEntreprise::class, $arret);

    $this->post($this->chemin)->assertOk();
    $this->post($this->chemin)->assertOk();

    $attendu = [[$this->ws, ListeSuppression::empreinte(DESINSCR_ADRESSE)]];
    expect($retrait->appels)->toBe($attendu);
    expect($arret->appels)->toBe($attendu);
});

test('effets interrompus : le rejeu du jeton les termine, sans doublon d’opposition', function () {
    $retrait = new class implements RetraitEnvoisEnFile
    {
        public int $n = 0;

        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            $this->n++;
            if ($this->n === 1) {
                throw new RuntimeException('panne simulée');
            }

            return 0;
        }
    };
    app()->instance(RetraitEnvoisEnFile::class, $retrait);

    $this->post($this->chemin)->assertOk(); // l'opposition est acquise malgré la panne
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();
    $this->post($this->chemin)->assertOk();

    expect($retrait->n)->toBe(2);
    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(desinscrOccurrences())->toBe(1);
});

test('l’opposition n’efface rien : l’inscription et ses lignes restent', function () {
    $this->post($this->chemin)->assertOk();
    $n = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_inscriptions')->where('reference', $this->ref)->count());
    expect($n)->toBe(1);
});

test('RLS FORCE sur les deux tables', function () {
    foreach (['envoi_inscriptions', 'envoi_oppositions'] as $t) {
        $r = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$t]);
        expect($r->relrowsecurity)->toBeTrue();
        expect($r->relforcerowsecurity)->toBeTrue();
    }
});

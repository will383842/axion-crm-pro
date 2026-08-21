<?php

/**
 * 🔴 B15-001 (S0) — « UNE PERSONNE EFFACEE PAR LA CONSOLE REVIENT AU VIVIER A
 * LA CANDIDATURE SUIVANTE ».
 *
 * Le mecanisme, mesure le 2026-08-20 sur `axion_crm_test_lot1` :
 *
 *   1. `GdprErasureService::erase()` — la porte CONSOLE / API — solde son
 *      travail par `DeduplicationService::addOptOut()`, qui insere dans
 *      `opt_out` SANS poser `scope`. La colonne a un DEFAULT SQL
 *      (`'business'::text`, mesure : `\d opt_out`). Une opposition nee d'un
 *      effacement console n'existe donc QUE dans l'univers business.
 *
 *   2. Le funnel d'ingestion des candidatures classe une
 *      `application_submitted` en univers `vivier`
 *      (`SiteSyncClassifier::universe()`), et sa garde anti-reinsertion
 *      (`SiteSyncIngestService::hasOpposed()`) interroge
 *      `where('scope', 'vivier')`.
 *
 *   3. Les deux ne se rencontrent jamais : la candidature suivante recree la
 *      fiche candidat, avec nom, prenom, adresse et telephone de quelqu'un qui
 *      venait d'obtenir son effacement.
 *
 * LE JUMEAU FAISAIT DEJA BIEN (patron A-011) : `SiteGdprService::erase()`, la
 * porte SITE, ecrit `optOut(..., 'business')` ET `optOut(..., 'vivier')` quand
 * la portee vaut `both`. Le correctif existait a cote et n'avait pas ete porte.
 *
 * TEMOIN : une adresse jamais opposee, elle, doit bien entrer. Sans ce temoin,
 * une garde qui casserait l'ingestion pour tout le monde passerait pour une
 * reussite.
 */

use App\Crm\Ingest\IngestOutcome;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncIngestService;
use App\Crm\Taxonomy;
use App\Services\Dedup\DeduplicationService;
use App\Services\Rgpd\GdprErasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Le flux vivier est ferme par defaut (`crm.ingest.candidates_enabled`).
    // On l'ouvre : ce qu'on veut mesurer, c'est la garde d'opposition, pas le
    // drapeau qui la precede.
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.candidates_enabled' => true,
    ]);
});

function espaceVivier(): string
{
    // L'espace vivier est cree par les migrations (mesure :
    // `select slug from workspaces` rend `vivier-candidats` sur une base
    // fraiche). On le lit, on ne le fabrique pas : fabriquer un doublon ferait
    // atterrir l'ingestion ailleurs que la ou l'effacement a cherche.
    return (string) DB::table('workspaces')
        ->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)
        ->value('id');
}

/** La candidature telle que le site l'emet — schema strict, consentement v2. */
function candidatureDuSite(string $courriel): SiteSyncEvent
{
    return SiteSyncEvent::fromArray([
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'application_submitted',
        'form_type' => null,
        'occurred_at' => '2026-08-20T09:30:00+02:00',
        'subject_ref' => 'site:job_application:' . Str::uuid(),
        'person' => [
            'person_key' => hash('sha256', $courriel),
            'email' => $courriel,
            'first_name' => 'Jean',
            'last_name' => 'ZZ TEST',
            'phone' => '+33600000011',
        ],
        'consent' => [
            'version' => 'careers-v2-2026-08-13',
            'at' => '2026-08-20T09:29:00+02:00',
            'vivier_at' => '2026-08-20T09:29:00+02:00',
        ],
        'candidate' => ['family' => 'candidat_commercial', 'offer_slug' => 'monteur-video'],
        'payload' => ['page' => '/fr/carrieres'],
    ]);
}

function deposerCandidature(string $courriel): IngestOutcome
{
    return app(SiteSyncIngestService::class)->ingest(candidatureDuSite($courriel));
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. LE DEFAUT LUI-MEME
// ─────────────────────────────────────────────────────────────────────────────

test('B15-001 — une personne effacee par la CONSOLE ne revient PAS au vivier a la candidature suivante', function () {
    $courriel = 'efface.console@example.invalid';
    $vivier = espaceVivier();

    // Elle est au vivier : c'est l'etat de depart, pas une hypothese.
    DB::table('candidates')->insert([
        'workspace_id' => $vivier,
        'person_key' => hash('sha256', $courriel),
        'first_name' => 'Jean',
        'last_name' => 'ZZ TEST',
        'email' => $courriel,
        'phone' => '+33600000011',
        'relation_type' => 'candidat_commercial',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'consent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(GdprErasureService::class)->erase($courriel, '+33600000011');

    expect(DB::table('candidates')->where('email', $courriel)->count())
        ->toBe(0, 'l’effacement console n’a meme pas supprime la fiche candidat');

    // ── LA CANDIDATURE SUIVANTE ──────────────────────────────────────────
    $resultat = deposerCandidature($courriel);

    expect($resultat->status)->toBe(
        IngestOutcome::OPTED_OUT,
        'la candidature suivante a ete INGEREE : la personne effacee revient au vivier',
    );
    expect(DB::table('candidates')->where('email', $courriel)->count())
        ->toBe(0, 'une fiche candidat a ete recreee pour une personne effacee');
    // La timeline non plus ne doit rien garder : une activite `application`
    // porte le message de candidature, donc des PII.
    expect(DB::table('activities')->where('person_key', hash('sha256', $courriel))->count())->toBe(0);
});

test('B15-001 — TEMOIN : une adresse jamais opposee entre bien au vivier', function () {
    $courriel = 'jamais.opposee@example.invalid';

    $resultat = deposerCandidature($courriel);

    // Sans ce temoin, une garde qui refuserait TOUTES les candidatures
    // passerait le test precedent et paraitrait avoir ferme le constat.
    expect($resultat->status)->toBe(
        IngestOutcome::CREATED,
        'la garde anti-reinsertion refuse aussi les candidatures legitimes',
    );
    expect(DB::table('candidates')->where('email', $courriel)->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LE MECANISME, NOMME — pour que la reparation ne puisse pas etre fortuite
// ─────────────────────────────────────────────────────────────────────────────

test('B15-001 — un effacement console inscrit l’opposition dans les DEUX univers', function () {
    $courriel = 'deux.univers@example.invalid';

    app(GdprErasureService::class)->erase($courriel);

    $portees = DB::table('opt_out')
        ->where('email_hash', hash('sha256', $courriel))
        ->pluck('scope')
        ->all();
    sort($portees);

    // `assertContains` et non `expect()->toContain()` : ce dernier est
    // VARIADIQUE chez Pest — un message passe en second argument deviendrait
    // une valeur a chercher, et la garde rougirait pour toujours.
    $this->assertContains('vivier', $portees, 'aucune opposition « vivier » : la porte du vivier reste ouverte');
    $this->assertContains('business', $portees, 'aucune opposition « business » : la porte du scraping reste ouverte');
});

test('B15-001 — la garde du VIVIER, requete a l’identique, voit l’opposition', function () {
    $courriel = 'garde.vivier@example.invalid';

    app(GdprErasureService::class)->erase($courriel);

    // Reproduction EXACTE de `SiteSyncIngestService::hasOpposed()` : c'est
    // elle, et aucune autre requete, qui decide si une candidature revient.
    $vueParLaGardeDuVivier = DB::table('opt_out')
        ->where('scope', 'vivier')
        ->where('email_hash', hash('sha256', $courriel))
        ->exists();

    expect($vueParLaGardeDuVivier)->toBeTrue();

    // Et celle du scraping (`ScrapedRecordIngestService`), qui interroge
    // `business` : l'ajout du vivier ne doit pas l'avoir perdue au passage.
    $vueParLaGardeDuScraping = DB::table('opt_out')
        ->where('scope', 'business')
        ->where('email_hash', hash('sha256', $courriel))
        ->exists();

    expect($vueParLaGardeDuScraping)->toBeTrue();
});

test('B15-001 — TEMOIN : une adresse jamais effacee n’est opposee dans AUCUN univers', function () {
    app(GdprErasureService::class)->erase('quelquun@example.invalid');

    expect(DB::table('opt_out')->where('email_hash', hash('sha256', 'quelquundautre@example.invalid'))->exists())
        ->toBeFalse();
    expect(app(DeduplicationService::class)->isOptedOut('quelquundautre@example.invalid'))->toBeFalse();
});

test('B15-001 — un second effacement de la meme adresse ne duplique pas les oppositions', function () {
    $courriel = 'deux.fois@example.invalid';

    app(GdprErasureService::class)->erase($courriel);
    app(GdprErasureService::class)->erase($courriel);

    // Une demande d'effacement se rejoue (reprise de file, double clic dans la
    // console). Si chaque passage ajoutait deux lignes, la table d'opposition
    // grossirait sans rien apprendre — et `opt_out` n'a pas de contrainte
    // d'unicite pour l'en empecher (mesure : `\d opt_out`, seul `opt_out_pkey`
    // est unique). Deux lignes attendues : UNE par univers, pas deux par appel.
    $lignes = DB::table('opt_out')->where('email_hash', hash('sha256', $courriel))->get();

    expect($lignes)->toHaveCount(2);
    expect($lignes->pluck('scope')->unique()->count())->toBe(2, 'les deux lignes portent le meme univers');
});

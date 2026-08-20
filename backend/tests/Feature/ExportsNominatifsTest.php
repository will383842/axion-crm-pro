<?php

/**
 * LES QUATRE EXPORTS DE DONNÉES NOMINATIVES, TENUS PAR DES GARDES.
 *
 * Ce fichier ferme trois constats de l'audit 360 qui portent tous sur le même
 * geste — « faire sortir des personnes du système » — et qui n'étaient tenus
 * par rien :
 *
 *   F36-008 (S1) : `GET /media/export` et `GET /journalists/export` rendaient
 *     500 à TOUS les comptes habilités. La seule garde existante
 *     (`ExportPermissionTest`) ne vérifiait que le refus opposé au `viewer` —
 *     un 403 n'entre jamais dans le contrôleur, donc la panne du contrôleur
 *     restait invisible. « La garde est la seule partie qui fonctionne. »
 *
 *   B16-008 / B12-010 (S1) : aucun des quatre exports ne laissait de trace au
 *     journal d'audit. `AuditHashChainLogger` ne journalisait que
 *     POST/PUT/PATCH/DELETE ; un export est un GET. Sortir 4 295 349 fiches
 *     nominatives sans trace n'est pas un défaut de confort : c'est
 *     l'impossibilité de répondre à « qui a emporté quoi, et quand ».
 *
 *   G41-007 (S1) : l'export CSV n'avait aucun plafond. Au volume de production
 *     il parcourt les 4,29 M de fiches en `chunkById(1000)`, soit 4 296
 *     allers-retours SQL, chacun suivi d'un `fputcsv` par ligne.
 *
 * ⚠️ Les fonctions de ce fichier portent un suffixe `Nominatif` : Pest déclare
 * les fonctions de test au niveau GLOBAL, et `csvBody()` / `utilisateurAvecRole()`
 * existent déjà dans `CompaniesExportTest.php` et `ExportPermissionTest.php`.
 * Une redéclaration serait une erreur fatale à l'échelle de la suite entière.
 */

use App\Models\Company;
use App\Models\Journalist;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ListeSuppression;
use App\Support\PlafondExport;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Le VRAI référentiel du dépôt : si le seeder change, ces tests bougent.
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-exp-nom', 'name' => 'WS', 'settings' => [],
    ]);

    // Spatie « teams » : l'attribution d'un rôle exige un contexte d'équipe
    // (`model_has_roles.team_id` NOT NULL). En requête réelle c'est
    // `SetCurrentWorkspace` qui le pose.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);

    $this->exportateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'exp-' . Str::random(6) . '@example.com',
        'name' => 'Exportateur',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $this->exportateur->assignRole('owner');

    $this->actingAs($this->exportateur);
});

/** Le corps d'une réponse streamée ne se lit qu'en la faisant tourner. */
function corpsCsvNominatif(TestResponse $reponse): string
{
    ob_start();
    $reponse->baseResponse->sendContent();

    return (string) ob_get_clean();
}

/** Les lignes du journal d'audit qui portent ce chemin, les plus récentes d'abord. */
function tracesAuditNominatif(string $cheminPartiel): array
{
    return DB::table('audit_logs')
        ->where('path', 'like', '%' . $cheminPartiel . '%')
        ->orderByDesc('id')
        ->get()
        ->all();
}

// ─────────────────────────────────────────────────────────────────────────
// F36-008 — les exports médias / journalistes rendaient 500
// ─────────────────────────────────────────────────────────────────────────

test('F36-008 : un ayant droit obtient bien le CSV des medias, pas un 500', function () {
    Media::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'RADIO DU CENTRE',
        'media_type' => 'radio',
        'email' => 'redaction@radio-centre.example',
        'phone' => '0102030405',
    ]);

    $reponse = $this->get('/api/v1/media/export');

    // ⬇️ LE DÉFAUT : 500 pour owner, admin et opérateur. La permission était
    // correctement posée, le contrôleur derrière ne tournait pas.
    $reponse->assertOk();

    $csv = corpsCsvNominatif($reponse);

    // TÉMOIN : la garde ne doit pas passer au vert sur un CSV vide (le
    // contrôleur rend un CSV en-tête-seul quand la table manque ou que le
    // workspace est absent — ce chemin-là ne prouve rien).
    $this->assertStringContainsString('RADIO DU CENTRE', $csv, 'Le CSV ne porte pas la fiche : export vide, la garde ne prouve rien.');
    $this->assertStringContainsString('redaction@radio-centre.example', $csv, "Le CSV ne porte pas l'adresse de redaction.");
});

test('F36-008 : un ayant droit obtient bien le CSV des journalistes, pas un 500', function () {
    $media = Media::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'LE QUOTIDIEN TEST',
        'media_type' => 'presse_quotidien',
    ]);

    Journalist::create([
        'workspace_id' => $this->workspace->id,
        'media_id' => $media->id,
        'first_name' => 'Claire',
        'last_name' => 'DURANDEAU',
        'email' => 'claire.durandeau@quotidien.example',
        'opt_out' => false,
    ]);

    $reponse = $this->get('/api/v1/journalists/export');

    $reponse->assertOk();

    $csv = corpsCsvNominatif($reponse);

    // TÉMOIN : idem — un CSV en-tête-seul passerait à côté du défaut.
    $this->assertStringContainsString('DURANDEAU', $csv, 'Le CSV ne porte pas le journaliste : export vide, la garde ne prouve rien.');
    $this->assertStringContainsString('LE QUOTIDIEN TEST', $csv, "La relation media n'est pas chargee dans le CSV.");
});

test('F36-008 : reparer le 500 ne rouvre PAS la porte aux opposes', function () {
    // Le 500 venait de la garde d'opposition elle-même (mauvais type passé à
    // `EligibiliteCampagne`). Le réparer sans la reposer aurait échangé une
    // panne visible contre une fuite silencieuse — exactement le pire troc.
    $media = Media::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'GAZETTE OPPOSEE',
        'media_type' => 'presse_hebdo',
        'email' => 'contact@gazette-opposee.example',
    ]);

    Media::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'GAZETTE LIBRE',
        'media_type' => 'presse_hebdo',
        'email' => 'contact@gazette-libre.example',
    ]);

    DB::table('opt_out')->insert([
        'scope' => 'business',
        // `email` reste NULL : la décision du 2026-08-18 retire l'adresse en
        // clair de cette table, l'empreinte seule fait foi.
        'email_hash' => ListeSuppression::empreinte('contact@gazette-opposee.example'),
        'source' => 'test',
        'created_at' => now(),
    ]);

    $csv = corpsCsvNominatif($this->get('/api/v1/media/export')->assertOk());

    $this->assertStringContainsString('GAZETTE LIBRE', $csv, "La fiche non opposee doit sortir : sans elle le test passerait au vert sur un CSV vide.");
    expect($csv)->not->toContain('GAZETTE OPPOSEE');
    expect($csv)->not->toContain('contact@gazette-opposee.example');
    expect($media->exists)->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────
// B16-008 / B12-010 — aucun des quatre exports ne laissait de trace
// ─────────────────────────────────────────────────────────────────────────

test('B16-008 : les quatre exports laissent une trace au journal', function () {
    Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => '910000001', 'denomination' => 'FICHE EXPORTEE',
        'signals' => [], 'metadata' => [],
    ]);

    // Le corps doit être consommé : la trace est posée par le middleware, donc
    // au retour de la réponse — pas pendant le streaming. On appelle quand même
    // `sendContent()` pour rester au plus près d'un vrai téléchargement.
    corpsCsvNominatif($this->get('/api/v1/companies/export')->assertOk());
    corpsCsvNominatif($this->get('/api/v1/media/export')->assertOk());
    corpsCsvNominatif($this->get('/api/v1/journalists/export')->assertOk());
    // Le quatrième : la portabilité RGPD. Jeton inconnu → 404, ce qui suffit :
    // ce qui doit être tracé, c'est la TENTATIVE de sortie, pas seulement sa
    // réussite.
    $this->get('/api/v1/rgpd/export/' . str_repeat('z', 48))->assertNotFound();

    foreach (['companies/export', 'media/export', 'journalists/export', 'rgpd/export'] as $chemin) {
        $traces = tracesAuditNominatif($chemin);
        $this->assertNotEmpty(
            $traces,
            "Aucune trace au journal pour l'export « {$chemin} » : 4 295 349 fiches nominatives peuvent sortir sans qu'on sache qui les a emportees.",
        );
    }

    // TÉMOIN 1 : la trace porte l'AUTEUR et le RÉSULTAT, pas seulement le
    // chemin. Une ligne anonyme ne répond pas à « qui a emporté quoi ».
    $trace = tracesAuditNominatif('companies/export')[0];
    expect(strtolower((string) $trace->user_id))->toBe(strtolower($this->exportateur->id));
    expect((int) $trace->status_code)->toBe(200);
    expect(strtolower((string) $trace->workspace_id))->toBe(strtolower($this->workspace->id));
});

test('B16-008 : le jeton de portabilite RGPD ne part PAS en clair au journal', function () {
    // Le chemin de cette route CONTIENT le secret : le jeton de téléchargement
    // vaut 7 jours et suffit, à lui seul, à récupérer l'export d'une personne.
    // L'écrire tel quel dans `audit_logs` reviendrait à recopier le secret dans
    // une table que `audit.view` rend lisible — la trace deviendrait la fuite.
    $jeton = 'JETONSECRETDEPORTABILITE' . str_repeat('q', 24);

    $this->get('/api/v1/rgpd/export/' . $jeton)->assertNotFound();

    $traces = tracesAuditNominatif('rgpd/export');
    $this->assertNotEmpty($traces, "L'export de portabilite RGPD ne laisse aucune trace.");

    foreach ($traces as $trace) {
        $this->assertStringNotContainsString(
            $jeton,
            (string) $trace->path,
            'Le jeton de portabilite est recopie en clair dans le journal d audit.',
        );
    }
});

test('B16-008 : on trace les exports, PAS toutes les lectures', function () {
    // TÉMOIN de non-régression inverse : journaliser tous les GET noierait le
    // journal (chaque écran du CRM en émet plusieurs par affichage) et rendrait
    // la chaîne d'audit inexploitable. La trace doit viser la SORTIE de
    // données, pas la consultation.
    $this->getJson('/api/v1/companies')->assertOk();

    $traces = DB::table('audit_logs')->where('path', 'api/v1/companies')->get();

    $this->assertCount(0, $traces->all(), 'Une simple consultation de liste est journalisee : le journal va se noyer.');
});

// ─────────────────────────────────────────────────────────────────────────
// G41-007 — l'export CSV n'avait aucun plafond
// ─────────────────────────────────────────────────────────────────────────

test('G41-007 : l export des entreprises s arrete au plafond et le DIT', function () {
    config(['exports.plafond_lignes' => 2]);

    foreach (['920000001', '920000002', '920000003'] as $index => $siren) {
        Company::create([
            'workspace_id' => $this->workspace->id,
            'siren' => $siren, 'denomination' => 'PLAFOND ' . $index,
            'signals' => [], 'metadata' => [],
        ]);
    }

    $csv = corpsCsvNominatif($this->get('/api/v1/companies/export')->assertOk());

    // ⬇️ LE DÉFAUT : sans plafond, les trois sortent — et à l'échelle réelle
    // ce sont 4 295 349 lignes qui sortent, en gelant l'application.
    $this->assertStringContainsString('PLAFOND 0', $csv, 'Le plafond mutile un export qui devrait commencer normalement.');
    $this->assertStringContainsString('PLAFOND 1', $csv, 'Le plafond mutile un export qui devrait commencer normalement.');
    expect($csv)->not->toContain('PLAFOND 2');

    // Un export tronqué SANS le dire est pire qu'un export lent : celui qui le
    // reçoit croit tenir la base entière.
    $this->assertStringContainsString('EXPORT TRONQUE', $csv, "L'export tronque ne signale pas qu'il est incomplet.");
});

test('G41-007 : sous le plafond, rien n est tronque ni annonce comme tel', function () {
    // TÉMOIN : une garde de plafond qui tronquerait TOUJOURS, ou qui crierait
    // « tronqué » sur un export complet, serait pire que l'absence de plafond.
    foreach (['930000001', '930000002'] as $index => $siren) {
        Company::create([
            'workspace_id' => $this->workspace->id,
            'siren' => $siren, 'denomination' => 'COMPLET ' . $index,
            'signals' => [], 'metadata' => [],
        ]);
    }

    $csv = corpsCsvNominatif($this->get('/api/v1/companies/export')->assertOk());

    $this->assertStringContainsString('COMPLET 0', $csv, "L'export complet a perdu une fiche.");
    $this->assertStringContainsString('COMPLET 1', $csv, "L'export complet a perdu une fiche.");
    expect($csv)->not->toContain('EXPORT TRONQUE');
});

test('G41-007 : le plafond couvre AUSSI les medias et les journalistes', function () {
    // Site jumeau : plafonner les entreprises seules laisserait deux exports
    // sur trois capables de geler l'application.
    config(['exports.plafond_lignes' => 1]);

    foreach (['MEDIA UN', 'MEDIA DEUX'] as $nom) {
        Media::create([
            'workspace_id' => $this->workspace->id,
            'name' => $nom, 'media_type' => 'radio',
        ]);
    }

    $media = Media::where('name', 'MEDIA UN')->first();
    foreach (['ALPHATEST', 'BETATEST'] as $nom) {
        Journalist::create([
            'workspace_id' => $this->workspace->id,
            'media_id' => $media->id,
            'first_name' => 'J', 'last_name' => $nom, 'opt_out' => false,
        ]);
    }

    $csvMedias = corpsCsvNominatif($this->get('/api/v1/media/export')->assertOk());
    $this->assertStringContainsString('MEDIA UN', $csvMedias, "Le plafond a vide l'export des medias au lieu de le borner.");
    expect($csvMedias)->not->toContain('MEDIA DEUX');
    $this->assertStringContainsString('EXPORT TRONQUE', $csvMedias, "L'export medias tronque ne le signale pas.");

    $csvJournalistes = corpsCsvNominatif($this->get('/api/v1/journalists/export')->assertOk());
    $this->assertStringContainsString('ALPHATEST', $csvJournalistes, "Le plafond a vide l'export des journalistes au lieu de le borner.");
    expect($csvJournalistes)->not->toContain('BETATEST');
    $this->assertStringContainsString('EXPORT TRONQUE', $csvJournalistes, "L'export journalistes tronque ne le signale pas.");
});

test('G41-007 : le plafond par defaut est fini, et il est bas', function () {
    // TÉMOIN de la valeur par défaut : les tests ci-dessus la SURCHARGENT, donc
    // aucun d'eux ne prouverait qu'un plafond existe hors configuration. Sans
    // cette assertion, une valeur par défaut égale à `PHP_INT_MAX` laisserait
    // toute la suite au vert tout en gelant la production.
    expect(PlafondExport::LIGNES_PAR_DEFAUT)->toBeLessThanOrEqual(100000);
    expect(PlafondExport::LIGNES_PAR_DEFAUT)->toBeGreaterThan(0);
    // Et sans configuration, c'est bien cette valeur qui s'applique.
    expect(PlafondExport::lignes())->toBe(PlafondExport::LIGNES_PAR_DEFAUT);
});

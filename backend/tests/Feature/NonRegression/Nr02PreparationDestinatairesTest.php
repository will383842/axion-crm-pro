<?php

/**
 * NR-02 — R4, la préparation des destinataires (`crm:campagne:destinataires`).
 *
 * Sur un jeu FACTICE FIXE, la commande doit rendre EXACTEMENT la même liste et
 * les mêmes compteurs d’écart qu’aujourd’hui (instantané
 * `instantanes/nr02-destinataires.json`). Le futur moteur d’envoi (C1) et la
 * résolution des destinataires ne doivent pas changer ce résultat sans que la
 * PR le dise (« Filet modifié : pourquoi »).
 *
 * Les gardes existantes (`ResolveurDestinatairesTest`, `CampagneAdressesPartageesTest`,
 * `CampagnesB2bTest`) sont rattachées au groupe par `tests/Pest.php`.
 *
 * Aucun e-mail, aucun appel réseau : le DNS est simulé (`ResolveurDnsSimule`).
 */

use App\Crm\FichesProtegees;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NonRegression\Instantane;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $slug = 'nr02-' . Str::lower(Str::random(6));
    Workspace::create(['id' => $this->espace, 'slug' => $slug, 'name' => 'NR-02']);
    config(['crm.ingest.business_workspace' => $slug]);

    $this->tag = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAGS[0], 'name' => 'Organisateurs',
        'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->club = nr02Orga($this, 'evt:nr02-club', 'Club Nr02', 'bureau@club.example.test');
    $this->jumeau = nr02Orga($this, 'evt:nr02-jumeau', 'Club Nr02 (autre nom)', 'bureau@club.example.test');
    $this->seul = nr02Orga($this, 'evt:nr02-seul', 'Seul Nr02', 'accueil@seul.example.test');

    nr02Contact($this, $this->club, 'Pro', 'pro@club.example.test');
    nr02Contact($this, $this->club, 'Perso', 'perso@gmail.example.test', ['metadata' => json_encode(['email_nature' => 'perso'])]);
    nr02Contact($this, $this->club, 'Invalide', 'invalide@club.example.test', ['email_status' => 'invalid']);
    nr02Contact($this, $this->club, 'Oppose', 'oppose@club.example.test');
    nr02Contact($this, $this->seul, 'Deja', 'deja@seul.example.test', ['first_info_at' => '2026-10-01 10:00:00']);
    DB::table('opt_out')->insert([
        'email_hash' => hash('sha256', 'oppose@club.example.test'), 'scope' => 'business',
        'source' => 'nr02', 'created_at' => now(),
    ]);

    // Dates fixes loin dans le futur : l’instantané ne dépend pas de l’horloge.
    nr02Evenement($this, $this->club, '2099-03-15');
    nr02Evenement($this, $this->club, '2099-09-01');

    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'siren' => '900000941', 'denomination' => 'Ordinaire Nr02',
        'email_generic' => 'ordinaire@autre.example.test', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function () {
    foreach ($GLOBALS['nr02_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['nr02_fichiers'] = [];
});

function nr02Orga(object $t, string $fid, string $nom, ?string $generique): int
{
    $id = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $t->espace, 'siren' => null, 'country_code' => 'FR', 'foreign_id' => $fid,
        'entity_nature' => 'reseau', 'denomination' => $nom, 'email_generic' => $generique,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $id, 'tag_id' => $t->tag, 'workspace_id' => $t->espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);

    return $id;
}

/** @param  array<string, mixed>  $attrs */
function nr02Contact(object $t, int $companyId, string $nom, string $email, array $attrs = []): int
{
    return (int) DB::table('contacts')->insertGetId(array_merge([
        'workspace_id' => $t->espace, 'company_id' => $companyId, 'last_name' => $nom,
        'email' => $email, 'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

function nr02Evenement(object $t, int $companyId, string $date): int
{
    $id = (int) DB::table('events')->insertGetId([
        'workspace_id' => $t->espace, 'external_ref' => 'nr02-' . Str::random(8), 'nom' => 'Salon Nr02',
        'type' => 'salon', 'date_debut' => $date, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('event_organizers')->insert([
        'event_id' => $id, 'company_id' => $companyId, 'workspace_id' => $t->espace, 'created_at' => now(),
    ]);

    return $id;
}

/** @return array{lignes: list<array<string, mixed>>, bilan: array<string, int>, code: int} */
function nr02Preparer(array $options = []): array
{
    ResolveurDnsSimule::toutVerifier();
    $sortie = tempnam(sys_get_temp_dir(), 'nr02-');
    $GLOBALS['nr02_fichiers'][] = $sortie;
    $code = Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $sortie] + $options);
    $texte = Artisan::output();

    $bilan = [];
    if (preg_match_all('/\|\s*([a-z_]+)\s*\|\s*(\d+)\s*\|/', $texte, $m, PREG_SET_ORDER)) {
        foreach ($m as $ligne) {
            $bilan[$ligne[1]] = (int) $ligne[2];
        }
    }
    ksort($bilan);

    $lignes = array_values(array_filter(array_map(
        fn ($l) => json_decode($l, true),
        file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    )));
    // Les identifiants de base ne sont pas stables d’une exécution à l’autre : on les retire de la photo.
    $lignes = array_map(static function (array $l): array {
        unset($l['crm_ref'], $l['organisation_id'], $l['domaine_verifie_le']); // identifiants et date du jour : instables
        if (isset($l['evenement']) && is_array($l['evenement'])) {
            unset($l['evenement']['id']);
            // La garde « aucune donnée nominative » interdit les clés `nom`/`prenom` dans le dépôt public.
            $l['evenement']['libelle'] = $l['evenement']['nom'] ?? null;
            unset($l['evenement']['nom']);
        }
        foreach (['prenom' => 'prenom_factice', 'nom' => 'nom_factice'] as $ancienne => $nouvelle) {
            $l[$nouvelle] = $l[$ancienne] ?? null;
            unset($l[$ancienne]);
        }

        return $l;
    }, $lignes);
    usort($lignes, static fn (array $a, array $b): int => strcmp((string) $a['email'], (string) $b['email']));

    return ['lignes' => $lignes, 'bilan' => $bilan, 'code' => $code];
}

test('NR-02 la liste et les compteurs d’écart sont ceux de la photo de référence', function () {
    $r = nr02Preparer();

    expect($r['code'])->toBe(0);
    Instantane::verifier('nr02-destinataires', ['lignes' => $r['lignes'], 'bilan' => $r['bilan']]);
});

test('NR-02 l’option --non-informes écarte les adresses déjà informées', function () {
    $emails = array_column(nr02Preparer(['--non-informes' => true])['lignes'], 'email');

    expect($emails)->not->toContain('deja@seul.example.test')
        ->and($emails)->toContain('bureau@club.example.test');
});

test('NR-02 une opposition ne figure JAMAIS dans la liste, quelle que soit l’option', function () {
    foreach ([[], ['--non-informes' => true], ['--avec-adresses-partagees' => true]] as $options) {
        expect(array_column(nr02Preparer($options)['lignes'], 'email'))->not->toContain('oppose@club.example.test');
    }
});

test('NR-02 la préparation n’écrit rien en base', function () {
    $avant = [DB::table('opt_out')->count(), DB::table('email_suppressions')->count(), DB::table('activities')->count(), DB::table('contacts')->whereNotNull('first_info_at')->count()];

    nr02Preparer();

    expect([DB::table('opt_out')->count(), DB::table('email_suppressions')->count(), DB::table('activities')->count(), DB::table('contacts')->whereNotNull('first_info_at')->count()])->toBe($avant);
});

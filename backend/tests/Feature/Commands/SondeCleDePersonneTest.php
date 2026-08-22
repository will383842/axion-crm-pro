<?php

/**
 * GARDE `A05-001` — UNE PANNE QUI NE FAIT AUCUN BRUIT.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `contacts.person_key` est la clé de rapprochement des personnes. Sans elle, la
 * fiche 360° n'est offerte pour AUCUNE personne, et le rapprochement entre
 * univers est inerte. Elle se calcule avec `CRM_PERSON_KEY_SECRET`, dont le
 * défaut est la **chaîne vide**.
 *
 * ── CE QUE CETTE GARDE DÉFEND, ET CE QU'ELLE NE PEUT PAS DÉFENDRE ──────────
 *
 * Elle ne vérifie pas que le secret est posé en production : un secret de
 * production ne vit pas dans le dépôt, et aucun test ne peut l'y trouver.
 *
 * Elle défend ce qui est réellement en jeu : **que son absence fasse du bruit**.
 * Mesure du 2026-08-21, avant ce lot —
 *
 *   la migration de remplissage ....... s'ajourne, journalise UNE fois, passe
 *   `crm:remplir-cle-personne` ........ planifiée NULLE PART
 *   une sonde .......................... aucune
 *
 * Le produit tournait donc sans la moindre erreur, et une fonctionnalité
 * entière n'existait pas. *Une panne silencieuse est une panne qu'on ne répare
 * jamais* — c'est le patron `A08-001 / B16-006`, reproché ailleurs dans ce même
 * dépôt à une commande qui avait cessé de tourner sans que personne le voie.
 *
 * Les trois chemins sont mesurés, y compris le succès : sans lui, une sonde qui
 * crierait TOUJOURS passerait pour une sonde qui marche.
 */

use App\Console\Commands\CrmSondeCleDePersonne;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function a05Espace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'a05-' . Str::random(6),
        'name' => 'Espace sonde',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** `contacts.company_id` est NOT NULL : une fiche personne pend toujours a une entreprise. */
function a05Entreprise(string $espace): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace,
        // `companies_identity_anchor_check` : une entreprise porte TOUJOURS un
        // ancrage d'identite — un SIREN, ou un identifiant etranger.
        'siren' => str_pad((string) random_int(100000000, 999999999), 9, '0'),
        'denomination' => 'Entreprise sonde',
        'is_artisan' => false,
        'quality_score' => 0,
        'signals' => '{}',
        'metadata' => '{}',
        'field_origins' => '{}',
        'prospection_status' => 'pending',
        'website_status' => 'unknown',
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'country_code' => 'FR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function a05Contact(string $espace, ?string $email, ?string $cle = null): void
{
    DB::table('contacts')->insert([
        'workspace_id' => $espace,
        'company_id' => a05Entreprise($espace),
        'last_name' => 'Sonde',
        'email' => $email,
        'person_key' => $cle,
        'sources' => '{}',
        'metadata' => '{}',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// 1. LE SECRET ABSENT — le cas de la production au 2026-08-21
// ---------------------------------------------------------------------------

test('A05-001 — sans secret, la sonde CRIE et sort en echec', function () {
    config(['crm.person_key.secret' => '']);

    Log::spy();

    $this->artisan(CrmSondeCleDePersonne::SIGNATURE_PLANIFIEE)->assertFailed();

    // 🔑 Le CRITICAL n'est pas decoratif : c'est lui que le crochet
    // `onFailure()` de `routes/console.php` releve, et c'est lui qu'un humain
    // finira par lire. Une sonde qui echoue en silence ne vaut pas mieux que
    // pas de sonde.
    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $m): bool => str_contains($m, CrmSondeCleDePersonne::PREFIXE_ALERTE))
        ->once();
});

test('A05-001 — le cri DIT LE GESTE, il ne se contente pas de constater', function () {
    config(['crm.person_key.secret' => '']);

    $sortie = '';
    Log::spy();
    Log::shouldReceive('critical')->andReturnUsing(function (string $m) use (&$sortie): void {
        $sortie = $m;
    });

    $this->artisan(CrmSondeCleDePersonne::SIGNATURE_PLANIFIEE)->assertFailed();

    // Une alerte qui dit « c'est casse » sans dire quoi faire coute une heure a
    // celui qui la lit six mois plus tard. Celle-ci nomme la variable, sa
    // source, l'endroit ou la poser, et la commande a rejouer ensuite.
    expect($sortie)->toContain('CRM_PERSON_KEY_SECRET');
    expect($sortie)->toContain('PII_ENCRYPTION_KEY');
    expect($sortie)->toContain('crm:remplir-cle-personne');
});

// ---------------------------------------------------------------------------
// 2. LE SECRET POSÉ, MAIS LE STOCK PAS ENCORE RATTACHÉ
// ---------------------------------------------------------------------------

test('A05-001 — secret pose mais fiches en attente : la sonde le dit, et compte', function () {
    config(['crm.person_key.secret' => 'un-secret-de-mesure-2026']);

    $espace = a05Espace();
    a05Contact($espace, 'sans-cle-1@sonde.test');
    a05Contact($espace, 'sans-cle-2@sonde.test');
    a05Contact($espace, 'deja@sonde.test', 'une-cle-deja-posee');

    $vu = '';
    Log::spy();
    Log::shouldReceive('warning')->andReturnUsing(function (string $m) use (&$vu): void {
        $vu = $m;
    });

    $this->artisan(CrmSondeCleDePersonne::SIGNATURE_PLANIFIEE)->assertFailed();

    // Le COMPTE compte : « il reste des fiches » n'aide personne a decider si
    // c'est un oubli de deploiement ou un stock de 1,3 million.
    expect($vu)->toContain('2 fiche(s)');
    expect($vu)->toContain('crm:remplir-cle-personne');
});

test('A05-001 — TEMOIN : une fiche SANS adresse n est pas comptee comme en attente', function () {
    config(['crm.person_key.secret' => 'un-secret-de-mesure-2026']);

    $espace = a05Espace();
    a05Contact($espace, null);              // aucune adresse : rien a rapprocher
    a05Contact($espace, '   ');             // adresse vide : idem

    // La cle se calcule SUR l'adresse. Compter ces fiches-la ferait crier la
    // sonde pour toujours, sur un stock que rien ne peut rattacher — et une
    // alarme qu'on ne peut pas eteindre finit par etre ignoree.
    $this->artisan(CrmSondeCleDePersonne::SIGNATURE_PLANIFIEE)->assertSuccessful();
});

// ---------------------------------------------------------------------------
// 3. LE SUCCÈS — sans lui, une sonde qui crie toujours passerait pour bonne
// ---------------------------------------------------------------------------

test('A05-001 — TEMOIN : secret pose et stock rattache, la sonde se TAIT', function () {
    config(['crm.person_key.secret' => 'un-secret-de-mesure-2026']);

    $espace = a05Espace();
    a05Contact($espace, 'rattachee@sonde.test', 'une-cle-posee');

    Log::spy();

    $this->artisan(CrmSondeCleDePersonne::SIGNATURE_PLANIFIEE)->assertSuccessful();

    Log::shouldNotHaveReceived('critical');
    Log::shouldNotHaveReceived('warning');
});

test('A05-001 — le silence est MERITE, mais pas muet : la sonde dit sa COUVERTURE', function () {
    config(['crm.person_key.secret' => 'un-secret-de-mesure-2026']);

    $espace = a05Espace();
    a05Contact($espace, 'rattachee-1@sonde.test', 'cle-1');
    a05Contact($espace, 'rattachee-2@sonde.test', 'cle-2');
    a05Contact($espace, null);        // sans adresse : hors de portee
    a05Contact($espace, '   ');       // adresse vide : idem

    // 🔑 POURQUOI CE TEST EXISTE. « Aucune fiche en attente » est VRAI et
    // pourtant trompeur : la sonde ne compte que les fiches qui portent une
    // adresse, puisque la cle se calcule sur elle. Mesure du 2026-08-21 en
    // production : 410 481 fiches rattachees sur 1 319 567 — les 909 086 autres
    // n'ont AUCUNE adresse et n'auront jamais de fiche 360.
    //
    // Un operateur qui lit « rien a faire » en conclurait que toute personne est
    // atteignable. C'est faux, et ce n'est pas un defaut a reparer : c'est une
    // couverture a connaitre. *Une sonde qui se tait sur ce qu'elle ne mesure
    // pas laisse croire qu'elle mesure tout.*
    // ⚠️ `Artisan::call()` + `Artisan::output()`, et NON
    // `$this->artisan(...)->expectsOutputToContain(...)`.
    //
    // `expectsOutputToContain` compare LIGNE PAR LIGNE, et le formateur de
    // console COUPE un message long au milieu : « 2 sans adresse » se retrouvait
    // a cheval sur deux lignes, et l'assertion echouait sur une sortie pourtant
    // exacte. Mesure du 2026-08-21 : la sortie contenait bien la phrase entiere.
    // Une assertion qui rougit sur un retour a la ligne mesure la largeur du
    // terminal, pas le produit.
    $code = Artisan::call(CrmSondeCleDePersonne::SIGNATURE_PLANIFIEE);
    $sortie = Artisan::output();

    expect($code)->toBe(0);
    expect(str_contains($sortie, '2 fiche(s) rattachee(s)'))->toBeTrue(
        'La sonde ne dit pas combien de fiches sont rattachees.
Sortie : ' . $sortie,
    );
    expect(str_contains($sortie, '2 sans adresse'))->toBeTrue(
        'La sonde ne dit pas combien de fiches sont HORS DE PORTEE. Son silence laisse alors
'
        . 'croire que toute personne a une fiche 360, ce qui est faux.
Sortie : ' . $sortie,
    );
});

// ---------------------------------------------------------------------------
// 4. LA SONDE EST-ELLE SEULEMENT PLANIFIÉE ?
// ---------------------------------------------------------------------------

test('A05-001 — la sonde est PLANIFIEE : sans cela, elle ne sonnerait jamais', function () {
    // 🔴 C'EST LE CŒUR DU CONSTAT. `crm:remplir-cle-personne` existait depuis le
    // debut et n'etait planifiee NULLE PART : elle ne pouvait donc rien
    // signaler. Une sonde non planifiee reproduit exactement le defaut qu'elle
    // pretend fermer.
    $planifiees = collect(app(Schedule::class)->events())
        ->map(fn ($e) => (string) $e->command)
        ->filter(fn (string $c): bool => str_contains($c, CrmSondeCleDePersonne::SIGNATURE_PLANIFIEE));

    expect($planifiees)->not->toBeEmpty(
        'La sonde A05-001 n est pas planifiee : elle ne sonnera jamais toute seule, '
        . 'et le silence que ce lot ferme se rouvre entierement.',
    );
});

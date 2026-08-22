<?php

/**
 * GARDE `C19-010` — LE GUETTEUR VOIT-IL CE QU'IL PRÉTEND GUETTER ?
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Cette sonde ne répare rien, et c'est délibéré. Comptage sur la production du
 * 2026-08-21, sur 4 295 349 fiches :
 *
 *     fiches marquées `[ND]` ..... 0
 *     fiches sans dénomination ... 0
 *     fiches en corbeille ........ 0
 *
 * *Il n'y avait rien à rattraper.* Écrire une commande qui rejoue l'INSEE fiche
 * par fiche pour zéro ligne, c'eût été du code non exercé — donc du code qui
 * pourrit, et qu'on découvre cassé le jour où on en a besoin.
 *
 * Ce qui reste à défendre, c'est la RÉAPPARITION : si une seule fiche non
 * diffusible revient, c'est que l'entrée s'est rouverte, et cela doit s'apprendre
 * le jour même.
 *
 * ⚠️ Ces gardes mesurent donc trois choses, et pas une de plus : que le guetteur
 * VOIT les deux formes, qu'il ne crie PAS sur des fiches légitimes, et qu'il ne
 * se taise jamais sans dire sur quoi il a compté.
 */

use App\Console\Commands\CrmSondeNonDiffusibles;
use App\Services\Alertes\AlerteTelegram;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    AlerteTelegram::oublierLAvertissement();
    // Le canal n'est PAS configuré ici : on mesure la sonde, pas Telegram. Son
    // absence est déjà mesurée par `AlerteTelegramTest`.
    config(['alertes.telegram.token' => '', 'alertes.telegram.chat_id' => '']);
    Http::fake();
});

function ndEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'nd-sonde-' . Str::random(6),
        'name' => 'Espace sonde ND',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function ndFiche(string $espace, ?string $denomination, string $source = 'insee', bool $corbeille = false): void
{
    DB::table('companies')->insert([
        'deleted_at' => $corbeille ? now() : null,
        'workspace_id' => $espace,
        'siren' => str_pad((string) random_int(100000000, 999999999), 9, '0'),
        'denomination' => $denomination,
        'discovery_source' => $source,
        'signals' => '{}',
        'metadata' => '{}',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── 1. LE SILENCE MÉRITÉ, ET CE QU'IL DOIT DIRE ─────────────────────────────

test('C19-010/sonde — base saine : elle se tait, et DIT sur quoi elle a compte', function () {
    $espace = ndEspace();
    ndFiche($espace, 'Fabrique Lumiere SAS');
    ndFiche($espace, 'Atelier Durand');

    $code = Artisan::call(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE);
    $sortie = Artisan::output();

    expect($code)->toBe(0);

    // Un silence qui ne dit pas sur quoi il porte est indiscernable d'une sonde
    // qui n'a rien regardé. Celle-ci annonce le volume qu'elle a inspecté.
    expect(str_contains($sortie, 'Aucune fiche non diffusible'))->toBeTrue(
        "La sonde se tait sans rien dire.\nSortie : " . $sortie,
    );
    expect(str_contains($sortie, 'sur 2 fiches vivantes'))->toBeTrue(
        "La sonde ne dit pas sur combien de fiches elle a compte : son silence ne prouve\n"
        . "alors rien.\nSortie : " . $sortie,
    );
});

// ── 2. LES DEUX FORMES QU'ELLE DOIT VOIR ────────────────────────────────────

test('C19-010/sonde — une fiche marquee « [ND] » la fait CRIER', function () {
    $espace = ndEspace();
    ndFiche($espace, '[ND]');
    ndFiche($espace, 'Fabrique Lumiere SAS');

    Log::spy();

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertFailed();

    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $m): bool => str_contains($m, CrmSondeNonDiffusibles::PREFIXE_ALERTE))
        ->once();
});

test('C19-010/sonde — la forme « [ND] [ND] » aussi : la sous-chaine, pas l egalite', function () {
    // 🔑 C'est le defaut d'origine du rattrapage : une egalite stricte sur
    // `'[ND]'` ne voyait pas `'[ND] [ND]'`, la forme d'une personne PHYSIQUE
    // opposee. La sonde ne doit pas refaire la meme erreur.
    $espace = ndEspace();
    ndFiche($espace, '[ND] [ND]');

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertFailed();
});

test('C19-010/sonde — une fiche INSEE SANS denomination la fait crier aussi', function () {
    $espace = ndEspace();
    ndFiche($espace, null, 'insee');

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertFailed();
});

// ── 3. CE SUR QUOI ELLE NE DOIT PAS CRIER ───────────────────────────────────

test('C19-010/sonde — TEMOIN : une fiche sans nom venue d AILLEURS ne la fait pas crier', function () {
    // ⚠️ Une fiche sans denomination importee a la main ou nee d'une campagne
    // n'a RIEN a voir avec l'opposition INSEE. La compter ferait crier la sonde
    // sur un stock qu'aucun geste ne peut reduire — et une alarme qu'on ne peut
    // pas eteindre finit ignoree, y compris le jour ou elle a raison.
    $espace = ndEspace();
    ndFiche($espace, null, 'campaign');
    ndFiche($espace, null, 'import-manuel');

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertSuccessful();
});

test('C19-010/sonde — TEMOIN : une denomination qui contient « ND » sans crochets ne compte pas', function () {
    // « GRAND DUC », « LES NDs » : le marqueur INSEE est `[ND]`, avec ses
    // crochets. Un detecteur trop large accuserait des entreprises reelles.
    $espace = ndEspace();
    ndFiche($espace, 'GRAND DUC SARL');
    ndFiche($espace, 'ND CONSEIL');
    ndFiche($espace, 'Andre et Fils');

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertSuccessful();
});

// ── 3 bis. LA CORBEILLE — de la donnee STOCKEE, pas de la donnee disparue ───

test('C19-010/sonde — une fiche marquee EN CORBEILLE la fait crier aussi', function () {
    // 🔑 Une fiche non diffusible mise a la corbeille reste de la donnee
    // PERSONNELLE STOCKEE : la suppression douce ne l'efface pas, elle la cache.
    // La sonde doit la voir — mais separement, parce qu'elle ne se traite pas du
    // meme geste qu'une fiche vivante.
    $espace = ndEspace();
    ndFiche($espace, '[ND]', 'insee', corbeille: true);
    ndFiche($espace, 'Fabrique Lumiere SAS');

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertFailed();
});

test('C19-010/sonde — le cri DISTINGUE les vivantes des fiches en corbeille', function () {
    // Les compter ensemble melangerait deux situations qui appellent deux
    // gestes differents. Celui qui lit l'alerte a 3 h du matin doit savoir
    // laquelle il a devant lui.
    $espace = ndEspace();
    ndFiche($espace, '[ND]');
    ndFiche($espace, '[ND] [ND]', 'insee', corbeille: true);

    $vus = [];
    Log::spy();
    Log::shouldReceive('critical')->andReturnUsing(function (string $m) use (&$vus): void {
        $vus[] = $m;
    });

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertFailed();

    $vu = implode(' | ', $vus);

    expect(str_contains($vu, '1 fiche(s) VIVANTES'))->toBeTrue(
        'Le cri ne compte pas les fiches vivantes a part : ' . $vu,
    );
    expect(str_contains($vu, '1 autre(s) le portent EN CORBEILLE'))->toBeTrue(
        'Le cri ne distingue pas la corbeille : celui qui le lit ne saura pas quel geste faire. '
        . $vu,
    );
});

// ── 4. LE CRI DIT-IL LE GESTE ? ─────────────────────────────────────────────

test('C19-010/sonde — le cri DIT le geste, et met en garde contre la purge en masse', function () {
    $espace = ndEspace();
    ndFiche($espace, null, 'insee');

    // ⚠️ On accumule TOUS les `critical`, on n'en garde pas un seul.
    // `AlerteTelegram::envoyer()` en emet un lui aussi, avec le TITRE court : une
    // capture qui ecrase ne garderait que le dernier, et l'assertion rougirait
    // sur un message pourtant emis. Mesure du 2026-08-22 : c'est exactement ce
    // qui s'est passe a la premiere ecriture de ce test.
    $vus = [];
    Log::spy();
    Log::shouldReceive('critical')->andReturnUsing(function (string $m) use (&$vus): void {
        $vus[] = $m;
    });

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertFailed();

    $vu = implode(' | ', $vus);

    // Une alerte qui dit « c'est casse » sans dire quoi faire coute une heure a
    // celui qui la lit six mois plus tard. Et surtout, celle-ci doit RETENIR :
    // purger en masse les fiches sans denomination effacerait des entrepreneurs
    // individuels legitimes — le piege B15-004.
    expect(str_contains($vu, 'prospection:purge-non-diffusible'))->toBeTrue(
        'Le cri ne nomme pas la commande de rattrapage : ' . $vu,
    );
    expect(str_contains($vu, 'B15-004'))->toBeTrue(
        'Le cri ne met pas en garde contre la purge en masse des fiches sans denomination. '
        . 'Sans cet avertissement, celui qui le lit a 3 h du matin fera exactement le geste '
        . 'qui efface des clients reels.',
    );
    expect(str_contains($vu, 'HttpInseeClient'))->toBeTrue(
        'Le cri ne dit pas que la vraie cause est une ENTREE rouverte : on soignerait le '
        . 'symptome en laissant la source ouverte.',
    );
});

// ── 5. EST-ELLE SEULEMENT PLANIFIÉE ? ───────────────────────────────────────

test('C19-010/sonde — elle est PLANIFIEE : sans cela, elle ne guetterait jamais', function () {
    // 🔴 C'est le patron `A08-001 / B16-006`, deja paye deux fois dans cette
    // campagne : une piece qui ne tourne plus, et personne pour le voir. Une
    // sonde non planifiee reproduit exactement le defaut qu'elle ferme.
    $planifiees = collect(app(Schedule::class)->events())
        ->map(fn ($e) => (string) $e->command)
        ->filter(fn (string $c): bool => str_contains($c, CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE));

    expect($planifiees)->not->toBeEmpty(
        'La sonde C19-010 n est pas planifiee : elle ne guettera jamais toute seule, et le '
        . 'silence que ce lot installe se rouvre entierement.',
    );
});

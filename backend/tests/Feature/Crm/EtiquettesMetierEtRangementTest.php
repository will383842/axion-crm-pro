<?php

/**
 * CHANTIER 2 (2026-09-29) — le MÉTIER et le RANGEMENT des étiquettes, tels que
 * `crm:referentiels:reclasser` les applique.
 *
 * Chaque garde face à un TÉMOIN, et prouvée par son EFFET en base.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 94xxxxxxx, noms « ZZ ».
 */

use App\Crm\FichesProtegees;
use App\Crm\Taxonomy;
use App\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-metier-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ métier']);
});

/** @param  array<string, mixed>  $attrs */
function emFiche(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => (string) (940000000 + $seq),
        'denomination' => 'ZZ fiche ' . $seq,
        'discovery_source' => 'insee',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

/** @param  array<string, mixed>  $attrs */
function emTag(string $espace, string $slug, array $attrs = []): int
{
    return (int) DB::table('tags')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'slug' => $slug,
        'name' => $slug,
        'category' => 'custom',
        'kind' => 'auto',
        'rules' => '[]',
        'is_locked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

function emLier(string $espace, int $companyId, int $tagId, string $par = 'auto-rule'): void
{
    DB::table('company_tag')->insert([
        'company_id' => $companyId,
        'tag_id' => $tagId,
        'workspace_id' => $espace,
        'assigned_at' => now(),
        'assigned_by' => $par,
    ]);
}

/** @return list<string> */
function emSlugs(int $companyId): array
{
    $slugs = DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)->pluck('tags.slug')->all();
    sort($slugs);

    return array_values(array_map('strval', $slugs));
}

/** @return list<string> les seules étiquettes `metier-` de la fiche */
function emMetiers(int $companyId): array
{
    return array_values(array_filter(emSlugs($companyId), static fn (string $s): bool => str_starts_with($s, 'metier-')));
}

function emCompteur(string $sortie, string $compteur): ?int
{
    return preg_match('/\|\s*' . preg_quote($compteur, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m) === 1 ? (int) $m[1] : null;
}

function emExiste(string $espace, string $slug): bool
{
    return DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->exists();
}

/** @param  array<string, mixed>  $criteres */
function emAudience(string $espace, string $nom, array $criteres): void
{
    DB::table('email_audiences')->insert([
        'workspace_id' => $espace,
        'name' => $nom,
        'criteria' => json_encode($criteres, JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Tout ce que la commande pourrait écrire (fiches, liens, étiquettes). */
function emPhoto(string $espace): string
{
    return json_encode([
        DB::table('companies')->where('workspace_id', $espace)->orderBy('id')
            ->get(['id', 'sector_main', 'size_category', 'naf_rev2', 'updated_at'])->all(),
        DB::table('company_tag')->where('workspace_id', $espace)->orderBy('company_id')->orderBy('tag_id')->get()->all(),
        DB::table('tags')->where('workspace_id', $espace)->orderBy('id')->get(['id', 'slug', 'name', 'category', 'updated_at'])->all(),
    ], JSON_THROW_ON_ERROR);
}

/**
 * Juste APRÈS la première lecture d'un lot par la commande, une autre
 * écriture passe : elle s'exécute après l'inventaire des orphelines et avant
 * leur suppression.
 */
function emApresLecture(callable $ecriture): void
{
    $fait = false;
    DB::listen(function (QueryExecuted $requete) use (&$fait, $ecriture): void {
        if ($fait || ! str_starts_with(ltrim($requete->sql), 'SELECT c.id, c.naf_nomenclature')) {
            return;
        }
        $fait = true;
        $ecriture();
    });
}

function emVivier(): string
{
    return (string) DB::table('workspaces')->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)->value('id');
}

function emCandidat(string $vivier): int
{
    return (int) DB::table('candidates')->insertGetId([
        'workspace_id' => $vivier,
        'last_name' => 'ZZ TEST',
        'relation_type' => 'candidat_autre',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'precontractual',
        'attributes' => '{}',
        'experiences' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ══ Le métier ═══════════════════════════════════════════════════════════════

test('le reclassement pose l etiquette metier depuis naf_rev2, y compris pour un code de 1993 converti', function () {
    $rev2 = emFiche($this->espace, ['naf' => '69.20Z']);
    $rev1 = emFiche($this->espace, ['naf' => '74.1C']);        // → 69.20Z par la table INSEE
    $sansMetier = emFiche($this->espace, ['naf' => '74.90B']);  // fourre-tout : aucun métier
    $sansCode = emFiche($this->espace, ['naf' => null]);

    expect(Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]))->toBe(0);

    $tag = DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'metier-experts-comptables')->first();
    expect(emMetiers($rev2))->toBe(['metier-experts-comptables'])
        ->and(DB::table('companies')->where('id', $rev1)->value('naf_rev2'))->toBe('69.20Z')
        ->and(emMetiers($rev1))->toBe(['metier-experts-comptables'])
        ->and(emMetiers($sansMetier))->toBe([])
        ->and(emMetiers($sansCode))->toBe([])
        ->and($tag?->kind)->toBe('auto')
        ->and($tag?->category)->toBe('sector')
        ->and($tag?->is_locked)->toBeFalse()
        ->and($tag?->name)->toBe('Métier : Experts-comptables et cabinets comptables')
        ->and(DB::table('company_tag')->where('tag_id', $tag?->id)->pluck('assigned_by')->unique()->all())->toBe(['auto-rule']);
});

test('un code NAF qui change fait changer le metier ; une sous-classe sans metier n en garde aucun', function () {
    $coiffeur = emFiche($this->espace, ['naf' => '96.02A']);
    emLier($this->espace, $coiffeur, emTag($this->espace, 'metier-pharmacies', ['category' => 'sector']));
    $fourreTout = emFiche($this->espace, ['naf' => '74.90B']);
    emLier($this->espace, $fourreTout, emTag($this->espace, 'metier-coiffeurs', ['category' => 'sector']));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    expect(emMetiers($coiffeur))->toBe(['metier-coiffeurs'])
        ->and(emMetiers($fourreTout))->toBe([]);
});

test('jamais retirees : metier manuelle, verrouillee, posee par un utilisateur, proposee par l IA, src: ; le temoin automatique l est', function () {
    $id = emFiche($this->espace, ['naf' => '96.02A']);
    emLier($this->espace, $id, emTag($this->espace, 'metier-avocats-zz', ['category' => 'sector', 'kind' => 'manual']));
    emLier($this->espace, $id, emTag($this->espace, 'metier-dentistes', ['category' => 'sector', 'is_locked' => true]));
    emLier($this->espace, $id, emTag($this->espace, 'metier-medecins', ['category' => 'sector']), 'user');
    emLier($this->espace, $id, emTag($this->espace, 'metier-sante-ia', ['category' => 'intent', 'kind' => 'llm']), 'llm');
    emLier($this->espace, $id, emTag($this->espace, 'src:scraping-zz', ['category' => 'intent']));
    // Le TÉMOIN : automatique, plus désirée → retirée.
    emLier($this->espace, $id, emTag($this->espace, 'metier-pharmacies', ['category' => 'sector']));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    expect(emSlugs($id))
        ->toContain('metier-avocats-zz')
        ->toContain('metier-dentistes')
        ->toContain('metier-medecins')
        ->toContain('metier-sante-ia')
        ->toContain('src:scraping-zz')
        ->toContain('metier-coiffeurs')
        ->not->toContain('metier-pharmacies');
});

test('une fiche protegee ne recoit aucun metier sans l option, et le recoit avec', function () {
    $protegee = emFiche($this->espace, ['naf' => '69.20Z']);
    emLier($this->espace, $protegee, emTag($this->espace, FichesProtegees::TAG_ORGANISATEURS, ['is_locked' => true, 'category' => 'intent']));
    $temoin = emFiche($this->espace, ['naf' => '69.20Z']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    expect(emMetiers($protegee))->toBe([])
        ->and(emMetiers($temoin))->toBe(['metier-experts-comptables']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--inclure-protegees' => true]);
    expect(emMetiers($protegee))->toBe(['metier-experts-comptables']);
});

test('une etiquette metier hors referentiel est supprimee quand plus personne ne la porte, gardee sinon', function () {
    $id = emFiche($this->espace, ['naf' => '96.02A']);
    emLier($this->espace, $id, emTag($this->espace, 'metier-ancien-zz', ['category' => 'sector']));
    $autre = emFiche($this->espace, ['naf' => '96.02A']);
    emLier($this->espace, $autre, emTag($this->espace, 'metier-garde-zz', ['category' => 'sector']), 'user');

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $aBlanc = Artisan::output();
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $reel = Artisan::output();

    expect(emCompteur($aBlanc, 'etiquettes_obsoletes_a_supprimer'))->toBe(1)
        ->and(emCompteur($reel, 'etiquettes_obsoletes_supprimees'))->toBe(1)
        ->and(emExiste($this->espace, 'metier-ancien-zz'))->toBeFalse()
        ->and(emExiste($this->espace, 'metier-garde-zz'))->toBeTrue();
});

test('une audience d EXCLUSION qui cite un metier hors referentiel bloque l execution reelle', function () {
    $id = emFiche($this->espace, ['naf' => '96.02A']);
    emAudience($this->espace, 'ZZ sauf un metier disparu', ['not' => [
        ['field' => 'tags', 'op' => 'contains_any', 'value' => ['metier-disparu-zz']],
    ]]);
    // Témoin : une audience qui cite un métier DU référentiel ne gêne pas.
    emAudience($this->espace, 'ZZ les coiffeurs', ['all' => [
        ['field' => 'tags', 'op' => 'contains_any', 'value' => ['metier-coiffeurs']],
    ]]);

    expect(Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]))->toBe(1);
    $sortie = Artisan::output();

    expect($sortie)->toContain('REFUS')
        ->and($sortie)->toContain('tags=metier-disparu-zz')
        ->and($sortie)->not->toContain('ZZ les coiffeurs')
        ->and(emSlugs($id))->toBe([]);
});

test('idempotente : une seconde execution n ajoute, ne retire, ne range ni ne supprime rien', function () {
    emFiche($this->espace, ['naf' => '43.22A', 'department_code' => '38']);
    emFiche($this->espace, ['naf' => '52.1D']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $photo = emPhoto($this->espace);
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $sortie = Artisan::output();

    expect(emPhoto($this->espace))->toBe($photo)
        ->and(emCompteur($sortie, 'etiquettes_ajoutees'))->toBe(0)
        ->and(emCompteur($sortie, 'etiquettes_retirees'))->toBe(0)
        ->and(emCompteur($sortie, 'etiquettes_orphelines_supprimees'))->toBe(0)
        ->and(emCompteur($sortie, 'etiquettes_ia_rangees'))->toBe(0);
});

// ══ Le rangement : les étiquettes IA ═══════════════════════════════════════

test('les etiquettes IA restees en intent passent en ia : ni supprimees, ni renommees, liens et updated_at intacts', function () {
    $id = emFiche($this->espace, ['naf' => '96.02A']);
    $ancienne = '2024-01-15 10:00:00';
    $ia = emTag($this->espace, 'cible-chaude-zz', ['kind' => 'llm', 'category' => 'intent', 'name' => 'Cible chaude', 'updated_at' => $ancienne]);
    emLier($this->espace, $id, $ia, 'llm');
    // Une catégorie choisie À LA MAIN pour une étiquette IA est gardée.
    $choisie = emTag($this->espace, 'scale-up-zz', ['kind' => 'llm', 'category' => 'custom']);
    emLier($this->espace, $id, $choisie, 'llm');
    // Une étiquette gouvernée de la même catégorie ne bouge pas.
    $svc = emTag($this->espace, 'svc:audit', ['category' => 'intent', 'is_locked' => true]);
    emLier($this->espace, $id, $svc);
    // Une étiquette IA VERROUILLÉE par un administrateur non plus.
    $verrouillee = emTag($this->espace, 'ia-verrou-zz', ['kind' => 'llm', 'category' => 'intent', 'is_locked' => true]);
    emLier($this->espace, $id, $verrouillee, 'llm');

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $aBlanc = Artisan::output();
    expect(DB::table('tags')->where('id', $ia)->value('category'))->toBe('intent');

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $reel = Artisan::output();

    $t = DB::table('tags')->where('id', $ia)->first();
    expect(emCompteur($aBlanc, 'etiquettes_ia_a_ranger'))->toBe(1)
        ->and(emCompteur($reel, 'etiquettes_ia_rangees'))->toBe(1)
        ->and($t?->category)->toBe('ia')
        ->and($t?->name)->toBe('Cible chaude')
        ->and(substr((string) $t?->updated_at, 0, 19))->toBe($ancienne)
        ->and(emSlugs($id))->toContain('cible-chaude-zz')
        ->and(DB::table('tags')->where('id', $choisie)->value('category'))->toBe('custom')
        ->and(DB::table('tags')->where('id', $svc)->value('category'))->toBe('intent')
        ->and(DB::table('tags')->where('id', $verrouillee)->value('category'))->toBe('intent');
});

// ══ Le rangement : les orphelines ══════════════════════════════════════════

test('les orphelines sont supprimees ; jamais une portee, verrouillee, manuelle, src:, gouvernee, a regle, ni citee par une audience', function () {
    $fiche = emFiche($this->espace, ['naf' => '96.02A']);
    // Supprimées : automatique et IA, portées par personne.
    emTag($this->espace, 'dept-99', ['category' => 'geo']);
    emTag($this->espace, 'cible-froide-zz', ['kind' => 'llm', 'category' => 'ia']);
    // Gardées — chacune pour UNE raison.
    emLier($this->espace, $fiche, emTag($this->espace, 'dept-98', ['category' => 'geo']));
    emTag($this->espace, 'decisionnaire-zz', ['kind' => 'manual']);
    emTag($this->espace, 'region-verrou-zz', ['category' => 'geo', 'is_locked' => true]);
    emTag($this->espace, 'src:scraping-ancien-zz', ['category' => 'intent']);
    emTag($this->espace, 'famille:ordre', ['category' => 'custom']);
    emTag($this->espace, 'dept-97', ['category' => 'geo', 'rules' => json_encode(['all' => [['field' => 'naf', 'op' => '=', 'value' => '96.02A']]])]);
    emTag($this->espace, 'dept-96', ['category' => 'geo']);
    emTag($this->espace, 'metier-pompes-funebres', ['category' => 'sector']);   // du référentiel
    emAudience($this->espace, 'ZZ departement 96', ['any' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['dept-96']]]]);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $aBlanc = Artisan::output();
    $restantesABlanc = DB::table('tags')->where('workspace_id', $this->espace)->count();
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $reel = Artisan::output();

    expect(emCompteur($aBlanc, 'etiquettes_orphelines_a_supprimer'))->toBe(2)
        ->and(emCompteur($aBlanc, 'garde_b15008_refuserait'))->toBe(0)
        // L'essai à blanc n'a rien supprimé.
        ->and($restantesABlanc)->toBe(10)
        ->and(emCompteur($reel, 'etiquettes_orphelines_supprimees'))->toBe(2)
        ->and(emExiste($this->espace, 'dept-99'))->toBeFalse()
        ->and(emExiste($this->espace, 'cible-froide-zz'))->toBeFalse();
    foreach (['dept-98', 'decisionnaire-zz', 'region-verrou-zz', 'src:scraping-ancien-zz', 'famille:ordre', 'dept-97', 'dept-96', 'metier-pompes-funebres'] as $gardee) {
        expect(emExiste($this->espace, $gardee))->toBeTrue("supprimée à tort : {$gardee}");
    }
});

test('une orpheline portee ou verrouillee pendant les lots n est pas supprimee', function () {
    $fiche = emFiche($this->espace, ['naf' => '96.02A']);
    $posee = emTag($this->espace, 'dept-95', ['category' => 'geo']);
    $verrouillee = emTag($this->espace, 'dept-90', ['category' => 'geo']);
    $temoin = emTag($this->espace, 'dept-94', ['category' => 'geo']);
    // APRÈS l'inventaire des orphelines, AVANT leur suppression : l'une est
    // posée sur une fiche, l'autre verrouillée par un administrateur.
    emApresLecture(function () use ($fiche, $posee, $verrouillee): void {
        emLier($this->espace, $fiche, $posee, 'user');
        DB::table('tags')->where('id', $verrouillee)->update(['is_locked' => true]);
    });

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $sortie = Artisan::output();

    expect(emCompteur($sortie, 'etiquettes_orphelines_a_supprimer'))->toBe(3)
        ->and(emCompteur($sortie, 'etiquettes_orphelines_supprimees'))->toBe(1)
        ->and(DB::table('tags')->where('id', $posee)->exists())->toBeTrue()
        ->and(emSlugs($fiche))->toContain('dept-95')
        ->and(DB::table('tags')->where('id', $verrouillee)->exists())->toBeTrue()
        ->and(DB::table('tags')->where('id', $temoin)->exists())->toBeFalse();
});

test('une etiquette que porte un CANDIDAT n est jamais une orpheline, meme posee pendant les lots', function () {
    $vivier = emVivier();
    $slugVivier = (string) DB::table('workspaces')->where('id', $vivier)->value('slug');
    $portee = emTag($vivier, 'dept-93', ['category' => 'geo']);
    $posee = emTag($vivier, 'dept-92', ['category' => 'geo']);
    $temoin = emTag($vivier, 'dept-91', ['category' => 'geo']);
    $candidat = emCandidat($vivier);
    $lierCandidat = static fn (int $tag) => DB::table('candidate_tag')->insert([
        'candidate_id' => $candidat, 'tag_id' => $tag, 'workspace_id' => $vivier,
        'assigned_by' => 'user', 'assigned_at' => now(),
    ]);
    $lierCandidat($portee);
    emApresLecture(fn () => $lierCandidat($posee));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $slugVivier]);
    $sortie = Artisan::output();

    expect(emCompteur($sortie, 'etiquettes_orphelines_a_supprimer'))->toBe(2)
        ->and(emCompteur($sortie, 'etiquettes_orphelines_supprimees'))->toBe(1)
        ->and(DB::table('tags')->where('id', $portee)->exists())->toBeTrue()
        ->and(DB::table('tags')->where('id', $posee)->exists())->toBeTrue()
        ->and(DB::table('tags')->where('id', $temoin)->exists())->toBeFalse();
});

test('B15-008 : un menage massif est refuse sans --force ; avec, il part par paquets bornes, chacun sous lock_timeout', function () {
    emFiche($this->espace, ['naf' => '96.02A']);
    $lignes = [];
    for ($i = 0; $i < 1200; $i++) {
        $lignes[] = [
            'workspace_id' => $this->espace, 'slug' => 'dept-zz' . $i, 'name' => 'ZZ ' . $i,
            'category' => 'geo', 'kind' => 'auto', 'rules' => '[]', 'is_locked' => false,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($lignes, 400) as $paquet) {
        DB::table('tags')->insert($paquet);
    }

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $refus = Artisan::output();
    expect(emCompteur($refus, 'garde_b15008_refuserait'))->toBe(1)
        ->and(emCompteur($refus, 'etiquettes_orphelines_supprimees'))->toBe(0)
        ->and(DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'like', 'dept-zz%')->count())->toBe(1200);

    $suppressions = [];
    $sequence = [];
    DB::listen(function (QueryExecuted $q) use (&$suppressions, &$sequence): void {
        $sql = strtolower(ltrim($q->sql));
        if (str_starts_with($sql, 'delete from "tags"')) {
            $suppressions[] = count($q->bindings);
            $sequence[] = 'suppression';
        }
        if (str_contains($sql, 'lock_timeout')) {
            $sequence[] = 'verrou';
        }
    });
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--force' => true]);
    $force = Artisan::output();

    expect(emCompteur($force, 'etiquettes_orphelines_supprimees'))->toBe(1200)
        ->and(DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'like', 'dept-zz%')->count())->toBe(0)
        // 1 200 orphelines → deux paquets, aucun au-delà de 1 000 identifiants
        // (+ une poignée de paramètres fixes : espace, kinds, verrou, src:).
        ->and(count($suppressions))->toBe(2)
        ->and(max($suppressions))->toBeLessThanOrEqual(1000 + 10);
    // Chaque paquet dans SA transaction, ouverte par un `lock_timeout` : la
    // suppression est toujours précédée d'un verrou borné, jamais d'une autre.
    foreach ($sequence as $i => $evenement) {
        if ($evenement === 'suppression') {
            expect($sequence[$i - 1] ?? null)->toBe('verrou');
        }
    }
});

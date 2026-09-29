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
use Illuminate\Database\Connection;
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
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--supprimer-etiquettes-orphelines' => true]);
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
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--supprimer-etiquettes-orphelines' => true]);
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

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--supprimer-etiquettes-orphelines' => true]);
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

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $slugVivier, '--supprimer-etiquettes-orphelines' => true]);
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

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--supprimer-etiquettes-orphelines' => true]);
    $refus = Artisan::output();
    expect(emCompteur($refus, 'garde_b15008_refuserait'))->toBe(1)
        ->and(emCompteur($refus, 'etiquettes_orphelines_supprimees'))->toBe(0)
        ->and(DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'like', 'dept-zz%')->count())->toBe(1200);

    $suppressions = [];
    $sequence = [];
    DB::listen(function (QueryExecuted $q) use (&$suppressions, &$sequence): void {
        $sql = strtolower(ltrim($q->sql));
        if (str_starts_with($sql, 'delete from tags')) {
            $suppressions[] = count(explode(',', trim((string) $q->bindings[0], '{}')));
            $sequence[] = 'suppression';
        } elseif (str_starts_with($sql, 'select id from tags where id = any') && str_ends_with(rtrim($sql), 'for update')) {
            $sequence[] = 'verrouillage';
        } elseif (str_starts_with($sql, 'lock table email_audiences in share mode')) {
            $sequence[] = 'audiences';
        } elseif (str_contains($sql, 'lock_timeout')) {
            $sequence[] = 'delai';
        }
    });
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--force' => true, '--supprimer-etiquettes-orphelines' => true]);
    $force = Artisan::output();

    expect(emCompteur($force, 'etiquettes_orphelines_supprimees'))->toBe(1200)
        ->and(DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'like', 'dept-zz%')->count())->toBe(0)
        // 1 200 orphelines → deux paquets, aucun au-delà de 1 000 identifiants.
        ->and($suppressions)->toBe([1000, 200]);
    // Chaque paquet dans SA transaction : un `lock_timeout`, PUIS le
    // verrouillage `FOR UPDATE`, PUIS le verrou SHARE des audiences (relues
    // ensuite), PUIS la suppression.
    foreach ($sequence as $i => $evenement) {
        if ($evenement === 'suppression') {
            expect($sequence[$i - 1] ?? null)->toBe('audiences')
                ->and($sequence[$i - 2] ?? null)->toBe('verrouillage')
                ->and($sequence[$i - 3] ?? null)->toBe('delai');
        }
    }

    // Une entrée d'audit PAR PAQUET, qui porte de quoi recréer chaque étiquette.
    $traces = DB::table('audit_logs')->where('workspace_id', $this->espace)
        ->where('event_type', 'RECLASSEMENT_ETIQUETTES_SUPPRIMEES')->orderBy('id')->get();
    expect($traces)->toHaveCount(2);
    $restaurables = [];
    foreach ($traces as $t) {
        $json = substr((string) $t->path, (int) strpos((string) $t->path, '['));
        expect(hash('sha256', $json))->toBe($t->payload_hash);
        foreach (json_decode($json, true, 512, JSON_THROW_ON_ERROR) as $e) {
            $restaurables[] = $e;
        }
    }
    expect($restaurables)->toHaveCount(1200)
        ->and(array_keys($restaurables[0]))->toContain('id', 'slug', 'name', 'category', 'kind', 'workspace_id')
        ->and($restaurables[0]['workspace_id'])->toBe($this->espace)
        ->and($restaurables[0]['slug'])->toStartWith('dept-zz')
        ->and($restaurables[0]['category'])->toBe('geo')
        ->and($restaurables[0]['kind'])->toBe('auto');
});

// ══ Ordre de Will (29/09) : aucune suppression sans décision explicite ═════

test('PAR DEFAUT, aucune etiquette n est supprimee : obsoletes et orphelines sont seulement comptees', function () {
    $id = emFiche($this->espace, ['naf' => '96.02A']);
    emLier($this->espace, $id, emTag($this->espace, 'metier-ancien-zz', ['category' => 'sector']));
    emTag($this->espace, 'dept-99', ['category' => 'geo']);
    emTag($this->espace, 'cible-froide-zz', ['kind' => 'llm', 'category' => 'ia']);
    $avant = DB::table('tags')->where('workspace_id', $this->espace)->pluck('slug')->all();

    expect(Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]))->toBe(0);
    $sortie = Artisan::output();

    $apres = DB::table('tags')->where('workspace_id', $this->espace)->pluck('slug')->all();
    expect(emCompteur($sortie, 'etiquettes_obsoletes_a_supprimer'))->toBe(1)
        ->and(emCompteur($sortie, 'etiquettes_orphelines_a_supprimer'))->toBe(2)
        ->and(emCompteur($sortie, 'etiquettes_obsoletes_supprimees'))->toBe(0)
        ->and(emCompteur($sortie, 'etiquettes_orphelines_supprimees'))->toBe(0)
        ->and(emCompteur($sortie, 'suppression_etiquettes_demandee'))->toBe(0)
        ->and($sortie)->toContain('AUCUNE supprimée')
        // Toutes les étiquettes d'avant sont encore là (des étiquettes de
        // classement ont pu s'ajouter).
        ->and(array_values(array_diff($avant, $apres)))->toBe([])
        // Le RETRAIT d'une étiquette automatique d'une fiche reste permis :
        // c'est du classement, l'étiquette elle-même demeure.
        ->and(emMetiers($id))->toBe(['metier-coiffeurs'])
        ->and(emExiste($this->espace, 'metier-ancien-zz'))->toBeTrue()
        ->and(DB::table('audit_logs')->where('event_type', 'RECLASSEMENT_ETIQUETTES_SUPPRIMEES')->exists())->toBeFalse();
});

test('une audience qui cite l etiquette la retient, dans les blocs all, any et not, et meme EN CORBEILLE', function () {
    emFiche($this->espace, ['naf' => '96.02A']);
    foreach (['dept-81', 'dept-82', 'dept-83', 'dept-84', 'dept-85'] as $slug) {
        emTag($this->espace, $slug, ['category' => 'geo']);
    }
    emAudience($this->espace, 'ZZ bloc all', ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['dept-81']]]]);
    emAudience($this->espace, 'ZZ bloc any', ['any' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['dept-82']]]]);
    emAudience($this->espace, 'ZZ bloc not', ['not' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['dept-83']]]]);
    emAudience($this->espace, 'ZZ en corbeille', ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['dept-84']]]]);
    DB::table('email_audiences')->where('workspace_id', $this->espace)->where('name', 'ZZ en corbeille')->update(['deleted_at' => now()]);
    // `dept-85` : citée par personne — le TÉMOIN, qui part.

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--supprimer-etiquettes-orphelines' => true]);
    $sortie = Artisan::output();

    expect(emCompteur($sortie, 'etiquettes_orphelines_supprimees'))->toBe(1)
        ->and(emExiste($this->espace, 'dept-85'))->toBeFalse();
    foreach (['dept-81', 'dept-82', 'dept-83', 'dept-84'] as $gardee) {
        expect(emExiste($this->espace, $gardee))->toBeTrue("supprimée malgré son audience : {$gardee}");
    }
});

test('une audience creee PENDANT les lots retient l etiquette qu elle cite : les audiences sont relues au moment de supprimer', function () {
    emFiche($this->espace, ['naf' => '96.02A']);
    emTag($this->espace, 'dept-86', ['category' => 'geo']);
    $temoin = emTag($this->espace, 'dept-87', ['category' => 'geo']);
    emApresLecture(fn () => emAudience($this->espace, 'ZZ creee pendant', [
        'not' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['dept-86']]],
    ]));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--supprimer-etiquettes-orphelines' => true]);
    $sortie = Artisan::output();

    // Les deux étaient orphelines à l'inventaire…
    expect(emCompteur($sortie, 'etiquettes_orphelines_a_supprimer'))->toBe(2)
        // … mais celle qu'une audience cite désormais reste.
        ->and(emCompteur($sortie, 'etiquettes_orphelines_supprimees'))->toBe(1)
        ->and(emExiste($this->espace, 'dept-86'))->toBeTrue()
        ->and(DB::table('tags')->where('id', $temoin)->exists())->toBeFalse();
});

test('un lien range dans un autre espace que son etiquette arrete tout le menage', function () {
    $fiche = emFiche($this->espace, ['naf' => '96.02A']);
    $orpheline = emTag($this->espace, 'dept-88', ['category' => 'geo']);
    $ailleurs = (string) Str::uuid();
    Workspace::create(['id' => $ailleurs, 'slug' => 'zz-ailleurs-' . Str::random(6), 'name' => 'ZZ ailleurs']);
    $etrangere = emTag($this->espace, 'dept-89', ['category' => 'geo']);
    // Le lien porte l'espace d'AILLEURS, l'étiquette celui-ci.
    emLier($ailleurs, $fiche, $etrangere);

    expect(Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--supprimer-etiquettes-orphelines' => true]))->toBe(1);
    $sortie = Artisan::output();

    expect($sortie)->toContain('REFUS du ménage')
        ->and(emCompteur($sortie, 'liens_etiquette_hors_espace'))->toBe(1)
        ->and(emCompteur($sortie, 'etiquettes_orphelines_supprimees'))->toBe(0)
        ->and(DB::table('tags')->where('id', $orpheline)->exists())->toBeTrue()
        ->and(DB::table('tags')->where('id', $etrangere)->exists())->toBeTrue();

    // Témoin : sans le lien fautif, la même orpheline part.
    DB::table('company_tag')->where('workspace_id', $ailleurs)->delete();
    expect(Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--supprimer-etiquettes-orphelines' => true]))->toBe(0)
        ->and(DB::table('tags')->where('id', $orpheline)->exists())->toBeFalse();
});

/**
 * LA COURSE, EN VRAI — deux connexions, deux processus.
 *
 * Un second processus PHP pose un lien vers l'étiquette orpheline, attend
 * 4 s, puis valide. Entre-temps, la commande (connexion propriétaire, hors
 * transaction de test : elle valide réellement) inventorie l'étiquette comme
 * orpheline — le lien n'est pas encore validé — puis tente de la supprimer :
 * son `SELECT … FOR UPDATE` ATTEND le verrou que la clé étrangère du lien tient.
 *
 * Quand le lien est validé, le `DELETE`, instruction SÉPARÉE, prend une photo
 * neuve, le voit, et ne supprime rien. Un `DELETE` unique, lui, évaluerait
 * son `NOT EXISTS` sur la photo d'AVANT l'attente : il supprimerait
 * l'étiquette, et la cascade `ON DELETE CASCADE` emporterait le lien validé.
 */
test('COURSE : un lien valide pendant l attente du verrou n est jamais emporte par la cascade', function () {
    /** @var Connection $proprio */
    $proprio = DB::connection('pgsql_owner');
    $espace = (string) Str::uuid();
    $slug = 'zz-course-' . substr($espace, 0, 8);
    $proprio->table('workspaces')->insert([
        'id' => $espace, 'slug' => $slug, 'name' => 'ZZ course',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $fiche = (int) $proprio->table('companies')->insertGetId([
        'workspace_id' => $espace, 'siren' => '9' . random_int(10000000, 99999999),
        'denomination' => 'ZZ course', 'naf' => '96.02A', 'discovery_source' => 'insee',
        'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0,
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tag = (int) $proprio->table('tags')->insertGetId([
        'workspace_id' => $espace, 'slug' => 'dept-zz-course', 'name' => 'ZZ course',
        'category' => 'geo', 'kind' => 'auto', 'rules' => '[]', 'is_locked' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $c = config('database.connections.pgsql_owner');
    $script = implode("\n", [
        '$pdo = new PDO(getenv("ZZ_DSN"), getenv("ZZ_USER"), getenv("ZZ_PASS"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);',
        '$pdo->beginTransaction();',
        '$pdo->prepare("INSERT INTO company_tag (company_id, tag_id, workspace_id, assigned_at, assigned_by) VALUES (?, ?, ?::uuid, now(), \'user\')")'
            . '->execute([(int) getenv("ZZ_FICHE"), (int) getenv("ZZ_TAG"), getenv("ZZ_ESPACE")]);',
        'fwrite(STDOUT, "pret\n"); fflush(STDOUT);',
        'sleep(4);',
        '$pdo->commit();',
        'fwrite(STDOUT, "valide\n");',
    ]);
    $env = array_merge(getenv(), [
        'ZZ_DSN' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']),
        'ZZ_USER' => (string) $c['username'], 'ZZ_PASS' => (string) $c['password'],
        'ZZ_FICHE' => (string) $fiche, 'ZZ_TAG' => (string) $tag, 'ZZ_ESPACE' => $espace,
    ]);
    $tubes = [];
    $processus = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, null, $env);
    expect($processus)->not->toBeFalse();

    $precedente = DB::getDefaultConnection();
    try {
        expect(trim((string) fgets($tubes[1])))->toBe('pret');

        // Combien de temps le verrouillage `FOR UPDATE` a-t-il attendu ?
        $attente = 0.0;
        $proprio->listen(function (QueryExecuted $q) use (&$attente): void {
            if (str_ends_with(rtrim(strtolower($q->sql)), 'for update')) {
                $attente = max($attente, (float) $q->time);
            }
        });
        DB::setDefaultConnection('pgsql_owner');
        $code = Artisan::call('crm:referentiels:reclasser', ['--workspace' => $slug, '--supprimer-etiquettes-orphelines' => true]);
        $sortie = Artisan::output();
        DB::setDefaultConnection($precedente);

        $fin = trim((string) stream_get_contents($tubes[1]));
        $erreurs = trim((string) stream_get_contents($tubes[2]));

        expect($erreurs)->toBe('')
            ->and($fin)->toBe('valide')
            ->and($code)->toBe(0)
            // TÉMOINS que la course a bien eu lieu : l'étiquette était orpheline
            // à l'inventaire, et le verrouillage a ATTENDU le lien (> 0,5 s).
            ->and(emCompteur($sortie, 'etiquettes_orphelines_a_supprimer'))->toBe(1)
            ->and($attente)->toBeGreaterThan(500.0)
            // L'effet : rien de supprimé, le lien validé est là.
            ->and(emCompteur($sortie, 'etiquettes_orphelines_supprimees'))->toBe(0)
            ->and($proprio->table('tags')->where('id', $tag)->exists())->toBeTrue()
            ->and($proprio->table('company_tag')->where('tag_id', $tag)->where('company_id', $fiche)->exists())->toBeTrue();
    } finally {
        DB::setDefaultConnection($precedente);
        foreach ($tubes as $t) {
            if (is_resource($t)) {
                fclose($t);
            }
        }
        if (is_resource($processus)) {
            proc_close($processus);
        }
        $proprio->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        $proprio->table('company_tag')->where('workspace_id', $espace)->delete();
        $proprio->table('companies')->where('workspace_id', $espace)->delete();
        $proprio->table('tags')->where('workspace_id', $espace)->delete();
        // Cette commande a VALIDÉ des lignes de journal : elles casseraient la
        // chaîne vérifiée par d'autres tests. Elles n'existent que pour celui-ci.
        $proprio->table('audit_logs')->where('workspace_id', $espace)->delete();
        $proprio->table('workspaces')->where('id', $espace)->delete();
        $proprio->disconnect();
    }
});

/**
 * SOUS LE RÔLE APPLICATIF (`pgsql_app`, RLS), comme en production dès que
 * `CRM_DB_APP_ROLE_ENABLED=true` : un lien rangé dans l'espace B vers une
 * étiquette de l'espace A est INVISIBLE à la commande qui travaille dans A.
 * L'étiquette y paraît donc orpheline — la supprimer emporterait le lien par
 * la cascade. Le contrôle des liens hors espace passe par une connexion qui
 * voit tout ; si aucune ne voit tout, la suppression est refusée.
 */
function emEspacesSousRls(): array
{
    /** @var Connection $proprio */
    $proprio = DB::connection('pgsql_owner');
    $ids = [];
    foreach (['a', 'b'] as $suffixe) {
        $id = (string) Str::uuid();
        $proprio->table('workspaces')->insert([
            'id' => $id, 'slug' => 'zz-em-rls-' . $suffixe . '-' . substr($id, 0, 8), 'name' => 'ZZ RLS',
            'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids[$suffixe] = $id;
    }
    $fiche = (int) $proprio->table('companies')->insertGetId([
        'workspace_id' => $ids['a'], 'siren' => '9' . random_int(10000000, 99999999),
        'denomination' => 'ZZ RLS', 'naf' => '96.02A', 'discovery_source' => 'insee',
        'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0,
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tag = static fn (string $slug): int => (int) $proprio->table('tags')->insertGetId([
        'workspace_id' => $ids['a'], 'slug' => $slug, 'name' => $slug,
        'category' => 'geo', 'kind' => 'auto', 'rules' => '[]', 'is_locked' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $orpheline = $tag('dept-zz-orpheline');
    $liee = $tag('dept-zz-liee-ailleurs');
    // Le lien porte l'espace B : sous la RLS, l'espace A ne le voit pas.
    $proprio->table('company_tag')->insert([
        'company_id' => $fiche, 'tag_id' => $liee, 'workspace_id' => $ids['b'],
        'assigned_at' => now(), 'assigned_by' => 'user',
    ]);

    return [
        'a' => $ids['a'], 'b' => $ids['b'], 'slug' => (string) $proprio->table('workspaces')->where('id', $ids['a'])->value('slug'),
        'fiche' => $fiche, 'orpheline' => $orpheline, 'liee' => $liee,
    ];
}

function emNettoyerSousRls(array $e): void
{
    $proprio = DB::connection('pgsql_owner');
    $proprio->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    foreach ([$e['a'], $e['b']] as $id) {
        $proprio->table('company_tag')->where('workspace_id', $id)->delete();
    }
    $proprio->table('companies')->where('workspace_id', $e['a'])->delete();
    foreach ([$e['a'], $e['b']] as $id) {
        $proprio->table('tags')->where('workspace_id', $id)->delete();
        // Lignes de journal VALIDÉES par ce test : elles casseraient la chaîne
        // vérifiée par d'autres tests.
        $proprio->table('audit_logs')->where('workspace_id', $id)->delete();
    }
    $proprio->table('workspaces')->whereIn('id', [$e['a'], $e['b']])->delete();
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    $proprio->disconnect();
}

test('SOUS RLS (pgsql_app) : le lien range dans un autre espace est VU, et la suppression refusee ; sans lui, elle part', function () {
    $e = emEspacesSousRls();
    $proprio = DB::connection('pgsql_owner');
    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

        $code = Artisan::call('crm:referentiels:reclasser', ['--workspace' => $e['slug'], '--supprimer-etiquettes-orphelines' => true]);
        $sortie = Artisan::output();
        DB::setDefaultConnection($precedente);

        expect($code)->toBe(1)
            ->and($sortie)->toContain('REFUS du ménage')
            // TÉMOIN : sous la RLS, l'étiquette liée ailleurs PARAÎT orpheline.
            ->and(emCompteur($sortie, 'etiquettes_orphelines_a_supprimer'))->toBe(2)
            ->and(emCompteur($sortie, 'liens_etiquette_hors_espace'))->toBe(1)
            ->and($proprio->table('tags')->where('id', $e['liee'])->exists())->toBeTrue()
            ->and($proprio->table('company_tag')->where('tag_id', $e['liee'])->exists())->toBeTrue()
            ->and($proprio->table('tags')->where('id', $e['orpheline'])->exists())->toBeTrue();

        // Témoin : sans le lien fautif, la même commande, sous le même rôle,
        // supprime bien les orphelines (le refus venait du lien, pas du rôle).
        $proprio->table('company_tag')->where('workspace_id', $e['b'])->delete();
        DB::setDefaultConnection('pgsql_app');
        $code = Artisan::call('crm:referentiels:reclasser', ['--workspace' => $e['slug'], '--supprimer-etiquettes-orphelines' => true]);
        DB::setDefaultConnection($precedente);

        expect($code)->toBe(0)
            ->and($proprio->table('tags')->where('id', $e['orpheline'])->exists())->toBeFalse();
    } finally {
        DB::setDefaultConnection($precedente);
        emNettoyerSousRls($e);
    }
});

test('SOUS RLS, si AUCUNE connexion ne voit tous les espaces, la suppression est refusee', function () {
    $e = emEspacesSousRls();
    $proprio = DB::connection('pgsql_owner');
    $precedente = DB::getDefaultConnection();
    $owner = config('database.connections.pgsql_owner');
    try {
        // `pgsql_owner` pointée, pour ce test, sur le rôle applicatif : plus
        // aucune connexion ne voit tout.
        config(['database.connections.pgsql_owner' => config('database.connections.pgsql_app')]);
        DB::purge('pgsql_owner');
        DB::setDefaultConnection('pgsql_app');
        $code = Artisan::call('crm:referentiels:reclasser', ['--workspace' => $e['slug'], '--supprimer-etiquettes-orphelines' => true]);
        $sortie = Artisan::output();
        DB::setDefaultConnection($precedente);
        config(['database.connections.pgsql_owner' => $owner]);
        DB::purge('pgsql_owner');

        expect($code)->toBe(1)
            ->and($sortie)->toContain('aucune connexion ne voit TOUS les espaces')
            ->and(DB::connection('pgsql_owner')->table('tags')->where('id', $e['orpheline'])->exists())->toBeTrue()
            ->and(DB::connection('pgsql_owner')->table('tags')->where('id', $e['liee'])->exists())->toBeTrue();
    } finally {
        DB::setDefaultConnection($precedente);
        config(['database.connections.pgsql_owner' => $owner]);
        DB::purge('pgsql_owner');
        emNettoyerSousRls($e);
    }
});

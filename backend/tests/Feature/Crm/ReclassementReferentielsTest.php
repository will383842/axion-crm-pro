<?php

/**
 * RECLASSEMENT DE MASSE — `crm:referentiels:reclasser` (chantier 1, 2026-09-28).
 *
 * Chaque garde face à un TÉMOIN, et chaque garde prouvée par son EFFET en base :
 * une commande qui ne ferait plus rien du tout ne doit passer aucun de ces tests.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 9xxxxxxxx, noms « ZZ ».
 */

use App\Contracts\InseeClient;
use App\Crm\EspaceProspection;
use App\Crm\FichesProtegees;
use App\Data\Sources\InseeCompanyData;
use App\Models\Workspace;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-reclasse-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ reclassement']);
});

/** @param  array<string, mixed>  $attrs */
function rcFiche(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => (string) (930000000 + $seq),
        'denomination' => 'ZZ fiche ' . $seq,
        'discovery_source' => 'insee',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

/** @param  array<string, mixed>  $attrs */
function rcTag(string $espace, string $slug, array $attrs = []): int
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

function rcLier(string $espace, int $companyId, int $tagId, string $par = 'auto-rule'): void
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
function rcSlugs(int $companyId): array
{
    $slugs = DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)->pluck('tags.slug')->all();
    sort($slugs);

    return array_values(array_map('strval', $slugs));
}

function rcCompteur(string $sortie, string $compteur): ?int
{
    return preg_match('/\|\s*' . preg_quote($compteur, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m) === 1 ? (int) $m[1] : null;
}

/** Une photographie de tout ce que la commande pourrait écrire. */
function rcPhoto(string $espace): string
{
    return json_encode([
        DB::table('companies')->where('workspace_id', $espace)->orderBy('id')
            ->get(['id', 'sector_main', 'size_category', 'entity_nature', 'region_code', 'naf_nomenclature', 'naf_rev2', 'updated_at'])->all(),
        DB::table('company_tag')->where('workspace_id', $espace)->orderBy('company_id')->orderBy('tag_id')->get()->all(),
        DB::table('tags')->where('workspace_id', $espace)->orderBy('id')->get(['id', 'slug', 'name'])->all(),
    ], JSON_THROW_ON_ERROR);
}

// ── Le classement ─────────────────────────────────────────────────────────

test('reclasse secteur, nomenclature, taille, nature et region depuis les donnees de la fiche', function () {
    $rev1 = rcFiche($this->espace, [
        'naf' => '52.1D', 'effectif_range' => '12', 'department_code' => '38',
        'sector_main' => 'transport', 'size_category' => 'tpe',
    ]);
    $micro = rcFiche($this->espace, ['naf' => '62.01Z', 'size_category' => 'micro', 'sector_main' => 'it_saas']);
    $grande = rcFiche($this->espace, ['naf' => '67.01', 'size_category' => 'grande_entreprise']);
    $vide = rcFiche($this->espace, ['naf' => '00.00Z', 'sector_main' => 'autre']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    $lire = static fn (int $id): object => DB::table('companies')->where('id', $id)->first();

    // Ancienne nomenclature : 52.1D = commerce de DÉTAIL, pas le transport.
    expect($lire($rev1)->sector_main)->toBe('commerce_detail')
        ->and($lire($rev1)->naf)->toBe('52.1D')              // le code d'origine reste
        ->and($lire($rev1)->naf_nomenclature)->toBe('naf_rev1')
        ->and($lire($rev1)->naf_rev2)->toBe('47.11D')
        ->and($lire($rev1)->size_category)->toBe('pme')      // 20-49 salariés
        ->and($lire($rev1)->entity_nature)->toBe('entreprise')
        ->and($lire($rev1)->region_code)->toBe('84');

    expect($lire($micro)->sector_main)->toBe('numerique_telecoms')
        ->and($lire($micro)->size_category)->toBe('tpe')
        ->and($lire($grande)->sector_main)->toBe('restauration')
        ->and($lire($grande)->naf_nomenclature)->toBe('nap_1973')
        ->and($lire($grande)->size_category)->toBe('grand_groupe')
        ->and($lire($vide)->sector_main)->toBe('non_classe')
        ->and($lire($vide)->naf_nomenclature)->toBe('inconnue');
});

test('une fiche importee sans donnee INSEE ne devient ni une TPE ni une entreprise', function () {
    $association = rcFiche($this->espace, [
        'siren' => null, 'country_code' => 'RO', 'foreign_id' => 'zz:asso-ro',
        'discovery_source' => 'scraping', 'entity_nature' => 'association', 'department_code' => '01',
    ]);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    $f = DB::table('companies')->where('id', $association)->first();
    expect($f->size_category)->toBeNull()
        ->and($f->entity_nature)->toBe('association')
        // Un « 01 » roumain n'est pas l'Ain.
        ->and($f->region_code)->toBeNull()
        ->and($f->sector_main)->toBe('non_classe');
});

test('la fiche protegee n est pas touchee, le temoin identique est reclasse', function () {
    $insee = ['naf' => '52.1D', 'sector_main' => 'transport', 'size_category' => 'micro'];
    $protegee = rcFiche($this->espace, $insee);
    $temoin = rcFiche($this->espace, $insee);
    rcLier($this->espace, $protegee, rcTag($this->espace, FichesProtegees::TAGS[0], ['is_locked' => true, 'category' => 'intent']));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    expect(DB::table('companies')->where('id', $protegee)->value('sector_main'))->toBe('transport')
        ->and(DB::table('companies')->where('id', $protegee)->value('size_category'))->toBe('micro')
        ->and(DB::table('companies')->where('id', $temoin)->value('sector_main'))->toBe('commerce_detail')
        ->and(rcCompteur(Artisan::output(), 'fiches_protegees_exclues'))->toBe(1);
});

test('updated_at n est pas touche par le reclassement', function () {
    $ancienne = '2024-01-15 10:00:00';
    $id = rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport', 'updated_at' => $ancienne]);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    $f = DB::table('companies')->where('id', $id)->first();
    // Témoin : la fiche a BIEN été réécrite…
    expect($f->sector_main)->toBe('commerce_detail')
        // … sans passer pour « modifiée aujourd'hui ».
        ->and(substr((string) $f->updated_at, 0, 19))->toBe($ancienne);
});

test('le declencheur remet updated_at a jour pour toute autre ecriture', function () {
    // Témoin de la garde précédente : sans le réglage de transaction, le
    // déclencheur fait bien son travail.
    $ancienne = '2024-01-15 10:00:00';
    $id = rcFiche($this->espace, ['updated_at' => $ancienne]);

    DB::table('companies')->where('id', $id)->update(['sector_main' => 'btp']);

    expect(substr((string) DB::table('companies')->where('id', $id)->value('updated_at'), 0, 19))->not->toBe($ancienne);
});

// ── Les étiquettes ────────────────────────────────────────────────────────

test('les etiquettes secteur, taille et region sont resynchronisees avec la fiche', function () {
    $id = rcFiche($this->espace, ['naf' => '52.1D', 'effectif_range' => '00', 'department_code' => '38', 'sector_main' => 'commerce']);
    rcLier($this->espace, $id, rcTag($this->espace, 'sector-commerce', ['category' => 'sector']));
    rcLier($this->espace, $id, rcTag($this->espace, 'size-micro', ['category' => 'size']));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    expect(rcSlugs($id))->toBe(['region-84', 'sector-commerce-detail', 'size-tpe'])
        // Les anciennes étiquettes, portées par plus personne, disparaissent.
        ->and(DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'sector-commerce')->exists())->toBeFalse()
        ->and(DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'size-micro')->exists())->toBeFalse()
        ->and(DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'sector-commerce-detail')->value('name'))
        ->toBe('Secteur : Commerce de détail');
});

test('jamais retirees : etiquettes src:, verrouillees, manuelles', function () {
    $id = rcFiche($this->espace, ['naf' => '62.01Z']);
    // Toutes « obsolètes » au sens du secteur/de la taille, mais protégées :
    $src = rcTag($this->espace, 'src:scraping-zz');
    $verrouillee = rcTag($this->espace, 'size-micro', ['category' => 'size', 'is_locked' => true]);
    $manuelle = rcTag($this->espace, 'sector-it-saas', ['category' => 'sector', 'kind' => 'manual']);
    $parUtilisateur = rcTag($this->espace, 'region-99', ['category' => 'geo']);
    // … et le TÉMOIN, qui doit partir.
    $automatique = rcTag($this->espace, 'sector-services-pro', ['category' => 'sector']);
    rcLier($this->espace, $id, $src);
    rcLier($this->espace, $id, $verrouillee);
    rcLier($this->espace, $id, $manuelle);
    rcLier($this->espace, $id, $parUtilisateur, 'user');
    rcLier($this->espace, $id, $automatique);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    expect(rcSlugs($id))
        ->toContain('src:scraping-zz')
        ->toContain('size-micro')
        ->toContain('sector-it-saas')
        ->toContain('region-99')
        ->toContain('sector-numerique-telecoms')
        ->not->toContain('sector-services-pro');
    // La verrouillée n'est pas supprimée du référentiel des tags non plus.
    expect(DB::table('tags')->where('id', $verrouillee)->exists())->toBeTrue();
});

test('sans-etiquettes : les colonnes sont reclassees, les etiquettes laissees', function () {
    $id = rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport']);
    rcLier($this->espace, $id, rcTag($this->espace, 'sector-transport', ['category' => 'sector']));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--sans-etiquettes' => true]);

    expect(DB::table('companies')->where('id', $id)->value('sector_main'))->toBe('commerce_detail')
        ->and(rcSlugs($id))->toBe(['sector-transport']);
});

// ── L'essai à blanc ───────────────────────────────────────────────────────

test('l essai a blanc n ecrit RIEN et annonce le bilan que l execution realise', function () {
    $a = rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport', 'department_code' => '75']);
    rcFiche($this->espace, ['naf' => '85.1A', 'sector_main' => 'enseignement', 'size_category' => 'micro']);
    // Déjà juste : lue, jamais réécrite (seules ses étiquettes manquent).
    rcFiche($this->espace, ['naf' => '62.01Z', 'sector_main' => 'numerique_telecoms', 'size_category' => 'tpe',
        'entity_nature' => 'entreprise', 'naf_nomenclature' => 'naf_rev2', 'naf_rev2' => '62.01Z']);
    rcLier($this->espace, $a, rcTag($this->espace, 'sector-transport', ['category' => 'sector']));

    $avant = rcPhoto($this->espace);
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $aBlanc = Artisan::output();

    expect(rcPhoto($this->espace))->toBe($avant)
        ->and($aBlanc)->toContain('[À BLANC]')
        ->and(rcCompteur($aBlanc, 'fiches_lues'))->toBe(3)
        ->and(rcCompteur($aBlanc, 'fiches_a_modifier'))->toBe(2);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $reel = Artisan::output();

    // Ce que l'essai annonçait est ce que l'exécution a fait.
    expect(rcCompteur($reel, 'fiches_modifiees'))->toBe(rcCompteur($aBlanc, 'fiches_a_modifier'))
        ->and(rcCompteur($reel, 'etiquettes_ajoutees'))->toBe(rcCompteur($aBlanc, 'etiquettes_a_ajouter'))
        ->and(rcCompteur($reel, 'etiquettes_retirees'))->toBe(rcCompteur($aBlanc, 'etiquettes_a_retirer'))
        ->and(rcCompteur($reel, 'etiquettes_retirees'))->toBe(1)
        ->and(rcPhoto($this->espace))->not->toBe($avant);
});

test('le bilan donne la repartition AVANT et APRES par secteur', function () {
    rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport']);
    rcFiche($this->espace, ['naf' => '52.2A', 'sector_main' => 'transport']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $sortie = Artisan::output();

    // | transport | ⚠ hors référentiel | 2 | 0 | -2 |
    expect(preg_match('/\|\s*transport\s*\|[^|]*\|\s*2\s*\|\s*0\s*\|\s*-2\s*\|/u', $sortie))->toBe(1)
        ->and(preg_match('/\|\s*commerce_detail\s*\|\s*Commerce de détail\s*\|\s*0\s*\|\s*2\s*\|\s*\+2\s*\|/u', $sortie))->toBe(1);
});

// ── Lots, reprise, idempotence ────────────────────────────────────────────

test('interrompue apres un lot, la commande se reprend par --depuis-id et finit le travail', function () {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport']);
    }

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--lot' => 2, '--max-lots' => 1]);
    $sortie = Artisan::output();

    expect(preg_match('/--depuis-id=(\d+)/', $sortie, $m))->toBe(1);
    $reprise = (int) $m[1];
    expect($reprise)->toBe($ids[1]);

    $secteurs = static fn (): array => DB::table('companies')->whereIn('id', $ids)->orderBy('id')->pluck('sector_main')->all();
    // Deux traitées, trois intactes : l'arrêt est net.
    expect($secteurs())->toBe(['commerce_detail', 'commerce_detail', 'transport', 'transport', 'transport']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--lot' => 2, '--depuis-id' => $reprise]);

    expect($secteurs())->toBe(array_fill(0, 5, 'commerce_detail'))
        ->and(rcCompteur(Artisan::output(), 'fiches_lues'))->toBe(3);
});

test('idempotente : une seconde execution ne modifie rien', function () {
    rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport', 'department_code' => '38']);
    rcFiche($this->espace, ['naf' => '62.01Z', 'size_category' => 'micro']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    expect(rcCompteur(Artisan::output(), 'fiches_modifiees'))->toBe(2);
    $apres = rcPhoto($this->espace);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $seconde = Artisan::output();

    expect(rcCompteur($seconde, 'fiches_a_modifier'))->toBe(0)
        ->and(rcCompteur($seconde, 'etiquettes_a_ajouter'))->toBe(0)
        ->and(rcCompteur($seconde, 'etiquettes_a_retirer'))->toBe(0)
        ->and(rcPhoto($this->espace))->toBe($apres);
});

test('un espace inconnu est refuse sans rien toucher', function () {
    $code = Artisan::call('crm:referentiels:reclasser', ['--workspace' => 'zz-nexiste-pas']);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('Espace introuvable');
});

test('une audience qui cite une ancienne valeur est signalee', function () {
    DB::table('email_audiences')->insert([
        'workspace_id' => $this->espace,
        'name' => 'ZZ audience IT',
        'criteria' => json_encode(['all' => [
            ['field' => 'sector_main', 'op' => 'in', 'value' => ['it_saas', 'btp']],
            ['field' => 'size_category', 'op' => 'in', 'value' => ['micro']],
        ]], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $sortie = Artisan::output();

    expect($sortie)->toContain('ZZ audience IT')
        ->and($sortie)->toContain('sector_main=it_saas')
        ->and($sortie)->toContain('size_category=micro')
        ->and($sortie)->not->toContain('sector_main=btp');
});

// ── La collecte INSEE écrit déjà le classement unique ────────────────────

test('la collecte INSEE range secteur, taille, nature et region par le meme calcul', function () {
    $this->app->instance(InseeClient::class, new class implements InseeClient
    {
        public function fetchBySiren(string $siren): ?InseeCompanyData
        {
            return null;
        }

        public function searchByCriteria(array $criteria): array
        {
            return [
                new InseeCompanyData(
                    siren: '931000001',
                    denomination: 'ZZ Épicerie',
                    naf: '52.1D',
                    effectifRange: '12',
                    raw: ['uniteLegale' => ['categorieEntreprise' => 'PME']],
                ),
                new InseeCompanyData(
                    siren: '931000002',
                    denomination: 'ZZ Éditeur',
                    naf: '62.01Z',
                    effectifRange: '52',
                    raw: ['uniteLegale' => ['categorieEntreprise' => 'GE']],
                ),
                new InseeCompanyData(siren: '931000003', denomination: 'ZZ Déjà association', naf: '94.99Z'),
            ];
        }

        public function iterateByCriteria(array $criteria): Generator
        {
            yield from $this->searchByCriteria($criteria);
        }
    });
    // Une fiche déjà classée « association » à la main : la collecte ne
    // réécrit JAMAIS une nature décidée.
    rcFiche($this->espace, ['siren' => '931000003', 'entity_nature' => 'association', 'discovery_source' => 'site']);

    Artisan::call('prospection:collect', ['department' => '38', '--workspace' => $this->espace, '--req-delay' => 0]);

    $lire = static fn (string $siren): object => DB::table('companies')->where('siren', $siren)->first();
    expect($lire('931000001')->sector_main)->toBe('commerce_detail')
        ->and($lire('931000001')->naf_nomenclature)->toBe('naf_rev1')
        ->and($lire('931000001')->naf_rev2)->toBe('47.11D')
        ->and($lire('931000001')->size_category)->toBe('pme')
        ->and($lire('931000001')->entity_nature)->toBe('entreprise')
        ->and($lire('931000001')->region_code)->toBe('84')
        ->and($lire('931000002')->sector_main)->toBe('numerique_telecoms')
        ->and($lire('931000002')->size_category)->toBe('grand_groupe')
        ->and($lire('931000003')->entity_nature)->toBe('association')
        ->and($lire('931000003')->sector_main)->toBe('non_classe');
});

// ── Sous le rôle de production (RLS) ──────────────────────────────────────

test('sous axion_app (RLS), la commande reclasse et etiquette les fiches de son espace, et seulement elles', function () {
    // `axion` (le rôle des autres tests) contourne la RLS. En production la
    // commande parle en `axion_app` : une lecture sans contexte d'espace y rend
    // ZÉRO ligne, et une commande mal écrite finirait « verte » sans rien faire.
    /** @var Connection $proprio */
    $proprio = DB::connection('pgsql_owner');
    $espaces = [];
    foreach (['a', 'b'] as $suffixe) {
        $id = (string) Str::uuid();
        $proprio->table('workspaces')->insert([
            'id' => $id, 'slug' => 'zz-rc-rls-' . $suffixe . '-' . substr($id, 0, 8), 'name' => 'ZZ RLS',
            'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $espaces[$suffixe] = [
            'id' => $id,
            'slug' => 'zz-rc-rls-' . $suffixe . '-' . substr($id, 0, 8),
            'fiche' => (int) $proprio->table('companies')->insertGetId([
                'workspace_id' => $id, 'siren' => '9' . random_int(10000000, 99999999),
                'denomination' => 'ZZ RLS ' . $suffixe, 'naf' => '52.1D', 'sector_main' => 'transport',
                'department_code' => '38', 'discovery_source' => 'insee',
                'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0,
                'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
                'created_at' => now(), 'updated_at' => now(),
            ]),
        ];
    }

    $precedente = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('pgsql_app');
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

        $code = Artisan::call('crm:referentiels:reclasser', ['--workspace' => $espaces['a']['slug']]);
        $sortie = Artisan::output();
    } finally {
        DB::setDefaultConnection($precedente);
    }

    try {
        expect($code)->toBe(0)
            ->and(rcCompteur($sortie, 'fiches_lues'))->toBe(1)
            ->and($proprio->table('companies')->where('id', $espaces['a']['fiche'])->value('sector_main'))->toBe('commerce_detail')
            // Secteur et région (pas de taille : aucune donnée d'effectif).
            ->and($proprio->table('company_tag')->where('company_id', $espaces['a']['fiche'])->count())->toBe(2)
            // L'autre espace : intact.
            ->and($proprio->table('companies')->where('id', $espaces['b']['fiche'])->value('sector_main'))->toBe('transport')
            ->and($proprio->table('company_tag')->where('company_id', $espaces['b']['fiche'])->count())->toBe(0);
    } finally {
        foreach ($espaces as $e) {
            $proprio->table('company_tag')->where('workspace_id', $e['id'])->delete();
            $proprio->table('companies')->where('workspace_id', $e['id'])->delete();
            $proprio->table('tags')->where('workspace_id', $e['id'])->delete();
            // Ce test COMMIT (connexions hors transaction de test) : la ligne
            // de journal qu'il a écrite casserait la chaîne vérifiée par
            // d'autres tests (`ChaineAuditSecretTest`, `RunbookDisquePleinTest`).
            // On la retire : elle n'existe que pour ce test.
            $proprio->table('audit_logs')->where('workspace_id', $e['id'])->delete();
            $proprio->table('workspaces')->where('id', $e['id'])->delete();
        }
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::connection('pgsql_app')->disconnect();
        $proprio->disconnect();
    }
});

// ══ Relecture du 2026-09-28 — chaque correction, et le test qui la tient ══

/**
 * Simule une écriture CONCURRENTE : juste après la lecture d'un lot par la
 * commande (la requête `SELECT c.id, c.naf_nomenclature … FROM companies c`),
 * une autre écriture passe sur la fiche. Le rappel s'exécute avant l'`UPDATE`
 * de la commande.
 */
function rcApresLecture(callable $ecriture): void
{
    $fait = false;
    DB::listen(function ($requete) use (&$fait, $ecriture): void {
        if ($fait || ! str_starts_with(ltrim($requete->sql), 'SELECT c.id, c.naf_nomenclature')) {
            return;
        }
        $fait = true;
        $ecriture();
    });
}

test('B1 — une chaine vide est traitee comme absente, et reecrite', function () {
    $id = rcFiche($this->espace, [
        'naf' => '52.1D', 'size_category' => '', 'region_code' => '', 'department_code' => '', 'effectif_range' => '',
    ]);
    $sansCode = rcFiche($this->espace, ['naf' => '', 'sector_main' => '']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    $f = DB::table('companies')->where('id', $id)->first();
    // Avant correction, `''` était lu comme NULL par la garde de concurrence :
    // `size_category IS NOT DISTINCT FROM NULL` était faux, la fiche jamais écrite.
    expect($f->sector_main)->toBe('commerce_detail')
        ->and($f->size_category)->toBeNull()
        ->and($f->region_code)->toBeNull()
        ->and(DB::table('companies')->where('id', $sansCode)->value('sector_main'))->toBe('non_classe')
        ->and(rcCompteur(Artisan::output(), 'fiches_modifiees_entre_temps'))->toBe(0);
});

dataset('ecritures concurrentes', [
    'le secteur' => [['sector_main' => 'btp']],
    'la categorie INSEE' => [['metadata' => '{"categorie_entreprise":"GE"}']],
    'l effectif' => [['effectif_range' => '21']],
]);

test('B2 — une fiche modifiee entre-temps n est pas ecrasee, et ses etiquettes ne bougent pas', function (array $ecriture) {
    $id = rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport', 'department_code' => '38']);
    rcLier($this->espace, $id, rcTag($this->espace, 'sector-transport', ['category' => 'sector']));
    rcApresLecture(fn () => DB::table('companies')->where('id', $id)->update($ecriture));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $sortie = Artisan::output();

    expect(rcCompteur($sortie, 'fiches_modifiees'))->toBe(0)
        ->and(rcCompteur($sortie, 'fiches_modifiees_entre_temps'))->toBe(1)
        ->and(DB::table('companies')->where('id', $id)->value('sector_main'))->not->toBe('commerce_detail')
        // Les étiquettes suivent la FICHE : ni ajout ni retrait pour elle.
        ->and(rcSlugs($id))->toBe(['sector-transport']);
})->with('ecritures concurrentes');

test('B2 — TEMOIN : sans ecriture concurrente, la meme fiche est reecrite et reetiquetee', function () {
    $id = rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport', 'department_code' => '38']);
    rcLier($this->espace, $id, rcTag($this->espace, 'sector-transport', ['category' => 'sector']));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    expect(DB::table('companies')->where('id', $id)->value('sector_main'))->toBe('commerce_detail')
        ->and(rcSlugs($id))->toBe(['region-84', 'sector-commerce-detail']);
});

test('R4 — une fiche devenue protegee entre la lecture et l ecriture n est ni reecrite ni desetiquetee', function () {
    $id = rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport']);
    rcLier($this->espace, $id, rcTag($this->espace, 'sector-transport', ['category' => 'sector']));
    $protection = rcTag($this->espace, FichesProtegees::TAGS[0], ['is_locked' => true, 'category' => 'intent']);
    rcApresLecture(fn () => rcLier($this->espace, $id, $protection));

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    expect(DB::table('companies')->where('id', $id)->value('sector_main'))->toBe('transport')
        ->and(rcSlugs($id))->toContain('sector-transport');
});

test('B3 — une audience d EXCLUSION obsolete bloque l execution reelle, pas l essai a blanc', function () {
    $id = rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport']);
    DB::table('email_audiences')->insert([
        'workspace_id' => $this->espace,
        'name' => 'ZZ sauf le commerce',
        'criteria' => json_encode(['all' => [
            ['field' => 'sector_main', 'op' => 'not_in', 'value' => ['commerce']],
        ]], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // L'essai à blanc la signale, et passe.
    expect(Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('EXCLUSION')->toContain('ZZ sauf le commerce');

    // L'exécution réelle refuse, et n'écrit rien.
    expect(Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]))->toBe(1);
    expect(Artisan::output())->toContain('REFUS')
        ->and(DB::table('companies')->where('id', $id)->value('sector_main'))->toBe('transport');

    // Sur décision explicite, elle part.
    expect(Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--accepter-audiences' => true]))->toBe(0)
        ->and(DB::table('companies')->where('id', $id)->value('sector_main'))->toBe('commerce_detail');
});

test('B3 — les etiquettes obsoletes dans un bloc not, dont nature-entreprise, sont des exclusions', function () {
    DB::table('email_audiences')->insert([
        'workspace_id' => $this->espace,
        'name' => 'ZZ pas les entreprises',
        'criteria' => json_encode(['not' => [
            ['field' => 'tags', 'op' => 'contains_any', 'value' => ['nature-entreprise', 'size-micro']],
        ]], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $sortie = Artisan::output();

    expect(rcCompteur($sortie, 'audiences_exclusion_obsoletes'))->toBe(1)
        ->and($sortie)->toContain('tags=nature-entreprise')
        ->and($sortie)->toContain('tags=size-micro');
});

test('R3 — compteurs-seulement : aucun nom d audience dans la sortie', function () {
    DB::table('email_audiences')->insert([
        'workspace_id' => $this->espace,
        'name' => 'ZZ nom confidentiel',
        'criteria' => json_encode(['all' => [['field' => 'sector_main', 'op' => 'in', 'value' => ['it_saas']]]], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true, '--compteurs-seulement' => true]);
    $discret = Artisan::output();
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $complet = Artisan::output();

    expect($discret)->not->toContain('ZZ nom confidentiel')
        ->and(rcCompteur($discret, 'audiences_obsoletes'))->toBe(1)
        // Témoin : en mode normal, Will voit bien le nom.
        ->and($complet)->toContain('ZZ nom confidentiel');
});

test('B4 — un secteur valide pose autrement que par le NAF n est pas ecrase par non_classe', function () {
    $federation = rcFiche($this->espace, ['naf' => '94.11Z', 'sector_main' => 'interprofessionnel']);
    $represente = rcFiche($this->espace, ['naf' => '94.12Z', 'sector_main' => 'btp']);
    $ancien = rcFiche($this->espace, ['naf' => '94.12Z', 'sector_main' => 'it_saas']);
    $parNaf = rcFiche($this->espace, ['naf' => '62.01Z', 'sector_main' => 'interprofessionnel']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    $secteur = static fn (int $id): ?string => DB::table('companies')->where('id', $id)->value('sector_main');
    expect($secteur($federation))->toBe('interprofessionnel')
        ->and($secteur($represente))->toBe('btp')
        // Hors référentiel : devient non classé.
        ->and($secteur($ancien))->toBe('non_classe')
        // Un code NAF qui PARLE décide toujours.
        ->and($secteur($parNaf))->toBe('numerique_telecoms');
});

test('B6 — un espace sans fiche INSEE est refuse quand un autre en porte', function () {
    rcFiche($this->espace, ['naf' => '52.1D']);
    $autre = (string) Str::uuid();
    Workspace::create(['id' => $autre, 'slug' => 'zz-vide-' . Str::random(6), 'name' => 'ZZ vide']);

    $code = Artisan::call('crm:referentiels:reclasser', ['--workspace' => $autre]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('aucune fiche INSEE');
});

test('B6 — la collecte et le reclassement ont le meme espace par defaut', function () {
    $collecte = (string) DB::table('workspaces')->orderBy('created_at')->value('id');

    expect(EspaceProspection::resoudre(null))->toBe($collecte);
});

test('B8 — l essai a blanc chiffre les etiquettes obsoletes, et l execution en supprime autant', function () {
    $id = rcFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'commerce']);
    rcLier($this->espace, $id, rcTag($this->espace, 'sector-commerce', ['category' => 'sector']));
    rcLier($this->espace, $id, rcTag($this->espace, 'size-micro', ['category' => 'size']));
    // Retenue par un lien posé par un utilisateur : ne sera PAS supprimée.
    $autre = rcFiche($this->espace, ['naf' => '52.1D']);
    rcLier($this->espace, $autre, rcTag($this->espace, 'sector-services-pro', ['category' => 'sector']), 'user');

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $aBlanc = Artisan::output();
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $reel = Artisan::output();

    expect(rcCompteur($aBlanc, 'etiquettes_obsoletes_a_supprimer'))->toBe(2)
        ->and(rcCompteur($aBlanc, 'garde_b15008_refuserait'))->toBe(0)
        ->and(rcCompteur($reel, 'etiquettes_obsoletes_supprimees'))->toBe(2)
        ->and(DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'sector-services-pro')->exists())->toBeTrue();
});

test('R2 — l audit est ecrit par lot, avec l intervalle d ids et l operateur, plus une entree de fin', function () {
    for ($i = 0; $i < 3; $i++) {
        rcFiche($this->espace, ['naf' => '52.1D']);
    }

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--lot' => 1]);

    $lignes = DB::table('audit_logs')->where('workspace_id', $this->espace)->orderBy('id')->get();
    expect($lignes->where('event_type', 'RECLASSEMENT_REFERENTIELS_LOT')->count())->toBe(3)
        ->and($lignes->where('event_type', 'RECLASSEMENT_REFERENTIELS_FIN')->count())->toBe(1)
        ->and((string) $lignes->first()->path)->toContain('ids ')
        ->and((string) $lignes->first()->user_agent)->toStartWith('cli ')
        ->and((string) $lignes->first()->user_agent)->toContain('@');

    // L'essai à blanc, lui, n'écrit rien — pas même un journal.
    $avant = DB::table('audit_logs')->count();
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    expect(DB::table('audit_logs')->count())->toBe($avant);
});

test('R1 — la levee de updated_at est sans effet hors companies et tags', function () {
    $ancienne = '2024-01-15 10:00:00';
    $requete = (int) DB::table('rgpd_requests')->insertGetId([
        'workspace_id' => $this->espace, 'type' => 'access', 'subject_email' => 'zz@example.invalid',
        'updated_at' => $ancienne,
    ]);
    $fiche = rcFiche($this->espace, ['updated_at' => $ancienne]);

    DB::transaction(function () use ($requete, $fiche): void {
        DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
        DB::table('rgpd_requests')->where('id', $requete)->update(['status' => 'processing']);
        // Même une valeur ÉCRITE par l'UPDATE n'est pas retenue : c'est
        // l'ancienne qui est rétablie.
        DB::table('companies')->where('id', $fiche)->update(['sector_main' => 'btp', 'updated_at' => '2030-01-01 00:00:00']);
    });

    expect(substr((string) DB::table('rgpd_requests')->where('id', $requete)->value('updated_at'), 0, 19))->not->toBe($ancienne)
        ->and(substr((string) DB::table('companies')->where('id', $fiche)->value('updated_at'), 0, 19))->toBe($ancienne);
});

test('R5 — une etiquette manuelle ou verrouillee n est jamais renommee', function () {
    rcFiche($this->espace, ['naf' => '41.20A', 'effectif_range' => '21', 'department_code' => '38']);
    $manuelle = rcTag($this->espace, 'sector-btp', ['category' => 'sector', 'kind' => 'manual', 'name' => 'ZZ mon BTP']);
    $verrouillee = rcTag($this->espace, 'size-pme', ['category' => 'size', 'is_locked' => true, 'name' => 'ZZ PME gouvernée']);
    $auto = rcTag($this->espace, 'region-84', ['category' => 'geo', 'name' => 'Région 84']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);

    $nom = static fn (int $tag): string => (string) DB::table('tags')->where('id', $tag)->value('name');
    expect($nom($manuelle))->toBe('ZZ mon BTP')
        ->and($nom($verrouillee))->toBe('ZZ PME gouvernée')
        // Témoin : l'étiquette automatique, elle, prend le libellé du référentiel.
        ->and($nom($auto))->toBe('Région : Auvergne-Rhône-Alpes');
});

test('B7 — un index nature reste INVALIDE est detecte par la migration', function () {
    $migration = require database_path('migrations/2026_09_28_000002_index_nature_et_validation_naf.php');
    $index = $migration::INDEX;

    expect($migration::indexInvalide($index))->toBeFalse();
    DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = '{$index}'::regclass");
    expect($migration::indexInvalide($index))->toBeTrue();
});

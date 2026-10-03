<?php

/**
 * UN SITE DEVINÉ N'EST PLUS JAMAIS TENU POUR VÉRIFIÉ — `App\Crm\Sites\SiteFiable`
 * (lot N4 « fermer le robinet », 03/10/2026).
 *
 * Ce que ces gardes tiennent :
 *  1. la règle « non vérifié » (`website_method` `guess%` sans marqueur
 *     `metadata.site_entreprise.statut` verifie|trouve-verifie) se DÉDUIT :
 *     aucune ligne n'est réécrite pour l'évaluer ;
 *  2. le miroir PHP (`estNonVerifie`) et le SQL disent la même chose, et
 *     `fiableSql` en est la négation exacte ;
 *  3. les conditions « e-mail issu d'un site non vérifié » (personne des
 *     mentions légales, adresse générique) visent les bonnes lignes — sans
 *     rien filtrer ni effacer (lot N5) ;
 *  4. l'enrichissement écrit la MÉTHODE avec le site trouvé ;
 *  5. le comptage passe par l'index partiel `idx_companies_site_non_verifie`
 *     SOUS LE RÔLE DE PRODUCTION (`axion_app`, sécurité par espace forcée).
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Presse\SiteMedia;
use App\Crm\Sites\SiteFiable;
use App\Services\Domain\DomainFinderService;
use App\Services\Waterfall\WaterfallOrchestrator;
use App\Models\Company;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @param  array<string, mixed>|null  $metadata */
function sdnvFiche(string $ws, ?string $methode, ?array $metadata = null, array $attrs = []): int
{
    return F::fiche($ws, 'ZZ Fiche ' . Str::random(6), array_merge([
        'website' => $methode === null ? null : 'https://zz-' . Str::lower(Str::random(8)) . '.example.invalid/',
        'website_method' => $methode,
        'metadata' => json_encode($metadata ?? [], JSON_THROW_ON_ERROR),
    ], $attrs));
}

/** @return array<int, string> id => empreinte de la ligne entière */
function sdnvEmpreintes(string $ws): array
{
    return DB::table('companies')->where('workspace_id', $ws)
        ->selectRaw('id, md5(companies::text) AS e')->pluck('e', 'id')->all();
}

/** @return array{0: string, 1: array<string, int>} */
function sdnvEspacePeuple(): array
{
    $ws = F::espace('zz-sdnv');
    $ids = [
        'guess' => sdnvFiche($ws, 'guess'),
        'guess2' => sdnvFiche($ws, 'guess2'),
        // Toute FUTURE méthode de devinette garde le préfixe : elle est couverte.
        'guess3' => sdnvFiche($ws, 'guess3-futur'),
        'guess_verifie' => sdnvFiche($ws, 'guess', [SiteFiable::CLE => ['statut' => 'verifie', 'url' => 'https://zz.example.invalid/']]),
        'guess2_trouve_verifie' => sdnvFiche($ws, 'guess2', [SiteFiable::CLE => ['statut' => 'trouve-verifie']]),
        // Un statut qui ne vaut PAS vérification.
        'guess_a_confirmer' => sdnvFiche($ws, 'guess', [SiteFiable::CLE => ['statut' => 'a-confirmer']]),
        // Le marqueur des MÉDIAS ne vaut pas vérification du site de l'entreprise.
        'guess_site_media' => sdnvFiche($ws, 'guess', [SiteMedia::CLE => ['statut' => 'verifie']]),
        'brave' => sdnvFiche($ws, 'brave'),
        'sans_methode' => sdnvFiche($ws, null),
        'corbeille' => sdnvFiche($ws, 'guess', null, ['deleted_at' => now()]),
    ];

    return [$ws, $ids];
}

test('la règle « non vérifié » s évalue sans réécrire une seule ligne', function () {
    [$ws, $ids] = sdnvEspacePeuple();
    $avant = sdnvEmpreintes($ws);

    $nonVerifiees = SiteFiable::fichesNonVerifiees($ws)->orderBy('c.id')->pluck('c.id')->map(static fn ($i): int => (int) $i)->all();

    expect($nonVerifiees)->toBe([
        $ids['guess'], $ids['guess2'], $ids['guess3'], $ids['guess_a_confirmer'], $ids['guess_site_media'],
    ])
        ->and(SiteFiable::fichesNonVerifiees($ws)->count())->toBe(5)
        // Pas une ligne touchée : ni valeur, ni `updated_at`.
        ->and(sdnvEmpreintes($ws))->toBe($avant);
});

test('le miroir PHP et le SQL disent la même chose ; fiableSql est la négation exacte', function () {
    [$ws] = sdnvEspacePeuple();

    $lignes = DB::table('companies')->where('workspace_id', $ws)
        ->select('id', 'website_method', 'metadata')
        ->selectRaw(SiteFiable::nonVerifieSql('companies') . ' AS non_verifie')
        ->selectRaw(SiteFiable::fiableSql('companies') . ' AS fiable')
        ->get();

    expect($lignes)->toHaveCount(10);
    foreach ($lignes as $l) {
        $php = SiteFiable::estNonVerifie($l->website_method, $l->metadata);
        // `nonVerifieSql` peut valoir NULL (website_method NULL) : faux pour un WHERE.
        expect((bool) $l->non_verifie)->toBe($php, "fiche {$l->id} ({$l->website_method})")
            ->and($l->fiable)->toBe(! $php, "fiche {$l->id} : fiableSql n'est pas la négation");
    }
});

test('les conditions « e-mail issu d un site non vérifié » visent les bonnes lignes, sans rien effacer', function () {
    $ws = F::espace('zz-sdnv-mails');
    $devinee = sdnvFiche($ws, 'guess', null, ['email_generic' => 'contact@zz-devine.example.invalid']);
    $verifiee = sdnvFiche($ws, 'guess', [SiteFiable::CLE => ['statut' => 'verifie']], ['email_generic' => 'contact@zz-verifie.example.invalid']);
    $fiable = sdnvFiche($ws, 'brave', null, ['email_generic' => 'contact@zz-brave.example.invalid']);
    $sansAdresse = sdnvFiche($ws, 'guess2');

    $cMentions = F::contact($ws, $devinee, 'Zoé', 'ZZ-Un', ['discovery_source' => 'mentions-legales', 'email' => 'zoe@zz-devine.example.invalid']);
    $cAnnuaire = F::contact($ws, $devinee, 'Zack', 'ZZ-Deux', ['discovery_source' => 'annuaire-entreprises']);
    $cVerifiee = F::contact($ws, $verifiee, 'Zia', 'ZZ-Trois', ['discovery_source' => 'mentions-legales']);
    $cFiable = F::contact($ws, $fiable, 'Zed', 'ZZ-Quatre', ['discovery_source' => 'mentions-legales']);

    $contacts = DB::table('contacts')->where('workspace_id', $ws)
        ->whereRaw(SiteFiable::contactIssuSiteNonVerifieSql('contacts'))->pluck('id')->map(static fn ($i): int => (int) $i)->all();
    expect($contacts)->toBe([$cMentions]);

    $generiques = DB::table('companies AS c')->where('c.workspace_id', $ws)
        ->whereRaw(SiteFiable::emailGeneriqueIssuSiteNonVerifieSql('c'))->pluck('c.id')->map(static fn ($i): int => (int) $i)->all();
    expect($generiques)->toBe([$devinee]);

    // Rien n'est effacé ni vidé : la condition est fournie, pas appliquée.
    expect(DB::table('contacts')->whereIn('id', [$cMentions, $cAnnuaire, $cVerifiee, $cFiable])->count())->toBe(4)
        ->and(DB::table('companies')->where('id', $devinee)->value('email_generic'))->toBe('contact@zz-devine.example.invalid')
        ->and($sansAdresse)->toBeInt();
});

test('l enrichissement écrit la méthode avec le site trouvé : un site deviné reste non vérifié', function () {
    $ws = F::espace('zz-sdnv-waterfall');
    $id = sdnvFiche($ws, null);
    DB::table('companies')->where('id', $id)->update(['website' => null]);

    $this->mock(DomainFinderService::class)->shouldReceive('findAvecMethode')->once()
        ->andReturn(['url' => 'https://zz-devine.example.invalid/', 'methode' => SiteFiable::METHODE_DEVINEE]);

    $orchestre = app(WaterfallOrchestrator::class);
    $etape = new ReflectionMethod($orchestre, 'step3b_find_domain');
    $etape->invoke($orchestre, Company::query()->withoutGlobalScopes()->findOrFail($id));

    $fiche = DB::table('companies')->where('id', $id)->first();
    expect($fiche->website)->toBe('https://zz-devine.example.invalid/')
        ->and($fiche->website_method)->toBe('guess')
        ->and(SiteFiable::fichesNonVerifiees($ws)->pluck('c.id')->map(static fn ($i): int => (int) $i)->all())->toBe([$id]);
});

// ── Sous le rôle de production ──────────────────────────────────────────────

function sdnvApp(): Connection
{
    return DB::connection('pgsql_app');
}

/** Le plan d'un comptage, sous `axion_app`, sans balayage séquentiel permis. */
function sdnvPlan(string $espace, Builder $requete): string
{
    $q = $requete->selectRaw('count(*) AS aggregate');
    sdnvApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);
    sdnvApp()->statement('SET enable_seqscan = off');

    $lignes = sdnvApp()->select('EXPLAIN ' . $q->toSql(), $q->getBindings());

    return implode("\n", array_map(static fn ($l): string => (string) array_values((array) $l)[0], $lignes));
}

afterEach(function () {
    // Connexion jamais ouverte par ce test : surtout ne pas en ouvrir une.
    if (! array_key_exists('pgsql_app', DB::getConnections())) {
        return;
    }

    try {
        sdnvApp()->statement('RESET enable_seqscan');
        sdnvApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    } catch (Throwable) {
        // Connexion déjà perdue : elle part avec le `disconnect()`.
    }
    sdnvApp()->disconnect();
});

test('index idx_companies_site_non_verifie présent, valide, et au prédicat de la règle', function () {
    $index = DB::selectOne(
        'SELECT i.indisvalid AS valide, pg_get_indexdef(i.indexrelid) AS def
           FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
          WHERE c.relname = ?',
        ['idx_companies_site_non_verifie'],
    );

    expect($index)->not->toBeNull()
        ->and((bool) $index->valide)->toBeTrue()
        ->and($index->def)->toContain('(workspace_id)')
        ->and($index->def)->toContain("'guess%'")
        ->and($index->def)->toContain("'site_entreprise'")
        ->and($index->def)->toContain("'verifie'")
        ->and($index->def)->toContain("'trouve-verifie'")
        ->and($index->def)->toContain('deleted_at IS NULL')
        // Le SENS : l'index porte les fiches NON vérifiées (`<> ALL`), pas l'inverse.
        ->and($index->def)->toContain('<> ALL');
});

test('sous axion_app : le comptage des sites non vérifiés passe par l index partiel, sans balayage séquentiel', function () {
    $role = sdnvApp()->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
    expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

    $espace = (string) Str::uuid();
    $plan = sdnvPlan($espace, SiteFiable::fichesNonVerifiees($espace));

    expect($plan)->toContain('idx_companies_site_non_verifie')
        ->and($plan)->not->toContain('Seq Scan');
});

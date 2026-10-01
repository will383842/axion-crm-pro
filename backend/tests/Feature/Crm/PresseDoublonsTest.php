<?php

/**
 * LES DOUBLONS DE LA PRESSE — `crm:presse:doublons` (constat en production du
 * 2026-10-01 : l'harmonisation #264 a créé une fiche par ligne `media`).
 *
 *  - deux fiches du même titre, sans SIREN, même type : FUSIONNÉES (la source
 *    la plus fiable gardée), annulables ;
 *  - deux éditions (départements différents) : jamais fusionnées, jamais
 *    proposées ;
 *  - une fiche à SIREN face à un homonyme sans SIREN, des adresses
 *    contradictoires : file « Doublons à vérifier », jamais fusionnées ;
 *  - à blanc (défaut) : rien n'est écrit ;
 *  - les métadonnées de la presse suivent la fusion, et l'annulation les retire ;
 *  - après dédoublonnage, l'import rejoint la fiche restante.
 *
 * Fixtures FICTIVES (dépôt public) : noms « ZZ », domaines `.example.invalid`,
 * SIREN 9xxxxxxxx.
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Crm\Doublons\RefusFusion;
use App\Support\WorkspaceContext;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.scrape_funnel.validate_mx' => false, 'crm.ingest.business_workspace' => 'axion-ia']);
    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->seed(ScrapingSourcesSeeder::class);
});

/** @param  array<string, mixed>  $valeurs */
function pddMedia(string $espace, array $valeurs): int
{
    return (int) DB::table('media')->insertGetId($valeurs + [
        'workspace_id' => $espace, 'name' => 'ZZ media', 'media_type' => 'tv', 'media_family' => 'editorial',
        'source' => 'cppap', 'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** La fiche née de l'harmonisation pour ce média. */
function pddFiche(int $media): int
{
    return (int) DB::table('media')->where('id', $media)->value('company_id');
}

/** @return array{code: int, sortie: string} */
function pddDoublons(array $options = []): array
{
    $code = Artisan::call('crm:presse:doublons', $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function pddCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);
    expect($m)->not->toBeEmpty("Compteur « {$cle} » absent de la sortie.");

    return (int) $m[1];
}

/** @return array{code: int, sortie: string} */
function pddImporter(array $lignes): array
{
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-pdd-');
    file_put_contents($fichier, implode("\n", array_map(static fn (array $l): string => (string) json_encode($l, JSON_UNESCAPED_UNICODE), $lignes)) . "\n");
    $code = Artisan::call('crm:presse:importer', ['file' => $fichier]);
    $sortie = Artisan::output();
    @unlink($fichier);

    return ['code' => $code, 'sortie' => $sortie];
}

/** @return array<string, mixed> */
function pddMeta(int $fiche): array
{
    $m = DB::table('companies')->where('id', $fiche)->value('metadata');
    $d = is_string($m) ? json_decode($m, true) : [];

    return is_array($d) ? $d : [];
}

test('🔴 deux fiches du même titre (registre ARCOM + kit presse), sans SIREN : FUSIONNÉES, la fiche ARCOM gardée ; repasser ne refait rien', function () {
    // Le kit presse d'abord (plus petit identifiant) : c'est la SOURCE qui décide.
    $kit = pddMedia($this->espace, ['name' => 'ZZ Chaîne Une', 'source' => 'press-kit']);
    $arcom = pddMedia($this->espace, ['name' => 'zz chaine une', 'source' => 'arcom']);
    Artisan::call('crm:presse:harmoniser');
    [$ficheKit, $ficheArcom] = [pddFiche($kit), pddFiche($arcom)];
    expect($ficheKit)->not->toBe($ficheArcom);

    $r = pddDoublons(['--appliquer' => true]);

    $journal = DB::table('fusions_fiches')->first();
    expect($r['code'])->toBe(0)
        ->and(pddCompteur($r['sortie'], 'fusionnees'))->toBe(1)
        ->and($journal->garde_id)->toBe($ficheArcom)
        ->and($journal->absorbee_id)->toBe($ficheKit)
        ->and($journal->motif)->toBe(Rapprochement::PRESSE_MEME_TITRE)
        ->and($journal->mode)->toBe(FusionFiches::MODE_AUTO)
        ->and(DB::table('companies')->where('id', $ficheKit)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('companies')->where('id', $ficheKit)->exists())->toBeTrue()
        ->and(pddFiche($arcom))->toBe($ficheArcom)
        ->and(pddFiche($kit))->toBe($ficheArcom)
        ->and(DB::table('duplicate_flags')->count())->toBe(0);

    $r2 = pddDoublons(['--appliquer' => true]);
    expect(pddCompteur($r2['sortie'], 'fusionnees'))->toBe(0)
        ->and(DB::table('fusions_fiches')->count())->toBe(1);
});

test('🔴 la fusion est ANNULABLE : la fiche absorbée sort de la corbeille et retrouve sa ligne média', function () {
    $a = pddMedia($this->espace, ['name' => 'ZZ Chaîne Annulable', 'source' => 'arcom']);
    $b = pddMedia($this->espace, ['name' => 'ZZ Chaîne Annulable', 'source' => 'press-kit']);
    Artisan::call('crm:presse:harmoniser');
    [$garde, $absorbee] = [pddFiche($a), pddFiche($b)];
    pddDoublons(['--appliquer' => true]);
    $fusion = (int) DB::table('fusions_fiches')->value('id');
    expect(pddFiche($b))->toBe($garde);

    WorkspaceContext::run($this->espace, fn (): array => app(FusionFiches::class)->annuler($this->espace, $fusion, 'test'));

    expect(DB::table('companies')->where('id', $absorbee)->value('deleted_at'))->toBeNull()
        ->and(pddFiche($b))->toBe($absorbee)
        ->and(pddFiche($a))->toBe($garde)
        ->and(DB::table('fusions_fiches')->where('id', $fusion)->value('annulee_at'))->not->toBeNull();

    // Défaite par un humain : jamais reprise seule — la paire va dans la file.
    $r = pddDoublons(['--appliquer' => true]);
    expect(pddCompteur($r['sortie'], 'fusionnees'))->toBe(0)
        ->and(pddCompteur($r['sortie'], 'fusions_annulees_non_reprises'))->toBe(1)
        ->and(DB::table('companies')->where('id', $absorbee)->value('deleted_at'))->toBeNull()
        ->and(DB::table('duplicate_flags')->where('motif', Rapprochement::PRESSE_HOMONYME)->count())->toBe(1);
});

test('🔴 deux ÉDITIONS (départements 31 et 81) : jamais fusionnées, jamais proposées ; le TÉMOIN au même département fusionne', function () {
    $toulouse = pddMedia($this->espace, ['name' => 'ZZ Dépêche', 'media_type' => 'presse_quotidien', 'department_code' => '31', 'source' => 'press-kit']);
    $albi = pddMedia($this->espace, ['name' => 'ZZ Dépêche', 'media_type' => 'presse_quotidien', 'department_code' => '81', 'source' => 'press-kit']);
    $toulouse2 = pddMedia($this->espace, ['name' => 'ZZ Dépêche', 'media_type' => 'presse_quotidien', 'department_code' => '31', 'source' => 'wikidata']);
    Artisan::call('crm:presse:harmoniser');

    $r = pddDoublons(['--appliquer' => true]);

    expect(pddCompteur($r['sortie'], 'fusionnees'))->toBe(1)
        ->and(pddCompteur($r['sortie'], 'editions_distinctes'))->toBeGreaterThan(0)
        ->and(DB::table('companies')->where('id', pddFiche($albi))->value('deleted_at'))->toBeNull()
        ->and(pddFiche($toulouse))->toBe(pddFiche($toulouse2))
        ->and(pddFiche($albi))->not->toBe(pddFiche($toulouse))
        ->and(DB::table('fusions_fiches')->where('absorbee_id', pddFiche($albi))->exists())->toBeFalse()
        ->and(DB::table('duplicate_flags')->count())->toBe(0);
});

test('🔴 une fiche à SIREN (l éditeur) et un homonyme sans SIREN : file « à vérifier », JAMAIS fusionnés — même en forçant le motif strict', function () {
    $editeur = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000771', 'denomination' => 'ZZ GROUPE TELE',
        'entity_nature' => 'media', 'relation_type' => 'presse_media', 'created_at' => now(), 'updated_at' => now(),
    ]);
    pddMedia($this->espace, ['name' => 'ZZ Chaîne Deux', 'company_id' => $editeur, 'source' => 'arcom']);
    $seul = pddMedia($this->espace, ['name' => 'ZZ Chaîne Deux', 'source' => 'press-kit']);
    Artisan::call('crm:presse:harmoniser');
    DB::table('companies')->where('id', $editeur)->update(['relation_type' => 'presse_media']);
    $provisoire = pddFiche($seul);

    $r = pddDoublons(['--appliquer' => true]);

    $flag = DB::table('duplicate_flags')->first();
    expect(pddCompteur($r['sortie'], 'fusionnees'))->toBe(0)
        ->and(pddCompteur($r['sortie'], 'deposees_a_verifier'))->toBe(1)
        ->and($flag->motif)->toBe(Rapprochement::PRESSE_HOMONYME)
        ->and((bool) $flag->fusion_auto)->toBeFalse()
        ->and((int) $flag->entity_a_id)->toBe($editeur)
        ->and((int) $flag->entity_b_id)->toBe($provisoire)
        ->and(DB::table('fusions_fiches')->count())->toBe(0)
        ->and(DB::table('companies')->where('id', $provisoire)->value('deleted_at'))->toBeNull();

    // Le motif strict forcé à la main de la commande : re-jugé, refusé.
    expect(fn () => WorkspaceContext::run($this->espace, fn (): int => app(FusionFiches::class)->fusionner(
        $this->espace,
        $provisoire,
        $editeur,
        Rapprochement::PRESSE_MEME_TITRE,
        FusionFiches::MODE_AUTO,
    )))->toThrow(RefusFusion::class, RefusFusion::MESSAGES['presse_pas_stricte']);

    // Repasser ne redépose pas la paire.
    $r2 = pddDoublons(['--appliquer' => true]);
    expect(pddCompteur($r2['sortie'], 'deja_en_file'))->toBe(1)
        ->and(DB::table('duplicate_flags')->count())->toBe(1);
});

test('🔴 deux adresses de rédaction CONTRADICTOIRES : file « à vérifier », pas de fusion', function () {
    $a = pddMedia($this->espace, ['name' => 'ZZ Radio Trois', 'media_type' => 'radio', 'source' => 'cppap', 'email' => 'redaction@zz-radio-a.example.invalid']);
    $b = pddMedia($this->espace, ['name' => 'ZZ Radio Trois', 'media_type' => 'radio', 'source' => 'press-kit', 'email' => 'contact@zz-radio-b.example.invalid']);
    Artisan::call('crm:presse:harmoniser');

    $r = pddDoublons(['--appliquer' => true]);

    expect(pddCompteur($r['sortie'], 'fusionnees'))->toBe(0)
        ->and(pddCompteur($r['sortie'], 'deposees_a_verifier'))->toBe(1)
        ->and(DB::table('fusions_fiches')->count())->toBe(0)
        ->and(DB::table('companies')->whereIn('id', [pddFiche($a), pddFiche($b)])->whereNotNull('deleted_at')->count())->toBe(0);
});

test('🔴 une émission et la chaîne du même nom : file « à vérifier », pas de fusion', function () {
    pddMedia($this->espace, ['name' => 'ZZ Matin', 'media_type' => 'tv', 'source' => 'arcom']);
    pddMedia($this->espace, ['name' => 'ZZ Matin', 'media_type' => 'tv_emission', 'source' => 'wikidata']);
    Artisan::call('crm:presse:harmoniser');

    $r = pddDoublons(['--appliquer' => true]);
    expect(pddCompteur($r['sortie'], 'fusionnees'))->toBe(0)
        ->and(DB::table('fusions_fiches')->count())->toBe(0);
});

test('🔴 à blanc (défaut) : les compteurs du réel, et RIEN n est écrit', function () {
    $a = pddMedia($this->espace, ['name' => 'ZZ Chaîne Blanche', 'source' => 'arcom']);
    $b = pddMedia($this->espace, ['name' => 'ZZ Chaîne Blanche', 'source' => 'press-kit']);
    pddMedia($this->espace, ['name' => 'ZZ Radio Blanche', 'media_type' => 'radio', 'email' => 'redaction@zz-rb-a.example.invalid']);
    pddMedia($this->espace, ['name' => 'ZZ Radio Blanche', 'media_type' => 'radio', 'email' => 'redaction@zz-rb-b.example.invalid']);
    Artisan::call('crm:presse:harmoniser');
    $avant = [
        'fiches' => DB::table('companies')->orderBy('id')->get(['id', 'deleted_at', 'metadata', 'email_generic'])->toArray(),
        'medias' => DB::table('media')->orderBy('id')->get(['id', 'company_id'])->toArray(),
        'contacts' => DB::table('contacts')->orderBy('id')->get(['id', 'company_id'])->toArray(),
        'flags' => DB::table('duplicate_flags')->count(),
        'fusions' => DB::table('fusions_fiches')->count(),
    ];

    $r = pddDoublons();

    expect($r['code'])->toBe(0)
        ->and($r['sortie'])->toContain('[À BLANC]')
        ->and(pddCompteur($r['sortie'], 'fusionnees'))->toBe(1)
        ->and(pddCompteur($r['sortie'], 'deposees_a_verifier'))->toBe(1)
        ->and([
            'fiches' => DB::table('companies')->orderBy('id')->get(['id', 'deleted_at', 'metadata', 'email_generic'])->toArray(),
            'medias' => DB::table('media')->orderBy('id')->get(['id', 'company_id'])->toArray(),
            'contacts' => DB::table('contacts')->orderBy('id')->get(['id', 'company_id'])->toArray(),
            'flags' => DB::table('duplicate_flags')->count(),
            'fusions' => DB::table('fusions_fiches')->count(),
        ])->toEqual($avant)
        ->and(pddFiche($a))->not->toBe(pddFiche($b));

    // --compteurs-seulement : aucun identifiant en sortie.
    $discret = pddDoublons(['--compteurs-seulement' => true]);
    expect($discret['sortie'])->not->toContain('Dernier groupe')
        ->and($discret['sortie'])->not->toContain('ZZ');
});

test('🔴 les MÉTADONNÉES de la presse suivent la fusion (site vérifié, classement, adresses de liste presse), et l annulation les retire', function () {
    $a = pddMedia($this->espace, ['name' => 'ZZ Chaîne Méta', 'source' => 'arcom']);
    $b = pddMedia($this->espace, ['name' => 'ZZ Chaîne Méta', 'source' => 'press-kit']);
    Artisan::call('crm:presse:harmoniser');
    [$garde, $absorbee] = [pddFiche($a), pddFiche($b)];
    DB::update("UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb WHERE id = ?", [json_encode([
        'site_media' => ['statut' => 'verifie', 'url' => 'https://zz-meta.example.invalid', 'le' => '2026-10-01', 'v' => 5],
        'classement_media' => ['theme' => 'generaliste', 'scores' => new stdClass],
        'emails_liste_presse' => ['commune@zz-meta.example.invalid', 'redaction@zz-meta.example.invalid'],
    ]), $absorbee]);
    DB::update("UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb WHERE id = ?", [json_encode([
        'emails_liste_presse' => ['commune@zz-meta.example.invalid'],
        'zz_autre' => 'garde',
    ]), $garde]);
    $avant = pddMeta($garde);
    $metaAbsorbee = pddMeta($absorbee);

    pddDoublons(['--appliquer' => true]);

    $apres = pddMeta($garde);
    $journal = (string) DB::table('fusions_fiches')->value('journal');
    expect($apres['site_media'])->toEqual($metaAbsorbee['site_media'])
        ->and($apres['classement_media'])->toEqual($metaAbsorbee['classement_media'])
        ->and($apres['emails_liste_presse'])->toBe(['commune@zz-meta.example.invalid', 'redaction@zz-meta.example.invalid'])
        ->and($apres['zz_autre'])->toBe('garde')
        // Le journal ne garde aucune valeur.
        ->and($journal)->not->toContain('zz-meta.example.invalid')
        ->and($journal)->toContain('metadonnees');
    // Un objet vide reste un objet (recopié par la base).
    expect((string) DB::selectOne("SELECT jsonb_typeof(metadata->'classement_media'->'scores') AS t FROM companies WHERE id = ?", [$garde])->t)
        ->toBe(DB::selectOne("SELECT jsonb_typeof(metadata->'classement_media'->'scores') AS t FROM companies WHERE id = ?", [$absorbee])->t);

    WorkspaceContext::run($this->espace, fn (): array => app(FusionFiches::class)->annuler($this->espace, (int) DB::table('fusions_fiches')->value('id'), 'test'));
    expect(pddMeta($garde))->toEqual($avant)
        ->and(pddMeta($absorbee))->toEqual($metaAbsorbee);
});

test('🔴 import : une ligne AMBIGUË avant le dédoublonnage est RATTACHÉE après, à la fiche gardée', function () {
    $a = pddMedia($this->espace, ['name' => 'ZZ Chaîne Import', 'source' => 'arcom']);
    $b = pddMedia($this->espace, ['name' => 'ZZ Chaîne Import', 'source' => 'press-kit']);
    Artisan::call('crm:presse:harmoniser');
    $garde = pddFiche($a);
    $ligne = ['identifiant' => 'presse:zz:chaine-import', 'nom' => 'ZZ Chaîne Import', 'type' => 'tv',
        'journaliste' => ['prenom' => 'Zia', 'nom' => 'ZZIMPORT']];

    $avant = pddImporter([$ligne]);
    expect($avant['sortie'])->toContain('rapprochement_ambigu : 1');

    pddDoublons(['--appliquer' => true]);
    $apres = pddImporter([$ligne]);

    expect($apres['sortie'])->not->toContain('rapprochement_ambigu')
        ->and(pddCompteur($apres['sortie'], 'titres_rapproches'))->toBe(1)
        ->and(DB::table('companies')->where('foreign_id', 'presse:zz:chaine-import')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('company_id', $garde)->where('last_name', 'ZZIMPORT')->exists())->toBeTrue()
        ->and(pddFiche($b))->toBe($garde);
});

test('🔴 import : plusieurs fiches, UNE seule au même département exactement — c est elle ; sans département, le doute reste', function () {
    $exacte = pddMedia($this->espace, ['name' => 'ZZ Édition Locale', 'media_type' => 'presse_quotidien', 'department_code' => '31']);
    $sansDep = pddMedia($this->espace, ['name' => 'ZZ Édition Locale', 'media_type' => 'presse_quotidien', 'source' => 'press-kit']);
    Artisan::call('crm:presse:harmoniser');
    expect(pddFiche($exacte))->not->toBe(pddFiche($sansDep));

    $r = pddImporter([
        ['identifiant' => 'presse:zz:edition-31', 'nom' => 'ZZ Édition Locale', 'type' => 'presse_quotidien', 'departement' => '31',
            'journaliste' => ['prenom' => 'Zed', 'nom' => 'ZZLOCAL']],
        ['identifiant' => 'presse:zz:edition-x', 'nom' => 'ZZ Édition Locale', 'type' => 'presse_quotidien'],
    ]);

    expect(pddCompteur($r['sortie'], 'titres_rapproches'))->toBe(1)
        ->and($r['sortie'])->toContain('rapprochement_ambigu : 1')
        ->and(DB::table('contacts')->where('company_id', pddFiche($exacte))->where('last_name', 'ZZLOCAL')->exists())->toBeTrue();
});

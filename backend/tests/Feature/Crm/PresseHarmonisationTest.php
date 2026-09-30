<?php

/**
 * HARMONISER LA PRESSE (2026-09-30) — `crm:presse:harmoniser`.
 *
 * Demande de Will : « harmoniser l'ensemble des contacts ». Chaque média
 * devient une fiche `companies` (nature `media`, relation `presse_media`,
 * étiquettes `media-type:`/`media-zone:`/`media-theme:`, protégée), chaque
 * journaliste un contact de cette fiche. Ordre permanent : RIEN n'est
 * supprimé, une personne retirée ne revient jamais, une relation posée à la
 * main n'est jamais écrasée.
 *
 * Chaque garde est prouvée par son EFFET, face à un TÉMOIN quand l'absence
 * d'effet pourrait passer pour une réussite.
 *
 * Fixtures FICTIVES uniquement : le dépôt est PUBLIC (SIREN en 9xxxxxxxx,
 * noms « ZZ », domaines `.example.invalid`).
 */

use App\Crm\Campagnes\Segments;
use App\Crm\Etiquettes\FamillesEtiquettes;
use App\Crm\FichesProtegees;
use App\Crm\Presse\EtiquettesMedia;
use App\Crm\Presse\QualificationPresse;
use App\Crm\Taxonomy;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\QueryException;
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
function phFiche(string $espace, array $valeurs = []): int
{
    return (int) DB::table('companies')->insertGetId($valeurs + [
        'workspace_id' => $espace,
        'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ EDITEUR FICTIF',
        'entity_nature' => 'entreprise',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @param  array<string, mixed>  $valeurs */
function phMedia(string $espace, array $valeurs = []): int
{
    return (int) DB::table('media')->insertGetId($valeurs + [
        'workspace_id' => $espace,
        'name' => 'ZZ Gazette fictive',
        'media_type' => 'presse_quotidien',
        'media_family' => 'editorial',
        'source' => 'cppap',
        'enrich_status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @param  array<string, mixed>  $valeurs */
function phJournaliste(string $espace, int $mediaId, array $valeurs = []): int
{
    return (int) DB::table('journalists')->insertGetId($valeurs + [
        'workspace_id' => $espace,
        'media_id' => $mediaId,
        'first_name' => 'Zoe',
        'last_name' => 'ZZJOURNALISTE',
        'role' => 'Rédactrice en chef',
        'beat' => 'Économie',
        'source' => 'wikidata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $options
 * @return array{code: int, sortie: string}
 */
function phHarmoniser(array $options = []): array
{
    $code = Artisan::call('crm:presse:harmoniser', $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function phCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);
    expect($m)->not->toBeEmpty("Compteur « {$cle} » absent de la sortie.");

    return (int) $m[1];
}

/** @return list<string> */
function phSlugs(int $companyId): array
{
    return DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)->orderBy('tags.slug')->pluck('tags.slug')->all();
}

/** @return array<string, int> */
function phVolumes(): array
{
    $v = [];
    foreach (['companies', 'contacts', 'media', 'journalists', 'tags', 'company_tag', 'contacts_retires'] as $t) {
        $v[$t] = DB::table($t)->count();
    }

    return $v;
}

// ── Le référentiel ─────────────────────────────────────────────────────────

test('chaque media_type autorise par la base a son etiquette, et chaque etiquette sa famille et sa categorie', function () {
    // Les types de la contrainte INSTALLÉE, pas une liste recopiée.
    $def = (string) DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'media_media_type_check'",
    )->d;
    preg_match_all("/'([a-z_]+)'/", $def, $m);
    $types = array_values(array_unique($m[1]));
    expect(count($types))->toBeGreaterThanOrEqual(10, 'la contrainte installée ne rend presque rien : la garde ne mesure rien');

    foreach ($types as $type) {
        expect(EtiquettesMedia::typeEtiquette($type))->not->toBeNull("media_type « {$type} » sans étiquette");
    }
    expect(array_values(array_unique(Taxonomy::MEDIA_TYPE_VERS_ETIQUETTE)))
        ->toEqualCanonicalizing(array_keys(Taxonomy::MEDIA_TYPES_ETIQUETTE));

    $lignes = [(object) ['media_type' => 'radio', 'diffusion_zone' => 'Régionale', 'editorial_theme' => 'Économie']];
    foreach (EtiquettesMedia::desirees($lignes) as $slug => $spec) {
        expect(FamillesEtiquettes::famille($slug, 'auto')['type'])->toBe(FamillesEtiquettes::TYPE_GOUVERNEE)
            ->and($spec['category'])->toBe(FamillesEtiquettes::categorieAttendue($slug, 'auto'));
    }
});

test('la zone de diffusion vient de la source SEULEMENT : aucune zone inventee', function () {
    expect(EtiquettesMedia::zone('national'))->toBe('national')
        ->and(EtiquettesMedia::zone('régional'))->toBe('regional')
        ->and(EtiquettesMedia::zone('Départementale'))->toBe('departemental')
        ->and(EtiquettesMedia::zone('local'))->toBe('local')
        ->and(EtiquettesMedia::zone(null))->toBe('inconnue')
        ->and(EtiquettesMedia::zone('75'))->toBe('inconnue');

    // Un département (celui du SIÈGE de l'éditeur) ne fabrique pas « départemental ».
    $t = EtiquettesMedia::desirees([(object) ['media_type' => 'presse_quotidien', 'diffusion_zone' => null, 'editorial_theme' => null]]);
    expect(array_keys($t))->toBe(['media-type:presse-quotidienne', 'media-zone:inconnue']);

    // « inconnue » n'accompagne jamais une zone connue.
    $t = EtiquettesMedia::desirees([
        (object) ['media_type' => 'tv', 'diffusion_zone' => null, 'editorial_theme' => null],
        (object) ['media_type' => 'tv_emission', 'diffusion_zone' => 'national', 'editorial_theme' => null],
    ]);
    expect(array_keys($t))->toContain('media-zone:national')->not->toContain('media-zone:inconnue');
});

// ── Un média avec fiche ────────────────────────────────────────────────────

test('un media AVEC fiche : la fiche devient media / presse_media, protegee, etiquetee, completee sans rien remplacer', function () {
    $fiche = phFiche($this->espace, ['phone' => '04 00 00 00 09']);
    phMedia($this->espace, [
        'company_id' => $fiche, 'diffusion_zone' => 'régional', 'editorial_theme' => 'Économie',
        'email' => 'redaction@zz-gazette.example.invalid', 'phone' => '04 11 11 11 11',
        'website' => 'https://zz-gazette.example.invalid',
    ]);

    $r = phHarmoniser();

    $f = DB::table('companies')->where('id', $fiche)->first();
    expect($r['code'])->toBe(0)
        ->and($f->entity_nature)->toBe('media')
        ->and($f->relation_type)->toBe('presse_media')
        ->and(json_decode($f->metadata, true)[QualificationPresse::CLE_AVANT])
        ->toMatchArray(['nature_avant' => 'entreprise', 'relation_avant' => 'prospect'])
        // Backfill-only : l'adresse manquante est posée, le téléphone existant gardé.
        ->and($f->email_generic)->toBe('redaction@zz-gazette.example.invalid')
        ->and($f->phone)->toBe('04 00 00 00 09')
        ->and(phSlugs($fiche))->toContain(FichesProtegees::TAG_PRESSE, 'media-type:presse-quotidienne', 'media-zone:regional', 'media-theme:economie', 'nature-media')
        ->and(FichesProtegees::estProtegee($fiche))->toBeTrue()
        ->and(phCompteur($r['sortie'], 'fiches_existantes'))->toBe(1)
        ->and(phCompteur($r['sortie'], 'natures_posees'))->toBe(1)
        ->and(phCompteur($r['sortie'], 'relations_posees'))->toBe(1);

    // La base elle-même refuse de supprimer la fiche : ordre de Will.
    expect(fn () => DB::transaction(fn () => DB::table('companies')->where('id', $fiche)->delete()))
        ->toThrow(QueryException::class, 'fiche_protegee');
    expect(DB::table('companies')->where('id', $fiche)->exists())->toBeTrue();
});

test('une relation ou une nature deja qualifiee n est JAMAIS ecrasee ; le temoin froid l est', function () {
    $cas = [
        'client' => ['relation_type' => 'client', 'lifecycle_stage' => 'client'],
        'partenaire' => ['relation_type' => 'partenaire'],
        'prospect_qualifie' => ['relation_type' => 'prospect', 'lifecycle_stage' => 'qualifie'],
        'association' => ['entity_nature' => 'association'],
        'temoin' => [],
    ];
    $fiches = [];
    foreach ($cas as $nom => $valeurs) {
        $fiches[$nom] = phFiche($this->espace, $valeurs + ['denomination' => 'ZZ ' . $nom]);
        phMedia($this->espace, ['company_id' => $fiches[$nom], 'name' => 'ZZ media ' . $nom]);
    }

    $r = phHarmoniser();

    $rel = fn (string $n) => DB::table('companies')->where('id', $fiches[$n])->value('relation_type');
    $nat = fn (string $n) => DB::table('companies')->where('id', $fiches[$n])->value('entity_nature');
    expect($rel('client'))->toBe('client')
        ->and($rel('partenaire'))->toBe('partenaire')
        ->and($rel('prospect_qualifie'))->toBe('prospect')
        ->and($nat('association'))->toBe('association')
        ->and($rel('association'))->toBe('presse_media')
        ->and($rel('temoin'))->toBe('presse_media')
        ->and($nat('temoin'))->toBe('media')
        ->and(phCompteur($r['sortie'], 'relations_conservees'))->toBe(3)
        ->and(phCompteur($r['sortie'], 'natures_conservees'))->toBe(1)
        // Toutes restent visables comme média, par leurs étiquettes.
        ->and(phSlugs($fiches['client']))->toContain('media-type:presse-quotidienne', FichesProtegees::TAG_PRESSE);
});

test('updated_at d une fiche existante n est pas touche : qualifier n est pas modifier', function () {
    $fiche = phFiche($this->espace, ['updated_at' => '2026-01-02 03:04:05+00', 'created_at' => '2026-01-02 03:04:05+00']);
    phMedia($this->espace, ['company_id' => $fiche]);
    $avant = DB::table('companies')->where('id', $fiche)->value('updated_at');

    phHarmoniser();

    expect(DB::table('companies')->where('id', $fiche)->value('entity_nature'))->toBe('media')
        ->and(DB::table('companies')->where('id', $fiche)->value('updated_at'))->toBe($avant);
});

// ── Un média sans fiche ────────────────────────────────────────────────────

test('un media SANS fiche : une fiche nait sur (FR, media:<id>) et le media y est relie ; rejouer ne cree rien', function () {
    $media = phMedia($this->espace, ['media_type' => 'agence_presse', 'name' => 'ZZ Agence fictive', 'source' => 'agence']);

    $r = phHarmoniser();
    $fiche = DB::table('companies')->where('country_code', 'FR')->where('foreign_id', 'media:' . $media)->first();

    expect($r['code'])->toBe(0)
        ->and($fiche)->not->toBeNull()
        ->and($fiche->entity_nature)->toBe('media')
        ->and($fiche->relation_type)->toBe('presse_media')
        ->and($fiche->denomination)->toBe('ZZ Agence fictive')
        ->and((int) DB::table('media')->where('id', $media)->value('company_id'))->toBe((int) $fiche->id)
        ->and(DB::table('media')->where('id', $media)->value('harmonise_le'))->not->toBeNull()
        ->and(phSlugs((int) $fiche->id))->toContain('media-type:agence', 'media-zone:inconnue', FichesProtegees::TAG_PRESSE)
        ->and(phCompteur($r['sortie'], 'fiches_creees'))->toBe(1);

    $volumes = phVolumes();
    $r2 = phHarmoniser();
    expect(phVolumes())->toBe($volumes)
        ->and(phCompteur($r2['sortie'], 'fiches_creees'))->toBe(0)
        ->and(phCompteur($r2['sortie'], 'fiches_existantes'))->toBe(1);
});

test('une EMISSION va sur la fiche de sa chaine, harmonisee d abord ; ses journalistes y deviennent contacts', function () {
    // La chaîne reçoit un identifiant PLUS GRAND que l'émission : l'émission
    // est traitée AVANT sa chaîne, qui doit donc être harmonisée d'abord.
    $idChaine = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('media', 'id')) + 1000 AS n")->n;
    $chaine = phMedia($this->espace, ['id' => $idChaine, 'media_type' => 'tv', 'name' => 'ZZ TV fictive', 'diffusion_zone' => 'national', 'source' => 'arcom']);
    $emission = phMedia($this->espace, ['media_type' => 'tv_emission', 'name' => 'ZZ Journal du soir', 'parent_media_id' => $chaine, 'source' => 'wikidata',
        'email' => 'emission@zz-tv.example.invalid']);
    phJournaliste($this->espace, $emission);
    expect($emission)->toBeLessThan($chaine);

    $r = phHarmoniser();

    $ficheChaine = (int) DB::table('media')->where('id', $chaine)->value('company_id');
    expect($r['code'])->toBe(0)
        ->and($ficheChaine)->toBeGreaterThan(0)
        ->and((int) DB::table('media')->where('id', $emission)->value('company_id'))->toBe($ficheChaine)
        // Une seule fiche : l'émission n'en a pas reçu une à elle.
        ->and(DB::table('companies')->where('foreign_id', 'media:' . $emission)->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('company_id', $ficheChaine)->where('last_name', 'ZZJOURNALISTE')->exists())->toBeTrue()
        // L'adresse de l'émission n'est pas celle de la chaîne.
        ->and(DB::table('companies')->where('id', $ficheChaine)->value('email_generic'))->toBeNull()
        ->and(phSlugs($ficheChaine))->toContain('media-type:tv', 'media-type:emission-tv', 'media-zone:national')
        ->and(phCompteur($r['sortie'], 'emissions_sur_la_chaine'))->toBe(1);
});

// ── Les journalistes ──────────────────────────────────────────────────────

test('chaque journaliste vivant et non oppose devient un contact de la fiche, relie, avec sa rubrique ; la porte d acces est respectee', function () {
    $fiche = phFiche($this->espace);
    $media = phMedia($this->espace, ['company_id' => $fiche]);
    $a = phJournaliste($this->espace, $media, ['email' => 'zoe.zz@zz-gazette.example.invalid', 'acces' => 'email_redaction', 'linkedin_slug' => 'zoe-zz-1a2b3c', 'phone' => '06 00 00 00 01']);
    $b = phJournaliste($this->espace, $media, ['first_name' => 'Bob', 'last_name' => 'ZZOPPOSE', 'opt_out' => true]);
    $c = phJournaliste($this->espace, $media, ['first_name' => 'Cyd', 'last_name' => 'ZZCORBEILLE', 'deleted_at' => now()]);
    $d = phJournaliste($this->espace, $media, ['first_name' => 'Dan', 'last_name' => 'ZZPROD', 'email' => 'dan.zz@zz-gazette.example.invalid', 'acces' => 'redaction_prod']);
    phJournaliste($this->espace, $media, ['first_name' => 'Eve', 'last_name' => null]);

    $r = phHarmoniser();

    $ca = DB::table('contacts')->where('external_ref', 'journaliste:' . $a)->first();
    $cd = DB::table('contacts')->where('external_ref', 'journaliste:' . $d)->first();
    expect($r['code'])->toBe(0)
        ->and($ca)->not->toBeNull()
        ->and((int) $ca->company_id)->toBe($fiche)
        ->and($ca->role)->toBe('Rédactrice en chef')
        ->and($ca->email)->toBe('zoe.zz@zz-gazette.example.invalid')
        ->and($ca->phone)->toBe('06 00 00 00 01')
        ->and($ca->linkedin_url)->toBe('https://www.linkedin.com/in/zoe-zz-1a2b3c')
        ->and($ca->legal_basis)->toBe('legitimate_interest_b2b')
        ->and(json_decode($ca->sources, true))->toContain(QualificationPresse::SOURCE)
        ->and(json_decode($ca->metadata, true))->toMatchArray(['rubrique' => 'Économie', 'journaliste_id' => $a, 'media_id' => $media, 'acces' => 'email_redaction'])
        ->and((int) DB::table('journalists')->where('id', $a)->value('contact_id'))->toBe((int) $ca->id)
        // Porte « par la production » : la personne entre, son adresse NON.
        ->and($cd)->not->toBeNull()
        ->and($cd->email)->toBeNull()
        ->and(json_decode($cd->metadata, true)['acces'])->toBe('redaction_prod')
        ->and(DB::table('contacts')->where('last_name', 'ZZOPPOSE')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('last_name', 'ZZCORBEILLE')->exists())->toBeFalse()
        ->and(DB::table('journalists')->whereIn('id', [$b, $c])->whereNotNull('contact_id')->exists())->toBeFalse()
        ->and(phCompteur($r['sortie'], 'journalistes_convertis'))->toBe(2)
        ->and(phCompteur($r['sortie'], 'journalistes_opposes'))->toBe(1)
        ->and(phCompteur($r['sortie'], 'journalistes_sans_nom'))->toBe(1)
        ->and(phCompteur($r['sortie'], 'emails_journalistes_retenus_par_acces'))->toBe(1)
        // La ligne source n'est pas touchée : elle garde son adresse.
        ->and(DB::table('journalists')->where('id', $d)->value('email'))->toBe('dan.zz@zz-gazette.example.invalid');
});

test('une personne RETIREE ne revient jamais : contact supprime, a la corbeille, ou au registre ; le temoin entre', function () {
    $fiche = phFiche($this->espace);
    $media = phMedia($this->espace, ['company_id' => $fiche]);
    $a = phJournaliste($this->espace, $media, ['first_name' => 'Ana', 'last_name' => 'ZZSUPPRIMEE']);
    $d = phJournaliste($this->espace, $media, ['first_name' => 'Dom', 'last_name' => 'ZZCORBEILLE']);
    $g = phJournaliste($this->espace, $media, ['first_name' => 'Gus', 'last_name' => 'ZZAVANTRENOMMAGE']);
    phHarmoniser();

    // Will supprime Ana (vraie suppression) et met Dom à la corbeille ; il
    // corrige le nom de Gus PUIS le met à la corbeille — seule sa référence
    // `journaliste:<id>` dit encore que c'est lui.
    DB::table('contacts')->where('external_ref', 'journaliste:' . $a)->delete();
    DB::table('contacts')->where('external_ref', 'journaliste:' . $d)->update(['deleted_at' => now()]);
    DB::table('contacts')->where('external_ref', 'journaliste:' . $g)->update(['last_name' => 'ZZAPRESRENOMMAGE', 'deleted_at' => now()]);
    // Le déclencheur a inscrit Ana au registre (source presse-2026).
    expect(DB::table('contacts_retires')->where('company_id', $fiche)->count())->toBe(1)
        ->and(DB::table('journalists')->where('id', $a)->value('contact_id'))->toBeNull();

    // Une personne retirée AVANT l'harmonisation (registre seulement).
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $fiche, 'first_name' => 'Rex', 'last_name' => 'ZZREGISTRE',
        'sources' => json_encode([QualificationPresse::SOURCE]), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->where('last_name', 'ZZREGISTRE')->delete();
    phJournaliste($this->espace, $media, ['first_name' => 'Rex', 'last_name' => 'ZZREGISTRE']);
    $temoin = phJournaliste($this->espace, $media, ['first_name' => 'Tim', 'last_name' => 'ZZTEMOIN']);

    $r = phHarmoniser();

    expect(DB::table('contacts')->where('last_name', 'ZZSUPPRIMEE')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('last_name', 'ZZCORBEILLE')->whereNull('deleted_at')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('last_name', 'ZZREGISTRE')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('last_name', 'ZZAVANTRENOMMAGE')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('external_ref', 'journaliste:' . $temoin)->exists())->toBeTrue()
        ->and(phCompteur($r['sortie'], 'journalistes_retires_ignores'))->toBe(4)
        ->and(phCompteur($r['sortie'], 'journalistes_convertis'))->toBe(1);
});

// ── Ce qui ne revient jamais ──────────────────────────────────────────────

test('une fiche a la corbeille ou supprimee apres harmonisation n est JAMAIS recreee ; le temoin est harmonise', function () {
    $corbeille = phFiche($this->espace, ['deleted_at' => now()]);
    phMedia($this->espace, ['company_id' => $corbeille, 'name' => 'ZZ media corbeille']);
    $seul = phMedia($this->espace, ['name' => 'ZZ media sans fiche']);
    $temoin = phMedia($this->espace, ['name' => 'ZZ media temoin', 'company_id' => phFiche($this->espace)]);

    $r = phHarmoniser();
    expect($r['sortie'])->toContain('fiche_a_la_corbeille : 1')
        ->and(DB::table('companies')->where('id', $corbeille)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('companies')->where('id', $corbeille)->value('entity_nature'))->toBe('entreprise')
        ->and(DB::table('media')->where('id', $temoin)->value('harmonise_le'))->not->toBeNull();

    // Will supprime (vraiment) la fiche née pour le média sans fiche.
    $ficheNee = (int) DB::table('media')->where('id', $seul)->value('company_id');
    DB::transaction(function () use ($ficheNee): void {
        DB::statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        DB::table('companies')->where('id', $ficheNee)->delete();
    });
    expect(DB::table('media')->where('id', $seul)->value('company_id'))->toBeNull();
    $fiches = DB::table('companies')->count();

    $r2 = phHarmoniser();
    expect($r2['sortie'])->toContain('fiche_supprimee_non_recreee : 1')
        ->and(DB::table('companies')->count())->toBe($fiches)
        ->and(DB::table('companies')->where('foreign_id', 'media:' . $seul)->exists())->toBeFalse();
});

test('la production audiovisuelle recoit ses etiquettes et RIEN d autre ; --inclure-production la traite comme la presse', function () {
    $fiche = phFiche($this->espace);
    phMedia($this->espace, ['company_id' => $fiche, 'media_type' => 'production_audiovisuelle', 'media_family' => 'audiovisual_production', 'source' => 'naf-extract']);
    $sansFiche = phMedia($this->espace, ['media_type' => 'production_audiovisuelle', 'media_family' => 'audiovisual_production', 'name' => 'ZZ Studio sans fiche']);

    $r = phHarmoniser();
    $f = DB::table('companies')->where('id', $fiche)->first();
    expect($f->entity_nature)->toBe('entreprise')
        ->and($f->relation_type)->toBe('prospect')
        ->and(phSlugs($fiche))->toContain('media-type:production')->not->toContain(FichesProtegees::TAG_PRESSE)
        ->and(FichesProtegees::estProtegee($fiche))->toBeFalse()
        ->and(DB::table('media')->where('id', $sansFiche)->value('company_id'))->toBeNull()
        ->and(phCompteur($r['sortie'], 'production_etiquetee'))->toBe(1)
        ->and(phCompteur($r['sortie'], 'production_sans_fiche_ignoree'))->toBe(1);

    phHarmoniser(['--inclure-production' => true]);
    expect(DB::table('companies')->where('id', $fiche)->value('relation_type'))->toBe('presse_media')
        ->and(FichesProtegees::estProtegee($fiche))->toBeTrue()
        ->and(DB::table('media')->where('id', $sansFiche)->value('company_id'))->not->toBeNull();
});

test('une adresse de redaction GRAND PUBLIC ne devient pas l adresse de la fiche', function () {
    $media = phMedia($this->espace, ['email' => 'zz.blog.fictif@gmail.com', 'media_type' => 'blog', 'name' => 'ZZ Blog fictif']);

    $r = phHarmoniser();

    $fiche = (int) DB::table('media')->where('id', $media)->value('company_id');
    expect(DB::table('companies')->where('id', $fiche)->value('email_generic'))->toBeNull()
        ->and(phCompteur($r['sortie'], 'emails_grand_public_non_poses'))->toBe(1);
});

// ── Rien n'est supprimé ───────────────────────────────────────────────────

test('RIEN n est supprime : aucun volume ne baisse, et les etiquettes manuelle, verrouillee et src: restent', function () {
    $fiche = phFiche($this->espace);
    phMedia($this->espace, ['company_id' => $fiche]);
    $tags = [];
    foreach ([['zz-manuelle', 'manual', false], ['svc:audit', 'auto', true], ['src:scraping-zz-autre', 'auto', true], ['media-type:tv', 'auto', false]] as [$slug, $kind, $verrou]) {
        $tags[$slug] = (int) DB::table('tags')->insertGetId([
            'workspace_id' => $this->espace, 'slug' => $slug, 'name' => $slug, 'category' => str_starts_with($slug, 'media') || $slug === 'zz-manuelle' ? 'custom' : 'intent',
            'kind' => $kind, 'rules' => '{}', 'is_locked' => $verrou, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('company_tag')->insert(['company_id' => $fiche, 'tag_id' => $tags[$slug], 'workspace_id' => $this->espace,
            'assigned_at' => now(), 'assigned_by' => $kind === 'manual' ? 'user' : 'auto-rule']);
    }
    $avant = phVolumes();

    phHarmoniser();

    $apres = phVolumes();
    foreach (['companies', 'contacts', 'media', 'journalists', 'tags'] as $t) {
        expect($apres[$t])->toBeGreaterThanOrEqual($avant[$t], "{$t} a baissé");
    }
    // Les trois étiquettes à protéger restent ; le TÉMOIN automatique qui ne
    // décrit plus la fiche (`media-type:tv` sur un quotidien) est retiré par
    // la synchro ordinaire — la preuve qu'elle a bien tourné.
    expect(phSlugs($fiche))->toContain('zz-manuelle', 'svc:audit', 'src:scraping-zz-autre', 'media-type:presse-quotidienne')
        ->not->toContain('media-type:tv');
});

// ── Paquets, reprise, essai à blanc ───────────────────────────────────────

test('a blanc : rien n est ecrit, et les compteurs sont ceux du reel', function () {
    $fiche = phFiche($this->espace);
    $m1 = phMedia($this->espace, ['company_id' => $fiche]);
    phJournaliste($this->espace, $m1);
    phMedia($this->espace, ['name' => 'ZZ Radio fictive', 'media_type' => 'radio', 'diffusion_zone' => 'local']);
    $avant = phVolumes();
    $avantScrape = [DB::table('scraper_runs')->count(), DB::table('activities')->count()];

    $blanc = phHarmoniser(['--dry-run' => true, '--paquet' => 1]);

    expect($blanc['code'])->toBe(0)
        ->and($blanc['sortie'])->toContain('[À BLANC]')
        ->and(phVolumes())->toBe($avant)
        ->and([DB::table('scraper_runs')->count(), DB::table('activities')->count()])->toBe($avantScrape)
        ->and(DB::table('companies')->where('id', $fiche)->value('entity_nature'))->toBe('entreprise')
        ->and(DB::table('media')->whereNotNull('harmonise_le')->exists())->toBeFalse();

    $reel = phHarmoniser(['--paquet' => 1]);
    foreach (['medias_lus', 'paquets', 'fiches_creees', 'fiches_existantes', 'natures_posees', 'relations_posees', 'journalistes_convertis', 'contacts_crees'] as $cle) {
        expect(phCompteur($blanc['sortie'], $cle))->toBe(phCompteur($reel['sortie'], $cle), "compteur {$cle}");
    }
    expect(phCompteur($reel['sortie'], 'fiches_creees'))->toBe(1)
        ->and(phCompteur($reel['sortie'], 'journalistes_convertis'))->toBe(1);
});

test('par paquets, par etapes et reprenable : --paquet, --limite, --depuis-id', function () {
    $ids = [];
    for ($i = 1; $i <= 5; $i++) {
        $ids[] = phMedia($this->espace, ['name' => 'ZZ media ' . $i]);
    }

    $r = phHarmoniser(['--limite' => 2, '--paquet' => 2]);
    expect(phCompteur($r['sortie'], 'medias_lus'))->toBe(2)
        ->and(DB::table('media')->whereNotNull('company_id')->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all())->toBe([$ids[0], $ids[1]])
        ->and($r['sortie'])->toContain('--depuis-id=' . ($ids[1] + 1));

    $r2 = phHarmoniser(['--depuis-id' => $ids[1] + 1, '--paquet' => 2]);
    expect(phCompteur($r2['sortie'], 'medias_lus'))->toBe(3)
        ->and(phCompteur($r2['sortie'], 'paquets'))->toBe(2)
        ->and(DB::table('media')->whereNull('company_id')->count())->toBe(0);

    expect(phHarmoniser(['--paquet' => 0])['code'])->toBe(1)
        ->and(phHarmoniser(['--depuis-id' => 'x'])['code'])->toBe(1);
});

test('--compteurs-seulement : que des nombres, ni nom, ni adresse, ni identifiant', function () {
    $media = phMedia($this->espace, ['name' => 'ZZ Nom Qui Ne Doit Pas Sortir', 'email' => 'secret@zz-sortie.example.invalid']);
    phJournaliste($this->espace, $media, ['last_name' => 'ZZPERSONNESECRETE']);

    $r = phHarmoniser(['--compteurs-seulement' => true]);

    expect($r['code'])->toBe(0)
        ->and($r['sortie'])->not->toContain('ZZ Nom Qui Ne Doit Pas Sortir')
        ->and($r['sortie'])->not->toContain('zz-sortie')
        ->and($r['sortie'])->not->toContain('ZZPERSONNESECRETE')
        ->and($r['sortie'])->not->toContain('--depuis-id=')
        ->and($r['sortie'])->not->toContain($this->espace)
        ->and(phCompteur($r['sortie'], 'fiches_creees'))->toBe(1);
});

// ── Campagnes ─────────────────────────────────────────────────────────────

test('le segment presse est DEFINI mais FERME : la liste de campagne le refuse tant que Will ne l ouvre pas', function () {
    expect(Segments::tag(Segments::PRESSE))->toBe(FichesProtegees::TAG_PRESSE)
        ->and(Segments::OUVERTS)->not->toContain(Segments::PRESSE);

    $sortie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz-presse-' . Str::random(6) . '.jsonl';
    $code = Artisan::call('crm:campagne:destinataires', ['segment' => Segments::PRESSE, 'sortie' => $sortie]);
    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('Segment fermé ou inconnu')
        ->and(is_file($sortie))->toBeFalse();
});

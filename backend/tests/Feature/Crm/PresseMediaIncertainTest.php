<?php

/**
 * LE MÉDIA INCERTAIN (constat en production du 2026-09-30).
 *
 * Le premier passage réel de `crm:presse:harmoniser --limite=500` a basculé
 * en presse 213 fiches existantes, dont 79 en NAF 63.12Z et 16 en 58.19Z :
 * des sociétés web, de vrais prospects, que seule une ligne `media`
 * `naf-extract` (déduite du code NAF) faisait passer pour des médias.
 *
 *  - l'harmonisation ne bascule plus une telle fiche : étiquette
 *    `media-possible:a-verifier` seulement ;
 *  - une vraie source presse sur la fiche (CPPAP…) la garde dans la presse ;
 *  - `crm:presse:reparer-media-incertain` rend l'état d'avant aux fiches
 *    basculées, sans jamais toucher une relation saisie à la main, et n'écrit
 *    rien sans `--appliquer`.
 *
 * Les fiches « basculées » sont produites par le PRODUCTEUR réel de la
 * bascule (`QualificationPresse::qualifier`, celui qu'appelait
 * l'harmonisation), pas recopiées à la main.
 *
 * Fixtures FICTIVES uniquement : le dépôt est PUBLIC (SIREN en 9xxxxxxxx,
 * noms « ZZ »).
 */

use App\Console\Commands\CrmPresseReparerMediaIncertain;
use App\Crm\FichesProtegees;
use App\Crm\Presse\MediaIncertain;
use App\Crm\Presse\QualificationPresse;
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

/** Une fiche Sirene ordinaire, avec son code NAF. */
function pmiFiche(string $espace, string $naf, array $valeurs = []): int
{
    return (int) DB::table('companies')->insertGetId($valeurs + [
        'workspace_id' => $espace,
        'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ SOCIETE FICTIVE',
        'naf' => $naf,
        'entity_nature' => 'entreprise',
        'relation_type' => 'prospect',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Une ligne `media` rattachée (par défaut : déduite du NAF, comme `media:extract-from-companies`). */
function pmiMedia(string $espace, ?int $fiche, string $type, string $source = 'naf-extract'): int
{
    return (int) DB::table('media')->insertGetId([
        'workspace_id' => $espace, 'company_id' => $fiche, 'name' => 'ZZ media fictif',
        'media_type' => $type, 'media_family' => 'editorial', 'source' => $source,
        'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array{code: int, sortie: string} */
function pmiCommande(string $commande, array $options = []): array
{
    $code = Artisan::call($commande, $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function pmiCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);
    expect($m)->not->toBeEmpty("Compteur « {$cle} » absent de la sortie.");

    return (int) $m[1];
}

/** @return list<string> */
function pmiSlugs(int $companyId): array
{
    return DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)->orderBy('tags.slug')->pluck('tags.slug')->all();
}

/**
 * Une fiche BASCULÉE à tort, comme l'a fait le passage du 30/09 : le
 * producteur réel (`qualifier`) garde l'état d'avant dans `metadata`, et le
 * funnel avait posé le tag de provenance presse, verrouillé.
 */
function pmiBasculer(string $espace, int $fiche): void
{
    QualificationPresse::qualifier($fiche);
    $tag = DB::table('tags')->where('workspace_id', $espace)->where('slug', FichesProtegees::TAG_PRESSE)->value('id')
        ?? DB::table('tags')->insertGetId([
            'workspace_id' => $espace, 'slug' => FichesProtegees::TAG_PRESSE, 'name' => 'Presse 2026', 'category' => 'intent',
            'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    DB::table('company_tag')->insertOrIgnore([
        'company_id' => $fiche, 'tag_id' => $tag, 'workspace_id' => $espace, 'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);
}

// ── La règle, à l'harmonisation ────────────────────────────────────────────

test('une fiche 63.12Z venue du seul naf-extract ne devient PAS presse : etiquette media-possible seulement', function () {
    $portail = pmiFiche($this->espace, '63.12Z');
    pmiMedia($this->espace, $portail, 'portail_web');
    $edition = pmiFiche($this->espace, '5819Z');
    pmiMedia($this->espace, $edition, 'presse_autre');

    $r = pmiCommande('crm:presse:harmoniser');

    expect($r['code'])->toBe(0)
        ->and(pmiCompteur($r['sortie'], 'media_naf_incertain_etiquete'))->toBe(2)
        ->and(pmiCompteur($r['sortie'], 'relations_posees'))->toBe(0);
    foreach ([$portail, $edition] as $id) {
        $f = DB::table('companies')->where('id', $id)->first();
        expect($f->entity_nature)->toBe('entreprise')
            ->and($f->relation_type)->toBe('prospect')
            ->and(json_decode((string) $f->metadata, true) ?? [])->not->toHaveKey(QualificationPresse::CLE_AVANT)
            ->and(pmiSlugs($id))->toContain(MediaIncertain::ETIQUETTE)
            ->and(pmiSlugs($id))->not->toContain(FichesProtegees::TAG_PRESSE)
            ->and(FichesProtegees::estProtegee($id))->toBeFalse();
    }
});

test('temoin : une fiche 58.14Z (revue) venue de naf-extract DEVIENT presse, sans etiquette media-possible', function () {
    $revue = pmiFiche($this->espace, '58.14Z');
    pmiMedia($this->espace, $revue, 'presse_revue');

    $r = pmiCommande('crm:presse:harmoniser');

    $f = DB::table('companies')->where('id', $revue)->first();
    expect($r['code'])->toBe(0)
        ->and($f->entity_nature)->toBe('media')
        ->and($f->relation_type)->toBe('presse_media')
        ->and(pmiSlugs($revue))->toContain(FichesProtegees::TAG_PRESSE)
        ->and(pmiSlugs($revue))->not->toContain(MediaIncertain::ETIQUETTE)
        ->and(pmiCompteur($r['sortie'], 'media_naf_incertain_etiquete'))->toBe(0);
});

test('une fiche 63.12Z presente AUSSI en CPPAP est de la presse : une vraie source suffit', function () {
    $fiche = pmiFiche($this->espace, '63.12Z');
    pmiMedia($this->espace, $fiche, 'portail_web');
    pmiMedia($this->espace, $fiche, 'presse_quotidien', 'cppap');

    expect(MediaIncertain::fiche($fiche))->toBeFalse();

    $r = pmiCommande('crm:presse:harmoniser');

    $f = DB::table('companies')->where('id', $fiche)->first();
    expect($r['code'])->toBe(0)
        ->and($f->entity_nature)->toBe('media')
        ->and($f->relation_type)->toBe('presse_media')
        ->and(pmiSlugs($fiche))->not->toContain(MediaIncertain::ETIQUETTE);
});

test('une ligne naf-extract portail_web SANS fiche ne cree pas de fiche de presse', function () {
    pmiMedia($this->espace, null, 'portail_web');
    $avant = DB::table('companies')->count();

    $r = pmiCommande('crm:presse:harmoniser');

    expect($r['code'])->toBe(0)
        ->and(DB::table('companies')->count())->toBe($avant)
        ->and(pmiCompteur($r['sortie'], 'media_naf_incertain_sans_fiche'))->toBe(1)
        ->and(pmiCompteur($r['sortie'], 'fiches_creees'))->toBe(0);
});

test('une liste presse declarative ne se rattache pas a un media incertain (estFichePresse), meme bascule a tort', function () {
    $portail = pmiFiche($this->espace, '63.12Z');
    pmiMedia($this->espace, $portail, 'portail_web');
    $revue = pmiFiche($this->espace, '58.14Z');
    pmiMedia($this->espace, $revue, 'presse_revue');

    expect(QualificationPresse::estFichePresse($portail))->toBeFalse()
        ->and(QualificationPresse::estFichePresse($revue))->toBeTrue();

    pmiBasculer($this->espace, $portail);
    expect(QualificationPresse::estFichePresse($portail))->toBeFalse();
});

// ── La réparation ──────────────────────────────────────────────────────────

test('la reparation remet la nature et la relation d AVANT, pose media-possible, journalise, et repasser n ecrit rien', function () {
    $fiche = pmiFiche($this->espace, '63.12Z', ['relation_type' => 'conference']);
    pmiMedia($this->espace, $fiche, 'portail_web');
    pmiBasculer($this->espace, $fiche);
    $f = DB::table('companies')->where('id', $fiche)->first();
    expect($f->entity_nature)->toBe('media')->and($f->relation_type)->toBe('presse_media');

    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true]);

    $f = DB::table('companies')->where('id', $fiche)->first();
    $journal = json_decode((string) $f->metadata, true)[CrmPresseReparerMediaIncertain::CLE_JOURNAL] ?? null;
    expect($r['code'])->toBe(0)
        ->and($f->entity_nature)->toBe('entreprise')
        ->and($f->relation_type)->toBe('conference')
        ->and($journal)->toMatchArray(['nature_trouvee' => 'media', 'relation_trouvee' => 'presse_media', 'nature_remise' => 'entreprise', 'relation_remise' => 'conference'])
        ->and(pmiSlugs($fiche))->toContain(MediaIncertain::ETIQUETTE)
        ->and(pmiSlugs($fiche))->not->toContain('nature-media')
        // La protection n'est levée que par une décision de Will : le tag reste, compté.
        ->and(pmiSlugs($fiche))->toContain(FichesProtegees::TAG_PRESSE)
        ->and(pmiCompteur($r['sortie'], 'fiches_reparees'))->toBe(1)
        ->and(pmiCompteur($r['sortie'], 'relations_remises'))->toBe(1)
        ->and(pmiCompteur($r['sortie'], 'natures_remises'))->toBe(1)
        ->and(pmiCompteur($r['sortie'], 'provenance_presse_gardee'))->toBe(1)
        ->and(DB::table('business_events')->where('action', CrmPresseReparerMediaIncertain::EVENEMENT)
            ->where('resource_id', (string) $fiche)->count())->toBe(1);

    // Idempotente : le second passage ne change rien.
    $r2 = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true]);
    expect(pmiCompteur($r2['sortie'], 'fiches_reparees'))->toBe(0)
        ->and(pmiCompteur($r2['sortie'], 'deja_reparees'))->toBe(1)
        ->and(DB::table('business_events')->where('action', CrmPresseReparerMediaIncertain::EVENEMENT)->count())->toBe(1);
});

test('une relation presse_media saisie a la main : la fiche reste presse, la reparation ne la touche pas', function () {
    $fiche = pmiFiche($this->espace, '63.12Z');
    pmiMedia($this->espace, $fiche, 'portail_web');
    pmiBasculer($this->espace, $fiche);
    // Will confirme ensuite « presse » à la main.
    DB::table('companies')->where('id', $fiche)->update(['relation_saisie_manuelle_at' => now()]);

    // Will a tranché : ce n'est plus un média incertain, c'est de la presse.
    expect(MediaIncertain::fiche($fiche))->toBeFalse()
        ->and(QualificationPresse::estFichePresse($fiche))->toBeTrue();

    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true]);

    $f = DB::table('companies')->where('id', $fiche)->first();
    expect($r['code'])->toBe(0)
        ->and($f->relation_type)->toBe('presse_media')
        ->and($f->entity_nature)->toBe('media')
        ->and(pmiCompteur($r['sortie'], 'fiches_lues'))->toBe(0)
        ->and(pmiCompteur($r['sortie'], 'relations_remises'))->toBe(0);
});

test('un portail_web naf-extract reste incertain meme si le NAF de la fiche a change depuis l extraction', function () {
    $portail = pmiFiche($this->espace, '62.01Z');
    pmiMedia($this->espace, $portail, 'portail_web');
    $temoin = pmiFiche($this->espace, '62.01Z');
    pmiMedia($this->espace, $temoin, 'presse_revue');

    expect(MediaIncertain::fiche($portail))->toBeTrue()
        ->and(MediaIncertain::fiche($temoin))->toBeFalse();

    $r = pmiCommande('crm:presse:harmoniser');

    expect($r['code'])->toBe(0)
        ->and(DB::table('companies')->where('id', $portail)->value('relation_type'))->toBe('prospect')
        ->and(DB::table('companies')->where('id', $temoin)->value('relation_type'))->toBe('presse_media');
});

test('des journalistes rattaches (contact presse) : la fiche n est pas incertaine, la reparation ne la touche pas', function () {
    $fiche = pmiFiche($this->espace, '58.14Z');
    $media = pmiMedia($this->espace, $fiche, 'presse_revue');
    DB::table('journalists')->insert([
        'workspace_id' => $this->espace, 'media_id' => $media, 'first_name' => 'Zoe', 'last_name' => 'ZZJOURNALISTE',
        'email' => 'zoe.zz@zz-revue.example.invalid', 'acces' => 'email_redaction', 'source' => 'ours',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(pmiCommande('crm:presse:harmoniser')['code'])->toBe(0);
    expect(DB::table('contacts')->where('company_id', $fiche)->where('external_ref', 'like', 'journaliste:%')->exists())->toBeTrue();
    // L'ancienne harmonisation ne regardait pas le NAF : le NAF réel est 63.12Z.
    DB::table('companies')->where('id', $fiche)->update(['naf' => '63.12Z']);
    expect(MediaIncertain::fiche($fiche))->toBeFalse();

    // Le journaliste retiré de la ligne source : le CONTACT presse suffit encore.
    DB::table('journalists')->where('media_id', $media)->update(['deleted_at' => now()]);
    expect(MediaIncertain::fiche($fiche))->toBeFalse();

    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true]);

    $f = DB::table('companies')->where('id', $fiche)->first();
    expect($r['code'])->toBe(0)
        ->and($f->relation_type)->toBe('presse_media')
        ->and($f->entity_nature)->toBe('media')
        ->and(pmiCompteur($r['sortie'], 'fiches_lues'))->toBe(0);
});

test('la reparation ne touche jamais une fiche portee aussi par une vraie source presse', function () {
    $fiche = pmiFiche($this->espace, '63.12Z');
    pmiMedia($this->espace, $fiche, 'portail_web');
    pmiMedia($this->espace, $fiche, 'presse_quotidien', 'cppap');
    pmiBasculer($this->espace, $fiche);

    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true]);

    $f = DB::table('companies')->where('id', $fiche)->first();
    expect($r['code'])->toBe(0)
        ->and($f->entity_nature)->toBe('media')
        ->and($f->relation_type)->toBe('presse_media')
        ->and(pmiCompteur($r['sortie'], 'fiches_lues'))->toBe(0);
});

test('etat d avant introuvable : prospect et entreprise seulement sans fusion ni saisie a la main', function () {
    $fiche = pmiFiche($this->espace, '63.12Z', ['entity_nature' => 'media', 'relation_type' => 'presse_media']);
    pmiMedia($this->espace, $fiche, 'portail_web');
    pmiBasculer($this->espace, $fiche); // déjà presse : qualifier n'écrit aucune métadonnée
    expect(json_decode((string) DB::table('companies')->where('id', $fiche)->value('metadata'), true) ?? [])
        ->not->toHaveKey(QualificationPresse::CLE_AVANT);

    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true]);

    $f = DB::table('companies')->where('id', $fiche)->first();
    expect($f->relation_type)->toBe('prospect')
        ->and($f->entity_nature)->toBe('entreprise')
        ->and(pmiCompteur($r['sortie'], 'relations_prospect_par_defaut'))->toBe(1)
        ->and(pmiCompteur($r['sortie'], 'natures_entreprise_par_defaut'))->toBe(1);
});

test('sans --appliquer (ou avec --dry-run) la reparation n ecrit RIEN, et compte comme le reel', function () {
    $fiche = pmiFiche($this->espace, '63.12Z');
    pmiMedia($this->espace, $fiche, 'portail_web');
    pmiBasculer($this->espace, $fiche);
    $avant = (array) DB::table('companies')->where('id', $fiche)->first();
    $tags = pmiSlugs($fiche);
    $evenements = DB::table('business_events')->count();

    foreach ([[], ['--dry-run' => true]] as $options) {
        $r = pmiCommande('crm:presse:reparer-media-incertain', $options);

        expect($r['code'])->toBe(0)
            ->and(pmiCompteur($r['sortie'], 'fiches_reparees'))->toBe(1)
            ->and((array) DB::table('companies')->where('id', $fiche)->first())->toEqual($avant)
            ->and(pmiSlugs($fiche))->toBe($tags)
            ->and(DB::table('business_events')->count())->toBe($evenements);
    }

    expect(pmiCommande('crm:presse:reparer-media-incertain', ['--dry-run' => true, '--appliquer' => true])['code'])->toBe(1);
});

// ── La levée de la provenance (option explicite) ───────────────────────────

/**
 * Une fiche basculée par l'HARMONISATION RÉELLE (porte commune, tag de
 * provenance, `scraper_runs`), comme au passage du 30/09 : ancienne règle
 * reproduite en harmonisant une fiche 58.14Z, puis le NAF réel (63.12Z)
 * remis — l'harmonisation d'alors ne regardait pas le NAF.
 */
function pmiBasculeeParHarmonisation(string $espace): int
{
    $fiche = pmiFiche($espace, '58.14Z');
    pmiMedia($espace, $fiche, 'presse_revue');
    expect(pmiCommande('crm:presse:harmoniser')['code'])->toBe(0);
    DB::table('companies')->where('id', $fiche)->update(['naf' => '63.12Z']);
    expect(DB::table('companies')->where('id', $fiche)->value('relation_type'))->toBe('presse_media')
        ->and(pmiSlugs($fiche))->toContain(FichesProtegees::TAG_PRESSE)
        ->and(DB::table('scraper_runs')->where('company_id', $fiche)->where('source', QualificationPresse::SOURCE)->exists())->toBeTrue()
        ->and(MediaIncertain::fiche($fiche))->toBeTrue();

    return $fiche;
}

test('levee : le tag de provenance pose par l harmonisation est retire, journalise, et seulement lui', function () {
    $fiche = pmiBasculeeParHarmonisation($this->espace);
    $autres = array_values(array_diff(pmiSlugs($fiche), [FichesProtegees::TAG_PRESSE, 'nature-media']));

    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true, '--lever-provenance-posee-par-harmonisation' => true, '--force' => true]);

    $journal = json_decode((string) DB::table('companies')->where('id', $fiche)->value('metadata'), true)[CrmPresseReparerMediaIncertain::CLE_JOURNAL] ?? [];
    expect($r['code'])->toBe(0)
        ->and(pmiSlugs($fiche))->not->toContain(FichesProtegees::TAG_PRESSE)
        ->and(pmiSlugs($fiche))->toContain(MediaIncertain::ETIQUETTE)
        ->and(array_values(array_intersect($autres, pmiSlugs($fiche))))->toBe($autres)
        ->and(FichesProtegees::estProtegee($fiche))->toBeFalse()
        ->and($journal)->toHaveKey('provenance_levee')
        ->and(pmiCompteur($r['sortie'], 'provenance_presse_levee'))->toBe(1)
        ->and(pmiCompteur($r['sortie'], 'provenance_presse_gardee'))->toBe(0)
        ->and(DB::table('business_events')->where('action', CrmPresseReparerMediaIncertain::EVENEMENT_LEVEE)
            ->where('resource_id', (string) $fiche)->count())->toBe(1)
        // Rien d'autre n'est supprimé.
        ->and(DB::table('media')->where('company_id', $fiche)->count())->toBe(1)
        ->and(DB::table('companies')->where('id', $fiche)->whereNull('deleted_at')->exists())->toBeTrue();
});

test('une VRAIE liste presse importee sur la ligne naf-extract : ni incertaine, ni reparee, ni levee', function () {
    // Harmonisée (58.14Z), puis une liste presse RÉELLE la rejoint par son SIREN
    // et complète SUR PLACE sa ligne `naf-extract` (la source ne change pas).
    $fiche = pmiFiche($this->espace, '58.14Z');
    $media = pmiMedia($this->espace, $fiche, 'presse_revue');
    expect(pmiCommande('crm:presse:harmoniser')['code'])->toBe(0);
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-pmi-');
    file_put_contents($fichier, json_encode([
        'siren' => (string) DB::table('companies')->where('id', $fiche)->value('siren'),
        'nom' => 'ZZ media fictif', 'type' => 'presse_revue', 'departement' => '69',
        'site' => 'https://zz-revue.example.invalid', 'journaliste' => null,
    ], JSON_UNESCAPED_UNICODE) . "\n");
    try {
        $import = pmiCommande('crm:presse:importer', ['file' => $fichier]);
    } finally {
        @unlink($fichier);
    }
    expect($import['code'])->toBe(0)
        ->and(DB::table('media')->where('id', $media)->value('source'))->toBe('naf-extract')
        ->and(DB::table('scraper_runs')->where('company_id', $fiche)->where('dedup_key', 'like', '%:liste:%')->exists())->toBeTrue();

    // Le NAF réel est 63.12Z (l'ancienne harmonisation ne le regardait pas).
    DB::table('companies')->where('id', $fiche)->update(['naf' => '63.12Z']);
    expect(MediaIncertain::fiche($fiche))->toBeFalse();

    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true, '--lever-provenance-posee-par-harmonisation' => true, '--force' => true]);

    $f = DB::table('companies')->where('id', $fiche)->first();
    expect($r['code'])->toBe(0)
        ->and($f->relation_type)->toBe('presse_media')
        ->and($f->entity_nature)->toBe('media')
        ->and(pmiSlugs($fiche))->toContain(FichesProtegees::TAG_PRESSE)
        ->and(pmiCompteur($r['sortie'], 'fiches_lues'))->toBe(0)
        ->and(pmiCompteur($r['sortie'], 'provenance_presse_levee'))->toBe(0)
        ->and(DB::table('business_events')->where('action', CrmPresseReparerMediaIncertain::EVENEMENT)->count())->toBe(0)
        ->and(DB::table('business_events')->where('action', CrmPresseReparerMediaIncertain::EVENEMENT_LEVEE)->count())->toBe(0);
});

test('le plafond de la levee se mesure sur l espace traite, pas sur les liens de tous les espaces', function () {
    $fiche = pmiFiche($this->espace, '63.12Z');
    pmiMedia($this->espace, $fiche, 'portail_web');
    pmiBasculer($this->espace, $fiche);

    // Un autre espace, riche en liens : sur TOUTE la table, la levée pèserait peu.
    $autre = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $autre, 'slug' => 'zz-autre-espace', 'name' => 'ZZ autre', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $tag = DB::table('tags')->insertGetId([
        'workspace_id' => $autre, 'slug' => 'zz-autre', 'name' => 'zz-autre', 'category' => 'custom',
        'kind' => 'manual', 'rules' => '{}', 'is_locked' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    for ($i = 0; $i < 12; $i++) {
        $c = pmiFiche($autre, '62.01Z');
        DB::table('company_tag')->insert(['company_id' => $c, 'tag_id' => $tag, 'workspace_id' => $autre, 'assigned_at' => now(), 'assigned_by' => 'user']);
    }
    expect(DB::table('company_tag')->where('workspace_id', $this->espace)->count())->toBeLessThan(4);

    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true, '--lever-provenance-posee-par-harmonisation' => true]);

    expect($r['code'])->toBe(1)
        ->and($r['sortie'])->toContain('au-delà du plafond')
        ->and(DB::table('companies')->where('id', $fiche)->value('relation_type'))->toBe('presse_media');
});

test('levee REFUSEE quand le tag n a pas ete pose par l harmonisation (aucun passage de la porte commune)', function () {
    $fiche = pmiFiche($this->espace, '63.12Z');
    pmiMedia($this->espace, $fiche, 'portail_web');
    pmiBasculer($this->espace, $fiche);

    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true, '--lever-provenance-posee-par-harmonisation' => true, '--force' => true]);

    expect(pmiSlugs($fiche))->toContain(FichesProtegees::TAG_PRESSE)
        ->and(pmiCompteur($r['sortie'], 'provenance_presse_gardee'))->toBe(1)
        ->and(pmiCompteur($r['sortie'], 'provenance_presse_levee'))->toBe(0);
});

test('levee : rien sans l option, et rien a blanc meme avec l option', function () {
    $fiche = pmiBasculeeParHarmonisation($this->espace);

    // À blanc AVEC l'option : compté comme le réel, rien retiré.
    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--dry-run' => true, '--lever-provenance-posee-par-harmonisation' => true]);
    expect($r['code'])->toBe(0)
        ->and(pmiCompteur($r['sortie'], 'provenance_presse_levee'))->toBe(1)
        ->and(pmiSlugs($fiche))->toContain(FichesProtegees::TAG_PRESSE)
        ->and(DB::table('business_events')->where('action', CrmPresseReparerMediaIncertain::EVENEMENT_LEVEE)->count())->toBe(0);

    // Réel SANS l'option : la fiche est réparée, le tag reste.
    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true]);
    expect($r['code'])->toBe(0)
        ->and(DB::table('companies')->where('id', $fiche)->value('relation_type'))->toBe('prospect')
        ->and(pmiSlugs($fiche))->toContain(FichesProtegees::TAG_PRESSE)
        ->and(pmiCompteur($r['sortie'], 'provenance_presse_gardee'))->toBe(1)
        ->and(pmiCompteur($r['sortie'], 'provenance_presse_levee'))->toBe(0);

    // Et la levée reste possible ensuite, sur la fiche déjà réparée.
    $r = pmiCommande('crm:presse:reparer-media-incertain', ['--appliquer' => true, '--lever-provenance-posee-par-harmonisation' => true, '--force' => true]);
    expect(pmiCompteur($r['sortie'], 'provenance_presse_levee'))->toBe(1)
        ->and(pmiSlugs($fiche))->not->toContain(FichesProtegees::TAG_PRESSE);
});

<?php

/**
 * HARMONISATION DE LA PRESSE — RELECTURES DE CONFIRMATION (2026-09-30).
 *
 * Sécurité (veto RGPD) : un journaliste ne part JAMAIS dans une campagne d'un
 * autre segment, même sur une fiche qui porte ce segment ; une suppression
 * TECHNIQUE d'un contact ne détruit rien, seul un effacement se reporte sur
 * le journaliste ; une opposition déjà inscrite vaut pour le journaliste qui
 * entre ; un homonyme qui porte une autre adresse n'est jamais rattaché.
 *
 * Exactitude : les titres portés par une fiche provisoire `media:<id>` restent
 * cherchés (site) et rattachés à leur éditeur par SIREN (fusion journalisée,
 * rien supprimé) ; une émission dont la chaîne n'a pas de fiche est rejetée ;
 * `surLaChaine` est lu en base ; l'import rejoint un titre `media:<id>` même
 * quand la ligne porte un SIREN ; un fournisseur n'est jamais remplacé.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Campagnes\GardePresse;
use App\Crm\Campagnes\Segments;
use App\Crm\FichesProtegees;
use App\Crm\Presse\LienJournalisteContact;
use App\Crm\Presse\QualificationPresse;
use App\Models\Contact;
use App\Services\Domain\DomainFinderService;
use App\Support\EligibiliteCampagne;
use App\Support\ListeSuppression;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
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
function prlFiche(string $espace, array $valeurs = []): int
{
    return (int) DB::table('companies')->insertGetId($valeurs + [
        'workspace_id' => $espace, 'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ FICHE', 'entity_nature' => 'entreprise', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @param  array<string, mixed>  $valeurs */
function prlMedia(string $espace, array $valeurs = []): int
{
    return (int) DB::table('media')->insertGetId($valeurs + [
        'workspace_id' => $espace, 'name' => 'ZZ media', 'media_type' => 'presse_quotidien', 'media_family' => 'editorial',
        'source' => 'cppap', 'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function prlTag(string $espace, int $fiche, string $slug): void
{
    $tag = DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->value('id')
        ?? DB::table('tags')->insertGetId(['workspace_id' => $espace, 'slug' => $slug, 'name' => $slug, 'category' => 'intent',
            'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('company_tag')->insertOrIgnore(['company_id' => $fiche, 'tag_id' => $tag, 'workspace_id' => $espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
}

/** @return array<string, int> */
function prlCompteurs(string $sortie): array
{
    preg_match_all('/\|\s*([a-z_]+)\s*\|\s*(\d+)\s*\|/', $sortie, $m, PREG_SET_ORDER);
    $c = [];
    foreach ($m as $l) {
        $c[$l[1]] = (int) $l[2];
    }

    return $c;
}

// ── Sécurité : aucun journaliste dans une campagne d'un autre segment ───────

test('BLOQUANT — une fiche organisateur ET presse : son journaliste email_redaction n est PAS dans les destinataires organisateurs ; le temoin, si', function () {
    $fiche = prlFiche($this->espace, ['denomination' => 'ZZ GROUPE DE PRESSE ORGANISATEUR']);
    prlTag($this->espace, $fiche, FichesProtegees::TAG_ORGANISATEURS);
    prlTag($this->espace, $fiche, FichesProtegees::TAG_PRESSE);
    $media = prlMedia($this->espace, ['company_id' => $fiche]);
    $j = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $this->espace, 'media_id' => $media, 'first_name' => 'Zoe', 'last_name' => 'ZZJOURNALISTE',
        'email' => 'zoe.journaliste@zz-groupe.example.invalid', 'acces' => 'email_redaction',
        'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $r = prlCompteurs((string) (Artisan::call('crm:presse:harmoniser') === 0 ? Artisan::output() : ''));
    $contact = (int) DB::table('journalists')->where('id', $j)->value('contact_id');
    expect($contact)->toBeGreaterThan(0)
        ->and(DB::table('contacts')->where('id', $contact)->value('email'))->toBe('zoe.journaliste@zz-groupe.example.invalid')
        ->and($r['journalistes_sur_fiche_d_un_segment_ouvert'] ?? 0)->toBe(1);
    // Le témoin : un organisateur ordinaire de la même fiche.
    DB::table('contacts')->insert(['workspace_id' => $this->espace, 'company_id' => $fiche, 'first_name' => 'Tim', 'last_name' => 'ZZORGANISATEUR',
        'email' => 'tim.orga@zz-groupe.example.invalid', 'sources' => json_encode(['evenements-pro']), 'metadata' => '{}',
        'created_at' => now(), 'updated_at' => now()]);

    ResolveurDnsSimule::toutVerifier();
    $sortie = (string) tempnam(sys_get_temp_dir(), 'zz-prl-dest-');
    Artisan::call('crm:campagne:destinataires', ['segment' => Segments::ORGANISATEURS_EVENEMENTS, 'sortie' => $sortie]);
    $emails = array_map(static fn (string $l): string => (string) (json_decode($l, true)['email'] ?? ''), file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    @unlink($sortie);

    expect($emails)->toContain('tim.orga@zz-groupe.example.invalid')
        ->not->toContain('zoe.journaliste@zz-groupe.example.invalid');

    // La même règle à la porte commune des campagnes (`EligibiliteCampagne`).
    $eligibles = EligibiliteCampagne::appliquerContacts(Contact::query()->where('company_id', $fiche))->pluck('email')->all();
    expect($eligibles)->toContain('tim.orga@zz-groupe.example.invalid')->not->toContain('zoe.journaliste@zz-groupe.example.invalid')
        ->and(GardePresse::conditionContactsSql('c', [...Segments::OUVERTS, Segments::PRESSE]))->toBe('TRUE');
});

test('R1 — une suppression TECHNIQUE d un contact ne touche pas le journaliste ; un EFFACEMENT, si', function () {
    $fiche = prlFiche($this->espace);
    $media = prlMedia($this->espace, ['company_id' => $fiche]);
    $jA = (int) DB::table('journalists')->insertGetId(['workspace_id' => $this->espace, 'media_id' => $media, 'first_name' => 'Ana', 'last_name' => 'ZZTECHNIQUE',
        'email' => 'ana@zz-r1.example.invalid', 'acces' => 'email_redaction', 'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now()]);
    $jB = (int) DB::table('journalists')->insertGetId(['workspace_id' => $this->espace, 'media_id' => $media, 'first_name' => 'Bob', 'last_name' => 'ZZEFFACE',
        'email' => 'bob@zz-r1.example.invalid', 'acces' => 'email_redaction', 'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now()]);
    Artisan::call('crm:presse:harmoniser');

    DB::table('contacts')->where('external_ref', 'journaliste:' . $jA)->delete();
    DB::transaction(function () use ($jB): void {
        LienJournalisteContact::marquerEffacement();
        DB::table('contacts')->where('external_ref', 'journaliste:' . $jB)->delete();
    });

    $a = DB::table('journalists')->where('id', $jA)->first();
    $b = DB::table('journalists')->where('id', $jB)->first();
    expect($a->email)->toBe('ana@zz-r1.example.invalid')
        ->and($a->opt_out)->toBeFalse()
        ->and($a->deleted_at)->toBeNull()
        ->and($b->email)->toBeNull()
        ->and($b->opt_out)->toBeTrue()
        ->and($b->deleted_at)->not->toBeNull();
});

test('R2 — une opposition DEJA inscrite vaut pour le journaliste qui entre (adresse, telephone sous une autre forme) ; le temoin non', function () {
    DB::table('opt_out')->insert(['email' => null, 'email_hash' => ListeSuppression::empreinte('Deja.Oppose@zz-r2.example.invalid'),
        'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    DB::table('opt_out')->insert(['email' => null, 'email_hash' => null, 'phone' => '0612345679', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    $ligne = fn (array $v): int => (int) DB::table('journalists')->insertGetId($v + ['workspace_id' => $this->espace, 'source' => 'wikidata',
        'created_at' => now(), 'updated_at' => now()]);

    $parAdresse = $ligne(['first_name' => 'Ana', 'last_name' => 'ZZADRESSE', 'email' => 'deja.oppose@zz-r2.example.invalid']);
    $parTel = $ligne(['first_name' => 'Tel', 'last_name' => 'ZZTEL', 'phone' => '+33 6 12 34 56 79']);
    $temoin = $ligne(['first_name' => 'Tim', 'last_name' => 'ZZTEMOIN', 'email' => 'libre@zz-r2.example.invalid', 'phone' => '06 99 99 99 98']);

    expect(DB::table('journalists')->where('id', $parAdresse)->value('opt_out'))->toBeTrue()
        ->and(DB::table('journalists')->where('id', $parTel)->value('opt_out'))->toBeTrue()
        ->and(DB::table('journalists')->where('id', $temoin)->value('opt_out'))->toBeFalse();
});

test('R4 — un homonyme de la fiche qui porte une AUTRE adresse n est jamais rattache au journaliste (harmonisation et import)', function () {
    $fiche = prlFiche($this->espace, ['siren' => '900000331']);
    $autre = (int) DB::table('contacts')->insertGetId(['workspace_id' => $this->espace, 'company_id' => $fiche, 'first_name' => 'Zoe', 'last_name' => 'ZZHOMO',
        'email' => 'zoe.dirigeante@zz-r4.example.invalid', 'role' => 'Gérante', 'sources' => json_encode(['insee']), 'metadata' => '{}',
        'created_at' => now(), 'updated_at' => now()]);
    $media = prlMedia($this->espace, ['company_id' => $fiche, 'name' => 'ZZ Titre R4']);
    $j = (int) DB::table('journalists')->insertGetId(['workspace_id' => $this->espace, 'media_id' => $media, 'first_name' => 'Zoe', 'last_name' => 'ZZHOMO',
        'role' => 'Journaliste', 'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now()]);

    Artisan::call('crm:presse:harmoniser');
    $r = prlCompteurs(Artisan::output());

    $c = DB::table('contacts')->where('id', $autre)->first();
    expect(DB::table('journalists')->where('id', $j)->value('contact_id'))->toBeNull()
        ->and($c->role)->toBe('Gérante')
        ->and($c->external_ref)->toBeNull()
        ->and(json_decode((string) $c->sources, true))->not->toContain(QualificationPresse::SOURCE)
        ->and($r['journalistes_homonymes_autre_adresse'] ?? 0)->toBe(1);

    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-prl-');
    file_put_contents($fichier, json_encode(['siren' => '900000331', 'nom' => 'ZZ Titre R4', 'type' => 'presse_quotidien',
        'journaliste' => ['prenom' => 'Zoe', 'nom' => 'ZZHOMO', 'fonction' => 'Pigiste']]) . "\n");
    Artisan::call('crm:presse:importer', ['file' => $fichier]);
    @unlink($fichier);
    expect(DB::table('contacts')->where('id', $autre)->value('role'))->toBe('Gérante')
        ->and(Artisan::output())->toMatch('/journalistes_homonymes_autre_adresse\s*\|\s*1/');
});

// ── Exactitude ─────────────────────────────────────────────────────────────

test('N1 — media:find-websites cherche AUSSI le site des titres portes par une fiche provisoire media:<id> ; jamais celui d un titre rattache a son editeur', function () {
    $provisoire = prlMedia($this->espace, ['name' => 'ZZ Titre provisoire', 'website_status' => 'pending']);
    Artisan::call('crm:presse:harmoniser');
    expect(DB::table('companies')->where('foreign_id', 'media:' . $provisoire)->exists())->toBeTrue();
    $editeur = prlMedia($this->espace, ['name' => 'ZZ Titre editeur', 'website_status' => 'pending', 'company_id' => prlFiche($this->espace)]);
    $autonome = prlMedia($this->espace, ['name' => 'ZZ Titre autonome', 'website_status' => 'pending']);

    $this->mock(DomainFinderService::class)->shouldReceive('guessDomainsBatch')->andReturnUsing(
        static fn (iterable $medias): array => collect($medias)->mapWithKeys(static fn ($m): array => [$m->id => 'https://zz-' . $m->id . '.example.invalid'])->all(),
    );
    Artisan::call('media:find-websites');

    expect(DB::table('media')->where('id', $provisoire)->value('website'))->toBe('https://zz-' . $provisoire . '.example.invalid')
        ->and(DB::table('media')->where('id', $autonome)->value('website'))->toBe('https://zz-' . $autonome . '.example.invalid')
        ->and(DB::table('media')->where('id', $editeur)->value('website'))->toBeNull();
});

test('N1 — media:link-to-companies rattache un titre de fiche provisoire a la fiche SIREN de son editeur : fusion journalisee, rien supprime ; le temoin reste', function () {
    $titre = prlMedia($this->espace, ['name' => 'ZZ Titre a rattacher', 'siren' => '900000441']);
    $seul = prlMedia($this->espace, ['name' => 'ZZ Titre sans editeur', 'siren' => '900000442']);
    DB::table('journalists')->insert(['workspace_id' => $this->espace, 'media_id' => $titre, 'first_name' => 'Zoe', 'last_name' => 'ZZFUSION',
        'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now()]);
    Artisan::call('crm:presse:harmoniser');
    $provisoire = (int) DB::table('media')->where('id', $titre)->value('company_id');
    $provisoireSeul = (int) DB::table('media')->where('id', $seul)->value('company_id');
    $editeur = prlFiche($this->espace, ['siren' => '900000441', 'denomination' => 'ZZ EDITIONS DU TITRE']);
    $fiches = DB::table('companies')->count();

    Artisan::call('media:link-to-companies');

    expect((int) DB::table('media')->where('id', $titre)->value('company_id'))->toBe($editeur)
        ->and(DB::table('companies')->count())->toBe($fiches)
        ->and(DB::table('companies')->where('id', $provisoire)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('fusions_fiches')->where('garde_id', $editeur)->where('absorbee_id', $provisoire)->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('last_name', 'ZZFUSION')->value('company_id'))->toBe($editeur)
        ->and(FichesProtegees::estProtegee($editeur))->toBeTrue()
        // Le témoin : aucun éditeur ne porte son SIREN.
        ->and((int) DB::table('media')->where('id', $seul)->value('company_id'))->toBe($provisoireSeul)
        ->and(DB::table('companies')->where('id', $provisoireSeul)->value('deleted_at'))->toBeNull();
});

test('N3 — une emission dont la chaine n a pas de fiche est rejetee et comptee ; une emission deja portee par sa chaine n y verse jamais ses coordonnees, meme chaine a la corbeille', function () {
    $corbeille = prlFiche($this->espace, ['deleted_at' => now()]);
    $chaineKo = prlMedia($this->espace, ['name' => 'ZZ TV corbeille', 'media_type' => 'tv', 'company_id' => $corbeille]);
    $emissionKo = prlMedia($this->espace, ['name' => 'ZZ Emission orpheline', 'media_type' => 'tv_emission', 'parent_media_id' => $chaineKo,
        'email' => 'emission@zz-n3.example.invalid']);
    $chaine = prlMedia($this->espace, ['name' => 'ZZ TV', 'media_type' => 'tv']);
    $emission = prlMedia($this->espace, ['name' => 'ZZ Emission', 'media_type' => 'tv_emission', 'parent_media_id' => $chaine,
        'website' => 'https://zz-emission-n3.example.invalid', 'email' => 'redac@zz-emission-n3.example.invalid']);
    $fiches = DB::table('companies')->count();

    Artisan::call('crm:presse:harmoniser');
    $sortie = Artisan::output();
    expect($sortie)->toContain('chaine_a_la_corbeille : 1')
        ->and(DB::table('media')->where('id', $emissionKo)->value('company_id'))->toBeNull()
        ->and(DB::table('companies')->where('foreign_id', 'media:' . $emissionKo)->exists())->toBeFalse()
        ->and(DB::table('companies')->count())->toBe($fiches + 1);

    // La chaîne va à la corbeille (média) : son émission, déjà portée par sa
    // fiche, devient une tête — et ne verse toujours rien à cette fiche.
    $ficheChaine = (int) DB::table('media')->where('id', $chaine)->value('company_id');
    DB::table('media')->where('id', $chaine)->update(['deleted_at' => now()]);
    Artisan::call('crm:presse:harmoniser');

    $f = DB::table('companies')->where('id', $ficheChaine)->first();
    expect($f->website)->toBeNull()
        ->and($f->email_generic)->toBeNull();
});

test('N4 — l import rejoint un titre de fiche provisoire meme quand la ligne porte un SIREN, pose ce SIREN sur le titre ; un SIREN contradictoire est rejete', function () {
    $titre = prlMedia($this->espace, ['name' => 'ZZ Mensuel Fictif', 'media_type' => 'presse_mensuel', 'department_code' => '38']);
    Artisan::call('crm:presse:harmoniser');
    $provisoire = (int) DB::table('media')->where('id', $titre)->value('company_id');
    $editeurAutre = prlFiche($this->espace, ['siren' => '900000552']);
    prlMedia($this->espace, ['name' => 'ZZ Hebdo Autre', 'media_type' => 'presse_hebdo', 'company_id' => $editeurAutre]);
    $fiches = DB::table('companies')->count();

    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-prl-n4-');
    file_put_contents($fichier, implode("\n", array_map('json_encode', [
        ['siren' => '900000551', 'nom' => 'ZZ mensuel fictif', 'type' => 'presse_mensuel', 'departement' => '38'],
        ['siren' => '900000553', 'nom' => 'ZZ Hebdo Autre', 'type' => 'presse_hebdo'],
    ])) . "\n");
    Artisan::call('crm:presse:importer', ['file' => $fichier]);
    $sortie = Artisan::output();
    @unlink($fichier);

    expect(DB::table('companies')->count())->toBe($fiches)
        ->and(DB::table('companies')->where('siren', '900000551')->exists())->toBeFalse()
        ->and(DB::table('media')->where('id', $titre)->value('siren'))->toBe('900000551')
        ->and((int) DB::table('media')->where('id', $titre)->value('company_id'))->toBe($provisoire)
        ->and($sortie)->toContain('rapprochement_siren_contradictoire : 1');
});

test('N5 — un fournisseur n est JAMAIS remplace par presse_media, meme sans la marque de saisie manuelle', function () {
    $fiche = prlFiche($this->espace, ['relation_type' => 'fournisseur']);
    prlMedia($this->espace, ['company_id' => $fiche]);

    Artisan::call('crm:presse:harmoniser');

    expect(DB::table('companies')->where('id', $fiche)->value('relation_type'))->toBe('fournisseur')
        ->and(QualificationPresse::relationRemplacable('fournisseur', null))->toBeFalse()
        ->and(QualificationPresse::relationRemplacable('prospect', null))->toBeTrue();
});

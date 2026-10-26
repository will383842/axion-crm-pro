<?php

/**
 * `crm:pharmacies:ecarter-fermees` — ecarte sans JAMAIS supprimer.
 *
 * Gardes : dry-run n'ecrit rien ; apply pose deleted_at + motif sans effacer ;
 * chaque source de protection → a_verifier_fermee sans corbeille ; inconnue de
 * FINESS → non_officine ; FINESS inactif + INSEE ouvert → etiquette seule ;
 * fourchettes et CSV suspect refuses ; journal ; restauration ; verrou.
 *
 * Fixtures FICTIVES (depot public).
 */

use App\Crm\FichesProtegees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pefEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => 'pef-' . substr($id, 0, 8), 'name' => 'PEF',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function pefFiche(string $ws, string $siren, array $attrs = []): int
{
    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $ws, 'siren' => $siren, 'denomination' => 'ZZ FICTIVE ' . $siren,
        'naf' => '47.73Z', 'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

function pefCsv(array $lignes, int $actifsFiller = 20000, int $inactifsFiller = 0): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'pef');
    $contenu = "finess_et,siret,categorie,etat\n";
    foreach ($lignes as [$siren, $cat, $etat]) {
        $contenu .= "F{$siren},{$siren}00011,{$cat},{$etat}\n";
    }
    // Remplissage fictif : SIREN 3xxxxxxxx, sans lien avec les fiches du test.
    for ($i = 0; $i < $actifsFiller + $inactifsFiller; $i++) {
        $siren = 300000000 + $i;
        $etat = $i < $actifsFiller ? 'A' : 'I';
        $contenu .= "F{$siren},{$siren}00011,620,{$etat}\n";
    }
    file_put_contents($chemin, $contenu);

    return $chemin;
}

function pefProteger(string $source, string $ws, int $id): void
{
    $maintenant = ['created_at' => now(), 'updated_at' => now()];
    switch ($source) {
        case 'contact':
            DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $id, 'last_name' => 'Fictif'] + $maintenant);
            break;
        case 'deal':
            $pipeline = (int) DB::table('crm_pipelines')->insertGetId(['workspace_id' => $ws, 'name' => 'ZZ Pipeline', 'slug' => 'zz-pipeline']);
            $etape = (int) DB::table('pipeline_stages')->insertGetId(['workspace_id' => $ws, 'pipeline_id' => $pipeline, 'slug' => 'zz-etape', 'name' => 'ZZ Etape']);
            DB::table('deals')->insert(['workspace_id' => $ws, 'company_id' => $id, 'pipeline_id' => $pipeline, 'stage_id' => $etape]);
            break;
        case 'audience':
            $audience = (int) DB::table('email_audiences')->insertGetId(['workspace_id' => $ws, 'name' => 'ZZ Audience']);
            DB::table('audience_members')->insert(['workspace_id' => $ws, 'audience_id' => $audience, 'company_id' => $id]);
            break;
        case 'lifecycle_stage':
            DB::table('companies')->where('id', $id)->update(['lifecycle_stage' => 'qualifie']);
            break;
        case 'relation_type':
            DB::table('companies')->where('id', $id)->update(['relation_type' => 'partenaire']);
            break;
        case 'etiquette_protegee':
            $tag = (int) DB::table('tags')->insertGetId([
                'workspace_id' => $ws, 'slug' => FichesProtegees::TAGS[0], 'name' => 'Protegee', 'category' => 'intent',
                'kind' => 'auto', 'rules' => '{}', 'is_locked' => true] + $maintenant);
            DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $tag, 'workspace_id' => $ws, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
            break;
        case 'etiquette_manuelle':
            // Slug hors liste protegee : seule la main d'une personne protege.
            $tag = (int) DB::table('tags')->insertGetId([
                'workspace_id' => $ws, 'slug' => 'zz-etiquette-manuelle', 'name' => 'Manuelle', 'category' => 'intent',
                'kind' => 'manual', 'rules' => '{}', 'is_locked' => false] + $maintenant);
            DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $tag, 'workspace_id' => $ws, 'assigned_at' => now(), 'assigned_by' => 'user']);
            break;
        case 'activite_sans_type':
            DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'note', 'kind' => null, 'subject_type' => 'company', 'subject_id' => $id, 'created_at' => now()]);
            break;
        case 'console_client_ref':
            DB::table('companies')->where('id', $id)->update(['console_client_ref' => 'site:client:00000000-0000-0000-0000-000000000001']);
            break;
        case 'activite_rendez_vous':
            DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'note', 'kind' => 'calendly_booked', 'subject_type' => 'company', 'subject_id' => $id, 'created_at' => now()]);
            break;
        case 'personne':
            DB::table('personnes')->insert(['workspace_id' => $ws, 'company_id' => $id, 'person_key' => hash('sha256', 'zz-personne'), 'premiere_source' => 'newsletter', 'premiere_source_at' => now(), 'legal_basis' => 'consent']);
            break;
        case 'praticien':
            DB::table('health_practitioners')->insert(['workspace_id' => $ws, 'company_id' => $id, 'nom' => 'ZZ Praticien', 'rpps' => '90000000001']);
            break;
        case 'media':
            DB::table('media')->insert(['workspace_id' => $ws, 'company_id' => $id, 'name' => 'ZZ titre', 'media_type' => 'presse_quotidien',
                'media_family' => 'editorial', 'source' => 'cppap', 'enrich_status' => 'pending'] + $maintenant);
            break;
        case 'tache':
            DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'task', 'kind' => 'task', 'subject_type' => 'company', 'subject_id' => $id, 'created_at' => now()]);
            break;
        case 'note_saisie':
            $user = (string) Str::uuid();
            DB::table('users')->insert(['id' => $user, 'email' => $user . '@zz.example.invalid', 'name' => 'ZZ', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'note', 'kind' => 'reclassified', 'user_id' => $user, 'subject_type' => 'company', 'subject_id' => $id, 'created_at' => now()]);
            break;
        case 'echange_call':
            DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'note', 'kind' => 'call', 'subject_type' => 'company', 'subject_id' => $id, 'created_at' => now()]);
            break;
        case 'echange_linkedin_message':
            DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'note', 'kind' => 'linkedin_message', 'subject_type' => 'company', 'subject_id' => $id, 'created_at' => now()]);
            break;
        case 'journaliste':
            $titre = (int) DB::table('media')->insertGetId(['workspace_id' => $ws, 'name' => 'ZZ Media J', 'media_type' => 'blog']);
            DB::table('journalists')->insert(['workspace_id' => $ws, 'media_id' => $titre, 'company_id' => $id, 'last_name' => 'ZZ Journaliste']);
            break;
        case 'organisateur':
            $evt = (int) DB::table('events')->insertGetId(['workspace_id' => $ws, 'external_ref' => 'zz-evt-' . $id, 'nom' => 'ZZ evt', 'type' => 'salon'] + $maintenant);
            DB::table('event_organizers')->insert(['event_id' => $evt, 'company_id' => $id, 'workspace_id' => $ws, 'created_at' => now()]);
            break;
        case 'federation':
            DB::table('federations')->insert(['company_id' => $id, 'workspace_id' => $ws, 'famille' => 'ordre', 'niveau' => 'national', 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact']);
            break;
        case 'federation_parent':
            $antenne = pefFiche($ws, (string) (800000000 + $id));
            DB::table('federations')->insert(['company_id' => $antenne, 'workspace_id' => $ws, 'famille' => 'ordre', 'niveau' => 'departemental', 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact', 'parent_company_id' => $id]);
            break;
        case 'opco':
            DB::table('companies_opco')->insert(['workspace_id' => $ws, 'company_id' => $id, 'siret' => null, 'idcc' => '1486', 'opco' => 'atlas', 'opco_gestion' => null, 'source' => 'saisie', 'releve_le' => null]);
            break;
        case 'liste_manuelle':
            $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'ZZ Liste'] + $maintenant);
            DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $id, 'origine' => 'coche']);
            break;
        case 'evenement_metier':
            DB::table('business_events')->insert(['workspace_id' => $ws, 'action' => 'zz.tag', 'resource_type' => 'company', 'resource_id' => (string) $id, 'created_at' => now()]);
            break;
    }
}

function pefJeu(): array
{
    $ws = pefEspace();
    $ids = [
        'insee' => pefFiche($ws, '100000001', ['insee_ferme_le' => '2024-01-01']),
        'inactive' => pefFiche($ws, '100000002'),
        'active' => pefFiche($ws, '100000003'),
        'inconnue' => pefFiche($ws, '100000004'),
        'protegee' => pefFiche($ws, '100000005', ['insee_ferme_le' => '2024-01-01']),
        'autre_naf' => pefFiche($ws, '100000006', ['naf' => '10.11Z', 'insee_ferme_le' => '2024-01-01']),
    ];
    DB::table('contacts')->insert([
        'workspace_id' => $ws, 'company_id' => $ids['protegee'], 'last_name' => 'Fictif',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $csv = pefCsv([['100000002', '620', 'I'], ['100000003', '620', 'A'], ['100000005', '620', 'A'], ['100000006', '620', 'A']]);

    return [$ws, $ids, $csv];
}

test('dry-run : zero ecriture, totaux justes, aucun nom', function () {
    [$ws, $ids, $csv] = pefJeu();
    $avant = DB::table('companies')->orderBy('id')->get()->toJson();
    $activites = DB::table('activities')->orderBy('id')->get()->toJson();

    $code = Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv]);
    $sortie = Artisan::output();

    expect($code)->toBe(0);
    expect(DB::table('companies')->orderBy('id')->get()->toJson())->toBe($avant);
    expect(DB::table('activities')->orderBy('id')->get()->toJson())->toBe($activites);
    expect($sortie)->toContain('DRY-RUN')->toContain('insee_ferme   : 1')->toContain('finess_inactif: 0')
        ->toContain('INSEE ouvert (etiquette seule): 1')->toContain('liens, sans corbeille) : 1')->toContain('non_officine')->not->toContain('ZZ FICTIVE');
});

test('apply : corbeille + motif, jamais de suppression, liens proteges', function () {
    [$ws, $ids, $csv] = pefJeu();
    $nb = DB::table('companies')->count();

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv, '--apply' => true]);

    expect(DB::table('companies')->count())->toBe($nb);
    $f = fn (string $k) => DB::table('companies')->where('id', $ids[$k])->first();
    $meta = fn (string $k) => json_decode($f($k)->metadata, true);

    expect($f('insee')->deleted_at)->not->toBeNull();
    expect($meta('insee')['ecartee_motif'])->toBe('insee_ferme')->and($meta('insee'))->toHaveKey('ecartee_le');
    // FINESS inactif + INSEE ouvert : etiquette seule, JAMAIS la corbeille.
    expect($f('inactive')->deleted_at)->toBeNull();
    expect($meta('inactive')['a_verifier_fermee'])->toBeTrue()->and($meta('inactive'))->not->toHaveKey('ecartee_motif');
    expect($f('active')->deleted_at)->toBeNull();
    expect($f('inconnue')->deleted_at)->toBeNull();
    expect($meta('inconnue')['type_etablissement'])->toBe('non_officine');
    expect($f('protegee')->deleted_at)->toBeNull();
    expect($meta('protegee')['a_verifier_fermee'])->toBeTrue();
    expect($f('autre_naf')->deleted_at)->toBeNull();
    expect($meta('autre_naf'))->toBe([]);
});

test('apply est rejouable : deuxieme passage = aucun changement', function () {
    [$ws, $ids, $csv] = pefJeu();
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv, '--apply' => true]);
    $apres = DB::table('companies')->orderBy('id')->pluck('metadata', 'id')->toJson();
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv, '--apply' => true]);

    expect(DB::table('companies')->orderBy('id')->pluck('metadata', 'id')->toJson())->toBe($apres);
});

test('sans --finess : seul le motif INSEE s applique', function () {
    [$ws, $ids] = pefJeu();
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);

    expect(DB::table('companies')->where('id', $ids['insee'])->value('deleted_at'))->not->toBeNull();
    expect(DB::table('companies')->where('id', $ids['inactive'])->value('deleted_at'))->toBeNull();
    expect(DB::table('companies')->where('id', $ids['inconnue'])->value('metadata'))->toBe('{}');
});

test('chaque source de protection : etiquette a_verifier_fermee, jamais la corbeille', function (string $source) {
    $ws = pefEspace();
    $id = pefFiche($ws, '200000001', ['insee_ferme_le' => '2024-01-01']);
    pefProteger($source, $ws, $id);

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);

    $fiche = DB::table('companies')->where('id', $id)->first();
    expect($fiche->deleted_at)->toBeNull();
    expect(json_decode($fiche->metadata, true))->toHaveKey('a_verifier_fermee')->not->toHaveKey('ecartee_motif');
})->with([
    'contact', 'deal', 'audience', 'lifecycle_stage', 'relation_type', 'etiquette_protegee', 'etiquette_manuelle', 'activite_sans_type',
    'console_client_ref', 'activite_rendez_vous', 'personne', 'praticien', 'media', 'evenement_metier',
    'tache', 'note_saisie', 'echange_call', 'echange_linkedin_message', 'journaliste', 'organisateur',
    'federation', 'federation_parent', 'opco', 'liste_manuelle',
]);

test('une activite sans rapport (scraped) ne protege pas', function () {
    $ws = pefEspace();
    $id = pefFiche($ws, '200000002', ['insee_ferme_le' => '2024-01-01']);
    DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'note', 'kind' => 'scraped', 'subject_type' => 'company', 'subject_id' => $id, 'created_at' => now()]);

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);

    expect(DB::table('companies')->where('id', $id)->value('deleted_at'))->not->toBeNull();
});

test('CSV FINESS suspect : refus (trop court, trop d inactives, tronque, colonnes)', function () {
    pefJeu();
    $court = pefCsv([['100000003', '620', 'A']], 10);
    $inactifs = pefCsv([], 15000, 5000);
    $tronque = pefCsv([]);
    file_put_contents($tronque, 'F9,9999', FILE_APPEND);

    foreach ([$court, $inactifs, $tronque] as $csv) {
        $code = Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv, '--apply' => true]);
        expect($code)->toBe(1);
        expect(Artisan::output())->toContain('REFUS');
    }
    expect(DB::table('companies')->whereNotNull('deleted_at')->count())->toBe(0);
    expect(DB::table('companies')->where('metadata', '!=', '{}')->count())->toBe(0);

    $mauvais = tempnam(sys_get_temp_dir(), 'pef');
    file_put_contents($mauvais, "a,b\n1,2\n");
    expect(Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $mauvais]))->toBe(1);
});

test('fourchette : apply refuse hors bornes, rien n est ecrit ; relevable par option', function () {
    $ws = pefEspace();
    $lignes = [];
    for ($i = 0; $i < 151; $i++) {
        $lignes[] = ['workspace_id' => $ws, 'siren' => (string) (400000000 + $i), 'denomination' => 'ZZ FICTIVE ' . $i,
            'naf' => '4773Z', 'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}', 'insee_ferme_le' => '2024-01-01',
            'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('companies')->insert($lignes);

    $code = Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);
    expect($code)->toBe(1);
    expect(Artisan::output())->toContain('HORS FOURCHETTE')->toContain('REFUS');
    expect(DB::table('companies')->whereNotNull('deleted_at')->count())->toBe(0);

    expect(Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true, '--max-insee' => 200]))->toBe(0);
    expect(DB::table('companies')->whereNotNull('deleted_at')->count())->toBe(151);
});

test('fourchette non_officine : refus quand elle est depassee', function () {
    [$ws, $ids, $csv] = pefJeu();

    $avant = DB::table('companies')->orderBy('id')->pluck('metadata', 'id')->toJson();

    $code = Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv, '--apply' => true, '--max-non-officine' => 0]);

    expect($code)->toBe(1);
    expect(DB::table('companies')->whereNotNull('deleted_at')->count())->toBe(0);
    expect(DB::table('companies')->orderBy('id')->pluck('metadata', 'id')->toJson())->toBe($avant);
});

test('apply journalise chaque ecartement dans activities', function () {
    [$ws, $ids] = pefJeu();
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);

    $lignes = DB::table('activities')->where('subject_type', 'company')->whereRaw("payload->>'source' = 'pharmacies:ecarter-fermees'")->get();
    expect($lignes)->toHaveCount(1);
    expect((int) $lignes[0]->subject_id)->toBe($ids['insee']);
    expect(json_decode($lignes[0]->payload, true)['motif'])->toBe('insee_ferme');
});

test('option workspace : seul l espace demande est touche ; espace inconnu refuse', function () {
    $a = pefEspace();
    $b = pefEspace();
    $fa = pefFiche($a, '500000001', ['insee_ferme_le' => '2024-01-01']);
    $fb = pefFiche($b, '500000002', ['insee_ferme_le' => '2024-01-01']);

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true, '--workspace' => $a]);

    expect(DB::table('companies')->where('id', $fa)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('companies')->where('id', $fb)->value('deleted_at'))->toBeNull();

    expect(Artisan::call('crm:pharmacies:ecarter-fermees', ['--workspace' => (string) Str::uuid()]))->toBe(1);
    expect(Artisan::output())->toContain('REFUS');
});

test('verrou pris : refus', function () {
    pefJeu();
    $verrou = Cache::lock('crm:pharmacies:ecarter-fermees', 60);
    expect($verrou->get())->toBeTrue();

    expect(Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]))->toBe(1);
    expect(Artisan::output())->toContain('REFUS');
    expect(DB::table('companies')->whereNotNull('deleted_at')->count())->toBe(0);

    $verrou->release();
});

test('restaurer : vide deleted_at des seules fiches ecartees par la commande', function () {
    [$ws, $ids] = pefJeu();
    $autre = pefFiche($ws, '100000009', ['deleted_at' => now()]);
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);
    // prospection collect reecrit metadata en entier : le journal doit suffire.
    DB::table('companies')->where('id', $ids['insee'])->update(['metadata' => '{}']);

    // essai a blanc : rien n'est ecrit
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--restaurer' => true]);
    expect(DB::table('companies')->where('id', $ids['insee'])->value('deleted_at'))->not->toBeNull();

    // un autre motif ne restaure rien
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--restaurer' => true, '--apply' => true, '--motif' => 'finess_inactif']);
    expect(DB::table('companies')->where('id', $ids['insee'])->value('deleted_at'))->not->toBeNull();

    $nb = DB::table('companies')->count();
    expect(Artisan::call('crm:pharmacies:ecarter-fermees', ['--restaurer' => true, '--apply' => true, '--motif' => 'insee_ferme']))->toBe(0);

    expect(DB::table('companies')->count())->toBe($nb);
    expect(DB::table('companies')->where('id', $ids['insee'])->value('deleted_at'))->toBeNull();
    expect(DB::table('companies')->where('id', $autre)->value('deleted_at'))->not->toBeNull();
});

test('activites automatiques (enriched, reclassified, journal de la commande) ne protegent pas', function () {
    $ws = pefEspace();
    $id = pefFiche($ws, '200000003', ['insee_ferme_le' => '2024-01-01']);
    foreach (['enriched', 'reclassified', 'email_hard_bounced'] as $kind) {
        DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'note', 'kind' => $kind, 'subject_type' => 'company', 'subject_id' => $id, 'created_at' => now()]);
    }

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);

    expect(DB::table('companies')->where('id', $id)->value('deleted_at'))->not->toBeNull();
});

test('INSEE ferme mais FINESS encore actif : etiquette seule, compte a part', function () {
    $ws = pefEspace();
    $id = pefFiche($ws, '200000004', ['insee_ferme_le' => '2024-01-01']);
    $csv = pefCsv([['200000004', '620', 'A']]);

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv, '--apply' => true]);
    $sortie = Artisan::output();

    $fiche = DB::table('companies')->where('id', $id)->first();
    expect($fiche->deleted_at)->toBeNull();
    expect(json_decode($fiche->metadata, true))->toHaveKey('a_verifier_fermee')->not->toHaveKey('ecartee_motif');
    expect($sortie)->toContain('FINESS actif (etiquette seule): 1')->toContain('insee_ferme   : 0');
});

test('restaurer : une fiche retablie par un humain puis remise a la corbeille par ailleurs n est pas ressuscitee', function () {
    $ws = pefEspace();
    $id = pefFiche($ws, '200000005', ['insee_ferme_le' => '2024-01-01']);
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);
    $instant = DB::table('companies')->where('id', $id)->value('deleted_at');
    expect(json_decode(DB::table('activities')->where('subject_id', $id)->value('payload'), true))->toHaveKey('deleted_at');
    expect(json_decode(DB::table('companies')->where('id', $id)->value('metadata'), true))->toHaveKey('ecartee_instant');

    // Retablie par un humain, puis remise a la corbeille plus tard (fusion de doublon).
    DB::table('companies')->where('id', $id)->update(['deleted_at' => null]);
    DB::table('companies')->where('id', $id)->update(['deleted_at' => now()->addMinutes(5)]);
    expect(DB::table('companies')->where('id', $id)->value('deleted_at'))->not->toBe($instant);

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--restaurer' => true, '--apply' => true]);

    expect(DB::table('companies')->where('id', $id)->value('deleted_at'))->not->toBeNull();
});

test('apres restaurer, apply ne remet pas les memes fiches a la corbeille', function () {
    $ws = pefEspace();
    $id = pefFiche($ws, '200000006', ['insee_ferme_le' => '2024-01-01']);
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--restaurer' => true, '--apply' => true]);
    expect(DB::table('companies')->where('id', $id)->value('deleted_at'))->toBeNull();

    // collect reecrit metadata en entier : seul le journal reste.
    DB::table('companies')->where('id', $id)->update(['metadata' => '{}']);
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);

    expect(DB::table('companies')->where('id', $id)->value('deleted_at'))->toBeNull();
    expect(Artisan::output())->toContain('deja restaurees (ignorees)         : 1');
});

test('apply par paquets : paquets de taille 1, toutes les fiches examinees', function () {
    $ws = pefEspace();
    foreach (['600000001', '600000002', '600000003'] as $siren) {
        pefFiche($ws, $siren, ['insee_ferme_le' => '2024-01-01']);
    }

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true, '--taille-lot' => 1]);

    expect(DB::table('companies')->whereNotNull('deleted_at')->count())->toBe(3);
});

test('restaurer : une fiche a la corbeille hors NAF 47.73Z n est pas touchee', function () {
    $ws = pefEspace();
    $hors = pefFiche($ws, '700000001', ['naf' => '10.11Z', 'deleted_at' => now()]);
    $dans = pefFiche($ws, '700000002', ['insee_ferme_le' => '2024-01-01']);
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);
    // Journal « ecartee » a l'instant exact de la corbeille, pour la fiche hors NAF aussi.
    $instant = (string) DB::selectOne('SELECT deleted_at::text AS i FROM companies WHERE id = ?', [$hors])->i;
    DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'note', 'kind' => 'reclassified', 'subject_type' => 'company', 'subject_id' => $hors,
        'payload' => json_encode(['source' => 'pharmacies:ecarter-fermees', 'action' => 'ecartee', 'motif' => 'insee_ferme', 'deleted_at' => $instant]), 'created_at' => now()]);

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--restaurer' => true]);
    expect(Artisan::output())->toContain('DRY-RUN restauration : 1 fiche');
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--restaurer' => true, '--apply' => true]);

    expect(DB::table('companies')->where('id', $dans)->value('deleted_at'))->toBeNull();
    expect(DB::table('companies')->where('id', $hors)->value('deleted_at'))->not->toBeNull();
});

test('restaurer --workspace : l autre espace n est pas touche', function () {
    $a = pefEspace();
    $b = pefEspace();
    $fa = pefFiche($a, '710000001', ['insee_ferme_le' => '2024-01-01']);
    $fb = pefFiche($b, '710000002', ['insee_ferme_le' => '2024-01-01']);
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--restaurer' => true, '--apply' => true, '--workspace' => $a]);

    expect(DB::table('companies')->where('id', $fa)->value('deleted_at'))->toBeNull();
    expect(DB::table('companies')->where('id', $fb)->value('deleted_at'))->not->toBeNull();
});

test('arret par paquet : cumul hors fourchette en cours d ecriture, paquet suivant non ecrit', function () {
    $a = pefEspace();
    $b = pefEspace();
    pefFiche($a, '720000001', ['insee_ferme_le' => '2024-01-01']);
    pefFiche($b, '720000002', ['insee_ferme_le' => '2024-01-01']);

    // Entre l'essai a blanc et l'ecriture, des fiches fermees arrivent dans le
    // second espace : declenche apres la PREMIERE ecriture (mise a la corbeille),
    // donc independamment du nombre de lectures. La fourchette cumulee doit
    // arreter l'ecriture avant le paquet fautif.
    $inserees = false;
    DB::listen(function ($requete) use (&$inserees, $b): void {
        if ($inserees || ! str_contains($requete->sql, 'deleted_at = now()')) {
            return;
        }
        $inserees = true;
        $lignes = [];
        for ($i = 0; $i < 3; $i++) {
            $lignes[] = ['workspace_id' => $b, 'siren' => (string) (730000000 + $i), 'denomination' => 'ZZ FICTIVE',
                'naf' => '47.73Z', 'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}', 'insee_ferme_le' => '2024-01-01',
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('companies')->insert($lignes);
    });

    $code = Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true, '--max-insee' => 4]);

    expect($code)->toBe(1);
    expect($inserees)->toBeTrue();
    expect(Artisan::output())->toContain('REFUS : cumul hors fourchette');
    // Seul le premier espace est ecrit ; le second (1 + 3 fiches, cumul 5 > 4), fautif, ne l'est pas.
    expect(DB::table('companies')->whereNotNull('deleted_at')->count())->toBe(1);
});

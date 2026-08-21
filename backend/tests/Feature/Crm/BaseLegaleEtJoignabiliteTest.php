<?php

/**
 * GARDE — audit 360, C21-006 (S1) : « 909 086 personnes (68,89 %) sont
 * enregistrees SANS AUCUN moyen de contact, et les 1 319 567 sont sans base
 * legale renseignee. »
 *
 * ── CE QUE CE FICHIER FIGE, ET CE QU'IL NE PRETEND PAS FAIRE ──────────────
 *
 * Il ne repare PAS le stock : poser `legal_basis` retroactivement sur
 * 1 319 567 fiches est un acte juridique (decider a posteriori sous quelle
 * base une personne a ete collectee), pas une reparation technique. Ce qu'il
 * fige, c'est :
 *
 *   1. que le CHIFFRE est mesurable, exact, et rendu par source — sinon on ne
 *      saura jamais s'il empire ;
 *   2. que la mesure NE PASSE PAS AU VERT sur une reponse vide — le piege
 *      exact de cette table, qui porte `FORCE ROW LEVEL SECURITY` : sans
 *      contexte d'espace, la requete rend zero ligne, donc « 0 % de fiches
 *      sans base legale », le rapport le plus rassurant et le plus faux ;
 *   3. que le CHEMIN D'ECRITURE de l'ingestion pose desormais une base legale,
 *      a la creation ET en rattrapage sur une fiche qui n'en portait pas —
 *      sans quoi le stock recreuserait le trou des la premiere collecte.
 *
 * ── TEMOINS JOUES (mutations temporaires, code restaure ensuite) ───────────
 *
 *   · `MesureBaseLegale::part()` rendant `0.0` au lieu de `null` sur un
 *     denominateur nul + garde anti-vide retiree
 *       -> « aucune fiche mesuree n est PAS un bon resultat » ECHOUE.
 *   · `ContactUpserter` prive de sa ligne `'legal_basis' => $legalBasis`
 *       -> « l ingestion du site pose une base legale » ECHOUE :
 *          Failed asserting that null is identical to 'precontractual'.
 *   · predicat « sans moyen de contact » reduit au seul e-mail
 *       -> le decompte attendu (2) devient 4 : les fiches qui ne portent QUE
 *          un telephone ou QU'UN profil LinkedIn seraient comptees injoignables.
 */

use App\Crm\Ingest\ContactUpserter;
use App\Crm\Ingest\MesureBaseLegale;
use App\Crm\Taxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function blEspace(string $marque): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'bl-' . $marque . '-' . Str::random(6),
        'name' => 'Espace base legale ' . $marque,
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function blEntreprise(string $workspaceId, string $siren): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $workspaceId,
        'siren' => $siren,
        'denomination' => 'ZZ BL ' . $siren,
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Fabrique une fiche personne a l'image du stock de production : on choisit
 * explicitement ce qu'elle porte, y compris RIEN.
 */
function blFiche(
    string $workspaceId,
    int $companyId,
    string $nom,
    ?string $email = null,
    ?string $phone = null,
    ?string $linkedin = null,
    ?string $baseLegale = null,
    string $source = 'annuaire-entreprises',
    bool $supprimee = false,
): int {
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $workspaceId,
        'company_id' => $companyId,
        'first_name' => 'Jean',
        'last_name' => $nom,
        'email' => $email,
        'phone' => $phone,
        'linkedin_url' => $linkedin,
        'legal_basis' => $baseLegale,
        'discovery_source' => $source,
        'sources' => json_encode([$source], JSON_THROW_ON_ERROR),
        'metadata' => '{}',
        'field_origins' => '{}',
        'deleted_at' => $supprimee ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function blMesureJson(array $options = []): array
{
    $code = Artisan::call('crm:mesure-base-legale', $options + ['--json' => true, '--autoriser-base-vide' => true]);
    $sortie = trim(Artisan::output());

    expect($code)->toBeInt();

    $decode = json_decode($sortie, true);
    if (! is_array($decode)) {
        throw new RuntimeException('La sortie --json n est pas du JSON : ' . $sortie);
    }

    return $decode;
}

// ─────────────────────────────────────────────────────────────────────────────
// 0. LE SCHEMA SUR LEQUEL LE CONSTAT PORTE
// ─────────────────────────────────────────────────────────────────────────────

test('les quatre colonnes du constat existent — sinon la mesure ne mesure rien', function () {
    // Une mesure qui tourne sur une table amputee rend « 0 fiche sans base
    // legale » et annonce une conformite parfaite le jour ou le schema casse.
    expect(Schema::hasTable('contacts'))->toBeTrue();

    foreach (['email', 'phone', 'linkedin_url', 'legal_basis', 'discovery_source', 'deleted_at'] as $colonne) {
        expect(Schema::hasColumn('contacts', $colonne))->toBeTrue();
    }
});

test('la mesure REFUSE de compter si une colonne du constat disparait', function () {
    // TEMOIN structurel : on retire la colonne DANS la transaction du test
    // (RefreshDatabase la restaure au rollback) et on verifie que la mesure
    // hurle au lieu de rendre zero.
    DB::statement('ALTER TABLE contacts DROP COLUMN linkedin_url');

    $erreur = null;
    try {
        app(MesureBaseLegale::class)->surTousLesEspaces();
    } catch (Throwable $e) {
        $erreur = $e;
    }

    expect($erreur)->not->toBeNull();
    $this->assertStringContainsString('MESURE IMPOSSIBLE', $erreur->getMessage());
    $this->assertStringContainsString('linkedin_url', $erreur->getMessage());
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. LE CHIFFRE — exact, par source, et pas « vrai par accident »
// ─────────────────────────────────────────────────────────────────────────────

test('la mesure compte exactement les fiches sans AUCUN moyen de contact', function () {
    $ws = blEspace('joignabilite');
    $entreprise = blEntreprise($ws, '900002001');

    // 2 injoignables : ni adresse, ni telephone, ni profil.
    blFiche($ws, $entreprise, 'ZZ MUETTE A');
    blFiche($ws, $entreprise, 'ZZ MUETTE B', email: '   ');   // chaine vide = absence

    // 3 joignables, chacune par UN SEUL canal different. C'est le coeur du
    // temoin : un predicat qui ne regarderait que l'e-mail compterait 4
    // injoignables au lieu de 2, et ce test rougirait.
    blFiche($ws, $entreprise, 'ZZ COURRIEL', email: 'zz.courriel@acme.fr');
    blFiche($ws, $entreprise, 'ZZ TELEPHONE', phone: '+33 4 76 00 00 00');
    blFiche($ws, $entreprise, 'ZZ PROFIL', linkedin: 'https://linkedin.example.invalid/in/zz');

    $rapport = blMesureJson();

    expect($rapport['total'])->toBe(5);
    expect($rapport['sans_contact'])->toBe(2);
    // `json_decode` rend 40 (entier) pour un 40.0 encode : on compare la
    // VALEUR, pas la representation JSON.
    expect((float) $rapport['part_sans_contact'])->toBe(40.0);
});

test('la mesure compte exactement les fiches sans base legale, et par source', function () {
    $ws = blEspace('baselegale');
    $entreprise = blEntreprise($ws, '900002002');

    // La collecte (annuaire) : 3 fiches, aucune base legale — l'etat mesure
    // en production sur la totalite du stock.
    blFiche($ws, $entreprise, 'ZZ BL A', source: 'annuaire-entreprises');
    blFiche($ws, $entreprise, 'ZZ BL B', source: 'annuaire-entreprises');
    blFiche($ws, $entreprise, 'ZZ BL C', source: 'annuaire-entreprises');

    // Le site : 1 fiche, base legale posee.
    blFiche($ws, $entreprise, 'ZZ BL D', email: 'zz.d@acme.fr', source: 'site', baseLegale: 'precontractual');

    $rapport = blMesureJson();

    expect($rapport['total'])->toBe(4);
    expect($rapport['sans_base'])->toBe(3);
    expect((float) $rapport['part_sans_base'])->toBe(75.0);

    $parSource = collect($rapport['par_source'])->keyBy('source');

    expect($parSource['annuaire-entreprises']['total'])->toBe(3);
    expect($parSource['annuaire-entreprises']['sans_base'])->toBe(3);
    expect($parSource['site']['total'])->toBe(1);
    expect($parSource['site']['sans_base'])->toBe(0);
});

test('la colonne ne peut PAS contenir la chaine vide : le seul vide possible est NULL', function () {
    // DECOUVERTE de ce lot, verifiee ici : le CHECK
    // `contacts_legal_basis_check` (migration 2026_08_14_000002) s ecrit
    // « legal_basis IS NULL OR legal_basis IN (...) ». Une chaine vide le
    // VIOLE. Le predicat de mesure couvre quand meme `btrim(...) = ''` — non
    // par superstition, mais parce qu un futur relachement du CHECK
    // rouvrirait ce trou-la en silence, et que ce test dira alors qu il a
    // change.
    $ws = blEspace('chainevide');
    $entreprise = blEntreprise($ws, '900002013');

    $refus = null;
    try {
        blFiche($ws, $entreprise, 'ZZ CHAINE VIDE', baseLegale: '');
    } catch (Throwable $e) {
        $refus = $e;
    }

    expect($refus)->not->toBeNull();
    $this->assertStringContainsString('contacts_legal_basis_check', $refus->getMessage());
});

test('les fiches en suppression douce sont comptees A PART, jamais escamotees', function () {
    // Une fiche « supprimee » garde ses donnees a caractere personnel en base.
    // La retirer du rapport sans le dire ferait baisser le chiffre sans que
    // rien n ait ete corrige.
    $ws = blEspace('supprimees');
    $entreprise = blEntreprise($ws, '900002003');

    blFiche($ws, $entreprise, 'ZZ VIVANTE');
    blFiche($ws, $entreprise, 'ZZ SUPPRIMEE', supprimee: true);

    $rapport = blMesureJson();

    expect($rapport['total'])->toBe(1);
    expect($rapport['supprimees'])->toBe(1);
});

test('les fiches sans provenance ne disparaissent pas du detail par source', function () {
    // `discovery_source` est NULLable : un GROUP BY nu ferait une ligne
    // « source: null » que le rapport ne saurait pas afficher, et le total par
    // source cesserait d egaler le total general.
    $ws = blEspace('sanssource');
    $entreprise = blEntreprise($ws, '900002004');

    DB::table('contacts')->insert([
        'workspace_id' => $ws,
        'company_id' => $entreprise,
        'last_name' => 'ZZ SANS SOURCE',
        'discovery_source' => null,
        'sources' => '[]',
        'metadata' => '{}',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rapport = blMesureJson();
    $sommeDesSources = array_sum(array_column($rapport['par_source'], 'total'));

    expect($rapport['total'])->toBe(1);
    expect($sommeDesSources)->toBe($rapport['total']);
    $this->assertSame(
        MesureBaseLegale::SOURCE_INCONNUE,
        $rapport['par_source'][0]['source'],
    );
});

test('les fiches des DEUX espaces de travail sont comptees — la RLS n en avale aucune', function () {
    // C est le defaut qui a deja coute une commande de remplissage : sans
    // `app.current_workspace_id`, la policy stricte de `contacts` rend zero
    // ligne et le rapport annonce « aucun probleme ».
    $a = blEspace('espacea');
    $b = blEspace('espaceb');

    blFiche($a, blEntreprise($a, '900002005'), 'ZZ ESPACE A');
    blFiche($b, blEntreprise($b, '900002006'), 'ZZ ESPACE B');

    $rapport = blMesureJson();

    expect($rapport['espaces'])->toBeGreaterThanOrEqual(2);
    expect($rapport['total'])->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. ANTI-VERT-A-VIDE — le coeur de la garde
// ─────────────────────────────────────────────────────────────────────────────

test('une mesure qui ne trouve AUCUNE fiche echoue au lieu de rendre 0 %', function () {
    // Aucune fiche seedee : c est exactement la photographie que rendrait la
    // RLS si la mesure oubliait de poser le contexte d espace.
    $code = Artisan::call('crm:mesure-base-legale');
    $sortie = Artisan::output();

    expect($code)->toBe(1);
    $this->assertStringContainsString('MESURE VIDE', $sortie);
    $this->assertStringContainsString('n est PAS un bon resultat', $sortie);
});

test('sur zero fiche la part n est pas 0 % mais « non mesurable »', function () {
    expect(MesureBaseLegale::part(0, 0))->toBeNull();
    expect(MesureBaseLegale::part(0, 10))->toBe(0.0);
    expect(MesureBaseLegale::part(909086, 1319567))->toBe(68.89); // le chiffre du constat, reproduit
});

test('le drapeau --autoriser-base-vide rend le vide EXPLICITE, il ne le masque pas', function () {
    $code = Artisan::call('crm:mesure-base-legale', ['--autoriser-base-vide' => true]);
    $sortie = Artisan::output();

    expect($code)->toBe(0);
    $this->assertStringContainsString('non mesurable', $sortie);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. LE SEUIL — le mecanisme qui fera voir que le chiffre EMPIRE
// ─────────────────────────────────────────────────────────────────────────────

test('au-dessus du seuil, la commande sort en echec et nomme le depassement', function () {
    $ws = blEspace('seuil');
    $entreprise = blEntreprise($ws, '900002007');

    blFiche($ws, $entreprise, 'ZZ SEUIL A');                                  // sans base
    blFiche($ws, $entreprise, 'ZZ SEUIL B', baseLegale: 'legitimate_interest_b2b');

    // 50 % sans base legale, pour un maximum tolere de 40 %.
    $code = Artisan::call('crm:mesure-base-legale', ['--seuil-sans-base' => '40']);
    $sortie = Artisan::output();

    expect($code)->toBe(1);
    $this->assertStringContainsString('SEUIL DEPASSE', $sortie);
    $this->assertStringContainsString('sans base legale', $sortie);
});

test('sous le seuil, la commande sort en succes', function () {
    $ws = blEspace('seuilok');
    $entreprise = blEntreprise($ws, '900002008');

    blFiche($ws, $entreprise, 'ZZ OK A', baseLegale: 'legitimate_interest_b2b');
    blFiche($ws, $entreprise, 'ZZ OK B', baseLegale: 'precontractual');

    $code = Artisan::call('crm:mesure-base-legale', ['--seuil-sans-base' => '40']);

    expect($code)->toBe(0);
});

test('la commande N ECRIT RIEN : aucune base legale n apparait apres son passage', function () {
    // Le contrat central du lot. Si un jour quelqu un ajoute un « pendant qu on
    // y est, remplissons », ce test le refuse.
    $ws = blEspace('lectureseule');
    $entreprise = blEntreprise($ws, '900002009');
    $id = blFiche($ws, $entreprise, 'ZZ LECTURE SEULE');

    Artisan::call('crm:mesure-base-legale', ['--seuil-sans-base' => '100']);

    expect(DB::table('contacts')->where('id', $id)->value('legal_basis'))->toBeNull();
    expect(DB::table('contacts')->where('id', $id)->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. LE CHEMIN D'ECRITURE — pour que le trou cesse de se creuser
// ─────────────────────────────────────────────────────────────────────────────

test('l ingestion du site pose une base legale sur la fiche personne qu elle CREE', function () {
    $ws = blEspace('upsert');
    $entreprise = blEntreprise($ws, '900002010');

    [$id, $statut] = app(ContactUpserter::class)->upsert(
        workspaceId: $ws,
        companyId: $entreprise,
        personKey: str_repeat('a', 64),
        externalRef: 'ref-zz-upsert',
        email: 'zz.upsert@acme.fr',
        firstName: 'Jean',
        lastName: 'ZZ UPSERT',
        phone: null,
        legalBasis: 'precontractual',
    );

    $base = DB::table('contacts')->where('id', $id)->value('legal_basis');

    expect($statut)->toBe('created');
    $this->assertSame('precontractual', $base);
    $this->assertContains($base, Taxonomy::LEGAL_BASES);
});

test('l ingestion RATTRAPE une fiche collectee qui n avait aucune base legale', function () {
    // Les 1 319 567 fiches du constat sont deja la. La question qui compte :
    // quand le site touche l une d elles, sort-elle du decompte ?
    $ws = blEspace('rattrapage');
    $entreprise = blEntreprise($ws, '900002011');
    $id = blFiche($ws, $entreprise, 'ZZ RATTRAPAGE', email: 'zz.rattrapage@acme.fr');

    expect(DB::table('contacts')->where('id', $id)->value('legal_basis'))->toBeNull();

    [$idRevu, $statut] = app(ContactUpserter::class)->upsert(
        workspaceId: $ws,
        companyId: $entreprise,
        personKey: str_repeat('b', 64),
        externalRef: null,
        email: 'zz.rattrapage@acme.fr',
        firstName: 'Jean',
        lastName: 'ZZ RATTRAPAGE',
        phone: null,
        legalBasis: 'precontractual',
    );

    expect($idRevu)->toBe($id);
    expect($statut)->toBe('updated');
    $this->assertSame('precontractual', DB::table('contacts')->where('id', $id)->value('legal_basis'));

    // Et la mesure le voit : la fiche n est plus comptee.
    $rapport = blMesureJson();
    expect($rapport['sans_base'])->toBe(0);
});

test('la base legale posee appartient au vocabulaire ferme declare par Taxonomy', function () {
    // Le CHECK SQL `contacts_legal_basis_check` autorise NULL : c est ce qui a
    // laisse la colonne se vider en silence. Le vocabulaire, lui, est ferme —
    // et c est dans `Taxonomy::LEGAL_BASES` que le code declare la valeur des
    // fiches SCRAPEES (`legitimate_interest_b2b`).
    $this->assertContains('legitimate_interest_b2b', Taxonomy::LEGAL_BASES);
    $this->assertContains('precontractual', Taxonomy::LEGAL_BASES);
    $this->assertContains('consent', Taxonomy::LEGAL_BASES);

    $ws = blEspace('vocabulaire');
    $entreprise = blEntreprise($ws, '900002012');

    $refus = null;
    try {
        blFiche($ws, $entreprise, 'ZZ HORS VOCABULAIRE', baseLegale: 'parce-que-on-en-avait-envie');
    } catch (Throwable $e) {
        $refus = $e;
    }

    expect($refus)->not->toBeNull();
});

<?php

/**
 * 🔴 B14-002 / E31-001 (S0) — « L'ACCUSE DE RECEPTION QUI MENT ».
 *
 * Le constat, mot pour mot : « `erasure` traverse, le site repond "applique",
 * et rien n'est efface. »
 *
 * ── CE QUE LA MESURE DIT DU CONSTAT ────────────────────────────────────────
 *
 * Sur `erasure`, et COTE CRM, il est FAUX. Sonde du 2026-08-21 sur
 * `axion_crm_test_lot7`, une demande par type traitee via
 * `POST /rgpd/requests/{id}/process` :
 *
 *   type            HTTP  status  contacts restants  opt_out  outbound
 *   access          200   done          1               0       —      ← art. 15
 *   portability     200   done          1               0       —      ← art. 20
 *   erasure         200   done          0               2      erasure ← art. 17
 *   rectification   200   done          1               0       —      ← art. 16
 *   opposition      200   done          1               0       —      ← art. 21
 *
 * L'effacement efface reellement. La moitie VRAIE du constat vit dans le depot
 * du site (`axionia/src/server/crm-sync/inbound.ts:243-261`, hors perimetre) :
 * l'evenement `erasure` y tombe dans la meme branche qu'un `consent_optout`,
 * l'abonne est passe a `unsubscribed` — sa ligne, son adresse et son nom
 * restent — et la fonction rend « applied ». Ce fichier-ci ne peut pas le
 * reparer ; il PROUVE la moitie CRM, pour que le rapport sache laquelle des
 * deux reste ouverte.
 *
 * ── ET LA MEME MESURE A TROUVE LE DEFAUT, INTACT, TROIS COLONNES PLUS LOIN ──
 *
 * `access`, `rectification` et `opposition` : HTTP 200, `status = 'done'`,
 * `processed_at` horodate, `metadata.result = {"noop": true}` — et RIEN de fait.
 * `opposition` porte exactement la consequence du constat : la personne est
 * inscrite « traitee » au registre — la piece que le CRM opposerait a un
 * controle — et reste joignable, aucune ligne `opt_out`, la porte des campagnes
 * ouverte. La note que la console POSTe (`RgpdRequestsPage.tsx`) etait, elle,
 * jetee sans etre lue : l'operateur ecrivait la trace de son geste dans le vide.
 *
 * ── CE QUE CETTE GARDE MESURE, ET CE QU'ELLE REFUSE DE MESURER ─────────────
 *
 * AUCUNE assertion ne porte sur un code HTTP de succes. Une garde qui verifie
 * un 200 serait exactement le defaut qu'elle pretend garder. On mesure des
 * LIGNES : disparues, opposees, echangeables contre une archive.
 *
 * Les types sont enumeres depuis le CATALOGUE — la contrainte
 * `CHECK (type IN (...))` lue dans `pg_constraint` — jamais depuis une liste
 * ecrite a la main : un sixieme droit ajoute en base fera rougir la couverture.
 */

use App\Http\Controllers\Api\RgpdRequestsController;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Rgpd\GdprPortabilityService;
use App\Support\EligibiliteCampagne;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Le CATALOGUE des droits que ce point d'entree accepte : la contrainte CHECK
 * de `rgpd_requests.type`, relue dans le dictionnaire de Postgres.
 *
 * On ne recopie pas la liste : c'est tout l'objet du temoin de couverture.
 *
 * @return list<string>
 */
function b14002_typesDuCatalogue(): array
{
    $def = DB::selectOne(
        "select pg_get_constraintdef(c.oid) as def
           from pg_constraint c
           join pg_class t on t.oid = c.conrelid
          where t.relname = 'rgpd_requests'
            and c.contype = 'c'
            and pg_get_constraintdef(c.oid) ilike '%type%'
            and pg_get_constraintdef(c.oid) not ilike '%status%'",
    );

    expect($def)->not->toBeNull(
        'Aucune contrainte CHECK sur `rgpd_requests.type` : sans catalogue, la '
        . 'couverture de cette garde ne vaut rien et il faut le savoir.',
    );

    preg_match_all("/'([a-z_]+)'/", (string) $def->def, $m);
    $types = array_values(array_unique($m[1]));
    sort($types);

    return $types;
}

/** @return array{0: User, 1: Workspace} */
function b14002_operateur(): array
{
    test()->seed(PermissionsAndRolesSeeder::class);

    $ws = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'b14002-' . Str::random(6),
        'name' => 'B14-002',
    ]);
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'b14002-' . Str::random(4) . '@test.local',
        'name' => 'Operateur',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $ws->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws->id);
    $u->assignRole('admin');

    return [$u, $ws];
}

/** Une personne, sa fiche, son telephone. */
function b14002_fiche(string $workspaceId, string $email, string $telephone = '+33600000001'): int
{
    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $workspaceId,
        'denomination' => 'Entreprise B14-002',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $workspaceId,
        'company_id' => $companyId,
        'first_name' => 'Jean',
        'last_name' => 'Concerne',
        'email' => $email,
        'phone' => $telephone,
        'person_key' => hash('sha256', $email),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function b14002_demande(string $workspaceId, string $type, string $email): int
{
    return (int) DB::table('rgpd_requests')->insertGetId([
        'workspace_id' => $workspaceId,
        'type' => $type,
        'status' => 'pending',
        'subject_email' => $email,
        'requested_at' => now(),
        'metadata' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. LA MOITIE CRM DU CONSTAT — RE-PROUVEE, PAR LES LIGNES
// ═══════════════════════════════════════════════════════════════════════════

test('B14-002 — un effacement traite par la console fait DISPARAITRE les lignes', function () {
    [$u, $ws] = b14002_operateur();
    $email = 'efface@b14002.test';
    $voisine = 'voisine@b14002.test';

    b14002_fiche($ws->id, $email);
    b14002_fiche($ws->id, $voisine);
    $id = b14002_demande($ws->id, 'erasure', $email);

    $this->actingAs($u)->postJson("/api/v1/rgpd/requests/{$id}/process");

    // ── L'EFFET, jamais la reponse ──────────────────────────────────────────
    expect(DB::table('contacts')->where('email', $email)->count())->toBe(
        0,
        'La fiche de la personne effacee est toujours en base : l\'accuse de '
        . 'reception ment.',
    );

    // TEMOIN NEGATIF : un effacement qui viderait la table entiere passerait
    // pour une reussite.
    expect(DB::table('contacts')->where('email', $voisine)->count())->toBe(
        1,
        'La fiche d\'une AUTRE personne a disparu : ce n\'est pas un effacement, '
        . 'c\'est une purge.',
    );

    // Et la personne effacee ne redevient pas joignable (B15-001).
    expect(EligibiliteCampagne::peutRecevoir($email, 'business'))->toBeFalse();
    expect(EligibiliteCampagne::peutRecevoir($email, 'vivier'))->toBeFalse();
    expect(EligibiliteCampagne::peutRecevoir($voisine, 'business'))->toBeTrue(
        'Le temoin de campagne refuse TOUT LE MONDE : la garde ne mesure plus rien.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. LE DEFAUT TROUVE PAR LA MEME MESURE — ARTICLE 21
// ═══════════════════════════════════════════════════════════════════════════

test('B14-002 — une opposition « traitee » FERME reellement les portes', function () {
    [$u, $ws] = b14002_operateur();
    $email = 'oppose@b14002.test';
    $voisine = 'jamais-opposee@b14002.test';

    b14002_fiche($ws->id, $email);
    b14002_fiche($ws->id, $voisine);
    $id = b14002_demande($ws->id, 'opposition', $email);

    $this->actingAs($u)->postJson("/api/v1/rgpd/requests/{$id}/process");

    $empreinte = hash('sha256', $email);

    // ── L'EFFET : une ligne d'opposition PAR UNIVERS ────────────────────────
    $univers = DB::table('opt_out')->where('email_hash', $empreinte)->pluck('scope')->all();
    sort($univers);

    expect($univers)->toBe(
        ['business', 'vivier'],
        'Une demande d\'opposition art. 21 a ete inscrite « traitee » au registre '
        . 'sans qu\'aucune opposition ne soit ecrite : la personne reste joignable.',
    );

    // ── ET LA PORTE DES CAMPAGNES, qui est ce qui la protege vraiment ───────
    expect(EligibiliteCampagne::peutRecevoir($email, 'business'))->toBeFalse(
        'Une campagne « business » peut encore ecrire a une personne qui s\'y est opposee.',
    );
    expect(EligibiliteCampagne::peutRecevoir($email, 'vivier'))->toBeFalse(
        'Une campagne « vivier » peut encore ecrire a une personne qui s\'y est opposee.',
    );

    // TEMOIN NEGATIF : la garde n'est pas un refus universel.
    expect(EligibiliteCampagne::peutRecevoir($voisine, 'business'))->toBeTrue(
        'Une adresse JAMAIS opposee est refusee elle aussi : la garde ne mesure rien.',
    );

    // ── LE SIGNAL PART AU SITE, et il dit ce qui a ete decide ───────────────
    $sortant = DB::table('crm_outbound_events')->where('email_hash', $empreinte)->get();

    expect($sortant->count())->toBeGreaterThan(
        0,
        'Aucun signal vers le site : le site continue d\'ecrire a quelqu\'un qui '
        . 's\'est oppose dans la console.',
    );
    expect($sortant->pluck('event_type')->unique()->all())->toBe(
        ['consent_optout'],
        'Le signal emis ne correspond pas a la decision prise (une opposition '
        . 'n\'est pas un effacement).',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. LE DEFAUT TROUVE PAR LA MEME MESURE — ARTICLE 15
// ═══════════════════════════════════════════════════════════════════════════

test('B14-002 — un droit d acces « traite » produit une archive REELLEMENT echangeable', function () {
    [$u, $ws] = b14002_operateur();
    $email = 'acces@b14002.test';

    b14002_fiche($ws->id, $email, '+33611223344');
    $id = b14002_demande($ws->id, 'access', $email);

    $reponse = $this->actingAs($u)->postJson("/api/v1/rgpd/requests/{$id}/process");

    // ── L'EFFET : la ligne du registre porte de quoi ouvrir l'archive ───────
    $ligne = DB::table('rgpd_requests')->where('id', $id)->first();
    expect($ligne->export_token)->not->toBeNull(
        'Une demande d\'acces art. 15 est inscrite « traitee » sans qu\'aucun '
        . 'export n\'ait ete produit : la personne n\'a rien recu.',
    );
    expect($ligne->export_expires_at)->not->toBeNull();

    // ── ET L'ARCHIVE S'OUVRE, ET ELLE CONTIENT SES DONNEES ──────────────────
    // Le jeton en clair n'est rendu qu'a l'operateur, jamais persiste (B15-013).
    $jeton = $reponse->json('result.token');
    expect($jeton)->toBeString();

    $contenu = app(GdprPortabilityService::class)->retrieve((string) $jeton);
    expect($contenu)->not->toBeNull(
        'Le jeton rendu par le traitement d\'un droit d\'acces n\'ouvre RIEN : '
        . '`retrieve()` cherche `export_token` sur la ligne, et personne ne l\'y '
        . 'a ecrit. Un lien de telechargement mort-ne.',
    );
    $this->assertStringContainsString(
        '+33611223344',
        (string) $contenu,
        'L\'archive remise au titre de l\'article 15 ne contient pas le telephone '
        . 'que le CRM detient sur la personne.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. LE DROIT QUE CE CRM NE SAIT PAS EXECUTER — ARTICLE 16
// ═══════════════════════════════════════════════════════════════════════════

test('B14-002 — une rectification n est PAS inscrite « traitee » sans acte declare', function () {
    [$u, $ws] = b14002_operateur();
    $email = 'rectif@b14002.test';

    b14002_fiche($ws->id, $email);
    $id = b14002_demande($ws->id, 'rectification', $email);

    // Sans note : aucun automatisme n'existe, donc rien n'a ete fait.
    $this->actingAs($u)->postJson("/api/v1/rgpd/requests/{$id}/process", []);

    $ligne = DB::table('rgpd_requests')->where('id', $id)->first();
    expect($ligne->status)->toBe(
        'pending',
        'Le registre porte une rectification « traitee » alors qu\'aucun '
        . 'automatisme ne l\'execute et que personne n\'a dit ce qu\'il avait '
        . 'corrige. C\'est l\'accuse de reception qui ment, mot pour mot.',
    );
    expect($ligne->processed_at)->toBeNull();

    // Avec la trace de l'acte manuel : la demande se ferme, et le registre dit
    // ce qui s'est reellement passe.
    $this->actingAs($u)->postJson("/api/v1/rgpd/requests/{$id}/process", [
        'note' => 'Prenom corrige sur la fiche 4212 a la demande de la personne.',
    ]);

    $ligne = DB::table('rgpd_requests')->where('id', $id)->first();
    $metadata = json_decode((string) $ligne->metadata, true);

    expect($ligne->status)->toBe('done');
    $this->assertStringContainsString(
        'fiche 4212',
        json_encode($metadata, JSON_UNESCAPED_UNICODE),
        'La note que la console POSTe est jetee : l\'operateur ecrit la trace de '
        . 'son geste dans le vide, et le registre n\'en garde rien.',
    );
    expect($metadata['result']['executed_automatically'] ?? null)->toBeFalse(
        'Le registre laisse croire qu\'un automatisme a applique la rectification.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. TEMOIN DE COUVERTURE — ENUMERE PAR LE CATALOGUE, JAMAIS A LA MAIN
// ═══════════════════════════════════════════════════════════════════════════

test('B14-002 — AUCUN type du catalogue ne repond « traitee » sans avoir agi', function () {
    [$u, $ws] = b14002_operateur();

    $catalogue = b14002_typesDuCatalogue();

    // Le catalogue de la base et le vocabulaire du point d'entree doivent etre
    // le MEME ensemble : sinon la boucle ci-dessous ne couvre pas ce qui passe.
    $vocabulaire = RgpdRequestsController::TYPES;
    sort($vocabulaire);
    expect($catalogue)->toBe(
        $vocabulaire,
        'Le CHECK de `rgpd_requests.type` et `RgpdRequestsController::TYPES` ont '
        . 'diverge : un droit peut entrer par une porte que le traitement ignore.',
    );

    expect(count($catalogue))->toBe(
        5,
        'Le catalogue des droits a change de taille. Ce n\'est pas un echec : '
        . 'c\'est le chiffre du jour (5 au 2026-08-21) qui bouge, et chaque '
        . 'nouveau droit doit recevoir un traitement REEL dans `process()` avant '
        . 'que cette garde ne reparte au vert.',
    );

    foreach ($catalogue as $type) {
        $email = $type . '@couverture.test';
        b14002_fiche($ws->id, $email);
        $id = b14002_demande($ws->id, $type, $email);

        // La note est fournie a tous : on veut mesurer le TRAITEMENT, pas le
        // garde-fou de l'article 16, qui a son test dedie ci-dessus.
        $this->actingAs($u)->postJson("/api/v1/rgpd/requests/{$id}/process", [
            'note' => 'Acte declare par le temoin de couverture.',
        ]);

        $ligne = DB::table('rgpd_requests')->where('id', $id)->first();
        $metadata = json_decode((string) $ligne->metadata, true);
        $resultat = $metadata['result'] ?? [];

        // ── L'INVARIANT : « traitee » n'est jamais rendu contre RIEN ────────
        expect($resultat)->not->toBe(
            ['noop' => true],
            "Le droit « {$type} » est inscrit « traite » au registre et le "
            . 'resultat archive dit litteralement qu\'il ne s\'est rien passe. '
            . 'C\'est la forme exacte de B14-002.',
        );
        expect($resultat)->not->toBe(
            [],
            "Le droit « {$type} » ne laisse aucune trace de ce qui a ete fait.",
        );

        // ── ET LA NOTE DE L'OPERATEUR SURVIT, POUR CHAQUE TYPE ─────────────
        $this->assertStringContainsString(
            'temoin de couverture',
            json_encode($metadata, JSON_UNESCAPED_UNICODE),
            "La note posee au traitement du droit « {$type} » a ete jetee.",
        );
    }
});

<?php

/**
 * GARDE DE LA PORTABILITÉ — audit 360, B15-003 (S0).
 *
 * L'export des articles 15 et 20 ne couvrait que **4 tables sur 31**. Manquaient
 * la timeline de la personne, sa fiche candidat, sa fiche journaliste ou
 * praticien, ses courriels échangés — c'est-à-dire l'essentiel de ce que le CRM
 * sait d'elle.
 *
 * INVARIANT POSÉ : *ce qu'on sait effacer, on doit savoir l'exporter*. Le dernier
 * test le vérifie table pour table, pour qu'aucun des deux services ne puisse
 * apprendre une table sans l'autre.
 */

use App\Services\Rgpd\GdprErasureService;
use App\Services\Rgpd\GdprPortabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const COURRIEL_EXPORT = 'sujet@portabilite.test';

function espacePort(string $prefixe = 'port'): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => $prefixe . '-' . Str::random(8), 'name' => 'Espace portabilité',
        'settings' => '{}', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** Rend le contenu déchiffré de l'export. */
function contenuExporte(array $resultat): array
{
    $chemin = 'gdpr-exports/' . $resultat['token'] . '.enc';
    $chiffre = Storage::disk('local')->get($chemin);

    return json_decode(Crypt::decryptString($chiffre), true, 512, JSON_THROW_ON_ERROR);
}

test('B15-003 — l export contient la TIMELINE de la personne', function () {
    Storage::fake('local');
    $espace = espacePort();
    $cle = hash('sha256', COURRIEL_EXPORT);

    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $espace, 'denomination' => 'Entreprise export',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $companyId,
        'first_name' => 'Sujet', 'last_name' => 'Test', 'email' => COURRIEL_EXPORT,
        'person_key' => $cle, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('activities')->insert([
        'workspace_id' => $espace, 'person_key' => $cle,
        'type' => 'inbound', 'kind' => 'form_submission',
        'title' => 'Demande de contact',
        'payload' => json_encode(['message' => 'Bonjour']),
        'occurred_at' => now(), 'created_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu)->toHaveKey('activities');
    expect($contenu['activities'])->toHaveCount(1);
});

test('B15-003 — l export contient la fiche CANDIDAT', function () {
    Storage::fake('local');
    DB::table('candidates')->insert([
        'workspace_id' => espacePort('vivier'),
        'first_name' => 'Sujet', 'last_name' => 'Test', 'email' => COURRIEL_EXPORT,
        'relation_type' => 'candidat_commercial', 'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'consent', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu['candidates'])->toHaveCount(1);
});

test('B15-003 — TEMOIN : l export ne contient PAS les donnees de quelqu un d autre', function () {
    Storage::fake('local');
    $espace = espacePort();
    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $espace, 'denomination' => 'Voisine',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $companyId,
        'first_name' => 'Marie', 'last_name' => 'Martin', 'email' => 'marie@voisine.test',
        'person_key' => hash('sha256', 'marie@voisine.test'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    // Sans ce témoin, un export qui déverserait toute la base passerait pour
    // une réussite — et serait lui-même une violation.
    expect($contenu['contacts'])->toHaveCount(0);
});

test('B15-003 — INVARIANT : tout ce que l effacement supprime, l export le connait', function () {
    // Les deux services doivent se répondre table pour table. Si l'un apprend
    // une table et pas l'autre, on effacerait une donnée qu'on aurait refusé de
    // montrer — ou l'inverse.
    $effacement = file_get_contents(base_path('app/Services/Rgpd/GdprErasureService.php'));
    $export = file_get_contents(base_path('app/Services/Rgpd/GdprPortabilityService.php'));

    $tablesSensibles = [
        'contacts', 'candidates', 'activities', 'journalists',
        'media', 'health_practitioners', 'email_messages',
        // B10-004 : `email_verification_logs` est le JUMEAU d'`email_validations`
        // (même adresse, même verdict de délivrabilité, deux tables). L'une
        // était effacée et exportée, l'autre n'était ni l'une ni l'autre — le
        // patron A-011 dans sa forme la plus pure.
        'email_validations', 'email_verification_logs',
        // Effacées depuis le 2026-08-19 ; elles doivent donc être exportables.
        'notifications', 'magic_links',
    ];

    foreach ($tablesSensibles as $table) {
        // `assertStringContainsString` et non `expect()->toContain()` : ce
        // dernier est VARIADIQUE chez Pest, un message y deviendrait une
        // seconde aiguille à trouver.
        $this->assertStringContainsString("'{$table}'", $effacement, "l’effacement ignore « {$table} »");
        $this->assertStringContainsString("'{$table}'", $export, "l’export ignore « {$table} »");
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// B10-004 — L'INVENTAIRE VIENT DU SCHÉMA, PAS DE LA MÉMOIRE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 🔴 B10-004 (S0) — « l'export couvre 4 tables et l'effacement 8, sur ~40 tables
 * porteuses de données personnelles ».
 *
 * Le vice de forme du constat d'origine était la LISTE ÉCRITE À LA MAIN : une
 * table ajoutée par une migration n'y entre jamais toute seule, et personne ne
 * s'en aperçoit — c'est exactement ainsi que `candidates` s'est retrouvée dans
 * ni l'un ni l'autre.
 *
 * Cette garde renverse la charge : elle INTERROGE `information_schema` et exige
 * qu'aucune table porteuse d'un identifiant de personne n'existe sans DÉCISION
 * écrite — couverte par l'export, ou exclue avec un motif. Une migration qui
 * ajoute une table à `email` fait rougir ce test tant que personne n'a tranché.
 *
 * Mesure du 2026-08-20 sur `axion_crm_test_lot1` : 21 tables de base portent au
 * moins une colonne d'identification directe.
 */

/** Colonnes qui IDENTIFIENT une personne physique par un moyen de contact. */
const COLONNES_IDENTIFIANTES = [
    'email', 'subject_email', 'from_address', 'to_addresses',
    'phone', 'email_hash', 'person_key',
];

/**
 * Tables couvertes qui n'apparaissent PAS dans l'inventaire par colonnes : leur
 * PII vit dans du TEXTE LIBRE, pas dans une colonne nommée. `notifications`
 * porte l'adresse au milieu de `body` — c'est d'ailleurs ainsi que
 * l'effacement la retrouve (`body ILIKE '%…%'`).
 *
 * ⚠️ La leçon de cette exception : l'inventaire par colonnes ne voit pas tout.
 * Une table à `payload` JSONB ou à corps de message peut porter des PII sans
 * jamais déclarer de colonne `email`. `activities` est dans ce cas aussi — elle
 * n'entre dans l'inventaire que par `person_key`.
 *
 * @var list<string>
 */
const COUVERTES_HORS_INVENTAIRE = ['notifications'];

/**
 * Décision, table par table. Une valeur `true` = couverte par l'export ; une
 * chaîne = motif d'exclusion, qui doit être écrit et non sous-entendu.
 *
 * @return array<string, true|string>
 */
function decisionsPortabilite(): array
{
    return [
        // ── COUVERTES PAR L'EXPORT ───────────────────────────────────────
        'contacts' => true,
        'candidates' => true,
        'activities' => true,
        'journalists' => true,
        'media' => true,
        'health_practitioners' => true,
        'email_messages' => true,
        'email_validations' => true,
        'email_verification_logs' => true,
        'rgpd_requests' => true,
        'magic_links' => true,
        'notifications' => true, // cf. COUVERTES_HORS_INVENTAIRE
        'opt_out' => true,
        'dnc_entries' => true,
        'email_suppressions' => true,
        'unsubscribes' => true,
        'crm_outbound_events' => true,

        // ── EXCLUES, ET POURQUOI ─────────────────────────────────────────
        'companies' => 'personne MORALE : le standard de l’entreprise, pas la ligne d’une personne. La personne physique de l’entreprise vit dans `contacts`, qui est couverte.',
        'email_inboxes' => 'boîte de synchronisation de l’ÉQUIPE (configuration IMAP du CRM), jamais une adresse de sujet.',
        'users' => 'titulaire d’un compte du CRM : autre registre de traitement (gestion des accès), autre base légale, autre procédure. L’effacer par cette porte détruirait la traçabilité des actions et casserait la chaîne d’audit.',
        'invitations' => 'invitation à un compte du CRM — même registre que `users`.',
        'password_reset_tokens' => 'jeton éphémère d’un compte du CRM — même registre que `users`, et sans valeur pour la personne.',
    ];
}

test('B10-004 — aucune table porteuse d’un identifiant de personne ne reste SANS DÉCISION', function () {
    $enBase = DB::table('information_schema.columns as c')
        ->join('information_schema.tables as t', function ($j) {
            $j->on('t.table_name', '=', 'c.table_name')
                ->where('t.table_schema', 'public')
                // Les partitions d'`audit_logs` répètent la table mère : on ne
                // décide pas douze fois de la même chose.
                ->where('t.table_type', 'BASE TABLE');
        })
        ->where('c.table_schema', 'public')
        ->whereIn('c.column_name', COLONNES_IDENTIFIANTES)
        ->distinct()
        ->pluck('c.table_name')
        ->all();
    sort($enBase);

    // TEMOIN — la garde ne doit pas verdir sur une requête vide ou une base
    // sans migrations : c'est le mode de défaillance le plus discret d'un test
    // qui interroge le schéma.
    expect(count($enBase))->toBeGreaterThanOrEqual(
        20,
        'l’inventaire du schéma est quasi vide : la garde ne mesure rien',
    );
    $this->assertContains('contacts', $enBase, 'l’inventaire ne voit même pas `contacts`');
    $this->assertContains('candidates', $enBase, 'l’inventaire ne voit même pas `candidates`');

    $decisions = decisionsPortabilite();
    $sansDecision = array_values(array_diff($enBase, array_keys($decisions)));

    expect($sansDecision)->toBe(
        [],
        'tables porteuses de données personnelles sans décision écrite : ' . implode(', ', $sansDecision),
    );

    // Et l'inverse : une décision qui ne correspond à aucune table réelle est
    // un reste de refactoring qui donne une fausse impression de couverture.
    $decisionsOrphelines = array_values(array_diff(
        array_keys($decisions),
        $enBase,
        COUVERTES_HORS_INVENTAIRE,
    ));
    expect($decisionsOrphelines)->toBe(
        [],
        'décisions portant sur des tables inexistantes : ' . implode(', ', $decisionsOrphelines),
    );
});

test('B10-004 — chaque table DÉCIDÉE couverte est réellement lue par l’export', function () {
    // La décision ci-dessus est une intention ; ce test la confronte au code.
    // Sans lui, on pourrait cocher une table dans le tableau et ne jamais
    // l'interroger — c'est-à-dire se rassurer sans rien changer.
    $export = file_get_contents(base_path('app/Services/Rgpd/GdprPortabilityService.php'));

    foreach (decisionsPortabilite() as $table => $decision) {
        if ($decision !== true) {
            continue;
        }

        $this->assertStringContainsString(
            "'{$table}'",
            $export,
            "« {$table} » est déclarée couverte, mais l’export ne l’interroge pas",
        );
    }

    // TEMOIN : la recherche sait dire NON. Sans lui, une lecture de fichier
    // ratée (chemin faux, chaîne vide) ferait passer toutes les tables.
    expect(str_contains($export, "'table_qui_nexiste_pas'"))->toBeFalse();
});

test('B10-004 — l export rend les listes d’opposition et de suppression qui CONCERNENT la personne', function () {
    Storage::fake('local');
    $espace = espacePort('listes');

    // Une opposition, une suppression technique, un désabonnement, une ligne
    // « ne pas appeler » : quatre registres distincts, tous porteurs de son
    // adresse ou de son empreinte, aucun exporté avant le 2026-08-20.
    DB::table('opt_out')->insert([
        'email' => null, 'email_hash' => hash('sha256', COURRIEL_EXPORT),
        'scope' => 'business', 'source' => 'gdpr_erasure', 'created_at' => now(),
    ]);
    DB::table('email_suppressions')->insert([
        'email' => null, 'email_hash' => hash('sha256', COURRIEL_EXPORT),
        'scope' => 'business', 'reason' => 'hard_bounce', 'source' => 'esp',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('unsubscribes')->insert([
        'workspace_id' => $espace, 'email' => COURRIEL_EXPORT,
        'source' => 'lien-de-desinscription', 'unsubscribed_at' => now(),
    ]);
    $listeId = DB::table('dnc_lists')->insertGetId([
        'workspace_id' => $espace, 'name' => 'Ne pas appeler', 'created_at' => now(),
    ]);
    DB::table('dnc_entries')->insert([
        'dnc_list_id' => $listeId, 'email' => COURRIEL_EXPORT,
        'phone' => '+33600000022', 'created_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu['oppositions'])->toHaveCount(1);
    expect($contenu['suppressions_techniques'])->toHaveCount(1);
    expect($contenu['desabonnements'])->toHaveCount(1);
    expect($contenu['listes_ne_pas_appeler'])->toHaveCount(1);

    // 🔴 Et l'export ne rend JAMAIS l'empreinte : elle n'apprendrait rien à la
    // personne, et la publier affaiblirait la seule protection qui reste après
    // un effacement.
    $serialise = json_encode($contenu, JSON_THROW_ON_ERROR);
    expect(str_contains((string) $serialise, hash('sha256', COURRIEL_EXPORT)))->toBeFalse();
});

test('B10-004 — TEMOIN : les listes de QUELQU UN D AUTRE ne sortent pas dans l export', function () {
    Storage::fake('local');
    $espace = espacePort('listes-voisine');
    $voisine = 'marie@voisine.test';

    DB::table('opt_out')->insert([
        'email' => null, 'email_hash' => hash('sha256', $voisine),
        'scope' => 'business', 'source' => 'site', 'created_at' => now(),
    ]);
    DB::table('email_suppressions')->insert([
        'email' => null, 'email_hash' => hash('sha256', $voisine),
        'scope' => 'business', 'reason' => 'complaint', 'source' => 'esp',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('unsubscribes')->insert([
        'workspace_id' => $espace, 'email' => $voisine,
        'source' => 'lien', 'unsubscribed_at' => now(),
    ]);
    $listeId = DB::table('dnc_lists')->insertGetId([
        'workspace_id' => $espace, 'name' => 'NPA', 'created_at' => now(),
    ]);
    DB::table('dnc_entries')->insert([
        'dnc_list_id' => $listeId, 'email' => $voisine, 'created_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    // Un export qui déverse les listes de tout le monde n'est pas une
    // portabilité, c'est une violation.
    expect($contenu['oppositions'])->toHaveCount(0);
    expect($contenu['suppressions_techniques'])->toHaveCount(0);
    expect($contenu['desabonnements'])->toHaveCount(0);
    expect($contenu['listes_ne_pas_appeler'])->toHaveCount(0);
});

test('B10-004 — le VERDICT DE DELIVRABILITE porte sur son adresse : il est exporte, et il est efface', function () {
    Storage::fake('local');
    $espace = espacePort('verdict');

    // `email_verification_logs` est le jumeau d'`email_validations` : même
    // adresse, même verdict, deux tables — l'une couverte des deux côtés,
    // l'autre d'aucun (patron A-011).
    DB::table('email_verification_logs')->insert([
        'workspace_id' => $espace, 'email' => COURRIEL_EXPORT,
        'status' => 'valid', 'score' => 92, 'provider' => 'hunter',
        'raw_response' => json_encode(['result' => 'deliverable'], JSON_THROW_ON_ERROR),
        'verified_at' => now(),
    ]);
    DB::table('email_verification_logs')->insert([
        'workspace_id' => $espace, 'email' => 'marie@voisine.test',
        'status' => 'invalid', 'provider' => 'hunter', 'verified_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu['email_verification_logs'])->toHaveCount(1);
    expect($contenu['email_verification_logs'][0]['status'])->toBe('valid');

    // Puis l'effacement : le verdict s'en va avec le reste.
    app(GdprErasureService::class)->erase(COURRIEL_EXPORT);

    expect(DB::table('email_verification_logs')->where('email', COURRIEL_EXPORT)->count())->toBe(0);
    // TEMOIN : la ligne de la voisine, elle, est intacte.
    expect(DB::table('email_verification_logs')->where('email', 'marie@voisine.test')->count())->toBe(1);
});

test('B10-004 — les NOTIFICATIONS internes qui la nomment sont exportees, puisqu elles sont effacees', function () {
    Storage::fake('local');
    $espace = espacePort('notif');
    $utilisateur = (string) Str::uuid();
    DB::table('users')->insert([
        'id' => $utilisateur, 'email' => 'operateur@axion.test', 'name' => 'Operateur',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('notifications')->insert([
        'workspace_id' => $espace, 'user_id' => $utilisateur, 'type' => 'lead',
        'title' => 'Nouveau lead', 'body' => 'Demande de ' . COURRIEL_EXPORT,
        'created_at' => now(),
    ]);
    DB::table('notifications')->insert([
        'workspace_id' => $espace, 'user_id' => $utilisateur, 'type' => 'lead',
        'title' => 'Autre lead', 'body' => 'Demande de marie@voisine.test',
        'created_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu['notifications'])->toHaveCount(1);
    $this->assertStringContainsString('Nouveau lead', json_encode($contenu['notifications'], JSON_THROW_ON_ERROR));

    // 🔴 Le DESTINATAIRE de la notification est un TIERS : son identifiant n'a
    // rien à faire dans l'export d'une autre personne. On ne rend que le
    // contenu qui la concerne.
    expect($contenu['notifications'][0])->not->toHaveKey('user_id');
});

test('B10-004 — les SIGNAUX ENVOYES AU SITE a son sujet sont exportes', function () {
    Storage::fake('local');

    // `crm_outbound_events` porte l'empreinte de son adresse et le signal
    // transmis au site (opposition, effacement). Elle a le droit de savoir que
    // ce signal a été émis, et quand.
    DB::table('crm_outbound_events')->insert([
        'event_type' => 'consent_optout', 'email_hash' => hash('sha256', COURRIEL_EXPORT),
        'scope' => 'business', 'payload' => '{}', 'status' => 'sent',
        'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('crm_outbound_events')->insert([
        'event_type' => 'erasure', 'email_hash' => hash('sha256', 'marie@voisine.test'),
        'scope' => 'business', 'payload' => '{}', 'status' => 'sent',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu['signaux_envoyes_au_site'])->toHaveCount(1);
    expect($contenu['signaux_envoyes_au_site'][0]['event_type'])->toBe('consent_optout');
});

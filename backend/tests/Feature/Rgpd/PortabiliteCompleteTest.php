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

use App\Services\Audit\AuditHashChain;
use App\Services\Rgpd\GdprErasureService;
use App\Services\Rgpd\GdprPortabilityService;
use Illuminate\Database\QueryException;
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
        // B10-004, ce qui en restait (2026-08-21) : les titulaires de compte du
        // CRM et les gens qu'on a invités. `users` est ANONYMISÉE et non
        // supprimée — le catalogue interdit la suppression, cf. le test dédié
        // plus bas qui le mesure au lieu de le supposer.
        'users', 'invitations', 'password_reset_tokens', 'sessions',
    ];

    foreach ($tablesSensibles as $table) {
        // 🔴 ON CHERCHE `DB::table('x')`, PAS `'x'`.
        //
        // La forme précédente — `"'{$table}'"` — était satisfaite par une simple
        // MENTION dans un commentaire. Or ces deux fichiers sont massivement
        // commentés, et chaque nom de table y apparaît en prose : la garde
        // pouvait donc rester verte sur une table que le code n'interroge pas.
        // Le motif ci-dessous ne peut venir que d'un appel réel.
        //
        // `assertStringContainsString` et non `expect()->toContain()` : ce
        // dernier est VARIADIQUE chez Pest, un message y deviendrait une
        // seconde aiguille à trouver.
        $this->assertStringContainsString("DB::table('{$table}')", $effacement, "l’effacement n’interroge pas « {$table} »");
        $this->assertStringContainsString("DB::table('{$table}')", $export, "l’export n’interroge pas « {$table} »");
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
const COUVERTES_HORS_INVENTAIRE = [
    'notifications',
    // B10-004 (2026-08-21) : `sessions` ne déclare ni `email` ni `phone` — elle
    // se rattache à la personne par `user_id`. Elle garde pourtant son IP et son
    // navigateur, c'est-à-dire le résidu le plus direct de l'anonymisation d'un
    // compte. Deuxième démonstration que l'inventaire par colonnes ne voit pas
    // tout.
    'sessions',
];

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

        // ── LES TITULAIRES DE COMPTE, FERMÉS LE 2026-08-21 ───────────────
        //
        // Ces trois lignes portaient jusqu'ici un MOTIF D'EXCLUSION : « autre
        // registre de traitement (gestion des accès), autre base légale, autre
        // procédure ». Les trois affirmations sont exactes ET ne concluent
        // rien : un registre distinct change la base légale et la durée de
        // conservation, il ne suspend pas l'article 17. La phrase qui suivait
        // — « l'effacer détruirait la traçabilité » — était, elle, un argument
        // VALIDE, mais contre la SUPPRESSION seule ; le dépôt sait faire
        // autrement, et le faisait déjà pour `journalists`.
        //
        // `users` est donc ANONYMISÉE puis soft-delete, `invitations` et
        // `password_reset_tokens` supprimées. Les trois sont exportées, sans
        // aucun secret d'authentification. Quatre tests plus bas le mesurent.
        'users' => true,
        'invitations' => true,
        'password_reset_tokens' => true,
        'sessions' => true, // cf. COUVERTES_HORS_INVENTAIRE

        // ── EXCLUES, ET POURQUOI ─────────────────────────────────────────
        'companies' => 'personne MORALE : le standard de l’entreprise, pas la ligne d’une personne. La personne physique de l’entreprise vit dans `contacts`, qui est couverte.',
        'email_inboxes' => 'boîte de synchronisation de l’ÉQUIPE (configuration IMAP du CRM), jamais une adresse de sujet.',
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

        // Motif `DB::table('x')` et non `'x'` : ce dernier était satisfait par
        // une mention en COMMENTAIRE, et ce fichier-là est très commenté.
        $this->assertStringContainsString(
            "DB::table('{$table}')",
            $export,
            "« {$table} » est déclarée couverte, mais l’export ne l’interroge pas",
        );
    }

    // TEMOIN : la recherche sait dire NON. Sans lui, une lecture de fichier
    // ratée (chemin faux, chaîne vide) ferait passer toutes les tables.
    expect(str_contains($export, "DB::table('table_qui_nexiste_pas')"))->toBeFalse();
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

// ─────────────────────────────────────────────────────────────────────────────
// B10-004 — CE QUI EN RESTAIT : LES TITULAIRES DE COMPTE DU CRM
//
// Le 2026-08-20, `users`, `invitations` et `password_reset_tokens` ont été
// EXCLUS du périmètre par un motif écrit — « autre registre de traitement,
// autre base légale, autre procédure ». Les trois affirmations sont exactes et
// ne concluent rien : un registre distinct change la base légale et la durée de
// conservation, il ne suspend pas l'article 17. Ce sont des personnes
// IDENTIFIÉES : les utilisateurs du CRM, et les gens qu'on a invités.
//
// Ce qui, dans ce motif, était un VRAI argument — « l'effacer détruirait la
// traçabilité des actions et casserait la chaîne d'audit » — vise la
// SUPPRESSION, pas l'effacement. Le dépôt sait déjà faire autrement, et le
// faisait à trente lignes de là pour `journalists`.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Crée un compte du CRM. Préfixé `b10004` : les fichiers de test de Pest
 * partagent UN SEUL espace de noms, et deux `compte()` globaux tueraient la
 * campagne entière sans faire rougir aucun fichier joué seul.
 *
 * @param  array<string, mixed>  $extra
 */
function b10004Compte(string $courriel, array $extra = []): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(array_merge([
        'id' => $id,
        'email' => $courriel,
        'name' => 'Titulaire ' . Str::random(5),
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));

    return $id;
}

test('B10-004 — MESURE : le CATALOGUE interdit de SUPPRIMER une ligne users', function () {
    // 🔴 C'est ce test qui JUSTIFIE l'anonymisation. Sans lui, « on anonymise
    // plutôt qu'on ne supprime » serait un goût ; avec lui, c'est un constat.
    // L'énumération vient de `pg_constraint`, jamais d'une liste écrite à la
    // main : une migration qui ajoute une référence à `users` est comptée sans
    // que personne n'y pense.
    $toutes = DB::select(<<<'SQL'
        SELECT c.conname, c.conrelid::regclass::text AS enfant, c.confdeltype
          FROM pg_constraint c
         WHERE c.contype = 'f' AND c.confrelid = 'users'::regclass
    SQL);

    // TEMOIN DE COUVERTURE : si la requête ne voit rien, la conclusion qui suit
    // ne vaut rien. Mesure du 2026-08-21 : 33 contraintes.
    expect(count($toutes))->toBeGreaterThanOrEqual(
        20,
        'le catalogue ne rend presque aucune contrainte : la mesure ne mesure rien',
    );

    // `a` = NO ACTION, `r` = RESTRICT, `n` = SET NULL, `c` = CASCADE.
    $bloquantes = array_values(array_filter(
        $toutes,
        fn (object $c): bool => in_array($c->confdeltype, ['a', 'r'], true),
    ));
    $detachantes = array_map(
        fn (object $c): string => $c->enfant,
        array_filter($toutes, fn (object $c): bool => $c->confdeltype === 'n'),
    );

    expect(count($bloquantes))->toBeGreaterThanOrEqual(
        7,
        'contraintes qui bloquent la suppression : ' . implode(', ', array_map(
            fn (object $c): string => $c->enfant,
            $bloquantes,
        )),
    );

    // Et parmi les `SET NULL`, celle qui coûterait le plus cher : le journal
    // d'audit. Supprimer la ligne détacherait chaque maillon de son auteur.
    $this->assertContains(
        'audit_logs',
        array_values($detachantes),
        'audit_logs.user_id n’est plus en SET NULL : la démonstration est à refaire',
    );

    // LA MESURE ELLE-MEME : on tente la suppression, et Postgres refuse.
    $espace = espacePort('catalogue');
    $idCompte = b10004Compte('titulaire@catalogue.test');
    DB::table('rgpd_requests')->insert([
        'workspace_id' => $espace, 'type' => 'access', 'status' => 'done',
        'subject_email' => 'quelqu.un@ailleurs.test', 'processed_by' => $idCompte,
        'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $refus = null;
    try {
        DB::table('users')->where('id', $idCompte)->delete();
    } catch (QueryException $e) {
        $refus = $e->getMessage();
    }

    expect($refus)->not->toBeNull('la suppression a réussi : le motif d’anonymisation est caduc');
    $this->assertStringContainsString('rgpd_requests_processed_by_fkey', (string) $refus);
});

test('B10-004 — le compte CRM est ANONYMISE, ses secrets detruits, et la chaine d audit garde son auteur', function () {
    Storage::fake('local');
    $idCompte = b10004Compte(COURRIEL_EXPORT, [
        'name' => 'Jeanne Titulaire',
        'password_hash' => '$2y$04$HACHAGEMOTDEPASSETEMOIN',
        'avatar_url' => 'https://exemple.test/jeanne.png',
        'totp_secret' => 'SECRETTOTPTEMOIN',
        'totp_recovery_codes' => 'CODESDESECOURSTEMOIN',
        'totp_enabled_at' => now(),
        'remember_token' => 'JETONSOUVENIRTEMOIN',
        'last_login_ip' => '192.0.2.44',
        'last_login_user_agent' => 'Mozilla/5.0 (Jeanne)',
    ]);
    $idVoisine = b10004Compte('marie@voisine.test', [
        'name' => 'Marie Voisine',
        'password_hash' => '$2y$04$HACHAGEVOISINE',
        'totp_secret' => 'SECRETVOISINE',
        'last_login_ip' => '192.0.2.99',
    ]);

    // Une action journalisée, attribuée à elle. C'est ce lien-là qu'une
    // suppression aurait détaché, sans bruit et sans retour possible.
    app(AuditHashChain::class)->record([
        'workspace_id' => null, 'user_id' => $idCompte,
        'method' => 'POST', 'path' => '/api/companies', 'status' => 201,
        'ip' => null, 'user_agent' => null, 'payload_hash' => hash('sha256', 'peu importe'),
    ]);

    app(GdprErasureService::class)->erase(COURRIEL_EXPORT);

    $apres = DB::table('users')->where('id', $idCompte)->first();

    // 1. LA LIGNE EXISTE ENCORE — c'est tout l'objet de l'anonymisation.
    expect($apres)->not->toBeNull('la ligne `users` a disparu : la chaîne d’audit est orpheline');

    // 2. ELLE NE LA DESIGNE PLUS.
    expect((string) $apres->email)->not->toBe(COURRIEL_EXPORT);
    $this->assertStringContainsString('@compte-efface.invalid', (string) $apres->email);
    expect($apres->name)->not->toBe('Jeanne Titulaire');

    // 3. LES SECRETS SONT DETRUITS, UN PAR UN. Un compte anonymisé dont le
    //    hachage de mot de passe survit reste un compte réutilisable.
    expect($apres->password_hash)->toBeNull();
    expect($apres->totp_secret)->toBeNull();
    expect($apres->totp_recovery_codes)->toBeNull();
    expect($apres->remember_token)->toBeNull();
    expect($apres->avatar_url)->toBeNull();
    expect($apres->last_login_ip)->toBeNull();
    expect($apres->last_login_user_agent)->toBeNull();

    // 4. LE COMPTE EST FERME. `users` porte `SoftDeletes` depuis B10-016 et
    //    `config/auth.php` rend `deleted_at` opposable : sans cette pose, la
    //    ligne anonymisée resterait un compte ouvert.
    expect($apres->deleted_at)->not->toBeNull();

    // 5. LA CHAINE D'AUDIT GARDE SON AUTEUR.
    expect(DB::table('audit_logs')->where('user_id', $idCompte)->count())
        ->toBeGreaterThanOrEqual(1, 'le maillon d’audit a perdu son auteur');

    // 6. TEMOIN : la voisine est intacte — l'effacement n'est pas une purge.
    $voisine = DB::table('users')->where('id', $idVoisine)->first();
    expect((string) $voisine->email)->toBe('marie@voisine.test');
    expect($voisine->password_hash)->toBe('$2y$04$HACHAGEVOISINE');
    expect($voisine->totp_secret)->toBe('SECRETVOISINE');
    expect($voisine->deleted_at)->toBeNull();
});

test('B10-004 — les SESSIONS ouvertes du compte efface sont fermees, avec leur IP et leur navigateur', function () {
    Storage::fake('local');
    $espace = espacePort('session');
    $idCompte = b10004Compte(COURRIEL_EXPORT);
    $idVoisine = b10004Compte('marie@voisine.test');

    DB::table('sessions')->insert([
        'id' => 'jeton-session-' . Str::random(20), 'user_id' => $idCompte,
        'workspace_id' => $espace, 'ip_address' => '192.0.2.44',
        'user_agent' => 'Mozilla/5.0 (Jeanne)', 'payload' => 'a:0:{}',
        'last_activity' => time(),
    ]);
    DB::table('sessions')->insert([
        'id' => 'jeton-session-' . Str::random(20), 'user_id' => $idVoisine,
        'workspace_id' => $espace, 'ip_address' => '192.0.2.99',
        'user_agent' => 'Mozilla/5.0 (Marie)', 'payload' => 'a:0:{}',
        'last_activity' => time(),
    ]);

    // Avant : l'export la lui montre, puisque l'effacement la supprime.
    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));
    expect($contenu['sessions_ouvertes'])->toHaveCount(1);
    expect((string) $contenu['sessions_ouvertes'][0]['ip_address'])->toBe('192.0.2.44');

    app(GdprErasureService::class)->erase(COURRIEL_EXPORT);

    // 🔴 La clé étrangère `sessions.user_id` est en CASCADE — et elle ne se
    // déclenche JAMAIS ici, puisqu'on ne supprime pas la ligne `users`. Sans
    // suppression explicite, l'IP et le navigateur survivaient à l'effacement.
    expect(DB::table('sessions')->where('user_id', $idCompte)->count())->toBe(0);
    // TEMOIN : la session de la voisine tient.
    expect(DB::table('sessions')->where('user_id', $idVoisine)->count())->toBe(1);
});

test('B10-004 — les INVITATIONS envoyees a son adresse sont exportees, puis effacees', function () {
    Storage::fake('local');
    $espace = espacePort('invit');
    $idHote = b10004Compte('hote@axion.test');

    DB::table('invitations')->insert([
        'workspace_id' => $espace, 'email' => COURRIEL_EXPORT, 'role_slug' => 'operator',
        'invited_by' => $idHote, 'token_hash' => 'empreinte-invitation-' . Str::random(20),
        'expires_at' => now()->addDays(7), 'created_at' => now(),
    ]);
    DB::table('invitations')->insert([
        'workspace_id' => $espace, 'email' => 'marie@voisine.test', 'role_slug' => 'viewer',
        'invited_by' => $idHote, 'token_hash' => 'empreinte-voisine-' . Str::random(20),
        'expires_at' => now()->addDays(7), 'created_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu['invitations_recues'])->toHaveCount(1);
    expect($contenu['invitations_recues'][0]['role_slug'])->toBe('operator');
    // Le `token_hash` ouvre un compte, et `invited_by` désigne un TIERS :
    // ni l'un ni l'autre ne sortent.
    expect($contenu['invitations_recues'][0])->not->toHaveKey('token_hash');
    expect($contenu['invitations_recues'][0])->not->toHaveKey('invited_by');

    app(GdprErasureService::class)->erase(COURRIEL_EXPORT);

    expect(DB::table('invitations')->where('email', COURRIEL_EXPORT)->count())->toBe(0);
    // TEMOIN : l'invitation de la voisine tient.
    expect(DB::table('invitations')->where('email', 'marie@voisine.test')->count())->toBe(1);
});

test('B10-004 — le jeton de REINITIALISATION est efface, et l export n en rend que la date', function () {
    Storage::fake('local');

    DB::table('password_reset_tokens')->insert([
        'email' => COURRIEL_EXPORT, 'token' => 'JETONREINITIALISATIONTEMOIN', 'created_at' => now(),
    ]);
    DB::table('password_reset_tokens')->insert([
        'email' => 'marie@voisine.test', 'token' => 'JETONVOISINE', 'created_at' => now(),
    ]);

    $contenu = contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT));

    expect($contenu['reinitialisations_mot_de_passe'])->toHaveCount(1);
    expect($contenu['reinitialisations_mot_de_passe'][0])->not->toHaveKey('token');

    app(GdprErasureService::class)->erase(COURRIEL_EXPORT);

    expect(DB::table('password_reset_tokens')->where('email', COURRIEL_EXPORT)->count())->toBe(0);
    // TEMOIN : la ligne de la voisine tient.
    expect(DB::table('password_reset_tokens')->where('email', 'marie@voisine.test')->count())->toBe(1);
});

test('B10-004 — l export ne rend AUCUN secret d authentification', function () {
    Storage::fake('local');
    $espace = espacePort('secrets');
    $idHote = b10004Compte('hote@secrets.test');
    $idCompte = b10004Compte(COURRIEL_EXPORT, [
        'password_hash' => '$2y$04$HACHAGEMOTDEPASSETEMOIN',
        'totp_secret' => 'SECRETTOTPTEMOIN',
        'totp_recovery_codes' => 'CODESDESECOURSTEMOIN',
        'remember_token' => 'JETONSOUVENIRTEMOIN',
    ]);
    DB::table('invitations')->insert([
        'workspace_id' => $espace, 'email' => COURRIEL_EXPORT, 'role_slug' => 'operator',
        'invited_by' => $idHote, 'token_hash' => 'EMPREINTEINVITATIONTEMOIN',
        'expires_at' => now()->addDays(7), 'created_at' => now(),
    ]);
    DB::table('password_reset_tokens')->insert([
        'email' => COURRIEL_EXPORT, 'token' => 'JETONREINITIALISATIONTEMOIN', 'created_at' => now(),
    ]);
    DB::table('sessions')->insert([
        'id' => 'IDENTIFIANTDESESSIONTEMOIN', 'user_id' => $idCompte,
        'workspace_id' => $espace, 'ip_address' => '192.0.2.44', 'user_agent' => 'Mozilla/5.0',
        'payload' => 'a:0:{}', 'last_activity' => time(),
    ]);

    $serialise = json_encode(
        contenuExporte(app(GdprPortabilityService::class)->export(COURRIEL_EXPORT)),
        JSON_THROW_ON_ERROR,
    );

    // Un secret par assertion : c'est la seule façon de savoir LEQUEL fuit le
    // jour où l'une d'elles rougit.
    foreach ([
        '$2y$04$HACHAGEMOTDEPASSETEMOIN',
        'SECRETTOTPTEMOIN',
        'CODESDESECOURSTEMOIN',
        'JETONSOUVENIRTEMOIN',
        'EMPREINTEINVITATIONTEMOIN',
        'JETONREINITIALISATIONTEMOIN',
        'IDENTIFIANTDESESSIONTEMOIN',
    ] as $secret) {
        $this->assertStringNotContainsString(
            $secret,
            $serialise,
            "l’export livre un secret d’authentification : « {$secret} »",
        );
    }

    // TEMOIN DE COUVERTURE : l'export n'est pas vide, et il la concerne bien.
    // Sans ce contrôle, un export raté passerait les sept assertions ci-dessus.
    $this->assertStringContainsString(COURRIEL_EXPORT, $serialise);
    $this->assertStringContainsString('comptes_crm', $serialise);
});

test('B10-004 — COMPTE FIGE : la PII en texte libre et en JSONB echappe a l inventaire par colonnes', function () {
    // ⚠️ CE TEST NE FERME RIEN. Il COMPTE.
    //
    // Le constat d'origine parlait de « ~40 tables porteuses de données
    // personnelles » ; l'inventaire par NOMS DE COLONNES n'en voit que 21. La
    // différence n'est pas une erreur du constat : c'est la PII qui vit dans du
    // texte libre et dans du JSONB sans jamais déclarer de colonne `email`.
    // `activities.payload` en est la preuve — c'est là que le téléphone
    // survivait à l'effacement jusqu'au 2026-08-19 (B15-006).
    //
    // Ce qu'on ne sait pas prouver, on ne le déclare pas fermé : on fige le
    // chiffre du jour, pour qu'une migration qui en ajoute doive passer ici.
    $colonnes = DB::table('information_schema.columns as c')
        ->join('information_schema.tables as t', function ($j) {
            $j->on('t.table_name', '=', 'c.table_name')
                ->where('t.table_schema', 'public')
                ->where('t.table_type', 'BASE TABLE');
        })
        ->where('c.table_schema', 'public')
        ->whereIn('c.data_type', ['jsonb', 'json'])
        ->distinct()
        ->get(['c.table_name', 'c.column_name']);

    // TEMOIN DE COUVERTURE — le mode de défaillance le plus discret d'une garde
    // qui interroge le schéma : ne rien voir, et verdir.
    expect($colonnes->count())->toBeGreaterThanOrEqual(
        30,
        'le balayage du schéma ne rend presque rien : cette garde ne compte rien',
    );
    $this->assertContains(
        'activities.payload',
        $colonnes->map(fn (object $c): string => $c->table_name . '.' . $c->column_name)->all(),
        'le balayage ne voit même pas `activities.payload`, le cas fondateur',
    );

    // Les tables que l'export DÉCLARE couvrir : leur JSONB part avec la ligne.
    $couvertes = array_keys(array_filter(decisionsPortabilite(), fn ($d): bool => $d === true));
    $horsPortee = $colonnes
        ->reject(fn (object $c): bool => in_array($c->table_name, $couvertes, true))
        ->map(fn (object $c): string => $c->table_name . '.' . $c->column_name)
        ->sort()
        ->values()
        ->all();

    // Mesure du 2026-08-21 sur `axion_crm_test_lot8` : 40 colonnes JSON/JSONB,
    // dont 12 sur des tables couvertes et 28 sur des tables qu'aucun des deux
    // services RGPD n'interroge — `scraper_runs.request_payload`,
    // `business_events.context`, `linkedin_profiles_cache.snapshot`,
    // `companies.metadata`… Aucune d'elles n'est balayée par adresse.
    expect($colonnes->count())->toBe(
        40,
        'le nombre de colonnes JSON/JSONB a changé : ré-arbitrer, puis mettre ce chiffre à jour',
    );
    expect(count($horsPortee))->toBe(
        28,
        'colonnes JSON/JSONB hors de portée des deux services RGPD : ' . implode(', ', $horsPortee),
    );
});

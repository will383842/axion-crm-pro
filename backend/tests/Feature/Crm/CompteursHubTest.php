<?php

use App\Crm\Console\CompteursHub;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * COMPTEURS DU HUB — étape 1a, première pièce (§27).
 *
 * Le défaut corrigé est MESURÉ, pas supposé
 * (`_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md` §4 n°1, rejoué le
 * 2026-08-19) : `GROUP BY relation_type, lifecycle_stage` était un balayage
 * séquentiel complet de `companies` — 337 à 476 ms sur 300 000 fiches, donc de
 * l'ordre de 3 s sur les 4,29 M de la production, à chaque rendu de navigation.
 *
 * Quatre gardes, chacune vue ROUGE avant d'être verte (§28.3 — « une garde ne
 * vaut que si elle rougit ») ; les sorties rouges sont consignées dans
 * `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md` §2.
 *
 *   1. PLAN       — la requête est servie par `idx_companies_ws_counts`, en
 *                   `Index Only Scan`, et ne balaye pas la table ;
 *   2. CACHE      — deux affichages de suite ne font pas deux fois le calcul ;
 *   3. ÉTANCHÉITÉ — le cache ne fuit JAMAIS d'un workspace à l'autre ;
 *   4. FRAÎCHEUR  — une action de masse qui déplace des fiches d'une étape à
 *                   l'autre fait bouger la pastille TOUT DE SUITE, sans
 *                   attendre la fin de la fenêtre de fraîcheur.
 */
beforeEach(function () {
    config(['crm.console_v2' => true]);
    Cache::flush();

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-compteurs',
        'name' => 'Compteurs',
        'settings' => [],
    ]);

    $this->user = compteursUser($this->workspace->id);
    $this->actingAs($this->user);
});

// ── Fabriques (locales à ce fichier : un test ne dépend pas d'un autre) ──────

function compteursUser(string $workspaceId, string $email = 'compteurs@example.invalid'): User
{
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Opérateur',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id,
        'workspace_id' => $workspaceId,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    return $user;
}

/** @param  array<string, mixed>  $overrides */
function compteursCompany(string $workspaceId, string $siren, array $overrides = []): int
{
    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => $siren,
        'denomination' => 'ZZ COMPTEURS ' . $siren,
        'discovery_source' => 'site',
        'quality_score' => 0,
        'signals' => '{}',
        'metadata' => '{}',
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Compte les requêtes SQL d'agrégat qui lisent `companies` pendant l'exécution
 * de `$travail`. C'est la seule façon honnête de prouver qu'un cache sert : un
 * chronomètre sur une base de test vide ne prouverait rien du tout.
 */
function requetesDeComptage(callable $travail): int
{
    $vues = 0;

    DB::listen(function ($requete) use (&$vues): void {
        if (stripos($requete->sql, 'from "companies"') !== false && stripos($requete->sql, 'count(*)') !== false) {
            $vues++;
        }
    });

    $travail();

    return $vues;
}

// ── 1. PLAN — l'index couvrant est bien celui qui sert ───────────────────────

test('les compteurs sont servis par un index couvrant, jamais par un balayage', function () {
    compteursCompany($this->workspace->id, '910000001', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    compteursCompany($this->workspace->id, '910000002', ['relation_type' => 'presse_media', 'lifecycle_stage' => 'qualifie']);

    // ── CE QUE CE TEST MESURE, ET POURQUOI IL NE MESURE PLUS LE PLAN DE
    //    `companies` DIRECTEMENT ────────────────────────────────────────────
    //
    // 🔴 Cette garde a été VERTE PAR CHANCE jusqu'au 2026-08-21. Elle exigeait
    // `Index Only Scan using idx_companies_ws_counts` sur la vraie table, et elle
    // a rougi deux fois en CI sur des commits qui ne touchaient NI `companies`,
    // NI ses index, NI ce fichier.
    //
    // Mesures du 2026-08-21 (psql, sur le banc — la même requête à chaque fois) :
    //
    //   table propre, 2 lignes, `ANALYZE` .......... Index Only Scan  ✅
    //   table BALLONNÉE, 2 lignes, `ANALYZE` ....... Sort + Bitmap    ❌
    //   table ballonnée, lignes VALIDÉES + VACUUM ... Sort + Bitmap    ❌
    //   réplique vierge, 600 lignes fraîches ........ Bitmap Heap Scan ❌
    //
    // Deux causes, et AUCUNE des deux n'est à la portée du test :
    //
    //  1. `RefreshDatabase` annule les DONNÉES entre deux tests, pas l'ÉTAT
    //     PHYSIQUE. Les tuples morts et le nombre de pages laissés par toute la
    //     suite restent, et c'est sur eux que le planificateur raisonne.
    //  2. Un `Index Only Scan` exige que la carte de visibilité déclare les pages
    //     « toutes visibles ». Seul `VACUUM` la met à jour, il ne peut pas tourner
    //     dans une transaction, et des lignes écrites par une transaction NON
    //     VALIDÉE ne peuvent de toute façon jamais y être déclarées.
    //
    // Autrement dit : dans une suite transactionnelle, la forme du plan sur
    // `companies` n'est pas une propriété du schéma, c'est un accident de ce qui
    // a tourné avant. *Une garde dont le verdict dépend de l'ordre des tests ne
    // prouve rien — elle finit par être désarmée comme un test capricieux.*
    //
    // On mesure donc la même chose en deux temps, et les deux sont déterministes.

    // ── 1. LE CONTRAT : l'index couvrant existe sur `companies`, et il est VALIDE
    //
    // C'est la propriété dont la production dépend, et elle se lit sans passer par
    // le planificateur. `indisvalid` n'est pas décoratif : un `CREATE INDEX
    // CONCURRENTLY` interrompu laisse un index qui EXISTE et que PostgreSQL
    // n'utilisera jamais — exactement le piège dont se protège la migration
    // `2026_08_19_000001`.
    $index = DB::selectOne(
        'SELECT pg_get_indexdef(i.indexrelid) AS definition, i.indisvalid
           FROM pg_index i
           JOIN pg_class c ON c.oid = i.indexrelid
          WHERE c.relname = ?',
        ['idx_companies_ws_counts'],
    );

    expect($index)->not->toBeNull(
        'L index couvrant `idx_companies_ws_counts` a DISPARU de `companies`. Le calcul des '
        . 'compteurs du hub redevient un balayage complet : 337 a 476 ms sur 300 000 fiches '
        . 'mesurees le 2026-08-19, donc de l ordre de 3 s sur les 4,29 M de la production, a '
        . 'CHAQUE rendu de navigation. Retablir la migration `2026_08_19_000001`.',
    );
    expect((bool) $index->indisvalid)->toBeTrue(
        'L index `idx_companies_ws_counts` existe mais est INVALIDE : PostgreSQL ne s en '
        . 'servira jamais, et rien ne le signale. Rejouer la migration `2026_08_19_000001`, '
        . 'qui sait nettoyer un `CONCURRENTLY` interrompu.',
    );

    // ⚠️ `str_contains(...)->toBeTrue($message)` et NON `toContain($aiguille, $message)` :
    // `toContain` est VARIADIQUE dans Pest, le message y deviendrait une seconde
    // aiguille cherchee dans le texte. Le piege a deja ete paye une fois dans
    // cette campagne (garde `AucunMessageDansToContain`).
    expect(str_contains((string) $index->definition, '(workspace_id, relation_type, lifecycle_stage)'))
        ->toBeTrue('Les colonnes de `idx_companies_ws_counts` ont change : ' . $index->definition);

    // Le PARTIEL compte autant que les colonnes : sans `WHERE deleted_at IS NULL`,
    // la condition redevient un `Filter` applique APRES lecture, et l'index cesse
    // de couvrir la requete.
    expect(str_contains((string) $index->definition, 'WHERE (deleted_at IS NULL)'))
        ->toBeTrue('`idx_companies_ws_counts` n est plus PARTIEL : ' . $index->definition);

    // ── 2. LE COMPORTEMENT, sur une RÉPLIQUE que ce test contrôle entièrement ──
    //
    // `LIKE companies INCLUDING INDEXES` : la réplique hérite du schéma RÉEL et de
    // ses index réels. Si quelqu'un retire l'index couvrant de `companies`, la
    // réplique ne l'a pas non plus et ce contrôle rougit — la preuve reste
    // accrochée au vrai schéma, elle ne mesure pas une maquette.
    //
    // Mais la réplique est créée ICI, remplie ICI, et meurt avec la transaction :
    // aucun test voisin ne peut la ballonner. Son état physique est le même à
    // chaque passage, donc le plan aussi (vérifié : trois passages, coûts
    // identiques au centième).
    DB::statement('CREATE TEMP TABLE compteurs_replique (LIKE companies INCLUDING INDEXES INCLUDING DEFAULTS)');

    $lot = [];
    for ($i = 0; $i < 600; $i++) {
        $lot[] = [
            'workspace_id' => $this->workspace->id,
            'siren' => str_pad((string) (920000000 + $i), 9, '0'),
            'denomination' => 'ZZ REPLIQUE ' . $i,
            'discovery_source' => 'site',
            'quality_score' => 0,
            'signals' => '{}',
            'metadata' => '{}',
            'relation_type' => $i % 2 === 0 ? 'prospect' : 'client',
            'lifecycle_stage' => $i % 3 === 0 ? 'nouveau' : 'qualifie',
            'legal_basis' => 'legitimate_interest_b2b',
            'field_origins' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    foreach (array_chunk($lot, 200) as $paquet) {
        DB::table('compteurs_replique')->insert($paquet);
    }
    DB::statement('ANALYZE compteurs_replique');

    // `LIKE` renomme les index : on retrouve le nôtre par sa DÉFINITION, pas par
    // un nom deviné — un nom deviné qui ne correspond à rien passerait au vert.
    $couvrant = DB::selectOne(
        "SELECT indexname
           FROM pg_indexes
          WHERE tablename = 'compteurs_replique'
            AND indexdef LIKE '%(workspace_id, relation_type, lifecycle_stage)%'
            AND indexdef LIKE '%deleted_at IS NULL%'",
    );

    expect($couvrant)->not->toBeNull(
        'La replique n a herite d AUCUN index couvrant : `companies` n en porte donc plus. '
        . 'Voir le message du controle 1 ci-dessus.',
    );

    // ⚠️ `SET LOCAL`, et pas `SET`. La connexion est PARTAGÉE entre les tests d'un
    // même processus : un `SET` que le test n'atteint pas à cause d'une assertion
    // rouge resterait posé pour TOUS les tests suivants, et fausserait leurs
    // plans. `SET LOCAL` meurt avec la transaction, échec compris.
    //
    // Pourquoi couper les deux : on ne demande pas au planificateur ce qui est le
    // moins cher sur 600 lignes — sur si peu, tout est bon marché. On lui pose la
    // seule question qui vaudra à 4,29 M de lignes : « parmi les index, y en
    // a-t-il un qui COUVRE cette requête ? ».
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_bitmapscan = off');

    $planReplique = collect(DB::select(
        'EXPLAIN SELECT relation_type, lifecycle_stage, count(*) AS total
           FROM compteurs_replique
          WHERE workspace_id = ? AND deleted_at IS NULL
          GROUP BY relation_type, lifecycle_stage',
        [$this->workspace->id],
    ))->map(static fn ($ligne): string => (string) reset($ligne))->implode(PHP_EOL);

    expect(str_contains($planReplique, 'Index Only Scan using ' . $couvrant->indexname))->toBeTrue(
        "L index couvrant existe mais NE COUVRE PAS cette requete : le plan doit encore aller\n"
        . "chercher des colonnes dans la table. Verifier que les colonnes lues par\n"
        . "`CompteursHub` sont bien celles de l index.\n\nPlan obtenu :\n" . $planReplique,
    );

    // ── 3. TÉMOIN NÉGATIF : ce contrôle SAIT-il rougir ? ───────────────────
    //
    // Sans lui, les assertions ci-dessus pourraient passer pour de mauvaises
    // raisons. On retire l'index de la réplique — la vraie table n'est pas
    // touchée — et on exige que l'« Index Only Scan » disparaisse. Mesuré le
    // 2026-08-21 : il retombe sur `Index Scan` + `Filter: (deleted_at IS NULL)`.
    DB::statement('DROP INDEX ' . $couvrant->indexname);

    $planAmpute = collect(DB::select(
        'EXPLAIN SELECT relation_type, lifecycle_stage, count(*) AS total
           FROM compteurs_replique
          WHERE workspace_id = ? AND deleted_at IS NULL
          GROUP BY relation_type, lifecycle_stage',
        [$this->workspace->id],
    ))->map(static fn ($ligne): string => (string) reset($ligne))->implode(PHP_EOL);

    expect(str_contains($planAmpute, 'Index Only Scan'))->toBeFalse(
        "Le detecteur est AVEUGLE : la requete est encore servie en `Index Only Scan` alors\n"
        . "qu on vient de retirer le seul index couvrant. Le controle 2 ne prouve donc rien.\n\n"
        . "Plan obtenu :\n" . $planAmpute,
    );

    // ── 4. LA VRAIE TABLE ne retombe pas sur un balayage complet ────────────
    //
    // Faible, et volontairement le seul contrôle gardé sur `companies` : c'est le
    // seul énoncé qui reste VRAI quel que soit l'état physique de la table. Il
    // attrape le jour où plus AUCUN index ne sert `workspace_id`.
    $planReel = collect(DB::select(
        'EXPLAIN SELECT relation_type, lifecycle_stage, count(*) AS total
           FROM companies
          WHERE workspace_id = ? AND deleted_at IS NULL
          GROUP BY relation_type, lifecycle_stage',
        [$this->workspace->id],
    ))->map(static fn ($ligne): string => (string) reset($ligne))->implode(PHP_EOL);

    expect(str_contains($planReel, 'Seq Scan on companies'))->toBeFalse(
        "Plus aucun index ne sert `workspace_id` sur `companies`.\nPlan obtenu :\n" . $planReel,
    );
});

// ── 2. CACHE — le calcul sort du chemin d'affichage ──────────────────────────

test('deux affichages de suite ne recalculent pas les compteurs', function () {
    compteursCompany($this->workspace->id, '910000010', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);

    $premier = requetesDeComptage(function (): void {
        $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk()->assertJsonPath('total', 1);
    });

    $second = requetesDeComptage(function (): void {
        $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk()->assertJsonPath('total', 1);
    });

    expect($premier)->toBe(1, 'le premier affichage calcule');
    expect($second)->toBe(0, 'le second est servi par le cache — sinon le balayage revient à chaque rendu');
});

test('la réponse dit à quand les chiffres remontent', function () {
    compteursCompany($this->workspace->id, '910000011');

    $reponse = $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk();

    // Un compteur en cache qui se présente comme instantané est un mensonge
    // d'interface : l'écran doit pouvoir écrire « chiffres arrêtés à … ».
    expect($reponse->json('computed_at'))->toBeString();
    expect($reponse->json('fresh_for_seconds'))->toBe(CompteursHub::FRAIS_SECONDES);
    // §29 n°16 : tout horodatage circule en temps universel.
    expect($reponse->json('computed_at'))->toEndWith('Z');
});

// ── 3. ÉTANCHÉITÉ — un compteur n'appartient qu'à son workspace ─────────────

test('le cache des compteurs ne fuit pas vers un autre workspace', function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-compteurs-autre',
        'name' => 'Autre',
        'settings' => [],
    ]);

    compteursCompany($this->workspace->id, '910000020', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    compteursCompany($autre->id, '910000021', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    compteursCompany($autre->id, '910000022', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);

    // Le premier passage remplit le cache pour le workspace courant.
    $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk()->assertJsonPath('total', 1);

    // L'autre workspace ne doit RIEN recevoir de ce cache — une clé sans
    // identifiant de workspace lui servirait « 1 » au lieu de « 2 ».
    $utilisateurAutre = compteursUser($autre->id, 'compteurs-autre@example.invalid');
    $this->actingAs($utilisateurAutre)
        ->getJson('/api/v1/crm/contacts-hub/counts')
        ->assertOk()
        ->assertJsonPath('total', 2);

    // Et dans l'autre sens : le premier workspace garde SES chiffres.
    $this->actingAs($this->user)
        ->getJson('/api/v1/crm/contacts-hub/counts')
        ->assertOk()
        ->assertJsonPath('total', 1);
});

// ── 4. FRAÎCHEUR — le produit ne contredit pas ce qu'on vient de faire ──────

test('une action de masse fait bouger la pastille tout de suite', function () {
    $id = compteursCompany($this->workspace->id, '910000030', ['relation_type' => 'client', 'lifecycle_stage' => 'nouveau']);

    $this->getJson('/api/v1/crm/contacts-hub/counts')
        ->assertOk()
        ->assertJsonPath('by_lifecycle_stage.nouveau', 1)
        ->assertJsonPath('by_lifecycle_stage.qualifie', 0);

    $this->postJson('/api/v1/crm/bulk', [
        'entity' => 'company',
        'ids' => [$id],
        'action' => 'set_lifecycle',
        'params' => ['stage' => 'qualifie'],
    ])->assertOk();

    // Sans l'oubli du cache après COMMIT, l'écran afficherait encore
    // « nouveau : 1 » pendant cinq minutes — le produit contredirait le geste
    // que l'opérateur vient de faire.
    $this->getJson('/api/v1/crm/contacts-hub/counts')
        ->assertOk()
        ->assertJsonPath('by_lifecycle_stage.nouveau', 0)
        ->assertJsonPath('by_lifecycle_stage.qualifie', 1);
});

// ── 5. RLS — le calcul ne dépend pas de la requête HTTP qui l'a déclenché ────

test('le calcul pose lui-même le contexte de workspace', function () {
    compteursCompany($this->workspace->id, '910000040', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);

    // On se place dans la situation du rafraîchissement DIFFÉRÉ de
    // `Cache::flexible` : il s'exécute après la réponse, donc après le
    // `terminate()` du middleware `SetCurrentWorkspace`, qui a retiré
    // `app.current_workspace_id`. `companies` porte une policy RLS en FORCE :
    // sans contexte, le rôle applicatif voit ZÉRO ligne et le cache figerait
    // « total : 0 » pour une heure, sans erreur nulle part.
    WorkspaceContext::clear();
    expect(WorkspaceContext::current())->toBeNull();

    $contextePendantLaRequete = 'jamais observé';

    DB::listen(function ($requete) use (&$contextePendantLaRequete): void {
        if (stripos($requete->sql, 'from "companies"') !== false && stripos($requete->sql, 'count(*)') !== false) {
            $contextePendantLaRequete = WorkspaceContext::current();
        }
    });

    $compteurs = CompteursHub::calculer($this->workspace->id);

    expect($contextePendantLaRequete)->toBe($this->workspace->id);
    expect($compteurs['total'])->toBe(1);

    // Et l'état d'avant est rendu : le calcul ne laisse pas de contexte derrière
    // lui — un contexte résiduel serait pire que pas de contexte du tout.
    expect(WorkspaceContext::current())->toBeNull();
});

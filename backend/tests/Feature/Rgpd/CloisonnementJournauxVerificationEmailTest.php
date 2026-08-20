<?php

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * 🔴 A07-002 — `email_verification_logs` RENDAIT LES LIGNES DES DEUX ESPACES.
 *
 * La table porte des ADRESSES E-MAIL et la réponse brute du prestataire de
 * vérification (`raw_response` JSONB, Hunter.io). Sans contexte de workspace,
 * le rôle applicatif y voyait TOUT, tous espaces confondus.
 *
 * ── La cause, nommée ────────────────────────────────────────────────────────
 *
 * Deux policies coexistaient, et Postgres combine les policies PERMISSIVES par
 * OU logique — il suffit qu'UNE seule laisse passer :
 *
 *   1. `email_verif_workspace_isolation` (migration 2026_05_19_000001) :
 *          workspace_id::text = COALESCE(
 *              NULLIF(current_setting('app.current_workspace_id', true), ''),
 *              workspace_id::text)
 *      Sans contexte, le COALESCE retombe sur `workspace_id::text` et le
 *      prédicat devient `workspace_id::text = workspace_id::text` — TOUJOURS
 *      VRAI. C'est le repli permissif « pas de contexte ⇒ je vois tout ».
 *
 *   2. `email_verification_logs_workspace_isolation` (durcissement L0,
 *      2026_08_14_000001) : stricte, celle qu'on veut.
 *
 * La migration de durcissement fait pourtant bien un
 * `DROP POLICY IF EXISTS <table>_workspace_isolation`. Elle a raté la première
 * parce que son nom est RACCOURCI : `email_verif_…` et non
 * `email_verification_logs_…`. Le DROP n'a rien trouvé, la policy permissive a
 * survécu, et la stricte est venue s'ajouter À CÔTÉ au lieu de la remplacer.
 *
 * ── Balayage des sites jumeaux (patron A-011) ───────────────────────────────
 *
 * MESURÉ le 2026-08-20 sur `axion_crm_test_lot7`, schéma `public` : sur toutes
 * les tables portant `workspace_id`, `email_verif_workspace_isolation` est la
 * SEULE policy dont le nom s'écarte du canon `<table>_workspace_isolation`.
 * Le défaut n'a donc qu'un site, et le test « aucune policy hors canon » plus
 * bas empêche qu'il s'en crée un deuxième en silence.
 *
 * ── Correctif ───────────────────────────────────────────────────────────────
 *
 * `database/migrations/2026_08_20_100000_supprimer_policy_permissive_survivante_email_verification_logs.php`
 *
 * ⚠️ Toutes les lectures passent par `pgsql_app` (rôle NON-PROPRIÉTAIRE
 * `axion_app`) : c'est la SEULE connexion sur laquelle la RLS mord. Le rôle par
 * défaut `axion` est SUPERUSER + BYPASSRLS ; mesurer depuis lui ne prouverait
 * rien (cf. F36-007, figé par `tests/Feature/Rgpd/RolePorteurDeLaRlsTest.php`).
 */
function jveOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function jveApp(): Connection
{
    return DB::connection('pgsql_app');
}

/** Pose (ou retire, si `null`) le contexte workspace sur la connexion applicative. */
function jvePoserContexte(?string $workspaceId): void
{
    jveApp()->select('SELECT set_config(?, ?, false)', [
        'app.current_workspace_id',
        $workspaceId ?? '',
    ]);
}

/**
 * Sème deux espaces et UN journal de vérification dans chacun.
 *
 * ⚠️ L'écriture passe par `pgsql_owner`, en AUTO-COMMIT : la transaction de
 * `RefreshDatabase` enveloppe la connexion PAR DÉFAUT, et ces lignes seraient
 * invisibles depuis `pgsql_app`, qui est une autre session. Corollaire : le
 * nettoyage n'est pas une politesse, c'est la seule chose qui empêche ce
 * fichier de laisser deux espaces commités pour toute la suite (le piège déjà
 * constaté dans `RlsTest` le 2026-08-16).
 *
 * @return array{0: string, 1: string}
 */
function jveSemerDeuxEspaces(): array
{
    $wsA = (string) Str::uuid();
    $wsB = (string) Str::uuid();

    foreach ([[$wsA, 'A'], [$wsB, 'B']] as [$id, $lettre]) {
        jveOwner()->table('workspaces')->insert([
            'id' => $id,
            'slug' => 'zz-jve-' . strtolower($lettre) . '-' . substr($id, 0, 8),
            'name' => 'ZZ Journaux verif ' . $lettre,
            'settings' => '{}',
            'cost_cap_eur' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        jveOwner()->table('email_verification_logs')->insert([
            'workspace_id' => $id,
            'email' => 'temoin-' . strtolower($lettre) . '@exemple-audit.test',
            'status' => 'valid',
            'score' => 90,
            'provider' => 'hunter',
            'raw_response' => '{"audit":"A07-002"}',
            'verified_at' => now(),
        ]);
    }

    return [$wsA, $wsB];
}

function jveNettoyer(array $espaces): void
{
    jveOwner()->table('email_verification_logs')->whereIn('workspace_id', $espaces)->delete();
    jveOwner()->table('workspaces')->whereIn('id', $espaces)->delete();
}

afterEach(function () {
    jvePoserContexte(null);
    jveApp()->disconnect();
    jveOwner()->disconnect();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. LA MESURE — la fuite est-elle fermée, pour de bon ?
// ─────────────────────────────────────────────────────────────────────────────

test('SANS contexte, le role applicatif ne voit AUCUN journal de verification', function () {
    [$wsA, $wsB] = jveSemerDeuxEspaces();

    try {
        // TÉMOIN 1 — les lignes existent VRAIMENT. Sans lui, « 0 ligne vue »
        // serait indiscernable d'un semis raté ou d'une table vide, et la garde
        // passerait au vert en ne prouvant rien du tout.
        expect(jveOwner()->table('email_verification_logs')->whereIn('workspace_id', [$wsA, $wsB])->count())
            ->toBe(2);

        // TÉMOIN 2 — la connexion applicative SAIT lire cette table quand le
        // contexte est posé. Sans lui, un `0` dû à un GRANT manquant ou à une
        // connexion muette rendrait le test vert pour une mauvaise raison.
        jvePoserContexte($wsA);
        $vusAvecContexte = jveApp()->table('email_verification_logs')
            ->whereIn('workspace_id', [$wsA, $wsB])->pluck('workspace_id')->all();
        expect($vusAvecContexte)->toBe([$wsA]);

        // LA MESURE elle-même : contexte retiré, plus rien ne doit sortir.
        jvePoserContexte(null);
        $vusSansContexte = jveApp()->table('email_verification_logs')
            ->whereIn('workspace_id', [$wsA, $wsB])->count();

        expect($vusSansContexte)->toBe(
            0,
            'Repli permissif toujours en place : sans contexte, la table rend des lignes '
            . 'de plusieurs espaces (adresses e-mail + reponses prestataire).',
        );
    } finally {
        jveNettoyer([$wsA, $wsB]);
    }
});

test('AVEC le contexte de l espace B, l espace A reste invisible', function () {
    [$wsA, $wsB] = jveSemerDeuxEspaces();

    try {
        jvePoserContexte($wsB);
        $vus = jveApp()->table('email_verification_logs')
            ->whereIn('workspace_id', [$wsA, $wsB])->pluck('workspace_id')->all();

        expect($vus)->toBe([$wsB]);
    } finally {
        jveNettoyer([$wsA, $wsB]);
    }
});

test('une ecriture SANS contexte est refusee, pas silencieusement ignoree', function () {
    [$wsA, $wsB] = jveSemerDeuxEspaces();

    try {
        jvePoserContexte(null);

        // La policy stricte porte un `WITH CHECK` : l'insertion doit LEVER.
        // Un refus silencieux (0 ligne insérée, aucune erreur) serait le pire
        // des cas — un traitement vert qui ne fait rien.
        expect(fn () => jveApp()->table('email_verification_logs')->insert([
            'workspace_id' => $wsA,
            'email' => 'intrus@exemple-audit.test',
            'status' => 'valid',
            'provider' => 'hunter',
            'raw_response' => '{}',
            'verified_at' => now(),
        ]))->toThrow(QueryException::class);
    } finally {
        jveNettoyer([$wsA, $wsB]);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LA CAUSE — la policy permissive est bien PARTIE, et la stricte est restée
// ─────────────────────────────────────────────────────────────────────────────

test('la policy permissive au nom raccourci n existe plus, et la stricte demeure', function () {
    $policies = jveOwner()->select(
        "SELECT policyname, qual FROM pg_policies
          WHERE schemaname = current_schema()
            AND tablename = 'email_verification_logs'
          ORDER BY policyname",
    );

    $noms = array_map(static fn (object $p): string => $p->policyname, $policies);

    // TÉMOIN — la requête voit quelque chose. Une liste vide (mauvais schéma,
    // table absente) ferait passer le `assertNotContains` par vacuité.
    expect($policies)->not->toBeEmpty();

    $this->assertNotContains(
        'email_verif_workspace_isolation',
        $noms,
        'La policy permissive heritee de 2026_05_19_000001 est de retour : sans contexte, '
        . 'son COALESCE rend le predicat toujours vrai.',
    );
    $this->assertContains(
        'email_verification_logs_workspace_isolation',
        $noms,
        'La policy STRICTE a disparu : la table n a plus de barriere du tout, '
        . 'ce qui est pire que le defaut qu on ferme.',
    );

    // Et aucune policy survivante ne conserve un repli, quelle qu'en soit la forme.
    foreach ($policies as $policy) {
        $this->assertStringNotContainsString(
            'COALESCE',
            (string) $policy->qual,
            'Repli permissif ecrit en COALESCE sur la policy ' . $policy->policyname,
        );
        $this->assertStringNotContainsString(
            'IS NULL',
            (string) $policy->qual,
            'Repli permissif ecrit en IS NULL sur la policy ' . $policy->policyname,
        );
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. LE SITE JUMEAU — patron A-011 : le correctif doit être porté PARTOUT
// ─────────────────────────────────────────────────────────────────────────────

test('aucune table scopee ne porte de policy hors du nom canonique', function () {
    // Le défaut ne venait pas du prédicat mais du NOM : un nom hors canon
    // échappe au `DROP POLICY IF EXISTS <table>_workspace_isolation` de la
    // migration de durcissement, donc au remplacement. Ce contrôle ferme la
    // classe entière, pas le seul cas connu.
    //
    // MESURÉ le 2026-08-20 avant correctif : une seule ligne sortait de cette
    // requête, `email_verification_logs | email_verif_workspace_isolation`.
    $horsCanon = jveOwner()->select(
        "SELECT p.tablename, p.policyname
           FROM pg_policies p
           JOIN pg_class c ON c.relname = p.tablename
           JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = p.schemaname
           JOIN pg_attribute a ON a.attrelid = c.oid
                              AND a.attname = 'workspace_id'
                              AND a.attnum > 0
                              AND NOT a.attisdropped
          WHERE p.schemaname = current_schema()
            AND p.policyname <> p.tablename || '_workspace_isolation'
          ORDER BY p.tablename, p.policyname",
    );

    $etiquettes = array_map(
        static fn (object $r): string => $r->tablename . '.' . $r->policyname,
        $horsCanon,
    );

    expect($etiquettes)->toBe(
        [],
        "Policies dont le nom echappe au DROP de la migration de durcissement : \n  - "
        . implode("\n  - ", $etiquettes),
    );

    // TÉMOIN du détecteur — il DOIT savoir voir des policies. S'il n'en compte
    // aucune sur tout le schéma, c'est lui qui est cassé, pas le schéma qui est
    // propre : la jointure a changé de sens, ou le schéma courant n'est pas le
    // bon, et le `toBe([])` ci-dessus est vrai par vacuité.
    $totalPolicies = (int) jveOwner()->scalar(
        "SELECT count(*) FROM pg_policies p
           JOIN pg_class c ON c.relname = p.tablename
           JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = p.schemaname
           JOIN pg_attribute a ON a.attrelid = c.oid
                              AND a.attname = 'workspace_id'
                              AND a.attnum > 0
                              AND NOT a.attisdropped
          WHERE p.schemaname = current_schema()",
    );
    expect($totalPolicies)->toBeGreaterThan(20);
});

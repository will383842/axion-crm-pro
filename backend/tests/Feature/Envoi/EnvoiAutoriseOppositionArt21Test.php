<?php

/**
 * X7 / B10 — la porte d'envoi relit les oppositions (art. 21 RGPD, STOP, liste de refus) AU DÉPART.
 *
 * Fermée en cas d'erreur, lecture seule. Adresses en `*.example.test` (dépôt PUBLIC).
 * Chaque scénario tourne deux fois : connexion par défaut (superutilisateur, RLS contournée) PUIS `pgsql_app`
 * (rôle applicatif soumis à la RLS) avec `WorkspaceContext`. Pour `pgsql_app`, les données sont écrites par la
 * connexion propriétaire (auto-commit, sinon invisibles d'une autre session) puis nettoyées dans un `finally`.
 */

use App\Envoi\Conformite\MotifRegroupe;
use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise as P;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const OA21_ADRESSE = 'cible@client.example.test';
const OA21_DOMAINE = 'zz-envoi.example.test';

afterEach(function () {
    if (array_key_exists('pgsql_app', DB::getConnections())) {
        try {
            DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        } catch (Throwable) {
            // connexion perdue : elle part avec le disconnect
        }
        DB::connection('pgsql_app')->disconnect();
    }
    if (array_key_exists('pgsql_owner', DB::getConnections())) {
        DB::connection('pgsql_owner')->disconnect();
    }
});

/** Exécute $f avec $nom pour connexion par défaut, puis la restaure. */
function oa21Sur(string $nom, Closure $f): mixed
{
    $avant = DB::getDefaultConnection();
    DB::setDefaultConnection($nom);
    try {
        return $f();
    } finally {
        DB::setDefaultConnection($avant);
    }
}

/** Un espace en règle sur toutes les gardes ANTÉRIEURES : seule l'opposition peut refuser. */
function oa21Espace(DateTimeImmutable $now): string
{
    $ws = F::espace('zz-oa21');
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte(OA21_ADRESSE),
        'verdict' => 'valide', 'verifie_le' => $now->modify('-5 days')->format('c'), 'source' => 'test',
    ]);
    $d = $now->format('c');
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $ws, 'nom' => OA21_DOMAINE, 'achete_le' => $now->modify('-30 days')->format('Y-m-d'),
        'dmarc_verifie_le' => $d, 'spf_verifie_le' => $d, 'dkim_verifie_le' => $d,
    ]);
    FonctionsEspace::regler($ws, 'federations', true);

    return $ws;
}

function oa21Demande(string $ws, DateTimeImmutable $now, ?string $email = null): DemandeEnvoi
{
    return new DemandeEnvoi(
        $ws,
        $email ?? OA21_ADRESSE,
        [['verification' => 'valide']],
        'FR',
        null,
        OA21_DOMAINE,
        'ZZ Expediteur SAS',
        $now->modify('-5 days'),
        null,
        'federations',
        true,
    );
}

function oa21Requete(string $ws, string $statut, string $email = OA21_ADRESSE): int
{
    return (int) DB::table('rgpd_requests')->insertGetId([
        'workspace_id' => $ws, 'type' => 'opposition', 'status' => $statut, 'subject_email' => $email,
        'requested_at' => now(), 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function oa21Stop(string $ws, string $email = OA21_ADRESSE): void
{
    $lien = DB::table('envoi_liens_desinscription')->insertGetId([
        'workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($email), 'portee' => 'business',
    ]);
    DB::table('envoi_oppositions')->insert([
        'workspace_id' => $ws, 'lien_id' => $lien, 'empreinte_adresse' => ListeSuppression::empreinte($email), 'canal' => 'one_click',
    ]);
}

/** @return array<string, int> */
function oa21Comptes(): array
{
    return array_combine(
        ['rgpd_requests', 'envoi_oppositions', 'envoi_oppositions_registre', 'opt_out', 'email_suppressions', 'envoi_liens_desinscription'],
        array_map(static fn (string $t): int => DB::table($t)->count(), ['rgpd_requests', 'envoi_oppositions', 'envoi_oppositions_registre', 'opt_out', 'email_suppressions', 'envoi_liens_desinscription']),
    );
}

/** Nettoie (données de TEST, base de test) ce que le scénario `pgsql_owner` a laissé committé. */
function oa21Nettoyer(array $espaces): void
{
    oa21Sur('pgsql_owner', function () use ($espaces): void {
        DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        foreach (['envoi_oppositions', 'envoi_liens_desinscription'] as $t) {
            DB::transaction(function () use ($t, $espaces): void {
                DB::statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
                DB::table($t)->whereIn('workspace_id', $espaces)->delete();
                DB::statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
            });
        }
        foreach (['rgpd_requests', 'envoi_verdicts_adresse', 'envoi_domaines'] as $t) {
            DB::table($t)->whereIn('workspace_id', $espaces)->delete();
        }
        DB::table('fonctions_espace')->whereIn('espace_id', $espaces)->delete();
        DB::table('workspaces')->whereIn('id', $espaces)->delete();
    });
}

/**
 * Le scénario complet. $rls = false : connexion par défaut. $rls = true : écriture par le propriétaire, lecture par
 * `pgsql_app` sous `WorkspaceContext`.
 */
function oa21Scenario(bool $rls): void
{
    $now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    $ecrire = static fn (Closure $f): mixed => $rls ? oa21Sur('pgsql_owner', $f) : $f();
    $verifier = static fn (string $ws, ?string $email = null) => $rls
        ? oa21Sur('pgsql_app', static fn () => WorkspaceContext::run($ws, static fn () => P::verifier(oa21Demande($ws, $now, $email), $now)))
        : P::verifier(oa21Demande($ws, $now, $email), $now);

    $espaces = [];
    try {
        $ws = $ecrire(static fn () => oa21Espace($now));
        $autre = $ecrire(static fn () => F::espace('zz-oa21-autre'));
        $espaces = [$ws, $autre];

        // Nominal : la porte est ouverte.
        expect($verifier($ws)->refus)->toBeNull();

        // Art. 21 : pending, processing, done => refus ; rejected, expired => aucun refus à ce titre.
        $id = $ecrire(static fn () => oa21Requete($ws, 'pending'));
        foreach (['pending', 'processing', 'done'] as $statut) {
            $ecrire(static fn () => DB::table('rgpd_requests')->where('id', $id)->update(['status' => $statut]));
            expect($verifier($ws)->refus)->toBe(P::OPPOSITION_ART21, "statut {$statut}");
        }
        foreach (['rejected', 'expired'] as $statut) {
            $ecrire(static fn () => DB::table('rgpd_requests')->where('id', $id)->update(['status' => $statut]));
            expect($verifier($ws)->refus)->toBeNull("statut {$statut}");
        }
        $ecrire(static fn () => DB::table('rgpd_requests')->where('id', $id)->update(['status' => 'pending']));
        expect($verifier($ws)->refus)->toBe(P::OPPOSITION_ART21);
        $ecrire(static fn () => DB::table('rgpd_requests')->where('id', $id)->update(['status' => 'rejected']));

        // Une demande d'un AUTRE espace n'a aucun effet.
        $ecrire(static fn () => oa21Requete($autre, 'pending'));
        expect($verifier($ws)->refus)->toBeNull();

        // STOP (envoi_oppositions) => opposition_stop.
        $ecrire(static fn () => oa21Stop($ws));
        expect($verifier($ws)->refus)->toBe(P::OPPOSITION_STOP);

        // Un STOP de l'autre espace seul n'a aucun effet sur un tiers : adresse différente, même espace => rien.
        expect($verifier($ws, 'autre@client.example.test')->refus)->not->toBe(P::OPPOSITION_STOP);

        // Priorité : art. 21 avant STOP.
        $ecrire(static fn () => DB::table('rgpd_requests')->where('id', $id)->update(['status' => 'done']));
        expect($verifier($ws)->refus)->toBe(P::OPPOSITION_ART21);

        // Liste de refus (registre manuel du domaine) : le motif R13 d'aujourd'hui reste prioritaire et inchangé.
        // Aucune écriture ni suppression par la porte : comptes identiques avant/après.
        $avant = $ecrire(static fn () => oa21Comptes());
        $verifier($ws);
        $verifier($autre);
        expect($ecrire(static fn () => oa21Comptes()))->toBe($avant);
    } finally {
        if ($rls) {
            oa21Nettoyer($espaces);
        }
    }
}

test('B10 — art. 21, STOP, autre espace, statuts, sans écriture (connexion par défaut)', function () {
    oa21Scenario(false);
});

test('B10 — mêmes cas sur pgsql_app avec contexte d\'espace (RLS armée)', function () {
    oa21Scenario(true);
});

test('B10 — liste de refus : motif opposition_liste_refus (la garde X7 seule, via StatutOpposition)', function () {
    $now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    $ws = oa21Espace($now);
    DB::table('email_suppressions')->insert([
        'email_hash' => ListeSuppression::empreinte(OA21_ADRESSE), 'scope' => 'business', 'reason' => 'manual', 'source' => 'zz-test',
    ]);

    $garde = new ReflectionMethod(P::class, 'garderOppositions');

    expect($garde->invoke(null, oa21Demande($ws, $now)))->toBe(P::OPPOSITION_LISTE_REFUS)
        // Le motif rendu AUJOURD'HUI par la porte (filtre enveloppé, plus tôt) reste inchangé.
        ->and(P::verifier(oa21Demande($ws, $now), $now)->refus)->toBe('opposition');
});

test('B10 — fermé en cas d\'erreur : une erreur de lecture fait ÉCHOUER verifier, rien ne part', function () {
    $now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    $ws = oa21Espace($now);
    expect(P::verifier(oa21Demande($ws, $now), $now)->refus)->toBeNull();

    // Panne de lecture réelle (annulée avec la transaction du test) : la table des demandes disparaît.
    DB::statement('ALTER TABLE rgpd_requests RENAME TO rgpd_requests_zz_panne');

    expect(fn () => P::verifier(oa21Demande($ws, $now), $now))->toThrow(QueryException::class);
});

test('B10 — MUTATION : sans contexte d\'espace DANS la garde, la RLS cache la demande (la garde ne voit plus rien)', function () {
    $now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    $ws = null;
    try {
        $ws = oa21Sur('pgsql_owner', static function () use ($now): string {
            $ws = oa21Espace($now);
            oa21Requete($ws, 'pending');

            return $ws;
        });

        // AUCUN contexte autour : seul le contexte posé par la garde elle-même peut faire voir la demande.
        $garde = new ReflectionMethod(P::class, 'garderOppositions');
        $motif = oa21Sur('pgsql_app', static fn () => $garde->invoke(null, oa21Demande($ws, $now)));

        expect($motif)->toBe(P::OPPOSITION_ART21);
    } finally {
        if ($ws !== null) {
            oa21Nettoyer([$ws]);
        }
    }
});

test('B10 — un motif opposition_* inconnu de MotifRegroupe ne plante pas « Puis-je écrire ? »', function () {
    foreach ([P::OPPOSITION_ART21, P::OPPOSITION_STOP, P::OPPOSITION_LISTE_REFUS] as $motif) {
        expect(MotifRegroupe::de($motif))->toBeIn(MotifRegroupe::TOUS);
    }
});

<?php

/**
 * P0 — la lecture unifiée des oppositions voit AUSSI la mise à l'écart d'une personne (`personnes.opposition_at`, sans
 * ligne `opt_out`) et le désabonnement de la seule lettre (`opt_out` portée `lettre`). Lecture seule, fermée en cas
 * d'erreur. Adresses en `*.example.test` (dépôt PUBLIC).
 *
 * Chaque scénario de porte tourne sur la connexion par défaut PUIS sur `pgsql_app` (RLS armée, écriture par le propriétaire).
 */

use App\Crm\Opposition\StatutOpposition;
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

const OPP_ADRESSE = 'cible@client.example.test';
const OPP_DOMAINE = 'zz-envoi.example.test';

afterEach(function () {
    foreach (['pgsql_app', 'pgsql_owner'] as $nom) {
        if (array_key_exists($nom, DB::getConnections())) {
            try {
                DB::connection($nom)->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
            } catch (Throwable) {
            }
            DB::connection($nom)->disconnect();
        }
    }
});

function oppSur(string $nom, Closure $f): mixed
{
    $avant = DB::getDefaultConnection();
    DB::setDefaultConnection($nom);
    try {
        return $f();
    } finally {
        DB::setDefaultConnection($avant);
    }
}

function oppEspace(DateTimeImmutable $now, string $adresse = OPP_ADRESSE): string
{
    $ws = F::espace('zz-opp');
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($adresse),
        'verdict' => 'valide', 'verifie_le' => $now->modify('-5 days')->format('c'), 'source' => 'test',
    ]);
    $d = $now->format('c');
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $ws, 'nom' => OPP_DOMAINE, 'achete_le' => $now->modify('-30 days')->format('Y-m-d'),
        'dmarc_verifie_le' => $d, 'spf_verifie_le' => $d, 'dkim_verifie_le' => $d,
    ]);
    FonctionsEspace::regler($ws, 'federations', true);

    return $ws;
}

function oppDemande(string $ws, DateTimeImmutable $now, string $email = OPP_ADRESSE): DemandeEnvoi
{
    return new DemandeEnvoi(
        $ws,
        $email,
        [['verification' => 'valide']],
        'FR',
        null,
        OPP_DOMAINE,
        'ZZ Expediteur SAS',
        $now->modify('-5 days'),
        null,
        'federations',
        true,
    );
}

function oppPersonne(string $ws, string $email, ?string $ecartee = null): int
{
    return (int) DB::table('personnes')->insertGetId([
        'workspace_id' => $ws, 'person_key' => hash('sha256', 'opp|' . $ws . $email), 'email' => $email,
        'email_hash' => ListeSuppression::empreinte($email), 'email_nature' => 'pro', 'premiere_source' => 'newsletter',
        'premiere_source_at' => now(), 'derniere_interaction_at' => now(), 'legal_basis' => 'consent',
        'opposition_at' => $ecartee, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function oppLettre(string $email): void
{
    DB::table('opt_out')->insert(['email_hash' => ListeSuppression::empreinte($email), 'scope' => 'lettre', 'source' => 'zz-test', 'reason' => 'unsubscribe']);
}

function oppStatut(string $ws, string $email): array
{
    return WorkspaceContext::run($ws, fn () => (new StatutOpposition)->pourLignes($ws, ['x' => ['email' => $email, 'siren' => null]])['x']);
}

function oppNettoyer(array $espaces): void
{
    oppSur('pgsql_owner', function () use ($espaces): void {
        DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::table('opt_out')->where('source', 'zz-test')->delete();
        foreach (['personnes', 'envoi_verdicts_adresse', 'envoi_domaines'] as $t) {
            DB::table($t)->whereIn('workspace_id', $espaces)->delete();
        }
        DB::table('fonctions_espace')->whereIn('espace_id', $espaces)->delete();
        DB::table('workspaces')->whereIn('id', $espaces)->delete();
    });
}

function oppScenario(bool $rls): void
{
    $now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    $ecrire = static fn (Closure $f): mixed => $rls ? oppSur('pgsql_owner', $f) : $f();
    $verifier = static fn (string $ws, string $email = OPP_ADRESSE) => $rls
        ? oppSur('pgsql_app', static fn () => WorkspaceContext::run($ws, static fn () => P::verifier(oppDemande($ws, $now, $email), $now)))
        : P::verifier(oppDemande($ws, $now, $email), $now);
    $statut = static fn (string $ws, string $email = OPP_ADRESSE) => $rls
        ? oppSur('pgsql_app', static fn () => oppStatut($ws, $email))
        : oppStatut($ws, $email);

    $espaces = [];
    try {
        $ws = $ecrire(static fn () => oppEspace($now));
        $autre = $ecrire(static fn () => F::espace('zz-opp-autre'));
        $espaces = [$ws, $autre];
        expect($verifier($ws)->refus)->toBeNull();

        // Une personne d'un AUTRE espace, mise à l'écart : aucun effet ici (cloisonnement).
        $ecrire(static fn () => oppPersonne($autre, OPP_ADRESSE, '2026-11-01 10:00:00'));
        expect($verifier($ws)->refus)->toBeNull()
            ->and($statut($ws))->toBe(['opposee' => false, 'motifs' => []]);

        // Une personne de l'espace NON écartée : aucun effet.
        $ecrire(static fn () => oppPersonne($ws, OPP_ADRESSE));
        expect($verifier($ws)->refus)->toBeNull();

        // (a) Mise à l'écart : opposition_at non nul, AUCUNE ligne opt_out.
        $ecrire(static fn () => DB::table('personnes')->where('workspace_id', $ws)->update(['opposition_at' => '2026-11-01 10:00:00']));
        expect($statut($ws)['opposee'])->toBeTrue()
            ->and($verifier($ws)->refus)->toBe(P::OPPOSITION_ART21);

        // Autre forme de l'adresse (majuscules, alias +, point final) : toujours refusée.
        foreach (['CIBLE@Client.Example.Test', 'cible+promo@client.example.test', 'cible@client.example.test.'] as $forme) {
            expect($statut($ws, $forme)['opposee'])->toBeTrue($forme);
        }
        $ecrire(static fn () => DB::table('personnes')->where('workspace_id', $ws)->update(['opposition_at' => null]));
        expect($verifier($ws)->refus)->toBeNull();

        // (b) Désabonnement de la seule lettre (opt_out portée lettre, globale) => refus.
        $ecrire(static fn () => oppLettre(OPP_ADRESSE));
        expect($statut($ws)['opposee'])->toBeTrue()
            ->and($verifier($ws)->refus)->toBe(P::OPPOSITION_STOP)
            ->and($statut($ws, 'CIBLE+x@client.example.test')['opposee'])->toBeTrue();

        // Adresse voisine : intacte.
        expect($statut($ws, 'autre@client.example.test'))->toBe(['opposee' => false, 'motifs' => []]);
    } finally {
        if ($rls) {
            oppNettoyer($espaces);
        }
    }
}

test('P0 — personne écartée / lettre : refus, formes d\'adresse, autre espace (connexion par défaut)', function () {
    oppScenario(false);
});

test('P0 — mêmes cas sous pgsql_app (RLS armée)', function () {
    oppScenario(true);
});

test('P0 — échec fermé : une erreur de lecture de `personnes` fait ÉCHOUER la lecture et la porte', function () {
    $now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    $ws = oppEspace($now);
    expect(P::verifier(oppDemande($ws, $now), $now)->refus)->toBeNull();
    DB::statement('ALTER TABLE personnes RENAME TO personnes_zz_panne');

    expect(fn () => oppStatut($ws, OPP_ADRESSE))->toThrow(QueryException::class)
        ->and(fn () => P::verifier(oppDemande($ws, $now), $now))->toThrow(QueryException::class);
});

test('P0 — échec fermé : une erreur de lecture de `opt_out` fait ÉCHOUER la lecture', function () {
    $ws = oppEspace(new DateTimeImmutable('2026-12-01 10:00:00'));
    DB::statement('ALTER TABLE opt_out RENAME TO opt_out_zz_panne');

    expect(fn () => oppStatut($ws, OPP_ADRESSE))->toThrow(QueryException::class);
});

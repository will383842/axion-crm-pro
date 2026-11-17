<?php

use App\Crm\Opposition\StatutOpposition;
use App\Envoi\Conformite\RegistreOppositionLinkedin;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot PONT-LINKEDIN LI-L3 — opposition par profil LinkedIn : `RegistreOppositionLinkedin::inscrire` et
 * `StatutOpposition::profilsOpposes`. Chaque test est rejoué SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE.
 * Profils fictifs seulement (exemple-prenom-nom-0a1b2c). Aucun appel réseau.
 */
uses(TestCase::class, RefreshDatabase::class);

const OLL_MODES = ['app', 'proprio'];
const OLL_PROFIL = 'exemple-prenom-nom-0a1b2c';

function ollEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Oll', 'slug' => 'oll-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Exécute $f dans une transaction annulée ; en mode « app », sous le rôle applicatif non propriétaire. */
function ollSous(string $mode, string $ws, Closure $f): mixed
{
    DB::beginTransaction();
    try {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }

        return $f();
    } finally {
        DB::rollBack();
        WorkspaceContext::clear();
    }
}

function ollInscrire(string $ws, string $profil, string $source = 'manuel'): bool
{
    return (new RegistreOppositionLinkedin)->inscrire($ws, $profil, 'opposition fictive', 'le fondateur', $source);
}

function ollNb(string $ws): int
{
    return DB::table('envoi_oppositions_linkedin')->where('workspace_id', $ws)->count();
}

test('trois formes d\'URL du même profil ⇒ une seule ligne ; rejeu ⇒ false', function () {
    foreach (OLL_MODES as $mode) {
        $ws = ollEspace();
        ollSous($mode, $ws, function () use ($ws, $mode) {
            expect(ollInscrire($ws, 'https://www.linkedin.com/in/' . OLL_PROFIL . '/'))->toBeTrue($mode)
                ->and(ollInscrire($ws, 'https://fr.linkedin.com/in/' . OLL_PROFIL . '?trk=public_profile'))->toBeFalse($mode)
                ->and(ollInscrire($ws, 'linkedin.com/in/' . strtoupper(OLL_PROFIL) . '/en'))->toBeFalse($mode)
                ->and(ollInscrire($ws, OLL_PROFIL))->toBeFalse($mode);
            expect(ollNb($ws))->toBe(1, $mode);
        });
    }
});

test('page d\'entreprise ou adresse étrangère ⇒ refus explicite, aucune ligne', function () {
    foreach (OLL_MODES as $mode) {
        $ws = ollEspace();
        ollSous($mode, $ws, function () use ($ws, $mode) {
            foreach (['https://www.linkedin.com/company/societe-fictive', 'https://example.test/in/' . OLL_PROFIL, '', '   '] as $mauvais) {
                expect(fn () => ollInscrire($ws, $mauvais))->toThrow(InvalidArgumentException::class);
            }
            expect(ollNb($ws))->toBe(0, $mode);
        });
    }
});

test('motif ou source invalides ⇒ refus, aucune ligne', function () {
    $ws = ollEspace();
    $r = new RegistreOppositionLinkedin;
    expect(fn () => $r->inscrire($ws, OLL_PROFIL, 'ab', 'le fondateur', 'manuel'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $r->inscrire($ws, OLL_PROFIL, 'écrit à x@example.test', 'le fondateur', 'manuel'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $r->inscrire($ws, OLL_PROFIL, 'opposition fictive', 'le fondateur', 'outil_tiers'))->toThrow(InvalidArgumentException::class);
    expect(ollNb($ws))->toBe(0);
});

test('profilsOpposes : opposé, non opposé, non normalisable ⇒ opposé', function () {
    foreach (OLL_MODES as $mode) {
        $ws = ollEspace();
        ollSous($mode, $ws, function () use ($ws, $mode) {
            ollInscrire($ws, OLL_PROFIL);
            $r = (new StatutOpposition)->profilsOpposes($ws, [
                'https://www.linkedin.com/in/' . OLL_PROFIL . '/', 'autre-profil-fictif-9z9z9z', 'https://www.linkedin.com/company/societe-fictive', '',
            ]);
            expect($r)->toBe([
                'https://www.linkedin.com/in/' . OLL_PROFIL . '/' => true,
                'autre-profil-fictif-9z9z9z' => false,
                'https://www.linkedin.com/company/societe-fictive' => true,
                '' => true,
            ], $mode);
            expect((new StatutOpposition)->profilsOpposes($ws, []))->toBe([]);
        });
    }
});

test('profilsOpposes : une seule requête de lecture pour toute la liste', function () {
    $ws = ollEspace();
    ollInscrire($ws, OLL_PROFIL);
    DB::flushQueryLog();
    DB::enableQueryLog();
    (new StatutOpposition)->profilsOpposes($ws, [OLL_PROFIL, 'profil-fictif-aaa111', 'profil-fictif-bbb222', 'profil-fictif-ccc333']);
    $lectures = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'envoi_oppositions_linkedin'));
    DB::disableQueryLog();
    expect($lectures)->toHaveCount(1);
});

test('table illisible (rôle sans SELECT) ⇒ exception, jamais « non opposé »', function () {
    $ws = ollEspace();
    ollInscrire($ws, OLL_PROFIL);
    $app = '"' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"';
    ollSous('app', $ws, function () use ($ws, $app) {
        // Dans la transaction annulée : le rôle perd son droit de lecture (la révocation est annulée avec elle).
        DB::statement('RESET ROLE');
        DB::statement('REVOKE SELECT ON public.envoi_oppositions_linkedin FROM ' . $app);
        DB::statement('SET LOCAL ROLE ' . $app);
        expect(fn () => (new StatutOpposition)->profilsOpposes($ws, [OLL_PROFIL]))->toThrow(RuntimeException::class, 'illisible');
    });
});

test('espace B ne voit pas l\'opposition de A, et inscrit à part', function () {
    foreach (OLL_MODES as $mode) {
        $a = ollEspace();
        $b = ollEspace();
        ollSous($mode, $a, function () use ($a, $b, $mode) {
            ollInscrire($a, OLL_PROFIL);
            expect((new StatutOpposition)->profilsOpposes($b, [OLL_PROFIL]))->toBe([OLL_PROFIL => false], $mode)
                ->and((new StatutOpposition)->profilsOpposes($a, [OLL_PROFIL]))->toBe([OLL_PROFIL => true], $mode);
            // B inscrit le même profil : c'est une AUTRE ligne (l'unicité est par espace), nouvelle pour B.
            expect(ollInscrire($b, OLL_PROFIL))->toBeTrue($mode)->and(ollNb($a))->toBe(1)->and(ollNb($b))->toBe(1);
        });
    }
});

test('aucune colonne en clair : le slug n\'apparaît nulle part hors valeur chiffrée (qui le restitue)', function () {
    $ws = ollEspace();
    ollInscrire($ws, 'https://www.linkedin.com/in/' . OLL_PROFIL . '/', 'prospection_linkedin');
    $ligne = DB::table('envoi_oppositions_linkedin')->where('workspace_id', $ws)->first();
    expect(json_encode($ligne))->not->toContain(OLL_PROFIL)->not->toContain('linkedin')
        ->and($ligne->empreinte)->toBe(hash('sha256', OLL_PROFIL))
        ->and(Crypt::decryptString($ligne->valeur_chiffree))->toBe(OLL_PROFIL)
        ->and($ligne->source)->toBe('prospection_linkedin');
});

test('ajout seul : aucune suppression ni modification possible', function () {
    $ws = ollEspace();
    ollInscrire($ws, OLL_PROFIL);
    expect(fn () => DB::table('envoi_oppositions_linkedin')->where('workspace_id', $ws)->delete())->toThrow(QueryException::class);
    expect(ollNb($ws))->toBe(1);
});

<?php

use App\Envoi\Conformite\RegistreOppositionLinkedin;
use App\ProspectionLinkedIn\DepotProspects;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot LI-ROB-A — aucun changement d'état sans ligne de journal ; insertion + journal « ajout » dans UNE transaction ;
 * l'opposition relue sous le verrou du prospect. Chaque test tourne SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE.
 * Profils fictifs seulement. Aucun appel réseau, aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const DRR_MODES = ['app', 'proprio'];
const DRR_PROFIL = 'exemple-robuste-0a1b2c';

function drrEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Drr', 'slug' => 'drr-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function drrExpediteur(): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(['id' => $id, 'email' => 'fondateur-' . substr($id, 0, 8) . '@example.test', 'name' => 'Expediteur', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Transaction annulée ; en mode « app », sous le rôle applicatif non propriétaire. */
function drrSous(string $mode, string $ws, Closure $f): mixed
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
        Str::createUuidsNormally();
    }
}

/** Fait « perdre » en silence toute écriture du journal pour cet événement : `ecrire()` rend alors faux. */
function drrJournalMuet(string $mode, string $evenement): void
{
    if ($mode === 'app') {
        DB::statement('RESET ROLE');
    }
    DB::statement('CREATE FUNCTION pg_temp.drr_muet() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END $$');
    DB::statement('CREATE TRIGGER drr_muet BEFORE INSERT ON pl_journal FOR EACH ROW WHEN (NEW.evenement = ' . DB::getPdo()->quote($evenement) . ') EXECUTE FUNCTION pg_temp.drr_muet()');
    if ($mode === 'app') {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
}

function drrNb(string $ws, string $table): int
{
    return WorkspaceContext::run($ws, static fn (): int => DB::table($table)->where('workspace_id', $ws)->count());
}

function drrEtat(string $ws, string $id): string
{
    return (string) WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('workspace_id', $ws)->where('id', $id)->value('etat'));
}

function drrGestes(string $ws, string $id, string $evenement): int
{
    return WorkspaceContext::run($ws, static fn (): int => DB::table('pl_journal')->where('workspace_id', $ws)->where('prospect_id', $id)->where('evenement', $evenement)->count());
}

function drrAjouter(string $ws, string $exp, string $profil = DRR_PROFIL): string
{
    return (string) (new DepotProspects)->ajouter($ws, $exp, $profil, 'recherche', 'ref-fictive', '2026-11-17')['prospect_id'];
}

test('journal « ajout » qui rend faux ⇒ exception et AUCUN prospect inséré', function () {
    foreach (DRR_MODES as $mode) {
        $ws = drrEspace();
        $exp = drrExpediteur();
        drrSous($mode, $ws, function () use ($ws, $exp, $mode) {
            drrJournalMuet($mode, 'ajout');
            expect(fn () => (new DepotProspects)->ajouter($ws, $exp, DRR_PROFIL, 'recherche', 'ref-fictive', '2026-11-17'))
                ->toThrow(RuntimeException::class);
            expect(drrNb($ws, 'pl_prospects'))->toBe(0, $mode)->and(drrNb($ws, 'pl_journal'))->toBe(0, $mode);
        });
    }
});

test('journal « ajout » qui lève ⇒ exception et AUCUN prospect inséré', function () {
    foreach (DRR_MODES as $mode) {
        $ws = drrEspace();
        $exp = drrExpediteur();
        drrSous($mode, $ws, function () use ($ws, $exp, $mode) {
            if ($mode === 'app') {
                DB::statement('RESET ROLE');
            }
            DB::statement('CREATE FUNCTION pg_temp.drr_panne() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION \'panne du journal\'; END $$');
            DB::statement('CREATE TRIGGER drr_panne BEFORE INSERT ON pl_journal FOR EACH ROW EXECUTE FUNCTION pg_temp.drr_panne()');
            if ($mode === 'app') {
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }
            expect(fn () => (new DepotProspects)->ajouter($ws, $exp, DRR_PROFIL, 'recherche', 'ref-fictive', '2026-11-17'))
                ->toThrow(RuntimeException::class);
            expect(drrNb($ws, 'pl_prospects'))->toBe(0, $mode);
        });
    }
});

test('geste dont le journal rend faux ⇒ exception, état inchangé, aucune ligne de geste', function () {
    foreach (DRR_MODES as $mode) {
        $ws = drrEspace();
        $exp = drrExpediteur();
        drrSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $id = drrAjouter($ws, $exp);
            drrJournalMuet($mode, 'a_repondu');
            expect(fn () => (new DepotProspects)->appliquerGeste($ws, $id, 'a_repondu', 'clic-' . Str::uuid(), 'le fondateur', []))
                ->toThrow(RuntimeException::class);
            expect(drrEtat($ws, $id))->toBe('candidat', $mode)->and(drrGestes($ws, $id, 'a_repondu'))->toBe(0, $mode);
        });
    }
});

test('opposition posée entre la lecture et l\'écriture ⇒ invitation_faite et message_fait refusés', function () {
    foreach (DRR_MODES as $mode) {
        $ws = drrEspace();
        $exp = drrExpediteur();
        drrSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $id = drrAjouter($ws, $exp);
            (new RegistreOppositionLinkedin)->inscrire($ws, DRR_PROFIL, 'opposition fictive', 'le fondateur', 'manuel');
            foreach (['invitation_faite', 'message_fait'] as $geste) {
                expect(fn () => (new DepotProspects)->appliquerGeste($ws, $id, $geste, 'clic-' . Str::uuid(), 'le fondateur', []))
                    ->toThrow(RuntimeException::class);
                expect(drrGestes($ws, $id, $geste))->toBe(0, $mode);
            }
            expect(drrEtat($ws, $id))->toBe('candidat', $mode);
            // relecture SEULE : le dépôt ne modifie rien (ni écarté, ni journal supplémentaire)
            expect(drrNb($ws, 'pl_journal'))->toBe(1, $mode);
        });
    }
});

test('sans opposition, invitation_faite passe ; un geste sans contact passe même opposé', function () {
    foreach (DRR_MODES as $mode) {
        $ws = drrEspace();
        $exp = drrExpediteur();
        drrSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $id = drrAjouter($ws, $exp);
            $r = (new DepotProspects)->appliquerGeste($ws, $id, 'invitation_faite', 'clic-' . Str::uuid(), 'le fondateur', []);
            expect($r['etat'])->toBe('invite', $mode);
            (new RegistreOppositionLinkedin)->inscrire($ws, DRR_PROFIL, 'opposition fictive', 'le fondateur', 'manuel');
            $r = (new DepotProspects)->appliquerGeste($ws, $id, 'a_repondu', 'clic-' . Str::uuid(), 'le fondateur', []);
            expect($r['etat'])->toBe('repondu', $mode);
        });
    }
});

test('rejeu d\'un geste déjà journalisé : rendu tel quel, même si l\'opposition est posée depuis', function () {
    foreach (DRR_MODES as $mode) {
        $ws = drrEspace();
        $exp = drrExpediteur();
        drrSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $id = drrAjouter($ws, $exp);
            $cle = 'clic-' . Str::uuid();
            (new DepotProspects)->appliquerGeste($ws, $id, 'invitation_faite', $cle, 'le fondateur', []);
            (new RegistreOppositionLinkedin)->inscrire($ws, DRR_PROFIL, 'opposition fictive', 'le fondateur', 'manuel');
            $r = (new DepotProspects)->appliquerGeste($ws, $id, 'invitation_faite', $cle, 'le fondateur', []);
            expect($r['rejeu'])->toBeTrue($mode)->and(drrGestes($ws, $id, 'invitation_faite'))->toBe(1);
        });
    }
});

test('espace B : ne voit rien de A, et l\'opposition de A ne le touche pas', function () {
    foreach (DRR_MODES as $mode) {
        $a = drrEspace();
        $b = drrEspace();
        $exp = drrExpediteur();
        drrSous($mode, $a, function () use ($a, $b, $exp, $mode) {
            $idA = drrAjouter($a, $exp);
            (new RegistreOppositionLinkedin)->inscrire($a, DRR_PROFIL, 'opposition fictive', 'le fondateur', 'manuel');
            expect(fn () => (new DepotProspects)->appliquerGeste($b, $idA, 'invitation_faite', 'clic-' . Str::uuid(), 'le fondateur', []))
                ->toThrow(RuntimeException::class, 'introuvable');
            expect(drrNb($b, 'pl_prospects'))->toBe(0, $mode)->and(drrNb($b, 'pl_journal'))->toBe(0, $mode);
            // même profil chez B : pas opposé là-bas
            $idB = drrAjouter($b, $exp);
            $r = (new DepotProspects)->appliquerGeste($b, $idB, 'invitation_faite', 'clic-' . Str::uuid(), 'le fondateur', []);
            expect($r['etat'])->toBe('invite', $mode)->and(drrEtat($a, $idA))->toBe('candidat');
        });
    }
});

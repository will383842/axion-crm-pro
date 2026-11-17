<?php

use App\Envoi\Conformite\RegistreOppositionLinkedin;
use App\ProspectionLinkedIn\DepotProspects;
use App\ProspectionLinkedIn\JournalProspection;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot PONT-LINKEDIN LI-B1 — dépôt des prospects : ajout, rapprochement, gestes journalisés. Chaque test tourne SOUS LE RÔLE
 * APPLICATIF ET SOUS LE PROPRIÉTAIRE. Profils et adresses fictifs seulement. Aucun appel réseau, aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const DPT_MODES = ['app', 'proprio'];
const DPT_PROFIL = 'exemple-prenom-nom-0a1b2c';

function dptEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Dpt', 'slug' => 'dpt-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function dptExpediteur(): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(['id' => $id, 'email' => 'fondateur-' . substr($id, 0, 8) . '@example.test', 'name' => 'Expediteur', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Une fiche + un contact (profil LinkedIn donné) dans l'espace. */
function dptContact(string $ws, ?string $url, string $nom = 'Nom'): int
{
    return WorkspaceContext::run($ws, static function () use ($ws, $url, $nom): int {
        $societe = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => (string) random_int(100000000, 999999999), 'denomination' => 'Societe dpt', 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $societe, 'first_name' => 'Prenom', 'last_name' => $nom, 'linkedin_url' => $url, 'created_at' => now(), 'updated_at' => now()]);
    });
}

/** Exécute $f dans une transaction annulée ; en mode « app », sous le rôle applicatif non propriétaire. */
function dptSous(string $mode, string $ws, Closure $f): mixed
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

/** @return array<string, mixed> */
function dptAjouter(string $ws, string $exp, string $profil, string $source = 'recherche'): array
{
    return (new DepotProspects)->ajouter($ws, $exp, $profil, $source, 'ref-fictive', '2026-11-17');
}

function dptNbProspects(string $ws): int
{
    return WorkspaceContext::run($ws, static fn (): int => DB::table('pl_prospects')->where('workspace_id', $ws)->count());
}

/** @return list<object> */
function dptJournal(string $ws, ?string $prospect = null): array
{
    return WorkspaceContext::run($ws, static fn (): array => DB::table('pl_journal')->where('workspace_id', $ws)
        ->when($prospect !== null, static fn ($q) => $q->where('prospect_id', $prospect))->orderBy('id')->get()->all());
}

function dptEtat(string $ws, string $id): string
{
    return (string) WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('workspace_id', $ws)->where('id', $id)->value('etat'));
}

test('trois formes d\'URL du même profil ⇒ un seul prospect ; rejeu ⇒ l\'existant, rien créé', function () {
    foreach (DPT_MODES as $mode) {
        $ws = dptEspace();
        $exp = dptExpediteur();
        dptSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $a = dptAjouter($ws, $exp, 'https://www.linkedin.com/in/' . DPT_PROFIL . '/');
            expect($a['refus'])->toBeNull($mode)->and($a['cree'])->toBeTrue($mode);
            $b = dptAjouter($ws, $exp, 'https://fr.linkedin.com/in/' . DPT_PROFIL . '?trk=public_profile');
            $c = dptAjouter($ws, $exp, 'linkedin.com/in/' . strtoupper(DPT_PROFIL) . '/en');
            expect($b['cree'])->toBeFalse($mode)->and($b['prospect_id'])->toBe($a['prospect_id'])
                ->and($c['cree'])->toBeFalse($mode)->and($c['prospect_id'])->toBe($a['prospect_id']);
            expect(dptNbProspects($ws))->toBe(1, $mode)->and(dptJournal($ws))->toHaveCount(1);
            expect(dptEtat($ws, $a['prospect_id']))->toBe('candidat');
        });
    }
});

test('profil opposé (quelle que soit la forme d\'URL) ⇒ refus « oppose », aucune ligne', function () {
    foreach (DPT_MODES as $mode) {
        $ws = dptEspace();
        $exp = dptExpediteur();
        dptSous($mode, $ws, function () use ($ws, $exp, $mode) {
            (new RegistreOppositionLinkedin)->inscrire($ws, DPT_PROFIL, 'opposition fictive', 'le fondateur', 'manuel');
            $r = dptAjouter($ws, $exp, 'https://www.linkedin.com/in/' . DPT_PROFIL . '/');
            expect($r['refus'])->toBe('oppose', $mode)->and($r['prospect_id'])->toBeNull()->and($r['cree'])->toBeFalse();
            expect(dptNbProspects($ws))->toBe(0, $mode)->and(dptJournal($ws))->toBe([]);
        });
    }
});

test('profil non normalisable, source ou champs invalides ⇒ refus nommé, aucune ligne', function () {
    $ws = dptEspace();
    $exp = dptExpediteur();
    foreach (['https://www.linkedin.com/company/societe-fictive', 'https://example.test/in/' . DPT_PROFIL, '', '   '] as $mauvais) {
        expect(dptAjouter($ws, $exp, $mauvais)['refus'])->toBe('profil_invalide');
    }
    expect(dptAjouter($ws, $exp, DPT_PROFIL, 'outil_tiers')['refus'])->toBe('source_invalide');
    expect(dptAjouter($ws, $exp, DPT_PROFIL, '')['refus'])->toBe('source_invalide');
    $d = new DepotProspects;
    expect($d->ajouter($ws, $exp, DPT_PROFIL, 'recherche', 'ecrit@example.test', '2026-11-17')['refus'])->toBe('source_ref_invalide');
    expect($d->ajouter($ws, $exp, DPT_PROFIL, 'recherche', 'ref', '2026-11-17', null, '12345')['refus'])->toBe('siren_invalide');
    expect($d->ajouter($ws, $exp, DPT_PROFIL, 'recherche', 'ref', 'pas-une-date')['refus'])->toBe('date_invalide');
    expect(dptNbProspects($ws))->toBe(0);
});

test('registre d\'opposition illisible ⇒ exception (échec fermé), aucune ligne créée', function () {
    $ws = dptEspace();
    $exp = dptExpediteur();
    $app = '"' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"';
    dptSous('app', $ws, function () use ($ws, $exp, $app) {
        DB::statement('RESET ROLE');
        DB::statement('REVOKE SELECT ON public.envoi_oppositions_linkedin FROM ' . $app);
        DB::statement('SET LOCAL ROLE ' . $app);
        // Point de sauvegarde : l'erreur SQL n'avorte pas la transaction englobante du test.
        expect(fn () => DB::transaction(fn () => dptAjouter($ws, $exp, DPT_PROFIL)))->toThrow(RuntimeException::class);
        expect(dptNbProspects($ws))->toBe(0);
    });
});

test('un contact dont linkedin_url se normalise au même slug ⇒ contact_id posé', function () {
    foreach (DPT_MODES as $mode) {
        $ws = dptEspace();
        $exp = dptExpediteur();
        $contact = dptContact($ws, 'https://fr.linkedin.com/in/' . strtoupper(DPT_PROFIL) . '/?originalSubdomain=fr');
        dptContact($ws, 'https://www.linkedin.com/in/' . DPT_PROFIL . '-autre/', 'Voisin');
        dptContact($ws, null, 'SansProfil');
        dptSous($mode, $ws, function () use ($ws, $exp, $mode, $contact) {
            $r = dptAjouter($ws, $exp, 'https://www.linkedin.com/in/' . DPT_PROFIL . '/');
            expect($r['contact_id'])->toBe($contact, $mode);
            $ligne = WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('workspace_id', $ws)->first());
            expect((int) $ligne->contact_id)->toBe($contact);
        });
    }
});

test('deux contacts pour le même profil ⇒ aucun rattachement + journal « rapprochement_ambigu »', function () {
    foreach (DPT_MODES as $mode) {
        $ws = dptEspace();
        $exp = dptExpediteur();
        dptContact($ws, 'https://www.linkedin.com/in/' . DPT_PROFIL . '/', 'Un');
        dptContact($ws, 'https://fr.linkedin.com/in/' . DPT_PROFIL, 'Deux');
        dptSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $r = dptAjouter($ws, $exp, DPT_PROFIL);
            expect($r['contact_id'])->toBeNull($mode);
            $j = dptJournal($ws, $r['prospect_id']);
            expect($j)->toHaveCount(1);
            expect(json_decode((string) $j[0]->details, true))->toMatchArray(['rapprochement_ambigu' => true]);
        });
    }
});

test('contact d\'un autre espace jamais rattaché', function () {
    $a = dptEspace();
    $b = dptEspace();
    $exp = dptExpediteur();
    dptContact($a, 'https://www.linkedin.com/in/' . DPT_PROFIL . '/');
    foreach (DPT_MODES as $mode) {
        dptSous($mode, $b, function () use ($b, $exp, $mode) {
            expect(dptAjouter($b, $exp, DPT_PROFIL)['contact_id'])->toBeNull($mode);
        });
    }
});

test('double clic « invitation_faite » (même clé) ⇒ une ligne de journal, un seul changement', function () {
    foreach (DPT_MODES as $mode) {
        $ws = dptEspace();
        $exp = dptExpediteur();
        dptSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $id = dptAjouter($ws, $exp, DPT_PROFIL)['prospect_id'];
            $cle = 'clic-' . Str::uuid();
            $depot = new DepotProspects;
            $premier = $depot->appliquerGeste($ws, $id, 'invitation_faite', $cle, 'le fondateur', []);
            $second = $depot->appliquerGeste($ws, $id, 'invitation_faite', $cle, 'le fondateur', []);
            expect($premier)->toMatchArray(['etat' => 'invite', 'sans_effet' => false, 'rejeu' => false], $mode)
                ->and($second)->toMatchArray(['etat' => 'invite', 'sans_effet' => false, 'rejeu' => true]);
            expect(dptEtat($ws, $id))->toBe('invite');
            $gestes = array_filter(dptJournal($ws, $id), static fn (object $l): bool => $l->evenement === 'invitation_faite');
            expect($gestes)->toHaveCount(1);
        });
    }
});

test('« a_repondu » avant « message_fait » ⇒ état repondu ; le geste tardif est sans effet', function () {
    foreach (DPT_MODES as $mode) {
        $ws = dptEspace();
        $exp = dptExpediteur();
        dptSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $id = dptAjouter($ws, $exp, DPT_PROFIL)['prospect_id'];
            $depot = new DepotProspects;
            $r1 = $depot->appliquerGeste($ws, $id, 'a_repondu', 'clic-' . Str::uuid(), 'le fondateur', []);
            $r2 = $depot->appliquerGeste($ws, $id, 'message_fait', 'clic-' . Str::uuid(), 'le fondateur', []);
            expect($r1['etat'])->toBe('repondu', $mode)->and($r2)->toMatchArray(['etat' => 'repondu', 'sans_effet' => true]);
            expect(dptEtat($ws, $id))->toBe('repondu');
            $tardif = array_values(array_filter(dptJournal($ws, $id), static fn (object $l): bool => $l->evenement === 'message_fait'));
            expect($tardif)->toHaveCount(1)->and((bool) $tardif[0]->sans_effet)->toBeTrue()
                ->and($tardif[0]->etat_avant)->toBe('repondu')->and($tardif[0]->etat_apres)->toBe('repondu');
        });
    }
});

test('« opposition » puis « invitation_acceptee » ⇒ reste écarté', function () {
    foreach (DPT_MODES as $mode) {
        $ws = dptEspace();
        $exp = dptExpediteur();
        dptSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $id = dptAjouter($ws, $exp, DPT_PROFIL)['prospect_id'];
            $depot = new DepotProspects;
            expect($depot->appliquerGeste($ws, $id, 'opposition', 'clic-' . Str::uuid(), 'le fondateur', [])['etat'])->toBe('ecarte', $mode);
            $r = $depot->appliquerGeste($ws, $id, 'invitation_acceptee', 'clic-' . Str::uuid(), 'le fondateur', []);
            expect($r)->toMatchArray(['etat' => 'ecarte', 'sans_effet' => true]);
            expect(dptEtat($ws, $id))->toBe('ecarte');
        });
    }
});

test('geste inconnu ⇒ exception, ni journal ni changement', function () {
    $ws = dptEspace();
    $exp = dptExpediteur();
    $id = dptAjouter($ws, $exp, DPT_PROFIL)['prospect_id'];
    expect(fn () => (new DepotProspects)->appliquerGeste($ws, $id, 'geste_inconnu', 'clic-' . Str::uuid(), 'le fondateur', []))->toThrow(InvalidArgumentException::class);
    expect(dptEtat($ws, $id))->toBe('candidat')->and(dptJournal($ws, $id))->toHaveCount(1); // seul l'ajout
});

test('prospect de l\'espace A manipulé par l\'espace B ⇒ introuvable, rien ne change', function () {
    foreach (DPT_MODES as $mode) {
        $a = dptEspace();
        $b = dptEspace();
        $exp = dptExpediteur();
        dptSous($mode, $a, function () use ($a, $b, $exp, $mode) {
            $id = dptAjouter($a, $exp, DPT_PROFIL)['prospect_id'];
            expect(fn () => (new DepotProspects)->appliquerGeste($b, $id, 'invitation_faite', 'clic-' . Str::uuid(), 'le fondateur', []))
                ->toThrow(RuntimeException::class, 'introuvable');
            expect(dptEtat($a, $id))->toBe('candidat', $mode)->and(dptJournal($b))->toBe([]);
            // B dépose le même profil : une AUTRE ligne.
            $autre = dptAjouter($b, $exp, DPT_PROFIL);
            expect($autre['cree'])->toBeTrue()->and($autre['prospect_id'])->not->toBe($id);
            expect(dptNbProspects($a))->toBe(1)->and(dptNbProspects($b))->toBe(1);
        });
    }
});

test('la clé d\'idempotence d\'un autre prospect n\'est pas rejouée', function () {
    $ws = dptEspace();
    $exp = dptExpediteur();
    $p1 = dptAjouter($ws, $exp, DPT_PROFIL)['prospect_id'];
    $p2 = dptAjouter($ws, $exp, 'autre-profil-fictif-9z9z9z')['prospect_id'];
    $cle = 'clic-' . Str::uuid();
    (new DepotProspects)->appliquerGeste($ws, $p1, 'invitation_faite', $cle, 'le fondateur', []);
    expect(fn () => (new DepotProspects)->appliquerGeste($ws, $p2, 'invitation_faite', $cle, 'le fondateur', []))->toThrow(InvalidArgumentException::class);
    expect(dptEtat($ws, $p2))->toBe('candidat');
});

test('le geste verrouille la ligne du prospect (SELECT … FOR UPDATE) avant de décider', function () {
    $ws = dptEspace();
    $exp = dptExpediteur();
    $id = dptAjouter($ws, $exp, DPT_PROFIL)['prospect_id'];
    DB::flushQueryLog();
    DB::enableQueryLog();
    (new DepotProspects)->appliquerGeste($ws, $id, 'invitation_faite', 'clic-' . Str::uuid(), 'le fondateur', []);
    $requetes = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();
    $verrou = array_keys(array_filter($requetes, static fn (string $q): bool => str_contains($q, 'pl_prospects') && stripos($q, 'for update') !== false));
    $maj = array_keys(array_filter($requetes, static fn (string $q): bool => stripos($q, 'update "pl_prospects"') !== false));
    expect($verrou)->not->toBe([])->and($maj)->not->toBe([])->and($verrou[0])->toBeLessThan($maj[0]);
});

test('journal : liste blanche de clés, jamais d\'adresse ; seul écrivain de pl_journal', function () {
    foreach (DPT_MODES as $mode) {
        $ws = dptEspace();
        $exp = dptExpediteur();
        dptSous($mode, $ws, function () use ($ws, $exp, $mode) {
            $id = dptAjouter($ws, $exp, DPT_PROFIL)['prospect_id'];
            (new DepotProspects)->appliquerGeste($ws, $id, 'non', 'clic-' . Str::uuid(), 'le fondateur', [
                'motif' => 'pas intéressé', 'courriel' => 'quelquun@example.test', 'cle_inconnue' => 'x', 'note' => 'écrit à autre@example.test',
            ]);
            $ligne = array_values(array_filter(dptJournal($ws, $id), static fn (object $l): bool => $l->evenement === 'non'))[0];
            $details = json_decode((string) $ligne->details, true);
            expect($details)->toBe(['motif' => 'pas intéressé'], $mode);
            expect((string) $ligne->details)->not->toContain('@');
            expect(JournalProspection::CLES_AUTORISEES)->not->toContain('courriel');
        });
    }
});

test('aucune suppression : DELETE de pl_prospects et UPDATE/DELETE de pl_journal refusés', function () {
    $ws = dptEspace();
    $exp = dptExpediteur();
    $id = dptAjouter($ws, $exp, DPT_PROFIL)['prospect_id'];
    expect(fn () => DB::transaction(fn () => DB::table('pl_prospects')->where('workspace_id', $ws)->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('pl_journal')->where('workspace_id', $ws)->delete()))->toThrow(QueryException::class);
    expect(dptNbProspects($ws))->toBe(1)->and(dptEtat($ws, $id))->toBe('candidat');
    expect(file_get_contents(base_path('app/ProspectionLinkedIn/DepotProspects.php')))->not->toMatch('/->delete\(|DELETE FROM|TRUNCATE/i');
});

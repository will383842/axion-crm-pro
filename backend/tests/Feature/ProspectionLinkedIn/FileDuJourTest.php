<?php

use App\Envoi\Conformite\RegistreOppositionManuel;
use App\ProspectionLinkedIn\Arret;
use App\ProspectionLinkedIn\FileDuJour;
use App\ProspectionLinkedIn\Plafonds;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot PONT-LINKEDIN LI-B2 — contrôles, plafonds, arrêt et file du jour. Chaque test tourne SOUS LE RÔLE APPLICATIF ET SOUS LE
 * PROPRIÉTAIRE. Profils et adresses fictifs seulement. Aucun appel réseau, aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const FDJ_MODES = ['app', 'proprio'];

/** Jeudi 19 novembre 2026, 10 h (Paris). */
function fdjJeudi(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-19 10:00:00', 'Europe/Paris');
}

function fdjEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Fdj', 'slug' => 'fdj-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function fdjExpediteur(): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(['id' => $id, 'email' => 'fondateur-' . substr($id, 0, 8) . '@example.test', 'name' => 'Expediteur', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Fiche + contact (adresse d'exemple) dans l'espace ; rend l'id du contact. */
function fdjContact(string $ws, string $email, ?string $siren = null): int
{
    return WorkspaceContext::run($ws, static function () use ($ws, $email, $siren): int {
        $societe = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => $siren ?? (string) random_int(100000000, 999999999), 'denomination' => 'Societe fdj', 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $societe, 'first_name' => 'Prenom', 'last_name' => 'Nom', 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
    });
}

/** @param array<string, mixed> $col */
function fdjProspect(string $ws, string $exp, array $col = []): string
{
    static $n = 0;
    $id = (string) Str::uuid();
    $n++;
    WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->insert(array_merge([
        'id' => $id, 'workspace_id' => $ws, 'expediteur_user_id' => $exp, 'linkedin_slug' => 'exemple-prenom-nom-' . $n . substr($id, 0, 4),
        'source' => 'recherche', 'source_le' => '2026-11-01', 'score' => 80, 'etat' => 'qualifie',
    ], $col)));

    return $id;
}

function fdjEtat(string $ws, string $id): string
{
    return (string) WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('workspace_id', $ws)->where('id', $id)->value('etat'));
}

/** @return list<object> */
function fdjJournal(string $ws, ?string $evenement = null): array
{
    return WorkspaceContext::run($ws, static fn (): array => DB::table('pl_journal')->where('workspace_id', $ws)
        ->when($evenement !== null, static fn ($q) => $q->where('evenement', $evenement))->orderBy('id')->get()->all());
}

/** Dépose $n lignes « invitation_faite » à la date donnée pour le prospect (journal en ajout seul, date explicite). */
function fdjInvitations(string $ws, string $prospect, int $n, string $le): void
{
    WorkspaceContext::run($ws, static function () use ($ws, $prospect, $n, $le): void {
        for ($i = 0; $i < $n; $i++) {
            DB::table('pl_journal')->insert(['workspace_id' => $ws, 'prospect_id' => $prospect, 'evenement' => 'invitation_faite', 'origine' => 'humain',
                'auteur' => 'le fondateur', 'cle_idempotence' => 'fdj-inv-' . Str::uuid(), 'recu_le' => $le]);
        }
    });
}

function fdjPlafonds(string $ws, string $exp, int $jour, int $semaine, bool $ouvres = true): void
{
    WorkspaceContext::run($ws, static fn () => DB::table('pl_plafonds')->insert(['workspace_id' => $ws, 'expediteur_user_id' => $exp, 'invitations_jour' => $jour,
        'invitations_semaine' => $semaine, 'messages_jour' => 20, 'notes_mois' => 5, 'jours_ouvres_seulement' => $ouvres, 'auteur' => 'le fondateur']));
}

function fdjArret(string $ws, ?string $exp, string $geste = 'arret'): void
{
    WorkspaceContext::run($ws, static fn () => DB::table('pl_arret')->insert(['workspace_id' => $ws, 'expediteur_user_id' => $exp, 'geste' => $geste,
        'motif' => 'arret de precaution du test', 'auteur' => 'le fondateur', 'declencheur' => 'humain']));
}

/** Exécute $f dans une transaction annulée ; en mode « app », sous le rôle applicatif non propriétaire. */
function fdjSous(string $mode, string $ws, Closure $f): mixed
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

/** Rend une table illisible (renommée) pour le propriétaire comme pour le rôle applicatif. */
function fdjCasser(string $mode, string $table): void
{
    if ($mode === 'app') {
        DB::statement('RESET ROLE');
    }
    DB::statement("ALTER TABLE {$table} RENAME TO {$table}_cassee");
    if ($mode === 'app') {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
}

test('registre d\'opposition illisible ⇒ file vide, alerte journalisée, aucun état changé', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $p = fdjProspect($ws, $exp);
        fdjSous($mode, $ws, function () use ($ws, $exp, $p, $mode) {
            fdjCasser($mode, 'envoi_oppositions_linkedin');
            $r = (new FileDuJour)->construire($ws, $exp, fdjJeudi());
            expect($r['prospects'])->toBe([], $mode)->and($r['alerte'])->toBeTrue()->and($r['raison'])->toBe('controle_illisible')
                ->and(fdjEtat($ws, $p))->toBe('qualifie')->and(count(fdjJournal($ws, 'alerte')))->toBe(1)->and(count(fdjJournal($ws, 'controle_refuse')))->toBe(0);
        });
    }
});

test('opposition par adresse d\'une fiche rattachée ⇒ écarté, jamais en file', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $contact = fdjContact($ws, 'oppose@example.test');
        $oppose = fdjProspect($ws, $exp, ['contact_id' => $contact]);
        $libre = fdjProspect($ws, $exp, ['score' => 70]);
        WorkspaceContext::run($ws, static fn () => (new RegistreOppositionManuel)->inscrire($ws, 'adresse', 'oppose@example.test', 'test', 'le fondateur'));
        fdjSous($mode, $ws, function () use ($ws, $exp, $oppose, $libre, $mode) {
            $r = (new FileDuJour)->construire($ws, $exp, fdjJeudi());
            expect($r['prospects'])->toBe([$libre], $mode)->and(fdjEtat($ws, $oppose))->toBe('ecarte')->and(fdjEtat($ws, $libre))->toBe('en_file');
        });
    }
});

test('opposition par SIREN d\'une fiche rattachée ⇒ écarté', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $oppose = fdjProspect($ws, $exp, ['siren' => '123456782']);
        WorkspaceContext::run($ws, static fn () => (new RegistreOppositionManuel)->inscrire($ws, 'organisation', '123456782', 'test', 'le fondateur'));
        fdjSous($mode, $ws, function () use ($ws, $exp, $oppose, $mode) {
            expect((new FileDuJour)->construire($ws, $exp, fdjJeudi())['prospects'])->toBe([], $mode)->and(fdjEtat($ws, $oppose))->toBe('ecarte');
        });
    }
});

test('demande RGPD en cours, fiche presse, société cliente ⇒ exclus avec geste controle_refuse et motif', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $cRgpd = fdjContact($ws, 'rgpd@example.test');
        $cPresse = fdjContact($ws, 'presse@example.test');
        $cClient = fdjContact($ws, 'client@example.test');
        WorkspaceContext::run($ws, static function () use ($ws, $cRgpd, $cPresse, $cClient): void {
            DB::table('rgpd_requests')->insert(['workspace_id' => $ws, 'type' => 'access', 'status' => 'pending', 'subject_email' => 'rgpd@example.test', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('contacts')->where('id', $cPresse)->update(['external_ref' => 'journaliste:42']);
            DB::table('contacts')->where('id', $cClient)->update(['discovery_source' => 'console']);
        });
        $pRgpd = fdjProspect($ws, $exp, ['contact_id' => $cRgpd]);
        $pPresse = fdjProspect($ws, $exp, ['contact_id' => $cPresse]);
        $pClient = fdjProspect($ws, $exp, ['contact_id' => $cClient]);
        fdjSous($mode, $ws, function () use ($ws, $exp, $pRgpd, $pPresse, $pClient, $mode) {
            expect((new FileDuJour)->construire($ws, $exp, fdjJeudi())['prospects'])->toBe([], $mode);
            $motifs = [];
            foreach (fdjJournal($ws, 'controle_refuse') as $l) {
                $motifs[(string) $l->prospect_id] = json_decode((string) $l->details, true)['motif'];
            }
            expect($motifs)->toBe([$pRgpd => 'rgpd_en_cours', $pPresse => 'presse', $pClient => 'client'])
                ->and(fdjEtat($ws, $pRgpd))->toBe('qualifie');
        });
    }
});

test('contacté par courriel il y a 30 jours ⇒ exclu ; il y a 120 jours ⇒ inclus', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $recent = fdjProspect($ws, $exp, ['contact_id' => fdjContact($ws, 'recent@example.test')]);
        $ancien = fdjProspect($ws, $exp, ['contact_id' => fdjContact($ws, 'ancien@example.test'), 'score' => 70]);
        $seq = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $seq, 'workspace_id' => $ws, 'nom' => 'Sequence fdj']);
        foreach ([['recent@example.test', '2026-10-20 09:00:00+01'], ['ancien@example.test', '2026-07-21 09:00:00+01']] as [$adresse, $le]) {
            $c = (int) DB::table('contacts')->where('workspace_id', $ws)->where('email', $adresse)->value('id');
            DB::table('envoi_inscriptions')->insert(['workspace_id' => $ws, 'sequence_id' => $seq, 'contact_id' => $c, 'entreprise_id' => (int) DB::table('contacts')->where('id', $c)->value('company_id'),
                'empreinte_adresse' => App\Support\ListeSuppression::empreinte($adresse), 'prochaine_echeance' => $le, 'version_figee' => '{}', 'inscrite_le' => $le]);
        }
        fdjSous($mode, $ws, function () use ($ws, $exp, $recent, $ancien, $mode) {
            $r = (new FileDuJour)->construire($ws, $exp, fdjJeudi());
            expect($r['prospects'])->toBe([$ancien], $mode)->and(fdjEtat($ws, $recent))->toBe('qualifie');
            expect(json_decode((string) fdjJournal($ws, 'controle_refuse')[0]->details, true)['motif'])->toBe('courriel_recent');
        });
    }
});

test('40 prospects, 12 invitations restantes dans la semaine ⇒ 12 ; tri score puis ancienneté', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        fdjPlafonds($ws, $exp, 25, 50);
        $hist = fdjProspect($ws, $exp, ['etat' => 'invite']);
        fdjInvitations($ws, $hist, 38, '2026-11-17 11:00:00+01'); // mardi de la même semaine ISO
        fdjInvitations($ws, $hist, 40, '2026-11-09 11:00:00+01'); // semaine précédente : ne compte pas
        $ids = [];
        for ($i = 0; $i < 40; $i++) {
            $ids[] = fdjProspect($ws, $exp, ['score' => 60 + ($i % 40)]);
        }
        fdjSous($mode, $ws, function () use ($ws, $exp, $mode) {
            expect((new Plafonds)->restants($ws, $exp, fdjJeudi()))->toBe(['jour' => 25, 'semaine' => 12], $mode);
            $r = (new FileDuJour)->construire($ws, $exp, fdjJeudi());
            expect(count($r['prospects']))->toBe(12);
            $scores = array_map(static fn (string $id): int => (int) WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('id', $id)->value('score')), $r['prospects']);
            expect($scores)->toBe([99, 98, 97, 96, 95, 94, 93, 92, 91, 90, 89, 88]);
        });
    }
});

test('défauts sans ligne de plafonds ; score sous le seuil exclu ; jour compté', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $hist = fdjProspect($ws, $exp, ['etat' => 'invite']);
        fdjInvitations($ws, $hist, 4, '2026-11-19 08:00:00+01'); // aujourd'hui
        $bas = fdjProspect($ws, $exp, ['score' => 59]);
        $ok = fdjProspect($ws, $exp, ['score' => 60]);
        fdjSous($mode, $ws, function () use ($ws, $exp, $bas, $ok, $mode) {
            expect((new Plafonds)->restants($ws, $exp, fdjJeudi()))->toBe(['jour' => 6, 'semaine' => 46], $mode);
            expect((new FileDuJour)->construire($ws, $exp, fdjJeudi())['prospects'])->toBe([$ok])->and(fdjEtat($ws, $bas))->toBe('qualifie');
        });
    }
});

test('samedi et dimanche ⇒ 0 (jours ouvrés seulement) ; autorisé si le plafond le permet', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $p = fdjProspect($ws, $exp);
        $samedi = CarbonImmutable::parse('2026-11-21 10:00:00', 'Europe/Paris');
        $dimanche = CarbonImmutable::parse('2026-11-22 10:00:00', 'Europe/Paris');
        fdjSous($mode, $ws, function () use ($ws, $exp, $p, $samedi, $dimanche, $mode) {
            expect((new Plafonds)->restants($ws, $exp, $samedi))->toBe(['jour' => 0, 'semaine' => 0], $mode)
                ->and((new Plafonds)->restants($ws, $exp, $dimanche))->toBe(['jour' => 0, 'semaine' => 0]);
            $r = (new FileDuJour)->construire($ws, $exp, $samedi);
            expect($r['prospects'])->toBe([])->and($r['raison'])->toBe('plafond')->and(fdjEtat($ws, $p))->toBe('qualifie');
            fdjPlafonds($ws, $exp, 10, 50, false);
            expect((new Plafonds)->restants($ws, $exp, $samedi)['jour'])->toBe(10);
        });
    }
});

test('arrêt actif ⇒ file vide avec raison ; reprise ⇒ file normale ; arrêt de A sans effet sur B', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $a = fdjExpediteur();
        $b = fdjExpediteur();
        $pa = fdjProspect($ws, $a);
        $pb = fdjProspect($ws, $b);
        fdjArret($ws, $a);
        fdjSous($mode, $ws, function () use ($ws, $a, $b, $pa, $pb, $mode) {
            $ra = (new FileDuJour)->construire($ws, $a, fdjJeudi());
            expect($ra['prospects'])->toBe([], $mode)->and($ra['raison'])->toBe('arret')->and(fdjEtat($ws, $pa))->toBe('qualifie')
                ->and((new Arret)->actif($ws, $a))->toBeTrue()->and((new Arret)->actif($ws, $b))->toBeFalse();
            expect((new FileDuJour)->construire($ws, $b, fdjJeudi())['prospects'])->toBe([$pb]);
            fdjArret($ws, $a, 'reprise');
            expect((new Arret)->actif($ws, $a))->toBeFalse();
        });
    }
});

test('arrêt « tous » (sans expéditeur) ⇒ actif pour chacun ; erreur de lecture ⇒ considéré actif', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $a = fdjExpediteur();
        fdjArret($ws, null);
        fdjSous($mode, $ws, function () use ($ws, $a, $mode) {
            expect((new Arret)->actif($ws, $a))->toBeTrue($mode);
            fdjArret($ws, null, 'reprise');
            expect((new Arret)->actif($ws, $a))->toBeFalse();
            fdjCasser($mode, 'pl_arret');
            expect((new Arret)->actif($ws, $a))->toBeTrue();
        });
    }
});

test('arrêt posé PENDANT la construction (entre lecture et écriture) ⇒ relu avant l\'UPDATE, 0 prospect, états inchangés', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $p = fdjProspect($ws, $exp);
        fdjSous($mode, $ws, function () use ($ws, $exp, $p, $mode) {
            $file = new FileDuJour(avantEcriture: static fn () => fdjArret($ws, $exp));
            $r = $file->construire($ws, $exp, fdjJeudi());
            expect($r['prospects'])->toBe([], $mode)->and($r['raison'])->toBe('arret')->and(fdjEtat($ws, $p))->toBe('qualifie');
        });
    }
});

test('opposition posée PENDANT la construction ⇒ relue avant l\'UPDATE : écarté, pas en file', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $contact = fdjContact($ws, 'tardif@example.test');
        $p = fdjProspect($ws, $exp, ['contact_id' => $contact]);
        fdjSous($mode, $ws, function () use ($ws, $exp, $p, $mode) {
            $file = new FileDuJour(avantEcriture: static fn () => (new RegistreOppositionManuel)->inscrire($ws, 'adresse', 'tardif@example.test', 'test', 'le fondateur'));
            $r = $file->construire($ws, $exp, fdjJeudi());
            expect($r['prospects'])->toBe([], $mode)->and(fdjEtat($ws, $p))->toBe('ecarte');
        });
    }
});

test('invitation retirée : exclue avant 21 jours, incluse après ; « plus tard » échu inclus, non échu exclu', function () {
    foreach (FDJ_MODES as $mode) {
        $ws = fdjEspace();
        $exp = fdjExpediteur();
        $recent = fdjProspect($ws, $exp, ['etat' => 'invitation_retiree']);
        $ancien = fdjProspect($ws, $exp, ['etat' => 'invitation_retiree', 'score' => 70]);
        $echu = fdjProspect($ws, $exp, ['etat' => 'plus_tard', 'relance_le' => '2026-11-18', 'score' => 65]);
        $futur = fdjProspect($ws, $exp, ['etat' => 'plus_tard', 'relance_le' => '2026-11-25', 'score' => 90]);
        foreach ([[$recent, '2026-11-09 10:00:00+01'], [$ancien, '2026-10-20 10:00:00+01']] as [$id, $le]) {
            WorkspaceContext::run($ws, static fn () => DB::table('pl_journal')->insert(['workspace_id' => $ws, 'prospect_id' => $id, 'evenement' => 'invitation_retiree', 'origine' => 'humain',
                'auteur' => 'le fondateur', 'cle_idempotence' => 'fdj-ret-' . Str::uuid(), 'recu_le' => $le]));
        }
        fdjSous($mode, $ws, function () use ($ws, $exp, $recent, $ancien, $echu, $futur, $mode) {
            $r = (new FileDuJour)->construire($ws, $exp, fdjJeudi());
            expect($r['prospects'])->toBe([$ancien, $echu], $mode)->and(fdjEtat($ws, $recent))->toBe('invitation_retiree')->and(fdjEtat($ws, $futur))->toBe('plus_tard');
        });
    }
});

test('espace B : ne voit ni ne touche les prospects de A ; plafonds et arrêt de A sans effet sur B', function () {
    foreach (FDJ_MODES as $mode) {
        $wsA = fdjEspace();
        $wsB = fdjEspace();
        $exp = fdjExpediteur();
        $pa = fdjProspect($wsA, $exp);
        fdjArret($wsA, $exp);
        $hist = fdjProspect($wsA, $exp, ['etat' => 'invite']);
        fdjInvitations($wsA, $hist, 10, '2026-11-19 08:00:00+01');
        fdjSous($mode, $wsB, function () use ($wsA, $wsB, $exp, $pa, $mode) {
            expect((new Arret)->actif($wsB, $exp))->toBeFalse($mode)->and((new Plafonds)->restants($wsB, $exp, fdjJeudi()))->toBe(['jour' => 10, 'semaine' => 50]);
            expect((new FileDuJour)->construire($wsB, $exp, fdjJeudi())['prospects'])->toBe([]);
            expect(fdjEtat($wsA, $pa))->toBe(''); // invisible depuis B sous RLS ; en propriétaire, le filtre d'espace de fdjEtat le prouve aussi
        });
    }
});

test('aucune suppression : la file ne retire ni prospect ni journal', function () {
    $ws = fdjEspace();
    $exp = fdjExpediteur();
    $contact = fdjContact($ws, 'efface@example.test');
    fdjProspect($ws, $exp, ['contact_id' => $contact]);
    fdjProspect($ws, $exp);
    WorkspaceContext::run($ws, static fn () => (new RegistreOppositionManuel)->inscrire($ws, 'adresse', 'efface@example.test', 'test', 'le fondateur'));
    (new FileDuJour)->construire($ws, $exp, fdjJeudi());
    expect(WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('workspace_id', $ws)->count()))->toBe(2);
    $sources = file_get_contents(base_path('app/ProspectionLinkedIn/FileDuJour.php')) . file_get_contents(base_path('app/ProspectionLinkedIn/ControlesProspect.php'))
        . file_get_contents(base_path('app/ProspectionLinkedIn/Plafonds.php')) . file_get_contents(base_path('app/ProspectionLinkedIn/Arret.php'));
    expect($sources)->not->toContain('->delete(')->not->toContain('DELETE FROM')->not->toContain('TRUNCATE')->not->toContain('Http::');
});

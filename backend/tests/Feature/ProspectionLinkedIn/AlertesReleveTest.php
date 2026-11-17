<?php

use App\ProspectionLinkedIn\Alertes;
use App\ProspectionLinkedIn\Arret;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot PONT-LINKEDIN LI-E — alertes, rappels de relance et relevé hebdomadaire. Chaque test de sécurité tourne SOUS LE RÔLE
 * APPLICATIF ET SOUS LE PROPRIÉTAIRE. Profils fictifs, aucune adresse. Aucun appel réseau, aucun envoi : les alertes vont au
 * seul fondateur (cloche et courriel), et restent dans le journal tant que `alertes.actif` est éteint.
 */
uses(TestCase::class, RefreshDatabase::class);

const ALE_MODES = ['app', 'proprio'];

/** Lundi 16 novembre 2026, 8 h (Paris). */
function aleLundi(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-16 08:00:00', 'Europe/Paris');
}

function aleEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Ale', 'slug' => 'ale-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function aleExpediteur(): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(['id' => $id, 'email' => 'fondateur-' . substr($id, 0, 8) . '@example.test', 'name' => 'Expediteur', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** @param array<string, mixed> $col */
function aleProspect(string $ws, string $exp, array $col = []): string
{
    static $n = 0;
    $id = (string) Str::uuid();
    $n++;
    WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->insert(array_merge([
        'id' => $id, 'workspace_id' => $ws, 'expediteur_user_id' => $exp, 'linkedin_slug' => 'profil-secret-' . $n . substr($id, 0, 4),
        'source' => 'recherche', 'source_le' => '2026-11-01', 'score' => 80, 'etat' => 'invite',
    ], $col)));

    return $id;
}

/** $total invitations faites (une par prospect), dont $acceptees suivies d'une acceptation. Les plus anciennes d'abord. */
function aleInvitations(string $ws, string $exp, int $total, int $acceptees, string $depuis = '2026-10-01 09:00:00'): void
{
    $t = CarbonImmutable::parse($depuis, 'Europe/Paris');
    for ($i = 0; $i < $total; $i++) {
        $p = aleProspect($ws, $exp);
        WorkspaceContext::run($ws, static function () use ($ws, $p, $i, $acceptees, $t): void {
            DB::table('pl_journal')->insert(['workspace_id' => $ws, 'prospect_id' => $p, 'evenement' => 'invitation_faite', 'origine' => 'humain',
                'auteur' => 'le fondateur', 'cle_idempotence' => 'ale-inv-' . Str::uuid(), 'recu_le' => $t->addMinutes($i * 2)->toIso8601String()]);
            if ($i < $acceptees) {
                DB::table('pl_journal')->insert(['workspace_id' => $ws, 'prospect_id' => $p, 'evenement' => 'invitation_acceptee', 'origine' => 'humain',
                    'auteur' => 'le fondateur', 'cle_idempotence' => 'ale-acc-' . Str::uuid(), 'recu_le' => $t->addMinutes($i * 2 + 1)->toIso8601String()]);
            }
        });
    }
}

/** @return list<object> */
function aleArrets(string $ws): array
{
    return WorkspaceContext::run($ws, static fn (): array => DB::table('pl_arret')->where('workspace_id', $ws)->orderBy('id')->get()->all());
}

/** @return list<object> */
function aleJournal(string $ws, string $evenement): array
{
    return WorkspaceContext::run($ws, static fn (): array => DB::table('pl_journal')->where('workspace_id', $ws)->where('evenement', $evenement)->orderBy('id')->get()->all());
}

/** Exécute $f dans une transaction annulée ; en mode « app », sous le rôle applicatif non propriétaire. */
function aleSous(string $mode, string $ws, Closure $f): mixed
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

/** Un espion de journal NEUF (Log::spy() réutilise sinon celui du tour précédent et fausse les `once()`). */
function aleEspion(): void
{
    Facade::clearResolvedInstance('log');
    app()->forgetInstance('log');
    Log::spy();
}

function aleAlertes(): Alertes
{
    return app(Alertes::class);
}

beforeEach(function () {
    config(['prospection_linkedin.active' => true]);
});

test('50 invitations dont 9 acceptées (18 %) ⇒ arrêt automatique posé, tracé et alerté', function () {
    foreach (ALE_MODES as $mode) {
        $ws = aleEspace();
        $exp = aleExpediteur();
        aleSous($mode, $ws, function () use ($ws, $exp) {
            aleInvitations($ws, $exp, 50, 9);
            aleEspion();

            $r = aleAlertes()->executerEspace($ws, CarbonImmutable::parse('2026-11-19 08:00:00', 'Europe/Paris'));

            expect($r['arrets'])->toBe(1);
            $arrets = aleArrets($ws);
            expect($arrets)->toHaveCount(1)
                ->and($arrets[0]->geste)->toBe('arret')
                ->and($arrets[0]->declencheur)->toBe('acceptation_basse')
                ->and($arrets[0]->auteur)->toBe('systeme')
                ->and($arrets[0]->expediteur_user_id)->toBe($exp)
                ->and($arrets[0]->motif)->not->toContain('@');
            expect((new Arret)->actif($ws, $exp))->toBeTrue();
            expect(aleJournal($ws, 'arret'))->toHaveCount(1);
            Log::shouldHaveReceived('critical')->withArgs(static fn (string $m): bool => str_contains($m, '[ALERTE]') && str_contains($m, 'LinkedIn'))->once();
        });
    }
});

test('50 invitations dont 10 acceptées (20 %) ⇒ rien, ni arrêt ni alerte', function () {
    foreach (ALE_MODES as $mode) {
        $ws = aleEspace();
        $exp = aleExpediteur();
        aleSous($mode, $ws, function () use ($ws, $exp) {
            aleInvitations($ws, $exp, 50, 10);
            aleEspion();

            $r = aleAlertes()->executerEspace($ws, CarbonImmutable::parse('2026-11-19 08:00:00', 'Europe/Paris'));

            expect($r['arrets'])->toBe(0)
                ->and(aleArrets($ws))->toBe([])
                ->and((new Arret)->actif($ws, $exp))->toBeFalse();
            Log::shouldNotHaveReceived('critical');
        });
    }
});

test('moins de 50 invitations ⇒ aucun jugement ; seules les 50 dernières comptent', function () {
    foreach (ALE_MODES as $mode) {
        $ws = aleEspace();
        $a = aleExpediteur();
        $b = aleExpediteur();
        aleSous($mode, $ws, function () use ($ws, $a, $b) {
            aleInvitations($ws, $a, 49, 0);
            // 20 anciennes sans acceptation, puis 50 récentes dont 10 acceptées : 20 % sur les 50 dernières ⇒ rien.
            aleInvitations($ws, $b, 20, 0, '2026-09-01 09:00:00');
            aleInvitations($ws, $b, 50, 10, '2026-10-20 09:00:00');

            $r = aleAlertes()->executerEspace($ws, CarbonImmutable::parse('2026-11-19 08:00:00', 'Europe/Paris'));

            expect($r['arrets'])->toBe(0)->and(aleArrets($ws))->toBe([]);
        });
    }
});

test('un arrêt déjà actif n’est pas posé une seconde fois (relance sans effet)', function () {
    foreach (ALE_MODES as $mode) {
        $ws = aleEspace();
        $exp = aleExpediteur();
        aleSous($mode, $ws, function () use ($ws, $exp) {
            aleInvitations($ws, $exp, 50, 5);
            $t = CarbonImmutable::parse('2026-11-19 08:00:00', 'Europe/Paris');
            aleAlertes()->executerEspace($ws, $t);
            aleAlertes()->executerEspace($ws, $t->addDay());

            expect(aleArrets($ws))->toHaveCount(1)->and(aleJournal($ws, 'arret'))->toHaveCount(1);
        });
    }
});

test('espace B : les invitations d’un autre espace ne déclenchent rien ici, et inversement', function () {
    foreach (ALE_MODES as $mode) {
        $wsA = aleEspace();
        $wsB = aleEspace();
        $expA = aleExpediteur();
        $expB = aleExpediteur();
        // Les deux espaces sont préparés sous le propriétaire ; seul l'espace A est traité sous le rôle testé.
        DB::beginTransaction();
        try {
            aleInvitations($wsA, $expA, 50, 25);
            aleInvitations($wsB, $expB, 50, 1);
            $t = CarbonImmutable::parse('2026-11-19 08:00:00', 'Europe/Paris');
            if ($mode === 'app') {
                WorkspaceContext::set($wsA);
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }
            $r = aleAlertes()->executerEspace($wsA, $t);
            expect($r['arrets'])->toBe(0)->and(aleArrets($wsA))->toBe([]);
            if ($mode === 'app') {
                DB::statement('RESET ROLE');
            }
            WorkspaceContext::clear();
            expect(aleArrets($wsB))->toBe([]);
            $r = aleAlertes()->executerEspace($wsB, $t);
            expect($r['arrets'])->toBe(1);
            $arrets = aleArrets($wsB);
            expect($arrets)->toHaveCount(1)->and($arrets[0]->expediteur_user_id)->toBe($expB)->and(aleArrets($wsA))->toBe([]);
        } finally {
            DB::rollBack();
            WorkspaceContext::clear();
        }
    }
});

test('rappels de relance : échus seulement, une alerte par jour, sans nom ni profil', function () {
    foreach (ALE_MODES as $mode) {
        $ws = aleEspace();
        $exp = aleExpediteur();
        aleSous($mode, $ws, function () use ($ws, $exp) {
            aleProspect($ws, $exp, ['etat' => 'plus_tard', 'relance_le' => '2026-11-18']);
            aleProspect($ws, $exp, ['etat' => 'plus_tard', 'relance_le' => '2026-11-19']);
            aleProspect($ws, $exp, ['etat' => 'plus_tard', 'relance_le' => '2026-11-25']);
            aleProspect($ws, $exp, ['etat' => 'qualifie', 'relance_le' => '2026-11-01']);
            aleEspion();
            $t = CarbonImmutable::parse('2026-11-19 08:00:00', 'Europe/Paris');

            // `rappels` compte les alertes parties (une par expéditeur et par jour), pas les personnes.
            $r = aleAlertes()->executerEspace($ws, $t);
            expect($r['rappels'])->toBe(1);
            Log::shouldHaveReceived('critical')->withArgs(static fn (string $m, array $c = []): bool => str_contains($m, '2 relances à faire')
                && ! str_contains(json_encode($c, JSON_THROW_ON_ERROR), 'profil-secret') && ! str_contains($m, '@'))->once();

            // Le même jour : pas de seconde alerte ; le lendemain : de nouveau.
            expect(aleAlertes()->executerEspace($ws, $t->addHours(3))['rappels'])->toBe(0);
            expect(aleAlertes()->executerEspace($ws, $t->addDay())['rappels'])->toBe(1);
        });
    }
});

test('relevé hebdomadaire : comptes par état et par variante, aucune donnée de personne, une fois par semaine, le lundi', function () {
    foreach (ALE_MODES as $mode) {
        $ws = aleEspace();
        $exp = aleExpediteur();
        aleSous($mode, $ws, function () use ($ws, $exp) {
            aleProspect($ws, $exp, ['etat' => 'invite', 'variante' => 'A']);
            aleProspect($ws, $exp, ['etat' => 'invite', 'variante' => 'A']);
            aleProspect($ws, $exp, ['etat' => 'connecte', 'variante' => 'B']);
            aleProspect($ws, $exp, ['etat' => 'qualifie']);
            aleEspion();

            // Jeudi : pas de relevé.
            expect(aleAlertes()->executerEspace($ws, CarbonImmutable::parse('2026-11-19 08:00:00', 'Europe/Paris'))['releves'])->toBe(0);
            // Lundi : un relevé, une seule fois.
            expect(aleAlertes()->executerEspace($ws, aleLundi())['releves'])->toBe(1);
            expect(aleAlertes()->executerEspace($ws, aleLundi()->addHours(2))['releves'])->toBe(0);

            Log::shouldHaveReceived('critical')->withArgs(static function (string $m, array $c = []): bool {
                $texte = $m . json_encode($c, JSON_THROW_ON_ERROR);

                return str_contains($m, 'relevé') && ! str_contains($texte, 'profil-secret') && ! str_contains($texte, '@') && ! str_contains($texte, 'linkedin.com');
            })->once();
            $lignes = aleJournal($ws, 'alerte');
            expect($lignes)->toHaveCount(1)->and(json_encode($lignes[0]->details, JSON_THROW_ON_ERROR))->not->toContain('@');
        });
    }
});

test('le corps du relevé contient les comptes par état et par variante', function () {
    $ws = aleEspace();
    $exp = aleExpediteur();
    aleProspect($ws, $exp, ['etat' => 'invite', 'variante' => 'A']);
    aleProspect($ws, $exp, ['etat' => 'invite', 'variante' => 'A']);
    aleProspect($ws, $exp, ['etat' => 'connecte', 'variante' => 'B']);
    $corps = aleAlertes()->corpsReleve($ws, $exp);

    expect($corps)->toContain('invite : 2')->and($corps)->toContain('connecte : 1')->and($corps)->toContain('A : 2')->and($corps)->toContain('B : 1')
        ->and($corps)->not->toContain('profil-secret')->and($corps)->not->toContain('@');
});

test('alertes non réglées (alertes.actif éteint) ⇒ aucune requête réseau', function () {
    $ws = aleEspace();
    $exp = aleExpediteur();
    config(['alertes.actif' => false]);
    Http::fake();
    aleInvitations($ws, $exp, 50, 3);
    aleProspect($ws, $exp, ['etat' => 'plus_tard', 'relance_le' => '2026-11-10']);

    aleAlertes()->executerEspace($ws, aleLundi());

    Http::assertNothingSent();
    expect(aleArrets($ws))->toHaveCount(1); // l'arrêt est posé même si l'alerte n'est pas réglée
});

test('drapeau éteint ⇒ la commande sort sans lire aucune table du module', function () {
    config(['prospection_linkedin.active' => false]);
    $ws = aleEspace();
    $exp = aleExpediteur();
    aleInvitations($ws, $exp, 50, 0);

    $requetes = [];
    DB::listen(static function ($q) use (&$requetes): void {
        $requetes[] = $q->sql;
    });
    $this->artisan('prospection-linkedin:releve')->assertSuccessful();

    expect(array_filter($requetes, static fn (string $s): bool => preg_match('/\bpl_(prospects|journal|arret|plafonds)\b/', $s) === 1))->toBe([])
        ->and(aleArrets($ws))->toBe([]);
});

test('drapeau allumé ⇒ la commande traite les espaces', function () {
    $ws = aleEspace();
    $exp = aleExpediteur();
    aleInvitations($ws, $exp, 50, 0);

    $this->artisan('prospection-linkedin:releve')->assertSuccessful();

    expect(aleArrets($ws))->toHaveCount(1);
});

test('la planification quotidienne est sautée drapeau éteint et active drapeau allumé', function () {
    $evenements = collect(app(Schedule::class)->events())->filter(static fn ($e): bool => str_contains((string) $e->command, 'prospection-linkedin:releve'));
    expect($evenements)->toHaveCount(1);
    $evenement = $evenements->first();
    expect($evenement->expression)->toBe('0 7 * * *');

    config(['prospection_linkedin.active' => false]);
    expect($evenement->filtersPass(app()))->toBeFalse();
    config(['prospection_linkedin.active' => true]);
    expect($evenement->filtersPass(app()))->toBeTrue();
});

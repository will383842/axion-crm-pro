<?php

use App\Console\Commands\EnvoiCampagnesMaterialiser;
use App\Console\Commands\EnvoiCycle;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Creation\ServiceMessagesTypes;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

/*
 * Lot X1-L4-C — commande planifiée `envoi:campagnes:materialiser`. Inerte : aucun envoi, porte fermée, aucune condition de porte sur la tâche.
 * Données fictives (`*.example.test`).
 */
uses(TestCase::class, RefreshDatabase::class);

if (! function_exists('l4cLundi')) {
    function l4cLundi(string $heure = '10:00'): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-16 ' . $heure, new DateTimeZone('Europe/Paris'));
    }

    function l4cEspace(): string
    {
        $id = (string) Str::uuid();
        DB::table('workspaces')->insert(['id' => $id, 'name' => 'L4C', 'slug' => 'l4c-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** Campagne « programmee » (sans boîte ni plafond) sur $n entreprises ; $contenu s'ajoute à la saisie. Rend son identifiant. */
    function l4cProgrammee(string $ws, int $n, array $contenu = []): string
    {
        $messages = app(ServiceMessagesTypes::class);
        $sequence = [];
        foreach ([0, 3] as $i => $delai) {
            $m = $messages->creer($ws, 'Etape ' . ($i + 1), 'Objet ' . ($i + 1), 'Corps ' . ($i + 1), 'le fondateur');
            $messages->valider($ws, $m['id'], 1);
            $sequence[] = ['etape' => $i + 1, 'message_id' => $m['id'], 'delai_jours' => $delai];
        }
        $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . Str::random(6)]);
        for ($i = 1; $i <= $n; $i++) {
            $e = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => F::siren(), 'denomination' => 'Société fictive', 'discovery_source' => 'insee']);
            DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $i, 'email' => "prenom.nom{$i}.{$e}@societe.example.test"]);
            DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $e, 'origine' => 'coche']);
        }
        $depot = app(DepotCampagnes::class);
        $id = $depot->creer($ws, 'Campagne fictive', ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Liste fictive'], [], 2, ['contenu' => $contenu + ['sequence' => $sequence], 'etapes_faites' => []], 'le fondateur');
        $depot->transitionner($ws, $id, 'a_valider', 1, 'le fondateur', 'test', 'cle-l4c-' . Str::random(12));
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'programmee', 'version' => 3]);
        DB::table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $id, 'transition' => 'a_valider>programmee', 'auteur' => 'le fondateur',
            'motif' => 'test', 'cle_idempotence' => 'cle-j-' . Str::random(12), 'cree_le' => DB::raw("now() - interval '10 minutes'")]);

        return $id;
    }

    /** Joue $f sous le mode : « app » = rôle applicatif non propriétaire sous RLS. */
    function l4cSous(string $mode, Closure $f): mixed
    {
        MaterialisationCampagne::$niveauTolere = DB::transactionLevel();
        if ($mode === 'proprio') {
            return $f();
        }
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        try {
            return $f();
        } finally {
            DB::statement('RESET ROLE');
        }
    }
}

function cmdEvenement(): Event
{
    Artisan::call('list', ['--format' => 'txt']); // charge routes/console.php
    foreach (app(Schedule::class)->events() as $e) {
        if (str_contains((string) $e->command, EnvoiCampagnesMaterialiser::SIGNATURE_PLANIFIEE)) {
            return $e;
        }
    }
    throw new RuntimeException('Tâche planifiée absente.');
}

/** @return array{int, string} code, sortie */
function cmdLance(string $mode): array
{
    $code = l4cSous($mode, fn () => Artisan::call(EnvoiCampagnesMaterialiser::SIGNATURE_PLANIFIEE));

    return [$code, Artisan::output()];
}

function cmdSimulerSchemaAbsent(): void
{
    DB::statement('ALTER INDEX envoi_inscriptions_adresse_active_unique RENAME TO envoi_inscriptions_adresse_active_unique_x'); // annulé avec la transaction du test
}

beforeEach(function () {
    config(['envoi.plafond_jour' => 20]);
    EnvoiCampagnesMaterialiser::$horloge = null;
});

afterEach(function () {
    MaterialisationCampagne::$niveauTolere = 0;
    EnvoiCampagnesMaterialiser::$horloge = null;
});

test('X1-L4-C 1 schéma P2 absent : aucune requête d\'écriture, « schéma P2 absent : rien à faire », SUCCESS, tâche non due', function () {
    $e = cmdEvenement();
    expect(EnvoiCampagnesMaterialiser::schemaPret())->toBeTrue()->and($e->filtersPass(app()))->toBeTrue();
    cmdSimulerSchemaAbsent();
    $ws = l4cEspace();
    l4cProgrammee($ws, 2);
    $requetes = [];
    DB::listen(function ($q) use (&$requetes) {
        $requetes[] = $q->sql;
    });

    foreach (['app', 'proprio'] as $mode) {
        [$code, $sortie] = cmdLance($mode);
        expect($code)->toBe(0, $mode)->and($sortie)->toContain('schéma P2 absent : rien à faire');
    }

    $ecritures = array_filter($requetes, static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|lock|set)\b|pg_advisory|for update/i', $sql) === 1);
    expect(EnvoiCampagnesMaterialiser::schemaPret())->toBeFalse()->and($e->filtersPass(app()))->toBeFalse()->and(array_values(array_filter($ecritures, static fn (string $s): bool => ! str_contains($s, 'ROLE'))))->toBe([])
        ->and(DB::table('envoi_campagnes_materialisations')->where('workspace_id', $ws)->count())->toBe(0);
});

test('X1-L4-C 2 NR07 : chaque minute, verrou 10, un seul serveur, UNE condition (le schéma), aucune condition de porte', function () {
    $e = cmdEvenement();
    $filtres = new ReflectionProperty(Event::class, 'filters');

    expect($e->expression)->toBe('* * * * *')->and($e->withoutOverlapping)->toBeTrue()->and($e->expiresAt)->toBe(10)->and($e->onOneServer)->toBeTrue()
        ->and(count($filtres->getValue($e)))->toBe(1)->and($e->filtersPass(app()))->toBeTrue() // porte fermée (défaut) : la tâche est due quand même
        ->and(config('envoi.reel_actif'))->toBeFalse()
        ->and(file_get_contents(base_path('routes/console/envoi-campagnes.php')))->not->toContain('PorteEnvoiReelle');
});

test('X1-L4-C 3 bornes : au-delà de 50 s, plus aucun espace ni campagne commencé (hors_borne), puis reprise normale', function () {
    $ws = l4cEspace();
    $id = l4cProgrammee($ws, 3);
    $t = 0;
    EnvoiCampagnesMaterialiser::$horloge = function () use (&$t): DateTimeImmutable {
        $t += 30; // chaque lecture de l'horloge avance de 30 s

        return l4cLundi('07:00')->modify("+{$t} seconds");
    };

    [$code, $sortie] = cmdLance('proprio');

    expect($code)->toBe(0)->and($sortie)->toMatch('/hors_borne=[1-9]/')->and(DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->count())->toBe(0);

    EnvoiCampagnesMaterialiser::$horloge = null;
    [, $sortie] = cmdLance('proprio');
    expect($sortie)->toContain('inscrits=3')->and(DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->value('etat'))->toBe('terminee');
});

test('X1-L4-C 4 une campagne en erreur n\'empêche ni la suivante ni l\'autre espace ; FAILURE ; le journal ne contient aucune adresse ni empreinte', function () {
    foreach (['app', 'proprio'] as $mode) {
        $a = l4cEspace();
        $b = l4cEspace();
        $mauvaise = l4cProgrammee($a, 2, ['cible' => ['contact_ids' => [1]]]); // cible_non_supportee : refus non réessayable
        $bonneA = l4cProgrammee($a, 2);
        $bonneB = l4cProgrammee($b, 2);
        $journal = [];
        Log::shouldReceive('error', 'critical', 'warning', 'info')->andReturnUsing(function (...$args) use (&$journal) {
            $journal[] = json_encode($args);
        });

        [$code, $sortie] = cmdLance($mode);

        $ids = DB::table('envoi_campagnes_materialisations')->where('etat', 'terminee')->pluck('campagne_id')->all();
        expect($code)->toBe(1, $mode)->and($sortie)->toMatch('/erreurs=[1-9]/')->and($ids)->toContain($bonneA)->toContain($bonneB)->not->toContain($mauvaise);
        expect(implode(' ', $journal))->not->toContain('@')->not->toContain('example.test')->not->toMatch('/[0-9a-f]{64}/');
        Log::swap(new LogManager(app()));
    }
});

test('X1-L4-C 8 rien ne part : la commande jouée, `envoi:cycle` répond « porte fermée », zéro envoi_messages, drapeau toujours faux', function () {
    $ws = l4cEspace();
    l4cProgrammee($ws, 3);

    [$code, $sortie] = cmdLance('app');
    $cycle = Artisan::call(EnvoiCycle::SIGNATURE_PLANIFIEE);

    expect($code)->toBe(0)->and($sortie)->toContain('inscrits=3')->and($cycle)->toBe(0)->and(Artisan::output())->toContain('porte fermée : rien à faire')
        ->and(DB::table('envoi_messages')->count())->toBe(0)->and(config('envoi.reel_actif'))->toBeFalse()
        ->and(DB::table('envoi_campagnes')->where('workspace_id', $ws)->value('statut'))->toBe('programmee');
});

test('L5-B 5 commande : l\'étape clôture ferme les inscriptions d\'une campagne arrêtée, par lots de 1 000, avant le re-étalement ; rien n\'est supprimé', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = l4cEspace();
        $id = l4cProgrammee($ws, 3);
        l4cSous('proprio', fn () => app(MaterialisationCampagne::class)->materialiser($ws, $id, l4cLundi('07:00')));
        $seq = (string) DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id');
        DB::statement("INSERT INTO envoi_inscriptions (workspace_id, sequence_id, contact_id, entreprise_id, empreinte_adresse, prochaine_echeance, version_figee)
                       SELECT ?::uuid, ?::uuid, 1000000 + g, 1000000 + g, md5(g::text) || md5((g + 1)::text), now() + interval '3 days', '{}' FROM generate_series(1, 2497) g", [$ws, $seq]);
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'arretee', 'version' => DB::raw('version + 1')]);   // arrêtée SANS clôture : c'est la commande qui ferme

        [$c1, $s1] = cmdLance($mode);
        [$c2, $s2] = cmdLance($mode);
        [$c3, $s3] = cmdLance($mode);

        expect([$c1, $c2, $c3])->toBe([0, 0, 0])->and($s1)->toContain('cloturees=1000')->and($s2)->toContain('cloturees=1000')->and($s3)->toContain('cloturees=500')
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('statut', 'active')->count())->toBe(0)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(2500)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('motif_arret', 'campagne_arretee')->count())->toBe(2500);
    }
});

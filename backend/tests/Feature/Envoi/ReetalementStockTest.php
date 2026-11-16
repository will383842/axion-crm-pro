<?php

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Campagnes\ReetalementStock;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

/*
 * Lot X1-L4-C — re-étalement du stock : le jour où la porte s'ouvre sur un stock, aucune échéance ne doit rester dans le passé.
 * Chaque comportement est joué SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE. Données fictives (`*.example.test`). Aucun envoi.
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

/** Matérialise une campagne de $n entreprises puis fait « vieillir » ses échéances : toutes échues à $echeance. Rend [ws, campagne, séquence]. */
function reeStock(int $n, string $echeance = '2026-11-13 10:00:00+01'): array
{
    $ws = l4cEspace();
    $id = l4cProgrammee($ws, $n);
    l4cSous('proprio', fn () => app(MaterialisationCampagne::class)->materialiser($ws, $id, l4cLundi('07:00')));
    DB::table('envoi_inscriptions')->where('workspace_id', $ws)->update(['prochaine_echeance' => $echeance]);

    return [$ws, $id, (string) DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id')];
}

/** @return array<string, string> inscription => échéance (Paris) */
function reeEcheances(string $ws): array
{
    return DB::table('envoi_inscriptions')->where('workspace_id', $ws)->selectRaw("id, to_char(prochaine_echeance AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD HH24:MI:SS') AS e")->pluck('e', 'id')->all();
}

function reeOuvrirLaPorte(): void
{
    config(['envoi.reel_actif' => true]);
    app()->instance(RetraitEnvoisEnFile::class, new class implements RetraitEnvoisEnFile
    {
        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            return 0;
        }
    });
    app()->instance(ArretParEntreprise::class, new class implements ArretParEntreprise
    {
        public function declencher(string $workspaceId, string $empreinteAdresse): void {}
    });
    expect(app(PorteEnvoiReelle::class)->ouverte())->toBeTrue();
}

beforeEach(function () {
    config(['envoi.plafond_jour' => 20]);
});

afterEach(function () {
    MaterialisationCampagne::$niveauTolere = 0;
});

test('X1-L4-C 5 stock, porte fermée : plus rien dans le passé, 20 par jour au plus, heures irrégulières ; messages, reports et séquences libres intacts', function () {
    foreach (['app', 'proprio'] as $mode) {
        [$ws, , $seq] = reeStock(60);
        $ids = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->orderBy('contact_id')->pluck('id')->all();
        [$avecMessage, $avecReport] = [$ids[0], $ids[1]];
        DB::table('envoi_messages')->insert(['workspace_id' => $ws, 'inscription_id' => $avecMessage, 'etape' => 1, 'statut' => 'reserve', 'message_id' => 'mid-' . Str::random(8) . '@example.test']);
        DB::table('envoi_inscriptions')->where('id', $avecReport)->update(['reports' => 1]);
        $libre = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $libre, 'workspace_id' => $ws, 'nom' => 'Séquence libre']);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $libre, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'S', 'corps' => 'C']);
        $contact = (int) DB::table('contacts')->where('workspace_id', $ws)->value('id');
        DB::table('envoi_inscriptions')->insert(['workspace_id' => $ws, 'sequence_id' => $libre, 'contact_id' => $contact + 100000, 'entreprise_id' => 1, 'empreinte_adresse' => ListeSuppression::empreinte("libre.{$mode}@societe.example.test"),
            'prochaine_echeance' => '2026-11-13 10:00:00+01', 'version_figee' => '{}']);
        $libreId = DB::table('envoi_inscriptions')->where('sequence_id', $libre)->value('id');
        $avant = reeEcheances($ws);

        $n = l4cSous($mode, fn () => app(ReetalementStock::class)->reetaler($ws, l4cLundi()));

        $apres = reeEcheances($ws);
        $immobiles = [$avecMessage, $avecReport, $libreId];
        expect($n)->toBe(58, $mode);
        foreach ($immobiles as $i) {
            expect($apres[$i])->toBe($avant[$i], $mode);
        }
        $mobiles = array_diff_key($apres, array_flip($immobiles));
        $parJour = [];
        foreach ($mobiles as $e) {
            expect($e >= '2026-11-16 10:05:00')->toBeTrue($mode . ' ' . $e)->and(substr($e, 11, 8))->not->toEndWith(':00:00');
            $parJour[substr($e, 0, 10)] = ($parJour[substr($e, 0, 10)] ?? 0) + 1;
        }
        expect(max($parJour))->toBeLessThanOrEqual(20)->and(count($parJour))->toBeGreaterThanOrEqual(3)->and(DB::table('envoi_messages')->where('workspace_id', $ws)->count())->toBe(1);
        expect(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('sequence_id', $seq)->count())->toBe(60);
    }
});

test('X1-L4-C 6 ouverture de la porte : échue depuis 10 min = non déplacée ; depuis 40 min = déplacée', function () {
    foreach (['app', 'proprio'] as $mode) {
        [$ws] = reeStock(2);
        [$recente, $ancienne] = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->orderBy('contact_id')->pluck('id')->all();
        DB::table('envoi_inscriptions')->where('id', $recente)->update(['prochaine_echeance' => '2026-11-16 09:50:00+01']);
        DB::table('envoi_inscriptions')->where('id', $ancienne)->update(['prochaine_echeance' => '2026-11-16 09:20:00+01']);
        reeOuvrirLaPorte();

        $n = l4cSous($mode, fn () => app(ReetalementStock::class)->reetaler($ws, l4cLundi()));

        $e = reeEcheances($ws);
        expect($n)->toBe(1, $mode)->and($e[$recente])->toBe('2026-11-16 09:50:00')->and($e[$ancienne] >= '2026-11-16 10:05:00')->toBeTrue($mode);
    }
});

test('X1-L4-C 7 ligne verrouillée par une autre connexion : sautée (SKIP LOCKED), sans attente', function () {
    // Les lignes doivent être VALIDÉES pour qu'une autre connexion les voie : tout se joue sur la connexion propriétaire (hors transaction du test).
    config(['database.connections.verrou' => config('database.connections.pgsql_owner')]); // 3e connexion : tient le verrou, relâché avant le nettoyage
    $test = DB::connection('verrou');
    DB::setDefaultConnection('pgsql_owner');
    $proprio = DB::connection('pgsql_owner');
    $ws = null;
    try {
        [$ws] = reeStock(3);
        [$verrouillee] = $proprio->table('envoi_inscriptions')->where('workspace_id', $ws)->orderBy('contact_id')->pluck('id')->all();
        $test->beginTransaction();
        $test->select("SELECT set_config('app.current_workspace_id', ?, false)", [$ws]);
        $test->select('SELECT id FROM envoi_inscriptions WHERE workspace_id = ? AND id = ? FOR UPDATE', [$ws, $verrouillee]);
        $debut = microtime(true);

        $n = app(ReetalementStock::class)->reetaler($ws, l4cLundi());

        $e = reeEcheances($ws);
        expect($n)->toBe(2)->and($e[$verrouillee])->toBe('2026-11-13 10:00:00')->and(microtime(true) - $debut)->toBeLessThan(3.0);
    } finally {
        $test->rollBack();
        DB::purge('verrou');
        try {
            $proprio->statement("SET session_replication_role = 'replica'");
            if ($ws !== null) {
                foreach (['envoi_inscriptions', 'envoi_campagnes_materialisations', 'envoi_sequences_gestes', 'envoi_sequence_etapes', 'envoi_campagnes_journal', 'listes_manuelles_membres'] as $t) {
                    $proprio->table($t)->where('workspace_id', $ws)->delete();
                }
                $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->update(['sequence_id' => null]);
                $proprio->table('envoi_sequences')->where('workspace_id', $ws)->delete();
                $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->delete();
                $proprio->table('envoi_modeles')->where('workspace_id', $ws)->update(['version_validee' => null, 'statut' => 'brouillon']);
                foreach (['envoi_modele_versions', 'envoi_modeles', 'listes_manuelles', 'contacts', 'companies'] as $t) {
                    $proprio->table($t)->where('workspace_id', $ws)->delete();
                }
                $proprio->table('workspaces')->where('id', $ws)->delete();
            }
        } finally {
            $proprio->statement("SET session_replication_role = 'origin'");
            DB::setDefaultConnection('pgsql');
        }
    }
});

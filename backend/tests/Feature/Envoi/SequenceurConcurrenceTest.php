<?php

use App\Crm\FichesProtegees;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * CONCURRENCE RÉELLE : 40 processus PHP (connexions Postgres séparées) lancent
 * chacun 5 exécutions, soit 200 exécutions au même instant.
 *
 * Les fils ne voient PAS la transaction de RefreshDatabase : TOUTES les données
 * (y compris l'inscription, écrite par `Inscriptions` sur la connexion par défaut
 * temporairement basculée) sont écrites en auto-commit par `pgsql_owner`, comme
 * `PlafondConcurrenceTest`, puis NETTOYÉES (blocs `finally`). Transport : simulateur.
 *
 * Deux chemins distincts :
 *  - `traiter` : verrou de ligne (SELECT … FOR UPDATE) + unicité => UN envoi, UNE ligne ;
 *  - `reserver` : SANS verrou de ligne, plusieurs étapes — c'est l'unicité en base
 *    (ON CONFLICT DO NOTHING sur (inscription, étape)) qui doit à elle seule garantir
 *    une réservation et une ligne par étape.
 */
const CONC_MAINTENANT = '2026-11-16 10:00:00+01'; // lundi, dans la fenêtre

/** @return array{string, string, string} workspace, inscription, jour de compteur */
function concPreparer(int $etapes): array
{
    $owner = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $owner->table('workspaces')->insert(['id' => $ws, 'name' => 'ConcSeq', 'slug' => 'concseq-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    try {
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);

        $entreprise = (int) $owner->table('companies')->insertGetId([
            'workspace_id' => $ws, 'denomination' => 'ZZ Conc', 'discovery_source' => 'insee', 'siren' => '944444444',
            'country_code' => 'FR', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tag = (int) $owner->table('tags')->insertGetId(['workspace_id' => $ws, 'slug' => FichesProtegees::TAG_FEDERATIONS, 'name' => 'f', 'category' => 'custom', 'kind' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
        $owner->table('company_tag')->insert(['company_id' => $entreprise, 'tag_id' => $tag, 'workspace_id' => $ws, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
        // Article 14 : dates du CONTACT.
        $contact = (int) $owner->table('contacts')->insertGetId([
            'workspace_id' => $ws, 'company_id' => $entreprise, 'last_name' => 'Conc', 'email' => 'conc@zz-client.example.invalid', 'email_status' => 'valid',
            'created_at' => '2026-11-10 10:00:00+01', 'first_info_at' => '2026-11-10 10:00:00+01',
        ]);
        $owner->table('envoi_verdicts_adresse')->insert(['workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte('conc@zz-client.example.invalid'), 'verdict' => 'valide', 'verifie_le' => '2026-11-14 10:00:00+01', 'source' => 'test']);
        $owner->table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'zz-envoi.example.invalid', 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => CONC_MAINTENANT, 'spf_verifie_le' => CONC_MAINTENANT, 'dkim_verifie_le' => CONC_MAINTENANT]);
        // Mode témoins laissé à sa valeur sûre (true) : le déclencheur envoi_drapeaux_garde réserve l'extinction
        // à la commande locale (journal temoins_off) ; ce test porte sur la concurrence, pas sur l'extinction.
        $owner->table('envoi_drapeaux')->insert(['workspace_id' => $ws, 'mode_temoins' => true]);
        // Le destinataire est un témoin déclaré : il reste joignable sans éteindre le mode témoins.
        $owner->table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::calculer('conc@zz-client.example.invalid', 'cle-de-test-sequenceur'), 'libelle' => 'test']);
        $sid = (string) Str::uuid();
        $owner->table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Conc']);
        foreach (array_slice([0, 2, 3], 0, $etapes) as $i => $delai) {
            $owner->table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => $i + 1, 'delai_jours_ouvres' => $delai, 'sujet' => 's' . ($i + 1), 'corps' => 'c' . ($i + 1)]);
        }
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

        // `Inscriptions` et `FonctionsEspace` écrivent sur la connexion PAR DÉFAUT : on la
        // bascule sur le propriétaire (auto-commit) le temps de l'inscription.
        $defaut = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_owner');
        try {
            $ins = WorkspaceContext::run($ws, function () use ($ws, $sid, $contact): string {
                FonctionsEspace::regler($ws, 'federations', true); // segment ouvert pour CET espace

                return (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $sid, $contact, new DateTimeImmutable('2026-11-16 09:00:00', new DateTimeZone('Europe/Paris')));
            });
        } finally {
            DB::setDefaultConnection($defaut);
        }

        return [$ws, $ins, '2026-11-16'];
    } catch (Throwable $e) {
        concNettoyer($ws, '2026-11-16'); // une préparation à moitié faite ne laisse rien en base

        throw $e;
    }
}

/** Retire tout ce que le test a écrit en auto-commit (journal des commandes : ajout seul, il reste, sans donnée de personne). */
function concNettoyer(string $ws, string $jour): void
{
    $owner = DB::connection('pgsql_owner');
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
    // Une fiche « fédérations » est PROTÉGÉE : sa suppression physique est refusée par la base
    // (déclencheurs). Levée volontaire, dans la transaction seulement (patron de DoublonsSousRlsTest).
    $owner->transaction(function () use ($owner, $ws): void {
        $owner->statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        $owner->statement("SET LOCAL app.autoriser_suppression_absorbee = 'on'");
        foreach (['envoi_messages', 'envoi_inscriptions', 'envoi_sequence_etapes', 'envoi_sequences', 'envoi_verdicts_adresse', 'envoi_domaines', 'envoi_temoins', 'envoi_drapeaux'] as $table) {
            $owner->table($table)->where('workspace_id', $ws)->delete();
        }
        $owner->table('fonctions_espace')->where('espace_id', $ws)->delete();
        foreach (['company_tag', 'contacts'] as $table) {
            $owner->table($table)->where('workspace_id', $ws)->delete();
        }
        $owner->table('companies')->where('workspace_id', $ws)->delete();
        $owner->table('contacts_retires')->where('workspace_id', $ws)->delete();
        $owner->table('tags')->where('workspace_id', $ws)->delete();
    });
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    $owner->table('workspaces')->where('id', $ws)->delete();
    $owner->table('envoi_compteur_global')->where('jour', $jour)->delete(); // compteur agrégé, sans donnée de personne
}

/**
 * @return list<array<string, mixed>> la sortie JSON de chacun des 40 ouvriers
 */
function concLancer(string $mode, string $ws, string $ins): array
{
    $depart = microtime(true) + 6;
    $env = array_merge(getenv(), ['ENVOI_CLE_EMPREINTE' => 'cle-de-test-sequenceur', 'APP_ENV' => 'testing']);
    $procs = [];
    for ($i = 0; $i < 40; $i++) {
        $cmd = [PHP_BINARY, base_path('tests/Support/ouvrier_sequenceur.php'), $ws, $ins, (string) $depart, '5', CONC_MAINTENANT, $mode];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        expect(is_resource($p))->toBeTrue();
        $procs[] = [$p, $pipes];
    }
    $sorties = [];
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        expect(proc_close($p))->toBe(0, "ouvrier en échec : {$err} {$out}");
        $lignes = array_values(array_filter(array_map('trim', explode("\n", $out)), fn (string $l): bool => $l !== ''));
        $json = $lignes === [] ? null : json_decode($lignes[array_key_last($lignes)], true);
        expect(is_array($json))->toBeTrue("sortie d'ouvrier illisible. stdout : {$out} stderr : {$err}");
        $sorties[] = $json;
    }

    return $sorties;
}

/** @return list<object> */
function concLignes(string $ws, string $ins): array
{
    $owner = DB::connection('pgsql_owner');
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
    $lignes = $owner->table('envoi_messages')->where('inscription_id', $ins)->orderBy('etape')->get()->all();
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

    return $lignes;
}

test('200 exécutions concurrentes sur la même inscription : zéro doublon (verrou de ligne ET unicité réunis ; le verrou seul est prouvé par SequenceurTest « verrou de ligne RÉELLEMENT demandé »)', function () {
    [$ws, $ins, $jour] = concPreparer(3);
    try {
        $sorties = concLancer('traiter', $ws, $ins);
        $envoyes = array_sum(array_map(fn (array $s): int => (int) ($s['envoyes'] ?? 0), $sorties));
        $lignes = concLignes($ws, $ins);

        expect($sorties)->toHaveCount(40)
            ->and($envoyes)->toBe(1)
            ->and($lignes)->toHaveCount(1)
            ->and($lignes[0]->statut)->toBe('envoye')
            ->and($lignes[0]->etape)->toBe(1);
    } finally {
        concNettoyer($ws, $jour);
    }
});

test('200 réservations concurrentes SANS verrou de ligne, sur 3 étapes : l\'unicité en base (ON CONFLICT) donne exactement une ligne par étape', function () {
    [$ws, $ins, $jour] = concPreparer(3);
    try {
        $sorties = concLancer('reserver', $ws, $ins);
        $reservees = array_sum(array_map(fn (array $s): int => (int) ($s['reservees'] ?? 0), $sorties));
        $lignes = concLignes($ws, $ins);

        expect($sorties)->toHaveCount(40)
            ->and($reservees)->toBe(3) // une réservation gagnante par étape, jamais deux
            ->and($lignes)->toHaveCount(3)
            ->and(array_map(fn (object $l): int => (int) $l->etape, $lignes))->toBe([1, 2, 3])
            ->and(array_unique(array_map(fn (object $l): string => (string) $l->message_id, $lignes)))->toHaveCount(3);
    } finally {
        concNettoyer($ws, $jour);
    }
});

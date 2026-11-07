<?php

use App\Crm\FichesProtegees;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * CONCURRENCE RÉELLE : 40 processus PHP (connexions Postgres séparées) lancent
 * chacun 5 exécutions, soit 200 exécutions sur LA MÊME inscription échue, au même
 * instant. Verrou de ligne (SELECT … FOR UPDATE) + unicité (inscription, étape) :
 * il doit y avoir EXACTEMENT un envoi et UNE ligne, ZÉRO doublon.
 * Les données sont écrites en auto-commit par la connexion propriétaire
 * (les fils ne voient pas la transaction de RefreshDatabase). Transport : simulateur.
 */
test('200 exécutions concurrentes sur la même inscription : zéro doublon', function () {
    $owner = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $maintenant = '2026-11-16 10:00:00+01'; // lundi, dans la fenêtre
    $owner->table('workspaces')->insert(['id' => $ws, 'name' => 'ConcSeq', 'slug' => 'concseq-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);

    $entreprise = (int) $owner->table('companies')->insertGetId([
        'workspace_id' => $ws, 'denomination' => 'ZZ Conc', 'discovery_source' => 'insee', 'siren' => '944444444',
        'country_code' => 'FR', 'first_info_at' => '2026-11-10 10:00:00+01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $tag = (int) $owner->table('tags')->insertGetId(['workspace_id' => $ws, 'slug' => FichesProtegees::TAG_FEDERATIONS, 'name' => 'f', 'category' => 'custom', 'kind' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
    $owner->table('company_tag')->insert(['company_id' => $entreprise, 'tag_id' => $tag, 'workspace_id' => $ws, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
    $contact = (int) $owner->table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $entreprise, 'last_name' => 'Conc', 'email' => 'conc@zz-client.example.invalid', 'email_status' => 'valid']);
    $owner->table('envoi_verdicts_adresse')->insert(['workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte('conc@zz-client.example.invalid'), 'verdict' => 'valide', 'verifie_le' => '2026-11-14 10:00:00+01', 'source' => 'test']);
    $owner->table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'zz-envoi.example.invalid', 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $maintenant, 'spf_verifie_le' => $maintenant, 'dkim_verifie_le' => $maintenant]);
    $owner->table('envoi_drapeaux')->insert(['workspace_id' => $ws, 'mode_temoins' => false]); // sinon la passerelle refuse tout destinataire hors témoins
    $sid = (string) Str::uuid();
    $owner->table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Conc']);
    $owner->table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 's', 'corps' => 'c']);
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    // Le segment « fédérations » doit être ouvert pour CET espace (réglage par espace).
    $ins = \App\Support\WorkspaceContext::run($ws, function () use ($ws, $sid, $contact, $owner) {
        FonctionsEspace::regler($ws, 'federations', true);

        return (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $sid, $contact, new DateTimeImmutable('2026-11-16 09:00:00', new DateTimeZone('Europe/Paris')));
    });

    $depart = microtime(true) + 6;
    $env = array_merge(getenv(), ['ENVOI_CLE_EMPREINTE' => 'cle-de-test-sequenceur', 'APP_ENV' => 'testing']);
    $procs = [];
    for ($i = 0; $i < 40; $i++) {
        $cmd = [PHP_BINARY, base_path('tests/Support/ouvrier_sequenceur.php'), $ws, $ins, (string) $depart, '5', $maintenant];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        expect(is_resource($p))->toBeTrue();
        $procs[] = [$p, $pipes];
    }
    $envoyes = 0;
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        expect(proc_close($p))->toBe(0, "ouvrier en échec : {$err} {$out}");
        $envoyes += (int) (json_decode(trim($out), true)['envoyes'] ?? 0);
    }

    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
    $lignes = $owner->table('envoi_messages')->where('inscription_id', $ins)->get();
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

    expect($envoyes)->toBe(1)
        ->and($lignes)->toHaveCount(1)
        ->and($lignes[0]->statut)->toBe('envoye');
});

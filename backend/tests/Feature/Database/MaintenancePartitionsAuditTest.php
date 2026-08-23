<?php

/**
 * GARDE — B10-003 (S1) : « le partitionnement d'`audit_logs` n'est entretenu
 * par personne ».
 *
 * ── CE QUI A ETE MESURE, LE 2026-08-20 ────────────────────────────────────
 *
 * 1. `run_maintenance` n'apparait NULLE PART dans un chemin automatique. Sur
 *    tout le depot, ses seules occurrences sont un runbook d'incident MANUEL
 *    (`infra/runbooks/02-disk-full.md`), la garde qui relit ce runbook, et des
 *    rapports d'audit. Aucun `Schedule::command`, aucun cron, aucun BGW —
 *    `Dockerfile.postgres:49-51` compile pg_partman en `NO_BGW=1` et designe
 *    explicitement « le cron de partition mgmt sera Laravel scheduler », qui
 *    n'a jamais ete ecrit.
 *
 * 2. L'horizon reel, lu sur `axion_crm` ET sur `axion_crm_test_a35r` :
 *
 *        audit_logs_p20270201 => FOR VALUES FROM ('2027-02-01') TO ('2027-03-01')
 *
 *    C'est la DERNIERE. Les partitions s'arretent bien en fevrier 2027, comme
 *    le dit le constat, et `premake = 6` n'y changera rien tant que personne
 *    n'appelle la maintenance : `premake` est un nombre de partitions creees
 *    D'AVANCE PAR run_maintenance, pas une creation automatique.
 *
 * ── CE QUE LE CONSTAT DIT DE TRAVERS, ET QUI EST PIRE ─────────────────────
 *
 * Le constat annonce qu'« apres fevrier 2027, les insertions dans `audit_logs`
 * echouent faute de partition ». MESURE : c'est FAUX.
 *
 *     INSERT INTO audit_logs (…, created_at) VALUES (…, '2027-06-15');
 *     -- INSERT 0 1
 *     SELECT tableoid::regclass FROM audit_logs WHERE …;
 *     -- audit_logs_default
 *
 * Une partition DEFAULT existe (posee volontairement par
 * `2026_05_17_000011_setup_pg_partman_audit_logs`, cf. « FILET OBLIGATOIRE »).
 * Rien n'echoue donc : les lignes tombent en silence dans le fourre-tout.
 *
 * Ce silence est le vrai defaut, parce qu'il se VERROUILLE :
 *
 *     BEGIN;
 *     INSERT … created_at = '2027-06-15';
 *     CREATE TABLE audit_logs_p20270601 PARTITION OF audit_logs
 *       FOR VALUES FROM ('2027-06-01') TO ('2027-07-01');
 *     -- ERROR: updated partition constraint for default partition
 *     --        "audit_logs_default" would be violated by some row
 *
 * Autrement dit : des qu'UNE SEULE ligne d'un mois futur est tombee dans
 * DEFAULT, pg_partman ne peut PLUS creer la partition de ce mois-la. La
 * reparation automatique devient impossible precisement parce que la panne est
 * restee invisible. C'est pour cela que la commande gardee ici ne se contente
 * pas d'appeler `run_maintenance` : elle refuse de rendre 0 quand cet etat est
 * atteint.
 *
 * ── ET LA RETENTION DE 24 MOIS ────────────────────────────────────────────
 *
 * `part_config` porte bien `retention = '24 months'`, mais la retention de
 * pg_partman n'est appliquee QUE par `run_maintenance`. Jamais appele = jamais
 * appliquee. Le constat est exact sur ce point.
 */

use App\Console\Commands\PartmanMaintenir;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Borne superieure la plus lointaine couverte par une VRAIE partition (la
 * DEFAULT est exclue : elle ne « couvre » rien, elle ramasse). Rend null si
 * `audit_logs` n'est pas partitionnee du tout.
 */
function horizonDesPartitionsAudit(): ?string
{
    return DB::scalar(<<<'SQL'
        SELECT max((regexp_match(pg_get_expr(c.relpartbound, c.oid), 'TO \(''([^'']+)''\)'))[1]::timestamptz)
        FROM   pg_class c
        JOIN   pg_inherits i ON i.inhrelid = c.oid
        WHERE  i.inhparent = 'public.audit_logs'::regclass
          AND  pg_get_expr(c.relpartbound, c.oid) <> 'DEFAULT'
    SQL);
}

/**
 * Le socle de tout ce fichier. Si `audit_logs` n'est pas partitionnee par
 * pg_partman sur cette base, les tests qui suivent seraient des verts a vide :
 * ils passeraient sans rien avoir eprouve. On ECHOUE en le disant, on ne saute
 * pas (consigne : un test ignore est un vert deguise).
 */
function exigerUnAuditLogsGereParPartman(): string
{
    $schema = DB::scalar(
        "SELECT n.nspname FROM pg_extension e
         JOIN pg_namespace n ON n.oid = e.extnamespace
         WHERE e.extname = 'pg_partman'",
    );

    expect($schema)->not->toBeNull(
        'pg_partman est absent de cette base : la garde B10-003 ne peut RIEN eprouver. '
        . 'Ce n est pas une raison de sauter le test — c est une raison de le faire rougir.',
    );

    $partitionnee = (bool) DB::scalar(
        "SELECT EXISTS (SELECT 1 FROM pg_partitioned_table p
         JOIN pg_class c ON c.oid = p.partrelid WHERE c.relname = 'audit_logs')",
    );

    expect($partitionnee)->toBeTrue(
        'audit_logs n est pas partitionnee sur cette base : la garde B10-003 ne peut rien eprouver.',
    );

    $gere = DB::scalar(
        sprintf("SELECT parent_table FROM %s.part_config WHERE parent_table = 'public.audit_logs'", $schema),
    );

    expect($gere)->not->toBeNull(
        'audit_logs est partitionnee mais ABSENTE de part_config : pg_partman ne la gere pas. '
        . 'C est exactement l etat que la migration 000011 peut laisser via son bloc EXCEPTION.',
    );

    return (string) $schema;
}

test('B10-003 : la maintenance des partitions est PLANIFIEE, elle ne depend pas d un geste manuel', function () {
    // On interroge le REGISTRE du planificateur, pas le texte de
    // `routes/console.php`. Une garde qui cherche une chaine dans un fichier
    // prouve qu'un caractere est present ; celle-ci prouve que Laravel a
    // REELLEMENT enregistre la tache — elle tient donc meme si la ligne passe
    // par une constante, si elle demenage dans un autre fichier, ou si un
    // `Schedule::command()` est ecrit dans un bloc jamais atteint.
    $planificateur = app(Schedule::class);
    $evenements = $planificateur->events();

    // TEMOIN. Sans lui, un registre VIDE — bootstrap different, routes console
    // non chargees — ferait passer chaque assertion « introuvable » pour un
    // constat, et le vert final pour une preuve.
    expect($evenements)->not->toBeEmpty('Le registre du planificateur est vide : cette garde ne prouverait rien.');

    $commandes = array_map(fn ($evenement): string => (string) $evenement->command, $evenements);

    $this->assertNotEmpty(
        array_filter($commandes, fn (string $c): bool => str_contains($c, 'retention:purge')),
        'Temoin manquant : `retention:purge` n est pas dans le registre, donc ce registre n est pas celui de ce depot.',
    );

    // LE CONSTAT. Avant correctif, la seule occurrence de `run_maintenance`
    // du depot etait dans un runbook d'incident MANUEL.
    $maintenances = array_values(array_filter(
        $evenements,
        fn ($evenement): bool => str_contains((string) $evenement->command, PartmanMaintenir::SIGNATURE_PLANIFIEE),
    ));

    expect($maintenances)->toHaveCount(
        1,
        'La maintenance des partitions audit_logs n est planifiee NULLE PART (ou l est plusieurs fois). '
        . 'Sans elle, les partitions s arretent a leur horizon et la retention de 24 mois n est appliquee '
        . 'par rien : pg_partman n applique sa retention QUE dans run_maintenance.',
    );

    // Une maintenance mensuelle laisserait passer un mois entier avant de
    // rattraper un horizon trop court. On exige un passage quotidien.
    expect($maintenances[0]->expression)->toBe('30 1 * * *');

    // Le planificateur de Laravel N'INTERPRETE PAS le code de sortie d'une
    // commande planifiee (patron etabli par A08-001 et B16-006). Une
    // maintenance de partitions qui echoue sans que personne ne le sache
    // reproduirait EXACTEMENT le defaut qu'on repare.
    //
    // On ne se contente pas de constater la PRESENCE d'un crochet : `onFailure()`
    // n'est qu'un `then()` conditionnel range dans `afterCallbacks`, ou
    // `withoutOverlapping()` et `onOneServer()` deposent eux aussi les leurs. Une
    // assertion « ce tableau n est pas vide » serait donc verte sans le moindre
    // onFailure. On DECLENCHE le crochet, et on regarde ce qui sort.
    $evenement = $maintenances[0];
    $codeDeSortie = new ReflectionProperty($evenement, 'exitCode');
    $codeDeSortie->setAccessible(true);

    // TEMOIN : sur un code de sortie NUL, rien ne doit etre journalise. Sans ce
    // temoin, une alerte emise inconditionnellement — donc a chaque passage
    // reussi, donc du bruit qu'on apprendrait a ignorer — passerait pour une
    // alerte correcte.
    Log::spy();
    $codeDeSortie->setValue($evenement, 0);
    $evenement->callAfterCallbacks(app());
    Log::shouldNotHaveReceived('critical');

    // LE CONSTAT : sur un code de sortie non nul, l'echec doit atteindre le
    // journal de l'application. La sortie console d'une tache planifiee, elle,
    // ne va NULLE PART.
    Log::spy();
    $codeDeSortie->setValue($evenement, 1);
    $evenement->callAfterCallbacks(app());

    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $message): bool => str_contains($message, PartmanMaintenir::PREFIXE_ALERTE))
        ->atLeast()->once();
});

test('B10-003 : la commande repousse REELLEMENT l horizon des partitions', function () {
    exigerUnAuditLogsGereParPartman();

    $horizonInitial = horizonDesPartitionsAudit();
    expect($horizonInitial)->not->toBeNull();

    // ── SABOTAGE : on ramene la base a un horizon court, c'est-a-dire a l'etat
    // ou elle SERA en janvier 2027 si personne n'entretient rien. On supprime
    // toutes les partitions dont la borne haute depasse « maintenant + 1 mois ».
    $supprimees = DB::select(<<<'SQL'
        SELECT c.relname
        FROM   pg_class c
        JOIN   pg_inherits i ON i.inhrelid = c.oid
        WHERE  i.inhparent = 'public.audit_logs'::regclass
          AND  pg_get_expr(c.relpartbound, c.oid) <> 'DEFAULT'
          AND  (regexp_match(pg_get_expr(c.relpartbound, c.oid), 'TO \(''([^'']+)''\)'))[1]::timestamptz
               > (now() + INTERVAL '1 month')
    SQL);

    foreach ($supprimees as $partition) {
        DB::statement('DROP TABLE ' . $partition->relname);
    }

    // TEMOIN DU SABOTAGE. Si le sabotage n'a rien fait, tout ce qui suit serait
    // un vert de complaisance : la commande « repousserait » un horizon qui
    // n'avait jamais recule.
    expect($supprimees)->not->toBeEmpty(
        'Aucune partition future a supprimer : le sabotage n a pas eu lieu, le test ne prouverait rien.',
    );

    $horizonSabote = horizonDesPartitionsAudit();
    expect($horizonSabote)->not->toBeNull();
    expect(strtotime((string) $horizonSabote))
        ->toBeLessThan(strtotime('+2 months'), 'Le sabotage n a pas suffisamment raccourci l horizon.');

    // ── UNE TABLE VIVANTE. Ce n'est pas une commodite de test, c'est la
    // mecanique reelle de pg_partman, mesuree le 2026-08-20 :
    // `infinite_time_partitions = false` fait que `run_maintenance` cree les
    // partitions en avance des DONNEES, pas du calendrier. Sur une table vide,
    // il ne cree RIEN (mesure : horizon inchange a 2026-08-31). Une seule ligne
    // datee de maintenant, et il en cree six mois d'un coup. La production
    // ecrit dans `audit_logs` en continu : c'est cet etat-la qu'on reproduit.
    DB::table('audit_logs')->insert([
        'event_type' => 'garde.b10003',
        'current_hash' => 'table.vivante',
        // `prev_hash` est fourni EXPLICITEMENT : B16-013 a retire le defaut SQL
        // de cette colonne le 2026-08-22, pour qu'un INSERT qui l'omet echoue
        // franchement au lieu d'heriter d'un maillon zero. Cette garde-ci ne
        // porte pas sur la chaine de hachage : elle a juste besoin d'une ligne.
        'prev_hash' => str_repeat('0', 64),
        'created_at' => now(),
    ]);

    // ── LE GESTE.
    $code = Artisan::call(PartmanMaintenir::SIGNATURE_PLANIFIEE);
    $sortie = Artisan::output();

    expect($code)->toBe(0, "La commande a rendu {$code}. Sortie :\n" . $sortie);

    $horizonRepousse = horizonDesPartitionsAudit();
    expect($horizonRepousse)->not->toBeNull();

    // pg_partman cree `premake` partitions d'avance (6 ici). On exige 3 mois :
    // assez pour prouver que la maintenance a bien eu lieu, assez tolerant pour
    // ne pas rougir sur un effet de bord de fin de mois.
    expect(strtotime((string) $horizonRepousse))->toBeGreaterThan(
        strtotime('+3 months'),
        "L horizon est reste a {$horizonRepousse} : run_maintenance n a pas cree les partitions a venir. Sortie :\n" . $sortie,
    );
});

test('B10-003 : sur une table qui n ecrit plus, la commande DIT pourquoi l horizon ne bouge pas au lieu de crier au loup', function () {
    exigerUnAuditLogsGereParPartman();

    // Meme sabotage que ci-dessus, mais SANS ligne recente. C'est le temoin de
    // la mecanique mesuree : pg_partman ne cree aucune partition d'avance tant
    // qu'aucune donnee n'arrive.
    foreach (DB::select(<<<'SQL'
        SELECT c.relname
        FROM   pg_class c
        JOIN   pg_inherits i ON i.inhrelid = c.oid
        WHERE  i.inhparent = 'public.audit_logs'::regclass
          AND  pg_get_expr(c.relpartbound, c.oid) <> 'DEFAULT'
          AND  (regexp_match(pg_get_expr(c.relpartbound, c.oid), 'TO \(''([^'']+)''\)'))[1]::timestamptz
               > (now() + INTERVAL '1 month')
    SQL) as $partition) {
        DB::statement('DROP TABLE ' . $partition->relname);
    }

    // TEMOIN : la table doit vraiment etre muette, sinon le test eprouve autre
    // chose que ce qu'il annonce.
    $recentes = (int) DB::scalar("SELECT count(*) FROM audit_logs WHERE created_at >= now() - INTERVAL '32 days'");
    expect($recentes)->toBe(0);

    $code = Artisan::call(PartmanMaintenir::SIGNATURE_PLANIFIEE);
    $sortie = Artisan::output();

    // Rendre 0 ici est le comportement VOULU : une table d'audit muette n'est
    // pas un defaut de partitionnement. Mais le silence, lui, serait un defaut :
    // la commande doit dire pourquoi elle n a rien pu repousser.
    expect($code)->toBe(0, "Sortie :\n" . $sortie);
    $this->assertStringContainsString('AUCUNE ligne depuis 32 jours', $sortie);
});

test('B10-003 : la commande REFUSE de rendre 0 quand pg_partman ne gere pas audit_logs', function () {
    $schema = exigerUnAuditLogsGereParPartman();

    // L'etat reproduit ici n'est pas theorique : la migration 000011 enveloppe
    // son `create_parent()` dans un `BEGIN … EXCEPTION WHEN OTHERS` qui retombe
    // sur la seule partition DEFAULT en cas d'echec. Une base ayant emprunte ce
    // chemin porte une `audit_logs` PARTITIONNEE mais INCONNUE de pg_partman :
    // `run_maintenance` n'y fait alors strictement rien — et rend un succes.
    DB::statement(sprintf("DELETE FROM %s.part_config WHERE parent_table = 'public.audit_logs'", $schema));

    Log::spy();

    $code = Artisan::call(PartmanMaintenir::SIGNATURE_PLANIFIEE);
    $sortie = Artisan::output();

    expect($code)->not->toBe(0, "La commande a rendu 0 sur une table non geree. Sortie :\n" . $sortie);
    $this->assertStringContainsString('part_config', $sortie);

    // Le code de sortie ne suffit pas : le planificateur ne le lit pas. Il faut
    // une trace dans le canal de l'application (patron A08-001).
    Log::shouldHaveReceived('critical')->atLeast()->once();
});

test('B10-003 : la commande REFUSE de rendre 0 quand des lignes futures sont deja tombees dans DEFAULT', function () {
    exigerUnAuditLogsGereParPartman();

    $horizon = horizonDesPartitionsAudit();
    expect($horizon)->not->toBeNull();

    // Une ligne datee APRES la derniere partition : c'est ce qui arrivera a
    // chaque ecriture d'audit passe fevrier 2027. Elle ne fait echouer aucune
    // insertion — elle verrouille la creation de la partition de son mois.
    $apresHorizon = date('Y-m-d H:i:sP', strtotime((string) $horizon . ' +10 days'));

    DB::table('audit_logs')->insert([
        'event_type' => 'garde.b10003',
        'current_hash' => 'ligne.tombee.dans.default',
        // `prev_hash` est fourni EXPLICITEMENT : B16-013 a retire le defaut SQL
        // de cette colonne le 2026-08-22, pour qu'un INSERT qui l'omet echoue
        // franchement au lieu d'heriter d'un maillon zero. Cette garde-ci ne
        // porte pas sur la chaine de hachage : elle a juste besoin d'une ligne.
        'prev_hash' => str_repeat('0', 64),
        'created_at' => $apresHorizon,
    ]);

    // TEMOIN : la ligne doit VRAIMENT etre dans la partition fourre-tout,
    // sinon le test ne reproduit pas l'etat qu'il pretend eprouver.
    $partitionDAtterrissage = DB::scalar(
        "SELECT tableoid::regclass::text FROM audit_logs WHERE current_hash = 'ligne.tombee.dans.default'",
    );
    expect($partitionDAtterrissage)->toBe('audit_logs_default');

    Log::spy();

    $code = Artisan::call(PartmanMaintenir::SIGNATURE_PLANIFIEE);
    $sortie = Artisan::output();

    expect($code)->not->toBe(0, "La commande a rendu 0 alors que DEFAULT est empoisonnee. Sortie :\n" . $sortie);
    $this->assertStringContainsString('audit_logs_default', $sortie);
    Log::shouldHaveReceived('critical')->atLeast()->once();
});

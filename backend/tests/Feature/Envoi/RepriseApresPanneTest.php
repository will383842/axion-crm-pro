<?php

/**
 * P1-REPRISE — reprise après panne, preuve par ARRÊT BRUTAL. Des processus réels (même méthode que `PlafondConcurrenceTest`) font
 * tourner la vraie chaîne (ChefOrchestre -> Sequenceur -> PasserelleLocale -> FauxServeurPersistant) et se tuent eux-mêmes par
 * SIGKILL à quatre points nommés ; un second processus reprend. Aucun réseau, aucun vrai message (`ENVOI_REEL_ACTIF` reste faux :
 * la porte n'est ouverte que dans le processus fils, en mémoire), adresses en `example.test`.
 * Les données sont écrites en auto-commit (les processus fils ne voient pas une transaction de test), puis retirées à la fin.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\FauxServeurPersistant;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const REPRISE_PLAFOND = 2;

/**
 * Un lundi lointain (an 2100), fixé par scénario : 7 lundis possibles, un par point d'arrêt. Les compteurs sont écrits pour de bon ;
 * d'autres tests de l'envoi tirent aussi des jours en 2100 (au hasard), une rencontre est improbable mais pas exclue, d'où le
 * nettoyage ciblé de `repNettoyer`.
 */
function repLundi(string $point): DateTimeImmutable
{
    return (new DateTimeImmutable('2100-01-04 10:00:00', new DateTimeZone('Europe/Paris')))->modify('+' . (7 * (crc32($point) % 7)) . ' days');
}

/**
 * Espace complet avec TROIS inscriptions échues dans l'ordre (la première est celle où le processus meurt).
 *
 * @return array{ws: string, ins: list<string>}
 */
function repEspace(DateTimeImmutable $lundi): array
{
    $precedente = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_owner'); // connexion hors de la transaction du test : tout est validé pour de bon
    $ws = null;
    try {
        $ws = F::espace('zz-reprise');
        DB::table('workspaces')->where('id', $ws)->update(['settings' => json_encode(['produits' => ['envoi' => 'actif']])]);
        config(['envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test']]);
        FonctionsEspace::regler($ws, 'federations', true);
        (new DepotSocleDb)->eteindreTemoins($ws, 'test-reprise');
        WorkspaceContext::set($ws);
        app(DepotReglages::class)->enregistrerIdentite($ws, [
            'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
            'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
        ], 'test');
        $jour = $lundi->format('c');
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'zz-reprise.example.test', 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour, 'flux' => 'prospection']);
        DB::table('envoi_boites')->insert(['workspace_id' => $ws, 'adresse' => 'contact@zz-reprise.example.test', 'hote' => 'imap.example.test', 'port' => 587, 'identifiants_chiffres' => 'x']);
        $sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
        $ids = [];
        foreach ([1, 2, 3] as $i) {
            $adresse = "dest{$i}@client.example.test";
            $e = F::fiche($ws, "ZZ Entreprise {$i}", ['country_code' => 'FR', 'first_info_at' => $lundi->modify('-3 days')->format('c')]);
            F::lier($ws, $e, F::tag($ws, FichesProtegees::TAG_FEDERATIONS));
            $contact = (int) DB::table('contacts')->insertGetId([
                'workspace_id' => $ws, 'company_id' => $e, 'last_name' => "Dupont-test{$i}", 'email' => $adresse, 'email_status' => 'valid',
                'created_at' => $lundi->modify('-3 days')->format('c'), 'first_info_at' => $lundi->modify('-3 days')->format('c'),
            ]);
            DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($adresse), 'verdict' => 'valide', 'verifie_le' => $lundi->modify('-2 days')->format('c'), 'source' => 'test']);
            $ids[] = (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $sid, $contact, $lundi);
        }
        // Ordre de traitement fixé : l'inscription 1 d'abord, puis 2, puis 3.
        foreach ($ids as $k => $id) {
            DB::table('envoi_inscriptions')->where('id', $id)->update(['prochaine_echeance' => $lundi->modify('-' . (30 - $k) . ' minutes')->format('c')]);
        }
        WorkspaceContext::clear();

        return ['ws' => $ws, 'ins' => $ids];
    } catch (Throwable $e) {
        // Échec en cours de montage : l'appelant n'a pas reçu l'identifiant, on retire ici ce qui a déjà été validé.
        if ($ws !== null) {
            repNettoyer($ws, $lundi);
        }
        throw $e;
    } finally {
        DB::setDefaultConnection($precedente);
    }
}

/** Retire toutes les lignes de l'espace de test (données fictives créées ici). Les déclencheurs « ajout seul » sont suspendus pour CETTE session. */
function repNettoyer(string $ws, DateTimeImmutable $lundi): void
{
    $o = DB::connection('pgsql_owner');
    $o->unprepared('SET session_replication_role = replica');
    try {
        $tables = $o->select("SELECT DISTINCT c.table_name FROM information_schema.columns c JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name WHERE c.table_schema = 'public' AND c.column_name = 'workspace_id' AND t.table_type = 'BASE TABLE'");
        foreach ($tables as $t) {
            $o->table((string) $t->table_name)->where('workspace_id', $ws)->delete();
        }
        // `fonctions_espace` est rattachée par `espace_id` (pas `workspace_id`) : sans cette ligne, un orphelin restait et faisait échouer la restauration du dump (ACQUIS 2).
        $o->table('fonctions_espace')->where('espace_id', $ws)->delete();
        $o->table('workspaces')->where('id', $ws)->delete();
        $o->table('envoi_compteur_global')->where('jour', $lundi->format('Y-m-d'))->delete();
    } finally {
        $o->unprepared('SET session_replication_role = DEFAULT');
    }
}

/**
 * Lance un ouvrier, attend sa fin.
 *
 * @return array{signal: ?int, code: int, sortie: ?array<string, mixed>, erreur: string}
 */
function repOuvrier(string $ws, string $run, string $point, DateTimeImmutable $instant): array
{
    $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'ENVOI_REEL_ACTIF' => 'false', FauxServeurPersistant::ENV_TABLE => FauxServeurPersistant::table()]);
    $p = proc_open([PHP_BINARY, base_path('tests/Support/ouvrier_cycle.php'), $ws, $run, $point, $instant->format('c'), (string) REPRISE_PLAFOND], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
    expect(is_resource($p))->toBeTrue();
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    // Les tubes fermés ne prouvent pas que le processus est réapé : on attend sa fin, puis le PREMIER relevé donne code et signal.
    $debut = microtime(true);
    do {
        $etat = proc_get_status($p);
        if (! $etat['running']) {
            break;
        }
        usleep(10000);
    } while (microtime(true) - $debut < 30);
    proc_close($p);

    return [
        'signal' => ($etat['signaled'] ?? false) ? (int) $etat['termsig'] : null,
        'code' => (int) $etat['exitcode'],
        'sortie' => json_decode(trim($out), true),
        'erreur' => $err . $out,
    ];
}

/**
 * Alertes critiques d'un cycle. La sortie du fils DOIT être un JSON valide portant la clé `critiques` : sinon `[]` passerait
 * pour « aucune alerte » alors que rien n'a été lu.
 *
 * @param  array{signal: ?int, code: int, sortie: ?array<string, mixed>, erreur: string}  $cycle
 * @return list<string>
 */
function repCritiques(array $cycle, string $nom): array
{
    expect(is_array($cycle['sortie']))->toBeTrue("sortie du {$nom} : JSON invalide ou absent : " . $cycle['erreur']);
    expect(is_array($cycle['sortie']['critiques'] ?? null))->toBeTrue("sortie du {$nom} : clé « critiques » absente : " . $cycle['erreur']);

    return array_values(array_map('strval', $cycle['sortie']['critiques']));
}

/** @return array<string, mixed> photo de l'état durable (lue sur la connexion propriétaire, sans contexte : le propriétaire contourne la RLS) */
function repPhoto(string $ws, string $run): array
{
    $o = DB::connection('pgsql_owner');

    return [
        'appels' => FauxServeurPersistant::appels($run),
        'messages' => $o->table('envoi_messages')->where('workspace_id', $ws)->orderBy('etape')->pluck('statut', 'inscription_id')->all(),
        'inscriptions' => $o->table('envoi_inscriptions')->where('workspace_id', $ws)->pluck('statut', 'id')->all(),
        'registre' => (int) $o->table('envoi_messages_envoyes')->where('workspace_id', $ws)->count(),
        'compteur' => (int) $o->table('envoi_compteur_espace')->where('workspace_id', $ws)->sum('n'),
    ];
}

/**
 * Déroule un scénario : cycle 1 tué au point donné, cycle 2 (+2 h, la réservation est « ancienne »), cycle 3 (+3 h, rien ne doit bouger).
 *
 * @return array{ws: string, ins: list<string>, run: string, photo: array<string, mixed>, apres2: array<string, mixed>, critiques: list<string>, cycle3: array<string, mixed>}
 */
function repScenario(string $point, ?Closure $casserLaReprise = null): array
{
    $lundi = repLundi($point);
    $run = (string) Str::uuid();
    $e = null;
    try {
        FauxServeurPersistant::creerTable();
        $e = repEspace($lundi);
        $premier = repOuvrier($e['ws'], $run, $point, $lundi);
        expect($premier['signal'])->toBe(9, "le processus devait mourir par SIGKILL ({$point}) : " . $premier['erreur']);
        $photo = repPhoto($e['ws'], $run);
        if ($casserLaReprise !== null) {
            $casserLaReprise($e, $run);
        }
        $deux = repOuvrier($e['ws'], $run, 'aucun', $lundi->modify('+2 hours'));
        expect($deux['code'])->toBe(0, 'reprise en échec : ' . $deux['erreur']);
        $critiques = repCritiques($deux, 'cycle 2');
        $apres2 = repPhoto($e['ws'], $run);
        $trois = repOuvrier($e['ws'], $run, 'aucun', $lundi->modify('+3 hours'));
        expect($trois['code'])->toBe(0, 'troisième cycle en échec : ' . $trois['erreur']);
        $critiques = array_merge($critiques, repCritiques($trois, 'cycle 3')); // le troisième cycle ne doit rien alerter non plus

        return $e + ['run' => $run, 'photo' => $photo, 'apres2' => $apres2, 'critiques' => $critiques, 'cycle3' => repPhoto($e['ws'], $run)];
    } finally {
        if ($e !== null) {
            repNettoyer($e['ws'], $lundi);
        }
        FauxServeurPersistant::retirerTable();
    }
}

/** Invariants communs : jamais deux appels pour une clé, plafond respecté, un troisième cycle ne change rien. */
function repInvariants(array $r): void
{
    foreach ($r['cycle3']['appels'] as $cle => $n) {
        expect($n)->toBe(1, "la clé {$cle} a été injectée {$n} fois");
    }
    expect(array_sum($r['cycle3']['appels']))->toBeLessThanOrEqual(REPRISE_PLAFOND)
        ->and($r['cycle3']['compteur'])->toBeLessThanOrEqual(REPRISE_PLAFOND)
        ->and($r['cycle3'])->toEqual($r['apres2']);
}

test('arrêt brutal après le temps A : le message réservé par le séquenceur part UNE fois à la reprise, celui jamais réservé aussi, le troisième attend (plafond)', function () {
    $r = repScenario('apres_temps_A');

    expect($r['photo']['appels'])->toBe([])
        ->and($r['photo']['messages'])->toHaveCount(1);
    [$i1, $i2, $i3] = $r['ins'];
    expect($r['cycle3']['appels'])->toBe(['seq-' . $i1 . '-1' => 1, 'seq-' . $i2 . '-1' => 1])
        ->and($r['cycle3']['messages'][$i1])->toBe('envoye')
        ->and($r['cycle3']['messages'][$i2])->toBe('envoye')
        ->and($r['cycle3']['messages'][$i3] ?? null)->not->toBe('envoye') // le plafond du jour le garde à l'attente
        ->and($r['cycle3']['registre'])->toBe(2);
    repInvariants($r);
});

test('arrêt brutal pendant l\'appel (rien n\'est parti) : issue incertaine, inscription arrêtée avec alerte, jamais réinjectée', function () {
    $r = repScenario('pendant_appel');

    [$i1, $i2] = $r['ins'];
    expect($r['photo']['appels'])->toBe([])
        ->and($r['cycle3']['appels'])->toBe(['seq-' . $i2 . '-1' => 1])
        ->and($r['cycle3']['inscriptions'][$i1])->not->toBe('active')
        ->and($r['cycle3']['messages'][$i1])->not->toBe('envoye')
        ->and($r['critiques'])->toContain('envoi.injection_a_verifier')
        ->and($r['cycle3']['messages'][$i2])->toBe('envoye');
    repInvariants($r);
});

test('arrêt brutal après l\'acceptation du faux serveur : le message n\'est PAS réinjecté, issue incertaine, inscription arrêtée avec alerte', function () {
    $r = repScenario('apres_acceptation');

    [$i1, $i2] = $r['ins'];
    expect($r['photo']['appels'])->toBe(['seq-' . $i1 . '-1' => 1])
        ->and($r['cycle3']['appels'])->toBe(['seq-' . $i1 . '-1' => 1, 'seq-' . $i2 . '-1' => 1])
        ->and($r['cycle3']['inscriptions'][$i1])->not->toBe('active')
        ->and($r['cycle3']['messages'][$i1])->not->toBe('envoye')
        ->and($r['critiques'])->toContain('envoi.injection_a_verifier')
        ->and($r['cycle3']['messages'][$i2])->toBe('envoye');
    repInvariants($r);
});

test('arrêt brutal pendant le temps C : le message accepté est conclu UNE fois (envoyé, registre), le serveur n\'est pas rappelé', function () {
    $r = repScenario('pendant_temps_C');

    [$i1, $i2] = $r['ins'];
    expect($r['photo']['appels'])->toBe(['seq-' . $i1 . '-1' => 1])
        ->and($r['photo']['messages'][$i1])->toBe('reserve') // la conclusion n'a jamais été validée
        ->and($r['cycle3']['appels'])->toBe(['seq-' . $i1 . '-1' => 1, 'seq-' . $i2 . '-1' => 1])
        ->and($r['cycle3']['messages'][$i1])->toBe('envoye')
        ->and($r['cycle3']['messages'][$i2])->toBe('envoye')
        ->and($r['cycle3']['registre'])->toBe(2)
        ->and($r['critiques'])->toBe([]);
    repInvariants($r);
});

/**
 * TÉMOIN OBLIGATOIRE : la preuve ne vaut que si elle peut rougir. On « retire la reprise » (la mémoire durable de la
 * passerelle et du séquenceur est effacée après la mort du processus) : le même scénario produit alors un double envoi, et le
 * détecteur des autres tests (une clé injectée plus d'une fois) le voit.
 */
test('témoin : sans mémoire durable de la reprise, le message accepté est injecté DEUX fois et le détecteur le voit', function () {
    $r = repScenario('apres_acceptation', function (array $e, string $run): void {
        $o = DB::connection('pgsql_owner');
        $o->unprepared('SET session_replication_role = replica');
        try {
            $o->table('envoi_messages')->where('workspace_id', $e['ws'])->where('inscription_id', $e['ins'][0])->delete();
            $o->table('envoi_journal_commandes')->where('workspace_id', $e['ws'])->where('cle_idempotence', 'like', 'seq-' . $e['ins'][0] . '-1%')->delete();
        } finally {
            $o->unprepared('SET session_replication_role = DEFAULT');
        }
    });

    expect($r['cycle3']['appels']['seq-' . $r['ins'][0] . '-1'] ?? 0)->toBeGreaterThan(1);
    $rougit = false;
    try {
        repInvariants($r);
    } catch (Throwable) {
        $rougit = true;
    }
    expect($rougit)->toBeTrue('le détecteur d\'invariants devait rougir sur un double envoi');
});

test('garde du détecteur d\'alertes : une sortie du fils illisible ou sans « critiques » fait échouer au lieu de passer pour « aucune alerte »', function () {
    $vide = ['signal' => null, 'code' => 0, 'sortie' => null, 'erreur' => 'rien'];
    $sansCle = ['signal' => null, 'code' => 0, 'sortie' => ['bilan' => []], 'erreur' => ''];

    expect(fn () => repCritiques($vide, 'cycle test'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => repCritiques($sansCle, 'cycle test'))->toThrow(ExpectationFailedException::class)
        ->and(repCritiques(['signal' => null, 'code' => 0, 'sortie' => ['critiques' => ['envoi.x']], 'erreur' => ''], 'cycle test'))->toBe(['envoi.x']);
});

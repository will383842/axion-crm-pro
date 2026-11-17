<?php

use App\Envoi\Campagnes\ConstructionSequence;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\GestesCampagne;
use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\CampagneRefusee;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

/*
 * Lot L5-A — « lancer » et « reprendre » propagés à la séquence (service seul, aucune route). Chaque test de comportement est joué
 * SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE. Données fictives (`*.example.test`). Aucun envoi : le drapeau d'envoi réel reste faux.
 */
uses(TestCase::class, RefreshDatabase::class);

const GCS_MODES = ['app', 'proprio'];

beforeEach(function () {
    config(['envoi.plafond_jour' => 20]);
    $this->ws = gcsEspace();
});

afterEach(function () {
    MaterialisationCampagne::$niveauTolere = 0;
});

function gcsEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Gcs', 'slug' => 'gcs-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function gcsSous(string $mode, Closure $f): mixed
{
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

/** Code machine du refus rendu par $f (null = accepté). */
function gcsRefus(Closure $f): ?string
{
    try {
        $f();

        return null;
    } catch (CampagneRefusee $e) {
        return $e->codeErreur;
    }
}

/**
 * Campagne « programmee » SANS boîte, sur $n entreprises fictives, matérialisée pour de vrai (sauf $materialiser = false).
 * Opposition simulée par $opposees (lecteur injecté : toutes les fiches opposées).
 */
function gcsPrete(string $ws, int $n = 3, bool $materialiser = true, array $contenu = [], bool $opposees = false): string
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . Str::random(6)]);
    for ($i = 1; $i <= $n; $i++) {
        $e = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => F::siren(), 'denomination' => 'Société fictive', 'discovery_source' => 'insee']);
        DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $i, 'email' => "prenom.nom{$i}.{$e}@societe.example.test"]);
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $e, 'origine' => 'coche']);
    }
    $sequence = [];
    foreach ([0, 3] as $i => $delai) {
        $m = app(ServiceMessagesTypes::class)->creer($ws, 'Etape ' . ($i + 1), 'Objet ' . ($i + 1), 'Corps ' . ($i + 1), 'le fondateur');
        app(ServiceMessagesTypes::class)->valider($ws, $m['id'], 1);
        $sequence[] = ['etape' => $i + 1, 'message_id' => $m['id'], 'delai_jours' => $delai];
    }
    $depot = app(DepotCampagnes::class);
    $id = $depot->creer($ws, 'Campagne fictive', ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Liste fictive'], [], 2, ['contenu' => $contenu + ['sequence' => $sequence], 'etapes_faites' => []], 'le fondateur');
    $depot->transitionner($ws, $id, 'a_valider', 1, 'le fondateur', 'test', 'cle-gcs-' . Str::random(12));
    DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'programmee', 'version' => 3]);
    DB::table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $id, 'transition' => 'a_valider>programmee', 'auteur' => 'le fondateur',
        'motif' => 'test', 'cle_idempotence' => 'cle-j-' . Str::random(12), 'cree_le' => DB::raw("now() - interval '10 minutes'")]);
    if ($materialiser) {
        $lecteur = $opposees ? fn ($w, array $r): array => array_map(fn () => ['opposee' => true, 'motifs' => ['opposition']], $r) : null;
        MaterialisationCampagne::$niveauTolere = DB::transactionLevel();
        (new MaterialisationCampagne($depot, new SelectionDestinataires($lecteur), app(ConstructionSequence::class), app(DepotSocle::class)))
            ->materialiser($ws, $id, new DateTimeImmutable('2026-11-16 07:00', new DateTimeZone('Europe/Paris')));
    }

    return $id;
}

function gcsGestes(string $id): array
{
    $seq = DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id');

    return DB::table('envoi_sequences_gestes')->where('sequence_id', $seq)->orderBy('cree_le')->orderBy('id')->get(['geste', 'auteur'])->map(fn ($g) => $g->geste . '|' . $g->auteur)->all();
}

/** Empreinte de ce que le geste peut écrire : statut, version, journal, gestes. */
function gcsEtat(string $id): array
{
    $c = DB::table('envoi_campagnes')->where('id', $id)->first();

    return [$c->statut, $c->version, DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->count(), gcsGestes($id)];
}

function gcsGestesService(): GestesCampagne
{
    return app(GestesCampagne::class);
}

/** Campagne en_cours-prête (programmee, séquence, préparation terminée à 1 inscrit) COMMITTÉE par le propriétaire. @return array{string, string, string} espace, campagne, séquence */
function gcsSemer(ConnectionInterface $proprio, bool $terminee = true): array
{
    $ws = (string) Str::uuid();
    $camp = (string) Str::uuid();
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-gcs-' . substr($ws, 0, 6), 'name' => 'Course L5-A', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $proprio->table('envoi_campagnes')->insert(['id' => $camp, 'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(['type' => 'liste', 'reference' => 'r', 'libelle' => 'l']), 'nb_etapes' => 1, 'cree_par' => 'r']);
    $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => 'a_valider', 'version' => 2]);
    $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => 'programmee', 'version' => 3]);
    $seq = LienCampagneSequence::poser($proprio, $ws, $camp, 3, 1)['sequence'];
    $proprio->table('envoi_campagnes_materialisations')->insert(['campagne_id' => $camp, 'workspace_id' => $ws, 'sequence_id' => $seq]);
    if ($terminee) {
        $proprio->table('envoi_campagnes_materialisations')->where('campagne_id', $camp)->update(gcsFin());
    }

    return [$ws, $camp, $seq];
}

/** Fin de préparation (chemin légal du propriétaire). */
function gcsFin(): array
{
    return ['etat' => 'terminee', 'terminee_le' => now(), 'compteurs' => '{"inscrits":1,"membres":1,"retenus":1}'];
}

function gcsNettoyer(ConnectionInterface $proprio, string $ws): void
{
    $proprio->statement("SET lock_timeout = '3s'");   // un test en échec laisse sa transaction ouverte : on ne s'y bloque pas
    $proprio->statement("SET session_replication_role = 'replica'");
    foreach (['envoi_campagnes_journal', 'envoi_sequences_gestes', 'envoi_sequence_etapes', 'envoi_campagnes_materialisations', 'envoi_campagnes', 'envoi_sequences'] as $t) {
        $proprio->table($t)->where('workspace_id', $ws)->delete();
    }
    $proprio->table('workspaces')->where('id', $ws)->delete();
    $proprio->statement("SET session_replication_role = 'origin'");
}

test('L5-A 1 lancer nominal : en_cours, 1 journal « lancer », geste reprise (auteur campagne:lancer), séquenceur : plus en pause ; cycle porte fermée, 0 message', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $id = gcsPrete($ws);
        expect(array_slice(gcsGestes($id), -1))->toBe(['pause|campagne:naissance'], $mode);
        $c = gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', null));
        $seq = $c->sequence_id;
        expect($c->statut)->toBe('en_cours')
            ->and(DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->where('transition', 'programmee>en_cours')->where('motif', 'lancer')->count())->toBe(1)
            ->and(array_slice(gcsGestes($id), -1))->toBe(['reprise|campagne:lancer']);
        $geste = DB::selectOne("SELECT COALESCE((SELECT g.geste FROM envoi_sequences_gestes g WHERE g.workspace_id = ? AND g.sequence_id = ? ORDER BY g.cree_le DESC, g.id DESC LIMIT 1), 'reprise') AS g", [$ws, $seq])->g;
        expect($geste)->toBe('reprise');
        Artisan::call('envoi:cycle');
        expect(Artisan::output())->toContain('porte fermée : rien à faire')->and(DB::table('envoi_messages')->where('workspace_id', $ws)->count())->toBe(0);
    }
});

test('L5-A 2 préparation pas finie : absente ou en_cours (horizon plein, 20 inscrits) ⇒ materialisation_en_cours ; rien d\'écrit', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $absente = gcsPrete($ws, 3, false);
        config(['envoi.plafond_jour' => 1]);
        $pleine = gcsPrete($ws, 30);
        config(['envoi.plafond_jour' => 20]);
        expect(DB::table('envoi_campagnes_materialisations')->where('campagne_id', $pleine)->value('etat'))->toBe('en_cours');
        $avant = gcsEtat($pleine);
        try {
            gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $pleine, 'le fondateur', null));
            $this->fail('refus attendu');
        } catch (CampagneRefusee $e) {
            expect($e->codeErreur)->toBe('materialisation_en_cours')->and($e->getMessage())->toContain('20')->and(gcsEtat($pleine))->toBe($avant);
        }
        // matérialisation absente MAIS séquence posée (chemin légal du propriétaire) ⇒ même refus
        LienCampagneSequence::poser(DB::connection(), $ws, $absente, 3, 2);
        expect(gcsRefus(fn () => gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $absente, 'le fondateur', null))))->toBe('materialisation_en_cours');
    }
});

test('L5-A 3 préparation bloquée (jours_non_applicables) ⇒ materialisation_bloquee ; rien d\'écrit', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $id = gcsPrete($ws, 3, true, ['jours' => ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam']]);
        expect(DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->value('etat'))->toBe('bloquee');
        $avant = gcsEtat($id);
        expect(gcsRefus(fn () => gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', null))))->toBe('materialisation_bloquee')->and(gcsEtat($id))->toBe($avant);
    }
});

test('L5-A 4 sans séquence ⇒ sans_sequence, message qui dit quoi faire ; rien d\'écrit', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $id = gcsPrete($ws, 3, false);
        $avant = gcsEtat($id);
        try {
            gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', null));
            $this->fail('refus attendu');
        } catch (CampagneRefusee $e) {
            expect($e->codeErreur)->toBe('sans_sequence')->and($e->getMessage())->toContain('préparée automatiquement')->and(gcsEtat($id))->toBe($avant);
        }
    }
});

test('L5-A 5 aucun destinataire (toutes les fiches opposées, 0 inscrit) ⇒ aucun_destinataire ; personne n\'est inscrit', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $id = gcsPrete($ws, 3, true, [], true);
        expect(DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->value('etat'))->toBe('terminee')
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(0);
        $avant = gcsEtat($id);
        expect(gcsRefus(fn () => gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', null))))->toBe('aucun_destinataire')->and(gcsEtat($id))->toBe($avant);
    }
});

test('L5-A 6 freins : arrêt général et pause automatique refusent, rien d\'écrit, aucun frein levé', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $id = gcsPrete($ws);
        $avant = gcsEtat($id);
        app(DepotSocle::class)->armerToutArreter($ws, 'test');
        expect(gcsRefus(fn () => gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', null))))->toBe('frein_arret_general')
            ->and(gcsEtat($id))->toBe($avant)->and(app(DepotSocle::class)->toutArreter($ws))->toBeTrue();
        app(DepotSocle::class)->leverToutArreter($ws, 'test');
        app(DepotChauffe::class)->ouvrirPause($ws, 'plaintes', null, new DateTimeImmutable('now'), 24);
        expect(gcsRefus(fn () => gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', null))))->toBe('frein_pause_automatique')
            ->and(gcsEtat($id))->toBe($avant)->and(app(DepotChauffe::class)->pausesActives($ws, new DateTimeImmutable('now')))->not->toBe([]);
    }
});

test('L5-A 7 idempotence : même clé = 1 journal, 1 geste ; clé rejouée après pause ou sous frein = rejeu sans erreur ; clé d\'une autre campagne ; double appel sans clé', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $id = gcsPrete($ws);
        $a = gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', 'cle-lancer-0001'));
        $b = gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', 'cle-lancer-0001'));
        expect($a->statut)->toBe('en_cours')->and($b->statut)->toBe('en_cours')
            ->and(DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->where('motif', 'lancer')->count())->toBe(1)
            ->and(array_filter(gcsGestes($id), fn ($g) => str_starts_with($g, 'reprise')))->toHaveCount(1);
        gcsSous($mode, fn () => gcsGestesService()->pause($ws, $id, 'le fondateur', null));
        $nb = count(gcsGestes($id));
        app(DepotSocle::class)->armerToutArreter($ws, 'test');   // un rejeu ne contrôle PAS les freins
        expect(gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', 'cle-lancer-0001'))->statut)->toBe('en_pause')->and(count(gcsGestes($id)))->toBe($nb);
        app(DepotSocle::class)->leverToutArreter($ws, 'test');
        $autre = gcsPrete($ws);
        expect(gcsRefus(fn () => gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $autre, 'le fondateur', 'cle-lancer-0001'))))->toBe('cle_reutilisee');
        // clé prise entre le contrôle et l'écriture (course) : la transition rend « déjà appliqué », AUCUN geste de séquence n'est écrit
        $course = gcsPrete($ws);
        $armee = new ArrayObject(['oui' => true]);
        DB::beforeExecuting(function (string $sql) use ($armee, $ws, $course): void {
            if ($armee['oui'] && str_contains($sql, 'FROM envoi_campagnes_materialisations')) {
                $armee['oui'] = false;
                app(DepotCampagnes::class)->transitionner($ws, $course, 'en_cours', (int) DB::table('envoi_campagnes')->where('id', $course)->value('version'), 'autre', 'lancer', 'cle-course-0001');
            }
        });
        $avantCourse = gcsGestes($course);
        gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $course, 'le fondateur', 'cle-course-0001'));
        expect(gcsGestes($course))->toBe($avantCourse)->and(DB::table('envoi_campagnes_journal')->where('campagne_id', $course)->where('motif', 'lancer')->count())->toBe(1);
        $sans = gcsPrete($ws);
        gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $sans, 'le fondateur', null));
        expect(gcsRefus(fn () => gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $sans, 'le fondateur', null))))->toBe('transition_refusee')
            ->and(array_filter(gcsGestes($sans), fn ($g) => str_starts_with($g, 'reprise')))->toHaveCount(1);
    }
});

test('L5-A 8 reprendre : lancer, pause (campagne:en_pause), reprendre (campagne:reprise) ; les gestes alternent et le dernier suit le statut', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $id = gcsPrete($ws);
        gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', null));
        gcsSous($mode, fn () => gcsGestesService()->pause($ws, $id, 'le fondateur', null));
        expect(array_slice(gcsGestes($id), -1))->toBe(['pause|campagne:en_pause']);
        $c = gcsSous($mode, fn () => gcsGestesService()->reprendre($ws, $id, 'le fondateur', null));
        expect($c->statut)->toBe('en_cours')->and(gcsGestes($id))->toBe(['pause|campagne:naissance', 'reprise|campagne:lancer', 'pause|campagne:en_pause', 'reprise|campagne:reprise']);
    }
});

test('L5-A 9 refus de la base converti : P0001 sur le geste ⇒ reprise_refusee_base, statut et journal inchangés (tout annulé)', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $id = gcsPrete($ws);
        $avant = gcsEtat($id);
        $armee = new ArrayObject(['oui' => true]);
        DB::beforeExecuting(function (string $sql) use ($armee): void {
            if ($armee['oui'] && str_starts_with(ltrim($sql), 'INSERT INTO envoi_sequences_gestes')) {
                $armee['oui'] = false;
                $pdo = new PDOException('refus simulé');
                (new ReflectionProperty($pdo, 'code'))->setValue($pdo, 'P0001');
                throw new QueryException('pgsql', $sql, [], $pdo);
            }
        });
        expect(gcsRefus(fn () => gcsSous($mode, fn () => gcsGestesService()->lancer($ws, $id, 'le fondateur', null))))->toBe('reprise_refusee_base')->and(gcsEtat($id))->toBe($avant);
    }
});

test('L5-A 10 verrou occupé : autre connexion tenant le verrou de la campagne ⇒ concurrence en 6 s au plus, rien d\'écrit', function () {
    $proprio = DB::connection('pgsql_owner');
    [$ws, $camp] = gcsSemer($proprio);
    $autre = DB::connection('pgsql_owner');
    try {
        $avant = gcsEtat($camp);
        $autre->beginTransaction();
        $autre->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_campagne:' . $camp]);
        $t = microtime(true);
        expect(gcsRefus(fn () => gcsGestesService()->lancer($ws, $camp, 'le fondateur', null)))->toBe('concurrence')->and(microtime(true) - $t)->toBeLessThan(6.0);
        $autre->rollBack();
        expect(gcsEtat($camp))->toBe($avant);
    } finally {
        if ($autre->transactionLevel() > 0) {
            $autre->rollBack();
        }
        try {
            gcsNettoyer($proprio, $ws);
        } catch (Throwable) {   // en échec, la transaction du test tient encore les lignes : l'échec d'origine doit rester visible
        }
    }
});

test('L5-A 11 concurrence réelle : 2 lancer + 1 pause en processus réels ⇒ statut cohérent avec le journal et le dernier geste', function () {
    $proprio = DB::connection('pgsql_owner');
    [$ws, $camp, $seq] = gcsSemer($proprio);
    try {
        $depart = microtime(true) + 4;
        foreach (['lancer', 'lancer', 'pause'] as $i => $geste) {
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/Support/campagne-lancer-concurrent.php'), $ws, $camp, $geste, sprintf('%.4F', $depart)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes[$i]);
        }
        foreach ($procs as $i => $p) {
            $sortie = stream_get_contents($tubes[$i][1]) . stream_get_contents($tubes[$i][2]);
            expect(proc_close($p))->toBeIn([0, 3], $sortie);   // 0 appliqué, 3 refusé proprement ; jamais une erreur SQL
        }
        $statut = $proprio->table('envoi_campagnes')->where('id', $camp)->value('statut');
        $dernier = (string) $proprio->table('envoi_campagnes_journal')->where('campagne_id', $camp)->orderBy('id', 'desc')->value('transition');
        $geste = (string) $proprio->table('envoi_sequences_gestes')->where('sequence_id', $seq)->orderBy('cree_le', 'desc')->orderBy('id', 'desc')->value('geste');
        expect(explode('>', $dernier)[1] ?? '')->toBe($statut)->and($geste)->toBe($statut === 'en_cours' ? 'reprise' : 'pause');
    } finally {
        gcsNettoyer($proprio, $ws);
    }
});

test('L5-A 12 lancer contre fin de préparation : la fin de préparation committée pendant le lancer ⇒ jamais en_cours avec une préparation en_cours', function () {
    $proprio = DB::connection('pgsql_owner');
    [$ws, $camp] = gcsSemer($proprio, false);
    $fin = DB::connection('pgsql_owner');
    try {
        $fin->beginTransaction();
        $fin->table('envoi_campagnes_materialisations')->where('campagne_id', $camp)->update(gcsFin());   // ligne verrouillée, pas encore visible
        $p = proc_open([PHP_BINARY, base_path('tests/Support/campagne-lancer-concurrent.php'), $ws, $camp, 'lancer', sprintf('%.4F', microtime(true) + 1)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
        usleep(2_500_000);
        $fin->commit();
        $sortie = stream_get_contents($tubes[1]) . stream_get_contents($tubes[2]);
        $code = proc_close($p);
        $statut = $proprio->table('envoi_campagnes')->where('id', $camp)->value('statut');
        $etat = $proprio->table('envoi_campagnes_materialisations')->where('campagne_id', $camp)->value('etat');
        expect($code)->toBeIn([0, 3], $sortie)->and($statut === 'en_cours' ? $etat : 'terminee')->toBe('terminee')->and($code === 0 ? $statut : 'en_cours')->toBe('en_cours');
    } finally {
        if ($fin->transactionLevel() > 0) {
            $fin->rollBack();
        }
        gcsNettoyer($proprio, $ws);
    }
});

test('L5-A 13 cloisonnement : lancer la campagne d\'un autre espace ⇒ introuvable, rien d\'écrit dans aucun espace', function () {
    foreach (GCS_MODES as $mode) {
        $ws = gcsEspace();
        $autre = gcsEspace();
        $id = gcsPrete($ws);
        $avant = gcsEtat($id);
        expect(gcsRefus(fn () => gcsSous($mode, fn () => gcsGestesService()->lancer($autre, $id, 'le fondateur', null))))->toBe('non_trouve')->and(gcsEtat($id))->toBe($avant);
    }
});

test('L5-A 14 source : GestesCampagne.php ne contient plus le verrou envoi:campagnes:', function () {
    expect((string) file_get_contents(app_path('Envoi/Campagnes/GestesCampagne.php')))->not->toContain('envoi:campagnes:');
});

<?php

use App\Envoi\Campagnes\ClotureInscriptionsCampagne;
use App\Envoi\Campagnes\ConstructionSequence;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\GestesCampagne;
use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Campagnes\MaterialisationRefusee;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Envoi\Sequence\Evenement;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

/*
 * Lot L5-B — « arrêter » une campagne et clôture de ses inscriptions. Clôturer = statut « arretee » + motif, JAMAIS de suppression.
 * Comportements joués SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE. Données fictives (`*.example.test`). Aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['envoi.plafond_jour' => 20]);
});
afterEach(function () {
    MaterialisationCampagne::$niveauTolere = 0;
});

function cicEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Cic', 'slug' => 'cic-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function cicSous(string $mode, Closure $f): mixed
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

function cicRefus(Closure $f): ?string
{
    try {
        $f();

        return null;
    } catch (CampagneRefusee $e) {
        return $e->codeErreur;
    }
}

/** Liste de $n entreprises fictives (une personne chacune). */
function cicListe(string $ws, int $n): int
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . Str::random(6)]);
    for ($i = 1; $i <= $n; $i++) {
        $e = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => F::siren(), 'denomination' => 'Société fictive', 'discovery_source' => 'insee']);
        DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $i, 'email' => "prenom.nom{$i}.{$e}@societe.example.test"]);
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $e, 'origine' => 'coche']);
    }

    return $liste;
}

/** Campagne « programmee » sur la liste, matérialisée pour de vrai (sauf $materialiser = false). */
function cicCampagne(string $ws, int $liste, bool $materialiser = true): string
{
    $sequence = [];
    foreach ([0, 3] as $i => $delai) {
        $m = app(ServiceMessagesTypes::class)->creer($ws, 'Etape ' . ($i + 1), 'Objet ' . ($i + 1), 'Corps ' . ($i + 1), 'le fondateur');
        app(ServiceMessagesTypes::class)->valider($ws, $m['id'], 1);
        $sequence[] = ['etape' => $i + 1, 'message_id' => $m['id'], 'delai_jours' => $delai];
    }
    $depot = app(DepotCampagnes::class);
    $id = $depot->creer($ws, 'Campagne fictive', ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Liste fictive'], [], 2, ['contenu' => ['sequence' => $sequence], 'etapes_faites' => []], 'le fondateur');
    $depot->transitionner($ws, $id, 'a_valider', 1, 'le fondateur', 'test', 'cle-cic-' . Str::random(12));
    DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'programmee', 'version' => 3]);
    DB::table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $id, 'transition' => 'a_valider>programmee', 'auteur' => 'le fondateur',
        'motif' => 'test', 'cle_idempotence' => 'cle-j-' . Str::random(12), 'cree_le' => DB::raw("now() - interval '10 minutes'")]);
    if ($materialiser) {
        MaterialisationCampagne::$niveauTolere = DB::transactionLevel();
        (new MaterialisationCampagne($depot, new SelectionDestinataires, app(ConstructionSequence::class), app(DepotSocle::class)))
            ->materialiser($ws, $id, new DateTimeImmutable('2026-11-16 07:00', new DateTimeZone('Europe/Paris')));
    }

    return $id;
}

function cicPrete(string $ws, int $n = 3): string
{
    return cicCampagne($ws, cicListe($ws, $n));
}

function cicGestes(): GestesCampagne
{
    return app(GestesCampagne::class);
}

function cicSeq(string $id): string
{
    return (string) DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id');
}

/** @return list<string> gestes de la séquence, dans l'ordre */
function cicTraces(string $id): array
{
    return DB::table('envoi_sequences_gestes')->where('sequence_id', cicSeq($id))->orderBy('cree_le')->orderBy('id')->get(['geste', 'auteur'])->map(fn ($g) => $g->geste . '|' . $g->auteur)->all();
}

/** Ce que les gestes écrivent : statut, version, journal, gestes, répartition des inscriptions. */
function cicEtat(string $id): array
{
    $c = DB::table('envoi_campagnes')->where('id', $id)->first();

    return [$c->statut, $c->version, DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->count(), cicTraces($id),
        DB::table('envoi_inscriptions')->where('sequence_id', $c->sequence_id)->selectRaw('statut, count(*) n')->groupBy('statut')->pluck('n', 'statut')->all()];
}

/** Change le statut par le chemin de la base (la version avance d'un cran), sans passer par le geste. */
function cicStatut(string $id, string $statut): void
{
    DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $statut, 'version' => DB::raw('version + 1')]);
}

function cicActives(string $id): int
{
    return DB::table('envoi_inscriptions')->where('sequence_id', cicSeq($id))->where('statut', 'active')->count();
}

function cicEvenements(string $ws): int
{
    return DB::table('envoi_inscriptions_evenements')->where('workspace_id', $ws)->count();
}

/** Campagne « programmee » reliée avec $n inscriptions actives, VALIDÉES (committées) par le propriétaire. @return array{string, string, string} */
function cicSemer(ConnectionInterface $proprio, int $n): array
{
    $ws = (string) Str::uuid();
    $camp = (string) Str::uuid();
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-cic-' . substr($ws, 0, 6), 'name' => 'Course L5-B', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $proprio->table('envoi_campagnes')->insert(['id' => $camp, 'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(['type' => 'liste', 'reference' => 'r', 'libelle' => 'l']), 'nb_etapes' => 1, 'cree_par' => 'r']);
    $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => 'a_valider', 'version' => 2]);
    $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => 'programmee', 'version' => 3]);
    $seq = LienCampagneSequence::poser($proprio, $ws, $camp, 3, 1)['sequence'];
    $proprio->statement("INSERT INTO envoi_inscriptions (workspace_id, sequence_id, contact_id, entreprise_id, empreinte_adresse, prochaine_echeance, version_figee)
                         SELECT ?::uuid, ?::uuid, g, g, md5(g::text) || md5((g + 1)::text), now() + interval '3 days', '{}' FROM generate_series(1, {$n}) g", [$ws, $seq]);

    return [$ws, $camp, $seq];
}

function cicNettoyer(ConnectionInterface $proprio, string $ws): void
{
    $proprio->statement("SET lock_timeout = '3s'");
    $proprio->statement("SET session_replication_role = 'replica'");
    foreach (['envoi_inscriptions', 'envoi_campagnes_journal', 'envoi_sequences_gestes', 'envoi_sequence_etapes', 'envoi_campagnes_materialisations', 'envoi_campagnes', 'envoi_sequences'] as $t) {
        $proprio->table($t)->where('workspace_id', $ws)->delete();
    }
    $proprio->table('workspaces')->where('id', $ws)->delete();
    $proprio->statement("SET session_replication_role = 'origin'");
}

test('L5-B 1 arrêter une campagne en cours : séquence en pause, inscriptions actives fermées (campagne_arretee), les autres INCHANGÉES, rien supprimé, aucun événement', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = cicEspace();
        $id = cicPrete($ws, 4);
        cicSous($mode, fn () => cicGestes()->lancer($ws, $id, 'le fondateur', null));
        [$opp, $term] = DB::table('envoi_inscriptions')->where('sequence_id', cicSeq($id))->orderBy('contact_id')->pluck('id')->all();
        DB::table('envoi_inscriptions')->where('id', $opp)->update(['statut' => 'arretee', 'motif_arret' => 'opposition', 'arretee_le' => '2026-11-10 10:00:00+00']);
        DB::table('envoi_inscriptions')->where('id', $term)->update(['statut' => 'terminee']);
        $lignes = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count();
        $evenements = cicEvenements($ws);

        $c = cicSous($mode, fn () => cicGestes()->arreter($ws, $id, 'le fondateur', null));

        expect($c->statut)->toBe('arretee')->and(array_slice(cicTraces($id), -1))->toBe(['pause|campagne:arretee'])
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe($lignes)->and(cicEvenements($ws))->toBe($evenements)
            ->and(cicActives($id))->toBe(0)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('motif_arret', 'campagne_arretee')->whereNotNull('arretee_le')->count())->toBe(2)
            ->and(DB::table('envoi_inscriptions')->where('id', $opp)->value('motif_arret'))->toBe('opposition')
            ->and(DB::table('envoi_inscriptions')->where('id', $term)->value('statut'))->toBe('terminee');
    }
});

test('L5-B 2 arrêter une campagne programmée reliée, préparation en cours : accepté ; ensuite plus de matérialisation ni de nouvelle inscription', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = cicEspace();
        config(['envoi.plafond_jour' => 1]);
        $id = cicPrete($ws, 30);
        config(['envoi.plafond_jour' => 20]);
        expect(DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->value('etat'))->toBe('en_cours');
        $avant = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count();

        $c = cicSous($mode, fn () => cicGestes()->arreter($ws, $id, 'le fondateur', null));
        $motif = null;
        try {
            app(MaterialisationCampagne::class)->materialiser($ws, $id, new DateTimeImmutable('2026-11-17 07:00', new DateTimeZone('Europe/Paris')));
        } catch (MaterialisationRefusee $e) {
            $motif = $e->motif;
        }

        expect($c->statut)->toBe('arretee')->and($motif)->toBe('statut_non_materialisable')->and(cicActives($id))->toBe(0)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe($avant);
    }
});

test('L5-B 3 programmée sans séquence ⇒ sans_sequence avec le conseil « renvoyez-la à valider » ; rien d\'écrit', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = cicEspace();
        $id = cicCampagne($ws, cicListe($ws, 2), false);
        $avant = DB::table('envoi_campagnes')->where('id', $id)->first();
        $journal = DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->count();
        try {
            cicSous($mode, fn () => cicGestes()->arreter($ws, $id, 'le fondateur', null));
            $this->fail('refus attendu');
        } catch (CampagneRefusee $e) {
            expect($e->codeErreur)->toBe('sans_sequence')->and($e->getMessage())->toContain('renvoyez-la à valider')
                ->and(DB::table('envoi_campagnes')->where('id', $id)->first())->toEqual($avant)->and(DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->count())->toBe($journal);
        }
    }
});

test('L5-B 4 terminer : motif campagne_terminee ; une campagne vivante ne se ferme jamais (arrêt interdit hors statut)', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = cicEspace();
        $id = cicPrete($ws, 3);
        cicSous($mode, fn () => cicGestes()->lancer($ws, $id, 'le fondateur', null));
        cicSous($mode, fn () => cicGestes()->terminer($ws, $id, 'le fondateur', null));
        expect(DB::table('envoi_inscriptions')->where('sequence_id', cicSeq($id))->where('motif_arret', ClotureInscriptionsCampagne::MOTIF_TERMINEE)->count())->toBe(3)->and(cicActives($id))->toBe(0);
        $autre = cicPrete($ws, 2);
        expect(cicRefus(fn () => cicSous($mode, fn () => cicGestes()->arreter($ws, $autre, 'le fondateur', null) && cicGestes()->arreter($ws, $autre, 'le fondateur', null))))->toBe('transition_refusee');
    }
});

test('L5-B 5 lots bornés : le geste ferme au plus 1 000 inscriptions, le reste attend la commande (la commande est testée dans MaterialisationCommandeTest)', function () {
    $ws = cicEspace();
    $id = cicPrete($ws, 3);
    DB::statement("INSERT INTO envoi_inscriptions (workspace_id, sequence_id, contact_id, entreprise_id, empreinte_adresse, prochaine_echeance, version_figee)
                   SELECT ?::uuid, ?::uuid, 1000000 + g, 1000000 + g, md5(g::text) || md5((g + 1)::text), now() + interval '3 days', '{}' FROM generate_series(1, 1997) g", [$ws, cicSeq($id)]);
    DB::statement("INSERT INTO envoi_inscriptions (workspace_id, sequence_id, statut, contact_id, entreprise_id, empreinte_adresse, prochaine_echeance, version_figee)
                   SELECT ?::uuid, ?::uuid, 'terminee', 2000000 + g, 2000000 + g, md5(g::text) || md5((g + 7)::text), now() + interval '3 days', '{}' FROM generate_series(1, 1000) g", [$ws, cicSeq($id)]);   // des fermées : elles ne comptent pas dans la limite

    cicGestes()->arreter($ws, $id, 'le fondateur', null);

    expect(cicActives($id))->toBe(1000)->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(3000);
});

test('L5-B 6 l\'adresse est libérée : après l\'arrêt, une autre campagne inscrit la même adresse (aucun écart à l\'insertion)', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = cicEspace();
        $liste = cicListe($ws, 3);
        $a = cicCampagne($ws, $liste);
        cicSous($mode, fn () => cicGestes()->arreter($ws, $a, 'le fondateur', null));

        $b = cicCampagne($ws, $liste);

        $compteurs = json_decode((string) DB::table('envoi_campagnes_materialisations')->where('campagne_id', $b)->value('compteurs'), true);
        expect($compteurs['inscrits'])->toBe(3)->and($compteurs['ecartes_insertion'])->toBe(0)->and(cicActives($b))->toBe(3)->and(cicActives($a))->toBe(0);
    }
});

test('L5-B 8 cloisonnement : arrêter A1 ne touche ni A2 (même espace) ni B (autre espace)', function () {
    foreach (['app', 'proprio'] as $mode) {
        [$a, $b] = [cicEspace(), cicEspace()];
        [$a1, $a2, $b1] = [cicPrete($a, 3), cicPrete($a, 3), cicPrete($b, 3)];
        [$avant2, $avantB] = [cicEtat($a2), cicEtat($b1)];

        cicSous($mode, fn () => cicGestes()->arreter($a, $a1, 'le fondateur', null));

        expect(cicActives($a1))->toBe(0)->and(cicEtat($a2))->toBe($avant2)->and(cicEtat($b1))->toBe($avantB);
        expect(cicRefus(fn () => cicSous($mode, fn () => cicGestes()->arreter($b, $a2, 'le fondateur', null))))->toBe('non_trouve')->and(cicEtat($a2))->toBe($avant2);
    }
});

test('L5-B 9 garde : clore() sur une campagne vivante (programmée, en cours, en pause) ⇒ LogicException, rien d\'écrit', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = cicEspace();
        $id = cicPrete($ws, 3);
        foreach (['programmee', 'en_cours', 'en_pause'] as $statut) {
            if ($statut !== 'programmee') {
                cicStatut($id, $statut);
            }
            $erreur = null;
            try {
                cicSous($mode, fn () => app(ClotureInscriptionsCampagne::class)->clore($ws, $id, new DateTimeImmutable('now')));
            } catch (Throwable $e) {
                $erreur = $e::class;
            }
            expect($erreur)->toBe(LogicException::class, "{$mode} {$statut}")->and(cicActives($id))->toBe(3);
        }
    }
});

test('L5-B 10 SKIP LOCKED : une inscription tenue par une autre connexion est sautée sans attente, fermée à la passe suivante', function () {
    config(['database.connections.verrou' => config('database.connections.pgsql_owner')]);
    $verrou = DB::connection('verrou');
    $proprio = DB::connection('pgsql_owner');
    DB::setDefaultConnection('pgsql_owner');
    $ws = null;
    try {
        [$ws, $camp, $seq] = cicSemer($proprio, 3);
        $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => 'arretee', 'version' => 5]);   // arrêtée sans clôture
        $tenue = $proprio->table('envoi_inscriptions')->where('sequence_id', $seq)->orderBy('contact_id')->value('id');
        $verrou->beginTransaction();
        $verrou->select("SELECT set_config('app.current_workspace_id', ?, false)", [$ws]);
        $verrou->select('SELECT id FROM envoi_inscriptions WHERE workspace_id = ? AND id = ? FOR UPDATE', [$ws, $tenue]);
        $t = microtime(true);

        $n = app(ClotureInscriptionsCampagne::class)->clore($ws, $camp, new DateTimeImmutable('now'));

        expect($n)->toBe(2)->and(microtime(true) - $t)->toBeLessThan(3.0)->and($proprio->table('envoi_inscriptions')->where('id', $tenue)->value('statut'))->toBe('active');
        $verrou->rollBack();
        expect(app(ClotureInscriptionsCampagne::class)->clore($ws, $camp, new DateTimeImmutable('now')))->toBe(1)->and($proprio->table('envoi_inscriptions')->where('sequence_id', $seq)->where('statut', 'active')->count())->toBe(0);
    } finally {
        if ($verrou->transactionLevel() > 0) {
            $verrou->rollBack();
        }
        DB::purge('verrou');
        DB::setDefaultConnection('pgsql');
        if ($ws !== null) {
            cicNettoyer($proprio, $ws);
        }
    }
});

test('L5-B 11 concurrence réelle : 2 arrêts + 1 terminaison + 1 clôture en processus réels ⇒ une seule issue, 0 inscription active, jamais d\'erreur SQL', function () {
    $proprio = DB::connection('pgsql_owner');
    [$ws, $camp, $seq] = cicSemer($proprio, 60);
    try {
        $depart = microtime(true) + 4;
        foreach (['arreter', 'arreter', 'terminer', 'clore'] as $i => $geste) {
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/Support/cloture-concurrente.php'), $ws, $camp, $geste, sprintf('%.4F', $depart)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes[$i]);
        }
        foreach ($procs as $i => $p) {
            $sortie = stream_get_contents($tubes[$i][1]) . stream_get_contents($tubes[$i][2]);
            expect(proc_close($p))->toBeIn([0, 3], $sortie);   // 0 appliqué, 3 refusé proprement ; jamais une erreur SQL brute
        }
        $statut = $proprio->table('envoi_campagnes')->where('id', $camp)->value('statut');
        $motif = $statut === 'arretee' ? 'campagne_arretee' : 'campagne_terminee';
        expect($statut)->toBeIn(['arretee', 'terminee'])->and($proprio->table('envoi_campagnes_journal')->where('campagne_id', $camp)->where('transition', 'like', 'programmee>%')->count())->toBe(1)
            ->and($proprio->table('envoi_inscriptions')->where('sequence_id', $seq)->where('statut', 'active')->count())->toBe(0)
            ->and($proprio->table('envoi_inscriptions')->where('sequence_id', $seq)->where('motif_arret', $motif)->count())->toBe(60);
    } finally {
        cicNettoyer($proprio, $ws);
    }
});

test('L5-B 12 pas de blocage à tort : les motifs de clôture n\'entrent dans aucune liste du séquenceur (adresse ou entreprise arrêtée)', function () {
    $source = (string) file_get_contents(app_path('Envoi/Sequence/Sequenceur.php')) . implode(' ', [...Evenement::VALENT_POUR_L_ADRESSE]);

    expect($source)->not->toContain('campagne_arretee')->not->toContain('campagne_terminee')->not->toContain('MOTIF_ARRETEE')->not->toContain('MOTIF_TERMINEE')
        ->and(ClotureInscriptionsCampagne::MOTIF_ARRETEE)->not->toBeIn([Evenement::REPONSE, Evenement::OPPOSITION, Evenement::MOTIF_GARDE_OPPOSITION, Evenement::MOTIF_ENTREPRISE, Evenement::MOTIF_ADRESSE, ...Evenement::VALENT_POUR_L_ADRESSE])
        ->and(ClotureInscriptionsCampagne::MOTIF_TERMINEE)->not->toBeIn([Evenement::REPONSE, Evenement::OPPOSITION, Evenement::MOTIF_GARDE_OPPOSITION, Evenement::MOTIF_ENTREPRISE, Evenement::MOTIF_ADRESSE, ...Evenement::VALENT_POUR_L_ADRESSE]);
});

test('L5-B 13 idempotence : même clé deux fois ⇒ 1 journal, 1 geste pause ; clé rejouée ⇒ rien d\'écrit', function () {
    foreach (['app', 'proprio'] as $mode) {
        $ws = cicEspace();
        $id = cicPrete($ws, 3);
        $cle = 'cle-arret-' . Str::random(10);
        cicSous($mode, fn () => cicGestes()->arreter($ws, $id, 'le fondateur', $cle));
        $apres = cicEtat($id);

        $c = cicSous($mode, fn () => cicGestes()->arreter($ws, $id, 'le fondateur', $cle));

        expect($c->statut)->toBe('arretee')->and(cicEtat($id))->toBe($apres)->and(DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->where('transition', 'like', '%>arretee')->count())->toBe(1)
            ->and(array_values(array_filter(cicTraces($id), fn ($g) => $g === 'pause|campagne:arretee')))->toHaveCount(1);
    }
});

test('L5-B 14 échec fermé : aucun code de app/Envoi/Campagnes ne remet une inscription « active »', function () {
    foreach (glob(app_path('Envoi/Campagnes/*.php')) as $f) {
        expect((string) file_get_contents($f))->not->toMatch('/SET\s+statut\s*=\s*\'active\'|\'statut\'\s*=>\s*\'active\'/i', $f);
    }
});

test('L5-B 15 rien ne part : après l\'arrêt, `envoi:cycle` répond « porte fermée », zéro envoi_messages, drapeau faux', function () {
    $ws = cicEspace();
    $id = cicPrete($ws, 3);
    cicGestes()->arreter($ws, $id, 'le fondateur', null);
    Artisan::call('envoi:cycle');

    expect(Artisan::output())->toContain('porte fermée : rien à faire')->and(DB::table('envoi_messages')->where('workspace_id', $ws)->count())->toBe(0)->and(config('envoi.reel_actif'))->toBeFalse();
});

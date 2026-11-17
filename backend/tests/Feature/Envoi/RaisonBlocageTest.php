<?php

use App\Envoi\Campagnes\LectureCampagnes;
use App\Envoi\Campagnes\RaisonBlocage;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

/*
 * Lot B8-A (préfixe rbl) — champ `blocage` de la liste et de la fiche (« bloquée, pourquoi »). LECTURE SEULE.
 * Chaque test est joué SOUS LE RÔLE APPLICATIF (pgsql_app, RLS, WorkspaceContext) ET SOUS LE PROPRIÉTAIRE. Données fictives seulement.
 */
uses(TestCase::class, RefreshDatabase::class)->group('envoi');

const RBL_MODES = ['app', 'proprio'];

function rblEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Rbl', 'slug' => 'rbl-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/**
 * Campagne fictive par le chemin légal. $mat : null (aucune ligne) | 'en_cours' | 'terminee:N' | 'bloquee:motif'.
 * $statut : brouillon, programmee, en_cours, en_pause, terminee, arretee (les trois derniers exigent une préparation terminée).
 *
 * @return array{string, string} campagne, séquence ('' si brouillon)
 */
function rblCampagne(string $ws, string $statut = 'programmee', ?string $mat = null, bool $boites = false, bool $plafond = false, string $dernierGeste = 'pause'): array
{
    $id = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Campagne fictive', 'cible' => json_encode(['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive']),
        'boites' => $boites ? '{' . (string) Str::uuid() . '}' : '{}', 'nb_etapes' => 2,
        'saisie' => json_encode(['objet' => 'Bonjour', 'contenu' => $plafond ? ['plafond_jour' => 50] : []]), 'cree_par' => 'responsable']);
    if ($statut === 'brouillon') {
        return [$id, ''];
    }
    $v = 1;
    foreach (['a_valider', 'programmee'] as $s) {
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $s, 'version' => ++$v]);
    }
    $seq = LienCampagneSequence::poser(DB::connection(), $ws, $id, $v, 2);
    $v = $seq['version'];
    $suite = ['programmee' => [], 'en_cours' => ['en_cours'], 'en_pause' => ['en_cours', 'en_pause'], 'terminee' => ['en_cours', 'terminee'], 'arretee' => ['arretee']][$statut];
    if ($suite !== [] && $suite[0] === 'en_cours') {
        $mat ??= 'terminee:1';
    }
    if ($mat !== null) {
        DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => $id, 'workspace_id' => $ws, 'sequence_id' => $seq['sequence']]);
        [$etat, $arg] = array_pad(explode(':', $mat, 2), 2, null);
        if ($etat === 'terminee') {
            DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->update(['etat' => 'terminee', 'terminee_le' => now(), 'compteurs' => json_encode(['inscrits' => (int) $arg, 'membres' => (int) $arg, 'retenus' => (int) $arg])]);
        } elseif ($etat === 'bloquee') {
            DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->update(['etat' => 'bloquee', 'motif' => $arg]);
        }
    }
    foreach ($suite as $s) {
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $s, 'version' => ++$v]);
    }
    if ($statut === 'en_cours') {   // une campagne en cours a, en règle générale, une séquence relancée (dernier geste « reprise »)
        DB::table('envoi_sequences_gestes')->insert(['workspace_id' => $ws, 'sequence_id' => $seq['sequence'], 'geste' => 'reprise', 'auteur' => 'test']);
        if ($dernierGeste === 'pause') {
            DB::table('envoi_sequences_gestes')->insert(['workspace_id' => $ws, 'sequence_id' => $seq['sequence'], 'geste' => 'pause', 'auteur' => 'test', 'cree_le' => now()->addSecond()]);
        }
    }

    return [$id, $seq['sequence']];
}

function rblSous(string $mode, string $ws, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/** Le blocage vu par la fiche ET par la liste (les deux doivent s'accorder). */
function rblVu(string $mode, string $ws, string $id): array
{
    return rblSous($mode, $ws, function () use ($ws, $id): array {
        $l = new LectureCampagnes;
        $c = $l->fiche($ws, $id)['campagne'];
        $fiche = array_key_exists('blocage', $c) ? $c['blocage'] : 'absent';
        $c = collect($l->liste($ws, null, null, 1, 100)['data'])->firstWhere('id', $id);
        $liste = array_key_exists('blocage', $c) ? $c['blocage'] : 'absent';

        return [$fiche, $liste];
    });
}

test('rbl 1 un cas par code : code et message exacts, fiche et liste accordées', function () {
    foreach (RBL_MODES as $mode) {
        $ws = rblEspace();
        $cas = [
            'reglage_non_applique' => rblCampagne($ws, 'programmee', null, true)[0],
            'preparation_bloquee' => rblCampagne($ws, 'programmee', 'bloquee:cible_introuvable')[0],
            'aucun_destinataire' => rblCampagne($ws, 'programmee', 'terminee:0')[0],
            'sequence_en_pause' => rblCampagne($ws, 'en_cours', 'terminee:1', false, false, 'pause')[0],
        ];
        $messages = [
            'reglage_non_applique' => 'Boîtes ou plafond choisis : l’envoi ne sait pas encore les appliquer. Renvoyez la campagne à valider et recréez-la sans eux.',
            'preparation_bloquee' => 'La cible de la campagne est introuvable : choisissez une autre cible.',
            'aucun_destinataire' => 'La préparation est terminée mais aucun destinataire n’est retenu : élargissez la cible.',
            'sequence_en_pause' => 'La séquence de la campagne est en pause : reprenez la campagne pour relancer les envois.',
        ];
        foreach ($cas as $code => $id) {
            expect(rblVu($mode, $ws, $id))->toBe([['code' => $code, 'message' => $messages[$code]], ['code' => $code, 'message' => $messages[$code]]], "$mode $code");
        }
        // Freins : une campagne propre (en cours, séquence relancée), puis arrêt général, puis pause automatique.
        [$propre] = rblCampagne($ws, 'en_cours', 'terminee:1', false, false, 'reprise');
        expect(rblVu($mode, $ws, $propre))->toBe([null, null], $mode);
        app(DepotSocle::class)->armerToutArreter($ws, 'test');
        expect(rblVu($mode, $ws, $propre)[0]['code'])->toBe('frein_arret_general')->and(rblVu($mode, $ws, $propre)[1]['code'])->toBe('frein_arret_general');
        app(DepotSocle::class)->leverToutArreter($ws, 'test');
        app(DepotChauffe::class)->ouvrirPause($ws, 'plaintes', null, new DateTimeImmutable('now'), 24);
        expect(rblVu($mode, $ws, $propre)[0]['code'])->toBe('frein_pause_automatique')->and(rblVu($mode, $ws, $propre)[1]['code'])->toBe('frein_pause_automatique');
    }
});

test('rbl 1b les motifs de la base ont un message sans code ; motif inconnu ou absent = phrase générique ; préparation en cours, ni bloquée ni vide', function () {
    foreach (RBL_MODES as $mode) {
        $ws = rblEspace();
        foreach (['jours_non_applicables', 'objet_b_non_applicable', 'etapes_invalides', 'sequence_incoherente', 'cible_introuvable', 'espace_non_emetteur'] as $motif) {
            [$f] = rblVu($mode, $ws, rblCampagne($ws, 'programmee', "bloquee:$motif")[0]);
            expect($f['code'])->toBe('preparation_bloquee')->and($f['message'])->not->toBe(RaisonBlocage::messagePreparation('inconnu'))
                ->and($f['message'])->not->toContain($motif)->and($f['message'])->not->toContain('_');
        }
        expect(rblVu($mode, $ws, rblCampagne($ws, 'programmee', 'en_cours')[0]))->toBe([null, null], $mode);
    }
    foreach (['cible_non_supportee', 'population_non_ouverte', 'filtre_origine_inactif'] as $motif) {
        expect(RaisonBlocage::messagePreparation($motif))->not->toBe(RaisonBlocage::messagePreparation('inconnu'))->and(RaisonBlocage::messagePreparation($motif))->not->toContain('_');
    }
    expect(RaisonBlocage::messagePreparation('motif_inconnu'))->toBe('La préparation de la campagne est bloquée.')->and(RaisonBlocage::messagePreparation(null))->toBe('La préparation de la campagne est bloquée.');
});

test('rbl 1c statuts hors programmee/en_cours/en_pause : null, même sous frein et avec boîtes', function () {
    foreach (RBL_MODES as $mode) {
        $ws = rblEspace();
        $ids = [rblCampagne($ws, 'brouillon', null, true)[0], rblCampagne($ws, 'terminee')[0], rblCampagne($ws, 'arretee', null, true)[0]];
        app(DepotSocle::class)->armerToutArreter($ws, 'test');
        foreach ($ids as $id) {
            expect(rblVu($mode, $ws, $id))->toBe([null, null], $mode);
        }
    }
});

test('rbl 2 reglage_non_applique SANS ligne de matérialisation (plafond seul, boîtes seules) : présent dans la fiche ET la liste', function () {
    foreach (RBL_MODES as $mode) {
        $ws = rblEspace();
        foreach ([rblCampagne($ws, 'programmee', null, false, true)[0], rblCampagne($ws, 'programmee', null, true, false)[0]] as $id) {
            expect(DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->exists())->toBeFalse();
            [$f, $l] = rblVu($mode, $ws, $id);
            expect($f['code'])->toBe('reglage_non_applique')->and($l['code'])->toBe('reglage_non_applique');
        }
    }
});

test('rbl 3 priorité : boîtes + préparation bloquée ⇒ reglage_non_applique ; boîtes + frein ⇒ reglage_non_applique', function () {
    foreach (RBL_MODES as $mode) {
        $ws = rblEspace();
        $id = rblCampagne($ws, 'programmee', 'bloquee:etapes_invalides', true)[0];
        app(DepotSocle::class)->armerToutArreter($ws, 'test');
        expect(rblVu($mode, $ws, $id)[0]['code'])->toBe('reglage_non_applique')->and(rblVu($mode, $ws, $id)[1]['code'])->toBe('reglage_non_applique');
        $sans = rblCampagne($ws, 'programmee', 'bloquee:etapes_invalides')[0];   // préparation bloquée avant le frein
        expect(rblVu($mode, $ws, $sans)[0]['code'])->toBe('preparation_bloquee');
    }
});

test('rbl 4 liste : nombre de requêtes constant (5 ou 25 campagnes), blocage ≤ 5 requêtes', function () {
    $journal = new ArrayObject;   // un seul écouteur pour tout le test (un écouteur ne se retire pas)
    DB::listen(function ($q) use ($journal): void {
        if (! preg_match('/^(SELECT set_config|SET LOCAL ROLE|RESET ROLE)/', $q->sql)) {   // seules les lectures de données comptent
            $journal[] = substr($q->sql, 0, 70);
        }
    });
    foreach (RBL_MODES as $mode) {
        $ws = rblEspace();
        $compter = function (int $n) use ($mode, $ws, $journal): int {
            while (DB::table('envoi_campagnes')->where('workspace_id', $ws)->count() < $n) {
                $i = DB::table('envoi_campagnes')->where('workspace_id', $ws)->count();
                $mats = [null, 'bloquee:cible_introuvable', 'terminee:0', 'en_cours'];
                match ($i % 5) {
                    0 => rblCampagne($ws, 'programmee', $mats[$i % 4], $i % 2 === 0),
                    1 => rblCampagne($ws, 'en_cours', 'terminee:1', false, false, 'pause'),
                    2 => rblCampagne($ws, 'en_pause', 'terminee:1'),
                    3 => rblCampagne($ws, 'terminee'),
                    default => rblCampagne($ws, 'brouillon'),
                };
            }
            $avant = count($journal);
            rblSous($mode, $ws, fn () => (new LectureCampagnes)->liste($ws, null, null, 1, 100));

            return count($journal) - $avant;
        };
        $petit = $compter(5);
        $grand = $compter(25);
        expect($grand)->toBe($petit, "$mode : requêtes indépendantes de la taille de page");
        $lignes = DB::table('envoi_campagnes')->where('workspace_id', $ws)->get()->all();
        $avant = count($journal);
        rblSous($mode, $ws, fn () => WorkspaceContext::run($ws, fn () => (new RaisonBlocage)->pour($ws, $lignes)));
        $vus = array_slice($journal->getArrayCopy(), $avant);
        $requetes = count($vus);
        expect($requetes)->toBeLessThanOrEqual(5, implode(' ## ', $vus));
    }
});

test('rbl 5 cloisonnement : frein armé dans l\'espace B ⇒ aucune campagne de A marquée ; une pause de B non plus', function () {
    foreach (RBL_MODES as $mode) {
        $a = rblEspace();
        $b = rblEspace();
        [$ca] = rblCampagne($a, 'en_cours', 'terminee:1', false, false, 'reprise');
        [$cb] = rblCampagne($b, 'en_cours', 'terminee:1', false, false, 'reprise');
        app(DepotSocle::class)->armerToutArreter($b, 'test');
        app(DepotChauffe::class)->ouvrirPause($b, 'plaintes', null, new DateTimeImmutable('now'), 24);
        expect(rblVu($mode, $a, $ca))->toBe([null, null], $mode)->and(rblVu($mode, $b, $cb)[0]['code'])->toBe('frein_arret_general');
        // IDOR : la fiche d'une campagne d'un autre espace reste introuvable, la liste de A ne montre rien de B.
        expect(fn () => rblSous($mode, $a, fn () => (new LectureCampagnes)->fiche($a, $cb)))->toThrow(CampagneRefusee::class);
        $ids = rblSous($mode, $a, fn () => array_column((new LectureCampagnes)->liste($a, null, null, 1, 100)['data'], 'id'));
        expect($ids)->toBe([$ca]);
    }
});

test('rbl 6 contrat : fiche et liste conformes à CampagneSuivi (blocage nul ou {code, message})', function () {
    foreach (RBL_MODES as $mode) {
        $ws = rblEspace();
        rblCampagne($ws, 'programmee', null, true);
        rblCampagne($ws, 'brouillon');
        $r = rblSous($mode, $ws, fn () => (new LectureCampagnes)->liste($ws, null, null, 1, 100)['data']);
        expect($r)->toHaveCount(2);
        foreach ($r as $c) {
            expect(array_key_exists('blocage', $c))->toBeTrue()->and(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $c))->toBe([]);
        }
        $fiche = rblSous($mode, $ws, fn () => (new LectureCampagnes)->fiche($ws, $r[0]['id'])['campagne']);
        expect(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', $fiche))->toBe([]);
        // Le contrat REFUSE un blocage mal formé (le champ est bien décrit).
        expect(ContratOpenApiEnvoi::ecarts('campagnes.yaml', 'CampagneSuivi', ['blocage' => ['code' => 3]] + $fiche))->not->toBe([]);
    }
});

test('rbl 7 prédicat partagé : MaterialisationCampagne appelle RaisonBlocage::reglageNonApplique et n\'en recopie pas le test', function () {
    $src = (string) file_get_contents(base_path('app/Envoi/Campagnes/MaterialisationCampagne.php'));
    expect($src)->toContain('RaisonBlocage::reglageNonApplique')->and($src)->not->toContain("['plafond_jour']");
});

test('rbl 8 lecture seule : lire ne change aucune ligne (campagnes, matérialisations, gestes, pauses)', function () {
    $ws = rblEspace();
    rblCampagne($ws, 'en_cours', 'terminee:1', true);
    $avant = fn () => [DB::table('envoi_campagnes')->count(), DB::table('envoi_campagnes')->max('maj_le'), DB::table('envoi_campagnes_materialisations')->count(), DB::table('envoi_sequences_gestes')->count(), DB::table('envoi_pauses')->count()];
    $etat = $avant();
    (new LectureCampagnes)->liste($ws, null, null, 1, 100);
    expect($avant())->toBe($etat);
});

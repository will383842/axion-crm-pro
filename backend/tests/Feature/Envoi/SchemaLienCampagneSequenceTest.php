<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

/*
 * Lot X1-L1 — schéma qui relie une campagne validée à sa séquence. Chaque test de sécurité est rejoué SOUS LE RÔLE APPLICATIF
 * (connexion pgsql_app, WorkspaceContext, SET LOCAL ROLE) ET SOUS LE PROPRIÉTAIRE : les gardes sont des déclencheurs, valables pour tous.
 * Données fictives seulement (aucune adresse, aucune personne).
 */
uses(TestCase::class, RefreshDatabase::class);

const SLC_MODES = ['app', 'proprio'];

function slcEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Slc', 'slug' => 'slc-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Campagne « programmee » sans lien (chemin légal) ; rend [id, version]. */
function slcCampagne(string $ws, int $nb = 2, string $statut = 'programmee'): array
{
    $id = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'Campagne fictive', 'cible' => json_encode(['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive']),
        'boites' => '{' . (string) Str::uuid() . '}', 'nb_etapes' => $nb, 'saisie' => json_encode(['objet' => 'Bonjour']), 'cree_par' => 'responsable']);
    $v = 1;
    foreach (['brouillon' => [], 'a_valider' => ['a_valider'], 'programmee' => ['a_valider', 'programmee']][$statut] as $s) {
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $s, 'version' => ++$v]);
    }

    return [$id, $v];
}

/** Campagne reliée (programmee + séquence + lien) ; rend [campagne, version, séquence]. */
function slcLiee(string $ws, int $nb = 2): array
{
    [$c, $v] = slcCampagne($ws, $nb);
    $r = LienCampagneSequence::poser(DB::connection(), $ws, $c, $v, $nb);

    return [$c, $r['version'], $r['sequence']];
}

function slcAvancer(string $c, int $v, string ...$statuts): int
{
    foreach ($statuts as $s) {
        DB::table('envoi_campagnes')->where('id', $c)->update(['statut' => $s, 'version' => ++$v]);
    }

    return $v;
}

/** Exécute dans un point de sauvegarde ; rend le SQLSTATE de l'échec (null = accepté, puis annulé). */
function slcEchec(Closure $f): ?string
{
    try {
        DB::transaction(function () use ($f) {
            $f();
            throw new RuntimeException('__annule__');
        });
    } catch (QueryException $e) {
        return (string) $e->getCode();
    } catch (RuntimeException $e) {
        return $e->getMessage() === '__annule__' ? null : 'autre:' . $e->getMessage();
    }

    return 'inattendu';
}

/** Exécute sous le mode demandé : « app » = rôle applicatif non propriétaire sous RLS, « proprio » = propriétaire. */
function slcSous(string $mode, string $ws, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    $r = $f();
    DB::statement('RESET ROLE');

    return $r;
}

/** Le SQLSTATE de $f joué sous le mode, annulé ensuite. */
function slcCode(string $mode, string $ws, Closure $f): ?string
{
    return slcEchec(fn () => slcSous($mode, $ws, $f));
}

function slcLier(string $c, int $v, string $sequence, array $plus = []): Closure
{
    return fn () => DB::table('envoi_campagnes')->where('id', $c)->update(['sequence_id' => $sequence, 'version' => $v + 1] + $plus);
}

/** @return list<object> */
function slcGestes(string $sequence): array
{
    return DB::table('envoi_sequences_gestes')->where('sequence_id', $sequence)->orderBy('cree_le')->orderBy('id')->get()->all();
}

test('X1-L1 liaison légitime : une seule fois, au statut programmee, vers la séquence de la même campagne (rôle applicatif ET propriétaire)', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c, $v] = slcCampagne($ws);
        $s = LienCampagneSequence::creerSequence(DB::connection(), $ws, $c, 2);
        expect(slcCode($mode, $ws, slcLier($c, $v, $s)))->toBeNull($mode);
        slcSous($mode, $ws, slcLier($c, $v, $s));
        expect(DB::table('envoi_campagnes')->where('id', $c)->value('sequence_id'))->toBe($s, $mode);
    }
});

test('X1-L1 liaison refusée : séquence d\'une autre campagne, d\'un autre espace, inexistante', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        $autreWs = slcEspace();
        [$c, $v] = slcCampagne($ws);
        [$autre] = slcCampagne($ws);
        [$etrangere] = slcCampagne($autreWs);
        $seqAutre = LienCampagneSequence::creerSequence(DB::connection(), $ws, $autre, 2);
        $seqEtrangere = LienCampagneSequence::creerSequence(DB::connection(), $autreWs, $etrangere, 2);
        $seqLibre = LienCampagneSequence::creerSequence(DB::connection(), $ws, null, 2);
        foreach (['autre campagne' => $seqAutre, 'autre espace' => $seqEtrangere, 'inexistante' => (string) Str::uuid(), 'sans campagne' => $seqLibre] as $cas => $seq) {
            expect(slcCode($mode, $ws, slcLier($c, $v, $seq)))->toBe('P0001', "{$mode} {$cas}");
        }
        expect(DB::table('envoi_campagnes')->where('id', $c)->value('sequence_id'))->toBeNull();
    }
});

test('X1-L1 liaison refusée hors du statut programmee : brouillon, a_valider, en_cours', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        // a_valider et brouillon : la séquence naît à « programmee », la campagne recule ensuite (permis tant qu'elle n'est pas reliée)
        [$c, $v] = slcCampagne($ws);
        $s = LienCampagneSequence::creerSequence(DB::connection(), $ws, $c, 2);
        $v = slcAvancer($c, $v, 'a_valider');
        expect(slcCode($mode, $ws, slcLier($c, $v, $s)))->toBe('P0001', "{$mode} a_valider");
        $v = slcAvancer($c, $v, 'brouillon');
        expect(slcCode($mode, $ws, slcLier($c, $v, $s)))->toBe('P0001', "{$mode} brouillon");
        // en_cours : lien et démarrage d'un seul geste
        [$c2, $v2] = slcCampagne($ws);
        $s2 = LienCampagneSequence::creerSequence(DB::connection(), $ws, $c2, 2);
        expect(slcCode($mode, $ws, slcLier($c2, $v2, $s2, ['statut' => 'en_cours'])))->toBe('P0001', "{$mode} en_cours");
        expect(DB::table('envoi_campagnes')->whereIn('id', [$c, $c2])->whereNotNull('sequence_id')->count())->toBe(0);
    }
});

test('X1-L1 seconde liaison et retrait du lien refusés', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c, $v, $s] = slcLiee($ws);
        [$autre] = slcCampagne($ws);
        $seqAutre = LienCampagneSequence::creerSequence(DB::connection(), $ws, $autre, 2);
        expect(slcCode($mode, $ws, slcLier($c, $v, $seqAutre)))->toBe('P0001', "{$mode} second lien")
            ->and(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes')->where('id', $c)->update(['sequence_id' => null, 'version' => $v + 1])))->toBe('P0001', "{$mode} retrait");
        expect(DB::table('envoi_campagnes')->where('id', $c)->value('sequence_id'))->toBe($s);
        // les écritures sans changement du lien restent permises (le nom, par exemple)
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes')->where('id', $c)->update(['nom' => 'Renommée', 'version' => $v + 1])))->toBeNull($mode);
    }
});

test('X1-L1 liaison refusée si le nombre d\'étapes diffère de nb_etapes', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        foreach ([0, 1, 3] as $n) {
            [$c, $v] = slcCampagne($ws, 2);
            $s = LienCampagneSequence::creerSequence(DB::connection(), $ws, $c, $n);
            expect(slcCode($mode, $ws, slcLier($c, $v, $s)))->toBe('P0001', "{$mode} {$n} étapes pour 2");
        }
    }
});

test('X1-L1 campagne reliée : programmee -> a_valider refusé ; cible, saisie, nb_etapes et boîtes figées', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c, $v] = slcLiee($ws);
        $modifs = [
            'a_valider' => ['statut' => 'a_valider'],
            'cible' => ['cible' => json_encode(['type' => 'liste', 'reference' => 'autre', 'libelle' => 'Autre'])],
            'saisie' => ['saisie' => json_encode(['objet' => 'Changé'])],
            'nb_etapes' => ['nb_etapes' => 1],
            'boites' => ['boites' => '{' . (string) Str::uuid() . '}'],
            'boites vides' => ['boites' => '{}'],
        ];
        foreach ($modifs as $cas => $m) {
            expect(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes')->where('id', $c)->update($m + ['version' => $v + 1])))->toBe('P0001', "{$mode} {$cas}");
        }
        // en revanche le départ (programmee -> en_cours) et l'arrêt restent possibles
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes')->where('id', $c)->update(['statut' => 'en_cours', 'version' => $v + 1])))->toBeNull($mode)
            ->and(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes')->where('id', $c)->update(['statut' => 'arretee', 'version' => $v + 1])))->toBeNull($mode);
    }
});

test('X1-L1 campagne NON reliée : l\'ancien comportement subsiste (retour à a_valider, modifications libres)', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c, $v] = slcCampagne($ws);
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes')->where('id', $c)->update(['statut' => 'a_valider', 'nb_etapes' => 3, 'version' => $v + 1])))->toBeNull($mode);
    }
});

test('X1-L1 séquence : campagne_id et fenêtres figés ; étapes figées dès que la séquence est reliée', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c, , $s] = slcLiee($ws);
        [$c2] = slcCampagne($ws);
        $libre = LienCampagneSequence::creerSequence(DB::connection(), $ws, null, 1);
        $maj = fn (string $id, array $m) => fn () => DB::table('envoi_sequences')->where('id', $id)->update($m);
        // campagne_id ne se pose qu'à l'insertion : ni NULL -> valeur, ni changement, ni retrait
        expect(slcCode($mode, $ws, $maj($libre, ['campagne_id' => $c2])))->toBe('P0001', "{$mode} NULL->valeur")
            ->and(slcCode($mode, $ws, $maj($s, ['campagne_id' => $c2])))->toBe('P0001', "{$mode} changement")
            ->and(slcCode($mode, $ws, $maj($s, ['campagne_id' => null])))->toBe('P0001', "{$mode} retrait");
        // fenêtres figées pour une séquence née d'une campagne, libres pour les autres ; le nom reste libre
        expect(slcCode($mode, $ws, $maj($s, ['fenetre_debut' => 8])))->toBe('P0001', "{$mode} début")
            ->and(slcCode($mode, $ws, $maj($s, ['fenetre_fin' => 18])))->toBe('P0001', "{$mode} fin")
            ->and(slcCode($mode, $ws, $maj($s, ['nom' => 'Renommée'])))->toBeNull($mode)
            ->and(slcCode($mode, $ws, $maj($libre, ['fenetre_debut' => 8, 'fenetre_fin' => 18])))->toBeNull($mode);
        // étapes : ajout, modification et suppression refusés après la liaison, permis avant
        $etape = fn (string $seq, int $rang) => ['workspace_id' => $ws, 'sequence_id' => $seq, 'rang' => $rang, 'delai_jours_ouvres' => 1, 'sujet' => 'S', 'corps' => 'C'];
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_sequence_etapes')->insert($etape($s, 3))))->toBe('P0001', "{$mode} ajout")
            ->and(slcCode($mode, $ws, fn () => DB::table('envoi_sequence_etapes')->where('sequence_id', $s)->where('rang', 1)->update(['sujet' => 'Changé'])))->toBe('P0001', "{$mode} modif")
            ->and(slcCode($mode, $ws, fn () => DB::table('envoi_sequence_etapes')->where('sequence_id', $s)->where('rang', 1)->delete()))->toBe('P0001', "{$mode} suppression")
            ->and(slcCode($mode, $ws, fn () => DB::table('envoi_sequence_etapes')->insert($etape($libre, 2))))->toBeNull($mode);
        $n = DB::table('envoi_sequence_etapes')->where('sequence_id', $s)->count();
        expect($n)->toBe(2);
    }
});

test('X1-L1 séquence : une seule par campagne (23505), et seulement pour une campagne programmee sans lien', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        $autreWs = slcEspace();
        [$c] = slcCampagne($ws);
        [$etrangere] = slcCampagne($autreWs);
        [$liee] = slcLiee($ws);
        [$aValider] = slcCampagne($ws, 2, 'a_valider');
        [$brouillon] = slcCampagne($ws, 2, 'brouillon');
        $ins = fn (?string $campagne) => fn () => DB::table('envoi_sequences')->insert(['workspace_id' => $ws, 'nom' => 'S', 'campagne_id' => $campagne]);
        expect(slcCode($mode, $ws, $ins($c)))->toBeNull($mode);
        LienCampagneSequence::creerSequence(DB::connection(), $ws, $c, 0);
        expect(slcCode($mode, $ws, $ins($c)))->toBe('23505', "{$mode} deuxième séquence")
            ->and(slcCode($mode, $ws, $ins($liee)))->toBe('P0001', "{$mode} campagne déjà reliée")
            ->and(slcCode($mode, $ws, $ins($aValider)))->toBe('P0001', "{$mode} a_valider")
            ->and(slcCode($mode, $ws, $ins($brouillon)))->toBe('P0001', "{$mode} brouillon")
            ->and(slcCode($mode, $ws, $ins($etrangere)))->toBe('P0001', "{$mode} autre espace")
            ->and(slcCode($mode, $ws, $ins((string) Str::uuid())))->toBe('P0001', "{$mode} inexistante")
            ->and(slcCode($mode, $ws, $ins(null)))->toBeNull($mode)
            ->and(slcCode($mode, $ws, $ins(null)))->toBeNull($mode);   // les séquences sans campagne ne sont pas limitées
    }
});

test('X1-L1 séquence née en pause : un geste pause « campagne:naissance » est posé avec l\'horloge réelle', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c] = slcCampagne($ws);
        $s = (string) Str::uuid();
        slcSous($mode, $ws, fn () => DB::table('envoi_sequences')->insert(['id' => $s, 'workspace_id' => $ws, 'nom' => 'S', 'campagne_id' => $c]));
        $g = slcGestes($s);
        expect($g)->toHaveCount(1)->and($g[0]->geste)->toBe('pause')->and($g[0]->auteur)->toBe('campagne:naissance')->and($g[0]->workspace_id)->toBe($ws);
        // clock_timestamp() : le geste porte l'heure RÉELLE, strictement après le début de la transaction (now() y est figé)
        expect((bool) DB::selectOne('SELECT cree_le > now() AS ok FROM envoi_sequences_gestes WHERE sequence_id = ?', [$s])->ok)->toBeTrue($mode);
        // sans campagne : aucun geste
        $libre = LienCampagneSequence::creerSequence(DB::connection(), $ws, null, 1);
        expect(slcGestes($libre))->toBe([]);
    }
});

test('X1-L1 reprise refusée tant que la campagne n\'est pas en_cours ET la matérialisation terminee', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c, $v, $s] = slcLiee($ws);
        $reprise = fn () => DB::table('envoi_sequences_gestes')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'geste' => 'reprise', 'auteur' => 'responsable']);
        $pause = fn () => DB::table('envoi_sequences_gestes')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'geste' => 'pause', 'auteur' => 'responsable']);
        expect(slcCode($mode, $ws, $reprise))->toBe('P0001', "{$mode} programmee sans matérialisation")
            ->and(slcCode($mode, $ws, $pause))->toBeNull($mode);
        $v = slcAvancer($c, $v, 'en_cours');
        expect(slcCode($mode, $ws, $reprise))->toBe('P0001', "{$mode} en_cours sans matérialisation");
        DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => $c, 'workspace_id' => $ws, 'sequence_id' => $s]);
        expect(slcCode($mode, $ws, $reprise))->toBe('P0001', "{$mode} matérialisation en_cours");
        DB::table('envoi_campagnes_materialisations')->where('campagne_id', $c)->update(['etat' => 'terminee']);
        expect(slcCode($mode, $ws, $reprise))->toBeNull("{$mode} en_cours + terminee");
        // campagne en pause : refusé même si la matérialisation est terminee
        $v = slcAvancer($c, $v, 'en_pause');
        expect(slcCode($mode, $ws, $reprise))->toBe('P0001', "{$mode} campagne en_pause");
        // une séquence sans campagne se reprend librement
        $libre = LienCampagneSequence::creerSequence(DB::connection(), $ws, null, 1);
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_sequences_gestes')->insert(['workspace_id' => $ws, 'sequence_id' => $libre, 'geste' => 'reprise', 'auteur' => 'responsable'])))->toBeNull($mode);
    }
});

test('X1-L1 reprise refusée aussi quand la matérialisation est bloquee', function () {
    $ws = slcEspace();
    [$c, $v, $s] = slcLiee($ws);
    slcAvancer($c, $v, 'en_cours');
    DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => $c, 'workspace_id' => $ws, 'sequence_id' => $s, 'etat' => 'bloquee', 'motif' => 'cible_introuvable']);
    foreach (SLC_MODES as $mode) {
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_sequences_gestes')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'geste' => 'reprise', 'auteur' => 'r'])))->toBe('P0001', $mode);
    }
});

test('X1-L1 pause, fin et arrêt de la campagne posent le geste pause sur la séquence', function () {
    foreach (SLC_MODES as $mode) {
        foreach ([['en_pause'], ['terminee'], ['arretee'], ['en_pause', 'terminee'], ['en_pause', 'en_cours', 'en_pause']] as $chemin) {
            $ws = slcEspace();
            [$c, $v, $s] = slcLiee($ws);
            $v = slcAvancer($c, $v, 'en_cours');
            $avant = count(slcGestes($s));
            $vers = $chemin;
            $dernier = end($chemin);
            foreach ($vers as $cible) {
                slcSous($mode, $ws, function () use ($c, &$v, $cible) {
                    DB::table('envoi_campagnes')->where('id', $c)->update(['statut' => $cible, 'version' => ++$v]);
                });
            }
            $g = slcGestes($s);
            $nouveaux = array_slice($g, $avant);
            $attendus = array_values(array_filter($chemin, fn ($x) => $x !== 'en_cours'));
            expect(array_map(fn ($x) => $x->auteur, $nouveaux))->toBe(array_map(fn ($x) => 'campagne:' . $x, $attendus), $mode . ' ' . implode('>', $chemin))
                ->and(array_unique(array_map(fn ($x) => $x->geste, $nouveaux)))->toBe(['pause'])
                ->and(end($g)->auteur)->toBe('campagne:' . $dernier);
        }
    }
});

test('X1-L1 le geste pause de la campagne est ordonné après la naissance (clock_timestamp, même transaction)', function () {
    $ws = slcEspace();
    [$c, $v, $s] = slcLiee($ws);
    slcAvancer($c, $v, 'en_cours', 'arretee');
    $g = slcGestes($s);
    expect(array_map(fn ($x) => $x->auteur, $g))->toBe(['campagne:naissance', 'campagne:arretee']);
});

test('X1-L1 campagne sans séquence : aucune transition ne pose de geste, rien ne casse', function () {
    $ws = slcEspace();
    [$c, $v] = slcCampagne($ws);
    $v = slcAvancer($c, $v, 'a_valider', 'programmee', 'a_valider');
    expect(DB::table('envoi_sequences_gestes')->where('workspace_id', $ws)->count())->toBe(0)
        ->and($v)->toBeGreaterThan(2);
});

test('X1-L1 deux liaisons concurrentes, et une liaison contre un retour à a_valider : un seul gagnant (processus réels)', function () {
    $proprio = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-lc-' . substr($ws, 0, 6), 'name' => 'Concurrence lien', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $ids = [];
    try {
        foreach (['liens', 'course'] as $cas) {
            $camp = (string) Str::uuid();
            $proprio->table('envoi_campagnes')->insert(['id' => $camp, 'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(['type' => 'liste', 'reference' => 'r', 'libelle' => 'l']), 'nb_etapes' => 1, 'cree_par' => 'r']);
            $v = 1;
            foreach (['a_valider', 'programmee'] as $e) {
                $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => $e, 'version' => ++$v]);
            }
            $seq = LienCampagneSequence::creerSequence($proprio, $ws, $camp, 1);
            $ids[$cas] = [$camp, $seq, $v];
        }
        $depart = microtime(true) + 4;
        $lance = function (string $mode, string $camp, string $seq, int $v) use ($ws, $depart) {
            $p = proc_open([PHP_BINARY, base_path('tests/Support/lien-campagne-concurrent.php'), $ws, $camp, $mode, $seq, (string) $v, sprintf('%.4F', $depart)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);

            return [$p, $tubes];
        };
        $procs = [];
        [$camp, $seq, $v] = $ids['liens'];
        foreach ([1, 2, 3] as $i) {
            $procs["liens{$i}"] = $lance('lier', $camp, $seq, $v);
        }
        [$camp2, $seq2, $v2] = $ids['course'];
        $procs['course-lier'] = $lance('lier', $camp2, $seq2, $v2);
        $procs['course-reculer'] = $lance('a_valider', $camp2, $seq2, $v2);
        $codes = [];
        foreach ($procs as $nom => [$p, $tubes]) {
            $sortie = stream_get_contents($tubes[1]) . stream_get_contents($tubes[2]);
            $codes[$nom] = proc_close($p);
            expect($codes[$nom])->toBeIn([0, 3], $nom . ' : ' . $sortie);
        }
        expect(count(array_filter([$codes['liens1'], $codes['liens2'], $codes['liens3']], fn ($c) => $c === 0)))->toBe(1)
            ->and($proprio->table('envoi_campagnes')->where('id', $camp)->value('sequence_id'))->toBe($seq);
        expect(count(array_filter([$codes['course-lier'], $codes['course-reculer']], fn ($c) => $c === 0)))->toBe(1);
        $etat = $proprio->table('envoi_campagnes')->where('id', $camp2)->first();
        // jamais « reliée ET revenue à a_valider »
        expect($etat->sequence_id === null && $etat->statut === 'programmee')->toBeFalse();
        expect($etat->sequence_id !== null ? $etat->statut : 'a_valider')->toBe($etat->sequence_id !== null ? 'programmee' : 'a_valider');
    } finally {
        $proprio->statement("SET session_replication_role = 'replica'");
        $proprio->table('envoi_sequences_gestes')->where('workspace_id', $ws)->delete();
        $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->update(['sequence_id' => null]);
        $proprio->table('envoi_sequence_etapes')->where('workspace_id', $ws)->delete();
        $proprio->table('envoi_sequences')->where('workspace_id', $ws)->delete();
        $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->delete();
        $proprio->table('workspaces')->where('id', $ws)->delete();
        $proprio->statement("SET session_replication_role = 'origin'");
    }
});

// ── envoi_campagnes_materialisations ─────────────────────────────────────────

function slcMat(string $ws, array $plus = []): array
{
    [$c, $v, $s] = slcLiee($ws);
    slcAvancer($c, $v, 'en_cours');

    return [$c, $s, ['campagne_id' => $c, 'workspace_id' => $ws, 'sequence_id' => null] + $plus];
}

test('X1-L1 matérialisation : valeurs par défaut, RLS et clés étrangères composites', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        $autre = slcEspace();
        [$c, $s, $ligne] = slcMat($ws);
        [$cEtr] = slcCampagne($autre);
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes_materialisations')->insert($ligne)))->toBeNull($mode);
        slcSous($mode, $ws, fn () => DB::table('envoi_campagnes_materialisations')->insert($ligne));
        $l = DB::table('envoi_campagnes_materialisations')->where('campagne_id', $c)->first();
        expect($l->etat)->toBe('en_cours')->and((int) $l->curseur)->toBe(0)->and(json_decode($l->compteurs, true))->toBe([])->and($l->motif)->toBeNull()->and($l->terminee_le)->toBeNull();
        // une seule matérialisation par campagne
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes_materialisations')->insert($ligne)))->toBe('23505', $mode);
        // clés composites : campagne d'un autre espace, séquence d'un autre espace ou inexistante
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => $cEtr] + $ligne)))->toBeIn(['23503', '42501'], "{$mode} campagne étrangère");
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => (string) Str::uuid(), 'sequence_id' => (string) Str::uuid()] + $ligne)))->toBe('23503', "{$mode} inexistantes");
        expect(slcCode($mode, $ws, fn () => DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => (string) Str::uuid(), 'sequence_id' => $s] + $ligne)))->toBe('23503', "{$mode} campagne inexistante");
    }
    // RLS : l'autre espace ne voit rien et n'écrit pas
    $a = slcEspace();
    $b = slcEspace();
    [, , $ligneA] = slcMat($a);
    DB::table('envoi_campagnes_materialisations')->insert($ligneA);
    expect(slcSous('app', $a, fn () => DB::table('envoi_campagnes_materialisations')->count()))->toBe(1);
    DB::statement('RESET ROLE');
    expect(slcSous('app', $b, fn () => DB::table('envoi_campagnes_materialisations')->count()))->toBe(0);
    DB::statement('RESET ROLE');
    expect(slcCode('app', $b, fn () => DB::table('envoi_campagnes_materialisations')->insert($ligneA)))->toBe('42501');
});

test('X1-L1 matérialisation : bornes (CHECK) sur etat, curseur, compteurs, motif', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c, , $ligne] = slcMat($ws);
        $ins = fn (array $plus) => fn () => DB::table('envoi_campagnes_materialisations')->insert($plus + $ligne);
        $gros = json_encode(array_fill_keys(array_map(fn ($i) => 'cle' . $i, range(1, 300)), 1000000));
        $cas = [
            'etat inconnu' => ['etat' => 'ouverte'], 'curseur négatif' => ['curseur' => -1], 'compteurs liste' => ['compteurs' => '[1]'],
            'compteur texte' => ['compteurs' => '{"a":"x"}'], 'compteur objet' => ['compteurs' => '{"a":{"b":1}}'], 'compteur nul' => ['compteurs' => '{"a":null}'],
            'compteurs > 2048' => ['compteurs' => $gros], 'motif hors liste' => ['motif' => 'au_hasard'],
            'bloquee sans motif' => ['etat' => 'bloquee'],
        ];
        foreach ($cas as $nom => $plus) {
            expect(slcCode($mode, $ws, $ins($plus)))->toBe('23514', "{$mode} {$nom}");
        }
        foreach (['jours_non_applicables', 'objet_b_non_applicable', 'sequence_incoherente', 'cible_introuvable', 'etapes_invalides', 'espace_non_emetteur'] as $motif) {
            expect(slcCode($mode, $ws, $ins(['etat' => 'bloquee', 'motif' => $motif, 'compteurs' => '{"vus":12,"ecartes":3.5}', 'curseur' => 9])))->toBeNull("{$mode} {$motif}");
        }
    }
});

test('X1-L1 matérialisation : transitions, curseur qui ne décroît pas, colonnes figées, sequence_id posé une fois', function () {
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c, $s, $ligne] = slcMat($ws);
        DB::table('envoi_campagnes_materialisations')->insert($ligne);
        $maj = fn (array $m) => fn () => DB::table('envoi_campagnes_materialisations')->where('campagne_id', $c)->update($m);
        // sequence_id : NULL -> valeur seulement
        expect(slcCode($mode, $ws, $maj(['sequence_id' => (string) Str::uuid()])))->toBe('23503', "{$mode} séquence inexistante");
        slcSous($mode, $ws, $maj(['sequence_id' => $s, 'maj_le' => now()]));
        DB::table('envoi_campagnes_materialisations')->where('campagne_id', $c)->value('sequence_id');
        $autreSeq = LienCampagneSequence::creerSequence(DB::connection(), $ws, null, 1);
        expect(slcCode($mode, $ws, $maj(['sequence_id' => null])))->toBe('P0001', "{$mode} retrait")
            ->and(slcCode($mode, $ws, $maj(['sequence_id' => $autreSeq])))->toBe('P0001', "{$mode} changement");
        // curseur : monte, jamais ne descend
        slcSous($mode, $ws, $maj(['curseur' => 50]));
        expect(slcCode($mode, $ws, $maj(['curseur' => 49])))->toBe('P0001', "{$mode} décroît")
            ->and(slcCode($mode, $ws, $maj(['curseur' => 50, 'compteurs' => '{"a":1}'])))->toBeNull($mode)
            ->and(slcCode($mode, $ws, $maj(['curseur' => 51])))->toBeNull($mode);
        // colonnes figées : campagne_id, workspace_id, cree_le (le rôle applicatif n'a même pas le droit : 42501)
        $fige = $mode === 'app' ? '42501' : 'P0001';
        expect(slcCode($mode, $ws, $maj(['campagne_id' => (string) Str::uuid()])))->toBeIn([$fige, '23503'], "{$mode} campagne_id")
            ->and(slcCode($mode, $ws, $maj(['workspace_id' => slcEspace()])))->toBeIn([$fige, '23503', '42501'], "{$mode} workspace_id")
            ->and(slcCode($mode, $ws, $maj(['cree_le' => now()->subDay()])))->toBe($fige, "{$mode} cree_le");
        // terminee : terminale ; bloquee : terminale
        slcSous($mode, $ws, $maj(['etat' => 'terminee', 'terminee_le' => now()]));
        foreach (['en_cours', 'bloquee'] as $vers) {
            expect(slcCode($mode, $ws, $maj(['etat' => $vers, 'motif' => 'cible_introuvable'])))->toBe('P0001', "{$mode} terminee -> {$vers}");
        }
        expect(slcCode($mode, $ws, $maj(['curseur' => 60])))->toBeNull("{$mode} terminee : le curseur reste réglable sans changer d'état");
    }
    foreach (SLC_MODES as $mode) {
        $ws = slcEspace();
        [$c, , $ligne] = slcMat($ws);
        DB::table('envoi_campagnes_materialisations')->insert($ligne);
        $maj = fn (array $m) => fn () => DB::table('envoi_campagnes_materialisations')->where('campagne_id', $c)->update($m);
        slcSous($mode, $ws, $maj(['etat' => 'bloquee', 'motif' => 'etapes_invalides']));
        foreach (['en_cours', 'terminee'] as $vers) {
            expect(slcCode($mode, $ws, $maj(['etat' => $vers])))->toBe('P0001', "{$mode} bloquee -> {$vers}");
        }
        expect(slcCode($mode, $ws, $maj(['etat' => 'en_cours', 'motif' => null])))->toBe('P0001', "{$mode} bloquee -> en_cours sans motif");
    }
});

test('X1-L1 matérialisation : DELETE et TRUNCATE refusés (déclencheur pour le propriétaire, droits pour le rôle applicatif)', function () {
    $ws = slcEspace();
    [$c, , $ligne] = slcMat($ws);
    DB::table('envoi_campagnes_materialisations')->insert($ligne);
    expect(slcCode('proprio', $ws, fn () => DB::table('envoi_campagnes_materialisations')->where('campagne_id', $c)->delete()))->toBe('P0001')
        ->and(slcCode('proprio', $ws, fn () => DB::statement('TRUNCATE envoi_campagnes_materialisations')))->toBe('P0001')
        ->and(slcCode('app', $ws, fn () => DB::table('envoi_campagnes_materialisations')->where('campagne_id', $c)->delete()))->toBe('42501')
        ->and(slcCode('app', $ws, fn () => DB::statement('TRUNCATE envoi_campagnes_materialisations')))->toBe('42501');
    // même un propriétaire qui suspendrait les déclencheurs ne tue pas la table : la ligne existe toujours (la suite de tests le prouve en lisant)
    expect(DB::table('envoi_campagnes_materialisations')->where('campagne_id', $c)->count())->toBe(1);
    $declencheurs = collect(DB::select("SELECT tgname FROM pg_trigger WHERE tgrelid = 'envoi_campagnes_materialisations'::regclass AND NOT tgisinternal"))->pluck('tgname')->all();
    expect($declencheurs)->toContain('envoi_campagnes_materialisations_garde')->and(count($declencheurs))->toBeGreaterThanOrEqual(3);
});

test('X1-L1 droits : UPDATE(sequence_id) sur envoi_campagnes mais jamais INSERT(sequence_id) ; droits exacts sur la matérialisation', function () {
    $r = (string) config('database.connections.pgsql_app.username');
    $a = fn (string $t, string $col, string $p) => (bool) DB::selectOne('SELECT has_column_privilege(?, ?, ?, ?) AS ok', [$r, $t, $col, $p])->ok;
    expect($a('envoi_campagnes', 'sequence_id', 'UPDATE'))->toBeTrue()
        ->and($a('envoi_campagnes', 'sequence_id', 'INSERT'))->toBeFalse();
    foreach (['sequence_id', 'etat', 'curseur', 'compteurs', 'motif', 'maj_le', 'terminee_le'] as $col) {
        expect($a('envoi_campagnes_materialisations', $col, 'UPDATE'))->toBeTrue($col);
    }
    foreach (['campagne_id', 'workspace_id', 'cree_le'] as $col) {
        expect($a('envoi_campagnes_materialisations', $col, 'UPDATE'))->toBeFalse($col);
    }
    $t = fn (string $p) => (bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$r, 'envoi_campagnes_materialisations', $p])->ok;
    expect($t('SELECT'))->toBeTrue()->and($t('INSERT'))->toBeTrue()->and($t('DELETE'))->toBeFalse()->and($t('TRUNCATE'))->toBeFalse();
    $rls = DB::selectOne("SELECT relrowsecurity AS e, relforcerowsecurity AS f FROM pg_class WHERE oid = 'envoi_campagnes_materialisations'::regclass");
    expect($rls->e)->toBeTrue()->and($rls->f)->toBeTrue();
});

test('X1-L1 structure : clés étrangères validées sans action de suppression, index unique partiel, fonctions à search_path fixe', function () {
    $fk = fn (string $nom) => DB::selectOne('SELECT confdeltype AS d, convalidated AS v, confrelid::regclass::text AS r FROM pg_constraint WHERE conname = ?', [$nom]);
    foreach (['envoi_sequences_campagne_fk' => 'envoi_campagnes', 'envoi_campagnes_sequence_fk' => 'envoi_sequences'] as $nom => $cible) {
        $l = $fk($nom);
        expect($l)->not->toBeNull($nom)->and($l->d)->toBe('a', "{$nom} : NO ACTION")->and($l->v)->toBeTrue($nom)->and($l->r)->toBe($cible);
    }
    $actions = DB::select("SELECT confdeltype AS d, confupdtype AS u FROM pg_constraint WHERE contype = 'f' AND conrelid IN ('envoi_campagnes_materialisations'::regclass, 'envoi_campagnes'::regclass, 'envoi_sequences'::regclass)");
    expect(collect($actions)->pluck('d')->unique()->all())->toBe(['a'])->and(collect($actions)->pluck('u')->unique()->all())->toBe(['a']);
    $idx = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'envoi_sequences_campagne_unique'");
    expect($idx->indexdef)->toContain('UNIQUE')->toContain('workspace_id, campagne_id')->toContain('campagne_id IS NOT NULL');
    $valide = DB::selectOne("SELECT i.indisvalid AS v, pg_get_indexdef(i.indexrelid) AS d FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'idx_envoi_inscriptions_contact_actif'");
    expect($valide)->not->toBeNull()->and($valide->v)->toBeTrue()->and($valide->d)->toContain('workspace_id, contact_id')->toContain("'active'");
    foreach (['envoi_sequences_garde_campagne_insert', 'envoi_sequences_garde_campagne', 'envoi_sequences_nee_en_pause', 'envoi_sequence_etapes_figees', 'envoi_campagnes_garde',
        'envoi_campagnes_pause_sequence', 'envoi_sequences_gestes_reprise_campagne', 'envoi_campagnes_materialisations_garde', 'envoi_campagnes_materialisations_sans_suppression'] as $f) {
        $p = DB::selectOne('SELECT proconfig, prosecdef FROM pg_proc WHERE proname = ?', [$f]);
        expect($p)->not->toBeNull($f)->and((string) $p->proconfig)->toContain('search_path=pg_catalog, public')->and($p->prosecdef)->toBeFalse();
    }
});

test('X1-L1 migration du lien : des sequence_id orphelins la font ÉCHOUER avec leur nombre, sans rien modifier', function () {
    $ws = slcEspace();
    [$c, $v] = slcCampagne($ws);
    $migration = require database_path('migrations/2026_12_04_000030_envoi_campagnes_lien_sequence.php');
    $message = null;
    $avant = null;
    try {
        DB::transaction(function () use ($c, $v, $migration, &$avant) {
            DB::statement('ALTER TABLE envoi_campagnes DROP CONSTRAINT envoi_campagnes_sequence_fk');   // annulé avec la transaction
            DB::statement("SET LOCAL session_replication_role = 'replica'");
            DB::table('envoi_campagnes')->where('id', $c)->update(['sequence_id' => (string) Str::uuid(), 'version' => $v + 1]);
            DB::statement("SET LOCAL session_replication_role = 'origin'");
            $avant = DB::table('envoi_campagnes')->where('id', $c)->value('sequence_id');
            $migration->up();
        });
    } catch (Throwable $e) {
        $message = $e;
    }
    expect($message)->not->toBeNull()->and($message)->not->toBeInstanceOf(QueryException::class)
        ->and($message->getMessage())->toContain('1')->and($message->getMessage())->toContain('orphelin')->and($message->getMessage())->not->toContain('SQLSTATE');
    expect($avant)->not->toBeNull();   // la colonne n'a pas été remise à NULL par la migration
    expect(DB::table('envoi_campagnes')->where('id', $c)->value('sequence_id'))->toBeNull();   // transaction annulée : l'état d'avant
});

test('X1-L1 les quatre migrations sont additives : ni DROP de colonne ou de table, ni DELETE, ni UPDATE de lignes, ni SET NULL, ni CASCADE', function () {
    $fichiers = glob(database_path('migrations/2026_12_04_0000[1-4]0_*.php')) ?: [];
    expect($fichiers)->toHaveCount(4);
    foreach ($fichiers as $f) {
        $src = (string) file_get_contents($f);
        $code = preg_replace('~//[^\n]*|/\*.*?\*/|--[^\n]*~s', '', $src);
        foreach (['DROP TABLE', 'DROP COLUMN', 'DELETE FROM', 'SET NULL', 'CASCADE', 'ENVOI_REEL_ACTIF', 'reel_actif'] as $interdit) {
            expect(stripos($code, $interdit))->toBeFalse(basename($f) . ' contient ' . $interdit);
        }
        expect(preg_match('~^\s*TRUNCATE\s+(TABLE\s+)?[a-z_]+~im', $code))->toBe(0, basename($f) . ' TRUNCATE');
        expect(preg_match('~\bUPDATE\s+(public\.)?(envoi_|contacts|persons)\w*\s+SET\b~i', $code))->toBe(0, basename($f));
        expect(preg_match('~public function down\(\): void \{\}~', $src))->toBe(1, basename($f) . ' : down() inerte');
    }
    $quatre = (string) file_get_contents(database_path('migrations/2026_12_04_000040_envoi_inscriptions_contact_actif_index.php'));
    expect($quatre)->toContain('$withinTransaction = false')->toContain('DROP INDEX CONCURRENTLY')->toContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_envoi_inscriptions_contact_actif')
        ->and(preg_match('~DROP INDEX(?! CONCURRENTLY)~', $quatre))->toBe(0);
});

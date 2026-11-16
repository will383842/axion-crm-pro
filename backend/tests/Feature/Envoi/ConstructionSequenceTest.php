<?php

use App\Envoi\Campagnes\ConstructionRefusee;
use App\Envoi\Campagnes\ConstructionSequence;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Creation\ErreurCreation;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Creation\ValidationCampagne;
use App\Envoi\Exceptions\CampagneRefusee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

/*
 * Lot X1-L3 — construction de la séquence d'une campagne « programmee » et liaison (DepotCampagnes::lierSequence).
 * Chaque test de comportement est joué SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE. Données fictives seulement.
 * Aucune inscription, aucune matérialisation, aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const CNS_MODES = ['app', 'proprio'];
const CNS_OUVRES = ['lun', 'mar', 'mer', 'jeu', 'ven'];

function cnsEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Cns', 'slug' => 'cns-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Message type créé puis validé par le service ; rend son identifiant. */
function cnsMessage(string $ws, string $nom = 'Message fictif'): string
{
    $s = app(ServiceMessagesTypes::class);
    $m = $s->creer($ws, $nom, 'Objet ' . $nom, 'Corps ' . $nom, 'responsable');
    $s->valider($ws, $m['id'], 1);

    return $m['id'];
}

/**
 * Séquence de saisie : une étape par délai, chacune avec son message validé.
 *
 * @param  list<int>  $delais
 * @return list<array<string, mixed>>
 */
function cnsEtapes(string $ws, array $delais = [0, 3]): array
{
    $out = [];
    foreach ($delais as $i => $d) {
        $out[] = ['etape' => $i + 1, 'message_id' => cnsMessage($ws, 'Etape ' . ($i + 1)), 'delai_jours' => $d];
    }

    return $out;
}

/**
 * Campagne « programmee » SANS lien, par le chemin légal : brouillon, a_valider (dépôt), programmee (propriétaire, version + 1),
 * journal « a_valider>programmee » daté d'il y a $ilYa (null : aucune ligne de journal).
 *
 * @param  array<string, mixed>  $contenu  clés de saisie.contenu qui remplacent celles par défaut
 * @return array{id: string, v: int, sequence: list<array<string, mixed>>}
 */
function cnsProgrammee(string $ws, array $contenu = [], ?int $nb = null, ?string $ilYa = '10 minutes', ?array $sequence = null): array
{
    $sequence ??= cnsEtapes($ws);
    $contenu = $contenu + ['sequence' => $sequence];
    $depot = app(DepotCampagnes::class);
    $id = $depot->creer(
        $ws,
        'Campagne fictive',
        ['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive'],
        [(string) Str::uuid()],
        $nb ?? count($contenu['sequence']),
        ['contenu' => $contenu, 'etapes_faites' => []],
        'responsable',
    );
    $depot->transitionner($ws, $id, 'a_valider', 1, 'responsable', 'test', 'cle-cns-' . Str::random(12));
    DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'programmee', 'version' => 3]);
    if ($ilYa !== null) {
        DB::table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $id, 'transition' => 'a_valider>programmee', 'auteur' => 'responsable',
            'motif' => 'test', 'cle_idempotence' => 'cle-j-' . Str::random(12), 'cree_le' => DB::raw("now() - interval '{$ilYa}'")]);
    }

    return ['id' => $id, 'v' => 3, 'sequence' => $sequence];
}

/** Exécute sous le mode : « app » = rôle applicatif non propriétaire sous RLS, « proprio » = propriétaire. */
function cnsSous(string $mode, Closure $f): mixed
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

function cnsConstruire(string $mode, string $ws, string $campagne): mixed
{
    return cnsSous($mode, fn () => app(ConstructionSequence::class)->construire($ws, $campagne));
}

/** Le motif du refus de $f (null si aucun refus). */
function cnsMotif(Closure $f): ?string
{
    try {
        $f();
    } catch (ConstructionRefusee $e) {
        return $e->motif;
    }

    return null;
}

/** @return array<string, int> */
function cnsComptes(string $ws, string $campagne): array
{
    return [
        'sequences' => DB::table('envoi_sequences')->where('workspace_id', $ws)->count(),
        'etapes' => DB::table('envoi_sequence_etapes')->where('workspace_id', $ws)->count(),
        'gestes' => DB::table('envoi_sequences_gestes')->where('workspace_id', $ws)->count(),
        'inscriptions' => DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count(),
        'materialisations' => DB::table('envoi_campagnes_materialisations')->where('workspace_id', $ws)->count(),
        'version' => (int) DB::table('envoi_campagnes')->where('id', $campagne)->value('version'),
    ];
}

/** Hors de la transaction de RefreshDatabase (niveau 0) pour $f, puis la transaction est rétablie. */
function cnsHorsTransaction(Closure $f): mixed
{
    $niveau = DB::transactionLevel();
    for ($i = 0; $i < $niveau; $i++) {
        DB::rollBack();
    }
    try {
        return $f();
    } finally {
        for ($i = 0; $i < $niveau; $i++) {
            DB::beginTransaction();
        }
    }
}

test('X1-L3 cas nominal : séquence, étapes de la version validée, lien, version + 1, un geste de naissance, rien d\'autre (rôle applicatif ET propriétaire)', function () {
    foreach (CNS_MODES as $mode) {
        $ws = cnsEspace();
        $p = cnsProgrammee($ws, ['jours' => CNS_OUVRES, 'heures' => ['de' => '10:00', 'a' => '16:00']]);
        $r = cnsConstruire($mode, $ws, $p['id']);
        expect($r->dejaFaite)->toBeFalse($mode)->and($r->versionCampagne)->toBe($p['v'] + 1);
        $s = DB::table('envoi_sequences')->where('id', $r->sequenceId)->first();
        expect($s->campagne_id)->toBe($p['id'])->and($s->nom)->toBe('Campagne fictive')->and((int) $s->fenetre_debut)->toBe(10)->and((int) $s->fenetre_fin)->toBe(16);
        $etapes = DB::table('envoi_sequence_etapes')->where('sequence_id', $r->sequenceId)->orderBy('rang')->get();
        expect($etapes)->toHaveCount(2)
            ->and([(int) $etapes[0]->rang, (int) $etapes[0]->delai_jours_ouvres, $etapes[0]->sujet, $etapes[0]->corps])->toBe([1, 0, 'Objet Etape 1', 'Corps Etape 1'])
            ->and([(int) $etapes[1]->rang, (int) $etapes[1]->delai_jours_ouvres, $etapes[1]->sujet, $etapes[1]->corps])->toBe([2, 3, 'Objet Etape 2', 'Corps Etape 2']);
        $c = DB::table('envoi_campagnes')->where('id', $p['id'])->first();
        expect($c->sequence_id)->toBe($r->sequenceId)->and((int) $c->version)->toBe($p['v'] + 1)->and($c->statut)->toBe('programmee');
        $gestes = DB::table('envoi_sequences_gestes')->where('sequence_id', $r->sequenceId)->get();
        expect($gestes)->toHaveCount(1)->and([$gestes[0]->geste, $gestes[0]->auteur])->toBe(['pause', 'campagne:naissance']);
        $n = cnsComptes($ws, $p['id']);
        expect($n['inscriptions'])->toBe(0)->and($n['materialisations'])->toBe(0);
    }
});

test('X1-L3 jours et heures absents : lundi-vendredi, 9 h - 17 h (défauts de la table)', function () {
    $ws = cnsEspace();
    $p = cnsProgrammee($ws);
    $r = cnsConstruire('proprio', $ws, $p['id']);
    $s = DB::table('envoi_sequences')->where('id', $r->sequenceId)->first();
    expect([(int) $s->fenetre_debut, (int) $s->fenetre_fin])->toBe([9, 17]);
});

test('X1-L3 idempotence : un second appel rend dejaFaite et ne change aucun compte (rôle applicatif ET propriétaire)', function () {
    foreach (CNS_MODES as $mode) {
        $ws = cnsEspace();
        $p = cnsProgrammee($ws);
        $un = cnsConstruire($mode, $ws, $p['id']);
        $avant = cnsComptes($ws, $p['id']);
        $deux = cnsConstruire($mode, $ws, $p['id']);
        expect($deux->dejaFaite)->toBeTrue($mode)->and($deux->sequenceId)->toBe($un->sequenceId)->and($deux->versionCampagne)->toBe($un->versionCampagne)
            ->and(cnsComptes($ws, $p['id']))->toBe($avant);
    }
});

test('X1-L3 E8 : jours hors lundi-vendredi, objet_b, ou les deux (jours l\'emporte) : refus sans aucune écriture', function () {
    foreach (CNS_MODES as $mode) {
        $ws = cnsEspace();
        $avecB = fn (array $e) => array_replace_recursive($e, [1 => ['objet_b' => 'Autre objet']]);
        $cas = [
            'jours_non_applicables' => [['jours' => [...CNS_OUVRES, 'sam']], ['jours' => ['lun', 'mar']], ['jours' => []], ['jours' => ['lun', 'mar', 'mer', 'jeu', 'ven', 'ven']]],
            'objet_b_non_applicable' => [['sequence' => $avecB(cnsEtapes($ws))], ['sequence' => $avecB(cnsEtapes($ws)), 'jours' => CNS_OUVRES]],
        ];
        $cas['jours_non_applicables'][] = ['jours' => ['lun', 'mar'], 'sequence' => $avecB(cnsEtapes($ws))];   // les deux : jours l'emporte
        foreach ($cas as $motif => $liste) {
            foreach ($liste as $contenu) {
                $p = cnsProgrammee($ws, $contenu);
                $avant = cnsComptes($ws, $p['id']);
                try {
                    cnsConstruire($mode, $ws, $p['id']);
                    $refus = null;
                } catch (ConstructionRefusee $e) {
                    $refus = $e;
                }
                expect($refus?->motif)->toBe($motif, $mode . ' ' . json_encode($contenu))->and($refus->motifMaterialisation())->toBe($motif)->and($refus->reessayable)->toBeFalse()
                    ->and(cnsComptes($ws, $p['id']))->toBe($avant)->and(DB::table('envoi_campagnes')->where('id', $p['id'])->value('sequence_id'))->toBeNull();
            }
        }
        expect(DB::table('envoi_sequences')->where('workspace_id', $ws)->count())->toBe(0);
    }
});

test('X1-L3 etapes_invalides : message non validé, archivé, d\'un autre espace, délai, heures, contenu refusé, forme de la séquence', function () {
    foreach (CNS_MODES as $mode) {
        $ws = cnsEspace();
        $autre = cnsEspace();
        $svc = app(ServiceMessagesTypes::class);
        $cas = [];
        $e = cnsEtapes($ws);
        $svc->enregistrer($ws, $e[1]['message_id'], 'Objet neuf', 'Corps neuf', 'responsable');   // retombe en brouillon
        $cas['brouillon'] = ['sequence' => $e];
        $e = cnsEtapes($ws);
        $svc->archiver($ws, $e[0]['message_id']);
        $cas['archive'] = ['sequence' => $e];
        $e = cnsEtapes($ws);
        $e[1]['message_id'] = cnsMessage($autre);
        $cas['autre espace'] = ['sequence' => $e];
        $e = cnsEtapes($ws);
        $e[1]['delai_jours'] = 61;
        $cas['delai 61'] = ['sequence' => $e];
        $e = cnsEtapes($ws);
        $e[1]['delai_jours'] = -1;
        $cas['delai -1'] = ['sequence' => $e];
        $cas['heures 08:30'] = ['sequence' => cnsEtapes($ws), 'heures' => ['de' => '08:30', 'a' => '17:00']];
        $cas['heures inversées'] = ['sequence' => cnsEtapes($ws), 'heures' => ['de' => '17:00', 'a' => '09:00']];
        $cas['heures 25'] = ['sequence' => cnsEtapes($ws), 'heures' => ['de' => '09:00', 'a' => '25:00']];
        $e = cnsEtapes($ws);
        $e[1]['message_id'] = 'pas-un-uuid';
        $cas['uuid'] = ['sequence' => $e];
        $e = cnsEtapes($ws);
        $e[1]['etape'] = 3;
        $cas['numérotation'] = ['sequence' => $e];
        $cas['quatre étapes'] = ['sequence' => cnsEtapes($ws, [0, 1, 2, 3])];
        $cas['vide'] = ['sequence' => []];
        $e = cnsEtapes($ws);
        $m = $e[0]['message_id'];
        DB::table('envoi_modeles')->where('id', $m)->update(['statut' => 'a_relire', 'version_validee' => null]);
        DB::table('envoi_modele_versions')->insert(['workspace_id' => $ws, 'modele_id' => $m, 'numero' => 2, 'objet' => 'Objet', 'corps' => 'Bonjour <script>alert(1)</script>', 'auteur' => 'responsable']);
        DB::table('envoi_modeles')->where('id', $m)->update(['statut' => 'valide', 'version_validee' => 2]);
        $cas['contenu refusé'] = ['sequence' => $e];
        foreach ($cas as $nom => $contenu) {
            $p = cnsProgrammee($ws, $contenu, $nom === 'quatre étapes' || $nom === 'vide' ? 2 : null);
            $avant = cnsComptes($ws, $p['id']);
            expect(cnsMotif(fn () => cnsConstruire($mode, $ws, $p['id'])))->toBe('etapes_invalides', "{$mode} : {$nom}" . json_encode($contenu['heures'] ?? null))
                ->and(cnsComptes($ws, $p['id']))->toBe($avant);
        }
        $p = cnsProgrammee($ws, [], 3);   // 2 étapes saisies, 3 annoncées
        expect(cnsMotif(fn () => cnsConstruire($mode, $ws, $p['id'])))->toBe('sequence_incoherente', $mode);
        expect(DB::table('envoi_sequences')->where('workspace_id', $ws)->count())->toBe(0);
    }
});

test('X1-L3 statut : brouillon et a_valider refusés sans écriture ; une campagne déjà reliée (en_cours) rend dejaFaite', function () {
    foreach (CNS_MODES as $mode) {
        $ws = cnsEspace();
        foreach (['brouillon', 'a_valider'] as $statut) {
            if ($statut === 'brouillon') {
                $id = app(DepotCampagnes::class)->creer($ws, 'Brouillon', ['type' => 'liste', 'reference' => 'r', 'libelle' => 'l'], [], 2, ['contenu' => ['sequence' => cnsEtapes($ws)]], 'responsable');
                $p = ['id' => $id, 'v' => 1];
            } else {
                $p = cnsProgrammee($ws);
                DB::table('envoi_campagnes')->where('id', $p['id'])->update(['statut' => 'a_valider', 'version' => $p['v'] + 1]);
            }
            $avant = cnsComptes($ws, $p['id']);
            $r = cnsMotif(fn () => cnsConstruire($mode, $ws, $p['id']));
            expect($r)->toBe('statut_non_programmee', "{$mode} {$statut}")->and(cnsComptes($ws, $p['id']))->toBe($avant);
        }
        // Écart avec le cahier : une campagne en_cours a TOUJOURS un lien ; l'étape 4 (reprise par lien) précède le contrôle du statut.
        $p = cnsProgrammee($ws);
        $r = cnsConstruire($mode, $ws, $p['id']);
        DB::table('envoi_campagnes')->where('id', $p['id'])->update(['statut' => 'en_cours', 'version' => $r->versionCampagne + 1]);
        $avant = cnsComptes($ws, $p['id']);
        expect(cnsConstruire($mode, $ws, $p['id'])->dejaFaite)->toBeTrue($mode)->and(cnsComptes($ws, $p['id']))->toBe($avant);
        expect(cnsMotif(fn () => cnsConstruire($mode, $ws, (string) Str::uuid())))->toBe('introuvable', $mode);
    }
});

test('X1-L3 fenêtre d\'annulation : journal à l\'instant (réessayable), à -10 minutes (succès), absent (validation_introuvable)', function () {
    foreach (CNS_MODES as $mode) {
        $ws = cnsEspace();
        $p = cnsProgrammee($ws, [], null, '0 seconds');
        try {
            cnsConstruire($mode, $ws, $p['id']);
            $e = null;
        } catch (ConstructionRefusee $e) {
        }
        expect($e?->motif)->toBe('fenetre_annulation_ouverte', $mode)->and($e->reessayable)->toBeTrue()->and($e->motifMaterialisation())->toBeNull()
            ->and(DB::table('envoi_sequences')->where('workspace_id', $ws)->count())->toBe(0);
        $p = cnsProgrammee($ws, [], null, '5 minutes 30 seconds');   // 5 min + marge d'une minute non écoulées
        expect(cnsMotif(fn () => cnsConstruire($mode, $ws, $p['id'])))->toBe('fenetre_annulation_ouverte', $mode);
        $p = cnsProgrammee($ws, [], null, '10 minutes');
        expect(cnsConstruire($mode, $ws, $p['id'])->dejaFaite)->toBeFalse($mode);
        $p = cnsProgrammee($ws, [], null, null);
        try {
            cnsConstruire($mode, $ws, $p['id']);
            $e = null;
        } catch (ConstructionRefusee $e) {
        }
        expect($e?->motif)->toBe('validation_introuvable')->and($e->reessayable)->toBeFalse();
    }
});

test('X1-L3 reprise : une séquence déjà née de la campagne et identique au plan est reliée ; différente : sequence_incoherente, rien ne change', function () {
    foreach (CNS_MODES as $mode) {
        $ws = cnsEspace();
        $p = cnsProgrammee($ws, ['jours' => CNS_OUVRES]);
        $seq = LienCampagneSequence::creerSequence(DB::connection(), $ws, $p['id'], 2);
        foreach ([1 => 0, 2 => 3] as $rang => $delai) {
            DB::table('envoi_sequence_etapes')->where('sequence_id', $seq)->where('rang', $rang)
                ->update(['delai_jours_ouvres' => $delai, 'sujet' => 'Objet Etape ' . $rang, 'corps' => 'Corps Etape ' . $rang]);
        }
        $r = cnsConstruire($mode, $ws, $p['id']);
        expect($r->sequenceId)->toBe($seq)->and($r->dejaFaite)->toBeFalse()->and(DB::table('envoi_sequences')->where('workspace_id', $ws)->count())->toBe(1)
            ->and(DB::table('envoi_campagnes')->where('id', $p['id'])->value('sequence_id'))->toBe($seq);

        $q = cnsProgrammee($ws);
        $seq2 = LienCampagneSequence::creerSequence(DB::connection(), $ws, $q['id'], 2);   // étapes fictives : textes différents du plan
        $avant = cnsComptes($ws, $q['id']);
        expect(cnsMotif(fn () => cnsConstruire($mode, $ws, $q['id'])))->toBe('sequence_incoherente', $mode)->and(cnsComptes($ws, $q['id']))->toBe($avant)
            ->and(DB::table('envoi_campagnes')->where('id', $q['id'])->value('sequence_id'))->toBeNull();
        // fenêtre différente
        $q2 = cnsProgrammee($ws);
        $seq3 = LienCampagneSequence::creerSequence(DB::connection(), $ws, $q2['id'], 2, ['fenetre_debut' => 8]);
        foreach ([1 => 0, 2 => 3] as $rang => $delai) {
            DB::table('envoi_sequence_etapes')->where('sequence_id', $seq3)->where('rang', $rang)->update(['delai_jours_ouvres' => $delai, 'sujet' => 'Objet Etape ' . $rang, 'corps' => 'Corps Etape ' . $rang]);
        }
        expect(cnsMotif(fn () => cnsConstruire($mode, $ws, $q2['id'])))->toBe('sequence_incoherente', $mode . ' fenêtre');
    }
});

test('X1-L3 transitions : reliée, programmee→a_valider est refusée proprement et l\'annulation répond delai_annulation_depasse ; non reliée, elle revient', function () {
    foreach (CNS_MODES as $mode) {
        $ws = cnsEspace();
        $p = cnsProgrammee($ws);
        $r = cnsConstruire($mode, $ws, $p['id']);
        $depot = app(DepotCampagnes::class);
        try {
            $depot->transitionner($ws, $p['id'], 'a_valider', $r->versionCampagne, 'responsable', 'test', 'cle-t-' . Str::random(10));
            $e = null;
        } catch (CampagneRefusee $e) {
        }
        expect($e?->codeErreur)->toBe('transition_refusee', $mode);
        try {
            app(ValidationCampagne::class)->annuler($ws, 'responsable', $p['id'], 'cle-a-' . Str::random(10));
            $e = null;
        } catch (ErreurCreation $e) {
        }
        expect($e)->not->toBeNull($mode)->and($e->erreur)->toBe('delai_annulation_depasse');
        expect(DB::table('envoi_campagnes')->where('id', $p['id'])->value('statut'))->toBe('programmee');

        $q = cnsProgrammee($ws, [], null, '1 minute');
        expect($depot->transitionner($ws, $q['id'], 'a_valider', $q['v'], 'responsable', 'test', 'cle-u-' . Str::random(10)))->toBeTrue($mode)
            ->and(DB::table('envoi_campagnes')->where('id', $q['id'])->value('statut'))->toBe('a_valider');
    }
});

test('X1-L3 lierSequence : hors transaction LogicException ; version périmée ; second appel ; passe sous le rôle applicatif', function () {
    $ws = cnsEspace();
    $depot = app(DepotCampagnes::class);
    $hors = cnsHorsTransaction(function () use ($depot, $ws) {
        try {
            $depot->lierSequence($ws, (string) Str::uuid(), 1, (string) Str::uuid());
        } catch (LogicException $e) {
            return $e->getMessage();
        }

        return null;
    });
    expect($hors)->toContain('sousVerrou');
    foreach (CNS_MODES as $mode) {
        $ws = cnsEspace();
        $p = cnsProgrammee($ws);
        $seq = LienCampagneSequence::creerSequence(DB::connection(), $ws, $p['id'], 2);
        $lier = fn (int $v) => fn () => $depot->sousVerrou($ws, $p['id'], fn () => $depot->lierSequence($ws, $p['id'], $v, $seq));
        try {
            cnsSous($mode, $lier($p['v'] - 1));
            $e = null;
        } catch (CampagneRefusee $e) {
        }
        expect($e?->codeErreur)->toBe('version_perimee', $mode);
        expect(cnsSous($mode, $lier($p['v'])))->toBe($p['v'] + 1)
            ->and(DB::table('envoi_campagnes')->where('id', $p['id'])->value('sequence_id'))->toBe($seq);
        try {
            cnsSous($mode, $lier($p['v']));
            $e = null;
        } catch (CampagneRefusee $e) {
        }
        expect($e?->codeErreur)->toBe('version_perimee', $mode . ' second appel');
    }
});

test('X1-L3 relancer : deadlock rejoué, trois échecs = concurrence réessayable, P0001, autres codes, jamais de QueryException brute', function () {
    $erreur = function (string $code, string $msg = 'x'): QueryException {
        $pdo = new class($msg, $code) extends PDOException
        {
            public function __construct(string $m, string $c)
            {
                parent::__construct($m);
                $this->code = $c;
            }
        };

        return new QueryException('pgsql', 'SELECT 1', [], $pdo);
    };
    cnsHorsTransaction(function () use ($erreur) {
        $n = 0;
        $r = ConstructionSequence::relancer(function () use (&$n, $erreur) {
            if (++$n <= 2) {
                throw $erreur('40P01');
            }

            return 'ok';
        });
        expect($r)->toBe('ok')->and($n)->toBe(3);
        $n = 0;
        $e = null;
        try {
            ConstructionSequence::relancer(function () use (&$n, $erreur) {
                $n++;
                throw $erreur('40001');
            });
        } catch (ConstructionRefusee $e) {
        }
        expect($e?->motif)->toBe('concurrence')->and($e->reessayable)->toBeTrue()->and($n)->toBe(3);
        foreach (['55P03', '57014', '23505'] as $code) {
            $n = 0;
            $e = null;
            try {
                ConstructionSequence::relancer(function () use (&$n, $erreur, $code) {
                    $n++;
                    throw $erreur($code);
                });
            } catch (ConstructionRefusee $e) {
            }
            expect($e?->motif)->toBe('concurrence', $code)->and($e->reessayable)->toBeTrue()->and($n)->toBe(1);
        }
        $e = null;
        try {
            ConstructionSequence::relancer(fn () => throw $erreur('P0001'));
        } catch (ConstructionRefusee $e) {
        }
        expect($e?->motif)->toBe('sequence_incoherente')->and($e->reessayable)->toBeFalse();
        $e = null;
        $origine = $erreur('23514');
        try {
            ConstructionSequence::relancer(fn () => throw $origine);
        } catch (ConstructionRefusee $e) {
        }
        expect($e?->motif)->toBe('erreur_base')->and($e->reessayable)->toBeFalse()->and($e->getPrevious())->toBe($origine);
    });
    // Dans une transaction englobante : pas de relance, conversion directe.
    $n = 0;
    $e = null;
    try {
        ConstructionSequence::relancer(function () use (&$n, $erreur) {
            $n++;
            throw $erreur('40P01');
        });
    } catch (ConstructionRefusee $e) {
    }
    expect($e?->motif)->toBe('concurrence')->and($n)->toBe(1);
});

/** Lance un sous-processus ; rend [processus, tubes]. */
function cnsLance(string $ws, string $camp, string $geste, float $depart): array
{
    $p = proc_open([PHP_BINARY, base_path('tests/Support/construction-sequence-concurrente.php'), $ws, $camp, $geste, sprintf('%.4F', $depart)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);

    return [$p, $tubes];
}

test('X1-L3 concurrence en processus réels : trois construire = un 0 et deux 2 ; un construire contre un changement de date : jamais de demi-état', function () {
    $proprio = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-cn-' . substr($ws, 0, 6), 'name' => 'Concurrence construction', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $camps = [];
    try {
        $sequence = [];
        foreach ([1, 2] as $rang) {
            $m = (string) Str::uuid();
            $proprio->table('envoi_modeles')->insert(['id' => $m, 'workspace_id' => $ws, 'nom' => 'M' . $rang, 'cree_par' => 'r']);
            $proprio->table('envoi_modele_versions')->insert(['workspace_id' => $ws, 'modele_id' => $m, 'numero' => 1, 'objet' => 'Objet ' . $rang, 'corps' => 'Corps ' . $rang, 'auteur' => 'r']);
            $proprio->table('envoi_modeles')->where('id', $m)->update(['statut' => 'a_relire']);
            $proprio->table('envoi_modeles')->where('id', $m)->update(['statut' => 'valide', 'version_validee' => 1]);
            $sequence[] = ['etape' => $rang, 'message_id' => $m, 'delai_jours' => $rang - 1];
        }
        foreach (['trois', 'course'] as $cas) {
            $camp = (string) Str::uuid();
            $proprio->table('envoi_campagnes')->insert(['id' => $camp, 'workspace_id' => $ws, 'nom' => 'Campagne ' . $cas, 'cible' => json_encode(['type' => 'liste', 'reference' => 'r', 'libelle' => 'l']), 'nb_etapes' => 2,
                'saisie' => json_encode(['contenu' => ['sequence' => $sequence], 'etapes_faites' => []]), 'cree_par' => 'r']);
            foreach (['a_valider' => 2, 'programmee' => 3] as $statut => $v) {
                $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => $statut, 'version' => $v]);
            }
            $proprio->table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $camp, 'transition' => 'a_valider>programmee', 'auteur' => 'r', 'motif' => 'x',
                'cle_idempotence' => 'cle-cc-' . $cas . '-' . substr($ws, 0, 6), 'cree_le' => $proprio->raw("now() - interval '10 minutes'")]);
            $camps[$cas] = $camp;
        }
        $depart = microtime(true) + 4;
        $procs = [];
        foreach ([1, 2, 3] as $i) {
            $procs["trois{$i}"] = cnsLance($ws, $camps['trois'], 'construire', $depart);
        }
        $procs['course-construire'] = cnsLance($ws, $camps['course'], 'construire', $depart);
        $procs['course-date'] = cnsLance($ws, $camps['course'], 'date', $depart);
        $codes = [];
        $sorties = [];
        foreach ($procs as $nom => [$p, $tubes]) {
            $sorties[$nom] = stream_get_contents($tubes[1]);
            $err = stream_get_contents($tubes[2]);
            $codes[$nom] = proc_close($p);
            expect($codes[$nom])->toBeIn([0, 2, 3], $nom . ' : ' . $sorties[$nom] . $err);
        }
        $trois = [$codes['trois1'], $codes['trois2'], $codes['trois3']];
        sort($trois);
        expect($trois)->toBe([0, 2, 2])
            ->and($proprio->table('envoi_sequences')->where('campagne_id', $camps['trois'])->count())->toBe(1)
            ->and($proprio->table('envoi_sequences_gestes')->whereIn('sequence_id', $proprio->table('envoi_sequences')->where('campagne_id', $camps['trois'])->select('id'))->where('auteur', 'campagne:naissance')->count())->toBe(1);
        $etat = $proprio->table('envoi_campagnes')->where('id', $camps['course'])->first();
        $seqs = $proprio->table('envoi_sequences')->where('campagne_id', $camps['course'])->pluck('id')->all();
        expect($etat->sequence_id === null ? $seqs : [$etat->sequence_id])->toBe($seqs);   // séquence existante ⇒ lien posé
        expect($codes['course-construire'])->toBeIn([0, 3]);
        if ($codes['course-construire'] === 3) {
            expect($sorties['course-construire'])->toBeIn(['concurrence', 'version_perimee']);
        }
        if ($codes['course-date'] === 3) {
            expect($sorties['course-date'])->toBeIn(['concurrence', 'version_perimee']);
        }
    } finally {
        try {
            $proprio->statement("SET session_replication_role = 'replica'");
            foreach (['envoi_sequences_gestes', 'envoi_sequence_etapes', 'envoi_campagnes_journal'] as $t) {
                $proprio->table($t)->where('workspace_id', $ws)->delete();
            }
            $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->update(['sequence_id' => null]);
            $proprio->table('envoi_sequences')->where('workspace_id', $ws)->delete();
            $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->delete();
            $proprio->table('envoi_modeles')->where('workspace_id', $ws)->update(['version_validee' => null, 'statut' => 'brouillon']);
            $proprio->table('envoi_modele_versions')->where('workspace_id', $ws)->delete();
            $proprio->table('envoi_modeles')->where('workspace_id', $ws)->delete();
            $proprio->table('workspaces')->where('id', $ws)->delete();
        } finally {
            $proprio->statement("SET session_replication_role = 'origin'");
        }
    }
});

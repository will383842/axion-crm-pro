<?php

use App\Envoi\Campagnes\CompteursCampagne;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LienCampagneSequence;
use Tests\TestCase;

/*
 * Lot L6-A — compteurs réels d'une campagne (lecture seule), cahier L5-L6 § 6 (PR #649).
 * Chaque test est rejoué SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE + WorkspaceContext) ET SOUS LE PROPRIÉTAIRE. Données semées par le propriétaire
 * (transaction annulée par RefreshDatabase). Données fictives seulement (aucune adresse : empreintes fictives).
 */
uses(TestCase::class, RefreshDatabase::class);

const CPT_MODES = ['app', 'proprio'];
const CPT_SOURCE = __DIR__ . '/../../../app/Envoi/Campagnes/CompteursCampagne.php';

function cptEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Cpt', 'slug' => 'cpt-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Campagne reliée à une séquence de $nb étapes ; rend [campagne, séquence]. */
function cptCampagne(string $ws, int $nb = 3): array
{
    $c = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $c, 'workspace_id' => $ws, 'nom' => 'Campagne fictive', 'cible' => json_encode(['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive']),
        'boites' => '{' . (string) Str::uuid() . '}', 'nb_etapes' => $nb, 'saisie' => json_encode(['objet' => 'Bonjour']), 'cree_par' => 'responsable']);
    $v = 1;
    foreach (['a_valider', 'programmee'] as $s) {
        DB::table('envoi_campagnes')->where('id', $c)->update(['statut' => $s, 'version' => ++$v]);
    }
    $r = LienCampagneSequence::poser(DB::connection(), $ws, $c, $v, $nb);

    return [$c, $r['sequence']];
}

function cptN(): int
{
    static $n = 0;

    return ++$n;
}

function cptInscrire(string $ws, string $seq, string $statut = 'active', ?string $motif = null): string
{
    $id = (string) Str::uuid();
    $n = cptN();
    DB::table('envoi_inscriptions')->insert(['id' => $id, 'workspace_id' => $ws, 'sequence_id' => $seq, 'contact_id' => $n, 'entreprise_id' => $n,
        'empreinte_adresse' => str_pad(dechex($n), 64, 'a', STR_PAD_LEFT), 'statut' => $statut, 'motif_arret' => $motif, 'prochaine_echeance' => now(), 'version_figee' => '{}']);

    return $id;
}

function cptMessage(string $ws, string $ins, int $etape, string $statut, ?string $inj = null, ?string $serveur = null): void
{
    DB::table('envoi_messages')->insert(['workspace_id' => $ws, 'inscription_id' => $ins, 'etape' => $etape, 'statut' => $statut, 'message_id' => 'm-' . Str::uuid() . '@example.test',
        'id_injection' => $inj, 'id_injection_serveur' => $serveur]);
}

/** Un identifiant du registre local (nécessaire à l'insertion d'un événement rattaché) ; rend l'UUID. */
function cptInjection(string $ws, ?string $cle = null): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_injections_locales')->insert(['workspace_id' => $ws, 'id_injection' => $id, 'cle_idempotence' => $cle ?? 'x-' . $id, 'adaptateur' => 'faux']);

    return $id;
}

function cptEvenement(string $ws, string $classe, string $statut, ?string $idInjection): void
{
    $n = cptN();
    DB::table('envoi_evenements')->insert(['workspace_id' => $ws, 'boite' => 'boite-fictive', 'uid_validity' => 1, 'uid' => $n, 'empreinte_message' => str_pad(dechex($n), 64, 'b', STR_PAD_LEFT),
        'classe' => $classe, 'statut' => $statut, 'id_injection' => $statut === 'rattache' ? $idInjection : null, 'empreinte_expediteur' => str_repeat('c', 64), 'recu_le' => now()]);
}

function cptTrace(string $ws, string $ins, string $evenement): void
{
    $i = DB::table('envoi_inscriptions')->where('id', $ins)->first();
    DB::table('envoi_inscriptions_evenements')->insert(['workspace_id' => $ws, 'inscription_id' => $ins, 'entreprise_id' => $i->entreprise_id, 'empreinte_adresse' => $i->empreinte_adresse, 'evenement' => $evenement]);
}

/** Rebond/événement rattaché au message (etape 1) d'une inscription, par l'une des trois formes. */
function cptRattache(string $ws, string $ins, string $classe, string $forme, int $etape = 1): void
{
    $uuid = cptInjection($ws, $forme === 'cle' ? 'seq-' . $ins . '-' . $etape : null);
    match ($forme) {
        'injection' => cptMessage($ws, $ins, $etape, 'envoye', $uuid),
        'serveur' => cptMessage($ws, $ins, $etape, 'envoye', null, $uuid),
        'cle' => cptMessage($ws, $ins, $etape, 'envoye'),
    };
    cptEvenement($ws, $classe, 'rattache', $uuid);
}

function cptSous(string $mode, string $ws, Closure $f): mixed
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

function cptPour(string $mode, string $ws, string $c): array
{
    return cptSous($mode, $ws, fn () => (new CompteursCampagne)->pour($ws, $c));
}

const CPT_CLES = ['inscrits', 'actives', 'envoyes', 'touches', 'refuses', 'ouverts', 'suivi_ouvertures', 'reponses', 'rebonds_durs', 'rebonds_mous', 'oppositions',
    'arretes_entreprise', 'arretes_campagne', 'exclus_preparation', 'preparation', 'issues', 'taux_refus', 'taux_desinscription'];

test('L6-A 1 sans séquence : toutes les clés, zéros, ouverts null, suivi_ouvertures faux, preparation null', function () {
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        $c = (string) Str::uuid();
        DB::table('envoi_campagnes')->insert(['id' => $c, 'workspace_id' => $ws, 'nom' => 'Sans séquence', 'cible' => json_encode(['type' => 'liste', 'reference' => 'l', 'libelle' => 'l']),
            'boites' => '{' . (string) Str::uuid() . '}', 'nb_etapes' => 2, 'saisie' => json_encode(['objet' => 'Bonjour']), 'cree_par' => 'responsable']);
        $r = cptPour($mode, $ws, $c);
        expect(array_keys($r))->toEqualCanonicalizing(CPT_CLES);
        expect($r['ouverts'])->toBeNull()->and($r['suivi_ouvertures'])->toBeFalse()->and($r['preparation'])->toBeNull()
            ->and($r['inscrits'])->toBe(0)->and($r['envoyes'])->toBe(0)->and($r['exclus_preparation'])->toBe(0)->and($r['taux_refus'])->toBe(0.0)
            ->and(array_sum($r['issues']))->toBe(0);
    }
});

test('L6-A 2 nominal : 7 envoyés, 5 touchés, 1 refus ; reserve et reporte jamais comptés', function () {
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        [$c, $s] = cptCampagne($ws);
        $i = array_map(fn () => cptInscrire($ws, $s), range(1, 10));
        foreach (range(0, 4) as $k) {
            cptMessage($ws, $i[$k], 1, 'envoye');
        }
        cptMessage($ws, $i[0], 2, 'envoye');
        cptMessage($ws, $i[1], 2, 'envoye');
        cptMessage($ws, $i[5], 1, 'refuse');
        cptMessage($ws, $i[6], 1, 'reserve');
        cptMessage($ws, $i[7], 1, 'reporte');
        $r = cptPour($mode, $ws, $c);
        expect($r['inscrits'])->toBe(10, $mode)->and($r['envoyes'])->toBe(7, $mode)->and($r['touches'])->toBe(5, $mode)->and($r['refuses'])->toBe(1, $mode)
            ->and($r['actives'])->toBe(10, $mode)->and($r['taux_refus'])->toBe(round(1 / 8, 4), $mode);
    }
});

test('L6-A 3 réponses : la trace compte ; l\'arrêt entreprise_arretee des collègues n\'est PAS une réponse', function () {
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        [$c, $s] = cptCampagne($ws);
        $a = cptInscrire($ws, $s);
        cptTrace($ws, $a, 'reponse');
        cptInscrire($ws, $s, 'arretee', 'entreprise_arretee');
        $r = cptPour($mode, $ws, $c);
        expect($r['reponses'])->toBe(1, $mode)->and($r['arretes_entreprise'])->toBe(1, $mode);
    }
});

test('L6-A 4 rebonds : trois formes de rattachement = 3 ; même rebond par deux formes + trace = 1', function () {
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        [$c, $s] = cptCampagne($ws);
        foreach (['injection', 'serveur', 'cle'] as $forme) {
            cptRattache($ws, cptInscrire($ws, $s), 'rebond_dur', $forme);
        }
        expect(cptPour($mode, $ws, $c)['rebonds_durs'])->toBe(3, $mode);

        $ws2 = cptEspace();
        [$c2, $s2] = cptCampagne($ws2);
        $x = cptInscrire($ws2, $s2);
        $u = cptInjection($ws2, 'seq-' . $x . '-1');
        cptMessage($ws2, $x, 1, 'envoye', $u, $u); // même UUID : formes 1, 2 et 3 à la fois
        cptEvenement($ws2, 'rebond_dur', 'rattache', $u);
        cptTrace($ws2, $x, 'rebond_dur');
        expect(cptPour($mode, $ws2, $c2)['rebonds_durs'])->toBe(1, $mode);
    }
});

test('L6-A 5 dur l\'emporte sur mou', function () {
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        [$c, $s] = cptCampagne($ws);
        $x = cptInscrire($ws, $s);
        cptRattache($ws, $x, 'rebond_mou', 'injection', 1);
        cptRattache($ws, $x, 'rebond_dur', 'serveur', 2);
        $y = cptInscrire($ws, $s);
        cptRattache($ws, $y, 'rebond_mou', 'injection');
        $r = cptPour($mode, $ws, $c);
        expect($r['rebonds_durs'])->toBe(1, $mode)->and($r['rebonds_mous'])->toBe(1, $mode);
    }
});

test('L6-A 6 oppositions : stop tracé, motif seul, garde:opposition, désinscription IMAP = 4 ; trace + motif sur la même = 1', function () {
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        [$c, $s] = cptCampagne($ws);
        cptTrace($ws, cptInscrire($ws, $s), 'stop');
        cptInscrire($ws, $s, 'arretee', 'opposition');
        cptInscrire($ws, $s, 'arretee', 'garde:opposition');
        cptRattache($ws, cptInscrire($ws, $s), 'desinscription', 'injection');
        expect(cptPour($mode, $ws, $c)['oppositions'])->toBe(4, $mode);

        $ws2 = cptEspace();
        [$c2, $s2] = cptCampagne($ws2);
        $z = cptInscrire($ws2, $s2, 'arretee', 'opposition');
        cptTrace($ws2, $z, 'opposition');
        expect(cptPour($mode, $ws2, $c2)['oppositions'])->toBe(1, $mode);
    }
});

test('L6-A 7 partition : somme des issues = inscrits ; opposée qui avait répondu = opposition seulement', function () {
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        [$c, $s] = cptCampagne($ws);
        $o = cptInscrire($ws, $s, 'arretee', 'opposition');
        cptTrace($ws, $o, 'reponse');
        cptRattache($ws, cptInscrire($ws, $s, 'arretee', 'rebond_dur'), 'rebond_dur', 'injection');
        $rep = cptInscrire($ws, $s, 'arretee', 'reponse');
        cptTrace($ws, $rep, 'reponse');
        cptRattache($ws, cptInscrire($ws, $s), 'rebond_mou', 'injection');
        cptInscrire($ws, $s, 'arretee', 'campagne_arretee');
        cptInscrire($ws, $s, 'arretee', 'entreprise_arretee');
        cptInscrire($ws, $s, 'terminee');
        cptInscrire($ws, $s);
        $r = cptPour($mode, $ws, $c);
        expect($r['issues'])->toBe(['opposition' => 1, 'rebond_dur' => 1, 'reponse' => 1, 'rebond_mou' => 1, 'arretee_autre' => 2, 'terminee' => 1, 'active' => 1], $mode)
            ->and(array_sum($r['issues']))->toBe($r['inscrits'], $mode)->and($r['reponses'])->toBe(2, $mode)->and($r['arretes_campagne'])->toBe(1, $mode);
    }
});

test('L6-A 8 a_rattacher ignoré ; événement d\'un autre espace au même id_injection ignoré', function () {
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        $autre = cptEspace();
        [$c, $s] = cptCampagne($ws);
        $x = cptInscrire($ws, $s);
        $u = cptInjection($ws);
        cptMessage($ws, $x, 1, 'envoye', $u);
        cptEvenement($ws, 'rebond_dur', 'a_rattacher', null);
        // l'autre espace porte le même UUID dans son registre, puis un événement rattaché dessus
        DB::table('envoi_injections_locales')->insert(['workspace_id' => $autre, 'id_injection' => (string) Str::uuid(), 'cle_idempotence' => 'k', 'adaptateur' => 'faux']);
        $v = DB::table('envoi_injections_locales')->where('workspace_id', $autre)->value('id_injection');
        DB::table('envoi_messages')->where('workspace_id', $ws)->update(['id_injection' => $v]);
        cptEvenement($autre, 'rebond_dur', 'rattache', $v);
        $r = cptPour($mode, $ws, $c);
        expect($r['rebonds_durs'])->toBe(0, $mode)->and($r['oppositions'])->toBe(0, $mode);
    }
});

test('L6-A 9 cloisonnement : miroir A/B, campagne de B vue depuis A = zéros', function () {
    foreach (CPT_MODES as $mode) {
        $a = cptEspace();
        $b = cptEspace();
        [$ca, $sa] = cptCampagne($a);
        [$cb, $sb] = cptCampagne($b);
        foreach ([[$a, $sa], [$b, $sb]] as [$w, $q]) {
            $i = cptInscrire($w, $q);
            cptMessage($w, $i, 1, 'envoye');
        }
        cptInscrire($b, $sb);
        expect(cptPour($mode, $a, $ca)['inscrits'])->toBe(1, $mode)->and(cptPour($mode, $b, $cb)['inscrits'])->toBe(2, $mode);
        $croise = cptPour($mode, $a, $cb);
        expect($croise['inscrits'])->toBe(0, $mode)->and($croise['envoyes'])->toBe(0, $mode)->and($croise)->toBe(cptPour($mode, $a, (string) Str::uuid()), $mode);
    }
});

test('L6-A 10 volume : 20 000 inscriptions, 40 000 messages, 5 000 événements sur 3 campagnes, < 300 ms, sans Seq Scan sur envoi_evenements', function () {
    $ws = cptEspace();
    $ids = [];
    foreach (range(1, 3) as $k) {
        [$c, $s] = cptCampagne($ws, 2);
        $ids[] = $c;
        $debut = ($k - 1) * 7000;
        DB::statement("INSERT INTO envoi_inscriptions (workspace_id, sequence_id, contact_id, entreprise_id, empreinte_adresse, prochaine_echeance, version_figee)
            SELECT ?::uuid, ?::uuid, g, g, lpad(to_hex(g), 64, '0'), now(), '{}' FROM generate_series(?::bigint, ?::bigint) g", [$ws, $s, 1_000_000 + $debut, 1_000_000 + $debut + ($k === 3 ? 6000 : 7000) - 1]);
    }
    DB::statement("INSERT INTO envoi_injections_locales (workspace_id, id_injection, cle_idempotence, adaptateur)
        SELECT ?::uuid, gen_random_uuid(), 'vol-' || g, 'faux' FROM generate_series(1, 5000) g", [$ws]);
    DB::statement('CREATE TEMP TABLE cpt_vol AS SELECT i.id AS iid, row_number() OVER (ORDER BY i.contact_id) AS rn FROM envoi_inscriptions i WHERE i.workspace_id = ?', [$ws]);
    DB::statement("INSERT INTO envoi_messages (workspace_id, inscription_id, etape, statut, message_id, id_injection)
        SELECT ?::uuid, v.iid, e, 'envoye', 'vol-' || v.rn || '-' || e || '@example.test', CASE WHEN e = 1 AND v.rn <= 5000 THEN (SELECT l.id_injection::text FROM envoi_injections_locales l WHERE l.workspace_id = ?::uuid AND l.cle_idempotence = 'vol-' || v.rn) END
        FROM cpt_vol v CROSS JOIN generate_series(1, 2) e", [$ws, $ws]);
    DB::statement("INSERT INTO envoi_evenements (workspace_id, boite, uid_validity, uid, empreinte_message, classe, statut, id_injection, empreinte_expediteur, recu_le)
        SELECT ?::uuid, 'vol', 1, l.n, lpad(to_hex(l.n), 64, '0'), 'rebond_dur', 'rattache', l.id_injection, repeat('c', 64), now()
        FROM (SELECT id_injection, row_number() OVER () AS n FROM envoi_injections_locales WHERE workspace_id = ?::uuid AND cle_idempotence LIKE 'vol-%') l", [$ws, $ws]);
    DB::statement('ANALYZE envoi_inscriptions');
    DB::statement('ANALYZE envoi_messages');
    DB::statement('ANALYZE envoi_evenements');
    DB::statement('ANALYZE envoi_injections_locales');

    foreach (CPT_MODES as $mode) {
        $t = microtime(true);
        $r = cptSous($mode, $ws, fn () => (new CompteursCampagne)->pourListe($ws, $ids));
        $ms = (microtime(true) - $t) * 1000;
        expect($ms)->toBeLessThan(300.0, "{$mode} {$ms} ms")->and(array_sum(array_column($r, 'inscrits')))->toBe(20_000)->and(array_sum(array_column($r, 'rebonds_durs')))->toBe(5_000);
    }
    // Même convention que SchemaEvenementsInjectionIndexTest : le planificateur PEUT n'utiliser que l'index (enable_seqscan coupé le temps du plan).
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_nestloop = off');
    [$sql, $liaisons] = CompteursCampagne::preparer($ws, $ids);
    $plan = collect(DB::select('EXPLAIN ' . $sql, $liaisons))->pluck('QUERY PLAN')->implode("\n");
    DB::statement('RESET enable_seqscan');
    DB::statement('RESET enable_nestloop');
    expect($plan)->toContain('envoi_evenements_injection_texte')->not->toContain('Seq Scan on envoi_evenements');
});

test('L6-A 11 une seule instruction par appel (hors contexte) ; 12 lecture seule : aucune écriture, source sans insert/update/delete', function () {
    $vues = [];
    DB::listen(function ($q) use (&$vues) {
        if (! str_contains($q->sql, 'set_config') && ! str_starts_with(strtoupper(trim($q->sql)), 'SET ') && ! str_starts_with(strtoupper(trim($q->sql)), 'RESET ')) {
            $vues[] = $q->sql;
        }
    });
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        [$c, $s] = cptCampagne($ws);
        cptMessage($ws, cptInscrire($ws, $s), 1, 'envoye');
        $vues = [];
        cptSous($mode, $ws, fn () => (new CompteursCampagne)->pour($ws, $c));
        expect($vues)->toHaveCount(1, $mode);
        $vues = [];
        cptSous($mode, $ws, fn () => (new CompteursCampagne)->pourListe($ws, [$c, (string) Str::uuid()]));
        expect($vues)->toHaveCount(1, $mode)->and(implode(' ', $vues))->not->toMatch('/\b(INSERT|UPDATE|DELETE|TRUNCATE)\b/i');
    }
    $src = (string) file_get_contents(CPT_SOURCE);
    expect($src)->not->toContain('->insert(')->not->toContain('->update(')->not->toContain('->delete(');
});

test('L6-A préparation : etat, motif, inscrits, membres ; exclus_preparation = membres - retenus', function () {
    foreach (CPT_MODES as $mode) {
        $ws = cptEspace();
        [$c, $s] = cptCampagne($ws);
        DB::table('envoi_campagnes_materialisations')->insert(['campagne_id' => $c, 'workspace_id' => $ws, 'sequence_id' => $s, 'compteurs' => json_encode(['membres' => 10, 'retenus' => 7, 'inscrits' => 5])]);
        $r = cptPour($mode, $ws, $c);
        expect($r['preparation'])->toBe(['etat' => 'en_cours', 'motif' => null, 'inscrits' => 5, 'membres' => 10], $mode)->and($r['exclus_preparation'])->toBe(3, $mode);
    }
});

<?php

/**
 * LOT N2 — le workflow `.github/workflows/surveillance-canaux.yml` garde ses
 * promesses de structure : planifié chaque heure, borné, appelant une commande
 * qui EXISTE, traitant « serveur injoignable » comme une alerte, une issue
 * par type d'alerte, et jamais la sortie d'erreur de ssh dans une issue
 * publique (elle peut nommer le serveur).
 *
 * En CI (`actions/checkout`), la garde lit le vrai arbre.
 */

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function n2WorkflowSource(): string
{
    $chemin = (realpath(base_path('..')) ?: base_path('..')) . '/.github/workflows/surveillance-canaux.yml';
    expect(is_file($chemin))->toBeTrue("Workflow introuvable : {$chemin}");

    return (string) file_get_contents($chemin);
}

/** @return array<mixed> */
function n2Workflow(): array
{
    $wf = Yaml::parse(n2WorkflowSource());
    expect($wf)->toBeArray();

    /** @var array<mixed> $wf */
    return $wf;
}

test('planifié toutes les heures, et relançable à la main', function () {
    $wf = n2Workflow();
    // Selon la version de YAML, la clé `on` peut être lue comme le booléen true.
    $declencheurs = $wf['on'] ?? $wf[true] ?? $wf[1] ?? null;

    expect($declencheurs)->toBeArray()
        ->and($declencheurs)->toHaveKey('workflow_dispatch')
        ->and($declencheurs['schedule'][0]['cron'] ?? null)->toMatch('/^\d{1,2} \* \* \* \*$/');
});

test('chaque job porte une borne de durée (F38-008)', function () {
    $jobs = n2Workflow()['jobs'] ?? [];
    expect($jobs)->toHaveKeys(['verifier', 'alerte']);

    foreach ($jobs as $nom => $job) {
        expect(is_array($job) && array_key_exists('timeout-minutes', $job))->toBeTrue("Job « {$nom} » sans timeout-minutes");
    }
});

test('la commande appelée existe, et la mesure est en lecture seule (aucune option d’écriture)', function () {
    $source = n2WorkflowSource();

    expect($source)->toContain('php artisan crm:canaux:etat')
        ->and(array_keys(Artisan::all()))->toContain('crm:canaux:etat')
        ->and($source)->not->toContain('-u root');
});

test('« serveur injoignable » (ssh 255) est une alerte, et l’alerte suit un job de mesure en échec', function () {
    $wf = n2Workflow();
    $source = n2WorkflowSource();

    expect($source)->toContain('"$CODE" -eq 255')
        ->and($source)->toContain('controle_impossible')
        // Le job d'alerte tourne aussi quand tout est vert : c'est lui qui
        // ferme les issues rétablies. Jamais sur une exécution annulée.
        ->and($wf['jobs']['alerte']['if'] ?? null)->toBe('${{ !cancelled() }}')
        ->and($wf['jobs']['alerte']['needs'] ?? null)->toBe(['verifier']);
});

test('une issue par type : recherche d’une issue ouverte au même libellé avant d’en créer', function () {
    $source = n2WorkflowSource();

    expect($source)->toContain('gh issue list --repo "$DEPOT" --state open --label "$LIBELLE"')
        ->and($source)->toContain('gh issue create --repo "$DEPOT" --title "$TITRE" --label "$LIBELLE"');
});

test('la sortie d’erreur de ssh ne part JAMAIS dans une issue (dépôt public)', function () {
    $wf = n2Workflow();
    $alerte = json_encode($wf['jobs']['alerte'] ?? [], JSON_THROW_ON_ERROR);
    $sorties = json_encode($wf['jobs']['verifier']['outputs'] ?? [], JSON_THROW_ON_ERROR);

    expect($alerte)->not->toContain('erreurs.txt')
        ->and($sorties)->not->toContain('erreurs');
});

test('permissions minimales : le job qui tient la clé SSH n’a AUCUN droit, seul le job d’alerte écrit des issues', function () {
    $wf = n2Workflow();

    expect($wf['permissions'] ?? null)->toBe(['contents' => 'read'])
        ->and($wf['jobs']['verifier']['permissions'] ?? null)->toBe([])
        ->and($wf['jobs']['alerte']['permissions'] ?? null)->toBe(['contents' => 'read', 'issues' => 'write']);
});

test('RETOUR AU VERT : un type qui n’apparaît plus voit son issue fermée (« rétabli le … »), jamais sur un contrôle impossible', function () {
    $source = n2WorkflowSource();

    expect($source)->toContain('gh issue close "$N"')
        ->and($source)->toContain('Rétabli le')
        // Sans mesure, on ne sait pas si c'est rétabli : on ne ferme rien.
        ->and($source)->toContain('MESURE_COMPLETE');
});

test('ABANDONS : fenêtre de 26 h, le « déjà signalé » est tenu côté serveur (--memoriser-signales), plus dans les issues', function () {
    $source = n2WorkflowSource();

    expect($source)->toContain("vars.SURV_CANAUX_FENETRE_ABANDON_MIN || '1560'")
        ->and($source)->toContain('--memoriser-signales')
        ->and($source)->toContain('gave_up_nouveaux')
        // Veto sécurité #310 : plus aucun état lu dans les issues publiques.
        ->and($source)->not->toContain('gave_up_ids')
        ->and($source)->not->toContain('gave_up_recents_ids')
        ->and($source)->not->toContain('--state all');
});

test('veto sécurité #310 : le journal PUBLIC n’imprime qu’un résumé chiffré, jamais le JSON complet', function () {
    $source = n2WorkflowSource();

    expect($source)->not->toContain('jq . etat.json')
        ->and($source)->toContain('RESUME_JOURNAL');
});

test('veto sécurité #310 : SURV_CANAUX_FERMES_EXPRES passe par une liste blanche EXACTE, sans dépendre de la locale', function () {
    $source = n2WorkflowSource();
    expect(preg_match("/^\\s*RE_FERMES='([^']+)'$/m", $source, $m))->toBe(1);
    $regex = $m[1];

    $valides = ['', 'site-vers-crm', 'crm-vers-site', 'site-vers-crm,crm-vers-site', 'crm-vers-site,site-vers-crm'];
    $invalides = ['abc', 'site-vers-crm;id', 'site-vers-crm,x', '$(id)', 'site-vers-crmx', "site-vers-crm\ncrm-vers-site", 'site', '-'];
    foreach ([...array_map(fn ($v) => [$v, true], $valides), ...array_map(fn ($v) => [$v, false], $invalides)] as [$valeur, $attendu]) {
        $p = proc_open(['bash', '-c', '[[ "$V" =~ $RE ]]'], [], $tubes, null, ['V' => $valeur, 'RE' => $regex, 'LC_ALL' => 'C']);
        expect(proc_close($p) === 0)->toBe($attendu, 'Valeur « ' . $valeur . ' » : ' . ($attendu ? 'devait passer' : 'devait être refusée'));
    }
});

test('en-tête DÉPÔT PUBLIC : les risques et champs publics assumés sont écrits', function () {
    $source = n2WorkflowSource();
    $entete = substr($source, 0, (int) strpos($source, "\non:"));

    expect($entete)->toContain('bad_signature')
        ->and($entete)->toContain('un tiers')
        ->and($entete)->toContain('canaux_fermes_expres')
        ->and($entete)->toContain('ferme_expres')
        ->and($entete)->toContain('planificateur.dernier_battement_a')
        ->and($entete)->toContain('sans_entete')
        ->and($entete)->toContain('github-actions');
});

// ─────────────────────────────────────────────────────────────────────────────
// COMPORTEMENT RÉEL du job d'alerte : son script est extrait du workflow et
// joué par bash avec un FAUX `gh` qui journalise ses appels (aucun réseau).
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param  list<array<string, mixed>>  $alertes
 * @param  array<string, mixed>  $etat
 * @param  array<string, mixed>|null  $issueOuverte  `{number, createdAt, comments}` de l'issue ouverte au libellé, ou null
 * @return array{code: int, journal: string, corps: string, sortie: string}
 */
function n2JouerAlerte(array $alertes, array $etat, string $libelle, ?array $issueOuverte): array
{
    if (trim((string) shell_exec('command -v jq')) === '') {
        test()->markTestSkipped('jq absent : il est présent sur les runners GitHub (ubuntu-24.04).');
    }

    $script = n2Workflow()['jobs']['alerte']['steps'][0]['run'] ?? null;
    expect($script)->toBeString();

    $dir = sys_get_temp_dir() . '/n2-faux-gh-' . bin2hex(random_bytes(4));
    mkdir($dir . '/bin', 0700, true);
    file_put_contents($dir . '/liste.json', json_encode($issueOuverte === null ? [] : [['number' => $issueOuverte['number']]]));
    file_put_contents($dir . '/vue.json', json_encode($issueOuverte ?? new stdClass));
    file_put_contents($dir . '/script.sh', (string) $script);
    file_put_contents($dir . '/bin/gh', <<<'SH'
#!/usr/bin/env bash
echo "gh $*" >> "$FAUX_GH_DIR/journal.txt"
args=("$@"); jqexpr=''; libelle=''
for ((i = 0; i < ${#args[@]}; i++)); do
  case "${args[$i]}" in
    --jq) jqexpr="${args[$((i + 1))]}" ;;
    --label) libelle="${args[$((i + 1))]}" ;;
    --body-file) cat "${args[$((i + 1))]}" >> "$FAUX_GH_DIR/corps.txt" ;;
  esac
done
case "$1 $2" in
  'issue list') if [ "$libelle" = "$FAUX_GH_LIBELLE" ]; then src="$FAUX_GH_DIR/liste.json"; else echo '[]' > "$FAUX_GH_DIR/vide.json"; src="$FAUX_GH_DIR/vide.json"; fi ;;
  'issue view') src="$FAUX_GH_DIR/vue.json" ;;
  *) exit 0 ;;
esac
if [ -n "$jqexpr" ]; then jq -r "$jqexpr" < "$src"; else cat "$src"; fi
SH);
    chmod($dir . '/bin/gh', 0700);
    touch($dir . '/journal.txt');
    touch($dir . '/corps.txt');

    $env = [
        'PATH' => $dir . '/bin:' . getenv('PATH'),
        'HOME' => $dir,
        'FAUX_GH_DIR' => $dir,
        'FAUX_GH_LIBELLE' => $libelle,
        'GH_TOKEN' => 'faux',
        'DEPOT' => 'exemple/depot',
        'RUN_URL' => 'https://example.invalid/run/1',
        'ALERTES' => json_encode($alertes, JSON_UNESCAPED_UNICODE),
        'ETAT' => json_encode($etat, JSON_UNESCAPED_UNICODE),
        'RESULTAT_MESURE' => 'success',
    ];
    $p = proc_open(['bash', '--noprofile', '--norc', '-eo', 'pipefail', $dir . '/script.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, $dir, $env);
    $sortie = stream_get_contents($tubes[1]) . stream_get_contents($tubes[2]);
    $code = proc_close($p);

    $resultat = [
        'code' => $code,
        'journal' => (string) file_get_contents($dir . '/journal.txt'),
        'corps' => (string) file_get_contents($dir . '/corps.txt'),
        'sortie' => $sortie,
    ];
    exec('rm -rf ' . escapeshellarg($dir));

    return $resultat;
}

/** Un commentaire d'issue tel que `gh --json comments` le rend. */
function n2Commentaire(string $auteur, int $ilYaH, string $corps = 'occurrence'): array
{
    return ['author' => ['login' => $auteur], 'createdAt' => now()->subHours($ilYaH)->utc()->format('Y-m-d\TH:i:s\Z'), 'body' => $corps];
}

/** @param  list<array<string, mixed>>  $commentaires */
function n2Issue(int $numero, int $creeIlYaH, array $commentaires): array
{
    return ['number' => $numero, 'createdAt' => now()->subHours($creeIlYaH)->utc()->format('Y-m-d\TH:i:s\Z'), 'comments' => $commentaires];
}

test('veto sécurité #310 : la minuterie des 24 h ne compte QUE les commentaires du bot — un tiers ne fait pas taire le rappel', function () {
    $alertes = [['type' => 'refus_signature', 'message' => 'Trop de refus.']];

    // Le bot a écrit il y a 30 h ; un tiers commente il y a 1 h pour tout
    // repousser. Le rappel des 24 h doit partir quand même.
    $r = n2JouerAlerte($alertes, [], 'canal-refus-signature', n2Issue(7, 72, [
        n2Commentaire('github-actions', 30),
        n2Commentaire('tiers-malveillant', 1, 'je repousse la minuterie'),
    ]));
    // TÉMOIN : le bot a écrit il y a 2 h → pas de rappel.
    $temoin = n2JouerAlerte($alertes, [], 'canal-refus-signature', n2Issue(7, 72, [
        n2Commentaire('github-actions', 2),
    ]));
    // Sans aucun commentaire du bot, seule la date de l'issue compte.
    $sansBot = n2JouerAlerte($alertes, [], 'canal-refus-signature', n2Issue(7, 30, [
        n2Commentaire('tiers-malveillant', 1),
    ]));

    expect($r['code'])->toBe(0, $r['sortie'])
        ->and($r['journal'])->toContain('gh issue comment 7')
        ->and($temoin['code'])->toBe(0, $temoin['sortie'])
        ->and($temoin['journal'])->not->toContain('gh issue comment')
        ->and($sansBot['journal'])->toContain('gh issue comment 7');
});

test('veto sécurité #310 : un marqueur posté par un tiers ne fait plus taire l’alerte gave_up — seul le compteur serveur décide', function () {
    $alertes = [['type' => 'file_sortante_abandon', 'message' => '2 événement(s) abandonné(s).']];
    $marqueurTiers = n2Commentaire('tiers-malveillant', 1, '<!-- gave_up_ids: 1,2,3,4,5,6,7,8,9,10 -->');

    // Deux lignes NOUVELLES selon le serveur, issue ouverte complétée il y a
    // 1 h, et un tiers qui prétend que tout est déjà dit : l'issue est
    // complétée quand même.
    $nouveaux = n2JouerAlerte(
        $alertes,
        ['file_sortante' => ['gave_up_recents' => 2, 'gave_up_nouveaux' => 2]],
        'canal-file-sortante-abandon',
        n2Issue(9, 48, [n2Commentaire('github-actions', 1), $marqueurTiers]),
    );
    // Rien de nouveau selon le serveur : pas de répétition, même après 24 h.
    $dejaDits = n2JouerAlerte(
        $alertes,
        ['file_sortante' => ['gave_up_recents' => 2, 'gave_up_nouveaux' => 0]],
        'canal-file-sortante-abandon',
        n2Issue(9, 48, [n2Commentaire('github-actions', 30)]),
    );
    // Aucune issue ouverte et des lignes nouvelles : on en ouvre une.
    $premiere = n2JouerAlerte(
        $alertes,
        ['file_sortante' => ['gave_up_recents' => 1, 'gave_up_nouveaux' => 1]],
        'canal-file-sortante-abandon',
        null,
    );

    expect($nouveaux['code'])->toBe(0, $nouveaux['sortie'])
        ->and($nouveaux['journal'])->toContain('gh issue comment 9')
        ->and($nouveaux['corps'])->toContain('2 ligne(s)')
        ->and($nouveaux['corps'])->not->toContain('gave_up_ids')
        ->and($dejaDits['code'])->toBe(0, $dejaDits['sortie'])
        ->and($dejaDits['journal'])->not->toContain('gh issue comment')
        ->and($dejaDits['journal'])->not->toContain('gh issue create')
        ->and($premiere['journal'])->toContain('gh issue create')
        // Aucune lecture des issues fermées, ni des commentaires, pour décider.
        ->and($nouveaux['journal'] . $dejaDits['journal'])->not->toContain('--state all');
});

test('RETOUR AU VERT (comportement) : une issue ouverte d’un type disparu est fermée, jamais sur un contrôle impossible', function () {
    $vert = n2JouerAlerte([], [], 'canal-site-muet', n2Issue(4, 5, []));
    $impossible = n2JouerAlerte([['type' => 'controle_impossible', 'message' => 'SSH.']], [], 'canal-site-muet', n2Issue(4, 5, []));

    expect($vert['code'])->toBe(0, $vert['sortie'])
        ->and($vert['journal'])->toContain('gh issue close 4')
        ->and($impossible['journal'])->not->toContain('gh issue close');
});

test('réglages neufs relayés : seuil bad_signature et canaux fermés exprès, validés avant la commande distante', function () {
    $source = n2WorkflowSource();

    expect($source)->toContain('vars.SURV_CANAUX_SEUIL_BAD_SIGNATURE')
        ->and($source)->toContain('--seuil-bad-signature=')
        ->and($source)->toContain('vars.SURV_CANAUX_FERMES_EXPRES')
        ->and($source)->toContain('--fermes-expres=')
        ->and($source)->toContain('planificateur_arrete');
});

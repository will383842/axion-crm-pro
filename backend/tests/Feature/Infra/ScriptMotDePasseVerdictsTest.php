<?php

/**
 * GARDE DU SCRIPT QUI REND L'ACCÈS AU CRM — constats P5-35-004 et P5-35-005.
 *
 * CE QUE FAIT CE SCRIPT.
 *
 * `infra/scripts/definir-mot-de-passe-crm.sh` est le geste par lequel
 * l'exploitant reprend la main sur son propre produit (constat A-012 : le
 * propriétaire ne pouvait pas entrer dans le CRM). Il n'a **jamais eu de
 * garde** — c'est P5-35-005.
 *
 * LE DÉFAUT QUE CETTE GARDE FERME.
 *
 * Un correctif antérieur (F35-014) a ajouté `-e` à `set -uo pipefail` pour que
 * le script cesse de mentir sur son succès. Sous `set -e`, une affectation dont
 * la substitution de commande rend un code non nul **tue le script sur-le-champ**.
 * Or le code PHP exécuté dans le conteneur appelle `exit(1)` sur
 * `A35_INTROUVABLE` et sur `A35_ECHEC_MDP_VIDE`. Le `case "$VERDICT"` placé
 * juste après, et tous ses messages, ne sont donc jamais atteints.
 *
 * Mesuré, avant correctif, sur les quatre verdicts :
 *
 *   A35_OK ................... message rendu
 *   A35_INTROUVABLE .......... AUCUN MESSAGE, code 1
 *   A35_ECHEC_MDP_VIDE ....... AUCUN MESSAGE, code 1
 *   sortie inattendue ........ AUCUN MESSAGE, code 1
 *
 * L'opérateur qui se trompe d'une lettre dans l'adresse ne voit donc **rien** :
 * ni « aucun compte », ni le bloc de diagnostic prévu pour ce cas. Le constat
 * F35-014 disait qu'« un faux "c'est fait" envoie l'opérateur chercher la panne
 * du mauvais côté ». Son correctif avait remplacé le faux succès par le
 * **silence total**, ce qui n'est pas mieux.
 *
 * COMMENT ELLE MESURE.
 *
 * On ne joue pas contre un vrai Docker : on pose un `docker` factice en tête de
 * `PATH`, qui rend le verdict demandé avec le code de retour correspondant.
 * La variable isolée est donc bien le **verdict**, et rien d'autre.
 */

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

function cheminScriptMotDePasse(): string
{
    return (realpath(base_path('..')) ?: base_path('..')) . '/infra/scripts/definir-mot-de-passe-crm.sh';
}

/**
 * Pose un `docker` factice et joue le script pour un verdict donné.
 *
 * @return array{sortie: string, code: int}
 */
function jouerScriptMotDePasse(string $verdict, int $codeConteneur): array
{
    $atelier = sys_get_temp_dir() . '/garde-mdp-' . bin2hex(random_bytes(6));
    mkdir($atelier . '/bin', 0o755, true);

    // ⚠️ PIÈGE PAYÉ À L'ÉCRITURE DE CETTE GARDE, et consigné pour le suivant.
    //
    // Une première version faisait échouer TOUS les `docker exec`. Or le script
    // en lance deux : l'appel à `artisan tinker`, et le NETTOYAGE du fichier
    // temporaire. Sous `set -e`, c'est le nettoyage qui tuait le script — la
    // sonde mesurait donc sa propre panne, et déclarait le correctif inopérant
    // alors qu'il fonctionnait. Seul l'appel à `tinker` doit rendre le verdict.
    file_put_contents($atelier . '/bin/docker', <<<'SH'
#!/bin/sh
case "$1" in
  ps) echo "axion-crm-api" ;;
  cp) exit 0 ;;
  exec)
     for a in "$@"; do
       if [ "$a" = "tinker" ]; then
         cat > /dev/null
         printf "%s\n" "$GARDE_VERDICT"
         exit "${GARDE_CODE:-0}"
       fi
     done
     exit 0 ;;
  *) exit 0 ;;
esac
SH);
    chmod($atelier . '/bin/docker', 0o755);

    // ⚠️ ET UN FAUX `id`, POUR LA MEME RAISON QUE LE FAUX `docker`.
    //
    // `definir-mot-de-passe-crm.sh:50` sort immediatement si `id -u` ne rend
    // pas 0 :
    //
    //     if [ "$(id -u)" -ne 0 ]; then
    //       echo "ERREUR : a lancer en root." >&2
    //
    // Le banc `a35r` tourne en root dans son conteneur ; le runner GitHub, non.
    // Mesure du 2026-08-21 : en CI, les trois gardes ci-dessous recevaient
    // « erreur : a lancer en root. » et n'atteignaient JAMAIS le code qu'elles
    // pretendent mesurer. Elles etaient vertes sur le banc et rouges en CI, pour
    // une raison qui n'a rien a voir avec le produit.
    //
    // On ne touche pas au script — sa garde root est correcte et doit le rester.
    // On lui donne un `id` qui repond 0, comme on lui donne deja un `docker` qui
    // repond ce qu'on veut mesurer.
    file_put_contents($atelier . '/bin/id', <<<'SH'
#!/bin/sh
# Le script n'appelle `id` que sous la forme `id -u`. Tout autre usage est
# delegue au vrai binaire : une garde ne doit pas mentir plus que necessaire.
if [ "$1" = "-u" ]; then
  echo 0
  exit 0
fi
exec /usr/bin/id "$@"
SH);
    chmod($atelier . '/bin/id', 0o755);

    $processus = new Process(
        ['bash', cheminScriptMotDePasse(), 'compte-de-garde@example.test'],
        null,
        [
            'PATH' => $atelier . '/bin:' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'GARDE_VERDICT' => $verdict,
            'GARDE_CODE' => (string) $codeConteneur,
        ],
        // Le script refuse un mot de passe de moins de douze caractères, et
        // refuse aussi un terminal : on lui parle par l'entrée standard.
        'un-mot-de-passe-assez-long',
        60,
    );
    $processus->run();

    $sortie = $processus->getOutput() . $processus->getErrorOutput();

    // Ménage : le script factice n'a servi qu'à cette mesure.
    @unlink($atelier . '/bin/docker');
    @unlink($atelier . '/bin/id');
    @rmdir($atelier . '/bin');
    @rmdir($atelier);

    return ['sortie' => $sortie, 'code' => $processus->getExitCode() ?? -1];
}

test('P5-35-005 — TEMOIN : le banc voit bien le script', function () {
    $chemin = cheminScriptMotDePasse();
    expect(file_exists($chemin))->toBeTrue(
        "Le banc ne voit pas {$chemin}. Une garde qui n'a aucun fichier à exercer passe au vert " .
        'sans rien prouver : monte `infra/` avant de la croire.',
    );
    expect(filesize($chemin))->toBeGreaterThan(1000);
});

test('P5-35-004 — un compte inexistant le DIT a l operateur', function () {
    $r = jouerScriptMotDePasse('A35_INTROUVABLE', 1);

    expect($r['sortie'])->not->toBe(
        '',
        "Le script est mort en silence. C'est le geste par lequel l'exploitant reprend l'accès " .
        "à son produit : une faute de frappe sur l'adresse doit produire un message, pas un code 1 muet.",
    );
    expect(strtolower($r['sortie']))->toContain('aucun compte');
});

test('P5-35-004 — un mot de passe qui n arrive pas au conteneur le DIT', function () {
    $r = jouerScriptMotDePasse('A35_ECHEC_MDP_VIDE', 1);

    expect($r['sortie'])->not->toBe('', 'Le script est mort en silence sur un tube vide.');
    expect(strtolower($r['sortie']))->toContain('mot de passe');
});

test('P5-35-004 — une sortie inattendue rend le diagnostic brut', function () {
    $r = jouerScriptMotDePasse('PHP Fatal error: quelque chose a casse', 255);

    expect($r['sortie'])->not->toBe('', 'Le script est mort en silence sur une sortie imprévue.');
    // Le bloc de diagnostic existe précisément pour ce cas : il doit apparaître.
    expect($r['sortie'])->toContain('sortie brute');
});

/**
 * TÉMOIN POSITIF — la garde doit distinguer « le script se tait » de « le
 * script parle toujours ». Sans ce cas, un correctif qui imprimerait le même
 * message partout la satisferait aussi.
 */
test('P5-35-004 — TEMOIN : le succes reste un succes, et ne dit pas l echec', function () {
    $r = jouerScriptMotDePasse('A35_OK', 0);

    expect($r['sortie'])->toContain('OK : mot de passe défini');
    expect(strtolower($r['sortie']))->not->toContain('aucun compte');
    expect($r['code'])->toBe(0);
});

/**
 * TÉMOIN DE NON-RÉGRESSION sur F35-014 — le défaut que le `set -e` corrigeait
 * ne doit pas revenir. Le verdict se lit sur la DERNIÈRE ligne, à l'égalité
 * stricte : une bannière contenant les lettres « OK » ne doit pas déclencher
 * la branche de succès.
 */
test('F35-014 — TEMOIN : une banniere contenant OK ne passe pas pour un succes', function () {
    $r = jouerScriptMotDePasse("Psy Shell v0.12 (PHP 8.3) — tapez help\nA35_INTROUVABLE", 1);

    expect($r['sortie'])->not->toContain('OK : mot de passe défini');
    expect(strtolower($r['sortie']))->toContain('aucun compte');
});

/**
 * F35-007 — LE SECRET NE PASSE QUE PAR L'ENTREE STANDARD.
 *
 * CE QUI MANQUAIT, ET QUI EST LE CONSTAT P5-35-005.
 *
 * Le correctif F35-007 a ete pose le 2026-08-19 : le script transmettait le mot
 * de passe par `docker exec -e CRM_MDP="$MDP"`, c'est-a-dire comme ARGUMENT de
 * la commande `docker`. Il apparaissait donc en clair dans la ligne de commande
 * du processus, lisible par TOUT utilisateur du serveur via `ps -ef` ou
 * `/proc/<pid>/cmdline`, pendant toute la duree du `docker exec`.
 *
 * Mesure du 2026-08-22 : le correctif est bien en place (`definir-mot-de-passe-crm.sh:147`
 * fait `printf '%s' "$MDP" | docker exec -i …`), mais il n'avait AUCUNE GARDE.
 * La seule mention de F35-007 dans `backend/tests/` etait un commentaire —
 * `DiagnosticConnexionSansEcritureTest.php:15` — qui parle du defaut sans
 * l'inspecter. Un commentaire n'a jamais rougi.
 *
 * POURQUOI UNE LECTURE STATIQUE, ET PAS UNE EXECUTION.
 *
 * Les gardes ci-dessus jouent le script contre un faux `docker` : elles
 * mesurent ce qu'il DIT. Ici on mesure ce qu'il TRANSMET, et un faux `docker`
 * ne peut pas en temoigner — il verrait les arguments qu'on lui donne, pas
 * l'exposition dans la table des processus. La forme de la commande est donc
 * ce qu'il faut lire, et elle se lit dans le fichier.
 *
 * ⚠️ Les COMMENTAIRES sont ecartes a dessein. L'en-tete du script et le code PHP
 * qu'il ecrit citent tous deux `docker exec -e CRM_MDP=...` pour expliquer le
 * defaut corrige (l.31 et l.85). Une garde qui les compterait rougirait sur la
 * documentation de son propre correctif.
 */
test('F35-007 — le mot de passe ne figure dans AUCUN argument de docker exec', function () {
    $source = (string) file_get_contents(cheminScriptMotDePasse());

    $fautives = [];
    foreach (explode("\n", $source) as $index => $ligne) {
        $nu = ltrim($ligne);
        // Commentaires shell (`#`) et commentaires du PHP embarque (`//`).
        if ($nu === '' || str_starts_with($nu, '#') || str_starts_with($nu, '//')) {
            continue;
        }
        $position = strpos($ligne, 'docker exec');
        if ($position === false) {
            continue;
        }

        // On ne regarde qu'APRES `docker exec` : le tube qui alimente son
        // entree standard (`printf '%s' "$MDP" | docker exec …`) est ecrit
        // AVANT, et il est precisement la bonne forme.
        $arguments = substr($ligne, $position);
        if (preg_match('/\bMDP\b|MOT_DE_PASSE|PASSWORD/i', $arguments) === 1) {
            $fautives[] = 'l.' . ($index + 1) . ' : ' . trim($ligne);
        }
    }

    expect($fautives === [])->toBeTrue(
        "Le mot de passe repasse par la ligne de commande de `docker exec` :\n" .
        implode("\n", $fautives) . "\n" .
        'Tout utilisateur du serveur le lit alors dans `ps -ef` ou /proc/<pid>/cmdline ' .
        'pendant la duree de la commande (constat F35-007). Geste : le remettre sur ' .
        'l\'ENTREE STANDARD — `printf \'%s\' "$MDP" | docker exec -i … ` — et laisser le ' .
        'code PHP le lire par `stream_get_contents(STDIN)`.',
    );
});

/**
 * TEMOIN POSITIF — sans lui, un script qui aurait CESSE de transmettre le mot
 * de passe satisferait aussi la garde ci-dessus. « Aucun secret en argument »
 * est trivialement vrai d'un script qui n'envoie plus rien.
 */
test('F35-007 — TEMOIN : le mot de passe arrive bien au conteneur, par un tube', function () {
    $source = (string) file_get_contents(cheminScriptMotDePasse());

    expect(preg_match('/\$MDP"?\s*\|.*docker exec\s+(-\w+\s+)*-i\b/', $source) === 1)->toBeTrue(
        'Aucun tube n\'amene `$MDP` a l\'entree standard d\'un `docker exec -i`. Soit le ' .
        'script ne transmet plus le mot de passe du tout — et il ne rend plus l\'acces au ' .
        'CRM — soit il le transmet autrement, et la garde F35-007 ci-dessus ne mesure plus ' .
        'rien. `-i` est indispensable : sans lui, `docker exec` ne relaie pas stdin. ' .
        'Geste : verifier la ligne `SORTIE="$(printf \'%s\' "$MDP" | docker exec -i …)"`.',
    );
});

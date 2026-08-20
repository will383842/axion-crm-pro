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
        60
    );
    $processus->run();

    $sortie = $processus->getOutput() . $processus->getErrorOutput();

    // Ménage : le script factice n'a servi qu'à cette mesure.
    @unlink($atelier . '/bin/docker');
    @rmdir($atelier . '/bin');
    @rmdir($atelier);

    return ['sortie' => $sortie, 'code' => $processus->getExitCode() ?? -1];
}

test('P5-35-005 — TEMOIN : le banc voit bien le script', function () {
    $chemin = cheminScriptMotDePasse();
    expect(file_exists($chemin))->toBeTrue(
        "Le banc ne voit pas {$chemin}. Une garde qui n'a aucun fichier à exercer passe au vert " .
        'sans rien prouver : monte `infra/` avant de la croire.'
    );
    expect(filesize($chemin))->toBeGreaterThan(1000);
});

test('P5-35-004 — un compte inexistant le DIT a l operateur', function () {
    $r = jouerScriptMotDePasse('A35_INTROUVABLE', 1);

    expect($r['sortie'])->not->toBe(
        '',
        "Le script est mort en silence. C'est le geste par lequel l'exploitant reprend l'accès " .
        "à son produit : une faute de frappe sur l'adresse doit produire un message, pas un code 1 muet."
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

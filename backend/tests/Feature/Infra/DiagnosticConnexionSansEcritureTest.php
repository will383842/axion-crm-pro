<?php

/**
 * GARDE DU DIAGNOSTIC DE CONNEXION — il doit rester en LECTURE SEULE.
 *
 * `infra/scripts/diagnostiquer-connexion-crm.sh` est destiné à être joué **sur
 * la production**, par un exploitant qui ne peut plus entrer dans son propre
 * produit. Il promet, en tête, de ne rien changer.
 *
 * 🔑 **Une promesse écrite en commentaire n'est pas une garantie.** Le jour où
 * quelqu'un ajoutera « pendant qu'on y est, déverrouillons le compte », le
 * script cessera d'être sûr à jouer, et son en-tête continuera d'affirmer le
 * contraire. C'est exactement le motif que l'audit 360° poursuit depuis deux
 * jours : `definir-mot-de-passe-crm.sh` affirmait ne pas passer le mot de passe
 * en argument alors qu'il le faisait (F35-007), et son en-tête l'a fait croire
 * pendant des semaines.
 *
 * Cette garde tient la promesse à sa place.
 *
 * ⚠️ Le script AFFICHE des commandes d'écriture, dans sa section « ce qu'il faut
 * faire » — c'est son office : il conseille sans agir. La garde distingue donc
 * ce qui est **exécuté** de ce qui est **imprimé**, et c'est toute sa finesse.
 * Un contrôle naïf qui chercherait `UPDATE` n'importe où rougirait sur le
 * conseil et empêcherait de le donner.
 */

use Tests\TestCase;

uses(TestCase::class);

function cheminDiagnostic(): string
{
    return (realpath(base_path('..')) ?: base_path('..'))
        . '/infra/scripts/diagnostiquer-connexion-crm.sh';
}

/**
 * Les lignes RÉELLEMENT exécutées : on retire les commentaires et tout ce qui
 * n'est qu'affiché par `dit`.
 *
 * @return list<string>
 */
function lignesExecutees(string $source): array
{
    $out = [];

    foreach (explode("\n", $source) as $ligne) {
        $t = ltrim($ligne);

        if ($t === '' || str_starts_with($t, '#')) {
            continue;
        }

        // `dit ...` et `titre ...` n'exécutent rien : ils impriment. C'est là
        // que vivent les commandes CONSEILLÉES à l'opérateur.
        if (preg_match('/^(dit|titre)\b/', $t) === 1) {
            continue;
        }

        $out[] = $t;
    }

    return $out;
}

test('diagnostic — TEMOIN : le banc voit bien le script', function () {
    $chemin = cheminDiagnostic();

    expect(file_exists($chemin))->toBeTrue(
        "Le banc ne voit pas {$chemin}. Une garde qui n'a rien a inspecter passe au vert "
        . 'sans rien prouver : monte la racine du depot avant de la croire.'
    );
    expect(filesize($chemin))->toBeGreaterThan(2000);
});

test('diagnostic — TEMOIN NEGATIF : le filtre SAIT distinguer l execute de l imprime', function () {
    $fabrique = "#!/usr/bin/env bash\n"
        . "# UPDATE dans un commentaire\n"
        . "dit \"     UPDATE users SET locked_until = NULL;\"\n"
        . "psql -c \"UPDATE users SET x = 1;\"\n";

    $executees = implode("\n", lignesExecutees($fabrique));

    // Ce qui est imprimé ou commenté ne doit PAS ressortir...
    expect($executees)->not->toContain('locked_until');
    // ...et ce qui est exécuté doit ressortir, sinon la garde ci-dessous ne
    // prouverait rien du tout.
    expect($executees)->toContain('SET x = 1');
});

test('le diagnostic de connexion n EXECUTE aucune ecriture', function () {
    $source = (string) file_get_contents(cheminDiagnostic());
    $executees = lignesExecutees($source);

    // Motifs SANS LETTRE ACCENTUEE, par principe : un contrôle en français joué
    // sur une sous-chaîne accentuée rend zéro et ce zéro ment.
    $mutations = ['UPDATE ', 'INSERT ', 'DELETE ', 'ALTER ', 'DROP ', 'TRUNCATE ', 'CREATE '];

    $fautives = [];
    foreach ($executees as $i => $ligne) {
        foreach ($mutations as $m) {
            if (stripos($ligne, $m) !== false) {
                $fautives[] = $m . ' -> ' . $ligne;
            }
        }
    }

    expect($fautives)->toBe(
        [],
        "Ce script est joue SUR LA PRODUCTION par quelqu'un qui ne peut plus entrer dans son "
        . "produit, et son en-tete promet qu'il ne change rien. Ces lignes l'executeraient :\n  - "
        . implode("\n  - ", $fautives)
        . "\n\nUne promesse ecrite en commentaire n'est pas une garantie. Si le geste est "
        . 'necessaire, il doit etre CONSEILLE (via `dit`) et joue a la main, jamais execute ici.'
    );
});

test('le diagnostic reste lisible meme quand tout echoue', function () {
    $source = (string) file_get_contents(cheminDiagnostic());

    // Constat P5-35-004, paye sur le script voisin : sous `set -e`, une
    // substitution de commande qui rend un code non nul TUE le script, et
    // l'operateur ne voit RIEN. Ce script-ci ne doit pas porter `-e`.
    expect($source)->not->toContain('set -euo pipefail');
    $this->assertStringContainsString(
        'set -uo pipefail',
        $source,
        "Le script doit tourner SANS `-e` : sur un serveur ou tout va mal, chacune de ses "
        . "sondes peut echouer, et il doit continuer pour dire POURQUOI. Un diagnostic qui "
        . 'meurt au premier obstacle ne diagnostique rien.'
    );

    // Et il doit dire, noir sur blanc, ce que sa derniere ligne promet.
    //
    // ⚠️ Motif SANS LETTRE ACCENTUEE, et il a fallu le payer : la premiere
    // version cherchait « n'a rien ecrit » dans un script qui ecrit, en bon
    // francais, « n'a rien ecrit » avec un accent aigu. Zero resultat, et ce
    // zero mentait -- la phrase etait bien la. C'est la CINQUIEME fois de la
    // session que cette regle se paie. On ne francise donc pas le script pour
    // arranger la garde : on coupe le motif avant l'accent.
    $this->assertStringContainsString(
        "Ce script n'a rien ",
        $source,
        'La derniere ligne doit reaffirmer la promesse a celui qui vient de la lire tourner.'
    );
});

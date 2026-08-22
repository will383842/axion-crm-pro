<?php

/**
 * GARDE : LE SOMMAIRE DE LA SPEC DIT LE VRAI — constat A09-011 (S3).
 *
 * CE QUI ETAIT MESURE, LE 2026-08-22.
 *
 * `spec/00_INDEX.md` se trompait sur trois choses a la fois, et chacune se
 * verifie mecaniquement :
 *
 *  1. STATUT. « **Statut :** Spec exhaustive — implementation a venir. » alors
 *     que `backend/` et `frontend/` tournent en production depuis le
 *     2026-05-17. Un lecteur pressé en concluait qu'il n'y avait rien a casser.
 *  2. COMPTE. Le titre annoncait « Sommaire des 24 fichiers » quand le tableau
 *     en enumerait 25 (et que 25 fichiers numerotes existent sur le disque).
 *  3. TAILLES. Une colonne « Lignes » recopiee a la main, fausse jusqu'a un
 *     facteur 2,44 : `13_ui_admin_phase1.md` annonce ~1500, mesure 614 ;
 *     `22_risques_mitigations.md` annonce ~700, mesure 194.
 *
 * POURQUOI UNE GARDE, POUR UN SIMPLE DOCUMENT.
 *
 * Les trois defauts ont la MEME cause : des nombres recopies a la main dans un
 * document que personne ne remesure. Corriger les nombres sans poser de garde,
 * c'est reconduire exactement l'etat qui a produit le constat — le sommaire
 * redeviendra faux au prochain fichier ajoute, et personne ne le verra.
 *
 * CE QU'ELLE N'INSPECTE PAS, ET LE DIT.
 *
 * Elle ne juge PAS le contenu de la spec, ni sa justesse vis-a-vis du code : la
 * spec est figee au 2026-05-16 et l'etat reel du produit se lit dans
 * `ARCHITECTURE.md`. Elle verifie seulement que le sommaire decrit le dossier
 * qu'il pretend sommer.
 *
 * ⚠️ PIEGE DE BANC, deja paye ailleurs dans ce depot : `RecursiveDirectoryIterator`
 * TRONQUE le parcours sur ce montage Docker (14 fichiers vus sur 56 mesures).
 * `spec/` est un dossier PLAT — un `scandir` suffit, et ne ment pas.
 */

use Tests\TestCase;

uses(TestCase::class);

function cheminSpecA09011(): string
{
    return (realpath(base_path('..')) ?: base_path('..')) . '/spec';
}

/**
 * Les fichiers de spec NUMEROTES presents sur le disque, tries.
 *
 * `AUDIT_v1.md` n'est pas numerote : il ne fait pas partie de la suite ordonnee
 * que le sommaire enumere, et il n'a donc pas a y figurer.
 *
 * @return list<string>
 */
function fichiersSpecSurDisque(): array
{
    $entrees = scandir(cheminSpecA09011());
    if ($entrees === false) {
        return [];
    }

    $fichiers = array_values(array_filter(
        $entrees,
        fn (string $nom) => preg_match('/^\d{2}_.+\.md$/', $nom) === 1,
    ));
    sort($fichiers);

    return $fichiers;
}

/**
 * Les fichiers cites par le tableau du sommaire, dans l'ordre du document.
 *
 * On lit la premiere paire de barres obliques inverses de chaque ligne de
 * tableau : `| 04 | \`03_db_schema_phase1.md\` | … |`.
 *
 * @return list<string>
 */
function fichiersCitesParLeSommaire(string $index): array
{
    preg_match_all('/^\|\s*\d+\s*\|\s*`([^`]+\.md)`/m', $index, $captures);

    return $captures[1];
}

test('A09-011 — TEMOIN : le banc voit bien le dossier spec/', function () {
    $chemin = cheminSpecA09011();

    expect(is_dir($chemin))->toBeTrue(
        "Le banc ne voit pas {$chemin}. Une garde qui n'a aucun dossier a parcourir passe " .
        'au vert sans rien prouver : monte la racine du depot dans le conteneur de tests ' .
        'avant de la croire.',
    );

    // Une seconde face au temoin : `scandir` doit rendre un nombre PLAUSIBLE.
    // Un parcours tronque (le piege deja paye avec RecursiveDirectoryIterator)
    // rendrait une poignee de fichiers, et toutes les egalites ci-dessous
    // seraient satisfaites par un tableau tronque lui aussi.
    expect(count(fichiersSpecSurDisque()) >= 20)->toBeTrue(
        'Le parcours de spec/ rend moins de 20 fichiers numerotes. Mesure du 2026-08-22 : ' .
        'il y en a 25. Un parcours tronque ferait passer cette garde pour verte sur un ' .
        'sommaire lui aussi amputé. Geste : verifier le montage de spec/ dans le conteneur.',
    );
});

test('A09-011 — le sommaire enumere EXACTEMENT les fichiers presents sur le disque', function () {
    $index = (string) file_get_contents(cheminSpecA09011() . '/00_INDEX.md');

    $surDisque = fichiersSpecSurDisque();
    $cites = fichiersCitesParLeSommaire($index);
    sort($cites);

    $oublies = array_values(array_diff($surDisque, $cites));
    $fantomes = array_values(array_diff($cites, $surDisque));

    expect($oublies === [])->toBeTrue(
        'spec/00_INDEX.md n\'enumere pas : ' . implode(', ', $oublies) . '. ' .
        'Un sommaire incomplet fait disparaitre un fichier de spec pour tout lecteur qui ' .
        'le prend pour la liste complete. Geste : ajouter la ou les lignes manquantes dans ' .
        'le tableau du bloc correspondant, et corriger le titre « Sommaire des N fichiers ».',
    );

    expect($fantomes === [])->toBeTrue(
        'spec/00_INDEX.md cite des fichiers qui n\'existent plus : ' . implode(', ', $fantomes) . '. ' .
        'Geste : retirer ces lignes du tableau, et corriger le titre « Sommaire des N fichiers ».',
    );
});

test('A09-011 — le compte annonce dans le titre est le compte REEL', function () {
    $index = (string) file_get_contents(cheminSpecA09011() . '/00_INDEX.md');

    expect(preg_match('/^## Sommaire des (\d+) fichiers/m', $index, $capture) === 1)->toBeTrue(
        'Le titre « ## Sommaire des N fichiers » a disparu de spec/00_INDEX.md. ' .
        'Cette garde ne peut plus verifier le compte annonce. Geste : le remettre, ou ' .
        'adapter cette garde si le sommaire a change de forme.',
    );

    $annonce = (int) $capture[1];
    $reel = count(fichiersSpecSurDisque());

    expect($annonce === $reel)->toBeTrue(
        "Le sommaire annonce {$annonce} fichiers ; il y en a {$reel} dans spec/. " .
        'C\'est exactement le constat A09-011 : le titre disait 24 quand le tableau en ' .
        'enumerait 25. Geste : corriger le nombre dans « ## Sommaire des N fichiers » ' .
        '(et dans le champ « **Format :** N fichiers Markdown » de l\'en-tete).',
    );

    // Le champ « Format » de l'en-tete porte le MEME nombre. Sans ce controle,
    // on corrigerait un des deux et pas l'autre — ce qui est arrive.
    expect(str_contains($index, "**Format :** {$reel} fichiers Markdown"))->toBeTrue(
        "L'en-tete de spec/00_INDEX.md n'annonce pas « **Format :** {$reel} fichiers " .
        'Markdown ». Deux comptes dans le meme document doivent dire la meme chose. ' .
        'Geste : aligner le champ « Format » sur le titre du sommaire.',
    );
});

test('A09-011 — la spec ne se declare plus « implementation a venir »', function () {
    $index = (string) file_get_contents(cheminSpecA09011() . '/00_INDEX.md');

    // La phrase reste tolerée dans l'encart de rectification, qui RACONTE
    // l'erreur passee ; ce qui est interdit, c'est de la porter comme STATUT.
    expect(preg_match('/^> \*\*Statut :\*\*.*implémentation à venir/mu', $index) === 0)->toBeTrue(
        'spec/00_INDEX.md se declare de nouveau « implementation a venir » alors que ' .
        'backend/ et frontend/ sont en production depuis le 2026-05-17 (constat A09-011). ' .
        'Un lecteur en conclut qu\'il n\'y a rien a casser. Geste : le statut doit dire que ' .
        'la spec est implementee et renvoyer a ARCHITECTURE.md pour l\'etat reel du produit.',
    );
});

/**
 * Le troisieme volet du constat : les TAILLES.
 *
 * La colonne « Lignes » a ete retiree plutot que remesuree — une colonne de
 * tailles recopiee a la main se perime a chaque commit, et c'est precisement
 * ainsi qu'elle en est venue a se tromper d'un facteur 2,44.
 *
 * Cette garde ne juge pas d'un ecart tolerable (choisir un seuil serait
 * trancher a la place de l'humain) : elle interdit la colonne, et son message
 * dit par quoi la remplacer.
 */
test('A09-011 — le sommaire ne recopie pas a la main des tailles de fichiers', function () {
    $index = (string) file_get_contents(cheminSpecA09011() . '/00_INDEX.md');

    // On ne cherche QUE dans les en-tetes de tableau : le mot « Lignes » dans
    // une phrase de prose (l'encart qui explique ce retrait, par exemple) n'est
    // pas une colonne.
    $enTetesAvecTailles = preg_match('/^\|[^\n]*\|\s*Lignes\s*\|/m', $index);

    expect($enTetesAvecTailles === 0)->toBeTrue(
        'Une colonne « Lignes » est revenue dans le sommaire de la spec. Mesure du ' .
        '2026-08-22 : la precedente annoncait ~1500 lignes pour 13_ui_admin_phase1.md qui ' .
        'en comptait 614 (facteur 2,44), et ~700 pour 22_risques_mitigations.md qui en ' .
        'comptait 194. Un nombre recopie a la main dans un document que personne ne ' .
        'remesure devient faux, puis trompeur. Geste : retirer la colonne — le compte ' .
        'exact se prend d\'un `wc -l spec/*.md`, il n\'a pas a vivre dans le sommaire.',
    );
});

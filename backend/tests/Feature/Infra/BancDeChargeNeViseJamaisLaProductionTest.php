<?php

/**
 * GARDE — G43-007 (S2) : LE MODE D'EMPLOI DU BANC DE CHARGE NE VISE PAS LA
 * PRODUCTION, ET NE NOMME PAS UN COMPTE HUMAIN.
 *
 * ── CE QUI A ETE MESURE, LE 2026-08-22 ──────────────────────────────────────
 *
 * `load-tests/LOAD-TEST-RUNBOOK.md` prescrivait, en preparation obligatoire :
 *
 *     curl -X POST https://app.axion-crm-pro.com/api/v1/auth/login \
 *       -d '{"email":"<adresse personnelle du dirigeant>","password":"..."}'
 *     export LOAD_TEST_TARGET='https://app.axion-crm-pro.com'
 *
 * puis une phase « sustained » de 300 s a 20 req/s — 6 000 requetes envoyees a
 * la PRODUCTION, authentifiees avec le compte le plus privilegie du produit, et
 * un mot de passe colle en clair dans l'historique du shell.
 *
 * Le banc n'avait par ailleurs JAMAIS tourne : `load-tests/` ne contenait que
 * deux fichiers (aucun `results/`), et `artillery` n'apparaissait dans aucun
 * `package.json` ni aucun workflow. Un mode d'emploi jamais joue est exactement
 * celui qu'un lecteur consciencieux suivra A LA LETTRE le jour ou il s'y met.
 *
 * ── CE QUE CETTE GARDE MESURE, ET CE QU'ELLE NE MESURE PAS ──────────────────
 *
 * Elle LIT les deux fichiers de `load-tests/` et verifie quatre choses :
 *   1. aucune URL de production (schema + domaine) n'y figure ;
 *   2. toute adresse e-mail citee est un compte de service jetable en
 *      `.invalid` — donc jamais une boite reelle, jamais celle d'un humain ;
 *   3. aucun mot de passe litteral : le champ `password` d'un `curl` doit
 *      porter une variable de shell, pas une valeur ;
 *   4. le runbook porte toujours son REFUS explicite de la production.
 *
 * ⚠️ ELLE NE PROUVE PAS que personne ne charge la production : rien n'empeche
 * un exploitant d'exporter la main ce qu'il veut. Elle prouve que le DEPOT ne
 * le lui demande plus. C'est le seul terrain ou un test a prise.
 *
 * ⚠️ PIEGE DE BANC, deja documente par `DocsDeDeploiementDisentLeVraiTest` : le
 * conteneur de mesure ne monte que `backend/`. Deposer `load-tests/` avant la
 * mesure, sinon les temoins de non-vacuite ci-dessous rougissent :
 *
 *     docker cp load-tests a35r:/var/www/
 *
 * En CI le probleme n'existe pas : `actions/checkout` pose l'arbre entier.
 */

use Tests\TestCase;

uses(TestCase::class);

/** La racine du depot : `load-tests/` vit AU-DESSUS de l'application Laravel. */
function racineDepotG43007(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les fichiers du banc de charge, nommes un par un.
 *
 * @return array<string, string>
 */
function fichiersBancDeChargeG43007(): array
{
    return [
        'runbook' => 'load-tests/LOAD-TEST-RUNBOOK.md',
        'scenario' => 'load-tests/audience-refresh.yml',
    ];
}

test('G43-007 — le banc de charge ne prescrit ni la production, ni un compte humain, ni un mot de passe en clair', function () {
    $racine = racineDepotG43007();

    // ── TEMOINS DE NON-VACUITE ──────────────────────────────────────────────
    // Sans eux, un `load-tests/` absent (ou renomme) rendrait toutes les
    // assertions vertes sans qu'une seule ligne ait ete lue.
    $contenus = [];
    foreach (fichiersBancDeChargeG43007() as $cle => $relatif) {
        $chemin = $racine . '/' . $relatif;
        $this->assertFileExists(
            $chemin,
            $relatif . ' introuvable : la garde G43-007 n aurait rien inspecte. Racine lue : ' . $racine
            . '. GESTE : si le banc a ete SUPPRIME (c est l une des deux issues prevues par le '
            . 'runbook), supprimer aussi ce test ; s il a seulement ete deplace, corriger '
            . 'fichiersBancDeChargeG43007().',
        );
        $contenus[$cle] = (string) file_get_contents($chemin);
    }

    // ── 1. AUCUNE URL DE PRODUCTION ─────────────────────────────────────────
    // On cherche la forme EXECUTABLE (schema + domaine). Le runbook a le droit
    // de NOMMER le domaine — il doit meme le faire, pour le refuser — mais pas
    // de fournir une URL qu'un copier-coller envoie telle quelle.
    foreach ($contenus as $cle => $contenu) {
        expect(preg_match('#https?://[^\s\'"`]*app\.axion-crm-pro\.com#i', $contenu) === 1)->toBeFalse(
            'G43-007 : ' . fichiersBancDeChargeG43007()[$cle] . ' contient de nouveau une URL de '
            . "PRODUCTION executable.\n\n"
            . 'Mesure du 2026-08-22 : ce fichier prescrivait `export '
            . 'LOAD_TEST_TARGET=<url de prod>` puis une phase de 300 s a 20 req/s — 6 000 '
            . "requetes sur la base des clients, et des lignes creees qui y restent.\n\n"
            . 'GESTE : viser un environnement de charge dedie via `$LOAD_TEST_TARGET`. Le '
            . 'domaine de production ne doit apparaitre que dans le bloc de REFUS, sans '
            . 'schema `https://`.',
        );
    }

    // ── 2. AUCUNE ADRESSE REELLE ────────────────────────────────────────────
    // Un compte de service jetable se nomme en `.invalid` (RFC 6761 : ce TLD ne
    // resout jamais). Toute autre adresse est, par construction, la boite de
    // quelqu'un — c'etait celle du dirigeant.
    preg_match_all(
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        implode("\n", $contenus),
        $adresses,
    );
    $reelles = array_values(array_filter(
        array_unique($adresses[0]),
        static fn (string $adresse): bool => ! str_ends_with(strtolower($adresse), '.invalid'),
    ));

    expect($reelles === [])->toBeTrue(
        'G43-007 : adresse(s) e-mail reelle(s) dans load-tests/ — ' . implode(', ', $reelles) . ". \n\n"
        . 'Le runbook nommait l adresse personnelle du dirigeant dans le `curl` de connexion : '
        . "son jeton porte TOUS les droits, et l adresse finit dans les journaux de charge.\n\n"
        . 'GESTE : creer un compte de service jetable SUR l environnement de charge et le '
        . 'nommer en `.invalid` (ex. `load-test@axion-ia.invalid`), ou passer l adresse par '
        . '`$LOAD_TEST_EMAIL` sans l ecrire dans le document.',
    );

    // TEMOIN de la regle ci-dessus : si plus AUCUNE adresse n'est citee, la
    // regle 2 devient vraie sans rien avoir inspecte. On exige donc qu'au moins
    // le compte de service jetable soit toujours la, pour que la regle porte.
    expect(count($adresses[0]) > 0)->toBeTrue(
        'G43-007 : plus aucune adresse e-mail dans load-tests/ — la regle « toute adresse est '
        . 'un compte jetable en .invalid » ne verifie donc plus rien. GESTE : garder dans le '
        . 'runbook l exemple de compte de service (`load-test@axion-ia.invalid`), qui est '
        . 'aussi ce qui apprend au lecteur quel compte employer.',
    );

    // ── 3. AUCUN MOT DE PASSE LITTERAL ──────────────────────────────────────
    // Un champ `password` dont la valeur ne commence pas par `$` est une valeur
    // ecrite en dur — y compris un « ...changeme... », qui invite a coller le
    // vrai a sa place, dans un fichier suivi par git.
    foreach ($contenus as $cle => $contenu) {
        // Les `curl` du runbook sont ecrits dans un JSON echappe
        // (`\"password\":\"…\"`) : on retire les antislashs avant de lire, sinon
        // la regexp passerait a cote de la forme meme qui a pose probleme.
        preg_match_all(
            '/["\']password["\']\s*:\s*["\']([^"\']*)["\']/',
            str_replace('\\', '', $contenu),
            $motsDePasse,
        );
        $litteraux = array_values(array_filter(
            $motsDePasse[1],
            static fn (string $valeur): bool => ! str_starts_with($valeur, '$'),
        ));

        expect($litteraux === [])->toBeTrue(
            'G43-007 : ' . fichiersBancDeChargeG43007()[$cle] . ' porte un champ `password` dont la '
            . 'valeur n est pas une variable de shell — trouve : ' . implode(', ', $litteraux) . ".\n\n"
            . 'GESTE : lire le mot de passe a la saisie (`read -rs LOAD_TEST_PASSWORD`) et '
            . 'l interpoler (`\\"password\\":\\"$LOAD_TEST_PASSWORD\\"`). Un mot de passe ecrit '
            . 'dans un fichier suivi par git est un secret publie, meme remplace par un '
            . 'marqueur : le marqueur invite a coller le vrai a sa place.',
        );
    }

    // ── 4. LE REFUS EST TOUJOURS LA ─────────────────────────────────────────
    // C'est la seule ligne du document qui empeche reellement l accident : si
    // elle disparait, le reste n est plus que de la prose.
    $runbook = $contenus['runbook'];
    foreach (['LOAD_TEST_TARGET', 'REFUS'] as $marqueur) {
        expect(str_contains($runbook, $marqueur))->toBeTrue(
            'G43-007 : le bloc de refus a disparu du runbook (marqueur « ' . $marqueur . " » absent).\n\n"
            . 'Sans lui, le document redevient un mode d emploi qui laisse deviner la cible — '
            . "et la cible devinee, la derniere fois, etait la production.\n\n"
            . 'GESTE : restaurer la section « Refus explicite de la production » : refus si '
            . '`$LOAD_TEST_TARGET` est vide, refus s il contient le domaine de production.',
        );
    }
});

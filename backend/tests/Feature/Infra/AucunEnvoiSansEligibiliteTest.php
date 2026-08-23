<?php

use App\Support\EligibiliteCampagne;
use Tests\TestCase;

uses(TestCase::class);

/**
 * GARDE ARCHITECTURALE — ON N'ÉCRIT PAS DANS `email_sends` SANS DEMANDER
 * L'ÉLIGIBILITÉ. Constat `B15-009`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QU'ELLE PROUVE AUJOURD'HUI — ET CE QU'ELLE NE PROUVE PAS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Mesure du 2026-08-22 : `ListeSuppression::estSupprimee()` n'a qu'UN SEUL
 * appelant applicatif — `EligibiliteCampagne.php:166`. Les autres mentions de
 * `ListeSuppression::` dans `app/` sont des écritures (webhook ZeptoMail) ou
 * l'empreinte partagée. Et aucun moteur d'envoi de campagne n'existe encore :
 * `email_sends` n'est écrite par AUCUN fichier de `app/`.
 *
 * ⚠️ DONC, DIT SANS DÉTOUR : cette garde ne trouve rien aujourd'hui, et elle ne
 * peut rien trouver. Elle n'atteste PAS que les envois sont filtrés — il n'y a
 * pas d'envoi. Ce qu'elle fait, c'est ARMER le lot L7 : le jour où le premier
 * moteur d'envoi écrira dans `email_sends`, il rougira tant qu'il n'aura pas
 * demandé `EligibiliteCampagne::peutRecevoir()`. Le risque de ce constat était
 * inverse du risque habituel — pas un défaut vivant, mais un lot qui se
 * construit sans que la garde soit dans sa définition de fini.
 *
 * Pour qu'un « zéro écrivain trouvé » ne soit pas un vert creux, deux témoins
 * l'encadrent : l'un prouve que le parcours de `app/` lit vraiment des
 * fichiers, l'autre que le DÉTECTEUR sait reconnaître une écriture quand on lui
 * en met une sous les yeux.
 *
 * ── Pourquoi une filtration en liste ne suffit pas, et pourquoi la porte est
 *    `peutRecevoir()` et pas `estSupprimee()` ────────────────────────────────
 *
 * `EligibiliteCampagne.php:135-145` le dit : une audience est une PHOTO. Entre
 * sa constitution et l'envoi, une opposition peut arriver. La question se
 * repose adresse par adresse juste avant d'écrire — et `peutRecevoir()` pose
 * les DEUX portes (opposition `opt_out` ET liste de suppression), là où
 * `estSupprimee()` n'en pose qu'une.
 */

/**
 * Les fichiers PHP de `app/`, en ne se fiant PAS à `RecursiveDirectoryIterator`.
 *
 * ⚠️ Mesure de cette campagne : cet itérateur a TRONQUÉ le parcours dans 14
 * gardes sur 56 — il rendait 42 fichiers sur 300 sans le dire. Une garde qui
 * inspecte moins de fichiers qu'elle ne croit passe au vert pour rien.
 *
 * @return list<string>
 */
function eligibiliteFichiersPhp(string $racine): array
{
    $trouves = [];
    $entrees = scandir($racine);

    if ($entrees === false) {
        return [];
    }

    foreach ($entrees as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }

        $chemin = $racine . DIRECTORY_SEPARATOR . $entree;

        if (is_dir($chemin)) {
            $trouves = array_merge($trouves, eligibiliteFichiersPhp($chemin));

            continue;
        }

        if (str_ends_with($entree, '.php')) {
            $trouves[] = $chemin;
        }
    }

    return $trouves;
}

/**
 * Le code d'un fichier PHP, commentaires ÔTÉS.
 *
 * ⚠️ On compte les APPELS, pas les mentions. Les fichiers de ce dépôt
 * EXPLIQUENT volontiers un défaut en citant sa forme fautive dans un
 * commentaire ; un grep naïf les accuserait tous. `token_get_all()` sait
 * séparer le code des commentaires, aucune expression régulière ne le sait sur
 * du PHP. (Idiome repris de `SimulacresLusHorsDeLaGardeTest`, qui l'a payé.)
 */
function eligibiliteCodeSansCommentaires(string $php): string
{
    $net = '';

    foreach (token_get_all($php) as $jeton) {
        if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $net .= is_array($jeton) ? $jeton[1] : $jeton;
    }

    return $net;
}

/**
 * Combien d'ÉCRITURES dans `email_sends` ce code contient-il ?
 *
 * On ne compte que les écritures, pas les lectures : un tableau de bord qui
 * COMPTE les envois n'a rien à demander à l'éligibilité, et le faire rougir
 * pousserait le premier venu à affaiblir la garde pour se débarrasser d'elle.
 */
function eligibiliteEcrituresEmailSends(string $code): int
{
    $formes = [
        // Constructeur de requêtes : DB::table('email_sends')->insert(...)
        '/DB::table\(\s*[\'"]email_sends[\'"]\s*\)\s*->\s*(insert|insertGetId|insertOrIgnore|update|updateOrInsert|upsert)\b/i',
        // SQL brut.
        '/insert\s+into\s+email_sends\b/i',
        // Modèle Eloquent, le jour où il existera.
        '/\bEmailSend::\s*(create|insert|forceCreate|firstOrCreate|updateOrCreate|upsert)\b/',
        '/\bnew\s+EmailSend\b/',
    ];

    $total = 0;
    foreach ($formes as $forme) {
        $total += (int) preg_match_all($forme, $code);
    }

    return $total;
}

// ── TÉMOIN 1 — le parcours lit-il seulement quelque chose ? ──────────────────

test('B15-009 — TÉMOIN : le parcours lit vraiment `app/`', function () {
    // Sans ce témoin, un `app/` introuvable rendrait une liste vide et
    // l'inventaire ci-dessous passerait au vert en n'ayant rien inspecté.
    $fichiers = eligibiliteFichiersPhp((string) realpath(app_path()));

    // Seuil posé SOUS le nombre mesuré (294 fichiers le 2026-08-21) : il attrape
    // une troncature grossière sans rougir à chaque fichier ajouté ou retiré.
    $this->assertGreaterThan(
        250,
        count($fichiers),
        'B15-009 : le parcours de `app/` ne rend que ' . count($fichiers) . ' fichiers PHP. '
        . 'Il est tronqué, ou la racine a bougé — la garde ci-dessous serait alors verte sans '
        . 'avoir rien lu. Geste : vérifier eligibiliteFichiersPhp() et app_path().',
    );

    $relatifs = array_map(
        static fn (string $c): string => str_replace('\\', '/', $c),
        $fichiers,
    );

    $vuLaFacade = false;
    foreach ($relatifs as $relatif) {
        if (str_ends_with($relatif, 'app/Support/EligibiliteCampagne.php')) {
            $vuLaFacade = true;
            break;
        }
    }

    $this->assertTrue(
        $vuLaFacade,
        'B15-009 : le parcours ne voit même pas `app/Support/EligibiliteCampagne.php`. '
        . "Il n'inspecte pas ce qu'il croit inspecter.",
    );
});

// ── TÉMOIN 2 — le détecteur reconnaît-il une écriture ? ──────────────────────

test('B15-009 — TÉMOIN : le détecteur reconnaît une écriture dans email_sends', function () {
    // Zéro écrivain aujourd'hui : sans ce témoin, la garde serait un vert creux
    // — verte parce qu'elle ne sait rien voir, et personne ne le saurait.
    $echantillons = [
        "DB::table('email_sends')->insert(['contact_id' => 1]);",
        'DB::table("email_sends")->updateOrInsert($cle, $valeurs);',
        'DB::statement("INSERT INTO email_sends (id) VALUES (1)");',
        'EmailSend::create($attributs);',
        '$envoi = new EmailSend();',
    ];

    foreach ($echantillons as $echantillon) {
        $this->assertGreaterThan(
            0,
            eligibiliteEcrituresEmailSends($echantillon),
            "B15-009 : le détecteur ne reconnaît PAS « {$echantillon} » comme une écriture dans "
            . 'email_sends. La garde principale serait verte devant un moteur d\'envoi non filtré. '
            . 'Geste : compléter les formes de eligibiliteEcrituresEmailSends().',
        );
    }

    // Et il ne doit pas hurler sur une LECTURE, sinon on l'affaiblira pour s'en
    // débarrasser.
    $this->assertSame(
        0,
        eligibiliteEcrituresEmailSends("DB::table('email_sends')->where('status', 'sent')->count();"),
        'B15-009 : le détecteur prend une lecture pour une écriture. Un faux positif sur un '
        . 'tableau de bord finira par faire désarmer la garde.',
    );
});

// ── LA GARDE ────────────────────────────────────────────────────────────────

test('B15-009 — tout écrivain de `email_sends` passe par EligibiliteCampagne::peutRecevoir()', function () {
    $this->assertTrue(
        method_exists(EligibiliteCampagne::class, 'peutRecevoir'),
        'B15-009 : `EligibiliteCampagne::peutRecevoir()` a disparu. La garde ci-dessous nomme une '
        . 'porte qui n\'existe plus : réécris-la sur la nouvelle porte AVANT de la laisser verte.',
    );

    $racine = (string) realpath(app_path());
    $fautifs = [];

    foreach (eligibiliteFichiersPhp($racine) as $chemin) {
        $relatif = str_replace('\\', '/', substr($chemin, strlen($racine) + 1));

        // La façade elle-même n'a évidemment pas à s'appeler.
        if ($relatif === 'Support/EligibiliteCampagne.php') {
            continue;
        }

        $code = eligibiliteCodeSansCommentaires((string) file_get_contents($chemin));

        if (eligibiliteEcrituresEmailSends($code) === 0) {
            continue;
        }

        if (! str_contains($code, 'peutRecevoir(')) {
            $fautifs[] = $relatif;
        }
    }

    sort($fautifs);

    $this->assertSame([], $fautifs, sprintf(
        'B15-009 : %d fichier(s) de `app/` écrivent dans `email_sends` sans demander '
        . "l'éligibilité :\n  - %s\n"
        . "Une audience est une PHOTO : entre sa constitution et l'envoi, une opposition peut "
        . "arriver (EligibiliteCampagne.php:135-145). Filtrer la liste ne suffit donc pas.\n"
        . 'GESTE : appeler `EligibiliteCampagne::peutRecevoir($email, $scope)` adresse par adresse '
        . "juste avant d'écrire la ligne d'envoi — et non `ListeSuppression::estSupprimee()`, qui "
        . "ne pose qu'UNE des deux portes (elle ignore `opt_out`).",
        count($fautifs),
        implode("\n  - ", $fautifs),
    ));
});

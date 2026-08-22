<?php

/**
 * GARDE : L'INVENTAIRE QUI FIXE L'ORDRE DES PIECES NE DECLARE PAS « VIVANTE »
 * UNE TABLE QUE PERSONNE N'ALIMENTE — constat I48-006 (S2).
 *
 * CE QUI ETAIT ECRIT. `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md`, §1 (« Ce
 * qui EXISTE et sert directement l'etape 1a — a etendre ») :
 *
 *     | **`notifications`** ... | base, 10 fichiers applicatifs |
 *       Vivante. Sert les rappels et les notifications de 1a. |
 *
 * CE QUI EST MESURE, le 2026-08-22, par grep de `'notifications'` sur
 * `backend/app`, `backend/routes`, `backend/config`, `backend/database/seeders` :
 * deux LECTEURS (`NotificationsController::index`, `GdprPortabilityService`),
 * deux SUPPRESSEURS (`RetentionPurge`, `GdprErasureService`), un surveillant
 * (`PentestSelfCheck`) — et **aucun ecrivain**. Pas un `insert`, pas de modele
 * Eloquent `Notification`, pas un `->notify()` (le trait `Notifiable` est
 * declare sur `App\Models\User` et jamais appele). `markRead()` et
 * `markAllRead()` repondent encore 501.
 *
 * POURQUOI CELA MERITE UNE GARDE. Cet inventaire n'est pas un compte rendu :
 * c'est lui qui FIXE L'ORDRE DES PIECES de l'etape 1a. Ranger `notifications`
 * en « a etendre » envoie l'auteur du lot etendre une table que rien ne
 * remplit ; il decouvre l'absence de producteur au milieu du travail, quand le
 * plan est deja ecrit autour. Un document qui ment est pire qu'un document
 * absent, parce qu'on le suit.
 *
 * CE QUE CETTE GARDE MESURE, ET DANS QUEL SENS. Elle compte les ecrivains dans
 * le code, puis exige que le document DISE ce qu'elle a compte. Elle rougit
 * donc dans les DEUX sens : si la ligne redevient « Vivante » sans producteur,
 * et si un producteur apparait sans que l'inventaire soit reclasse. Elle ne
 * pretend rien sur la table en base — elle inspecte des fichiers, et le temoin
 * de couverture ci-dessous verifie qu'elle en voit assez pour conclure.
 */

use PHPUnit\Framework\Assert;
use Tests\TestCase;

uses(TestCase::class);

const INVENTAIRE_1A_I48006 = '_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md';

/**
 * Parcours recursif A LA MAIN.
 *
 * `RecursiveDirectoryIterator` TRONQUE le parcours sur le montage Docker de ce
 * depot — mesure d'une campagne precedente : 14 fichiers rendus sur 56 reels.
 * Une garde qui balaye la moitie de l'arbre verdit sur ce qu'elle n'a pas lu.
 */
function fichiersPhpI48006(string $racine): array
{
    if (! is_dir($racine)) {
        return [];
    }

    $trouves = [];
    foreach (scandir($racine) ?: [] as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }
        $chemin = $racine . DIRECTORY_SEPARATOR . $entree;
        if (is_dir($chemin)) {
            $trouves = array_merge($trouves, fichiersPhpI48006($chemin));
        } elseif (str_ends_with($entree, '.php')) {
            $trouves[] = $chemin;
        }
    }

    return $trouves;
}

/**
 * Le contenu ECRIT-IL dans `notifications` ?
 *
 * On ne compte que les verbes qui CREENT une ligne. Un `update` de `read_at`
 * (ce que fera un jour `markRead()`) n'est pas un producteur : il modifie une
 * notification que quelqu'un d'autre aurait du poser.
 */
function ecrivainNotificationsI48006(string $contenu): bool
{
    // Requeteur brut : `DB::table('notifications')` suivi, dans la meme
    // instruction, d'un verbe de creation.
    if (preg_match('/DB::table\(\s*[\'"]notifications[\'"]\s*\)[^;]*->\s*(insertGetId|insertOrIgnore|updateOrInsert|upsert|insert)\s*\(/s', $contenu) === 1) {
        return true;
    }

    // Modele Eloquent adosse a la table.
    if (preg_match('/\$table\s*=\s*[\'"]notifications[\'"]/', $contenu) === 1) {
        return true;
    }

    // Canal Laravel : le trait `Notifiable` n'ecrit que si on l'appelle.
    return preg_match('/->\s*notifyNow?\s*\(|Notification::\s*sendNow?\s*\(/', $contenu) === 1;
}

function sourcesBackendI48006(): array
{
    return array_merge(
        fichiersPhpI48006(base_path('app')),
        fichiersPhpI48006(base_path('routes')),
        fichiersPhpI48006(base_path('database/seeders')),
    );
}

/**
 * La ligne `notifications` du §1 — et elle seule.
 *
 * On n'inspecte pas tout le document : le §3 bis CITE volontairement la phrase
 * fautive d'origine (« Vivante. Sert les rappels… ») pour garder la trace de ce
 * qui a ete rectifie. Chercher ce mot dans l'ensemble du fichier ferait rougir
 * la garde sur sa propre rectification. Rend `''` si la ligne a ete retiree du
 * §1 — c'est un classement plus juste encore, pas une regression.
 */
function ligneNotificationsSection1I48006(string $inventaire): string
{
    $debut = strpos($inventaire, "## 1. Ce qui EXISTE");
    $fin = strpos($inventaire, "## 2. Ce qui N'EXISTE PAS");

    Assert::assertNotFalse($debut, 'le titre du §1 a change : cette garde n\'inspecte plus la bonne section.');
    Assert::assertNotFalse($fin, 'le titre du §2 a change : cette garde n\'inspecte plus la bonne section.');

    $section1 = substr($inventaire, (int) $debut, (int) $fin - (int) $debut);

    foreach (explode("\n", $section1) as $ligne) {
        if (str_starts_with(trim($ligne), '|') && str_contains($ligne, '`notifications`')) {
            return $ligne;
        }
    }

    return '';
}

function lireInventaireI48006(): string
{
    $racine = realpath(base_path('..')) ?: base_path('..');
    $chemin = $racine . '/' . INVENTAIRE_1A_I48006;

    Assert::assertFileExists(
        $chemin,
        INVENTAIRE_1A_I48006 . " est introuvable. En local, la copie du depot dans le conteneur de\n"
        . 'banc se fait effacer par les autres agents : re-copier avant de croire ce resultat.',
    );

    return (string) file_get_contents($chemin);
}

test('I48-006 — TEMOIN : le detecteur d ecrivain sait dire OUI, et sait dire NON', function (): void {
    // Sans ce temoin, un detecteur casse rendrait « aucun ecrivain » sur tout
    // le depot et la garde suivante passerait au vert sans rien inspecter.
    // C'est le pire des verts, et ce depot l'a deja paye.
    expect(ecrivainNotificationsI48006("DB::table('notifications')->insert(['id' => 1]);"))->toBeTrue();
    expect(ecrivainNotificationsI48006("DB::table('notifications')->where('x', 1)->updateOrInsert([]);"))->toBeTrue();
    expect(ecrivainNotificationsI48006("protected \$table = 'notifications';"))->toBeTrue();
    expect(ecrivainNotificationsI48006('$user->notify(new RappelEntretien());'))->toBeTrue();

    // Et il ne prend pas une LECTURE ni une SUPPRESSION pour une ecriture :
    // c'est exactement ce que fait le code d'aujourd'hui.
    expect(ecrivainNotificationsI48006("DB::table('notifications')->where('user_id', \$id)->get();"))->toBeFalse();
    expect(ecrivainNotificationsI48006("DB::table('notifications')->where('created_at', '<', \$d)->delete();"))->toBeFalse();
});

test('I48-006 — TEMOIN DE COUVERTURE : le balayage voit bien les lecteurs connus', function (): void {
    $fichiers = sourcesBackendI48006();

    expect(count($fichiers))->toBeGreaterThan(
        200,
        'le balayage de `backend/app` + `routes` + `seeders` rend une poignee de fichiers : '
        . 'le parcours est tronque, cette garde ne mesure rien. Verifier `fichiersPhpI48006()`.',
    );

    $citants = array_values(array_filter(
        $fichiers,
        fn (string $f): bool => str_contains((string) file_get_contents($f), "DB::table('notifications')"),
    ));

    // Les quatre citants mesures le 2026-08-22 : la cloche, l'export RGPD, la
    // retention et l'effacement. En voir moins, c'est ne pas avoir lu l'arbre.
    expect(count($citants))->toBeGreaterThanOrEqual(
        4,
        'moins de 4 fichiers citent `DB::table(\'notifications\')` : le balayage ne lit pas tout '
        . "l'arbre, ou la cloche a ete demontee. Fichiers vus : \n" . implode("\n", $citants),
    );
});

test('I48-006 — la table notifications n a toujours AUCUN ecrivain, et l inventaire le dit', function (): void {
    $ecrivains = array_values(array_filter(
        sourcesBackendI48006(),
        fn (string $f): bool => ecrivainNotificationsI48006((string) file_get_contents($f)),
    ));

    $inventaire = lireInventaireI48006();

    if ($ecrivains !== []) {
        // ✅ BONNE NOUVELLE, ET GESTE A FAIRE. Un producteur existe enfin :
        // l'inventaire doit reclasser `notifications` en §1 (« a etendre »),
        // et cette garde doit devenir son symetrique.
        expect(str_contains($inventaire, '3 bis'))->toBeFalse(
            "Un ecrivain de `notifications` existe desormais :\n" . implode("\n", $ecrivains)
            . "\nGESTE : dans " . INVENTAIRE_1A_I48006 . ", supprimer le §3 bis et remonter la ligne "
            . "`notifications` en §1 avec le nom du producteur ; puis retourner cette garde (I48-006) "
            . 'pour qu\'elle exige la presence d\'un ecrivain plutot que son absence.',
        );

        return;
    }

    // Mesure du 2026-08-22 : aucun ecrivain. Le document doit le dire, avec ses
    // roles — c'est la ligne qui envoie ou non l'auteur du lot 1a « etendre »
    // une table vide.
    $ligne = ligneNotificationsSection1I48006($inventaire);

    if ($ligne !== '') {
        expect(str_contains($ligne, 'Vivante'))->toBeFalse(
            INVENTAIRE_1A_I48006 . ' declare a nouveau `notifications` « Vivante » au §1 (« a '
            . 'etendre »), alors qu\'aucun fichier de `backend/app`, `backend/routes` ou '
            . "`backend/database/seeders` n'y insere la moindre ligne (I48-006). Ligne fautive :\n"
            . trim($ligne) . "\nGESTE : retablir la mesure — table lue par la cloche et l'export "
            . "RGPD, purgee par la retention et l'effacement, jamais alimentee.",
        );

        expect(str_contains($ligne, 'sans écrivain'))->toBeTrue(
            INVENTAIRE_1A_I48006 . " : la ligne `notifications` du §1 n'annonce plus l'absence "
            . "d'ecrivain (I48-006). Ligne lue :\n" . trim($ligne)
            . "\nGESTE : y ecrire « table **sans écrivain** » et renvoyer au §3 bis, sinon l'auteur "
            . 'du lot 1a partira etendre une table que rien ne remplit.',
        );
    }

    foreach ([
        '3 bis' => 'le §3 bis qui classe la table en echafaudage a disparu',
        '501' => 'les deux `501` de `markRead()` / `markAllRead()` ne sont plus signales',
    ] as $exigence => $manque) {
        expect(str_contains($inventaire, $exigence))->toBeTrue(
            INVENTAIRE_1A_I48006 . " : {$manque} (I48-006). GESTE : la ligne `notifications` doit "
            . 'porter la mesure — lue par `NotificationsController` et l\'export RGPD, purgee par '
            . 'la retention et l\'effacement, aucun producteur, `markRead()`/`markAllRead()` en 501 '
            . '— et renvoyer au §3 bis plutot qu\'a une pièce « a etendre ».',
        );
    }
});

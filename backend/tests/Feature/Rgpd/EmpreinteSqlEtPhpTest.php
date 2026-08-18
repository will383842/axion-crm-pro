<?php

/**
 * 🔴 LES DEUX CALCULS D'EMPREINTE DOIVENT COÏNCIDER — ET ON LE MESURE.
 *
 * Le système hache une adresse d'opposition à DEUX endroits, dans deux
 * langages :
 *
 *   · en PHP — `ListeSuppression::empreinte()` et `SiteSyncEvent::emailHash()` :
 *     `sha256(mb_strtolower(trim($email)))`, sans sel. C'est le SSOT : le SITE
 *     calcule la même chose de son côté, indépendamment ;
 *   · en SQL — `EligibiliteCampagne::appliquerPortes()` :
 *     `encode(digest(btrim(lower(<col>)), 'sha256'), 'hex')`, appliqué à la
 *     colonne d'adresse du SUJET pour la comparer aux empreintes stockées.
 *
 * Depuis le 2026-08-18 la comparaison porte sur l'EMPREINTE SEULE : plus aucun
 * repli sur la colonne en clair ne rattrape une divergence. Ces deux calculs
 * sont donc devenus la charnière de toute la garde, et « ils se ressemblent »
 * ne suffit plus — il faut le mesurer.
 *
 * ── Ce que la mesure a trouvé, et qui n'était pas évident ─────────────────
 * Ils coïncident sur l'ASCII, y compris les majuscules et les espaces de
 * bordure. Ils DIVERGENT sur une majuscule non-ASCII, parce que la base est
 * initialisée en `--lc-ctype=C` (`docker-compose.yml`), où `lower()` ne
 * connaît que l'ASCII :
 *
 *     SQL  lower('ÉRIC@ACME.FR')          → 'Éric@acme.fr'   (le É reste)
 *     PHP  mb_strtolower('ÉRIC@ACME.FR')  → 'éric@acme.fr'
 *
 * Cet écart PRÉEXISTE au retrait de la colonne en clair : `citext` se compare
 * lui aussi via `lower()`, donc l'ancien repli sur l'adresse lisible était
 * aveugle exactement au même endroit. Il n'est ni créé ni aggravé par la
 * décision du 2026-08-18 — mais il n'était écrit NULLE PART, et un correctif
 * de collation posé à l'aveugle changerait silencieusement le comportement de
 * la garde en production.
 *
 * Ce test le FIXE : il est vert tant que l'état mesuré est celui qu'on croit.
 * Il rougit le jour où quelqu'un change la collation de la base, la formule
 * SQL, ou la normalisation PHP — c'est-à-dire au moment exact où une décision
 * doit être prise, et non six mois plus tard sur une opposition ratée.
 */

use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** L'empreinte telle que le SQL de production la calcule, sur la valeur donnée. */
function empreinteSql(string $valeur): ?string
{
    /** @var object{h: ?string} $ligne */
    $ligne = DB::selectOne(
        // `citext` n'apporte pas son propre `lower()` : l'appel se résout par
        // la conversion implicite vers `text`. Mesurer sur `text` couvre donc
        // les deux colonnes réelles (`companies.email_generic` en varchar,
        // `contacts.email` en citext).
        "select encode(digest(btrim(lower(?::text)), 'sha256'), 'hex') as h",
        [$valeur],
    );

    return $ligne->h;
}

test('🔴 empreinte SQL et empreinte PHP coïncident sur toutes les formes ASCII', function () {
    // Les cas limites qui comptent réellement : une adresse arrive d'un
    // tableur, d'un webhook, d'un formulaire — rarement propre.
    $cas = [
        'nominal' => 'contact@acme.fr',
        'majuscules' => 'CONTACT@ACME.FR',
        'casse mixte' => 'CoNtAcT@AcMe.Fr',
        'espaces de bordure' => '   contact@acme.fr   ',
        'majuscules + espaces' => '  CONTACT@ACME.FR  ',
        'sous-adressage' => 'contact+campagne@acme.fr',
        'point dans le local' => 'jean.dupont@acme.fr',
        'tiret dans le domaine' => 'contact@acme-groupe.fr',
        'domaine long' => 'contact@sous.domaine.acme.co.uk',
        'chaine vide' => '',
        'espaces seuls' => '   ',
    ];

    $ecarts = [];
    foreach ($cas as $etiquette => $valeur) {
        $php = ListeSuppression::empreinte($valeur);
        $sql = empreinteSql($valeur);

        if ($php !== $sql) {
            $ecarts[] = sprintf(
                'ÉCART · %s · entrée=%s%sPHP=%s%sSQL=%s',
                $etiquette,
                var_export($valeur, true),
                PHP_EOL . '        ',
                $php,
                PHP_EOL . '        ',
                $sql ?? '(null)',
            );
        }
    }

    expect($ecarts)->toBe([], sprintf(
        "L'empreinte PHP (SSOT, `ListeSuppression::empreinte()`) et l'empreinte SQL\n"
        . "(`EligibiliteCampagne::appliquerPortes()`) ne rendent pas la même valeur.\n"
        . "Conséquence : une opposition écrite par un système ne serait PAS vue par l'autre,\n"
        . "et une garde aveugle ne fait aucun bruit.\n\n%s\n",
        implode(PHP_EOL . PHP_EOL, $ecarts),
    ));
});

test('témoin — l’empreinte PHP est bien sha256(mb_strtolower(trim)), sans sel', function () {
    // Sans ce témoin, le test précédent resterait vert si les DEUX calculs
    // dérivaient ensemble : deux systèmes également faux se ressemblent.
    // Cette valeur est celle que le SITE calcule de son côté ; elle ne peut
    // pas être « corrigée » unilatéralement.
    expect(ListeSuppression::empreinte('  CONTACT@ACME.FR  '))
        ->toBe(hash('sha256', 'contact@acme.fr'))
        ->and(ListeSuppression::empreinte('contact@acme.fr'))
        ->toBe('9aaf508323617ba70194ef34caef88c571e8b1c09c39215d01a3e0c852a483ef');
});

test('🔴 ÉCART CONNU ET DÉLIBÉRÉMENT FIGÉ — majuscule non-ASCII', function () {
    // Ce test AFFIRME une divergence. Ce n'est pas un aveu de renoncement :
    // c'est la seule façon de la rendre visible. Le jour où quelqu'un pose
    // `COLLATE "C.UTF-8"` sur la formule SQL, ou change `lc_ctype`, ce test
    // rougit — et la décision passe par un humain au lieu d'être découverte
    // sur une opposition ratée.
    //
    // Portée réelle : une adresse e-mail dont le domaine ou la partie locale
    // porte une majuscule accentuée. Aucune n'a été observée dans les données
    // du CRM ; l'écart reste théorique, mais il est réel.
    $adresse = 'ÉRIC@ACME.FR';

    expect(ListeSuppression::empreinte($adresse))->toBe(hash('sha256', 'éric@acme.fr'));
    expect(empreinteSql($adresse))->toBe(hash('sha256', 'Éric@acme.fr'));
    expect(empreinteSql($adresse))->not->toBe(ListeSuppression::empreinte($adresse));

    // Et l'origine exacte, pour qu'on n'aille pas la chercher ailleurs :
    // c'est `lower()` sous `lc_ctype=C`, pas `digest` ni `btrim`.
    // ⚠️ `current_setting('lc_ctype')` n'existe PLUS en PostgreSQL 16 : les
    // GUC serveur `lc_collate` / `lc_ctype` ont été retirés au profit des
    // colonnes de `pg_database`. On lit donc la base elle-même.
    /** @var object{l: string, c: string} $ligne */
    $ligne = DB::selectOne(
        'select lower(?) as l, (select datctype from pg_database where datname = current_database()) as c',
        [$adresse],
    );
    expect($ligne->l)->toBe('Éric@acme.fr')
        ->and($ligne->c)->toBe('C');
});

test('la minuscule non-ASCII, elle, ne pose aucun problème', function () {
    // La divergence ne touche QUE la mise en minuscule. Une adresse déjà en
    // minuscules — c'est la forme normalisée que tous les points d'écriture
    // produisent — donne la même empreinte des deux côtés.
    foreach (['éric@acme.fr', 'straße@acme.fr', 'ökonom@acme.de'] as $adresse) {
        expect(empreinteSql($adresse))->toBe(ListeSuppression::empreinte($adresse));
    }
});

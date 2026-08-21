<?php

/**
 * GARDE B10-016 (S1), SECOND VOLET — LA PORTEE REELLE DE LA CORBEILLE.
 * Audit 360, agent 35, vague 12 (2026-08-21).
 *
 * CE QUI EST DEJA FERME, ET PAR QUI
 * ---------------------------------
 * Le premier volet (`EffacementDouxAgent35Test.php`, commit 0ac9578) a pose
 * `SoftDeletes` sur `Company`, `Contact`, `User`, `Workspace` et l'a prouve :
 * rejoue le 2026-08-21, 10 tests / 35 assertions au vert, et chacune des QUATRE
 * moities du defaut remise une par une fait bien rougir sa garde.
 *
 * CE QUE CE PREMIER VOLET NE MESURE PAS — ET QUE CELUI-CI MESURE
 * -------------------------------------------------------------
 * Sa garde anti-rechute balaye `app/Models/` et exige le trait partout ou la
 * table porte la colonne. Elle raisonne donc MODELE -> TABLE. Trois angles
 * morts en decoulent, tous mesures ici :
 *
 *   1. Une table qui porte `deleted_at` et sur laquelle AUCUN modele ne
 *      s'assied est invisible pour elle : rien a balayer, donc rien a dire.
 *      C'est le cas de `campaigns` (test « TROU DE COUVERTURE »).
 *
 *   2. `SoftDeletes` est un trait ELOQUENT. Tout `DB::table(...)` l'ignore :
 *      le constructeur de requetes n'applique aucun scope global. Une ligne
 *      correctement mise a la corbeille reste donc rendue par ces lectures-la.
 *      Mesure du 2026-08-21, au tokeniseur PHP (commentaires exclus) :
 *      **67 lectures `DB::table(...)` de `app/` qui ne nomment jamais
 *      `deleted_at`** sur les 11 tables qui portent la colonne. Le test
 *      « PLAFOND » les compte table par table et refuse qu'elles augmentent.
 *
 *   3. Poser le trait rend la corbeille POSSIBLE ; il ne la rend pas
 *      ATTEIGNABLE. Sur les sept portes `DELETE` de l'API, DEUX
 *      (`/contacts/{contact}`, `/users/{user}`) verifient l'espace de travail
 *      puis retournent `notImplemented()` — un 501. L'operateur qui clique
 *      « supprimer » sur un contact ne le met pas a la corbeille : il ne se
 *      passe rien. Test « PORTES MORTES ».
 *
 * L'ECART REEL, EN LIGNES — ET IL EST DE ZERO
 * -------------------------------------------
 * Le constat B10-016 annonce « ce que l'operateur croit avoir mis a la
 * corbeille reste rendu par toutes les requetes ». Compte le 2026-08-21 sur
 * les bases locales les plus fournies (`axion_crm_dr_a08` : 4 295 349
 * entreprises et 1 319 567 contacts ; `axion_crm_perf4m` : 2 800 000
 * entreprises ; `axion_crm`) :
 *
 *     table                | lignes    | dont deleted_at NON NUL
 *     ---------------------+-----------+------------------------
 *     companies            | 4 295 349 | 0
 *     contacts             | 1 319 567 | 0
 *     users                |         1 | 0
 *     workspaces           |         2 | 0
 *     (les 7 autres tables a deleted_at) | 0 | 0
 *
 * **Aucune ligne n'est aujourd'hui en corbeille, nulle part.** La consequence
 * decrite par le constat est donc THEORIQUE en volume : il n'y a rien de
 * masque qui serait quand meme rendu. Ce qui est REEL, c'est le mecanisme —
 * et le mecanisme est prouve ici par le test « MECANISME », qui met une
 * entreprise a la corbeille et montre la meme ligne masquee pour Eloquent et
 * TOUJOURS RENDUE par une lecture `DB::table(...)`.
 *
 * L'AUTRE CONSTAT : NEUF COLONNES MORTES
 * --------------------------------------
 * Le mandat demandait, si l'ecart etait nul, de mesurer ce qui ECRIT dans
 * `deleted_at`. Reponse mesuree : `app/` ne compte que DEUX sites capables de
 * poser cette colonne, sur les onze tables qui la portent.
 *   - `app/Http/Controllers/Api/CompaniesController.php:535` — `$company->delete()`,
 *     le SEUL appel Eloquent de mise a la corbeille de tout `app/` sur les
 *     quatre tables du lot ;
 *   - `app/Services/Rgpd/GdprErasureService.php:216` — un
 *     `DB::table('users')->update([... 'deleted_at' => now()])`, dans
 *     l'effacement RGPD.
 * Donc `contacts.deleted_at`, `workspaces.deleted_at` et les colonnes des sept
 * autres tables ne sont ecrites par RIEN. Le test « COLONNES MORTES » fige cet
 * etat : le jour ou un site se met a ecrire l'une d'elles, il rougira et il
 * faudra verifier que les lectures aveugles correspondantes ont ete filtrees
 * AVANT — sans quoi le defaut du constat deviendrait, lui, bien reel.
 *
 * ⚠️ CE FICHIER NE CORRIGE RIEN, ET C'EST DELIBERE.
 * Filtrer les 67 lectures aveugles changerait le resultat de toutes les listes,
 * de tous les comptages et de toutes les jointures de la console. Brancher les
 * deux portes mortes donnerait a l'operateur un pouvoir de suppression qu'il
 * n'a jamais eu. Ni l'un ni l'autre ne se decide au detour d'un correctif
 * d'audit. On MESURE, et on empeche le chiffre de grandir en silence.
 */

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Le CATALOGUE, jamais une liste ecrite a la main : les tables qui portent
 * reellement une colonne `deleted_at`, lues dans `information_schema`.
 */
function b10pTablesAEffacementDoux(): array
{
    return DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('column_name', 'deleted_at')
        ->orderBy('table_name')
        ->pluck('table_name')
        ->all();
}

/**
 * Balaye `app/` AU TOKENISEUR et rend toutes les chaines `DB::table('<t>')`.
 *
 * ⚠️ Pourquoi le tokeniseur et pas un `grep` : un balayage naif du 2026-08-21
 * comptait `app/Jobs/RefreshAudienceChunkJob.php:47` comme une lecture reelle.
 * C'est un COMMENTAIRE qui cite `DB::table('contacts')` en prose. Les
 * commentaires sont donc remplaces par des sauts de ligne (les numeros de
 * ligne restent justes) avant toute recherche.
 *
 * Chaque chaine est classee en trois seaux :
 *   - `ecritures`   : la chaine appelle insert/update/upsert/delete/truncate ;
 *   - `conscientes` : c'est une lecture, et elle NOMME `deleted_at` ;
 *   - `aveugles`    : c'est une lecture, et elle ne le nomme jamais.
 *
 * @return array{aveugles: array<int, array{table: string, site: string}>, conscientes: array<int, array{table: string, site: string}>, ecritures: array<int, array{table: string, site: string, pose_deleted_at: bool}>}
 */
/**
 * Tous les `.php` d'un dossier, en profondeur — par `scandir`, jamais par
 * `RecursiveDirectoryIterator`.
 *
 * @return list<string> chemins absolus, ordre stable
 */
function b10pFichiersPhp(string $dossier): array
{
    $trouves = [];

    foreach (scandir($dossier) ?: [] as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }

        $chemin = $dossier . DIRECTORY_SEPARATOR . $entree;

        if (is_dir($chemin)) {
            $trouves = array_merge($trouves, b10pFichiersPhp($chemin));
        } elseif (str_ends_with($entree, '.php')) {
            $trouves[] = $chemin;
        }
    }

    sort($trouves);

    return $trouves;
}

function b10pBalayerAppelsConstructeur(array $tables): array
{
    $seaux = ['aveugles' => [], 'conscientes' => [], 'ecritures' => []];

    // ⚠️ `scandir` RECURSIF ET NON `RecursiveDirectoryIterator`. Ce n'est pas
    // un gout : sur le montage de Docker Desktop pour Windows, l'iterateur ne
    // rend pas tout le repertoire. Mesure du 2026-08-21 dans le conteneur
    // `a35r`, sur `app/Console/Commands` :
    //
    //   scandir() / glob() / find ......... 56 fichiers
    //   RecursiveDirectoryIterator ........ 14   <- stable sur trois passages
    //
    // Le balayage voyait 251 fichiers sur 293. Une garde qui enumere sur un
    // quart d'un repertoire rend un « rien trouve » sans valeur — et c'est
    // exactement ce qui a fige des plafonds faux (cf. la note plus bas).
    foreach (b10pFichiersPhp(app_path()) as $chemin) {
        $relatif = 'app' . str_replace('\\', '/', substr($chemin, strlen(app_path())));

        // Source SANS commentaires, numeros de ligne preserves.
        $plat = '';
        foreach (token_get_all(file_get_contents($chemin)) as $jeton) {
            if (is_array($jeton) && ($jeton[0] === T_COMMENT || $jeton[0] === T_DOC_COMMENT)) {
                $plat .= str_repeat("\n", substr_count($jeton[1], "\n"));

                continue;
            }
            $plat .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        foreach ($tables as $table) {
            foreach (["DB::table('" . $table . "')", 'DB::table("' . $table . '")'] as $motif) {
                $depart = 0;
                while (($position = strpos($plat, $motif, $depart)) !== false) {
                    $depart = $position + 1;
                    $ligne = substr_count(substr($plat, 0, $position), "\n") + 1;
                    $fin = strpos($plat, ';', $position);
                    $chaine = $fin === false
                        ? substr($plat, $position, 600)
                        : substr($plat, $position, $fin - $position);

                    $site = $relatif . ':' . $ligne;
                    $nommeColonne = str_contains($chaine, 'deleted_at');

                    if (preg_match('/->\s*(insertGetId|insertOrIgnore|insert|upsert|updateOrInsert|update|delete|truncate)\s*\(/', $chaine) === 1) {
                        $seaux['ecritures'][] = [
                            'table' => $table,
                            'site' => $site,
                            'pose_deleted_at' => $nommeColonne,
                        ];

                        continue;
                    }

                    $seaux[$nommeColonne ? 'conscientes' : 'aveugles'][] = [
                        'table' => $table,
                        'site' => $site,
                    ];
                }
            }
        }
    }

    return $seaux;
}

// ───────────────────────────────────────────── TEMOINS D'INSTRUMENTATION

/**
 * TEMOIN DE COUVERTURE, indispensable. Tous les plafonds ci-dessous sont des
 * `<=`. Un balayage qui ne trouverait RIEN — repertoire deplace, autoload
 * casse, `token_get_all` muet — rendrait des seaux vides et TOUS les plafonds
 * seraient satisfaits. La garde verdirait sur du neant.
 *
 * On exige donc d'abord que le balayage voie quelque chose, et que le
 * catalogue reponde.
 */
test('B10-016-PORTEE TEMOIN — le catalogue repond et le balayage du code voit reellement des appels', function () {
    $tables = b10pTablesAEffacementDoux();
    expect(count($tables))->toBeGreaterThanOrEqual(11);

    $seaux = b10pBalayerAppelsConstructeur($tables);
    $total = count($seaux['aveugles']) + count($seaux['conscientes']) + count($seaux['ecritures']);

    // 106 chaines vues le 2026-08-21 (67 + 14 + 25). On exige au moins 80 :
    // en dessous, ce n'est plus le code qui a change, c'est le balayage qui
    // est casse, et aucune conclusion de ce fichier ne tient.
    expect($total)->toBeGreaterThanOrEqual(80);

    // Et les trois seaux sont peuples : si `ecritures` etait vide, le test
    // « COLONNES MORTES » conclurait « personne n'ecrit » pour la mauvaise
    // raison.
    expect(count($seaux['aveugles']))->toBeGreaterThan(0);
    expect(count($seaux['conscientes']))->toBeGreaterThan(0);
    expect(count($seaux['ecritures']))->toBeGreaterThan(0);
});

/**
 * TEMOIN NEGATIF. Le classement « aveugle / consciente » repose entierement
 * sur le retrait des commentaires. On verifie sur un cas connu que la prose
 * n'est PAS comptee : `RefreshAudienceChunkJob.php` cite `DB::table('contacts')`
 * dans un commentaire (ligne 47 le 2026-08-21) et l'appelle pour de vrai plus
 * bas. Le balayage doit voir le second et pas le premier.
 */
test('B10-016-PORTEE TEMOIN NEGATIF — une occurrence citee en commentaire n est pas comptee', function () {
    $chemin = app_path('Jobs/RefreshAudienceChunkJob.php');
    expect(file_exists($chemin))->toBeTrue();

    $source = file_get_contents($chemin);
    $brutes = substr_count($source, "DB::table('contacts')");

    $vues = array_filter(
        array_merge(
            b10pBalayerAppelsConstructeur(['contacts'])['aveugles'],
            b10pBalayerAppelsConstructeur(['contacts'])['conscientes'],
            b10pBalayerAppelsConstructeur(['contacts'])['ecritures'],
        ),
        fn (array $e): bool => str_ends_with(dirname($e['site']), 'app/Jobs')
            && str_contains($e['site'], 'RefreshAudienceChunkJob.php'),
    );

    // Le fichier contient l'occurrence en prose ET l'appel reel : le balayage
    // doit en compter STRICTEMENT MOINS que le texte brut.
    expect($brutes)->toBeGreaterThan(count($vues));
    expect(count($vues))->toBeGreaterThan(0);
});

// ───────────────────────────────────────────── LE CATALOGUE, ET SON TROU

test('B10-016-PORTEE — la liste des tables portant deleted_at est celle du 2026-08-21', function () {
    expect(b10pTablesAEffacementDoux())->toBe([
        'campaigns',
        'candidates',
        'companies',
        'contacts',
        'email_audiences',
        'health_practitioners',
        'journalists',
        'media',
        'scraping_campaigns',
        'users',
        'workspaces',
    ]);
});

/**
 * L'ANGLE MORT DU PREMIER VOLET.
 *
 * Sa garde anti-rechute part des modeles et remonte aux tables. Une table qui
 * porte `deleted_at` sans qu'aucun modele ne s'y assoie ne peut donc jamais la
 * faire rougir. Celle-ci part des TABLES et redescend : elle voit ce que
 * l'autre ne peut pas voir.
 *
 * `campaigns` est dans ce cas. Ce n'est pas un defaut a corriger a l'aveugle —
 * on n'invente pas un modele Eloquent pour satisfaire une garde — mais un fait
 * a connaitre : cette table a une corbeille que rien ne peut ouvrir ni fermer.
 */
test('B10-016-PORTEE TROU DE COUVERTURE — les tables a deleted_at sans aucun modele Eloquent', function () {
    $tablesDesModeles = [];
    $modelesVus = 0;

    foreach (glob(app_path('Models') . '/*.php') ?: [] as $fichier) {
        $classe = 'App\\Models\\' . basename($fichier, '.php');
        if (! class_exists($classe) || ! is_subclass_of($classe, Model::class)) {
            continue;
        }
        $modelesVus++;
        $tablesDesModeles[] = (new $classe)->getTable();
    }

    // TEMOIN : 18 fichiers dans `app/Models/` le 2026-08-21. Un balayage vide
    // ferait passer TOUTES les tables pour orphelines et la garde rougirait
    // pour la mauvaise raison — ou, pire, un `array_diff` vide la ferait
    // verdir si l'on avait ecrit l'assertion dans l'autre sens.
    expect($modelesVus)->toBeGreaterThanOrEqual(15);

    $orphelines = array_values(array_diff(b10pTablesAEffacementDoux(), $tablesDesModeles));

    expect($orphelines)->toBe(['campaigns']);
});

// ───────────────────────────────────────────── LE CHIFFRE FIGE

/**
 * LE PLAFOND. Une lecture `DB::table(...)` ignore `SoftDeletes` : le
 * constructeur de requetes n'applique aucun scope global. Ces 67 lectures
 * rendraient donc les lignes en corbeille, table par table.
 *
 * Assertion en `<=` et jamais en `==` : on veut pouvoir en FILTRER sans casser
 * la garde, mais jamais en AJOUTER en silence. Le chiffre du jour est ecrit en
 * clair a cote de chaque table pour que la derive se lise au diff.
 */
test('B10-016-PORTEE PLAFOND — les lectures DB::table aveugles a deleted_at n augmentent pas', function () {
    // 🔴 CES ONZE NOMBRES ONT ETE FAUX, ET LE COUPABLE EST LE BANC.
    //
    // Ils valaient 16 / 23 / 1 / 10 pour companies / contacts / media /
    // workspaces, total 67. La CI en a rendu 22 / 26 / 5 / 17, total 87 — sur
    // EXACTEMENT le meme commit. Mesure du 2026-08-21, dans le conteneur
    // `a35r`, sur `app/Console/Commands` :
    //
    //   scandir() ......................... 56 fichiers
    //   glob() ............................ 56
    //   find (shell) ...................... 56
    //   RecursiveDirectoryIterator ........ 14   <- stable sur trois passages
    //
    // Le montage de Docker Desktop pour Windows ne rend pas tout le repertoire
    // a `RecursiveDirectoryIterator`. Le balayage voyait 251 fichiers `.php` sur
    // 293, et 14 commandes sur 56 : les plafonds ci-dessous ont donc ete ecrits
    // d'apres UN QUART du repertoire le plus concerne, puis vus verts.
    //
    // ⚠️ TREIZE gardes de cette suite balaient un repertoire de cette facon.
    // Toutes rendent, sur ce banc, un verdict portant sur un sous-ensemble
    // arbitraire — et un « rien trouve » y est sans valeur. La reference est la
    // CI, ou l'arbre est complet. Voir REPRISE-ETAT.md §13.
    $plafonds = [
        'campaigns' => 0,
        'candidates' => 11,
        'companies' => 22,
        'contacts' => 26,
        'email_audiences' => 0,
        'health_practitioners' => 2,
        'journalists' => 1,
        'media' => 5,
        'scraping_campaigns' => 1,
        'users' => 2,
        'workspaces' => 17,
    ];

    $tables = b10pTablesAEffacementDoux();

    // Le catalogue et le plafond doivent parler des MEMES tables : si une
    // table apparait au schema sans ligne de plafond, elle passerait sous le
    // radar. On l'exige explicitement plutot que de tolerer un `?? 0`.
    expect($tables)->toBe(array_keys($plafonds));

    $aveugles = b10pBalayerAppelsConstructeur($tables)['aveugles'];

    $comptes = array_fill_keys($tables, 0);
    foreach ($aveugles as $entree) {
        $comptes[$entree['table']]++;
    }

    $depassements = [];
    foreach ($plafonds as $table => $plafond) {
        if ($comptes[$table] > $plafond) {
            $depassements[] = $table . ' : ' . $comptes[$table] . ' > ' . $plafond;
        }
    }

    expect($depassements)->toBe([]);

    // Et le total, pour que l'ordre de grandeur reste visible d'un coup d'oeil.
    // 87 et non 67 : cf. la note des plafonds ci-dessus.
    expect(count($aveugles))->toBeLessThanOrEqual(87);
});

/**
 * LES COLONNES MORTES — ET ELLES SONT HUIT, PAS NEUF.
 *
 * 🔴 CETTE GARDE AFFIRMAIT : « un seul site de tout `app/` pose `deleted_at` :
 * `GdprErasureService.php:216`, sur `users`. Rien d'autre. » C'ETAIT FAUX, et
 * pour la meme raison que les plafonds ci-dessus : le balayage du banc ne voyait
 * que 14 des 56 fichiers de `app/Console/Commands`.
 *
 * Le site manquant, rendu par la CI le 2026-08-21 :
 *
 *   app/Console/Commands/ImportMediaMerge.php:197
 *     DB::table('media')->whereIn('id', $paquet)->update(['deleted_at' => now(), ...])
 *
 * Ce n'est pas un detail : c'est l'ARCHIVAGE des medias sortis du registre, et
 * il tourne en automatisme. `media` porte donc bien une corbeille VIVANTE — la
 * seule avec `users` — et les cinq lectures aveugles de `media` recensees
 * au-dessus lisent, elles, des lignes archivees comme si elles ne l'etaient pas.
 *
 * On fige l'ENSEMBLE des tables concernees, pas un compte : le jour ou un
 * `DB::table('contacts')->update(['deleted_at' => ...])` apparait, la garde
 * nomme la table et impose de verifier d'abord les 26 lectures aveugles de
 * `contacts`.
 */
test('B10-016-PORTEE COLONNES MORTES — deux tables recoivent un deleted_at par DB::table', function () {
    $ecritures = b10pBalayerAppelsConstructeur(b10pTablesAEffacementDoux())['ecritures'];

    // TEMOIN : sans ecritures vues, `$posantes` serait vide et l'assertion
    // finale ne prouverait rien.
    expect(count($ecritures))->toBeGreaterThanOrEqual(20);

    $posantes = [];
    $sites = [];
    foreach ($ecritures as $entree) {
        if ($entree['pose_deleted_at']) {
            $posantes[] = $entree['table'];
            $sites[] = $entree['site'];
        }
    }
    $posantes = array_values(array_unique($posantes));
    sort($posantes);
    sort($sites);

    expect($posantes)->toBe(['media', 'users']);
    expect($sites)->toBe([
        'app/Console/Commands/ImportMediaMerge.php:197',
        'app/Services/Rgpd/GdprErasureService.php:216',
    ]);
});

/**
 * LES PORTES MORTES.
 *
 * 🔴 CETTE GARDE A RENDU UN RESULTAT QUE SON PROPRE AUTEUR N'AVAIT PAS VU.
 *
 * Elle annoncait « sept routes DELETE, deux mortes » — une liste ecrite a la
 * main, jamais mesuree. Premiere execution, le 2026-08-21 : le routeur rend
 * NEUF routes `DELETE` cablees sur un controleur, et TROIS d'entre elles ne
 * suppriment rien. La troisieme, `api/v1/saved-views/{saved_view}`, avait
 * echappe au constat.
 *
 * Ce n'est pas un hasard : `saved_views` est l'une des six tables sans modele,
 * sans controleur utile, sans ecran, recensees par
 * `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md`. La porte est declaree au
 * routeur, elle repond, et il n'y a rien derriere.
 *
 * Les trois ne se ressemblent pas, et la nuance compte :
 *
 *   contacts/{contact}      verifie l'espace (`refuserHorsEspace`) PUIS 501
 *   users/{user}            verifie l'espace (`refuserHorsEspace`) PUIS 501
 *   saved-views/{saved_view} 501 sec, aucun controle
 *
 * Les deux premieres font croire a un controle serieux avant de ne rien faire.
 * Poser `SoftDeletes` sur `Contact` et `User` n'y change rien : personne
 * n'appelle jamais leur `->delete()`.
 *
 * La liste des routes vient du ROUTEUR, pas d'une liste ecrite a la main :
 * une dixieme porte ajoutee demain sera balayee sans qu'on y pense. C'est
 * precisement ce qui a permis a cette garde de contredire son auteur.
 */
test('B10-016-PORTEE PORTES MORTES — trois des neuf portes DELETE de l API ne suppriment rien', function () {
    $portes = [];
    $mortes = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('DELETE', $route->methods(), true)) {
            continue;
        }
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            continue;
        }
        [$classe, $methode] = explode('@', $action, 2);
        if (! class_exists($classe) || ! method_exists($classe, $methode)) {
            continue;
        }

        $portes[] = $route->uri();

        $reflet = new ReflectionMethod($classe, $methode);
        $lignes = file($reflet->getFileName());
        $corps = implode('', array_slice(
            $lignes,
            $reflet->getStartLine() - 1,
            $reflet->getEndLine() - $reflet->getStartLine() + 1,
        ));

        // `assertStringContainsString` et non `toContain` : en Pest, `toContain`
        // est VARIADIQUE et se comporte autrement sur une chaine.
        if (str_contains($corps, 'notImplemented')) {
            $mortes[] = $route->uri();
        }
    }

    // TEMOIN : le routeur doit rendre les portes. Zero porte balayee et
    // `$mortes` serait vide — vert sur du neant. Le plancher reste a 7 alors
    // que la mesure du 2026-08-21 en rend 9 : il garde contre le balayage
    // VIDE, pas contre le retrait deliberé d'une route.
    expect(count($portes))->toBeGreaterThanOrEqual(7);

    sort($mortes);
    expect($mortes)->toBe([
        'api/v1/contacts/{contact}',
        'api/v1/saved-views/{saved_view}',
        'api/v1/users/{user}',
    ]);
});

// ───────────────────────────────────────────── LE MECANISME, DEMONTRE

/**
 * LA DEMONSTRATION.
 *
 * Le chiffre de 67 lectures aveugles est un comptage statique. Voici ce qu'il
 * signifie, joue : la MEME ligne, mise a la corbeille, est masquee pour
 * Eloquent et rendue par le constructeur de requetes.
 *
 * C'est le coeur du constat B10-016 — et la raison pour laquelle poser le
 * trait ne suffisait pas a le clore.
 */
test('B10-016-PORTEE MECANISME — une ligne en corbeille reste rendue par une lecture DB::table', function () {
    $espace = DB::table('workspaces')->insertGetId([
        'id' => (string) Str::uuid(),
        'slug' => 'b10p-' . Str::random(10),
        'name' => 'B10-016 portee',
        'created_at' => now(),
        'updated_at' => now(),
    ], 'id');

    $entreprise = Company::create([
        'workspace_id' => $espace,
        'siren' => str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
        'denomination' => 'Entreprise B10-016 portee',
    ]);
    $id = $entreprise->id;

    // TEMOIN : la ligne est vivante et vue des DEUX cotes avant la corbeille.
    expect(Company::find($id))->not->toBeNull();
    expect(DB::table('companies')->where('id', $id)->exists())->toBeTrue();

    $entreprise->delete();

    // Cote Eloquent : masquee. C'est ce que le premier volet a obtenu.
    expect(Company::find($id))->toBeNull();

    // Cote constructeur de requetes : TOUJOURS LA. Le trait n'y est pour rien,
    // et c'est exactement ce que font les 16 lectures aveugles de `companies`.
    $brute = DB::table('companies')->where('id', $id)->first();
    expect($brute)->not->toBeNull();
    expect($brute->deleted_at)->not->toBeNull();

    // Et la parade, quand on decidera de la generaliser : nommer la colonne.
    expect(DB::table('companies')->where('id', $id)->whereNull('deleted_at')->exists())->toBeFalse();
});

<?php

/**
 * 🔴 B15-002 (S1) — « `AntiReinsertionTest` EST VERT ET MESURE LE MAUVAIS OBJET ».
 *
 * Mesure du 2026-08-21, sur `axion_crm_test_lot11` :
 *
 *   1. `AntiReinsertionTest` porte le nom de la garde anti-reinsertion. Il ne
 *      l'exerce jamais : il appelle `DeduplicationService::addOptOut()` seul,
 *      recopie la requete du funnel de SCRAPING (`scope = 'business'`), et
 *      affirme `expect($ligne->scope)->toBe('business')`.
 *   2. Il consacre donc comme CORRECT le reglage exact qui produit B15-001 —
 *      une opposition ecrite dans le seul univers `business` pendant que la
 *      garde du vivier interroge `vivier`.
 *   3. Preuve jouee : le defaut de B15-001 a ete REMIS dans `addOptOut()`
 *      (`$scopes = ['business']`). `AntiReinsertionTest` est reste VERT,
 *      4 / 4, 9 assertions. Une garde qui ne rate pas seulement le defaut :
 *      elle le certifie.
 *
 * ── CE QUE CE FICHIER FERME, ET QUI N'ETAIT GARDE NULLE PART ────────────────
 *
 * `EffacementConsoleAntiReinsertionTest` (constat B15-001, ferme le 2026-08-20)
 * rougit bien sur ce defaut — mais il enumere les deux univers A LA MAIN
 * (`assertContains('vivier', …)`, `assertContains('business', …)`). Le jour ou
 * un troisieme univers entre dans le `CHECK` de la table, aucune des deux
 * gardes ne le verra : c'est la forme exacte du piege « liste ecrite a la
 * main » qui a laisse B15-001 ouvert pendant que son jumeau etait repare.
 *
 * Ici, RIEN n'est enumere a la main :
 *   · les univers sont LUS dans `pg_constraint` (`opt_out_scope_check`) ;
 *   · les points de lecture sont TROUVES par balayage de `app/`.
 * Deux temoins de couverture prouvent que ces deux inventaires savent rendre
 * vide — sans quoi une garde qui ne trouve rien passerait pour verte.
 *
 * Et une TROISIEME porte est mesuree, qu'aucun des deux tests n'atteint :
 * `EligibiliteCampagne::peutRecevoir($email, $scope)`, consultee juste avant
 * chaque envoi. Elle a son propre `where('scope', …)`. Une personne effacee
 * doit y etre refusee dans TOUS les univers, pas seulement dans `business`
 * (qui est la valeur par DEFAUT du parametre — donc celle que tout appelant
 * distrait obtient).
 */

use App\Services\Dedup\DeduplicationService;
use App\Services\Rgpd\GdprErasureService;
use App\Support\EligibiliteCampagne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Les univers d'opposition tels que LA TABLE les autorise.
 *
 * Source de verite : la contrainte de table, pas une constante PHP et pas une
 * liste recopiee dans un test. `pg_get_constraintdef()` rend
 * `CHECK ((scope = ANY (ARRAY['business'::text, 'vivier'::text])))`.
 *
 * @return list<string>
 */
function universAutorisesParLeSchema(string $table): array
{
    $ligne = DB::selectOne(
        'select pg_get_constraintdef(oid) as def from pg_constraint where conname = ?',
        [$table . '_scope_check'],
    );

    if ($ligne === null) {
        return [];
    }

    preg_match_all("/'([a-z_]+)'(?:::text)?/", (string) $ligne->def, $trouves);

    $univers = array_values(array_unique($trouves[1]));
    sort($univers);

    return $univers;
}

/**
 * Les fichiers de `app/` qui interrogent une liste d'opposition EN FILTRANT
 * SUR L'UNIVERS. Balayage, jamais une liste ecrite a la main : c'est
 * precisement une liste ecrite a la main qui a laisse la porte du vivier
 * ouverte pendant que celle du site etait reparee (motif A-011).
 *
 * @return list<string>
 */
function fichiersQuiFiltrentUneOpposition(): array
{
    $racine = base_path('app');
    $trouves = [];

    $parcours = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($parcours as $fichier) {
        if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());

        // Le fichier nomme-t-il une table d'opposition ?
        if (preg_match("/'(opt_out|email_suppressions)'/", $source) !== 1) {
            continue;
        }

        // Et filtre-t-il sur l'univers ? On accepte les deux ecritures du
        // depot : `->where('scope', …)` et `->where($table . '.scope', …)`.
        if (preg_match("/->where\(\s*[^,\n]*scope['\"]/", $source) !== 1) {
            continue;
        }

        $trouves[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen(base_path()) + 1));
    }

    sort($trouves);

    return $trouves;
}

/**
 * Les ENUMERATIONS COMPLETES des univers ecrites a la main dans `app/`.
 *
 * On ne retient qu'une liste qui nomme A LA FOIS `business` ET `vivier` : une
 * telle liste PRETEND enumerer les univers, et doit donc suivre le schema. Un
 * branchement par univers (`in_array($scope, ['both', 'business'], true)`) n'en
 * est pas une, et n'a pas a contenir les autres.
 *
 * Aucune liste de fichiers ici : c'est precisement une liste de fichiers ecrite
 * a la main qui a laisse B15-001 ouvert pendant que son jumeau etait repare.
 *
 * @return array<string, list<string>> emplacement => valeurs enumerees
 */
function enumerationsCompletesDesUnivers(): array
{
    $racine = base_path('app');
    $trouvees = [];

    $parcours = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($parcours as $fichier) {
        if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        $lignes = explode("\n", $source);

        foreach ($lignes as $numero => $ligne) {
            if (preg_match_all("/\[[^\[\]]*'business'[^\[\]]*\]/", $ligne, $listes) === 0) {
                continue;
            }

            foreach ($listes[0] as $liste) {
                if (! str_contains($liste, "'vivier'")) {
                    continue;
                }

                preg_match_all("/'([a-z_]+)'/", $liste, $valeurs);

                $chemin = str_replace('\\', '/', substr($fichier->getPathname(), strlen(base_path()) + 1));
                $trouvees[$chemin . ':' . ($numero + 1)] = array_values($valeurs[1]);
            }
        }
    }

    ksort($trouvees);

    return $trouvees;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. LES DEUX INVENTAIRES — et la preuve qu'ils savent rendre vide
// ─────────────────────────────────────────────────────────────────────────────

test('B15-002 — les univers d’opposition sont LUS dans le schema, jamais recopies', function () {
    $univers = universAutorisesParLeSchema('opt_out');

    // Temoin de couverture : si la requete ou l'expression reguliere cassait,
    // la liste serait vide et TOUTES les boucles de ce fichier tourneraient a
    // zero tour — vertes, et aveugles. On exige donc un plancher.
    expect($univers)->not->toBeEmpty('l’inventaire des univers est vide : le balayage du schema ne mesure plus rien');
    expect(count($univers))->toBeGreaterThanOrEqual(2);

    // Le chiffre du jour, fige. S'il bouge, ce n'est pas une regression : c'est
    // une decision a porter dans `UNIVERS_OPPOSITION` et dans les trois portes.
    expect($univers)->toBe(['business', 'vivier']);
});

test('B15-002 — TEMOIN : le lecteur de schema rend VIDE sur une contrainte absente', function () {
    // Sans ce temoin, un lecteur qui renverrait une constante passerait le test
    // precedent en ne lisant jamais la base.
    expect(universAutorisesParLeSchema('table_qui_nexiste_pas'))->toBe([]);
});

test('B15-002 — la constante du code ne peut pas diverger du CHECK de la table', function () {
    $constante = DeduplicationService::UNIVERS_OPPOSITION;
    sort($constante);

    // `addOptOut()` boucle sur cette constante. Si un univers entrait dans le
    // CHECK sans entrer ici, un effacement laisserait sa porte ouverte — et
    // c'est exactement B15-001, un cran plus loin.
    expect($constante)->toBe(universAutorisesParLeSchema('opt_out'));
});

test('B15-002 — l’inventaire des points de lecture est BALAYE, jamais recopie', function () {
    $fichiers = fichiersQuiFiltrentUneOpposition();

    // Temoin de couverture : un balayage qui ne trouve rien doit rougir.
    expect($fichiers)->not->toBeEmpty('le balayage de `app/` ne trouve plus aucun filtre d’univers : il ne mesure plus rien');

    // Les six points mesures le 2026-08-21. Un septieme qui apparait n'est pas
    // une faute : c'est un rappel qu'il faut verifier que l'effacement couvre
    // l'univers qu'il interroge, puis remonter le chiffre ici.
    expect($fichiers)->toBe([
        'app/Crm/Ingest/SiteSyncIngestService.php',
        'app/Crm/Rgpd/SiteGdprService.php',
        'app/Crm/Scraping/ScrapedRecordIngestService.php',
        'app/Services/Dedup/DeduplicationService.php',
        'app/Support/EligibiliteCampagne.php',
        'app/Support/ListeSuppression.php',
    ]);
});

test('B15-002 — TEMOIN : le balayage rend VIDE quand on lui donne un arbre sans code', function () {
    // Le meme balayage, applique a un dossier qui ne contient aucun `.php`
    // metier. S'il rendait quand meme six fichiers, il ne lirait pas le disque.
    $racine = base_path('storage/framework');
    $trouves = 0;

    if (is_dir($racine)) {
        $parcours = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($parcours as $fichier) {
            if ($fichier->isFile() && $fichier->getExtension() === 'php'
                && preg_match("/'(opt_out|email_suppressions)'/", (string) file_get_contents($fichier->getPathname())) === 1) {
                $trouves++;
            }
        }
    }

    expect($trouves)->toBe(0);
});

test('B15-002 — aucune enumeration ecrite a la main ne peut oublier un univers du schema', function () {
    $enumerations = enumerationsCompletesDesUnivers();
    $univers = universAutorisesParLeSchema('opt_out');

    expect($univers)->not->toBeEmpty();

    // Temoin de couverture : le depot en porte plusieurs — `UNIVERS_OPPOSITION`,
    // `ConsentOutboundRecorder::SCOPES`, la liste fermee du controleur du canal
    // interne. Si le balayage n'en trouve plus, il ne mesure plus rien.
    expect(count($enumerations))->toBeGreaterThanOrEqual(
        3,
        'le balayage ne trouve plus les enumerations d’univers de `app/` : il ne mesure plus rien',
    );

    foreach ($enumerations as $emplacement => $valeurs) {
        foreach ($univers as $u) {
            $this->assertContains(
                $u,
                $valeurs,
                "{$emplacement} enumere les univers et oublie « {$u} » : cette porte ne connait pas tout ce que la table autorise",
            );
        }
    }
});

test('B15-002 — TEMOIN : le balayage des enumerations ne rend rien sur un arbre sans code', function () {
    // Il lit le disque, il ne rend pas une constante. Sans ce temoin, un
    // balayage cable en dur passerait le test precedent.
    $racine = base_path('storage/framework');
    $trouvees = 0;

    if (is_dir($racine)) {
        $parcours = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($parcours as $fichier) {
            if ($fichier->isFile() && $fichier->getExtension() === 'php'
                && preg_match("/\[[^\[\]]*'business'[^\[\]]*'vivier'[^\[\]]*\]/", (string) file_get_contents($fichier->getPathname())) === 1) {
                $trouvees++;
            }
        }
    }

    expect($trouvees)->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LE DEFAUT — un effacement doit fermer TOUTES les portes que le schema ouvre
// ─────────────────────────────────────────────────────────────────────────────

test('🔴 B15-002 — un effacement console oppose la personne dans CHAQUE univers du schema', function () {
    $courriel = 'tous.univers@example.invalid';

    app(GdprErasureService::class)->erase($courriel);

    $univers = universAutorisesParLeSchema('opt_out');
    expect($univers)->not->toBeEmpty();

    $inscrits = DB::table('opt_out')
        ->where('email_hash', hash('sha256', $courriel))
        ->pluck('scope')
        ->all();

    foreach ($univers as $u) {
        // `assertContains` et non `expect()->toContain()` : ce dernier est
        // VARIADIQUE chez Pest — un message passe en second argument
        // deviendrait une aiguille de plus, et la garde rougirait toujours.
        $this->assertContains(
            $u,
            $inscrits,
            "aucune opposition dans l'univers « {$u} » : la porte de cet univers reste ouverte apres un effacement",
        );
    }
});

test('🔴 B15-002 — apres un effacement, la porte des CAMPAGNES est fermee dans CHAQUE univers', function () {
    // La troisieme porte, qu'aucune des deux gardes existantes n'atteint.
    // `peutRecevoir()` a son propre `where('scope', …)` et son parametre vaut
    // `'business'` par DEFAUT : une campagne vivier qui oublie l'argument
    // interroge le mauvais univers, et une campagne vivier qui le passe
    // n'aurait rien vu tant que l'effacement n'ecrivait que `business`.
    $courriel = 'campagne.fermee@example.invalid';

    app(GdprErasureService::class)->erase($courriel);

    foreach (universAutorisesParLeSchema('opt_out') as $u) {
        expect(EligibiliteCampagne::peutRecevoir($courriel, $u))->toBeFalse(
            "une campagne « {$u} » peut encore ecrire a une personne effacee",
        );
    }
});

test('B15-002 — TEMOIN : une adresse jamais effacee reste joignable dans chaque univers', function () {
    // Sans ce temoin, une garde qui refuserait TOUT LE MONDE passerait les deux
    // tests ci-dessus et paraitrait avoir ferme le constat.
    app(GdprErasureService::class)->erase('quelquun@example.invalid');

    $univers = universAutorisesParLeSchema('opt_out');
    expect($univers)->not->toBeEmpty();

    foreach ($univers as $u) {
        expect(EligibiliteCampagne::peutRecevoir('quelquundautre@example.invalid', $u))->toBeTrue(
            "l’instrument refuse une adresse jamais opposee dans l’univers « {$u} » : il ne discrimine rien",
        );
    }

    expect(DB::table('opt_out')->where('email_hash', hash('sha256', 'quelquundautre@example.invalid'))->exists())
        ->toBeFalse();
});

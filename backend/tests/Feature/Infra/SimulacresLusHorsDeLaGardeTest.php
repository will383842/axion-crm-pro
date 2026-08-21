<?php

/**
 * GARDE — QUI LIT UN DRAPEAU DE SIMULACRE SANS PASSER PAR LA GARDE `C18-016` ?
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CETTE GARDE A TROUVÉ, LE 2026-08-21, EN PRODUCTION
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `MockServicesProvider::drapeau()` REFUSE tout simulacre en production, et son
 * refus n'est pas contournable — c'est le correctif du constat `C18-016 /
 * F37-002` (S0). Mais il ne protège que ce qui passe par LE CONTENEUR.
 *
 * Or `docker inspect axion-crm-api` rend, en production :
 *
 *     MOCK_SCRAPERS=true       MOCK_CAPTCHA=true      MOCK_SMTP=true
 *     MOCK_FRANCE_TRAVAIL=true MOCK_PROXIES=true      MOCK_LLM=true
 *
 * Et trois sites lisent `env('MOCK_SCRAPERS')` **en direct**, sans jamais
 * demander au conteneur :
 *
 *   `LaunchCampaignJob:109` ......... les `ScraperRun` d'une campagne sont
 *                                     créés en statut `cancelled`, avec
 *                                     « MOCK_SCRAPERS=true: Phase B Webshare
 *                                     non activée »
 *   `LaunchZoneScrapingJob:423` ..... idem
 *   `WaterfallOrchestrator:446` ..... l'enrichissement prend le chemin simulé
 *
 * *Le refus est donc vrai pour les services, et faux pour les jobs.* Une garde
 * qui protège la moitié d'un chemin protège zéro chemin.
 *
 * ── LE MOTIF ÉTAIT CONNU, ET N'A PAS ÉTÉ PROPAGÉ ──────────────────────────
 *
 * `MagicLinkService:59` et `PasswordResetController:55` portent en commentaire
 * ce défaut EXACT, corrigé chez eux : « `env('MOCK_MODE', true)` rendait alors
 * TRUE en production ». Deux sites réparés, huit laissés.
 *
 * ⚠️ Et le défaut de forme est le même partout : `(bool) env(...)` au lieu de
 * `filter_var(..., FILTER_VALIDATE_BOOLEAN)`. En PHP, `(bool) "off"` vaut
 * **`true`** — un opérateur qui croit désactiver un simulacre l'active.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CETTE GARDE FIGE AU LIEU DE RÉPARER
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Router ces trois sites vers `drapeau()` ferait basculer la production sur le
 * VRAI scraping — avec des coûts (proxies, captcha) et une conséquence
 * mesurée : `TwoCaptchaSolver::solve()` **lève une exception**, il n'est pas
 * implémenté (« requires Sprint 7 »). Le simulacre refusé, la résolution de
 * captcha ne fabrique plus de données : elle casse.
 *
 * *Corriger le contournement sans décider d'abord si le scraping doit être
 * vivant en production, ce serait échanger un défaut silencieux contre une
 * panne bruyante que personne n'a demandée.* **Arbitrage à Will**, et il est
 * porté au registre.
 *
 * Cette garde fige donc l'inventaire : elle rougit si quelqu'un AJOUTE un
 * contournement, et elle rougit aussi si quelqu'un en RETIRE un sans mettre à
 * jour la liste — auquel cas c'est une bonne nouvelle à enregistrer.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * Les fichiers PHP de `app/`, en ne se fiant PAS à `RecursiveDirectoryIterator`.
 *
 * ⚠️ Mesure de cette campagne : cet itérateur a TRONQUÉ le parcours dans 14
 * gardes sur 56 — il rendait 42 fichiers sur 300 sans le dire. Une garde qui
 * inspecte moins de fichiers qu'elle ne croit passe au vert pour rien.
 *
 * @return list<string>
 */
function simulacresFichiersPhp(string $racine): array
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
            $trouves = array_merge($trouves, simulacresFichiersPhp($chemin));

            continue;
        }

        if (str_ends_with($entree, '.php')) {
            $trouves[] = $chemin;
        }
    }

    return $trouves;
}

/**
 * Les lectures directes de `env('MOCK_…')` dans `app/`, hors du fournisseur.
 *
 * @return array<string, int> chemin relatif => nombre d'occurrences
 */
function simulacresLusEnDirect(): array
{
    $racine = realpath(app_path());
    $occurrences = [];

    foreach (simulacresFichiersPhp((string) $racine) as $chemin) {
        $relatif = str_replace('\\', '/', substr($chemin, strlen((string) $racine) + 1));

        // Le fournisseur est le SEUL endroit légitime : c'est lui qui refuse.
        if ($relatif === 'Providers/MockServicesProvider.php') {
            continue;
        }

        $code = (string) file_get_contents($chemin);

        // ⚠️ On compte les APPELS, pas les mentions. Les fichiers réparés
        // EXPLIQUENT le défaut en citant la forme fautive dans un commentaire ;
        // un grep naïf les accuserait tous. `token_get_all()` sait séparer le
        // code des commentaires, aucune expression régulière ne le sait sur du
        // PHP. (Idiome repris de `C21-001/twins`, qui l'a payé avant nous.)
        $seulementLeCode = '';
        foreach (token_get_all($code) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $seulementLeCode .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        $n = preg_match_all('/\benv\(\s*[\'"]MOCK_[A-Z_]+[\'"]/', $seulementLeCode);

        if ($n > 0) {
            $occurrences[$relatif] = $n;
        }
    }

    ksort($occurrences);

    return $occurrences;
}

// ── TÉMOIN — l'instrument voit-il seulement quelque chose ? ──────────────────

test('C18-016/contournements — TEMOIN : le detecteur LIT vraiment `app/`', function () {
    // Sans ce témoin, un `app/` introuvable rendrait une liste vide, et
    // l'inventaire ci-dessous passerait au vert en n'ayant rien inspecté.
    // C'est le pire des verts, et cette campagne l'a déjà payé une fois.
    $fichiers = simulacresFichiersPhp((string) realpath(app_path()));

    // 294 fichiers mesures le 2026-08-21 (`find app -name '*.php' | wc -l`), et
    // le parcours ci-dessus en rend exactement autant. Le seuil est pose SOUS
    // ce chiffre : il attrape une troncature grossiere sans rougir a chaque
    // fichier supprime. Un seuil devine plutot que mesure a deja fait rougir ce
    // temoin pour rien.
    expect(count($fichiers))->toBeGreaterThan(
        250,
        'Le detecteur ne voit que ' . count($fichiers) . ' fichiers dans `app/`. '
        . 'Il en existe bien davantage : le parcours est TRONQUE, et tout verdict '
        . 'rendu par ce fichier est sans valeur.',
    );
});

test('C18-016/contournements — TEMOIN : un commentaire qui CITE la forme fautive n est pas compte', function () {
    // `MagicLinkService` et `PasswordResetController` expliquent le défaut en
    // citant `env('MOCK_MODE', true)` dans un commentaire. S'ils étaient
    // comptés, l'inventaire accuserait deux fichiers réparés.
    $inventaire = simulacresLusEnDirect();

    expect(array_key_exists('Services/Auth/MagicLinkService.php', $inventaire))->toBeFalse(
        'Le detecteur compte les COMMENTAIRES : il accuse un fichier repare, qui ne fait '
        . 'que documenter le defaut. L inventaire entier est alors faux.',
    );
    expect(array_key_exists('Http/Controllers/Api/Auth/PasswordResetController.php', $inventaire))
        ->toBeFalse('Meme defaut que ci-dessus, sur le second fichier repare.');
});

// ── L'INVENTAIRE FIGÉ ────────────────────────────────────────────────────────

test('C18-016/contournements — l inventaire des lectures directes est FIGE', function () {
    // Mesure du 2026-08-21. Ces huit fichiers lisent un drapeau de simulacre
    // sans passer par `MockServicesProvider::drapeau()`, donc sans son refus.
    //
    // Les trois premiers sont ceux qui comptent : ils décident du scraping, et
    // la production porte `MOCK_SCRAPERS=true`.
    $attendu = [
        'Console/Commands/BlacklistsCheck.php' => 1,
        'Console/Commands/ImportIgnAdminExpress.php' => 2,
        'Console/Commands/ImportNaf.php' => 1,
        'Console/Commands/ImportRpps.php' => 2,
        'Console/Commands/ProspectionEnrich.php' => 0,
        'Console/Commands/SignalsNightlyScan.php' => 1,
        'Jobs/LaunchCampaignJob.php' => 1,
        'Jobs/LaunchZoneScrapingJob.php' => 1,
        'Services/Waterfall/WaterfallOrchestrator.php' => 2,
    ];

    $reel = simulacresLusEnDirect();

    // On compare les FICHIERS d'abord : le message est alors lisible.
    expect(array_keys($reel))->toBe(
        array_keys(array_filter($attendu, static fn (int $n): bool => $n > 0)),
        "L'inventaire des lectures directes de `env('MOCK_…')` a CHANGE.\n"
        . 'Constate : ' . json_encode($reel, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
        . "Si un fichier a DISPARU de la liste, c'est une bonne nouvelle : mettre a jour\n"
        . "`\$attendu` et le dire au registre (C18-016).\n"
        . "Si un fichier est APPARU, c'est un contournement neuf de la garde S0 : le\n"
        . "simulacre qu'il lit ne sera PAS refuse en production.",
    );
});

test('C18-016/contournements — le scraping est decide HORS de la garde, et la production le simule', function () {
    // 🔑 LE CŒUR DU CONSTAT, isolé pour qu'il ne se perde pas dans l'inventaire.
    //
    // `docker inspect axion-crm-api` (2026-08-21) : `MOCK_SCRAPERS=true`.
    // Ces trois sites ne demandent pas au conteneur, donc le refus de
    // `drapeau()` ne les atteint pas : en production, les runs de campagne sont
    // créés `cancelled` et l'enrichissement prend le chemin simulé.
    $reel = simulacresLusEnDirect();

    foreach ([
        'Jobs/LaunchCampaignJob.php',
        'Jobs/LaunchZoneScrapingJob.php',
        'Services/Waterfall/WaterfallOrchestrator.php',
    ] as $fichier) {
        // ⚠️ `array_key_exists(...)->toBeTrue($message)` et NON
        // `toHaveKey($cle, $message)` : le SECOND argument de `toHaveKey` est une
        // VALEUR attendue, pas un message — le message y devient la valeur
        // cherchee, et l'assertion echoue en comparant « 1 » a une phrase.
        // Troisieme piege de cette famille dans la campagne, apres `toContain`
        // (variadique) et `toBe`. Les messages ne se passent qu'aux matchers qui
        // prennent une seule valeur.
        expect(array_key_exists($fichier, $reel))->toBeTrue(
            "BONNE NOUVELLE, et il faut la traiter : `{$fichier}` ne lit plus `env('MOCK_…')` "
            . 'en direct. Si le site passe desormais par `MockServicesProvider`, retirer ce '
            . 'fichier de la liste ci-dessus ET fermer le volet correspondant de C18-016.',
        );
    }

    $this->markTestIncomplete(
        'C18-016, second volet : le refus de simulacre en production ne couvre QUE les '
        . 'services resolus par le conteneur. Trois sites lisent `env(MOCK_SCRAPERS)` en '
        . 'direct et y echappent ; la production porte `MOCK_SCRAPERS=true`. '
        . 'NON REPARE DELIBEREMENT : router ces sites vers la garde ferait basculer la '
        . 'production sur le vrai scraping, alors que `TwoCaptchaSolver::solve()` LEVE une '
        . 'exception (« requires Sprint 7 »). Decider d abord si le scraping doit etre vivant '
        . 'en production — arbitrage a Will, porte au registre.',
    );
});

<?php

/**
 * GARDE : `MOCKS-STRATEGY.md` NE DÉCRIT QUE DU CODE QUI EXISTE — constat H44-009 (S3).
 *
 * LE DÉFAUT.
 *
 * Le tableau « Principe directeur » de `MOCKS-STRATEGY.md` est lu comme un
 * INVENTAIRE : c'est là qu'on va chercher quel contrat couvre quel service, et
 * quelle classe le sert en production. Mesure du 2026-08-22, il en inventait
 * trois choses :
 *
 *   · le contrat `DnsManager` et son double `MockDnsManager` — `backend/app/Contracts/`
 *     contient 14 fichiers, aucun ne s'appelle ainsi ; `find backend/app -name "Mock*.php"`
 *     rend 15 doubles, aucun non plus ;
 *   · le contrat `EmailSender` et son double `MockEmailSender` — idem ;
 *   · `RealSmtpProber` comme implémentation de production du sondage SMTP, alors que
 *     `MockServicesProvider.php:110` câble `HunterSmtpProber` depuis le sprint H2 (le
 *     sondage direct sur le port 25 faisait bannir l'IP Hetzner par Spamhaus).
 *     `RealSmtpProber` existe encore en classe, pour un repli manuel, mais n'est plus
 *     branché.
 *
 * Un inventaire qui invente coûte plus cher qu'un inventaire absent : on cherche
 * pendant une heure une classe que personne n'a écrite, puis on doute de tout le
 * reste du document. Et la troisième erreur est la plus coûteuse des trois — elle
 * envoie déboguer la mauvaise classe le jour où le sondage SMTP se comporte mal.
 *
 * CE QUE CETTE GARDE MESURE.
 *
 *   1. toute classe ou interface citée EN BACKTICKS dans une ligne du tableau
 *      existe réellement sous `backend/app/` ;
 *   2. quand une ligne nomme une implémentation de production ET que le provider
 *      branche ce contrat, la classe branchée est bien l'une de celles que la
 *      ligne nomme. C'est cette seconde règle qui aurait vu `RealSmtpProber`.
 *
 * ⚠️ Elle inspecte les LIGNES DU TABLEAU, pas le fichier entier : la prose peut
 * citer `DnsManager` pour dire qu'il n'existe pas — ce que fait justement la note
 * posée sous le tableau — sans faire rougir la garde. Une garde qui interdit de
 * NOMMER le défaut interdit de l'expliquer.
 */

use Tests\TestCase;

uses(TestCase::class);

/** Racine du dépôt : `MOCKS-STRATEGY.md` vit AU-DESSUS de l'application Laravel. */
function racineDepotSimulacres(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les lignes de DONNÉES du tableau « Principe directeur », découpées en cellules.
 *
 * On ne garde que les lignes à quatre cellules ou plus, hors en-tête et hors
 * ligne de séparation : le document contient d'autres tableaux plus bas.
 *
 * @return list<list<string>>
 */
function lignesDuTableauDesSimulacres(): array
{
    $chemin = racineDepotSimulacres() . '/MOCKS-STRATEGY.md';
    $contenu = file_exists($chemin) ? (string) file_get_contents($chemin) : '';
    $lignes = [];

    foreach (preg_split('/\R/u', $contenu) ?: [] as $ligne) {
        $ligne = trim($ligne);

        if (! str_starts_with($ligne, '|')) {
            continue;
        }

        // Ligne de séparation `|---|---|` : aucune donnée.
        if (preg_match('/^\|[\s:|-]+$/', $ligne) === 1) {
            continue;
        }

        $cellules = array_map('trim', array_slice(explode('|', $ligne), 1, -1));

        if (count($cellules) < 4 || $cellules[1] === 'Interface') {
            continue;
        }

        $lignes[] = array_values($cellules);
    }

    return $lignes;
}

/**
 * Les noms de classes cités en backticks dans une cellule.
 *
 * Filtre sur la casse Pascal : la cellule des doubles cite aussi
 * `http://localhost:0`, qui n'est pas une classe, et la citer ferait rougir la
 * garde sur une valeur de retour.
 *
 * @return list<string>
 */
function classesCiteesDansLaCellule(string $cellule): array
{
    preg_match_all('/`([^`]+)`/u', $cellule, $trouves);

    return array_values(array_filter(
        $trouves[1] ?? [],
        fn (string $jeton): bool => preg_match('/^[A-Z][A-Za-z0-9]+$/', $jeton) === 1,
    ));
}

/**
 * Toutes les classes PHP de `backend/app/`, par nom court.
 *
 * ⚠️ scandir récursif, PAS `RecursiveDirectoryIterator` : mesure de cette
 * campagne, cet itérateur a TRONQUÉ le parcours dans 14 gardes sur 56 — il
 * rendait 42 fichiers sur 300 sans le dire. Ici, une troncature ferait rougir la
 * garde sur des classes qui existent : le crier au loup qui fait désactiver.
 *
 * @return array<string, true>
 */
function classesDeLApplication(?string $racine = null): array
{
    $racine ??= app_path();
    $trouvees = [];
    $entrees = scandir($racine);

    if ($entrees === false) {
        return $trouvees;
    }

    foreach ($entrees as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }

        $chemin = $racine . DIRECTORY_SEPARATOR . $entree;

        if (is_dir($chemin)) {
            $trouvees += classesDeLApplication($chemin);

            continue;
        }

        if (str_ends_with($entree, '.php')) {
            $trouvees[substr($entree, 0, -4)] = true;
        }
    }

    return $trouvees;
}

test('H44-009 — TEMOIN : le banc voit le tableau et le corpus de classes', function () {
    // Mesure du 2026-08-22 : 13 lignes de service dans le tableau, après retrait
    // des deux lignes fantômes. Le plancher est volontairement dessous.
    expect(count(lignesDuTableauDesSimulacres()))->toBeGreaterThan(
        8,
        'Le banc ne lit que ' . count(lignesDuTableauDesSimulacres()) . ' lignes du tableau de '
        . "`MOCKS-STRATEGY.md`. Une garde qui n'inspecte presque rien certifie presque rien : "
        . 'verifie la racine du depot et le decoupage des cellules.',
    );

    expect(count(classesDeLApplication()))->toBeGreaterThan(
        100,
        'Le banc ne voit que ' . count(classesDeLApplication()) . " classes sous `backend/app/`. "
        . 'Le parcours est tronque, et la garde va crier au loup sur des classes qui existent. '
        . 'Repare `classesDeLApplication()` avant de la croire.',
    );
});

test('H44-009 — TEMOIN NEGATIF : le balayage voit bien les deux lignes fantomes d origine', function () {
    // La ligne exacte retiree le 2026-08-22.
    $fantome = '| Cloudflare/Hetzner DNS | `DnsManager` | API Cloudflare/Hetzner | `MockDnsManager` no-op |';
    $cellules = array_map('trim', array_slice(explode('|', $fantome), 1, -1));

    expect(classesCiteesDansLaCellule($cellules[1]))->toBe(
        ['DnsManager'],
        "Le balayage ne reconnait plus le contrat cite dans une cellule. Une garde qu'on n'a "
        . 'jamais vue rouge ne garde rien : repare `classesCiteesDansLaCellule()`.',
    );

    expect(array_key_exists('DnsManager', classesDeLApplication()))->toBeFalse(
        '`DnsManager` existe desormais sous `backend/app/` : le constat H44-009 disait le '
        . "contraire, et ce temoin negatif ne prouve plus rien. Remplace-le par un nom qui "
        . "n'existe pas, sinon la garde principale ne mesure plus l'absence.",
    );

    // Et il doit se TAIRE sur ce qui n'est pas une classe.
    expect(classesCiteesDansLaCellule('`MockProxyProvider` retournant `http://localhost:0` no-op'))
        ->toBe(
            ['MockProxyProvider'],
            'Le balayage prend `http://localhost:0` pour un nom de classe : il rougira '
            . 'eternellement sur une valeur de retour. Resserre le filtre de casse Pascal.',
        );
});

test('H44-009 — toute classe citee par le tableau de MOCKS-STRATEGY.md existe vraiment', function () {
    $classes = classesDeLApplication();
    $fantomes = [];

    foreach (lignesDuTableauDesSimulacres() as $cellules) {
        foreach ([$cellules[1], $cellules[2], $cellules[3]] as $cellule) {
            foreach (classesCiteesDansLaCellule($cellule) as $nom) {
                if (! array_key_exists($nom, $classes)) {
                    $fantomes[] = "{$nom}  (ligne « {$cellules[0]} »)";
                }
            }
        }
    }

    expect($fantomes)->toBe(
        [],
        "`MOCKS-STRATEGY.md` nomme des classes qui n'existent nulle part sous `backend/app/`.\n\n"
        . "Ce tableau est lu comme un INVENTAIRE : c'est la qu'on cherche quel contrat couvre "
        . "quel service. Un inventaire qui invente coute plus cher qu'un inventaire absent — on "
        . "cherche pendant une heure une classe que personne n'a ecrite, puis on doute de tout "
        . "le reste du document (constat H44-009).\n\n"
        . 'GESTE : retire la ligne, ou ecris la classe. Ne la marque pas « bientot » : le '
        . "tableau decrit ce qui EST branche, pas ce qui est envisage.\n\n"
        . "Fantomes :\n  - " . implode("\n  - ", $fantomes),
    );
});

test('H44-009 — l implementation de production annoncee est bien celle que le provider branche', function () {
    $provider = (string) file_get_contents(app_path('Providers/MockServicesProvider.php'));
    $ecarts = [];

    foreach (lignesDuTableauDesSimulacres() as $cellules) {
        $contrats = classesCiteesDansLaCellule($cellules[1]);
        $annoncees = classesCiteesDansLaCellule($cellules[2]);

        // Les lignes dont la colonne « prod » est en prose (« Playwright + proxy
        // residentiel ») ne promettent aucune classe : rien a comparer.
        if ($contrats === [] || $annoncees === []) {
            continue;
        }

        $motif = '/\$bind\(' . preg_quote($contrats[0], '/') . '::class,\s*(\w+)::class/';

        if (preg_match($motif, $provider, $capture) !== 1) {
            continue;
        }

        if (! in_array($capture[1], $annoncees, true)) {
            $ecarts[] = "{$contrats[0]} : le tableau annonce " . implode(' ou ', $annoncees)
                . ", le provider branche {$capture[1]}";
        }
    }

    expect($ecarts)->toBe(
        [],
        "`MOCKS-STRATEGY.md` nomme une implementation de production que "
        . "`MockServicesProvider::register()` ne branche PAS.\n\n"
        . "C'est l'erreur la plus couteuse du constat H44-009 : elle envoie deboguer la mauvaise "
        . "classe le jour ou le service se comporte mal. Le cas mesure le 2026-08-22 : le "
        . "tableau annoncait `RealSmtpProber` pour le sondage SMTP, alors que le sprint H2 avait "
        . "bascule sur `HunterSmtpProber` (le sondage direct sur le port 25 faisait bannir l'IP "
        . "Hetzner par Spamhaus). `RealSmtpProber` existe toujours en classe, pour un repli "
        . "manuel, mais n'est plus wire — cf. `MockServicesProvider.php:108-109`.\n\n"
        . "GESTE : aligne la colonne « Implementation prod » sur le `\$bind` du provider, et dis "
        . "en une ligne ce que devient l'ancienne classe si elle survit.\n\n"
        . "Ecarts :\n  - " . implode("\n  - ", $ecarts),
    );
});

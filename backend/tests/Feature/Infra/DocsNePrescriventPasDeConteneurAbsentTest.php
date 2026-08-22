<?php

/**
 * GARDE : LE README ET LE CONTRIBUTING NE PRESCRIVENT QUE DES CONTENEURS QUI
 * EXISTENT — constat A09-010 (S3).
 *
 * CE QUI ETAIT ECRIT, ET QUI NE POUVAIT PAS S'EXECUTER.
 *
 *     README.md:85        docker exec axion-crm-worker-google-maps pnpm test
 *     CONTRIBUTING.md:77  docker exec axion-crm-worker-google-maps pnpm typecheck
 *     CONTRIBUTING.md:78  docker exec axion-crm-worker-google-maps pnpm test
 *
 * Mesure du 2026-08-22 : `docker-compose.yml` declare HUIT services, et pas un
 * seul worker — postgres, redis, api, horizon, reverb, scheduler, app, caddy.
 * Le conteneur `axion-crm-worker-google-maps` n'a jamais existe. Le README
 * annonçait par ailleurs une stack « + workers » qu'il ne demarre pas.
 *
 * POURQUOI CELA MERITE UNE GARDE. Ces trois lignes sont la PROCEDURE d'avant
 * push : celui qui les suit voit `Error: No such container`, croit son poste
 * mal monte, et finit par pousser sans avoir joue les tests des workers. Une
 * commande impossible dans un CONTRIBUTING ne fait pas perdre du temps une
 * fois : elle en fait perdre a chaque nouvel arrivant.
 *
 * CE QUE CETTE GARDE MESURE. Que chaque `docker exec <nom>` des deux documents
 * vise un `container_name` reellement declare dans `docker-compose.yml`, et que
 * les scripts pnpm prescrits en remplacement existent bien dans
 * `workers/package.json`.
 *
 * CE QU'ELLE NE MESURE PAS, ET LE DIT : elle ne JOUE aucune commande. Que
 * `pnpm test` passe dans `workers/` releve du banc, pas d'ici — vingt agents
 * partagent une seule base et une suite lancee a l'aveugle ne prouve rien.
 * Elle verifie que la commande prescrite DESIGNE quelque chose qui existe.
 */

use PHPUnit\Framework\Assert;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotA09010(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function lireFichierRacineA09010(string $relatif): string
{
    $chemin = racineDepotA09010() . '/' . $relatif;

    Assert::assertFileExists(
        $chemin,
        "{$relatif} est introuvable. En local, la copie du depot dans le conteneur de banc se "
        . 'fait effacer par les autres agents : re-copier avant de croire ce resultat.',
    );

    $contenu = (string) file_get_contents($chemin);

    Assert::assertNotSame('', trim($contenu), "{$relatif} est vide : la garde ne mesurerait rien.");

    return $contenu;
}

/**
 * Les `container_name` declares par la stack locale.
 *
 * On lit les NOMS DE CONTENEUR, pas les noms de service : c'est ce que
 * `docker exec` attend, et c'est la ou l'ecart s'est produit.
 *
 * @return list<string>
 */
function conteneursDeclaresA09010(): array
{
    preg_match_all(
        '/^\s*container_name:\s*([A-Za-z0-9_.-]+)\s*$/m',
        lireFichierRacineA09010('docker-compose.yml'),
        $captures,
    );

    return $captures[1];
}

/**
 * Les conteneurs qu'un document prescrit d'attaquer au `docker exec`.
 *
 * @return list<string>
 */
function conteneursPrescritsA09010(string $contenu): array
{
    preg_match_all('/docker exec\s+(?:-\S+\s+)*([A-Za-z0-9_.-]+)/', $contenu, $captures);

    return array_values(array_unique($captures[1]));
}

test('A09-010 — TEMOIN : la garde voit bien la stack et les procedures', function (): void {
    // Une garde qui ne trouve ni conteneur ni commande passerait au vert en
    // n'ayant rien lu. C'est le pire des verts, et ce depot l'a deja paye.
    $declares = conteneursDeclaresA09010();

    expect(count($declares))->toBeGreaterThanOrEqual(
        8,
        'docker-compose.yml rend moins de 8 `container_name`. Mesure du 2026-08-22 : il y en a '
        . '8 (postgres, redis, api, horizon, reverb, scheduler, app, caddy). Geste : verifier '
        . "que le fichier lu est bien celui de la racine. Vus : \n" . implode("\n", $declares),
    );

    $prescrits = array_merge(
        conteneursPrescritsA09010(lireFichierRacineA09010('README.md')),
        conteneursPrescritsA09010(lireFichierRacineA09010('CONTRIBUTING.md')),
    );

    expect(count($prescrits))->toBeGreaterThanOrEqual(
        2,
        'aucun `docker exec` n\'a ete trouve dans README.md / CONTRIBUTING.md : l\'extraction '
        . 'ne fonctionne plus, et l\'egalite du test suivant serait satisfaite par le vide.',
    );
});

test('A09-010 — TEMOIN NEGATIF : l extraction sait reperer un conteneur absent', function (): void {
    // Sans ce temoin, un motif casse rendrait un tableau vide et le controle
    // suivant certifierait une conformite qu'il n'aurait pas inspectee.
    expect(conteneursPrescritsA09010('docker exec axion-crm-worker-google-maps pnpm test'))
        ->toBe(['axion-crm-worker-google-maps']);

    // Et il ne confond pas une prose qui NOMME le conteneur fantome avec une
    // commande qui l'invoque : les deux documents en parlent desormais.
    expect(conteneursPrescritsA09010('il n\'existe aucun conteneur `axion-crm-worker-*`'))->toBe([]);
});

test('A09-010 — chaque `docker exec` du README et du CONTRIBUTING vise un conteneur DECLARE', function (): void {
    $declares = conteneursDeclaresA09010();

    foreach (['README.md', 'CONTRIBUTING.md'] as $document) {
        $prescrits = conteneursPrescritsA09010(lireFichierRacineA09010($document));
        $fantomes = array_values(array_diff($prescrits, $declares));

        expect($fantomes)->toBe(
            [],
            "{$document} prescrit `docker exec` vers un ou des conteneurs que docker-compose.yml "
            . "ne declare pas : " . implode(', ', $fantomes) . ". C'est le constat A09-010 : "
            . "celui qui suit la procedure lit « No such container », croit son poste mal monte, "
            . "et finit par pousser sans avoir joue ces tests. GESTE : soit declarer le service "
            . "dans docker-compose.yml, soit ecrire la forme HORS conteneur — pour les workers "
            . "Node, `(cd workers && pnpm test)` depuis la racine. Conteneurs declares : "
            . implode(', ', $declares),
        );
    }
});

test('A09-010 — la commande de remplacement designe des scripts qui EXISTENT', function (): void {
    // Le risque de ce correctif etait de remplacer une commande impossible par
    // une autre. On ne peut pas la JOUER ici (banc partage), mais on peut
    // verifier qu'elle designe quelque chose : le dossier, et les deux scripts.
    $chemin = racineDepotA09010() . '/workers/package.json';

    expect(is_file($chemin))->toBeTrue(
        'workers/package.json est introuvable : le README et le CONTRIBUTING prescrivent '
        . '`(cd workers && pnpm test)` vers un dossier absent — la meme faute que A09-010, '
        . 'deplacee.',
    );

    $scripts = json_decode((string) file_get_contents($chemin), true)['scripts'] ?? [];

    foreach (['test', 'typecheck'] as $script) {
        expect(is_array($scripts) && array_key_exists($script, $scripts))->toBeTrue(
            "workers/package.json ne declare pas le script `{$script}`, que README.md et "
            . 'CONTRIBUTING.md prescrivent de lancer. GESTE : ajouter le script, ou corriger '
            . 'la commande prescrite dans les deux documents — jamais l\'un sans l\'autre.',
        );
    }
});

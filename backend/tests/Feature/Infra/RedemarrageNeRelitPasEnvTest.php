<?php

/**
 * GARDE OPÉRATIONNELLE — audit 360, A07-003 (S0) et sa famille.
 *
 * `docker compose restart` **ne relit pas `env_file`** : il relance le processus
 * DANS le conteneur existant, dont l'environnement a été figé à la création.
 * Seul `up -d` recrée le conteneur et relit les variables.
 *
 * Conséquence mesurée le 2026-08-19 : le runbook de rotation des secrets
 * prescrivait `restart` — un secret réputé tourné ne l'était pas. Et pire,
 * `configure-prod-env.sh`, le script qui ÉCRIT les variables de production, se
 * terminait par un `restart` : aucune des variables qu'il venait d'écrire n'était
 * appliquée. L'opérateur repartait convaincu du contraire.
 *
 * Ce n'est pas une panne, c'est une fausse assurance — et c'est pire.
 */

use Tests\TestCase;

uses(TestCase::class);

/** @return list<string> */
function scriptsInfra(): array
{
    $racine = realpath(base_path('../infra/scripts'));
    if ($racine === false || ! is_dir($racine)) {
        return [];
    }

    $trouves = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine)) as $fichier) {
        if ($fichier->isFile() && str_ends_with($fichier->getFilename(), '.sh')) {
            $trouves[] = $fichier->getPathname();
        }
    }

    return $trouves;
}

test('A07-003 — aucun script d infrastructure ne PRESCRIT « docker compose restart »', function () {
    $fautifs = [];

    foreach (scriptsInfra() as $chemin) {
        foreach (file($chemin) as $numero => $ligne) {
            // On ne vise que les lignes qui EXECUTENT la commande : une mention
            // en commentaire — comme l'avertissement qui explique le piège — est
            // légitime et doit le rester.
            if (preg_match('/^\s*docker\s+compose\s+restart\b/', $ligne) === 1) {
                $fautifs[] = basename($chemin) . ':' . ($numero + 1);
            }
        }
    }

    expect($fautifs)->toBe([]);
});

test('A07-003 — le script qui ECRIT les variables de production les VERIFIE ensuite', function () {
    $chemin = base_path('../infra/scripts/configure-prod-env.sh');
    expect(file_exists($chemin))->toBeTrue();

    $contenu = file_get_contents($chemin);

    // Il recrée les conteneurs, il ne se contente pas de les relancer.
    expect($contenu)->toContain('docker compose up -d');

    // Et il LIT ce qui est réellement dans le conteneur au lieu de le supposer.
    // Sans ce contrôle, l'erreur était indétectable par celui qui l'exécutait.
    expect($contenu)->toContain('docker inspect');
});

test('A07-003 — TEMOIN : la garde SAIT reperer une prescription fautive', function () {
    // Sans ce témoin, une garde qui ne trouverait jamais rien — parce que son
    // motif est faux — passerait pour une bonne nouvelle.
    expect(preg_match('/^\s*docker\s+compose\s+restart\b/', "docker compose restart api horizon\n"))->toBe(1);

    // Et elle ne se déclenche PAS sur une mention en commentaire.
    expect(preg_match('/^\s*docker\s+compose\s+restart\b/', "# docker compose restart …\n"))->toBe(0);
});

test('A07-003 — la garde a bien des fichiers a inspecter', function () {
    // Un « aucun fautif » sur zéro fichier ne vaut rien.
    expect(count(scriptsInfra()))->toBeGreaterThan(3);
});

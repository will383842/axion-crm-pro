<?php

/**
 * GARDE DE LA JOURNALISATION DES CONNEXIONS À LA BASE.
 *
 * Mesure n° 7 du registre des violations de données
 * (`_REPORTS/REGISTRE-DES-VIOLATIONS-DE-DONNEES.md`), identifiée le 2026-08-19
 * et restée non réalisée jusqu'au 2026-08-23.
 *
 * ── POURQUOI CETTE GARDE EXISTE ─────────────────────────────────────────────
 *
 * La base de production a été joignable depuis internet pendant 94 jours, avec
 * un mot de passe publié en clair dans un dépôt public. Au moment de décider
 * s'il fallait notifier la CNIL au titre de l'article 33 du RGPD, la seule
 * phrase qu'on a pu écrire est celle-ci, et elle est dans le registre :
 *
 *   « Il n'est pas possible de démontrer qu'aucun accès non autorisé n'a eu
 *     lieu : les journaux de connexion de PostgreSQL n'étaient pas conservés.
 *     L'absence d'effet constaté n'est donc pas une preuve d'absence d'accès. »
 *
 * Sans ces réglages, le prochain incident se solde par la MÊME phrase.
 *
 * ── CE QUE CETTE GARDE NE PROUVE PAS, ET IL FAUT LE DIRE ────────────────────
 *
 * 🔴 Elle lit un FICHIER. Elle ne dit RIEN du serveur qui tourne.
 *
 * C'est exactement le piège que ce dépôt a déjà payé : le correctif qui retirait
 * la publication des ports a été fusionné, déployé avec succès, et n'avait RIEN
 * FERMÉ — parce qu'un déploiement ne recrée pas les conteneurs de base de
 * données. Une garde de fichier aurait certifié « conforme » pendant que la
 * porte restait ouverte.
 *
 * Le seul contrôle qui mesure la RÉALITÉ est
 * `infra/scripts/verifier-journalisation-connexions-db.sh`, qui interroge
 * PostgreSQL lui-même par `SHOW`. Cette garde-ci empêche la RÉGRESSION du
 * réglage dans le dépôt ; elle ne remplace pas ce script, et ne prétend pas le
 * faire.
 *
 * ── POURQUOI DANS LE FICHIER DE BASE ────────────────────────────────────────
 *
 * Le constat F38-007 a dénombré DOUZE chemins qui lancent `docker compose up`
 * sans `docker-compose.prod.yml`. Un réglage posé dans le seul overlay
 * laisserait chacun de ces douze chemins recréer un Postgres muet. La garde
 * vérifie donc que le réglage est bien dans `docker-compose.yml`.
 */

use function PHPUnit\Framework\assertNotFalse;

/** Le bloc du service `postgres` de `docker-compose.yml`, brut. */
function blocPostgresDuCompose(): string
{
    $chemin = dirname(__DIR__, 3) . '/../docker-compose.yml';
    assertNotFalse(realpath($chemin), "docker-compose.yml introuvable depuis {$chemin}");

    $contenu = file_get_contents(realpath($chemin));
    assertNotFalse($contenu, 'docker-compose.yml illisible');

    // 🔴 NORMALISER LES FINS DE LIGNE AVANT DE CHERCHER.
    //
    // Ce fichier est en CRLF sur le poste où il est édité. Sans cette ligne,
    // `^  postgres:\n` ne rencontre JAMAIS rien : la garde annonce « service
    // introuvable » au lieu de mesurer ce qu'elle est censée mesurer — elle
    // rougit pour la mauvaise raison, ce qui est une autre façon de ne rien
    // garder. Vu en vrai le 2026-08-23, avant que cette garde ne soit déclarée
    // bonne : sur le fichier RÉEL, elle ne trouvait pas le bloc.
    $contenu = str_replace("\r\n", "\n", $contenu);

    // Du début du service `postgres:` jusqu'au service suivant (2 espaces + nom).
    if (preg_match('/^  postgres:\n(.*?)(?=^  [a-z0-9_-]+:\n)/ms', $contenu, $m) !== 1) {
        return '';
    }

    return $m[1];
}

test('Le service postgres journalise les connexions ET les deconnexions', function () {
    $bloc = blocPostgresDuCompose();

    expect($bloc)->not->toBe('', 'Le service `postgres` est introuvable dans docker-compose.yml.');

    $manquants = [];
    foreach (['log_connections=on', 'log_disconnections=on'] as $reglage) {
        if (! str_contains($bloc, $reglage)) {
            $manquants[] = $reglage;
        }
    }

    expect($manquants)->toBe(
        [],
        "Le service `postgres` de docker-compose.yml ne journalise pas ses connexions.\n\n"
        . "C'est l'absence de ces journaux qui a empeche, le 2026-08-19, de demontrer "
        . "qu'aucun acces non autorise n'avait eu lieu pendant les 94 jours ou la base a "
        . "ete joignable depuis internet — et qui a rendu la decision de l'article 33 du "
        . "RGPD plus lourde a porter qu'elle n'aurait du l'etre.\n\n"
        . "Correctif : reposer les arguments dans le `command:` du service `postgres`.\n"
        . "Puis MESURER LE SERVEUR QUI TOURNE, jamais le fichier :\n"
        . "  bash infra/scripts/verifier-journalisation-connexions-db.sh\n\n"
        . 'Reglages manquants : ' . implode(', ', $manquants),
    );
});

test('Le prefixe de journal porte %h — sans quoi on ne sait pas D OU vient la connexion', function () {
    $bloc = blocPostgresDuCompose();

    // 🔴 PAS `expect($bloc)->toContain('%h', "message")`.
    //
    // `toContain()` est VARIADIQUE : ses arguments sont tous des motifs à
    // trouver. Le message d'explication était donc cherché DANS le fichier
    // compose, et la garde rougissait sur un produit correct. Vu en CI le
    // 2026-08-23 (run 32639647022) : les deux autres tests du fichier
    // passaient, celui-ci seul tombait — l'accusation portait sur mon
    // instrument, pas sur l'objet.
    expect(str_contains($bloc, '%h'))->toBeTrue(
        "Le `log_line_prefix` du service `postgres` ne porte pas `%h`.\n\n"
        . "Sans ce champ, le journal dit qu'il y a eu des connexions mais pas d'OU elles "
        . "venaient : il ne permet donc pas de distinguer un acces interne d'un acces "
        . "depuis internet. C'est precisement la question a laquelle il a fallu repondre "
        . '« on ne peut pas savoir » le 2026-08-19.',
    );
});

test('TEMOIN NEGATIF : la garde SAIT reperer un service postgres muet', function () {
    // Sans ce temoin, une garde qui ne trouve jamais le bloc `postgres` passerait
    // verte en ne verifiant rien — le defaut recurrent de ce depot.
    $muet = <<<'YAML'
      container_name: axion-crm-postgres
      environment:
        POSTGRES_DB: axion_crm
    YAML;

    expect(str_contains($muet, 'log_connections=on'))->toBeFalse();
    expect(str_contains($muet, '%h'))->toBeFalse();

    // Et le crible reconnait bien la forme CORRECTE, sinon il n'accuserait
    // pas : il refuserait tout le monde.
    $correct = "      command:\n        - postgres\n        - -c\n        - log_connections=on\n"
        . "        - -c\n        - log_disconnections=on\n        - -c\n"
        . '        - "log_line_prefix=%m [%p] %q%u@%d de %h "';

    expect(str_contains($correct, 'log_connections=on'))->toBeTrue();
    expect(str_contains($correct, 'log_disconnections=on'))->toBeTrue();
    expect(str_contains($correct, '%h'))->toBeTrue();
});

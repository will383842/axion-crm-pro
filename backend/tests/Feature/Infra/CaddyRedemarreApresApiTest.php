<?php

/**
 * GARDE — CADDY DOIT REDÉMARRER APRÈS LA RECRÉATION DE `api`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UNE PANNE DE PRODUCTION MESURÉE, LE 2026-08-21
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CORRECTION DU 2026-08-21 (après vérification du journal GitHub Actions).
 * Une première version de cette garde affirmait que le déploiement avait
 * « réussi de bout en bout ». **C'est faux, et l'erreur était de moi.**
 *
 * L'étape `Smoke test prod` du déploiement `377febf` A DÉTECTÉ la panne, et a
 * fait échouer le job :
 *
 *     GET https://api.axion-crm-pro.com/up
 *     curl: (22) The requested URL returned error: 502
 *     ##[error]Health check failed
 *
 * Voici l'état réel, et il est plus intéressant que celui que j'avais écrit :
 *
 *   les cinq conteneurs .............. running (healthy)      ← trompeur
 *   les onze migrations .............. « Aucune migration en attente »
 *   https://app.axion-crm-pro.com .... 200                    ← trompeur
 *   https://api.axion-crm-pro.com/up . 502, pendant treize minutes
 *   le job GitHub Actions ............ ROUGE, dès la 21ᵉ seconde
 *
 * ── CE QUE CELA CHANGE, ET CE QUE CELA NE CHANGE PAS ──────────────────────
 *
 * Le correctif reste exactement le même : Caddy doit redémarrer après la
 * recréation d'`api`, et cette garde le défend.
 *
 * Mais le défaut d'alerte n'est pas celui que je décrivais. La détection
 * EXISTE et elle a fonctionné en vingt et une secondes. Ce qui a manqué, c'est
 * que **personne n'a lu le rouge** : un déploiement en échec n'envoie aucune
 * notification, et treize minutes ont passé avec un job rouge que nul ne
 * regardait. *Une alarme que personne ne reçoit n'est pas une alarme.*
 *
 * Ce qui a égaré le diagnostic sur le moment, ce sont les conteneurs : tous
 * `healthy`, y compris Caddy, pendant que Caddy parlait à une adresse morte.
 * Un `healthcheck` qui interroge le conteneur lui-même ne dit rien de ce qu'il
 * atteint.
 *
 * ── LE MÉCANISME ───────────────────────────────────────────────────────────
 *
 * `docker compose up -d --force-recreate --no-deps api …` DÉTRUIT et RECRÉE le
 * conteneur `api` : Docker lui attribue alors une nouvelle adresse sur le
 * réseau interne. Caddy, lui, n'était ni recréé ni redémarré par ce script — il
 * n'y figurait que dans une boucle de VÉRIFICATION. Il gardait donc l'adresse
 * résolue au démarrage de son propre conteneur, et parlait dans le vide.
 *
 * Un `docker restart axion-crm-caddy` joué à la main a ramené l'API en 200
 * immédiatement.
 *
 * ── POURQUOI CETTE GARDE, ET PAS SEULEMENT LA LIGNE ───────────────────────
 *
 * Parce que la ligne se retire en trois secondes, et que rien ne dirait
 * pourquoi elle était là. C'est le même patron que `F40-005`, déjà noté dans ce
 * fichier de déploiement : *le fichier a raison, le conteneur a tort, et rien
 * ne les réconcilie.* La garde nomme la réconciliation.
 *
 * ⚠️ ELLE EXIGE AUSSI L'ORDRE. Redémarrer Caddy AVANT de recréer `api` ne
 * servirait à rien : il re-résoudrait l'ancienne adresse, celle qui va
 * disparaître. C'est le genre d'inversion qu'une relecture rapide laisse passer.
 */

use Tests\TestCase;

uses(TestCase::class);

function cheminDeploiementCaddy(): string
{
    return (realpath(base_path('..')) ?: base_path('..'))
        . '/.github/workflows/deploy-direct-ssh.yml';
}

test('le workflow de production est LISIBLE depuis le banc', function () {
    $chemin = cheminDeploiementCaddy();

    // ⚠️ TÉMOIN DE PRÉSENCE, et il est payé. Le conteneur `a35r` ne monte pas
    // `.github/` : il en reçoit une COPIE (`docker cp`). Une garde qui lit un
    // fichier absent passerait au vert sans rien avoir mesuré — le pire des
    // verts. Si ce test rougit, ce n'est pas le déploiement qui est cassé,
    // c'est le banc : rejouer `docker cp .github a35r:/var/www/.github`.
    expect(file_exists($chemin))->toBeTrue(
        "Le banc ne voit pas {$chemin}. Copier `.github` dans le conteneur avant de croire "
        . 'le moindre resultat de ce fichier.',
    );

    expect(filesize($chemin))->toBeGreaterThan(2000);
});

test('la production RECREE api, et redemarre Caddy ENSUITE', function () {
    $source = (string) file_get_contents(cheminDeploiementCaddy());

    // 1. la recréation de `api` — c'est elle qui change l'adresse
    $posRecreation = strpos($source, '--force-recreate --no-deps api');
    expect($posRecreation)->not->toBeFalse(
        'La ligne qui recree `api` a change de forme. Si la recreation a disparu, cette garde '
        . 'n a plus d objet ; si elle a seulement ete reecrite, mettre a jour le motif ici.',
    );

    // 2. le redémarrage de Caddy
    $posCaddy = strpos($source, 'docker compose restart caddy');
    expect($posCaddy)->not->toBeFalse(
        "Le deploiement ne redemarre PAS Caddy.\n"
        . 'Recreer `api` lui donne une nouvelle adresse interne ; Caddy garde l ancienne et rend '
        . '502 sur TOUT le domaine api. Mesure du 2026-08-21 : treize minutes de panne, avec les '
        . "cinq conteneurs declares `healthy`.\n"
        . 'Retablir `docker compose restart caddy` apres la recreation d `api`.',
    );

    // 3. 🔑 L'ORDRE. Redémarrer avant, c'est re-résoudre l'adresse qui va mourir.
    expect($posCaddy)->toBeGreaterThan(
        $posRecreation,
        'Caddy est redemarre AVANT la recreation d `api` : il re-resoudrait l adresse qui va '
        . 'disparaitre, et la panne serait exactement la meme.',
    );
});

test('TEMOIN NEGATIF : le detecteur SAIT rougir quand la ligne manque', function () {
    // Sans ce témoin, les deux tests ci-dessus pourraient passer sur n'importe
    // quel texte contenant les bons mots. On fabrique un workflow amputé et on
    // exige que le contrôle le voie.
    $source = (string) file_get_contents(cheminDeploiementCaddy());
    $ampute = str_replace('docker compose restart caddy', '# ligne retiree par le temoin', $source);

    expect(strpos($ampute, 'docker compose restart caddy'))->toBeFalse(
        'Le temoin n a pas reussi a amputer le fichier : il ne prouve donc rien.',
    );
    expect(strpos($ampute, '--force-recreate --no-deps api'))->not->toBeFalse(
        'Le temoin a trop coupe : il aurait fait rougir le premier controle pour la mauvaise raison.',
    );
});

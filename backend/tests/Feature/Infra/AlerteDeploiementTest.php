<?php

/**
 * GARDE — UN DÉPLOIEMENT ROUGE DOIT PRÉVENIR QUELQU'UN.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DÉFAUT N'ÉTAIT PAS LA DÉTECTION. C'ÉTAIT LA LECTURE.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Le 2026-08-21, l'étape `Smoke test prod` a fait exactement son travail :
 *
 *     GET https://api.axion-crm-pro.com/up
 *     curl: (22) The requested URL returned error: 502
 *     ##[error]Health check failed
 *
 * Vingt et une secondes après le déploiement, le job était rouge. Et **il l'est
 * resté treize minutes sans que personne l'apprenne**, pendant que l'API était
 * injoignable. Un déploiement en échec n'envoyait aucune notification : le
 * rouge attendait sur GitHub qu'on vienne le regarder.
 *
 * *Une alarme que personne ne reçoit n'est pas une alarme.*
 *
 * ⚠️ CETTE GARDE NE VÉRIFIE PAS QUE L'ALERTE ARRIVE — aucun test du dépôt ne
 * peut le faire. Elle vérifie que le dispositif est **posé, conditionné et
 * inoffensif** : les quatre propriétés qu'une relecture rapide laisse filer.
 */

use Tests\TestCase;

uses(TestCase::class);

function alerteDeploiementSource(): string
{
    $chemin = (realpath(base_path('..')) ?: base_path('..'))
        . '/.github/workflows/deploy-direct-ssh.yml';

    // ⚠️ TÉMOIN DE PRÉSENCE, et il est payé. Le conteneur du banc ne monte pas
    // `.github/` : il en reçoit une COPIE. Une garde qui lit un fichier absent
    // passerait au vert sans rien avoir mesuré.
    expect(file_exists($chemin))->toBeTrue(
        "Le banc ne voit pas {$chemin}. Rejouer `docker cp .github a35r:/var/www/.github` "
        . 'avant de croire le moindre resultat de ce fichier.',
    );

    return (string) file_get_contents($chemin);
}

/**
 * Le TEXTE ENTIER de l'étape d'alerte, découpé par ses bornes réelles.
 *
 * ⚠️ POURQUOI PAS UNE FENÊTRE DE N CARACTÈRES. La première version de ce fichier
 * lisait `substr($source, $pos, 2500)`. Le 2026-08-22, l'ajout d'un commentaire
 * de dix lignes dans le workflow a repoussé la ligne `curl` au-delà de la
 * fenêtre, et trois contrôles ont rougi sur un workflow parfaitement correct.
 *
 * *Une garde qui dépend d'un décompte de caractères mesure la longueur des
 * commentaires, pas le produit.* On découpe donc du `- name:` de l'alerte
 * jusqu'au `- name:` suivant — les bornes que YAML donne lui-même.
 */
function alerteDeploiementEtape(): string
{
    $source = alerteDeploiementSource();

    $debut = strpos($source, '- name: Alerte Telegram si le deploiement a echoue');

    expect($debut)->not->toBeFalse(
        "L etape d alerte a disparu du workflow. Mesure du 2026-08-21 : sans elle, un\n"
        . 'deploiement en echec ne previent personne, et treize minutes passent.',
    );

    // La borne de fin : l'étape suivante, ou la fin du fichier s'il n'y en a pas.
    $suivante = strpos($source, "\n      - name:", (int) $debut + 10);

    return $suivante === false
        ? substr($source, (int) $debut)
        : substr($source, (int) $debut, $suivante - (int) $debut);
}

test('ALERTE-DEPLOIEMENT — le dispositif EXISTE dans le workflow de production', function () {
    $source = alerteDeploiementSource();

    expect(str_contains($source, 'Alerte Telegram si le deploiement a echoue'))->toBeTrue(
        "Le deploiement ne previent PLUS personne quand il echoue.\n"
        . "Mesure du 2026-08-21 : le smoke test a detecte un 502 en 21 secondes, le job est\n"
        . "parti au rouge, et treize minutes ont passe avant que quelqu un s en apercoive.\n"
        . 'Retablir l etape `Alerte Telegram si le deploiement a echoue`.',
    );
});

test('ALERTE-DEPLOIEMENT — elle ne se declenche QUE sur un echec', function () {
    $source = alerteDeploiementSource();

    // Sans `if: failure()`, l'alerte partirait a CHAQUE deploiement reussi. Un
    // canal qui crie tout le temps finit coupe, et le jour ou il a quelque chose
    // a dire, plus personne ne le lit.
    $extrait = alerteDeploiementEtape();

    expect(str_contains($extrait, 'if: failure()'))->toBeTrue(
        "L etape d alerte n est plus conditionnee a un ECHEC : elle partira a chaque\n"
        . "deploiement, y compris les reussis. Un canal qui crie toujours est un canal\n"
        . "qu on finit par couper.\nExtrait lu :\n" . $extrait,
    );
});

test('ALERTE-DEPLOIEMENT — elle ne peut pas MASQUER la panne qu elle annonce', function () {
    $extrait = alerteDeploiementEtape();

    // 🔑 `continue-on-error: true`. Sans lui, un Telegram injoignable ferait
    // echouer l etape d alerte — et le job resterait rouge pour la MAUVAISE
    // raison. Celui qui lirait le journal chercherait une panne d envoi au lieu
    // de la panne de production.
    expect(str_contains($extrait, 'continue-on-error: true'))->toBeTrue(
        "L etape d alerte peut desormais faire echouer le job pour SA propre raison.\n"
        . "Le lecteur du journal chercherait alors un probleme d envoi au lieu de la panne\n"
        . "de production.\nExtrait lu :\n" . $extrait,
    );
});

test('ALERTE-DEPLOIEMENT — le JETON passe par l environnement, jamais par la ligne de commande', function () {
    $source = alerteDeploiementSource();

    // Les arguments d'un processus sont visibles (`ps`), et GitHub Actions
    // journalise les commandes executees. L'environnement, lui, ne l'est pas.
    expect(str_contains($source, 'TELEGRAM_BOT_TOKEN: ${{ secrets.TELEGRAM_BOT_TOKEN }}'))->toBeTrue(
        'Le jeton du bot n est plus passe par `env:` : verifier qu il ne se retrouve pas '
        . 'ecrit en clair dans la ligne de commande, donc dans le journal d execution.',
    );

    // Et la sortie de `curl` part dans /dev/null : son message d'erreur cite
    // l'URL, qui CONTIENT le jeton.
    $extrait = alerteDeploiementEtape();

    expect(str_contains($extrait, '2>/dev/null'))->toBeTrue(
        'La sortie d erreur de `curl` n est plus jetee : elle cite l URL de l API, qui '
        . 'contient le jeton du bot, et elle finirait dans le journal public du workflow.',
    );
});

test('ALERTE-DEPLOIEMENT — elle ne MENT pas sur son propre succes', function () {
    $extrait = alerteDeploiementEtape();

    // 🔴 DEFAUT MESURE LE 2026-08-21, PENDANT L'ECRITURE DE CETTE ETAPE.
    // Sans `--fail`, `curl` rend 0 meme sur un 401 ou un 404 : l'essai a blanc
    // avec un jeton bidon affichait « alerte Telegram envoyee ». *Une alerte qui
    // ment sur son propre succes est pire que pas d'alerte : elle rassure.*
    expect(str_contains($extrait, '--fail'))->toBeTrue(
        "`curl` n a plus `--fail` : il rend 0 meme quand Telegram REFUSE l envoi, et\n"
        . "l etape annoncera « alerte Telegram envoyee » sans que rien ne soit parti.\n"
        . 'Mesure du 2026-08-21 : avec un jeton bidon, elle se declarait satisfaite.',
    );
});

test('ALERTE-DEPLOIEMENT — TEMOIN : le detecteur SAIT rougir', function () {
    // Sans ce témoin, les contrôles ci-dessus passeraient sur n'importe quel
    // texte contenant les bons mots. On ampute le workflow et on exige que la
    // détection le voie.
    $source = alerteDeploiementSource();
    $ampute = str_replace('if: failure()', '# ligne retiree par le temoin', $source);

    expect(str_contains($ampute, 'if: failure()'))->toBeFalse(
        'Le temoin n a pas reussi a amputer le texte : il ne prouve donc rien.',
    );
    expect(str_contains($ampute, 'Alerte Telegram si le deploiement a echoue'))->toBeTrue(
        'Le temoin a trop coupe : le premier controle rougirait pour la mauvaise raison.',
    );
});

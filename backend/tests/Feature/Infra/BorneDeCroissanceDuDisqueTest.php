<?php

/**
 * GARDE DE LA CROISSANCE DU DISQUE — constat F39-011 (S1).
 *
 * ── CE QUE DIT LE CONSTAT, ET CE QUE JE PEUX EN PROUVER ─────────────────────
 *
 * « Le disque de production se remplit de 511 Mio par jour et sature vers le
 * 6 octobre. »
 *
 * ⚠️ Je n'ai AUCUN acces a la production. Je ne dis donc rien de ce qui s'y
 * passe. Ce fichier ne mesure qu'une chose, et c'est la seule qui soit a ma
 * portee : **ce que le depot PRESCRIT**. Un depot qui ne prescrit aucune borne
 * produit une croissance non bornee, quel qu'en soit le debit du jour.
 *
 * ── LES QUATRE ROBINETS, ET CE QUE LA MESURE A DIT DE CHACUN ────────────────
 *
 * 1. 🔴 **LES JOURNAUX DOCKER, SANS AUCUN PLAFOND.** Les sept fichiers
 *    `docker-compose*.yml` du depot ne portaient PAS UNE SEULE directive
 *    `logging:`. Le pilote par defaut de Docker est `json-file`, et son defaut
 *    est **illimite** : `/var/lib/docker/containers/<id>/<id>-json.log` grossit
 *    jusqu'a ce que le disque soit plein. `infra/scripts/setup-hetzner-cpx22.sh`
 *    installe Docker par `get.docker.com` et n'ecrit AUCUN
 *    `/etc/docker/daemon.json` : il n'y a pas non plus de plafond cote demon.
 *
 *    Mesure jouee le 2026-08-20 sur les conteneurs issus de CE
 *    `docker-compose.yml` (pile locale, meme fichier que la production) :
 *
 *        $ docker inspect --format '{{json .HostConfig.LogConfig}}' axion-crm-app
 *        {"Type":"json-file","Config":{}}
 *        $ docker inspect --format '{{json .HostConfig.LogConfig}}' axion-crm-caddy
 *        {"Type":"json-file","Config":{}}
 *        $ docker inspect --format '{{json .HostConfig.LogConfig}}' axion-crm-postgres
 *        {"Type":"json-file","Config":{}}
 *
 *    `Config` VIDE : ni `max-size`, ni `max-file`. Aucune borne, pour aucun des
 *    conteneurs.
 *
 * 2. 🔴 **`storage/logs/laravel.log`, JAMAIS TOURNE.** `config/logging.php`
 *    faisait par defaut `LOG_STACK=single,stderr`. Le pilote `single` ecrit dans
 *    UN fichier, sans rotation ni plafond, a `LOG_LEVEL=debug`. Le depot le sait
 *    et l'ecrit deux fois sans l'avoir corrige :
 *
 *        docker-compose.prod.yml:199  « storage/logs pesait 968 Mo sur le
 *                                       serveur au 2026-08-16, jamais tourne »
 *        .dockerignore:19             le meme chiffre
 *
 *    Le canal `daily` — celui qui TOURNE, et qui SUPPRIME au-dela de
 *    `LOG_DAILY_DAYS` — existait dans le fichier depuis toujours et **rien ne le
 *    selectionnait**. La piece etait deja la, non branchee : le defaut
 *    caracteristique de ce depot.
 *
 *    Et les deux robinets sont le MEME texte compte DEUX fois : `stack` =
 *    `single,stderr` ecrit chaque ligne dans le fichier ET sur la sortie
 *    d'erreur, donc dans le journal Docker.
 *
 * 3. 🔴 **LOKI : LA BORNE ECRITE QUE PERSONNE NE LIT.** C'est le piege exact
 *    contre lequel ce lot met en garde. `infra/monitoring/loki/local-config.yaml`
 *    portait `limits_config.retention_period: 720h  # 30 jours`. Cette valeur
 *    est **sans effet** tant que le compacteur n'a pas `retention_enabled: true`.
 *
 *    Mesure jouee le 2026-08-20, en demandant a Loki LUI-MEME ce qu'il a compris
 *    du fichier (`-print-config-stderr` rend la configuration RESOLUE, defauts
 *    compris) :
 *
 *        $ docker run --rm -v .../local-config.yaml:/etc/loki/local-config.yaml:ro \
 *              grafana/loki:3.2.0 -config.file=/etc/loki/local-config.yaml \
 *              -print-config-stderr -verify-config
 *        compactor:
 *          working_directory: /loki/compactor
 *          retention_enabled: false        <-- LA BORNE N'EST PAS APPLIQUEE
 *          delete_request_store: ""
 *        limits_config:
 *          retention_period: 30d           <-- LA BORNE ECRITE, JAMAIS LUE
 *
 *    `-verify-config` sortait 0 : la configuration est VALIDE, et pourtant elle
 *    ne borne rien. Une garde qui se serait contentee de lire `retention_period:
 *    720h` dans le fichier aurait conclu « borne posee ». C'est precisement le
 *    faux vert que ce lot interdit.
 *
 * 4. ✅ **LES SAUVEGARDES LOCALES SONT, ELLES, BORNEES.**
 *    `infra/scripts/backup-postgres.sh` garde 7 jours en local
 *    (`RETENTION_LOCAL_DAYS=7`, applique par un `find -mtime -delete` reellement
 *    present dans le script). A ~692 Mo l'archive (chiffre du script lui-meme,
 *    ligne 186), le regime permanent plafonne vers 4,8 Go et n'augmente plus.
 *    Rien a reparer ici — mais il faut le FIGER, parce que retirer la ligne de
 *    rotation serait indetectable autrement.
 *
 * ── CE QUE CETTE GARDE MESURE, ET POURQUOI ELLE NE SE PAIE PAS DE MOTS ──────
 *
 * « La borne EXISTE et elle est ATTEIGNABLE. » Les deux moities sont testees
 * separement, parce qu'elles echouent separement :
 *
 *   · EXISTE  : les fichiers portent une directive de plafond, calculee sur la
 *               configuration FUSIONNEE (base + overlay), parce que Compose
 *               fusionne et que lire un seul fichier ment ;
 *   · ATTEINTE : la rotation applicative est REELLEMENT JOUEE — on ecrit dans le
 *               canal par defaut du depot et on regarde si les vieux fichiers
 *               DISPARAISSENT. Une configuration qui a l'air juste et un
 *               `unlink()` qui n'arrive jamais, c'est le cas Loki, et il a suffi
 *               d'une mesure pour le voir.
 *
 * Chaque controle porte son temoin : un temoin de montage (le banc voit-il les
 * fichiers ?) et un temoin negatif (l'analyseur sait-il rendre ROUGE ?). Sans
 * eux, un chemin faux rendrait tout ce fichier vert sur zero fichier.
 *
 * ── LE VERT, MESURE HORS DU BANC (ce qu'un test PHP ne peut pas prouver) ─────
 *
 * Ce fichier lit des fichiers. Il ne peut pas interroger le demon Docker ni
 * demarrer Loki. Les deux mesures qui ferment vraiment la boucle ont donc ete
 * jouees a la main le 2026-08-20, et elles sont consignees ici :
 *
 *   1. LE PLAFOND DOCKER ATTEINT LE DEMON. Un conteneur temoin cree depuis le
 *      VRAI `docker-compose.yml` fusionne avec l'overlay de production (projet
 *      jetable, conteneur et reseau distincts, aucun effet sur la pile locale) :
 *
 *        AVANT : {"Type":"json-file","Config":{}}
 *        APRES : {"Type":"json-file","Config":{"max-file":"5","max-size":"10m"}}
 *
 *   2. LOKI APPLIQUE ENFIN SA RETENTION. Meme commande qu'avant le correctif :
 *
 *        AVANT : retention_enabled: false · delete_request_store: ""
 *        APRES : retention_enabled: true  · delete_request_store: filesystem
 *                retention_period: 30d    · -verify-config sort 0
 *
 *      ⚠️ Et une premiere version du correctif a ete REFUSEE par Loki :
 *        « failed parsing config: line 92: field delete_request_store_path not
 *          found in type storage.Config »
 *      Ecrit de memoire, il aurait empeche Loki de demarrer — c'est-a-dire
 *      remplace « le disque se remplit » par « il n'y a plus de journaux ».
 *
 * ⚠️ CE QUE CE FICHIER NE PROUVE PAS, ET QU'IL NE FAUT PAS LUI FAIRE DIRE.
 * Le plafond s'applique a la CREATION d'un conteneur. `deploy-direct-ssh.yml`
 * recree `api app horizon scheduler` puis `postgres redis` — mais **jamais
 * `caddy` ni `reverb`**. Sur la production, ces deux-la garderont leur journal
 * sans plafond jusqu'a un `up -d --no-deps caddy` explicite. Cette garde peut
 * etre verte pendant que la machine, elle, n'a rien recu : c'est exactement
 * l'ecart du 2026-08-19, ou `ports: !override []` etait vert au fichier et le
 * conteneur publiait 55432 devant 4 295 349 fiches.
 */

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

/**
 * La racine du depot.
 *
 * ⚠️ `base_path('..')` et NON `base_path()` : les fichiers inspectes
 * (`docker-compose*.yml`, `infra/`) vivent AU-DESSUS de l'application. Le
 * conteneur de mesure les monte bien : `/var/www/docker-compose.yml`,
 * `/var/www/infra`, `/var/www/html` = `backend/`.
 */
function f39Racine(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Lit un fichier YAML du depot, en tolerant les etiquettes `!override`.
 *
 * ⚠️ `Yaml::PARSE_CUSTOM_TAGS` est INDISPENSABLE : `docker-compose.prod.yml`
 * porte `ports: !override []`, et sans ce drapeau Symfony leve une exception.
 * Une garde qui explose sur le fichier qu'elle doit lire est un rouge inutile ;
 * une garde qui l'attrape en silence serait pire.
 *
 * ⚠️ DEUXIEME PIEGE, PAYE LE 2026-08-20 : l'analyseur de Symfony ne sait PAS
 * lire un alias suivi d'un commentaire sur la meme ligne. Ecrire
 *
 *     logging: *journal   # plafond F39-011
 *
 * lui fait chercher une ancre nommee « journal   » et lever
 * `Reference "journal  " does not exist`. Docker Compose, lui, accepte cette
 * forme sans broncher — le fichier est donc VALIDE pour l'outil qui compte, et
 * illisible pour la garde. Les commentaires sont pour cette raison places sur
 * la ligne AU-DESSUS de chaque `logging: *journal`. Si cette garde explose un
 * jour sur une `ParseException`, c'est probablement ca.
 *
 * @return array<string, mixed>
 */
function f39LireYaml(string $chemin): array
{
    $contenu = Yaml::parse((string) file_get_contents($chemin), Yaml::PARSE_CUSTOM_TAGS);

    return is_array($contenu) ? $contenu : [];
}

/**
 * Convertit une taille Docker (`10m`, `512k`, `1g`) en octets.
 *
 * Rend `null` si la forme n'est pas reconnue — et l'appelant traite `null`
 * comme « pas de borne ». On ne devine pas : une valeur qu'on ne sait pas lire
 * est une valeur dont on ne peut rien prouver.
 */
function f39TailleEnOctets(string $valeur): ?int
{
    if (preg_match('/^\s*([0-9]+)\s*([kmg]?)b?\s*$/i', $valeur, $m) !== 1) {
        return null;
    }

    $facteur = match (strtolower($m[2])) {
        'k' => 1024,
        'm' => 1024 * 1024,
        'g' => 1024 * 1024 * 1024,
        default => 1,
    };

    return ((int) $m[1]) * $facteur;
}

/**
 * Verdict de plafond pour UN service, a partir de son bloc `logging` effectif.
 *
 * ⚠️ LA FORME QUI PIEGE, ET QUE J'AI MESUREE : un bloc
 * `logging: {driver: json-file}` SANS `options` ne borne RIEN. C'est exactement
 * ce que rend `docker inspect` sur les conteneurs actuels :
 * `{"Type":"json-file","Config":{}}`. Un analyseur qui se contenterait de
 * chercher la CLE `logging` declarerait la borne posee. Le temoin negatif
 * ci-dessous joue precisement cette forme.
 *
 * @param  array<string, mixed>|null  $logging
 * @return array{borne: bool, raison: string, octets: int}
 */
function f39PlafondDuService(?array $logging): array
{
    if ($logging === null || $logging === []) {
        return [
            'borne' => false,
            'raison' => 'aucune directive `logging:` — pilote `json-file` par defaut, ILLIMITE',
            'octets' => 0,
        ];
    }

    $pilote = (string) ($logging['driver'] ?? 'json-file');

    // `none` ne conserve rien : c'est borne a zero. Honnete, mais aveugle en
    // incident — on l'accepte sans l'encourager.
    if (in_array($pilote, ['none', 'null'], true)) {
        return ['borne' => true, 'raison' => 'pilote `none` : aucun journal conserve', 'octets' => 0];
    }

    if (! in_array($pilote, ['json-file', 'local'], true)) {
        return [
            'borne' => false,
            'raison' => "pilote `{$pilote}` : ce contour ne sait pas prouver sa borne, il refuse plutot que de rassurer",
            'octets' => 0,
        ];
    }

    /** @var array<string, mixed> $options */
    $options = is_array($logging['options'] ?? null) ? $logging['options'] : [];

    $taille = isset($options['max-size']) ? f39TailleEnOctets((string) $options['max-size']) : null;
    $nombre = isset($options['max-file']) ? (int) $options['max-file'] : null;

    if ($taille === null || $nombre === null || $nombre < 1) {
        $vu = json_encode($options, JSON_UNESCAPED_SLASHES) ?: '{}';

        return [
            'borne' => false,
            'raison' => "pilote `{$pilote}` sans `max-size` ET `max-file` exploitables (options vues : {$vu})",
            'octets' => 0,
        ];
    }

    return [
        'borne' => true,
        'raison' => "{$options['max-size']} x {$nombre}",
        'octets' => $taille * $nombre,
    ];
}

/**
 * Le bloc `logging` EFFECTIF d'un service, base FUSIONNEE avec un overlay.
 *
 * ⚠️ POURQUOI LA FUSION, ET PAS UNE SIMPLE LECTURE. Compose fusionne les
 * MAPPINGS cle par cle entre fichiers (l'overlay gagne sur les cles qu'il
 * redefinit) et FUSIONNE aussi les listes, sauf `!override`. Ce depot a deja
 * paye ce piege deux fois — sur `volumes` en aout, sur `ports` le 19 — et les
 * deux fois le fichier lu isolement disait le contraire de ce que Compose
 * rendait. On calcule donc l'effet, on ne lit pas une intention.
 *
 * @param  array<string, mixed>  $base
 * @param  array<string, mixed>  $overlay
 * @return array<string, mixed>|null
 */
function f39LoggingEffectif(array $base, array $overlay, string $service): ?array
{
    $lire = static function (array $doc) use ($service): ?array {
        $s = $doc['services'][$service] ?? null;
        if (! is_array($s)) {
            return null;
        }
        $l = $s['logging'] ?? null;

        // Une etiquette `!override` sur `logging` remplacerait le bloc de base.
        if ($l instanceof TaggedValue) {
            $v = $l->getValue();

            return is_array($v) ? $v : [];
        }

        return is_array($l) ? $l : null;
    };

    $lBase = $lire($base);
    $lOver = $lire($overlay);

    if ($lBase === null && $lOver === null) {
        return null;
    }

    $fusion = $lBase ?? [];
    foreach ($lOver ?? [] as $cle => $valeur) {
        if ($cle === 'options' && is_array($valeur) && is_array($fusion['options'] ?? null)) {
            $fusion['options'] = array_merge($fusion['options'], $valeur);

            continue;
        }
        $fusion[$cle] = $valeur;
    }

    return $fusion;
}

/**
 * Les services que la PRODUCTION fait tourner, plus ceux qu'un `up` nu
 * demarrerait.
 *
 * ⚠️ On prend TOUS les services du fichier de base, et c'est delibere : le
 * depot a documente DOUZE chemins qui lancent `docker compose up` sans overlay
 * (constat F38-007). Un plafond pose seulement sur les quatre services deployes
 * laisserait les huit autres sans borne des le premier `up` nu.
 *
 * @return list<string>
 */
function f39ServicesDeBase(): array
{
    $base = f39LireYaml(f39Racine() . '/docker-compose.yml');
    $services = $base['services'] ?? [];

    return is_array($services) ? array_keys($services) : [];
}

/**
 * Vide un dossier de journaux de mesure, sans jamais appeler `unlink(false)`.
 *
 * ⚠️ `array_map('unlink', (array) glob(...))` etait la forme d'origine, et elle
 * est piegeuse : quand `glob()` echoue il rend `false`, et `(array) false` vaut
 * `[false]`. On appelait alors `unlink('')`, qui leve un avertissement — or
 * `phpunit-lot1.xml` porte `failOnWarning="true"`. Le nettoyage aurait fait
 * echouer le test qu'il servait.
 */
function f39ViderLeDossier(string $dossier): void
{
    $fichiers = glob($dossier . '/*.log');
    if ($fichiers === false) {
        return;
    }

    foreach ($fichiers as $fichier) {
        @unlink($fichier);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// TEMOINS DE MONTAGE
// ═══════════════════════════════════════════════════════════════════════════

test('F39-011 — TEMOIN : le banc voit tous les fichiers qui bornent le disque', function () {
    // ⚠️ PIEGE DU BANC, MESURE LE 2026-08-20. Dans le conteneur `a35r`, ces
    // fichiers ne sont pas tous montes de la meme facon :
    //   · `infra/`, `docker-compose.{yml,prod,staging,local}.yml` sont des
    //     montages BIND : une edition du depot y est visible immediatement ;
    //   · `docker-compose.observability.yml` est une COPIE (`docker cp`), figee
    //     a l'heure ou quelqu'un l'a deposee.
    // Un correctif ecrit dans le depot restait donc INVISIBLE au banc, et la
    // garde rougissait sur une version perimee — ou pire, resterait verte sur
    // une copie qui porte encore un correctif que le depot a perdu.
    // Rafraichir : `docker cp docker-compose.observability.yml a35r:/var/www/`.
    $attendus = [
        'docker-compose.yml',
        'docker-compose.prod.yml',
        'docker-compose.staging.yml',
        'docker-compose.observability.yml',
        'infra/monitoring/loki/local-config.yaml',
        'infra/monitoring/tempo/tempo.yaml',
        'infra/scripts/backup-postgres.sh',
        'infra/scripts/setup-hetzner-cpx22.sh',
    ];

    $manquants = [];
    foreach ($attendus as $relatif) {
        if (! is_file(f39Racine() . '/' . $relatif)) {
            $manquants[] = $relatif;
        }
    }

    expect($manquants)->toBe(
        [],
        'Le banc ne voit pas ces fichiers : ' . implode(', ', $manquants) . '. '
        . 'Une garde qui n a rien a inspecter passe au vert sans rien prouver — c est le '
        . 'defaut exact trouve le 19/08 sur les gardes de ce dossier. Racine vue : ' . f39Racine(),
    );

    // Et le fichier de configuration de l application, cote `backend/`.
    expect(is_file(base_path('config/logging.php')))->toBeTrue(
        'config/logging.php introuvable : la moitie applicative de cette garde ne mesurerait rien.',
    );

    // Le banc doit voir des SERVICES, sinon la boucle ci-dessous tourne a vide.
    expect(count(f39ServicesDeBase()))->toBeGreaterThanOrEqual(
        6,
        'docker-compose.yml ne rend pas la liste de services attendue : le fichier n est pas '
        . 'lu comme du YAML, ou sa forme a change. Services vus : '
        . implode(', ', f39ServicesDeBase()),
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// ROBINET 1 — LE PLAFOND DES JOURNAUX DOCKER
// ═══════════════════════════════════════════════════════════════════════════

test('F39-011 — TEMOIN NEGATIF : l analyseur de plafond SAIT rendre rouge', function () {
    // (a) Aucun bloc `logging` : c est l etat mesure aujourd hui.
    $sans = f39PlafondDuService(null);
    expect($sans['borne'])->toBeFalse('Un service sans `logging:` doit etre declare NON borne.');

    // (b) 🔴 LA FORME QUI PIEGE. `logging: {driver: json-file}` sans `options`
    // rend exactement `{"Type":"json-file","Config":{}}` a `docker inspect` —
    // la forme MESUREE sur les trois conteneurs de la pile. Elle ne borne rien.
    $vide = f39PlafondDuService(['driver' => 'json-file']);
    expect($vide['borne'])->toBeFalse(
        'Un bloc `logging:` SANS `options` a ete accepte comme borne. C est le faux vert exact : '
        . '`docker inspect` rend {"Type":"json-file","Config":{}} et le journal grossit sans fin.',
    );

    // (c) Une moitie seulement de la borne ne borne pas : `max-size` sans
    // `max-file` laisse Docker garder un nombre illimite de fichiers tournes.
    $moitie = f39PlafondDuService(['driver' => 'json-file', 'options' => ['max-size' => '10m']]);
    expect($moitie['borne'])->toBeFalse('`max-size` seul, sans `max-file`, ne plafonne pas le total.');

    // (d) Et il doit reconnaitre la forme CORRECTE, sinon il rougirait sur le
    // correctif lui-meme et quelqu un finirait par retirer la garde.
    $bon = f39PlafondDuService([
        'driver' => 'json-file',
        'options' => ['max-size' => '10m', 'max-file' => 5],
    ]);
    expect($bon['borne'])->toBeTrue('L analyseur refuse la forme correcte : il rougirait sur le correctif.');
    expect($bon['octets'])->toBe(
        50 * 1024 * 1024,
        'Le calcul du plafond est faux : 10m x 5 doit valoir 52 428 800 octets.',
    );

    // (e) La fusion base+overlay doit vraiment fusionner. Un overlay qui ne
    // parle pas de journaux ne doit pas effacer le plafond de la base.
    $effectif = f39LoggingEffectif(
        ['services' => ['api' => ['logging' => ['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => 5]]]]],
        ['services' => ['api' => ['restart' => 'unless-stopped']]],
        'api',
    );
    expect(f39PlafondDuService($effectif)['borne'])->toBeTrue(
        'La fusion perd le plafond de la base quand l overlay ne parle pas de journaux.',
    );
});

test('F39-011 — chaque service de la pile porte un PLAFOND de journal Docker', function () {
    $base = f39LireYaml(f39Racine() . '/docker-compose.yml');

    $sansBorne = [];
    foreach (['docker-compose.prod.yml', 'docker-compose.staging.yml'] as $fichierOverlay) {
        $overlay = f39LireYaml(f39Racine() . '/' . $fichierOverlay);

        foreach (f39ServicesDeBase() as $service) {
            $verdict = f39PlafondDuService(f39LoggingEffectif($base, $overlay, $service));
            if (! $verdict['borne']) {
                $sansBorne[] = "{$fichierOverlay} · {$service} : {$verdict['raison']}";
            }
        }
    }

    expect($sansBorne)->toBe(
        [],
        "Ces services ecrivent leur journal SANS PLAFOND.\n\n"
        . 'Le pilote par defaut de Docker est `json-file`, et son defaut est ILLIMITE : '
        . '/var/lib/docker/containers/<id>/<id>-json.log grossit jusqu a ce que le disque soit '
        . 'plein. `infra/scripts/setup-hetzner-cpx22.sh` installe Docker par get.docker.com et '
        . "n ecrit AUCUN /etc/docker/daemon.json : il n y a pas non plus de plafond cote demon.\n\n"
        . "Mesure du 2026-08-20 sur les conteneurs issus de ce meme fichier :\n"
        . "  docker inspect --format '{{json .HostConfig.LogConfig}}' axion-crm-app\n"
        . "  {\"Type\":\"json-file\",\"Config\":{}}          <-- Config VIDE, aucune borne\n\n"
        . 'Aggravant : `config/logging.php` envoie la pile de journaux sur `stderr` a '
        . "LOG_LEVEL=debug. Chaque ligne de l application atterrit donc ICI.\n\n"
        . 'Correctif : une ancre `x-journal` dans `docker-compose.yml` (le fichier de BASE, pour '
        . 'couvrir aussi les douze invocations `up` nues du constat F38-007) et `logging: '
        . "*journal` sur chaque service.\n\n"
        . "Services sans plafond :\n  - " . implode("\n  - ", $sansBorne),
    );
});

test('F39-011 — la pile d observabilite porte le meme plafond', function () {
    // ⚠️ RULE 8 DU MANDAT : « l audit nomme souvent UN site ; il y en a souvent
    // deux ». `docker-compose.observability.yml` declare HUIT services de plus,
    // tous sans plafond — dont `promtail`, dont le metier est de LIRE des
    // journaux, et `glitchtip`, qui empile des traces d erreur. Une garde qui
    // n aurait regarde que la pile applicative aurait ferme le dossier a moitie.
    //
    // ⚠️ Une ancre YAML ne franchit pas la frontiere d un document : l ancre
    // `x-journal` de `docker-compose.yml` NE S APPLIQUE PAS ici. Elle doit etre
    // repetee dans ce fichier, et c est ce que ce controle verifie de fait.
    $chemin = f39Racine() . '/docker-compose.observability.yml';
    $obs = f39LireYaml($chemin);

    $services = is_array($obs['services'] ?? null) ? $obs['services'] : [];

    // TEMOIN : si le fichier n est pas lu, la boucle serait vide et verte.
    expect(count($services))->toBeGreaterThanOrEqual(
        6,
        'docker-compose.observability.yml ne rend pas ses services : ce controle ne mesurerait '
        . "rien. Chemin : {$chemin}",
    );

    $sansBorne = [];
    foreach (array_keys($services) as $service) {
        $verdict = f39PlafondDuService(f39LoggingEffectif($obs, [], (string) $service));
        if (! $verdict['borne']) {
            $sansBorne[] = "{$service} : {$verdict['raison']}";
        }
    }

    expect($sansBorne)->toBe(
        [],
        "Ces services d observabilite ecrivent leur journal SANS PLAFOND.\n\n"
        . 'Le pilote `json-file` par defaut de Docker est ILLIMITE. Et ce fichier tourne sur LE '
        . 'MEME disque que la production (son en-tete prescrit `-f docker-compose.yml -f '
        . "docker-compose.prod.yml -f docker-compose.observability.yml`).\n\n"
        . '⚠️ L ancre `x-journal` de docker-compose.yml ne s applique PAS ici : une ancre YAML '
        . 'ne franchit pas la frontiere d un document. Elle doit etre repetee dans ce '
        . "fichier.\n\nServices sans plafond :\n  - " . implode("\n  - ", $sansBorne),
    );
});

test('F39-011 — le plafond total est CHIFFRE, et il ne grandit pas', function () {
    $base = f39LireYaml(f39Racine() . '/docker-compose.yml');
    $overlay = f39LireYaml(f39Racine() . '/docker-compose.prod.yml');

    $total = 0;
    $detail = [];
    foreach (f39ServicesDeBase() as $service) {
        $verdict = f39PlafondDuService(f39LoggingEffectif($base, $overlay, $service));
        $total += $verdict['octets'];
        $detail[] = $service . ' = ' . round($verdict['octets'] / 1048576) . ' Mio (' . $verdict['raison'] . ')';
    }

    $totalMio = (int) round($total / 1048576);

    // TEMOIN : un total nul voudrait dire que rien n a ete mesure.
    expect($total)->toBeGreaterThan(
        0,
        "Le plafond total calcule vaut ZERO : la mesure n a pas eu lieu.\n" . implode("\n", $detail),
    );

    // Le constat parle de 511 Mio PAR JOUR. Le plafond, lui, est un total qui
    // ne bouge plus jamais : c est la difference entre « sature le 6 octobre »
    // et « n atteint jamais ce chiffre ». On le fige, pour qu on ne le desserre
    // pas d un `max-size: 10g` distrait un soir d incident.
    expect($totalMio)->toBeLessThanOrEqual(
        1024,
        "Le plafond CUMULE des journaux Docker vaut {$totalMio} Mio, soit plus d un Gio.\n\n"
        . 'Ce n est plus une borne utile sur un disque qui sature : le constat F39-011 mesure '
        . '511 Mio de croissance par jour. Un plafond doit rester tres en dessous du debit '
        . "quotidien qu il est cense contenir.\n\n"
        . "Detail :\n  - " . implode("\n  - ", $detail),
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// ROBINET 2 — LA ROTATION APPLICATIVE
// ═══════════════════════════════════════════════════════════════════════════

test('F39-011 — TEMOIN DE SCENARIO : on mesure bien le DEFAUT de LOG_STACK', function () {
    // Si un `.env` du depot definissait LOG_STACK, le test ci-dessous ne
    // mesurerait pas la production (ou la variable est absente) mais ce `.env`.
    // Meme temoin que celui de la garde Telescope, pour la meme raison.
    expect(env('LOG_STACK', null))->toBeNull(
        'LOG_STACK est definie dans l environnement du banc (valeur : '
        . var_export(env('LOG_STACK'), true) . '). Le scenario joue n est donc pas celui du '
        . 'defaut, et le test suivant ne prouverait rien.',
    );

    // Et la pile par defaut doit etre non vide, sinon tout est vert pour rien.
    expect(config('logging.channels.stack.channels'))->toBeArray();
    expect(count((array) config('logging.channels.stack.channels')))->toBeGreaterThan(0);
});

test('F39-011 — la pile de journaux par defaut ne contient AUCUN canal sans borne', function () {
    /** @var list<string> $canaux */
    $canaux = array_map('trim', (array) config('logging.channels.stack.channels'));

    // Ce que chaque pilote ecrit, et qui le borne.
    //   · `single`  : un fichier, sans rotation ni plafond — NON borne ;
    //   · `daily`   : borne par son `days` (verifie ATTEIGNABLE ci-dessous) ;
    //   · `monolog`/`syslog`/`errorlog` sur stderr : borne par le plafond
    //     Docker, verifie par le controle du robinet 1 ;
    //   · `null`    : n ecrit rien.
    $nonBornes = [];
    foreach ($canaux as $nom) {
        $canal = (array) config("logging.channels.{$nom}", []);
        $pilote = (string) ($canal['driver'] ?? '');

        if ($pilote === 'single') {
            $nonBornes[] = "{$nom} (pilote `single` : un fichier, aucune rotation, aucun plafond)";

            continue;
        }

        if ($pilote === 'daily' && (int) ($canal['days'] ?? 0) < 1) {
            $nonBornes[] = "{$nom} (pilote `daily` sans `days` exploitable)";
        }
    }

    expect($nonBornes)->toBe(
        [],
        'La pile de journaux par defaut (`' . implode(',', $canaux) . '`) contient un canal qui '
        . "ecrit sans borne.\n\n"
        . "Le depot MESURE deja le degat et ne l avait pas corrige :\n"
        . '  docker-compose.prod.yml:199  « storage/logs pesait 968 Mo sur le serveur au '
        . "2026-08-16, jamais tourne »\n"
        . "  .dockerignore:19             le meme chiffre\n\n"
        . 'Le canal `daily` — celui qui TOURNE et qui SUPPRIME au-dela de LOG_DAILY_DAYS — '
        . 'existe dans config/logging.php depuis toujours, et RIEN ne le selectionnait. La piece '
        . "etait deja la, non branchee.\n\n"
        . "Correctif : defaut de LOG_STACK a `daily,stderr`.\n\n"
        . "Canaux sans borne :\n  - " . implode("\n  - ", $nonBornes),
    );
});

test('F39-011 — ATTEIGNABLE : la rotation SUPPRIME vraiment les vieux journaux', function () {
    // ── POURQUOI CE TEST EXISTE ────────────────────────────────────────────
    //
    // C est la moitie que le cas Loki rend obligatoire : une borne peut etre
    // ECRITE, valide, relue par l outil, et ne rien supprimer. On ne lit donc
    // pas la configuration : on ECRIT dans le canal que le depot a choisi, et
    // on regarde si les vieux fichiers ont disparu.

    /** @var list<string> $canaux */
    $canaux = array_map('trim', (array) config('logging.channels.stack.channels'));

    $canalFichier = null;
    foreach ($canaux as $nom) {
        if ((string) (config("logging.channels.{$nom}.driver") ?? '') === 'daily') {
            $canalFichier = $nom;
            break;
        }
    }

    expect($canalFichier)->not->toBeNull(
        'Aucun canal a rotation dans la pile par defaut (`' . implode(',', $canaux) . '`) : '
        . 'il n y a rien dont on puisse prouver que la borne est atteignable.',
    );

    $dossier = sys_get_temp_dir() . '/f39-rotation-' . getmypid();
    is_dir($dossier) || mkdir($dossier, 0777, true);
    f39ViderLeDossier($dossier);

    try {
        // On reprend la configuration REELLE du canal du depot, en ne changeant
        // que le chemin — sinon on testerait une configuration inventee.
        $config = (array) config("logging.channels.{$canalFichier}");
        $config['path'] = $dossier . '/laravel.log';
        $config['days'] = 2;

        // Cinq journaux anciens, dates bien avant aujourd hui. Le motif de nom
        // est celui de Monolog : `{filename}-{date}.log`.
        foreach (['2020-01-01', '2020-01-02', '2020-01-03', '2020-01-04', '2020-01-05'] as $jour) {
            file_put_contents($dossier . "/laravel-{$jour}.log", "ancien\n");
        }
        expect(glob($dossier . '/laravel-*.log'))->toHaveCount(5, 'Le decor du test n a pas ete pose.');

        // On ecrit UNE ligne. Monolog\Handler\RotatingFileHandler::write() met
        // `mustRotate` a vrai quand le fichier du jour n existe pas, puis
        // `close()` declenche `rotate()`, qui supprime au-dela de `days`.
        /** @var Logger $journal */
        $journal = Log::build($config);
        $journal->info('f39 — une ligne suffit a declencher la rotation');

        // On ferme explicitement : ne pas dependre du ramasse-miettes.
        foreach ($journal->getLogger()->getHandlers() as $handler) {
            $handler->close();
        }

        $restants = (array) glob($dossier . '/laravel-*.log');

        expect(count($restants))->toBeLessThanOrEqual(
            2,
            'La borne EXISTE dans la configuration mais elle n est PAS ATTEIGNABLE : apres une '
            . 'ecriture, il reste ' . count($restants) . " journaux la ou `days=2` en autorise 2.\n\n"
            . 'C est le meme defaut que Loki, mesure le 2026-08-20 : `retention_period: 720h` '
            . 'ecrit, `retention_enabled: false` applique. Une borne qu on lit sans la jouer ne '
            . "prouve rien.\n\n"
            . "Fichiers restants :\n  - " . implode("\n  - ", array_map('basename', $restants)),
        );

        // TEMOIN POSITIF : la rotation ne doit pas avoir tout efface non plus.
        // Un dossier vide passerait le controle ci-dessus sans qu aucun journal
        // ne soit conserve — un vert qui cacherait la perte de la trace.
        expect(count($restants))->toBeGreaterThan(
            0,
            'La rotation a tout supprime, journal du jour compris : la borne est atteinte, mais '
            . 'il ne reste plus rien a lire en incident.',
        );
    } finally {
        f39ViderLeDossier($dossier);
        @rmdir($dossier);
    }
});

test('F39-011 — TEMOIN NEGATIF de la rotation : le canal `single` ne supprime RIEN', function () {
    // Sans ce contre-essai, le test precedent pourrait passer au vert parce que
    // le decor n a pas ete pose, pas parce que la rotation marche. On rejoue le
    // MEME scenario avec le canal fautif d origine : il doit tout conserver.
    $dossier = sys_get_temp_dir() . '/f39-sans-rotation-' . getmypid();
    is_dir($dossier) || mkdir($dossier, 0777, true);
    f39ViderLeDossier($dossier);

    try {
        $config = (array) config('logging.channels.single');
        $config['path'] = $dossier . '/laravel.log';

        foreach (['2020-01-01', '2020-01-02', '2020-01-03', '2020-01-04', '2020-01-05'] as $jour) {
            file_put_contents($dossier . "/laravel-{$jour}.log", "ancien\n");
        }

        /** @var Logger $journal */
        $journal = Log::build($config);
        $journal->info('f39 — contre-essai');
        foreach ($journal->getLogger()->getHandlers() as $handler) {
            $handler->close();
        }

        expect(glob($dossier . '/laravel-*.log'))->toHaveCount(
            5,
            'Le canal `single` a supprime des fichiers : le contre-essai ne distingue plus un '
            . 'canal borne d un canal non borne, et le test de rotation ne prouve plus rien.',
        );
    } finally {
        f39ViderLeDossier($dossier);
        @rmdir($dossier);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// ROBINET 3 — LOKI : LA BORNE ECRITE QUE PERSONNE NE LIT
// ═══════════════════════════════════════════════════════════════════════════

test('F39-011 — Loki : `retention_period` ne borne RIEN sans compacteur arme', function () {
    $chemin = f39Racine() . '/infra/monitoring/loki/local-config.yaml';
    $loki = f39LireYaml($chemin);

    // TEMOIN : le fichier annonce bien une retention. Si la cle disparaissait,
    // ce test deviendrait un vert sans objet — il faut alors le dire.
    $periode = $loki['limits_config']['retention_period'] ?? null;
    expect($periode)->not->toBeNull(
        "`limits_config.retention_period` a disparu de {$chemin} : ce controle ne mesure plus "
        . 'rien. Soit Loki a ete retire du depot — et ce test doit partir avec lui — soit la '
        . 'borne a ete supprimee.',
    );

    $compacteur = (array) ($loki['compactor'] ?? []);

    expect($compacteur['retention_enabled'] ?? false)->toBeTrue(
        "LA BORNE EST ECRITE ET PERSONNE NE LA LIT — le piege exact de ce lot.\n\n"
        . "Le fichier annonce `retention_period: {$periode}`. Cette valeur est SANS EFFET tant "
        . "que le compacteur ne porte pas `retention_enabled: true`.\n\n"
        . 'Mesure du 2026-08-20, en demandant a Loki lui-meme ce qu il a compris du fichier '
        . "(-print-config-stderr rend la configuration RESOLUE, defauts compris) :\n\n"
        . "  \$ docker run --rm -v .../local-config.yaml:/etc/loki/local-config.yaml:ro \\\n"
        . "        grafana/loki:3.2.0 -config.file=/etc/loki/local-config.yaml \\\n"
        . "        -print-config-stderr -verify-config\n"
        . "  compactor:\n"
        . "    retention_enabled: false      <-- la borne n est PAS appliquee\n"
        . "    delete_request_store: \"\"\n"
        . "  limits_config:\n"
        . "    retention_period: 30d         <-- la borne ecrite, jamais lue\n\n"
        . '`-verify-config` sortait 0 : la configuration est VALIDE et ne borne rien. Une garde '
        . 'qui se serait contentee de lire `retention_period` dans le fichier aurait conclu '
        . "« borne posee ».\n\n"
        . 'Correctif : bloc `compactor` avec `retention_enabled: true` ET '
        . '`delete_request_store: filesystem` (Loki 3.x REFUSE de demarrer si la retention est '
        . 'armee sans magasin de demandes de suppression).',
    );

    // Loki 3.x exige un magasin de demandes de suppression quand la retention
    // est armee, sinon il REFUSE de demarrer. Une borne qui empeche le service
    // de demarrer n est pas une borne, c est une panne.
    expect((string) ($compacteur['delete_request_store'] ?? ''))->not->toBe(
        '',
        '`compactor.retention_enabled: true` sans `delete_request_store` : Loki 3.x refuse de '
        . 'demarrer avec cette combinaison. Verifie par `-verify-config` le 2026-08-20.',
    );
});

test('F39-011 — Tempo : la retention de blocs reste posee', function () {
    // Tempo, lui, portait deja sa borne au bon endroit
    // (`compactor.compaction.block_retention`). On la FIGE : c est le voisin de
    // Loki, et la difference entre les deux est precisement ce que ce lot
    // cherche a rendre visible.
    $tempo = f39LireYaml(f39Racine() . '/infra/monitoring/tempo/tempo.yaml');

    expect($tempo['compactor']['compaction']['block_retention'] ?? null)->not->toBeNull(
        '`compactor.compaction.block_retention` a disparu de tempo.yaml : les traces '
        . 's accumuleraient sans fin dans le volume `tempo-data`.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// ROBINET 4 — LES SAUVEGARDES LOCALES
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// LE GESTE D INCIDENT QUI NE LIBERE RIEN
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Repere une ligne qui presente `docker logs … --tail=0` comme une troncature.
 *
 * @return list<string>
 */
function f39LignesDeFausseTroncature(string $contenu): array
{
    $trouvees = [];
    foreach (explode("\n", $contenu) as $i => $ligne) {
        // ⚠️ Le bloc de citation qui EXPLIQUE le defaut contient forcement la
        // ligne fautive. On l'ignore ICI, en gardant la numerotation d'origine :
        // filtrer les lignes AVANT de les numeroter donnerait un numero faux
        // dans le message d'erreur, et enverrait le lecteur au mauvais endroit.
        // C'est la difference entre « ne fais pas ca » et « fais ca ».
        if (str_starts_with(ltrim($ligne), '>')) {
            continue;
        }
        if (preg_match('/docker\s+logs\b/', $ligne) !== 1) {
            continue;
        }
        if (preg_match('/--tail[=\s]*0/', $ligne) !== 1) {
            continue;
        }
        // Le mot « tronque » sans accent : le mandat interdit de controler du
        // texte francais accentue, et le fichier ecrit « tronque logs verbeux ».
        if (preg_match('/tronqu|purge|vide|libere|nettoi/i', $ligne) === 1) {
            $trouvees[] = 'ligne ' . ($i + 1) . ' : ' . trim($ligne);
        }
    }

    return $trouvees;
}

test('F39-011 — TEMOIN NEGATIF : le contour SAIT reperer la fausse troncature', function () {
    $fabrique = "docker logs axion-crm-api --tail=0       # tronque logs verbeux\n";
    expect(f39LignesDeFausseTroncature($fabrique))->toHaveCount(
        1,
        'Le contour ne reconnait pas la ligne fautive fabriquee : le controle ci-dessous '
        . 'passerait au vert sur n importe quoi.',
    );

    // Et il ne doit pas rougir sur une lecture legitime, sinon il interdirait
    // de consulter un journal en incident.
    expect(f39LignesDeFausseTroncature("docker logs axion-crm-api --tail=50\n"))->toBe([]);
    expect(f39LignesDeFausseTroncature("docker logs -f axion-crm-api\n"))->toBe([]);
});

test('F39-011 — le runbook disque plein ne prescrit plus un geste qui ne libere RIEN', function () {
    $chemin = f39Racine() . '/infra/runbooks/02-disk-full.md';
    $runbook = (string) file_get_contents($chemin);

    // TEMOIN DE MONTAGE : le runbook doit vraiment etre lu.
    expect(strlen($runbook))->toBeGreaterThan(
        500,
        "Le banc ne lit pas {$chemin} : ce controle ne mesure rien.",
    );

    // TEMOIN DE SCENARIO : le bloc de citation qui EXPLIQUE le defaut contient
    // la ligne fautive, et le contour DOIT l ignorer. Si ce n etait pas le cas,
    // le controle ci-dessous rougirait sur sa propre explication et quelqu un
    // finirait par supprimer l explication plutot que le defaut.
    $this->assertStringContainsString(
        'docker logs axion-crm-api --tail=0',
        $runbook,
        'L encadre qui explique le defaut a disparu du runbook : le prochain lecteur ne saura '
        . 'pas POURQUOI la ligne a ete retiree, et la remettra.',
    );

    expect(f39LignesDeFausseTroncature($runbook))->toBe(
        [],
        "Le runbook PRESCRIT `docker logs --tail=0` comme une troncature. Ce n en est pas une.\n\n"
        . '`docker logs` est une commande de LECTURE : `--tail=0` demande d afficher zero ligne, '
        . "il n efface pas un octet. Mesure du 2026-08-20 sur un conteneur reel :\n\n"
        . "  \$ docker logs axion-crm-caddy | wc -c            # 461154\n"
        . "  \$ docker logs axion-crm-caddy --tail=0 | wc -c   # 0   (affichage, pas suppression)\n"
        . "  \$ docker logs axion-crm-caddy | wc -c            # 461154   <-- INCHANGE\n\n"
        . 'En incident, l operateur croit avoir libere de la place et n en a libere AUCUNE. '
        . "C est pire qu une etape manquante : c est une etape qui rassure.\n\n"
        . "Le vrai geste : truncate -s 0 \"\$(docker inspect --format='{{.LogPath}}' <conteneur>)\"",
    );

    // Et le geste JUSTE doit y etre : retirer le faux sans poser le vrai
    // laisserait l operateur sans moyen de tronquer.
    $this->assertStringContainsString(
        'truncate -s 0',
        $runbook,
        'Le runbook ne prescrit AUCUN moyen de tronquer reellement un journal de conteneur. '
        . 'On a retire la ligne fausse sans la remplacer.',
    );

    // ⚠️ Et le `rm` ne marcherait PAS : Docker garde le descripteur ouvert, la
    // place ne serait pas rendue. On verifie qu on ne l a pas prescrit.
    expect(preg_match('/rm\s+.*LogPath/', $runbook))->toBe(
        0,
        'Le runbook prescrit un `rm` sur le fichier de journal. Docker garde son descripteur '
        . 'ouvert : la place ne serait PAS rendue, et le conteneur cesserait de journaliser.',
    );
});

test('F39-011 — les sauvegardes locales gardent leur rotation, et elle est JOUEE', function () {
    $chemin = f39Racine() . '/infra/scripts/backup-postgres.sh';
    $script = (string) file_get_contents($chemin);

    // La valeur declaree…
    expect(preg_match('/RETENTION_LOCAL_DAYS=(\d+)/', $script, $m))->toBe(
        1,
        'backup-postgres.sh ne declare plus RETENTION_LOCAL_DAYS : les archives locales '
        . '(~692 Mo par nuit, chiffre du script lui-meme) s empileraient sans fin dans '
        . '/var/backups/axion-crm.',
    );
    $jours = (int) ($m[1] ?? 0);
    expect($jours)->toBeGreaterThan(0);
    expect($jours)->toBeLessThanOrEqual(
        30,
        "Retention locale a {$jours} jours : a ~692 Mo l archive, cela represente "
        . round($jours * 692 / 1024, 1) . ' Gio en regime permanent.',
    );

    // …et la ligne qui l APPLIQUE. Une valeur declaree et jamais utilisee, c est
    // exactement le cas Loki. On exige donc le geste, pas la constante.
    $this->assertMatchesRegularExpression(
        '/find\s+"\$BACKUP_DIR".*-mtime\s+"\+\$RETENTION_LOCAL_DAYS"\s+-delete/',
        $script,
        'La constante RETENTION_LOCAL_DAYS existe mais AUCUN `find … -mtime +… -delete` ne '
        . 'l applique. Une borne declaree et jamais jouee ne borne rien — c est le defaut Loki, '
        . 'transpose aux sauvegardes.',
    );
});

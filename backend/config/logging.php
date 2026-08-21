<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],
    'channels' => [
        // ── LA PILE PAR DEFAUT — constat F39-011 (S1), corrige le 2026-08-20 ──
        //
        // 🔴 ELLE VALAIT `single,stderr`, ET `single` N'A NI ROTATION NI PLAFOND.
        //
        // Le pilote `single` ecrit dans UN fichier, `storage/logs/laravel.log`,
        // a `LOG_LEVEL=debug`, et ne le tourne JAMAIS. Le depot mesurait deja le
        // degat a deux endroits, sans l'avoir corrige :
        //
        //   docker-compose.prod.yml:199  « storage/logs pesait 968 Mo sur le
        //                                  serveur au 2026-08-16, jamais tourne »
        //   .dockerignore:19             le meme chiffre
        //
        // ⚠️ ET LA PIECE ETAIT DEJA DANS LE FICHIER. Le canal `daily` ci-dessous
        // — celui qui tourne, et qui SUPPRIME au-dela de `LOG_DAILY_DAYS` —
        // existait depuis toujours, et RIEN ne le selectionnait. C'est le defaut
        // caracteristique de ce depot : le correctif est ecrit, il n'est pas
        // branche.
        //
        // ── CE QUE CE CHANGEMENT DEPLACE, ET QU'IL FAUT SAVOIR ────────────────
        //
        // Le nom du fichier change : `laravel.log` devient
        // `laravel-AAAA-MM-JJ.log` (motif `{filename}-{date}` de Monolog). Deux
        // textes du depot prescrivent encore l'ancien nom et il faut lire
        // l'entree du jour a la place :
        //   · load-tests/LOAD-TEST-RUNBOOK.md:71  `tail -f storage/logs/laravel.log`
        //   · docker-compose.staging.yml:149      « et storage/logs/laravel.log »
        // Le canal `emergency` de Laravel, lui, garde `laravel.log` a dessein :
        // il n'ecrit que lorsque la journalisation elle-meme est en panne.
        //
        // ⚠️ LA BORNE POSEE ICI EST TEMPORELLE, PAS EN OCTETS. 14 jours d'un
        // service bavard restent 14 jours de gros fichiers ; le plafond en
        // octets, lui, est pose cote Docker (`x-journal` dans
        // `docker-compose.yml` : 10 Mio x 5 par conteneur). Un plafond en octets
        // sur CE canal supposerait de baisser `LOG_LEVEL` en production — c'est
        // un changement de SEMANTIQUE (des lignes cessent d'etre ecrites), et je
        // ne le prends pas ici.
        //
        // Garde : `backend/tests/Feature/Infra/BorneDeCroissanceDuDisqueTest.php`.
        // Elle ne se contente pas de relire ce fichier : elle ECRIT dans le canal
        // et verifie que les vieux journaux DISPARAISSENT vraiment — parce qu'une
        // borne ecrite et jamais jouee ne borne rien (cf. `retention_period` de
        // Loki, mesuree inoperante le meme jour).
        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'daily,stderr')),
            'ignore_exceptions' => false,
        ],
        // ⚠️ CONSERVE, MAIS PLUS SELECTIONNE PAR DEFAUT. On ne supprime pas le
        // canal : `LOG_STACK=single` reste un choix legitime en developpement,
        // ou le fichier unique est plus commode. Ce qui change, c'est ce que
        // fait la PRODUCTION quand personne n'a rien decide.
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],
        // `days` est la borne. Monolog\Handler\RotatingFileHandler la joue au
        // premier ecrit de la journee : `write()` arme `mustRotate` quand le
        // fichier du jour n'existe pas encore, `close()` declenche `rotate()`,
        // qui `unlink()` tout ce qui depasse. C'est ce geste-la que la garde
        // rejoue, plutot que de relire ce tableau.
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => ['stream' => 'php://stderr'],
            'processors' => [PsrLogMessageProcessor::class],
        ],
        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],
        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],
        'null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        'emergency' => ['path' => storage_path('logs/laravel.log')],
    ],
];

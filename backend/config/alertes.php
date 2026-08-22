<?php

/*
|--------------------------------------------------------------------------
| CANAL D'ALERTE — où part ce que personne ne doit rater
|--------------------------------------------------------------------------
|
| 🔴 POURQUOI CE FICHIER EXISTE (audit 360, 2026-08-21).
|
| Trois endroits du dépôt PROMETTAIENT une alerte Telegram, et aucun ne
| l'envoyait :
|
|   `AnomalyDetect:66` ......... « // Sprint 11 : send TelegramAlert::dispatch »
|   `AuditVerifyChain` ......... « // En prod : envoi Slack/Telegram »
|   le déploiement ............. rien du tout
|
| Le commentaire tenait lieu de code. Et le 2026-08-21, la conséquence a été
| mesurée en production : le déploiement `377febf` a détecté un 502 dès la 21ᵉ
| seconde, a fait échouer son job — et **personne ne l'a lu pendant treize
| minutes**. *Une alarme que personne ne reçoit n'est pas une alarme.*
|
| ── CE QUE CE CANAL N'EST PAS ─────────────────────────────────────────────
|
| Il ne remplace pas le journal d'application. Le journal est le canal FIABLE
| et collecté ; Telegram est le canal LU. Une alerte part dans les deux : si
| Telegram tombe, la trace reste ; si personne ne lit les journaux, le message
| arrive quand même.
|
| ⚠️ NON CONFIGURÉ, CE CANAL LE DIT. Il ne se contente pas de ne rien faire —
| c'est exactement le défaut qu'on répare ici. Sans jeton, il journalise un
| avertissement nommant les deux variables à poser.
|
*/

return [

    'telegram' => [

        /*
         | Le jeton du bot, donné par @BotFather. Il ne vit QUE dans
         | l'environnement du conteneur — jamais dans le dépôt, jamais dans un
         | message. Sur le VPS : `/opt/axion-crm-pro/.env`, puis
         | `docker compose up -d --force-recreate` (un `restart` ne relit pas
         | `env_file` — constat `A07-003`).
         */
        'token' => env('TELEGRAM_BOT_TOKEN', ''),

        /*
         | L'identifiant du canal ou de la conversation qui reçoit. Pour un
         | canal privé il commence par `-100`. Pour l'obtenir : ajouter le bot
         | au canal, y écrire un message, puis appeler
         | `https://api.telegram.org/bot<TOKEN>/getUpdates`.
         */
        'chat_id' => env('TELEGRAM_CHAT_ID', ''),

        /*
         | Coupe-circuit. À `false`, plus rien ne part — mais le journal
         | continue de tout écrire. Utile pour faire taire le canal sans
         | retirer les jetons.
         */
        'actif' => env('TELEGRAM_ALERTES_ACTIVES', true),

        /*
         | Secondes avant d'abandonner l'envoi. Court, et c'est délibéré : une
         | alerte n'a pas le droit de retarder la commande qui l'émet. Mieux
         | vaut une alerte perdue qu'un traitement nocturne bloqué sur un
         | réseau lent.
         */
        'delai_max' => (int) env('TELEGRAM_TIMEOUT', 5),
    ],

];

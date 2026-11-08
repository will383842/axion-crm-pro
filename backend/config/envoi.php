<?php

/*
|--------------------------------------------------------------------------
| ENVOI — palier 0, socle local
|--------------------------------------------------------------------------
| `reel_actif` est ÉTEINT par défaut : tant qu'il l'est, la passerelle locale
| refuse toute injection et ne tente aucune connexion. L'ouvrir est une
| décision d'exploitation (variable d'environnement), jamais une route d'API.
| `plafond_jour` : plafond dur d'injections par workspace et par jour ; au-delà,
| le message est reporté au lendemain.
*/

return [
    // filter_var : « non », « off », « faux », « 0 » => false (un simple (bool) les lisait true).
    'reel_actif' => filter_var(env('ENVOI_REEL_ACTIF', false), FILTER_VALIDATE_BOOLEAN) === true,
    // Bornés à [0, 300] en V0 (App\Envoi\PasserelleLocale::PLAFOND_MAX) : au-delà, injection refusée.
    'plafond_jour' => (int) env('ENVOI_PLAFOND_JOUR', 20),
    // Plafond GLOBAL (tous espaces), en plus du plafond par espace.
    'plafond_global_jour' => (int) env('ENVOI_PLAFOND_GLOBAL_JOUR', 20),
    // Clé HMAC des empreintes de témoins. Jamais dans le dépôt : posée dans l'environnement.
    'cle_empreinte' => (string) env('ENVOI_CLE_EMPREINTE', ''),
    // Délai (minutes) avant nouvelle tentative après une réponse SMTP 4xx.
    'smtp_delai_4xx_minutes' => (int) env('ENVOI_SMTP_DELAI_4XX_MINUTES', 15),
    // Dérogations de TEST uniquement. Valeurs littérales (jamais lues dans l'environnement) et, de
    // toute façon, honorées SEULEMENT sous APP_ENV=testing : boucle locale 127.0.0.1 pour le faux
    // serveur SMTP, et injection dans la transaction englobante de RefreshDatabase.
    'smtp_boucle_tests' => false,
    'smtp_tolere_transaction_englobante' => false,
    // K12 — paramètres DNS par domaine d'envoi, posés dans l'environnement (jamais dans le dépôt) :
    // JSON {"domaine": {"selecteur": "...", "helo": "...", "ip": "..."}}.
    'dns' => [
        'domaines' => (array) (json_decode((string) env('ENVOI_DNS_DOMAINES', '{}'), true) ?: []),
    ],
    // K13 : surveillance des listes noires. Les serveurs de noms du SYSTÈME (resolv.conf) doivent
    // tous être locaux et non relayés (pas 127.0.0.11 de Docker) : Spamhaus refuse les résolveurs
    // publics, le contrôle est alors « non fiable ». Plus aucun réglage de résolveur ici : on ne
    // déclare pas ce que `dns_get_record()` n'utiliserait pas.
    'listes_noires' => [
        // « Non fiable » sans interruption au-delà de ce délai : domaine/IP traité comme en pause (fail-closed).
        'non_fiable_bloque_apres_minutes' => (int) env('ENVOI_LISTES_NON_FIABLE_BLOQUE_APRES_MINUTES', 30),
        'zones_ip' => ['zen.spamhaus.org'],
        'zones_domaine' => ['dbl.spamhaus.org'],
        // « ip@uuid-workspace », séparés par des virgules.
        'ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENVOI_LISTES_IPS', ''))))),
    ],
];

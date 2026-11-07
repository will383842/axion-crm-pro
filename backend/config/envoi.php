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
    // K1 — serveur d'envoi (ADR K0 D3/D7). TOUT vient de l'environnement ; jamais de fichier ni de secret dans le
    // dépôt. L'adresse n'est PAS lue en base. Appels réels bloqués tant que `reel_actif` est faux.
    'serveur' => [
        'url' => (string) env('ENVOI_SERVEUR_URL', ''),                     // https://… obligatoire (garde d'hôte)
        'urls' => (array) json_decode((string) env('ENVOI_SERVEUR_URLS', '{}'), true),      // {"source":"https://…"}
        'secret' => (string) env('ENVOI_SERVEUR_SECRET', ''),               // vide = tout refusé
        'secrets' => (array) json_decode((string) env('ENVOI_SERVEUR_SECRETS', '{}'), true), // {"source":"secret"}
        'cle_id' => (string) env('ENVOI_SERVEUR_CLE_ID', '1'),              // identifiant « k= » de l'en-tête
        'cles' => (array) json_decode((string) env('ENVOI_SERVEUR_CLE_IDS', '{}'), true),
        'ca_path' => (string) env('ENVOI_SERVEUR_CA_PATH', ''),             // AC privée épinglée (chemin)
        'client_cert_path' => (string) env('ENVOI_SERVEUR_CLIENT_CERT_PATH', ''), // TLS mutuel
        'client_key_path' => (string) env('ENVOI_SERVEUR_CLIENT_KEY_PATH', ''),
        'connect_timeout' => (int) env('ENVOI_SERVEUR_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('ENVOI_SERVEUR_TIMEOUT', 10),
        // Dérogation de TEST (valeur littérale, honorée SEULEMENT sous APP_ENV=testing).
        'tolere_transaction_englobante' => false,
    ],
    // Délai (minutes) avant nouvelle tentative après une réponse SMTP 4xx.
    'smtp_delai_4xx_minutes' => (int) env('ENVOI_SMTP_DELAI_4XX_MINUTES', 15),
    // Dérogations de TEST uniquement. Valeurs littérales (jamais lues dans l'environnement) et, de
    // toute façon, honorées SEULEMENT sous APP_ENV=testing : boucle locale 127.0.0.1 pour le faux
    // serveur SMTP, et injection dans la transaction englobante de RefreshDatabase.
    'smtp_boucle_tests' => false,
    'smtp_tolere_transaction_englobante' => false,
];

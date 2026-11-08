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
    // Clé HMAC des jetons de désinscription. Jamais dans le dépôt : posée dans l'environnement.
    // Vide => aucun jeton émis ni accepté (porte fermée).
    'cle_desinscription' => (string) env('ENVOI_CLE_DESINSCRIPTION', ''),
    // Durée de validité d'un lien de désinscription (jours).
    'desinscription_validite_jours' => (int) env('ENVOI_DESINSCRIPTION_VALIDITE_JOURS', 730),
    // Adresse de contact générique affichée sur la page de refus d'un lien expiré (facultative).
    'contact_desinscription' => (string) env('ENVOI_CONTACT_DESINSCRIPTION', ''),
    // K1 — serveur d'envoi (ADR K0 D3/D7). TOUT vient de l'environnement ; jamais de fichier ni de secret dans le
    // dépôt. L'adresse n'est PAS lue en base. Appels réels bloqués tant que `reel_actif` est faux.
    // Interrupteur PROPRE à K1 (faux par défaut), en plus de `reel_actif` : les deux doivent être vrais.
    'k1_actif' => filter_var(env('ENVOI_K1_ACTIF', false), FILTER_VALIDATE_BOOLEAN) === true,
    'serveur' => [
        // Une source n'a AUCUN repli global : URL et secret PROPRES à chaque source, sinon refus.
        'urls' => (array) json_decode((string) env('ENVOI_SERVEUR_URLS', '{}'), true),      // {"source":"https://…"}
        'secrets' => (array) json_decode((string) env('ENVOI_SERVEUR_SECRETS', '{}'), true), // {"source":"secret"}
        'cle_id' => (string) env('ENVOI_SERVEUR_CLE_ID', '1'),              // identifiant « k= » par défaut (pas un secret)
        'cles' => (array) json_decode((string) env('ENVOI_SERVEUR_CLE_IDS', '{}'), true),
        // TLS mutuel + AC privée : OBLIGATOIRES dès que `reel_actif` est vrai (refus sinon, aucun repli public).
        'ca_path' => (string) env('ENVOI_SERVEUR_CA_PATH', ''),             // AC privée épinglée (chemin)
        'client_cert_path' => (string) env('ENVOI_SERVEUR_CLIENT_CERT_PATH', ''),
        'client_key_path' => (string) env('ENVOI_SERVEUR_CLIENT_KEY_PATH', ''),
        'empreinte_cle' => (string) env('ENVOI_SERVEUR_EMPREINTE_CLE', ''),  // optionnel : « sha256//base64= » (épinglage)
        // Délais bornés à [1, 30] s par le client (jamais 0 = infini).
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
    // K15 — supervision. Interrupteur PROPRE (faux par défaut) en plus de `reel_actif` et `k1_actif` : tant que la
    // route `/v1/sante` n'existe pas côté passerelle, l'allumer mettrait les files en pause (homme mort).
    'sante' => [
        'actif' => filter_var(env('ENVOI_SANTE_ACTIF', false), FILTER_VALIDATE_BOOLEAN) === true,
        // Silence du serveur au-delà duquel : alerte critique + pause (homme mort, INF-025).
        'silence_minutes' => 15,
        // Silence au-delà duquel une alerte d'ATTENTION part déjà (tunnel coupé < 10 min, plan 33 K15) ; la pause reste à `silence_minutes`.
        'silence_alerte_minutes' => 10,
        // Marge (s) retranchée aux deux seuils de silence : le planificateur passe toutes les 5 min, au plus juste ±quelques secondes.
        // Sans marge, un passage à 9 min 59 s ferait attendre le suivant (+5 min) : alerte à 15 min au lieu de < 10 (ADR K0), pause à 20 min.
        'silence_marge_s' => 60,
        // Trou (s) entre deux relevés au-delà duquel la supervision a été interrompue (arrêt, interrupteur éteint) : le silence est recompté depuis la reprise.
        'supervision_trou_s' => 900,
        // Relève IMAP (vie du canal des retours) sans passage réussi depuis N h alors que des envois vieux de N h ont eu lieu : critique + pause.
        'retours_muets_heures' => 2,
        // Seuils chiffrés. file/horloge : strictement au-delà ; disque/mémoire : strictement en deçà ; certificat : jours restants ≤.
        'file_age_s' => ['attention' => 900, 'critique' => 3600],
        'disque_libre_pct' => ['attention' => 20, 'critique' => 10],
        'memoire_libre_pct' => ['attention' => 15, 'critique' => 7],
        'certificat_jours' => ['attention' => 30, 'renforce' => 14, 'critique' => 7], // ADR K0 : 30, 14 et 7 jours
        'horloge_ecart_s' => ['attention' => 60, 'critique' => 300],
        // Dernier contrôle DNS (K12, quotidien) plus vieux que ceci : attention.
        'dns_age_max_heures' => 36,
    ],
];

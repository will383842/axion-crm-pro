<?php

/*
|--------------------------------------------------------------------------
| Drapeaux du chantier « centralisation CRM » (autopilot 2026-08-13)
|--------------------------------------------------------------------------
|
| Stratégie git du chantier : fusion progressive derrière drapeaux. Tout le
| code livré avant l'activation finale doit être INERTE — c'est-à-dire que,
| tous drapeaux à OFF, le comportement observable est identique à celui d'avant
| la PR. Le rollback d'un lot est alors « remettre le drapeau à OFF », sans
| revert ni restauration de base.
|
| Référence : _PLANS/2026-08-13_ORDRE-MISSION-AUTOPILOT-CRM.md
|             (§ STRATÉGIE GIT — FUSION PROGRESSIVE DERRIÈRE DRAPEAUX)
|
*/

return [

    /*
    | L0 — isolation.
    |
    | `db_app_role` : quand ce drapeau est à true, l'application se connecte à
    | Postgres avec le rôle NON-PROPRIÉTAIRE `axion_app` au lieu du rôle
    | `axion` (qui est SUPERUSER + BYPASSRLS : vérifié en prod le 2026-08-14,
    | `SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname='axion'` →
    | t / t). C'est CE drapeau qui rend la Row Level Security réellement
    | opérante : tant qu'il est à false, les policies — même en FORCE — sont
    | intégralement contournées par le superutilisateur, donc le durcissement
    | SQL est inerte.
    |
    | Les migrations continuent de tourner avec le rôle propriétaire
    | (connexion `pgsql_owner`, cf. config/database.php).
    */
    'db_app_role' => env('CRM_DB_APP_ROLE_ENABLED', false),

    /*
    | `strict_workspace_scope` : ceinture applicative (la RLS est la bretelle).
    | Quand il est à true :
    |   - les modèles workspace-scopés portent un global scope Eloquent qui
    |     filtre sur le workspace courant ;
    |   - une lecture/écriture SANS contexte workspace lève
    |     MissingWorkspaceContextException au lieu de renvoyer silencieusement
    |     zéro ligne.
    |
    | Ce second point est le cœur du piège identifié à la contre-vérification :
    | avec des policies strictes, un job Horizon ou une commande artisan qui
    | « oublie » de poser le contexte ne voit AUCUNE ligne et ne fait donc RIEN,
    | tout en sortant en succès. Un cron vert qui ne purge rien est le pire des
    | échecs — on exige donc un échec BRUYANT.
    */
    'strict_workspace_scope' => env('CRM_STRICT_WORKSPACE_SCOPE', false),

    /*
    |--------------------------------------------------------------------------
    | Mode simulacre
    |--------------------------------------------------------------------------
    |
    | 🔴 CETTE CLE EXISTE PARCE QUE `env('MOCK_MODE', true)` ETAIT LU AU MOMENT
    | DE LA REQUETE, dans le code applicatif.
    |
    | Quand `php artisan config:cache` a tourne - et l'entrypoint de production
    | le tente a chaque demarrage - Laravel NE CHARGE PLUS le fichier `.env`.
    | `env()` rend alors sa valeur PAR DEFAUT, ici `true` : la production se
    | croyait en mode simulacre, et les courriels d'authentification (lien
    | magique, reinitialisation) n'etaient jamais envoyes, meme avec un SMTP
    | parfaitement configure. `MOCK_MODE=false` etait pourtant bien pose sur le
    | serveur - il n'etait simplement plus lu.
    |
    | C'est la raison pour laquelle on ne lit jamais `env()` ailleurs que dans un
    | fichier de configuration. Mesure le 2026-08-19 (audit 360, F40-002).
    |
    */
    'mock_mode' => env('MOCK_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | 🔑 `mock_mail` — LE COURRIEL SE DEVERROUILLE SEUL
    |--------------------------------------------------------------------------
    |
    | POURQUOI CE DRAPEAU EXISTE. `MOCK_MODE` est un interrupteur GENERAL.
    | Mesure du 2026-08-24 : il commande aussi `BlacklistsCheck` (qui declare
    | toutes les IP saines tant qu'il est vrai), `SignalsNightlyScan` (no-op),
    | et les imports `ImportIgnAdminExpress` / `ImportNaf` / `ImportRpps`, qui
    | lisent des fixtures au lieu des sources reelles.
    |
    | Autrement dit : qui voulait seulement recevoir les invitations par
    | courriel devait, du meme geste, reveiller un scan nocturne et trois
    | imports sur des API externes. Un reglage de production ne doit pas forcer
    | ce marche-la.
    |
    | Le repli sur `MOCK_MODE` garde le comportement existant : rien ne change
    | pour qui ne pose pas `MOCK_MAIL`. C'est le patron DEJA employe dans ce
    | depot par `MOCK_IGN`, `MOCK_RPPS` et `MOCK_INSEE` — on etend, on
    | n'invente pas (§28.5).
    |
    | En production, `MOCK_MAIL=false` suffit a faire partir les liens de
    | reinitialisation et d'invitation, sans toucher au reste.
    |
    | ⚠️ `env()` ICI ET NULLE PART AILLEURS — c'est la lecon F40-002 rappelee
    | juste au-dessus : avec `config:cache`, un `env()` lu hors configuration
    | rend sa valeur par defaut en production.
    |
    | ⚠️ NE PAS CONFONDRE AVEC `MOCK_SMTP`, qui existe deja et ne commande PAS
    | l'envoi. Celui-la choisit l'implementation de `SmtpProber`
    | (`MockServicesProvider`) : il sert a SONDER l'existence d'une adresse chez
    | Hunter, pour la prospection. Deux sujets distincts sous des noms voisins —
    | d'ou cette note, pour que personne ne pose l'un en croyant l'autre.
    |
    */
    'mock_mail' => env('MOCK_MAIL', env('MOCK_MODE', true)),

    /*
    | L2 — ingestion site → CRM (`POST /api/internal/site-sync`).
    |
    | `enabled` : drapeau MAÎTRE. À false (défaut), l'endpoint répond 503
    | `ingest_disabled` et n'écrit RIEN — la route existe, le code est livré,
    | mais la tuyauterie est inerte. Rollback = remettre à false.
    |
    | `candidates_enabled` : second verrou, propre à l'univers VIVIER. Les flux
    | candidats ne s'ouvrent qu'APRÈS que les textes de consentement v2 sont
    | servis en production sur le site (séquencement croisé imposé par l'ordre
    | de mission). Même avec ce drapeau à true, une fiche candidat sans version
    | de consentement v2 est REJETÉE — le drapeau ouvre le flux, il ne dispense
    | jamais de la preuve du consentement.
    |
    | `hmac_secret` : secret partagé du canal site→CRM (64 hex). Absent =
    | aucune requête ne peut être authentifiée (l'endpoint répond 401), ce qui
    | est le bon état par défaut d'un serveur qui n'a pas encore reçu le secret.
    |
    | `business_workspace` : slug du workspace commercial de destination. Le
    | site n'a PAS à connaître les workspaces du CRM : la destination est une
    | décision du CRM, pas une donnée du payload (sinon un appelant compromis
    | choisirait l'univers d'atterrissage de ses fiches, vivier compris).
    */
    'ingest' => [
        'enabled' => env('CRM_INGEST_ENABLED', false),
        'candidates_enabled' => env('CRM_INGEST_CANDIDATES_ENABLED', false),
        'hmac_secret' => env('SITE_SYNC_HMAC_SECRET', ''),
        'business_workspace' => env('CRM_INGEST_BUSINESS_WORKSPACE', 'axion-ia'),
        // Fenêtre de tolérance de l'horodatage signé (anti-rejeu). 0 = contrôle
        // désactivé (le site ne signe pas encore d'horodatage).
        'max_clock_skew_seconds' => (int) env('CRM_INGEST_MAX_CLOCK_SKEW', 300),
    ],

    /*
    | 🔴 A05-001 (S1) — CLÉ DE RAPPROCHEMENT DES PERSONNES.
    |
    | Mesure du 2026-08-18 en production : 1 319 567 contacts, 410 481 avec
    | e-mail, **0 avec `person_key`**. La fiche 360°
    | (`/console/personnes/$personKey`) et le rapprochement site ↔ CRM étaient
    | donc inatteignables pour 100 % du stock.
    |
    | La clé n'est pas un « sel incalculable » : c'est un HMAC-SHA256 dont la
    | formule est écrite dans `axionia/src/lib/security/email-hash.ts` —
    | `HMAC(PII_ENCRYPTION_KEY, "submission-email-index-v1:" + trim(lower(email)))`.
    | Seul le SECRET vit côté site.
    |
    | ⚠️ `secret` DOIT valoir exactement le `PII_ENCRYPTION_KEY` du site. Une
    | valeur différente fabriquerait des clés d'apparence valide qui ne
    | rapprocheraient RIEN, et rendraient muets l'export art. 15 et
    | l'effacement art. 17 servis par `POST /internal/site-sync/gdpr`.
    |
    | Vide par défaut, et c'est voulu : sans secret, aucune clé n'est écrite et
    | la commande de remplissage refuse. Inventer un sel serait le seul geste
    | irréversible de ce lot.
    */
    'person_key' => [
        'secret' => env('CRM_PERSON_KEY_SECRET', ''),
    ],

    /*
    | L3 — funnel d'ingestion de la COLLECTE (schéma pivot ScrapedRecord).
    |
    | `enabled` : à false (défaut), `POST /internal/scraper-result` n'écrit RIEN
    | — le message est journalisé, et la réponse le DIT depuis le 2026-08-20 :
    | `200 {"ingested": false, "raison": "funnel_ferme"}`. Le funnel est livré
    | mais inerte, fusion sans risque.
    |
    | 🔴 CE PARAGRAPHE ÉCRIVAIT « log-only, 200 `ingested: true` », ET C'ÉTAIT LE
    | DÉFAUT LUI-MÊME, DOCUMENTÉ. Constat C18-001 (S1) : l'endpoint confirmait
    | une ingestion qui n'avait pas lieu. La porte fermée est un choix de
    | conception ; l'accusé de réception mensonger n'en était pas un — c'est la
    | leçon IndexNow que ce dépôt cite par ailleurs. Seule la réponse a changé,
    | pas l'inertie. Gardes : `tests/Feature/Internal/ReponseVeridiqueIngestionTest.php`
    | et `workers/tests/reponse-ingestion-veridique.test.ts`.
    |
    | À true, le même endpoint valide le
    | pivot, ingère (registre → idempotence → dédup → backfill-only → tags →
    | timeline) et répond avec l'outcome. Les autres portes (waterfall PHP,
    | `scraping:ingest-file`) ne dépendent PAS de ce drapeau : elles n'ont pas
    | de comportement historique à préserver.
    |
    | `validate_mx` : validation DNS des emails collectés (« jamais confiance
    | au collecteur »). false en tests — aucun appel réseau réel, même règle
    | que les MOCK_*.
    */
    'scrape_funnel' => [
        'enabled' => env('CRM_SCRAPE_FUNNEL_ENABLED', false),
        'validate_mx' => env('CRM_SCRAPE_VALIDATE_MX', true),
    ],

    /*
    | Backfill des tags `src:scraping-*` sur le stock (4,29 M de companies,
    | décision 2 de l'audit scraping). DERNIER acte de la mission (ordre de
    | mission §5) : la commande `scraping:backfill-src-tags` refuse tant que ce
    | drapeau n'est pas à true.
    */
    'backfill_enabled' => env('CRM_BACKFILL_ENABLED', false),

    /*
    | L4 — purges automatiques par univers (plan §2.8.3). À false, les
    | commandes `rgpd:purge-vivier` / `rgpd:purge-business-prospects` refusent
    | et le scheduler les saute : construites, testées, inertes.
    |
    | 🔴 B17-009 (S0), mesuré le 2026-08-20. « Inerte » n'est pas un état
    | transitoire ici : `CRM_PURGE_ENABLED` n'apparaît que DEUX fois dans tout
    | le dépôt — cette ligne, et `.env.example:258` — et vaut `false` aux deux.
    | Aucun `docker-compose*.yml`, aucun fichier d'`infra/`, aucun workflow
    | `.github/` ne le pose. Les deux SEULES purges RGPD correctement écrites du
    | dépôt n'ont donc jamais tourné, et l'échéance CNIL (CVthèque 2 ans,
    | prospection 3 ans) n'est tenue par aucun automatisme.
    |
    | Ce défaut ne se répare PAS en basculant ce défaut à `true` : cela
    | déclencherait en production la suppression mensuelle de fiches candidats
    | réelles. C'est un geste d'exploitant (STOP & ASK), pas un correctif
    | d'audit. Ce qui a été réparé, c'est le SILENCE : le saut se journalise
    | désormais en `warning` à chaque passage (cf. `routes/console.php`, closure
    | `$purgeRgpdRetenue`), de sorte que l'inaction laisse une trace datée.
    |
    | POUR ACTIVER : poser `CRM_PURGE_ENABLED=true` dans l'environnement de
    | production, puis vérifier une première fois à la main avec
    | `artisan rgpd:purge-vivier --dry-run`.
    */
    'purges_enabled' => env('CRM_PURGE_ENABLED', false),

    /*
    | L5 — mini-outbox CRM → site (convergence BIDIRECTIONNELLE des
    | consentements, plan « Synchro BIDIRECTIONNELLE »).
    |
    | `outbound_enabled` : à false (défaut), `crm:flush-outbound` REFUSE et le
    | scheduler la SAUTE — double verrou, comme les purges de L4. Le producteur
    | (`ConsentOutboundRecorder`), lui, n'est PAS gaté : il remplit un journal
    | local qui ne sort pas du serveur et ne change aucune réponse d'API. Le
    | drapeau garde la seule chose observable de l'extérieur, l'émission HTTP.
    |
    | `site_webhook_url` : endpoint webhook du site (`POST /api/internal/
    | crm-webhook`). Vide = la commande refuse : on ne devine pas une
    | destination.
    |
    | Le secret de signature n'est PAS dupliqué ici : c'est le MÊME
    | `SITE_SYNC_HMAC_SECRET` que le canal site → CRM (cf. `ingest.hmac_secret`).
    | Un seul secret à faire tourner, un seul à ne pas perdre — deux secrets
    | pour un même couple de systèmes finissent toujours par diverger.
    |
    | `max_attempts` : essais CONSOMMÉS avant `gave_up`. Une indisponibilité
    | temporaire du site n'en consomme AUCUN — ni le 503, ni les 408/429/502/504,
    | ni une connexion refusée (constat B14-005, cf. CrmFlushOutbound).
    |
    | ── 🔴 CONSTAT B14-013 (S1) : LES DEUX SENS NE S'OUVRENT PAS PAREIL ────────
    |
    | Ce qui est MESURÉ ici, c'est le code livré ; la production n'est pas
    | observable depuis ce dépôt, et rien dans ce dépôt ne l'ouvre. Relevé le
    | 2026-08-20 sur la branche `fix/a35-authentification` :
    |
    |   - `CRM_INGEST_ENABLED`   : défaut `false` (ligne ~101), `false` dans
    |     `.env.example:86`. Aucun `docker-compose*.yml`, aucun `infra/`, aucun
    |     `.github/` ne le pose.
    |   - `CRM_OUTBOUND_ENABLED` : défaut `false` (ci-dessous), `false` dans
    |     `.env.example:268`. Idem, posé nulle part.
    |
    | Donc, PAR DÉFAUT, les deux sens sont fermés — l'asymétrie annoncée n'est
    | PAS dans les défauts. Elle est dans le NOMBRE DE GESTES qu'il faut pour
    | ouvrir chaque sens :
    |
    |   site → CRM : un drapeau (`CRM_INGEST_ENABLED`) et le secret partagé,
    |                que l'authentification de l'endpoint exige de toute façon.
    |   CRM → site : le drapeau `CRM_OUTBOUND_ENABLED`, le MÊME secret, ET
    |                `SITE_CRM_WEBHOOK_URL` — qui n'a aucun défaut utilisable et
    |                n'est posé dans AUCUN fichier de ce dépôt.
    |
    | Un exploitant qui « ouvre le canal » à la bascule ouvre donc l'ingestion
    | et croit avoir ouvert l'émission. Ce qu'il obtient : `crm:flush-outbound`
    | tourne toutes les 5 minutes, refuse faute de destination, et empile les
    | oppositions décidées dans la console. Ce n'est pas réparable par un défaut
    | — poser une URL de production ici serait deviner. Ce qui EST réparé, c'est
    | le silence : les deux refus se journalisent désormais en `error`
    | (`crm.outbound.destination_absente`, `crm.outbound.secret_absent`).
    |
    | POUR OUVRIR LE SENS CRM → SITE, les trois clés vont ENSEMBLE :
    |   CRM_OUTBOUND_ENABLED=true
    |   SITE_CRM_WEBHOOK_URL=https://<site>/api/internal/crm-webhook
    |   SITE_SYNC_HMAC_SECRET=<le MÊME secret que le sens site → CRM>
    */
    'outbound_enabled' => env('CRM_OUTBOUND_ENABLED', false),

    'outbound' => [
        'site_webhook_url' => env('SITE_CRM_WEBHOOK_URL', ''),
        'max_attempts' => (int) env('CRM_OUTBOUND_MAX_ATTEMPTS', 8),
        'timeout_seconds' => (int) env('CRM_OUTBOUND_TIMEOUT', 10),
        'batch_size' => (int) env('CRM_OUTBOUND_BATCH', 100),
    ],

    /*
    | L6 — console CRM v2 (plan §2.11, conception UX v2 « 3 espaces »).
    |
    | À false (défaut), les routes `/v1/crm/*` de la console répondent **404**,
    | et `GET /v1/config/features` annonce `console_v2: false` — le frontend
    | n'affiche alors RIEN de neuf. L'inertie est donc observable des deux
    | côtés, et le rollback est « remettre le drapeau à OFF ».
    |
    | Pourquoi 404 et pas 503 : un 503 dit « réessaie plus tard », ce qui a du
    | sens pour un canal d'ingestion dont l'émetteur doit rejouer sa ligne
    | (L2/L3). La console n'a rien à rejouer : tant que le drapeau est fermé,
    | ces routes n'existent pas — c'est exactement ce que dit un 404, et cela
    | ne divulgue pas l'existence d'une surface non ouverte.
    */
    'console_v2' => env('CRM_CONSOLE_V2_ENABLED', false),

    /*
    | 🔴 C19-011 (S3) — LA SONDE DE SANTE DES MANDATAIRES NE VERIFIAIT AUCUN
    | CERTIFICAT.
    |
    | Mesure du 2026-08-22 : `WebshareProvider.php:70` et `IPRoyalProvider.php:56`
    | ecrivaient tous deux `Http::withOptions(['proxy' => …, 'verify' => false])`
    | avant d'appeler `https://api.ipify.org?format=json`. Un mandataire pouvait
    | donc rendre N'IMPORTE QUELLE reponse pour ce domaine : il suffisait qu'elle
    | contienne une IP bien formee pour que `healthCheck()` declare le point de
    | sortie sain. La seule sonde du sous-systeme etait aveugle a la substitution
    | qu'elle est censee detecter.
    |
    | POURQUOI UNE CLE PAR FOURNISSEUR, ET PAS UN SEUL INTERRUPTEUR. Certains
    | mandataires HTTPS presentent un certificat d'interception ; remettre la
    | verification partout d'un coup ferait passer des points de sortie SAINS pour
    | morts, et `pickEndpoint()` leve une RuntimeException des qu'il n'en reste
    | aucun — c'est-a-dire l'arret de la collecte. On ferme donc l'oeil
    | fournisseur par fournisseur, apres mesure, et jamais globalement.
    |
    | Le defaut est `true` (on verifie). Quand une cle est mise a `false`, la
    | sonde le JOURNALISE a chaque appel : une verification desactivee doit rester
    | visible dans les journaux, sinon elle redevient l'etat par defaut invisible
    | qu'on repare ici.
    */
    'proxies' => [
        'verify_tls' => [
            'webshare' => env('WEBSHARE_PROXY_VERIFY_TLS', true),
            'iproyal' => env('IPROYAL_PROXY_VERIFY_TLS', true),
        ],
    ],

];

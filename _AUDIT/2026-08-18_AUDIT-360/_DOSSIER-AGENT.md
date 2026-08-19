# DOSSIER COMMUN — à lire par TOUT agent avant de mesurer quoi que ce soit

> Fourni par l'agent 2 (gardien du contexte). Il t'évite de redécouvrir ce qui est déjà mesuré,
> et il t'interdit de réinventer ce qui existe.

## 1. La référence — mesurée, pas déduite

- Dépôt CRM : le dépôt principal, référence **`main = e8924b8`** — code **identique** à `c0c453d` : les 3 commits intermédiaires ne touchent que 2 documents `_REPORTS/` et 1 script neuf, `infra/scripts/definir-mot-de-passe-crm.sh` (celui-ci **entre au périmètre**, personne ne l'a audité).
  ⚠️ **Une autre session de construction pousse sur `main` PENDANT cet audit** (PR #187, #188, #189, entre 11:26 et 12:07). Re-mesure `git log` avant de conclure, et **nomme la référence exacte de chaque constat**. N'ouvre aucune PR sans avoir relu `gh pr list`.
- Dépôt site : `C:\Users\willi\Documents\Projets\Axion-IA\axionia`.
- ⛔ **INTERDIT** : le worktree `C:\Users\willi\Documents\Projets\crmpro-wt-etape1a` et sa branche `travail`.
  Ne pas y lire, ne pas y écrire, ne pas le supprimer. Consigne explicite du dirigeant.
- Le worktree `C:\Users\willi\Documents\Projets\crmpro-wt-etape0` existe encore (`702253c`) : résiduel, ne pas s'en servir comme référence.
- **N'utilise AUCUN SHA écrit dans un document.** Ils ont été faux trois fois. Relis `git log` toi-même.

## 2. La doctrine — dix règles, elles priment sur tout

1. **Le code fait foi. Les documents sont des hypothèses.** Y compris les rapports de `_REPORTS/`, `_AUDIT/`, `TODO.md`, `CHANGELOG.md`, les commentaires, et le prompt d'audit lui-même. Un constat sans commande jouée n'existe pas.
2. **Une garde ne vaut que si on l'a vue rougir** — et (ajout du 19/08) **si elle rougit sur l'objet qui casse**, pas sur un fichier qui décrit l'objet.
3. **Témoin négatif obligatoire.** Un « rien trouvé » ne vaut que si le contrôle a d'abord été prouvé capable de trouver.
4. **Le geste réel avant l'instrumentation** (écrans : ouverts pour de vrai).
5. **Mesure, jamais supposition.** Interdit d'écrire « rapide », « couvert », « conforme » sans nombre.
6. **Vérifier sur la bonne référence**, nommée dans chaque constat.
7. **Celui qui réalise ne vérifie jamais sa propre pièce.**
8. **On étend, on ne réinvente pas.**
9. **Un désaccord se tranche par une mesure.**
10. **Rien n'est fini sans preuve archivée** dans `04_PREUVES/`.

## 3. L'atelier — ce qui tourne, et comment y toucher

```
docker ps                          # axion-crm-{api,app,caddy,postgres,redis,horizon,scheduler,reverb}
https://app.localhost              # SPA (200)
https://api.localhost/up           # API (200)
http://localhost:58080/up          # API sans TLS (pour Node/outillage)
docker exec axion-crm-api php artisan ...
docker exec axion-crm-postgres psql -U axion -d axion_crm -c "..."
```
Bases locales : `axion_crm` (travail), `axion_crm_test` (Pest), `axion_crm_perf` (300 000 fiches),
`axion_crm_perf4m` (2,8 M fiches). Les deux dernières sont **jetables et rejouables**
(`backend/database/perf/seed_reference_50k.sql`, `seed_volume_production_4m.sql`).

Production **en lecture seule** : `https://api.axion-crm-pro.com`, `https://app.axion-crm-pro.com`.
Préproduction : `https://staging.axion-crm-pro.com`, `https://staging-api.axion-crm-pro.com`.
⛔ **Aucune écriture, aucune mutation, aucun secret touché en production.**

## 4. Écarts d'inventaire déjà mesurés — ne les redécouvre pas, appuie-toi dessus

Voir `01_INVENTAIRE.md`. En résumé, le prompt d'audit **sous-compte ou se trompe** sur :
- **35 tâches planifiées**, pas 10 (25 non nommées, dont 3 destructives).
- **58 migrations**, pas 54. **18 modèles**, pas 21. **84 services**, pas 68.
- **3** contrôleurs Phase 2, pas 5 (`AnalyticsController` et `CrmController` **n'existent pas**).
- **+1 contrôleur interne** absent du prompt : `Internal/ZeptoMailWebhookController`.
- **37 écrans**, pas 39 : `/crm` et `/analytics` n'existent pas ; la fiche 360° est `/console/personnes/$personKey`.
- `routes/api.php` fait **328 lignes**, pas 311 ; **112 déclarations** de route.

## 5. Constats déjà ouverts — ne les re-rapporte pas ; approfondis, ou appuie-toi dessus

> ⚠️ **Liste tenue à jour et CORRIGÉE.** Plusieurs constats ont été révisés à la baisse après
> contre-vérification — c'est normal et c'est le but. **Utilise cette version, pas ta mémoire.**

**Les S0 :**
- **A-010** — 🔴 la **PRODUCTION** sert toute l'API par `php -S`, **un seul processus** : requêtes **sérialisées**. Escalier de 15 ms sur 12 requêtes simultanées ; témoin positif séquentiel plat à 15 ms. Une requête lente bloque **tous** les utilisateurs (compteurs du hub mesurés à **17,5 s cache froid**). **Critère 17 du §29 et principe directeur 8 inatteignables par construction.** php-fpm **est dans l'image** et n'est jamais lancé (⚠️ `pm.max_children = 5` par défaut).
- **A-012** — 🔴 **personne ne s'est jamais connecté au CRM en production** (1 compte, 0 session, 0 jeton, depuis le 2026-05-17). Trois causes : mot de passe initial généré et annoncé **une seule fois** à la console ; **`MAIL_MAILER` défini nulle part** → `config/mail.php` retombe sur `'log'` → **aucun courriel ne part** (ni lien magique, ni réinitialisation), malgré **7 clés ZeptoMail valides** en production ; et les deux voies de secours passent par le courriel. **`MAIL_MAILER = log` est une décision du dirigeant, non rouverte** — le défaut est que personne n'avait vu qu'elle coupait aussi l'**authentification**.
- **A07-001** — **l'enrôlement 2FA écrit trois colonnes qui n'existent pas** : aucun utilisateur neuf ne peut terminer sa première connexion. *Maillon manquant de A-012 : poser un mot de passe ne suffira pas.*
- **A07-003** — le **runbook de rotation des secrets** prescrit `docker compose restart` : un secret réputé tourné **ne l'est pas**.
- **B16-002 / B16-003 / B16-004** — chaîne d'audit **tronquable par la queue** sans détection · **`created_at` hors hachage** (2026 → 2019 passe « OK ») · **`GET /audit-logs` rend le journal de tous les espaces à tout compte authentifié**.
- **B11-001 / B11-002** — **26 tâches planifiées sur 33** et **5 jobs sur 6** s'exécutent **sans contexte d'espace**.
- **B12-001 / B12-003 / B12-004** — `GET /companies/{id}` **rend la fiche d'un autre espace** (200 mesuré ; rôle DB **`BYPASSRLS`**) · **aucune policy n'est jamais appelée**, un `viewer` a **supprimé définitivement** une entreprise · `POST /internal/scraper-result` **accepte une signature forgée** (HMAC réimplémenté, secret vide toléré).
- **B10-004** — **export RGPD = 4 tables, effacement = 8, `candidates` dans NI L'UN NI L'AUTRE**.
- **B14-002** — `erasure` traverse, **le site répond « 200 applied », et rien n'est effacé**.
- **C19-007** — base légale « intérêt légitime » **sans mise en balance écrite ni information art. 14**, pour **1 319 567 personnes**.
- **F40-002** — `MAIL_MAILER` nulle part (→ A-012).

**Les autres, souvent utiles comme point de départ :**
- **A-001 (S2, abaissé depuis S1)** — le 500 au lieu de 401 n'arrive **que sans l'en-tête `Accept: application/json`**, sur **106 routes**. **Le SPA le pose et n'est pas touché.** Les 11 routes publiques sont épargnées. *Ne réécris pas « le produit est inutilisable ».*
- **A-002 / B10-013** — `GET /saved-views` répond **200 liste vide** au lieu de 501 — **et le même patron vaut pour `/ai-act/register` (un registre réglementaire !) et la recherche globale**. Au total **9 routes rendent 200 avec un corps figé**.
- **A-003 (S2)** — **8 des 16 `.sh` suivis portent encore des octets CR**, dont `dr-drill.sh`, `backup-postgres.sh`, `verifier-sauvegarde.sh`, `entrypoint-prod.sh`.
  ⚠️ **Méthode** : `grep -c $'\r'` n'est **pas fiable** selon les shells de ce poste. **Compte les octets `0x0d`** (`od -An -tx1 f | tr ' ' '\n' | grep -c '^0d'`) et **valide ta méthode sur un témoin pur LF et un témoin pur CRLF** avant usage.
- **A-004 (S3, abaissé depuis S2)** — le Caddy **local** demande des certificats ACME pour les domaines de production. **Correction : c'est la limite horaire de validations échouées qui est consommée, pas le quota d'émission — le renouvellement n'est pas menacé.**
- **A-005 (S3)** — `/cold-email` et `/linkedin` restent joignables par URL ; `/crm` et `/analytics` **ont bien été retirés**.
- **A-006 (S2)** — le §4.8 et le §6.2 du mandat décrivent **une barre latérale qui n'existe plus** : refondue à l'étape 0, **6 sections**, une seule entrée « Contacts », **aucune entrée verrouillée**. **Huit des dix « défauts » du §6.2 sont déjà corrigés.** L'écart réel à la cible §23.3 : **1 conforme, 4 partiels, 13 absents** — manquent le groupe **ÉCHANGES entier**, Boîte de réception, Mes rendez-vous, Mes tâches, Organisations, Prospection, les 6 vues épinglées, Canal, Coûts, les 8 sous-groupes de Réglages, les 3 éléments du pied de barre, et **tous les compteurs**.
- **A-007 (S1)** — Telescope actif sans ses tables en production. **Chiffres CORRIGÉS : ~5,5 erreurs/minute** (et non 56 — je comptais des occurrences de chaîne, elle apparaît **7 fois par entrée**) et **~90 Mo/jour** (et non 133). `laravel.log` = **270 Mo**, `LOG_LEVEL=debug`, aucune rotation. **Le « 6 par minute » du journal de construction était juste.**
- **A-009 (S2)** — l'atelier local sert l'API par `php -S` : **sérialise tes appels HTTP, allonge tes délais, et si une mesure expire, dis-le au lieu de conclure.**
- **A-011 (S1)** — **défaut systémique : les gardes de ce dépôt mesurent souvent le mauvais objet.** **Sept cas** mesurés indépendamment. Passe **chaque** garde que tu rencontres à ce crible, et dis **sur quel objet elle rougit**.
- **D23-001 (S1)** — **l'atelier local servait un bundle frontend vieux de 32 h.** Reconstruit par le chef de chantier. **La production, elle, est à jour** (vérifié : « Journaux de collecte » ×2, « Runs de scraping » ×0). **Toute mesure d'interface antérieure à la reconstruction est non valide** (décision D-011).
- **E32-002 (S1)** — **le canal ne transporte aucun contenu** : la timeline du CRM est un **INDEX**, par conception assumée. Retirer les écrans du site perdrait **13 catégories d'information**. *Arbitrage à trancher, pas bug à corriger — il contredit le principe 10.*
- **B13-001 (S1)** — **aucun émetteur du site ne transmet de SIREN**, et aucun formulaire n'en collecte : **100 %** des leads restent en arbitrage manuel. **Cause racine du critère 18.**
- **A05-001 (S1)** — **0 contact sur 1 319 567 porte une `person_key`** (410 481 ont pourtant un e-mail) : **la fiche 360° est inatteignable**, et le **critère 4** avec elle.

## 5 bis. 🔴 TROIS FAITS QUI INVALIDENT CERTAINES MESURES — lis-les avant de conclure quoi que ce soit

0. **Toute mesure de performance ou de concurrence** doit dire si elle a été faite **à un seul
   utilisateur** ou en charge. À un seul utilisateur, la sérialisation de A-010 est **rigoureusement
   invisible** : c'est ainsi que le défaut a traversé tous les contrôles verts du produit.

1. **L'atelier local et la production n'exécutent PAS le même cloisonnement** (constat **B11-010**) :
   `CRM_DB_APP_ROLE_ENABLED` vaut **`false` en local** et **`true` en production**. Toute mesure
   d'étanchéité faite en local mesure donc **autre chose** que ce qui tourne. Si ton verdict dépend de
   la RLS, soit tu armes le dispositif comme en production, soit tu déclares ton verdict **non
   concluant** — tu ne le présentes pas comme valide. C'est le piège 19 appliqué à l'atelier lui-même.
2. **La base `axion_crm_test` est codée en dur et partagée** (constat **B11-005**) : plusieurs agents
   qui y lancent des tests **se détruisent mutuellement**, et le rouge que tu observes peut n'être que
   celui du voisin. **Crée ta propre base** (`axion_crm_<ton-id>`) et vérifie
   `select count(*) from migrations` = **58** avant et après chaque mesure.

## 6. Ce qui est DÉJÀ RÉFUTÉ par la mesure — ne le re-rapporte pas comme ouvert

- « le frontend n'a aucun test » → **37 fichiers de test frontend**.
- « `reportUnmatchedIgnoredErrors: false` », « baseline PHPStan 2 045 l. » → c'est **`true`**, et **1 321 l.** ; `phpstan analyse` niveau 8 rend **`[OK] No errors`** (rejoué le 19/08).
- 🔴 **« Export CSV plafonné » — la question a été mal posée trois fois de suite, et voici l'état vrai.** Le mandat parle d'un « plafond de 100 » (code mort), puis d'un « plafond réel 5 000, silencieux ». L'agent 12 a cherché dans les contrôleurs **du CRM** : il n'y en a **aucun** — et **aucune trace au journal non plus** (B12-010, S1). J'en avais conclu « il n'existe aucun plafond » : **c'était imprécis.** L'agent 33 a mesuré que la fragilité **F14 porte sur l'export du SITE**, pas du CRM — et là, **le plafond existe : `PLAFOND_EXPORT = 50 000`**, appliqué, et **bruyant** (avertissement dans le fichier + Sentry + journal RGPD). Le « 5 000 » **était vrai** (`git log -S "take: 5000"` le retrouve) et a été remplacé le **2026-08-18**, la veille de l'audit.
  **Donc : côté SITE, c'est corrigé et il n'y a rien à rapporter. Côté CRM, il n'y a ni plafond ni journal — et c'est un vrai défaut.** *Deux exports, deux dépôts, un seul mot : c'est ainsi qu'on se trompe trois fois.*
- **« `calendly_canceled` n'est pas émis »** → **FAUX**, mesuré par l'agent 33 : `réservé`, `annulé` et `absent` sont **automatiques** (3 émetteurs, sondage 1×/min et 1×/10 min). **Seul `honoré` reste manuel**, par conception assumée et documentée — *rien dans l'API de Calendly ne dit qu'un rendez-vous a eu lieu*. Ne re-rapporte que `honoré`.
- « 20 PR Dependabot ouvertes » → **0 PR, et 0 branche `dependabot/*` sur `origin`** (elles ont été supprimées depuis ma première mesure). Les 20 PR ont été **fermées volontairement par Dependabot lui-même** après le gel des 5 écosystèmes (`fccc9d1`), sous une politique écrite et datée : `_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md`. **Aucune n'était corrective de sécurité.**
- Les **57 alertes** GitHub sont réelles (4 critiques / 18 hautes / 31 moyennes / 4 basses) mais **57/57 sont npm** et **aucune n'a de chemin d'appel démontré en production** : 32 viennent de `workers/`, qui n'est déployé par aucun compose hors tests. Mesuré par l'agent 47.
- « les hooks Git empêchent X » → **il n'y a aucun hook** (`core.hooksPath` vide).
- « la faille Postgres/Redis exposés est à corriger » → **fermée le 19/08**, vérifiée depuis l'extérieur. Restent **⑤ rotation des secrets (REFUSÉE par Will, ne plus la proposer)** et **⑥ notification CNIL (brouillon écrit, envoi à Will)**.

## 6 bis. Références périmées à ne pas suivre

- 🔴 **`_REPORTS/DPIA_2026-05-17.md` est OBSOLÈTE** — il porte lui-même le bandeau « DOCUMENT OBSOLÈTE
  — NE PAS UTILISER » depuis le 2026-08-18. **La référence en vigueur est `_REPORTS/AIPD_2026-08-18.md`**
  (version 2.0). Le mandat d'audit renvoie encore à l'ancien : ne le suis pas.
- `_REPORTS/PROGRESS.md` (lié depuis `README.md`) annonce S3→S12 « pending » alors que les 12 sprints
  sont livrés : document figé au 2026-05-16.
- `TODO.md` se déclare « source de vérité » et décrit un dépôt **d'avant la première ligne de code**.
- `_AUDIT/DEPLOY-PIPELINE.md` décrit une commande de déploiement qui n'est pas celle qui tourne et
  **omet `--no-deps`** — c'est ce qui a laissé la faille du 19/08 ouverte sous un déploiement vert.
- `_REPORTS/RUNBOOK-CONSOLE-LOCALE.md` fait démarrer la pile depuis le worktree résiduel
  `crmpro-wt-etape0` ; le fichier est versionné à la racine.

## 7. Pièges de ces dépôts — ils t'attendent

1. **Windows / CRLF** : un test statique qui cherche un `\n` littéral est aveugle ici. Et un `.sh` de la copie de travail est **inexécutable** tel quel sur Linux.
2. `git stash` est global au dépôt.
3. Plusieurs worktrees en parallèle : relis `HEAD` juste avant d'écrire.
4. Relire `gh pr list` juste avant d'ouvrir une PR.
5. **La CI évalue le commit de fusion**, pas la branche.
6. **Jamais de montée de dépendances en lot.** Une par une.
7. **Une gate qui meurt à l'installation n'a exécuté aucun test** : un vert peut être un silence.
8. `docker compose restart` ne relit pas `env_file` — `up -d`.
9. Playwright + serveur de dev : `localhost`, jamais `127.0.0.1`.
10. 🔴 **PIÈGE 10 — CORRIGÉ : le mandat se trompe de cause.** Il énonce « CI en `en_US.utf8`, prod en `C` ». **C'est faux.** `ci.yml:363` initialise Postgres avec `--lc-collate=C --lc-ctype=C`, et le commentaire de la l.351 dit que c'est **exprès**, pour coller au compose ; la production mesure `datcollate|datctype = C|C`. **La CI est alignée sur la production** (vérifié par le chef de chantier, des deux côtés).
    **Le piège reste réel, mais sa cause est ailleurs** : sous `lc_ctype=C`, le `lower()` **de PostgreSQL** ne replie pas les accents, alors que `mb_strtolower` **de PHP** le fait. La divergence n'est donc pas CI ↔ production, elle est **SQL ↔ PHP, partout et tout le temps**. Mesuré par l'agent 18 (C18-006) ; l'agent 13 (B13-003) avait retenu l'ancienne formulation — **c'est C18-006 qui fait foi**.
    **Conséquence pratique** : un correctif qui « alignerait les locales » ne réglerait **rien**.
11. `git log -S` ignore les commits de fusion.
12. Un test qui pré-insère ce qu'il doit produire ne teste rien.
13. Ne pas répéter les constats du §6 ci-dessus.
14. Une valeur commerciale « inexistante » peut vivre hors du dépôt.
15. Une constante dupliquée ne signale jamais qu'elle a divergé.
16. `gh pr merge --auto` fusionne **immédiatement** si les conditions sont réunies.
17. Les montants français utilisent l'espace fine insécable **U+202F**.
18. 🔴 **`deploy-direct-ssh.yml` ne recrée que `api app horizon scheduler`, avec `--no-deps`.** Toute modification de `docker-compose*.yml` portant sur **`postgres`, `redis` ou `reverb`** est **inapplicable par le déploiement**. Devant un constat sur ces trois services : **compare le conteneur (`docker inspect`), jamais le fichier seul.** **Cherche d'autres occurrences de ce patron.**
19. 🔴 **Une garde peut être irréprochable et mesurer le mauvais objet.** Passe chaque garde à ce crible.
20. **Aucun hook Git** : la CI est le seul filet.
21. `docker exec` n'a **pas** d'option `-T` (c'est `docker compose exec`). Une commande qui échoue avec `unknown shorthand flag: 'T'` **n'a rien mesuré**.
22. **Un seeder appelé par une migration ne doit jamais faire d'`upsert`** sur un référentiel éditable depuis la console : il écraserait les personnalisations à chaque déploiement. Vérifie **chaque** seeder appelé depuis une migration.
23. 🔴 **`rg` / `grep -r` RÉCURSIF saute silencieusement les `.env`** — ils sont dans `.gitignore`, et ripgrep le respecte par défaut. **Un contrôle récursif d'une clef d'environnement rend donc le même « absent » que la clef y soit ou non.** Vérifié : `rg -l "VITE_SENTRY_DSN" .` liste `.env.example`, `sentry.php`, `docker-compose.yml`… **et pas `.env` ni `backend/.env`, qui la contiennent pourtant**. En revanche `rg` **sur un fichier nommé** le lit très bien.
    **Méthode** : nomme chaque fichier explicitement, **ou** passe `--no-ignore`, **et joins un témoin positif** (une clef dont tu sais qu'elle est là). Trouvé par l'agent 18, **contre sa propre mesure** — sa preuve d'origine était muette, et il l'a refaite.
24. **`Env::getRepository()->set()` n'écrase pas un dépôt d'environnement immuable.** Une matrice de mesure bâtie dessus rend **huit fois la même valeur, de façon cohérente et fausse**. Utilise de vraies variables d'environnement. Même agent, même reprise.
    *Le point commun des pièges 23 et 24 mérite d'être retenu : dans les deux cas la première mesure n'était pas approximative, elle était **muette et plausible**. C'est exactement la forme d'erreur que le témoin négatif existe pour attraper.*

## 8. Ce que tu rends — format obligatoire

Écris ton rapport dans le fichier qu'on te donne. Il contient, dans cet ordre :

1. **Ton tableau de grille complet** — une ligne par objet de ton périmètre, une colonne par point
   de grille. Une case « non vérifié — raison » est **acceptable et honnête** ; une case **absente**
   ne l'est pas.
2. **Tes constats**, chacun au format suivant, sans exception :

```
### [X-NNN] Titre en une ligne, factuel
- Sévérité      : S0 bloquant | S1 grave | S2 défaut | S3 finition
- Domaine       : backend / interface / navigation / canal / sécurité / performance / tests / conformité / UX
- Référence     : main c0c453d
- Emplacement   : chemin/fichier.php:123
- Constat       : ce qui est, en une phrase, sans jugement
- Preuve        : la commande jouée + sa sortie (fichier dans 04_PREUVES/<ton-prefixe>/)
- Témoin négatif: la preuve que le contrôle aurait vu le problème s'il existait
- Impact        : ce qui casse, pour qui, dans quel cas
- Reproduction  : les gestes exacts
- Correctif     : ce qui est proposé, et son coût
- Statut        : ouvert
```

3. **La liste de ce que tu n'as PAS pu vérifier, et pourquoi.** Cette liste est un livrable,
   pas un aveu. Un audit qui prétend tout avoir vu est un audit qu'on ne peut pas croire.

**Sévérités.** **S0** : perte de données, faille, non-conformité RGPD, indisponibilité, blocage du
chantier cible. **S1** : fonctionnalité qui ment ou ne marche pas dans un cas courant. **S2** :
défaut réel sans contournement coûteux. **S3** : finition. ⚠️ **Une confusion de navigation qui
fait perdre l'utilisateur est au minimum S2.**

**Archive tes sorties brutes** dans `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/<ton-prefixe>/`.
Interdiction de conclure sans commande jouée.

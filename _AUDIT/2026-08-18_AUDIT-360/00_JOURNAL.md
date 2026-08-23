# 00 — JOURNAL DE L'AUDIT 360°

> Append-only, horodaté (UTC). **Seule source de vérité de l'avancement de l'audit.**
> Prompt joué : `_PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.md` **v2.1 (révisée le 2026-08-19)**,
> empreinte MD5 `44c6f70053057cabefec69d50b00d6e6`.

---

## 2026-08-19T09:20Z — P0.0 Ouverture

Session ouverte sur `C:\Users\willi\Documents\Projets\Axion-CRM-Pro`.
Trois préconditions posées par le dirigeant avant tout agent :

1. **§3 bis et §3 ter lus en premier.** Fait.
2. **Aucun SHA du document n'est cru.** Relevé indépendamment ci-dessous.
3. **`_SESSIONS/2026-08-19_CRM-ETAPE-1A.md` lu intégralement (1 397 l.)** avant tout
   audit touchant contacts, compteurs, préproduction, gardes, activités et motifs. Fait.

**Contrainte de périmètre acceptée** : le worktree `C:\Users\willi\Documents\Projets\crmpro-wt-etape1a`
et sa branche ne sont ni lus en écriture, ni modifiés, ni supprimés.

---

## 2026-08-19T09:25Z — P0.1 Référence réelle, mesurée (règle 6)

```
$ git -C Axion-CRM-Pro log --oneline -5
c0c453d docs(cnil): les deux decisions du dirigeant (#186)
01004a5 docs(cnil): les deux decisions du dirigeant, prises le 2026-08-19
02b46c2 docs(cnil): brouillon complet (#185)
eb74ac4 docs(cnil): le brouillon est complet
377b902 feat(preprod): jeu de reference portable + preproduction remplie (#184)

$ gh pr list --state open
(vide — 0 PR ouverte)

$ git worktree list
Axion-CRM-Pro      c0c453d [main]
crmpro-wt-etape0   702253c [chore/etape-0-prealables]   <- résiduel, étape 0 close
crmpro-wt-etape1a  c0c453d [travail]                    <- INTERDIT (construction)
```

**Référence de l'audit : `main = c0c453d`, `origin/main = c0c453d`, 0 PR ouverte.**

⚠️ **Écarts mesurés avec le document lui-même, y compris sa révision 2.1 :**

| Le document v2.1 dit | Mesuré le 19/08 à 09:25Z |
|---|---|
| `main` = `e577828` puis `65e39a6` (§3) | `main` = **`c0c453d`** — 8 commits plus loin, PR #179 → #186 fusionnées depuis |
| Journal §9.1 : `main = d4910c8` | **périmé** — d4910c8 est 5 PR en arrière |
| worktree `crmpro-wt-etape0` « n'est plus actif » | il **existe encore** (`702253c`), non supprimé |
| worktree étape 1a sur `feat/etape-1a` | il est sur la branche **`travail`**, à `c0c453d` |

*Conclusion de méthode : la règle 6 avait raison contre le document trois fois de suite.
Aucun SHA de ce prompt n'est réutilisé nulle part dans l'audit.*


---

## 2026-08-19T09:30Z — P0.2 Lecture du contexte imposé

- `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md` (1 397 l.) lu **intégralement** avant tout audit
  touchant contacts, compteurs, préproduction, gardes, activités et motifs. Retenu comme
  source de vérité de l'avancement — et daté : son §9.1 annonce `main = d4910c8`, périmé de 5 PR.
- Cahier des charges v2.7 (983 l.) : §A, §A.1, §0, §23.3, §23.4, §27, §29 lus par le chef de chantier.
  ⚠️ **Le §A.1 compte 15 fragilités (F1 → F15), pas 19.** Le prompt d'audit parle de « F1 → F19 » :
  l'agent 7 recompte et publie l'écart.
- Prompt d'audit v2.1 lu en entier. Empreinte MD5 identique à sa prétendue « sauvegarde v2.0 » :
  **la v2.0 n'existe plus**, aucune comparaison n'est possible.

## 2026-08-19T09:32Z — P0.3 Terrain rendu praticable

Pile locale relancée **depuis le dépôt principal** (`docker compose -f docker-compose.yml
-f docker-compose.local.yml up -d`). Les conteneurs tournaient jusque-là avec un fichier
de surcouche pris dans le worktree `crmpro-wt-etape0` — référence périmée, corrigée.

État : `app.localhost` 200 · `api.localhost/up` 200 · 8 conteneurs sains.
**Mais toute route authentifiée répond 500** → constat **A-001**.

Constats tombés pendant l'amorçage, comme le §8 P0.3 le prévoyait :
**A-001** (500 au lieu de 401, **en production**), **A-002** (`/saved-views` ment),
**A-003** (CRLF encore armé dans la copie de travail), **A-004** (le Caddy local
demande les certificats des domaines de production).

## 2026-08-19T09:35Z — P0.4/P0.5 Inventaire publié, agents lancés

`01_INVENTAIRE.md` publié. Écarts majeurs avec le §4 du prompt : **35 tâches planifiées
et non 10**, **58 migrations et non 54**, **3 stubs Phase 2 et non 5**, **37 écrans et non 39**,
**18 modèles et non 21**, **84 services et non 68**, **+1 contrôleur interne** non listé.

Contre-vérifications de la règle 7 sur le travail produit **le jour même** :
- « six tables mortes » → **six confirmées**, mais `saved_views` **réfutée** (elle a un contrôleur
  et une route enregistrée) ;
- « `reportUnmatchedIgnoredErrors: false`, baseline 2 045 l. » → **réfuté** : `true`, 1 321 l.,
  et `phpstan analyse` niveau 8 rend `[OK] No errors` ;
- « aucun hook Git » → **confirmé**.

**P1 vague (a) lancée** : agents 5, 6, 7, 8, 9 (bloc A), 10, 11, 12, 13, 14, 15, 16, 17 (bloc B),
44, 45, 46, 47 (bloc H), puis 18 (bloc C). Plafond de 20 agents simultanés atteint ;
les blocs C (reste), D, E, F, G, I partent au fur et à mesure des retours.

## 2026-08-19T12:20Z — P1 en cours · premiers retours · deux corrections de ma propre mesure

**Agents rendus : 5 (plan 13/08), 9 (écarts documents), 16 (audit/AI Act), 17 (automatismes),
47 (dépendances).** Détail dans `02_CONSTATS.md` §P1.

**Récolte la plus grave à ce stade : quatre S0, tous de l'agent 16**, sur la chaîne d'audit —
hachée **sans secret**, **tronquable par la queue** sans être détectée, **horodatage non haché**
(2026 → 2019 passe « OK »), et `GET /audit-logs` qui rend le journal de **tous les espaces** à
**tout compte authentifié**. Chacun mesuré avec témoin positif **et** négatif joués dans le même
amorçage.

**Deux corrections de mes propres constats — règle 7 appliquée contre moi-même :**

1. **A-003 (CRLF).** L'agent 9 a montré que `grep -c $'\r'` n'est pas fiable selon les shells de ce
   poste : il avait « prouvé » 15/15 à tort. J'ai re-mesuré par comptage des **octets `0x0d`**,
   **après avoir validé la méthode sur un témoin pur LF (0) et un témoin pur CRLF (2)**. Résultat
   réel : **8 fichiers `.sh` sur 16**, et les deux que je citais en exemple ont été corrigés depuis.
   Le constat tient, l'exemple était faux. Corrigé dans le registre, avec la mention de l'écart.
2. **« migrate:status dit Pending, migrate dit Nothing to migrate ».** J'allais en faire un constat.
   Ce n'en était pas un : l'agent 10 jouait `migrate:fresh` sur `axion_crm` au même instant, comme
   le §8 P0.3 le demande. **Artefact de concurrence, pas défaut du produit.** Non retenu.

**Deux faits d'environnement qui contraignent la suite :**

- **A-008** — une **autre session** a fusionné les PR #187, #188, #189 sur `main` **pendant** l'audit
  (11:26 → 12:07). Le §3 ter est donc violé, mais **par l'autre bord** : l'audit n'a touché à rien.
  `git diff --stat c0c453d origin/main` → **2 documents et 1 script neuf, aucun code produit**.
  Référence portée à **`e8924b8`, code identique à `c0c453d`**. Le script neuf
  `infra/scripts/definir-mot-de-passe-crm.sh` **entre au périmètre** (confié à l'agent 35).
- **A-009** — l'atelier local sert l'API par **`php -S`, mono-processus**. Vingt agents ne peuvent pas
  le partager : `/up` est passé de 200 en 2,7 s à une expiration à 60 s. Conséquence de méthode :
  les travaux qui passent par HTTP sont **sérialisés**. Conséquence produit : le **critère 17 du §29**
  (dix sessions simultanées) est **inmesurable sur cet atelier** — toute mesure de concurrence faite
  ici serait fausse, et une mesure fausse présentée comme vraie est exactement ce que la passe
  adversariale doit trouver. Confié à l'agent 40 de vérifier ce qu'il en est **en production**.

**Trois hypothèses du mandat réfutées par la mesure, et c'est heureux :**

- les 20 PR Dependabot n'ont **pas** été « fermées sans traitement » : fermées **par Dependabot
  lui-même** après un gel sous politique écrite de 441 lignes, aucune n'étant corrective de sécurité ;
- les **57 alertes** sont exactes au chiffre près, mais **aucune n'est atteignable en production** —
  32 viennent de `workers/`, qui n'est déployé nulle part ;
- le **piège Stripe** du §12-9 ne concerne pas ce dépôt : il n'y a aucun SDK Stripe dans le CRM.

**Vague (b) lancée** : agents 22 (écrans), 23 (navigation), 35 (authentification), 36 (permissions),
40 (infrastructure). Restent à lancer : 24, 25, 26, 27, 28, 29, 30 (bloc D), 31→34 (bloc E),
37, 38, 39 (bloc F), 41, 42, 43 (bloc G), 48, 49, 50 (bloc I).

## 2026-08-19T10:50Z — A-010 : le constat d'infrastructure le plus lourd de l'audit

En cherchant à déporter le bloc D sur la préproduction (l'atelier local étant saturé, A-009), j'ai
mesuré le serveur HTTP de la **production**. C'est `php -S`, **un seul processus**.

Trois mesures, en lecture seule, dans cet ordre :
1. `docker inspect` → `Config.Cmd = ["php","-S","0.0.0.0:80","-t","public"]` ; `PHP_CLI_SERVER_WORKERS`
   **non posé** ; **1 seul** processus `php -S`, PID 1.
2. **12 requêtes simultanées** sur `/up` → escalier **parfait**, pas ≈ **15 ms** : 0,025 · 0,041 ·
   0,055 · 0,072 · 0,086 · 0,091 · 0,103 · 0,121 · 0,140 · 0,156 · 0,177 · 0,192 s.
3. **Témoin positif** — les **mêmes 12 en séquentiel** : **plates à 0,0145 → 0,0184 s**. Le serveur
   traite donc bien une requête en 15 ms : l'escalier n'est pas un ralentissement, c'est une **file**.

**Témoin manqué, consigné comme tel** : j'ai voulu déposer un point lent (`sleep 3`) pour rendre la
démonstration spectaculaire. **Refusé, `Permission denied` — le système de fichiers du conteneur de
production est en lecture seule.** Bonne nouvelle de sécurité, et ce témoin-là n'a pas été joué.

**Pourquoi personne ne l'avait vu** : avec **un seul utilisateur** (1 compte, 0 session, 0 jeton),
la sérialisation est rigoureusement invisible. Tous les contrôles verts du produit ont été joués à un
utilisateur. C'est le piège 19 dans sa forme la plus pure — la garde mesure le bon objet, mais dans
des conditions où le défaut **ne peut pas** se manifester.

Portée : le **critère 17 du §29** n'est pas « non mesuré », il est **inatteignable par construction** ;
et le **principe directeur 8** du CDC (« dix utilisateurs dès le premier jour ») est structurellement
violé. Le §0 précise que les principes « priment sur toute fonctionnalité prise isolément ».
→ **A-010, S0, premier lot de P3.**

Effet de bord utile : le correctif de la pièce 1 du 19/08 (index couvrant + `Cache::flexible` sur les
compteurs du hub, mesurés à **17,5 s cache froid** sur 2,8 M) ne réglait pas seulement une lenteur —
il retirait un **point de blocage global de l'application**. Personne ne le savait, y compris son auteur.

## 2026-08-19T11:00Z — Deux corrections de mes propres constats, levées par les agents

**1. A-001 abaissé de S1 à S2.** L'agent 13 obtenait un **401 propre** là où j'avais mesuré un 500, et
a refusé de crier à la réfutation : « écart de protocole, pas réfutation ». C'est cette prudence qui a
fait trouver la vraie variable — ni la route, ni Caddy : **l'en-tête `Accept`**.

```
PRODUCTION, sans Accept :            PRODUCTION, avec Accept: application/json :
  /api/v1/crm/arbitrage   -> 500       /api/v1/crm/arbitrage   -> 401
  /api/v1/config/features -> 500       /api/v1/config/features -> 401
  /api/v1/contacts        -> 500       /api/v1/contacts        -> 401
```

Et `frontend/src/lib/api.ts:5-8` **pose cet en-tête**, `:30-31` redirige vers `/login` sur 401.
**La console n'est donc pas touchée.** J'avais écrit qu'« un visiteur déconnecté reçoit une erreur au
lieu de l'écran de connexion » : **c'était faux**. J'avais mesuré au `curl` nu et généralisé à tous les
clients. **Un `curl` n'est pas un navigateur.** La clause de reclassement en S0 de la décision D-004
tombe d'elle-même ; D-004 est révisée.

**2. L'hypothèse « une opposition RGPD part en 422 » est morte, et c'était ma faute.** Je l'avais
étayée sur une ligne du `laravel.log` **local** : `consentement v2 requis … reçu : careers-v1-2026-06-09`.
L'agent 13 a établi qu'elle vient de la **suite de tests** — qui s'exécute dans le même conteneur et
écrit dans le même fichier. **J'ai pris la preuve qu'une garde fonctionne pour la preuve qu'un défaut
existe.** Sa mesure : **zéro événement légitime rejeté**. Hypothèse close.
*(L'angle mort côté site — la version dynamique des 71 candidatures du stock — reste ouvert et
appartient à l'agent 31.)*

**Ce que ces deux corrections disent de la méthode** : la rotation de vérification a fonctionné **dès
P1**, sans attendre P4, et elle a corrigé le chef de chantier deux fois. C'est exactement ce que la
règle 7 vise. Les deux constats corrigés portent désormais la trace de l'écart, pas seulement la
version corrigée — un registre qui efface ses erreurs ne permet pas de juger sa propre fiabilité.

## 2026-08-19T11:05Z — B13-001 corrige et durcit A05-003

L'agent 5 attribuait l'échec du canal à un SIREN « rarement rempli ». L'agent 13 a mesuré :
**aucun émetteur du site ne transmet de SIREN, et aucun formulaire n'en collecte.** Six leads calqués
sur le contrat réel → **6 `pending_match`, 0 entreprise, 0 personne**. Ce n'est pas « rarement »,
c'est **100 %**. Les 3 événements de production tombés en arbitrage manuel n'étaient pas de la
malchance : **c'est le fonctionnement nominal**. C'est la **cause racine du critère 18 du §29**.

## 2026-08-19T11:10Z — A-012 : le produit n'a jamais été utilisable par personne

En vérifiant le script `infra/scripts/definir-mot-de-passe-crm.sh` — arrivé sur `main` **pendant**
l'audit, par la session parallèle — j'ai trouvé que celle-ci avait diagnostiqué **exactement** la même
chaîne causale que j'étais en train d'assembler à partir de F40-002 et de la mesure « 1 utilisateur,
0 session, 0 jeton ». Trois défauts qui se referment l'un sur l'autre :

1. `OWNER_INITIAL_PASSWORD` était **vide** → le seeder a généré un mot de passe et l'a annoncé **une
   seule fois**, à la console de déploiement ;
2. **`MAIL_MAILER` n'est défini nulle part** → `config/mail.php:4` retombe sur `'log'` → **aucun
   courriel ne part**, alors que **sept clés ZeptoMail sont posées et valides en production** ;
3. les **deux** voies de secours — lien magique et réinitialisation — **passent par le courriel**.

**Le propriétaire ne peut pas entrer dans son propre CRM depuis le 2026-05-17.** → **A-012, S0**.

**Ce que ce constat explique, et c'est sa vraie valeur** : *on ne découvre pas un problème de dix
utilisateurs quand on n'en a jamais eu un seul.* A-010 (sérialisation), les 37 écrans jamais ouverts
en production, les contrôles verts joués à un utilisateur — tout tient à cela.

⚠️ **Et un détail de ce diagnostic ne survit pas à ma mesure.** Le script affirme que le mot de passe
généré « a été écrit dans `storage/logs`, un fichier qui pèse aujourd'hui 263 Mo ». **Je ne l'y trouve
pas** : zéro occurrence de toute ligne annonçant un mot de passe dans le `laravel.log` de production.
La raison est dans le code — `OwnerUserSeeder.php:164` emploie **`$this->command?->warn(...)`**, qui
écrit sur la **sortie console d'Artisan**, pas dans `laravel.log`. Le mot de passe est donc dans les
journaux de déploiement de mai 2026, **pas** dans un fichier de 270 Mo lisible par tout ce qui accède
au conteneur. *Cela ne change rien au verrouillage ; cela change l'évaluation de l'exposition.*

**Ce n'est pas une décision fautive du dirigeant.** `MAIL_MAILER = log` **est** sa décision explicite,
et elle n'est pas rouverte (D-005). Le défaut est que personne n'avait vu qu'une décision prise pour
les envois **transactionnels métier** coupait aussi les courriels **d'authentification**. Une
conséquence non nommée n'est pas une faute — la nommer est le travail d'un audit.

## 2026-08-19T11:15Z — Troisième correction de mes propres mesures

**A-007 : j'avais tort, et j'avais accusé le journal de construction à tort.** J'avais écrit « le
journal dit 6 erreurs/minute, mesuré 56 — 9× plus ». **Faux.** Je comptais les **occurrences de la
chaîne** `telescope_entries`, or elle apparaît **7 fois par entrée** (message, requête SQL, trace).
Re-mesure propre sur 120 s en comptant les **entrées horodatées** : **11 entrées, soit 5,5/minute** ;
l'agent 40, sur 484 minutes, trouve **5,8**. **Le « 6 par minute » du journal était juste.**
Débit corrigé : **~90 Mo/jour**, pas 133. *Compter des occurrences de chaîne n'est pas compter des
événements — et le facteur d'erreur était exactement le nombre de fois où le défaut se cite lui-même.*

**A-004 abaissé de S2 à S3** (agent 40) : ce qui est consommé est la limite horaire de **validations
échouées**, pas le quota d'**émission**. Le renouvellement de la production n'est pas menacé (fenêtre
ARI au 13/09). J'avais extrapolé au lieu de mesurer.

**B16-001 réfuté pour la production** (agent 40) : `AUDIT_HASH_CHAIN_SECRET` fait **64 caractères** en
production, mesuré deux fois sans jamais afficher la valeur. Il reste vide **en local** — ce qui veut
dire que **les gardes de la chaîne d'audit tournent sur une configuration qui n'est pas celle de la
production**. Troisième occurrence du même motif, après B11-010 (cloisonnement) et A05-008 (fuseau).
**Les trois autres S0 de l'agent 16 survivent** : ils ne dépendent d'aucun secret.

*Bilan de méthode à mi-P1 : la rotation de vérification a corrigé le chef de chantier **quatre fois**
(A-001, A-004, A-007, et l'hypothèse RGPD morte). C'est le fonctionnement attendu, pas un incident —
mais cela dit aussi que mes propres constats doivent passer la passe adversariale comme les autres.*

## 2026-08-19T13:20Z — A-013 : le constat le plus important de l'audit, et il n'est pas technique

L'agent 6 a mesuré que **l'exemple fondateur du mandat est mal caractérisé**. Le §3 bis et le piège 19
présentent `_REPORTS/2026-08-18_ETAT-PARE-FEU.md` comme « une garde irréprochable qui mesure le mauvais
objet : elle concluait que le pare-feu était en ordre ». **J'ai relu le document ligne à ligne. Ce n'est
pas ce qui s'est passé.**

Le rapport porte, **dès sa ligne 11** : « 🔴 **CE DOCUMENT NE CONSTATE RIEN SUR LE SERVEUR** ». Puis,
**la veille de la faille**, il écrit : Postgres `55432` et Redis `56379` sur **`0.0.0.0`** (l.153-154) ·
« un `ufw deny` sur un port publié par Docker **ne bloque rien** » (l.188) · `POSTGRES_PASSWORD=axion_dev_only`,
rôle **SUPERUSER + BYPASSRLS** (l.212, 247) · le correctif `- "127.0.0.1:55432:5432"` (l.198) · la
commande de vérification et sa sortie saine attendue (l.335-336) · et jusqu'à la notification CNIL.
Il se clôt sur : « **tant que ce n'est pas fait, F12 n'est PAS soldé** ».

**Et la ligne 14 a été cochée ✅ par-dessus.**

Le lendemain, une base de **4 295 349 fiches — 1 319 567 personnes** — était joignable en
superutilisateur depuis internet, et on l'a découverte **par hasard**, en préparant autre chose.

**Le coût n'a pas été la difficulté technique : le diagnostic était déjà écrit.** Le coût a été qu'un
tableau de synthèse a transformé « je n'ai pas pu mesurer, et voici précisément ce qui va casser » en
un ✅.

🔑 **Et le motif se généralise.** L'agent 6 a montré que **les artefacts de ce dépôt sont d'une
honnêteté inhabituelle**, et que **ce sont les couches de résumé qui mentent** : le README du harnais
écrit « 31 écrans sur 37 restent » · `vitest.config.ts` documente ses propres seuils comme
« **DÉCORATIFS** » · `EtancheiteWorkspace` **avoue** la cécité de son scan · `deploy-staging.yml` écrit
lui-même « Coolify RETIRÉ ». **Dans chaque cas, l'information exacte existait, écrite, au bon endroit.**

*Ce dépôt n'a pas un problème de mesure. Il a un problème de clôture.*

**Conséquences prises immédiatement :**
- **A-013** ouvert (S1), avec le document cité ligne à ligne.
- **A-011 corrigé** : le rapport pare-feu **retiré** de sa liste — ce n'est pas une garde qui mesure
  mal, c'est un patron **distinct et plus grave**, qu'aucune garde technique n'attrape. A-011 garde
  ses **huit** autres cas (deux ajoutés depuis : l'instrument de parité, et la garde e2e d'un
  `ErrorBoundary` **jamais monté**).
- **Clôture de l'étape 0 rejouée** : **7 CLOS · 7 PARTIELS · 2 OUVERTS**, là où le journal annonce
  « 15 sur 16 ». *L'écart n'est pas un écart de travail — le travail a été fait — c'est un écart de
  clôture.*
- **Règle appliquée à cet audit lui-même, sans exception** : aucun constat ne sera déclaré corrigé en
  P3 sans une mesure jouée en P4 par un **autre** agent.

## 2026-08-19T13:26Z — Atelier remis d'aplomb (D-011)

Image `axion-crm-app` reconstruite et conteneur recréé. Vérifié sur le bundle réellement servi :

```
avant  /assets/index-DPQz8SpC.js   "Journaux de collecte" : 0   "Runs de scraping" : 2
apres  /assets/index-BVK1vh1a.js   "Journaux de collecte" : 2   "Runs de scraping" : 0
PROD   /assets/index-D3nU2tuG.js   "Journaux de collecte" : 2   "Runs de scraping" : 0
```

**L'atelier sert enfin la même interface que la production.** L'agent 22 a été prévenu que ses mesures
antérieures sont à rejouer.

**Piège 10 du mandat corrigé** : il annonce « CI en `en_US.utf8`, prod en `C` ». **Faux** —
`ci.yml:363` initialise Postgres en `--lc-collate=C --lc-ctype=C` **exprès** (commentaire l.351), et
la production mesure `C|C`. **La CI est alignée.** Le piège est réel mais sa cause est ailleurs : sous
`lc_ctype=C`, le `lower()` **de PostgreSQL** ne replie pas les accents là où `mb_strtolower` **de PHP**
le fait. La divergence est **SQL ↔ PHP**, pas CI ↔ prod — donc « aligner les locales » ne réglerait rien.

## 2026-08-19T14:30Z — Le critique de complétude a fait son travail contre l'audit

L'agent 50 a audité **l'audit**, instantané daté (12:06Z), et il a trouvé quatre choses contre moi.
**Les quatre sont fondées, les quatre sont corrigées :**

1. 🔴 **J'ai reproduit `A-013` dans le document qui le dénonce.** Le §5 de `02bis` affirmait « la CI
   backend, **bloquante et requise** » — ce qui contredit `F38-002`, `A08-005` et `H44-003` du même
   dossier. **C'est la SUITE qui est saine, pas son câblage.** Corrigé, avec la mention de l'écart.
2. **J'avais laissé tomber une réserve d'agent** : `B12-001`/`B12-003` sont mesurés là où
   `CRM_DB_APP_ROLE_ENABLED=false` alors que la production est à `true`. L'agent 12 l'avait écrit ; ma
   consolidation avait gardé le constat et perdu la réserve. **Rétablie.**
3. **`B12-004` était périmé** — `F37-001` l'a mesuré sur le serveur et l'aggrave. **Corrigé.**
4. 🔴 **Une garde vue ROUGE dormait dans les preuves, publiée nulle part** : un `viewer` obtient
   **200 au lieu de 403** sur l'export des **4 295 349 fiches nominatives**. **C'était la seule garde
   de permission vue rouge de tout l'audit**, et son correctif était déjà écrit.
   **Publiée en `F36-001`, S0.** *Sans l'agent 50, elle était perdue — `A-013` appliqué à l'audit.*

**Une chose de sa critique est corrigée par la mesure** : il classe l'agent 45 « squelette, 0 constat ».
C'était vrai **à 12:06Z**, faux depuis **14:10Z** — le rapport porte **dix constats**. Il avait daté son
instantané : c'est le décalage qui parle, pas l'erreur.

## 2026-08-19T14:35Z — Trois mesures de clôture, dont deux qui referment des points ouverts

**1. La RLS en production (`A-014`).** J'ai attendu **48 minutes** la fin de la restauration plutôt que
de conclure sur une base en cours d'index — la mesure prématurée aurait donné « 0 policy ».
Résultat : la base restaurée rend **39 policies / 38 FORCE RLS**, **exactement comme la production**.
→ ✅ **les policies survivent à une restauration** (point ouvert de l'agent 8, **clos dans le bon
sens** ; `A08-008` porte sur les **droits**, pas sur les policies) ; ✅ **l'écart 55 ↔ 38 n'est pas un
trou** (l'agent 11 mesurait une base bâtie par migrations) ; 🔴 **mais sur 41 tables à `workspace_id`,
3 n'ont pas de FORCE RLS, et deux sont des exclusions motivées. Il reste `audit_logs`.**
**Cinq agents, cinq chemins, un seul trou — désormais mesuré en production.** Et son périmètre est
**borné et petit** : une table et ses 14 partitions.

**2. Les deux bascules d'heure, jouées pour la première fois dans ce dépôt** (agent 29). Sur le chemin
réel, table réelle : **sans `DB_TIMEZONE`, 6/6 décalés** (+7 200 s l'été, +3 600 s l'hiver) ;
**avec — c'est-à-dire en production — 6/6 à 0 s**, y compris l'heure **inexistante** du 29/03 et
l'heure **ambiguë** du 25/10. *Le volet stockage du critère 16 est **TENU**. Son volet affichage ne
l'est pas.*

**3. Une hypothèse sérieuse, tuée par la mesure.** L'agent 46 a montré que le lien magique et le jeton
de réinitialisation sont **écrits dans le journal** quand `MOCK_MODE`/`MAIL_MAILER=log` — or ce journal
fait **1 Go** et il est en **`-rwxrwxrwx`**. **Si des liens de connexion y étaient, quiconque lit ce
fichier pourrait se connecter en tant que propriétaire.** Vérifié en production, sans afficher aucun
contenu : **0 `Message-ID:`, 0 `To:`, 0 `magic-link/verify`**. **Témoin positif** sur le même fichier :
`telescope_entries` **172 131**, `axion_crm_session` **264**. *Les zéros sont des zéros.*
**Aucun courriel n'a jamais été rendu dans ce journal** — cohérent avec `A-012`. **Le risque est armé,
il n'est pas réalisé** : consigné comme non-constat, pas comme découverte.

*Sixième fois que je vérifie avant d'écrire et que la vérification tue l'hypothèse. C'est le bon ratio.*

## 2026-08-19T15:40Z — P1 close : 43 rapports, 883 preuves, et la rotation a fonctionné

**Tous les agents lancés ont rendu.** 43 rapports dans `11_GRILLES/`, **883 fichiers de preuves**.

**Ce que la rotation a produit, et c'est le meilleur indicateur de l'audit** — des agents se sont
corrigés les uns les autres, **dans les deux sens** :

- l'agent **28** corrige l'agent **27** : les 23 écrans qui recopient du balisage **ne perdent pas**
  l'anneau de focus (0 élément sans indicateur sur 37 écrans) → **S1 ramené à S3** ; et les deux
  badges sans mode sombre sont **parfaitement lisibles** (6,4 à 8,2:1) → défaut de **cohérence**, pas
  de contraste. Il **corrobore** en revanche `D27-002` **par une autre méthode**.
- l'agent **28** nuance l'agent **44** : `a11y.yml` **tourne et passe** (25 exécutions) — elle ne
  « mesure pas rien ».
- l'agent **13** avait corrigé le chef de chantier sur `A-001` ; l'agent **35** en a trouvé **la
  seconde cause** ; l'agent **45** l'a mesuré **vivant en production** pendant que la suite le
  certifie absent.
- l'agent **26** m'a montré que **ma consigne d'isolation n'était pas exécutable** (`D-017`).
- l'agent **50** m'a pris à **reproduire `A-013` dans le document qui le dénonce**.

**Sept fois que je suis corrigé, trois de mes décisions révisées.** C'est le fonctionnement attendu,
et c'est la seule raison pour laquelle on peut accorder du crédit aux constats **non** corrigés.

**Le fait de méthode le plus instructif de la journée**, et il vient de l'agent 30 : `resize_window` a
**menti** — « Successfully resized to 375x812 », et `innerWidth` valait **toujours 1920**. Il l'a vu,
a mesuré avec **deux instruments indépendants** qui s'accordent, et a **écarté un faux constat** au
passage. *Sans ce doute, tout son rapport était faux et personne ne l'aurait su.*

Et son corollaire, qui résume l'audit entier : **son compte de débordements est bas pour une mauvaise
raison** — `overflow-x-hidden` fait que **rien ne défile, tout est rogné**. *Un contrôle naïf aurait
conclu « aucun débordement » et se serait trompé trois fois.*

---

## 2026-08-19 — P4 · le décompte S0 tranché par recomptage, et deux erreurs à moi

**Point de départ** : l'agent 35, en clôture, annonce **32** défauts S0 ; mon dossier en annonçait
**26** au `02bis` et **27** au rapport final. J'ai refusé d'absorber son chiffre sans mesurer, et
refusé de défendre le mien. J'ai recompté.

**Relevé brut** : `grep -n 'S0' 02_CONSTATS.md` → **37 lignes portant l'étiquette**, dont `D24-001`
devenu `A-015` (même défaut, deux noms) → **36 constats S0 distincts par l'identifiant**.

**Deux erreurs trouvées dans mon propre tableau `02bis` §1 bis** :

1. La ligne « Isolés » annonçait **6** défauts S0 et incluait **`A08-008`** — vérifié à
   `02_CONSTATS.md:1597` : **il est S1**. *Un S1 comptait parmi les S0.* Une fois retiré, la somme
   du tableau retombe sur **26**, qui était le chiffre du texte : **c'était la ligne qui était
   fausse, pas la somme.**
2. Le tableau **datait d'avant trois rendus** — `G41-001`, `G41-002` et le second chemin de
   `F35-002`. Les deux premiers forment un **groupe entier absent** : `G7 — la base ne tient pas le
   volume`.

**→ 29 défauts S0 distincts, ouverts, vrais pour la production.**

**Et l'écart avec l'agent 35 s'explique sans que personne ait tort** : il comptait des
**identifiants**, je compte des **défauts**. Sept défauts ont été trouvés **deux fois, par deux
agents, sur deux bancs** ; cela fait deux preuves et un seul correctif. J'ai écrit la règle dans
`02bis` : **29 pour ordonner P3, 36 pour juger la solidité du registre.**

**Ce que je retiens contre moi** : c'est la **troisième fois** que je corrige ce chiffre, et les
trois fois **vers le haut**. La cause n'est pas l'arithmétique, c'est qu'un tableau de synthèse
**ne se rouvrait pas** à l'arrivée de nouveaux rendus. C'est exactement `A-013`, le défaut de
clôture que ce dossier dénonce — reproduit une fois de plus dans le document qui le dénonce.
*Mesure prise* : le §1 bis porte désormais sa méthode **avant** son chiffre, pour qu'un tiers puisse
le recompter sans me croire.

**Propagé** : `07_RAPPORT-FINAL.md` (registre des auto-corrections + « vingt-sept » → « vingt-neuf »)
et `06_RESTE-WILL.md`, qui portait encore **« douze »** — le plus périmé des trois, et c'est la page
que Will lit en premier.

**Note d'atelier, sans gravité** : `root.crt` (631 o, certificat **public**, daté du 17/08 — donc
antérieur à l'audit, il n'est pas de moi) traîne **non suivi et non ignoré** à la racine du dépôt.
Pas un secret ; mais un `git add .` distrait le committerait. **Je n'y touche pas** — ce n'est pas
mon fichier. Signalé, c'est tout.

---

## 2026-08-23 — LA VÉRIFICATION DE LA VAGUE 2 EST ALLÉE AU BOUT

**Banc rafraîchi d'abord** (`infra/scripts/rafraichir-le-banc.sh a35r`, 28 chemins). Sans ce
geste, douze gardes rougissent sur un arbre périmé et l'on répare du vide.

### Ce qui a été joué, et ce que ça a rendu

| suite | tests | verdict |
|---|---:|---|
| `Feature/Crm` + `Feature/Database` | 266 | ✅ |
| `Feature/Console` + `Feature/Commands` | 100 | ✅ |
| `Feature/Rgpd` + `Auth` + `Controllers` | 368 | ✅ |
| 8 sous-dossiers restants de `Feature` | 84 | ✅ |
| `Feature` racine (28 fichiers, 2 tranches) | 224 | ✅ |
| `Unit` (48 fichiers) | 342 | ✅ |
| `Feature/Infra` | 261 | ✅ |
| Workers (`vitest`, 9 fichiers) | 94 | ✅ |
| Frontend (`vitest`, 59 fichiers) | 412 | ✅ |

Trois tests portent un `markTestIncomplete` **délibéré** (`C19-010` voie jumelle, 2 dans
`Infra`) : ils disent qu'un constat reste ouvert, ils ne le cachent pas.

### Les six rouges — aucun n'était une régression du produit

1. **Trois gardes de partitionnement** (`MaintenancePartitionsAuditTest`,
   `AuditLogsPartitionnementTest`) inséraient dans `audit_logs` sans `prev_hash`. Le correctif
   `B16-013` de la vague 2 avait **délibérément** retiré le défaut SQL de cette colonne, pour
   qu'un INSERT qui l'omet échoue franchement plutôt que d'hériter en silence d'un maillon zéro.
   Vérifié avant de toucher aux gardes : `AuditHashChain::record()` (l. 140) est le **seul** site
   d'écriture du produit et il fournit toujours `prev_hash`. Commit `d9205b5`.

2. **Le plafond `B10-016`** des lectures aveugles au `deleted_at` passait de 2 à 3 sur `users`.
   Le troisième lecteur est l'essai à blanc d'`AnonymizeOldIps`, ajouté par `B15-011`, et il
   **doit** rester aveugle : le chemin réel est un UPDATE brut qui ne filtre pas non plus, et
   filtrer serait le vrai défaut — l'IP d'un compte en corbeille ne serait alors **jamais**
   anonymisée, ce que la commande existe pour empêcher. Plafond relevé après examen, raison
   écrite à côté du chiffre ; total 87 → 88.

3. 🔴 **Trois gardes lisaient leurs propres commentaires comme du code.** Le même défaut
   d'instrument, trouvé trois fois en une matinée, sur trois fichiers de mains différentes —
   le patron `A-011` de ce dépôt, mais **entre gardes**. Commit `e41a034`.

   - `ContexteEspaceDesJobsTest` (`B11-002`) désignait `MonitorCampaignProgressJob.php:60`, une
     ligne de **commentaire** expliquant que le re-dispatch « construit un `new self(...)` neuf ».
     Elle gonflait en outre `$sitesVus`, son propre témoin de couverture. Elle désignait aussi la
     l. 114 à tort : le chemin d'échec ajouté par `B17-011` pose `->pourEspace($espace)` trois
     instructions plus loin, après être allé chercher l'espace.
   - `SsrfCompletudeTest` (`C19-001`) faisait entrer à l'inventaire des « émetteurs HTTP à
     examiner pour SSRF » le fichier `VerificationTlsSonde.php`, **qui est un trait n'émettant
     rien** : ce qui déclenchait la signature, c'était son commentaire d'en-tête, qui **cite**
     l'appel fautif qu'il répare. Plus grave qu'un faux rouge — l'inventaire y perdait son sens.
     Une fois les commentaires écartés, la garde a signalé d'elle-même que `SsrfGuard.php`
     disparaissait de l'inventaire, et elle avait raison : ses quatre occurrences de signature
     (l. 25, 90, 164, 187) sont des explications. Mesure faite en amputant le fichier de ses
     commentaires : **aucune émission HTTP**, seulement deux `dns_get_record`, qui sont la
     résolution dont ce garde-fou a besoin pour juger un hôte. Il n'est pas un émetteur à
     examiner : il est ce qui examine. L'y garder revenait à faire comparaître le juge.
   - `Phase2SansCheminFantomeTest` (`B12-017`) rougissait **sur le compte rendu de sa propre
     réparation** : l'en-tête de `Phase2\CampaignsController` expliquait quelle annotation
     `@OA\Get(path="/campaigns")` il avait fallu retirer, et la garde lisait l'explication comme
     le défaut décrit. Ici les commentaires ne peuvent pas être écartés — une annotation OpenAPI
     **est** un commentaire — donc le motif exige désormais l'ouverture d'une ligne de bloc de
     documentation, forme que prennent les 40+ annotations réelles du dépôt et qu'une citation en
     cours de phrase ne prend jamais. Et le geste restant de `B12-017` est fait : la classe morte
     `Api/Phase2/CampaignsController` est **supprimée**.

**Témoins rouges joués** avant de conclure, produit cassé exprès puis restauré : `->pourEspace()`
retiré du re-dispatch → `B11-002` rouge, et sur la l. 114 **seule** ; vraie annotation
`@OA\Get(path="/campaigns")` posée sur `ColdEmailController` → `B12-017` rouge.

### Les 91 correctifs sont inscrits au registre

**+87 lignes fermées** dans `FILE-DE-TRAVAIL.md` : 86 sur une garde jouée verte le jour même,
2 sur un correctif documentaire (`E32-001`, `I49-009`). Le décompte passe de 119 à **206 fermées**
sur 485 lignes.

⚠️ **`I49-009` porte une réserve** : le correctif est réel (les deux mises en gras fautives ont
disparu du §22.2, l. 683 et 694), mais le CDC vit dans `C:/Users/willi/Downloads/`, **hors de tout
dépôt**. Ce correctif n'a ni historique, ni revue, et rien ne le rattrapera s'il est perdu.

🔴 **Trois correctifs restent OUVERTS à dessein.** `E31-010`, `E33-002` et `E33-004` sont écrits
mais **non committés** : ils vivent en modification non suivie dans `Axion-IA/axionia`, sur la
branche `docs/plan-console-editoriale` — qui n'est pas la leur — au milieu du travail d'autres
sessions. Patch archivé dans `04_PREUVES/agent-35/site-non-committe/`. *Un correctif qu'aucun
dépôt ne porte n'est pas un correctif : c'est une note qui disparaîtra au premier `git checkout`
de quelqu'un d'autre.* Je n'ai touché à aucune branche de ce dépôt : d'autres sessions y vivent.

### Les quatre verrous de connexion : le rapport final est périmé

Mesure du jour, en lecture seule. Les **quatre** verrous nommés au `07_RAPPORT-FINAL.md:28` —
mot de passe initial, `MAIL_MAILER`, enrôlement 2FA sur trois colonnes inexistantes, écran blanc
dès une ligne d'`audit_logs` — **sont levés dans le code**, chacun avec sa citation
`fichier:ligne`. Le quatrième (`ActivityFeed.tsx:152`) n'est encore que sur la branche non
fusionnée.

Ce qui empêche de se connecter **aujourd'hui** est autre chose, et c'est de l'infrastructure :

| | ce qui bloque | preuve |
|---|---|---|
| **V5** | `axion-crm-api` et `axion-crm-redis` sont **arrêtés** (`Exited 255`) — tout `/api/*` rend 502 | `docker logs axion-crm-caddy` : `dial tcp: lookup api: i/o timeout` |
| **V6** | Le Caddy **vivant** est celui d'avant le passage à FastCGI : il parle `api:80`, la branche attend `api:9000` en fastcgi | API d'admin 2019 : `4 × "dial":"api:80"`, aucun `fastcgi` |
| **V3′** | La migration `2026_08_19_120000_reparer_socle_authentification` **n'est pas appliquée** : `totp_recovery_codes` est `ARRAY` en base, face à un cast `encrypted:array` | dernière migration en base : `2026_08_19_000002` |
| **V7** | `CRM_CONSOLE_V2_ENABLED=false` → 404 sur `/v1/crm/*` | `EnsureCrmConsoleV2.php:29-30` |

**C'est le chantier suivant, et il est plus court qu'on ne le croyait** : l'ordre de levée est
V5 → V6 → V3′ → V7, et il rendrait mesurables les 39 écrans du mandat, jamais ouverts à ce jour.

# OÙ ON EN EST — état vivant, à lire en premier

> **À quoi sert ce fichier.** Il est réécrit à chaque étape pour qu'une coupure
> ne coûte rien. Il dit ce qui est prouvé, ce qui attend Will, ce qui est à
> moitié fait, et par quoi reprendre — dans cet ordre.
>
> Récit détaillé : `REPRISE-ETAT.md` (§14 à §16). Registre des constats :
> `FILE-DE-TRAVAIL.md`. Verdicts bruts de la vague de vérification :
> `verdicts-420.json`. Ce qui revient à Will : `ARBITRAGES.md`.
>
> 📖 **Une session d'explication est ouverte en parallèle** — elle n'écrit aucun
> correctif, elle explique les constats à Will thème par thème. Son journal est
> `SESSION-EXPLICATION-2026-08-23.md`, tenu au fur et à mesure ; les 168 S2
> ouverts y sont rangés en 15 thèmes par `S2-PAR-THEME.md` (rejouable :
> `python extraire-s2-par-theme.py`). **Ce rangement ne referme rien.**

**Dernière mise à jour : 2026-08-23, après-midi — LA PILE DE VÉRIFICATION EST DEBOUT,
LES SIX VERROUS SONT LEVÉS, LA CONNEXION EST PROUVÉE.**

---

## 0. REPRENDRE EN TROIS COMMANDES

```bash
# 1. Où en est le code
cd C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth && git log --oneline -8

# 2. Rendre le banc honnête AVANT toute suite (sans quoi douze gardes mentent)
bash infra/scripts/rafraichir-le-banc.sh a35r

# 3. La vérification est FINIE (23/08). Pour la rejouer, par tranches courtes :
docker exec a35r sh -lc 'cd /var/www/html && php artisan test --without-tty tests/Feature/Crm tests/Feature/Database'
```

⚠️ **Le banc ne monte que `backend/`.** Tout le reste y arrive par `docker cp`,
donc figé. Douze gardes ont rougi sur des documents pourtant corrects. Et leur
« témoin de présence » n'y voit rien : il attrape « fichier absent », mais une
copie **périmée** existe. Rafraîchir d'abord, croire ensuite.

---

## 0 bis. 🟢 CE QUI A CHANGÉ CET APRÈS-MIDI — à lire avant le reste

**La console est enfin joignable, connectée, sur le BON code.** C'est ce qui bloquait
l'exigence n° 3 du §12 du mandat depuis le début.

### La pile de vérification, en trois commandes

```bash
cd C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth
docker compose -p crmverif -f docker-compose.verif.yml up -d
curl -sk --resolve verif.localhost:8443:127.0.0.1 https://verif.localhost:8443/up   # 200, ~0,2 s
```

| | |
|---|---|
| adresse | `https://verif.localhost:8443` (TLS interne Caddy ; `-k` en ligne de commande) |
| compte | `audit360@verif.localhost` / `Audit360-Verif!2026` — rôle `owner`, sans 2FA |
| base | `crmverif-postgres`, **jetable**, séparée de `axion_crm_test` du banc |
| conteneurs | `crmverif-{postgres,redis,api,app,caddy}` — **rien de commun** avec les `axion-crm-*` |

⚠️ **Sanctum : toujours envoyer `Origin` et `Referer`.** Sans eux, la requête est traitée comme
apatride et rend 401 **malgré** un cookie de session valide. Mesuré : avec → 200, sans → 401.
Un navigateur les envoie toujours ; `curl`, non. *Ne pas rapporter ce 401 comme un défaut.*

### Les six verrous, et leur état

| | verrou | état |
|---|---|---|
| **V5** | pile câblée sur la copie interdite | ✅ **levé** — pile `crmverif` séparée, bâtie du worktree |
| **V6** | Caddy vivant en `api:80` au lieu de `api:9000` fastcgi | ✅ **levé sur cette pile** — `Caddyfile.verif` |
| **V3′** | `totp_recovery_codes` en `ARRAY` face à un cast `encrypted:array` | ✅ **levé** — migration passée, colonne en `text` |
| **V7** | `CRM_CONSOLE_V2_ENABLED=false` → 404 sur `/v1/crm/*` | ✅ **levé** — `/crm/persons/{clé}/timeline` rend 401, plus 404 |
| **V8** | `APP_KEY` **vide** — *cinquième verrou, découvert le 23/08* | ✅ **levé** — et **vu rouge** |
| **V9** | **le montage lié** — *sixième verrou, découvert le 23/08* | ✅ **levé** — code cuit dans l'image |

🔴 **Le sixième verrou est celui que personne n'avait nommé, et c'est le plus lourd.**
Un seul `GET /up` mettait **428 secondes**. Cause mesurée : 13 944 fichiers dans `vendor`,
2 min 15 s pour les parcourir à ~10 ms pièce à travers le montage Docker Desktop Windows — et la
cible `dev` du Dockerfile pose `opcache.enable=0`, si bien que **chaque** requête repaie le
parcours. Témoin de contrôle au même instant, même Caddy, même hôte : le frontend, **cuit dans son
image**, rend 200 en **0,32 s**. La seule variable qui diffère est le montage.

Correctif : cible **`prod`** (code cuit, vendor `--no-dev`, opcache actif, caches construits au
démarrage par `entrypoint-prod`). **428 s → 0,17 s.** Effet de bord heureux : plus aucun montage
lié, donc l'interdiction du mandat de toucher à `Axion-CRM-Pro/backend` devient **sans objet** au
lieu d'être une promesse.

🔴 **Le cinquième verrou a été vu ROUGE, et par accident — donc sans complaisance.**
`APP_KEY` est vide dans les **quatre** fichiers (`.env:22` et `backend/.env:22`, worktree **et**
copie principale). Le banc ne peut pas le voir : `backend/phpunit.xml:28` la fournit. Et la clef
posée le matin même faisait **38 octets** décodés là où aes-256-cbc en exige **32** : le journal
disait « Unsupported cipher or incorrect key length » **24 fois**, et toute route rendait 500.
*Une clef présente mais mal dimensionnée échoue exactement comme une clef absente.*

### Deux corrections au présent document

1. **Le §8 étape 2 était déjà faite.** `origin` porte `fix/gardes-de-plan-et-c19-010` à `e41a034`.
2. **Le §8 étape 1 est faite** — voir ci-dessous.

### Ce qui est commité, et ce qui ne l'est pas

| dépôt / branche | commit | poussé ? |
|---|---|---|
| `crmpro-wt-a35-auth` · `fix/gardes-de-plan-et-c19-010` | `0c06153` pile de vérif · `29bf113` montage + clef | ❌ **local** |
| `Axion-IA/axionia` · `fix/correctifs-audit-360-depot-du-site` | `2b3612a0` — les 586 lignes | ❌ **local** |
| `Axion-CRM-Pro` · `audit/360-p1-p2` | `1c9b6b8` journal + preuves | — |

⚠️ **Rien n'est poussé sur les dépôts publics sans le mot de Will** (§A00 de `06_RESTE-WILL.md`).

🔴 **L'archive de secours du dépôt du site était PÉRIMÉE** — 40 709 octets contre 44 386, et il
lui manquait `confirmations.spec.ts` **en entier**, donc tout le constat `E34-006`. C'est la
démonstration qu'une archive ne remplace pas un commit : prise une fois, le travail a continué
sans elle. La branche `fix/correctifs-audit-360-depot-du-site` a été créée **par un index
temporaire** — `HEAD`, l'index et la copie de travail de `docs/plan-console-editoriale` n'ont pas
bougé d'un octet, au cas où une autre session y travaillerait.

### Par quoi reprendre MAINTENANT

**Ouvrir les 39 écrans du §4.7 du mandat, un par un, dans un vrai navigateur**, grille §5.1 et une
capture par état. C'est l'exigence n° 3 du §12, la seule jamais entamée — et plus rien ne l'empêche.

---

## 1. L'ÉTAT DU CODE

| | |
|---|---|
| worktree de travail | `C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth` |
| branche | `fix/gardes-de-plan-et-c19-010` @ `29bf113` — `e41a034` est **poussé** ; les 2 commits de l'après-midi ne le sont pas |
| copie principale (journaux) | `Axion-CRM-Pro`, branche `audit/360-p1-p2` |
| conteneur du banc | `a35r` · base de test `axion_crm_test` (forcée par `phpunit.xml`) |

⚠️ **Le dépôt du SITE, lui, n'est pas propre** — et personne ne l'avait compté.
`Axion-IA/axionia` porte **586 lignes de correctifs d'audit non committées**
(`E31-010`, `E33-002`, `E33-004`, plus `crm-sync`), sur la branche
`docs/plan-console-editoriale` qui n'est pas la leur, au milieu du travail
d'autres sessions. Je n'y ai touché à **aucune** branche ; le patch est archivé
dans `04_PREUVES/agent-35/site-non-committe/`.

✅ **RÉGLÉ le 2026-08-23 après-midi.** Les 586 lignes (11 fichiers, `E31-010`,
`E33-002`, `E33-004` **et `E34-006`** que l'archive avait manqué) sont sur
`fix/correctifs-audit-360-depot-du-site` (`2b3612a0`), branche **locale**, créée
par un index temporaire : `HEAD`, l'index et la copie de travail de
`docs/plan-console-editoriale` n'ont pas bougé d'un octet.

### Fusionné et déployé en production

| PR | contenu | état |
|---|---|---|
| **#192** | correctif Caddy + sonde `A05-001` | fusionnée `41688a9`, déployée |
| **#193** | 3 gardes de plan, `C19-010`, canal Telegram, `G43-004` | fusionnée `5087f1e`, déployée |

### En cours, non fusionné

La branche `fix/gardes-de-plan-et-c19-010` porte **la vague 2** : 91 correctifs
écrits par 20 agents, plus les six défauts trouvés en les vérifiant. La vérification est **finie et verte** (23/08).
**Pas encore de PR ouverte** — le dépôt est PUBLIC et `06_RESTE-WILL.md` §A00
garde la trace d'un agent qui a poussé malgré trois refus : **la PR se demande à
Will avant de s'ouvrir.**

---

## 2. CE QUI EST PROUVÉ EN PRODUCTION

| chose | preuve |
|---|---|
| `A05-001` fermé | `crm:remplir-cle-personne` → **410 481 fiches** ; sonde verte, code 0 |
| Correctif Caddy | deux déploiements de suite, retour en 200 **sans geste manuel** |
| **Telegram vivant** | `AlerteTelegram::envoyer()` → `ENVOYE`. Jetons repris d'Axion IA, posés dans `/opt/axion-crm-pro/.env` **et** en secrets GitHub. Canal : `TELEGRAM_CHAT_ID_SYSTEM` |
| Fiche 360° branchée | `api/v1/crm/persons/{clé}/timeline` → **401** (existe, protégée) ; chemin voisin → 404 |

⚠️ **Couverture réelle du rapprochement** : 1 319 567 contacts, dont **410 481
portent une adresse** — la clé se calcule sur elle. Les 909 086 autres sont hors
de portée **par construction**, pas par défaut. La sonde le dit désormais.

---

## 3. L'AUDIT, CHIFFRÉ

```
          fermés  partiels  ouverts   total
S0            16         3        6      25
S1            62         7       47     116
S2            88         0      168     256
S3            40         0       48      88
────────────────────────────────────────────
TOTAL        206        10      269     485
```

✅ **Les 91 correctifs de la vague 2 sont inscrits** (2026-08-23) : **+87 lignes
fermées**, dont 86 sur une garde **jouée verte le jour même** et 2 sur un
correctif documentaire. Aucun S0 dans le lot — ceux qui restent sont des
`conception` et des arbitrages, pas des correctifs mécaniques.

🔴 **Trois correctifs restent ouverts à dessein** : `E31-010`, `E33-002`,
`E33-004` sont **écrits mais non committés**, en modification non suivie dans le
dépôt du site. Un correctif qu'aucun dépôt ne porte n'est pas un correctif.

### D'où viennent les 206 fermés

- **87** fermés le 2026-08-23 par la vague 2, garde jouée verte le jour même.
- **55** fermés par des correctifs, vagues 1 à 14.
- **64** fermés par **lecture**, le 2026-08-22 : trente vérificateurs ont établi
  que le registre les déclarait ouverts **à tort**. Chacun porte une citation
  `fichier:ligne` ; trois ont été revérifiés à la main (`A08-001`, `B14-005`,
  `B12-006`) avant d'y toucher.

---

## 4. LA VÉRIFICATION DE LA VAGUE 2 — ✅ TERMINÉE LE 2026-08-23

| contrôle | tests | verdict |
|---|---:|---|
| Syntaxe PHP · PHPStan niveau 8 · Pint · `tsc --noEmit` | — | ✅ |
| `Feature/Crm` + `Feature/Database` | 266 | ✅ |
| `Feature/Console` + `Feature/Commands` | 100 | ✅ |
| `Feature/Rgpd` + `Auth` + `Controllers` | 368 | ✅ |
| 8 sous-dossiers restants de `Feature` | 84 | ✅ |
| `Feature` racine (28 fichiers) | 224 | ✅ |
| `Unit` (48 fichiers) | 342 | ✅ |
| `Feature/Infra` | 261 | ✅ |
| Workers (`vitest`) | 94 | ✅ |
| Frontend (`vitest`, 59 fichiers) | 412 | ✅ |

**Six rouges rencontrés, tous examinés, aucun n'était une régression du produit.**
Le récit complet est au `00_JOURNAL.md`, entrée du 2026-08-23. En trois mots :
trois gardes comptaient sur un défaut SQL que la vague 2 avait délibérément
retiré ; un plafond n'avait pas été rouvert après un correctif légitime ; et
**trois gardes lisaient leurs propres commentaires comme du code** — dont une qui
rougissait sur le compte rendu de sa propre réparation.

⚠️ **Jouer par tranches courtes.** La suite entière meurt en sortie 255 vers
816 tests, et les tâches d'arrière-plan longues se font arrêter. Une tranche =
un ou deux dossiers.

⚠️ **Jamais deux suites en parallèle.** Elles partagent une base et chacune
commence par `migrate:fresh` : deux processus concurrents ont déjà produit deux
faux échecs dans cette campagne.

---

## 5. CE QUI ATTEND WILL

### Tranché

| question | décision |
|---|---|
| le scraping doit-il être vivant ? | **REPORTÉ** — « on verra une prochaine fois ». Aucun geste. |
| où part l'alerte d'un déploiement rouge ? | **TELEGRAM**, fait et testé. |
| `C19-010` / fiches sans dénomination | **sans objet** : 0 fiche en production. Guetteur posé à 06h20. |

### Encore ouvert

**`ARBITRAGES.md`** liste les **116 constats** dont le correctif ne m'appartient
pas, groupés par nature : **89 touchent la production**, **18 changent une
sémantique**, **9 sont juridiques**.

Plus, du mandat lui-même (§12, « définition de fini ») :

| # | exigence | état |
|---|---|---|
| 3 | **chaque écran ouvert à la main**, captures archivées | 🟡 **écrans faits le 23/08** (36/36) ; **« chaque bouton essayé » et les 21 parcours du §11 restent** |
| 5 | chaque S0/S1 corrigé | 🟡 **6 S0 et 47 S1 ouverts** |
| 9 | les **57 alertes de vulnérabilité** arbitrées ou gelées | ❌ toujours 57 |
| 10 | aucune route 501 | ❌ `I48-001` ouvert |
| 13 | **une sauvegarde restaurée pour de vrai** | ✅ **TENU** — *cette ligne disait « aucune trace » à tort* : `agent-08`, archive de 724 926 343 octets, **20 726 338 lignes, écart nul**. Réserve écrite : copie locale, pas hors-site |
| 14 | plus rien de sévérité ≥ S2 | ❌ 168 S2, 48 S3 *(étaient 220 et 82)* |

### 📊 LA DÉFINITION DE FINI (§12), REPRISE POINT PAR POINT LE 2026-08-23

> Le tableau de référence est `12_CRITIQUE-COMPLETUDE.md` §8, mais il **date du
> 2026-08-19 12:06Z** — donc **d'avant** les deux vagues d'agents, d'avant P5,
> d'avant P6, d'avant le rapport final, et d'avant l'ouverture des écrans. Voici
> le même tableau, **remesuré**. Ce qui a bougé est signalé.

| # | exigence | état au 2026-08-23 | bougé ? |
|---|---|---|---|
| 1 | chaque élément du §4 dans un tableau, aucune ligne vide | ❌ **0/11 policies · 0/84 services · 0/34 workers · 0/44 contrôleurs** en grille | — |
| 2 | chaque grille du §5 remplie | ❌ grille ÉCRAN à **8 points sur 25** ; `fonctionnalites.md`, `raccordement.md`, `parcours.md` **absents** | — |
| 3 | chaque écran ouvert à la main, **chaque bouton essayé**, captures | 🟡 **les 36 écrans sont ouverts** (23/08, connectés, bon code, captures) — mais **« chaque bouton essayé » non**, et **0 des 21 parcours du §11** joué | 🟢 **oui** |
| 4 | §6 : refonte de navigation **appliquée** | ⚠️ document produit (73 Ko) ; **refonte non appliquée**, 8 redirections à écrire, **0 écrite** | — |
| 5 | chaque S0/S1 corrigé, test vu rouge puis vert | ❌ **6 S0 et 47 S1 ouverts** *(étaient 22 S0)* | 🟢 en partie |
| 6 | les 16 lignes de l'étape 0 closes | ❌ **7 clos · 7 partiels · 2 ouverts** | — |
| 7 | F1 → F15 levées ou arbitrées sur `main` fusionné | ❌ **5 levées · 7 partielles · 3 encore vraies** | — |
| 8 | PR #174 et #735 fusionnées, `main` vert | ✅ **tenu**, re-vérifié | — |
| 9 | les 57 alertes arbitrées, corrigées ou gelées | ⚠️ 57/57 mesurées, **0 atteignable en production**, politique de gel écrite ; la montée `axios 1.16.1 → 1.18.0` **non faite**, `H47-005` non tranché | — |
| 10 | aucune route 501, aucun écran factice | ❌ **19 routes 501 + 9 « 200 à corps figé » + 3 inertes** ; `/cold-email` et `/linkedin` conservés | — |
| 11 | matrice exigence → test → preuve complète | ❌ section C (13 parcours) **vide** | — |
| 12 | critères du §29 du CDC mesurés | ❌ **1 tenu · 4 non tenus · 8 hors périmètre motivé · 12 en blanc** | — |
| 13 | **une sauvegarde restaurée pour de vrai** | ✅ **tenu** — 724 926 343 octets, **20 726 338 lignes, écart nul**, témoin négatif joué. Réserve : copie locale, pas hors-site ; et `A08-008`, les **droits** ne sont pas restaurés | — |
| 14 | P5 **puis** P6 menées en entier, la dernière sans rien ≥ S2 | 🟡 **les deux ONT été menées** (`08_…md` 805 l., `09_…/` 6 fichiers) — mais la condition de sortie **n'est pas remplie** : **168 S2 et 48 S3 ouverts** | 🟢 **oui** |
| 15 | rapport final, verdict net par domaine | 🟡 **`07_RAPPORT-FINAL.md` existe** (234 l.), avec un verdict par domaine — mais son §28 s'appuie sur **quatre verrous levés depuis** : il est à réécrire | 🟢 **oui** |
| 16 | `06_RESTE-WILL.md` en une page, chaque ligne avec recommandation | ✅ **tenu**, format respecté | — |

**Score : 3 tenus sur 16** (8, 13, 16) · **3 nettement avancés** (3, 14, 15) ·
**2 partiels** (4, 9) · **8 non tenus**.

*Le chantier des écrans, qui était le plus gros manque structurel, est clos. Ce
qui reste n'est pas de la mesure d'écran : c'est du remplissage de grilles, de la
correction de S0/S1, et des arbitrages qui reviennent à Will.*

✅ **CE PARAGRAPHE EST PÉRIMÉ — les écrans ONT été ouverts le 2026-08-23**, tous les
36, dans un vrai navigateur, connectés, sur le bon code. Les six verrous V5 à V9
décrits plus bas sont **tous levés** (§0 bis). Ce qui suit est conservé pour la
traçabilité de la décision, **pas comme un reste à faire**.

🔴 *État d'avant le 23/08 — conservé pour mémoire.*
Les 39 écrans n'ont toujours jamais été ouverts dans un navigateur. En revanche,
la raison invoquée par le rapport final est **périmée** : les **quatre verrous**
du `07_RAPPORT-FINAL.md:28` (mot de passe initial · `MAIL_MAILER` · enrôlement
2FA sur trois colonnes inexistantes · écran blanc dès une ligne d'`audit_logs`)
**sont levés dans le code**, chacun avec sa citation `fichier:ligne`. Le
quatrième (`ActivityFeed.tsx:152`) n'est encore que sur la branche non fusionnée.

Ce qui bloque **aujourd'hui** est de l'infrastructure, et c'est plus court :

| | ce qui bloque | preuve | qui |
|---|---|---|---|
| **V5** | `axion-crm-api` et `axion-crm-redis` **arrêtés** (`Exited 255`) — tout `/api/*` rend 502 | `docker logs axion-crm-caddy` : `dial tcp: lookup api: i/o timeout` | voir ci-dessous |
| **V6** | Le Caddy **vivant** date d'avant le passage à FastCGI : il parle `api:80`, la branche attend `api:9000` en fastcgi | API d'admin 2019 : `4 × "dial":"api:80"`, aucun `fastcgi` | voir ci-dessous |
| **V3′** | La migration `2026_08_19_120000_reparer_socle_authentification` **n'est pas appliquée** : `totp_recovery_codes` est `ARRAY`, face à un cast `encrypted:array` | dernière migration en base : `2026_08_19_000002` | moi |
| **V7** | `CRM_CONSOLE_V2_ENABLED=false` → 404 sur `/v1/crm/*` | `EnsureCrmConsoleV2.php:29-30` | drapeau produit → Will |

**Ordre de levée : V5 → V6 → V3′ → V7.** C'est le prochain vrai chantier, et
c'est lui qui rendrait les 39 écrans mesurables autrement qu'au `curl`.

🔴 **MAIS V5 N'EST PAS « rallumer deux conteneurs », et c'est la mesure la plus
importante du 2026-08-23.** La pile locale n'est pas seulement arrêtée : elle est
**câblée sur la mauvaise copie du code**.

`docker inspect axion-crm-api` rend `com.docker.compose.project.config_files =
Axion-CRM-Pro/docker-compose.yml`, dont le service `api` monte
`./backend:/var/www/html` — c'est-à-dire **`Axion-CRM-Pro/backend`, la copie que
le mandat interdit de toucher**, figée sur `audit/360-p1-p2` et qui **ne porte
aucun des 91 correctifs**. Rallumer l'API telle quelle servirait donc un CRM
d'avant la vague 2, et les 39 écrans seraient mesurés sur du code périmé —
l'exact contraire de ce qu'on cherche.

Et l'on ne peut pas simplement rebrancher la pile sur le worktree : les
`container_name` sont **fixes** (`axion-crm-postgres`, `axion-crm-caddy`, …), si
bien qu'un `up` depuis `crmpro-wt-a35-auth` **reprendrait les mêmes conteneurs** —
dont `axion-crm-postgres`, qui héberge `axion_crm_test`, **la base du banc**, et
`axion-crm-caddy`, qui sert en ce moment le frontend à d'autres sessions.

**Ce n'est donc pas un geste d'exploitation, c'est une décision** : *quelle copie
du code la pile locale doit-elle servir ?* Elle revient à Will, ou au minimum se
pose avant d'être prise. Tant qu'elle n'est pas tranchée, aucune ouverture
d'écran à la main n'a de sens.

---

## 6. LES DEUX VAGUES D'AGENTS — ce qu'elles ont donné

### Vague de vérification (30 agents, lecture seule, 1 h 36)

420 constats tranchés : **341 ouverts confirmés, 64 déjà fermés, 15 indécidables**.
Règle imposée : sans citation `fichier:ligne` réellement lue, le verdict est
INDÉCIDABLE — *un faux « déjà fermé » enterre un défaut.*

### Vague de correction (20 agents, fichiers disjoints, 1 h)

120 fiches : **91 corrigées, 26 reportées, 3 déjà fermées**. 203 fichiers,
5 795 insertions.

Les 26 reports, par cause : **11** dans le dépôt du site (mon découpage était
fautif), **8** demandent un arbitrage, 2 une mesure préalable, 2 portent sur des
journaux non versionnés, 1 permission refusée, 2 autres.

⚠️ **Une vague dédiée au dépôt du site reste à lancer** (`Axion-IA/axionia`, qui
est versionné) pour les 11 premiers.

---

## 7. LES PIÈGES DE CE DÉPÔT — payés, à ne pas repayer

1. **`toContain($aiguille, $message)` est VARIADIQUE** en Pest : le message y
   devient une seconde aiguille. Idem `toHaveKey($clé, $valeur)`. Employer
   `expect(str_contains(...))->toBeTrue($message)`.
2. **`expectsOutputToContain` compare ligne par ligne** — le formateur coupe les
   messages longs. Employer `Artisan::call()` + `Artisan::output()`.
3. **`RecursiveDirectoryIterator` TRONQUE** sur ce montage Docker : 14 fichiers
   sur 56 mesurés. Employer un `scandir` récursif.
4. **`docker cp` copie DANS la cible** quand elle existe déjà : elle crée
   `/var/www/X/X/` et laisse l'ancien intact. D'où le `rm -rf` du script.
5. **`git branch` crée sans basculer.** Six commits sont partis sur la mauvaise
   branche, et trois `git push` n'ont rien poussé — masqués par un filtre `grep`.
6. **Un plan de requête n'est pas une propriété du schéma** dans une suite
   transactionnelle. `RefreshDatabase` annule les données, pas l'état physique.
   Trois gardes étaient vertes par chance.
7. **`notifyNow?` ne reconnaît pas `notify()`** — le `?` ne porte que sur le
   caractère précédent.
8. **Ne jamais relever un plafond de garde** pour accommoder son propre code.
   En refusant, on trouve souvent une meilleure réponse.
9. **Un bouchon de test qui simplifie ce qu'il imite** fait mesurer le bouchon,
   pas le produit — un `Link` simulé qui jetait ses propriétés effaçait
   l'attribut que la garde cherchait.

---

## 8. PAR QUOI REPRENDRE, DANS L'ORDRE

> Mis à jour le 2026-08-23. Les étapes 1 à 3 de la version précédente sont
> **faites** : banc rafraîchi, vérification backend terminée et verte, 91
> correctifs inscrits au registre.

1. ✅ **FAIT** — les correctifs du dépôt du site sont sécurisés sur
   `fix/correctifs-audit-360-depot-du-site` (`2b3612a0`), branche locale.
2. ✅ **DÉJÀ FAIT AVANT** — `d9205b5` et `e41a034` étaient poussés ; ce document
   se trompait. *Ne pas repayer cette vérification : `git ls-remote --heads origin`.*
3. **Demander à Will l'ouverture de la PR** de `fix/gardes-de-plan-et-c19-010`.
   ⚠️ Le dépôt est **public** et le §A00 de `06_RESTE-WILL.md` garde la trace
   d'un agent qui a poussé malgré trois refus : **ne pas l'ouvrir sans réponse.**
4. ✅ **FAIT le 2026-08-23** — **les 36 écrans sont ouverts à la main**, un par
   un, dans un vrai navigateur. L'exigence n° 3 du §12 du mandat, la seule jamais
   entamée, est **atteinte**. Journal complet, constats et reprise :
   `ECRANS-OUVERTS-A-LA-MAIN-2026-08-23.md` (quatre passes).
   - **36, pas 39 ni 37** : `/crm` et `/analytics` n'existent pas, et le 37ᵉ
     `createRoute` est `layoutRoute`, la coquille de mise en page (`id: 'layout'`,
     aucun `path`). `01_INVENTAIRE.md:41` est à corriger d'une unité.
   - **23 constats `X39-*`** relevés, tous recoupés dans le code ou en base.
   - 🟢 **L'hypothèse du sondage accumulé est RÉFUTÉE** : même débit d'appels
     (1,5/min) sur le même écran, avant et après 20 changements de route sans un
     seul rechargement ; 25 minuteurs posés, 24 coupés, 1 vivant.
   - **Cinq faux constats évités** — le gel, `/contacts`, le gréement, le
     « chargement infini » de la fiche introuvable, et un message d'erreur que
     j'avais déduit d'une seule ligne de code avant de le mesurer.
   - Gréement : **Playwright**, une page neuve chargée à froid par écran.
     *L'extension Chrome de Claude ne répond pas sur ce poste.*
5. **Lancer la vague du dépôt du site** pour les 11 constats reportés.
6. Attaquer les **47 S1 ouverts**, puis les 168 S2.
7. Poser à Will les arbitrages de `ARBITRAGES.md`, famille par famille.

---

# 🟢 LA BRANCHE ÉTAIT ROUGE EN CI, ET LES TROIS ROUGES ÉTAIENT MÉCANIQUES

> Découvert le 2026-08-23 en cherchant à répondre à « est-ce que tout est
> terminé ? ». **Personne ne l'avait regardé.**

`fix/gardes-de-plan-et-c19-010` portait les 91 correctifs de la vague 2 et sa CI
rougissait depuis le **23/08 06:53**, alors que la vérification locale était
verte — 1 645 tests backend, 412 frontend, 94 workers. *Un dossier peut déclarer
une vague « vérifiée en entier » pendant que la forge la refuse.*

| workflow | verdict |
|---|---|
| CI | ❌ **failure** |
| Security | ❌ **failure** |
| E2E | ✅ success |
| Accessibility | ✅ success |

**Aucun des trois rouges de `CI` ne touchait un test.**

| # | job rouge | cause réelle | portée |
|---|---|---|---|
| 1 | Frontend React/Vite → *Lint (BLOQUANT)* | `vi.importActual<typeof import('@/lib/api')>` — une annotation de type `import()` en ligne, refusée par `@typescript-eslint/consistent-type-imports` | **1 ligne** |
| 2 | *Les scripts d'infra sont-ils exécutables ?* | `infra/scripts/rafraichir-le-banc.sh` enregistré en **100644** | **1 fichier** |
| 3 | Backend → *Pint (BLOQUANT)* | règle `single_quote` | **2 fichiers sur 192 modifiés** |

## Le détail, et ce qui a été vérifié avant d'accepter

**① Le lint.** Remplacé par `import type * as ModuleApi from '@/lib/api'`.
Ce n'est pas un détail de style dans ce fichier : sa mise en page inhabituelle
(les `import` **après** le `vi.mock`) existe pour le hissage de `vi.mock`.
`import type` est **effacé à la compilation**, donc il ne déplace rien.
*Vérifié : eslint vert, et le fichier passe toujours **11 tests sur 11**.*

**② Le bit d'exécution.** Ironie utile : le fichier fautif est **exactement le
script que la mémoire de reprise prescrit de lancer avant toute suite de tests**
(`rafraichir-le-banc.sh a35r`). Non exécutable, il aurait échoué **en silence
sous cron** — ce que la garde dit vouloir empêcher, mot pour mot.
*La garde ne couvre que `infra/scripts/*.sh`.* Deux autres `.sh` du dépôt sont en
100644 (`backend/database/perf/mesure_reference.sh`, `infra/docker/entrypoint-prod.sh`) :
**hors de sa portée, délibérément pas touchés.**

**③ Pint.** ⚠️ **Vérifié avant d'accepter, parce que Pint touchait à des CHAÎNES
et non à de l'indentation.** Le diff transformait `"…"` en `'…'` autour d'une
**vraie nouvelle ligne** (pas une séquence `\n`) :

```php
$plat .= str_repeat("\n", …)   →   $plat .= str_repeat('\n', …)
                 ↑ une VRAIE nouvelle ligne dans la source, pas un antislash-n
```

Mesuré plutôt que supposé :

```
"<0a>" === '<0a>'   →  bool(true)
strlen             →  1  et  1
bin2hex            →  "0a"  et  "0a"
```

Plus `php -l` sur les deux fichiers. **Le changement est neutre à l'octet près.**

## Les trois contrôles rejoués en local

| contrôle | résultat |
|---|---|
| `pint --test` sur les **192** fichiers PHP modifiés face à `main` | ✅ **PASS** |
| `eslint . --max-warnings 0` | ✅ **vert** |
| `git ls-files -s infra/scripts/*.sh` | ✅ tous en **100755** |

⚠️ **`Security` reste rouge et n'est PAS traité ici.** Il vit dans
`security.yml` (audits de dépendances + scan de secrets) et relève des
**57 alertes de vulnérabilité** du point 9 du §12 — un autre chantier.

## ⛔ NON POUSSÉ

Le dépôt est **public**. Les commits restent locaux, conformément au §A00 de
`06_RESTE-WILL.md`.

| état | nombre |
|---|---|
| commits **locaux, jamais poussés** | **5** |
| commits **poussés**, non fusionnés dans `main` | **6** (les 91 correctifs) |

Les cinq locaux : la pile de vérification (`0c06153`), le montage lié et la clef
(`29bf113`), l'écoute HTTP (`106cdbf`), `DB_TIMEZONE` (`8f23e9b`), et les trois
rouges de CI (`8a8bf41`).

---

# ✅ CE QUI EST DÉJÀ EN PRODUCTION — la mémoire du dossier était PÉRIMÉE

En cherchant quoi conseiller, j'ai vérifié la faille de production (Postgres et
Redis joignables depuis internet, mesurée le 19/08). **Elle est fermée.**

| | mesure |
|---|---|
| `23a0e5f` et `b6fa07f` — les **onze chemins** qui rouvraient les ports | ✅ **sur `origin/main`** |
| PR **#193** | ✅ **fusionnée le 2026-08-22 à 20:57** |
| workflow « Deploy direct SSH Hetzner » | ✅ **success** |

*Le dossier et la mémoire disaient encore « poussés sur la branche, non fusionnés ;
le produit qui tourne porte encore le défaut ». C'est faux depuis le 22/08 au soir.*

⚠️ **Ce qui reste à Will sur ce sujet, inchangé et non vérifiable d'ici** :
rendre les règles de pare-feu **persistantes** (elles sautent au redémarrage),
faire tourner les secrets, trancher l'article 33 du RGPD.
*Je n'ai pas touché à la production — ces trois points reposent sur le relevé du
19/08, pas sur une mesure d'aujourd'hui.*

---

# 🔴 CORRECTION D'ÉTAT — les « trois gestes de Will » étaient DÉJÀ FAITS

> Écrit le 2026-08-23 après vérification. **Ce dossier, sa mémoire de reprise et
> moi-même avons répété pendant quatre jours que trois choses attendaient Will.
> Les trois étaient réglées depuis le 19 août, et écrites.**

Ce que je répétais, et ce qui est vrai :

| ce qui était annoncé « en attente » | ce qui est mesuré |
|---|---|
| « rendre les règles de pare-feu persistantes » | ✅ **FAIT le 19/08** — `iptables-persistent`, puis re-sauvegarde sur l'état propre (registre, mesure n° 2) |
| « faire tourner les secrets » | ✅ **TRANCHÉ le 19/08** — examinée et **écartée par le dirigeant**, motif écrit, **risque résiduel assumé** (registre, mesure n° 6) |
| « trancher l'article 33 » | ✅ **TRANCHÉ le 19/08** — *« Décision du responsable de traitement : NE PAS NOTIFIER »*, prise par Williams Jullin, avec sa motivation (registre, §7) |

**Où c'est écrit, et c'est sur `main`** :
`_REPORTS/REGISTRE-DES-VIOLATIONS-DE-DONNEES.md` et
`_REPORTS/2026-08-19_BROUILLON-NOTIFICATION-CNIL-ART33.md`.
La branche `docs/registre-violations` est **entièrement fusionnée** (0 commit
d'avance sur `main`).

*L'obligation de documentation de l'article 33 §5 — qui vaut **même quand on ne
notifie pas** — est donc remplie.*

## Pourquoi je ne l'avais pas vu

Je lisais la mémoire de reprise, qui datait du 20/08 et disait « restent ouverts :
persistance, secrets, article 33 ». Elle était juste **ce jour-là**. Le registre a
été écrit le 19, la fusion a eu lieu ensuite, et personne n'est revenu fermer la
note. *C'est le défaut que ce dépôt paie sans cesse : une note d'état qui survit à
la réalité qu'elle décrit.* Mémoire corrigée le 23/08.

## ⚠️ CE QUI RESTE VRAIMENT OUVERT — un seul point, et il est dans le registre

**Mesure n° 7, identifiée le 19/08, non réalisée : la journalisation des
connexions à la base.**

Ce n'est pas une ligne de plus sur une liste. C'est **exactement ce qui a empêché
de conclure** sur l'incident, et le registre le dit lui-même :

> ⚠️ *Il n'est pas possible de démontrer qu'aucun accès non autorisé n'a eu lieu :
> les journaux de connexion de PostgreSQL n'étaient pas conservés. L'absence
> d'effet constaté n'est donc pas une preuve d'absence d'accès.*

C'est cette phrase qui a rendu la décision de non-notification plus lourde à
porter qu'elle n'aurait dû l'être. **Tant que `log_connections` et
`log_disconnections` restent inactifs, un incident futur se soldera par la même
phrase.**

*Deux réglages dans la configuration Postgres. Le correctif se prépare ici ;
l'appliquer à la production reste un geste de Will.*

## 📌 Rappel du risque résiduel accepté

Le mot de passe `axion_dev_only` figure dans **huit fichiers** d'`origin/main`,
dépôt **public**, et il n'est pas tourné — décision assumée et consignée. Le seul
garde-fou est donc `infra/scripts/verifier-ports-publies.sh`, qui mesure **les
conteneurs en fonctionnement** et non la configuration.

⚠️ **Cette distinction n'est pas théorique, et le registre en garde la trace** :
le correctif de fond, *une fois fusionné et déployé avec succès*, **n'avait rien
fermé** — un déploiement ne recrée pas les conteneurs de base de données. Sans ce
contrôle, l'illusion aurait tenu. *Ne jamais désarmer ce script.*

---

# ✅ LA PR #194 EST VERTE ET FUSIONNABLE — 2026-08-23

**Elle était déjà OUVERTE**, créée le 23/08 à 06:53. Le §8 étape 3 demandait
« demander à Will l'ouverture de la PR » : il n'y avait rien à ouvrir, seulement
à débloquer. *Encore une note d'état en retard sur la réalité.*

| | |
|---|---|
| PR | **#194** — `fix/gardes-de-plan-et-c19-010` → `main` |
| contenu | **320 fichiers**, +17 050 / −1 383, **12 commits** |
| état avant | `BLOCKED` — CI rouge + Gitleaks rouge |
| **état après** | **`MERGEABLE` / `CLEAN`** — **21 contrôles verts**, 3 ignorés délibérément |
| url | https://github.com/will383842/axion-crm-pro/pull/194 |

Dont, parmi les 21 verts : **« La config de production ne publie que 80 et 443 »**,
la garde née de la faille des ports — elle tient sur cette branche.

## Ce qu'il a fallu pour la débloquer

1. **Trois rouges de CI, tous mécaniques** (commit `8a8bf41`) — une annotation de
   type `import()`, un script d'infra en 100644, deux fichiers Pint sur 192.
   Aucun ne touchait un test.
2. **Deux faux positifs Gitleaks** (commit `b94ee76`) — `un-mot-de-passe-de-reprise-2026`,
   donnée de test d'un test unitaire **sur la règle `NotPwnedPassword`**, face à un
   vérificateur HIBP simulé. **Lus avant d'être ignorés**, par empreinte, avec le
   pourquoi écrit dans `.gitleaksignore`.

⚠️ **La FUSION reste un geste de Will.** Rien n'est fusionné.

## État des dépôts à cet instant

| | |
|---|---|
| `crmpro-wt-a35-auth` · `fix/gardes-de-plan-et-c19-010` | **poussée**, 0 commit local en attente |
| `Axion-CRM-Pro` · `audit/360-p1-p2` | commitée localement (journaux d'audit) |
| PR #194 | **ouverte, verte, non fusionnée** |

---

# 🟢 LA MESURE 7 DU REGISTRE EST PRÊTE — 2026-08-23

**C'était le seul point vraiment ouvert du registre des violations de données.**
Il est écrit, vu rouge puis vert. **Il n'est pas déployé.**

| | |
|---|---|
| branche | `fix/journalisation-connexions-db`, tirée de `main` (`5087f1e`) |
| commit | `57f7737` — **local, non poussé** |
| pourquoi une branche à part | ne pas rouvrir la CI de la **PR #194**, verte et en attente de fusion |

## Ce qui est posé

Trois arguments sur le service `postgres` de **`docker-compose.yml`** — le
fichier de base, **pas** l'overlay de production :

```yaml
command:
  - postgres
  - -c
  - log_connections=on
  - -c
  - log_disconnections=on
  - -c
  - "log_line_prefix=%m [%p] %q%u@%d de %h "
```

*Dans le fichier de base à dessein* : le constat `F38-007` a dénombré **douze**
chemins qui lancent `docker compose up` sans l'overlay. Un réglage posé dans le
seul overlay laisserait chacun de ces douze chemins recréer un Postgres muet —
exactement le piège déjà payé sur la publication des ports.

**`%h` est le champ qui compte.** Sans lui, on saurait qu'il y a eu des
connexions sans pouvoir dire d'où — donc sans pouvoir distinguer un accès
interne d'un accès depuis internet. C'est la question exacte à laquelle il a
fallu répondre « on ne peut pas savoir » le 19 août.

## Vu rouge, puis vert — sur des serveurs qui TOURNENT

| serveur | mesure | sortie |
|---|---|---|
| `crmverif-postgres`, **sans** le réglage | `log_connections=off`, `log_disconnections=off`, préfixe **sans `%h`** | **1 — rouge** |
| un Postgres lancé avec **ces mêmes arguments** | les trois ✅ | **0 — vert** |

Et le journal produit porte bien l'origine :

```
connection received: host=127.0.0.1 port=47738
connection authorized: user=axion database=axion_crm application_name=psql
disconnection: session time: 0:00:00.105 user=axion database=axion_crm host=127.0.0.1
```

## Deux contrôles, et ils ne prouvent PAS la même chose

1. **`infra/scripts/verifier-journalisation-connexions-db.sh`** interroge
   PostgreSQL par `SHOW` : il mesure **le serveur qui tourne**. C'est le seul
   qui prouve quelque chose sur la réalité. *Leçon du registre : le correctif
   des ports a été fusionné, déployé avec succès, et n'avait **rien fermé**.*
2. **`JournalisationConnexionsBaseTest`** ne garde que le **fichier**, et son
   en-tête le déclare au lieu de le laisser croire.

### ⚠️ SEPTIÈME PIÈGE DE MESURE — la garde a failli être déclarée bonne à vide

Elle **ne trouvait même pas le bloc `postgres`** : `docker-compose.yml` est en
**CRLF**, et `^  postgres:
` ne rencontrait rien. Elle aurait rougi en annonçant
« service introuvable » — *rougir pour la mauvaise raison est une autre façon de
ne rien garder.* Corrigée, puis la logique rejouée **hors Pest** sur le fichier
réel (**vert**) et sur un compose muet (**rouge**).

## ⚠️ CE QUI RESTE À WILL

**Poser le réglage ne suffit pas.** Un déploiement ne recrée pas les conteneurs
de base de données. Il faut le geste explicite, **une fois** :

```bash
cd /opt/axion-crm-pro
export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"
docker compose up -d --no-deps postgres
bash infra/scripts/verifier-journalisation-connexions-db.sh
```

Une fois fait et vérifié, la **mesure 7** du registre des violations peut passer
de « identifiée, non réalisée » à réalisée. *Je n'ai pas modifié le registre :
c'est un document qui engage le responsable de traitement.*

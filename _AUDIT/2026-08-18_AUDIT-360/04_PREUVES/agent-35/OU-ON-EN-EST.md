# OÙ ON EN EST — état vivant, à lire en premier

> **À quoi sert ce fichier.** Il est réécrit à chaque étape pour qu'une coupure
> ne coûte rien. Il dit ce qui est prouvé, ce qui attend Will, ce qui est à
> moitié fait, et par quoi reprendre — dans cet ordre.
>
> Récit détaillé : `REPRISE-ETAT.md` (§14 à §16). Registre des constats :
> `FILE-DE-TRAVAIL.md`. Verdicts bruts de la vague de vérification :
> `verdicts-420.json`. Ce qui revient à Will : `ARBITRAGES.md`.

**Dernière mise à jour : 2026-08-23 (fin de la vérification de la vague 2).**

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

## 1. L'ÉTAT DU CODE

| | |
|---|---|
| worktree de travail | `C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth` |
| branche | `fix/gardes-de-plan-et-c19-010` @ `e41a034` — ⚠️ **2 commits du 23/08 NON POUSSÉS** |
| copie principale (journaux) | `Axion-CRM-Pro`, branche `audit/360-p1-p2` |
| conteneur du banc | `a35r` · base de test `axion_crm_test` (forcée par `phpunit.xml`) |

⚠️ **Le dépôt du SITE, lui, n'est pas propre** — et personne ne l'avait compté.
`Axion-IA/axionia` porte **586 lignes de correctifs d'audit non committées**
(`E31-010`, `E33-002`, `E33-004`, plus `crm-sync`), sur la branche
`docs/plan-console-editoriale` qui n'est pas la leur, au milieu du travail
d'autres sessions. Je n'y ai touché à **aucune** branche ; le patch est archivé
dans `04_PREUVES/agent-35/site-non-committe/`. *C'est la première chose à
sécuriser en reprenant.*

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
| 3 | **chaque écran ouvert à la main**, captures archivées | ❌ **jamais fait** |
| 5 | chaque S0/S1 corrigé | 🟡 **6 S0 et 47 S1 ouverts** |
| 9 | les **57 alertes de vulnérabilité** arbitrées ou gelées | ❌ toujours 57 |
| 10 | aucune route 501 | ❌ `I48-001` ouvert |
| 13 | **une sauvegarde restaurée pour de vrai** | ❌ aucune trace |
| 14 | plus rien de sévérité ≥ S2 | ❌ 168 S2, 48 S3 *(étaient 220 et 82)* |

🔴 **Le plus gros manque est structurel — mais il a rétréci le 2026-08-23.**
Les 39 écrans n'ont toujours jamais été ouverts dans un navigateur. En revanche,
la raison invoquée par le rapport final est **périmée** : les **quatre verrous**
du `07_RAPPORT-FINAL.md:28` (mot de passe initial · `MAIL_MAILER` · enrôlement
2FA sur trois colonnes inexistantes · écran blanc dès une ligne d'`audit_logs`)
**sont levés dans le code**, chacun avec sa citation `fichier:ligne`. Le
quatrième (`ActivityFeed.tsx:152`) n'est encore que sur la branche non fusionnée.

Ce qui bloque **aujourd'hui** est de l'infrastructure, et c'est plus court :

| | ce qui bloque | preuve | qui |
|---|---|---|---|
| **V5** | `axion-crm-api` et `axion-crm-redis` **arrêtés** (`Exited 255`) — tout `/api/*` rend 502 | `docker logs axion-crm-caddy` : `dial tcp: lookup api: i/o timeout` | moi |
| **V6** | Le Caddy **vivant** date d'avant le passage à FastCGI : il parle `api:80`, la branche attend `api:9000` en fastcgi | API d'admin 2019 : `4 × "dial":"api:80"`, aucun `fastcgi` | moi |
| **V3′** | La migration `2026_08_19_120000_reparer_socle_authentification` **n'est pas appliquée** : `totp_recovery_codes` est `ARRAY`, face à un cast `encrypted:array` | dernière migration en base : `2026_08_19_000002` | moi |
| **V7** | `CRM_CONSOLE_V2_ENABLED=false` → 404 sur `/v1/crm/*` | `EnsureCrmConsoleV2.php:29-30` | drapeau produit → Will |

**Ordre de levée : V5 → V6 → V3′ → V7.** C'est le prochain vrai chantier, et
c'est lui qui rendrait les 39 écrans mesurables autrement qu'au `curl`.

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

1. **Sécuriser les correctifs du dépôt du site.** `E31-010`, `E33-002`,
   `E33-004` sont écrits et **non committés** dans `Axion-IA/axionia`, sur une
   branche qui n'est pas la leur. Patch de secours :
   `04_PREUVES/agent-35/site-non-committe/`. Vérifier d'abord qu'aucune autre
   session ne travaille dans cette copie.
2. **Pousser les 2 commits du 23/08** (`d9205b5`, `e41a034`) — la branche locale
   est en avance sur son distant.
3. **Demander à Will l'ouverture de la PR** de `fix/gardes-de-plan-et-c19-010`.
   ⚠️ Le dépôt est **public** et le §A00 de `06_RESTE-WILL.md` garde la trace
   d'un agent qui a poussé malgré trois refus : **ne pas l'ouvrir sans réponse.**
4. **Lever les quatre verrous d'infrastructure** (§5, V5 → V6 → V3′ → V7) et
   **ouvrir enfin les 39 écrans à la main** — c'est l'exigence n° 3 du §12 du
   mandat, la seule jamais entamée.
5. **Lancer la vague du dépôt du site** pour les 11 constats reportés.
6. Attaquer les **47 S1 ouverts**, puis les 168 S2.
7. Poser à Will les arbitrages de `ARBITRAGES.md`, famille par famille.

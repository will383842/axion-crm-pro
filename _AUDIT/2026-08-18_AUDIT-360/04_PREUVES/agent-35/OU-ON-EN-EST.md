# OÙ ON EN EST — état vivant, à lire en premier

> **À quoi sert ce fichier.** Il est réécrit à chaque étape pour qu'une coupure
> ne coûte rien. Il dit ce qui est prouvé, ce qui attend Will, ce qui est à
> moitié fait, et par quoi reprendre — dans cet ordre.
>
> Récit détaillé : `REPRISE-ETAT.md` (§14 à §16). Registre des constats :
> `FILE-DE-TRAVAIL.md`. Verdicts bruts de la vague de vérification :
> `verdicts-420.json`. Ce qui revient à Will : `ARBITRAGES.md`.

**Dernière mise à jour : 2026-08-23.**

---

## 0. REPRENDRE EN TROIS COMMANDES

```bash
# 1. Où en est le code
cd C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth && git log --oneline -8

# 2. Rendre le banc honnête AVANT toute suite (sans quoi douze gardes mentent)
bash infra/scripts/rafraichir-le-banc.sh a35r

# 3. Reprendre la vérification là où elle s'est arrêtée
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
| branche | `fix/gardes-de-plan-et-c19-010` @ `44cb33e` — **poussée** |
| copie principale (journaux) | `Axion-CRM-Pro`, branche `audit/360-p1-p2` @ `a21361b` |
| conteneur du banc | `a35r` · base de test `axion_crm_test` (forcée par `phpunit.xml`) |

**Aucun fichier non committé nulle part.** Les deux dépôts sont propres.

### Fusionné et déployé en production

| PR | contenu | état |
|---|---|---|
| **#192** | correctif Caddy + sonde `A05-001` | fusionnée `41688a9`, déployée |
| **#193** | 3 gardes de plan, `C19-010`, canal Telegram, `G43-004` | fusionnée `5087f1e`, déployée |

### En cours, non fusionné

La branche `fix/gardes-de-plan-et-c19-010` porte **la vague 2** : 91 correctifs
écrits par 20 agents, plus les six défauts trouvés en les vérifiant. **Pas encore
de PR ouverte** — elle attend la fin de la vérification backend.

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
S1            61         7       48     116
S2            36         0      220     256
S3             6         0       82      88
────────────────────────────────────────────
TOTAL        119        10      356     485
```

⚠️ **Ce tableau SOUS-ESTIME le travail fait.** Les **91 correctifs de la vague 2**
n'y sont pas inscrits : je ne compte un constat fermé qu'une fois sa garde jouée
verte. C'est le premier geste à faire en reprenant.

### D'où viennent les 119 fermés

- **55** fermés par des correctifs, vagues 1 à 14.
- **64** fermés par **lecture**, le 2026-08-22 : trente vérificateurs ont établi
  que le registre les déclarait ouverts **à tort**. Chacun porte une citation
  `fichier:ligne` ; trois ont été revérifiés à la main (`A08-001`, `B14-005`,
  `B12-006`) avant d'y toucher.

---

## 4. LA VÉRIFICATION DE LA VAGUE 2 — où elle en est

| contrôle | verdict |
|---|---|
| Syntaxe PHP, 73 fichiers | ✅ |
| **PHPStan niveau 8**, tout le dépôt | ✅ **aucune erreur** |
| Pint | ✅ |
| `tsc --noEmit` | ✅ |
| **Frontend : 59 fichiers, 412 tests** | ✅ **tous verts** |
| Backend `tests/Feature/Infra` (147 tests) | ✅ verts |
| Backend `Crm`, `Database`, `Console`, `Commands` | ⏳ **à jouer** |
| Backend `Rgpd`, `Auth`, `Controllers`, … | ⏳ **à jouer** |
| Backend `Unit` + racine de `Feature` | ⏳ **à jouer** |
| Workers (`workers/`) | ⏳ **à jouer** |

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
| 5 | chaque S0/S1 corrigé | 🟡 **6 S0 et 48 S1 ouverts** |
| 9 | les **57 alertes de vulnérabilité** arbitrées ou gelées | ❌ toujours 57 |
| 10 | aucune route 501 | ❌ `I48-001` ouvert |
| 13 | **une sauvegarde restaurée pour de vrai** | ❌ aucune trace |
| 14 | plus rien de sévérité ≥ S2 | ❌ 220 S2, 82 S3 |

🔴 **Le plus gros manque est structurel.** Les 39 écrans n'ont jamais été
ouverts dans un navigateur — et ils ne peuvent pas l'être : le rapport final
établit que **personne ne s'est jamais connecté au CRM**, quatre verrous se
refermant l'un sur l'autre. Tant qu'ils tiennent, tout est mesuré au `curl`.
*C'est probablement le prochain vrai chantier.*

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

1. **Rafraîchir le banc** (`infra/scripts/rafraichir-le-banc.sh a35r`).
2. **Finir la vérification backend** par tranches courtes — `Crm`, `Database`,
   `Console`, `Commands`, puis `Rgpd`, `Auth`, `Controllers`, puis `Unit` et la
   racine de `Feature`, puis les workers.
3. **Inscrire les 91 correctifs au registre** une fois leurs gardes vertes.
4. **Ouvrir la PR** de `fix/gardes-de-plan-et-c19-010` et la faire fusionner.
5. **Lancer la vague du dépôt du site** pour les 11 constats reportés.
6. Attaquer les **48 S1 ouverts**, puis les S2.
7. Poser à Will les arbitrages de `ARBITRAGES.md`, famille par famille.

# OÙ ON EN EST — état vivant de la session

> **À quoi sert ce fichier.** Il est réécrit à chaque étape pour qu'une fermeture
> brutale de Claude Code ne coûte rien. Il dit ce qui est fait, ce qui est en
> vol, ce qui attend Will, et par quoi reprendre. Le récit détaillé est dans
> `REPRISE-ETAT.md` (§14 à §16) ; le registre des constats dans
> `FILE-DE-TRAVAIL.md`.

**Dernière mise à jour : 2026-08-21, vague 15.**

---

## 1. Ce qui est FAIT et vérifié

### En production, mesuré sur la vraie base

| chose | preuve |
|---|---|
| `A05-001` fermé | `crm:remplir-cle-personne` → **410 481 fiches**, 83 lots ; sonde verte, code 0 |
| Correctif Caddy prouvé | déploiement du 21/08 : 502 pendant 4 min 20, retour en 200 **16 s après** le redémarrage automatique de Caddy — sans geste manuel |
| `CRM_PERSON_KEY_SECRET` posé | `strlen()` = 64 dans le conteneur |
| Fiche 360° branchée | route `api/v1/crm/persons/{key}/timeline` → **401** (existe, protégée) ; chemin voisin → 404 |

### Fusionné dans `main`

- **PR #192** — correctif Caddy + sonde `A05-001`. Fusionnée (`41688a9`), déployée, `Deploy OK`.

### En attente de fusion — **PR #193** (branche `fix/gardes-de-plan-et-c19-010`)

| commit | contenu |
|---|---|
| `f39a1b2` | six gardes de plan jouées sur une fiche vide — jeu d'essai de 200 fiches |
| `818a97a` | témoin dont la prémisse était fausse (« sur une ligne, Postgres balaie ») |
| `777f31a` | `C19-010` — la purge ne reconnaissait qu'une forme de marquage sur trois |
| `d482b36` | correction de récit : le smoke test AVAIT détecté le 502 |
| `1506b47` | `C18-016` — trois jobs échappent à la garde anti-simulacre (inventaire figé) |
| `9c1b30e` | `A05-001` — la sonde dit désormais sa COUVERTURE |
| `c6d615a` | **canal Telegram** — service, `AnomalyDetect`, étape CI, 18 gardes |

Chaque garde a été **vue rouge avant d'être verte**, par une mutation réelle.

---

## 2. Ce qui ATTEND WILL

### a. Telegram — deux valeurs à fournir (ne les envoyer dans aucun message)

1. **@BotFather** → `/newbot` → jeton `123456789:AAF…`
2. Créer un canal, y ajouter le bot comme **admin**, y écrire un message, puis
   `https://api.telegram.org/bot<TOKEN>/getUpdates` → `chat.id` en `-100…`
3. Sur le serveur :
   ```
   ssh root@46.62.248.239 "cd /opt/axion-crm-pro && cp .env .env.avant-telegram && sed -i '/^TELEGRAM_/d' .env && printf 'TELEGRAM_BOT_TOKEN=…\nTELEGRAM_CHAT_ID=…\n' >> .env && grep -c '^TELEGRAM_' .env"
   ```
   → doit afficher **2**, puis **recréer** les conteneurs (un `restart` ne relit
   pas `env_file` — constat `A07-003`).
4. Les **mêmes valeurs** en secrets de dépôt GitHub (*Settings → Secrets and
   variables → Actions*), pour l'alerte de déploiement.

### b. Fusionner #193 quand la CI est verte

```
cd C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth && gh pr merge 193 --merge
```

### c. Quatre arbitrages qui n'appartiennent qu'à lui

| # | question | ce que la mesure dit |
|---|---|---|
| 1 | **le scraping doit-il être vivant en production ?** | les 4,29 M d'entreprises viennent de l'**import INSEE**, pas du scraping. Les trois scrapers Node (`pages-jaunes`, `website`, `google-search`) ont **0 run depuis toujours**. Rien n'est cassé : c'est un allumage, pas une réparation. Et `TwoCaptchaSolver::solve()` **lève une exception** — il n'est pas écrit. |
| 2 | où doit partir l'alerte d'un déploiement rouge ? | **tranché : Telegram.** En cours d'implémentation. |
| 3 | `C19-010` / fiches sans dénomination | **sans objet aujourd'hui : 0 fiche concernée** en production. Un guetteur est posé à la place. |
| 4 | `G41-002` — recherche multi-champs | corriger changerait ce que la recherche trouve (157 ms → 2 ms). Décision produit. |

---

## 3. Ce qui est EN VOL au moment de l'écriture

- **CI de #193** en cours.
- **`CrmSondeNonDiffusibles`** — guetteur `C19-010` écrit, **pas encore testé ni
  committé**. Reste : les gardes, la planification dans `routes/console.php`,
  Pint/PHPStan, commit.

---

## 4. L'état de l'audit, chiffré

```
          fermés  partiels  ouverts   total
S0            16         3        6      25
S1            39         7       70     116
S2             0         0      256     256
S3             0         0       88      88
────────────────────────────────────────────
TOTAL         55        10      420     485
```

**Les 344 constats S2 et S3 n'ont jamais été ouverts** — la campagne a
délibérément commencé par le haut. L'audit **n'est pas terminé**.

---

## 5. Les erreurs que j'ai commises dans cette vague

Elles sont ici parce qu'elles se répéteront si personne ne les lit.

1. **J'ai muté la mauvaise base** (`axion_crm` au lieu de `axion_crm_test`, que
   `phpunit.xml` force), puis j'ai expliqué l'échec de ces mutations par une
   cause plausible **non vérifiée**.
2. **Deux échecs de test que j'avais fabriqués** : j'avais lancé la même tranche
   deux fois, et les deux processus refaisaient la base l'un sous l'autre.
3. **Un commit annonçait un texte qu'il ne portait pas** — script d'écriture
   échoué, non vérifié avant de committer.
4. **`git branch` crée sans basculer** : six commits partis sur la mauvaise
   branche, et trois `git push` qui ne poussaient rien — masqués par un filtre
   `grep` sur la sortie.
5. **J'ai deviné un seuil** de témoin à 300 fichiers là où `app/` en compte 294.
6. **`toHaveKey($clé, $message)`** — le second argument est une valeur attendue,
   pas un message. Troisième matcher de cette famille après `toContain`.
7. **`expectsOutputToContain`** compare ligne par ligne : le formateur coupait le
   message et l'assertion rougissait sur une sortie exacte.

*Les sept ont été rattrapées par une mesure, aucune par un raisonnement.*

---

## 6. Par quoi reprendre, si la session se ferme maintenant

1. Finir `CrmSondeNonDiffusibles` : gardes, planification, Pint/PHPStan, commit.
2. Vérifier la CI de #193 et la faire fusionner par Will.
3. Quand Will aura posé les jetons Telegram : **essai réel** d'envoi.
4. Reprendre l'audit là où il s'est arrêté : **6 S0 ouverts**, puis les 70 S1.

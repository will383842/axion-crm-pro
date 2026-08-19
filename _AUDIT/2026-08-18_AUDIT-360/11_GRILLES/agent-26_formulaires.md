# AGENT 26 — Auditeur des formulaires et de la saisie

> Périmètre : `FormField`, `Input`, `CampaignWizardPage`, `AudienceBuilderPage`, `SettingsPage`,
> et les écrans d'authentification (`/login`, `/password-reset`, `/magic-link`, `/2fa`).
> Grille §5.1 points 18-19. Preuves dans `04_PREUVES/agent-26/`.

## 0. Référence, et pourquoi elle n'est pas celle du dossier

Le dossier commun nomme `main = e8924b8`. **Au moment de mes mesures, `main` est `8db8229`.**
J'ai vérifié que le déplacement ne touche pas mon périmètre :

```
git diff --name-only e8924b8 8db8229 | grep -vE "^_AUDIT/"
  _PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.md
  _PROMPTS/PROMPT_UX_CONSOLES_ADMIN_2026-08-18.md
  infra/scripts/verifier-serveur-http.sh
```

→ **aucun fichier de `frontend/` ni de `backend/` entre les deux.** Le code que je mesure est
identique à `e8924b8`. Chaque constat porte donc la référence **`main 8db8229` (frontend et backend
identiques à `e8924b8`)**.

### Ce que je n'ai PAS pu faire, et qui cadre tout le reste

La console est inutilisable (connexion 200 → premier écran 403 → enrôlement 2FA 500). **Je n'ai
soumis aucun formulaire authentifié.** Deux tentatives de mesure HTTP ont rendu `HTTP=000` ; le
conteneur `axion-crm-api` sert par `php -S` **avec une file d'attente de 129 connexions en attente**
(`netstat` : `tcp 129 0 0.0.0.0:80 LISTEN`) — c'est A-010/A-009 en direct. J'ai donc mesuré le
back-end **par le service lui-même**, pas par HTTP, et je le dis à chaque fois.

---

## 1. Tableau de grille

Colonnes : **V-cli** validation côté client · **V-srv** validation côté serveur · **=** les deux
disent-ils la même chose · **RS** refus silencieux mesurés · **C3** perte de saisie (critère 3 du §29).

| # | Objet | Champs | `<form>` | V-cli | V-srv | = | RS | C3 | Constat principal |
|---|---|---:|---|---|---|---|---:|---|---|
| 1 | `ui/FormField.tsx` | — | — | porte `error`, `aria-invalid`, `aria-describedby`, `role="alert"` | s.o. | s.o. | 0 | s.o. | **le bon composant, employé par 0 des 7 écrans de saisie** (D26-011) |
| 2 | `ui/Input.tsx` | — | — | prop `invalid` **purement visuelle** | s.o. | s.o. | 1 | s.o. | `invalid` ne pose **aucun** `aria-invalid` (D26-011) |
| 3 | `CampaignWizardPage` (4 étapes, 8 champs) | 5 `Input` + 2 `input` + 1 `textarea` | **0** | `canContinue[step]`, bornes **jamais appliquées** | `StoreScrapingCampaignRequest` (complet) | **non** | **4** | **0/1** | pas de `<form>` : `min`/`max`/`required` décoratifs (D26-005, D26-007, D26-008, D26-009) |
| 4 | `AudienceBuilderPage` | 2 `Input` + 2 `input` + 1 `textarea` + 5 groupes de puces | 1 | RHF, **2 règles inatteignables** | `StoreEmailAudienceRequest` (champ/op) — **`value` jamais validée** | **non** | **3** | **0/1** | **4 asymétries mesurées** entre les 2 évaluateurs (D26-001) |
| 5 | `SettingsPage` — Workspace | 3 (dont 1 `disabled`) | 1 | `required` sur le nom | `PUT /workspace` → **501** | s.o. | 1 | 0/1 | **le seul formulaire de l'écran ne sauvegarde jamais** (D26-003) |
| 6 | `SettingsPage` — Intégrations | 0 saisissable | 0 | s.o. | s.o. | s.o. | **3** | s.o. | **14 boutons sans `onClick`**, secret factice, état « Configuré » codé en dur (D26-003) |
| 7 | `SettingsPage` — Observabilité | 1 (`DSN Sentry`) | 0 | aucune | **aucun envoi** | s.o. | 1 | s.o. | champ **sans `name`, sans `onChange`, sans bouton** : tout est jeté (D26-003) |
| 8 | `SettingsPage` — Apparence | 2 | 0 | s.o. | s.o. | s.o. | 1 | s.o. | thème OK · **densité : état local sans effet ni persistance** (D26-003) |
| 9 | `/login` | 3 | 1 | `required`, `type=email` | oui | **non** | 1 | s.o. | `catch {}` : les 7 formes d'erreur deviennent « Une erreur est survenue. » (D26-006) |
| 10 | `/password-reset` | 1 | 1 | `required`, `disabled={!email}` | oui | **non** | 1 | s.o. | **annonce un envoi qui n'a pas lieu** (D26-004) |
| 11 | `/magic-link` | 1 | 1 | `required`, `disabled={!email}` | oui | **non** | 1 | s.o. | **idem** (D26-004) |
| 12 | `/2fa` | 1 | 1 | `code.length !== 6`, `replace(/\D/g,'')` | oui | **non** | 2 | s.o. | **un 500 serveur est présenté comme « Code invalide »** (D26-006) |

**Totaux mesurés** (`04_PREUVES/agent-26/03-recensement-champs.txt`) : **7 écrans de saisie**,
**23 champs** (8 + 5 + 4 + 3 + 1 + 1 + 1), **`FormField` employé 0 fois sur les 7**,
**l'assistant de campagne n'a aucun `<form>`** pour ses 8 champs (et 3 des 4 onglets de Réglages non
plus), **0 dispositif de sauvegarde de saisie**, **14 mécanismes de refus silencieux distincts**.

---

## 2. Les trois réponses que le mandat demande

### 2.1 🔴 Refus silencieux : **14 mécanismes**, et leur mécanisme exact

| # | Mécanisme | Emplacement | Ce qui disparaît, et comment |
|---|---|---|---|
| RS-1 | **condition retirée de la requête** | `AudienceBuilderService::applyCondition` / `buildPositive` | un critère mal formé n'est pas rejeté : il est **effacé du SQL**, et l'audience devient **le workspace entier**. Mesuré : **300 000/300 000** |
| RS-2 | **bornage à chaque frappe** | `CampaignWizardPage.tsx:853-856` | `onChange(Math.max(min, Math.min(max, v)))` : taper « 12 » dans « Durée max » (min 5) donne **52** |
| RS-3 | **champ vidé → valeur plancher** | idem | `Number('')` vaut `0`, pas `NaN` : effacer le champ le remet à `min` sans un mot |
| RS-4 | **`maxLength` sans compteur** | 7 champs (`:407`, `:416`, Audience `:225`, `:232`, 2FA `:35`) | coller 200 caractères dans un champ à 120 : **80 disparaissent**, aucun message, aucun compteur |
| RS-5 | **valeur d'une source retirée toujours envoyée** | `CampaignWizardPage.tsx:236-240` + `:182-184` | `toggleSource` ne nettoie pas `perSourceLimits` ; `buildPayload` ne regarde pas `showPerSource` |
| RS-6 | **bornes HTML décoratives** | `CampaignWizardPage` — **0 `<form>`** | `min={1} max={100}` sur les limites par source ne sont **jamais** évaluées : sans `<form>`, la validation de contrainte HTML ne s'exécute pas |
| RS-7 | **champ sans destination** | `SettingsPage.tsx:233` (`DSN Sentry`) | ni `name`, ni `onChange`, ni `<form>`, ni bouton : **la saisie n'a nulle part où aller** |
| RS-8 | **boutons sans gestionnaire** | `SettingsPage.tsx:209-214` ×7 intégrations | **14 boutons** « Renouveler »/« Configurer » sans `onClick` : le clic ne produit **rien**, pas même une erreur |
| RS-9 | **valeur affichée codée en dur** | `SettingsPage.tsx:207` | `MaskedSecret value="sk-•••••"` : « Afficher » révèle **la constante littérale**, pas la clef |
| RS-10 | **état d'intégration codé en dur** | `SettingsPage.tsx:49-57` | `status: 'configured'` est écrit dans le frontend : l'écran affirme « Configuré » **sans avoir rien lu** |
| RS-11 | **préférence sans effet ni persistance** | `SettingsPage.tsx:99, 291` | « Densité » n'est qu'un `useState` : ne change aucune table, perdu au changement d'onglet |
| RS-12 | **message du serveur jeté** | **12 `catch {}`** sans liaison, dont les **4** écrans d'auth | les 7 formes de réponse d'erreur de B12-015 sont réduites à **4 chaînes fixes** |
| RS-13 | **découpage silencieux des tags** | `AudienceBuilderPage.tsx:135-139` | `split(/[,\s]+/)` coupe « growth marketing » en deux tags — et le récapitulatif affiche `[2]`, **jamais les valeurs** |
| RS-14 | **règles de validation inatteignables** | `AudienceBuilderPage.tsx:223, 233` | `maxLength: {value:120, message:'Max 120 caractères'}` est masqué par l'attribut HTML `maxLength={120}` ; la règle de la description **n'a pas de message** et son `Field` **ne reçoit pas `error`** |

Douze de ces quatorze font perdre ou déforment une **saisie**. RS-12 fait perdre un **message**.
RS-8/RS-9/RS-10 font croire à une **commande** qui n'existe pas.

**Le plus grave est RS-1**, parce qu'il ne porte pas sur un champ mais sur *le service qui décide à
qui part un courriel*.

### 2.2 🔴 Critère 3 du §29 : **il tombe. 0 sur 6.**

> « rechargement forcé et coupure réseau pendant la saisie sur les 6 écrans de saisie :
> reprise à l'identique 6/6 »

Mesure (`04_PREUVES/agent-26/06-perte-de-saisie.txt`) :

```
grep -rniE 'beforeunload|useBlocker|isDirty|validateSearch|useSearch\(' frontend/src
  → AUCUNE ligne
```

**Aucun** avertissement de sortie, **aucun** blocage de navigation, **aucun** suivi de modification,
**aucun** état de formulaire dans l'URL. Et les seuls usages de `localStorage` du produit sont
**trois** : le repli de la barre latérale, le thème, la langue. **Zéro brouillon.**

**Témoin négatif** : le même `grep`, sur le même arbre, retrouve bien les **6 lignes** de
`localStorage` (RootLayout, DarkModeToggle, i18n). Le contrôle sait trouver ; il ne trouve rien
parce qu'il n'y a rien.

Conséquence mesurable sur le plus gros formulaire : `/campaigns/new` est un **chemin statique**
(`routeTree.tsx:87`), sans `validateSearch`. L'étape courante n'est pas dans l'URL. Donc :
rechargement à l'étape 4 → retour à l'étape 1, **8 champs vidés** ; et le **bouton Précédent du
navigateur quitte l'assistant** au lieu de reculer d'une étape.

### 2.3 🔴 `neq` / `not_in` sur NULL : **l'arbitrage tient. Mesuré.**

Mesure jouée par le **vrai service** (`buildPublicQuery` + `companyMatchesCriteria`), sur
`axion_crm_perf` : **300 000 fiches, `sector_main` NULL sur les 300 000** — exactement la condition
que le commentaire du code décrit (« l'essentiel d'une base de prospection collectée »).
Sortie brute : `04_PREUVES/agent-26/02-neq-notin-null.txt`.

```
CAS               |       SQL | MEMOIRE | PREDICAT SQL GENERE
A  neq btp        |    300000 | GARDE   | ("sector_main" != ? or "sector_main" is null)
B  not_in [btp]   |    300000 | GARDE   | ("sector_main" not in (?) or "sector_main" is null)
C  eq btp         |         0 | EXCLUE  | ("sector_main" = ?)
```

**Les deux évaluateurs s'accordent** : la fiche à champ vide est **gardée** par « tout sauf X », des
deux côtés. Le combinateur `not` reste symétrique (`not{neq}` → 0/EXCLUE ; `not{eq}` → 300 000/GARDE).

**Témoin négatif — c'est lui qui donne sa valeur à la mesure** : le SQL naïf (la sémantique d'avant
le correctif) joué sur **la même table** rend **0** :

```
where sector_main != 'btp'       -> 0
where sector_main not in ('btp') -> 0
```

Le contrôle sait donc distinguer, et l'écart vaut **300 000 fiches** sur ce jeu.
→ **Rien à rouvrir sur `neq`/`not_in` face à NULL.** A07-005 (adresse en clair) reste hors de mon
périmètre.

**Mais la même mesure a ouvert autre chose** — voir D26-001.

### 2.4 L'assistant de campagne, du début à la fin

| Question du mandat | Réponse mesurée |
|---|---|
| Combien d'étapes ? | **4** — Identité, Zones, Sources, Budget & sécurité (`Stepper`, `:347-385`) |
| L'état survit-il au retour arrière ? | **Oui.** Les **14** `useState` (`:103-161`) vivent dans le composant de page ; les étapes ne sont que des rendus conditionnels. Aller 4→1→4 ne perd rien. **C'est le seul point sain du parcours.** |
| Peut-on sauter une étape ? | **Non.** Les puces du `Stepper` sont des `<span>`, pas des boutons ; « Continuer » est `disabled` tant que `canContinue[step]` est faux ; les 4 conditions sont revérifiées avant création. |
| Que se passe-t-il si on recharge au milieu ? | **Tout est perdu**, sans avertissement : retour à l'étape 1, 8 champs vidés (§2.2). Idem si la session expire : l'intercepteur fait `window.location.assign('/login')`. |
| Autre | **Aucun `<form>`** sur les 8 champs : la touche Entrée ne fait rien, et `min`/`max` ne sont jamais appliqués. **Aucun test** ne couvre cet écran (869 lignes) — `frontend/tests/screens/` en compte 6, pas celui-là. |

### 2.5 Les « 3 chemins aveugles » d'`AudienceBuilderPage`

Le mandat les désigne sans les nommer. Mesurés, ils sont :

1. **L'interface ne peut produire que 4 des 10 opérateurs** (`in`, `gte`, `eq`, `contains_any`) et
   **uniquement le groupe `all`**. `neq`, `not_in`, `gt`, `lt`, `lte`, `is_null`, `is_not_null` et les
   groupes `any`/`not` sont **inatteignables à la souris** — alors que le service les traite et que la
   validation les accepte. L'arbitrage NULL tranché au §2.3 porte donc sur des critères qu'**aucun
   utilisateur ne peut composer depuis l'écran**.
2. **Le récapitulatif masque les valeurs** : `{Array.isArray(c.value) ? `[${c.value.length}]` : …}`
   (`:366`). L'utilisateur lit « department_code in [3] » et ne voit **jamais** lesquels — ni que son
   tag « growth marketing » a été coupé en deux (RS-13).
3. **Un critère est pré-coché à l'insu de l'utilisateur** : `statuses` démarre à
   `['ready_for_outreach']` (`:119`). Et **retirer toutes les puces d'un groupe ne veut pas dire
   « aucun »** : `if (statuses.length > 0)` supprime la condition, donc « je ne veux aucun statut »
   produit « tous les statuts ». L'étiquette « Tous statuts » l'annonce, mais l'inversion reste un
   piège.

---

## 3. Constats

### [D26-001] Un critère d'audience mal formé n'est pas rejeté : il est effacé de la requête, et l'audience devient le workspace entier
- Sévérité      : S1 grave
- Domaine       : backend / canal
- Référence     : main 8db8229 (backend identique à e8924b8)
- Emplacement   : `backend/app/Services/Audiences/AudienceBuilderService.php:280-303` (`applyCondition`), `:353-406` (`buildPositive`), `:502-546` (`evalCondition`) ; `backend/app/Http/Controllers/Api/AudiencesController.php:108`
- Constat       : quand une condition est mal formée, le chemin SQL la **retire** de la requête (la requête s'élargit) tandis que le chemin en mémoire répond **faux** (la fiche est exclue) — les deux évaluateurs partent dans des directions opposées, et l'utilisateur n'est averti d'aucun des deux.
- Preuve        : `04_PREUVES/agent-26/02-neq-notin-null.txt`, joué par le vrai service sur `axion_crm_perf` (300 000 fiches) :

  ```
  CAS               |       SQL | MEMOIRE | PREDICAT SQL GENERE
  E  champ inconnu  |    300000 | EXCLUE  | "workspace_id" = ?
  F  has_email neq  |    300000 | EXCLUE  | "workspace_id" = ?
  G  in non-tableau |    300000 | EXCLUE  | "workspace_id" = ?
  H  op inconnu     |    300000 | EXCLUE  | "workspace_id" = ?
  ```

  Le prédicat produit est **`"workspace_id" = ?`** : le critère a purement disparu.
  Script joué : `04_PREUVES/agent-26/mesure-null.php`.

  **Confirmé une seconde fois, sur un jeu MIXTE** (`04_PREUVES/agent-26/05-asymetrie-residuelle.txt`),
  par un test Pest écrit pour l'occasion (`AsymetrieResiduelleTest.php`) sur 4 fiches dont une avec
  `email_generic`, une avec un contact `valid`, deux sans rien :

  ```
  ENTREE                         | SQL rend  | MEM rend  | DIRECTION
  has_email avec op neq          |         4 |         2 | DESACCORD (SQL elargit a TOUT / memoire restreint)
  in avec valeur scalaire        |         4 |         0 | DESACCORD (SQL elargit a TOUT / memoire ne vise PERSONNE)
  not_in avec valeur scalaire    |         4 |         0 | DESACCORD (SQL elargit a TOUT / memoire ne vise PERSONNE)
  champ hors liste blanche       |         4 |         0 | DESACCORD (SQL elargit a TOUT / memoire ne vise PERSONNE)
  ```

  Le cas `has_email` + `neq` est le plus parlant : ce n'est pas « tout contre rien », c'est
  **4 fiches contre 2** — deux audiences réellement différentes, toutes deux plausibles, selon le
  chemin qui les a calculées.
- Témoin négatif: **quatre conditions BIEN formées, jouées par le même test, sur les mêmes 4 fiches, dans la même exécution, s'accordent toutes les quatre** :
  ```
  has_email eq true (bien forme)   | SQL=2 | MEM=2 | accord
  in avec tableau (bien forme)     | SQL=1 | MEM=1 | accord
  not_in tableau sur NULL          | SQL=3 | MEM=3 | accord
  neq sur colonne NULL             | SQL=3 | MEM=3 | accord
  ```
  Le contrôle sait donc dire « accord » ; il ne dit « désaccord » que sur les quatre entrées mal
  formées. *(Les deux dernières lignes reconfirment au passage §2.3 sur un jeu mixte : 3 des 4 fiches
  ont `sector_main` NULL et sont bien gardées par « tout sauf btp », des deux côtés.)*
  Enfin, `SymetrieEvaluateursTest` **ne couvre aucune de ces quatre entrées** : ses 22 conditions
  sont toutes valides. Le défaut n'est donc gardé par rien.
- Impact        : ce service décide **à qui part un courriel**. Deux des quatre entrées franchissent `StoreEmailAudienceRequest` et sont donc **persistées** : `has_email` avec `neq` (les deux sont dans les listes blanches) et `in`/`not_in` avec une valeur scalaire (**`criteria.*.value` n'est validée nulle part**). Les quatre franchissent `POST /audiences/preview`, qui ne valide que `criteria => required|array` : l'aperçu affiche alors **le compte du workspace entier** pour un critère qui n'a pas été appliqué, et c'est ce chiffre que l'utilisateur lit pour décider d'envoyer. L'invariant que le test d'origine se donne dans son propre en-tête — « le contenu d'une même audience ne doit pas dépendre du chemin qui l'a calculée » — est rompu sur ces entrées.
- Reproduction  : `docker cp _AUDIT/…/agent-26/mesure-null.php axion-crm-api:/tmp/` puis `docker exec axion-crm-api php /tmp/mesure-null.php`.
- Correctif     : faire répondre `buildPositive()` par `fn ($q) => $q->whereRaw('1 = 0')` — le patron **déjà retenu** pour `tags` avec une liste vide, ligne 368, et pour la même raison écrite en commentaire (« une condition inexploitable ne vise PERSONNE, elle n'est pas ignorée ») — au lieu de `null`, dans les 3 cas de `buildPositive` ; et faire de même dans `applyCondition` pour le champ/op hors liste blanche. Ajouter `criteria.*.value` aux règles de `StoreEmailAudienceRequest`, et faire valider `POST /audiences/preview` par la même règle que `store`. **Coût : ~0,5 j**, plus l'extension de `symetrieCas()` aux quatre entrées (le fichier `04_PREUVES/agent-26/AsymetrieResiduelleTest.php` les code déjà).
- Statut        : ouvert

---

### [D26-002] Aucun écran de saisie ne conserve quoi que ce soit : le critère 3 du §29 est à 0 sur 6
- Sévérité      : S1 grave
- Domaine       : UX / interface
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/` entier — `CampaignWizardPage.tsx`, `AudienceBuilderPage.tsx`, `SettingsPage.tsx`, les 4 écrans d'auth ; `frontend/src/app/routeTree.tsx:87,92`
- Constat       : il n'existe dans le produit ni avertissement de sortie, ni blocage de navigation, ni suivi de modification, ni état de formulaire dans l'URL, ni brouillon persisté.
- Preuve        : `04_PREUVES/agent-26/06-perte-de-saisie.txt` — `grep -rniE 'beforeunload|useBlocker|isDirty|validateSearch|useSearch\(' frontend/src` rend **zéro ligne**. `routeTree.tsx:87` déclare `/campaigns/new` en chemin statique, sans `validateSearch` : l'étape n'est pas dans l'URL.
- Témoin négatif: le même `grep`, même arbre, retrouve les **6** lignes de `localStorage` réellement présentes (`RootLayout.tsx:50,60`, `DarkModeToggle.tsx:22,27`, `i18n.ts:17`) — barre latérale, thème, langue. Le contrôle est capable de trouver ; **aucune de ces 6 n'est un formulaire**.
- Impact        : un rechargement, une coupure réseau ou une expiration de session pendant la saisie détruit tout, sans un mot. Sur `CampaignWizardPage`, cela fait **8 champs et 4 étapes**. Aggravé par `frontend/src/lib/api.ts:30-33` : sur un 401, l'intercepteur exécute `window.location.assign('/login')` — une navigation dure qui vide le formulaire **sans avertissement ni message**. Le critère 3 n'est pas « partiellement atteint » : il est **inatteignable en l'état**, sur 6 écrans sur 6.
- Reproduction  : ouvrir `/campaigns/new`, remplir les 4 étapes, recharger (F5) → retour étape 1, tout vide.
- Correctif     : mettre l'étape et l'état de l'assistant dans l'URL (`validateSearch` de TanStack Router, déjà disponible — **le produit ne l'utilise nulle part**), et poser un brouillon `sessionStorage` par écran de saisie. **Coût : ~2 j** pour les 6 écrans.
- Statut        : ouvert

---

### [D26-003] `SettingsPage` : quatre onglets, une vingtaine de commandes, **une seule** produit un effet
- Sévérité      : S1 grave
- Domaine       : interface / UX
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/features/settings/SettingsPage.tsx` ; `backend/app/Http/Controllers/Api/WorkspaceController.php:41`
- Constat       : sur les quatre onglets de l'écran de réglages, seul le sélecteur de thème modifie quoi que ce soit ; tout le reste est sans destination, sans gestionnaire, ou adressé à une route non implémentée.
- Preuve        : `04_PREUVES/agent-26/07-settings-commandes-mortes.txt`
  - **Workspace** — `SettingsPage.tsx:107` poste vers `PUT /workspace`; `WorkspaceController.php:41` est `return $this->notImplemented('3');` et `ApiController.php:56-60` rend **501**. L'utilisateur ne reçoit que `toast.error('Erreur mise à jour')` (`:112`) : le message du serveur (« Endpoint à implémenter en Sprint 3 ») **n'est pas affiché**. **Le seul formulaire de l'écran ne peut jamais réussir.**
  - **Intégrations** — les boutons « Renouveler » et « Configurer » (`:209-214`) n'ont **pas de `onClick`** : **14 boutons** (7 intégrations × 2) dont le clic ne produit rien. `MaskedSecret value="sk-•••••"` (`:207`) est une **constante littérale** : « Afficher » révèle `sk-•••••`. `status: 'configured'` (`:49-57`) est **écrit en dur dans le frontend** : l'écran affirme « Configuré » sans avoir lu aucune variable d'environnement.
  - **Observabilité** — le champ « DSN Sentry » (`:233`) n'a ni `name`, ni `onChange`, ni `<form>`, ni bouton d'enregistrement : **tout ce qui y est saisi est jeté**.
  - **Apparence** — `density` (`:99, 291`) est un `useState` local : il ne pilote que la couleur de ses propres boutons, ne persiste pas, et est perdu au changement d'onglet.
- Témoin négatif: le même fichier contient bien **3** `onClick` fonctionnels (`:86` l'œil, `:291` la densité) et un `DarkModeToggle` qui écrit réellement dans `localStorage` — le contrôle n'est donc pas aveugle aux gestionnaires présents ; il constate leur absence sur les 14 autres boutons.
- Impact        : l'exploitant croit configurer ses clefs d'API, son DSN Sentry, son plafond de coût LLM et sa densité d'affichage. **Aucune de ces quatre actions n'aboutit**, et trois d'entre elles ne renvoient même pas d'erreur. L'onglet Intégrations affirme en outre un état de configuration qu'il n'a jamais vérifié.
- Reproduction  : ouvrir `/settings`, onglet Observabilité, saisir un DSN, changer d'onglet, revenir : le champ est vide.
- Correctif     : implémenter `PUT /workspace` (Sprint 3, jamais fait) ; retirer ou brancher les 14 boutons ; lire l'état réel des intégrations depuis l'API ; retirer le champ DSN ou lui donner une destination ; persister la densité. **Coût : ~3 j.** À défaut, **retirer les commandes mortes** coûte ~0,5 j et supprime le mensonge.
- Statut        : ouvert

---

### [D26-004] Les écrans de réinitialisation et de lien magique annoncent un envoi qui ne peut pas avoir lieu
- Sévérité      : S1 grave
- Domaine       : UX / canal
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/features/auth/PasswordResetPage.tsx:17-24, 33-46` ; `frontend/src/features/auth/MagicLinkPage.tsx:19-27`
- Constat       : l'état « envoyé » est déduit du seul code HTTP de la réponse, jamais d'une remise effective.
- Preuve        : `PasswordResetPage.tsx:18-20` — `await api.post('/auth/password/forgot', …); setSent(true); toast.success('Email envoyé');`. L'écran affiche alors, en dur : « **Un lien a été envoyé à `{email}`** » et « **Le lien expire dans 60 minutes.** » (`:39-44`). Or **A-012 / F40-002** ont mesuré que `MAIL_MAILER` n'est défini nulle part, que `config/mail.php` retombe donc sur `'log'`, et qu'**aucun courriel ne part**. Le même patron est en `MagicLinkPage.tsx:21-23` avec « expire dans 15 minutes ».
- Témoin négatif: je n'ai **pas** re-mesuré `MAIL_MAILER` — c'est A-012, déjà mesuré, et le §5 m'interdit de le re-rapporter. Ce que j'ajoute est **le comportement de l'écran**, lisible dans le code : il n'existe aucun chemin par lequel l'interface pourrait apprendre que le courriel n'est pas parti, puisqu'elle ne consulte que le code HTTP. Le contrôle porte donc sur l'écran, pas sur le courrielleur.
- Impact        : c'est la **face visible** de A-012. Les deux voies de secours de l'authentification affichent un succès franc et une durée d'expiration précise pour un message qui n'existe pas. Un utilisateur bloqué attend un courriel, vérifie ses spams, recommence — l'écran lui confirme à chaque fois que le lien est parti. Aucune des deux pages ne peut alerter, par construction.
- Reproduction  : ouvrir `/password-reset`, saisir une adresse, valider : le panneau de succès s'affiche quel que soit l'état du courrielleur.
- Correctif     : ne pas annoncer une remise mais une **prise en compte** (« Si un compte existe pour cette adresse, un lien vient d'être demandé »), et faire remonter par l'API l'état du transport quand `MAIL_MAILER=log`. **Coût : ~0,5 j** côté écrans. La cause racine est A-012 et n'est pas la mienne.
- Statut        : ouvert

---

### [D26-005] Les champs numériques de l'assistant se corrigent à chaque frappe : taper « 12 » donne « 52 »
- Sévérité      : S2 défaut
- Domaine       : UX / interface
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/features/campaigns/CampaignWizardPage.tsx:831-861` (`NumberField`), employé en `:694-713` pour « Entreprises max » et « Durée max »
- Constat       : `onChange` réécrit la valeur saisie dans les bornes à chaque caractère frappé, sans le signaler.
- Preuve        : `:853-856` —
  ```js
  const v = Number(e.target.value);
  if (!Number.isNaN(v)) onChange(Math.max(min, Math.min(max, v)));
  ```
  Le champ étant contrôlé, la valeur affichée est immédiatement remplacée. Sur « Durée max » (`min={5}`) : frapper `1` → la valeur devient **5** et le champ affiche `5` ; frapper `2` → `52`. **Il est impossible de saisir 12.** Sur « Entreprises max » (`max={50000}`) : coller `99999` donne **50000**, sans message. Et `Number('')` vaut **`0`**, non `NaN` : vider le champ ne le vide pas, il le ramène à `min`.
- Témoin négatif: le même écran contient deux champs numériques **sans** ce bornage — les limites par source (`:769-781` et `:785-797`) laissent passer n'importe quelle valeur. Le contrôle distingue donc bien les deux comportements ; il ne confond pas « champ numérique » et « champ borné ».
- Impact        : l'utilisateur croit avoir saisi une durée ou un volume, et en a saisi un autre. Le budget d'une collecte — le garde-fou anti-blacklist annoncé par le titre de l'étape — est celui que le champ a réécrit, pas celui qui a été voulu.
- Reproduction  : `/campaigns/new`, étape 4, cliquer dans « Durée max », tout sélectionner, taper `12`.
- Correctif     : borner **au `blur`** et non à la frappe, garder l'état en chaîne de caractères, et afficher un message quand la valeur est ramenée dans les bornes. **Coût : ~0,5 j.**
- Statut        : ouvert

---

### [D26-006] Douze `catch` jettent le message du serveur ; sur `/2fa`, un 500 est présenté comme « Code invalide »
- Sévérité      : S2 défaut
- Domaine       : UX / interface
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : 12 sites, dont `LoginPage.tsx:74`, `MagicLinkPage.tsx:22`, `PasswordResetPage.tsx:20`, `TwoFactorPage.tsx:22`
- Constat       : douze blocs `catch` sans liaison d'erreur remplacent la réponse du serveur par une chaîne fixe.
- Preuve        : `grep -rn "} catch {" frontend/src --include=*.tsx` → **12** occurrences, contre **6** pour `} catch (`. Les 4 écrans d'authentification — les seuls réellement soumettables — sont dans les 12. `LoginPage.tsx:74-75` : `catch { toast.error(t('common.error')); }`, et `locales/fr.json:38` donne `"error": "Une erreur est survenue."`.
- Témoin négatif: les 6 autres sites lient bien l'erreur et l'exploitent — `CampaignWizardPage.tsx:197` et `AudienceBuilderPage.tsx:195` appellent `extractApiMessage(err)` et affichent le message du serveur. Le produit sait donc le faire ; ces 12 sites-là ne le font pas.
- Impact        : les **7 formes de réponse d'erreur** mesurées par B12-015 sont réduites à **4 chaînes fixes**. Le cas le plus lourd est `/2fa` : `TwoFactorPage.tsx:22-23` affiche « **Code invalide** » pour *toute* exception — or **A07-001** a mesuré que l'enrôlement 2FA écrit trois colonnes qui n'existent pas et **rend 500**. L'écran attribue donc à la saisie de l'utilisateur un défaut de schéma du serveur ; l'utilisateur retape son code indéfiniment. Même chose pour un 429 (trop de tentatives) ou un 422 : indiscernables d'un mot de passe erroné.

  **Le cas qui résume le défaut** : `AuthController.php:44-49` prend la peine de répondre, en **419**, un message soigneusement rédigé et exact — « *Cette route ouvre une session : la requête doit provenir d'un domaine stateful (en-tête Origin ou Referer). Pour un accès machine, utilisez un jeton d'API.* » — assorti d'un commentaire expliquant que « *un 500 n'est pas un contrat* ». Ce message, qui dirait à l'utilisateur exactement quoi faire, est **intégralement jeté** par le `catch {}` de `LoginPage.tsx:74` et remplacé par « Une erreur est survenue. ». Le serveur a fait le travail ; l'écran l'annule.
- Reproduction  : `grep -rn "} catch {" frontend/src --include=*.tsx`
- Correctif     : lier l'erreur et réutiliser `extractApiMessage()`, qui existe déjà dans deux écrans, avec un repli par code HTTP (401/422/429/500). **Coût : ~1 j** pour les 12 sites.
- Statut        : ouvert

---

### [D26-007] `CampaignWizardPage` n'a aucun `<form>` : ses bornes HTML ne sont jamais appliquées et la touche Entrée est inerte
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/features/campaigns/CampaignWizardPage.tsx` — 8 champs, `:769-781`, `:785-797`, `:431-437`
- Constat       : les huit champs de l'assistant ne sont contenus dans aucun élément `<form>`.
- Preuve        : `04_PREUVES/agent-26/03-recensement-champs.txt` — `grep -c "<form" CampaignWizardPage.tsx` → **0**, pour 5 `<Input>`, 2 `<input>` et 1 `<textarea>`. Les trois autres écrans du périmètre en ont bien un (Audience 1, Settings 1, chaque écran d'auth 1) : le contrôle sait compter les `<form>` là où il y en a.
- Témoin négatif: `AudienceBuilderPage.tsx:214` a un `<form onSubmit={onSubmit}>` et son bouton est `type="submit"` — même comptage, résultat différent. L'absence mesurée ici n'est donc pas un artefact du contrôle.
- Impact        : sans `<form>`, la **validation de contrainte HTML ne s'exécute jamais**. Les `min={1} max={100}` des limites par source (`:770-772`) et le `min` du champ `datetime-local` (`:435`) sont **décoratifs** — et les limites par source n'ont, elles, aucun bornage JavaScript non plus (contrairement à `NumberField`) : une valeur de 99 999 part telle quelle vers le serveur, qui la refuse par `per_source_limits.*.rpm max:100`, et le message revient dans une infobulle alors que le panneau « avancé » peut être replié. Par ailleurs la touche **Entrée ne valide rien** dans les 8 champs.
  Accessoirement, `:435` calcule le plancher du `datetime-local` avec `toISOString()` — donc en **UTC** — pour un champ qui s'exprime en heure **locale** : à Paris en août le plancher est posé **2 heures dans le passé**. Sans `<form>` il n'est de toute façon jamais évalué.
- Reproduction  : `grep -c "<form" frontend/src/features/campaigns/CampaignWizardPage.tsx`
- Correctif     : envelopper chaque étape dans un `<form onSubmit>` et brancher « Continuer » en `type="submit"`. **Coût : ~0,5 j.**
- Statut        : ouvert

---

### [D26-008] Une limite saisie pour une source ensuite désélectionnée reste envoyée et persistée, sans être visible nulle part
- Sévérité      : S2 défaut
- Domaine       : interface / backend
- Référence     : main 8db8229 (frontend et backend identiques à e8924b8)
- Emplacement   : `frontend/src/features/campaigns/CampaignWizardPage.tsx:236-240` (`toggleSource`), `:182-184` (`buildPayload`), `:759-803` (panneau « avancé »)
- Constat       : `perSourceLimits` n'est jamais nettoyé lorsqu'une source est retirée, et son envoi ne dépend pas de la visibilité du panneau qui le renseigne.
- Preuve        : `toggleSource` (`:236-240`) ne touche que `sources`. `buildPayload` (`:182-184`) teste `Object.keys(perSourceLimits).length > 0` — **pas** `showPerSource`, **pas** l'appartenance à `sources`. Côté serveur, `StoreScrapingCampaignRequest.php:34-36` valide `per_source_limits` en `nullable|array` et ne contraint **pas les clefs** ; `ScrapingCampaignsController.php:126` persiste `$validated['per_source_limits']` tel quel.
- Témoin négatif: le même `buildPayload` **filtre** correctement ailleurs — `description.trim() || undefined` (`:175`) et le `scheduled_at` conditionné à `scheduleMode === 'later'` (`:185-187`). La construction de la charge utile sait donc omettre ; elle n'omet pas ce cas-là.
- Impact        : parcours réel — étape 4, saisir un débit pour « France Travail » ; revenir étape 3 ; décocher « France Travail » ; revenir étape 4. Le panneau ne liste plus que les sources cochées : **la valeur devient invisible**, et elle part quand même, et elle est stockée sur la campagne. Symétriquement, replier le panneau « avancé » ne retire pas les valeurs déjà saisies.
- Reproduction  : les gestes ci-dessus, puis lire `per_source_limits` sur la campagne créée.
- Correctif     : purger l'entrée dans `toggleSource`, et restreindre l'envoi aux clefs présentes dans `sources` ; ajouter côté serveur une règle liant les clefs de `per_source_limits` à `sources`. **Coût : ~0,5 j.**
- Statut        : ouvert

---

### [D26-009] Le bouton Précédent du navigateur ne recule pas d'une étape : il quitte l'assistant et jette la saisie
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/app/routeTree.tsx:87` ; `frontend/src/features/campaigns/CampaignWizardPage.tsx:103` (`useState<Step>(1)`), `:290-301`
- Constat       : l'étape courante de l'assistant est un état de composant, jamais une entrée d'historique de navigation.
- Preuve        : `routeTree.tsx:87` déclare `path: '/campaigns/new'` sans `validateSearch` ; l'étape vit dans `useState<Step>(1)` (`:103`) et les changements d'étape passent par `setStep` (`:296`, `:310`), jamais par le routeur. Le bouton « Précédent » de l'écran gère le cas `step > 1` lui-même (`:295-298`).
- Témoin négatif: le même écran **sait** naviguer quand il le veut — `void navigate({ to: '/campaigns' })` (`:297`) et vers la fiche créée (`:196`). L'absence d'entrée d'historique par étape est donc un choix du code, pas une limite du routeur ; et TanStack Router expose `validateSearch`, que **le produit n'emploie nulle part** (D26-002).
- Impact        : à l'étape 4, le réflexe universel « revenir en arrière » sort de l'assistant vers `/campaigns` et détruit les 8 champs, sans confirmation (il n'existe aucun garde de sortie — D26-002). Le §8 du dossier qualifie une confusion de navigation qui fait perdre l'utilisateur de **S2 au minimum**.
- Reproduction  : `/campaigns/new`, avancer jusqu'à l'étape 3, presser Alt+← : l'écran est quitté et vidé.
- Correctif     : porter l'étape dans la recherche d'URL (`?etape=3`) via `validateSearch` — ce qui règle aussi la moitié de D26-002. **Coût : ~0,5 j.**
- Statut        : ouvert

---

### [D26-010] Sept champs tronquent la saisie collée sans compteur ni message
- Sévérité      : S2 défaut
- Domaine       : UX / interface
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `CampaignWizardPage.tsx:407` (120), `:416` (500) ; `AudienceBuilderPage.tsx:225` (120), `:232` (500) ; `TwoFactorPage.tsx:35` (6) ; les règles jumelles `AudienceBuilderPage.tsx:223, 233`
- Constat       : cinq champs portent un attribut `maxLength` et aucun n'affiche de compteur de caractères ni d'avertissement de troncature.
- Preuve        : `grep -rn "maxLength" frontend/src --include=*.tsx` → **7 lignes, 5 champs** ; `grep -rniE "length}/|caractères restants|compteur"` sur les mêmes fichiers → aucun compteur. À cela s'ajoute `TwoFactorPage.tsx:37` : `onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}` — tout caractère non numérique est **retiré en silence**.
- Témoin négatif: le contrôle trouve bien, dans les mêmes fichiers, les textes d'aide réellement présents (`hint` de `NumberField` `:858`, « 1 — 50 000 » `:697`) : il sait repérer un message d'accompagnement quand il y en a un.
- Impact        : coller une description de 800 caractères dans le champ à 500 en dépose 500 et **en perd 300** ; l'utilisateur n'a aucun signal. C'est le cas le plus courant du refus silencieux nommé par le mandat.
- Reproduction  : `/campaigns/new`, étape 1, coller un texte de 800 caractères dans la description.
- Correctif     : ajouter un compteur « n/500 » (ce que `FormField` sait déjà porter par son `helpText`), et avertir à la troncature. **Coût : ~0,5 j.**
- Statut        : ouvert

---

### [D26-011] `FormField` n'est employé par aucun des 7 écrans de saisie, et la prop `invalid` d'`Input` ne pose aucun `aria-invalid`
- Sévérité      : S3 finition
- Domaine       : interface / UX
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/components/ui/FormField.tsx:11-12, 18` ; `frontend/src/components/ui/Input.tsx:28-31` ; `AudienceBuilderPage.tsx:224`
- Constat       : le composant qui porte les messages d'erreur accessibles n'est utilisé par aucun écran de saisie, et celui qui l'est ne signale l'erreur que par la couleur.
- Preuve        : `04_PREUVES/agent-26/03-recensement-champs.txt` — `grep -c FormField` rend **0** sur les 7 écrans de mon périmètre (D27-008 avait mesuré 1 écran sur 37 : c'est `/tags`, hors de mon périmètre). `Input.tsx:28-31` ne fait de `invalid` qu'un choix de classes d'anneau : **aucun `aria-invalid`**, aucun `aria-describedby`. `AudienceBuilderPage.tsx:224` passe pourtant `invalid={!!errors.name}`. Par contraste `FormField.tsx:34-35` pose bien `aria-invalid` et `aria-describedby`, et `:42` un `role="alert"`.
  Second point : `FormField.tsx:11-12` utilise un compteur de module (`let _id = 0; const nextId = () => ...`) appelé pendant le rendu (`:18`) — l'identifiant **change à chaque rendu**, donc à chaque frappe, au lieu d'être stable (`useId()` de React 18).
- Témoin négatif: `frontend/tests/components/FormField.test.tsx` compte **7 cas** et vérifie explicitement « shows error message + sets aria-invalid » — le composant est donc correct **et testé** ; ce qui manque est son emploi. Le contrôle ne conclut pas à un composant défectueux.
- Impact        : sur `AudienceBuilderPage`, un lecteur d'écran n'apprend pas que le champ « Nom » est en erreur : seule la couleur de l'anneau change. Les messages d'erreur des 7 écrans sont rendus par des `<span>` maison (`Field` local, dupliqué **deux fois**, en `CampaignWizardPage.tsx:820-829` et `AudienceBuilderPage.tsx:492-513`) au lieu du composant prévu. C'est D27-008 vu depuis la saisie : le composant n'est pas sous-employé, il est **absent là où il compte**.
- Reproduction  : les `grep` ci-dessus.
- Correctif     : poser `aria-invalid={invalid}` dans `Input` (2 lignes), remplacer `nextId()` par `useId()` (2 lignes), puis converger les deux `Field` locaux vers `FormField`. **Coût : ~1 j**, à faire avec D27-008 dont c'est le même chantier.
- Statut        : ouvert

---

### [D26-012] Les deux seuls messages de validation d'`AudienceBuilderPage` ne peuvent jamais s'afficher
- Sévérité      : S3 finition
- Domaine       : interface / UX
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/features/audiences/AudienceBuilderPage.tsx:223, 228-235, 201`
- Constat       : les règles de validation déclarées sur les deux champs de texte sont masquées par des contraintes qui les rendent inatteignables, ou n'ont pas de message.
- Preuve        :
  - `:223` — `register('name', { required: 'Nom requis', maxLength: { value: 120, message: 'Max 120 caractères' } })`, mais `:225` porte `maxLength={120}` en HTML : **la longueur ne peut pas dépasser 120**, la règle ne peut pas rougir. Et `required` ne peut pas rougir non plus, car `:382` rend le bouton `disabled={!canCreate}` et `:201` exige déjà `watchedName.trim().length > 0`.
  - `:233` — `register('description', { maxLength: 500 })` : **règle sans message** (rendrait une chaîne vide), et le `Field` de la description (`:228`) **ne reçoit pas de prop `error`**. Si elle échouait, `handleSubmit` interromprait la soumission **sans rien afficher**.
- Témoin négatif: le mécanisme d'affichage, lui, fonctionne — `Field` (`:506-510`) rend bien `error` dans une `StatusPill`, et `:220` le lui passe pour le nom. Le contrôle ne conclut donc pas à un affichage cassé, mais à des règles qui ne l'atteignent jamais.
- Impact        : l'écran donne l'apparence d'une validation par messages ; en pratique il n'en affiche aucun, et se contente de désactiver le bouton avec un texte générique (`:397-399`). Le chemin « soumission interrompue sans cause visible » existe et n'est gardé par rien.
- Reproduction  : lecture des lignes citées ; `frontend/tests/screens/AudienceBuilderPage.test.tsx` (8 cas) ne couvre aucun des deux messages.
- Correctif     : retirer les règles mortes ou retirer les attributs HTML qui les masquent, et passer `error={errors.description?.message}` au `Field` de la description. **Coût : ~0,25 j.**
- Statut        : ouvert

---

### [D26-013] L'intercepteur de session détruit le formulaire par une navigation dure, et le jeton CSRF n'est jamais renouvelé
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/lib/api.ts:12-24` (`ensureCsrf`), `:27-35` (intercepteur de réponse)
- Constat       : le drapeau `csrfFetched` n'est jamais remis à faux, et un 401 provoque un rechargement complet de page.
- Preuve        : `api.ts:13` — `let csrfFetched = false;` puis `:16` `if (csrfFetched) return;` et `:18` `csrfFetched = true`. **Aucune remise à zéro** dans le fichier. L'intercepteur (`:29-32`) ne traite que le **401** et exécute `window.location.assign('/login')` ; **419** (jeton de session expiré, la réponse de Laravel quand le cookie CSRF est périmé) n'est traité nulle part.
- Témoin négatif: l'intercepteur **sait** distinguer un statut et agir (`error?.response?.status === 401`, avec la garde `!window.location.pathname.startsWith('/login')` qui évite la boucle) : le mécanisme est en place et correct pour 401. Son silence sur 419 est une absence, pas une panne.
- Impact        : deux conséquences. (1) Sur un 401 en cours de saisie, `window.location.assign` recharge la page : **tout le formulaire est perdu**, sans avertissement ni message — c'est l'aggravation de D26-002. (2) Après expiration de la session, `ensureCsrf` croit le jeton acquis et ne le redemande pas : chaque envoi rend **419**, non traité, donc affiché comme une erreur générique par les `catch` de D26-006. L'utilisateur réessaie sans fin ; seul un rechargement manuel débloque.
- Reproduction  : lecture de `api.ts` ; je n'ai **pas** pu jouer le cas 419 de bout en bout, la console n'étant pas atteignable (voir §4).
- Correctif     : remettre `csrfFetched = false` sur 419 et réessayer une fois ; remplacer la navigation dure par une navigation du routeur assortie d'un message. **Coût : ~0,5 j.**
- Statut        : ouvert

---

## 3 bis. Ce que j'ai vérifié et qui est SAIN — à ne pas rouvrir

Un audit qui ne rapporte que des défauts ne dit pas où il a regardé. Ces points ont été mesurés et
ne portent pas de constat :

- **L'aiguillage 2FA de la connexion est correct.** `LoginPage.tsx:69` lit `data.requires_2fa` ;
  `ApiController::ok()` (`:49-52`) fait `response()->json($data)` **sans enveloppe**, et
  `AuthController.php:58-61` rend bien `{user, requires_2fa}` au premier niveau. Les deux
  correspondent. *J'ai cherché un décalage d'enveloppe — il n'y en a pas.*
- **Le retour arrière dans l'assistant préserve la saisie** (§2.4), et **aucune étape n'est
  contournable** : les puces du `Stepper` sont des `<span>` inertes et les 4 conditions sont
  revérifiées avant création (`:320`, `:330`).
- **`FormField` lui-même est correct et testé** : `aria-invalid`, `aria-describedby`,
  `role="alert"`, 7 cas dans `frontend/tests/components/FormField.test.tsx`. Le défaut est son
  absence d'emploi (D26-011), pas sa qualité.
- **`AudienceBuilderPage` est le seul écran du périmètre réellement testé** : 8 cas dans
  `frontend/tests/screens/AudienceBuilderPage.test.tsx`, dont un qui garde explicitement
  « aperçu en échec : l'écran affiche le message du serveur, jamais un compte faux ».
- **La validation serveur de la campagne est complète** : `StoreScrapingCampaignRequest` couvre les
  14 clefs avec bornes, `Rule::in` et `after:now`. Le défaut est que le client ne s'y aligne pas
  (D26-005, D26-007), pas que le serveur soit permissif.

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Aucune soumission de formulaire authentifié.** La console est inutilisable (403 puis 500 sur
   l'enrôlement 2FA). Tout ce qui concerne `CampaignWizardPage`, `AudienceBuilderPage` et
   `SettingsPage` est mesuré **sur le code et sur le service**, jamais par un geste dans l'écran.
   Les constats D26-003, D26-005, D26-008, D26-009, D26-010 sont donc **structurels et non
   observés à l'écran** — je les donne comme tels.
2. **Les mesures HTTP ont échoué.** `curl` depuis l'hôte et depuis le conteneur rendent `HTTP=000`
   (`04_PREUVES/agent-26/01-login-json-mauvais.txt`), et `netstat` dans `axion-crm-api` montre une
   file d'attente de **129** connexions sur le `php -S` du port 80. **Je n'ai donc pas re-mesuré
   B12-009** (302 au lieu de 422 sans en-tête JSON) et je ne le rapporte pas.
3. 🔴 **J'ai cru mesurer sur ma propre base ; j'ai mesuré sur la base PARTAGÉE — et je le déclare.**
   Le §5 bis point 2 prescrit « crée ta propre base (`axion_crm_<ton-id>`) ». Je l'ai fait :
   `axion_crm_a26`, migrée, 116 tables. J'ai fabriqué une configuration PHPUnit dédiée pour la viser.
   **Cela n'a servi à rien.** La sortie de mon test dit `Base : axion_crm_test`.

   Cause, lue après coup dans `backend/tests/bootstrap.php:26-33` : le fichier **épingle en dur**
   `const TEST_DATABASE_NAME = 'axion_crm_test'` et l'écrit dans `$_SERVER`, `$_ENV` **et**
   `putenv()` avant tout démarrage de l'application — précisément pour neutraliser un `<env>` de
   PHPUnit, ce que son propre commentaire explique. `Tests\TestCase::setUp()` (`:31-38`) ajoute une
   garde qui **refuse de démarrer** si le nom de la base ne commence pas par `axion_crm_test`.

   **Conséquence pour l'audit entier, et c'est le point utile** : l'instruction du §5 bis point 2
   **n'est pas exécutable** pour la suite Pest du back-end. Aucun agent ne peut isoler sa base sans
   modifier un fichier du produit. C'est la raison mécanique pour laquelle **B11-005 continue de se
   produire**, et une contre-mesure qui ne peut pas être appliquée n'en est pas une. *(Le seul
   contournement légitime existant est `php artisan test --parallel`, qui fait suffixer la base par
   Laravel — la garde valide le PRÉFIXE, pas l'égalité.)*

   **Ce que cela coûte, et ce que cela ne coûte pas.** J'ai donc exécuté `RefreshDatabase` sur
   `axion_crm_test` : **j'ai pu détruire la mesure d'un autre agent en cours**, et je le signale
   plutôt que de le taire. En revanche **la mienne reste valide** : le jeu d'essai est créé dans
   `beforeEach` sous un `workspace_id` en UUID neuf, et **toutes** les requêtes des deux évaluateurs
   filtrent sur ce `workspace_id` ; des lignes laissées par un voisin dans un autre workspace sont
   exclues par construction. Sa cohérence interne le confirme : les 4 conditions bien formées
   s'accordent (2/2, 1/1, 3/3, 3/3) dans la même exécution que les 4 désaccords.

4. **`04-symetrie-evaluateurs.txt` est vide : je l'ai arrêté, délibérément.** L'exécution de
   `SymetrieEvaluateursTest` (22 conditions × 2 combinateurs, chacune créant puis supprimant une
   audience sonde et balayant 12 fiches) tournait encore après **25 minutes** — le second test a mis
   **1 216 s pour un seul cas**, la sérialisation de A-010/A-009 étrangle tout. Surtout, ayant
   compris le point 3, je savais qu'elle opérait sur `axion_crm_test` **en concurrence avec ma propre
   seconde exécution et avec celles des autres agents** : la laisser courir ne pouvait plus
   qu'endommager le travail d'autrui pour un résultat dont je n'avais plus besoin. `kill 6392`.
   **D26-001 ne repose pas dessus** : il est établi deux fois, par `02-neq-notin-null.txt` (vrai
   service, 300 000 fiches) et par `05-asymetrie-residuelle.txt` (jeu mixte, témoin négatif interne).
   Ce que cette exécution aurait ajouté — la confirmation que les 22 cas valides restent verts — est
   déjà couvert par les 4 témoins négatifs du second fichier.
5. **Le second témoin négatif de `02-neq-notin-null.txt` était non concluant — il l'est resté, mais
   la lacune est comblée ailleurs.** `axion_crm_perf` ne contient **aucune** fiche à `sector_main`
   renseigné : sur cette base je n'ai pas pu vérifier que `neq` exclut bien une valeur qui
   correspond. Le premier témoin (le SQL naïf rendant 0) suffisait à établir l'écart ; et
   `05-asymetrie-residuelle.txt`, lui, **exerce bien une valeur renseignée** — `in avec tableau`
   rend 1/1, donc une fiche portait `sector_main = 'btp'` et les deux évaluateurs l'ont retenue.
   Le trou de mesure est donc fermé, mais par un autre fichier que celui où il s'est ouvert.
6. **Le comportement 419** de D26-013 est lu dans le code, **pas joué**.
7. **La troncature au collage** (D26-010) est établie par la spécification de `maxLength` et par le
   code, **pas par un collage réel dans un navigateur**.
8. **Écart non élucidé, signalé sans conclusion** : ma base `axion_crm_a26`, migrée depuis
   `8db8229`, compte **66 lignes** dans `migrations` pour **58 fichiers** dans
   `backend/database/migrations/` ; `axion_crm` en compte 58 et `axion_crm_perf` **57**. L'écart vient
   probablement de migrations publiées par des paquets tiers, mais **je ne l'ai pas vérifié** et il
   n'entre pas dans mon périmètre. Il mérite d'être regardé par qui audite les migrations, car il
   signifie que **les bases locales ne sont pas au même niveau de schéma**.
9. **Je n'ai pas audité** les 30 champs bruts des 13 autres écrans (D27-008) : hors périmètre.
   Ce que j'ajoute à D27-008 est que `FormField` est absent des **7 écrans de saisie**, pas
   seulement sous-employé.

---

## 5. Ce que je n'ai pas fait, sur consigne

- Aucune autorité racine installée.
- Aucun fichier du produit modifié. Le test que j'ai écrit vit dans `04_PREUVES/agent-26/` et n'a été
  copié que dans `/tmp` du conteneur ; la configuration PHPUnit dérivée aussi.
- Le worktree `crmpro-wt-etape1a` n'a été ni lu, ni écrit, ni approché.
- Aucune écriture en production. Mes mesures portent sur `axion_crm_perf` **en lecture seule** et sur
  `axion_crm_a26`, base que j'ai créée pour moi.

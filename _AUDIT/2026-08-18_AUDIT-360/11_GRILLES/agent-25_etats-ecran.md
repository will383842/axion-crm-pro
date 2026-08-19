# Grille des états d'écran — AGENT 25 « Auditeur des états d'écran »

- **Référence** : `main = 8db8229` (relue par `git rev-parse HEAD` au début **et** à la fin de la mission ;
  `8db8229` = « audit(360): P0 a P2 — 24 agents rendus »). L'agent 22 a mesuré sur `e8924b8` et vérifié
  qu'aucune ligne de `frontend/` ne bouge entre les deux : **nos deux grilles portent sur le même code frontend**.
- **Périmètre** : les **37 écrans** de `src/app/routeTree.tsx` (inventaire de l'agent 22, recompté ici :
  37 `createRoute`, dont 4 hors coquille, 2 bouchons Phase 2, 1 route introuvable).
- **Écrans effectivement montés et mesurés** : **37 / 37**, sous **4 conditions réseau chacun** = **148 montages réels**,
  plus 7 montages « état partiel », 5 « hors ligne », 3 « retour arrière », 25 « volumes », 1 « délai ».
- **Cases non vérifiables** : **4** sur 185 (les 4 conditions de `/coverage`), raison nommée au §6.

---

## 0. Méthode — et pourquoi elle est plus fiable ici qu'un navigateur

### 0.1 Ce que je n'ai pas fait, et pourquoi

L'agent 22 a établi que **Chrome refuse `https://app.localhost`** (autorité Caddy absente du magasin Windows)
et qu'**installer une autorité racine est une modification de sécurité du poste** : il ne l'a pas faite, et **je ne
l'ai pas faite non plus**. Sa méthode de contournement (`04_PREUVES/agent-22/03-comment-les-ecrans-ont-ete-ouverts.txt`)
ouvre un conteneur Caddy temporaire en clair — elle marche, mais elle a un prix qu'il déclare lui-même :
depuis cette origine, **tous** les appels d'API échouent en bloc (`Failed to fetch`). On obtient donc **une seule**
des cinq conditions, et on ne peut pas choisir laquelle.

Or ma grille porte précisément sur **cinq états**. Il me fallait pouvoir décider, écran par écran et requête par
requête, si le serveur répond **200 avec une collection vide**, **500**, **403**, **rien du tout**, ou **une source
sur deux**. Un navigateur branché sur un atelier saturé (**A-009**) ne le permet pas.

### 0.2 Ce que j'ai fait : monter les 37 écrans, pour de vrai, dans le harnais du dépôt

Le dépôt possède déjà un harnais de montage d'écran de qualité — **je l'ai étendu, je ne l'ai pas réinventé**
(règle 8) : `tests/helpers/renderScreen.tsx` (vrai routeur TanStack en mémoire, vrai i18n français, vraie coquille
en option) et `tests/msw/handlers.ts` (**MSW intercepte au niveau réseau**, donc `src/lib/api.ts` — intercepteur 401,
CSRF, sérialisation axios — **reste dans le chemin testé** ; un `vi.mock('@/lib/api')` l'aurait retiré).

Pour chaque écran, quatre conditions successives :

| condition | ce que fait le serveur | l'état de la grille qu'elle révèle |
|---|---|---|
| **EN-VOL** | accepte, ne répond jamais | ⏳ chargement (squelette ? saut ? rien ?) |
| **VIDE** | `200` + collection vide | ∅ vide (dessiné ? dit quoi faire ?) |
| **ERREUR** | `500` | ⚠ erreur (langage courant ? cause ? action ?) |
| **REFUS** | `403` | ⛔ permission refusée (explicite ? cul-de-sac ?) |

puis, à part, **partiel** (une source répond, l'autre tombe), **hors ligne** (`onlineManager.setOnline(false)`),
**volumes** (0 / 1 / 100 / 10 000 / 100 000 lignes) et **retour arrière** (geste d'historique réel).

On relève à chaque fois **le texte intégral du DOM** et **six mesures de structure** (`animate-pulse`, `animate-spin`,
`aria-busy`, nombre de nœuds, taille du HTML, nombre de boutons). Le classement automatique n'est **jamais** le
verdict : chaque case du tableau ci-dessous a été **relue sur le texte brut**, et cinq classements automatiques ont
été **corrigés à la main** (§0.4).

Sorties brutes : `04_PREUVES/agent-25/` — `releve-etats.txt` (57 Ko, les 148 relevés mot pour mot),
`synthese-etats.txt`, `releve-volumes.txt`, `releve-partiel.txt`, `releve-horsligne.txt`,
`releve-retour.txt`, `releve-delai.txt`, et les sources des cinq bancs de mesure.

### 0.3 Ce que cette méthode NE mesure pas — dit avant les résultats

1. **Le rendu visuel.** jsdom ne peint pas. Je ne mesure donc **ni** le saut de mise en page en pixels, **ni** la
   lisibilité. Ce que je mesure de la mise en page est **structurel** : « le squelette a-t-il la forme de ce qu'il
   remplace » se lit dans le code (§4.5), pas au chronomètre.
2. **Les durées de rendu** (§3) sont des durées de **construction de DOM par React**, pas de peinture. **Le nombre de
   nœuds, lui, est exact** : c'est celui que le navigateur devrait peindre. C'est lui qui porte le verdict.
3. **L'état nominal avec de vraies données de production.** Il reste hors d'atteinte pour les raisons déjà au
   dossier — **A07-001** (l'enrôlement 2FA écrit trois colonnes qui n'existent pas), **D22-001** (aucun écran ne
   l'expose), **A-012**, **A05-001**. Je ne rouvre aucun de ces constats.
4. **Le banc lui-même a été mesuré** (§3, ligne « TÉMOIN ») : fabriquer et sérialiser 100 000 lignes coûte
   **604 ms et 44 Mo**. Tout ce qui dépasse est imputable à l'écran, pas à moi.

### 0.4 Les cinq classements automatiques que j'ai corrigés à la main

Le classement cherchait des mots (« impossible », « erreur », « échec »). Il s'est trompé cinq fois, **toujours dans
le même sens** — il a cru voir un état d'erreur là où le mot venait d'un libellé de filtre. Relu et corrigé :

| écran | ce que le classement a dit | ce que le texte dit vraiment | d'où venait le mot |
|---|---|---|---|
| `/scraper-runs` | « erreur explicite » | « Taux de succès **0 %** · **Aucun run** pour l'instant » | l'onglet de filtre « Échec » |
| `/audit-logs` | « erreur explicite » | « **Aucun journal d'audit** » | l'option de sévérité « Erreur » |
| `/llm/proxy-providers` | « erreur explicite » | « **Aucun fournisseur configuré** » | la phrase « dans `.env` **serveur** » |
| `/console/arbitrage` | « contenu, sans marqueur » | « **Rien à arbitrer** » (→ constat **D25-003**) | le mot « Rien » n'était pas cherché |
| `/audiences/new` | « affirme 0 » | « Aucun département » = **espace réservé d'une liste déroulante**, pas une donnée | placeholder de `MultiSelect` |

*Les trois premières corrections vont contre mon propre résultat : sans elles, j'aurais compté 16 écrans menteurs
au lieu de 19.* C'est exactement ce que le §2 règle 3 du dossier demande.

---

## 1. LA GRILLE — 37 écrans × 5 états

Légende : **⏳** chargement · **∅** vide · **⚠** erreur · **⛔** permission refusée · **◐** partiel / hors ligne.
« s.o. » = sans objet (l'écran ne consomme aucune donnée). **Aucune case n'est vide.**

### 1.1 Authentification — 4 écrans, hors coquille

| écran | ⏳ chargement | ∅ vide | ⚠ erreur | ⛔ permission refusée | ◐ partiel / hors ligne | verdict |
|---|---|---|---|---|---|---|
| `/login` | **bouton seul** (`loading` sur *Se connecter*) ; pas de squelette, aucun saut : l'écran est statique | **s.o.** — aucune collection | **toast** `t('common.error')` = « Une erreur est survenue. » — **langage courant OUI, cause NON, action NON** | **s.o.** — écran public | **s.o.** — aucune lecture | **OK** — le seul écran dont l'état d'erreur passe par la traduction prévue pour ça |
| `/2fa` | **bouton seul** | **s.o.** | **toast** « Code invalide » — **cause OUI** (le code), action implicite (ressaisir) | **s.o.** | **s.o.** | **OK sur les états** ; l'écran ne sait qu'*vérifier*, jamais *enrôler* (**D22-001**, non rouvert) |
| `/magic-link` | **bouton seul** | **s.o.** | **toast** « Erreur envoi du lien » — **cause NON** | **s.o.** | **s.o.** | **MENT PAR OMISSION** — succès et échec sont indiscernables pour l'utilisateur, et **A-012** montre qu'aucun courriel ne part |
| `/password-reset` | **bouton seul** | **s.o.** | **toast** « Erreur envoi du lien » — **cause NON** | **s.o.** | **s.o.** | idem (**A-012**) |

### 1.2 Aujourd'hui · Contacts

| écran | ⏳ chargement | ∅ vide | ⚠ erreur | ⛔ permission refusée | ◐ partiel / hors ligne | verdict |
|---|---|---|---|---|---|---|
| `/` | 🔴 **le squelette n'est JAMAIS rendu** — `placeholderData` est un **objet de zéros** (`DashboardPage.tsx:82-92`), donc `isLoading` est toujours faux et `DashboardSkeleton` (l. 243-278) est **du code mort**. Mesuré : `pulse=0`. **Le premier écran du CRM est une grille de zéros, avant toute réponse** (**D25-008**) | **dessiné et utile** : « Aucune entreprise collectée… **Démarrer sur /coverage →** » — dit quoi faire | 🔴 **ABSENT** — rend **le texte strictement identique** à l'état vide | 🔴 **ABSENT** — identique au 500 | ◐ **muet** : `/coverage` KO → la carte « Top départements » affiche **« Aucune donnée de couverture »**, sans dire qu'elle n'a pas pu lire | 🔴 **MENT** (D22-002 ; chiffré ici **D25-002**) — et `/dashboard/stats` est en outre un bouchon de zéros (`routes/api.php:86-99`) |
| `/contacts` | **squelette** `CompaniesTableSkeleton rows={6}` + `isPlaceholderData` (le seul écran à traiter les deux) | **dessiné** : « Aucun contact — Lance l'enrichissement… » | 🔴 **ABSENT** — corps identique. **Seule fuite du produit** : le sous-titre passe de « **0** décideurs » à « **…** décideurs » (`total` indéfini). Un « … » de trois pixels est la seule différence entre une base vide et un serveur mort | 🔴 **ABSENT** | ◐ s.o. (source unique) | 🔴 **MENT** — orphelin conditionnel par ailleurs (**D22-005**) |
| `/companies` | **squelette** `CompaniesTableSkeleton` | **dessiné** : « Aucune entreprise » | 🔴 **ABSENT** — « Pipeline de prospection · **0 entreprises actives** · Total **0** · Enrichies **0 %** » | 🔴 **ABSENT** | ◐ **muet** : `/referentiels/geo` KO → les listes « Toutes régions / Tous départements » restent vides **sans un mot** | 🔴 **MENT** — mais **seul écran qui tient le volume** (§3) |
| `/companies/$companyId` | **sablier** (`Spinner` + « Chargement de la fiche entreprise… ») — texte présent, pas de squelette → saut | **s.o.** (fiche unique) | ⚠ **présent mais FAUX SUR LA CAUSE** : un 500 et un 403 affichent « **Entreprise introuvable · 404 · Cette entreprise n'existe pas ou a été supprimée** » (**D25-005**) | 🔴 **ABSENT** — même écran « 404 » | ◐ s.o. | **DÉFAUT** — accuse la donnée d'être absente quand c'est le serveur qui a refusé |
| `/journalists` | **texte nu** « Chargement… » dans une `Card` — **aucun squelette**, saut garanti | **dessiné** : « Aucun journaliste — la base se remplira quand… » | 🔴 **ABSENT** — « **0 journalistes** · Total **0** · Avec email **0** · Opt-out **0** » | 🔴 **ABSENT** | ◐ s.o. | 🔴 **MENT** |
| `/media` | **texte nu** « Chargement… » | **dessiné** : « Aucun média » | 🔴 **ABSENT** — « **0 médias** · Total **0** · Avec site web **0 %** » | 🔴 **ABSENT** | ◐ s.o. | 🔴 **MENT** |
| `/media/$mediaId` | **sablier sans texte** (`Spinner` seul, `spin=1`, 0 caractère) — l'agent 22 l'a lu « écran blanc » : c'est un sablier muet | **s.o.** | ⚠ **présent mais FAUX SUR LA CAUSE** : « **Média introuvable** · Ce média n'existe pas ou a été supprimé » sur un 500 | 🔴 **ABSENT** | ◐ s.o. | **DÉFAUT** (précise **D22-003**) |

### 1.3 Console CRM v2

| écran | ⏳ chargement | ∅ vide | ⚠ erreur | ⛔ permission refusée | ◐ partiel / hors ligne | verdict |
|---|---|---|---|---|---|---|
| `/console/contacts` | **squelette** `ConsoleListSkeleton` + `isPlaceholderData` (bien traité, commenté) | **dessiné** : « Aucun contact dans cette vue — les fiches arrivent depuis le site » | 🔴 **ABSENT** — « Clients **0** · Prospects **0** · Opportunités **0** · Dormants **0** », 12 compteurs à zéro | 🔴 **ABSENT** au sens des droits. Le seul refus dessiné est celui du **drapeau** (`ConsoleGate`), pas d'une **permission** | ◐ 🔴 **MENT** : compteurs KO + liste OK → **une ligne s'affiche sous « Tous 0 »** (§2.3) | 🔴 **MENT** — et **D22-004** (le drapeau prend l'erreur pour une décision) reste ouvert |
| `/console/vivier` | **squelette** | **dessiné** | 🔴 **ABSENT** — « À qualifier **0** · Présélection **0** · Entretien **0** · Conservés **0** » | ⛔ **le seul refus EXEMPLAIRE du produit** : « Univers vivier candidats non accessible — demandez à un administrateur de vous y rattacher » — **explicite, et pas un cul-de-sac** | ◐ 🔴 **MENT** (même patron) | 🔴 **MENT** sur les données ; **modèle à copier** sur le refus |
| `/console/arbitrage` | **squelette** | **dessiné** : « Rien à arbitrer » | 🔴 **PIRE QUE L'ABSENCE** — affiche « **Rien à arbitrer — Tous les événements entrants ont trouvé leur entreprise.** » : une **conclusion métier fausse**, pas seulement un zéro (**D25-003**) | 🔴 **ABSENT** | ◐ s.o. (source unique) | 🔴 **GRAVE** — c'est l'écran où stationnent **100 %** des leads (**B13-001**) |
| `/console/personnes/$personKey` | **squelette sans texte** (`ConsoleListSkeleton`, 0 caractère) | **dessiné** (timeline vide) | ⚠ **présent mais FAUX SUR LA CAUSE** : « **Fiche introuvable** — cette personne n'existe dans aucun univers accessible » sur un 500 | 🔴 **ABSENT** — le même message sert de refus et de panne | ◐ s.o. | **DÉFAUT** — et **A05-001** rend l'écran inatteignable en pratique |

### 1.4 Collecte

| écran | ⏳ chargement | ∅ vide | ⚠ erreur | ⛔ permission refusée | ◐ partiel / hors ligne | verdict |
|---|---|---|---|---|---|---|
| `/coverage` | **non vérifiable** — jsdom n'a pas de WebGL, `maplibre-gl` lève à l'initialisation (§6.1). Lu dans le code : `isLoading ? 'Chargement…' : …`, **pas de squelette** | **non vérifiable** (même cause) ; lu dans le code : `cells = data ?? []` → 0 % affirmé | **non vérifiable** ; lu dans le code : **aucun `isError`**, donc absent | **non vérifiable** ; **aucun** | **non vérifiable** | **DÉFAUT lu, non mesuré** — mais l'exception a révélé **D25-006** : il n'y a **aucune frontière d'erreur** |
| `/campaigns` | **squelette** `ListSkeleton` (cartes) | **dessiné** : « Aucune campagne — lance ta première en 3 clics » | 🔴 **ABSENT** — « Total **0** campagnes · En cours **0** · Terminées **0** · Entreprises créées **0** » | 🔴 **ABSENT** | ◐ s.o. | 🔴 **MENT** — et actions destructrices sans garde (**D22-006**) |
| `/campaigns/new` | **rendu complet** (formulaire), squelette sur l'étape *Zones* | **s.o.** (formulaire) | ⚠ **toasts** sur les mutations, avec **le message du serveur** (`extractApiMessage`) — **le meilleur du produit** | 🔴 **ABSENT** | ◐ `/coverage` KO → les compteurs de zones valent 0 **sans un mot** | **OK** — l'écran le plus soigné, comme le dit l'agent 22 |
| `/campaigns/$campaignId` | **sablier sans texte** | 🔴 **SABLIER ÉTERNEL** | 🔴 **SABLIER ÉTERNEL** | 🔴 **SABLIER ÉTERNEL** | 🔴 **SABLIER ÉTERNEL** | 🔴 **GRAVE** (**D25-004**) — `if (isLoading \|\| !campaign)` : sur erreur `campaign` reste indéfini, **la condition ne redevient jamais fausse**. 4 actions destructrices masquées |
| `/scraper-runs` | **squelette** `RunsTableSkeleton` — **le seul squelette taillé pour son propre tableau** (7 colonnes, mêmes largeurs) | **dessiné** : « Aucun run — lance ton premier scrape depuis Couverture France » | 🔴 **ABSENT** — « Total **0** · Taux de succès **0 %** · **0 OK / 0 clos** · Échecs **0** · *aucun échec* » | 🔴 **ABSENT** | ◐ s.o. | 🔴 **MENT** — « aucun échec » affirmé alors que la lecture a échoué |
| `/international/roumanie` | **texte nu** « Chargement… » | **dessiné** | ✅ **PRÉSENT** : « **Impossible de charger le vivier Roumanie.** » — **langage courant OUI, cause NON, action NON** | 🔴 **ABSENT** — même message | ◐ s.o. | **UN DES DEUX SEULS ÉCRANS QUI DISENT LA VÉRITÉ** |

### 1.5 Pilotage

| écran | ⏳ chargement | ∅ vide | ⚠ erreur | ⛔ permission refusée | ◐ partiel / hors ligne | verdict |
|---|---|---|---|---|---|---|
| `/audiences` | **squelette** `ListSkeleton` | **dessiné** : « Aucune audience — crée ton premier segment » | 🔴 **ABSENT** — « Total audiences **0** · Actives **0** · Membres cumul **0** » | 🔴 **ABSENT** | ◐ s.o. | 🔴 **MENT** |
| `/audiences/new` | **rendu complet** (formulaire) | **s.o.** — les « Aucun département » sont des **espaces réservés de listes déroulantes**, pas une donnée (corrigé §0.4) | ⚠ **`setPreviewError`** — le **seul** endroit du produit où le message du serveur alimente un **état d'écran** de lecture, et non un toast | 🔴 **ABSENT** | ◐ prévisualisation KO → message dédié | **OK** — second meilleur écran, et **le patron à généraliser** |
| `/audiences/$audienceId` | **sablier sans texte** | 🔴 **SABLIER ÉTERNEL** | 🔴 **SABLIER ÉTERNEL** | 🔴 **SABLIER ÉTERNEL** | 🔴 fiche OK + membres KO → **l'onglet Membres reste un sablier** | 🔴 **GRAVE** (**D25-004**) — `if (isLoading \|\| !audience)`, même faute |
| `/admin/observability` | **texte nu** « Chargement de l'observabilité… » | ∅ **NON DESSINÉ** — l'écran rend ses vignettes à 0 sans état vide propre | ✅ **PRÉSENT** : « **Impossible de charger les métriques d'observabilité.** » — **cause NON, action NON**. ⚠️ **Corrige l'agent 22**, qui l'avait noté « ⚠ absent » : le message existe, mais il met **jusqu'à 93 s** à venir (§2.5) | 🔴 **ABSENT** — même message | ◐ s.o. | **DÉFAUT** — vrai état d'erreur, mais muet pendant une minute et demie |

### 1.6 Conformité

| écran | ⏳ chargement | ∅ vide | ⚠ erreur | ⛔ permission refusée | ◐ partiel / hors ligne | verdict |
|---|---|---|---|---|---|---|
| `/rgpd/requests` | **squelette** générique | **dessiné** : « Aucune requête RGPD » | 🔴 **ABSENT** | 🔴 **ABSENT** | ◐ s.o. | 🔴 **GRAVE** — écran à **obligation légale** qui affirme « aucune requête » quand il n'a pas pu lire |
| `/rgpd/ai-act` | **squelette** générique | **dessiné** : « Aucun système IA enregistré » | 🔴 **ABSENT** — « Total registres **0** · Risque élevé **0** · En production **0** » | 🔴 **ABSENT** | ◐ s.o. | 🔴 **GRAVE** — **registre réglementaire** ; s'ajoute à **A-002 / B10-013** (l'API rend déjà un corps figé) |
| `/audit-logs` | **squelette** générique | **dessiné** : « Aucun journal d'audit » | 🔴 **ABSENT** | 🔴 **ABSENT** | ◐ vérification de chaîne KO → **toast** « Erreur vérification chaîne » (la mutation, elle, parle) | 🔴 **GRAVE** — un journal d'audit qui dit « aucun événement » sur une panne est **exactement** ce qu'un journal d'audit ne doit jamais dire |

### 1.7 Réglages

| écran | ⏳ chargement | ∅ vide | ⚠ erreur | ⛔ permission refusée | ◐ partiel / hors ligne | verdict |
|---|---|---|---|---|---|---|
| `/users` | **squelette** générique (5 barres) pour une grille à **5 colonnes** → saut | **dessiné** : « Aucun utilisateur — invite ton premier collaborateur » | 🔴 **ABSENT** — **le cas emblématique** : « Aucun utilisateur » alors qu'un utilisateur **existe** | 🔴 **ABSENT** | ◐ s.o. | 🔴 **GRAVE** — et **ne tient aucun volume** (§3) |
| `/settings` | **texte nu** « Chargement… » **dans le seul onglet Workspace** ; l'en-tête affiche déjà « Workspace : **…** » | ∅ **ABSENT** | 🔴 **BLOQUÉ SUR « Chargement… » POUR TOUJOURS** — `ws.data` reste indéfini, aucune branche d'erreur | 🔴 **ABSENT** | ◐ s.o. | 🔴 **GRAVE** — plafond de coût et clés d'intégration derrière un « Chargement… » perpétuel |
| `/tags` | **squelette** (4 cartes) | **dessiné** : « Aucun tag » | 🔴 **ABSENT** — « Total tags **0** · Auto **0** · Manuel **0** · LLM **0** » | 🔴 **ABSENT** | ◐ s.o. | 🔴 **MENT** |
| `/llm/router` | **squelette** générique ×3 onglets | **dessiné** : « Aucun cas d'usage configuré » | 🔴 **ABSENT** — et l'onglet *Usage 30 j* affiche **0,00 €** | 🔴 **ABSENT** | ◐ 🔴 cas d'usage OK + coûts KO → **« 0,00 € » affiché à côté de données vraies** | 🔴 **GRAVE** — l'écran de **surveillance des dépenses** rassure au moment où il devrait alerter |
| `/llm/proxy-providers` | **squelette** générique | **dessiné** | 🔴 **ABSENT — ET ACCUSE L'UTILISATEUR** : « Aucun fournisseur configuré — **Configure `WEBSHARE_API_KEY` ou `IPROYAL_USERNAME` dans `.env` serveur** ». Sur un 500, l'écran envoie l'exploitant modifier une configuration qui n'a rien à voir | 🔴 **ABSENT** | ◐ s.o. | 🔴 **MENT ACTIVEMENT** |
| `/llm/rotations` | **squelette** générique | **dessiné** : « Aucune rotation configurée » | 🔴 **ABSENT** | 🔴 **ABSENT** | ◐ s.o. | 🔴 **MENT** — et l'écran est en lecture seule (agent 22) |

### 1.8 Phase 2 et hors-menu

| écran | ⏳ chargement | ∅ vide | ⚠ erreur | ⛔ permission refusée | ◐ partiel / hors ligne | verdict |
|---|---|---|---|---|---|---|
| `/cold-email` | **s.o.** — 13 lignes, aucune requête | **s.o.** | **s.o.** | **s.o.** | **s.o.** | **A-005** (déjà ouvert) — honnête, mais joignable par adresse |
| `/linkedin` | **s.o.** | **s.o.** | **s.o.** | **s.o.** | **s.o.** | **A-005** (déjà ouvert) |
| `/*` (NotFound) | **s.o.** | **s.o.** | **s.o.** | **s.o.** | **s.o.** | **D22-007** (déjà ouvert) — le composant rend bien « 404 / Page introuvable / Retour au tableau de bord » **quand on le monte** (vérifié ici), il n'est simplement **jamais branché** |

---

## 2. Ce que la grille donne, en chiffres

### 2.1 Le compte exact des écrans qui affirment « 0 » sur une erreur : **19**

L'agent 22 en comptait **12**, à l'œil, sur la seule condition que son montage permettait. Mesuré ici sur les
**37 écrans** et **relu à la main sur le texte brut** (§0.4), le compte est de **19**.

**Les 19** : `/` · `/contacts` · `/companies` · `/journalists` · `/media` · `/console/contacts` ·
`/console/vivier` · `/console/arbitrage` · `/campaigns` · `/scraper-runs` · `/audiences` · `/rgpd/requests` ·
`/rgpd/ai-act` · `/audit-logs` · `/users` · `/tags` · `/llm/router` · `/llm/proxy-providers` · `/llm/rotations`.

**Les 7 qui ne le font pas**, et pourquoi :

| écran | ce qu'il fait à la place | est-ce mieux ? |
|---|---|---|
| `/international/roumanie` | « Impossible de charger le vivier Roumanie. » | ✅ **oui** — vrai état d'erreur |
| `/admin/observability` | « Impossible de charger les métriques d'observabilité. » | ✅ **oui** — mais après ~93 s (§2.5) |
| `/companies/$companyId` | « Entreprise introuvable · 404 » | ❌ **non** — impute la panne à la donnée |
| `/media/$mediaId` | « Média introuvable » | ❌ **non** — idem |
| `/console/personnes/$personKey` | « Fiche introuvable » | ❌ **non** — idem |
| `/campaigns/$campaignId` | sablier éternel | ❌ **non** — ne dit **rien**, indéfiniment |
| `/audiences/$audienceId` | sablier éternel | ❌ **non** — idem |

Plus `/settings`, bloqué sur « Chargement… », et `/coverage`, non vérifiable.
**Bilan : sur 30 écrans qui lisent une donnée, 2 disent la vérité.**

### 2.2 Plus dur encore : **23 écrans sur 30 rendent un texte STRICTEMENT identique** selon que le serveur a répondu « vide » ou a planté

Comparaison **caractère par caractère** du DOM entre la condition **VIDE** (`200` + collection vide) et la condition
**ERREUR** (`500`) — `04_PREUVES/agent-25/10-vide-vs-erreur-indiscernables.txt`.

> **23 / 30.** Aucun octet ne diffère. *Un opérateur ne peut pas distinguer « la base est vide » de « la requête a
> échoué » — non pas parce que c'est difficile, mais parce qu'il n'y a rigoureusement rien à distinguer.*

**Témoin négatif** — la même comparaison, appliquée au couple **VIDE vs ERREUR**, trouve **7 écrans différents** :
la sonde sait donc voir une différence quand il y en a une. Elle n'invente pas l'identité.

### 2.3 Le verdict sur l'état « permission refusée » : **37 / 37 identiques au 500**

Même comparaison, entre **403** et **500** — `04_PREUVES/agent-25/09-403-identique-500.txt` :

> **37 écrans sur 37 rendent EXACTEMENT LA MÊME CHOSE sous un refus et sous une panne. Zéro écran distingue les deux.**

Il n'existe donc **aucun état « permission refusée »** dans ce produit. Le seul refus dessiné —
« Univers vivier candidats non accessible » — porte sur un **univers de données**, pas sur un **droit**, et il est
rendu par `ConsoleGate` **avant** tout appel : il ne se déclenche jamais sur un vrai 403.

C'est la moitié manquante de **D22-006** : celui-ci établit que l'interface n'interroge **jamais** les permissions
*avant* d'offrir ses actions ; celui-ci établit qu'elle ne sait pas davantage les reconnaître *après*. L'utilisateur
ne découvre donc pas son absence de droit « au clic » — **il ne la découvre pas du tout** : il voit un zéro.

### 2.4 L'état partiel : mesuré sur les 7 écrans multi-sources — **0 sur 7** le signale

*(Relevé complet : `04_PREUVES/agent-25/releve-partiel.txt`.)*

### 2.5 Le délai avant le premier aveu : **~93 secondes**, chronométrées

Trois constantes du produit se composent, chacune lue dans le code :

```
src/lib/api.ts:9       timeout: 30_000                        -> 30 s par tentative
src/main.tsx:19-22     retry: (count, …) => … && count < 2    -> 3 tentatives
react-query (défaut)   retryDelay = min(1000 * 2**n, 30_000)  -> 1 s puis 2 s
                                              30 + 1 + 30 + 2 + 30 = 93 s
```

Chronométré sur `/admin/observability`, avec **une copie exacte du `QueryClient` de production**, face à un serveur
qui accepte la connexion et ne répond jamais — c'est-à-dire **le cas A-010 / A-009**, pas une hypothèse.
*(Valeur mesurée : `04_PREUVES/agent-25/releve-delai.txt`.)*

**C'est la réconciliation entre l'agent 22 et moi** : il a vu « Chargement de l'observabilité… » et conclu que
l'état d'erreur était absent ; j'ai vu le message d'erreur. Nous avons tous les deux raison — il regardait
**pendant** la minute et demie de silence. **Et pour les 19 écrans sans état d'erreur, ce silence n'a pas de fin** :
passé le squelette, ils affichent « 0 » et ne se corrigent jamais.

---

## 3. La tenue aux volumes — 0, 1, 100, 10 000, 100 000 lignes

*(Relevé intégral : `04_PREUVES/agent-25/releve-volumes.txt`.)*

### 3.1 Ce que le code prévoit

| mécanisme | où | portée |
|---|---|---|
| `useVirtualizer` (`@tanstack/react-virtual`) | **`CompaniesListPage.tsx:297`, et nulle part ailleurs** | **1 écran sur 37** |
| composant `Pagination` | `/companies`, `/scraper-runs` | **2 écrans** |
| `per_page` demandé au serveur | `/companies` et `/media` (100), `/contacts`, `/scraper-runs`, `/console/*` (50) | 7 écrans |
| **rien du tout** | `/users`, `/audit-logs`, `/tags`, `/audiences`, `/rgpd/requests`, `/rgpd/ai-act`, `/llm/*` | **9 écrans demandent tout, et rendent tout** |
| `@tanstack/react-table` | **déclaré dans `package.json`, importé nulle part** | 0 |
| clés React `key={index}` | 4 fichiers (`ScraperRuns`, `Audiences`, `Campaigns`, `Tags`) — **uniquement sur des squelettes**, jamais sur des données | sans conséquence |

**Témoin négatif** : le même contrôle **trouve** les 39 fichiers qui importent `@tanstack/react-query` et les
2 occurrences de `useVirtualizer`. Il sait donc repérer un import quand il existe.

### 3.2 Ce que la mesure donne

<!-- TABLEAU-VOLUMES -->

---

## 4. L'état dans l'URL, le retour arrière, le hors-ligne, les squelettes

### 4.1 L'état n'est JAMAIS dans l'URL — verdict sans nuance

Le §5.1-7 exige que le tri, les filtres et la page soient **dans l'URL**, donc partageables et rechargeables.

```
grep -rn "useSearch\|validateSearch" src/     ->  AUCUNE OCCURRENCE  (code retour 1)
```

**Témoin négatif** : `useSearch` **existe bien** dans `@tanstack/react-router@1.170.27` installé
(`node_modules/@tanstack/react-router/dist/esm/fileRoute.d.ts`), et le même contrôle trouve sans peine les
19 fichiers qui importent le routeur. Aucune route de `routeTree.tsx` ne déclare `validateSearch`.

Les 31 occurrences d'`URLSearchParams` construisent la **chaîne de requête envoyée à l'API** — jamais l'URL du
navigateur. **Tout l'état d'écran vit en `useState`** : `page`, `filter`, `search`, `tab`, `temperature`, `nature`,
`severityFilter`, `selection`… Aucun n'est persisté (`sessionStorage`, `localStorage`, `zustand` : **0 occurrence**
dans `src/features/`).

**Témoin négatif** : le produit **sait** persister — `RootLayout.tsx:46-60` garde le repli de la barre latérale dans
`localStorage`, et `DarkModeToggle.tsx:22` le thème. Ce n'est donc pas une lacune technique, c'est un choix qui n'a
pas été fait pour l'état de liste.

**Conséquences mesurées, pas déduites** :
- un écran filtré **ne se partage pas** : le lien renvoie la liste non filtrée ;
- un rechargement (F5) **perd tout** ;
- une URL portant `?severity=critical&q=erasure` est **ignorée** par `/audit-logs` ;
- la **restauration de défilement** du routeur n'est pas activée (`scrollRestoration` : 0 occurrence ;
  `createRouter` reçoit `routeTree`, `defaultPreload`, `context` — rien d'autre).

### 4.2 Le retour arrière : l'état de liste ne survit pas

Geste réel joué (`04_PREUVES/agent-25/releve-retour.txt`) : ouvrir `/media`, saisir un filtre, aller sur une fiche,
revenir par `router.history.back()`. **Le composant est démonté à la navigation, son `useState` avec lui.**
Le cache react-query conserve les **données** (`gcTime` 5 min) mais **pas la question posée** : l'utilisateur
retrouve la liste au début, sans son filtre, sans sa page, et à la première ligne.

### 4.3 Le hors-ligne : rien, nulle part

```
navigator.onLine · événements online/offline · onlineManager · networkMode  ->  0 occurrence
service worker · workbox · manifest                                          ->  0 occurrence
bandeau de reconnexion / laravel-echo déconnecté                             ->  0 occurrence
```

**Témoin négatif** : `onlineManager` et `networkMode` existent dans `@tanstack/react-query` installé.

La conséquence n'est pas neutre : react-query v5 vaut `networkMode: 'online'` par défaut et `src/main.tsx` ne le
change pas. **Hors ligne, la requête n'est pas émise, elle est mise en pause** — `isPending` reste vrai.
Mesuré (`releve-horsligne.txt`) : les écrans à squelette restent **en squelette pour toujours**, les fiches restent
en **sablier**, et **aucun** ne prononce le mot « connexion ». Le seul écran qui saurait dire « impossible de
charger » ne le dit pas non plus, puisqu'il n'y a pas d'erreur — il y a une attente.

### 4.4 Le vocabulaire de l'erreur est écrit, traduit… et jamais appelé

`src/locales/fr.json:36-44` contient :

```json
"common": { "loading": "Chargement…", "empty": "Aucun résultat",
            "error": "Une erreur est survenue.", "retry": "Réessayer", … }
```

- **`common.retry` = « Réessayer » n'est appelé par aucun composant.** Le mot « Réessayer » n'apparaît **nulle part**
  dans une interface du produit.
- **`common.error` est appelé une seule fois**, dans le `catch` de `LoginPage.tsx:75`.
- **Témoin négatif** : le même contrôle trouve bien `t('common.loading')` et `t('common.error')` dans `LoginPage` —
  il sait donc repérer un appel de traduction quand il existe.

À quoi s'ajoute que **le message du serveur n'atteint jamais un écran de lecture** : `extractApiMessage` est
**dupliqué à l'identique dans 8 fichiers** (piège 15 : une constante dupliquée ne signale jamais qu'elle a divergé)
et ses **21 usages sont tous** des toasts de **mutation**, sauf un (`setPreviewError`, `AudienceBuilderPage:156`).
**Aucune des 19 listes menteuses ne pourrait afficher la cause même si elle le voulait : elle ne l'a pas sous la main.**

### 4.5 Les squelettes : un seul est taillé pour ce qu'il remplace

`CompaniesTableSkeleton` — **une barre de 40 px, puis N barres de 48 px pleine largeur** — sert de chargement à
**9 écrans** : `/companies`, `/contacts`, `/llm/router` (×3), `/llm/proxy-providers`, `/llm/rotations`,
`/rgpd/ai-act`, `/rgpd/requests`, `/audit-logs`, `/users`. Aucun d'eux, sauf `/companies`, n'a la forme d'un tableau
d'entreprises : `/llm/router` rend des onglets et des vignettes, `/tags` des cartes, `/users` une grille à 5 colonnes,
`/rgpd/ai-act` quatre vignettes de synthèse **au-dessus** de la liste. **Le squelette annonce une forme, le contenu en
prend une autre : c'est un saut de mise en page par construction.** *(Non chiffré en pixels — jsdom ne peint pas.)*

- **Le seul squelette fidèle** : `RunsTableSkeleton` (`ScraperRunsPage.tsx:738`), qui reprend les 7 colonnes et
  leurs largeurs. **C'est le patron à copier.**
- **6 écrans n'ont aucun squelette**, seulement le mot « Chargement… » : `/media`, `/journalists`,
  `/admin/observability`, `/international/roumanie`, `/coverage`, `/settings`.
- **4 écrans n'ont qu'un sablier muet**, sans un caractère : `/media/$id`, `/campaigns/$id`, `/audiences/$id`,
  et `/console/personnes/$key` (squelette sans texte).

---

## 5. Constats

### [D25-001] Aucun écran ne distingue un refus de droits d'une panne serveur : 37 écrans sur 37 rendent un texte strictement identique sous 403 et sous 500
- Sévérité      : **S1** grave
- Domaine       : interface / sécurité / UX
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/features/**` — l'ensemble ; `frontend/src/lib/api.ts:26-33` (l'intercepteur ne traite que le **401**)
- Constat       : sous une réponse **403**, les 37 écrans affichent **exactement les mêmes octets** que sous une réponse **500** ; aucun ne prononce le mot « droit », « permission » ou « accès ».
- Preuve        : 37 écrans × 4 conditions montés dans le harnais du dépôt, puis comparaison **caractère par caractère** des textes de DOM. Sortie : `04_PREUVES/agent-25/09-403-identique-500.txt` → « **TOTAL : 37 écrans rendent EXACTEMENT LA MÊME CHOSE sous 403 et sous 500. 0 écran distingue le refus de la panne.** ». Relevés bruts des 148 montages : `04_PREUVES/agent-25/releve-etats.txt`.
- Témoin négatif: la **même** comparaison, appliquée au couple **VIDE (200 vide) vs ERREUR (500)**, rend **7 écrans DIFFÉRENTS** sur 30 — la sonde sait donc voir une différence lorsqu'il y en a une, et le « 37 identiques » n'est pas un artefact. En outre, le seul écran de refus du produit (« Univers vivier candidats non accessible ») **est bien trouvé** par la sonde lorsqu'on ferme l'univers : elle sait reconnaître un écran de refus.
- Impact        : un `viewer` à qui le serveur refuse `/users` voit « **Aucun utilisateur — Invite ton premier collaborateur** », avec le bouton *Inviter*. Il ne découvre pas son absence de droit « au clic » (ce que décrit **D22-006**) : **il ne la découvre pas du tout**, il croit le workspace vide. Sur `/audit-logs` et `/rgpd/requests`, un refus légitime devient un « aucun événement » — et **B16-004** montre qu'aujourd'hui l'API ne refuse justement pas ; le jour où elle refusera, l'écran mentira.
- Reproduction  : `npx vitest run --config tmp/agent25/vitest.a25.config.ts tmp/agent25/etats.test.tsx`, puis comparer les blocs `[ERREUR]` et `[REFUS]` de `releve-etats.txt`.
- Correctif     : traiter le **403** dans l'intercepteur d'`api.ts` (comme le 401) pour marquer la requête « refusée », et rendre un état ⛔ partagé — « Vous n'avez pas le droit de consulter cet écran », avec ce qu'il faut demander et à qui. `ConsoleGate` (univers vivier) fournit déjà le libellé exemplaire : **on étend, on ne réinvente pas.** Coût **1 j**, à faire avec **D25-002** (même composant).
- Statut        : ouvert

### [D25-002] Sur les 30 écrans qui lisent une donnée, 23 rendent un texte strictement identique selon que la base est vide ou que la requête a échoué — et 19 affirment « 0 » ou « aucun »
- Sévérité      : **S1** grave
- Domaine       : interface / UX
- Référence     : main 8db8229
- Emplacement   : `features/dashboard/DashboardPage.tsx:105-141` · `companies/CompaniesListPage.tsx:617-620` · `contacts/ContactsListPage.tsx:174-177` · `media/MediaListPage.tsx:257-260` · `media/JournalistsListPage.tsx:99-102` · `crm-console/ContactsHubPage.tsx:180-183` · `crm-console/CandidatesPage.tsx:108-111` · `crm-console/ArbitragePage.tsx:85-88` · `campaigns/CampaignsListPage.tsx:158-161` · `scraping/ScraperRunsPage.tsx:398-401` · `audiences/AudiencesListPage.tsx:125-128` · `rgpd/RgpdRequestsPage.tsx:137-140` · `rgpd/AiActRegisterPage.tsx:118-121` · `rgpd/AuditLogsPage.tsx:131-134` · `users/UsersPage.tsx:98-101` · `tags/TagsManagerPage.tsx:192-199` · `llm/LlmRouterPage.tsx:92-95` · `llm/ProxyProvidersPage.tsx:58-61` · `llm/RotationsPage.tsx:57-60`
- Constat       : ces 19 écrans partagent un même arbre de rendu — `isLoading ? <Squelette/> : rows.length === 0 ? <ÉtatVide/> : <Liste/>` avec `rows = data?.data ?? []` — dans lequel **la branche d'erreur n'existe pas** : une requête échouée laisse `data` indéfini, donc `rows` vide, donc l'état vide.
- Preuve        : **chiffrage exact** du constat **D22-002** (S1, agent 22), que je ne rouvre pas mais qui portait sur **12** écrans estimés sur la seule condition « API injoignable ». Mesuré ici sur **37 écrans × 4 conditions**, montages réels : **19** écrans affirment « 0 »/« aucun » sur un `500`, et la comparaison caractère par caractère `200-vide` vs `500` rend **23 identiques sur 30**. Sorties : `04_PREUVES/agent-25/10-vide-vs-erreur-indiscernables.txt`, `synthese-etats.txt`, `releve-etats.txt`. **Trois classements automatiques ont été corrigés à la main contre mon propre résultat** (§0.4) : sans cette relecture j'aurais annoncé 16.
- Témoin négatif: sur les **mêmes** montages et au **même** instant, `/international/roumanie` rend « Impossible de charger le vivier Roumanie. » et `/admin/observability` « Impossible de charger les métriques d'observabilité. » — **le rendu dépend donc bien de l'état de la requête**, et l'affirmation « 0 » n'est pas une fatalité du harnais. Complément statique : `grep -c "isError"` vaut **0** sur les 19 fichiers listés, et **≠ 0** sur `CompanyDetailPage`, `MediaDetailPage`, `RoumaniePage` — le contrôle sait trouver `isError` quand il est là.
- Impact        : pour un CRM, c'est le mensonge le plus coûteux, et il est **plus large que mesuré jusqu'ici**. `/users` annonce « Aucun utilisateur » quand un utilisateur existe. `/audit-logs` — un **journal d'audit** — annonce « Aucun journal d'audit ». `/rgpd/requests` et `/rgpd/ai-act` — deux écrans à **obligation légale** — annoncent qu'il n'y a rien à traiter. `/llm/router` affiche **0,00 €** de dépenses. `/scraper-runs` affirme « **aucun échec** » au moment précis où la lecture a échoué.
- Reproduction  : `npx vitest run --config tmp/agent25/vitest.a25.config.ts tmp/agent25/etats.test.tsx` ; lire les blocs `[VIDE]` et `[ERREUR]` de chaque écran.
- Correctif     : un composant partagé `<ÉtatDeRequête query={…}>` qui distingue `isPending` / `isError` / `data.length === 0`, rend un message en langage courant **avec la cause** (le message du serveur est déjà extrait par `extractApiMessage`, aujourd'hui réservé aux toasts de mutation) et **un bouton *Réessayer*** — dont la traduction `common.retry` **existe déjà et n'est jamais appelée** (§4.4). Coût **2 à 3 j** pour les 19 écrans, dont ~0,5 j pour le composant.
- Statut        : ouvert

### [D25-003] `/console/arbitrage` n'affiche pas seulement un zéro : il énonce une conclusion métier fausse — « Tous les événements entrants ont trouvé leur entreprise »
- Sévérité      : **S1** grave
- Domaine       : interface / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/features/crm-console/ArbitragePage.tsx:82-95`
- Constat       : lorsque `GET /crm/arbitrage` échoue, l'écran rend le sous-titre « **0 événement(s) reçus sans SIREN** » et l'état vide « **Rien à arbitrer — Tous les événements entrants ont trouvé leur entreprise.** »
- Preuve        : montage réel sous `500`, texte intégral relevé — `04_PREUVES/agent-25/releve-etats.txt`, bloc `### /console/arbitrage [ERREUR]` : « *Rapprochements à arbitrer · 0 événement(s) reçus sans SIREN — à rattacher ou à écarter. · Rien à arbitrer · Tous les événements entrants ont trouvé leur entreprise.* ». Le rendu est **strictement identique** au cas `200` + liste vide (comparaison caractère par caractère, `10-vide-vs-erreur-indiscernables.txt`).
- Témoin négatif: le même banc, sur le même écran, avec une réponse `200` portant **une** ligne, affiche bien la ligne et non l'état vide — la sonde distingue donc les deux cas. Et sur `/international/roumanie`, monté au même instant sous le même `500`, elle relève « Impossible de charger… » : elle sait lire un état d'erreur là où il existe.
- Impact        : ce n'est pas un compteur à zéro, c'est **une affirmation sur l'état du système**. **B13-001** mesure qu'**aucun émetteur du site ne transmet de SIREN**, donc que **100 % des leads stationnent sur cet écran** : la phrase affichée est **toujours fausse**, et elle l'est de la manière la plus rassurante possible. Un dirigeant qui ouvre l'écran pendant une panne conclut que le rapprochement automatique fonctionne parfaitement — le contraire exact de la réalité mesurée.
- Reproduction  : monter `ArbitragePage` avec `consoleFeatures: 'open'` et `GET /crm/arbitrage` → 500.
- Correctif     : au minimum, faire dépendre la phrase de `isSuccess` et non de `rows.length === 0` (**0,25 j**) ; proprement, appliquer le composant de **D25-002**. **Garde à poser** : un test qui monte l'écran sous 500 et **exige** l'absence de la chaîne « ont trouvé leur entreprise » — la garde actuelle, s'il en existe une, ne rougit sur rien.
- Statut        : ouvert

### [D25-004] Trois écrans ne sont pas « blancs » : ils sont bloqués en chargement pour toujours, parce que la condition de sortie teste la donnée et non l'état de la requête
- Sévérité      : **S1** grave (relève **D22-003**, S2, dont il précise la cause et corrige la description)
- Domaine       : interface / navigation
- Référence     : main 8db8229
- Emplacement   : `frontend/src/features/campaigns/CampaignDetailPage.tsx:96-99` · `frontend/src/features/audiences/AudienceDetailPage.tsx:100-103` · `frontend/src/features/settings/SettingsPage.tsx:183-186`
- Constat       : les deux premiers écrivent `if (isLoading || !campaign) return <Spinner/>` (resp. `!audience`) ; sur une réponse en erreur, `isLoading` retombe à faux mais la donnée reste indéfinie, **la condition reste donc vraie indéfiniment**. `/settings` fait de même avec un texte « Chargement… ».
- Preuve        : montages réels sous les **quatre** conditions. `04_PREUVES/agent-25/synthese-etats.txt` : `/campaigns/$campaignId` et `/audiences/$audienceId` sont classés **« CHARGEMENT (spinner seul, sans texte) »** en **EN-VOL, VIDE, ERREUR et REFUS** — les quatre. Métriques du DOM au même instant : `pulse=0 spin=1 elements=9 html=373o boutons=0`, texte = **0 caractère**. `/settings` sous 500 : « *Paramètres · Workspace : … · Workspace · Identité et limites · **Chargement…*** ».
- Témoin négatif: la sonde distingue bien un sablier d'un écran réellement vide — elle compte `animate-spin` et le nombre de nœuds séparément, et elle classe `/coverage` « ÉCRAN BLANC (DOM vide) » quand le DOM ne porte que 13 nœuds sans sablier. Au même instant, `/companies/$companyId` rend « Entreprise introuvable » : la sonde sait lire un état d'erreur sur un écran de détail.
- Impact        : **précision apportée à D22-003.** L'agent 22 relevait `main.innerText` vide et concluait « écran blanc » : c'est exact au caractère près, mais **la cause n'est pas un rendu manquant, c'est une condition de sortie mal écrite**, et l'écran affiche en réalité un **sablier qui tourne pour l'éternité**. La différence compte pour l'utilisateur : un écran blanc se lit « c'est cassé, je recharge » ; un sablier se lit « **ça travaille, j'attends** ». Sur `/campaigns/$id`, quatre actions destructrices (lancer, pause, reprendre, annuler) sont derrière ce sablier. Sur `/settings`, le plafond de coût et les clés d'intégration.
- Reproduction  : monter l'un des trois écrans avec sa requête en 500 ; observer `document.querySelectorAll('.animate-spin').length === 1` et `document.body.textContent === ''`.
- Correctif     : remplacer `isLoading || !x` par les trois branches `isPending` / `isError` / `!x` ; `CompanyDetailPage.tsx:79-95` porte **déjà** le patron correct (`if (isLoading)` puis `if (isError || !c)`) — **on étend, on ne réinvente pas**. Coût **0,5 j** pour les trois.
- Statut        : ouvert

### [D25-005] Un 500 et un 403 sont présentés comme une fiche supprimée : trois écrans de détail affichent « introuvable · 404 » sur une panne
- Sévérité      : **S2** défaut
- Domaine       : interface / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/features/companies/CompanyDetailPage.tsx:88-95` · `frontend/src/features/media/MediaDetailPage.tsx:51-56` · `frontend/src/features/crm-console/PersonTimelinePage.tsx:45-51`
- Constat       : les trois écrans réunissent l'erreur et l'absence dans une seule branche — `if (isError || !data)` — et rendent un message qui **affirme la suppression** : « Entreprise introuvable · **404** · Cette entreprise n'existe pas ou **a été supprimée** ».
- Preuve        : montages réels. `releve-etats.txt`, blocs `[ERREUR]` : `/companies/$companyId` → « *Entreprise introuvable · 404 · Cette entreprise n'existe pas ou a été supprimée.* » ; `/media/$mediaId` → « *Média introuvable · Ce média n'existe pas ou a été supprimé.* » ; `/console/personnes/$personKey` → « *Fiche introuvable · Cette personne n'existe dans aucun univers accessible.* ». Le bloc `[REFUS]` (403) est **strictement identique** dans les trois cas.
- Témoin négatif: sous la condition **VIDE** (`200` avec une fiche réelle), les trois écrans rendent bien la fiche — la branche d'erreur ne se déclenche donc pas à tort. Et `/international/roumanie`, monté sous le même 500, rend « Impossible de charger… » : le vocabulaire de la panne existe dans le produit.
- Impact        : sur `/console/personnes/$personKey` — **la fiche 360°** — un opérateur à qui le serveur refuse l'accès (403) lit « **Cette personne n'existe dans aucun univers accessible** » : il conclut que la personne n'est pas dans la base, alors qu'elle y est et qu'on lui en refuse la lecture. Sur `/companies/$id`, le mot « **supprimée** » et le code « **404** » invitent à conclure à une perte de données là où il n'y a qu'une indisponibilité passagère. C'est la variante « fiche » de **D25-001**.
- Reproduction  : ouvrir l'une des trois routes avec un identifiant valide, l'API répondant 500 puis 403.
- Correctif     : séparer `isError` de `!data`, et ne parler de suppression que sur un **404 avéré** ; réutiliser l'état ⛔ de **D25-001** pour le 403. Coût **0,5 j**.
- Statut        : ouvert

### [D25-006] La frontière d'erreur du produit existe, est écrite en français, est livrée dans le bundle — et n'est montée nulle part : toute exception de rendu affiche « Something went wrong! » en anglais, hors coquille
- Sévérité      : **S2** défaut
- Domaine       : interface / navigation
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/ui/ErrorBoundary.tsx` (44 l., exporté par `src/components/ui/index.ts:33`) · `frontend/src/main.tsx:28-32` (`createRouter` **sans** `defaultErrorComponent`) · `frontend/src/app/RootLayout.tsx:100-101` (`<main><Outlet/></main>`, nu)
- Constat       : `ErrorBoundary` rend « **Une erreur est survenue.** », le message de l'exception et un bouton « **Recharger la page** » ; `grep -rn "<ErrorBoundary" src/` ne trouve **aucun usage**, et aucune route ne déclare `errorComponent`.
- Preuve        : `04_PREUVES/agent-25/11-frontiere-erreur.txt`. Manifestation **mesurée** : sous jsdom, `maplibre-gl` lève « Failed to initialize WebGL » à l'ouverture de `/coverage` ; l'écran rendu est alors « **Something went wrong! · Hide Error · Failed to initialize WebGL** » — **13 nœuds, en anglais, sans barre latérale ni retour** (`releve-etats.txt`, bloc `### /coverage`). C'est le composant interne de TanStack Router, faute d'`errorComponent`.
- Témoin négatif: le même `grep` **trouve 27 usages JSX de `<EmptyState`** — il sait donc repérer un composant du même baril lorsqu'il est monté. Et `Sentry.ErrorBoundary` comme l'option `errorComponent` **existent bien** dans les versions installées (vérifié dans `node_modules`) : ce n'est pas une lacune de dépendance.
- Impact        : **même patron que D22-007** (l'écran « Page introuvable » existe et ne s'affiche jamais), sur un objet différent — et cette fois c'est le **dernier filet**. Toute exception de rendu, sur n'importe lequel des 37 écrans, sort l'utilisateur du produit vers un message anglais sans identité ni issue. S'y ajoute que `componentDidCatch` ne fait qu'un `console.error` : la ligne Sentry est restée un commentaire (« *Sprint 11 : Sentry.captureException* »), donc **même monté, l'incident ne serait signalé à personne**.
- Reproduction  : `grep -rn "<ErrorBoundary" frontend/src` → vide ; ouvrir un écran qui lève.
- Correctif     : passer `defaultErrorComponent: <ErrorBoundary level="root"/>` à `createRouter` (ou envelopper l'`<Outlet/>` de `RootLayout`), et remplacer le `console.error` par `captureException` (`src/lib/sentry.ts:26` l'expose déjà). Coût **0,25 j**. **Garde à poser** : un test qui monte un écran qui lève et exige la présence de « Une erreur est survenue. ».
- Statut        : ouvert

### [D25-007] Aucun état d'écran n'est dans l'URL : filtres, tri, page et onglet ne se partagent pas, ne se rechargent pas, et ne survivent pas au retour arrière
- Sévérité      : **S2** défaut
- Domaine       : navigation / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/app/routeTree.tsx` (aucune route ne déclare `validateSearch`) · `frontend/src/main.tsx:28-32` (`createRouter` sans `scrollRestoration`) · les 14 écrans de liste, dont `companies/CompaniesListPage.tsx:198-200`, `media/MediaListPage.tsx:117-119`, `scraping/ScraperRunsPage.tsx:220-224`, `crm-console/ContactsHubPage.tsx:53-60`, `rgpd/AuditLogsPage.tsx:56-58`, `international/RoumaniePage.tsx:75-77`
- Constat       : `useSearch` et `validateSearch` n'apparaissent **nulle part** dans `src/` ; tout l'état d'écran vit en `useState`, et rien ne le persiste.
- Preuve        : `04_PREUVES/agent-25/03-virtualisation-et-url.txt` et `06-retour-arriere-et-restauration.txt`. Geste réel joué (`releve-retour.txt`) : ouvrir `/media`, saisir un filtre — **l'API reçoit bien le filtre**, l'**URL du routeur ne change pas** —, aller sur une fiche, revenir par `router.history.back()` : le champ est vide. Et `/audit-logs` visité à `?severity=critical&q=erasure` affiche **les deux lignes**, filtre ignoré.
- Témoin négatif: `useSearch` **existe** dans `@tanstack/react-router@1.170.27` installé, et le même contrôle trouve les 19 fichiers qui importent le routeur : il sait voir un symbole du routeur quand il est utilisé. Sur la persistance, le contrôle **trouve bien** `localStorage` dans `RootLayout.tsx:46-60` (repli de la barre latérale) et `DarkModeToggle.tsx:22` (thème) — **le produit sait persister un état ; il ne le fait pas pour les listes.**
- Impact        : le **§5.1-7 exige explicitement** l'état dans l'URL, il n'y est pas. Concrètement : on ne peut pas envoyer à un collègue « regarde les entreprises de l'Isère non enrichies » — le lien rend la liste entière ; un rechargement après une erreur repart de zéro ; et un opérateur qui parcourt une liste de 100 pages **perd sa page à chaque consultation de fiche**. Sur un écran de travail répétitif comme `/console/arbitrage`, où stationnent 100 % des leads (**B13-001**), c'est le geste le plus fréquent qui est le plus puni. **Une confusion de navigation qui fait perdre l'utilisateur est au minimum S2.**
- Reproduction  : ouvrir `/companies`, filtrer, copier l'URL, l'ouvrir dans un autre onglet.
- Correctif     : déclarer `validateSearch` (zod est déjà une dépendance) sur les 14 routes de liste et remplacer les `useState` de filtre par `useSearch` + `navigate({ search })` ; activer `scrollRestoration: true` sur `createRouter`. Coût **3 à 4 j** ; **0,25 j** pour la seule restauration de défilement. À arbitrer avec le chantier CRM cible, qui redessinera ces écrans.
- Statut        : ouvert

### [D25-008] Le tableau de bord n'affiche jamais son squelette : `placeholderData` est un objet de zéros, donc le premier écran du CRM est une grille de zéros avant toute réponse
- Sévérité      : **S2** défaut
- Domaine       : interface / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/features/dashboard/DashboardPage.tsx:78-92` (`placeholderData` **objet**) · `l. 135-136` (`{isLoading ? <DashboardSkeleton/> : …}`) · `l. 243-278` (`DashboardSkeleton`, jamais rendu)
- Constat       : `placeholderData` reçoit un **objet littéral** et non une fonction ; react-query considère donc qu'il y a des données dès le premier rendu, `isLoading` reste **faux en permanence**, et la branche `<DashboardSkeleton/>` est **du code mort**.
- Preuve        : montage réel sous la condition **EN-VOL** (serveur muet). Métriques du DOM relevées : **`pulse=0`** — aucun élément de squelette — pour **30 nœuds et 3 677 octets de HTML** déjà peints, portant « Total entreprises 0 », « Enrichies 24 h 0 »… (`releve-etats.txt`, bloc `### / [EN-VOL]`). Le fichier de test du dépôt `tests/screens/DashboardPage.test.tsx:12-16` **décrit lui-même le mécanisme** — il était donc connu, et n'a jamais été porté au dossier comme défaut.
- Témoin négatif: la sonde compte bien `pulse=8` et plus sur les 15 autres écrans à squelette montés dans la **même** condition, au même instant (`/companies`, `/users`, `/tags`…) : elle sait détecter un squelette quand il est rendu. Et `DashboardSkeleton` **existe** dans le bundle — c'est son branchement, pas sa présence, qui est en cause.
- Impact        : le **premier écran que voit un utilisateur** affirme « 0 entreprise » **avant même que la question ait été posée au serveur**. Cumulé à **D25-002** (aucun état d'erreur) et à **A-010** (une requête lente bloque tout), cela signifie qu'un dirigeant ouvrant le CRM pendant une lenteur voit un CRM vide, sans squelette pour lui signaler l'attente, et sans erreur ensuite pour le détromper. C'est le même patron que **D22-004** — un cas transitoire traité, l'autre pas —, ici poussé jusqu'à supprimer le cas transitoire lui-même.
- Reproduction  : monter `DashboardPage` avec `GET /dashboard/stats` qui ne répond jamais ; observer `document.querySelectorAll('.animate-pulse').length === 0` et le texte « 0 ».
- Correctif     : passer une **fonction** à `placeholderData` (ou la retirer : `keepPreviousData` n'a pas de sens pour une requête sans clé variable), ce qui rend `isLoading` vrai au premier chargement et réveille `DashboardSkeleton`. Coût **0,25 j**.
- Statut        : ouvert

### [D25-009] Une seule liste sur 37 tient le volume : `/users` à 10 000 lignes construit 160 025 nœuds et 18 Mo de HTML, et neuf écrans ne demandent aucune limite au serveur
- Sévérité      : **S2** défaut
- Domaine       : performance / interface
- Référence     : main 8db8229
- Emplacement   : `frontend/src/features/companies/CompaniesListPage.tsx:297` (seul `useVirtualizer` du dépôt) · `users/UsersPage.tsx:62-64` · `rgpd/AuditLogsPage.tsx:60-63` · `tags/TagsManagerPage.tsx:99-102` · `audiences/AudiencesListPage.tsx:65-71` · `rgpd/RgpdRequestsPage.tsx:75-78` · `rgpd/AiActRegisterPage.tsx:55-58` · `llm/LlmRouterPage.tsx:58-68` · `llm/ProxyProvidersPage.tsx` · `llm/RotationsPage.tsx:38-41` — **aucun de ces neuf n'envoie de `per_page` ni n'offre de pagination**
- Constat       : `@tanstack/react-virtual` n'est importé que par `CompaniesListPage` ; `@tanstack/react-table`, pourtant déclaré dans `package.json`, n'est importé **nulle part** ; neuf écrans rendent intégralement ce que le serveur veut bien leur envoyer.
- Preuve        : 25 montages réels, jeux de 0 / 1 / 100 / 10 000 / 100 000 lignes — `04_PREUVES/agent-25/releve-volumes.txt`. Extrait : `/companies` garde **155 nœuds** de 1 à 100 000 lignes (la virtualisation fait son travail) ; `/users` passe de **41 nœuds** (1 ligne) à **1 625** (100) puis **160 025 nœuds, 18 397 Ko de HTML et 1,1 Go de tas** (10 000). Inventaire statique : `04_PREUVES/agent-25/04-volumes-pagination-keys.txt`.
- Témoin négatif: **obligatoire ici, et il est dans le relevé** — la fabrication et la sérialisation du jeu de 100 000 lignes, **sans monter aucun écran**, coûtent **604 ms et 44 Mo**. Tout ce qui dépasse est imputable à l'écran et non au banc. Par ailleurs le contrôle statique **trouve bien** les 2 occurrences de `useVirtualizer` et les 39 fichiers important `@tanstack/react-query` : il sait repérer un import quand il existe.
- Impact        : la production porte **4,29 M d'entreprises** et **1 319 567 personnes** (**C19-007**). Les écrans concernés sont aujourd'hui vides, donc le défaut est **invisible** — exactement comme la sérialisation d'**A-010** est invisible à un seul utilisateur. Le jour où `/audit-logs` contiendra un an de journal, l'écran demandera **tout** le journal et tentera d'en peindre chaque ligne : sur les mesures ci-dessus, 10 000 entrées suffisent à dépasser le gigaoctet. **B16-004** ajoute que cette route rend le journal de **tous** les espaces : le volume servi n'est même pas borné par l'espace de travail.
- Reproduction  : `npx vitest run --config tmp/agent25/vitest.a25.config.ts tmp/agent25/volumes.test.tsx`.
- Correctif     : (1) borner côté serveur — un `per_page` par défaut sur les 9 routes concernées, qui protège quel que soit le client ; (2) côté écran, réutiliser le composant `Pagination` **qui existe déjà** (`features/companies/components/Pagination.tsx`) et, pour les listes longues, `useVirtualizer` **selon le patron déjà écrit** dans `CompaniesListPage` — on étend, on ne réinvente pas. Coût **1 j** pour le plafond serveur, **2 à 3 j** pour les 9 écrans.
- Statut        : ouvert

### [D25-010] Le vocabulaire de l'erreur est écrit et traduit dans le produit, et n'est presque jamais appelé ; le message du serveur n'atteint jamais un écran de lecture
- Sévérité      : **S3** finition (mais c'est le **coût de correction** de D25-002 qu'il abaisse)
- Domaine       : interface / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/locales/fr.json:36-44` · `frontend/src/features/{audiences,campaigns,scraping,tags}/*.tsx` (8 copies de `extractApiMessage`)
- Constat       : `common.retry` = « Réessayer » n'est appelé par **aucun** composant ; `common.error` = « Une erreur est survenue. » l'est **une seule fois** (`LoginPage.tsx:75`) ; et `extractApiMessage`, **dupliqué à l'identique dans 8 fichiers**, n'alimente que des toasts de **mutation** — un seul de ses 21 usages sert un état d'écran (`AudienceBuilderPage.tsx:156`).
- Preuve        : `04_PREUVES/agent-25/12-reessayer-et-cause.txt`. `grep -rniE "r[ée]essayer|try again" src/` → **une seule ligne, `src/locales/fr.json:39`**, la définition. Aucun appel.
- Témoin négatif: le même contrôle **trouve** `t('common.loading')` et `t('common.error')` dans `LoginPage.tsx` — il sait repérer un appel de traduction lorsqu'il existe ; et il trouve « Actualiser » dans `DashboardPage.tsx:129`, donc il sait lire un libellé de bouton.
- Impact        : deux conséquences. D'abord, **aucune des 19 listes de D25-002 ne pourrait afficher la cause même si elle le voulait** : le message du serveur n'est extrait que dans le chemin des mutations. Ensuite, **le correctif de D25-002 est moins cher qu'il n'y paraît** : les libellés sont écrits, traduits en français et en anglais, et une fonction d'extraction existe déjà en 8 exemplaires — il reste à les brancher, et à en garder **une** (piège 15 : une constante dupliquée ne signale jamais qu'elle a divergé).
- Reproduction  : `grep -rn "common.retry" frontend/src --include=*.tsx` → vide.
- Correctif     : remonter `extractApiMessage` dans `src/lib/api.ts` (une seule copie), et l'appeler depuis le composant d'état de **D25-002**. Coût **0,5 j**, inclus dans D25-002.
- Statut        : ouvert

---

## 6. Ce que je n'ai PAS pu vérifier, et pourquoi

Ce n'est pas un aveu : c'est la partie du travail qu'un autre agent, ou Will, doit reprendre.

### 6.1 `/coverage` — 4 cases sur 185 (les seules non vérifiables)

`maplibre-gl` appelle `window.URL.createObjectURL` au chargement du module, puis initialise un contexte **WebGL**.
jsdom n'a ni l'un ni l'autre : j'ai comblé la première lacune dans **mon propre** socle de mesure
(`tmp/agent25/setup-a25.ts`, sans toucher à `tests/setup.ts` ni au produit), mais **WebGL ne se simule pas**.
Les quatre états de `/coverage` sont donc **lus dans le code** (`CoveragePage.tsx:28-37,137` : `cells = data ?? []`,
`isLoading ? 'Chargement…' : …`, **aucun `isError`** — donc même patron que les 19), **et déclarés non mesurés**.
*L'exception a en revanche produit une mesure utile : c'est elle qui a révélé D25-006.*
→ **Reprise possible** : Playwright sur un vrai navigateur, une fois l'autorité Caddy installée (§6.4).

### 6.2 Le rendu visuel, donc le saut de mise en page en pixels

jsdom ne peint pas. Le §4.5 établit **structurellement** qu'un squelette générique remplace 9 mises en page
différentes, ce qui **implique** un saut — mais je n'ai **pas** de valeur de CLS, et je n'en avance aucune.
→ **Reprise possible** : Lighthouse ou `PerformanceObserver('layout-shift')` sur les 15 pages stratégiques.

### 6.3 L'état nominal, avec de vraies données et une session ouverte

Inchangé depuis l'agent 22, et pour les mêmes causes déjà au dossier : **A07-001** (l'enrôlement 2FA écrit trois
colonnes absentes), **D22-001** (aucun écran ne l'expose), **A-012**, **A05-001** (0 contact sur 1 319 567 porte une
`person_key`). **Toutes mes mesures portent sur les états dégradés** — ce qui est précisément mon périmètre, mais
il faut le dire : je n'ai pas vu un seul de ces écrans faire son travail.

### 6.4 Les 37 écrans dans un vrai navigateur sur `https://app.localhost`

Bloqué par le certificat de l'autorité locale Caddy, absent du magasin Windows. **Je n'ai pas installé d'autorité
racine** — c'est une modification de sécurité du poste, elle revient à Will, et l'agent 22 a refusé avant moi.
Je reprends son geste à l'identique, il vaut aussi pour ma grille :

```
docker cp axion-crm-caddy:/data/caddy/pki/authorities/local/root.crt %TEMP%\caddy-local.crt
puis importer dans « Autorités de certification racines de confiance »
```

### 6.5 Le comportement des mutations sous erreur

Ma grille porte sur les états de **lecture**. Les toasts d'erreur des mutations (`onError`) ont été **lus** —
21 usages, tous nommés au §4.4 — mais **aucune mutation n'a été jouée** contre un serveur en erreur.

### 6.6 Les durées réelles de rendu

Les millisecondes du §3 sont des durées de **construction de DOM sous jsdom**, sur un poste partagé avec les autres
agents de l'audit. **Elles ne valent pas comme mesure de performance navigateur** et ne doivent pas être citées
comme telles. Le **nombre de nœuds**, lui, est exact et transposable.

### 6.7 La recherche globale et le rendu mobile

`GlobalSearch` (qui appelle `/search`, un bouchon — **A-002 / B10-013**) et la barre latérale en tiroir vivent dans
`RootLayout`, hors de mes 37 écrans. Non mesurés, comme chez l'agent 22.

---

## 7. Ce que je corrige de la grille de l'agent 22

Ses mesures ont été faites API totalement injoignable, sur une seule condition ; les miennes séparent les conditions.
Trois écarts, tous dans le même sens — **son montage ne pouvait pas attendre 93 secondes** :

| écran | agent 22 | mesuré ici | pourquoi l'écart |
|---|---|---|---|
| `/admin/observability` | « ⚠ **absent** — reste sur *Chargement de l'observabilité…* » | ⚠ **présent** : « Impossible de charger les métriques d'observabilité. » (`ObservabilityPage.tsx:41-47`) | l'erreur met **jusqu'à 93 s** à venir (§2.5) ; il observait pendant le silence |
| `/campaigns/$id`, `/audiences/$id` | « 🔴 **ÉCRAN BLANC**, `<main>` entièrement vide » | **sablier éternel** (`spin=1`, 0 caractère) | `innerText` d'un `<svg>` est vide ; la cause est une condition de sortie, pas un rendu manquant (**D25-004**) |
| écrans affirmant « 0 » sur une erreur | **12** | **19** | il ne pouvait mesurer qu'une condition ; le compte exhaustif est plus lourd que le sien |

**Aucun de ces écarts n'infirme ses constats** : `D22-002`, `D22-003`, `D22-004`, `D22-006`, `D22-007` restent
ouverts et fondés. Ils les précisent.

---

## 8. Ménage effectué

Les cinq bancs de mesure ont été écrits dans `frontend/tmp/agent25/` — répertoire **couvert par `.gitignore`**
(vérifié : `git check-ignore -v frontend/tmp/agent25` → `.gitignore:97:tmp/`), donc invisible de `git status`.
Leur source est **archivée dans `04_PREUVES/agent-25/bancs-de-mesure/`** pour que les mesures soient rejouables,
et le répertoire de travail a été retiré.

**Aucun fichier du produit n'a été modifié** — ni `frontend/src/`, ni `frontend/tests/`, ni `backend/`.
Le complément de socle jsdom (`URL.createObjectURL`) vit dans **mon** fichier de mise en place, jamais dans
`tests/setup.ts`. Le worktree `crmpro-wt-etape1a` n'a jamais été lu ni touché. Aucune écriture en production,
aucune requête vers la production ni vers la préproduction. Aucun conteneur créé, aucun service redémarré :
**toutes les mesures de cette grille sont hors ligne**, sur le code du dépôt.

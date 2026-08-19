# Inventaire des intentions — AGENT 22 (mandat §6.3-1)

- **Référence** : `main = e8924b8`. Migrations = **58** avant et après mesure.
- **Méthode** : la liste est **sortie du code**, écran par écran, action par action — les 37 écrans de `routeTree.tsx`, leurs appels d'API, leurs boutons et leurs libellés relevés un à un (voir `ecrans.md` §2). Elle n'est pas devinée depuis le mandat : c'est la seule façon de ne rien oublier, et c'est aussi la seule façon de voir ce qui **manque**.
- **72 intentions** listées. Le mandat en demandait 30 à 50 ; le code en a rendu davantage, et je n'en ai retiré aucune : chacune correspond à une action, un filtre ou un manque **mesuré**.

## Barème

| marque | ce que cela veut dire |
|---|---|
| **trouvable** | une entrée de la barre latérale, ou un bouton évident sur un écran qui, lui, est dans la barre |
| **trouvable avec effort** | il faut savoir où descendre, ou deviner un libellé, ou l'écran existe mais dit mal ce qu'il fait |
| **introuvable** | la fonction existe dans le produit, mais **aucun chemin de navigation** n'y mène (adresse à taper, écran orphelin, écran blanc) |
| **impossible** | le produit **ne sait pas le faire** — ou le fait semblant |

## Répartition

| marque | nombre | part |
|---|---|---|
| trouvable | **30** | 42 % |
| trouvable avec effort | **15** | 21 % |
| introuvable | **5** | 7 % |
| impossible | **22** | 30 % |
| **total** | **72** | 100 % |

Détail par section (le compte a été refait section par section) :

| section | trouvable | avec effort | introuvable | impossible | total |
|---|---|---|---|---|---|
| §1 Entrer dans l'outil (1-8) | 2 | 1 | 0 | 5 | 8 |
| §2 Ouvrir sa journée (9-15) | 1 | 0 | 0 | 6 | 7 |
| §3 Retrouver (16-28) | 4 | 6 | 0 | 3 | 13 |
| §4 Trier, arbitrer (29-36) | 5 | 2 | 0 | 1 | 8 |
| §5 Collecter (37-45) | 7 | 1 | 1 | 0 | 9 |
| §6 Agir commercialement (46-52) | 3 | 1 | 1 | 2 | 7 |
| §7 Conformité, réglages, veille (53-72) | 8 | 4 | 3 | 5 | 20 |
| **total** | **30** | **15** | **5** | **22** | **72** |

> Lecture : **37 % des intentions (27 sur 72) n'aboutissent pas** — et parmi elles, **22 sont des impasses complètes**. Les 30 « trouvables » le sont *en navigation* ; elles restent toutes soumises au verdict de connexion (`ecrans.md` §1) qui, lui, bloque **tout**.
>
> Les deux extrémités sont parlantes : **§5 Collecter** est la section la plus saine (7 trouvables sur 9, aucune impasse), **§2 Ouvrir sa journée** est la plus fermée (**6 impasses sur 7**).

---

## 1. Entrer dans l'outil

| # | ce que la personne vient faire, dans ses mots | où cela se passe aujourd'hui | marque |
|---|---|---|---|
| 1 | « me connecter » | `/login` | **trouvable** |
| 2 | « activer ma double authentification » | nulle part — `/2fa` ne fait que **vérifier** un code ; `/auth/2fa/setup` n'est appelé par aucun écran (**D22-001**) | **impossible** |
| 3 | « j'ai oublié mon mot de passe » | `/password-reset` — l'écran existe et répond, mais aucun courriel ne part (**A-012**) | **impossible** |
| 4 | « me connecter sans mot de passe » | `/magic-link` — même cause | **impossible** |
| 5 | « changer mon mot de passe maintenant que je suis entré » | nulle part — `/settings` n'a que Workspace · Intégrations · Observabilité · Apparence. **`infra/scripts/definir-mot-de-passe-crm.sh` demande pourtant explicitement de « le CHANGER depuis l'interface une fois connecté »** | **impossible** |
| 6 | « voir mon profil » | menu utilisateur → *Profil* → **atterrit sur `/settings`**, exactement comme *Paramètres* (**D22-008**) | **impossible** |
| 7 | « me déconnecter » | menu utilisateur (avatar, en haut à droite) → *Déconnexion* | **trouvable avec effort** |
| 8 | « changer d'espace de travail » | sélecteur en haut de la barre latérale | **trouvable** |

## 2. Ouvrir sa journée

| # | intention | emplacement | marque |
|---|---|---|---|
| 9 | « voir où j'en suis ce matin » | `/` — mais `/dashboard/stats` est un **bouchon codé en dur** qui rend des zéros (`routes/api.php:86-99`) | **impossible** (l'écran existe, le chiffre est faux) |
| 10 | « savoir par où commencer quand la base est vide » | `/` → « Démarrer sur /coverage → » | **trouvable** |
| 11 | « voir qui m'a écrit » | nulle part — pas de boîte de réception (groupe **ÉCHANGES** entier absent, **A-006**) | **impossible** |
| 12 | « voir mes rendez-vous du jour » | nulle part (**A-006**) | **impossible** |
| 13 | « voir mes tâches » | nulle part (**A-006**) | **impossible** |
| 14 | « revenir sur une fiche que j'ai ouverte hier » | nulle part — pas de « Fiches récentes » (**A-006**) | **impossible** |
| 15 | « chercher partout d'un coup » | recherche globale de l'en-tête (⌘K) — mais `/search` rend des tableaux **figés et vides** (A-002 / B10-013) | **impossible** |

## 3. Retrouver quelqu'un, retrouver une entreprise

| # | intention | emplacement | marque |
|---|---|---|---|
| 16 | « retrouver une entreprise par son nom » | `/companies` → champ de recherche | **trouvable** |
| 17 | « retrouver une entreprise par son SIREN » | `/console/contacts` (le champ mentionne SIREN) — `/companies` **ne cherche pas** par SIREN | **trouvable avec effort** |
| 18 | « retrouver **une personne** » | `/console/personnes/$personKey` — **0 contact sur 1 319 567 porte une `person_key`** (**A05-001**) | **impossible** |
| 19 | « voir la fiche complète d'une entreprise » | `/companies/$companyId`, par rebond depuis la liste | **trouvable** |
| 20 | « voir tout ce qui s'est passé avec cette personne » | la fiche 360° `/console/personnes/…` — inatteignable (#18), et le canal ne transporte qu'un **index** (**E32-002**) | **impossible** |
| 21 | « voir les contacts d'une entreprise » | `/contacts`, filtré — pas de rebond direct depuis la fiche entreprise | **trouvable avec effort** |
| 22 | « voir qui a un e-mail exploitable » | `/contacts` → filtre *statut e-mail* — **mais `/contacts` sort de la barre latérale dès que la console v2 est ouverte** (**D22-005**) | **trouvable avec effort** |
| 23 | « voir mes prospects chauds » | `/console/contacts` → onglet *Prospects* + filtre température | **trouvable avec effort** |
| 24 | « voir mes clients » | `/console/contacts` → onglet *Clients* | **trouvable avec effort** |
| 25 | « voir les comptes qui se sont endormis » | `/console/contacts` → onglet *Dormants* | **trouvable avec effort** |
| 26 | « filtrer les entreprises d'un département » | `/companies` → filtres (12 axes) | **trouvable** |
| 27 | « voir les entreprises françaises implantées en Roumanie » | `/international/roumanie` | **trouvable** |
| 28 | « faire la même chose pour un autre pays » | nulle part — seule la Roumanie a son écran | **impossible** |

## 4. Trier, qualifier, arbitrer

| # | intention | emplacement | marque |
|---|---|---|---|
| 29 | « arbitrer un rapprochement douteux » | `/console/arbitrage` → *Rattacher* | **trouvable** |
| 30 | « écarter un rapprochement, en disant pourquoi » | `/console/arbitrage` → *Écarter* + motif | **trouvable** |
| 31 | « suivre un candidat du vivier » | `/console/vivier` — n'apparaît dans le menu **que** si l'utilisateur est membre de l'univers | **trouvable avec effort** |
| 32 | « enrichir cette fiche tout de suite » | `/companies/$companyId` → *Enrichir maintenant* | **trouvable** |
| 33 | « marquer une fiche comme obsolète » | `/companies/$companyId` → *Marquer obsolète* | **trouvable** |
| 34 | « poser un tag sur 50 entreprises d'un coup » | `/companies` → sélection de la page + *Poser le tag* | **trouvable avec effort** |
| 35 | « créer un tag maison » | `/tags` → *Nouveau tag* | **trouvable** |
| 36 | « fusionner deux tags devenus doublons » | nulle part — `/tags` sait créer, modifier, supprimer, **pas fusionner** | **impossible** |

## 5. Aller chercher de la donnée

| # | intention | emplacement | marque |
|---|---|---|---|
| 37 | « lancer une collecte sur l'Isère » | `/coverage` → carte → mode *Action* | **trouvable** |
| 38 | « savoir quels départements ne sont pas couverts » | `/coverage` | **trouvable** |
| 39 | « lancer une collecte multi-sources avec un budget et un garde-fou anti-blocage » | `/campaigns/new` — assistant en 4 étapes | **trouvable** |
| 40 | « mettre une collecte en pause » | `/campaigns` → *Pause* | **trouvable** |
| 41 | « arrêter une collecte qui dérape » | `/campaigns` → *Annuler* | **trouvable** |
| 42 | « refaire la campagne qui avait bien marché » | `/campaigns/$campaignId` → *Dupliquer* — **écran blanc mesuré** (**D22-003**) | **introuvable** |
| 43 | « savoir si un run a échoué, et pourquoi » | `/scraper-runs` → détail | **trouvable** |
| 44 | « relancer un run échoué » | `/scraper-runs` → *Retry* | **trouvable** |
| 45 | « importer une liste d'entreprises que j'ai déjà » | `/companies` → *Importer* | **trouvable avec effort** |

## 6. Préparer une action commerciale

| # | intention | emplacement | marque |
|---|---|---|---|
| 46 | « constituer une liste pour une campagne e-mail » | `/audiences/new` — le constructeur de segments | **trouvable** |
| 47 | « savoir combien de monde il y a dans mon segment **avant** de l'enregistrer » | `/audiences/new` — prévisualisation continue | **trouvable** |
| 48 | « voir qui est dans mon segment » | `/audiences/$audienceId` → *Membres* — **écran blanc mesuré** (**D22-003**) | **introuvable** |
| 49 | « sortir ma liste en CSV » | `/companies` · `/media` · `/journalists` · `/scraper-runs` → *Exporter* | **trouvable** |
| 50 | « envoyer un e-mail à froid » | `/cold-email` — bouchon « Phase 2 », joignable **seulement par adresse** (**A-005**) | **impossible** |
| 51 | « prospecter sur LinkedIn » | `/linkedin` — même chose (**A-005**) | **impossible** |
| 52 | « trouver un journaliste qui couvre mon sujet » | `/journalists` — recherche **par nom uniquement**, aucun axe thématique | **trouvable avec effort** |

---

## 7. Les intentions de conformité, de réglage et de surveillance

| # | intention | emplacement | marque |
|---|---|---|---|
| 53 | « traiter une demande d'effacement » | `/rgpd/requests` → *Traiter* — mais **B14-002** : la traversée répond « appliqué » et **rien n'est effacé** ; **B10-004** : `candidates` n'est ni exporté ni effacé | **trouvable** *(et trompeur)* |
| 54 | « enregistrer une demande d'accès reçue par courriel » | `/rgpd/requests` → *Nouvelle requête* | **trouvable** |
| 55 | « vérifier qu'un événement est bien arrivé » | `/audit-logs` → recherche (événement, chemin, IP, acteur) | **trouvable** |
| 56 | « m'assurer que le journal n'a pas été trafiqué » | `/audit-logs` → *Vérifier la chaîne* — la garde **ment** (**B16-002 / B16-003**) | **trouvable** *(et trompeur)* |
| 57 | « tenir le registre AI Act à jour » | `/rgpd/ai-act` — **lecture seule**, et l'API rend un **corps figé** (A-002 / B10-013) | **impossible** |
| 58 | « régler qui reçoit quoi / qui a le droit de quoi » | `/users` montre les rôles mais **n'offre aucune action** de modification ; aucun écran ne pose une permission | **impossible** |
| 59 | « inviter un collègue » | `/users` → *Inviter un utilisateur* | **trouvable** |
| 60 | « retirer l'accès à quelqu'un qui part » | nulle part — `DELETE /users/{user}` **existe côté API** et **aucun écran ne l'appelle** | **introuvable** |
| 61 | « changer le plafond de dépenses » | `/settings` → *Workspace* | **trouvable** |
| 62 | « brancher une intégration » | `/settings` → *Intégrations* | **trouvable avec effort** |
| 63 | « passer en thème sombre » | `/settings` → *Apparence*, ou l'en-tête | **trouvable** |
| 64 | « savoir combien me coûtent les IA ce mois-ci » | `/llm/router` → *Usage 30j* — un **0 €** faute de réponse est indiscernable d'un 0 € réel (**D22-002**) | **trouvable avec effort** |
| 65 | « changer le modèle utilisé pour un cas d'usage » | `/llm/router` — **aucune mutation** dans l'écran : lecture seule | **impossible** |
| 66 | « tester qu'un proxy répond » | `/llm/proxy-providers` → *Tester* | **trouvable** |
| 67 | « configurer une rotation de proxies » | `/llm/rotations` — l'écran **dit** « Configure des rotations » et **n'offre aucun moyen de le faire** | **impossible** |
| 68 | « savoir si le système va mal » | `/admin/observability` — reste sur « Chargement… » et **n'a pas d'état d'erreur** (**D22-002**) | **trouvable avec effort** |
| 69 | « savoir si je vais dépasser mon quota Hunter » | `/admin/observability` | **trouvable avec effort** |
| 70 | « voir la fiche d'un média » | `/media/$mediaId` — **écran blanc mesuré** (**D22-003**) | **introuvable** |
| 71 | « respecter l'opposition RGPD d'un journaliste » | `/journalists` **affiche** l'opt-out ; **poser** l'opposition n'est offert nulle part sur cet écran | **introuvable** |
| 72 | « mesurer mes retombées presse » | nulle part — « Couverture France » est la couverture **géographique de prospection**, pas la couverture **presse**. Le mot induit en erreur | **impossible** |

---

## 8. Ce que cet inventaire fait apparaître, et que la grille des écrans seule ne montrait pas

1. **Le produit sait collecter ; il ne sait pas encore travailler.** Les intentions de *collecte* sont presque toutes **trouvables** (37, 38, 39, 40, 41, 43, 44). Les intentions du **quotidien d'un commercial** — voir qui m'a écrit, mes rendez-vous, mes tâches, revenir sur une fiche récente — sont **toutes impossibles** (11 à 14). C'est exactement l'écart que **A-006** chiffre côté navigation : le groupe **ÉCHANGES** entier manque.
2. **Quatre écrans annoncent une action qu'ils n'offrent pas.** `/llm/rotations` (« Configure des rotations »), `/rgpd/ai-act` (un *registre* en lecture seule), `/llm/router` (pilotage sans pilote), `/users` (les rôles s'affichent, rien ne se règle). Ce ne sont pas des écrans vides : ce sont des écrans qui **promettent**.
3. **Trois intentions échouent sur un écran blanc, pas sur un refus** (42, 48, « voir la fiche d'un média ») : l'utilisateur ne conclut pas « je n'ai pas le droit » ni « il n'y a rien », il conclut « c'est cassé ».
4. **Le vocabulaire trahit deux fois.** « Couverture » désigne la géographie de prospection, jamais la presse — alors que le produit a par ailleurs des médias et des journalistes. Et « Profil » mène aux réglages de l'espace de travail. Dans les deux cas le mot promet autre chose que ce qu'il ouvre.
5. **L'entrée dans l'outil est le point le plus fermé de tout l'inventaire** : sur 8 intentions du §1, **5 sont impossibles**. C'est cohérent avec **A-012** — personne ne s'est jamais connecté en production — et cela confirme que le sujet n'est pas « le mot de passe », mais **toute la première connexion**.

---

## 9. Constat complémentaire

### [D22-008] « Profil » et « Paramètres » mènent à la même page, et cette page n'a rien de personnel
- Sévérité      : **S3** finition
- Domaine       : navigation / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/layout/UserMenu.tsx:56-65`
- Constat       : les deux entrées du menu utilisateur appellent `navigate({ to: '/settings' })`, et `/settings` n'expose que *Workspace · Intégrations · Observabilité · Apparence* — aucun onglet de compte personnel.
- Preuve        : lecture de `UserMenu.tsx:59` et `:65` (même destination) ; onglets relevés dans `SettingsPage.tsx` et **confirmés à l'écran** lors de l'ouverture réelle de `/settings` : « Workspace | Intégrations | Observabilité | Apparence ».
- Témoin négatif: le même relevé distingue bien deux destinations différentes ailleurs dans le menu — l'entrée *Déconnexion* appelle `handleLogout()` et non `navigate` : la lecture sait donc différencier les cibles.
- Impact        : la personne qui cherche à changer son mot de passe, sa langue ou son fuseau clique sur *Profil*, atterrit sur les réglages de l'espace de travail, et conclut que la fonction n'existe pas — ce qui, mesuré, est exact (intention 5). Le script `definir-mot-de-passe-crm.sh`, arrivé sur `main` le 2026-08-19, **prescrit pourtant ce geste** : « ⚠️ Si ce mot de passe a transité par un canal non sûr […] le **CHANGER depuis l'interface** une fois connecté ». La consigne renvoie à un écran qui n'existe pas.
- Reproduction  : ouvrir le menu utilisateur, cliquer *Profil*, puis *Paramètres*.
- Correctif     : soit un onglet *Mon compte* dans `/settings` (mot de passe, langue, fuseau, 2FA — ce qui adresserait aussi **D22-001**), soit le retrait de l'entrée *Profil*. Coût **0,25 j** pour le retrait, **0,5 à 1 j** pour l'onglet.
- Statut        : ouvert

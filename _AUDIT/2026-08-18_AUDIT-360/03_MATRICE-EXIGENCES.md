# 03 — MATRICE EXIGENCE → TEST → PREUVE

> Tenue par l'agent 3 (greffier). **Référence : `main = e8924b8`** (code identique à `c0c453d`).
> Une exigence sans test nommé est un trou ; un test sans preuve archivée n'existe pas (règle 10).
>
> Colonnes : **Exigence** (§ du CDC, ligne de plan, fragilité F*, critère §29) · **Ce qui la rejoue**
> (le test, la commande, le geste) · **Preuve** (fichier de `04_PREUVES/`) · **Verdict**.
>
> Verdicts : ✅ **TENU** (mesuré) · ⚠️ **PARTIEL** · ❌ **NON TENU** (mesuré) ·
> 🔵 **NON MESURABLE** (avec la raison) · ⏳ **en cours**.
>
> ⚠️ **Un verdict ✅ exige une commande jouée ET un témoin négatif.** Sans témoin négatif, le verdict
> est ⚠️ au mieux — c'est la règle 3, et la passe adversariale (P5) cherchera précisément les ✅ posés
> sans mesure.

---

## A. Les 15 fragilités du §A.1 du CDC — le préalable à toute étape du §27

⚠️ Le mandat d'audit parle de « F1 → F19 ». **Le §A.1 en compte 15.** Recompté par le chef de chantier
sur le CDC lui-même ; confirmé à l'agent 7, qui publie l'écart.

| # | Fragilité | Exigence de sortie | Ce qui la rejoue | Verdict | Preuve |
|---|---|---|---|---|---|
| F1 | Interface sans aucun test automatisé | Chaque écran couvert par un test de rendu **et** un test de parcours | Agent 44 (harnais) + agent 22 (écrans) | ⚠️ **PARTIEL** — **37 fichiers de test frontend existent** (l'énoncé « 0 fichier » est **périmé**), mais **14 des 16 spécifications Playwright ne sont exécutées par aucun pipeline** (A05-009) | `04_PREUVES/agent-05/`, agent 44 |
| F2 | Collecte quasi sans test | Parcours du §11 couverts | Agent 18 + agent 44 | ⏳ | — |
| F3 | 337 erreurs d'analyse statique suppressées | Baseline à zéro sur les modules du chantier ; interdiction d'y ajouter une ligne | Agent 46 | ⚠️ **l'énoncé est périmé** : baseline = **1 321 l.**, `reportUnmatchedIgnoredErrors: **true**`, `phpstan analyse` niveau 8 → **`[OK] No errors`**. Reste à établir **ce que la baseline cache** | `04_PREUVES/P0/statique.txt` |
| F4 | Console non exécutable en local d'une seule origine (D-11) | Surcouche locale dédiée, sans contournement de sécurité | Chef de chantier, P0.3 | ⚠️ **PARTIEL** — `app.localhost` → 200 et `api.localhost/up` → 200 avec `docker-compose.local.yml`, **mais toute route authentifiée répond 500** (A-001), et l'API est mono-processus (A-009) | `04_PREUVES/P0/etat-local.txt` |
| F5 | 20 mises à jour automatiques en attente | Traitées une par une, **ou** versions figées par décision écrite | Agent 47 | ✅ **TENU** — **0 PR ouverte**, gel des 5 écosystèmes sous **politique écrite de 441 lignes** datée et chiffrée. **Mais 57 alertes subsistent** (dont **0 atteignable en production**, mesuré) | `04_PREUVES/agent-47/` (16 fichiers) |
| F6 | Performance jamais mesurée au volume | Mesure de référence établie et conservée | Agent 41 + journal étape 1a §2.6/§2.11 | ⚠️ **PARTIEL** — mesure existante et rejouable (300 000 et 2 800 000 fiches), mais **sur les compteurs du hub seulement** | `_REPORTS/2026-08-19_MESURE-COMPTEURS-HUB.md` |
| F7 | Deux arbitrages ouverts (`neq`/`not_in` sur NULL ; adresse en clair dans l'opposition) | Tranchés et appliqués ; l'adresse en clair disparaît | Agents 15 et 26 | ⏳ | — |
| F8 | Routes « CRM » partiellement factices | **Retirées ou réalisées** ; aucune route factice sous un nom du CDC | Chef de chantier | ⚠️ **PARTIEL** — `/crm` et `/analytics` **retirés**, avec garde (`PasDeStub501SousCrmEtAnalyticsTest`) ; **`/cold-email` et `/linkedin` conservés à dessein** et joignables par URL (A-005) | `02_CONSTATS.md` A-005 |
| F9 | Isolation par espace durcie récemment | Chaque table re-vérifiée par un test **qui rougit** quand le contexte manque | Agent 11 | ❌ **NON TENU** — **55 tables en FORCE RLS sur 59 portant une colonne d'espace**, mais **`audit_logs` et ses 14 partitions n'en ont aucune** (B11-006, B16-004, S0), **11 tables de données personnelles n'ont pas de colonne d'espace** (B11-007), et **26 tâches planifiées sur 33 + 5 jobs sur 6 tournent sans contexte** (B11-001, B11-002, S0). 🔵 **Le rouge provoqué n'a pas pu être obtenu proprement** (base de test partagée, B11-005) → **à rejouer en P4** | `04_PREUVES/agent-11/` (11 fichiers) |
| F10 | Base locale non reconstruisible de zéro | Une base neuve se construit **en une commande** | Agent 10 (`make db-rebuild-check`, `migrate:fresh` **deux fois**) | ⏳ | — |
| F11 | Analyse d'impact obsolète (17 mai, entité estonienne) | Refaite pour la SAS française, couvrant enregistrements et notation | Agent 15 | ⏳ | — |
| F12 | Pare-feu du serveur non vérifié | `ufw` et `fail2ban` vérifiés et consignés | Agent 40 | ⚠️ **le sujet a été dépassé par les faits** : la faille du 19/08 était **sous** `ufw` (Docker le contourne). Fermée et re-vérifiée depuis l'extérieur ; **seul Caddy publie 80/443** (mesuré par le chef de chantier en lecture seule) | `04_PREUVES/P0/` |
| F13 | Trois défauts du site du 13 août | Chiffrement du chatbot · export sans plafond · statuts Calendly automatiques — **trois tests** | Agents 33 et 34 | ⏳ | — |
| F14 | Navigation du CRM à ranger | Barre ramenée aux groupes du §23.3 ; une seule entrée Contacts ; aucune entrée verrouillée ; visite guidée refaite | Agent 23 | ⚠️ **PARTIEL, et bien plus avancé que le mandat ne le croit** — 6 sections, une seule entrée Contacts, « Collectes », « Journaux de collecte », **aucune entrée verrouillée**. Reste : le groupe **ÉCHANGES** entier, les compteurs, et la visite guidée (A-006) | `02_CONSTATS.md` A-006 |
| F15 | *(voir F14 — le §A.1 fusionne navigation et visite guidée)* | | | | |

**Ce qui « ne doit pas régresser » (§A.1, dernier paragraphe)** — confié à l'agent 8, **rejoué et non relu** :
sauvegardes de production restaurables · décalage horaire corrigé · intégration continue réelle ·
isolation par espace durcie · formulaire du site réparé.
⚠️ Déjà mesuré contre : la CI **n'est pas** entièrement réelle (`composer-audit` n'a jamais audité un
paquet, H47-001 ; les `pnpm-audit` rendent `success` sur 31 et 33 vulnérabilités, H47-002) ; l'isolation
a **régressé ou n'a jamais couvert** `audit_logs` (B11-006).

---

## B. Les 25 critères d'acceptation du §29 — mesurables, sur le volume de référence

> Le §29 impose « un jeu de données au **volume de référence** : 50 000 fiches, cinq ans d'historique ».
> Ce jeu **existe et est versionné** (`backend/database/perf/seed_reference_50k.sql`, 300 000 fiches ;
> `seed_volume_production_4m.sql`, 2,8 M). C'est un acquis réel de l'étape 0, à ne pas redécouvrir.

| # | Critère | Protocole exigé | Verdict | Preuve / raison |
|---|---|---|---|---|
| 1 | Toute personne retrouvable en < 5 s | 20 recherches, 20/20 sous 5 s, à la frappe | ⏳ agent 41/42 | — |
| 2 | Aucun paramétrage courant n'exige d'intervention technique | La liste du §25.4 réglable depuis la console | ❌ **NON TENU** — la console du CRM (§19) **n'existe pas** ; les activités et motifs sont en base mais **sans route ni écran** (journal étape 1a §8.5) | journal 1a |
| 3 | Aucune saisie n'est perdue | Rechargement forcé + coupure réseau sur **6 écrans de saisie**, 6/6 | ⏳ agent 26 | — |
| 4 | Une fiche montre l'intégralité de la relation, **les deux consoles confondues** | 10 fiches au hasard | ❌ **NON TENU** — **0 contact sur 1 319 567 porte une `person_key`** (A05-001) : la fiche 360° est **inatteignable** | `04_PREUVES/agent-05/` |
| 5 | Tout événement traverse en < 1 min | 20 événements par famille, 100 % sous 60 s, 0 doublon après rejeu | ⏳ agents 13/14/49 | — |
| 6 | Toute information se modifie sur place | 10 champs, sans formulaire intermédiaire | ⏳ agent 26 | — |
| 7 | Un enregistrement se réécoute en 2 clics | 5 enregistrements | 🔵 **HORS PÉRIMÈTRE ACTUEL** — étape 5 du §27, rien n'existe | — |
| 8 | Un nouvel utilisateur mène un entretien sans formation | 3 personnes extérieures, 20 min | 🔵 **HORS PÉRIMÈTRE** — l'écran d'entretien n'existe pas | — |
| 9 | Toute donnée personnelle s'exporte et s'efface | Export complet ; effacement propagé sous 60 s ; **anti-réinsertion** | ⏳ agent 15 | — |
| 10 | Chaque parcours du §23.4 tient dans son budget | 13 parcours, ordinateur **et** téléphone, clics comptés | ⏳ agent 24 | — |
| 11 | **Une sauvegarde se restaure** | Exercice daté **avant chaque mise en service** | ⏳ agent 8 / agent 39 — ⚠️ `dr-drill.sh` est l'un des **8 scripts encore en CRLF** (A-003) : inexécutable tel quel sur le serveur | `02_CONSTATS.md` A-003 |
| 12 | Aucun appel enregistré sans accord | Tentative refusée **et journalisée** | 🔵 **HORS PÉRIMÈTRE** — étape 6 | — |
| 13 | Le canal se surveille | Débrancher l'autre console 2 h : 0 perte, alerte, rejeu complet | ⏳ agent 14/31 | — |
| 14 | Aucun écran ne déroge au langage visuel | Revue contre le système de design, **dans les deux consoles** | ⏳ agent 27 | — |
| 15 | Le déroulé de référence du §6.0 tient de bout en bout | Réservation → salle → trame → compte rendu | 🔵 **HORS PÉRIMÈTRE** — étapes 4 et 5 | — |
| 16 | **Les horodatages sont justes** | UTC en base, fuseau de l'utilisateur à l'écran, **testé sur les deux bascules de l'année** | ⏳ agents 8 et 29 — ⚠️ le correctif du décalage de 2 h est **actif en production et inerte en local** (A05-008) : les deux ne mesurent pas la même chose | `04_PREUVES/agent-05/` |
| 17 | **Dix utilisateurs simultanés**, dégradation < 20 % | 10 sessions actives sur les mêmes listes | ❌ **NON TENU — et INATTEIGNABLE PAR CONSTRUCTION.** La **production** sert l'API par `php -S`, **un seul processus**, sans `PHP_CLI_SERVER_WORKERS` : 12 requêtes simultanées forment un **escalier parfait de pas 15 ms** (témoin positif : les mêmes 12 en séquentiel restent plates à 15 ms). Ce n'est pas « non mesuré », c'est impossible tant que le serveur n'est pas changé — **A-010, S0** | `04_PREUVES/P0/prod-concurrence.txt` |
| 18 | **Aucun point de capture n'est muet** | Réservations, soumissions, candidatures, inscriptions vues par la console = événements reçus par le CRM ; **tout écart expliqué ligne à ligne** | ❌ **NON TENU** — **3 événements sur 3 tombés en arbitrage manuel, 0 fiche créée en 5 jours** (A05-003) ; le vivier est **vide** (A05-006) | `04_PREUVES/agent-05/` |
| 19 | Chaque motif d'échange a son écran prêt | 5 activités × 8 motifs, trame pré-chargée | 🔵 **HORS PÉRIMÈTRE** — l'écran d'entretien n'existe pas ; les référentiels existent en base depuis le 19/08 | journal 1a §8 |
| 20 | La console du CRM se prend en main sans formation | 3 personnes extérieures, 5 réglages, < 1 min chacun | 🔵 **HORS PÉRIMÈTRE** — la console du §19 n'existe pas | — |
| 21 | Les échanges se lisent par dossier | Fiche à 3 dossiers, bascule en 1 clic | 🔵 **HORS PÉRIMÈTRE** — les dossiers sont séquencés, non construits | journal 1a §10.2 |
| 22 | Le rappel WhatsApp part et se journalise | Avec consentement / sans | 🔵 **HORS PÉRIMÈTRE** — étape 4 | — |
| 23 | On ne se perd pas entre les deux consoles | 10 actions de la table §22.6, 10/10 ; **aucun écran de relation atteignable dans la console axionia autrement que par redirection** | ⏳ agents 23 et 32 | — |
| 24 | **La barre latérale se comprend seule** | **3 personnes extérieures**, 10 intentions, 10/10 en < 5 s ; **aucun libellé n'a de synonyme ailleurs** | ⏳ agent 23 — substitution `D-007` déclarée : 3 agents sans accès au code, sur captures seules | `05_DECISIONS.md` D-007 |
| 25 | Le retrait des écrans de la console ne casse rien | À chaque palier du §25.1 : parité maintenue, Telegram continue, drapeau à `false` en < 1 min | ⏳ agent 32/34 | — |

**Lecture d'ensemble, à ce stade** : sur les 25 critères, **9 sont hors périmètre** (ils portent sur des
étapes du §27 non ouvertes — c'est normal et ce n'est pas un défaut), **4 sont déjà mesurés NON TENUS**
(n°2, 4, 17, 18), et le reste est en cours.

⚠️ **Trois d'entre eux commandent tout le reste, et aucun n'est un détail :**
- **n°4** — la fiche 360° est **inatteignable** : 0 contact sur 1 319 567 porte une `person_key`.
- **n°18** — le canal est **muet** : 3 événements sur 3 en arbitrage manuel, 0 fiche créée en 5 jours.
- **n°17** — **inatteignable par construction** tant que la production sert l'API par `php -S`.

Les deux premiers disent que **les deux fondations de l'étape 1a ne fonctionnent pas aujourd'hui**.
Le troisième touche un **principe directeur** du CDC, le n°8 (« conçu pour dix utilisateurs dès le
premier jour »), et les principes « priment sur toute fonctionnalité prise isolément » (§0).

---

## C. Les 13 parcours du §23.4 — grille §5.6

*(Rempli par l'agent 24. Chaque parcours : **existe / partiel / absent**, clics comptés sur ordinateur
et en 375 px, comparé au budget du CDC.)*

| Parcours | Budget CDC | Existe ? | Clics mesurés | Verdict |
|---|---|---|---|---|
| Répondre à un message entrant | 2 clics | ⏳ | — | — |
| Créer un contact complet | 1 clic + saisie, **aucun champ bloquant** | ⏳ | — | — |
| Consigner un appel | 1 clic | ⏳ | — | — |
| Lancer la visio d'un rendez-vous | 1 clic | ⏳ | — | — |
| Retrouver ce qui a été dit | < 10 s | ⏳ | — | — |
| Valider un compte rendu | 1 écran | ⏳ | — | — |
| Envoyer le devis après un rendez-vous | 1 clic vers la console | ⏳ | — | — |
| Traiter un appel support | 1 clic + 2 | ⏳ | — | — |
| Prendre un appel de motif inconnu | 1 + 1 clic | ⏳ | — | — |
| Déplacer un candidat d'étape | glisser ou 1 clic | ⏳ | — | — |
| Programmer un rappel | 1 clic + une date | ⏳ | — | — |
| Modifier un questionnaire | aperçu avant publication | ⏳ | — | — |
| Voir depuis la console qui est ce client | 1 clic | ⏳ | — | — |

---

## D. Les défauts D-01 → D-13 du rapport de clôture du 17 août

*(Confié à l'agent 7, même traitement que les fragilités : encore vrai / levé / partiel, sur `e8924b8`.)*
⏳ en cours.

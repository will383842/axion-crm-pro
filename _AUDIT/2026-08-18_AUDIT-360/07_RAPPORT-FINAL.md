# 07 — RAPPORT FINAL

> **Audit 360° d'Axion CRM Pro** — mandat `_PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.md` v2.1.
> **Référence : `main = e8924b8`** (code identique à `c0c453d`). Mesuré le **2026-08-19**.
> Registre complet : `02_CONSTATS.md` · consolidation : `02bis` · décisions : `05` · réserves : `06`.

---

# LE VERDICT, EN UNE PAGE

## Ce que dit l'audit, en une phrase

**Le CRM Pro n'a jamais été utilisé par personne, et c'est ce fait qui explique presque tout le reste :
un produit où nul n'entre ne révèle aucun de ses défauts.**

## La question posée, et la réponse

Le mandat a reformulé sa propre question le 19/08, et c'est la bonne : *l'étape 1a a commencé — sur
quoi le chantier est-il en train de bâtir, et qu'est-ce qui va lui casser dans les mains ?*

**Réponse : il bâtit sur des fondations dont trois sont mesurées cassées, et sur une couche de sécurité
qui est du code mort.** Ce n'est pas un jugement : c'est ce que rendent les commandes jouées.

## Les sept faits qui portent tout le rapport

| # | Le fait | La preuve, en un chiffre |
|---|---|---|
| **1** | **Personne ne s'est jamais connecté au CRM en production.** **Quatre** verrous se referment l'un sur l'autre : mot de passe initial jamais reçu · `MAIL_MAILER` défini nulle part (donc ni lien magique ni réinitialisation) · enrôlement 2FA **sur trois colonnes qui n'existent pas**, qu'**aucun écran n'expose** · et, **derrière les trois autres**, l'écran d'accueil qui **s'efface entièrement — barre latérale comprise — dès qu'`audit_logs` contient une ligne. *Et la connexion elle-même en écrit une.* | 1 compte · **0 session** · **0 jeton**, depuis le 2026-05-17. Flux rejoué : login **200** → écran **403** → 2FA **500**. Et **64 lignes** déjà dans `audit_logs` en production, pour une colonne `event_type` que le code lit `action` |
| **2** | **La couche d'autorisation est inerte.** Aucune des 11 policies n'est jamais appelée. | Les 11 policies **réécrites en refus total** : l'API répond **à l'identique**, **15 tests restent verts**. **118/118 routes sans policy** |
| **3** | **La production ne peut pas porter dix utilisateurs — et pour DEUX raisons indépendantes.** Elle sert l'API par `php -S`, **un seul processus** : les requêtes sont **sérialisées**. **Et la couche base échouerait de toute façon** : sous la RLS réelle, +310 % à 10 sessions. | Escalier de **15 ms** sur 12 requêtes simultanées, témoin séquentiel **plat**. Et **×92** entre la mesure de référence (jouée sous `BYPASSRLS`) et la réalité de production |
| **4** | **Le canal ne crée aucune fiche.** Aucun émetteur du site ne transmet de SIREN, et aucun formulaire n'en collecte. | **100 %** des leads en arbitrage manuel · **0 fiche en 5 jours** · **0 contact sur 1 319 567** porte une `person_key` |
| **5** | **L'effacement n'efface pas.** `erasure` traverse, le site répond « 200 applied », rien ne se passe. Et une personne effacée **revient au vivier** à la candidature suivante. | Export RGPD sur **4 tables sur 31** · effacement sur **8** · `candidates` dans **ni l'un ni l'autre** |
| **6** | **Le journal d'audit ne prouve rien.** Tronquable par la queue sans détection, horodatage hors hachage, et **seule table cloisonnée sans RLS**. | `owner2` de l'espace BETA lit **49 entrées de ALPHA** et `total: 68` = **toute la table** |
| **7 bis** | 🔴 **L'écran d'accueil de la console met 3 minutes, et gèle l'application pour tout le monde pendant ce temps.** Et **taper un mot dans la recherche coûte ≈ 5 minutes de gel** — 61,8 s par frappe, **aucun anti-rebond**, **un seul processus**. | `Rows Removed by Filter: 2 800 000` · **188 518 ms** à froid · et **le commentaire qui justifie cette requête s'appuie sur un index qui n'existe pas** |
| **7** | **Deux portes sont ouvertes sur la base de production, aujourd'hui.** Signature HMAC forgeable (secret **vide**, contrôle **fail-open**, funnel **ouvert**) et **6 services mockés en production**, dont le LLM qui **écrit des classifications fabriquées**. | Mesuré **sur le serveur qui tourne**, pas déduit |

## Le défaut qui explique les autres

**Ce dépôt n'a pas un problème de mesure. Il a un problème de clôture.**

Le rapport pare-feu du 18 août **prédisait la faille du 19 août en toutes lettres** — ports,
contournement d'`ufw`, mot de passe superutilisateur, correctif, commande de vérification, et jusqu'à
la notification CNIL — et se terminait par « **F12 n'est PAS soldé** ». **La ligne a été cochée ✅
par-dessus.** Le lendemain, 4 295 349 fiches étaient joignables depuis internet.

Le motif se répète : le README du harnais écrit « 31 écrans sur 37 restent » ; `vitest.config.ts`
documente ses propres seuils comme « **DÉCORATIFS** » ; `A08-008` était **écrit d'avance**, avec la
date à laquelle il se déclencherait. **Dans chaque cas, l'information exacte existait, écrite, au bon
endroit.** Ce qui a manqué, c'est la relecture — et le tableau de synthèse qui a transformé un
« je n'ai pas pu mesurer » en un ✅.

Et il a un jumeau technique : **dix gardes mesurent le mauvais objet**. La plus retorse porte le nom
du défaut qu'elle laisse passer — `AntiReinsertionTest` **consacre en assertion le réglage exact** qui
fait revenir une personne effacée, et **reste verte pendant qu'elle revient**.

## Le verdict par domaine — pas de feu vert global

| Domaine | Verdict |
|---|---|
| **Données** | 🔴 **Le modèle dit le contraire de sa cible.** Le type vit sur l'organisation, pas sur la personne ; une personne ne peut exister **ni sans entreprise ni sans nom** ; **42 tables sur 102** ne sont nommées par aucun code ; et **aucune route ne crée une fiche personne** |
| **Canal** | 🟠 **Entrant exemplaire, sortant absent, et l'entrant ne crée rien.** **13 événements émis sur 67 exigés**, un seul avec un effet conforme. La famille « Livraison » — **22 événements** — est entièrement vide |
| **Performance** | 🔴 **Le produit est inutilisable au volume qu'il porte déjà.** Écran d'accueil **3 min 08**, recherche **61,8 s par frappe**, deux tris à **93 s** et **15 s** sans index, export **sans plafond** à **1 min 54**, liste contacts **sans pagination** sur 1,3 M. *Tous ces chiffres sont des **planchers**.* Seul point sain : **aucun N+1** |
| **Interface et navigation** | 🟡 **Plus avancé que le mandat ne le croit** : la barre est refondue, 8 des 10 « défauts » listés sont corrigés. Reste le groupe **ÉCHANGES** entier, **tous les compteurs**, et **30 % des intentions sont impossibles** |
| **Conformité** | 🔴 **Le produit n'est pas en règle sur ses fondations.** Intérêt légitime **sans mise en balance ni information art. 14** pour 1 319 567 personnes ; registre AI Act **vide et servi en dur** ; les deux seules purges CNIL correctes **ne s'exécutent jamais** |
| **Sécurité** | 🔴 **Réelle là où on l'a durcie, absente là où elle prouve.** 38 tables en FORCE RLS — mais `audit_logs` non ; **26 tâches sur 33 et 5 jobs sur 6 sans contexte d'espace** ; autorisation inerte |
| **Exploitation** | 🔴 **La production ne peut pas porter le produit qu'on lui construit**, et personne n'y est jamais entré |
| **Tests** | 🟠 **La suite est saine, son câblage ne l'est pas.** 780 tests / 6 503 assertions, PHPStan niveau 8 `[OK]`, **zéro exclusion silencieuse** — mais **4 contextes requis sur 36 jobs**, **267 tests Playwright sur 285 ne tournent nulle part**, et **27 écrans sur 37 ne sont couverts par rien** |

## Ce qui est sain, et qu'aucun correctif ne doit casser

Le **canal entrant** (HMAC vérifié avant le drapeau, **1 témoin positif et 4 négatifs joués**,
idempotence sur index UNIQUE réel, 503 sans écriture) · la **suite de tests backend** ·
**`SocleCrmTest`**, la seule garde qui compare les `CHECK` **réellement en base** aux constantes ·
**203/203 colonnes temporelles en `timestamptz`** · **la sauvegarde se restaure pour de vrai**
(**20 726 338 lignes, écart nul**, policies préservées) · les **deux purges RGPD**, finement gardées ·
et la **pièce 1 de l'étape 1a**, qui retirait un **point de blocage global** sans que personne l'ait vu.

### 🔑 Et un résultat qu'il faut dire aussi fort que les défauts : **la contre-vérification de la règle 7 est POSITIVE**

Le dirigeant avait demandé expressément de **contre-vérifier le travail écrit les 18 et 19 août**
plutôt que de le reprendre. Fait, par **20 sabotages** dont **17 suivis de la suite entière**
(780 tests chacun) :

- **Les rouges annoncés le 19/08 sont reproductibles.** `CompteursHubTest` : **4 sabotages,
  4 rouges, rayon 0 à chaque fois**, et **chacun sur le bon objet**. `ActivitesEtMotifsTest` :
  le sabotage du semis fait rougir **exactement les deux gardes concernées**.
- **Aucun sabotage n'a fait rougir plus de 4 tests par sa propre cause : la suite est PRÉCISE.**
  *C'est la qualité qu'on ne mesure jamais — une suite qui rougit à tout n'apprend rien.*
- **Les deux soupçons les plus lourds du §10 du mandat ne tiennent pas** : ni « le test pré-insère
  ce qu'il doit produire », ni « le mock teste le mock » n'ont **de cas avéré côté backend**.

**En regard, 7 sabotages sur 20 n'ont fait rougir personne** — dont celui qui compte le plus :
la famille de gardes « sans authentification → 401, jamais 500 » **n'interroge que le chemin JSON**.
Mesuré **sur la production** : `Accept: application/json` → **401**, `Accept: text/html` → **500**,
**5 adresses sur 5**. ***A-001 est vivant en production, et la suite le certifie absent.***

---

# CE QUE CET AUDIT N'A PAS FAIT

**Le mandat annonce 50 agents et trois passes. Ce rapport en livre une.** Le dire est la condition
pour que le reste soit utilisable.

| Phase | État |
|---|---|
| **P0** amorçage | ✅ — mais le terrain n'était **pas** praticable : console inaccessible, API sérialisée, `migrate:fresh` en échec au premier passage |
| **P1** fan-out | ⚠️ **34 rapports rendus sur 46 agents** |
| **P2** consolidation | ✅ — puis **révisée** après la critique de complétude |
| **P3** correction | ❌ **0 correctif, 0 PR, 0 test vu rouge en correction** |
| **P4** vérification | ❌ — *mais une vérification croisée réelle a eu lieu dès P1 : le chef de chantier a été **corrigé sept fois*** |
| **P5** adversariale | ❌ **jamais lancée** |
| **P6** regard neuf | ❌ **jamais lancée** |
| **P7** clôture | ⚠️ ce document, `08` et `09` **absents** |

**Définition de fini (§12) : 3 points tenus sur 16.**
**§11 (le geste réel) : non satisfait** — les 37 écrans ont été ouverts **non connectés**, **aucun des
21 parcours n'a été joué**, et **12 captures** existent dans tout le dossier. *La cause n'est pas la
paresse : c'est le fait n° 1 — le produit ne laisse entrer personne.* **Débloquer coûte ~2 h.**

**Les grilles** : ROUTE est **complète** (117 lignes × 18 colonnes, **0 case vide, vérifié et non
cru**) ; TABLE et AUTOMATISME sont remplies ; **ÉCRAN est à 8 points sur 25** (629 cases absentes) ;
**FONCTIONNALITÉ, RACCORDEMENT et PARCOURS n'existent pas**.

**Le plus rentable qui reste** : **~12 h de rédaction sur des mesures déjà archivées** rendraient les
11 policies, les deux bascules d'heure, la non-régression de la console et les `EXPLAIN`.
**Le travail est fait ; il n'est pas écrit.** *Encore un défaut de clôture — le même que celui que ce
rapport dénonce.*

---

# CE QUE L'AUDIT S'EST TROMPÉ, ET COMMENT IL L'A SU

Sept corrections du chef de chantier, **toutes conservées dans le registre avec leur énoncé d'origine** :

| Constat | L'erreur | Ce qui l'a levée |
|---|---|---|
| **A-001** | S1 → **S2** : j'avais généralisé un `curl` nu à « tous les clients ». **Un `curl` n'est pas un navigateur** — le SPA pose l'en-tête `Accept` et n'est pas touché | agent 13, qui a refusé de crier à la réfutation |
| **A-007** | J'annonçais « 56 erreurs/min, 9× le journal ». **C'est 5,5.** Je comptais des occurrences d'une chaîne qui apparaît **7 fois par entrée**. *Le journal de construction avait raison* | agent 40 + re-mesure |
| **A-004** | S2 → **S3** : quota ACME mal compris | agent 40 |
| **Hypothèse RGPD** | **Morte** : les lignes de journal venaient de **la suite de tests**, qui écrit dans le même fichier. *J'avais pris la preuve qu'une garde fonctionne pour la preuve d'un défaut* | agent 13 |
| **A-011 cas 5** | Le cas fondateur du mandat **n'en était pas un** → devenu `A-013` | agent 6 |
| **`02bis` §5** | 🔴 **J'ai reproduit `A-013` dans le document qui le dénonce** | agent 50 |
| **Décompte S0** | J'ai annoncé **12**, puis **16**, puis **26**. Recompté une troisième fois sur le registre complet : **29**. À chaque fois le chiffre était **trop bas**, et à chaque fois pour la même raison : le tableau de synthèse **ne se rouvrait pas** quand de nouveaux rendus arrivaient. La dernière passe a trouvé en outre **un S1 (`A08-008`) compté parmi les S0** et **un groupe entier manquant** (`G7`, la base ne tient pas le volume). *Sous-évaluer trois fois de suite un décompte de S0 dans une synthèse est littéralement le défaut de clôture que ce rapport dénonce sous `A-013`.* | re-comptage, `02bis` §1 bis |

**Quatre sur sept viennent d'avoir généralisé une mesure au-delà de ce qu'elle mesurait.** C'est le
catalogue exact que la doctrine énumère — et les avoir commises malgré cela dit qu'énumérer ne suffit
pas.

**Un registre qui efface ses erreurs ne permet pas de juger ce que valent ses constats non corrigés.**

---

# LA SUITE, DANS L'ORDRE

L'ordonnancement complet et raisonné est au **§4 de `02bis`**. En trois lignes :

1. **Les deux portes ouvertes** (`F37-001`, `F37-002`) — correctif d'**une ligne** pour la première,
   la garde existe déjà dans la classe voisine et n'a jamais été rétroportée.
2. **Sortir la production de `php -S`**, puis **rendre le produit accessible** — sans quoi ni le §11
   du mandat ni le principe 7 du CDC ne peuvent être éprouvés.
3. **Le journal d'audit**, parce que c'est l'instrument de preuve de tout le reste, **y compris de cet
   audit**.

**Et avant tout correctif** : écrire dans `CONTRIBUTING.md` la règle de clôture (`A-013`) et la règle
« une garde ne vaut que si elle rougit **sur l'objet qui casse** » (`A-011`). *Sans elles, P3 produira
des gardes du même genre que celles qu'il corrige.*

**Ce qui revient au dirigeant** est dans `06_RESTE-WILL.md` — une page, dont **un geste qui bloque la
publication de ce dossier** : le dépôt est **public**, et ce rapport décrit **trente-quatre défauts S0 ouverts**
sur une production qui porte **1 319 567 personnes**.

# 02 ter — CONSOLIDATION S1 : le dédoublonnage des constats graves

> Produit en P5 (passe de réfutation), sur documents uniquement. **Aucune pile lancée, aucun conteneur
> touché, aucune requête vers la base ni vers l'API locale** — un autre agent mesure en parallèle et le
> serveur PHP local est mono-processus (`A-009` / `A-010`). Les seules commandes jouées ici portent sur
> des **fichiers Markdown** du dossier d'audit et sur `11_GRILLES/`.
>
> **Référence** : `_AUDIT/2026-08-18_AUDIT-360/02_CONSTATS.md` dans l'état de la branche
> `audit/360-p1-p2`, **3 087 lignes**. Aucun code du produit n'a été modifié.
>
> ⚠️ **Ce document ne vérifie rien.** Il compte, il fusionne et il propose. Chaque constat qu'il cite
> garde son statut d'origine dans `02_CONSTATS.md`, qui reste le registre.

Ce document comble le trou nommé en fin du **§1 bis** de `02bis_P2-CONSOLIDATION.md` :

> « Il **ne compte que les S0**. Le registre porte aussi **132 constats S1**, non dédoublonnés. »

Le §4 du même document ordonne les travaux de P3. **On ne peut pas ordonner ce qu'on n'a pas compté.**

---

## 1. La méthode, énoncée avant le chiffre

C'est la méthode du §1 bis, appliquée à l'identique. Je l'écris d'abord pour qu'un tiers qui ne me
croit pas puisse recompter sans me lire.

1. **On relève tout constat porté S1 par son agent** dans `02_CONSTATS.md` — quelle que soit sa forme
   (tableau, prose, ligne à identifiants groupés), et **on compte la sévérité d'arrivée**, pas celle
   d'origine : une étiquette `S1→S0` n'est plus un S1.
2. **On fusionne les confirmations.** Quand deux agents mesurent le même défaut par deux bancs, c'est
   **une preuve de plus, pas un défaut de plus**. Le registre le dit souvent lui-même : *« confirme »*,
   *« recoupe »*, *« étend »*, *« ex- »*, *« → devenu »*, *« contre-vérifié »*, *« Nᵉ mesure »*.
   Ces marqueurs font une grande partie du travail — mais **pas tout** : trois fusions de ce document
   ont été établies en ouvrant les rapports d'agent et en comparant les **fichiers et les numéros de
   ligne** (§3, notes ①②③), parce que le registre ne les signalait pas.
3. **On écarte ce qui ne vaut pas pour la production**, en le disant, et sans le supprimer : le §1 bis
   traite ainsi `B16-001`. Deux constats S1 sont dans ce cas.
4. **On ne compte pas deux fois un constat composite et ses composants.** Six étiquettes S1 sont des
   composants de défauts **déjà comptés parmi les 29 S0** ; les recompter ici gonflerait le total de
   travail à faire, alors que le correctif est le même et qu'il est déjà ordonné en P3.
5. **On vérifie l'arithmétique à part**, ligne par ligne, et dans les **deux sens** (§4). Le §1 bis a
   été corrigé pour deux erreurs de ce type exact — une somme fausse, et un S1 (`A08-008`) compté
   parmi les S0. Le contrôle symétrique est fait ici : **aucun S0 ni S2 n'entre dans ce décompte**, et
   la liste des étiquettes écartées est publiée pour qu'on puisse la contester.

**Une règle de groupage, et une seule** : un groupe utile est un groupe où **un même correctif ferme
plusieurs constats**. Les groupes sont donc nommés **par le défaut**, jamais par le domaine — et ils
ont été faits émerger de la matière, sans grille préalable. C'est pourquoi il y a un groupe
« rien n'alerte personne » et pas de groupe « observabilité ».

---

## 2. Le relevé brut — **146 étiquettes S1**

Le registre mêle trois formes. Les compter d'un seul motif était l'erreur à ne pas faire : ma première
passe en manquait **trois** (une ligne de tableau à identifiants groupés).

```bash
cd _AUDIT/2026-08-18_AUDIT-360 && export LC_ALL=C && F=02_CONSTATS.md

# A — lignes de tableau a UN SEUL identifiant, cellule de severite contenant S1
grep -acE '^\| *\*{0,2}[A-Za-z]+[0-9]*-[0-9]+\*{0,2} *\| *[^|]*S1' $F
# -> 141      (dont 139 « S1 » pur, 1 « S1 (relevable S0) », 1 « S1->S0 (reclasse, D-012) »)

# B — lignes de tableau a PLUSIEURS identifiants, severite S1
grep -anE '^\| *[A-Za-z]+[0-9]*-[0-9]+,' $F | grep -c '| S1 |'
# -> 1 ligne  = 3 identifiants (G41-003, G41-004, G41-006), ligne 2960

# C — constats en prose
grep -acE '^- \*\*Sévérité\*\* +: +\*\*S1\*\*' $F
# -> 3        (A-007 l.200, A-011 l.408, A-013 l.571)

# D — controle de non-doublon sur A
grep -aoE '^\| *\*{0,2}[A-Za-z]+[0-9]*-[0-9]+\*{0,2} *\| *[^|]*S1' $F \
  | sed -E 's/^\| *\*{0,2}([A-Za-z0-9-]+).*/\1/' | sort -u | wc -l
# -> 141      (141 lignes, 141 identifiants distincts : aucun doublon)
```

| Source | Étiquettes |
|---|---:|
| A — tableaux, un identifiant par ligne | 141 |
| dont **`C18-016`**, porté `S1→S0 (reclassé, D-012)` → **n'est plus un S1** | −1 |
| B — tableau à identifiants groupés (`G41-003, G41-004, G41-006 \| S1`, l. 2960) | +3 |
| C — constats en prose du chef de chantier (`A-007`, `A-011`, `A-013`) | +3 |
| **Relevé brut, sévérité d'arrivée** | **146** |

**Contrôle de non-contamination** — la répartition complète des cellules de sévérité en tableau,
produite d'un seul coup, montre qu'aucune autre étiquette n'a pu m'échapper vers le haut ni vers le bas :

```bash
grep -oE '^\| *\*{0,2}[A-Za-z]+[0-9]*-[0-9]+\*{0,2} *\| *[^|]*' $F \
  | sed -E 's/^\|[^|]*\| *//' | sed 's/\*//g' | sed 's/ *$//' | sort | uniq -c | sort -rn
#   182 S2 · 139 S1 · 33 S3 · 32 S0 · 1 « S1→S0 (reclassé, D-012) » · 1 « S1 (relevable S0) »
```

> **Le §1 bis annonçait « 132 constats S1 ». Le bon chiffre est 146.** L'écart n'est pas une erreur
> du §1 bis : il date d'avant les derniers rendus, exactement comme le tableau S0 qu'il a lui-même
> corrigé pour ce motif. *Un décompte de synthèse qui ne se rouvre pas ment par retard.*

---

## 3. Les groupes — **135 constats, 116 défauts distincts**

Les 11 étiquettes qui ne figurent dans aucun groupe sont listées au **§3 bis** : écartées, bornées, ou
rangées en arbitrage. Elles ne sont pas perdues, elles sont sorties du décompte **et on dit pourquoi**.

| Groupe | Défauts distincts | Les constats |
|---|---:|---|
| **G1 — « Rien n'alerte personne »** | **3** | aucun canal d'échec n'est branché : contrôle d'intégrité à 03:00 vers `/dev/null` (`B16-006`) + **35 tâches sans un seul `onFailure`**, alertes en commentaires (`B17-003`, *« recoupe B16-006 »*) + DSN Sentry posé et `withExceptions()` **vide** (`F39-006`, *« et voilà pourquoi »*) — **3 constats, un seul correctif** · une tâche échoue **71 fois sur 71** sans que rien ne le dise (`A08-001`) · suivre le runbook « disque plein » rend le contrôle **définitivement rouge** (`B16-009`) |
| **G2 — Les commandes destructives ne font pas ce qu'elles annoncent** | **4** | `retention:purge --dry-run` **exécute** l'`UPDATE` et rapporte « 0 » (`B17-001` + `H45-002`, *« confirme B17-001 par une seconde mesure indépendante »*) · deux purges de `companies` **sans contexte, sans dry-run, sans journal, sans test** (`B17-012` + `B15-004` — **① même fichier, mêmes lignes**) · sept commandes destructives, trois planifiées, **zéro test de ce qu'elles suppriment** (`B15-008`) · le script de restauration a pour **cible par défaut la base de production** (`F39-010`) |
| **G3 — L'automatisme tourne, rend `SUCCESS`, et ne fait rien (ou détruit)** | **6** | les 3 imports médias font `DELETE` + réinsertion : **l'enrichissement de la semaine est détruit** (`B17-008`) · mutex à **TTL 24 h**, 10 s de grâce contre 1 min 43 s mesurées (`B17-002`) · `run_maintenance()` **appelé par personne** : rétention 24 mois jamais appliquée, **partitions épuisées en 02/2027** (`B10-003`) · le pont Laravel → Node **rompu par le préfixe Redis** (`C18-011`) · le quota `max_companies` **ne freine rien** (`C18-007`) · **l'arrêt d'urgence n'arrête rien** (`C18-008`) |
| **G4 — Le cloisonnement s'arrête à la porte** *(volet S1)* | **4** | `retention:purge` purge **tous les espaces ou aucun** (`B11-003`) · `email_verification_logs` : la policy permissive survivante rend les **deux espaces** (`A07-002`, **3ᵉ mesure indépendante**) · `sameWorkspace()` compare des UUID castés `(int)` → **toujours vrai** (`B12-012`) · `POST /companies/tags/bulk` **insère sans `workspace_id`**, cassé en production (`G43-004`) |
| **G5 — L'autorisation manque aussi là où le S0 ne va pas** | **3** | le masquage protège **la liste, pas la fiche** (`B12-002` + `F36-006`, *« quatrième mesure »*) · **aucune interface** pour créer un compte, poser un rôle, ou même le voir (`F36-010`) · `/media/export` et `/journalists/export` rendent **500 pour tout ayant droit** : les portes d'opposition RGPD **ne s'exécutent jamais** (`F36-008`) |
| **G6 — Ce qui doit s'effacer, se purger et s'exporter** | **7** | les **deux seules purges RGPD correctement construites ne s'exécutent jamais** (`B17-009`) · l'anonymisation des IP **n'a jamais fonctionné** (`A08-006`) · le « non diffusible » INSEE filtré sur **1 voie sur 3**, purge reconnaissant **1 variante sur 5** (`C19-010`) · jeton d'export **persisté en clair** (`B15-013`) · `escalader_question` écrit l'adresse **en clair et sans hachage de recherche** : introuvable pour un art. 15/17 (`E33-006`) · §21.2 : deux exigences non servies, trois partielles (`B15-014`) · les exports nominatifs **ne laissent aucune trace** (`B16-008` + `B12-010` — **② même middleware, même ligne**) |
| **G7 — Une route répond 200 avec un corps figé** | **4** | le recensement : **dix routes**, dont un contrôle de santé qui dit toujours « en bonne santé » (`B12-007` + `C19-008`, **nommé dedans**) · le **registre AI Act** — un registre réglementaire — vide, `index()` en dur (`B16-007`) · **la recherche globale ne cherche rien** : critère 1 **non tenu** (`I48-002` + `G41-014`) · le funnel répond `{"ingested": true}` **sans rien ingérer** (`C18-001`) |
| **G8 — L'écran dit autre chose que ce qui s'est passé** | **2** | **37 écrans sur 37** rendent un texte **strictement identique sous 403 et sous 500** (`D25-001`) · `/console/arbitrage` **énonce une conclusion métier fausse** — « Tous les événements entrants ont trouvé leur entreprise » quand **100 %** restent en `pending_match` (`D25-003`) |
| **G9 — La console ne permet pas d'agir** | **5** | la fiche 360°, **départ de 6 des 13 parcours**, offre **0 bouton et 0 lien** (`D24-003`) · rattacher un lead exige de **taper l'identifiant numérique interne**, boutons désactivés (`D24-004`) · la cloche « Notifications » **sans gestionnaire de clic** (`D24-002`) · la **pièce 2 de l'étape 1a n'est reliée à rien** (`D24-007` + `I48-004` — **③ même migration, même fichier** ; `D24-007` va plus loin : **aucune clé étrangère**) · **quinze objets à DÉFAIRE, pas à compléter** (`I48-005`) |
| **G10 — Le canal sortant est vide, et son instrument compte les pertes comme des succès** | **7** | le vocabulaire sortant est quasi vide (`B14-001` + `I49-001`, chiffre obtenu **indépendamment** : 13 sur 67) · en production, le canal est **ouvert dans un sens et fermé dans l'autre** (`B14-013`) · l'effacement d'un journaliste **n'émet rien**, dans le contrôleur même qui émet pour l'opposition (`B14-010`) · la **file morte** n'est ni visible ni reprise, et le batch de réconciliation **promis en commentaire** n'existe pas (`B14-003` + `B14-009`) · ce sens **n'a aucune alerte**, et le seul tableau de bord **n'annonce pas son silence** (`B14-004` + `I49-006`) · le site **ne renvoie jamais 503** : 3 h 02 de panne suffisent à perdre un événement (`B14-005`) · **un `gave_up` compte comme émis** (`E32-003` + `E31-004` + `E33-003`, *« E32-003 contre-vérifié, il tient »*) — **3 constats, un filtre de statut** |
| **G11 — Le canal entrant ne crée aucune fiche, et ce qui devrait entrer n'entre pas** | **8** | **aucun émetteur du site ne transmet de SIREN**, et aucun formulaire n'en collecte (`B13-001` + `A05-003`, *« B13-001 corrige et durcit A05-003 »* : « rarement » était **100 %**) · **0 contact sur 1 319 567** porte une `person_key` (`A05-001`) · une personne ne peut exister **ni sans organisation ni sans nom**, et le lead passe **200 avec son adresse détruite** (`B13-002` + `I48-003`, *« cause mécanique de B13-002 »*) · le tunnel `booking` (**20 fichiers**) et 2 outils du chatbot **n'émettent rien** (`E33-001`) · le chatbot **exige** un consentement RGPD et **ne le transmet pas** (`E33-002`) · une demande « Recrutement » **ne peut jamais arriver** : 422 garanti (`E31-002`) · l'opposition vivier **n'est pas transmise**, réponse « ok » (`E31-003`) · §22.5 : **deux magasins d'identité disjoints**, aucun SSO (`I49-004`) |
| **G12 — Le critère ne peut pas faire rougir le défaut qu'il vise** | **1** | le **critère 18** est rédigé en événements **reçus** : il rendrait « écart zéro » sur un CRM qui n'a créé aucune fiche — **il ne peut pas faire rougir `B13-001`** (`I49-007`). *Défaut du cahier des charges, pas du produit : son correctif est une réécriture de critère.* |
| **G13 — Les portes ne bloquent rien, et l'une certifie le défaut** | **8** | `composer-audit` **n'a jamais audité un paquet PHP** (`H47-001`) · **4 contextes requis sur 36 jobs**, `enforce_admins: false`, PR #186 fusionnée **avant la fin de son run rouge** (`F38-002`) · `deploy-staging` déploie **sans aucun test** (`H44-003`) · les **780 tests ne tournent jamais dans la configuration de production**, et **deux tests verts affirment le contraire** (`A08-003`) · les deux contrôles d'isolation du périmètre **rouges et câblés nulle part** (`E34-007`) · le rôle `axion_app` est **global au cluster** : deux `migrate` concurrents se détruisent (`H45-008`) · le canal a rendu la suite du site **rouge 3 jours** (`E34-001`) · 🔴 **`AntiReinsertionTest` consacre comme correct le réglage exact qui produit `B15-001`** (`B15-002`) |
| **G14 — Le déploiement ne mesure pas ce qui tourne** | **7** | la vérification post-déploiement interroge **`127.0.0.1`** quand la pile se lie à `172.17.0.1` : **10 rouges sur 10** sur une préprod à 200 (`F38-001`) · la seule garde qui mesure **les ports réels** ne tourne qu'en préproduction et son code de retour y est **avalé** (`F38-003` + `F40-004`) · un simple `workflow_dispatch` **rouvre la faille du 19/08** (`F38-007`) · le correctif `--no-deps` **jamais rétroporté** en production (`F40-005`) · `DEPLOY-PIPELINE.md` décrit une autre commande et **omet `--no-deps`** (`A09-001`) · **la production exécute une migration absente de `main`** : 59 contre 58 (`F40-006`) · Telescope tourne **sans ses tables** : 270 Mo, ~90 Mo/jour, **100 % du même défaut** (`A-007` + `F40-003`, *« cause racine de A-007 »*) |
| **G15 — La reprise après sinistre livrerait une application qui ne marche pas** | **3** | la sauvegarde restaure **les données mais pas les droits** (`--no-acl`), et le script annonce « Restore complet » (`A08-008`) · le **RPO annoncé est faux d'un facteur 24**, profondeur **3 jours et non 30** (`F39-009`) · le disque sature vers le **6 octobre 2026** (511 Mio/jour) et **aucune garde ne regarde la trajectoire** (`F39-011`) |
| **G16 — L'authentification promet ce qu'elle n'applique pas** | **4** | 🔴 **la 2FA n'est JAMAIS exigée par le serveur** : `2fa_passed_at` écrit une fois, **relu nulle part** (`F35-003`) · `HibpChecker` **fail-open, joué** : réseau coupé → la règle **accepte** (`F35-004`) · **le jeton de réinitialisation n'expire jamais** (différence négative en Carbon 3 : −179,99 et −43 199,98) (`F35-005`) · l'empreinte de corps d'une connexion **contient le mot de passe**, servie à tout compte authentifié (`B16-005`) |
| **G17 — Les secrets et les surfaces publiques** | **4** | le mot de passe Postgres de production est **celui du dépôt public**, et `environment:` **empêche le `.env` de le corriger** (`F40-007`) · la préproduction sert des **pages de débogage de 880 Ko en public** : trace d'appels, chemins, IP clientes (`F37-003`) · la garde SSRF **n'est jamais appliquée à une URL issue de la donnée** — ses 5 appels portent sur une **constante** (`C19-001`) · **la redirection n'est pas re-vérifiée** : cible interne atteinte (`C19-003`) |
| **G18 — La base ne tient pas le volume** *(volet S1)* | **9** | la mesure de référence du §29 jouée **hors configuration de production** : **×92** sur la recherche globale (`G43-001`) · **le critère 17 échoue sur la couche base seule** : +310 % à 10 sessions (`G43-002`) · la liste contacts **sans aucune pagination**, tri à 14,5 s dès 50 000 (`G41-005`) · l'export CSV **sans plafond**, gel ≥ 2 min (`G41-007`, *« confirme `B12-010` par la mesure »* — `B12-010` compté en G6) · les index existants ne servent pas les requêtes exposées : **GIN de 110 Mo sur la mauvaise colonne**, 2 tris sur 4 sans index (`G41-003` + `G41-004`) · le `count(*)` de `paginate` coûte **2,2 s par page** (`G41-006`) · **9 index morts × 2,7 le coût d'écriture** (`G41-008`) · les recherches d'e-mail, **dont 5 sur le chemin RGPD art. 15/17**, sans aucun index : **776×** (`C21-001`) · **aucun mécanisme d'édition concurrente : une saisie disparaît en silence** (`G43-005`, *relevable S0 — §5*) |
| **G19 — L'interface est lourde par construction** | **6** | **aucun découpage par route** : 37 écrans importés statiquement (`G42-001`) · carte de couverture : **1 079 714 o sans `Cache-Control`, téléchargée deux fois**, moteur **802 715 o préchargé sur les 37 routes** (`G42-003`) · **1 fichier sur 31 virtualise** (`G42-004`) · **9 écrans de liste ne demandent aucune limite au serveur** : `/users` à 10 000 lignes = 160 025 nœuds, 18 Mo (`D25-009`) · **aucun anti-rebond** sur 4 champs sur 5 (`G42-010`) · **9 scrutations, dont deux à 5 s** (`G42-007`) |
| **G20 — Le produit est inutilisable sur téléphone** | **4** | **461 cibles tactiles sur 473 sous 44 × 44 px**, dont 82 sous 24 × 24 (`D30-003`) · **9 tableaux exigent 718 à 1 088 px sans conteneur défilable** : 52 à 68 % de chaque ligne inatteignable (`D30-002`) · le conteneur principal **rogne au lieu de laisser défiler** (`D30-001`) · **la barre basse du §23.3 n'existe pas**, et rien n'en tient lieu (`D30-004`) |
| **G21 — La porte d'accessibilité regarde au bon endroit, au mauvais moment** | **5** | la porte `a11y.yml` mesure **quatre écrans VIDES** et n'assert que sur `critical` (`D28-002`) · **9 écrans en `role="row"` sans conteneur ni cellule** : 14 violations critiques **dès qu'il y a des lignes** — *donc jamais visibles sur les écrans vides que la porte mesure* (`D28-001`) · **4 `!important` neutralisent 174 déclarations `dark:`** (`D27-002`, corroboré par une seconde méthode) · **76 défauts de contraste sur 31 écrans** (`D28-011`) · le **lien d'évitement à 1,19:1** (`D28-005`) |
| **G22 — La qualité des données de production** | **4** | **aucune unicité sur `contacts.email`** : 176 218 doublons, **42,93 %** (`C21-003`) · **82,58 % des `quality_score` contredisent la formule** — le trigger n'écoute pas l'`INSERT` (`C21-004`) · **909 086 personnes sans moyen de contact**, `legal_basis` **NULL sur les 1 319 567** (`C21-006`) · le type de relation **n'est porté par aucune personne** (`C21-008`) |
| **G23 — Le modèle de données ment sur ce qu'il fait** | **2** | `deleted_at` **sans le trait `SoftDeletes`** : **44 filtres lisent en suppression douce, Eloquent écrit en dure** (`B10-016`) · `pg_partman` resté dans `public` : `migrate:fresh` meurt, et **le correctif est inatteignable par le chemin censé le réparer** (`B10-001`) |
| **G24 — Le CRM n'est pas bilingue, et le détecteur de langue est actif** | **1** | 27 clés pour **1 417 chaînes en dur**, complétude **~1,3 %** — et un navigateur anglais obtient une console **panachée** (`D29-001`) |
| **G25 — Les résumés mentent là où les artefacts disent vrai** | **5** | le patron : **les gardes mesurent le mauvais objet — quinze cas** (`A-011`) · le patron de clôture : **une case ✅ posée sur un refus de conclure** (`A-013`) · `PROGRESS.md`, lié depuis `README.md`, annonce S3→S12 « pending » (`A09-002`) · `.gitattributes` affirme « plus de divergence » quand 8 `.sh` sur 16 portent des CR (`A09-005`) · la production **affirme la certification Qualiopi** le jour où un commit écrit qu'elle n'est pas délivrée (`E34-003`, *porté à Will comme question*) |

### Les trois fusions établies sur le code, et non sur un marqueur du registre

Le registre ne les signalait pas. Elles ont été tranchées en ouvrant `11_GRILLES/` et en comparant
**fichiers et numéros de ligne** — c'est le seul geste de ce document qui soit sorti du registre.

| ① | `B17-012` (agent 17) et `B15-004` (agent 15) décrivent **les deux mêmes commandes** : `ProspectionPurgeNonCommercial.php:21-25` et `ProspectionPurgeNonDiffusible.php:19-23`, mêmes clauses `WHERE`. **Deux bancs, un défaut, un correctif.** B17-012 apporte *« déjà jouées 5 fois en production le 2026-07-04 »* ; B15-004 apporte le **rayon de souffle : 2 800 000 / 2 800 000**. |
|---|---|
| ② | `B16-008` (agent 16) et `B12-010` (agent 12) portent sur **la même ligne** : `AuditHashChainLogger.php:23`. B16-008 est le plus large (**50 routes `GET` sur 111** muettes, 4 exports) ; B12-010 est le plus précis sur les exports CSV, **et il porte en plus une seconde affirmation** (« aucun plafond »), comptée une seule fois, avec renvoi en G18. |
| ③ | `D24-007` (agent 24) et `I48-004` (agent 48) portent sur **la même migration et le même service** : `2026_08_19_000002_crm_activites_et_motifs.php`, `app/Crm/ActivitesEtMotifs.php`. Le registre le dit à demi (*« plus profond que I48-004 »*) ; la lecture le confirme entièrement. |

---

## 3 bis. Les 11 étiquettes qui ne sont dans aucun groupe — et pourquoi

Elles sont sorties du décompte. Elles ne sont **pas** effacées : chacune reste ouverte à son
identifiant dans `02_CONSTATS.md`.

### a) Six composants de défauts **déjà comptés parmi les 29 S0** — règle 4

| Étiquette | Le S0 qui l'absorbe | Ce que le registre en dit |
|---|---|---|
| `B11-006` | `B16-004` (S0, G1 du §1 bis) | *« recoupe B16-004 et A09-008 »* — et `A-014` tranche : *« cinq agents, cinq chemins, **un seul et même trou** »* |
| `B10-002` | `B16-004` (idem) | *« recoupe B16-002/004, B11-006 »* — même table, même absence de RLS |
| `B17-010` | `B11-002` (S0, G2 du §1 bis) | *« recoupe B11-002 »* — les 5 points de dispatch sans `workspaceId` **sont** les 5 jobs sur 6 |
| `B12-016` | `B15-010` (S0, G6 du §1 bis) | « effacement définitif **sans permission** » = « les routes RGPD n'exigent aucune permission » |
| **`F37-002`** | **`C18-016`, reclassé S0 (`D-012`)** | ⚠️ **Point de vigilance** : le registre porte encore `F37-002` en **S1**, alors que le §1 bis le compte **avec `C18-016` sur une seule ligne S0**. *Le registre et le décompte se contredisent.* Je suis le §1 bis. **C'est un arbitrage, pas une évidence** — cf. §6 |
| `A06-001` | `A-013` (S1, G25) | Le registre écrit lui-même *« → **A-013** »* : c'est le **cas fondateur** du constat de synthèse, pas un défaut de plus |

### b) Deux constats vrais, mais **pas pour la production** — règle 3

Même traitement que `B16-001` au §1 bis : ils restent entiers, ils invalident des **mesures**, ils ne
décrivent **aucun défaut du produit qui tourne**.

- **`D23-001`** — l'atelier local sert une barre latérale vieille de 32 h. **La production est à jour**,
  vérifié par le chef de chantier avec témoin positif *et* négatif (« Journaux de collecte » ×2,
  « Runs de scraping » ×0). *Conséquence pour l'audit (décision `D-011`), pas pour le produit.*
- **`F36-007`** — RLS inerte **en local** : le rôle `axion` est superutilisateur `rolbypassrls`. En
  production, `CRM_DB_APP_ROLE_ENABLED = true` (`B11-010`). *Septième divergence atelier ↔ production.*

> **Si l'on veut un décompte « tout ce qui reste à faire », ces deux-là y rentrent : le total passe de
> 116 à 118.** Ils sont sortis ici parce que le §1 bis compte *« les défauts ouverts et vrais pour la
> production »*, et qu'il faut compter la même chose pour que les deux chiffres se lisent ensemble.

### c) Trois constats rangés en **arbitrage** par le §3 du document maître — décision 7

Le §4 de `02bis` est explicite : *« Ce qui ne va PAS en P3 »*. Ils vont dans `06_RESTE-WILL.md`.

- **`E32-002`** — le canal ne transporte **aucun contenu** : la timeline du CRM est un **index**, par
  conception assumée et écrite dans le code. Retirer les écrans du site perdrait **13 catégories
  d'information**. *Contredit le principe 10 — à trancher, pas à corriger.*
- **`D22-002`** et **`D25-002`** — quand la donnée n'arrive pas, l'écran **affirme « 0 » et « aucun »**
  (12 écrans, puis **23 sur 30** au chiffre affiné). Le rendu final de l'agent 25 établit que ce n'est
  **pas un oubli mais une convention écrite** (`TopDeptsCard.tsx:15` : *« si l'endpoint renvoie 404/500
  ou rien, on tombe sur `EmptyState` »*). *Une convention se tranche, elle ne se corrige pas.*

⚠️ **Je n'ai pas suivi la décision 7 sur `D25-003`, et je le dis plutôt que de le faire en silence** :
cf. §6, point 5.

---

## 4. Le total, et la vérification faite à part

### 4.1 Le compte

**→ 116 défauts S1 distincts, ouverts, et vrais pour la production**, portés par **135 constats**.

### 4.2 La première vérification : la somme des groupes

| G | Déf. | Const. | | G | Déf. | Const. | | G | Déf. | Const. |
|---|---:|---:|---|---|---:|---:|---|---|---:|---:|
| G1 | 3 | 5 | | G10 | 7 | 12 | | G19 | 6 | 6 |
| G2 | 4 | 6 | | G11 | 8 | 10 | | G20 | 4 | 4 |
| G3 | 6 | 6 | | G12 | 1 | 1 | | G21 | 5 | 5 |
| G4 | 4 | 4 | | G13 | 8 | 8 | | G22 | 4 | 4 |
| G5 | 3 | 4 | | G14 | 7 | 9 | | G23 | 2 | 2 |
| G6 | 7 | 8 | | G15 | 3 | 3 | | G24 | 1 | 1 |
| G7 | 4 | 6 | | G16 | 4 | 4 | | G25 | 5 | 5 |
| G8 | 2 | 2 | | G17 | 4 | 4 | | | | |
| G9 | 5 | 6 | | G18 | 9 | 10 | | **Total** | **116** | **135** |

Cumul des défauts, groupe par groupe : 3, 7, 13, 17, 20, 27, 31, 33, 38, 45, 53, 54, 62, 69, 72, 76,
80, 89, 95, 99, 104, 108, 110, 111, **116**.
Cumul des constats : 5, 11, 17, 21, 25, 33, 39, 41, 47, 59, 69, 70, 78, 87, 90, 94, 98, 108, 114, 118,
123, 127, 129, 130, **135**.

### 4.3 La deuxième vérification, **par un autre chemin** — la conservation des étiquettes

Le §1 bis a été corrigé deux fois pour des erreurs de ce type. Une somme qui tombe juste ne prouve
rien si le tableau classe faux. On recompte donc depuis le **relevé brut**, sans regarder les groupes :

```
146  etiquettes S1 relevees (§2)
 − 6  composants d'un S0 deja compte      (B11-006, B10-002, B17-010, B12-016, F37-002, A06-001)
 − 2  bornes a l'atelier                  (D23-001, F36-007)
 − 3  arbitrages, hors lot P3             (E32-002, D22-002, D25-002)
= 135  constats places dans les groupes          <-- identique au total de 4.2  ✅
```

### 4.4 La troisième vérification — le compte des fusions

`135 constats − 116 défauts = 19` étiquettes absorbées par une fusion. Les voici, énumérées :

| Groupe | Fusions | Absorbées |
|---|---|---:|
| G1 | `B16-006`+`B17-003`+`F39-006` | 2 |
| G2 | `B17-001`+`H45-002` · `B17-012`+`B15-004` | 2 |
| G5 | `B12-002`+`F36-006` | 1 |
| G6 | `B16-008`+`B12-010` | 1 |
| G7 | `B12-007`+`C19-008` · `I48-002`+`G41-014` | 2 |
| G9 | `D24-007`+`I48-004` | 1 |
| G10 | `B14-001`+`I49-001` · `B14-003`+`B14-009` · `B14-004`+`I49-006` · `E32-003`+`E31-004`+`E33-003` | 5 |
| G11 | `B13-001`+`A05-003` · `B13-002`+`I48-003` | 2 |
| G14 | `F38-003`+`F40-004` · `A-007`+`F40-003` | 2 |
| G18 | `G41-003`+`G41-004` | 1 |
| | **Total** | **19** |

`135 − 19 = 116` ✅ — **les trois chemins donnent le même chiffre.**

### 4.5 Le contrôle que le §1 bis a dû s'appliquer à lui-même : **aucun S0, aucun S2 dans ce décompte**

- Les **32 étiquettes S0** du registre : aucune n'apparaît dans un groupe ci-dessus. Les S0 sont
  **cités** comme absorbants au §3 bis (a), jamais comptés.
- Les **182 étiquettes S2** et les **33 S3** : aucune n'apparaît. Les S2 nommés dans les colonnes
  « les constats » (`B11-004`, `B10-005`, `B10-008`, `B15-005`, `A-003`, `B12-006`, `A06-012`,
  `H45-010`, `B15-012`) le sont **en contexte de fusion**, sans jamais entrer au décompte.
- `C18-016` (`S1→S0`) est **exclu** ; `G43-005` (`S1 (relevable S0)`) est **inclus**, parce que son
  étiquette d'arrivée est S1 — et sa relève est **proposée** au §5, pas décidée ici.

### 4.6 Ce que ce décompte ne dit pas

- **Il ne vérifie rien.** Comme le §1 bis, il consolide le meilleur état de la mesure. La vérification
  est P4, la réfutation P5 — ce document **est** de P5, mais sa passe porte sur le **compte**, pas sur
  les mesures : je n'ai joué aucune commande contre le produit.
- **Les deux chiffres qui comptent ne sont pas le même.** Comme au §1 bis : **116** est le nombre de
  **correctifs** (un défaut = un correctif, c'est le chiffre pour ordonner P3) ; **135** est le nombre
  de **constats**, et c'est le chiffre pour juger la solidité de l'audit — **19 défauts graves ont été
  trouvés au moins deux fois, par des agents qui ne se parlaient pas.**
- **La majorité de ces 116 n'a pas été mesurée sur la production.** Les exceptions nommément mesurées
  sur le serveur : `A-007`, `F40-003`, `F40-005`, `F40-006`, `F40-007`, `F37-003`, `C21-001`,
  `C21-003`, `C21-004`, `C21-006`, `C21-008`, `A08-001`, `F39-009`, `F39-011`. Tout le reste est sur banc.
- **`F36-007` borne une partie du reste** : toute mesure d'étanchéité S1 faite en local (G4 en
  particulier) l'a été **sans le dispositif de production**. C'est le §5 bis du dossier commun,
  appliqué au décompte S1.

---

## 5. Les reclassements proposés

**Je ne reclasse rien** : je propose, avec l'argument. L'échelle du dossier est
**S0 bloquant · S1 grave · S2 défaut · S3 finition**, et le §8 la précise :
**S0 = perte de données, faille, non-conformité RGPD, indisponibilité, blocage du chantier cible.**

### 5.1 S1 → **S0** — six propositions

| # | Constat | L'argument, pas l'opinion |
|---|---|---|
| **1** | **`G43-005`** — aucun mécanisme d'édition concurrente | L'agent l'a lui-même étiqueté *« S1 (relevable S0) »* — la seule étiquette du registre qui appelle sa propre relève. **Deux sessions réellement simultanées rendent toutes deux `UPDATE 1` : une saisie disparaît en silence.** C'est **perte de données**, mot pour mot le premier critère S0 du §8. **Témoin positif joué** : la même séquence avec `AND updated_at = <lu>` rend `UPDATE 0`. L'objection est qu'il est *armé, pas déclenché* — personne ne peut se connecter (`A-012`). **Mais `B15-001` est armé, pas déclenché, et le chef de chantier l'a classé S0** au motif qu'il *« se déclenchera au moment exact où l'on ouvrira le vivier »*. Le même raisonnement, appliqué au même critère, donne S0 ici. **C'est une question de cohérence de l'échelle, pas de gravité perçue.** |
| **2** | **`A08-006`** — l'anonymisation des IP n'a **jamais** fonctionné | `ip::cidr / 24` n'est pas un opérateur valide : la tâche RGPD **n'a jamais produit un seul effet, depuis le premier jour**. §8 : **non-conformité RGPD = S0**. Le chef de chantier a classé `C19-007` S0 pour exactement ce motif (une obligation légale non tenue, sur les mêmes 1 319 567 personnes). **Un manquement de rétention qui n'a jamais tourné n'est pas moins grave qu'une base légale non écrite** — il est simplement moins visible. |
| **3** | **`B17-009`** — les deux purges RGPD correctement construites **ne s'exécutent jamais** | *« L'échéance CNIL 2 ans / 3 ans n'est tenue par aucun automatisme. »* Le rapport de l'agent 15 donne la cause : leur gate `CRM_PURGE_ENABLED` vaut **`false` par défaut** (`config/crm.php:121`) et le `skip()` du planificateur les saute. Même argument que le n° 2 : **une durée de conservation qui n'est appliquée par rien est une non-conformité RGPD**, donc S0 par l'échelle. **Aggravant** : ce sont, d'après l'agent 45, **les deux commandes les mieux gardées du dépôt** — le travail est fait, il ne tourne pas. |
| **4** | **`E33-006`** — `escalader_question` écrit l'adresse **en clair et sans hachage de recherche** | **Le registre le dit lui-même** : *« même mode de panne que `B10-004` »*. Et **`B10-004` est S0** (`candidates` ni exportée ni effacée). Mode de panne identique, personnes concernées réelles, conséquence identique : **ces personnes sont introuvables pour un article 15 ou 17**. *Deux constats qui décrivent le même mode de panne ne peuvent pas porter deux sévérités différentes* — c'est le genre d'écart que la relecture de l'échelle existe pour attraper. |
| **5** | **`F40-007` + `F38-007`, en paire** — le mot de passe public, et le bouton qui rouvre le port | Pris seuls, chacun est S1 et le classement est juste : le mot de passe du dépôt public n'est **plus joignable** depuis la fermeture des ports du 19/08, et le workflow de diagnostic **ne tourne pas de lui-même**. **Pris ensemble, ils ne le sont plus** : `F38-007` recrée la production **sans l'overlay** sur un simple `workflow_dispatch` — c'est-à-dire **un bouton GitHub** — et `F40-007` garantit qu'au bout du port ainsi rouvert se trouve le mot de passe `axion_dev_only` du dépôt public, sur un rôle **SUPERUSER + BYPASSRLS**, `environment:` **empêchant le `.env` de le corriger**. **C'est la faille du 19 août, reconstituée en un clic.** `A-013` a établi qu'elle était écrite en toutes lettres la veille ; **elle est aujourd'hui réarmable en une action, et le dossier la classe deux fois S1.** → **S0 sur la paire**, ou à défaut sur `F38-007` seul, qui est la gâchette. |
| **6** | **`F35-003`** — la 2FA n'est **jamais** exigée par le serveur | `2fa_passed_at` est **écrit une fois et relu nulle part** : le second facteur est **contournable par construction**. Le produit **exige** l'enrôlement 2FA avant tout usage (`A-012`, mesuré : `403 first_login_required`) — il exige donc un facteur qu'il **n'applique pas**. Avec `F35-005` (le jeton de réinitialisation n'expire **jamais**) et `F35-004` (`HibpChecker` **fail-open**, joué), **les trois pièces du durcissement d'authentification sont décoratives**. §8 : **faille = S0**. ⚠️ **Objection honnête** : aujourd'hui personne n'a de 2FA active, puisque l'enrôlement écrit trois colonnes inexistantes (`A07-001`). Le défaut est **armé pour le jour où G4 sera corrigé** — et G4 est le **rang 2 de P3**. *Le corriger après aurait pour effet d'ouvrir la console avec un second facteur qui n'en est pas un.* |

### 5.2 S1 → **S2** — quatre propositions

| # | Constat | L'argument |
|---|---|---|
| **1** | **`A09-005`** — le commentaire de `.gitattributes` affirme « plus de divergence » | Le registre écrit lui-même *« recoupe `A-003` »* — et **`A-003` est S2**. Un constat qui re-mesure un défaut ne peut pas être **plus grave** que le défaut qu'il re-mesure. **S2, par cohérence avec sa propre référence.** |
| **2** | **`A09-002`** — `PROGRESS.md` annonce S3→S12 « pending » | Aucune fonction ne ment, aucune donnée n'est en jeu : un document figé au 2026-05-16, lié depuis `README.md`. Le registre porte **`A09-009`** (« `TODO.md` se déclare source de vérité et décrit un dépôt d'avant la première ligne de code ») en **S2** — *défaut identique, document identique, sévérité différente.* **S2.** ⚠️ Le contre-argument existe et il est sérieux : `A-013` établit que **ce sont les couches de résumé qui mentent**, et c'en est une. Mais alors c'est `A-013` qui le porte, et il est déjà compté. |
| **3** | **`E34-001`** — le canal a rendu la suite du site rouge pendant 3 jours | **C'est un constat historique** : la PR ajoute l'appel, le mock arrive 3 jours plus tard, **et l'incident est clos**. Le §8 définit S1 au présent : *« une fonctionnalité qui **ment** ou **ne marche pas** dans un cas courant »*. Rien ne casse aujourd'hui. **S2** — ou, mieux, un étiquetage « constat historique », que l'échelle du dossier ne prévoit pas encore. |
| **4** | **`I48-005`** — quinze objets du code contredisent la cible : ils devront être **défaits** | Ce n'est pas un défaut du produit : c'est **le coût de la cible**, un inventaire de dette de conception assumée. Rien ne ment, rien ne casse. Le constat est **précieux** — l'agent note lui-même que *« six d'entre eux sont une seule question posée six fois »* — mais il **n'a pas de correctif** : il a un arbitrage et un budget. **S2**, ou versement à `06_RESTE-WILL.md` comme `I48-008` (déjà fait pour ce dernier). |

### 5.3 Une observation qui ne donne pas lieu à proposition

**`D25-003`** — l'écran d'arbitrage qui **énonce une conclusion métier fausse** — est le seul constat
d'interface du registre dont la description contienne un mécanisme de **fabrication** d'information, et
non d'omission : *« les écrans qui affirment « 0 » sur une erreur **taisent** une information ;
celui-ci **en invente une** »*. Sur une échelle où S0 = « faille », un écran qui affirme à l'opérateur
*« Tous les événements entrants ont trouvé leur entreprise »* quand **100 %** sont en `pending_match`
n'est pas une faille technique. **Je ne propose donc pas S0 — mais je signale que sa classification en
« arbitrage » (décision 7) me paraît le mauvais rangement**, et c'est l'objet du §6-5.

---

## 6. Ce que je n'ai pas pu trancher

Sept points. Je les déclare plutôt que de deviner : dans cet audit, trois agents ont refusé de conclure
et leurs refus ont été validés comme du travail supérieur.

**1 — Les étiquettes de sévérité mixte sont indécidables telles qu'écrites.**
Six lignes du registre portent plusieurs identifiants sous **une sévérité composite** :
`E31-008, E31-011 → E31-013 | S2/S3` · `D27-001, D27-013 | S2/S3` ·
`F35-008, F35-011, F35-013, F35-014 | S2/S3` · `D28-009, D28-010, D28-006, D28-012, D28-013, D28-014 | S2/S3` ·
`G41-009 → G41-015 | S2/S3` · `A06-005, A06-006, A06-009, A06-011, A06-013 | S3`.
**Aucune ne porte S1, donc aucune n'entre au décompte.** Mais **aucune ne dit non plus quel
identifiant porte quelle sévérité** : si l'un d'eux était en réalité S1, mon relevé le manquerait, et
il le manquerait *en silence*. **Le trancher demande les rapports d'agent, un par un.** Je ne l'ai pas fait.

**2 — La plage `G41-009 → G41-015` recouvre `G41-014`, qui est porté S1 ailleurs.**
Ligne 2956 : `G41-014 | S1`. Ligne 2961 : `G41-009 → G41-015 | S2/S3`, **sept identifiants annoncés
pour cinq éléments décrits**. J'ai retenu la ligne nominative (`G41-014` = S1) et n'ai compté aucun
autre S1 dans la plage. **Je ne peux pas dire si la plage est inclusive, ni si elle est exacte.**

**3 — `F37-002` : le registre et le §1 bis se contredisent, et j'ai dû choisir.**
Le registre le porte **S1** (l. 1985). Le §1 bis le compte **avec `C18-016` sur une seule ligne S0**
(« 6 services mockés en production (`C18-016` / `F37-002`) »), et le §4 le met en **rang 0 bis** de P3
— c'est-à-dire **devant tout le reste**. J'ai suivi le §1 bis et l'ai écarté du décompte S1. **Si le
chef de chantier tranche l'inverse, mon total monte à 117.** *Une étiquette qui vit à deux sévérités
dans deux documents du même dossier est exactement ce que la règle de clôture d'`A-013` demande de
fermer.*

**4 — `A-011` : je le compte une fois, et l'inverse serait défendable.**
`A-011` relie **quinze** gardes, dont au moins onze portent un identifiant propre au registre
(`D28-002`, `E32-003`, `B15-002`, `E34-007`, `G42-013`, `F39-002`, `C19-008`, `H45-001`, `H45-003`,
`H45-004`, `H45-007`). Il déclare lui-même *« il ne remplace aucun constat d'agent, il les relie »*.
Je l'ai compté **une fois**, en G25, parce que **son correctif est distinct** : la règle écrite dans
`CONTRIBUTING.md`, qui est le **rang 0 de P3**, et qui ne répare aucune garde. **Si l'on préfère
compter les gardes et pas le patron, le total baisse de 1.** Le compter **et** ses composants, en
revanche, serait la faute que la règle 4 interdit.

**5 — `D25-003` : je n'ai pas suivi la décision 7, et je le dis.**
La décision 7 du §3 range *« `E32-002` **et** `D25-002`/`D25-003` »* en **arbitrages remontés au
dirigeant**. J'ai suivi pour `E32-002` et `D25-002` (et `D22-002`, sa première mesure) : ce sont des
**conventions écrites et assumées**, et le rendu final de l'agent 25 le démontre par le commentaire de
`TopDeptsCard.tsx:15`. **Je n'ai pas suivi pour `D25-003`** : la convention consiste à *retomber sur
`EmptyState`*, pas à **écrire une phrase qui affirme le contraire de la vérité**. Le registre le nomme
lui-même *« le pire défaut d'interface de l'audit »* et *« d'une autre nature que les autres »*.
**Je le compte comme défaut (G8) et je signale l'écart. C'est au chef de chantier de trancher, pas à
moi** — et si sa décision tient, mon total baisse de 1 (115) et l'arbitrage en gagne un.

**6 — Trois constats de couverture ne sont pas des défauts, et je les compte quand même.**
`B15-014` (« §21.2 : deux exigences non servies, trois partielles »), `I48-005` (« quinze objets à
défaire ») et, dans une moindre mesure, `B14-001` (« 3 déclarés, 2 produits, 1 atteignable ») sont des
**inventaires**, pas des pannes : ils **recouvrent partiellement** d'autres lignes du même groupe et
**ne se ferment par aucun correctif unique**. Je les compte pour ne pas les perdre — mais je ne peux
pas dire, sans ouvrir le §21.2 exigence par exigence, **combien de leurs composants sont déjà comptés
ailleurs**. C'est le seul endroit où mon chiffre est probablement **trop haut**, et je ne sais pas de
combien.

**7 — Quatre fusions restent des hypothèses de lecture, faute de mesure.**
Les trois fusions du §3 (①②③) ont été **vérifiées sur les fichiers et les numéros de ligne**. Les
quatre suivantes reposent sur la seule lecture des énoncés, et **les trancher demanderait de jouer le
canal, ce que le cadre de ce document m'interdit** :

| Fusion supposée | Ce qui manque pour la trancher |
|---|---|
| `B14-003` (file morte jamais reprise) + `B14-009` (batch de réconciliation promis, inexistant) | Savoir si le batch manquant **est** le mécanisme de reprise, ou un second mécanisme distinct. Le registre ne le dit pas. Un seul correctif ou deux ? |
| `B14-004` (aucune alerte sur le sens sortant) + `I49-006` (le tableau de bord n'annonce pas le silence) | Le premier est côté CRM (`CRM_SYNC_ALERT` n'existe pas), le second côté site (`inbound.lastAt` calculé, jamais comparé). **Deux dépôts.** Ils pourraient être deux défauts. |
| `E31-002` (« Recrutement » : 422 garanti) + `E31-003` (opposition vivier non transmise) | Deux mécanismes différents (version de consentement / flux fermé) pour un même symptôme (« ok » sans effet). Je les ai comptés **séparément** — l'inverse est défendable. |
| `C21-006` (moitié « `legal_basis` NULL sur 1 319 567 ») ↔ `C19-007` (**S0**) | Le registre écrit *« matière directe de `C19-007` »*. Si cette moitié est absorbée par le S0, `C21-006` perd la sienne et mon total baisse de 0 ou 1 selon le découpage. **Composite : je l'ai compté une fois, entier.** |

**Et une chose que je n'ai pas cherchée, faute de mandat** : je n'ai pas rejoué les **preuves** de
`04_PREUVES/`. Ce document consolide **les étiquettes du registre**, pas les mesures qui les portent.
Un constat S1 fondé sur une preuve fausse compte ici comme un défaut. C'est le travail de P4 et de la
suite de P5 — et c'est la limite qu'il faut lire avec le chiffre 116.

---

## 7. Ce que le chiffre change pour l'ordonnancement de P3

Une remarque, et une seule, parce que le §4 de `02bis` est le document qui ordonne et que ce n'est pas
le mien.

Le §4 range les S1 au **rang 11** (« S2 et S3, par domaine — après »), c'est-à-dire nulle part.
**Six des 116 défauts S1 ci-dessus sont proposés S0 au §5**, et **quatre d'entre eux tombent dans des
lots déjà ordonnés** :

- `F35-003` (la 2FA jamais exigée) tombe dans **G4, rang 2** — et le corriger *après* G4 reviendrait à
  ouvrir la console avec un second facteur qui n'en est pas un ;
- `A08-006` et `B17-009` (les deux obligations de rétention jamais exécutées) tombent dans le **rang 5**
  (`G3` + `B10-004`), qui porte déjà l'effacement et l'export ;
- `F38-007` + `F40-007` (la faille du 19/08 réarmable en un clic) **n'a de rang nulle part**, alors que
  le §4 place le rang **0 bis** sur le motif exact — *« deux portes ouvertes sur la base de production »*.

Et un chiffre pour l'échelle : **G18 (la base ne tient pas le volume) porte 9 défauts S1** à lui seul,
en plus des **2 S0** du groupe G7 du §1 bis. Le rang 1 du §4 le dit déjà — *« corriger `A-010` seul ne
suffira PAS »* — mais il ne le chiffre qu'à `G43-001`. **Il y en a neuf.**

---

*Fin. Ce document ne modifie ni `02_CONSTATS.md` ni `02bis_P2-CONSOLIDATION.md`. Aucune commande n'a
été jouée contre le produit, la base, Docker ou l'API locale. Aucun commit, aucune PR.*

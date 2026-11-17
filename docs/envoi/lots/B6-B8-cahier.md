# CAHIER — B3-bis verdicts, L7 bout en bout, L8 nettoyage, B8 écran campagne

**Pour le développeur :** dépôt public. Dans le code, les tests, les commits et les PR : écrire « le fondateur », jamais de nom ; données fictives en `*.example.test`.
Aucun drapeau touché : `ENVOI_REEL_ACTIF` reste faux ; `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`, `USINE_AGREGATS_CRM_ENABLED` ne sont ni lus ni écrits ;
`config/envoi.php` et les `.env*` inchangés. Aucune suppression ni purge de données de personnes (une inscription fermée passe `arretee` et reste en base ; une fiche va en corbeille douce, jamais `DELETE`).
Échec fermé partout : une personne opposée n'est jamais envoyée ni réinscrite.
Base relevée : `main` 454d333 (C3-5 #668). Cahiers lus : `L5-L6-cahier.md` (#649), `VERDICTS-cahier.md` (#607), `X1-L4-P2-cahier.md` (#609), `PLAN-FIN-ENVOI.md`. **Vérifier chaque numéro de ligne avant de s'y fier.**

PR ouvertes relevées avant d'écrire : **#669 (L6-C)** touche `frontend/src/features/envoi/campagnes/CampagneSuiviPage.tsx`, `frontend/src/demo/handlers/envoi/campagnes.handlers.ts`, `frontend/tests/screens/envoi/CampagneSuiviPage.test.tsx` ⇒ B8-B attend sa fusion.
#354, #352 hors Envoi ; les brouillons #607 #608 #609 #617 #618 #619 #648 #649 ne touchent que `docs/`.

---

## 0. Étape 1 — ce qui est DÉJÀ fait (preuves) et ce qui manque

### 0.1 B3-bis — écrivain des verdicts d'adresse

| Élément demandé | État sur `main` | Preuve |
|---|---|---|
| « `EssaiABlanc.php` seul écrivain » | **N'est plus vrai.** L'écrivain persistant existe (VERDICTS-CODE-1 #614). `EssaiABlanc` n'écrit que dans sa transaction annulée (verdict `essai`). | `app/Envoi/Verdicts/EcrivainVerdicts.php:13` (« L'unique écrivain »), `:67` (INSERT) ; `app/Envoi/Orchestration/EssaiABlanc.php:271` |
| Vérification sans coût (syntaxe, jetable, rôle, DNS) | fait | `app/Envoi/Verdicts/VerificateurInterne.php` ; quota `QuotaDns.php` |
| Rafraîchissement planifié (inscriptions échues sous 3 jours) | fait (VERDICTS-CODE-2) | `routes/console/envoi-verdicts.php:10` ; `app/Envoi/Verdicts/RafraichissementVerdicts.php:119` |
| Rôle applicatif sans UPDATE/DELETE sur les verdicts | fait | migration `2026_12_05_000510` (VERDICTS-CODE-1.md) |
| `EnvoiAutorise` exige le verdict | fait | `app/Envoi/Gardes/EnvoiAutorise.php:172-174`, `:260-281` (dernier verdict, < 30 jours, jamais du futur) |
| **Préparation appelée par la matérialisation** (« L4 n'inscrit que si `permetInscription` ») | **MANQUE.** Aucune classe de `app/Envoi/Campagnes/` n'appelle `PreparationVerdicts`. | `grep -rn PreparationVerdicts app` : seul `app/Envoi/Verdicts/PreparationVerdicts.php:12` (docblock « appellera ») |
| **Verdict absent ⇒ report, pas d'arrêt** | **MANQUE, et c'est pire qu'un report sans fin** : aucun verdict ⇒ refus DÉFINITIF (inscription `arretee`, `garde:verdict_adresse_absent_ou_perime`, jamais recréable). Une inscription échue avant le passage du rafraîchissement (minute 17 de chaque heure) est donc perdue. | `Sequenceur.php:424-437` (`passager` : reporte seulement si le dernier verdict est `valide`) ; `RefusSequenceur.php:39` (`VERDICT_ABSENT` dans `DEFINITIFS`) ; `MaterialisationCampagne.php:160-178` (échéance posée dès l'inscription) |
| Ordre de la porte | l'opposition STOP / art. 21 / liste de refus est contrôlée **après** le verdict | `EnvoiAutorise.php:188-196` (« TOUJOURS EN DERNIER ») ⇒ une personne opposée SANS verdict reçoit le motif `VERDICT_ABSENT`, pas l'opposition |

⇒ Lots **B3-B** (séquenceur) puis **B3-A** (matérialisation).

### 0.2 L7 — bout en bout de la campagne

| Élément | État | Preuve |
|---|---|---|
| Essai à blanc (inscription → faux serveur → rebond, STOP → étape 2 jamais) | fait, mais **sans campagne** (inscription directe) et tout annulé | `tests/Feature/Envoi/EssaiABlancTest.php:67`, `:81` |
| Lancer une campagne ⇒ séquence reprise ; porte fermée ⇒ rien | fait (L5-A) | `tests/Feature/Envoi/GestesCampagneSequenceTest.php:161` |
| Arrêt d'une campagne ⇒ inscriptions fermées | fait (L5-B) | `tests/Feature/Envoi/ClotureInscriptionsCampagneTest.php` |
| Réponse humaine ⇒ arrêt de séquence | fait (unitaire chaîne) | `app/Envoi/Imap/RecepteurRetoursSequence.php:35-46` ; `RattachementReponseD2Test.php:141` |
| Rebonds / plaintes (VERP, ARF, `abuse@`) | fait (relève seule) | `docs/envoi/rebonds-plaintes-k11.md` ; `RebondsPlaintesK11Test.php` |
| **Chaîne entière campagne → matérialisation → verdicts → lancer → séquenceur → faux serveur → relève → arrêt, sur 9 scénarios** | **MANQUE** : aucun test n'enchaîne `MaterialisationCampagne`, `Sequenceur` ET `ReleveImap` (relevé : seul `GestesCampagneSequenceTest` matérialise, et il ne relève rien) | balayage `tests/Feature/Envoi/*` |

⇒ Lots **L7-A**, **L7-B**, **L7-C**.

### 0.3 L8 — nettoyage

Aucun lot « nettoyage » n'existe. Relevé de ce qui est périmé (sans rien supprimer de données) :
- commentaires faux : `app/Envoi/Retours/AppliquerRetour.php:7` (« implémentée plus tard » : implémentée par `Chauffe/CompteurRetours.php:18`, liée `EnvoiServiceProvider.php:100`) ; `app/Envoi/Imap/RecepteurRetours.php:6` (« L1 n'est pas encore là ») ; `app/Envoi/Verdicts/PreparationVerdicts.php:11-12` (sera vrai après B3-A) ;
- documentation : `docs/envoi/rebonds-plaintes-k11.md` § « Dette connue » (« Le séquenceur lui-même n'est pas branché au planificateur » : faux depuis L8-ORCH, `routes/console/envoi-orchestration.php`) ;
- branches de transition : `RecepteurRetoursSequence.php:53` (« Branches 2 et 3 à retirer ») et `RegistreMessagesEnvoyesDb.php:40` (« Branche à retirer en fin de transition ») : leur retrait dépend des lignes d'ancienne forme EN PRODUCTION ⇒ constat d'abord (L8-B), retrait seulement après décision (ESCALADE 4).
- `TransportSmtpTunnel` : **pas mort** (encore référencé par `ServeurEnvoiKumo.php`) : ne pas y toucher.

⇒ Lots **L8-A**, **L8-B**, et L8-C conditionnel.

### 0.4 B8 — écran campagne

| Élément | État | Preuve |
|---|---|---|
| Bandeau « préparation bloquée » dans le suivi | **partiel** : seulement si une ligne de matérialisation existe ; liste de motifs incomplète | `CampagneSuiviPage.tsx:208-235` (`MOTIF_PREPARATION` : 5 motifs ; manquent `jours_non_applicables`, `objet_b_non_applicable`, `etapes_invalides`, `sequence_incoherente`) |
| « Bloquée, pourquoi » pour `reglage_non_applique` | **MANQUE** : ce refus est levé AVANT toute écriture, aucune ligne de matérialisation ⇒ `preparation: null` ⇒ l'écran ne dit rien, la campagne reste « programmée » pour toujours | `MaterialisationCampagne.php:69-71` ; `EnvoiCampagnesMaterialiser.php:109-110` |
| « Bloquée, pourquoi » dans la **liste** | **MANQUE** | `LectureCampagnes.php:125-138` (`exposer` : aucun champ de blocage) ; `composants/TableauCampagnes.tsx` |
| « Joignables » vrai | **MANQUE** : `joignables` = `total` en dur | `AssistantCreation.php:531`, `:535`, `:540`, `:545` ; écran `creation/EtapeCible.tsx:85-86` |
| Estimation | partielle : total − opposées, `exclusions_evaluees: false` | `AssistantCreation.php:237-245` |
| Pré-remplissage par audience | **MANQUE** : le lien envoie `?audience=` mais la page ne lit que `etiquette`, `entreprise`, `brouillon` ; et `cibles()` ne liste que 50 audiences par nom | `features/audiences/AudienceDetailPage.tsx:587-589` ; `campagnes/NouvelleCampagnePage.tsx:22-24` ; `AssistantCreation.php:533` |
| A/B | **proposé à l'écran mais refusé partout** : la construction bloque (`objet_b_non_applicable`), l'API des messages refuse | `creation/EtapeMessage.tsx:158-164` ; `Campagnes/PlanSequence.php:32-37` ; `EnvoiMessagesController.php:203-204` |
| Boîtes et plafond | **cochés par défaut** ⇒ TOUTE campagne de l'assistant tombe en `reglage_non_applique` | `creation/EtapeDepart.tsx:36-39` (toutes les boîtes prêtes + plafond) ; `MaterialisationCampagne.php:69` ; constat 9 de `L5-L6-cahier.md` |
| Compteurs réels à l'écran | en cours (#669, L6-C) | — |

⇒ Lots **B8-A** à **B8-F**.

### 0.5 Migrations
**Aucune migration n'est nécessaire** dans ce cahier (pas de colonne, pas d'index, pas de contrainte : les nouveaux compteurs vont dans le `jsonb` `compteurs` existant, les motifs d'arrêt n'ont pas de CHECK). La plage `2026_12_11_000200` à `2026_12_11_000290` est **libre** (vérifié : dernier fichier sur `main` = `2026_12_10_000100` ; aucune PR ouverte ni brouillon de conception n'utilise `2026_12_11_*`). Si un lot découvre qu'il lui faut une migration : il s'arrête, on ouvre d'abord une PR « migration seule » numérotée `2026_12_11_000200` (puis `…210`, `…220`), entrée NR-17 comprise, déployée avant le lot de code.

---

## 1. Ordre de fusion (une fusion à la fois ; chaque PR ≤ 400 lignes tests compris ; chaque branche part de `main`)

| Ordre | Lot | Contenu | Préalables |
|---|---|---|---|
| 1 | **B3-B** | verdict absent ⇒ report (jamais d'arrêt), opposition contrôlée avant | rien |
| 2 | **B3-A** | la matérialisation vérifie et écrit les verdicts avant d'inscrire | B3-B fusionnée |
| 3 | **B8-A** | API : champ `blocage` (liste et fiche) | B3-A fusionnée (même fichier `MaterialisationCampagne.php`) |
| 4 | **B8-C** | API : estimation « joignables » vraie | rien (peut se développer en parallèle) |
| 5 | **B8-D** | API : cible d'audience demandée + capacités de l'assistant | B8-C fusionnée (même `AssistantCreation.php`, `creation.yaml`) |
| 6 | **L7-A** | banc de bout en bout + S1 nominal + S9 porte fermée | B3-A fusionnée |
| 7 | **L7-B** | S2 réponse, S3 STOP, S4 rebond dur, S5 rebond mou | L7-A fusionnée |
| 8 | **L7-C** | S6 plainte, S7 arrêt en vol, S8 opposé jamais réinscrit | L7-B fusionnée |
| 9 | **B8-B** | écran : « bloquée, pourquoi » dans la liste et le suivi | B8-A **et #669** fusionnées |
| 10 | **B8-E** | écran : joignables vrais, pré-remplissage par audience | B8-D fusionnée |
| 11 | **B8-F** | écran : A/B, boîtes et plafond grisés tant que refusés | B8-E fusionnée (même `creation.handlers.ts`) |
| 12 | **L8-A** | commentaires et documentation périmés | toutes les précédentes |
| 13 | **L8-B** | constat lecture seule des lignes d'ancienne forme | rien |
| — | L8-C | retrait des branches de transition | **ESCALADE 4** tranchée + constat L8-B à zéro |

---

## 2. LOT B3-B — verdict absent : report, jamais d'arrêt

### 2.1 Fichiers autorisés
- M `backend/app/Envoi/Sequence/Sequenceur.php` (`passager` et son appelant, `:310-323`, `:424-437`)
- M `backend/app/Envoi/Gardes/EnvoiAutorise.php` : **ajout d'une méthode publique seulement**, aucun ordre de contrôle changé
- M `backend/app/Envoi/Sequence/RefusSequenceur.php` : docblock de `VERDICT_ABSENT` seulement (les listes ne bougent pas : le test de réflexion `SequenceurTest.php:602-606` reste vert)
- C `backend/tests/Feature/Envoi/SequenceurVerdictAbsentTest.php` (préfixe `sva`)
- C `docs/envoi/lots/B3-B.md`

**À ne pas toucher :** `PasserelleLocale.php`, `PorteEnvoiReelle.php`, `EnvoiServiceProvider.php`, `Verdicts/*`, `MaterialisationCampagne.php` (B3-A), migrations, `config/envoi.php`, `SequenceurTest.php` (aucune assertion n'y change).

### 2.2 Comportement exact
1. `EnvoiAutorise::oppositionSeule(DemandeEnvoi $d): ?string` (nouvelle, publique, statique) : rend le motif de `RegistreOppositionManuel::refuse` (`OPPOSITION_REGISTRE`) puis de `garderOppositions` (art. 21, STOP, liste de refus), dans cet ordre ; `null` sinon. **Fermée en cas d'erreur** : aucune exception attrapée (comme `:194-196`). `verifier()` n'est pas modifiée.
2. `Sequenceur`, quand la porte rend `VERDICT_ABSENT` :
   - dernier verdict `valide` (donc périmé ou daté du futur) ⇒ **inchangé** : `reporterAvecPlafond` (10 reports puis arrêt + alerte) ;
   - dernier verdict `invalide` ou `risque` ⇒ **inchangé** : refus définitif ;
   - **aucune ligne, ou dernier verdict `inconnu`** ⇒ d'abord `oppositionSeule($demande)` : un motif ⇒ `refuserSaufDejaParti` avec CE motif (arrêt définitif, trace d'opposition comme aujourd'hui `:629-632`) ; sinon ⇒ `reporter()` **sans compteur** (`reports` inchangé, message `reporte`, motif `verdict_adresse_absent_ou_perime`, échéance au prochain jour ouvré dans la fenêtre : comportement existant de `reporter`). C'est le « report sans fin » : la cause (pas encore vérifiée) disparaît d'elle-même au passage du rafraîchissement, qui prend justement les inscriptions actives échues sous 3 jours sans verdict (`RafraichissementVerdicts.php:119`).
   - Alerte bornée : quand `reports` resterait inchangé mais que le message est `reporte` pour ce motif depuis **7 jours calendaires** (date `cree_le` de la ligne `envoi_messages`), `Log::warning('envoi.verdict_absent_persistant', [workspace_id, inscription_id])` une fois par jour ouvré, jamais d'adresse. Aucun arrêt.
3. `passager()` reçoit l'empreinte déjà calculée (pas de seconde normalisation de l'adresse) ; le filtre `workspace_id` reste explicite.

### 2.3 Tests rouges d'abord (PostgreSQL réel ; chaque test en `['app','proprio']` : `SET LOCAL ROLE` applicatif + `WorkspaceContext`, puis propriétaire, modèle `SchemaLienCampagneSequenceTest.php:77-95`)
1. Aucun verdict ⇒ `REPORTE`, inscription `active`, `reports` inchangé, 0 appel au faux serveur ; puis verdict `valide` écrit ⇒ passage suivant ⇒ `ENVOYE`.
2. Dernier verdict `inconnu` ⇒ même chose que 1.
3. Aucun verdict + adresse au STOP (`email_suppressions`) ⇒ `REFUSE`, `arretee`, motif `garde:opposition_stop` (ou le motif exact rendu par `garderOppositions`), trace `opposition` ; **jamais** `reporte`.
4. Aucun verdict + art. 21 en cours ⇒ refus définitif `OPPOSITION_ART21`. Idem registre manuel ⇒ `OPPOSITION_REGISTRE`.
5. `invalide` ⇒ refus définitif (non-régression de `SequenceurTest.php:296-306`, recopié sous les deux rôles).
6. `valide` de 31 jours ⇒ report plafonné : 10 passages ⇒ arrêt (non-régression).
7. 30 passages sans verdict ⇒ toujours `active`, 1 seule ligne `envoi_messages` (statut `reporte`), aucune ligne `envoi_inscriptions_evenements` ; alerte `envoi.verdict_absent_persistant` émise après 7 jours, sans `@` dans le contexte.
8. Cloisonnement : un verdict `valide` de la même empreinte dans l'espace B ne débloque pas l'espace A (mode proprio compris).
9. Porte fermée (`config('envoi.reel_actif')` faux, montage réel) ⇒ rien de tout cela n'est atteint : `envoi:cycle` « porte fermée », 0 `envoi_messages`.

**Mutations :** retirer `oppositionSeule` ⇒ 3, 4 ; compter le report (`reports+1`) ⇒ 7 ; classer `inconnu` comme `invalide` ⇒ 2 ; reporter aussi `invalide` ⇒ 5 ; filtre d'espace de la lecture du verdict retiré ⇒ 8 (proprio).

---

## 3. LOT B3-A — la matérialisation vérifie avant d'inscrire

### 3.1 Fichiers autorisés
- M `backend/app/Envoi/Campagnes/MaterialisationCampagne.php`
- M `backend/app/Envoi/Verdicts/PreparationVerdicts.php` : docblock seulement (« appelée par `MaterialisationCampagne` »)
- C `backend/tests/Feature/Envoi/MaterialisationVerdictsTest.php` (préfixe `mvd`)
- M (si et seulement si rouge) les aides de montage de `MaterialisationCampagneTest.php`, `GestesCampagneSequenceTest.php`, `ClotureInscriptionsCampagneTest.php`, `tests/Support/materialisation-concurrente.php` : installer `ResolveurDnsSimule::toutVerifier` (aucune assertion modifiée ; ligne « Filet modifié : pourquoi » dans la PR)
- C `docs/envoi/lots/B3-A.md`

**À ne pas toucher :** `SelectionDestinataires.php` (lecture seule, ne doit faire aucun DNS), `Verdicts/*` hors docblock, `EnvoiServiceProvider.php`, `VerdictsAdresseServiceProvider.php`, `GestesCampagne.php`, `DepotCampagnes.php`, migrations.

### 3.2 Comportement exact
1. Constructeur : nouveau dernier paramètre `?PreparationVerdicts $verdicts = null` ; `null` ⇒ `app(PreparationVerdicts::class)` (les `new MaterialisationCampagne(...)` des tests restent valides).
2. Dans `derouler`, **après** `$this->selection->lot(...)` et **avant** `ecrire(...)` (donc hors de toute transaction et de tout verrou : le DNS ne doit jamais tenir un verrou) : `pourContacts($ws, ids du lot, $maintenant)`.
3. Tri du lot (avant l'écriture, dans un nouveau `LotDestinataires` reconstruit, `curseurSuivant` inchangé) :
   - `invalide` ⇒ écarté, compteur `adresse_invalide` ;
   - `risque` ⇒ écarté, compteur `adresse_risquee` ;
   - `valide` récent (`permetInscription`) ⇒ inscrit ;
   - `null` (DNS indéterminé, quota épuisé, ligne ancienne) ⇒ **inscrit** : la porte reste seule juge, et B3-B le reporte sans l'arrêter jusqu'au rafraîchissement.
   Les compteurs s'ajoutent au `jsonb` `compteurs` (mêmes règles d'addition que `:181-184`) ; `retenus` est diminué d'autant pour que `membres − retenus` (exclus de préparation, L6-A) reste exact.
4. `schemaPret()` faux ⇒ `MaterialisationRefusee('verdicts_indisponibles', true, …)` : réessayable, rien écrit.
5. Une exception du vérificateur (autre que le schéma) ⇒ `MaterialisationRefusee('verdicts_indisponibles', true, …)`, rien inscrit pour ce lot, curseur inchangé.
6. Verdicts écrits mais lot ensuite refusé (`concurrence`) : rien à défaire (faits datés, INSERT seul, pas de doublon avant 20 jours : `EcrivainVerdicts`).

### 3.3 Tests rouges d'abord (`['app','proprio']`, `ResolveurDnsSimule`, aucun réseau : `Http::preventStrayRequests()`)
1. 5 contacts : 2 valides, 1 jetable (invalide), 1 `contact@` générique ciblé (risque) — si la sélection l'a déjà écarté, remplacer par un rôle technique —, 1 domaine sans réponse ⇒ 3 inscrits (2 valides + 1 indéterminé), `adresse_invalide = 1`, `adresse_risquee = 1`, `membres − retenus` cohérent.
2. Les verdicts sont écrits AVANT l'inscription : `envoi_verdicts_adresse` contient 4 lignes, aucune adresse en clair (empreintes seules), source `interne-v1`.
3. Aucun verrou tenu pendant le DNS : résolveur simulé qui, pendant sa réponse, essaie `pg_try_advisory_lock(envoi_etalement:<ws>)` depuis une autre connexion ⇒ obtenu.
4. Schéma incomplet simulé (`Schema::hasColumn` faux par espionne) ⇒ `verdicts_indisponibles`, 0 inscription, curseur inchangé.
5. Cloisonnement : contacts de l'espace B passés à l'espace A ⇒ ignorés (pas de verdict écrit pour B).
6. Une personne opposée n'est ni vérifiée ni inscrite : la sélection l'écarte avant (`opposition`), 0 requête DNS pour son domaine.
7. Non-régression : `MaterialisationCampagneTest`, `MaterialisationCommandeTest`, `GestesCampagneSequenceTest`, `ClotureInscriptionsCampagneTest`, `RafraichissementVerdictsTest` verts.

**Mutations :** appel `pourContacts` retiré ⇒ 1, 2 ; `null` écarté au lieu d'inscrit ⇒ 1 ; vérification déplacée DANS `ecrire` ⇒ 3 ; `retenus` non corrigé ⇒ 1.

---

## 4. LOT L7-A — banc de bout en bout, S1 nominal, S9 porte fermée

### 4.1 Fichiers autorisés
- C `backend/tests/Support/BancCampagne.php` (aides seulement, aucun test)
- C `backend/tests/Feature/Envoi/BoutEnBout/CampagneNominaleTest.php` (préfixe `bebn`)
- C `docs/envoi/lots/L7-A.md`

**À ne pas toucher :** tout `app/` (si un scénario rougit sur `main`, voir 4.4), `tests/Feature/NonRegression/**`, `EssaiABlanc*.php`, `config/`, migrations.

### 4.2 Le banc (`BancCampagne`)
Montage par le **chemin légal** uniquement, sur PostgreSQL réel, données fictives `*.example.test` semées par `pgsql_owner`, nettoyées dans un `try/finally` (modèle `GestesCampagneSequenceTest.php:76-110`) :
- `espace()` : espace + produit Envoi actif + identité (R14) + domaine d'envoi + une boîte + **dernière décision de feu vert « accorde »** (même lecture que l'aiguilleur) ;
- `campagne(n, etapes = 2)` : liste manuelle de `n` entreprises fictives avec un contact nominatif chacune ; `DepotCampagnes::creer` **sans boîte ni plafond** ; validation ; `MaterialisationCampagne::materialiser` réel (avec B3-A : verdicts via `ResolveurDnsSimule::toutVerifier`) ;
- `lancer()` : `GestesCampagne::lancer` réel ;
- `tic(DateTimeImmutable)` : un `Sequenceur` construit comme dans `EssaiABlanc.php` (même fabrique, `FauxServeurEnvoi` construit APRÈS l'ouverture de la transaction, prédicat de porte **local au test** à vrai) ; **`config('envoi.reel_actif')` n'est jamais modifiée** ;
- `releve(MessageBrut ...$m)` : `ReleveImap` avec `FabriqueLecteurFixe` + `LecteurBoiteMemoire` et la chaîne de récepteurs **réelle** (`RecepteurRetours::class` du conteneur : CRM, freins, séquence, réponses), registre `RegistreMessagesEnvoyesDb` réel ;
- fabriques de messages : `reponse(messageId)`, `stop(messageId)`, `rebondDur(verp)`, `rebondMou(verp)`, `arf(verp, 'abuse')` (VERP signé par `Verp::adressePour`, comme `RebondsPlaintesK11Test`).
Garde-fous du banc : `Http::preventStrayRequests()`, `Mail::fake()`, aucune socket (espionne de flux comme `EssaiABlancSansReseauTest`), horloge fixée sur un jour ouvré fictif.

### 4.3 Scénarios
- **S1 nominal** (2 personnes, 2 étapes) : matérialisation `terminee`, `inscrits = 2` ; lancer ⇒ `en_cours` ; `tic(J)` ⇒ 2 injections sur le faux serveur, 2 `envoi_messages` `envoye`, 2 `envoi_messages_envoyes` (Message-ID retrouvable), corps conforme (lien de désinscription, entité, art. 14 : assertions du composeur) ; `tic(J+délai)` ⇒ étape 2 avec `In-Reply-To` = Message-ID de l'étape 1 ; `tic` suivant ⇒ inscriptions `terminee`. `CompteursCampagne::pour` ⇒ `envoyes = 4`, `touches = 2`, `Σ issues = inscrits`.
- **S9 porte fermée** : même montage mais `tic` passé par `artisan envoi:cycle` réel (porte du conteneur) ⇒ « porte fermée », 0 `envoi_messages`, 0 appel au faux serveur ; `config('envoi.reel_actif')` faux avant et après.

### 4.4 Si un scénario rougit sur `main`
Le test rouge est la preuve. Si la correction tient dans **un** fichier de `app/` non chaud (liste du § 12), elle entre dans ce lot (≤ 400 lignes au total). Sinon : le scénario part dans un lot `L7-FIX-<n>` à part (test rouge + correction), et ce lot-ci livre les autres scénarios. **Jamais** de `->skip()`, `->todo()` ou assertion affaiblie.

### 4.5 Rôles
Chaque scénario est joué deux fois : rôle applicatif `pgsql_app` (RLS) ET propriétaire (seul le filtre `workspace_id` protège), comme `RattachementReponseD2Test`.

**Mutations :** retirer l'écriture `envoi_messages_envoyes` (`Sequenceur::enregistrerEnvoye`) ⇒ S1 ; prédicat local du banc remplacé par la porte réelle ⇒ S1 rouge (rien ne part) ; `config('envoi.reel_actif')` forcée à vrai dans le banc ⇒ S9 rouge (garde du banc).

---

## 5. LOT L7-B — réponse, STOP, rebond dur, rebond mou

### 5.1 Fichiers autorisés
- C `backend/tests/Feature/Envoi/BoutEnBout/CampagneRetoursTest.php` (préfixe `bebr`)
- M `backend/tests/Support/BancCampagne.php` (ajouts d'aides seulement)
- C `docs/envoi/lots/L7-B.md`

### 5.2 Scénarios (2 entreprises, A avec 2 contacts dont un seul inscrit — E7 — et B ; 2 étapes)
- **S2 réponse humaine** : après l'étape 1, `releve(reponse(msgId de A))` ⇒ inscription de A `arretee` motif `reponse`, trace `reponse` ; `tic(J+délai)` ⇒ étape 2 envoyée à B seulement ; A : 1 seul message à vie. Réponse visible dans `/envoi/reponses` (lecture `ServiceReponses`, `boite_id` = UUID de la boîte).
- **S3 STOP** (réponse « STOP » classée `desinscription`) : opposition écrite (`email_suppressions`/`opt_out` via `RecepteurRetoursCrm`), `tic` suivant ⇒ étape 2 de A **refusée** (motif d'opposition), A `arretee`, trace `opposition` ; 0 injection pour A ; la fiche contact **existe toujours** (`deleted_at` nul, aucune ligne supprimée : comptage avant/après de `contacts`, `personnes`).
- **S4 rebond dur** (DSN par VERP signé) : événement `rebond_dur` rattaché ; `tic` suivant ⇒ A arrêtée (motif selon la porte : adresse invalide / suppression), étape 2 jamais injectée ; `CompteursCampagne` ⇒ `rebonds_durs = 1`.
- **S5 rebond mou** : événement `rebond_mou` rattaché ⇒ **aucun** arrêt, étape 2 envoyée ; `rebonds_mous = 1` ; un rebond mou suivi d'un dur sur la même inscription ⇒ compté dur seulement.

Dans tous les cas : relève rejouée deux fois ⇒ aucun événement ni effet en double (clé boîte/UIDVALIDITY/UID).

**Mutations :** `RecepteurRetoursSequence` retiré de la chaîne (montage) ⇒ S2 ; classe `desinscription` ignorée par `RecepteurRetoursCrm` ⇒ S3 ; rattachement VERP coupé ⇒ S4.

---

## 6. LOT L7-C — plainte, arrêt en vol, opposé jamais réinscrit (et cloisonnement)

### 6.1 Fichiers autorisés
- C `backend/tests/Feature/Envoi/BoutEnBout/CampagneArretsTest.php` (préfixe `beba`)
- M `backend/tests/Support/BancCampagne.php` (ajouts seulement)
- C `docs/envoi/lots/L7-C.md`

### 6.2 Scénarios
- **S6 plainte** : `releve(arf(verp de A, 'abuse'))` ⇒ opposition + liste de suppression « plainte », frein « palier tenu + relecture » (`CompteurRetours`) ; étape 2 de A jamais injectée. Seconde plainte (B) sous 7 jours ⇒ arrêt de 48 h **de l'espace** : `tic` suivant ⇒ 0 injection pour TOUTES les inscriptions de l'espace, inscriptions restées `active` (rien n'est perdu), reprise possible seulement par un humain (aucun geste automatique). ARF `not-spam` ⇒ aucun effet.
- **S7 arrêt en vol** : campagne `en_cours`, étape 1 partie ; `GestesCampagne::arreter` ⇒ inscriptions actives `arretee` motif `campagne_arretee` ; `tic` ⇒ 0 injection ; une inscription déjà `arretee/reponse` garde son motif. Variante pause : `pause` ⇒ 0 injection ; `reprendre` ⇒ étape 2 part à l'échéance suivante.
- **S8 opposé jamais réinscrit** : A fait STOP dans la campagne 1 ; campagne 2 sur la même cible ⇒ matérialisation : A écarté (`opposition`), 0 verdict demandé pour A, 0 inscription ; la campagne 1 arrêtée puis la campagne 3 matérialisée ⇒ A toujours écarté. Fiche A mise à la corbeille (douce) puis restaurée ⇒ toujours écartée. **Cloisonnement** : même montage dans l'espace B avec une autre adresse ; aucun événement, frein ni compteur de A ne déborde sur B (le STOP vaut pour l'ADRESSE dans `email_suppressions`, liste globale par univers `business` : si B contient la même adresse, elle est aussi écartée — c'est le comportement voulu, échec fermé ; le test l'asserte explicitement).

**Mutations :** `CompteurRetours` neutralisé (montage) ⇒ S6 ; clôture retirée de `arreter` ⇒ S7 ; motif `opposition` retiré de `SelectionDestinataires::PRIORITE` ⇒ S8.

---

## 7. LOT B8-A — API : « bloquée, pourquoi »

### 7.1 Fichiers autorisés
- C `backend/app/Envoi/Campagnes/RaisonBlocage.php`
- M `backend/app/Envoi/Campagnes/LectureCampagnes.php` (`exposer` reçoit le blocage ; `liste` et `fiche` le calculent)
- M `backend/app/Envoi/Campagnes/MaterialisationCampagne.php` : **une ligne**, `:69` appelle `RaisonBlocage::reglageNonApplique($c)` (une seule source de vérité, jamais deux copies du prédicat)
- M `backend/openapi/envoi/campagnes.yaml` (champ `blocage` nullable dans `CampagneSuivi`)
- M `frontend/src/features/envoi/api/campagnes.d.ts` (régénéré)
- C `backend/tests/Feature/Envoi/RaisonBlocageTest.php` (préfixe `rbl`) ; ajouts seuls dans `CampagnesSuiviRoutesTest.php` si besoin du contrat
- C `docs/envoi/lots/B8-A.md`

**À ne pas toucher :** `GestesCampagne.php`, `CompteursCampagne.php`, `EnvoiCampagnesController.php`, routes, `nr20`.

### 7.2 Comportement
`blocage: {code: string, message: string} | null`, liste fermée, premier vrai dans cet ordre, **seulement pour `programmee`, `en_cours`, `en_pause`** (null ailleurs) :
1. `reglage_non_applique` : boîtes non vides ou `contenu.plafond_jour` non nul. Message : « Boîtes ou plafond choisis : l'envoi ne sait pas encore les appliquer. Renvoyez la campagne à valider et recréez-la sans eux. »
2. `preparation_bloquee` : matérialisation `bloquee` ; message selon `motif` (les 9 motifs connus : `cible_introuvable`, `cible_non_supportee`, `population_non_ouverte`, `filtre_origine_inactif`, `jours_non_applicables`, `objet_b_non_applicable`, `etapes_invalides`, `sequence_incoherente`, et tout autre ⇒ phrase générique sans code).
3. `aucun_destinataire` : matérialisation `terminee` et `inscrits = 0`.
4. `frein_arret_general`, `frein_pause_automatique` : mêmes lectures que `GestesCampagne` (appeler ses lecteurs publics s'ils existent, sinon les lire par la même source ; lus **une fois par espace** pour la liste).
5. `sequence_en_pause` : campagne `en_cours` mais dernier geste de sa séquence `pause` (pause directe sur la séquence, L5-C).
`programmee` + matérialisation `en_cours` n'est PAS un blocage (c'est la préparation : `preparation` le montre déjà).
Liste : **nombre de requêtes constant** par page (une lecture groupée des matérialisations et des derniers gestes ; `pourListe` réutilisé si `preparation` y est déjà).
Aucune adresse, aucun nom de personne dans `message`.

### 7.3 Tests rouges d'abord (`['app','proprio']`)
1. Un cas par code (7 codes) ⇒ code et message exacts ; campagne `terminee`/`arretee`/`brouillon` ⇒ `null`.
2. `reglage_non_applique` pour une campagne SANS ligne de matérialisation (cas réel d'aujourd'hui) ⇒ présent dans la fiche ET la liste.
3. Priorité : boîtes + préparation bloquée ⇒ `reglage_non_applique`.
4. Liste de 25 campagnes mêlées ⇒ ≤ 5 requêtes (`DB::listen`), indépendant de la taille de page.
5. Cloisonnement : frein armé dans l'espace B ⇒ aucune campagne de A marquée.
6. Contrat : réponses validées par le validateur OpenAPI existant.
7. `MaterialisationCampagneTest` vert sans modification (prédicat partagé).

**Mutations :** ordre de priorité inversé ⇒ 3 ; prédicat dupliqué au lieu de partagé (test de source : `MaterialisationCampagne.php` contient `RaisonBlocage::reglageNonApplique`) ⇒ 7 ; lecture des freins par campagne ⇒ 4.

---

## 8. LOT B8-B — écran : « bloquée, pourquoi » dans la liste et le suivi

### 8.1 Fichiers autorisés (après fusion de #669 et B8-A)
- M `frontend/src/features/envoi/campagnes/composants/TableauCampagnes.tsx` (pastille « Bloquée » + raison en texte, lisible sans couleur, lien vers le suivi)
- M `frontend/src/features/envoi/campagnes/CampagneSuiviPage.tsx` (bandeau `blocage` au-dessus de `BandeauPreparation` ; `MOTIF_PREPARATION` complété des 4 motifs manquants)
- M `frontend/src/features/envoi/campagnes/composants/libelles.ts` (libellés des 7 codes)
- M `frontend/src/demo/handlers/envoi/campagnes.handlers.ts` (une campagne de démo par code)
- M `frontend/tests/screens/envoi/CampagneSuiviPage.test.tsx` + C `frontend/tests/screens/envoi/TableauCampagnesBlocage.test.tsx`
- C `docs/envoi/lots/B8-B.md`

### 8.2 Comportement et tests (vitest, rouges d'abord)
1. Liste : chaque code ⇒ pastille « Bloquée » + phrase courte ; `blocage: null` ⇒ rien ; filtre « Bloquées » dans la liste (côté écran, sur la page chargée seulement).
2. Suivi : le message du serveur est affiché tel quel (pas de recalcul côté écran) ; bouton « Lancer » désactivé avec la raison en `aria-describedby` quand `blocage` est non nul.
3. Motif inconnu ⇒ phrase générique, jamais le code brut.
4. Accessibilité : `role="status"`, contraste, axe sans violation.

**Mutations :** bouton Lancer actif malgré `blocage` ⇒ 2 ; code brut affiché ⇒ 3.

---

## 9. LOT B8-C — API : « joignables » vrais

### 9.1 Fichiers autorisés
- M `backend/app/Envoi/Creation/AssistantCreation.php` (`estimer`, `cibles`)
- M `backend/openapi/envoi/creation.yaml` ; régénérer `frontend/src/features/envoi/api/creation.d.ts`
- C `backend/tests/Feature/Envoi/CreationJoignablesTest.php` (préfixe `cjo`)
- C `docs/envoi/lots/B8-C.md`

**À ne pas toucher :** `SelectionDestinataires.php` (utilisée telle quelle, lecture seule), `MaterialisationCampagne.php`, `Verdicts/*` (l'estimation ne fait **aucun** DNS et n'écrit **aucun** verdict).

### 9.2 Comportement
1. `cibles()` : `joignables` devient **`null`** (« non estimé ») pour liste, audience, étiquette ; `total` inchangé. Contrat : `joignables: integer, nullable`. On ne montre plus jamais `joignables = total`.
2. `estimer($ws, $cible)` pour un brouillon dont la cible enregistrée (`envoi_campagnes.cible`) est la cible demandée : parcourt `SelectionDestinataires::lot($ws, $id, 'entreprises', curseur, 500)` jusqu'à la fin ou l'échéance globale de 3 s (`ECHEANCE_SECONDES`) ou 10 lots ; additionne les compteurs. Réponse : `total` = membres examinés, `restant` = `retenus`, `exclusions` = tous les motifs de `SelectionDestinataires::MOTIFS` + `hors_reseau` + `sans_contact_generique_disponible`, `exclusions_evaluees: true`, **`complet: bool`** (faux si échéance ou 10 lots atteints : l'écran dira « au moins N sur M examinés », jamais un chiffre extrapolé). Verdicts : lus seulement (dernier verdict `invalide`/`risque` existant ⇒ compté `adresse_invalide`/`adresse_risquee`), aucun écrit.
3. Cible demandée ≠ cible enregistrée, ou type non pris par la sélection (`carnet`, `contact_ids`) ⇒ ancienne estimation (total − opposées, `exclusions_evaluees: false`, `complet: false`).
4. `SelectionRefusee` `lectureTropLongue` ⇒ 503 `estimation_trop_longue` (inchangé) ; `filtreOrigineInactif` ⇒ 409 explicite.

### 9.3 Tests rouges d'abord (`['app','proprio']`)
1. Cible de 10 entreprises dont 2 opposées, 1 presse, 1 sans contact nominatif ⇒ `restant = 6`, chaque motif compté, `complet: true`.
2. Verdict `invalide` existant pour 1 contact ⇒ `adresse_invalide = 1`, `restant = 5`, **0 ligne** ajoutée à `envoi_verdicts_adresse`, 0 requête DNS (`ResolveurDnsInterdit` laissé en place).
3. Horloge simulée qui dépasse 3 s au 2ᵉ lot ⇒ `complet: false`, chiffres = 1ᵉʳ lot seulement.
4. Lecture seule : aucune instruction `INSERT|UPDATE|DELETE` (`DB::listen`).
5. `cibles()` ⇒ `joignables` nul partout sauf `carnet` (1 entreprise : estimée par la même voie, ou nulle).
6. Cloisonnement : brouillon de B estimé depuis A ⇒ 404.

**Mutations :** `joignables = total` remis ⇒ 5 ; écriture de verdict pendant l'estimation ⇒ 2 ; extrapolation de `restant` quand incomplet ⇒ 3.

---

## 10. LOT B8-D — API : audience demandée, capacités de l'assistant

### 10.1 Fichiers autorisés
- M `backend/app/Envoi/Creation/AssistantCreation.php` (`cibles(…, ?string $audience)`)
- M `backend/app/Http/Controllers/Envoi/CreationController.php` (`cibles` lit `?audience=`, chiffres seulement, < 18 caractères)
- M `backend/app/Http/Controllers/Api/EnvoiMessagesController.php` (`contexte` : ajout `capacites`)
- M `backend/openapi/envoi/creation.yaml` ; régénérer `creation.d.ts`
- C `backend/tests/Feature/Envoi/CreationCiblesAudienceTest.php`
- C `docs/envoi/lots/B8-D.md`

### 10.2 Comportement
1. `cibles(?audience=ID)` : l'audience demandée est **toujours** dans la réponse (en tête), même hors des 50 premières par nom, si elle appartient à l'espace, est active et non supprimée ; sinon ignorée sans erreur (pas d'oracle d'existence entre espaces).
2. `GET /envoi/creation/contexte` ajoute `capacites: {ab: false, boites: false, plafond: false}` : valeurs **lues des refus du serveur**, pas de constantes du front : `ab` = faux tant que `PlanSequence` refuse `objet_b` ; `boites`/`plafond` = faux tant que `RaisonBlocage::reglageNonApplique` existe (constante de classe `RaisonBlocage::REGLAGES_APPLIQUES = false`, la même que lit `MaterialisationCampagne`). Aucune route nouvelle (`nr20` inchangé).

### 10.3 Tests
1. 60 audiences ; la 60ᵉ par nom demandée ⇒ présente, en tête, une seule fois.
2. Audience d'un autre espace, supprimée, ou inactive ⇒ absente, 200.
3. `?audience=abc` ou trop long ⇒ ignoré, 200.
4. `capacites` toutes fausses ; mutation : `REGLAGES_APPLIQUES` à vrai ⇒ `boites: true` (le test vérifie le lien, pas une constante recopiée).
5. Droits inchangés (`users.manage`).

---

## 11. LOTS B8-E et B8-F — écran de l'assistant

### 11.1 B8-E — joignables vrais, pré-remplissage par audience
**Fichiers :** M `frontend/src/features/envoi/campagnes/NouvelleCampagnePage.tsx` (lit `?audience=`, le passe à `useCibles`), M `frontend/src/features/envoi/creation/Assistant.tsx` (cible initiale = audience si présente et listée ; nom « Campagne <libellé> »), M `frontend/src/features/envoi/creation/EtapeCible.tsx` (liste : « N membres » ; carte de la cible choisie : « Joignables » = `restant` de l'estimation, « au moins N sur M examinés » si `complet` faux, détail des exclusions), M `frontend/src/features/envoi/creation/api.ts` (paramètre `audience`), M `frontend/src/demo/handlers/envoi/creation.handlers.ts`, C `frontend/tests/screens/envoi/AssistantCibleAudience.test.tsx` ; C `docs/envoi/lots/B8-E.md`.
**Tests :** `?audience=12` ⇒ cible cochée à l'ouverture, estimation demandée une fois ; audience inconnue ⇒ aucune cible cochée, aucun message d'erreur ; `joignables: null` ⇒ jamais « 0 » ni le total affiché comme joignable ; `complet: false` ⇒ « au moins » ; `?etiquette=` et `?entreprise=` inchangés.

### 11.2 B8-F — A/B, boîtes et plafond grisés tant que refusés
**Fichiers :** M `frontend/src/features/envoi/creation/EtapeMessage.tsx` (case A/B `disabled` + « Bientôt : le test A/B n'est pas encore pris en charge par l'envoi » quand `capacites.ab` faux), M `frontend/src/features/envoi/creation/EtapeDepart.tsx` (boîtes et plafond en `fieldset disabled` + même explication ; **plus de pré-sélection** des boîtes `:36-39`), M `frontend/src/features/envoi/creation/saisie.ts` (n'envoie ni `boites`, ni `plafond_jour`, ni `objet_b` quand la capacité est fausse, même si un ancien brouillon les contient ; l'écran le signale : « réglages retirés : non pris en charge »), M `frontend/src/demo/handlers/envoi/creation.handlers.ts` (`capacites`), C `frontend/tests/screens/envoi/AssistantCapacites.test.tsx` ; C `docs/envoi/lots/B8-F.md`.
**Tests :** capacités fausses ⇒ contrôles désactivés avec raison accessible (`aria-describedby`) ; la charge utile de l'étape 3 ne contient ni `boites` ni `plafond_jour` ; brouillon ancien avec boîtes et `objet_b` ⇒ rechargé, puis sauvegardé SANS eux ; capacités vraies (simulées) ⇒ comportement d'aujourd'hui (non-régression des tests existants de l'assistant, sans modification).
**Mutations :** pré-sélection des boîtes remise ⇒ charge utile rouge ; `objet_b` envoyé malgré `ab: false` ⇒ rouge.

---

## 12. LOTS L8 — nettoyage

### 12.1 L8-A — commentaires et documentation périmés (aucun comportement)
**Fichiers :** M `backend/app/Envoi/Retours/AppliquerRetour.php` (docblock), M `backend/app/Envoi/Imap/RecepteurRetours.php` (docblock), M `docs/envoi/rebonds-plaintes-k11.md` (§ « Dette connue » : séquenceur planifié par `envoi:cycle`), M `docs/envoi/PLAN-FIN-ENVOI.md` (tableau d'état : L5, L6, B3-bis, L7, B8 faits avec leurs PR), C `docs/envoi/lots/L8-A.md`.
**Test :** `vendor/bin/pint --test` et la suite NR inchangés ; aucune ligne de code exécutable modifiée (diff relu : commentaires seuls). Pas de test rouge possible : le dire dans la PR.

### 12.2 L8-B — constat lecture seule des lignes d'ancienne forme
**Fichiers :** C `backend/app/Console/Commands/EnvoiTransitionConstat.php` (`envoi:transition:constat`, **non planifiée** ⇒ `nr07`/`nr08` inchangés), C `backend/tests/Feature/Envoi/TransitionConstatTest.php`, C `docs/envoi/lots/L8-B.md`.
**Comportement :** par espace (sous `WorkspaceContext` + filtre explicite), compte : `envoi_messages` avec `id_injection` nul et `id_injection_serveur` non nul (forme 2), messages rattachables seulement par la clé `seq-…` du registre local (forme 3), et les replis de `RegistreMessagesEnvoyesDb.php:40`. Sortie : identifiants d'espace et nombres, **jamais** d'adresse. Lecture seule (`SET TRANSACTION READ ONLY`).
**Tests (`['app','proprio']`) :** jeu de lignes des trois formes ⇒ comptes exacts ; aucune écriture (`DB::listen`) ; sortie sans `@` ; espace B non compté dans A.

### 12.3 L8-C — retrait des branches de transition (conditionnel)
Seulement après **ESCALADE 4** et un constat L8-B à zéro en production. Fichiers : `RecepteurRetoursSequence.php` (branches 2 et 3), `RegistreMessagesEnvoyesDb.php` (repli) et leurs tests ; les tests de l'ancienne forme deviennent des tests « l'ancienne forme n'est plus rattachée ⇒ `a_rattacher` (revue humaine) », jamais supprimés.

---

## 13. Fichiers chauds (une seule PR à la fois)

| Fichier | B3-B | B3-A | B8-A | B8-B | B8-C | B8-D | B8-E | B8-F | L7-* | L8-A | L8-B |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `Sequenceur.php`, `EnvoiAutorise.php` | oui | non | non | non | non | non | non | non | non | non | non |
| `MaterialisationCampagne.php` | non | oui | 1 ligne | non | non | non | non | non | non | non | non |
| `LectureCampagnes.php`, `campagnes.yaml`, `campagnes.d.ts` | non | non | oui | non | non | non | non | non | non | non | non |
| `AssistantCreation.php`, `creation.yaml`, `creation.d.ts` | non | non | non | non | oui | oui | non | non | non | non | non |
| `CampagneSuiviPage.tsx`, `campagnes.handlers.ts` | non | non | non | oui (après #669) | non | non | non | non | non | non | non |
| `creation.handlers.ts` | non | non | non | non | non | non | oui | oui | non | non | non |
| `tests/Support/BancCampagne.php` | non | non | non | non | non | non | non | non | oui (A crée, B/C ajoutent) | non | non |
| **Jamais** : `config/envoi.php`, `.env*`, `EnvoiServiceProvider.php`, `PasserelleLocale.php`, `PorteEnvoiReelle.php`, `GestesCampagne.php`, `DepotCampagnes.php`, `ReetalementStock.php`, `CompteursCampagne.php`, `SelectionDestinataires.php`, `routes/**`, `nr07`/`nr08`/`nr20`, migrations existantes | non | non | non | non | non | non | non | non | non | non | non |

## 14. Conditions d'activation (inchangées)
Rien ne part tant que `ENVOI_REEL_ACTIF` est faux : drapeau (`config/envoi.php`), `PorteEnvoiReelle`, `RetraitEnvoisEnFile` lié à l'absent, cycle non dû porte fermée, feu vert, verdict d'adresse exigé. B3-B **ne retire aucune garde** : il remplace un arrêt définitif par un report pour la seule absence de verdict ; la porte refuse toujours l'envoi sans verdict `valide` récent. Les tests L7-A S9 et B3-B 9 le prouvent ; à garder verts jusqu'à l'activation.

## 15. ESCALADE (décisions du fondateur ; chaque lot avance avec la recommandation par défaut, sans risque)
1. **Verdict absent : report sans fin ou plafonné ?** Recommandation (défaut de B3-B) : report sans fin, sans compteur, avec alerte journalisée après 7 jours. Rien ne part, rien n'est perdu ; seul coût : l'adresse reste « active » et ne peut entrer dans une autre campagne tant que le DNS ne répond pas. Alternative : arrêt après 30 jours (motif `verdict_jamais_obtenu`, sans trace d'opposition).
2. **Adresses `risque` (génériques, rôles) écartées à la préparation ?** Défaut B3-A : écartées et comptées (`adresse_risquee`), comme la porte les refuserait de toute façon. Rejoint l'ESCALADE 1 de `VERDICTS-cahier.md`.
3. **Campagnes déjà bloquées en `reglage_non_applique`.** Défaut : B8 l'affiche avec la marche à suivre (renvoyer à valider puis recréer sans boîtes ni plafond) ; aucun geste qui réécrit la saisie d'une campagne validée. Alternative : un geste « retirer les réglages non appliqués » (lot à part, journal d'audit).
4. **Fin de la transition des identifiants d'injection (L8-C).** Décision de production : lancer `envoi:transition:constat` sur la production ; si zéro partout, autoriser L8-C. Défaut : ne rien retirer.
5. **Plainte : espace seul ou tous les clients (B5 de `rebonds-plaintes-k11.md`).** Toujours non tranchée ; L7-C asserte le comportement en vigueur (freins limités à l'espace ; liste de suppression globale par univers).
6. **Estimation « au moins » au-delà de 5 000 membres.** Défaut B8-C : pas d'extrapolation. Alternative : calcul en tâche de fond avec résultat mis en cache (lot à part).

## 16. Hors périmètre
Vérificateur payant ; boîtes et plafond par campagne réellement appliqués par le transport ; A/B réel ; liste nominative des destinataires ; suivi des ouvertures ; lancement automatique à la date prévue.

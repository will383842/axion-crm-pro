# D3 à D6 — retours et réactions : arrêts immédiats, freins, effets des réponses, écran Réponses (cahier de conception)

Conception Opus du 17/11/2026, vérifiée sur `main` (tête `454d333`, C3-5 #668 fusionné) et sur les PR ouvertes (#669, #670,
#354, #352 et les brouillons de conception #607 #608 #609 #617 #618 #619 #648 #649).
Pour un développeur seul. Rien ici n'est du code à copier tel quel : ce sont des contrats. **Vérifier chaque numéro de
ligne avant de s'y fier** (relevés sur `454d333`).

Ne pas confondre avec `docs/envoi/lots/D3.md` (« dettes de la garde produit ») et `D4.md` (« dettes de l'infrastructure
en lecture ») : anciens lots livrés. Ni avec les décisions D3 à D6 de `PLAN-FIN-ENVOI.md` (boîtes, santé, chauffe, pause
de séquence) ni avec celles de l'ADR K0. Les PR de ce cahier s'appellent **RR-0, RR-3A, RR-4A … RR-6D** (« RR » =
retours et réactions). Le cahier D1-D2 (#617) les annonçait comme « L5 (STOP, plainte, rebond dur sur la séquence) »,
« L7 (absence : +7 jours) », « D4 (relève hors espace commercial) » et « D6 (écran Réponses) » ; D2 est terminé (#650, #654).

Règles qui ne bougent pas :
- `ENVOI_REEL_ACTIF`, `ENVOI_RELEVE_ACTIF` restent faux ; `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`,
  `USINE_AGREGATS_CRM_ENABLED` ne sont ni lus ni écrits ; `config/*.php` et `.env*` inchangés ; aucune nouvelle clé.
- **Échec fermé** : une personne opposée n'est jamais envoyée ni réinscrite ; aucun geste de ce cahier ne lève une
  opposition ni ne rouvre une inscription `arretee` (annuler un classement ne défait aucun arrêt).
- **Aucune suppression ni purge** de données de personnes : arrêts = statut `arretee` + motif ; gestes en ajout seul ;
  tâches jamais supprimées.
- **Cloisonnement par espace** : filtre `workspace_id` explicite dans chaque requête (la RLS est contournée sous le
  propriétaire). Chaque test de sécurité tourne **deux fois** : rôle `pgsql_app` (RLS appliquée) ET propriétaire.
- Aucun envoi, aucun réseau : relève par `LecteurBoiteMemoire`, horloge figée (`backend/AGENTS.md`, « Horloge »).
- Adresses d'exemple en `*.example.test`. Aucun nom de personne : « le fondateur ».
- Migrations en ajout seul, plage réservée **`2026_12_11_000100` à `000190`** (vérifiée libre le 17/11 : rien sur
  `main` après `2026_12_10_000100`, aucune PR ouverte ni aucun brouillon de conception n'utilise `2026_12_11`).

---

## 0. Ordre de fusion (une fusion à la fois ; 250 à 400 lignes tests compris ; chaque branche part de `main`)

| # | PR | Contenu | Préalable |
|---|----|---------|-----------|
| 1 | **RR-0** « migration seule » | canal d'opposition `releve` + geste `annulation` (`2026_12_11_000100`) | — |
| — | *déploiement* | RR-0 **déployée**, `migrate:status` sans `Pending` | obligatoire avant RR-3A et RR-6A |
| 2 | **RR-3A** (D3) | STOP, plainte, rebond dur relevés ⇒ arrêt immédiat de la séquence (+ entreprise pour STOP et plainte) | RR-0 déployée |
| 3 | **RR-4A** (D4) | relève hors espace commercial : plus d'exception, opposition tout de même acquise | RR-3A fusionnée |
| 4 | **RR-4B** (D4) | réponses humaines comptées dans les freins ; frein humain nourri par la file réelle | RR-3A fusionnée |
| 5 | **RR-4C** (D4) | absence du bureau ⇒ relance repoussée de 7 jours (sans arrêt) | RR-4B fusionnée |
| 6 | **RR-5A** (D5) | classement humain ⇒ arrêt de la séquence + tâche CRM (« pas maintenant » = arrêt + tâche) | RR-4C fusionnée |
| 7 | **RR-5B** (D5) | avis « réponse intéressée » dans la cloche | RR-5A fusionnée |
| 8 | **RR-5C** (D5) | arrêt sur rendez-vous pris / entreprise devenue cliente (garde du séquenceur) | aucun (peut partir tout de suite) |
| 9 | **RR-6A** (D6) | service : annuler un classement, corriger depuis « Traitées », STOP non annulable | RR-0 déployée ET RR-5B fusionnée |
| 10 | **RR-6B** (D6) | route `POST /envoi/reponses/{id}/annuler` + contrat + types | RR-6A fusionnée |
| 11 | **RR-6C** (D6) | écran : classer à la main « Intéressé » et « Question » | RR-5A fusionnée |
| 12 | **RR-6D** (D6) | écran « Traitées » : corriger et annuler | RR-6B ET RR-6C fusionnées |

Pourquoi « migration seule » d'abord : le déploiement remplace le code AVANT `migrate`
(`.github/workflows/deploy-direct-ssh.yml:443` puis `:561`). Un code qui écrit `canal = 'releve'` ou
`geste = 'annulation'` avant la migration ferait échouer la transaction de la relève (`23514`) : il arrive après.
Les fichiers `ServiceReponses.php` (RR-3A, RR-4B, RR-5A, RR-6A) et `RecepteurRetoursSequence.php` (RR-3A, RR-4C) sont
touchés par plusieurs lots : **strictement l'un après l'autre**.

---

## 1. Étape 1 — ce qui est DÉJÀ fait (preuves sur `main`) : aucun lot

| Élément du périmètre | Preuve (code) | Preuve (test) |
|---|---|---|
| D3 — réponse humaine relevée ⇒ inscription arrêtée `reponse`, **collègues de l'entreprise arrêtés** | `app/Envoi/Imap/RecepteurRetoursSequence.php:34-46` ; `app/Envoi/Sequence/Inscriptions.php:58-88` ; `Evenement.php:19` | `tests/Feature/Envoi/RattachementReponseD2Test.php:276` (R1, app ET propriétaire) |
| D3 — opposition par le **lien** (page, un clic) ⇒ adresse ET entreprise arrêtées tout de suite | `app/Envoi/Desinscription/EnregistrerOpposition.php:44-76` → `TerminerEffetsOpposition.php:36-63` → `EffetsOpposition.php:14-30` → `ArretParEntrepriseL1.php:23-43` | `tests/Feature/Envoi/DesinscriptionTest.php`, `SequenceurTest.php:278` |
| D3 — **STOP classé par un humain** sur l'écran ⇒ même opposition (adresse + entreprise) | `app/Envoi/Reponses/ServiceReponses.php:169-172`, `:194-210` | `tests/Feature/Envoi/Routes/ReponsesRoutesTest.php:98` |
| D3 — opposition posée ailleurs (CRM, liste de suppression) ⇒ **rien ne part**, inscription et collègue arrêtés au premier passage | `app/Envoi/Sequence/Sequenceur.php:620-633` (refus + trace `opposition`) ; `:276-290` (entreprise, adresse) ; `app/Envoi/Gardes/EnvoiAutorise.php:77-83`, `:170` | `SequenceurTest.php:718` (I2), `:1286` (opposition entre réservation et injection), `:664` (B1) |
| D3 — le moteur sait arrêter sur `stop`, `opposition`, `rebond_dur` (adresse, toutes séquences) | `Evenement.php:16-25` ; `Inscriptions.php:72-78` | `SequenceurTest.php:244`, `:463`, `:478`, `:492`, `:694` |
| D4 — plaintes et rebonds durs comptés dans les freins | `app/Envoi/Imap/RecepteurRetoursFreins.php:15-30` → `app/Envoi/Chauffe/CompteurRetours.php:21` | `tests/Feature/Envoi/RebondsPlaintesK11Test.php` (S10) |
| D5 — tâches CRM existantes (une tâche = `activities.kind = 'task'`) et « Mes tâches » qui lit aussi les tâches système (`user_id IS NULL`) | `app/Http/Controllers/Api/CompanyTachesController.php:59-91` ; `app/Crm/Taches/MesTaches.php:69`, `:183-190` (G2-A #628, G2-B #637) | `tests/Feature/Crm/MesTachesTest.php` |
| D6 — le serveur accepte déjà les six classements, dont `interesse` et `question`, et une **nouvelle** ligne humaine remplace la précédente (corriger = reclasser) | `ServiceReponses.php:25`, `:174-183` ; `app/Http/Controllers/Api/EnvoiReponsesController.php:47-56` | `ReponsesRoutesTest.php:125` |
| D2 — rattachement manuel par l'identifiant du registre, `reponses_7j`, chaîne entière | #650, #654 | `RattachementReponseD2Test.php:109-162`, `:276-358` |

## 2. Constats : ce qui MANQUE (chaque lot répond à un constat)

- **K1 (D3)** STOP (`desinscription`), plainte (`hostile`) et rebond dur relevés **n'arrêtent pas la séquence** :
  `RecepteurRetoursSequence.php:36` sort pour toute classe autre que `ReponseHumaine`. Seul `RecepteurRetoursCrm` agit
  (CRM, espace commercial seulement) ; l'inscription ne s'arrête qu'au **prochain passage** du séquenceur, par la porte.
  Rien n'est envoyé (la porte veille) mais l'arrêt n'est ni immédiat ni tracé dans `envoi_inscriptions_evenements`, et
  l'opposition n'entre pas au registre Envoi (`envoi_oppositions`).
- **K2 (D4)** Hors espace commercial, la relève **plante** : `RecepteurRetoursCrm.php:40-44` lève une
  `RuntimeException` ; la chaîne s'arrête avant les freins, la séquence et le stockage
  (`EnvoiServiceProvider.php:149-154`) ; après `ReleveImap::MAX_TENTATIVES = 3` (`ReleveImap.php:60`) le message est mis
  « à rattacher » sans effet (`:217-219`) : **le STOP n'est jamais appliqué**, seule une alerte part. Le test
  `tests/Feature/Envoi/ReleveImapTest.php:242` fige ce comportement.
- **K3 (D4)** Les réponses humaines **ne sont pas comptées** dans les freins : `RecepteurRetoursFreins.php:15-20` n'a
  pas `reponse_humaine`, alors que `CompteurRetours::TYPES` attend `reponse` (`CompteurRetours.php:21`). Le frein
  « taux de réponse en baisse » (`Freins.php:79-93`) voit toujours 0.
- **K4 (D4)** Le **frein humain** (`Freins::freinHumain`, `GardeChauffe.php:150`, `:223`) lit
  `DepotChauffe::reponsesEnAttente` (`DepotChauffe.php:436-445`), qui compte les commandes `reponse_recue` /
  `reponse_traitee` du journal. **Personne ne les écrit** (aucune occurrence hors `DepotChauffe.php`) : toujours 0.
- **K5 (D4)** **Absence du bureau** : aucun effet (`RecepteurRetoursSequence.php:19-21`,
  `docs/envoi/rebonds-plaintes-k11.md:36`). La relance part pendant l'absence.
- **K6 (D5)** Un **classement humain n'a aucun effet** : `ServiceReponses::classer` (`:162-191`) n'écrit qu'une ligne
  de classement ; `resume_crm.tache` vaut toujours `null` (`:188`) ; `creer_tache` est ignoré
  (`EnvoiReponsesController.php:47-56`). Or l'écran promet « créer une tâche … et arrêter la séquence »
  (`frontend/src/features/envoi/reponses/composants/libelles.ts:20-25`). Une réponse « à rattacher » classée
  « intéressé » laisse la séquence continuer.
- **K7 (D5)** Aucun **avis** quand une réponse est classée « intéressée ».
- **K8 (D5)** Le séquenceur ne lit **ni rendez-vous ni statut client** : `Sequenceur.php:276-290` ne regarde que les
  arrêts ; aucune lecture de `companies.lifecycle_stage` ni de `activities.kind = 'calendly_booked'` dans `app/Envoi`.
- **K9 (D6)** Écran : boutons manuels « Pas intéressé / Plus tard / Absent / STOP » seulement
  (`DetailReponse.tsx:18-22`, raccourcis `ReponsesPage.tsx:24`) ; « Intéressé » et « Question » n'existent qu'en
  appliquant la suggestion, que le classificateur ne propose jamais (`StockerReponse.php:26-31`). « Traitées » est en
  lecture seule (`ReponsesTraiteesPage.tsx:47-60`) ; aucune route d'annulation (`routes/envoi/reponses.php:13-18`).
- **K10 (schéma)** `envoi_reponses_classements.geste` n'admet que `classement|fiche`
  (`2026_12_02_000121_envoi_reponses_classements.php:21`) ; `envoi_oppositions.canal` que `page|one_click|reponse`
  (`2026_12_02_000122_envoi_oppositions_canal_reponse.php`). Le registre doit dire vrai : une opposition posée par la
  relève n'est ni un clic ni un geste humain.

---

## 3. RR-0 — migration seule (canal `releve`, geste `annulation`)

Branche `feat/rr-0-migration`. Titre : `feat(envoi): RR-0 canal releve et geste annulation (migration seule)`.

**Fichiers autorisés**
1. `backend/database/migrations/2026_12_11_000100_envoi_retours_reactions.php` (nouveau)
2. `backend/tests/Feature/Envoi/SchemaRetoursReactionsTest.php` (nouveau)
3. `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` : UNE entrée dans `NR17_RETOUR_INERTE_VOULU`
   (« Filet modifié : pourquoi » dans la PR : un retour arrière qui reposerait l'ancienne liste échouerait dès qu'une
   ligne `releve` ou `annulation` existe)
4. instantanés NR touchés par une migration (`backend/AGENTS.md` « Nouvelle migration ») : seulement ceux qui bougent,
   régénérés et relus
5. `docs/envoi/lots/RR-0.md` (nouveau, 30 lignes au plus)

**Comportement** (même forme que `2026_12_02_000122`)
```sql
ALTER TABLE envoi_oppositions DROP CONSTRAINT IF EXISTS envoi_oppositions_canal_check;
ALTER TABLE envoi_oppositions ADD CONSTRAINT envoi_oppositions_canal_check
    CHECK (canal IN ('page','one_click','reponse','releve')) NOT VALID;
ALTER TABLE envoi_reponses_classements DROP CONSTRAINT IF EXISTS envoi_reponses_classements_geste_check;
ALTER TABLE envoi_reponses_classements ADD CONSTRAINT envoi_reponses_classements_geste_check
    CHECK (geste IN ('classement','fiche','annulation')) NOT VALID;
COMMENT ON COLUMN envoi_reponses_classements.geste IS
    'classement | fiche | annulation (annule le dernier classement humain ; classement = celui annulé). Ajout seul.';
```
- **Vérifier d'abord** le nom réel de la contrainte de `geste` (`SELECT conname FROM pg_constraint WHERE conrelid =
  'envoi_reponses_classements'::regclass AND contype = 'c'`) : nom implicite attendu
  `envoi_reponses_classements_geste_check`. Si le nom diffère, le corriger dans la migration (sinon deux CHECK
  coexistent et `annulation` reste refusé : le test S2 le verrait).
- `NOT VALID` : aucune ligne relue ni réécrite. Aucun `DELETE`, aucun `DROP` de table ou de colonne. `down()` INERTE.
- Rien d'autre : `classement` garde sa liste, `auteur` aussi (une annulation est `auteur = 'humain'`).

**Tests rouges d'abord** (écrire, lancer, voir rougir faute de migration ; modes `['app', 'proprio']` comme
`tests/Feature/Envoi/SchemaLienCampagneSequenceTest.php:77-95`) :
- S1 (app ET proprio) : `INSERT` d'une opposition `canal = 'releve'` passe ; `canal = 'imap'` ⇒ `23514`.
- S2 (app ET proprio) : `INSERT` d'un geste `annulation` (classement `interesse`, auteur `humain`) passe ;
  `geste = 'effacement'` ⇒ `23514` ; une annulation avec `contact_id` non nul ⇒ `23514` (contrainte `_fiche` intacte).
- S3 : un seul CHECK sur `geste` et un seul sur `canal` (`pg_constraint`) ; déclencheurs « ajout seul » toujours là :
  `UPDATE` / `DELETE` d'une ligne d'annulation refusés (app ET proprio).
- S4 : `up()` rejoué deux fois sans erreur ; NR-17 vert.

**Mutations** : retirer `'releve'` (S1) ; garder l'ancien nom de contrainte si le nom réel diffère (S2, S3) ; oublier
`NOT VALID` n'a pas d'effet visible en test (base vide) : à vérifier à la relecture.

Commande : `cd backend && vendor/bin/pint --dirty && vendor/bin/pest --filter='SchemaRetoursReactions|NR-17'`.

---

## 4. RR-3A (D3) — STOP, plainte, rebond dur relevés ⇒ arrêt IMMÉDIAT

Branche `feat/rr-3a-arrets-releve`. Après RR-0 **déployée**.

**Fichiers autorisés**
1. `backend/app/Envoi/Desinscription/OpposerAdresse.php` (nouveau) : le corps actuel de `ServiceReponses::opposer`
   (`:194-210`), déplacé tel quel, avec le canal en paramètre (`EnregistrerOpposition::CANAL_REPONSE` ou nouvelle
   constante `CANAL_RELEVE = 'releve'`).
2. `backend/app/Envoi/Desinscription/EnregistrerOpposition.php` : UNE constante `CANAL_RELEVE` et sa ligne de doc.
3. `backend/app/Envoi/Reponses/ServiceReponses.php` : `opposer` délègue à `OpposerAdresse` (aucun autre changement).
4. `backend/app/Envoi/Imap/InscriptionsDuRetour.php` (nouveau) : `inscriptionsRattachees` (`RecepteurRetoursSequence.php:57-73`)
   déplacée telle quelle, publique : `pour(string $ws, string $idInjection): list<string>`.
5. `backend/app/Envoi/Imap/RecepteurRetoursSequence.php`
6. `backend/tests/Feature/Envoi/ArretsReleveRR3Test.php` (nouveau)

**Fichiers chauds à ne pas toucher** : `EnvoiServiceProvider.php` (la chaîne et son ordre restent : CRM, freins,
séquence, stockage ; tout tourne dans la MÊME transaction de `ReleveImap::appliquer`, donc l'ordre ne change pas le
résultat), `ReleveImap.php`, `Classificateur.php`, `Inscriptions.php`, `Evenement.php`, `Sequenceur.php`,
`ArretParEntrepriseL1.php`, `TerminerEffetsOpposition.php`, `app/Crm/**`, `RecepteurRetoursCrm.php` (RR-4A).

**Comportement exact de `RecepteurRetoursSequence::recevoir`** (dans `WorkspaceContext::run($ws)`, filtre d'espace
explicite partout) :

| Classe relevée | Effet sur la séquence | Effet registre |
|---|---|---|
| `ReponseHumaine` | inchangé : `Evenement::REPONSE` sur chaque inscription rattachée | — |
| `Desinscription` (STOP) | `Evenement::OPPOSITION` sur chaque inscription rattachée (⇒ adresse ET entreprise, `Inscriptions.php:72-84`) | `OpposerAdresse` pour l'empreinte du destinataire, puis pour celle de l'expéditeur si elle diffère, canal `releve` |
| `Hostile` (plainte) | idem STOP | idem STOP |
| `RebondDur` | `Evenement::REBOND_DUR` sur chaque inscription rattachée (⇒ adresse seulement) | — (la liste de suppression reste l'affaire du CRM) |
| `AutoReponse`, `RebondMou` | aucun (RR-4C traitera l'absence) | — |

- **Aucune inscription rattachée** (ancien envoi, autre séquence) : STOP et plainte passent quand même par
  `OpposerAdresse` (l'écouteur `EffetsOpposition` → `ArretParEntrepriseL1` arrête l'adresse ET les entreprises de
  l'adresse) ; rebond dur : si une inscription `active` de l'espace porte `empreinte_adresse =
  ListeSuppression::empreinte($retour->adresse)`, `Evenement::REBOND_DUR` sur la première (`ORDER BY id`), ce qui arrête
  toute l'adresse.
- **Ordre dans le récepteur** : d'abord les arrêts d'inscriptions (`Inscriptions::evenement`, verrous `FOR UPDATE` dans
  l'ordre des identifiants), puis `OpposerAdresse`. L'arrêt ne dépend donc pas des effets aval : si
  `TerminerEffetsOpposition` échoue, il attrape et la relance planifiée rejoue (`TerminerEffetsOpposition.php:53-60`) ;
  les inscriptions sont déjà `arretee`.
- **Décision STOP ⇒ collègues** : un STOP relevé est traité comme une **opposition** (adresse ET entreprise), comme le
  lien et le STOP humain (`ServiceReponses.php:169-172`). L'événement interne `Evenement::STOP` (personne seule,
  `SequenceurTest.php:278`) n'est PAS modifié. Voir ESCALADE 1 (avance avec cette recommandation : elle n'envoie que moins).
- Idempotent : `ReleveImap` n'appelle la chaîne qu'une fois par message (`ReleveImap.php:309-311` ; rejeu en rattrapage
  pour les oppositions seulement) ; `Inscriptions::tracer` est `insertOrIgnore` ; `OpposerAdresse` réutilise le lien de
  l'adresse (`envoi_liens_desinscription`) et `envoi_oppositions` est unique par lien.
- Jamais d'adresse en clair dans un journal, une exception ou `envoi_inscriptions_evenements` (empreintes seulement).

**Tests rouges d'abord** (harnais de relève de `RattachementReponseD2Test.php:260-300` : relève réelle sur
`LecteurBoiteMemoire`, cycle du faux serveur ; modes `app` ET `proprio` ; sur `main` chaque test rougit) :
- A1 : séquence envoyée à `a1@zz-client.example.test` et à un collègue `a2@` de la même entreprise ; un STOP relevé
  (réponse In-Reply-To, corps « STOP ») ⇒ les deux inscriptions `arretee` (motifs `opposition` et `entreprise_arretee`),
  trace `opposition` dans `envoi_inscriptions_evenements`, une ligne `envoi_oppositions` `canal = 'releve'`, empreinte
  dans la liste de suppression ; **dans la même transaction que la relève** (aucun passage du séquenceur entre les deux).
- A2 : même chose pour une plainte (`hostile`, et un ARF de plainte).
- A3 : rebond dur (DSN 5.1.1) ⇒ l'inscription et une autre inscription de la MÊME adresse (autre séquence) `arretee` ;
  le collègue de l'entreprise reste `active`.
- A4 : réinscription refusée : après A1, `Inscriptions::inscrire` de la même adresse dans une nouvelle séquence puis un
  passage du séquenceur ⇒ rien n'est réservé, l'inscription est arrêtée (échec fermé ; garde existante, prouvée ici de
  bout en bout).
- A5 : rejeu du même message (rattrapage) ⇒ une seule opposition, une seule trace, rien de neuf.
- A6 cloisonnement : même scénario dans l'Espace B ⇒ aucune inscription ni opposition de A touchée (app ET proprio).
- A7 : STOP sans inscription rattachée mais adresse connue d'une inscription active ⇒ arrêtée par
  `ArretParEntrepriseL1` (via `OpposerAdresse`).
- A8 : `ServiceReponses::classer(…, 'stop')` toujours vert (`ReponsesRoutesTest.php:98` inchangé) : le déplacement
  d'`opposer` ne change rien ; canal `reponse` conservé pour le geste humain.
- A9 : aucune adresse en clair dans `Log` (écouteur de journal) ni dans les tables touchées.

**Mutations** : retirer la branche `Desinscription` (A1) ; utiliser `Evenement::STOP` au lieu d'`OPPOSITION` (A1 :
collègue actif) ; retirer le filtre `workspace_id` de `InscriptionsDuRetour` (A6 proprio) ; appeler `OpposerAdresse`
avant l'arrêt et le faire lever (A1 : l'inscription doit rester arrêtée… ou la relève entière annulée : décrire le
résultat dans la PR) ; canal `reponse` au lieu de `releve` (A1).

Commande : `cd backend && vendor/bin/pint --dirty && vendor/bin/pest --filter='ArretsReleveRR3|RattachementReponseD2|ReponsesRoutes|Sequenceur|ReleveImap'`.

---

## 5. RR-4A (D4) — relève hors espace commercial, sans plantage

Branche `feat/rr-4a-releve-hors-commercial`. Après RR-3A (sinon un STOP hors espace commercial serait perdu).

**Fichiers autorisés**
1. `backend/app/Envoi/Imap/RecepteurRetoursCrm.php`
2. `backend/tests/Feature/Envoi/ReleveImapTest.php` : le test de la ligne 242 (« l'arrêt est refusé bruyamment ») est
   **remplacé par un test plus fort** (« Filet modifié : pourquoi » dans la PR : la garantie « jamais oublié » est
   tenue par l'opposition Envoi de RR-3A, plus par une exception qui faisait perdre le STOP après trois essais)
3. `backend/tests/Feature/Envoi/ReleveHorsCommercialRR4Test.php` (nouveau)
4. `docs/envoi/rebonds-plaintes-k11.md` : une ligne (« hors espace commercial : effets Envoi seuls »)

**Comportement** : si l'espace n'est pas l'espace commercial (ou si aucun espace commercial n'existe),
`recevoir` **rend la main sans rien faire** et écrit `Log::info('Relève : retour hors espace commercial, effets Envoi
seuls.', ['workspace_id' => …, 'classe' => …])` (jamais d'adresse). La chaîne continue : freins, séquence (RR-3A),
stockage. Dans l'espace commercial, rien ne change (fichier temporaire, `crm:campagne:retours`, marques de cache).
Limite connue, écrite dans la doc : hors espace commercial, le CRM ne marque pas l'adresse `invalid` après un rebond dur
et ne compte pas les rebonds mous (l'adresse est tout de même arrêtée côté Envoi : échec fermé).

**Tests rouges d'abord**
- H1 (remplace `ReleveImapTest.php:242`) : espace commercial = un autre ; un STOP relevé ⇒ **aucune exception**, le
  message est `rattache` (`releveCompte = 1`), l'opposition est au registre (`canal = 'releve'`), l'inscription est
  `arretee`, la réponse est stockée (`envoi_reponses`), aucune ligne `envoi_echecs_releve`.
- H2 : plainte et rebond dur hors espace commercial ⇒ freins comptés (`CompteurRetours`), inscription arrêtée.
- H3 : dans l'espace commercial, `crm:campagne:retours` est toujours appelé (commande factice qui compte ses appels) :
  rien ne change.
- H4 (app ET proprio) : la relève de l'Espace B (non commercial) ne touche aucune ligne de l'Espace A.

**Mutations** : remettre l'exception (H1) ; sortir aussi dans l'espace commercial (H3).

---

## 6. RR-4B (D4) — réponses comptées dans les freins ; frein humain nourri par la file réelle

Branche `feat/rr-4b-freins-reponses`. Après RR-3A.

**Fichiers autorisés**
1. `backend/app/Envoi/Imap/RecepteurRetoursFreins.php` : `'reponse_humaine' => 'reponse'` dans `TYPES`
2. `backend/app/Envoi/Reponses/ServiceReponses.php` : méthode statique `nonTraitees(string $ws): int`
   (`a_traiter + a_rattacher`, même SQL que `filtreFile`, `:86-93`, sous `WorkspaceContext::run`)
3. `backend/app/Envoi/Chauffe/DepotChauffe.php` : `reponsesEnAttente` rend
   `max(journal reçues − traitées, ServiceReponses::nonTraitees($ws))`
4. `backend/tests/Feature/Envoi/FreinsReponsesRR4Test.php` (nouveau)

**Chauds** : `Freins.php`, `Seuils.php`, `GardeChauffe.php`, `CompteurRetours.php` (inchangés : ils attendent déjà
`reponse`), `envoi_journal_commandes` (on n'y écrit rien de nouveau).

**Comportement**
- Une réponse humaine rattachée compte UNE fois dans la colonne `reponses` (clé = empreinte du message,
  `CompteurRetours` déjà idempotent). Les auto-réponses ne comptent pas.
- Frein humain : le maximum des deux sources (le journal garde son sens pour les tests existants ; la file réelle le
  nourrit enfin). Une réponse classée par un humain sort de la file ; « à rattacher » compte (ESCALADE 6).
- Effet voulu et sûr : au-delà de `HUMAIN_RALENTIR_AU_DELA` on ralentit, à `humain_pause_a` (15 par défaut,
  `Seuils.php:49`) on met en pause. Le frein ne fait qu'envoyer moins.

**Tests rouges d'abord**
- F1 : deux réponses humaines relevées ⇒ `DepotChauffe::totaux…` rend `reponses = 2` ; la même relue en rattrapage ⇒ 2.
- F2 : une auto-réponse et un rebond mou ⇒ `reponses` inchangé.
- F3 : 15 réponses non classées ⇒ `Freins::freinHumain(...) === PAUSE` (la garde refuse le tirage : test sur
  `GardeChauffe`) ; on en classe une ⇒ 14 ⇒ plus de pause.
- F4 (app ET proprio) : les réponses de l'Espace B ne comptent pas pour A.
- F5 : les tests existants de `ChauffeFreinsTest.php` et `FeuVertFreinsSignauxTest.php` restent verts **sans être
  modifiés** (journal seul : le maximum le garde).

**Mutations** : retirer `reponse_humaine` (F1) ; `min` au lieu de `max` (F3) ; filtre d'espace retiré (F4 proprio).

---

## 7. RR-4C (D4) — absence du bureau ⇒ relance repoussée de 7 jours

Branche `feat/rr-4c-absence-7-jours`. Après RR-4B.

**Fichiers autorisés**
1. `backend/app/Envoi/Sequence/ReportAbsence.php` (nouveau) : `reporter(string $ws, list<string> $ids, DateTimeImmutable $maintenant): int`
2. `backend/app/Envoi/Imap/RecepteurRetoursSequence.php` : `AutoReponse` ⇒ `ReportAbsence::reporter` des inscriptions rattachées
3. `backend/tests/Feature/Envoi/AbsenceSeptJoursRR4Test.php` (nouveau)
4. `docs/envoi/rebonds-plaintes-k11.md` : la ligne 36 (« S13 … NON fait ») devient « fait par RR-4C »

**Comportement**
```sql
UPDATE envoi_inscriptions SET prochaine_echeance = GREATEST(prochaine_echeance, :maintenant + interval '7 days')
WHERE workspace_id = :ws AND id = ANY(:ids) AND statut = 'active'
```
- **Aucun arrêt, aucun nouveau statut, aucune migration** (la contrainte `statut` reste `active|terminee|arretee`).
- `GREATEST` : plusieurs absences ne s'additionnent pas (au plus 7 jours après la dernière) ; une échéance déjà plus
  lointaine n'est jamais avancée. Une inscription `arretee` ou `terminee` n'est jamais touchée (jamais rouverte).
- La fenêtre horaire reste celle du séquenceur (`Sequenceur.php:280-284` replace l'échéance dans la fenêtre au tirage).
- Une absence qui suit un STOP ne change rien (inscription déjà arrêtée).

**Tests rouges d'abord** (horloge figée en UTC)
- B1 : auto-réponse relevée le 2026-12-07 10:00 UTC, échéance au 2026-12-08 ⇒ échéance au 2026-12-14 10:00 ; un passage
  du séquenceur le 2026-12-08 ne réserve rien.
- B2 : deuxième absence le 2026-12-09 ⇒ 2026-12-16 ; échéance déjà au 2027-01-10 ⇒ inchangée.
- B3 : inscription `arretee` ⇒ inchangée, statut inchangé.
- B4 (app ET proprio) : l'absence d'un retour de l'Espace B ne repousse rien dans A.
- B5 : l'auto-réponse n'arrête rien et n'oppose rien (`envoi_oppositions` vide).

**Mutations** : retirer `statut = 'active'` (B3) ; `prochaine_echeance + 7` au lieu de `GREATEST` (B2) ; retirer le
filtre d'espace (B4 proprio).

---

## 8. RR-5A (D5) — classement humain ⇒ arrêt + tâche (« pas maintenant » = arrêt + tâche)

Branche `feat/rr-5a-effets-classement`. Après RR-4C.

**Fichiers autorisés**
1. `backend/app/Envoi/Reponses/EffetsClassement.php` (nouveau)
2. `backend/app/Envoi/Reponses/ServiceReponses.php` : `classer` appelle `EffetsClassement::appliquer` APRÈS l'écriture
   de la ligne (même `WorkspaceContext`), et remplit `resume_crm.tache`
3. `backend/app/Http/Controllers/Api/EnvoiReponsesController.php` : lit `creer_tache` (booléen, défaut faux) et le passe
4. `backend/tests/Feature/Envoi/EffetsClassementRR5Test.php` (nouveau)

**Chauds** : `CompanyTachesController.php` et `PersonnesController.php` (on en reprend la forme d'écriture, on ne les
touche pas), `app/Crm/Taches/MesTaches.php` (G2 lit déjà `user_id IS NULL`), `Inscriptions.php`, `Sequenceur.php`,
`routes/api.php` (#670), `reponses.yaml` (le contrat porte déjà `creer_tache` et `resume_crm.tache`).

**Comportement** (inscriptions visées = celles du retour via `InscriptionsDuRetour` sur `envoi_evenements.id_injection`
ou, à défaut, `envoi_rattachements.id_injection` ; sinon les inscriptions `active` de l'espace dont
`empreinte_adresse = envoi_reponses.empreinte_expediteur`)

| Classement humain | Séquence | Tâche (`activities`, `kind = 'task'`) |
|---|---|---|
| `interesse` | `Evenement::REPONSE` (⇒ entreprise) | si `creer_tache` : « Rappeler : réponse intéressée », échéance aujourd'hui (Paris) |
| `question` | `Evenement::REPONSE` | si `creer_tache` : « Répondre à la question reçue », échéance aujourd'hui |
| `pas_maintenant` | `Evenement::REPONSE` | **toujours** : « Recontacter (pas maintenant) », échéance aujourd'hui + 90 jours (ESCALADE 3) |
| `refus` | `Evenement::REPONSE` | aucune |
| `absence` | `ReportAbsence` (+7 jours, pas d'arrêt) | aucune |
| `stop` | inchangé (opposition, `:169-172`) | aucune |

- Tâche : `workspace_id = ws`, `user_id = NULL` (tâche système, visible dans « Mes tâches », contrat G2 § 8.2),
  `type = kind = 'task'`, `occurred_at = now()`, `due_at` (minuit Paris converti en UTC), `subject_type = 'company'`
  et `subject_id = entreprise_id` de la première inscription visée, sinon `subject_type = 'contact'` si une fiche est
  liée (`geste = 'fiche'`), sinon `NULL` ; `title` ci-dessus ; `content = NULL` (jamais l'extrait ni l'adresse) ;
  `payload = {origine: 'reponse', genre: 'relance'|'tache', contexte: 'Réponse n° <id>', reponse_id: <id>}` ;
  `external_ref = 'envoi:reponse:<id>:<classement>'` en `insertOrIgnore` (index unique `workspace_id, external_ref`) :
  rejouer le geste ne crée rien ; corriger vers un autre classement crée la tâche de l'autre classement, l'ancienne
  reste (jamais supprimée). Entreprise ou contact d'un autre espace : jamais lus (filtre d'espace dans chaque jointure).
- L'arrêt passe par `Inscriptions::evenement` (verrou, trace, propagation) : jamais d'UPDATE direct.
- `resume_crm.tache` = le titre de la tâche (créée ou déjà là), sinon `null`.
- Ordre : ligne de classement, puis arrêts, puis tâche, **dans une seule transaction** (`DB::transaction`) : si la
  tâche échoue, rien n'est écrit et le geste répond 500 propre ; le rejeu refait tout (idempotent).

**Tests rouges d'abord** (routes réelles `POST /api/v1/envoi/reponses/{id}/classer`, propriétaire de l'espace)
- C1 : réponse relevée rattachée, inscription déjà arrêtée par la relève ⇒ `interesse` + `creer_tache` ⇒ une tâche
  `user_id NULL`, sujet = l'entreprise, `resume_crm.tache` non nul ; elle apparaît dans `GET /crm/taches` (G2).
- C2 : réponse « à rattacher » (aucun arrêt par la relève) dont l'expéditeur a une inscription active ⇒ `pas_maintenant`
  (sans `creer_tache`) ⇒ inscription `arretee` motif `reponse`, collègue `entreprise_arretee`, tâche échéance J+90.
- C3 : `refus` ⇒ arrêt, aucune tâche ; `absence` ⇒ échéance +7 jours, statut `active`.
- C4 : rejouer `interesse` deux fois ⇒ une tâche ; corriger `interesse` → `pas_maintenant` ⇒ deux tâches, aucune supprimée.
- C5 (app ET proprio) : réponse de l'Espace B ⇒ 404 ; aucune tâche ni arrêt dans A ; une entreprise de B portant le
  même identifiant numérique n'est jamais sujet.
- C6 : `content` et `payload` de la tâche ne contiennent ni adresse ni extrait (recherche de `@` et de l'extrait).
- C7 : `ReponsesRoutesTest.php` vert sans modification.

**Mutations** : retirer l'arrêt pour `pas_maintenant` (C2) ; `user_id = auteur` (C1 : la tâche reste visible, mais
le contrat G2 dit système : le test vérifie `NULL`) ; `insert` au lieu d'`insertOrIgnore` (C4) ; filtre d'espace de la
jointure entreprise retiré (C5 proprio).

---

## 9. RR-5B (D5) — avis « réponse intéressée »

Branche `feat/rr-5b-avis-interesse`. Après RR-5A.

**Fichiers autorisés**
1. `backend/app/Envoi/Reponses/AvisReponseInteressee.php` (nouveau)
2. `backend/app/Envoi/Reponses/EffetsClassement.php` : un appel pour `interesse`
3. `backend/tests/Feature/Envoi/AvisReponseInteresseeRR5Test.php` (nouveau)

**Chauds** : `app/Socle/Alertes/Notifier.php` (il vise l'adresse d'alerte principale : pas le bon public),
`NotificationsController.php`, la migration `notifications`.

**Comportement** : APRÈS validation de la transaction du geste (`DB::afterCommit`), pour chaque membre de l'espace
(`user_workspaces.workspace_id = ws`, `role_slug IN ('owner','admin')`, `joined_at` non nul, compte non supprimé)
**sauf l'auteur du geste** : une ligne `notifications` (`workspace_id = ws`, `user_id`, `type = 'envoi.reponse_interessee'`,
`title = 'Réponse intéressée'`, `body = 'Une réponse a été classée « intéressée » ; une tâche de rappel est prête.'`,
`action_url = '/envoi/reponses/<id>'`, `produit = 'envoi'`, `gravite = 'info'`), puis l'événement
`NotificationCreated` (forme de `AlerteArchiveOrigines.php:53-63`). **Une seule fois par réponse** : rien si une
notification `type = 'envoi.reponse_interessee'` et `action_url` identique existe déjà dans l'espace. Ni nom, ni
adresse, ni extrait dans le titre ou le corps. Une panne de l'avis est journalisée (classe seule) et ne défait pas le geste.

**Tests rouges d'abord**
- V1 : `interesse` par le propriétaire P1 ⇒ un avis pour P2 (propriétaire) et pour A (administrateur), aucun pour P1,
  aucun pour un `viewer`, aucun pour un membre d'un autre espace.
- V2 : rejouer, corriger puis reclasser `interesse` ⇒ toujours un seul avis par destinataire.
- V3 : `pas_maintenant`, `question` ⇒ aucun avis.
- V4 (app ET proprio) : aucune notification écrite dans l'Espace B.
- V5 : `title`, `body` sans `@`, sans l'extrait ni le nom.

**Mutations** : retirer l'exclusion de l'auteur (V1) ; retirer le filtre `workspace_id` des membres (V1/V4) ; retirer la
garde « une seule fois » (V2).

---

## 10. RR-5C (D5) — arrêt sur rendez-vous pris / entreprise devenue cliente

Branche `feat/rr-5c-arret-relation`. Indépendant : peut partir tout de suite (aucune migration).

**Fichiers autorisés**
1. `backend/app/Envoi/Sequence/RelationAvancee.php` (nouveau) : `motif(string $ws, int $entrepriseId, int $contactId, DateTimeImmutable $inscriteLe): ?string`
2. `backend/app/Envoi/Sequence/Sequenceur.php` : UN contrôle dans `reserverEtPreparer`, juste après
   `entrepriseArretee` (`:276-278`) : `if (($m = $this->relation->motif(...)) !== null) return $this->arreter($ins, $m, $maintenant);`
   (dépendance optionnelle du constructeur, `new RelationAvancee` par défaut, comme `LecteurVariablesDb` `:364`)
3. `backend/tests/Feature/Envoi/ArretRelationRR5Test.php` (nouveau)

**Chauds** : tout le reste du `Sequenceur.php` ; `Evenement.php` (les nouveaux motifs ne sont PAS des motifs qui
propagent : chaque collègue fait sa propre lecture), `app/Crm/**` (lecture seule), `GestesCampagne`, `ReetalementStock`.

**Comportement** (deux requêtes au plus, filtre `workspace_id` dans chacune)
- `client` : `companies.lifecycle_stage = 'client'` pour `id = entrepriseId AND workspace_id = ws`.
- `rendez_vous` : une ligne `activities` de l'espace telle que
  `(subject_type = 'company' AND subject_id = entrepriseId AND kind = 'calendly_booked')` (index
  `idx_activities_sujet_fiche`) OU `(kind = 'task' AND payload->>'genre' = 'rendez_vous' AND
  ((subject_type = 'company' AND subject_id = entrepriseId) OR (subject_type = 'contact' AND subject_id = contactId)))`
  (index `idx_activities_taches`), avec `created_at >= inscriteLe − 30 jours` (ESCALADE 4).
- Motif posé : `client` ou `rendez_vous` (pas de CHECK sur `motif_arret`, `2026_11_10_000031…:27`). L'inscription
  passe `arretee` ; rien n'est réservé ni envoyé ; aucun autre état touché. Les collègues sont arrêtés à leur propre
  tirage (même lecture par entreprise). Aucune réinscription automatique si l'entreprise redevient « perdu ».
- Placé AVANT la fenêtre horaire et la réservation : le contrôle coûte deux lectures indexées par tirage.

**Tests rouges d'abord** (séquenceur réel, faux serveur ; sur `main` le message part)
- E1 : entreprise passée `client` après l'inscription ⇒ au tirage : `arretee` motif `client`, aucun message réservé,
  aucun appel au faux serveur ; le collègue aussi, à son tirage.
- E2 : `calendly_booked` sur l'entreprise ⇒ `rendez_vous` ; tâche `genre = rendez_vous` sur le contact ⇒ `rendez_vous`.
- E3 : rendez-vous créé 60 jours avant l'inscription ⇒ l'envoi part ; 10 jours avant ⇒ arrêt.
- E4 (app ET proprio) : entreprise `client` dans l'Espace B avec le même identifiant numérique, ou activité de B ⇒
  aucun effet sur A.
- E5 : entreprise `opportunite`, `perdu` ⇒ l'envoi part (aucun faux arrêt).
- E6 : `DB::listen` : le contrôle ajoute au plus 2 requêtes par tirage.

**Mutations** : retirer le filtre d'espace (E4 proprio) ; `kind = 'calendly_booked'` seul (E2 tâche) ; placer le
contrôle après la réservation (E1 : une ligne `reserve` apparaît).

---

## 11. RR-6A (D6) — service : annuler, corriger, STOP non annulable

Branche `feat/rr-6a-annuler-corriger`. Après RR-0 **déployée** et RR-5B fusionnée.

**Fichiers autorisés**
1. `backend/app/Envoi/Reponses/ServiceReponses.php`
2. `backend/tests/Feature/Envoi/AnnulerCorrigerRR6Test.php` (nouveau)

**Comportement**
- Lecture (`BASE`, `:32-50`) : le classement courant est la DERNIÈRE ligne `geste IN ('classement','annulation')`.
  Si c'est une `annulation` : `classement` = dernière suggestion du classificateur (`s`, sinon `a_rattacher`), `auteur`
  = `classificateur` ⇒ la réponse revient « à traiter » (ou « à rattacher »). Le rejeu de `classer` (`:176-179`) lit la
  même dernière ligne. `nonTraitees` (RR-4B) suit automatiquement (même filtre).
- `annuler(string $ws, string $userId, int $id): array{0: string, 1: ?array}` : sous le verrou de la réponse
  (`verrou`, `:308-311`) ; inconnue de l'espace ⇒ `inconnue` ; classement courant non humain ⇒ `pas_traitee` ;
  classement courant `stop` ⇒ `stop_definitif` ; sinon INSERT `geste = 'annulation'`, `classement` = celui annulé,
  `auteur = 'humain'`, `auteur_id`, `confiance = 100` ⇒ `ok` + résumé.
- **Annuler ne défait aucun effet** : inscriptions arrêtées restent arrêtées, tâche et avis restent (la tâche se ferme
  à la main dans « Mes tâches »), opposition intacte. La réponse `ok` porte
  `message: 'Classement annulé. Les arrêts et les tâches déjà créés sont conservés.'`.
- Corriger depuis « Traitées » = `classer` sur une réponse traitée : permis, MAIS si le classement courant est `stop`
  et le nouveau ne l'est pas ⇒ `stop_definitif` (aucune ligne écrite). Vers `stop` : opposition comme aujourd'hui.
  (`classer` rend donc `array{0: string, 1: ?array}` ou garde sa signature et lève une `ClassementRefuse` : au choix du
  lot, la route RR-6B traduit en 409.)

**Tests rouges d'abord** (service sous `app` ET `proprio`)
- N1 : `interesse` puis `annuler` ⇒ la réponse est de nouveau dans `a_traiter`, `traitee = false`, deux lignes en base
  (rien d'effacé) ; l'inscription reste `arretee` ; la tâche existe toujours.
- N2 : `annuler` deux fois ⇒ la seconde `pas_traitee`, une seule ligne d'annulation.
- N3 : `stop` puis `annuler` ⇒ `stop_definitif`, aucune ligne ; `stop` puis `classer(refus)` ⇒ `stop_definitif` ;
  l'opposition et la liste de suppression sont intactes.
- N4 : `refus` puis `classer(interesse)` ⇒ classement `interesse`, effets RR-5A appliqués (tâche).
- N5 : annuler puis reclasser le même classement ⇒ une nouvelle ligne (le rejeu ne doit pas croire à un doublon).
- N6 : réponse de l'Espace B ⇒ `inconnue` (proprio : filtre explicite).
- N7 : `nonTraitees` augmente de 1 après une annulation.

**Mutations** : lire la dernière ligne `classement` seule (N1) ; retirer la garde `stop` (N3) ; rejeu comparé à la
dernière ligne `classement` au lieu de la dernière ligne courante (N5).

---

## 12. RR-6B (D6) — route d'annulation et contrat

Branche `feat/rr-6b-route-annuler`. Après RR-6A.

**Fichiers autorisés**
1. `backend/routes/envoi/reponses.php` : `Route::post('/envoi/reponses/{id}/annuler', …)` dans le groupe
   `permission:workspaces.manage` (comme `classer`)
2. `backend/app/Http/Controllers/Api/EnvoiReponsesController.php` : `annuler` ; `classer` traduit `stop_definitif`
3. `backend/openapi/envoi/reponses.yaml` : chemin `annuler` (200 `ReponseApresGeste`, 404, 409 `pas_traitee` et
   `stop_definitif` au schéma `Erreur`), et 409 `stop_definitif` sur `classer`
4. `frontend/src/features/envoi/api/reponses.d.ts` : régénéré par `pnpm api:envoi` (jamais à la main)
5. `backend/tests/Feature/Envoi/Routes/instantane-routes-envoi/reponses.json` : une entrée
6. `backend/tests/Feature/Envoi/Routes/ReponsesRoutesTest.php` : tests AJOUTÉS (aucun existant modifié)

**Chauds** : `routes/api.php` et `nr20-routes.json` (#670 ; les routes Envoi sont dans `routes/envoi/*` et leur
instantané à part), `frontend/tests/non-regression/routes.test.ts` (#670).

**Tests rouges d'abord** : annuler ⇒ 200 conforme au contrat (`ContratOpenApiEnvoi::ecartsReponse`) ;
administrateur ⇒ 403, `viewer` ⇒ 403 ; autre espace ⇒ 404 ; identifiant non numérique ⇒ 404 ; `stop` ⇒ 409
`stop_definitif` (annuler ET classer) ; non traitée ⇒ 409 `pas_traitee` ; aucune écriture sur 403/404/409 ;
`RoutesEnvoiInstantaneTest` vert avec l'entrée ajoutée.

**Mutations** : route dans le groupe `users.manage` (403 admin rougit) ; 200 au lieu de 409 sur `stop`.

---

## 13. RR-6C (D6) — écran : classer à la main « Intéressé » et « Question »

Branche `feat/rr-6c-classer-interesse-question`. Après RR-5A (sinon l'écran promettrait des effets absents).

**Fichiers autorisés**
1. `frontend/src/features/envoi/reponses/composants/DetailReponse.tsx` : `GESTES` reçoit `4` « Intéressé »
   (`interesse`) et `5` « Question » (`question`) ; ces deux gestes et « Plus tard » envoient `creer_tache: true`
2. `frontend/src/features/envoi/reponses/ReponsesPage.tsx` : `TOUCHES_CLASSEMENT` reçoit `'4'` et `'5'` (même règle
   de saisie : pas dans un champ, pas pendant la modale STOP) ; le KPI « Intéressées » compte les réponses dont le
   classement OU la suggestion est `interesse`
3. `frontend/tests/screens/envoi/ReponsesPage.test.tsx` : tests AJOUTÉS

**Tests rouges d'abord** (vitest, faux serveur de démo) : clic « Intéressé » ⇒ `POST classer` avec
`{classement: 'interesse', creer_tache: true}` et bandeau de confirmation CRM ; touche `5` ⇒ `question` ; touche `4`
dans le champ de réponse ⇒ aucun appel ; boutons utilisables au clavier (`aria-keyshortcuts`), cible 44 px sur mobile ;
`tsc`, `eslint` propres.

**Mutations** : retirer `creer_tache` (le test de la requête rougit) ; touche active dans un champ (rougit).

---

## 14. RR-6D (D6) — écran « Traitées » : corriger et annuler

Branche `feat/rr-6d-traitees-corriger`. Après RR-6B ET RR-6C.

**Fichiers autorisés**
1. `frontend/src/features/envoi/reponses/ReponsesTraiteesPage.tsx` : sur chaque ligne, « Corriger » (liste des
   classements, STOP passe par `ModaleStop`) et « Annuler » ; pour une ligne `stop`, aucun des deux, avec le texte
   « Opposition définitive : elle ne s'annule pas depuis cet écran. »
2. `frontend/src/features/envoi/reponses/composants/requetes.ts` : `useAnnuler` (invalide les listes et les compteurs)
3. `frontend/src/demo/handlers/envoi/reponses.handlers.ts` : `POST …/annuler` (mêmes 409 que le serveur)
4. `frontend/tests/screens/envoi/ReponsesTraiteesPage.test.tsx` (nouveau)

**Comportement** : « Annuler » demande une confirmation (« Le classement est annulé ; les arrêts et les tâches déjà
créés sont conservés. ») puis la réponse quitte « Traitées » et revient dans « À traiter ». Un 409 affiche le message
du serveur, rien n'est caché ; aucun bouton « supprimer ».

**Tests rouges d'abord** : corriger `refus` → `interesse` appelle `classer` ; annuler retire la ligne de la liste ;
ligne `stop` sans bouton ; 409 `stop_definitif` affiché ; l'onglet « À traiter » remonte de 1 ; accessibilité
(boutons nommés, focus rendu après la modale). Si `frontend/tests/non-regression/routes.test.ts` exige une ligne pour
le nouvel appel (route servie par RR-6B : normalement non), l'ajouter avec « Filet modifié » — seulement après la
fusion de #670.

---

## 15. Fichiers chauds communs : aucune PR de ce cahier ne les touche
`app/Providers/EnvoiServiceProvider.php`, `config/*.php`, `.env.example`, `routes/console.php`, `routes/api.php`,
`tests/Feature/NonRegression/instantanes/nr20-routes.json` et `frontend/tests/non-regression/routes.test.ts` (#670),
`frontend/src/features/envoi/campagnes/**` (#669), `app/Envoi/Imap/ReleveImap.php`, `Classificateur.php`, `Verp.php`,
`app/Envoi/Gardes/**`, `app/Crm/Campagnes/EligibiliteAdresse.php`, `app/Crm/Opposition/**`, `app/Crm/Outbound/**`,
`ArretParEntrepriseL1.php`, `TerminerEffetsOpposition.php`, `EffetsOpposition.php`, `app/Envoi/Smtp/**`,
`app/Envoi/Temoins/**`, `app/Envoi/Chauffe/` hors `DepotChauffe.php` (RR-4B), `Inscriptions.php`, `Evenement.php`,
`tests/Support/SemeurTablesScopees.php` (aucune table nouvelle), `LISTE.json`, toute migration existante, `infra/**`.

## 16. Rôles : chaque test de sécurité deux fois
Sous `pgsql_app` (RLS appliquée) ET sous le propriétaire (seul le filtre `workspace_id` protège). Modèles :
`tests/Feature/Envoi/RattachementReponseD2Test.php:42-56` (`d2Sous`) et `SchemaLienCampagneSequenceTest.php:77-95`.
Un test qui ne passe que sous l'un des deux est un défaut.

## 17. Avant chaque PR
`cd backend && vendor/bin/pint --dirty`, tests ciblés `vendor/bin/pest --filter=…`, filet si une migration ou un
instantané bouge (`php vendor/bin/pest --group=non-regression --no-coverage`) ; frontend : `pnpm exec vitest run` ciblé,
`tsc`, `eslint`. Si `vendor/` manque, le dire dans la PR. Description : fichiers autorisés (et seulement eux), tests vus
rouges puis verts, mutations essayées, « Filet modifié : pourquoi » s'il y a lieu. Ne jamais fusionner soi-même.

---

## 18. ESCALADE (décisions du fondateur ; chaque lot avance avec la recommandation, toutes sans risque d'envoi en trop)

- **ESCALADE 1 — un STOP relevé arrête-t-il les collègues de l'entreprise ?** Recommandation : **oui** (RR-3A le traite
  en opposition, comme le lien et le STOP humain). Si le fondateur préfère « la personne seule », il suffit de passer
  `Evenement::STOP` et `OpposerAdresse` restera par adresse : une ligne dans RR-3A.
- **ESCALADE 2 (juridique) — lever une opposition** : jamais depuis l'écran (409 `stop_definitif`, RR-6A). Une erreur
  de classement STOP se corrige par une procédure écrite (demande de la personne, trace), hors de ce cahier.
- **ESCALADE 3 — délai de la tâche « pas maintenant »** : 90 jours par défaut (constante dans `EffetsClassement`).
- **ESCALADE 4 — signaux « rendez-vous » et « client »** : `calendly_booked` ou tâche `genre = rendez_vous` créés au plus
  30 jours avant l'inscription ; `client` = `companies.lifecycle_stage`. Les affaires gagnées (G2-D, #670) pourront
  s'ajouter par un petit lot après sa fusion.
- **ESCALADE 5 — qui reçoit l'avis « réponse intéressée »** : propriétaires et administrateurs de l'espace, sauf
  l'auteur. Aucun courriel (cloche seulement : règle « aucun envoi réel »).
- **ESCALADE 6 — le frein humain compte-t-il les réponses « à rattacher » ?** Recommandation : oui (elles attendent un
  humain) ; seuils inchangés (`Seuils.php`).
- **ESCALADE 7 (production) — allumer `ENVOI_RELEVE_ACTIF`** : seulement après RR-3A, RR-4A et RR-4B fusionnés (sinon
  un STOP hors espace commercial est perdu et les réponses ne freinent rien), et après la bascule d'`ENVOI_REEL_ACTIF`.

## 19. Hors de ce cahier
Réponse dans le même fil (`repondre` reste 409 au palier 0) ; suggestions automatiques « intéressé / question » par le
classificateur ; rattachement manuel qui rejouerait les effets d'un STOP relevé « à rattacher » (aujourd'hui : l'humain
classe STOP, ce qui suffit) ; compteurs de santé par boîte (R04) ; suivi d'ouverture (aucun).

# CAHIER — L5 « gestes campagne → séquence » et L6 « compteurs réels »

**Pour le développeur :** dépôt public. Dans le code, les tests, les commits et les PR : écrire « le fondateur », jamais de nom ; données fictives en `*.example.test`.
Aucun drapeau touché : `ENVOI_REEL_ACTIF` reste faux, et `ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`, `USINE_AGREGATS_CRM_ENABLED` ne sont ni lus ni écrits ;
`config/envoi.php` et les `.env*` inchangés. Aucune suppression ni purge de données de personnes : une inscription « fermée » passe `arretee` et reste en base.
Base relevée : `main` d42beb0 (L4-C #645 fusionné). Cahier précédent : `X1-L4-P2-cahier.md` (PR #609). **Vérifier chaque numéro de ligne avant de s'y fier.**

PR ouvertes relevées avant d'écrire (`gh api …/pulls` + fichiers de chaque PR) : #647 touche `routes/api.php` et `tests/Feature/NonRegression/instantanes/nr20-routes.json` ;
#638 touche `ChefOrchestreTest.php`, `DesinscriptionTest.php`, `nr07`/`nr08` ; #639 touche `app/Envoi/Imap/*` ; #354 et #352 hors Envoi ; les brouillons de conception ne touchent que `docs/`.
Aucune PR ouverte ne touche `app/Envoi/Campagnes/**`, `EnvoiCampagnesController.php`, `routes/envoi/campagnes.php`, `openapi/envoi/campagnes.yaml` ni `frontend/src/features/envoi/campagnes/**`.

## 0. Ordre de fusion (une fusion à la fois, chaque PR ≤ 400 lignes tests compris)

| Ordre | Lot | Contenu | Préalables |
|---|---|---|---|
| 1 | **L5-A** | `GestesCampagne` : verrou unique, geste « lancer », propagation « reprise » à la séquence (service seul, aucune route) | rien (L4-B/L4-C fusionnés) |
| 2 | **L5-B** | geste « arrêter », clôture des inscriptions d'une campagne arrêtée ou terminée, filtre du re-étalement, étape dans la commande L4-C | L5-A fusionnée |
| 3 | **L5-C** | routes `lancer` / `arreter`, contrat `campagnes.yaml`, garde de la reprise directe d'une séquence née d'une campagne | L5-B fusionnée **et #647 fusionnée** (instantané `nr20`) |
| 4 | **L6-0** | migration SEULE : index d'expression `envoi_evenements (workspace_id, (id_injection::text))` | rien (peut partir tout de suite) |
| — | *déploiement* | L6-0 **déployée**, `migrate:status` sans `Pending` | obligatoire avant L6-A |
| 5 | **L6-A** | `CompteursCampagne` (lecture seule, une requête) + tests | L6-0 déployée |
| 6 | **L6-B** | branchement dans `LectureCampagnes` + contrat (compteurs, préparation) | L6-A et L5-C fusionnées (même contrat) |
| 7 | **L5-D** | écran : boutons Lancer / Arrêter, messages des refus, état de préparation | L5-C et L6-B fusionnées |
| 8 | **L6-C** | écran : cartes des compteurs réels | L5-D fusionnée (même page) |

Aucune PR ne s'empile sur une autre : chaque branche part de `main` après la fusion de son préalable.

---

## 1. Constats vérifiés dans le code (ils changent la conception)

1. **Gestes existants, pas de « lancer » ni d'« arrêter ».** `routes/envoi/campagnes.php:15-19` : `pause`, `reprise`, `terminer`, `dupliquer`, `date` sous `permission:workspaces.manage` (propriétaire seul). Rien ne fait passer une campagne `programmee → en_cours`, rien ne la mène à `arretee` (la matrice le permet : `DepotCampagnes.php:20-28`).
2. **La pause se propage déjà, la reprise jamais.** Déclencheur `envoi_campagnes_pause_sequence` (`2026_12_04_000030…:99-109`) : passage à `en_pause`, `terminee`, `arretee` d'une campagne reliée ⇒ geste `pause` sur sa séquence. Aucun déclencheur ni code n'écrit `reprise` : `GestesCampagne.php:14` (« aucun geste … n'écrit `sequence_id` ») et `EnvoiCampagnesController.php:17`. Aujourd'hui une campagne reprise garde une séquence en pause : c'est **fermé** (rien ne part), donc le bon sens de l'échec. L5 garde ce principe : la reprise de séquence est écrite par l'application, explicitement, jamais par un déclencheur automatique (un oubli laisse la séquence en pause, pas l'inverse).
3. **La base garde la reprise.** `envoi_sequences_gestes_reprise_campagne` (`000030:125-154`) : un geste `reprise` sur une séquence née d'une campagne exige campagne `en_cours` **et** matérialisation `terminee` (lues `FOR SHARE`) ; l'horloge réelle est imposée (`:111-123`). L'état d'une séquence est son DERNIER geste (`Sequenceur.php:107-108`, `:128-131`).
4. **Deux verrous d'espace différents pour la même campagne.** `GestesCampagne::sousVerrou` prend `envoi:campagnes:<ws>` (`GestesCampagne.php:119-126`) ; `DepotCampagnes::sousVerrou` prend `envoi_campagnes_espace:<ws>` puis `envoi_campagne:<id>` (`DepotCampagnes.php:64-75`), comme la matérialisation (`MaterialisationCampagne.php:142-145`, puis `envoi_etalement:<ws>`). Un geste et un lot de matérialisation ne se sérialisent aujourd'hui que par le verrou de ligne (`FOR SHARE` de `MaterialisationCampagne.php:146` contre l'`UPDATE` du geste). L5 aligne tout sur `DepotCampagnes::sousVerrou`.
5. **Les gestes directs sur la séquence prennent un troisième verrou** : `envoi_sequence_geste|<ws>|<seq>` (`DepotSequences.php:58`). La route `/envoi/sequences/{id}/reprise` (`routes/envoi/sequences.php:13-15`) peut donc reprendre une séquence née d'une campagne (si la base l'accepte), sans passer par la campagne ni son journal.
6. **Une campagne arrêtée ou terminée garde ses inscriptions `active`.** Conséquences, toutes trois vérifiées :
   a. l'index unique « une inscription active par adresse » (`2026_12_05_000180…`) empêche ces adresses d'entrer dans **toute autre** campagne de l'espace, pour toujours ;
   b. elles comptent dans « déjà prévu par jour » (`MaterialisationCampagne.php:232-246`) : la capacité de l'espace est consommée à vie ;
   c. `ReetalementStock` les re-étale chaque jour (`ReetalementStock.php:47-58` : filtre `s.campagne_id IS NOT NULL`, **aucun filtre sur le statut de la campagne**).
   Il faut donc fermer ces inscriptions (statut `arretee`, motif propre), sans rien effacer.
7. **Grandes campagnes : « lancer » attend la fin de la préparation.** La base exige `terminee` pour reprendre (constat 3). L'horizon est de `P × 20` jours ouvrés pour TOUT l'espace (`EtalementEcheances::HORIZON_JOURS_OUVRES`, `P = min(ENVOI_PLAFOND_JOUR, plafond de l'espace, 300)`, 20 par défaut). Une campagne plus grande que la place libre ne se matérialise ensuite qu'au rythme d'environ `P` inscriptions par jour ouvré (le jour qui entre dans l'horizon). Exemple : 1 000 adresses, P = 20 ⇒ environ 30 jours ouvrés avant de pouvoir lancer. Décision métier : **ESCALADE 1**. L5 reste fermé : refus `materialisation_en_cours`, avec la progression.
8. **Remarque hors lot (L4-C) :** le débordement du re-étalement est posé sans plafond sur le premier jour ouvré après l'horizon (`ReetalementStock.php:76`, `:109-120`). Sans effet d'envoi (le séquenceur et la passerelle plafonnent), mais `dejaParJour` de ce jour-là dépasse `P`. À reprendre dans le lot qui touchera `ReetalementStock` après L5-B ; L5-B n'y touche que pour le filtre du constat 6c.
9. **`programmee → arretee` exige une séquence** (`DepotCampagnes::EXIGENT_SEQUENCE`, `:30`, contrôle `:166-168`). Une campagne de l'assistant porte toujours des boîtes (`AssistantCreation.php:431-435`) donc reste en `reglage_non_applique` (`MaterialisationCampagne.php:69-71`) et n'a jamais de séquence : elle ne peut pas être « arrêtée », seulement renvoyée à valider (non reliée : `DepotCampagnes.php:163-164`). L5 garde la matrice ; refus `sans_sequence` avec un message qui dit quoi faire.
10. **Un test existant crée des campagnes `en_cours` / `en_pause` sans matérialisation** (`tests/Feature/Envoi/CampagnesSuiviRoutesTest.php:41-61`, aide `suivCampagne`) et attend `200` sur la reprise (`:150-165`). Cet état est impossible par le chemin légal après L5 (lancer exige `terminee`). Voir L5-A, « filet modifié ».
11. **Motifs d'arrêt lus par le séquenceur.** `Sequenceur.php:377-378` et `:387-388` bloquent une inscription si une AUTRE de la même entreprise ou de la même adresse est arrêtée pour `reponse`, `opposition`, `garde:opposition`, `entreprise_arretee`, `stop`, `rebond_dur`, `adresse_arretee`. Les nouveaux motifs `campagne_arretee` / `campagne_terminee` ne doivent **pas** y entrer (arrêter une campagne n'est pas une opposition de la personne). `motif_arret` n'a pas de CHECK de valeurs (`2026_11_10_000031…:27`, `:36`) : aucune migration pour L5.
12. **Compteurs aujourd'hui à zéro en dur** : `LectureCampagnes.php:56` (`prevus`, `envoyes`), `:72` (`par_boite`), `:81-82` (fiche), `:104-105` (liste) ; destinataires vides (`EnvoiCampagnesController.php:75-76`).
13. **Aucun suivi d'ouverture** : `ComposeurSequenceMessage.php:17` (« Aucun suivi d'ouverture »), et `pilotage.yaml` le dit (`R04.md`). « Ouverts » vaut donc `null` avec `suivi_ouvertures: false` ; L6 n'ajoute AUCUN pixel ni lien suivi (**ESCALADE 4**).
14. **Sources réelles des compteurs** (toutes en RLS FORCE, filtre d'espace explicite exigé) :
    - `envoi_inscriptions` (statut `active|terminee|arretee`, `motif_arret`, `2026_11_10_000031…:25-36`) ;
    - `envoi_messages` : `UNIQUE (inscription_id, etape)`, statut `reserve|envoye|refuse|reporte` (`2026_11_10_000032…:22-29`) ;
    - `envoi_inscriptions_evenements` : `UNIQUE (inscription_id, evenement)`, `reponse|stop|opposition|rebond_dur` (`2026_11_30_000320…:20-30`), ajout seul ;
    - `envoi_evenements` (relève IMAP) : `classe` `reponse_humaine|auto_reponse|desinscription|hostile|rebond_dur|rebond_mou`, `statut rattache|a_rattacher`, `id_injection UUID` (`2026_11_10_000060…:20-37`), **aucun index sur `id_injection`** ;
    - rattachement retour → message : trois formes, `RecepteurRetoursSequence.php:58-78` (`id_injection`, `id_injection_serveur`, clé `seq-<inscription>-<étape>` de `envoi_injections_locales`) ;
    - préparation : `envoi_campagnes_materialisations.compteurs` (`membres`, `retenus`, motifs d'exclusion, `inscrits`, `ecartes_insertion`).
15. **Fichiers chauds** : `nr20-routes.json` est dans #647 (ouverte) ⇒ L5-C attend sa fusion. `frontend/src/features/envoi/campagnes/CampagneSuiviPage.tsx` sera touché par L5-D puis L6-C : jamais en même temps.

---

## 2. LOT L5-A — « lancer » et « reprendre » propagés à la séquence (service seul)

### 2.1 Fichiers
- Modifier `backend/app/Envoi/Campagnes/GestesCampagne.php`
- Modifier `backend/app/Envoi/Campagnes/DepotCampagnes.php` : **une seule** modification, rendre publique la lecture du rejeu (`cleDejaJournalisee` → `public function rejeu(string $ws, string $id, string $vers, string $cle): bool`, corps inchangé ; l'appel interne de `transitionner` suit le nouveau nom).
- Modifier `backend/app/Envoi/Exceptions/CampagneRefusee.php` : fabriques `materialisationEnCours(int $inscrits, ?int $membres)`, `materialisationBloquee(string $motif)`, `aucunDestinataire()`, `repriseRefuseeBase()` (toutes 409, codes ci-dessous).
- Créer `backend/tests/Feature/Envoi/GestesCampagneSequenceTest.php` (préfixe `gcs`)
- Créer `backend/tests/Support/campagne-lancer-concurrent.php` (modèle `campagne-geste-concurrent.php` ; gestes `lancer|pause|reprise` ; sortie 0/3/1)
- Modifier `backend/tests/Feature/Envoi/CampagnesSuiviRoutesTest.php` : **l'aide `suivCampagne` seulement** (voir 2.5)
- Créer `docs/envoi/lots/L5-A.md`

**À ne pas toucher :** `Sequenceur.php`, `DepotSequences.php` (L5-C), `MaterialisationCampagne.php`, `ReetalementStock.php`, `EnvoiCampagnesMaterialiser.php`, toutes les migrations, `EnvoiServiceProvider.php` (classes résolues seules), routes, contrats, instantanés `nr07`/`nr08`/`nr20`, `config/envoi.php`, drapeaux.

### 2.2 Verrou unique, ordre fixe
`GestesCampagne::sousVerrou(string $ws, string $id, \Closure $f)` devient : `$this->depot->sousVerrou($ws, $id, function () { SET LOCAL lock_timeout = '5s'; … })`. La clé `envoi:campagnes:<ws>` disparaît du fichier.
Ordre des verrous, partout : **espace → campagne → séquence (`envoi_sequence_geste|<ws>|<seq>`, seulement si `sequence_id` non nul) → étalement (`envoi_etalement:<ws>`, L5-B)**. Personne ne prend ces verrous dans l'ordre inverse (vérifié : `DepotSequences` ne prend que celui de la séquence, `ReetalementStock` que celui de l'étalement, la matérialisation espace → campagne → étalement).
`dupliquer` et `changerDate` passent aussi par ce verrou (même campagne). `55P03` (lock_timeout) → `CampagneRefusee` 409 `concurrence` ; jamais de `QueryException` brute.

### 2.3 `lancer(string $ws, string $id, string $auteur, ?string $cle): \stdClass`
Sous le verrou, dans cet ordre :
1. `$c = depot->lire()` ou `introuvable` (404).
2. **Rejeu d'abord :** `$cle !== null && depot->rejeu($ws, $id, 'en_cours', $cle)` ⇒ rendre la campagne telle qu'elle est, **rien écrit, aucun contrôle** (une clé rejouée après une pause rend `en_pause`, sans erreur ni nouveau geste). Clé prise ailleurs : `cle_reutilisee` (déjà fait par `rejeu`).
3. `statut !== 'programmee'` ⇒ `transition_refusee` (même règle que la pause : double clic sans clé = 409).
4. `sequence_id === null` ⇒ `sans_sequence`, message : « La campagne n'a pas encore de séquence : elle sera préparée automatiquement ; si des boîtes ou un plafond sont choisis, la préparation attend le transport. »
5. Matérialisation lue `FOR SHARE` (`etat`, `compteurs`) : absente ou `en_cours` ⇒ `materialisation_en_cours` (message avec `inscrits` et `membres` lus dans `compteurs`, aucune adresse) ; `bloquee` ⇒ `materialisation_bloquee` (motif de la liste fermée).
6. `compteurs.inscrits` absent ou 0 ⇒ `aucun_destinataire` (« Personne à écrire : tous les destinataires ont été écartés. Arrêtez la campagne. ») — on ne lance jamais une campagne vide.
7. Freins (code existant de `reprendre`, `GestesCampagne.php:35-40`) : `frein_arret_general`, `frein_pause_automatique`.
8. `depot->transitionner($ws, $id, 'en_cours', version lue, $auteur, 'lancer', $cle ?? "auto-lancer-{$id}-{$version}")`.
9. **Seulement si `transitionner` a rendu `true`** : si le dernier geste de la séquence est `pause`, `INSERT INTO envoi_sequences_gestes (workspace_id, sequence_id, geste, auteur) VALUES (?, ?, 'reprise', 'campagne:lancer')` (la base impose `cree_le`). Même transaction : si la base refuse (`P0001` du déclencheur `000030:125-154`), TOUT est annulé (statut et journal compris) et l'appelant reçoit `reprise_refusee_base` (409).
10. Journal d'audit : la ligne `envoi_campagnes_journal` écrite par `transitionner` (qui, quand, `programmee>en_cours`, motif `lancer`, clé) + la ligne de geste de séquence (auteur `campagne:lancer`). Rien d'autre ; aucune adresse.
11. Rendre `depot->lire()`.

### 2.4 `reprendre` / `pause` / `terminer`
- `reprendre` : étapes 2 (rejeu, `vers = en_cours`), 3 (`statut === 'en_pause'`), 5 (défensif : une campagne `en_pause` est forcément passée par `en_cours`, donc `terminee` ; sinon `materialisation_en_cours`), 7 (freins), 8 (motif `reprise`), 9 (auteur `campagne:reprise`), 11.
- `pause` : comportement inchangé (le déclencheur `000030:99-109` écrit le geste `pause`) ; seul le verrou change. Le test vérifie la ligne `pause`, auteur `campagne:en_pause`.
- `terminer` : inchangé ici (la clôture des inscriptions arrive en L5-B).

### 2.5 Filet modifié (sans affaiblir)
`CampagnesSuiviRoutesTest.php:41-61`, aide `suivCampagne` : pour `en_cours`, `en_pause`, `terminee`, AVANT le passage à `en_cours`, insérer la matérialisation par son chemin légal (propriétaire de la base, comme la séquence factice) : `INSERT … (campagne_id, workspace_id, sequence_id)` (naît `en_cours`) puis `UPDATE … SET etat='terminee', terminee_le=now(), compteurs='{"inscrits":1,"membres":1,"retenus":1}'`. Aucune assertion modifiée ni retirée.
Pourquoi : l'état « en cours sans préparation terminée » n'est plus atteignable ; le test `:150-165` (reprise 200 après levée des freins) reste vrai et prouve en plus la propagation. Ligne « Filet modifié : pourquoi » dans la PR et dans `L5-A.md`.

### 2.6 Tests L5-A (écrits AVANT, vus rouges ; modes `['app','proprio']` comme `SchemaLienCampagneSequenceTest.php:77-95` ; PostgreSQL réel)
Aide `gcsPrete($ws, n)` : campagne SANS boîte (`DepotCampagnes::creer`), validée, matérialisée par `MaterialisationCampagne::materialiser` réel sur `n` entreprises fictives (aide `matProgrammee` de `MaterialisationCampagneTest`).
1. **Lancer nominal** : statut `en_cours` ; 1 ligne de journal `programmee>en_cours` motif `lancer` ; dernier geste de la séquence = `reprise`, auteur `campagne:lancer` ; la sous-requête du séquenceur (`Sequenceur.php:107-108`) ne la voit plus en pause ; puis `artisan envoi:cycle` ⇒ « porte fermée : rien à faire », 0 ligne `envoi_messages`.
2. **Préparation pas finie** : matérialisation absente ⇒ 409 `materialisation_en_cours` ; `en_cours` (horizon plein : P = 1, 30 adresses) ⇒ idem, message avec « 20 » inscrits ; dans les deux cas : statut, version, journal et gestes **inchangés**.
3. **Préparation bloquée** (`jours_non_applicables`) ⇒ 409 `materialisation_bloquee`, rien écrit.
4. **Sans séquence** ⇒ 409 `sans_sequence`, rien écrit.
5. **Aucun destinataire** (toutes les fiches opposées, matérialisation `terminee` avec `inscrits = 0`) ⇒ 409 `aucun_destinataire`, rien écrit.
6. **Freins** : arrêt général armé ⇒ `frein_arret_general` ; pause automatique ⇒ `frein_pause_automatique` ; rien écrit ; aucun frein levé par le geste.
7. **Idempotence** : même clé deux fois ⇒ 1 journal, 1 geste `reprise` ; clé rejouée après une pause ⇒ rend `en_pause`, aucun geste ; clé d'une autre campagne ⇒ `cle_reutilisee` ; double appel sans clé ⇒ second 409 `transition_refusee`, 1 seul geste.
8. **Reprendre** : lancer, pause (geste `pause` auteur `campagne:en_pause`), reprendre ⇒ geste `reprise` auteur `campagne:reprise` ; les gestes de la séquence alternent strictement et le dernier correspond au statut.
9. **Refus de la base converti** : crochet `DB::beforeExecuting` levant `P0001` sur l'`INSERT` de `envoi_sequences_gestes` ⇒ 409 `reprise_refusee_base`, statut et journal inchangés (transaction entière annulée).
10. **Verrou occupé** : autre connexion tenant `envoi_campagne:<id>` ⇒ `concurrence` en ≤ 6 s, rien écrit.
11. **Concurrence réelle** (`campagne-lancer-concurrent.php`, `pgsql_app`, données semées et nettoyées par `pgsql_owner` en `try/finally`) : 2 `lancer` + 1 `pause` simultanés ⇒ statut final cohérent avec la dernière ligne du journal ; jamais `en_cours` avec un dernier geste `pause` ni `en_pause` avec un dernier geste `reprise`.
12. **Lancer contre fin de préparation** : un processus finit la matérialisation (`materialisation-concurrente.php`) pendant qu'un autre lance ⇒ soit 409 `materialisation_en_cours`, soit `en_cours` avec matérialisation `terminee` ; jamais `en_cours` avec matérialisation `en_cours`.
13. **Cloisonnement** : lancer une campagne d'un autre espace (mode app : RLS ; mode proprio : filtre explicite) ⇒ `introuvable`, rien écrit dans aucun espace.
14. **Source** : `GestesCampagne.php` ne contient plus `envoi:campagnes:` ; toujours ni `ServeurEnvoi` ni `sequence_id' =>` (le test existant `CampagnesSuiviRoutesTest.php:215-231` reste vert sans modification).

**Mutations à prouver :**
| Mutation | Test qui rougit |
|---|---|
| contrôle de la matérialisation retiré (étape 5) | 2, 3 (code `reprise_refusee_base` au lieu de `materialisation_*`) |
| insertion du geste `reprise` retirée | 1, 8 |
| geste écrit même si `transitionner` rend `false` | 7 |
| rejeu contrôlé APRÈS les freins | 7 (clé rejouée sous frein ⇒ 409 au lieu de 200) |
| contrôle `inscrits = 0` retiré | 5 |
| freins retirés | 6 |
| conversion `P0001` retirée | 9 (`QueryException` brute) |
| ancien verrou `envoi:campagnes:` remis | 10, 14 |

---

## 3. LOT L5-B — « arrêter » et clôture des inscriptions

### 3.1 Fichiers
- Créer `backend/app/Envoi/Campagnes/ClotureInscriptionsCampagne.php`
- Modifier `backend/app/Envoi/Campagnes/GestesCampagne.php` : `arreter()` ; `terminer()` appelle la clôture
- Modifier `backend/app/Envoi/Campagnes/ReetalementStock.php` : **un seul** filtre ajouté (3.4)
- Modifier `backend/app/Console/Commands/EnvoiCampagnesMaterialiser.php` : étape « clôture » par espace + compteur `cloturees` (3.5)
- Créer `backend/tests/Feature/Envoi/ClotureInscriptionsCampagneTest.php` (préfixe `cic`)
- Créer `backend/tests/Support/cloture-concurrente.php`
- Ajouter des tests dans `MaterialisationCommandeTest.php` et `ReetalementStockTest.php` (ajouts seulement)
- Créer `docs/envoi/lots/L5-B.md`

**À ne pas toucher :** `Sequenceur.php` (constat 11), `Evenement.php` (les motifs vivent dans la nouvelle classe), `Inscriptions.php`, `MaterialisationCampagne.php`, `routes/console/envoi-campagnes.php` (même tâche, aucune nouvelle planification ⇒ `nr07`/`nr08` inchangés), migrations, routes, contrats, `config/envoi.php`, drapeaux.

### 3.2 `ClotureInscriptionsCampagne`
```php
final class ClotureInscriptionsCampagne
{
    public const MOTIF_ARRETEE = 'campagne_arretee';
    public const MOTIF_TERMINEE = 'campagne_terminee';
    public const LIMITE = 1000;

    /** Ferme au plus $limite inscriptions ACTIVES de la séquence de la campagne. Rend le nombre fermé. Appelable dans une transaction ouverte (geste) ou seule (commande). */
    public function clore(string $ws, string $campagneId, \DateTimeImmutable $maintenant, int $limite = self::LIMITE): int;
}
```
Déroulé (dans `DB::transaction`, contexte d'espace) :
1. `SET LOCAL lock_timeout = '5s'`, `SET LOCAL statement_timeout = '15000'` ; verrou `envoi_etalement:<ws>` (toujours en dernier).
2. `SELECT statut, sequence_id FROM envoi_campagnes WHERE workspace_id = ? AND id = ? FOR SHARE` : statut ∈ {`arretee`, `terminee`} sinon `\LogicException` (on ne ferme JAMAIS les inscriptions d'une campagne vivante) ; `sequence_id` nul ⇒ 0.
3. Une requête :
   ```sql
   UPDATE envoi_inscriptions SET statut = 'arretee', motif_arret = :motif, arretee_le = :maintenant
    WHERE workspace_id = :ws AND statut = 'active' AND id IN (
      SELECT i.id FROM envoi_inscriptions i
       WHERE i.workspace_id = :ws AND i.sequence_id = :seq AND i.statut = 'active'
       ORDER BY i.id LIMIT :limite FOR UPDATE SKIP LOCKED)
   ```
   Motif selon le statut de la campagne. Seules `statut`, `motif_arret`, `arretee_le` sont écrites. **Aucune** ligne `envoi_inscriptions_evenements` (ce n'est pas un événement de la personne ; et `entreprise_id NOT NULL` de cette table). Une inscription déjà `arretee` (réponse, opposition, rebond) ou `terminee` garde son statut et son motif.
4. `SKIP LOCKED` : une inscription que le séquenceur tient (`Sequenceur.php:236`, `:265`) est sautée, reprise au tour suivant de la commande.
5. Journal : rien par ligne. Le nombre fermé remonte dans la sortie de la commande (3.5) ; le geste a déjà son journal.

### 3.3 Gestes
- `arreter(string $ws, string $id, string $auteur, ?string $cle)` : sous le verrou de 2.2 ; rejeu d'abord (`vers = 'arretee'`) ; statut ∈ {`programmee` reliée, `en_cours`, `en_pause`} sinon `transition_refusee` ; `programmee` sans séquence ⇒ `sans_sequence` avec le message « Cette campagne n'a pas encore de séquence : renvoyez-la à valider (annulation de la validation) au lieu de l'arrêter. » ; `transitionner(…, 'arretee', …, motif 'arreter')` (le déclencheur met la séquence en pause, auteur `campagne:arretee`) ; puis, **même transaction**, `clore($ws, $id, maintenant, LIMITE)` (premier lot atomique avec le geste ; le reste par la commande). Aucun contrôle de frein : arrêter est toujours permis (le stop gagne).
- `terminer` : même chose, motif `campagne_terminee`.
- Arrêter pendant une matérialisation : le lot de matérialisation tient le verrou de la campagne ; l'arrêt passe après ; les inscriptions de ce dernier lot sont fermées par la clôture ; ensuite `materialiser` refuse (`MaterialisationCampagne.php:61-63`, `:147-149`) et la commande ne la liste plus (`EnvoiCampagnesMaterialiser.php:117-122`). La ligne de matérialisation reste `en_cours` (motif hors liste fermée ; rien à écrire) : l'écran la montre « préparation interrompue » (L6-B).

### 3.4 `ReetalementStock` : ne plus déplacer une campagne finie
Ajouter à la jointure de `ReetalementStock.php:47-52` :
`JOIN envoi_campagnes c ON c.workspace_id = s.workspace_id AND c.id = s.campagne_id AND c.statut IN ('programmee', 'en_cours', 'en_pause')`.
Rien d'autre ne change dans ce fichier.

### 3.5 Commande L4-C
Par espace, **avant** le re-étalement : jusqu'à `CLOTURES_PAR_TOUR = 20` campagnes `statut IN ('arretee','terminee')` avec `sequence_id` non nul ET une inscription active (`EXISTS`, index `(workspace_id, sequence_id, contact_id)`), triées par `id`, `clore()` chacune (limite 1 000). Même borne de 50 s, même traitement des erreurs (compteur `erreurs`, journal sans adresse : identifiants et classe seulement). Nouveau compteur de sortie `cloturees`. Ordre par espace : **clôture → re-étalement → matérialisation**.

### 3.6 Tests L5-B (rouges d'abord ; modes `['app','proprio']` ; PostgreSQL réel)
1. **Arrêter `en_cours`** : statut `arretee` ; dernier geste de séquence `pause` (`campagne:arretee`) ; toutes les inscriptions actives ⇒ `arretee`, `campagne_arretee`, `arretee_le` posé ; une inscription `arretee/opposition` et une `terminee` INCHANGÉES ; nombre total de lignes `envoi_inscriptions` identique (aucune suppression) ; 0 ligne `envoi_inscriptions_evenements` ajoutée.
2. **Arrêter `programmee` reliée, préparation `en_cours`** : accepté ; ensuite `materialiser` ⇒ `statut_non_materialisable` ; aucune nouvelle inscription après une passe de commande.
3. **`programmee` sans séquence** ⇒ 409 `sans_sequence`, rien écrit.
4. **Terminer** ⇒ motif `campagne_terminee`.
5. **Lots bornés** : 2 500 inscriptions actives semées (propriétaire) ⇒ le geste en ferme 1 000 ; deux passes de commande ⇒ 0 active ; sortie `cloturees=1000` puis `cloturees=500`.
6. **L'adresse est libérée** : après arrêt et clôture, la même adresse est inscrite par une autre campagne (matérialisation réelle) sans `ecartes_insertion` ; `dejaParJour` ne compte plus l'ancienne.
7. **Re-étalement** : inscriptions échues d'une campagne `arretee` (avant clôture complète) ⇒ NON déplacées ; celles d'une campagne `en_pause` ⇒ déplacées (non-régression : tout `ReetalementStockTest` reste vert sans modification).
8. **Cloisonnement** : deux campagnes dans l'espace A, une dans l'espace B ; arrêter A1 ⇒ A2 et B intactes (inscriptions, gestes, journal).
9. **Garde** : `clore()` sur une campagne `en_cours` ou `en_pause` ⇒ `LogicException`, rien écrit.
10. **`SKIP LOCKED`** : une inscription verrouillée par une autre connexion ⇒ sautée sans attente (< 1 s) ; fermée à la passe suivante.
11. **Concurrence réelle** (`cloture-concurrente.php` + `materialisation-concurrente.php`) : arrêt pendant 3 matérialisations simultanées ⇒ après une passe de commande, 0 inscription active de la campagne, aucune `QueryException` brute, curseur jamais décroissant.
12. **Pas de blocage à tort** : une adresse dont l'inscription est `campagne_arretee` est traitée normalement dans une autre campagne par `Sequenceur::executer` (montage de `SequenceurTest` : faux serveur), pas arrêtée `adresse_arretee` / `entreprise_arretee`.
13. **Idempotence** : même clé ⇒ 1 journal, 1 geste `pause` ; clé rejouée ⇒ 200, rien écrit.
14. **Échec fermé** : après arrêt, aucune inscription n'est jamais remise `active` (recherche de source : aucun `'statut' => 'active'` ni `SET statut = 'active'` dans `app/Envoi/Campagnes/**`).
15. **Rien ne part** : commande jouée puis `envoi:cycle` ⇒ « porte fermée », 0 `envoi_messages`.

**Mutations :** filtre `statut = 'active'` retiré ⇒ 1 ; filtre `sequence_id` retiré ⇒ 8 ; garde de statut de campagne retirée ⇒ 9 ; `SKIP LOCKED` retiré ⇒ 10 (attente puis `55P03`) ; filtre de 3.4 retiré ⇒ 7 ; motifs ajoutés aux listes de `Sequenceur.php:377-388` ⇒ 12 ; clôture retirée du geste ⇒ 1, 6 ; étape de commande retirée ⇒ 5.

---

## 4. LOT L5-C — routes, contrat, reprise directe gardée

### 4.1 Fichiers
- Modifier `backend/routes/envoi/campagnes.php:16` : ajouter `'lancer', 'arreter'` à la liste (même groupe `permission:workspaces.manage`, `whereUuid`)
- Modifier `backend/app/Http/Controllers/Api/EnvoiCampagnesController.php` : méthodes `lancer`, `arreter` (modèle `pause`, `:80-83`)
- Modifier `backend/openapi/envoi/campagnes.yaml` : deux chemins, réponses 200 (`CampagneSuivi`), 404, 409 (codes de 4.2), 422 (`Idempotency-Key`)
- Régénérer `frontend/src/features/envoi/api/campagnes.d.ts`
- Modifier `backend/tests/Feature/NonRegression/instantanes/nr20-routes.json` (deux entrées, « Filet modifié : pourquoi »)
- Modifier `backend/app/Envoi/Sequence/DepotSequences.php` : reprise d'une séquence née d'une campagne refusée (4.3)
- Créer `backend/tests/Feature/Envoi/CampagnesGestesRoutesTest.php` (préfixe `cgr`) ; ajouter un test à `tests/Feature/Envoi/Routes/SequencesRoutesTest.php`
- Créer `docs/envoi/lots/L5-C.md`

**À ne pas toucher :** `routes/api.php` (#647), `GestesCampagne.php` (fini en L5-B), `EnvoiSequencesController.php` (le refus passe par `CampagneRefusee`, déjà rendu), `routes/envoi/sequences.php`, frontend hors `.d.ts`, `config/envoi.php`, drapeaux.

### 4.2 Codes de refus (tous `{"error": code, "message": texte français}`)
`transition_refusee`, `sans_sequence`, `materialisation_en_cours`, `materialisation_bloquee`, `aucun_destinataire`, `frein_arret_general`, `frein_pause_automatique`, `reprise_refusee_base`, `concurrence`, `version_perimee`, `cle_reutilisee` : 409. `non_trouve` : 404. `validation` : 422. Jamais 500 pour un refus métier.

### 4.3 Reprise directe d'une séquence née d'une campagne
`DepotSequences::geste` : si `geste === 'reprise'` et la séquence a `campagne_id` non nul ⇒ `CampagneRefusee` 409 `pilotee_par_la_campagne` (« Cette séquence appartient à une campagne : reprenez la campagne. »). **La pause directe reste permise** (`users.manage`) : le stop gagne toujours. Si une séquence est mise en pause directement alors que sa campagne est `en_cours`, on la relance par « pause » puis « reprendre » sur la campagne (journalisé).

### 4.4 Tests L5-C (rouges d'abord)
1. Droits : 401 sans session ; 403 `admin`, `operator`, `viewer` sur `lancer` et `arreter` ; 404 sans le produit ; 404 campagne d'un autre espace.
2. `lancer` 200 (`statut: en_cours`, `envoi_ferme: true`) ; chaque code de 4.2 rendu avec le bon statut HTTP (un cas par code).
3. `arreter` 200 ; second appel sans clé 409 ; même `Idempotency-Key` deux fois ⇒ 200 deux fois, 1 journal ; clé mal formée ⇒ 422.
4. NR20 : instantané à jour ; `AucuneRouteTemoinSingulierTest` vert (aucune URI ni action « temoin », « armer », « reprendre »).
5. Contrat : la réponse de `lancer` et `arreter` valide `CampagneSuivi` (même validateur que les tests existants du contrat).
6. Séquences : reprise directe d'une séquence née d'une campagne ⇒ 409 `pilotee_par_la_campagne`, aucun geste écrit ; pause directe ⇒ 200 ; séquence libre : reprise inchangée (tests existants verts).

**Mutations :** route `lancer` sans `permission:workspaces.manage` ⇒ 1 ; garde de 4.3 retirée ⇒ 6 ; garde de 4.3 appliquée aussi à la pause ⇒ 6.

---

## 5. LOT L6-0 — index (MIGRATION SEULE)

**Numéro réservé :** `2026_12_09_000100` (après `2026_12_08_000150`, dernier sur `main` ; aucune branche ni cahier ouvert ne l'utilise : vérifié dans les PR #607, #608, #609, #617, #618, #619).

### 5.1 Fichiers
- Créer `backend/database/migrations/2026_12_09_000100_envoi_evenements_index_injection.php`
- Créer `backend/tests/Feature/Envoi/SchemaEvenementsInjectionIndexTest.php`
- Modifier `backend/tests/Feature/NonRegression/Nr17MigrationsTest.php` (une entrée, sur le modèle de `2026_12_05_000210`)
- Créer `docs/envoi/lots/L6-0.md`

### 5.2 Migration (modèle exact de `2026_12_05_000210_envoi_messages_index_injection.php`)
`$withinTransaction = false`. Si un index `envoi_evenements_injection_texte` existe **invalide** : `DROP INDEX CONCURRENTLY IF EXISTS public.envoi_evenements_injection_texte` (lui seul). Puis
`CREATE INDEX CONCURRENTLY IF NOT EXISTS envoi_evenements_injection_texte ON envoi_evenements (workspace_id, (id_injection::text)) WHERE id_injection IS NOT NULL;`
(dans la transaction d'un test : sans `CONCURRENTLY`). Expression `::text` : `envoi_messages.id_injection` et `id_injection_serveur` sont du texte (forme Kumo non UUID possible) ; la comparaison se fait donc en texte. Aucune ligne lue ni écrite ; `envoi_evenements` est en ajout seul, l'index ne change rien. `down()` inerte. Aucun droit à changer.

### 5.3 Tests (rouges d'abord)
1. Index présent, valide (`indisvalid`), partiel, expression `(id_injection)::text`, première colonne `workspace_id`.
2. `up()` rejouable ; index invalide simulé (créé puis marqué invalide n'est pas possible proprement : à la place, appeler `up()` deux fois et vérifier un seul index).
3. Ancien code : `RegistreStableC1LectureTest`, `ReleveImapBrancheeTest`, `ResultatsApiTest` verts sans modification.

**Déploiement** : L6-0 fusionnée PUIS déployée (`migrate:status` sans `Pending`) avant la fusion de L6-A. L6-A ne dépend de l'index que pour la vitesse : sans lui, les requêtes restent justes (test 10 de L6-A le vérifie avec un seuil).

---

## 6. LOT L6-A — `CompteursCampagne` (lecture seule)

### 6.1 Fichiers
- Créer `backend/app/Envoi/Campagnes/CompteursCampagne.php`
- Créer `backend/tests/Feature/Envoi/CompteursCampagneTest.php` (préfixe `cpt`)
- Créer `docs/envoi/lots/L6-A.md`

**À ne pas toucher :** `LectureCampagnes.php` (L6-B), `DepotResultats.php` (R04 : autre définition, par jour de chauffe), `RecepteurRetoursSequence.php` (on recopie ses trois formes en SQL, on ne la modifie pas), migrations, routes, contrats.

### 6.2 Signature
```php
final class CompteursCampagne
{
    /** @return array<string, int|bool|null|array> une campagne ; toutes clés présentes ; zéros si la campagne n'a pas de séquence */
    public function pour(string $ws, string $campagneId): array;
    /** @param list<string> $ids @return array<string, array<string, int|bool|null|array>> une requête pour toute une page */
    public function pourListe(string $ws, array $ids): array;
}
```
Sous `WorkspaceContext::run($ws, …)` ET filtre `workspace_id = :ws` sur CHAQUE table de la requête (la RLS ne protège pas le propriétaire superutilisateur). **Une seule instruction SQL** (CTE) par appel : un instantané cohérent (READ COMMITTED, une instruction), jamais deux lectures qui se contredisent.

### 6.3 Définitions (population `I` = inscriptions de `envoi_campagnes.sequence_id`, tous statuts)
| Clé | Définition exacte | Unité |
|---|---|---|
| `inscrits` | `count(*)` de `I` | inscription |
| `actives` | `I.statut = 'active'` | inscription |
| `envoyes` | messages `statut = 'envoye'` de `I` (unique par `(inscription, étape)`) | message |
| `touches` | inscriptions de `I` avec au moins un message `envoye` | inscription |
| `refuses` | messages `statut = 'refuse'` de `I` | message |
| `ouverts` | `null` toujours ; `suivi_ouvertures: false` (constat 13) | — |
| `reponses` | inscriptions de `I` ayant `evenement = 'reponse'` dans `envoi_inscriptions_evenements` | inscription |
| `rebonds_durs` | inscriptions de `I` ayant `rebond_dur` dans `envoi_inscriptions_evenements` OU un `envoi_evenements` `classe = 'rebond_dur'`, `statut = 'rattache'`, rattaché à un de ses messages | inscription |
| `rebonds_mous` | inscriptions de `I` avec un `envoi_evenements` `rebond_mou` rattaché, ET pas dans `rebonds_durs` | inscription |
| `oppositions` | inscriptions de `I` ayant `stop` ou `opposition` dans `envoi_inscriptions_evenements`, OU `motif_arret IN ('opposition','garde:opposition','stop')`, OU un `envoi_evenements` `classe = 'desinscription'` rattaché | inscription |
| `arretes_entreprise` | `motif_arret = 'entreprise_arretee'` | inscription |
| `arretes_campagne` | `motif_arret IN ('campagne_arretee','campagne_terminee')` | inscription |
| `exclus_preparation` | `membres − retenus` lus dans `envoi_campagnes_materialisations.compteurs` (0 si absent) | destinataire |
| `preparation` | `{etat, motif, inscrits, membres}` de la matérialisation, ou `null` | — |

**Rattachement d'un `envoi_evenements` à un message `m`** (les trois formes de `RecepteurRetoursSequence.php:58-78`, filtre d'espace sur chaque table) :
`e.id_injection::text = m.id_injection` OU `e.id_injection::text = m.id_injection_serveur` OU `e.id_injection = l.id_injection` avec `l.cle_idempotence = 'seq-' || m.inscription_id || '-' || m.etape`. Seul `statut = 'rattache'` compte (`a_rattacher` ignoré).

**Sans double comptage** : toutes les clés « inscription » comptent des `DISTINCT inscription_id` ; une personne qui répond deux fois, ou dont le rebond arrive par deux formes de rattachement, compte une fois. En plus, une **partition** `issues` range chaque inscription de `I` dans UNE seule case, par priorité :
`opposition` > `rebond_dur` > `reponse` > `rebond_mou` > `arretee_autre` (tout autre `arretee`, dont `campagne_*` et `entreprise_arretee`) > `terminee` > `active`.
Invariant testé : `Σ issues = inscrits`. Les taux du contrat (`taux_refus = refuses / max(envoyes + refuses, 1)`, `taux_desinscription = oppositions / max(touches, 1)`) sont calculés en PHP, arrondis à 4 décimales. `limite_refus` : inchangé (0), hors périmètre.

### 6.4 Tests L6-A (rouges d'abord ; modes `['app','proprio']` ; PostgreSQL réel ; données semées par le propriétaire)
1. **Sans séquence** : toutes les clés présentes, zéros, `ouverts` `null`, `suivi_ouvertures` faux, `preparation` `null`.
2. **Nominal** : 10 inscriptions, 7 messages `envoye` (dont 2 étapes 2), 1 `refuse`, 1 `reserve`, 1 `reporte` ⇒ `envoyes = 7`, `touches = 5`, `refuses = 1` ; `reserve` et `reporte` jamais comptés.
3. **Réponses** : une trace `reponse` ⇒ 1 ; l'arrêt `entreprise_arretee` des collègues ⇒ `arretes_entreprise`, PAS `reponses`.
4. **Rebonds, trois formes** : un rebond par `id_injection`, un par `id_injection_serveur`, un par la clé `seq-…` ⇒ 3 ; le même rebond dur arrivé par deux formes ET tracé dans `envoi_inscriptions_evenements` ⇒ 1.
5. **Dur l'emporte sur mou** : même inscription, `rebond_mou` puis `rebond_dur` ⇒ `rebonds_durs = 1`, `rebonds_mous = 0`.
6. **Oppositions** : `stop` tracé, `opposition` par `ArretParEntrepriseL1` (motif seul, sans trace), `garde:opposition`, `desinscription` IMAP rattachée ⇒ 4 ; `opposition` tracée ET motif `opposition` sur la même inscription ⇒ 1.
7. **Partition** : jeu mêlant tous les cas ⇒ `Σ issues = inscrits` ; une inscription opposée qui avait aussi répondu est dans `opposition` seulement.
8. **`a_rattacher` ignoré** ; événement d'un autre espace portant le même `id_injection` ⇒ ignoré (cloisonnement par jointure, pas seulement par RLS).
9. **Cloisonnement** : deux espaces avec données en miroir ; `pour(A)` ne voit rien de B, en mode app ET proprio ; identifiant d'une campagne de B passé avec l'espace A ⇒ zéros (pas d'oracle : même réponse qu'une campagne sans séquence).
10. **Volume** : 20 000 inscriptions, 40 000 messages, 5 000 événements sur 3 campagnes ⇒ `pourListe` < 300 ms ; `EXPLAIN` sans `Seq Scan on envoi_evenements` quand l'index de L6-0 existe.
11. **Une instruction** : `DB::listen` compte exactement 1 requête pour `pour` et 1 pour `pourListe` (hors `SET` de contexte).
12. **Lecture seule** : aucune instruction `INSERT|UPDATE|DELETE` émise (`DB::listen`) ; source sans `->insert(`, `->update(`, `->delete(`.

**Mutations :** `DISTINCT` retiré des rebonds ⇒ 4 ; priorité dur/mou retirée ⇒ 5 ; forme `id_injection_serveur` retirée ⇒ 4 ; filtre `statut = 'rattache'` retiré ⇒ 8 ; filtre d'espace de la jointure `envoi_evenements` retiré ⇒ 8 (mode proprio) ; `reserve` compté comme envoyé ⇒ 2 ; motif seul d'opposition ignoré ⇒ 6.

---

## 7. LOT L6-B — compteurs réels dans la lecture et le contrat

### 7.1 Fichiers
- Modifier `backend/app/Envoi/Campagnes/LectureCampagnes.php` : `liste` (`envoyes`, `total` = `inscrits`, `reponses` via `pourListe`, une requête par page), `fiche` (`compteurs` réels, `preparation`), `exposer` reçoit les compteurs en paramètre optionnel (zéros par défaut : les gestes rendent toujours la forme)
- Modifier `backend/openapi/envoi/campagnes.yaml` : dans `compteurs`, ajouter `inscrits`, `envoyes`, `touches`, `ouverts` (`integer`, `nullable`), `suivi_ouvertures` (`boolean`), `reponses`, `rebonds_durs`, `rebonds_mous`, `oppositions`, `arretes_campagne`, `exclus_preparation`, `issues` (objet des 7 cases) ; dans `lireCampagne`, `preparation` (nullable). Les clés existantes restent : `partis = touches`, `ont_repondu = reponses`, `desinscrits = oppositions`, `exclus = exclus_preparation`, `en_file = actives`, `refuses`, `arretes_entreprise`, `en_pause_absence = 0` (constat de `RecepteurRetoursSequence.php:19-21` : l'état n'existe pas).
- Régénérer `frontend/src/features/envoi/api/campagnes.d.ts`
- Ajouter des tests dans `backend/tests/Feature/Envoi/CampagnesSuiviRoutesTest.php` (ajouts seulement : le test `:112` « compteurs à 0 tant que `sequence_id` est nul » reste vrai et inchangé)
- Créer `docs/envoi/lots/L6-B.md`

**À ne pas toucher :** `EnvoiCampagnesController.php` (rien à changer : il appelle `LectureCampagnes`), la route `destinataires` (liste nominative : hors périmètre), `calendrier` (`prevus` reste 0 : hors périmètre), `DepotResultats.php`.

### 7.2 Tests L6-B (rouges d'abord)
1. Fiche d'une campagne matérialisée et partiellement « envoyée » (messages semés) : compteurs égaux à `CompteursCampagne::pour` ; réponse conforme au contrat.
2. Liste de 25 campagnes : `envoyes`, `total`, `reponses` exacts ; nombre de requêtes de la page constant (≤ 4, quelle que soit la taille de la page).
3. `preparation` : `en_cours` avec progression, `bloquee` avec motif, `terminee`.
4. Cloisonnement par la route : compte de l'espace A, campagne de B ⇒ 404 (inchangé) ; aucun compteur de B dans la liste de A.
5. `ouverts: null` et `suivi_ouvertures: false` partout.

---

## 8. LOT L5-D — écran des gestes

### 8.1 Fichiers
- Modifier `frontend/src/features/envoi/campagnes/api.ts` (mutations `lancer`, `arreter` ; en-tête `Idempotency-Key` = `crypto.randomUUID()` généré **une fois par clic**, réutilisé si l'utilisateur relance la même action après une erreur réseau)
- Modifier `frontend/src/features/envoi/campagnes/composants/GestesCampagne.tsx` (boutons selon le statut : `programmee` ⇒ Lancer, Arrêter ; `en_cours` ⇒ Pause, Terminer, Arrêter ; `en_pause` ⇒ Reprendre, Terminer, Arrêter ; Arrêter et Terminer demandent une confirmation qui dit « irréversible ; les personnes déjà prévues ne recevront rien »)
- Modifier `frontend/src/features/envoi/campagnes/CampagneSuiviPage.tsx` : bandeau « préparation » (progression, motif de blocage) et texte des refus (un texte par code de 4.2) ; bandeau permanent « Envoi réel fermé : rien ne part » tant que `envoi_ferme` est vrai
- Modifier `frontend/src/demo/handlers/envoi/campagnes.handlers.ts` (deux gestes de démo)
- Ajouter des tests dans `frontend/tests/screens/envoi/CampagneSuiviPage.test.tsx`
- Créer `docs/envoi/lots/L5-D.md`

**À ne pas toucher :** cartes des compteurs (L6-C), `sequences/**`, `.d.ts` (déjà régénérés), backend.

### 8.2 Tests (vitest, rouges d'abord)
1. Boutons visibles selon chacun des 7 statuts (aucun bouton sur `terminee`, `arretee`, `brouillon`, `a_valider`).
2. Double clic sur Lancer ⇒ une seule requête, même `Idempotency-Key` si on relance après un échec réseau simulé.
3. Chaque code 409 ⇒ son texte français ; `materialisation_en_cours` affiche « 20 personnes préparées sur 30 ».
4. Arrêter sans confirmer ⇒ aucune requête.
5. Lecteur d'écran : chaque bouton a un nom accessible ; la confirmation piège le focus (axe sans violation).

---

## 9. LOT L6-C — écran des compteurs

### 9.1 Fichiers
- Modifier `frontend/src/features/envoi/campagnes/CampagneSuiviPage.tsx` (cartes : Inscrits, Envoyés, Ouverts « non suivi », Réponses, Rebonds (durs / mous), Oppositions, Arrêtés ; barre des `issues` qui somme à 100 %)
- Modifier `frontend/src/demo/handlers/envoi/campagnes.handlers.ts` (compteurs de démo cohérents : `Σ issues = inscrits`)
- Ajouter des tests dans `frontend/tests/screens/envoi/CampagneSuiviPage.test.tsx`
- Créer `docs/envoi/lots/L6-C.md`

### 9.2 Tests
1. Valeurs affichées = valeurs de l'API (aucun calcul de taux côté écran hors affichage du pourcentage reçu).
2. `ouverts: null` ⇒ « non suivi », jamais « 0 ».
3. Barre des issues : 7 segments, total 100 % ; libellés lisibles sans couleur.

---

## 10. Conditions d'activation : rien ne part tant que `ENVOI_REEL_ACTIF` est faux

Inchangées par L5 et L6 (vérifiées dans `X1-L4-P2-cahier.md` § 6, toujours vraies sur d42beb0) : drapeau faux (`config/envoi.php:19`, `PorteEnvoiReelle.php:21`) ; `RetraitEnvoisEnFile` lié à l'absent ; cycle non dû porte fermée (`routes/console/envoi-orchestration.php:14`, `ChefOrchestre.php:65-67`) ; passerelle et client refusent ; mode témoins ; feu vert ; verdict d'adresse exigé.
Ce que L5 change : une séquence de campagne peut désormais être **reprise** (geste `reprise`). C'est le dernier verrou « métier » qui saute ; tous les verrous techniques ci-dessus restent. Les tests L5-A 1 et L5-B 15 prouvent « porte fermée : rien à faire, 0 `envoi_messages` » après lancer. À garder verts jusqu'à I1.

## 11. Rôles : chaque test de sécurité deux fois
Rôle applicatif `pgsql_app` (`SET LOCAL ROLE` + `WorkspaceContext`) ET propriétaire (`SchemaLienCampagneSequenceTest.php:77-95`). Scripts de concurrence en `pgsql_app` ; données semées et nettoyées par `pgsql_owner` dans un `try/finally`.

## 12. Fichiers chauds (une seule PR à la fois)

| Fichier | L5-A | L5-B | L5-C | L6-0 | L6-A | L6-B | L5-D | L6-C |
|---|---|---|---|---|---|---|---|---|
| `GestesCampagne.php` | oui | oui | non | non | non | non | non | non |
| `DepotCampagnes.php` | 1 méthode | non | non | non | non | non | non | non |
| `ReetalementStock.php` / `EnvoiCampagnesMaterialiser.php` | non | oui | non | non | non | non | non | non |
| `DepotSequences.php` | non | non | oui | non | non | non | non | non |
| `routes/envoi/campagnes.php`, `nr20` | non | non | oui (après #647) | non | non | non | non | non |
| `campagnes.yaml`, `campagnes.d.ts` | non | non | oui | non | non | oui | non | non |
| `LectureCampagnes.php` | non | non | non | non | non | oui | non | non |
| `CampagnesSuiviRoutesTest.php` | aide seule | non | non | non | non | ajouts | non | non |
| `Nr17MigrationsTest.php` | non | non | non | 1 entrée | non | non | non | non |
| `CampagneSuiviPage.tsx`, `campagnes.handlers.ts` | non | non | non | non | non | non | oui | oui |
| `Sequenceur.php`, `Inscriptions.php`, `Evenement.php`, `MaterialisationCampagne.php`, migrations existantes, `config/envoi.php`, `EnvoiServiceProvider.php` | non | non | non | non | non | non | non | non |

## 13. ESCALADE (décisions du fondateur ; rien ne bloque les lots d'ici là)
1. **Lancer une campagne dont la préparation n'est pas finie** (constat 7). Aujourd'hui fermé : une campagne plus grande que la place libre (≈ 400 adresses par espace avec le plafond de 20 par jour) ne peut être lancée qu'après plusieurs semaines de préparation. Ouvrir ce cas exigerait de changer la garde de la base (`000030:125-154`), donc une migration seule + un lot. Choix : garder fermé, ou autoriser le lancement partiel ?
2. **Lancement automatique à la date prévue.** L5 ne fait QUE le lancement manuel (le propriétaire clique). La date prévue fixe déjà la première échéance. Faut-il un lancement automatique à cette date ? (Défaut : non.)
3. **Qui peut mettre une campagne en pause ?** Aujourd'hui `workspaces.manage` (propriétaire seul) pour la campagne, mais `users.manage` (propriétaire et administrateur) pour la séquence. L5 ne change aucun droit. Faut-il ouvrir la pause de campagne aux administrateurs (le stop gagne) ?
4. **Suivi des ouvertures.** Aucun pixel ni lien suivi : « Ouverts » affiche « non suivi ». Le mettre en place est une décision juridique (consentement, information des personnes) et de délivrabilité ; hors de ces lots.
5. **Fin naturelle d'une campagne.** Quand toutes ses inscriptions sont `terminee` ou `arretee`, la campagne reste `en_cours`. Faut-il la passer seule à `terminee` ? (Défaut : non, geste manuel.)

## 14. Hors périmètre
- Liste nominative des destinataires (`/destinataires`) : données de personnes, lot à part avec revue RGPD.
- `calendrier.prevus`, `par_boite`, `par_jour`, `ab`, `fin_prevue_le` : restent à 0 / vides.
- Débordement du re-étalement au-delà de l'horizon (constat 8) et reprise après une longue pause d'étapes 2 et 3 échues en bloc (le plafond de la passerelle protège ; à traiter avec `Sequenceur.php`, lot C1).
- Population « personnes » (P3 à P7).

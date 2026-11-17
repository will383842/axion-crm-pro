# L6-B — compteurs réels dans l'écran des campagnes (lecture)

Cahier : `docs/envoi/lots/L5-L6-cahier.md` § 7 (PR #649). Préalables fusionnés : L6-A (#657, `CompteursCampagne`) et L5-C (#661, même contrat).

## Fait
- `LectureCampagnes::liste` : `envoyes`, `total` (= `inscrits`) et `reponses` de chaque ligne viennent de `CompteursCampagne::pourListe` : **une requête pour toute la page**, quel que soit `par_page`.
- `LectureCampagnes::fiche` : `compteurs` réels (une requête `pour`) et nouvelle clé `preparation` (`{etat, motif, inscrits, membres}` ou `null`).
- `LectureCampagnes::exposer` reçoit les compteurs en paramètre optionnel : sans eux, zéros (les gestes rendent toujours la forme `CampagneSuivi`).
- Clés historiques reliées : `partis` = `touches`, `ont_repondu` = `reponses`, `desinscrits` = `oppositions`, `exclus` = `exclus_preparation`, `en_file` = `actives` ; `en_pause_absence` = 0 (l'état n'existe pas) ; `limite_refus` = 0 (hors périmètre).
- Nouvelles clés (contrat `campagnes.yaml` + `campagnes.d.ts` régénéré) : `inscrits`, `envoyes`, `touches`, `ouverts` (toujours `null`), `reponses`, `rebonds_durs`, `rebonds_mous`, `oppositions`, `arretes_campagne`, `exclus_preparation`, `issues` (7 cases, somme = `inscrits`).
- Lecture seule, aucune migration, aucun drapeau lu ni écrit. Aucun nom ni adresse de personne : seulement des comptes.

## ÉCART AU CAHIER — ESCALADE (décision du fondateur)
Le cahier § 7.1 demande la clé `suivi_ouvertures` (booléen). La garde `frontend/tests/lib/aucune-ouverture-envoi.test.ts` interdit le mot « ouverture(s) » dans tout `backend/openapi/envoi/*.yaml`
(sauf « suivi d'ouverture » et « ni d'ouverture » en description) : la CI frontend est rouge avec cette clé. Je n'affaiblis pas la garde (hors fichiers du lot, et règle du dépôt).
Choix retenu pour rester vert : `suivi_ouvertures` n'est ni dans le contrat ni dans la réponse ; `ouverts: null` dit déjà « non suivi » (L6-C affichera « non suivi » sur `ouverts: null`).
`CompteursCampagne` (L6-A) continue de produire `suivi_ouvertures` en interne ; non exposé ici.
Décision demandée : (a) garder ainsi et corriger le cahier § 7.1 / § 9.2, ou (b) autoriser la clé par une exception écrite dans la garde, dans un lot qui peut toucher ce fichier de test. Rien d'autre n'est bloqué.

## Hors périmètre (inchangé)
`EnvoiCampagnesController.php`, route `destinataires` (toujours vide), `calendrier.prevus` (0), `par_boite`, `par_jour`, `ab`, `fin_prevue_le`, `cible.total`, `reponses_interessees` (0), `DepotResultats.php`.

## Tests (`CampagnesSuiviRoutesTest.php`, ajouts seulement ; chacun sous `pgsql_app` ET propriétaire)
1. Fiche = `CompteursCampagne::pour`, réponse conforme au contrat, clés historiques reliées.
2. Liste de 25 campagnes : valeurs exactes ; nombre de requêtes identique pour 3 et 25 lignes, ≤ 4 (observé : 3).
3. Préparation : `en_cours` (20 sur 30), `bloquee` avec motif, `terminee`, `null` sans matérialisation.
4. Cloisonnement : campagne d'un autre espace = 404, aucun compteur de l'autre espace dans la liste ni la fiche (et l'autre espace voit bien les siens).
5. `ouverts: null` ; campagne sans séquence = zéros ; les gestes rendent la forme.
6. Contrat : nouvelles clés déclarées et requises ; `LectureCampagnes.php` sans `->insert(`, `->update(`, `->delete(`.
Le test « compteurs à 0 tant que `sequence_id` est nul » (fiche, brouillon) est inchangé et reste vrai.
Mutations jouées (chacune rouge) : liste sans compteurs ⇒ 2, 4 ; une requête par ligne ⇒ 2 ; `total` = envoyés ⇒ 1, 2 ; `preparation` toujours nulle ⇒ 3 ; `ouverts` = 0 ⇒ 5 ; `partis` = envoyés ⇒ 1.

## Remarques non bloquantes des relectures de #657 et #661 qui visent L6-B
- **Reportées (fichier non autorisé ici)** : (a) le `finally` de `CompteursCampagne::pourListe` masque l'erreur d'origine par `25P02` (un dépassement de délai ne devient pas un refus clair) ; correctif de la relecture : supprimer le `try/finally`. (b) test de source `substr_count(CompteursCampagne::SQL, 'workspace_id = :ws') === 9`. (c) dire dans `L6-A.md` que trois filtres d'espace sont des mutants équivalents. Ces trois points touchent `CompteursCampagne.php`, `CompteursCampagneTest.php` et `L6-A.md` : à inscrire dans un lot qui peut les toucher.
- Rappel d'ordre (§ 0) : L6-0 doit être **déployée** (`migrate:status` sans `Pending`) avant la mise en service ; sans l'index, les compteurs restent justes mais la jointure sur `envoi_evenements` balaie la table.
- Rien dans #661 ne vise L6-B (la remarque sur `nr20` et `GestesCampagne` ne concerne pas ce lot).

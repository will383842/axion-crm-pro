# X1-L4-C — commande planifiée et re-étalement du stock

Cahier : `docs/envoi/lots/X1-L4-P2-cahier.md` (PR #609), section 5. Aucun drapeau touché : `ENVOI_REEL_ACTIF` reste faux (ainsi que
`ORIGINES_LECTURE_ENABLED`, `CRM_GUIDE_LIEN_ENABLED`, `USINE_AGREGATS_CRM_ENABLED`). Aucun envoi, aucune suppression de données.

## Ce que fait le lot
- `envoi:campagnes:materialiser` (chaque minute, verrou 10, un seul serveur, UNE condition : `schemaPret()`) : par espace, re-étale le stock puis matérialise
  (L4-B) les campagnes `programmee` / `en_cours` dont la matérialisation n'est pas terminale. **Aucune condition de porte** : la tâche tourne porte fermée.
- `schemaPret()` : table des matérialisations, colonne `population` et index `envoi_inscriptions_adresse_active_unique` VALIDE. Faux : « schéma P2 absent : rien à faire », `SUCCESS`
  (le code est déployé avant les migrations).
- `ReetalementStock::reetaler` : inscriptions d'une séquence née d'une campagne, étape 1, sans message, sans report, sans échec, dont l'échéance est passée
  (seuil = maintenant porte fermée, maintenant − 30 min porte ouverte). Elles sont redistribuées par `EtalementEcheances::repartir` sous le plafond
  `min(ENVOI_PLAFOND_JOUR, plafond de l'espace, 300)` ; seule `prochaine_echeance` est écrite. `FOR UPDATE SKIP LOCKED`, verrou consultatif `envoi_etalement:<ws>`.

## Choix où le cahier laissait une latitude
- Les campagnes en `reglage_non_applique` ne consomment pas le quota de 20 campagnes par tour (sinon 20 campagnes en attente de transport en affameraient d'autres).
- Compteur `a_reessayer` ajouté à la sortie pour les refus réessayables (comptés, pas des erreurs).
- Horloge de la commande injectable par `EnvoiCampagnesMaterialiser::$horloge` (tests de la borne de 50 s).
- Hors horizon : premier jour ouvré après les 20 jours, heure irrégulière dérivée de la même fenêtre.
- Journal : identifiants, classe d'exception et motif seulement ; jamais le message d'une exception (une `QueryException` peut citer une valeur).

## Tests
`MaterialisationCommandeTest` (1, 2, 3, 4, 8) et `ReetalementStockTest` (5, 6, 7), sous le rôle applicatif ET le propriétaire. Mutations vérifiées rouges :
`when(schemaPret)` retiré → 1 et 2 ; `NOT EXISTS envoi_messages` retiré → 5 ; filtre `campagne_id IS NOT NULL` retiré → 5 ;
seuil porte ouverte = maintenant → 6 ; `SKIP LOCKED` retiré → 7.

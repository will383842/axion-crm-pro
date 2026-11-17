# Lot L5-B — arrêter une campagne et clôturer ses inscriptions

Cahier : `docs/envoi/lots/L5-L6-cahier.md` (branche `conception/l5-l6`, PR #649), § 3. Préalable : L5-A (#656) fusionnée.

## Ce que fait le lot
- `GestesCampagne::arreter()` : `programmee` reliée, `en_cours` ou `en_pause` → `arretee`. Le déclencheur existant met la séquence en pause
  (auteur `campagne:arretee`). Aucun frein : arrêter est toujours permis. `programmee` sans séquence : refus `sans_sequence` avec le conseil de renvoyer la campagne à valider.
- `GestesCampagne::terminer()` ferme aussi les inscriptions (motif `campagne_terminee`).
- `ClotureInscriptionsCampagne::clore()` : au plus 1 000 inscriptions ACTIVES de la séquence passent à `arretee` (motif `campagne_arretee` ou `campagne_terminee`,
  `arretee_le`). Rien n'est supprimé ; les inscriptions déjà arrêtées (réponse, opposition, rebond) ou terminées gardent leur statut et leur motif ; aucun événement de personne n'est écrit.
  Refus (`LogicException`) sur une campagne vivante. Lignes tenues par le séquenceur : sautées (`SKIP LOCKED`), reprises au tour suivant.
- `ReetalementStock` : ne déplace plus les inscriptions d'une campagne arrêtée ou terminée (une seule jointure).
- Commande `envoi:campagnes:materialiser` : étape « clôture » (20 campagnes par tour au plus) AVANT le re-étalement ; compteur `cloturees`.
  Ordre par espace : clôture → re-étalement → matérialisation. Aucune nouvelle planification.

## Écart au cahier (déclaré)
La requête de clôture utilise une CTE `AS MATERIALIZED` au lieu de `id IN (sous-requête LIMIT … FOR UPDATE SKIP LOCKED)` : avec la sous-requête directe,
PostgreSQL peut la réévaluer et fermer PLUS que la limite (constaté : 1 586 fermées pour une limite de 1 000). Le test 5 le garde.

## Fichiers
`ClotureInscriptionsCampagne.php` (nouveau), `GestesCampagne.php`, `ReetalementStock.php` (1 ligne), `EnvoiCampagnesMaterialiser.php`,
`tests/Feature/Envoi/ClotureInscriptionsCampagneTest.php`, `tests/Support/cloture-concurrente.php`, ajouts dans `MaterialisationCommandeTest.php` et `ReetalementStockTest.php`, ce fichier.

## Non repris du cahier
Test 11 : l'arrêt contre 3 matérialisations simultanées n'est pas joué ; la course jouée est arrêt / terminaison / clôture en processus réels.
Test 12 : vérifié sur la source (aucun motif de clôture dans les listes du séquenceur), sans montage de faux serveur.
Remarques Opus de #656 : aucune ne vise L5-B (la mention « Reprise impossible » et les deux mutations survivantes concernent `relancer`).

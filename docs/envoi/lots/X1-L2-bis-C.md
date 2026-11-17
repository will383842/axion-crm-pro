# Lot X1-L2b-C — branchement du filtre « réseau d'origine » dans la sélection

Cahier : `docs/envoi/lots/X1-L2-bis-cahier.md` § 5 (branche `conception/x1-l2-bis`, PR #648). Dépend de L2b-A (colonne `envoi_campagnes.filtre_origine`, déployée) et L2b-B (`FiltreOrigine`, `RequetesOrigines::reseauxDesUnites`, fusionnée).

## Ce que fait le lot
- `SelectionDestinataires` lit le filtre de la campagne (`NULLIF(to_jsonb(k) -> 'filtre_origine', 'null'::jsonb)` : colonne absente ou NULL = pas de filtre).
- Filtre présent et forme invalide, ou porte fermée (`FiltreOrigine::actif`) : `SelectionRefusee('filtre_origine_inactif')`, non réessayable, AVANT toute lecture des membres. Un filtre n'est jamais ignoré (ce serait élargir la cible).
- Filtre actif : UNE lecture `reseauxDesUnites` ; une unité sans motif direct qui n'est pas « du réseau » est comptée `hors_reseau` (nouveau motif, en dernier dans `MOTIFS`) et n'entre pas dans les lectures d'exclusion ; un contact rattaché à une personne opposée porte `opposition` (O2). Erreur de lecture : `lecture_origine_impossible` (réessayable), aucun destinataire.
- Sans filtre : aucune de ces étapes ; mêmes destinataires, `hors_reseau = 0`.
- `MaterialisationCampagne` : `filtre_origine_inactif` rejoint les refus non réessayables.
- Aucun drapeau touché, aucune migration, aucune écriture de données de personnes.

## Fichiers
`SelectionDestinataires.php`, `SelectionRefusee.php`, `MaterialisationCampagne.php` (une ligne), `SelectionFiltreOrigineTest.php` (nouveau), `MaterialisationCampagneTest.php` (deux tests ajoutés), ce fichier.

## Écarts et points à relire
- Test 14 : le refus survient après la création de la séquence (en pause) et de la ligne `en_cours` à curseur 0, exactement comme `cible_non_supportee` aujourd'hui ; aucune inscription, aucun message, campagne `programmee`, la reprise ne crée aucun doublon. Les déplacer dépasserait « une ligne » de `MaterialisationCampagne`.
- Test 7 : opposition, demande RGPD en cours et déjà en séquence sont couverts ; presse et client console ne sont pas rejoués (code inchangé, déjà couvert par `SelectionDestinatairesTest`).
- Réécriture en `UNION` de la requête 1 de L2b-B (remarque Opus non bloquante) : non faite ici, `RequetesOrigines.php` est interdit dans ce lot.
- Taille : au-dessus de 400 lignes (tests compris).

## Mutations (casser, voir rouge, restaurer)
| Mutation | Tests rouges |
|---|---|
| filtre ignoré quand inactif | 2, 3, 14 |
| refus après la lecture des membres | 2 |
| `hors_reseau` non compté | 4, 6, 8, 9, 10, 15 |
| O2 retirée | 6 |
| lecture des origines aussi sans filtre | 1 |
| exception de lecture avalée | 12 |
| `filtre_origine_inactif` retiré de `MaterialisationCampagne` | 14 |
| R2 : entreprise du contact ciblé non passée | 4, 6 |

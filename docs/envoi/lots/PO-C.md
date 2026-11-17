# PO-C — écriture du filtre mot / guide (API de l'assistant)

Cahier PONT-LINKEDIN (#691) § 4, calque de X1-L2b-D. Aucun envoi, aucun drapeau touché (ENVOI_REEL_ACTIF, ORIGINES_LECTURE_ENABLED, CRM_GUIDE_LIEN_ENABLED, USINE_AGREGATS_CRM_ENABLED intacts).

## Fichiers
M `DepotCampagnes.php` (nouveau champ modifiable `filtre_origine_contenu`, P0001 → `CampagneRefusee`) ; M `AssistantCreation.php` (`cible.origine` accepte `mots`, `guides` ; une seule écriture `version + 1` ; `presenter` et `controles` lisent les deux colonnes) ; M `creation.yaml` ; M `creation.d.ts` (régénéré par `pnpm api:envoi`) ; C `AssistantFiltreContenuTest.php` ; C ce compte rendu.

## Tests rouges d'abord (avant tout code)
Sortie collée : 7 rouges sur 10 (les tests 5, 6, 8 passaient déjà : refus et IDOR existants).
```
  ⨯ 10 contrat : creation.yaml décrit mots et guides ; le .d.ts suit le…
  ⨯ 3 porte fermée et mots / guides présents : 422 filtre_origine_inact…
  ⨯ 4 origine: null porte fermée : 200, les DEUX colonnes à NULL ; clé… 
  ⨯ 1 porte ouverte : 200, deux colonnes posées dans UNE seule version,…
  ⨯ 7 contrôles : le contrôle filtre_origine couvre la colonne de conte…
  ⨯ 2 mots seuls : filtre_origine NULL, contenu posé ; remplacer par un…
  ⨯ 9 DepotCampagnes::modifier : écrit en modes app et proprio ; reliée…
  Tests:    7 failed, 3 warnings (78 assertions)
```

## Après correction
`AssistantFiltreContenuTest`, `CreationFiltreOrigineTest`, `CreationAssistantTest` : tous verts.

## Mutations (chacune rougit, puis annulée)
- `presenter` depuis la saisie : tests 1 et 6 rouges.
- porte non vérifiée : test 3 rouge.
- écriture en deux versions : test 1 rouge.

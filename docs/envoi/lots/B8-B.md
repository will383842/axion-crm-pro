# Lot B8-B — écran : « bloquée, pourquoi » dans la liste et le suivi

Cahier : `docs/envoi/lots/B6-B8-cahier.md` (PR brouillon #671), section 8. Écran seulement : aucune API, aucun drapeau, aucune écriture.

## Ce que fait le lot
- Liste : pastille « Bloquée » + phrase courte par code (texte, lisible sans couleur) + lien « Voir pourquoi » vers le suivi ; `blocage: null` ⇒ rien ; bouton-filtre « Bloquées (n) » côté écran, sur la page chargée seulement.
- Suivi : bandeau `role="status"` avec le message du serveur tel quel, au-dessus de la préparation ; « Lancer » désactivé, raison reliée par `aria-describedby`. `MOTIF_PREPARATION` complété (jours, objet B, étapes, séquence).
- Code inconnu ⇒ phrase générique ; le code brut ne s'affiche jamais.
- Démo : six campagnes `cmp-blq-<code>`, visibles dans la liste seulement quand une recherche est saisie (les comptes des autres écrans ne bougent pas).

## Écarts avec le cahier
- Le contrat B8-A compte 6 codes (pas 7) : 6 libellés + 1 phrase générique.
- Le filtre « Bloquées » est dans `TableauCampagnes.tsx` (fichier autorisé) et non dans `CampagnesPage.tsx` (non autorisé).
- `GestesCampagne.tsx` n'est pas autorisé : le bouton « Lancer » est désactivé depuis `CampagneSuiviPage.tsx` par une pose d'attributs sur le bouton rendu (`useLayoutEffect`). Un lot ultérieur pourra lui passer `blocage` en propriété.
- axe-core n'est pas une dépendance directe du front : l'accessibilité est vérifiée par assertions de structure (`role="status"`, `aria-describedby`, texte) ; axe reste couvert par `tests/e2e/a11y.spec.ts`.

## Tests (vitest, rouges d'abord)
`TableauCampagnesBlocage.test.tsx` (nouveau) et ajouts dans `CampagneSuiviPage.test.tsx`. Mutations vérifiées : code brut affiché ⇒ 8 rouges ; Lancer actif malgré `blocage` ⇒ 1 rouge.

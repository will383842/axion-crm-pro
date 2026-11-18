# UI-CAMP — suivi de campagne (relectures de #734 et #735)

## Ce qui change
- `GestesCampagne` reçoit une propriété `blocage` (`{ idRaison }` ou nul). Quand elle est posée, « Lancer » est désactivé dès le premier rendu et décrit par l'élément `idRaison` (`aria-describedby`).
- `CampagneSuiviPage` passe cette propriété à partir du blocage du serveur. L'ancien `useLayoutEffect`, qui modifiait le bouton dans le DOM après coup, est supprimé.
- Le test « Retirer le filtre » est complété avec des mots et des guides (ajout seul dans `FiltreReseau.test.tsx`).
- Test `phraseBlocage('toString')` : phrase par défaut, jamais `[object …]`.

- Relecture : « Lancer » est aussi désactivé pendant l'envoi (`blocage !== null || lancer.isPending`).

## Tests
- `frontend/tests/screens/envoi/CampagneSuiviBlocage.test.tsx` : « Lancer » désactivé dès le premier rendu avec sa raison accessible (vu rouge avant le code) ; actif sans blocage ; « Lancer » désactivé pendant l'envoi, un second clic n'envoie rien (rouge avant le correctif) ; `phraseBlocage` sur `toString`, `constructor`, `__proto__`, `hasOwnProperty`.
- Les tests existants de la page de suivi et de la liste restent verts et inchangés.

## Mutation
`disabled={blocage !== null}` retiré de `GestesCampagne` ⇒ le test « désactivé dès le premier rendu » est rouge. Restauré.

# L6-C — écran des compteurs réels

Cahier : `docs/envoi/lots/L5-L6-cahier.md` § 9 (PR #649). Préalables fusionnés : L6-B (#663, compteurs dans la fiche) et L5-D (#666, même page). Frontend seul : aucun backend, aucune migration, aucun drapeau touché.

## Fait
- `CampagneSuiviPage.tsx` : section « Compteurs réels » (onglet Progression) avec les cartes Inscrits, Envoyés (+ personnes touchées), Ouverts, Réponses, Rebonds (durs / mous), Oppositions, Arrêtés ; barre des `issues` en 7 segments (Oppositions, Rebonds durs, Réponses, Rebonds mous, Arrêtés, Terminés, En cours), chaque segment porte son libellé, son effectif et son pourcentage (lisible sans couleur) ; « Total : 100 % ».
- « Ouverts » affiche « Non suivi » quand l'API envoie `ouverts: null`, jamais 0.
- Aucun taux recalculé : seules les parts de la barre sont calculées à l'affichage (l'API ne les fournit pas), en pourcentages entiers au plus fort reste pour que la somme fasse exactement 100.
- « Arrêtés » = `issues.arretee_autre` (valeur de l'API, aucune addition côté écran).
- Démo : compteurs cohérents, `Σ issues = inscrits` (même priorité que le serveur).

## Écarts et remarques
- La démo n'envoie pas `suivi_ouvertures` : deux tests existants (`AucuneOuvertureDemo`, `CampagnesCorrectionsDm2Cam`) interdisent le mot « ouverture » dans les réponses de démo, et ils ne sont pas modifiés. L'écran ne lit pas ce champ.
- Les anciennes tuiles (Envoyés, Réponses, Refusés, Désinscriptions) restent inchangées ; les nouvelles cartes ont leurs propres identifiants (`carte-*`).
- `package.json` et les `.d.ts` non touchés ; aucun fichier hors liste.

## Tests (`CampagneSuiviPage.test.tsx`, 40 verts ; suite frontend 1783 verts, tsc et eslint propres)
Valeurs = API ; `ouverts: null` ⇒ « non suivi » ; 7 segments, somme 100 %, libellés ; aucune inscription ⇒ pas de barre ; démo `Σ issues = inscrits` pour chaque campagne. Rouges d'abord (4 échecs avant la page). Mutations tuées : « Ouverts » rendu 0, parts sans plus fort reste (test 3 bis : borne de boucle recalculée ⇒ somme 99), « Arrêtés » lu sur `arretes_campagne`.
Pas de test IDOR : aucune route ni requête ajoutée (écran seul, lecture de la fiche existante).

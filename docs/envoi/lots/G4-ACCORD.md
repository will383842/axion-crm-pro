# G4-ACCORD — Écran « accord donné » (D15)

## Fait
- Section « Accord donné » (`data-testid="accord-donne"`) dans `ContenusAccordsCard` : origine (mot, réseau, guide, date,
  déplacée de la liste principale sans changer son texte) et accords (date, source, version du texte).
- Sans accord (ou ancienne API sans bloc) : « Aucun accord enregistré », jamais une valeur inventée.
- Démo : les personnes dont la base de la lettre est le consentement ont un accord ; les autres n'en ont pas.
- Lecture seule. `commentaire_ref`, `post_ref` et `post_url` ne sont jamais lus par la section.

## Reste
- Rien pour ce lot. `libelleAccord.ts` est inchangé (aucun besoin constaté).

## Risques
- Le serveur reste la source ; aucun champ nouveau demandé. Données de démo fictives.

## Tests lancés
- Rouge d'abord : `tests/screens/AccordDonne.test.tsx` 3 rouges sur 4 (`Unable to find an element by: [data-testid="accord-donne"]`) ;
  la garde (déjà verte) a son témoin : un rendu fautif qui affiche le champ la fait rougir.
- Vert : `AccordDonne.test.tsx` 4/4 ; suite frontend complète 174 fichiers, 1599 tests verts (1 ignoré, existant).
- `dm3-accord.spec.ts` 3/3 et `dm3-crm-opposee.spec.ts` 3/3 (Playwright démo). `tsc --noEmit` et eslint propres.
- `ENVOI_REEL_ACTIF=false` inchangé ; aucun fichier du backend touché.

# X1-L2b-B — `FiltreOrigine` et lecture des réseaux des unités

Cahier : `docs/envoi/lots/X1-L2-bis-cahier.md` (PR #648, § 4). Lot de LECTURE : rien n'est branché, aucun drapeau touché.

## Ce que le lot ajoute
- `App\Envoi\Campagnes\FiltreOrigine` : forme `{"reseaux": [...]}` (1 à 10 jetons `RequetesOrigines::JETON`, triés, sans doublon).
  `depuisSaisie` normalise (trim, déduplique, trie) ; `depuisColonne` accepte null (pas de filtre), un JSON ou un tableau et
  normalise de même (l'ensemble de réseaux ne change pas) ; toute forme douteuse lève `\InvalidArgumentException`.
- `FiltreOrigine::actif($ws)` : `origines.lecture_enabled` ET `origines.v3_enabled` ET colonne `personnes.origines` ET fonction d'espace
  `origines.lecture`, dans cet ordre, arrêt au premier faux (drapeau éteint : zéro requête). Aucune écriture.
- `RequetesOrigines::reseauxDesUnites` (lecture seule, 3 requêtes de données au plus, bindings) : réutilise `entrees()`.
  Règles R1 (entreprise), R2 (contact), O1 (une personne opposée ne fait jamais entrer une unité), O2 (`contacts_opposes`).
  Échec fermé : un contact rattaché à une personne opposée n'est jamais rendu dans `contacts`.

## Hors lot
Pas de `SelectionDestinataires` (L2b-C), pas de colonne `filtre_origine` (L2b-A), aucun drapeau, aucune config, aucune migration.

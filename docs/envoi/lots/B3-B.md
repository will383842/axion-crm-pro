# Lot B3-B — verdict d'adresse absent : report sans compteur

Cahier : `docs/envoi/lots/B6-B8-cahier.md` (PR brouillon #671), section 2. ESCALADE 1 : recommandation par défaut appliquée (report sans fin, alerte après 7 jours).

## Comportement
Quand la porte `EnvoiAutorise` rend `verdict_adresse_absent_ou_perime`, le séquenceur distingue :
- dernier verdict `valide` (périmé ou daté du futur) : report plafonné à 10, puis arrêt + alerte (inchangé) ;
- dernier verdict `invalide` ou `risque` : refus définitif (inchangé) ;
- aucun verdict, ou dernier verdict `inconnu` (de l'espace courant seulement) :
  1. `EnvoiAutorise::oppositionSeule()` (registre manuel, puis art. 21 / STOP / liste de refus ; fermée en cas d'erreur) : un motif ⇒ arrêt définitif avec CE motif ;
  2. sinon report **sans compteur** (`reports` inchangé, message `reporte`). Le rafraîchissement des verdicts finit par vérifier l'adresse.
- Alerte `envoi.verdict_absent_persistant` (journal, sans adresse) à chaque passage une fois le message `reporte` depuis 7 jours ou plus ; aucun arrêt.

## Ce qui ne change pas
Aucune garde retirée : sans verdict `valide` récent, la porte refuse toujours l'envoi. `verifier()` garde son ordre. Aucune migration, aucun drapeau touché (`ENVOI_REEL_ACTIF` reste faux), aucune suppression de données.

## Fichiers
`Sequenceur.php`, `EnvoiAutorise.php` (une méthode ajoutée), `RefusSequenceur.php` (docblock + commentaire), `tests/Feature/Envoi/SequenceurVerdictAbsentTest.php`, ce document.

## Tests
`SequenceurVerdictAbsentTest.php` : 9 cas du cahier, chacun sous le rôle applicatif (RLS) ET propriétaire. Mutations vérifiées rouges : retrait de `oppositionSeule` (3, 4) ; report compté (1, 2, 7) ; `inconnu` classé invalide (2) ; `invalide`/`risque` reportés (5) ; filtre d'espace retiré (8).

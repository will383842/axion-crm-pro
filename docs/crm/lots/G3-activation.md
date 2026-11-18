# G3-F — commande d'activation par compte (cahier G3-G5, § 10)

**But** : passer UN compte en double authentification par courriel, ou le ramener à l'application. Commande locale, sans route ni écran.

## La commande
`php artisan crm:compte-double-auth <adresse> --methode=email|totp`

- `--methode=email` : refusé si le canal n'est pas un vrai canal. Le vrai canal n'existe que si `ENVOI_REEL_ACTIF` est allumé (et hors simulacre). Tant que cet interrupteur est éteint, l'activation est impossible.
- `--methode=totp` : ramène le compte à l'application d'authentification. Toujours possible.
- `--methode=aucune`, une méthode absente ou inconnue : refusées. Cette commande ne retire jamais la double authentification d'un compte.
- Compte inconnu : code de sortie 1, message neutre.
- Un seul compte change, une seule colonne (`users.double_auth_methode`). Rien n'est supprimé. Aucune migration.
- La sortie et le journal ne contiennent ni adresse ni jeton.

## Ce qui reste hors de ce lot
- L'allumage de `CRM_DOUBLE_AUTH_COURRIEL_ENABLED` et de `ENVOI_REEL_ACTIF` est un geste de production du fondateur. Aucun drapeau n'est touché ici.

## Tests
`backend/tests/Feature/Commands/CrmCompteDoubleAuthTest.php` : faux canal ⇒ refus ; canal réel simulé ⇒ `email` posé sur ce seul compte ; `aucune` ⇒ refus ; retour à `totp` ; compte inconnu.

## Mutations prouvées rouges
- Retirer le contrôle du canal : tests 1 et 1b rouges.
- Accepter `aucune` : test 3 rouge.

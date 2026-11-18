# G3-F — commande d'activation par compte (cahier G3-G5, § 10)

**But** : passer UN compte en double authentification par courriel, ou le ramener à l'application. Commande locale, sans route ni écran.

## La commande
`php artisan crm:compte:double-auth <adresse> --methode=email|totp`

- `--methode=email` : refusé si le canal n'est pas un vrai canal, ou si `CRM_DOUBLE_AUTH_COURRIEL_ENABLED` est éteint. Sinon, `first_login_completed_at` est posé s'il était nul (conservé sinon), dans la même écriture. Le vrai canal n'existe que si `ENVOI_REEL_ACTIF` est allumé (et hors simulacre). Tant que cet interrupteur est éteint, l'activation est impossible.
- `--methode=totp` : ramène le compte à l'application d'authentification. Refusé (code 1, rien ne change) si aucun TOTP n'est inscrit : sinon le compte n'aurait plus aucune double authentification.
- `--methode=aucune`, une méthode absente ou inconnue : refusées. Cette commande ne retire jamais la double authentification d'un compte.
- Compte inconnu : code de sortie 1, message neutre.
- Un seul compte change (colonnes `double_auth_methode`, et `first_login_completed_at` si elle était nulle, en « email »). Rien n'est supprimé. Aucune migration. Journal d'audit : `compte.double_auth.methode` (sans adresse).
- La sortie et le journal ne contiennent ni adresse ni jeton.

## Ce qui reste hors de ce lot
- L'allumage de `CRM_DOUBLE_AUTH_COURRIEL_ENABLED` et de `ENVOI_REEL_ACTIF` est un geste de production du fondateur. Aucun drapeau n'est touché ici.

## Tests
`backend/tests/Feature/Commands/CrmCompteDoubleAuthTest.php` : faux canal ⇒ refus ; canal réel simulé ⇒ `email` posé sur ce seul compte ; `aucune` ⇒ refus ; retour à `totp` (et refus sans TOTP inscrit) ; drapeau éteint ⇒ refus ; `first_login_completed_at` ; audit ; parcours complet après activation ; compte inconnu.

## Mutations prouvées rouges
- Retirer le contrôle du canal : tests 1 et 1b rouges.
- Accepter `aucune` : test 3 rouge.
- Retirer le refus de `totp` sans TOTP inscrit : test 4b rouge.

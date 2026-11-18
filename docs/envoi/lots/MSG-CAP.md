# MSG-CAP — capacités de l'assistant (relecture de #698)

Plan : commentaire « PLAN DU RESTE À FAIRE (18/11) » de #680.

## Ce qui change
- `RaisonBlocage::reglageNonApplique` lit l'interrupteur par la méthode statique `RaisonBlocage::reglagesAppliques()` : plus aucun `@phpstan-ignore` dans `app/Envoi/Campagnes`. La constante `REGLAGES_APPLIQUES` reste (les tests existants la lisent).
- `EnvoiMessagesController::abApplique(Closure $sonde)` : `ab` est vrai seulement si la sonde n'est refusée par AUCUN motif. Avant, un refus d'un autre motif que `objet_b_non_applicable` rendait `ab` vrai à tort.

## Note sur le plan
Le plan écrit « `$ab` ne devient vrai que si le motif est exactement `objet_b_non_applicable` ». Pris à la lettre, cela rendrait `ab` vrai alors que l'envoi refuse l'objet B. J'ai retenu le sens des tests attendus : tout refus ⇒ `ab` faux.

## Tests
`backend/tests/Feature/Envoi/CapacitesAbRelectureTest.php` : autre motif ⇒ faux (5 motifs), `objet_b_non_applicable` ⇒ faux, sonde acceptée ⇒ vrai, aucun `@phpstan-ignore` dans le dossier. Aucune donnée d'espace lue ni écrite : propriétaire et `pgsql_app` identiques.
Mutations prouvées rouges : (1) retour à `$e->motif !== 'objet_b_non_applicable'` ⇒ 5 tests rouges ; (2) un `@phpstan-ignore` remis dans `RaisonBlocage.php` ⇒ 1 test rouge.

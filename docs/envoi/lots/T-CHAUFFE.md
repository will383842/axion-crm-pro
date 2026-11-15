# T-CHAUFFE — Trous de la chauffe (2)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche T-CHAUFFE, décision D5. `ENVOI_REEL_ACTIF` reste `false` ; aucun message réel.

## Fait
- `GET /envoi/chauffe` (`users.manage`) : budget du jour, goulot, termes, ressources (IP et domaines) et table des paliers P1 à P8. Il lit les compteurs de la base (`DepotChauffe`, `CourbeChauffe`, `BudgetChauffe`) et ne résout jamais `ServeurEnvoi`.
- `POST /envoi/chauffe/{id}/reculer` (`workspaces.manage`, propriétaire seul) : écrit un recul dans `envoi_chauffe_reculs` (table existante, ajout seul, auteur et motif conservés). Même journée : second appel sans nouvel effet. Ressource en P1 : 409, rien n'est écrit. Ressource d'un autre espace ou inconnue : 404. Aucune route ne fait monter un palier.
- Les deux couples sortent de `trous/chauffe.php` (vidé) ; instantané `chauffe.json` ajouté. Aucune migration.

## Reste
- `tenu_jusqu_au` et `motif_tenue` valent `null` : aucune donnée ne porte aujourd'hui la tenue d'une ressource (à brancher avec un lot qui la stocke).
- `ressources[].id` est la clé sous laquelle `envoi_chauffe_jours` compte la ressource : `ip:<clé>`, ou `domaine:<clé de boîte>` (le libellé affiché est le domaine, jamais l'adresse entière). Un id par domaine pur demandera un lot sur le dépôt de chauffe.
- Le budget du jour suit, par type, la ressource la plus basse (sens prudent). Sans ressource connue, la courbe vaut P1.
- `jours_max` de P8 (palier sans fin) vaut `jours_min`.

## Risques
- Faible : lecture de compteurs ; le seul geste va dans le sens sûr, réservé au propriétaire. Cloisonnement par `workspace_id` : chaque lecture est faite sous le contexte de l'espace et filtrée explicitement.

## Tests lancés
- `tests/Feature/Envoi/Routes/ChauffeRoutesTest.php` (6 tests, rouges d'abord : routes absentes, 404 partout).
- `CouvertureContratEnvoiTest`, `RoutesEnvoiInstantaneTest` (rouges après avoir vidé les trous / posé l'instantané, puis verts).
- Suite `tests/Feature/Envoi`, pint, PHPStan.

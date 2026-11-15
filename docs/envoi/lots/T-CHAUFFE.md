# T-CHAUFFE — Trous de la chauffe (2)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche T-CHAUFFE, décision D5. `ENVOI_REEL_ACTIF` reste `false` ; aucun message réel.

## Fait
- `GET /envoi/chauffe` (`users.manage`) : budget du jour, goulot, termes, ressources (IP et domaines) et table des paliers P1 à P8. Il lit les compteurs de la base (`DepotChauffe`, `CourbeChauffe`, `BudgetChauffe`) et ne résout jamais `ServeurEnvoi`.
- `POST /envoi/chauffe/{id}/reculer` (`workspaces.manage`, propriétaire seul) : écrit un recul dans `envoi_chauffe_reculs` (table existante, ajout seul, auteur et motif conservés). Même journée : second appel sans nouvel effet. Ressource en P1 : 409, rien n'est écrit. Ressource d'un autre espace ou inconnue : 404. Aucune route ne fait monter un palier.
- Les deux couples sortent de `trous/chauffe.php` (vidé) ; instantané `chauffe.json` ajouté. Aucune migration. Relecture : `(retours)` exclu, domaines regroupés par nom de domaine.

## Reste
- `tenu_jusqu_au` et `motif_tenue` valent `null` : aucune donnée ne porte aujourd'hui la tenue d'une ressource (à brancher avec un lot qui la stocke).
- `ressources[].id` est la clé sous laquelle `envoi_chauffe_jours` compte la ressource : `ip:<clé>`, ou `domaine:<nom de domaine>`. Les boîtes d'un même domaine sont regroupées (jointure `envoi_boites` comme `DepotChauffe::envoyesDomaine`, volumes sommés par jour) : l'id n'est jamais l'identifiant d'une boîte ni une adresse entière, et un recul est posé sous le nom de domaine. La pseudo-boîte `(retours)` n'est jamais une ressource.
- Le budget du jour suit, par type, la ressource la plus basse (sens prudent). Sans ressource connue, la courbe vaut P1.
- `jours_max` de P8 (palier sans fin) vaut `jours_min`.

- **À faire avant le premier envoi réel (F07b)** : `GardeChauffe::jourDeChauffe` applique encore l'ancienne table P1-P3 par boîte, sans `reculsDe`. Brancher les reculs (par nom de domaine et par IP) et la table étendue P1-P8 dans la garde ; tant que ce n'est pas fait, un recul demandé ici n'abaisse pas le quota réellement appliqué à l'envoi.

## Risques
- Élevé avant F07b : écart entre ce que l'écran affiche (reculs, P1-P8) et ce que la garde applique (table P1-P3 par boîte, sans recul). Sans effet aujourd'hui (`ENVOI_REEL_ACTIF=false`), mais à fermer avant tout envoi réel.
- Journal : la ligne `envoi_chauffe_reculs` suffit comme trace d'un recul. Elle porte l'espace, la ressource, la date, l'auteur et le motif, elle est unique par ressource et par jour, et un trigger refuse UPDATE, DELETE et TRUNCATE. Un recul est un geste unitaire et idempotent, sans effet de bord à rejouer ni arrêt général à tracer ; une seconde ligne dans `envoi_journal_commandes` doublerait la même information sans rien ajouter. Le 403 de `reculer` (contrat `infrastructure.yaml`) est signalé à S2-CONTRATS ; ce fichier n'est pas touché ici.
- Faible : lecture de compteurs ; le seul geste va dans le sens sûr, réservé au propriétaire. Cloisonnement par `workspace_id` : chaque lecture est faite sous le contexte de l'espace et filtrée explicitement.

## Tests lancés
- `tests/Feature/Envoi/Routes/ChauffeRoutesTest.php` (8 tests ; rouges d'abord : routes absentes, puis `domaine:(retours)` ressource et domaines non regroupés).
- `CouvertureContratEnvoiTest`, `RoutesEnvoiInstantaneTest` (rouges après avoir vidé les trous / posé l'instantané, puis verts).
- Suite `tests/Feature/Envoi`, pint, PHPStan.

# SANTE-ESP — `envoi:sante` interroge le serveur dans le contexte de chaque espace

Cahier : commentaire « PLAN DU RESTE À FAIRE (18/11) » de #680, lot SANTE-ESP.

## Fichiers
- `backend/app/Console/Commands/EnvoiSante.php` : la sonde passe par chaque espace.
- `backend/tests/Feature/Envoi/EnvoiSanteParEspaceTest.php` : 7 cas (propriétaire et rôle applicatif).
- `docs/envoi/lots/SANTE-ESP.md` : ce fichier.

## Comportement
- Avant : `sante()` était appelée sans espace. L'aiguilleur ne voyait alors aucun adaptateur actif et répondait toujours « KO » dès que l'envoi réel est allumé ; le battement de l'homme mort n'était jamais noté.
- Maintenant : les espaces sont lus par `envoi_couples_reels_portes()` (fonction qui contourne la RLS, exécutable par le rôle applicatif). Pour chaque espace, `WorkspaceContext::run($ws, fn () => $serveur->sante())`.
- Un seul KO ⇒ code de sortie 1 (tous les espaces sont tout de même sondés).
- Aucun espace avec adaptateur réel ⇒ un seul appel hors contexte, comme avant.

## Tests
- Rouges d'abord : 4 échecs sur 7 (les cas « par espace »), puis 7 verts.
- Mutation : appeler `sante()` hors contexte dans la boucle ⇒ 4 échecs ; restauré ⇒ 7 verts.
- Aucune connexion réseau (doublure du serveur). Aucune migration, aucun drapeau touché. Les instantanés NR-07 et NR-08 sont inchangés.

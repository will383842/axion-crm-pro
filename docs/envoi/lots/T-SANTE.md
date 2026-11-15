# T-SANTE — `POST /envoi/sante/test`

## Fait
- Route `POST /envoi/sante/test` (permission `users.manage`, comme la lecture de l'écran Santé) ; méthode `EnvoiSanteController::test`.
- Réponse **409 `envoi_ferme_palier_0`** (décision D4, conforme à `pilotage.yaml`). Aucune écriture, aucune lecture, `ServeurEnvoi` jamais résolu.
- Entrée `tests/Support/Couverture/non-servis/sante.php` (vérifiée par appel dans `NonServiAuPalier0Test`) ; `trous/sante.php` vidé ; instantané `sante.json` complété.

## Reste
- Le vrai test du matin (boîtes témoins) relève du serveur réel, hors palier 0.

## Risques
- Aucun : la route ne fait rien. `ENVOI_REEL_ACTIF=false` inchangé ; aucune migration.

## Tests lancés
- Rouge d'abord : l'entrée `non-servis/sante.php` ajoutée seule, `route:list` ne montre que `GET /envoi/sante` ⇒ `NonServiAuPalier0Test` (`expect($routes)->toContain('POST /envoi/sante/test')`) rouge.
- Vert : `RoutesEnvoiInstantaneTest`, `AucuneRouteTemoinSingulierTest` ; pint ; PHPStan niveau 8 (aucune erreur).
- Non lancés en local : les tests à base de données (PostgreSQL sans postgis/pgvector dans ce conteneur) ; la CI les joue.

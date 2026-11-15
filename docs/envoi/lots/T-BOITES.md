# T-BOITES — Trous des boîtes (D3, D3 bis)

## Fait
- `GET /envoi/boites`, `GET /envoi/boites/{id}`, `POST /{id}/suspendre`, `POST /{id}/reactiver` servis (`EnvoiBoitesController`, `DepotBoites`). Aucun secret, hôte, port ni utilisateur dans une réponse ; aucune route ne résout `ServeurEnvoi`.
- Permissions du contrat : lecture et suspendre `users.manage`, réactiver `workspaces.manage`. Identifiant de boîte = UUID (autre forme : 404 sans requête) ; autre espace : 404.
- `suspendre` passe par `DepotBoites::suspendreManuellement` (`UPDATE … WHERE etat <> 'en_pause'`) : sur une boîte déjà en pause, quel que soit le motif, 200 et rien d'écrit (le stop gagne). Une suspension manuelle pose `pause_motif = 'suspension_manuelle'`.
- `reactiver` ne lève que `suspension_manuelle` ; tout autre motif (dont `manuelle` posé par « Tout arrêter », `refus_connexion_5xx`, `auto_3x5xx_10min`) ⇒ 409 `pause_automatique`, sans nommer de commande. Boîte déjà active : 200 idempotent.
- `mettreEnPause` et `reprendre` ne sont pas modifiées.
- `POST /envoi/boites`, `POST /envoi/boites/test-connexion`, `PUT /envoi/boites/{id}/reglages` : 501 `serveur_reel_requis` inconditionnel, avant toute validation, liaison ou lecture, sous `workspaces.manage` (la garde F36-001 exige une permission sur toute écriture ; un compte sans ce droit reçoit 403 avant le 501) (entrées `non-servis/boites.php` ; `NonServiAuPalier0Test` non modifié).
- `GET /envoi/boites/{id}` : `reglages` par défaut, sans rien stocker.
- Trous `boites` vidés ; instantané `instantane-routes-envoi/boites.json` ajouté.
- Test d'écran `BoitesPalier0.test.tsx` (C1 à C3), écran non modifié.

## Reste
- Fiche : `reponses`, `jours` et `derniers_refus` sont des listes vides (aucune source rattachée à la boîte dans ce lot) ; `taux_refus` vaut `null` (« non mesuré », permis par le contrat). `envoyes_aujourdhui` compte les injections acceptées du jour (Europe/Paris), `reponses_7j` les événements de réponse sur 7 jours.
- Aucune commande ne lève aujourd'hui une pause automatique de boîte : à prévoir avant le premier envoi réel (plan, section 9).
- Corps 4xx non validés par `ContratOpenApiEnvoi::ecartsReponse` avant S2-CONTRATS (statut seul).
- Pas de migration, pas de yaml touché.

## Risques
- Le motif `suspension_manuelle` est une convention sans migration : tout autre motif est traité comme pause automatique (sens sûr).
- `ENVOI_REEL_ACTIF=false` inchangé ; aucun message réel, aucun réseau.

## Tests lancés (PostgreSQL 16 local)
- CI : `CoucheAutorisationBrancheeTest` (F36-001) a rougi sur la première version (routes 501 sans permission) ; corrigé en posant `workspaces.manage`, sans toucher la garde.
- `BoitesRoutesTest` : 13 rouges avant le code (404 partout, entrées 501 absentes), 13 verts après. Témoin : retirer la condition de `suspendreManuellement` fait rougir 3 tests « le stop gagne ».
- `tests/Feature/Envoi`, `tests/Feature/NonRegression`, `RlsTest` : aucun échec. `CouvertureContratEnvoiTest` et `NonServiAuPalier0Test` verts.
- Frontend : `BoitesPalier0.test.tsx` (3) et `BoitesEcranF10.test.tsx` (18) verts. Les cas C1 à C3 décrivent un écran inchangé : ils sont verts d'emblée (pas de rouge exigé côté écran).
- `pint --dirty` : passé ; PHPStan sur `EnvoiBoitesController` et `DepotBoites` : aucune erreur.

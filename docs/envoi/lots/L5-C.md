# Lot L5-C — routes « lancer » et « arrêter » d'une campagne

Cahier : `docs/envoi/lots/L5-L6-cahier.md` § 4 (branche `conception/l5-l6`, PR #649).

## Ce que fait le lot
- `POST /api/v1/envoi/campagnes/{id}/lancer` et `/arreter` : groupe `permission:workspaces.manage` (propriétaire seul), `whereUuid`, en-tête `Idempotency-Key` optionnel (422 si mal formé).
- Le contrôleur ne fait que passer la main à `GestesCampagne::lancer` / `arreter` (L5-A, L5-B). Tous les refus métier sortent en `{"error": code, "message": texte}` : 409 (`transition_refusee`, `sans_sequence`, `materialisation_en_cours`, `materialisation_bloquee`, `aucun_destinataire`, `frein_arret_general`, `frein_pause_automatique`, `reprise_refusee_base`, `concurrence`, `version_perimee`, `cle_reutilisee`), 404 `non_trouve`, 422 `validation`.
- Contrat `campagnes.yaml` : deux chemins ; `campagnes.d.ts` régénéré par `pnpm api:envoi`.
- Reprise directe d'une séquence née d'une campagne (`DepotSequences::geste`) : 409 `pilotee_par_la_campagne`, aucun geste écrit. La pause directe reste permise (le stop gagne).

## Échec fermé
`lancer` ne fait rien partir : `ENVOI_REEL_ACTIF` reste faux (non touché), le cycle rend « porte fermée » (test 2). Une campagne sans destinataire retenu (opposés compris) est refusée (`aucun_destinataire`).

## Filet modifié : pourquoi
`tests/Feature/Envoi/Routes/instantane-routes-envoi/campagnes.json` : deux routes ajoutées (le test R00 compare l'instantané à l'identique). `nr20-routes.json` n'est pas modifié : la photo NR-20 est en « au moins » et ne contient aucune route `/envoi/*`.

## Non traité ici (fichiers hors lot)
Remarques non bloquantes des relectures #656 / #658 qui visent `GestesCampagne.php` (interdit en L5-C) : point de sauvegarde autour de la clôture pour ne pas annuler l'arrêt sur `55P03` ; `57014` converti en `concurrence` ; libellé « Reprise impossible » pour `lancer` ; tests du verrou de séquence.

# Lot D2 — rattachement d'une réponse à son inscription (sous-lot D2-1)

Cahier : `docs/envoi/lots/D1-D2-cahier.md` §6, PR #617 (branche `conception/d1-imap`). Ne pas confondre avec l'ancien `D2.md` (dettes du feu vert).

## Fait (D2-1)
- `ServiceReponses::rattacher` : l'identifiant est accepté s'il existe, pour l'espace, dans `envoi_injections_smtp` OU `envoi_injections_locales` (même règle que le déclencheur `2026_12_03_000080`). Avant : `inconnue_injection` pour tout identifiant du registre (faux serveur, Kumo).
- `DepotBoites::resumer` : `reponses_7j` compte `boite IN (<UUID de la boîte>, <adresse>)` (la relève écrit l'UUID ; l'adresse est gardée pour d'anciennes lignes). Avant : toujours 0.
- `MessageOrigine` : commentaire et message corrigés (UUID du registre). Aucun changement de règle.
- Tests R5 (3 cas) et R6 (2 cas) dans `RattachementReponseD2Test`, sous le rôle applicatif ET le propriétaire.

## Reste (D2-2)
- R1 (bout en bout par la relève), R2 (VERP), R3 (ancienne forme), R4 (cloisonnement de la relève), R7 (aucune adresse en clair) du cahier §6.2 : non écrits ici, pour tenir la taille de PR.
- `ServiceReponses::BASE` joint encore `envoi_boites.adresse = e.boite` pour `boite_id` (même écart que `reponses_7j`) : hors des fichiers autorisés, à traiter dans un lot dédié.

## Mutations vérifiées
- `ServiceReponses` ne lit plus que `envoi_injections_smtp` : R5 rouge.
- Filtre `reponses_7j` sur l'adresse seule : R6 rouge.
- `where workspace_id` retiré de la lecture de `envoi_injections_locales` : R5 (UUID d'un autre espace) rouge sous le propriétaire.

# Lot L8-B — constat en lecture seule des lignes d'ancienne forme

Cahier : `docs/envoi/lots/B6-B8-cahier.md`, § 12.2. Il conditionne L8-C (retrait des branches de transition), qui reste soumis à la décision du fondateur (ESCALADE 4).

## Ce que fait le lot
La commande `envoi:transition:constat [workspace]` compte, par espace, sans rien écrire :
- `forme2` : messages dont `id_injection` est nul et `id_injection_serveur` renseigné ;
- `forme3` : messages que seule la clé `seq-<inscription>-<étape>` du registre local rattache (aucune des deux colonnes ne porte l'identifiant du registre) ;
- `replis` : messages dont le registre des envoyés porte un autre identifiant d'injection, donc retrouvés seulement par le repli de `RegistreMessagesEnvoyesDb::parIdentifiantServeur`.

Sortie : une ligne par espace (`<id> forme2=N forme3=N replis=N`). Jamais d'adresse. Code de sortie 1 si l'espace est inconnu ou en erreur.

## Garanties
- Lecture seule : transaction `SET LOCAL transaction_read_only = on`, toujours annulée.
- Filtre `workspace_id` explicite (la RLS est contournée sous le propriétaire) ET contexte d'espace (rôle applicatif).
- Non planifiée : les instantanés `nr07` et `nr08` ne bougent pas. Aucune migration, aucun drapeau.

## Tests
`backend/tests/Feature/Envoi/TransitionConstatTest.php`, joués sous le rôle applicatif ET sous le propriétaire : comptes exacts, aucune écriture, sortie sans `@`, espace B non compté dans A, espace inconnu, commande non planifiée.

Mutations prouvées rouges : retrait du filtre d'espace (test d'isolation) ; retrait de la lecture seule (test « aucune écriture »).

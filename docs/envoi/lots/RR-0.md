# RR-0 — migration seule (canal `releve`, geste `annulation`)

Cahier de conception : PR #673 (`docs/envoi/lots/D3-D6-cahier.md`, section 3). Cette PR ne contient que la migration et son test ;
rien dans le code ne la lit encore (RR-3A et RR-6A la liront, après son déploiement).

## Ce que fait la migration `2026_12_11_000100_envoi_retours_reactions.php`
- `envoi_oppositions.canal` admet `page | one_click | reponse | releve` : une opposition posée par la relève n'est ni un clic
  ni un geste humain.
- `envoi_reponses_classements.geste` admet `classement | fiche | annulation` : annuler un classement est un geste en ajout seul
  (`auteur = 'humain'`, `contact_id` nul ; la contrainte `_fiche` est intacte).
- Contraintes reposées `NOT VALID` : aucune ligne relue ni réécrite. Aucun DROP de table ou de colonne, aucun DELETE.
- N'écrit ni ne lève aucune opposition ; n'ouvre aucun envoi. `down()` inerte (entrée de `NR17_RETOUR_INERTE_VOULU`).

## Tests
`backend/tests/Feature/Envoi/SchemaRetoursReactionsTest.php` (S1 à S4), joués sous le rôle applicatif ET le propriétaire.
Commande : `cd backend && vendor/bin/pint --dirty && vendor/bin/pest --filter='SchemaRetoursReactions|NR-17'`.

## Déploiement
Obligatoire avant RR-3A et RR-6A : `php artisan migrate:status` sans `Pending`.

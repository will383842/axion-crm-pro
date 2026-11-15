# R06-ARCH — Conservation des tentatives (vague 3)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche R06-ARCH, décision D16. Aucune migration : table et fonction viennent de S1-A (`2026_12_03_000060`).

## Fait
- `RetentionTentatives::archiver()` : boucle sur les espaces `whereNull('deleted_at')`, chacun sous `WorkspaceContext`, appelle `envoi_tentatives_archiver(espace, aujourd'hui Paris − 400 jours)` dans une transaction par espace. Un espace en erreur n'arrête pas les autres.
- Commande `envoi:tentatives-archiver` (code 1 si au moins un espace échoue).
- Tâche planifiée tous les jours à 04:50, `onOneServer`, `withoutOverlapping(60)` ; elle ne suit pas `CRM_PURGE_ENABLED` (aucune donnée de personne), donc sans `when`.
- Instantanés `nr07` et `nr08` régénérés (une tâche ajoutée).

## Filet modifié : pourquoi
`nr07-taches-planifiees.json` et `nr08-taches-sous-rls.json` : la tâche `envoi:tentatives-archiver` est ajoutée voulu par ce lot (rien d'autre ne change).

## Tests (`RetentionTentativesTest`)
401 jours agrégée puis retirée / 399 jours conservée ; totaux agrégés = totaux d'avant ; rejeu sans double comptage ; espaces bouclés sous contexte, corbeille ignorée ; aucune table de personnes touchée (comptages avant/après de `envoi_oppositions*`, `email_suppressions`, `contacts`, `personnes`) ; erreur isolée par espace ; planification (04:50, verrou 5–360 min, pas de condition) ; garde avec témoin sur `LecteurReportsDb` (fenêtre d'une heure).

## Reste
- Un jour déjà agrégé qui recevrait ensuite de nouvelles lignes anciennes ferait échouer la fonction pour cet espace (clé unique) : l'erreur est comptée, la commande sort en échec, rien n'est supprimé. Cas inatteignable en pratique (le détail n'est écrit qu'avec la date du jour).

## Risques
- `ENVOI_REEL_ACTIF=false` : aucun envoi réel.
- Seules des lignes techniques de `envoi_tentatives` de plus de 400 jours sont retirées, après agrégation.

## Tests lancés
`pint --test`, PHPStan (aucune erreur), Pest ciblé : `RetentionTentativesTest`, NR-07, NR-08, `VerrousDuPlanificateurTest`, `CommentairesDuPlanificateurTest`, `EffacementDouxPorteeAgent35Test`, `SocleFichiersCommunsTest`, `SchemaFinEnvoiATest` : verts.

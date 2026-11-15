# L8-ORCH — Chef d'orchestre de l'envoi (vague 4)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche L8-ORCH (D9). `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune migration, données fictives (`*.example.test`) en test seulement.

## Fait
- `ChefOrchestre::cycle()` : porte fermée (`PorteEnvoiReelle::ouverte()`, même prédicat que la passerelle) ⇒ sortie immédiate, sans séquenceur, sans péremption, sans échéance. Porte ouverte : verrou `Cache::lock` (un seul cycle travaille), péremption des réservations (`perimerToutesLesReservations`), puis boucle sur les espaces `whereNull('deleted_at')`.
- Un espace est sauté (une ligne de journal par espace, motif et jour) sans produit Envoi, sous arrêt général, si la dernière décision de feu vert n'est pas « accorde » (retirée ou absente), ou si un point bloquant est rouge hors `auto.reel_actif_faux`.
- Chaque espace est traité sous `WorkspaceContext::run`, avec un `Sequenceur` construit par la fabrique pour cet espace ; refus si le contexte au moment de l'exécution diffère de l'espace de construction. Une exception dans un espace est comptée et n'arrête pas les autres (seule la classe de l'exception est journalisée). Borne de garde de 120 s entre deux espaces, inférieure à l'expiration du verrou (600 s). Bilan journalisé : compteurs seulement.
- `envoi:cycle` (commande) planifiée chaque minute, `withoutOverlapping`, `onOneServer`, **non due** tant que la porte est fermée. `envoi:perimer-reservations` n'est **pas** planifiée.
- `SourceExpeditionDb` : paramètre optionnel `$flux` ; le chef passe `prospection` : un domaine `flux` NULL ou `lettre` est refusé (aucun expéditeur). Sans paramètre, comportement inchangé.
- `OrchestrationServiceProvider` : liaison (non singleton) du chef et de sa fabrique.

## Filet modifié : pourquoi
`nr07-taches-planifiees.json` et `nr08-taches-sous-rls.json` : la tâche `envoi:cycle` est ajoutée, voulu par ce lot (rien d'autre ne change).

## Fichier hors liste du plan (obligatoire)
`backend/app/Envoi/Sequence/SourceExpeditionDb.php` : exigence du relecteur de L8-COMPO (filtre sur le flux de prospection), rétrocompatible.

## Reste
- Au palier 0 la liste de feu vert garde des points rouges : le chef ne traite donc aucun espace en production.
- La borne de garde est vérifiée entre deux espaces, pas à l'intérieur d'un espace (le séquenceur borne à 100 inscriptions).

## Tests
`ChefOrchestreTest` (porte fermée, retrait absent, saut par motif avec une ligne par jour, deux espaces sous contexte, corbeille, exception isolée, verrou, borne, bilan sans adresse, fabrique par espace, bout en bout sur le faux serveur, flux NULL / lettre refusés, rôle `pgsql_app`) ; `ChefOrchestrePlanifieTest` (planification, tâche non due porte fermée, garde avec témoin sur `perimer-reservations`) ; NR-07, NR-08.

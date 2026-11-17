# Consignes communes des routines de codage (lots du cahier FINI)

Dépôt PUBLIC : écrire « le fondateur », jamais de nom ; adresses en example.test ; aucun secret ni IP réelle.

1. Cahier : `docs/envoi/lots/FINI-cahier.md` (branche `conception/fini`, PR brouillon #680). Lire la section de TON lot en entier, les règles communes, la table des fichiers, la section ESCALADE (appliquer le défaut sans risque).
2. Coder UNIQUEMENT ton lot, QUE ses fichiers autorisés. Avancer par petits pas et pousser tôt.
3. Une migration = PR « migration seule » (rien ne la lit) ; vérifier que son horodatage est libre (main + PR ouvertes).
4. Échec fermé ; cloisonnement par espace (test IDOR si route) ; aucune suppression ni purge de données de personnes ; tout reste inerte tant que l'envoi réel est éteint.
5. Ne jamais toucher ENVOI_REEL_ACTIF, ORIGINES_LECTURE_ENABLED, CRM_GUIDE_LIEN_ENABLED, USINE_AGREGATS_CRM_ENABLED.
6. Tests rouges d'abord (les lancer, les voir échouer), sous pgsql_app ET propriétaire ; mutations demandées ; tests déterministes.
7. Avant de pousser : `git fetch origin && git rebase origin/main` ; vérifier les PR ouvertes (`gh pr list`, `gh pr diff N --name-only`) : fichier commun avec une PR ouverte = ESCALADE, ne pas forcer.
8. Branche `feat/fini-<lot en minuscules>` ; commit terminé par « Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com> » ; PR prête « feat(envoi): <LOT> <objet> », corps : fichiers, tests et résultats, renvoi au cahier #680, terminé par « 🤖 Generated with [Claude Code](https://claude.com/claude-code) ».
9. Attendre la CI ; si rouge, corriger (jamais de push forcé).
10. Poster en commentaire « PR PRÊTE POUR RELECTURE : #<n> <sha 40 caractères> » et terminer par la même ligne.
11. Si bloqué : commentaire « ESCALADE » précis sur #680, ne pas s'arrêter en silence. Ne jamais fusionner.

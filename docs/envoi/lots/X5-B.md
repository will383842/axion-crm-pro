# X5-B — séparation lettre/prospection lue dans l'inventaire des IP

Cahier : `docs/envoi/lots/FINI-cahier.md` (PR #680). Dépend de X5-A (#702, fusionnée) et de FINI-M0A (table `envoi_ips`, colonne `envoi_domaines.ip_id`). Aucune migration ici.

## Fichiers (seuls ceux-là sont touchés)
- M `backend/app/Envoi/FeuVert/ListeFeuVert.php`
- C `backend/tests/Feature/Envoi/FeuVertSeparationIpTest.php`
- M `backend/tests/Feature/Envoi/FeuVertListeTest.php` (constante `FV_AUTO_SANS_SOURCE` vidée : le point a désormais une source ; l'aide `toutVert()` pose une IP et un domaine relié)
- C `docs/envoi/lots/X5-B.md`

## Comportement
`auto.separation_lettre_prospection` est vert seulement si : (a) colonne `flux` présente et aucun domaine sans flux ; (b) au moins un domaine et chaque domaine relié à une IP non retirée ; (c) aucune IP reliée à des domaines de deux flux (revérifié par requête) ; (d) au moins une IP active par flux utilisé. Sinon rouge avec la raison. Aucun domaine inscrit ⇒ rouge (échec fermé). Lecture seule, aucun réseau, filtre `workspace_id` explicite.

## Preuve
- Rouge : premier commit de la PR (tests seuls), sur la CI.
- Vert : second commit, sur la CI.
- Essai local : impossible, le PostgreSQL du bac à sable n'a pas l'extension PostGIS (migrations non jouables). Non poursuivi.
- Les tests tournent sous `pgsql` (propriétaire) ET `pgsql_app` (RLS armée).
- Mutations demandées (retirer (b), retirer (c)) : jouées sur la CI, voir le commentaire de la PR.

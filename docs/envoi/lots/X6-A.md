# X6-A — commandes d'inventaire des IP et de liaison domaine → IP

Cahier : `docs/envoi/lots/FINI-cahier.md` (branche `conception/fini`, PR brouillon #680). Dépend de FINI-M0A (#683, en ligne).

## Fichiers
- `backend/app/Envoi/Chauffe/DepotIps.php` : `ipDuDomaine`, `ipsParFlux`, `domainesSansIp` (lecture sous contexte + filtre), `ajouter`, `retirer`, `lier`.
- `backend/app/Console/Commands/EnvoiIpInventaire.php` : `envoi:ip-inventaire {workspace} {ajouter|retirer} {ip} {--flux=} --auteur=`.
- `backend/app/Console/Commands/EnvoiDomaineIp.php` : `envoi:domaine-ip {workspace} {domaine} {ip} --auteur=` (appelle `envoi_domaine_ip_poser`).
- `backend/tests/Feature/Envoi/InventaireIpTest.php` : 7 scénarios × (rôle applicatif, propriétaire).

## Comportement
- Commandes locales, aucune route. Une IP se retire (`retiree_le`), jamais ne se supprime.
- Forme vérifiée avant la base (IPv4 pointée, octets 0-255 ; les IP privées passent, elles servent aux tests) ; refus de la base (flux différent, IP retirée, doublon, espace inconnu) ⇒ code de sortie non nul, message sans valeur secrète.
- Les écritures s'isolent dans un point de sauvegarde : un refus ne casse pas une transaction englobante.
- Rien ne lit encore ces données (X6-B les lira) ; aucun drapeau d'envoi touché.

## Tests
Rouges d'abord (commande inexistante), puis verts sous `pgsql_app` et propriétaire. Mutation : `ipDuDomaine` sans filtre `retiree_le` ⇒ 2 rouges (test 2, deux rôles).

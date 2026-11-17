# X6-B — reculs d'IP dans la garde, et comptage des envois par IP

Cahier : `docs/envoi/lots/FINI-cahier.md` (branche `conception/fini`, PR brouillon #680). Dépend de X6-A (#699) et X11-A (#684), fusionnés.

## Fichiers
- `backend/app/Envoi/Chauffe/GardeChauffe.php` : motifs `ip_inconnue` et `quota_ip_atteint`, plafond d'IP avec reculs, `noter()` incrémente aussi l'IP.
- `backend/app/Envoi/Chauffe/DepotChauffe.php` : `envoyesIp`, `adaptateurDuDomaine`.
- `backend/tests/Feature/Envoi/ChauffeReculsIpGardeTest.php` : 7 scénarios × (rôle applicatif, propriétaire).
- `docs/envoi/lots/T-CHAUFFE.md`, ce fichier.

## Comportement
- Le lien message → IP vient de l'inventaire (domaine de l'expéditeur → `ip_id`), pas du transport.
- Domaine d'adaptateur autre que `faux` sans IP active : `ip_inconnue` (report, échec fermé). Domaine `faux` ou non inscrit : comportement de `main`.
- Sans recul d'IP : aucune porte de plus (seuils inchangés). Avec recul : plafond = budget de la table P1-P8 rejouée avec les reculs, borné à `Seuils::PALIER_MAX` ; envoyés de l'IP (+ en vol, clé facultative `ip` de `$enCours`) ≥ plafond ⇒ `quota_ip_atteint`. Porte supplémentaire : jamais de montée.
- `noter()` incrémente la boîte et, si le domaine a une IP, `ip:<ref>`, dans la même transaction.

## Hors lot (non repris, fichier non autorisé)
Les deux remarques de relecture de #699 portent sur `DepotIps.php` (contrôle de `auteur` dans `ajouter`, transaction sur `retirer`) : hors des fichiers de X6-B, à reprendre dans un lot qui les autorise. La passerelle ne fournit pas encore `enCours['ip']` (traité comme 0).

## Tests
Rouges d'abord (constantes et méthodes absentes), puis verts sous `pgsql_app` et propriétaire (par la CI : pas de PostgreSQL complet en local).

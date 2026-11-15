# R06 — conservation de `envoi_tentatives` (décision)

**Décision : conserver 400 jours, puis agréger par jour. Aucune suppression de données de personnes.**

- `envoi_tentatives` est une table **technique** (fournisseur, issue, code SMTP, horodatage) : elle ne porte ni adresse
  ni nom. La table est en ajout seul : UPDATE et TRUNCATE sont refusés par déclencheur ; le DELETE l'est aussi, sauf au-delà de 400 jours, par la fonction d'archivage seulement (variable `envoi.archivage_tentatives` = `on`), selon le déclencheur de la migration `2026_12_03_000060`.
- Le détail est gardé `RetentionTentatives::CONSERVATION_JOURS` = **400 jours**. Au-delà, la commande
  `envoi:tentatives-archiver` appelle `envoi_tentatives_archiver(espace, jour)` : la fonction (SECURITY DEFINER, refuse
  un espace différent du contexte et toute borne plus récente que 400 jours) **agrège d'abord** les lignes par
  (jour, fournisseur, issue, code SMTP) dans `envoi_tentatives_jours`, puis retire le détail déjà agrégé.
- Le total journalier est donc conservé sans limite ; seul le détail ligne à ligne, sans donnée de personne, disparaît.
- Un espace en erreur n'arrête pas les suivants (compté dans `erreurs`).
- Rien d'autre n'est purgé par cette conservation : contacts, oppositions, consentements et journaux d'audit ne sont
  pas concernés.

Garde : `tests/Feature/Envoi/RetentionTentativesDocTest.php` (durée du document = constante du code, agrégation
avant retrait dans la migration, aucune table de personnes touchée).

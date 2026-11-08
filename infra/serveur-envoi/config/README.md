# Configuration du serveur d'envoi générée par le CRM (lot K18)

Ce dossier ne contient que des MODÈLES : aucune valeur réelle (dépôt public).
Les valeurs réelles sont générées depuis la base du CRM et posées sur le serveur
par la commande locale `php artisan envoi:config-appliquer` (jamais par API).

- Validation à côté du service, puis application ; si l'application échoue, retour arrière automatique.
- Chaque tentative laisse une ligne (empreinte + statut) dans `envoi_config_versions` (ajout seul).
- Le socle local (plafond dur, homme mort, mode témoins, liste blanche) ne passe PAS par ce canal :
  tout paquet qui le vise est refusé et déclenche une alerte de dérive.
- Seules des clés PUBLIQUES DKIM voyagent. Les clés privées restent sur le serveur (ADR K0, D3).

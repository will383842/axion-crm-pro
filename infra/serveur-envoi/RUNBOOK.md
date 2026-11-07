# Runbook — serveur d'envoi (lot K3)

Serveur Hetzner **distinct** de la prod du CRM. Dossier public : aucune IP, aucun secret ici, seulement des marqueurs (`<ip-envoi>`, `<ip-tunnel>`…).

## Installer (une fois)
1. Créer `/etc/axion-envoi/valeurs.env` (hors dépôt) : `IP_ENVOI IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_CRM PORT_WG CLE_PUBLIQUE_CRM`.
2. `./installer.sh` (à blanc), relire, puis `./installer.sh --oui`. Relançable sans danger.
3. Donner au CRM la clé publique : `wg pubkey < /etc/wireguard/cle-privee`.
4. **Garder la console de secours Hetzner ouverte** tant que SSH-par-tunnel n'est pas vérifié.

## Surface exposée
Entrée : UDP WireGuard seulement ; SSH (22) uniquement via `wg0`. API KumoMTA : `127.0.0.1:8000` (`trusted_hosts` = localhost). Sortie : port 25 seulement depuis `<ip-envoi>`.

## Redémarrer proprement
`systemctl restart kumomta-envoi` : arrêt (`compose down`, 60 s de grâce pour vider les files), puis relance. Ordre garanti après `wg0` et `nftables`. Vérifier : `curl -s http://127.0.0.1:8000/api/check-liveness/`.

## Changer l'image
Relever la nouvelle empreinte `sha256:`, la remplacer dans `docker-compose.yml`, relancer `tests/lancer-tests.sh`, PR, puis `installer.sh --oui`.

## Hors périmètre K3 (restant)
Injection CRM→KumoMTA, DKIM/SPF/DMARC, réputation et chauffe de l'IP, supervision.

# Passerelle du serveur d'envoi (lot K2)

PHP 8.3 sans framework (ADR K0, D6). Stockage SQLite local. **Aucun envoi réel** : KumoMTA est derrière l'interface `Kumo` ; les tests utilisent `FauxKumo`, le défaut est `KumoInactif` (refuse tout).

## Contrat
- En-têtes : `X-Workspace-Id`, `X-Timestamp` (fenêtre ±300 s), `X-Nonce` (retenu 10 min, persistant), `X-Signature`.
- Charge signée (HMAC-SHA256) : `v1 \n workspace_id \n timestamp \n nonce \n METHODE \n chemin?requête \n sha256(corps)`. Vecteurs communs avec le CRM : `tests/vecteurs-hmac.json`.
- Clé par espace, lue de l'environnement : `PASSERELLE_CLES='{"<espace>":"<clé>"}'`. Clé vide = refus. Base : `PASSERELLE_BASE`.
- `POST /v1/commandes/{pause|reprendre|retirer}` avec `commande_id` : rejouée = même réponse sans refaire l'action ; autre corps = 409. L'extinction des témoins est refusée (403).
- `GET /v1/evenements?curseur=N[&limite=1..500]` : séquence par espace sans trou. Rejeu sur 72 h (410 + `curseur_minimal` au-delà), conservation 30 jours. Réponse signée (`X-Signature`, reprend le nonce de la requête).
- Journal des commandes en ajout seul (déclencheurs SQLite). Les erreurs ne donnent aucun détail.

## Tests
```bash
cd infra/serveur-envoi/passerelle && composer install && vendor/bin/phpunit
```

## Limites connues
- Pas encore de client KumoMTA réel (K4c), ni d'ingestion du log hook (K7) : `Stockage::ajouterEvenement` est le point d'entrée prévu.
- nginx/php-fpm, TLS mutuel et nftables relèvent de K2-nginx et K3. Un `DROP TABLE` par un accès direct au fichier n'est pas bloquable en SQL : droits `0600`, processus non root.

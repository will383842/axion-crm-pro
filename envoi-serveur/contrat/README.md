# Contrat CRM ↔ serveur d'envoi (vecteurs HMAC communs)

`vecteurs-hmac.json` est partagé par le CRM (PHP) et le serveur d'envoi : chaque côté doit retrouver
exactement les signatures du fichier (secret de vecteur publiable, sans valeur).

## Charge signée (HMAC-SHA256, hexadécimal)

- Requête : `MÉTHODE \n chemin \n horodatage \n nonce \n workspace_id \n corps`
  (GET : `corps` = chaîne de requête exacte). En-tête : `k=<id de clé>,sha256=<hex>`.
- Réponse : `REPONSE \n nonce \n workspace_id \n statut \n corps` (liée au nonce de la requête).

## Changement de contrat (v2) — `workspace_id` dans la charge signée de la requête

Avant : `MÉTHODE \n chemin \n horodatage \n nonce \n corps`. Maintenant, `workspace_id` s'insère entre
`nonce` et `corps`. Raison (audit sécurité, PR #446) : l'en-tête `X-Envoi-Workspace` n'était pas couvert par la
signature ; un intermédiaire pouvait le changer sans invalider la requête. Le serveur DOIT recalculer la
signature avec le `workspace_id` de l'en-tête (UUID canonique ; absent ou invalide = 401). Le nonce anti-rejeu
est consommé par couple (source, workspace_id, nonce).

Les signatures du fichier ont été recalculées INDÉPENDAMMENT en Python (`hmac` + `hashlib`, sans le code PHP),
puis comparées par le test Pest `vecteurs HMAC communs`.

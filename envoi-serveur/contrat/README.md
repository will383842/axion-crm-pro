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

## `GET /v1/sante` — mesures de santé du serveur (K15)

Lu par le CRM toutes les 5 minutes (`envoi:supervision-sante`). Même signature HMAC que le reste du contrat (GET : la « chaîne
de requête » signée est vide), réponse signée liée au nonce, `workspace_id` dans le corps. Aucune écriture, aucun effet de bord.

```json
{
  "workspace_id": "<uuid>",
  "mesures": {
    "file_age_s": 12,
    "disque_libre_pct": 63.5,
    "memoire_libre_pct": 41.0,
    "certificat_jours": 58,
    "tunnel_ok": true,
    "ntp_synchro": true,
    "horloge_unix": 1780000000
  }
}
```

| Champ | Type et unité | Plage acceptée par le CRM | Qui le calcule |
| --- | --- | --- | --- |
| `file_age_s` | entier, secondes | 0 à 31 536 000 | la passerelle : `maintenant − date du plus ancien message en file` (0 si la file est vide) |
| `disque_libre_pct` | nombre, % | 0 à 100 | la passerelle : octets libres du volume des files / octets totaux × 100 |
| `memoire_libre_pct` | nombre, % | 0 à 100 | la passerelle : mémoire disponible / `memory_limit`. **`memory_limit` peut valoir 0 ou −1 (illimitée) : la division est interdite, la clé est alors OMISE (ou `null`), jamais 0, NaN ni INF.** |
| `certificat_jours` | entier, jours entiers restants avant l'échéance du plus proche certificat (TLS du serveur ou client) | −36 500 à 36 500 (négatif = expiré) | la passerelle |
| `tunnel_ok` | booléen | — | la passerelle : le lien d'administration répond |
| `ntp_synchro` | booléen | — | la passerelle : l'horloge est synchronisée (NTP) |
| `horloge_unix` | entier, secondes UTC, lu au moment de la réponse | 0 à 4 102 444 800 | la passerelle |

Règles de lecture côté CRM :

- Types **stricts** : une chaîne (`"12"`), un nombre à décimales pour un champ entier (`900.9`) ou un flottant hors plage (`1e30`) n'est
  pas une mesure ; elle est traitée comme **absente** (constat « mesure_absente_… », attention).
- Une réponse signée **sans aucune mesure lisible** (clé `mesures` absente, vide, ou entièrement invalide) est une **sonde en échec** :
  elle compte comme silence pour l'homme mort (pas de « vert » par défaut).
- Écart d'horloge : le CRM compare `horloge_unix` à sa propre heure prise juste avant la sonde ; > 60 s attention, > 300 s critique.
  Or la passerelle refuse déjà toute requête dont l'horodatage s'écarte de plus de 300 s : au-delà, la sonde n'obtient pas de mesure.
  La passerelle DOIT alors répondre `401` **signé** (la signature est vérifiée avant le contrôle d'horloge) avec le corps
  `{"erreur":"horloge_hors_fenetre","workspace_id":"<uuid de l'en-tête>"}` (comme toute réponse signée, `workspace_id` est EXIGÉ : le CRM refuse une réponse
  sans lui, ce 401 compris, et la traite alors comme un refus ordinaire) ; le CRM classe ce refus « horloge » critique (pas « serveur muet »). Un `401` non signé reste un refus ordinaire.

**Dette K2-suite (nommée) : la route `/v1/sante` n'existe pas encore côté passerelle**, pas plus que le `401` signé `horloge_hors_fenetre`
(`infra/serveur-envoi/passerelle/src/Passerelle.php`, `FENETRE_HORODATAGE`). Tant qu'elle manque, `ENVOI_SANTE_ACTIF` doit rester faux
et l'envoi réel éteint (voir `docs/envoi/supervision-k15.md`, couplage avec l'homme mort).

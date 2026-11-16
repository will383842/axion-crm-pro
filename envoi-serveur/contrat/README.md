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
| `disque_libre_pct` | nombre, % | 0 à 100 | la passerelle : octets libres du volume du dossier de la base / octets totaux × 100 |
| `memoire_libre_pct` | nombre, % | 0 à 100 | la passerelle : `MemAvailable` / `MemTotal` de `/proc/meminfo`, × 100. **Si `MemTotal` est nul ou illisible : la division est interdite, la clé est alors OMISE (ou `null`), jamais 0, NaN ni INF.** |
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

Livré (C2-1) : la passerelle répond à `GET /v1/sante` et rend le `401` signé `horloge_hors_fenetre`. `file_age_s` et `tunnel_ok` sont omis tant que l'API d'administration KumoMTA n'est pas liée (le CRM les classe « mesure absente », jamais vert).

## `POST /v1/injections` — injection d'un message (C2-2, faux serveur seulement)

Charge signée comme toute requête (corps exact). L'espace vient de la **signature** : un `workspace_id` dans le corps est refusé (`400 corps`).
Corps JSON, exactement ces quatre clés (adresses d'exemple : `*.example.test`) :

```json
{ "id_injection": "<uuid>", "mail_from": "rb-<42 hex>@bounce.exemple.example.test", "rcpt_to": "contact@client.example.test", "message": "<RFC 5322 en base64>" }
```

| Contrôle | Refus |
| --- | --- |
| JSON, clés, types | `400 corps` |
| `id_injection` UUID minuscule | `400 id_injection` |
| `mail_from` VERP `rb-<42 hex>@bounce.<domaine>` | `400 mail_from` |
| `rcpt_to` : UNE adresse ASCII nue (ni virgule, ni nom, ni CR/LF) | `400 rcpt_to` |
| `message` base64 strict ; décodé ≤ 1 048 576 octets | `400 message` / `400 message_trop_gros` |
| fins de ligne CRLF seulement (LF nu ou CR nu refusé : anti « SMTP smuggling ») | `400 fin_de_ligne` |
| aucun `X-Envoi-*`, aucun `DKIM-Signature` ; `List-Unsubscribe` ET `List-Unsubscribe-Post: List-Unsubscribe=One-Click` | `422 conformite` |
| domaine de `From:` = parent de `bounce.<domaine>` du `mail_from` | `422 alignement` |

Plafond avant authentification : 1 500 000 octets pour ce seul chemin (2 × 65 536 ailleurs) ; au-delà, `401` non signé.

Idempotence : la passerelle réserve `(espace, id_injection)` AVANT d'appeler le moteur. Même id et même corps exact (empreinte
SHA-256) ⇒ réponse rejouée, un seul appel ; même id et autre corps ⇒ `409 id_injection_deja_utilise` ; un espace n'en voit jamais un
autre. Succès : `202 {"id_injection", "etat": "en_file"}`. Moteur injoignable avant acceptation : `503 indisponible`, réservation rendue
(rejouable). La table ne garde ni adresse ni message, et n'est jamais vidée. Le message n'est jamais journalisé ni rendu.
`POST /v1/commandes/injecter` reste `404`.

Traduction des réponses du moteur (C2-3, ADR K0 D7) :

| Moteur | Réponse | État gardé |
| --- | --- | --- |
| refus SMTP 4xx | `429` + `Retry-After: 900` + `{"code_smtp": 451}` | aucun (réservation rendue : rejouable) |
| refus SMTP 5xx | `422 {"erreur":"refus_definitif","code_smtp":550}` | `refuse` (rejoué, jamais ré-exécuté) |
| injoignable avant acceptation | `503 indisponible` | aucun (rejouable) |
| plus de 20 s (`Kumo::DELAI_MAX_SECONDES`) après l'envoi | `504 issue_incertaine` | `incertain` (rejoué, JAMAIS renvoyé) |

`code_smtp` est le code rendu par le moteur (451 et 550 ne sont que des exemples).

## `GET /v1/injections/{id}` — état d'une injection (C2-3)

Chaîne de requête vide (sinon `400 requete`) ; autre méthode : `405`. Réponse `200 {"id_injection", "etat"}` avec `etat` dans
`en_file | refuse | incertain | retire | inconnu`. Une ligne absente, une simple réservation ou l'identifiant d'un autre espace donnent
`inconnu` (jamais `404` : l'existence dans un autre espace n'est pas révélée). Lecture seule : aucune écriture, aucun appel au moteur.

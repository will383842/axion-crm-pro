# Contrat de synchro site → CRM Pro

Version du contrat : **2** (amendement PV-6, « Contenus et accords »).
La version 1 reste acceptée telle quelle. Le code de référence est
`backend/app/Crm/Ingest/SiteSyncEvent.php` (schéma strict) ; ce fichier en est la description lisible.

## Principes (inchangés)

- Le plus récent gagne (à la seconde près).
- Aucune suppression ne se propage : un champ absent n'efface rien, une liste plus courte ne retire rien.
- L'opposition gagne partout : la fiche est mise à l'écart de tout envoi, les données sont gardées.
- Un doublon possible va à la liste d'arbitrage existante.
- Le CRM décide de l'espace de destination ; le site ne l'envoie jamais.
- **Jamais** le pseudo de réseau ni le texte des messages : ces clés n'existent pas dans le contrat, donc toute
  tentative de les envoyer est rejetée (`unknown_field`).

## Ce qui change en version 2

Quatre champs facultatifs au premier niveau de la charge (clé de la fiche : l'adresse normalisée, `emailKey`,
portée par `person.person_key` et `person.email`) :

| Champ | Forme | Règles |
|---|---|---|
| `origine_contenu` | objet `{ mot, reseau, guide, date }` | `mot`, `reseau`, `guide` : minuscules, chiffres, `.` `_` `-`, 64 signes au plus ; `date` : ISO 8601 |
| `guides_recus` | liste (50 au plus) d'objets `{ guide, date }` | `guide` comme ci-dessus ; fusion par union, jamais de retrait |
| `consentements` | liste (50 au plus) d'objets `{ nature, date, source, version }` | `nature` ∈ `information`, `optin`, `retrait` ; `source` et `version` : 64 signes au plus, sans espace ni saut de ligne ; fusion par union |
| `opposition` | objet `{ actif, date }` | `actif` booléen ; `date` ISO 8601 facultative. `actif: true` met la fiche à l'écart ; `actif: false` n'annule jamais une opposition déjà reçue |

Tous les champs sont facultatifs et toutes les clés internes sont fermées.

## Tolérance dans les deux sens

- **Ancien site → nouveau CRM** : une charge en version 1, sans ces champs, passe sans changement.
- **Nouveau site → ancien CRM** : l'ancien CRM refuse la version 2 (`unsupported_schema_version`, 422) ;
  la ligne reste côté site. Le site n'émet donc ces champs en version 2 que quand le CRM l'a déployé.
  Le nouveau CRM accepte aussi ces champs dans une charge en version 1 (ils sont ignorés par un ancien
  CRM seulement s'il les tolère ; sinon rejet, et la ligne attend).
- Les champs ne sont lus que par la branche « personnes » (lettre et guide), derrière
  `CRM_INGEST_PERSONNES_ENABLED`. Pour les autres types d'événements ou drapeau fermé, ils sont validés puis ignorés.

## Exemples

Sans les nouveaux champs (version 1, valide) :

```json
{
  "schema_version": 1,
  "event_id": "00000000-0000-4000-8000-000000000001",
  "event_type": "lead_magnet_requested",
  "occurred_at": "2026-11-02T10:00:00.000Z",
  "subject_ref": "site:guide_request:exemple",
  "person": { "person_key": "<sha256 hex 64>", "email": "exemple@example.test" }
}
```

Avec les nouveaux champs (version 2) :

```json
{
  "schema_version": 2,
  "event_id": "00000000-0000-4000-8000-000000000002",
  "event_type": "lead_magnet_requested",
  "occurred_at": "2026-11-02T10:00:00.000Z",
  "subject_ref": "site:guide_request:exemple",
  "person": { "person_key": "<sha256 hex 64>", "email": "exemple@example.test" },
  "origine_contenu": { "mot": "guide-ia", "reseau": "reseau-a", "guide": "guide-ia", "date": "2026-11-02T09:58:00.000Z" },
  "guides_recus": [ { "guide": "guide-ia", "date": "2026-11-02T10:00:00.000Z" } ],
  "consentements": [
    { "nature": "information", "date": "2026-11-02T10:00:00.000Z", "source": "mot-guide-ia:reseau-a", "version": "M1" }
  ],
  "opposition": { "actif": false }
}
```

## Stockage côté CRM (additif)

Colonnes ajoutées à `personnes` : `origine_contenu`, `guides_recus`, `consentements` (jsonb),
`opposition_at`, `accords_at` (horodatages). Aucune suppression, aucune purge.

## Affichage

La fiche d'une personne montre un bloc « Contenus et accords » en lecture seule : d'où vient la personne,
quels guides, quels accords et versions de texte, et l'opposition en bandeau d'alerte. Texte échappé, rien d'autre que ces champs.

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
  Un ancien CRM REFUSE ces clés (`unknown_field`, HTTP 422), même dans une charge en version 1 :
  la ligne attend côté site. Le site n'envoie donc les 4 champs qu'une fois ce CRM déployé.
  Le nouveau CRM, lui, les accepte dans une charge en version 1 comme en version 2.
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
quels guides, les consentements d'information (« Information reçue », avec source et version du texte, ex. M1), l'accord
pour de futures campagnes (dernier choix, avec version du texte) et l'opposition en bandeau d'alerte. Texte échappé, rien d'autre que ces champs.

### Lien du guide (le contrat ne transporte pas d'URL)

Le contrat porte seulement l'**identifiant** public du guide (ex. `guide-ia`). Le CRM le traduit en lien à la lecture,
grâce à la table fermée `backend/config/guides.php` : aucune adresse ne vient d'une donnée reçue, rien n'est écrit en base,
et le lien personnel de l'e-mail (`?t=`) n'est jamais affiché ni stocké. Interrupteur `CRM_GUIDE_LIEN_ENABLED`, éteint par
défaut (allumer = déployer). Identifiant inconnu ou profil en lecture seule (sans `contacts.view_pii`) : pas de lien. Seul l'hôte `guides.hote_autorise`
(`CRM_GUIDE_HOTE`, par défaut `axion-ia.com`) est accepté ; l'hôte de test `exemple.invalid` ne l'est qu'en testing, local et
demo. Avant d'allumer l'interrupteur en production, poser la vraie adresse publique dans `config/guides.php`.
Le message « Aucun lien disponible pour ce guide » est propre à la ligne concernée.

## Version 3 — historique des origines (ADR-CONTRAT-V3-ORIGINE)

`schema_version` accepte 1, 2 et 3 (4 refusée). Tout le traitement v3 est derrière l'interrupteur
`ORIGINES_V3_ENABLED` (`config/origines.php`), **éteint par défaut** : éteint, une charge v3 reçoit un
**503** `origines_v3_disabled` (la ligne reste en attente côté site) ; v1 et v2 sont inchangées.

Champ ajouté (v3 seulement ; en v1/v2 c'est un champ inconnu, 422) : `origines`, liste de 20 entrées au plus
(`too_many_origines` au-delà). Clés fermées : `mot`, `reseau`, `guide`, `date` (requise), `post_ref`, `post_url`,
`canal` (`commentaire` | `message_prive`, ce dernier refusé pour LinkedIn et TikTok), `famille` + `detail`
(paire du dictionnaire `backend/resources/dictionnaire-origine.v1.json`, test d'empreinte), `compte`,
`version_texte`, `utm_campaign`, `commentaire_ref` (stocké en empreinte HMAC, jamais en clair). Toute autre clé
(pseudo, nom, texte…) est refusée `unknown_field`. `origine_contenu` reste « la plus récente gagne ».

Stockage (additif) : `personnes.origines` (jsonb) en **union sans retrait**, dédoublonnée sur
(`post_ref`, `canal`, `date`) — sans `post_ref` : (`mot`, `reseau`, `guide`, `date`). Au-delà de 50 entrées par
personne, la plus ancienne est **déplacée** (même transaction, verrou de ligne) vers `personne_origines_archive`
(ajout seul : ni UPDATE, ni DELETE, ni TRUNCATE pour le rôle applicatif). Rien n'est supprimé.

API des personnes (lecture seule, présente seulement si l'interrupteur est allumé) : `origine` (la plus
récente) et `origines_nombre` dans la liste ; `origines` (historique) dans la fiche ; filtres
`origine_famille` et `origine_detail` (422 si hors dictionnaire).

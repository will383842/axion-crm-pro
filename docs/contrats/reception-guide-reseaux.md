# Réception « guide réseaux » : mise en service

Fiche CRM-G5. Cette page décrit comment le CRM Pro reçoit les personnes qui demandent un guide depuis un réseau social
(contrat v3 de `docs/contrats/synchro-site-crm.md`). Aucun envoi d'e-mail n'est concerné ici.

## Ce que le CRM reçoit
- Un événement de la page du guide, repéré par `source_slug = "guide-reseau"`, et un événement d'origines dont le
  `subject_ref` commence par `site:guide-reseau:`. Les deux sont reconnus comme la même famille.
- Le contact est une « personne » (ni prospect ni client), avec le mot tapé, la date et le réseau. Son prénom le plus
  récent est retenu.
- Rien n'est supprimé : les origines s'ajoutent (union), l'excédent est déplacé dans une archive en ajout seul.

## Interrupteurs (tous éteints par défaut, valeurs sans secret dans `.env.example`)
| Variable | Rôle |
|---|---|
| `CRM_INGEST_ENABLED` | Interrupteur maître du canal site → CRM (éteint : 503 `ingest_disabled`, rien n'est écrit). |
| `CRM_INGEST_PERSONNES_ENABLED` | Ouvre la branche « personnes » (lettre et guide). |
| `ORIGINES_V3_ENABLED` | Ouvre le traitement v3 (tableau `origines`, archive, filtres). Éteint : 503 `origines_v3_disabled`. |
| `ORIGINES_LECTURE_ENABLED` | Ouvre l'API de lecture des origines. Il faut aussi la fonction d'espace `origines.lecture`. |
| `ORIGINES_MASQUAGE_COMMENTAIRE_REF_ENABLED` | Met à l'écart (sans supprimer) les références de commentaire anciennes. |

## Secrets (jamais dans un commit)
- `SITE_SYNC_HMAC_SECRET` : signature du canal site → CRM, posée dans le `.env` du serveur et côté site.
- `ORIGINES_HMAC_KEY` : empreinte des références de commentaire. Vide : repli sur `APP_KEY`.

## Ordre d'allumage
1. `CRM_INGEST_ENABLED` et `CRM_INGEST_PERSONNES_ENABLED`.
2. `ORIGINES_V3_ENABLED`.
3. Ensuite seulement, la synchro côté site.

Chaque étape est un déploiement (un `restart` ne relit pas l'environnement). Pendant que le CRM est éteint, le site garde
ses lignes en attente et les rejoue à l'allumage : rien n'est perdu. Retour arrière : éteindre l'interrupteur.

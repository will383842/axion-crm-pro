# G4-0 — Diagnostic du rôle applicatif (lecture seule)

Cahier : PR #672 (`docs/crm/lots/G3-G5-cahier.md`, section G4-0). Ce lot ne change rien en production : il constate.

## Comment le lancer
- Workflow : onglet Actions, « Diagnostic — rôle applicatif Postgres (prod, lecture seule) », « Run workflow » (`workflow_dispatch` seulement).
- Commande : `php artisan crm:role-applicatif:diagnostic`.

Le workflow lance la commande dans `api`, `horizon` et `scheduler` (chacun lit son propre environnement), lit le `.env` sans jamais afficher une valeur, puis liste les rôles réellement connectés à Postgres (session en lecture seule).

## Comment lire la sortie (`CLE=valeur`)
| Ligne | Sens |
|---|---|
| `DRAPEAU_CONFIG` | drapeau de bascule du rôle applicatif, vu par ce conteneur |
| `CONNEXION_DEFAUT_ROLE` | rôle Postgres sous lequel l'application parle |
| `CONNEXION_DEFAUT_SUPERUSER` / `_BYPASSRLS` | ce rôle est-il superutilisateur / contourne-t-il la RLS |
| `CONNEXION_DEFAUT_EST_PROPRIETAIRE` | possède-t-il la table `contacts` |
| `ROLE_APP_EXISTE` / `_LOGIN` / `_SUPERUSER` / `_BYPASSRLS` | état du rôle applicatif prévu |
| `ROLE_APP_CONNEXION` | `ok`, `refusee` (message jamais affiché) ou `non_configuree` (mot de passe vide, aucune tentative) |
| `TABLES_ESPACE_SANS_FORCE` | tables à `workspace_id` sans `FORCE ROW LEVEL SECURITY` |
| `VERDICT` | `role_applicatif_actif`, `superutilisateur_actif` ou `incoherent` |

Côté serveur : `ENV_FICHIER_DRAPEAU` (`true|false|absent|autre`), `ENV_FICHIER_MOT_DE_PASSE_APP` (`present|absent`), dates de démarrage et du `.env` (un `.env` plus récent que le conteneur : l'effectif peut différer du fichier).

Sortie 0 dans tous les cas lisibles ; 1 seulement si la base est injoignable.

## Retour arrière
Aucune écriture en production : annuler la PR.

## Geste du fondateur (ESCALADE 6)
Lancer le workflow une fois et coller la sortie dans la PR G4-A, qui ne s'allume pas sans elle.

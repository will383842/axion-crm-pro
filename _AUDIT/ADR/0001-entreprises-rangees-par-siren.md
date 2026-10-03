# ADR 0001 — Le CRM range les entreprises par SIREN

- **Statut** : acceptée
- **Date** : 2026-10-03
- **Décideur** : Williams (décision du 03/10/2026)

> Premier ADR du dépôt. `spec/00_INDEX.md` prévoit que les propositions
> d'ADR se remontent dans `_AUDIT/` : ce dossier `_AUDIT/ADR/` les recueille,
> numérotées à la suite.

## Contexte

Une entreprise française a un SIREN (9 chiffres) et autant de SIRET
(14 chiffres = SIREN + NIC) qu'elle a d'établissements : le siège, chaque
agence. Les sources du CRM (INSEE, fédérations, organisateurs d'événements,
saisie manuelle) déclarent tantôt l'un, tantôt l'autre. Une même entreprise
peut ainsi arriver par le numéro de son agence.

## Décision

1. **Le CRM range les entreprises par SIREN** : une fiche `companies` par
   SIREN, celle du siège.
2. **Le SIRET est ramené aux 9 premiers chiffres** partout où il est saisi
   pour chercher : le sélecteur « Entreprise » (`GET /crm/entreprises/choix`,
   `ChoixEntrepriseController::parNumero`) et la palette Ctrl+K
   (`GET /search`, `GlobalSearchController::chercherEntreprises`). Le SIRET
   d'une agence retrouve donc la fiche du siège ; un SIRET dont le SIREN est
   inconnu ne rend rien.
3. **Pas d'index sur `companies.siret`** : aucune recherche ne porte sur
   cette colonne, qui reste une information affichée.
4. **Pas de table d'établissements** : une entreprise déclarée par son agence
   est rangée sous son siège. Le numéro de l'agence pourra être gardé en note
   plus tard, sans nouvelle structure.

## Conséquences

- Une seule fiche par entreprise : pas de doublon siège / agence à
  rapprocher, ni de campagne envoyée deux fois à la même entreprise.
- On ne sait pas, dans le CRM, distinguer deux agences d'une même entreprise
  (adresse, contact local) : c'est accepté.
- Si un besoin d'établissements apparaît un jour, il fera l'objet d'un
  nouvel ADR (table dédiée, index sur `siret`) — pas d'un ajout « au passage ».

## Preuve

`backend/tests/Feature/Crm/RechercheParSirenSeulementTest.php` : SIRET d'un
établissement secondaire → fiche du siège ; SIRET inconnu → aucun résultat,
pour le sélecteur et pour la palette.

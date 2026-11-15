# G4-API — Statut d'opposition lisible par le CRM

Plan : `docs/envoi/PLAN-FIN-ENVOI.md` (branche `plan/fin-envoi`), décision D14, vague 1. `ENVOI_REEL_ACTIF=false` : inchangé, aucun envoi.

## Fait
- `App\Crm\Opposition\StatutOpposition::pourLignes($espace, $lignes)` : lecture seule, par lots. Sortie par ligne
  `opposition: {opposee: bool, motifs: ['stop'|'art21'|'liste_refus']}`. Ni empreinte, ni date, ni contenu de registre, ni adresse.
- Sources (une requête chacune pour toute la page, 5 au total, quel que soit le nombre de lignes) :
  - `envoi_oppositions` (STOP : page, un clic), de l'espace → `stop` ;
  - `opt_out` (portée business) : raison `gdpr_art21` → `art21`, autre raison → `stop` ;
  - `rgpd_requests` de l'espace, type `opposition`, ni refusée ni expirée → `art21` (table constatée dans `RgpdRequestsController::executerOpposition`, qui écrit aussi `opt_out`) ;
  - `email_suppressions` (portée business) → `liste_refus` ;
  - `envoi_oppositions_registre` (R13) de l'espace : adresse, domaine **et sous-domaines**, SIREN → `liste_refus`.
- Exposé dans la liste et la fiche des personnes (`PersonnesController`) et dans la liste des contacts (`ContactsController`, `company` charge aussi `siren`).
  Le statut est calculé sur l'adresse en clair **avant** le masquage des coordonnées.

## Garanties
- Aucune écriture (test : compteur d'écritures = 0), aucun événement site-sync / `crm_outbound_events` émis. Anti-boucle intacte : rien n'est touché pour `site-sync:*`, `gdpr_erasure_bisystem`, `metadata.origin=site-sync-gdpr`.
- Cloisonnement : `workspace_id` explicite sur les sources propres à l'espace. `opt_out` et `email_suppressions` sont globales par conception (l'opposition suit l'adresse).
- Aucune suppression de données de personnes : une opposition met à l'écart.

## Reste (hors lot)
- Badge « opposée » dans les écrans (G4-UI) ; exclusion d'envoi et 422 `adresse_opposee` (G4-ENVOI).
- La fiche contact (`ContactsController::show`) n'est pas dans la fiche du plan : non modifiée.

## Risques
- Une demande d'opposition RGPD encore `pending` compte déjà comme opposée (choix prudent : mise à l'écart).
- `rgpd_requests.subject_email` est comparé en minuscules (colonne CITEXT).

## Tests lancés
`vendor/bin/pest tests/Feature/Crm/StatutOppositionTest.php` (9 tests, vert) ; `PersonnesConsoleTest`, `ContactsIndexTest`, `CrmOutboundTest` (verts) ; `pint --dirty` ; PHPStan sur les 3 fichiers PHP.
Rouge d'abord : les 9 tests échouaient (`Class "App\Crm\Opposition\StatutOpposition" not found`) avant le code.

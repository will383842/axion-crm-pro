
## Correctifs de l'audit
- Mode `enrichSansPayant` inchangé : il garde les scrapes Node (orchestrateur) et Pages Jaunes (`DomainFinderService`, paramètre `$garderAnnuaires`) ; `EnrichirSiteOfficineJob` garde son comportement d'avant.
- Nouveau test : par défaut, l'auto-segment tourne (doublure d'`AudienceBuilderService` qui compte les appels).
- `PharmaciesEnrichirOrchestrateurTest` : `payant_actif` n'est forcé à vrai que hors mode sans payant.
- `journalists:scrape-ours` sort aussi quand `payant_actif` est fermé, avec un test dédié.
- `crm:federations:trouver-sites` sort proprement (code 0, message clair) quand `payant_actif` est fermé, avec un test ; son test existant ouvre le drapeau.
- Les deux doublures de `findAvecMethode` reprennent le 3e paramètre `$garderAnnuaires` (erreur PHP « Declaration must be compatible » corrigée).
- `journalists:scrape-ours` : deux `if` séparés, chacun avec son message (`MEDIA_JOURNALISTS_ENABLED`, `CRM_ENRICHISSEMENT_PAYANT_ACTIF`).
- Commentaire de `config/crm.php` précisé : enrich() saute Places, Hunter, LLM et Brave ; INSEE, triage et auto-segment inchangés.

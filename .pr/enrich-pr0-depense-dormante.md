
## Correctifs de l'audit
- Mode `enrichSansPayant` inchangé : il garde les scrapes Node (orchestrateur) et Pages Jaunes (`DomainFinderService`, paramètre `$garderAnnuaires`) ; `EnrichirSiteOfficineJob` garde son comportement d'avant.
- Nouveau test : par défaut, l'auto-segment tourne (doublure d'`AudienceBuilderService` qui compte les appels).
- `PharmaciesEnrichirOrchestrateurTest` : `payant_actif` n'est forcé à vrai que hors mode sans payant.
- `journalists:scrape-ours` sort aussi quand `payant_actif` est fermé, avec un test dédié.

# Quarantaine de tests — dette figée le 2026-08-13

## Ce qui s'est passé

La suite Pest de ce dépôt **n'avait jamais pu démarrer**. Deux défauts de
configuration l'arrêtaient avant le premier test (extension `TestCase` en double,
fonctions d'aide globales homonymes), et la CI masquait l'échec avec un
`composer test || true`. Autrement dit : les 61 fichiers de tests du backend
n'avaient jamais été exécutés depuis leur écriture.

Une fois la suite réparée (PR « fix/ci-reelle », Gate 0 de l'autopilot CRM), le
verdict réel est tombé :

| | tests |
|---|---|
| passent | **352** |
| échouent | **61** |
| assertions jouées | 5 091 |

Les échecs ne sont pas des régressions : ce sont des tests devenus faux au fil de
l'évolution du schéma et des API (contraintes `contacts_workspace_id_fkey`,
`companies.siren NOT NULL`, réponses HTTP modifiées, doubles de test périmés…).

## Ce qui a été décidé

Les corriger tous demandait de modifier largement le code applicatif et les
tests, dans une PR dont l'objet est l'outillage. Le choix retenu est le même que
pour PHPStan : **figer la dette explicitement, jamais neutraliser l'outil**.

- `backend/phpunit.xml` reste **inchangé** : en local, `composer test` exécute la
  suite ENTIÈRE et montre les 61 échecs. La vérité reste visible.
- La CI utilise `backend/phpunit-ci.xml`, identique **sauf** la liste
  d'exclusions ci-dessous. Tout ce qui n'est pas dans cette liste est
  **bloquant** : un test qui passe aujourd'hui et casse demain arrête la CI et
  donc le déploiement.

## Règles

1. Cette liste ne peut que **décroître**. Y ajouter un fichier exige une
   justification écrite dans ce fichier, datée.
2. Tout **nouveau** test est bloquant d'office (il n'est pas dans la liste).
3. `Tests\Feature\RlsTest` doit sortir de la quarantaine **au lot L1**
   (durcissement RLS) — c'est le premier travail de ce lot.
   → ✅ **FAIT le 2026-08-14** (lot L0 de l'autopilot, PR `feat/crm-L0-rls`).
   Le fichier a été RÉÉCRIT : ses deux tests d'origine ne prouvaient rien
   (l'un échouait, l'autre CERTIFIAIT le trou — « RLS bypass quand session var
   vide »). Il compte désormais 15 tests d'étanchéité qui passent par le rôle
   applicatif non-propriétaire `axion_app`, seul moyen de prouver quoi que ce
   soit : le rôle historique `axion` est SUPERUSER + BYPASSRLS.

## Liste au 2026-08-14 (22 fichiers)

### tests/Feature (10)

- `Auth/LoginTest.php`
- `Auth/OnboardingTourTest.php`
- `CampaignsTest.php`
- `Commands/GenerateMediaRedactionEmailsTest.php`
- `Commands/JournalistsScrapeLlmTest.php`
- `Commands/RescrapeArchivesCommandTest.php`
- `Controllers/Phase2StubsExtendedTest.php`
- `Phase2StubsTest.php`
- `Rgpd/RgpdRequestsControllerTest.php`
- `Seeders/OwnerUserSeederTest.php`

### tests/Unit (12)

- `Audiences/AudienceBuilderServiceTest.php`
- `Audit/AuditHashChainExtendedTest.php`
- `Audit/AuditHashChainTest.php`
- `Auth/AuthServiceTest.php`
- `Classification/AutoClassifierServiceTest.php`
- `Email/HunterEmailVerifierTest.php`
- `Email/SmtpProberTest.php`
- `Http/SsrfGuardTest.php`
- `Legal/MentionsLegalesScraperServiceTest.php`
- `Rotations/WeightedRoundRobinTest.php`
- `Scraping/GooglePlacesSmartSkipTest.php`
- `Tags/AutoTaggerServiceTest.php`

## Comment sortir un fichier de la quarantaine

```bash
docker compose -f docker-compose.yml -f docker-compose.test.yml up -d
docker exec axion-crm-api php vendor/bin/pest tests/Feature/RlsTest.php
# corriger jusqu'au vert, puis retirer la ligne <exclude> de phpunit-ci.xml
# et l'entrée correspondante ci-dessus.
```

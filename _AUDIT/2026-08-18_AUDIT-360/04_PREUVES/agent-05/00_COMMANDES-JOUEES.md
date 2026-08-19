# Agent 5 — commandes jouées (toutes, dans l'ordre)

Référence : dépôt CRM `main = b53338c` ; `git diff --stat c0c453d HEAD` = 1 fichier `.md`
→ **le code applicatif est identique à `c0c453d`**.

## Référence
    git log --oneline -1                      # b53338c
    git log --oneline c0c453d..HEAD           # 2 commits, docs CNIL
    git diff --stat c0c453d HEAD              # 1 fichier .md, +20/-1

## PR fusionnées (lots)
    gh pr list --state merged --limit 200 --json number,title
    gh pr view {53..65} --json number,state,mergeCommit,title
    # #53..#65 : TOUTES MERGED

## Gate 0 — CI réellement bloquante
    grep -n "continue-on-error|\|\| true" .github/workflows/ci.yml
    grep -n "needs:|migrate --force|migrate:status" .github/workflows/deploy-direct-ssh.yml
    cat backend/phpunit-ci.xml                 # aucune exclusion
    gh run list --workflow=ci.yml --limit 8
    gh run view 32233358457 --log | grep -E "Tests:|No errors"
      -> [OK] No errors
      -> Tests: 780 passed (6503 assertions) / Duration: 26.02s
    # sortie : ci-run-pest.txt

## Fichiers livrables des lots
    ls backend/database/migrations/            # 000001..000007 du 2026_08_14 presentes
    ls backend/app/Crm/Taxonomy.php backend/app/Support/WorkspaceContext.php \
       backend/app/Models/Candidate.php backend/database/seeders/GovernedTagsSeeder.php \
       backend/tests/Feature/RlsTest.php
    grep -n "internal" backend/routes/api.php  # site-sync, site-sync/gdpr, zeptomail
    grep -n "path:" frontend/src/app/routeTree.tsx
    sed -n '55,160p' frontend/src/components/layout/Sidebar.tsx

## Colonne morte first_info_at
    Grep "first_info_at|firstInfoAt" (tout le depot) -> 6 hits, TOUS dans la migration
    Grep "consent_text_ref" (temoin negatif)         -> 5 fichiers applicatifs
    # sorties : first_info_at_companies.txt, first_info_at_contacts.txt

## Cycle de vie
    grep -rn "dormant" backend/app backend/routes/console.php   # 3 hits, aucun ecrivain
    grep -rn "lifecycle_stage" backend/app                      # 13 fichiers, 2 ecrivains

## Playwright
    ls frontend/tests/e2e/*.spec.ts                                    # 16
    grep -rho "tests/e2e/[a-z0-9-]*\.spec\.ts" .github/workflows/      # 2
    # sortie : e2e-specs-non-executees.txt

## Atelier local
    docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT ... FROM contacts;"
    docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc "SHOW TimeZone;"   # Etc/UTC
    # sorties : contacts_colonnes.txt, tables-lots.txt, horodatage-sonde.txt

## Production — LECTURE SEULE UNIQUEMENT (aucune ecriture, aucune mutation)
    ssh root@46.62.248.239 "docker inspect axion-crm-api --format '{{range .Config.Env}}...'"
      -> les 9 drapeaux CRM_* = true, DB_TIMEZONE=Europe/Paris, APP_ENV=production
    ssh root@46.62.248.239 "docker exec axion-crm-postgres psql -U axion -d axion_crm -c 'SELECT ...'"
      -> candidates=0, candidate_tag=0, crm_outbound_events=0, opt_out=0, scraping_sources=17
      -> contacts=1319567, avec_email=410481, avec_person_key=0, avec_external_ref=0
      -> companies avec relation_type<>'prospect' = 0 ; lifecycle_stage : nouveau=4295349
      -> activities site:event:% = 3, toutes form_submission, 3/3 en pending_match
      -> tags=217 (60 verrouilles, 38 ns 'src', 14 categorie 'candidate')
      -> company_tag=7501969 dont assigned_by='backfill-src' = 4294895
    # sortie : prod-volumes-lots.txt

## NON JOUE, et pourquoi
    docker exec axion-crm-api php artisan test --configuration=phpunit-ci.xml
      -> ABANDONNE : `ps aux` dans le conteneur montre >20 processus concurrents d'autres
         agents de cet audit, dont `migrate:fresh --force` et `php /tmp/seed.php` qui
         recreent la base pendant la suite. Mesure non deterministe = mesure non prise.
         Remplacee par le run CI 32233358457 (base neuve, isolee).

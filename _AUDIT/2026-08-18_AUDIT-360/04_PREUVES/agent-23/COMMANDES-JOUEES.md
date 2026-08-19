# Agent 23 — commandes jouées, 2026-08-19, référence main = e8924b8

## Référence
    git log --oneline -3                       # e8924b8 (et non c0c453d)
    git log -1 --format='%H %ci %s' da97826    # 2026-08-18 17:39 — refonte de la barre

## D23-001 — l'atelier sert une barre périmée
    docker inspect axion-crm-app --format '{{.Image}}'
    docker image inspect <sha> --format 'imageCreated={{.Created}}'   # 2026-08-17T07:12:54Z
    docker exec axion-crm-app grep -c 'Runs de scraping'   /srv/app/dist/assets/index-DPQz8SpC.js   # 2
    docker exec axion-crm-app grep -c 'Journaux de collecte' /srv/app/dist/assets/index-DPQz8SpC.js # 0
    docker exec axion-crm-app grep -c 'Phase 2'           /srv/app/dist/assets/index-DPQz8SpC.js   # 2
    -> barre-v2.json     (bundle servi   : 10 sections, 28 entrées)
    -> cible-v2.json     (code de main   :  6 sections, 22 entrées)

## D23-003 — « Contacts » liste des entreprises
    docker exec axion-crm-postgres psql -U axion -d axion_crm -c "\d contacts"
    # company_id | bigint | not null

## Sondes navigateur (Playwright, ignoreHTTPSErrors, auth/me + config/features mockés)
Serveur : `pnpm exec vite --port 5199 --strictPort` dans `frontend/` (code de main).
Aucun fichier produit n'a été modifié.

    node sondes/nav-audit.mjs  <dossier> v1|v2   -> cible-v{1,2}.json, cible-sections-*.json
    node sondes/tour-probe.mjs <dossier>         -> visite-guidee-code-actuel.json + visite-etape-*.png
    TEMOIN=1 node sondes/tour-probe.mjs <d>      -> TÉMOIN NÉGATIF : 7 étapes sur 7
    node sondes/tour-blocage.mjs <dossier>       -> visite-blocage.json (pas de blocage : honnêteté)
    node sondes/recherche.mjs   <dossier>        -> recherche-vers-fiche.json (⌘K -> /contacts)
    node sondes/fil.mjs         <dossier>        -> fil-ariane-par-route.json (30 routes)

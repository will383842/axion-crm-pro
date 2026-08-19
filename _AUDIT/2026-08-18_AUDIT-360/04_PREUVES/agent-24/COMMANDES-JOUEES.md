# AGENT 24 — commandes jouées, 2026-08-19

## Référence
    git log --oneline -1                                   # 6c90194 (main a avancé pendant la mission)
    git diff --stat e8924b8..HEAD -- backend frontend infra
    # -> 1 fichier : infra/scripts/verifier-serveur-http.sh (+154).
    #    AUCUNE ligne de backend/ ni de frontend/ n'a bougé : les mesures
    #    valent pour e8924b8 ET pour 6c90194.

## Base
    docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc \
      "select count(*) from migrations"        # 58 (avant et après)
    docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc \
      "select count(*) from audit_logs"        # 34   <-- D24-001
    docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc \
      "select table_name from information_schema.tables where table_schema='public'"
      # -> preuve que crm_activites, crm_motifs, crm_tasks, crm_notes,
      #    crm_pipelines, deals, pipeline_stages, email_messages EXISTENT ;
      #    et que appointments, quotes, invoices, questionnaires, recordings,
      #    transcriptions, documents, opportunities N'EXISTENT PAS.

## Lecture de code (témoins négatifs inclus)
    grep -rn "console/personnes" frontend/src           # 4 occurrences dont 2 <Link> conditionnés à person_key !== null
    grep -rn "<ErrorBoundary" frontend/src              # 0 occurrence  <-- D24-008
    grep -rn "notifications" frontend/src --include=*.tsx | grep -v echo.ts   # 2, tous commentaires
    grep -rn "motif|rappel|devis|visio|questionnaire|entretien|compte.rendu|rendez-vous" -i frontend/src
    grep -rn "motif_id|activite_id" backend/database/migrations   # 0  <-- D24-007
    grep -rn "ALTER TABLE activities" backend/database/migrations/2026_08_19_000002_*  # 0

## Sondes navigateur (Playwright — méthode de l'agent 23, reprise, pas réinventée)
Serveur : `frontend/node_modules/.bin/vite --port 5224 --strictPort --host 127.0.0.1`
sur le code de `main`. Auth + drapeaux mockés (la console réelle est murée par
A07-001 / D22-001). **Aucun fichier du produit n'a été modifié.**

    node sondes/accueil-plante.mjs <dossier>   -> accueil-plante.json + 3 captures  (D24-001 + TÉMOIN)
    node sondes/recon.mjs          <dossier>   -> recon-ecrans.json   (32 routes : coquille, liens, boutons, culs-de-sac)
    node sondes/fiche360.mjs       <dossier>   -> fiche360.json       (la fiche 360° avec une person_key)
    node sondes/parcours.mjs       <dossier>   -> parcours-mesures.json (21 parcours, clics comptés) + parcours-appels-api.txt
    node sondes/arrets.mjs         <dossier>   -> arrets.json         (bouton absent ou bouton DÉSACTIVÉ ?)
    node sondes/debug.mjs / recon2.mjs         -> mise au point des doublures

## Ménage
Serveur vite 5224 arrêté. Aucun conteneur créé. Aucune écriture en production.
Le worktree `crmpro-wt-etape1a` n'a jamais été lu ni touché.

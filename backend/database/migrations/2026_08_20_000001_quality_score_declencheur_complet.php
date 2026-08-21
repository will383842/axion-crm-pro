<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 C21-004 (S1) — 82,58 % des `companies.quality_score` contredisent la
 * formule qui est censée les produire (3 546 986 sur 4 295 349 en production le
 * 2026-08-19 ; 3 484 663 SOUS-évalués).
 *
 * ## Les deux causes, mesurées séparément
 *
 * **1. Personne n'écoute l'`INSERT`.** `companies_recompute_score` était
 * `AFTER UPDATE OF website, phone, linkedin_url, signals`. Une fiche créée par
 * import garde donc le `DEFAULT 0` de la colonne tant qu'aucune de ces quatre
 * colonnes n'est modifiée — et les deux chemins d'ingestion du dépôt
 * (`ScrapedRecordIngestService::…`, `SiteSyncIngestService::…`) écrivent
 * explicitement `'quality_score' => 0` à l'insertion. C'est le cas dominant en
 * production : 3 083 493 fiches stockées à `0` que la formule note `10`, et
 * 377 571 stockées à `0` qu'elle note `15`.
 *
 * **2. La formule a grossi, la liste du déclencheur non.** La migration
 * `2026_07_09_000002_quality_score_terrain` a réécrit
 * `recompute_company_quality_score()` et lui a ajouté cinq entrées —
 * `email_generic` (+15), `address` (+10), `lat`/`lon` (+10), `enseigne` (+5) —
 * sans toucher au `UPDATE OF …` du déclencheur, resté celui de
 * `2026_05_16_000009`. Renseigner l'adresse ou la géolocalisation d'une fiche
 * changeait donc son score théorique sans que quoi que ce soit le recalcule.
 * Mesuré au banc : 4 des 8 entrées de la formule ne déclenchaient rien.
 *
 * ## Le correctif
 *
 * **a) Une seule formule, à un seul endroit.** La cause 2 est un défaut de
 * DUPLICATION : la connaissance « voici les colonnes qui font le score » vivait
 * en deux exemplaires (le corps de la fonction, la liste du déclencheur) et les
 * deux ont divergé. On extrait donc le barème dans
 * `company_quality_score_calcul(companies) RETURNS integer`, que la fonction de
 * recalcul ET le déclencheur appellent tous les deux. La liste `UPDATE OF …`
 * reste un deuxième exemplaire — c'est une contrainte de PostgreSQL — mais la
 * garde `tests/Feature/Crm/QualityScoreConformeALaFormuleTest.php` la compare
 * désormais à la formule, colonne par colonne : une prochaine retouche du
 * barème qui oublierait le déclencheur rougira.
 *
 * **b) Le déclencheur passe en `BEFORE INSERT OR UPDATE OF <les 9 colonnes>`.**
 * `BEFORE` plutôt qu'`AFTER` n'est pas un détail de style : un `AFTER` doit
 * ré-`UPDATE`r la ligne pour poser le score, ce qui crée une deuxième version
 * de la ligne et réécrit les index à chaque insertion. La table porte 1 491 Mo
 * d'index (B10-014). En `BEFORE`, on pose `NEW.quality_score` avant l'écriture :
 * une seule version de ligne, aucun coût d'index supplémentaire.
 *
 * ⚠️ **Pas de récursion.** `recompute_company_quality_score()` finit par
 * `UPDATE companies SET quality_score = …`. `quality_score` n'est PAS dans la
 * liste `UPDATE OF` du déclencheur, et ne doit jamais y entrer : c'est ce qui
 * empêche la boucle. C'est aussi ce qui laisse la garde « TEMOIN — la
 * comparaison SAIT voir une divergence » capable de désaligner le stock à la
 * main.
 *
 * ## ⚠️ Ce que ce correctif CHANGE pour l'appelant
 *
 * `quality_score` devient une colonne **dérivée** : la valeur passée à
 * l'`INSERT` est désormais ignorée et remplacée par celle du barème. C'était
 * déjà l'intention (`quality_badge` en dérive, la colonne est indexée pour le
 * tri par qualité, et aucun chemin applicatif n'y écrit de valeur choisie —
 * seulement `0`), mais ce n'était pas ce que la base FAISAIT. Un appelant qui
 * voudrait imposer un score arbitraire ne le peut plus. Le seul écrivain de ce
 * genre dans le dépôt est `Database\Seeders\DemoCompaniesSeeder`
 * (`random_int(40, 95)`), dont les fiches de démonstration porteront désormais
 * le score que leurs données méritent.
 *
 * ## Le stock existant n'est PAS repris ici
 *
 * Une migration qui ferait `UPDATE companies SET quality_score = …` sur
 * 4,3 M de lignes prendrait un verrou sur chacune, ferait enfler le WAL et
 * réécrirait les 1 491 Mo d'index en une seule transaction. La reprise est donc
 * une commande séparée, par lots, rejouable et avec essai à blanc :
 * `php artisan crm:recalculer-quality-score --simulation`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- ── Le barème, à un seul endroit ────────────────────────────────
            -- STABLE et non VOLATILE : la fonction lit (companies via son
            -- argument, contacts), elle n'écrit pas.
            CREATE OR REPLACE FUNCTION company_quality_score_calcul(c companies) RETURNS integer AS $$
              SELECT LEAST(100,
                  (CASE WHEN c.email_generic IS NOT NULL AND c.email_generic <> '' THEN 15 ELSE 0 END)
                + (CASE WHEN c.website IS NOT NULL THEN 15 ELSE 0 END)
                + (CASE WHEN c.phone IS NOT NULL THEN 10 ELSE 0 END)
                + (CASE WHEN c.linkedin_url IS NOT NULL THEN 10 ELSE 0 END)
                + (CASE WHEN c.address IS NOT NULL AND c.address <> '' THEN 10 ELSE 0 END)
                + (CASE WHEN c.lat IS NOT NULL AND c.lon IS NOT NULL THEN 10 ELSE 0 END)
                + (CASE WHEN c.enseigne IS NOT NULL AND c.enseigne <> '' THEN 5 ELSE 0 END)
                + (CASE WHEN c.signals IS NOT NULL
                          AND jsonb_array_length(coalesce(c.signals->'recent', '[]'::jsonb)) > 0
                        THEN 5 ELSE 0 END)
                -- Contact joignable — les e-mails des mentions légales n'ont
                -- pas de score, on ne l'exige donc pas (cf. 2026_07_09_000002).
                + (CASE WHEN EXISTS (
                      SELECT 1 FROM contacts ct
                      WHERE ct.company_id = c.id
                        AND ct.email_status IN ('valid', 'catchall', 'unknown', 'role')
                    ) THEN 20 ELSE 0 END)
              );
            $$ LANGUAGE sql STABLE;

            -- ⚠️ `search_path` fixé, comme les 7 autres fonctions du projet
            -- (2026_08_16_200000) : sans lui, `pg_dump` écrit un `CREATE
            -- FUNCTION` que la restauration ne sait pas résoudre — c'est ce qui
            -- rendait la sauvegarde de production irrécupérable.
            ALTER FUNCTION public.company_quality_score_calcul(c public.companies)
                SET search_path = public, pg_catalog;

            -- ── La fonction publique : signature et contrat inchangés ────────
            CREATE OR REPLACE FUNCTION recompute_company_quality_score(c_id BIGINT) RETURNS INT AS $$
            DECLARE
              score INT;
            BEGIN
              SELECT company_quality_score_calcul(c) INTO score
              FROM companies c
              WHERE c.id = c_id;

              -- Identifiant inconnu : `score` vaut NULL, l'UPDATE ne touche
              -- aucune ligne et la fonction rend NULL — comme avant.
              UPDATE companies SET quality_score = score WHERE id = c_id;
              RETURN score;
            END;
            $$ LANGUAGE plpgsql;

            ALTER FUNCTION public.recompute_company_quality_score(c_id bigint)
                SET search_path = public, pg_catalog;

            -- ── Le déclencheur : INSERT compris, 9 colonnes écoutées ─────────
            CREATE OR REPLACE FUNCTION trg_company_recompute_score() RETURNS TRIGGER AS $$
            BEGIN
              IF TG_OP = 'INSERT' THEN
                NEW.quality_score := company_quality_score_calcul(NEW);
                RETURN NEW;
              END IF;

              -- Sur UPDATE, `UPDATE OF …` se déclenche dès que la colonne est
              -- CITÉE, même à valeur égale. On ne recalcule que si la valeur a
              -- réellement bougé.
              IF NEW.email_generic IS DISTINCT FROM OLD.email_generic
                 OR NEW.website      IS DISTINCT FROM OLD.website
                 OR NEW.phone        IS DISTINCT FROM OLD.phone
                 OR NEW.linkedin_url IS DISTINCT FROM OLD.linkedin_url
                 OR NEW.address      IS DISTINCT FROM OLD.address
                 OR NEW.lat          IS DISTINCT FROM OLD.lat
                 OR NEW.lon          IS DISTINCT FROM OLD.lon
                 OR NEW.enseigne     IS DISTINCT FROM OLD.enseigne
                 OR NEW.signals      IS DISTINCT FROM OLD.signals THEN
                NEW.quality_score := company_quality_score_calcul(NEW);
              END IF;

              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER FUNCTION public.trg_company_recompute_score()
                SET search_path = public, pg_catalog;

            DROP TRIGGER IF EXISTS companies_recompute_score ON companies;

            CREATE TRIGGER companies_recompute_score
              BEFORE INSERT OR UPDATE OF
                email_generic, website, phone, linkedin_url,
                address, lat, lon, enseigne, signals
              ON companies
              FOR EACH ROW EXECUTE FUNCTION trg_company_recompute_score();
        SQL);
    }

    public function down(): void
    {
        // Restaure l'état antérieur : fonction de 2026_07_09_000002, déclencheur
        // AFTER UPDATE de 2026_05_16_000009, `search_path` de 2026_08_16_200000.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recompute_company_quality_score(c_id BIGINT) RETURNS INT AS $$
            DECLARE
              score INT := 0;
              row_count INT;
            BEGIN
              SELECT
                (CASE WHEN c.email_generic IS NOT NULL AND c.email_generic <> '' THEN 15 ELSE 0 END)
                + (CASE WHEN c.website IS NOT NULL THEN 15 ELSE 0 END)
                + (CASE WHEN c.phone IS NOT NULL THEN 10 ELSE 0 END)
                + (CASE WHEN c.linkedin_url IS NOT NULL THEN 10 ELSE 0 END)
                + (CASE WHEN c.address IS NOT NULL AND c.address <> '' THEN 10 ELSE 0 END)
                + (CASE WHEN c.lat IS NOT NULL AND c.lon IS NOT NULL THEN 10 ELSE 0 END)
                + (CASE WHEN c.enseigne IS NOT NULL AND c.enseigne <> '' THEN 5 ELSE 0 END)
                + (CASE WHEN c.signals IS NOT NULL AND jsonb_array_length(coalesce(c.signals->'recent', '[]'::jsonb)) > 0 THEN 5 ELSE 0 END)
              INTO score
              FROM companies c
              WHERE c.id = c_id;

              SELECT count(*) INTO row_count
              FROM contacts ct
              WHERE ct.company_id = c_id
                AND ct.email_status IN ('valid', 'catchall', 'unknown', 'role');
              IF row_count > 0 THEN score := score + 20; END IF;

              IF score > 100 THEN score := 100; END IF;

              UPDATE companies SET quality_score = score WHERE id = c_id;
              RETURN score;
            END;
            $$ LANGUAGE plpgsql;

            ALTER FUNCTION public.recompute_company_quality_score(c_id bigint)
                SET search_path = public, pg_catalog;

            CREATE OR REPLACE FUNCTION trg_company_recompute_score() RETURNS TRIGGER AS $$
            BEGIN
              IF NEW.website IS DISTINCT FROM OLD.website
                 OR NEW.phone IS DISTINCT FROM OLD.phone
                 OR NEW.linkedin_url IS DISTINCT FROM OLD.linkedin_url
                 OR NEW.signals IS DISTINCT FROM OLD.signals THEN
                PERFORM recompute_company_quality_score(NEW.id);
              END IF;
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER FUNCTION public.trg_company_recompute_score()
                SET search_path = public, pg_catalog;

            DROP TRIGGER IF EXISTS companies_recompute_score ON companies;

            CREATE TRIGGER companies_recompute_score
              AFTER UPDATE OF website, phone, linkedin_url, signals ON companies
              FOR EACH ROW EXECUTE FUNCTION trg_company_recompute_score();

            DROP FUNCTION IF EXISTS company_quality_score_calcul(companies);
        SQL);
    }
};

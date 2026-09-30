<?php

use App\Crm\Taxonomy;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LA PRESSE SUIT LE MÊME MODÈLE QUE TOUS LES AUTRES CONTACTS (2026-09-30).
 *
 * Demande de Will : « harmoniser l'ensemble des contacts ». Les médias
 * (`media`) et les journalistes (`journalists`) vivaient à part : le moteur de
 * campagnes, qui vise des FICHES (`companies`) et leurs CONTACTS, ne pouvait
 * pas les voir. `crm:presse:harmoniser` rattache chaque média à une fiche
 * (nature `media`, relation `presse_media`) et chaque journaliste à un contact
 * de cette fiche ; `crm:presse:importer` fait entrer les listes de diffusion
 * presse par le même chemin. Cette migration prépare le terrain, et elle est
 * PUREMENT ADDITIVE — ordre permanent de Will : rien n'est supprimé.
 *
 *  1. La source `presse-2026` entre au REGISTRE (`scraping_sources`) : le
 *     funnel d'ingestion refuse une source inconnue, et les seeders ne
 *     tournent pas au déploiement.
 *
 *  2. Son tag de provenance `src:scraping-presse-2026` devient PROTÉGÉ
 *     (`FichesProtegees::TAGS`) : le déclencheur de la base, dont la liste est
 *     FIGÉE, est réinstallé avec les quatre tags (`FichesProtegeesTest` lit la
 *     fonction installée).
 *
 *  3. Le registre des retraits (`contacts_retires`) connaît aussi les
 *     personnes de la presse : les deux déclencheurs qui l'alimentent
 *     (suppression d'une personne ; suppression d'une fiche, avant la
 *     cascade) retiennent désormais les contacts dont `sources` cite
 *     `federations-2026` OU `presse-2026`. Une personne de la presse retirée ne
 *     revient jamais par un nouvel import. Corps repris À L'IDENTIQUE de
 *     `2026_09_30_000001`, seule la condition de source s'élargit.
 *
 *  4. Le LIEN, sans rien déplacer ni supprimer :
 *     - `journalists.contact_id` : le contact qui porte désormais ce
 *       journaliste. `journalists` RESTE la table source de l'écran « Médias &
 *       Presse » (porte d'accès, relation LinkedIn, envois presse) ; le
 *       contact est la vue harmonisée que les campagnes visent. `ON DELETE SET
 *       NULL` : effacer le contact (droit à l'effacement) ne casse pas la ligne
 *       source ;
 *     - `journalists.harmonise_le`, `media.harmonise_le` : la date à laquelle
 *       l'harmonisation les a traités. Elle survit à la suppression de la fiche
 *       ou du contact (dont les liens repassent à NULL) : c'est elle qui
 *       interdit à un second passage de RECRÉER une fiche ou une personne que
 *       Will a supprimée.
 *
 *  5. Les DROITS de la personne traversent le lien, dans le sens contact →
 *     journaliste, quel que soit le chemin qui les exerce (relecture sécurité
 *     de #264, veto RGPD). Deux déclencheurs, `SECURITY DEFINER` :
 *     - `contacts_retrait_atteint_journalistes` (BEFORE DELETE sur
 *       `contacts`) : la ligne `journalists` liée est opposée, vidée de son
 *       adresse et de son téléphone, mise à la corbeille — la suppression d'un
 *       contact est un effacement (aucune purge n'est permise, ordre de Will) ;
 *     - `opt_out_atteint_journalistes` (AFTER INSERT sur `opt_out`, portée
 *       business) : tout journaliste dont l'adresse ou le téléphone — ou ceux
 *       de son contact lié — correspondent à l'opposition passe `opt_out`.
 *       L'adresse est comparée par l'empreinte de `ListeSuppression`
 *       (sha256 de l'adresse en minuscules) ; le téléphone par ses chiffres
 *       ramenés à la forme nationale (`presse_telephone_national`).
 *     Le sens journaliste → contact est porté par le code
 *     (`App\Crm\Presse\LienJournalisteContact`).
 *
 * ── Retour arrière ───────────────────────────────────────────────────────
 * REFUSÉ dès qu'une fiche porte le tag de la presse : retirer sa protection
 * la rendrait purgeable, ce que Will interdit.
 */
return new class extends Migration
{
    private const SLUGS_AVANT = ['src:scraping-evenements-pro', 'src:scraping-federations-2026', 'src:scraping-gofab-2026'];

    private const SLUGS = ['src:scraping-evenements-pro', 'src:scraping-federations-2026', 'src:scraping-gofab-2026', 'src:scraping-presse-2026'];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        (new ScrapingSourcesSeeder)->run();
        // Le seeder ne touche jamais `enabled` d'une source existante : un
        // `up()` rejoué après un `down()` (qui la COUPE) doit la rouvrir,
        // sinon les deux commandes refuseraient de tourner.
        DB::table('scraping_sources')->where('slug', 'presse-2026')->update(['enabled' => true, 'updated_at' => now()]);

        $this->installerProtection(self::SLUGS);
        $this->installerRetraits(
            "COALESCE(OLD.sources, '[]'::jsonb) @> '[\"federations-2026\"]'::jsonb OR COALESCE(OLD.sources, '[]'::jsonb) @> '[\"presse-2026\"]'::jsonb",
            "(COALESCE(ct.sources, '[]'::jsonb) @> '[\"federations-2026\"]'::jsonb OR COALESCE(ct.sources, '[]'::jsonb) @> '[\"presse-2026\"]'::jsonb)",
        );

        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS contact_id BIGINT NULL REFERENCES contacts(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE journalists ADD COLUMN IF NOT EXISTS harmonise_le TIMESTAMPTZ NULL');
        DB::statement('ALTER TABLE media ADD COLUMN IF NOT EXISTS harmonise_le TIMESTAMPTZ NULL');
        // Nom VÉRIFIÉ LIBRE le 2026-09-30.
        DB::statement('CREATE INDEX IF NOT EXISTS journalists_contact_idx ON journalists (contact_id) WHERE contact_id IS NOT NULL');

        DB::statement("COMMENT ON COLUMN journalists.contact_id IS 'Contact (contacts.id) qui porte ce journaliste depuis l''harmonisation presse. journalists reste la table source de l''ecran Medias & Presse.'");
        DB::statement("COMMENT ON COLUMN journalists.harmonise_le IS 'Traite par crm:presse:harmoniser. Survit a la suppression du contact : une personne retiree n''est jamais recreee.'");
        DB::statement("COMMENT ON COLUMN media.harmonise_le IS 'Traite par crm:presse:harmoniser ou crm:presse:importer. Survit a la suppression de la fiche : une fiche supprimee n''est jamais recreee.'");

        $this->installerDroitsContactVersJournaliste();
    }

    /**
     * Le sens contact → journaliste, porté par la base : ni l'effacement d'un
     * contact ni une opposition ne peuvent laisser la ligne `journalists` liée
     * joignable. `SECURITY DEFINER` et `search_path` fixé, comme les autres
     * déclencheurs du registre : une opposition est GLOBALE (`opt_out` n'a pas
     * d'espace), elle atteint le journaliste où qu'il soit.
     */
    private function installerDroitsContactVersJournaliste(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.presse_telephone_national(p TEXT)
            RETURNS TEXT
            LANGUAGE sql
            IMMUTABLE
            SET search_path = public, pg_catalog
            AS $fn$
                SELECT CASE
                    WHEN d ~ '^0[1-9][0-9]{8}$' THEN d
                    WHEN d ~ '^(0033|33)0?[1-9][0-9]{8}$' THEN '0' || right(d, 9)
                    ELSE NULLIF(d, '')
                END
                FROM (SELECT regexp_replace(COALESCE(p, ''), '[^0-9]', '', 'g') AS d) s
            $fn$;

            CREATE OR REPLACE FUNCTION public.contacts_retrait_atteint_journalistes()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
                UPDATE public.journalists
                   SET opt_out = true,
                       email = NULL,
                       phone = NULL,
                       harmonise_le = COALESCE(harmonise_le, now()),
                       deleted_at = COALESCE(deleted_at, now()),
                       updated_at = now()
                 WHERE contact_id = OLD.id;

                RETURN OLD;
            END
            $fn$;

            DROP TRIGGER IF EXISTS contacts_retrait_atteint_journalistes ON public.contacts;
            CREATE TRIGGER contacts_retrait_atteint_journalistes
                BEFORE DELETE ON public.contacts
                FOR EACH ROW EXECUTE FUNCTION public.contacts_retrait_atteint_journalistes();

            CREATE OR REPLACE FUNCTION public.opt_out_atteint_journalistes()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            DECLARE
                v_tel TEXT := public.presse_telephone_national(NEW.phone);
            BEGIN
                IF COALESCE(NEW.scope, 'business') <> 'business' THEN
                    RETURN NEW;
                END IF;
                IF NEW.email_hash IS NULL AND NEW.email IS NULL AND v_tel IS NULL THEN
                    RETURN NEW;
                END IF;

                UPDATE public.journalists j
                   SET opt_out = true,
                       updated_at = now()
                 WHERE j.opt_out = false
                   AND (
                        (j.email IS NOT NULL AND (
                            encode(digest(lower(trim(j.email::text)), 'sha256'), 'hex') = NEW.email_hash
                            OR j.email = NEW.email))
                     OR (v_tel IS NOT NULL AND public.presse_telephone_national(j.phone) = v_tel)
                     OR (j.contact_id IS NOT NULL AND EXISTS (
                            SELECT 1 FROM public.contacts c
                             WHERE c.id = j.contact_id
                               AND ((c.email IS NOT NULL AND (
                                        encode(digest(lower(trim(c.email::text)), 'sha256'), 'hex') = NEW.email_hash
                                        OR c.email = NEW.email))
                                    OR (v_tel IS NOT NULL AND public.presse_telephone_national(c.phone) = v_tel))))
                   );

                RETURN NEW;
            END
            $fn$;

            DROP TRIGGER IF EXISTS opt_out_atteint_journalistes ON public.opt_out;
            CREATE TRIGGER opt_out_atteint_journalistes
                AFTER INSERT ON public.opt_out
                FOR EACH ROW EXECUTE FUNCTION public.opt_out_atteint_journalistes();
        SQL);
    }

    /**
     * Retour arrière : le déclencheur et le registre reprennent leur état
     * d'avant. Les COLONNES et leurs valeurs RESTENT (même doctrine que B1 pour
     * `contacts_retires`) : les retirer effacerait la mémoire de ce qui a été
     * supprimé, qui reviendrait au prochain passage une fois la migration
     * rejouée. La source est COUPÉE, jamais supprimée (`scraper_runs` la cite).
     */
    public function down(): void
    {
        // Ordre de Will : une fiche de presse n'est JAMAIS purgeable. Retirer
        // sa protection alors qu'il en existe serait l'y exposer.
        $presse = DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('tags.slug', 'src:scraping-presse-2026')->exists();
        if ($presse) {
            throw new RuntimeException('Retour arriere refuse : des fiches portent src:scraping-presse-2026 et resteraient sans protection.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS opt_out_atteint_journalistes ON public.opt_out;
            DROP TRIGGER IF EXISTS contacts_retrait_atteint_journalistes ON public.contacts;
            DROP FUNCTION IF EXISTS public.opt_out_atteint_journalistes();
            DROP FUNCTION IF EXISTS public.contacts_retrait_atteint_journalistes();
            DROP FUNCTION IF EXISTS public.presse_telephone_national(TEXT);
        SQL);

        $this->installerProtection(self::SLUGS_AVANT);
        $this->installerRetraits(
            "COALESCE(OLD.sources, '[]'::jsonb) @> '[\"federations-2026\"]'::jsonb",
            "COALESCE(ct.sources, '[]'::jsonb) @> '[\"federations-2026\"]'::jsonb",
        );

        DB::table('scraping_sources')->where('slug', 'presse-2026')
            ->update(['enabled' => false, 'updated_at' => now()]);
    }

    /** @param  list<string>  $slugs */
    private function installerProtection(array $slugs): void
    {
        $liste = Taxonomy::sqlList($slugs);

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.refuser_suppression_fiche_protegee()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS \$fn\$
            BEGIN
                IF COALESCE(current_setting('app.autoriser_suppression_protegee', true), '') = 'on' THEN
                    RETURN OLD;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM   public.company_tag ct
                    JOIN   public.tags t ON t.id = ct.tag_id
                    WHERE  ct.company_id = OLD.id
                    AND    t.slug IN ({$liste})
                ) THEN
                    RAISE EXCEPTION 'fiche_protegee : suppression refusee (company_id=%)', OLD.id
                        USING HINT = 'Organisateurs d''evenements, federations, participants GOFAB ou presse. Levee volontaire : SET LOCAL app.autoriser_suppression_protegee = ''on''.';
                END IF;

                RETURN OLD;
            END
            \$fn\$;
        SQL);
    }

    /**
     * Les deux déclencheurs du registre, corps de `2026_09_30_000001`, avec la
     * condition de source donnée (pour une personne : `OLD` ; pour les
     * personnes d'une fiche supprimée : `ct`). Ces conditions sont écrites dans
     * ce fichier, jamais une donnée.
     */
    private function installerRetraits(string $conditionPersonne, string $conditionFiche): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.contacts_memoriser_retrait()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS \$fn\$
            DECLARE
                v_siren      CHAR(9);
                v_pays       CHAR(2);
                v_foreign_id TEXT;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.workspaces w WHERE w.id = OLD.workspace_id) THEN
                    RETURN OLD;
                END IF;

                IF ({$conditionPersonne}) THEN
                    SELECT c.siren, c.country_code, c.foreign_id INTO v_siren, v_pays, v_foreign_id
                    FROM public.companies c WHERE c.id = OLD.company_id;
                    IF NOT FOUND THEN
                        -- Suppression EN CASCADE de la fiche : le déclencheur
                        -- de `companies` (BEFORE DELETE) a déjà inscrit ses
                        -- personnes, avec son SIREN ou son ancre.
                        RETURN OLD;
                    END IF;

                    INSERT INTO public.contacts_retires (workspace_id, company_id, siren, country_code, foreign_id, cle_nom)
                    VALUES (OLD.workspace_id, OLD.company_id, v_siren, v_pays, v_foreign_id,
                            public.contacts_retires_empreinte(OLD.first_name, OLD.last_name))
                    ON CONFLICT DO NOTHING;
                END IF;

                RETURN OLD;
            END
            \$fn\$;

            CREATE OR REPLACE FUNCTION public.companies_memoriser_retraits()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS \$fn\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.workspaces w WHERE w.id = OLD.workspace_id) THEN
                    RETURN OLD;
                END IF;

                INSERT INTO public.contacts_retires (workspace_id, company_id, siren, country_code, foreign_id, cle_nom)
                SELECT ct.workspace_id, ct.company_id, OLD.siren, OLD.country_code, OLD.foreign_id,
                       public.contacts_retires_empreinte(ct.first_name, ct.last_name)
                FROM   public.contacts ct
                WHERE  ct.company_id = OLD.id
                AND    {$conditionFiche}
                ON CONFLICT DO NOTHING;

                RETURN OLD;
            END
            \$fn\$;
        SQL);
    }
};

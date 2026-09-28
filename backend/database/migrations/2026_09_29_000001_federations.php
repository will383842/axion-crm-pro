<?php

use App\Crm\Taxonomy;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LA MAISON DES FÉDÉRATIONS (chantier 3, 2026-09-29).
 *
 * Will veut importer ~35 600 organisations professionnelles (fédérations,
 * confédérations, ordres, chambres, syndicats, associations de métiers…), les
 * voir dans un onglet, les relier entre elles (tête de réseau ↔ antennes) et
 * à leurs événements, suivre une démarche « partenariat », et les cibler en
 * campagne. Décisions : `_FEDERATIONS/CADRAGE.md` (hors dépôt).
 *
 * ── Pourquoi une TABLE DÉDIÉE et pas des colonnes sur `companies` ──────────
 *
 *  - `companies` porte 4,3 M de lignes ; ces attributs n'ont de sens que pour
 *    ~35 000 d'entre elles. Une table 1-1 (`company_id` clé primaire) ne
 *    touche pas au schéma de la grande table : aucun verrou long, aucun
 *    risque sur les 4,3 M de fiches ;
 *  - ses listes fermées (famille, niveau, pertinence…) ont chacune leur CHECK
 *    VALIDE dès la création — sur `companies`, un CHECK devrait être posé
 *    `NOT VALID` puis validé par une seconde migration ;
 *  - les deux champs multi-valués (secteurs représentés, tailles des
 *    adhérents) sont des tableaux fermés par CHECK, indexables (GIN) ;
 *  - l'onglet « Fédérations » liste « les fiches qui ont une ligne ici » :
 *    une fiche déjà présente (un organisateur d'événement, une CCI) y entre en
 *    RECEVANT une ligne, sans changer de nature ni de démarche.
 *
 * ── La tête de réseau et sa garde anti-cycle ──────────────────────────────
 *
 * `parent_company_id` : sur le modèle de `media.parent_media_id`
 * (`ON DELETE SET NULL`), mais ici dans la table dédiée — une antenne est une
 * fédération, sa tête peut être n'importe quelle fiche du même espace. Le
 * déclencheur `federations_refuser_cycle` refuse : soi-même comme tête, une
 * tête d'un autre espace, et toute boucle (A → B → A, à toute profondeur).
 * Un verrou consultatif par espace sérialise les changements de tête : deux
 * transactions concurrentes ne peuvent pas fermer une boucle chacune de leur
 * côté.
 *
 * ── Et aussi ──────────────────────────────────────────────────────────────
 *
 *  - `companies.entity_nature` gagne `federation`. Le CHECK est reposé
 *    `NOT VALID` (instantané) et VALIDÉ par la migration suivante, hors
 *    transaction, sans verrou bloquant sur les 4,3 M de lignes. Même chose
 *    pour `activities.kind`, qui gagne les quatre étapes du partenariat ;
 *  - la source `federations-2026` entre au registre : son tag
 *    `src:scraping-federations-2026` est protégé comme celui des organisateurs
 *    (`FichesProtegees`) — et le déclencheur qui refuse la suppression
 *    physique d'une fiche protégée est RÉINSTALLÉ avec les deux slugs (sa liste
 *    est figée, cf. migration `2026_09_27_000001`).
 */
return new class extends Migration
{
    /** @var list<string> Natures d'avant cette migration, figées pour `down()`. */
    private const NATURES_AVANT = [
        'entreprise', 'association', 'cci', 'enseignement',
        'cabinet', 'institution', 'media', 'reseau',
    ];

    /** @var list<string> `ACTIVITY_KINDS` d'avant cette migration, figées pour `down()`. */
    private const ACTIVITY_KINDS_AVANT = [
        'form_submission', 'calendly_booked', 'calendly_completed', 'calendly_no_show',
        'calendly_canceled', 'review_posted', 'newsletter_optin', 'newsletter_optout',
        'application_submitted', 'stage_changed', 'reclassified', 'scraped',
        'enriched', 'opt_out', 'gdpr_export', 'gdpr_erasure',
        'press_release_sent', 'press_followup', 'press_reply', 'press_coverage',
        'linkedin_message', 'call',
        'lead_magnet_requested', 'email_hard_bounced', 'task',
        'evenement_repere', 'evenement_inscrit', 'evenement_rencontre',
        'intervention_proposee', 'intervention_acceptee', 'intervention_refusee', 'intervention_realisee',
    ];

    /** @var list<string> Slugs protégés AVANT cette migration (pour `down()`). */
    private const SLUGS_PROTEGES_AVANT = ['src:scraping-evenements-pro'];

    /**
     * Slugs protégés APRÈS cette migration — FIGÉS ici, jamais lus dans
     * `FichesProtegees::TAGS` : la production n'installe ce déclencheur qu'une
     * fois (cf. migration `2026_09_27_000001`).
     *
     * @var list<string>
     */
    private const SLUGS_PROTEGES = ['src:scraping-evenements-pro', 'src:scraping-federations-2026'];

    public function up(): void
    {
        // En TÊTE : les CHECK reposés plus bas verrouillent brièvement
        // `companies` et `activities` ; sans délai, l'ALTER ferait la queue
        // derrière une requête longue — et toutes les suivantes derrière lui.
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_entity_nature_check');
        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_entity_nature_check
             CHECK (entity_nature IS NULL OR entity_nature IN (' . Taxonomy::sqlList(array_merge(self::NATURES_AVANT, ['federation'])) . ')) NOT VALID',
        );

        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList(Taxonomy::ACTIVITY_KINDS) . ')) NOT VALID',
        );

        $this->createFederations();
        $this->applyRls();
        $this->installerGardeAntiCycle();
        $this->installerGardeMemeEspace();
        $this->createContactsRetires();
        $this->createFonctionsCanaux();
        $this->installerProtection(self::SLUGS_PROTEGES);

        (new ScrapingSourcesSeeder)->run();
    }

    public function down(): void
    {
        $federations = (int) DB::table('companies')->where('entity_nature', 'federation')->count();
        if ($federations > 0) {
            throw new RuntimeException(
                "Retour arrière refusé : {$federations} fiche(s) de nature `federation`. Les reclasser à la main d'abord.",
            );
        }

        DB::table('scraping_sources')->where('slug', 'federations-2026')
            ->update(['enabled' => false, 'updated_at' => now()]);

        $this->installerProtection(self::SLUGS_PROTEGES_AVANT);

        // Les index qui les emploient sont retirés par `2026_09_29_000002`.
        DB::statement('DROP FUNCTION IF EXISTS public.canaux_emails(JSONB)');
        DB::statement('DROP FUNCTION IF EXISTS public.canaux_telephones(JSONB)');

        // 🔴 LE REGISTRE DES RETRAITS SURVIT AU RETOUR ARRIÈRE (relecture B1).
        // `contacts_retires`, sa clé et ses deux fonctions RESTENT : les
        // supprimer ferait revenir, au prochain import, chaque personne qui a
        // été supprimée ou qui a exercé son droit à l'effacement — et une clé
        // tirée à nouveau ne reconnaîtrait plus aucune empreinte. Seuls les
        // déclencheurs partent ; le `up()` suivant les repose et retrouve la
        // même clé (`ON CONFLICT DO NOTHING`).
        DB::statement('DROP TRIGGER IF EXISTS companies_memoriser_retraits ON public.companies');
        DB::statement('DROP FUNCTION IF EXISTS public.companies_memoriser_retraits()');
        DB::statement('DROP TRIGGER IF EXISTS contacts_memoriser_retrait ON public.contacts');
        DB::statement('DROP FUNCTION IF EXISTS public.contacts_memoriser_retrait()');
        DB::statement('DROP TRIGGER IF EXISTS companies_federation_meme_espace ON public.companies');
        DB::statement('DROP FUNCTION IF EXISTS public.companies_federation_meme_espace()');
        DB::statement('DROP TRIGGER IF EXISTS federations_meme_espace ON public.federations');
        DB::statement('DROP FUNCTION IF EXISTS public.federations_meme_espace()');
        DB::statement('DROP TRIGGER IF EXISTS federations_refuser_cycle ON public.federations');
        DB::statement('DROP FUNCTION IF EXISTS public.federations_refuser_cycle()');
        DB::statement('DROP TABLE IF EXISTS federations');

        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_entity_nature_check');
        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_entity_nature_check
             CHECK (entity_nature IS NULL OR entity_nature IN (' . Taxonomy::sqlList(self::NATURES_AVANT) . '))',
        );

        DB::statement(
            'UPDATE activities SET kind = NULL
             WHERE kind IS NOT NULL AND kind NOT IN (' . Taxonomy::sqlList(self::ACTIVITY_KINDS_AVANT) . ')',
        );
        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList(self::ACTIVITY_KINDS_AVANT) . '))',
        );
    }

    private function createFederations(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS federations (
                company_id               BIGINT      PRIMARY KEY REFERENCES companies(id) ON DELETE CASCADE,
                workspace_id             UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,

                -- Classement (CADRAGE §1, §3, §5).
                famille                  TEXT        NOT NULL,
                niveau                   TEXT        NOT NULL,
                secteurs                 TEXT[]      NOT NULL DEFAULT '{}',
                tailles_adherents        TEXT[]      NOT NULL DEFAULT '{}',
                certitude                TEXT,
                pertinence               TEXT        NOT NULL,
                contactabilite           TEXT        NOT NULL,
                origine_classement       TEXT,

                -- Identité propre à un organisme (aucune donnée de personne).
                sigle                    TEXT,
                nom_developpe            TEXT,
                date_creation            DATE,
                nb_etablissements        INTEGER,

                -- Tête de réseau (FFB Rhône → FFB). Garde anti-cycle : déclencheur.
                parent_company_id        BIGINT      REFERENCES companies(id) ON DELETE SET NULL,

                -- LA DÉMARCHE DE WILL. Jamais écrite par un import : un ré-import
                -- ne doit pas effacer un « partenariat accepté ».
                partenariat              TEXT        NOT NULL DEFAULT 'aucun',
                partenariat_relance_at   TIMESTAMPTZ,
                partenariat_note         TEXT,

                created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT federations_parent_pas_soi_meme CHECK (parent_company_id IS NULL OR parent_company_id <> company_id)
            )
            SQL
        );

        $checks = [
            'federations_famille_check' => 'famille IN (' . Taxonomy::sqlList(array_keys(Taxonomy::FEDERATION_FAMILLES)) . ')',
            'federations_niveau_check' => 'niveau IN (' . Taxonomy::sqlList(array_keys(Taxonomy::FEDERATION_NIVEAUX)) . ')',
            'federations_certitude_check' => 'certitude IS NULL OR certitude IN (' . Taxonomy::sqlList(array_keys(Taxonomy::FEDERATION_CERTITUDES)) . ')',
            'federations_pertinence_check' => 'pertinence IN (' . Taxonomy::sqlList(array_keys(Taxonomy::FEDERATION_PERTINENCES)) . ')',
            'federations_contactabilite_check' => 'contactabilite IN (' . Taxonomy::sqlList(array_keys(Taxonomy::FEDERATION_CONTACTABILITES)) . ')',
            'federations_partenariat_check' => 'partenariat IN (' . Taxonomy::sqlList(array_keys(Taxonomy::FEDERATION_PARTENARIATS)) . ')',
            // `<@` : chaque élément du tableau appartient à la liste fermée.
            'federations_secteurs_check' => 'secteurs <@ ' . self::tableau(Taxonomy::secteursRepresentables())
                . ' AND cardinality(secteurs) <= ' . Taxonomy::FEDERATION_SECTEURS_MAX,
            'federations_tailles_adherents_check' => 'tailles_adherents <@ ' . self::tableau(array_keys(Taxonomy::TAILLES)),
        ];
        foreach ($checks as $nom => $condition) {
            DB::statement("ALTER TABLE federations DROP CONSTRAINT IF EXISTS {$nom}");
            DB::statement("ALTER TABLE federations ADD CONSTRAINT {$nom} CHECK ({$condition})");
        }

        // Noms VÉRIFIÉS LIBRES le 2026-09-29 dans `database/migrations/`.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_federations_workspace_famille_niveau ON federations (workspace_id, famille, niveau)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_federations_parent ON federations (parent_company_id) WHERE parent_company_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_federations_secteurs ON federations USING GIN (secteurs)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_federations_relance ON federations (workspace_id, partenariat_relance_at) WHERE partenariat_relance_at IS NOT NULL');

        DB::statement("COMMENT ON TABLE federations IS 'Organisations professionnelles (chantier 3) : classement, tete de reseau, demarche partenariat. Aucune donnee de personne : les personnes vont dans contacts.'");
        DB::statement("COMMENT ON COLUMN federations.partenariat IS 'aucun | propose | en_discussion | accepte | refuse. Jamais ecrit par un import.'");
        DB::statement("COMMENT ON COLUMN federations.partenariat_note IS 'Note de Will. JAMAIS un nom ni une coordonnee : une personne va dans contacts.'");
        DB::statement("COMMENT ON COLUMN federations.secteurs IS 'Secteurs REPRESENTES (1 a 3, cles de Taxonomy::SECTEURS). Le premier alimente companies.sector_main.'");
    }

    /**
     * Littéral tableau PostgreSQL d'une liste fermée : `ARRAY['a', 'b']::TEXT[]`.
     *
     * @param  list<string>  $valeurs
     */
    private static function tableau(array $valeurs): string
    {
        return 'ARRAY[' . Taxonomy::sqlList($valeurs) . ']::TEXT[]';
    }

    /**
     * `federations.workspace_id` est TOUJOURS l'espace de sa fiche `companies`
     * (relecture sécurité R4) : sinon une ligne rangée dans l'espace B pour une
     * fiche de l'espace A ferait voir, sous la RLS de B, le classement, la tête
     * de réseau et la démarche d'une fiche de A. Gardé des deux côtés : à
     * l'écriture de la ligne, et au déplacement (improbable) d'une fiche.
     */
    private function installerGardeMemeEspace(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.federations_meme_espace()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM public.companies c
                    WHERE  c.id = NEW.company_id
                    AND    c.workspace_id = NEW.workspace_id
                ) THEN
                    RAISE EXCEPTION 'federation_espace_incoherent : la ligne et sa fiche ne sont pas dans le meme espace (company_id=%)', NEW.company_id;
                END IF;

                RETURN NEW;
            END
            $fn$;

            DROP TRIGGER IF EXISTS federations_meme_espace ON public.federations;
            CREATE TRIGGER federations_meme_espace
                BEFORE INSERT OR UPDATE OF company_id, workspace_id ON public.federations
                FOR EACH ROW EXECUTE FUNCTION public.federations_meme_espace();

            CREATE OR REPLACE FUNCTION public.companies_federation_meme_espace()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM public.federations f
                    WHERE  f.company_id = NEW.id
                    AND    f.workspace_id <> NEW.workspace_id
                ) THEN
                    RAISE EXCEPTION 'federation_espace_incoherent : fiche deplacee sans sa ligne federations (company_id=%)', NEW.id;
                END IF;

                RETURN NEW;
            END
            $fn$;

            DROP TRIGGER IF EXISTS companies_federation_meme_espace ON public.companies;
            CREATE TRIGGER companies_federation_meme_espace
                AFTER UPDATE OF workspace_id ON public.companies
                FOR EACH ROW EXECUTE FUNCTION public.companies_federation_meme_espace();
        SQL);
    }

    /**
     * LES PERSONNES RETIRÉES NE REVIENNENT PAS (relecture sécurité R2).
     *
     * Une personne SANS e-mail n'a aucune opposition possible : `opt_out` se
     * cherche par empreinte d'adresse ou par téléphone. Supprimée à la main,
     * ou effacée, elle revenait donc au prochain import dès que la ligne du
     * fichier changeait (nouveau `run_id`).
     *
     * Ce registre garde, à chaque suppression d'une fiche personne venue de
     * l'import des fédérations, l'EMPREINTE SALÉE (HMAC) de son nom normalisé
     * (jamais le nom) et le SIREN de son organisme.
     *
     * Registre art. 30 — finalité : rendre effective une suppression ou un
     * effacement face aux réimports de l'annuaire (art. 17 et 21) ; données :
     * une empreinte HMAC non réversible sans la clé, et le SIREN d'une
     * personne morale ; durée : celle des oppositions (`opt_out`), c'est-à-dire
     * tant que la source `federations-2026` peut être réimportée — le registre
     * se vide avec elle, jamais avant (le vider ferait revenir les personnes). `crm:import-federations` écarte toute
     * personne qui y figure. Même doctrine que `opt_out` : l'effacement laisse
     * une empreinte, pas la donnée — c'est ce qui l'empêche de revenir.
     *
     * Posé par un déclencheur, et non dans chaque chemin de suppression :
     * console, effacement RGPD, purge — aucun ne peut l'oublier.
     */
    private function createContactsRetires(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS contacts_retires (
                id            BIGSERIAL   PRIMARY KEY,
                workspace_id  UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                company_id    BIGINT,
                siren         CHAR(9),
                cle_nom       TEXT        NOT NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL
        );
        // Nom VÉRIFIÉ LIBRE le 2026-09-29.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS contacts_retires_cle_key ON contacts_retires (workspace_id, siren, cle_nom)');
        DB::statement("COMMENT ON TABLE contacts_retires IS 'Personnes retirees (suppression, effacement) : empreinte du nom normalise + SIREN. Jamais le nom en clair. Lue par crm:import-federations.'");

        DB::statement('ALTER TABLE contacts_retires ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE contacts_retires FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS contacts_retires_workspace_isolation ON contacts_retires');
        DB::statement(
            "CREATE POLICY contacts_retires_workspace_isolation ON contacts_retires FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );

        // ── L'EMPREINTE EST SALÉE (relectures S7, S-a) ───────────────────────
        // Une empreinte SHA-256 d'un nom se retrouve par dictionnaire (les noms
        // sont peu nombreux) : sans sel, le registre redirait qui a été retiré.
        // `opt_out` n'a pas de sel, et le seul secret HMAC existant
        // (`CRM_PERSON_KEY_SECRET`) vit côté application — un déclencheur de la
        // base ne peut pas le lire. La clé est donc tirée ICI, dans la base, au
        // hasard (32 octets). Elle ne change jamais (sinon le registre perdrait
        // sa mémoire).
        //
        // CE QUE LE SEL PROTÈGE, ET CE QU'IL NE PROTÈGE PAS — honnêtement :
        //  - il protège une FUITE DE LA TABLE `contacts_retires` seule (un
        //    export, une copie, une requête de lecture) : sans la clé, les
        //    empreintes ne se retrouvent pas par dictionnaire ;
        //  - il ne protège PAS contre qui a la base entière : le propriétaire
        //    (et tout super-utilisateur) lit `contacts_retires_cle`, et la clé
        //    part dans CHAQUE sauvegarde (`pg_dump`) avec le registre ;
        //  - le rôle applicatif ne lit pas la clé et n'exécute PAS
        //    `contacts_retires_empreinte` (EXECUTE retiré à PUBLIC et à lui) :
        //    il ne peut pas fabriquer d'empreintes pour tester un dictionnaire.
        //    Il n'a que `contacts_retires_contient()`, une réponse oui/non pour
        //    UNE personne d'UN organisme, dans l'espace de son contexte : c'est
        //    ce dont l'import a besoin — et le rôle qui importe EST le rôle
        //    applicatif, on ne peut donc pas la réserver à un autre.
        //
        // RESTAURATION « DONNÉES SEULES » (`pg_restore --data-only` sur un
        // schéma neuf) : les migrations ont déjà tiré une clé NEUVE, et la
        // ligne restaurée entre en conflit avec elle. Vider
        // `contacts_retires_cle` AVANT de restaurer ses données — sinon la clé
        // neuve reste, et le registre ne reconnaît plus personne, sans erreur.
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS public.contacts_retires_cle (
                id   SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
                cle  BYTEA    NOT NULL
            );
            INSERT INTO public.contacts_retires_cle (id, cle) VALUES (1, gen_random_bytes(32)) ON CONFLICT (id) DO NOTHING;
            REVOKE ALL ON public.contacts_retires_cle FROM PUBLIC;
            COMMENT ON TABLE public.contacts_retires_cle IS 'Cle HMAC du registre contacts_retires. Lue par contacts_retires_empreinte() seulement. Ne jamais la changer ni l exporter.';

            CREATE OR REPLACE FUNCTION public.contacts_retires_empreinte(p_prenom TEXT, p_nom TEXT)
            RETURNS TEXT
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
                SELECT encode(hmac(convert_to(public.normalize_name(coalesce(p_prenom, '') || '_' || p_nom), 'UTF8'), k.cle, 'sha256'), 'hex')
                FROM   public.contacts_retires_cle k
                WHERE  k.id = 1
            $fn$;
        SQL);
        DB::unprepared(<<<'SQL'
            REVOKE EXECUTE ON FUNCTION public.contacts_retires_empreinte(TEXT, TEXT) FROM PUBLIC;

            -- La SEULE question que le rôle applicatif peut poser au registre :
            -- « cette personne de cet organisme a-t-elle été retirée ? » — dans
            -- l'espace de son contexte, jamais un autre.
            CREATE OR REPLACE FUNCTION public.contacts_retires_contient(p_workspace UUID, p_siren TEXT, p_prenom TEXT, p_nom TEXT)
            RETURNS BOOLEAN
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
                SELECT EXISTS (
                    SELECT 1
                    FROM   public.contacts_retires r
                    WHERE  r.workspace_id = p_workspace
                    AND    p_workspace::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
                    AND    r.siren = p_siren::CHAR(9)
                    AND    r.cle_nom = public.contacts_retires_empreinte(p_prenom, p_nom)
                )
            $fn$;
            REVOKE EXECUTE ON FUNCTION public.contacts_retires_contient(UUID, TEXT, TEXT, TEXT) FROM PUBLIC;
        SQL);
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.contacts_retires_cle FROM ' . $role);
            DB::statement('REVOKE EXECUTE ON FUNCTION public.contacts_retires_empreinte(TEXT, TEXT) FROM ' . $role);
            DB::statement('GRANT EXECUTE ON FUNCTION public.contacts_retires_contient(UUID, TEXT, TEXT, TEXT) TO ' . $role);
        }

        DB::unprepared(<<<'SQL'
            -- Un espace SUPPRIMÉ (cascade) ne mémorise rien : son registre part
            -- avec lui, et l'inscrire violerait la clé étrangère — la
            -- suppression de l'espace échouerait (relecture B4).
            CREATE OR REPLACE FUNCTION public.contacts_memoriser_retrait()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            DECLARE
                v_siren CHAR(9);
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.workspaces w WHERE w.id = OLD.workspace_id) THEN
                    RETURN OLD;
                END IF;

                IF COALESCE(OLD.sources, '[]'::jsonb) @> '["federations-2026"]'::jsonb THEN
                    SELECT c.siren INTO v_siren FROM public.companies c WHERE c.id = OLD.company_id;
                    IF NOT FOUND THEN
                        -- Suppression EN CASCADE de la fiche d'organisation : elle
                        -- n'est plus visible ici, son SIREN non plus. Le
                        -- déclencheur de `companies` (BEFORE DELETE) a déjà
                        -- inscrit ses personnes, avec le bon SIREN.
                        RETURN OLD;
                    END IF;

                    INSERT INTO public.contacts_retires (workspace_id, company_id, siren, cle_nom)
                    VALUES (OLD.workspace_id, OLD.company_id, v_siren, public.contacts_retires_empreinte(OLD.first_name, OLD.last_name))
                    ON CONFLICT DO NOTHING;
                END IF;

                RETURN OLD;
            END
            $fn$;

            DROP TRIGGER IF EXISTS contacts_memoriser_retrait ON public.contacts;
            CREATE TRIGGER contacts_memoriser_retrait
                AFTER DELETE ON public.contacts
                FOR EACH ROW EXECUTE FUNCTION public.contacts_memoriser_retrait();

            -- La fiche d'organisation supprimée : ses personnes, AVANT la cascade,
            -- pendant que le SIREN est encore lisible.
            CREATE OR REPLACE FUNCTION public.companies_memoriser_retraits()
            RETURNS trigger
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.workspaces w WHERE w.id = OLD.workspace_id) THEN
                    RETURN OLD;
                END IF;

                INSERT INTO public.contacts_retires (workspace_id, company_id, siren, cle_nom)
                SELECT ct.workspace_id, ct.company_id, OLD.siren, public.contacts_retires_empreinte(ct.first_name, ct.last_name)
                FROM   public.contacts ct
                WHERE  ct.company_id = OLD.id
                AND    COALESCE(ct.sources, '[]'::jsonb) @> '["federations-2026"]'::jsonb
                ON CONFLICT DO NOTHING;

                RETURN OLD;
            END
            $fn$;

            DROP TRIGGER IF EXISTS companies_memoriser_retraits ON public.companies;
            CREATE TRIGGER companies_memoriser_retraits
                BEFORE DELETE ON public.companies
                FOR EACH ROW EXECUTE FUNCTION public.companies_memoriser_retraits();
        SQL);
    }

    /**
     * LES CANAUX, INDEXABLES (relecture P1). L'effacement cherchait l'adresse
     * et les numéros d'une personne dans `signals.contact_channels` par
     * `ILIKE` et `regexp_replace … LIKE` : un parcours des 4,3 M de fiches,
     * sur un chemin que le site coupe à 10 s. Ces deux fonctions rendent les
     * valeurs NORMALISÉES des canaux (adresses en minuscules, clés de
     * `details` comprises ; téléphones réduits à leurs chiffres) ; les index
     * GIN de la migration `2026_09_29_000002` les servent, et l'égalité est
     * exacte (`&&`). IMMUTABLE : elles ne lisent que leur argument.
     */
    private function createFonctionsCanaux(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.canaux_emails(p_signals JSONB)
            RETURNS TEXT[]
            LANGUAGE sql
            IMMUTABLE
            PARALLEL SAFE
            SET search_path = pg_catalog
            AS $fn$
                SELECT coalesce(array_agg(DISTINCT v), '{}'::TEXT[])
                FROM (
                    SELECT lower(btrim(e)) AS v
                    FROM   jsonb_array_elements_text(CASE
                               WHEN jsonb_typeof(p_signals -> 'contact_channels' -> 'emails') = 'array'
                               THEN p_signals -> 'contact_channels' -> 'emails'
                               ELSE '[]'::JSONB END) e
                    UNION
                    SELECT lower(btrim(k))
                    FROM   jsonb_object_keys(CASE
                               WHEN jsonb_typeof(p_signals -> 'contact_channels' -> 'details') = 'object'
                               THEN p_signals -> 'contact_channels' -> 'details'
                               ELSE '{}'::JSONB END) k
                ) s
                WHERE v <> ''
            $fn$;

            CREATE OR REPLACE FUNCTION public.canaux_telephones(p_signals JSONB)
            RETURNS TEXT[]
            LANGUAGE sql
            IMMUTABLE
            PARALLEL SAFE
            SET search_path = pg_catalog
            AS $fn$
                SELECT coalesce(array_agg(DISTINCT d), '{}'::TEXT[])
                FROM (
                    SELECT regexp_replace(t, '[^0-9]', '', 'g') AS d
                    FROM   jsonb_array_elements_text(CASE
                               WHEN jsonb_typeof(p_signals -> 'contact_channels' -> 'phones') = 'array'
                               THEN p_signals -> 'contact_channels' -> 'phones'
                               ELSE '[]'::JSONB END) t
                ) s
                WHERE length(d) >= 9
            $fn$;
        SQL);
    }

    private function applyRls(): void
    {
        DB::statement('ALTER TABLE federations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE federations FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS federations_workspace_isolation ON federations');
        DB::statement(
            "CREATE POLICY federations_workspace_isolation ON federations FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );
    }

    /**
     * Refuse une tête de réseau qui ferait boucle, ou qui vit dans un autre
     * espace. PAS `SECURITY DEFINER` : sous la RLS, la remontée ne voit que
     * l'espace courant — c'est exactement l'arbre qu'on garde.
     */
    private function installerGardeAntiCycle(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.federations_refuser_cycle()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS $fn$
            DECLARE
                courant BIGINT;
                pas     INTEGER := 0;
            BEGIN
                IF NEW.parent_company_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.parent_company_id = NEW.company_id THEN
                    RAISE EXCEPTION 'federation_cycle : une fiche ne peut pas etre sa propre tete de reseau (company_id=%)', NEW.company_id;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM public.companies c
                    WHERE  c.id = NEW.parent_company_id
                    AND    c.workspace_id = NEW.workspace_id
                ) THEN
                    RAISE EXCEPTION 'federation_parent_hors_espace : tete de reseau introuvable dans cet espace (company_id=%)', NEW.company_id;
                END IF;

                -- Deux transactions qui poseraient A -> B et B -> A en même
                -- temps passeraient chacune la remontée : on les sérialise.
                PERFORM pg_advisory_xact_lock(hashtext('federations_arbre:' || NEW.workspace_id::TEXT));

                courant := NEW.parent_company_id;
                LOOP
                    SELECT f.parent_company_id INTO courant
                    FROM   public.federations f
                    WHERE  f.company_id = courant;

                    EXIT WHEN courant IS NULL;

                    IF courant = NEW.company_id THEN
                        RAISE EXCEPTION 'federation_cycle : cette tete de reseau ferait une boucle (company_id=%)', NEW.company_id;
                    END IF;

                    pas := pas + 1;
                    IF pas > 64 THEN
                        RAISE EXCEPTION 'federation_cycle : arborescence trop profonde (company_id=%)', NEW.company_id;
                    END IF;
                END LOOP;

                RETURN NEW;
            END
            $fn$;

            DROP TRIGGER IF EXISTS federations_refuser_cycle ON public.federations;

            CREATE TRIGGER federations_refuser_cycle
                BEFORE INSERT OR UPDATE OF parent_company_id, workspace_id ON public.federations
                FOR EACH ROW EXECUTE FUNCTION public.federations_refuser_cycle();
        SQL);
    }

    /**
     * Réinstalle le verrou de la base sur les fiches protégées (migration
     * `2026_09_27_000001`), avec la liste de slugs donnée — seul son corps
     * change.
     *
     * @param  list<string>  $slugs
     */
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
                        USING HINT = 'Organisateurs d''evenements ou federations. Levee volontaire : SET LOCAL app.autoriser_suppression_protegee = ''on''.';
                END IF;

                RETURN OLD;
            END
            \$fn\$;
        SQL);
    }
};

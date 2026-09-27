<?php

use App\Crm\Taxonomy;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ÉVÉNEMENTS PROFESSIONNELS ET LEURS ORGANISATEURS (2026-09-27).
 *
 * Will veut, dans le CRM, la liste des événements (salons, clubs d'affaires,
 * ateliers CCI…) reliée aux organisateurs, et y suivre sa démarche : repéré,
 * inscrit, rencontré ; intervention proposée, acceptée, refusée, réalisée.
 *
 * ── Pourquoi une TABLE et pas une activité ─────────────────────────────────
 *
 * Rangé dans `activities.payload`, un événement n'aurait ni date triable ni
 * filtre (aucun index, aucun écran ne liste les activités par type) ; un
 * événement à deux organisateurs aurait été dupliqué ; les 1 150 récurrents
 * sans date n'avaient pas de place. D'où :
 *
 *  - `events` : l'événement, ET l'état courant de la démarche
 *    (`participation`, `intervention`, `prochaine_relance_at`) — listable et
 *    filtrable ;
 *  - `event_organizers` : le lien N-N avec `companies` (un organisateur a
 *    jusqu'à 26 événements) ;
 *  - `activities` : l'HISTORIQUE daté de chaque étape (sept nouveaux `kind`).
 *
 * Aucune donnée de personne dans ces deux tables : les personnes restent dans
 * `contacts`, sous les chemins d'effacement existants.
 *
 * ── Et aussi ──────────────────────────────────────────────────────────────
 *
 *  - `companies.entity_nature` gagne `reseau` (BNI, clubs d'affaires) ;
 *  - la source `evenements-pro` entre au registre (son tag
 *    `src:scraping-evenements-pro` est celui que `FichesProtegees` garde).
 */
return new class extends Migration
{
    /**
     * `ACTIVITY_KINDS` d'avant cette migration, recopiées en dur : `down()` ne
     * peut pas relire `Taxonomy`, qui porte les valeurs qu'on retire.
     *
     * @var list<string>
     */
    private const ACTIVITY_KINDS_AVANT = [
        'form_submission', 'calendly_booked', 'calendly_completed', 'calendly_no_show',
        'calendly_canceled', 'review_posted', 'newsletter_optin', 'newsletter_optout',
        'application_submitted', 'stage_changed', 'reclassified', 'scraped',
        'enriched', 'opt_out', 'gdpr_export', 'gdpr_erasure',
        'press_release_sent', 'press_followup', 'press_reply', 'press_coverage',
        'linkedin_message', 'call',
        'lead_magnet_requested', 'email_hard_bounced', 'task',
    ];

    /** @var list<string> */
    private const NATURES_AVANT = [
        'entreprise', 'association', 'cci', 'enseignement',
        'cabinet', 'institution', 'media',
    ];

    public function up(): void
    {
        // En TÊTE : les deux CHECK reconstruits plus bas verrouillent
        // `activities` et `companies` (4,3 M de lignes). Sans délai, un ALTER
        // qui attend derrière une requête longue fait la queue — et toutes
        // les requêtes suivantes derrière lui.
        DB::statement("SET LOCAL lock_timeout = '30s'");

        $this->createEvents();
        $this->createEventOrganizers();
        $this->applyRls();

        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList(Taxonomy::ACTIVITY_KINDS) . '))',
        );

        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_entity_nature_check');
        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_entity_nature_check
             CHECK (entity_nature IS NULL OR entity_nature IN (' . Taxonomy::sqlList(array_merge(self::NATURES_AVANT, ['reseau'])) . '))',
        );

        (new ScrapingSourcesSeeder)->run();
    }

    public function down(): void
    {
        $reseaux = (int) DB::table('companies')->where('entity_nature', 'reseau')->count();
        if ($reseaux > 0) {
            throw new RuntimeException(
                "Retour arrière refusé : {$reseaux} fiche(s) de nature `reseau`. Les reclasser à la main d'abord.",
            );
        }

        DB::table('scraping_sources')->where('slug', 'evenements-pro')
            ->update(['enabled' => false, 'updated_at' => now()]);

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

        DB::statement('DROP TABLE IF EXISTS event_organizers');
        DB::statement('DROP TABLE IF EXISTS events');
    }

    private function createEvents(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS events (
                id                         BIGSERIAL PRIMARY KEY,
                workspace_id               UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,

                -- L'identifiant STABLE du sourcing : clé de ré-import (une
                -- correction du fichier met à jour, ne duplique pas).
                external_ref               TEXT        NOT NULL,
                nom                        TEXT        NOT NULL,
                type                       TEXT        NOT NULL,

                -- NULL pour les récurrents (BNI hebdomadaires…) : `recurrence`
                -- dit alors quand ils ont lieu.
                date_debut                 DATE,
                date_fin                   DATE,
                recurrence                 TEXT,
                heure                      TEXT,

                lieu                       TEXT,
                ville                      TEXT,
                departement_code           TEXT,
                region                     TEXT,

                public_vise                TEXT,
                taille                     TEXT,
                prix                       TEXT,
                lien_evenement             TEXT,
                lien_inscription           TEXT,
                appel_intervenants         TEXT        NOT NULL DEFAULT 'inconnu',
                appel_intervenants_limite  DATE,
                verifie                    BOOLEAN     NOT NULL DEFAULT false,
                source_url                 TEXT,
                notes                      TEXT,

                -- LA DÉMARCHE DE WILL. Jamais écrite par un import : un ré-import
                -- ne doit pas effacer un « intervention acceptée ».
                participation              TEXT        NOT NULL DEFAULT 'repere',
                intervention               TEXT        NOT NULL DEFAULT 'aucune',
                prochaine_relance_at       TIMESTAMPTZ,
                demarche_note              TEXT,

                created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT events_dates_check CHECK (date_fin IS NULL OR date_debut IS NULL OR date_fin >= date_debut)
            )
            SQL
        );

        $checks = [
            'events_type_check' => 'type IN (' . Taxonomy::sqlList(Taxonomy::EVENEMENT_TYPES) . ')',
            'events_participation_check' => 'participation IN (' . Taxonomy::sqlList(Taxonomy::EVENEMENT_PARTICIPATIONS) . ')',
            'events_intervention_check' => 'intervention IN (' . Taxonomy::sqlList(Taxonomy::EVENEMENT_INTERVENTIONS) . ')',
            'events_appel_intervenants_check' => 'appel_intervenants IN (' . Taxonomy::sqlList(Taxonomy::EVENEMENT_APPELS_INTERVENANTS) . ')',
        ];
        foreach ($checks as $nom => $condition) {
            DB::statement("ALTER TABLE events DROP CONSTRAINT IF EXISTS {$nom}");
            DB::statement("ALTER TABLE events ADD CONSTRAINT {$nom} CHECK ({$condition})");
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS events_workspace_external_ref_key ON events (workspace_id, external_ref)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_events_workspace_date ON events (workspace_id, date_debut)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_events_workspace_region_type ON events (workspace_id, region, type)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_events_workspace_relance ON events (workspace_id, prochaine_relance_at) WHERE prochaine_relance_at IS NOT NULL');

        DB::statement("COMMENT ON TABLE events IS 'Evenements professionnels (salons, clubs, ateliers) et etat de la demarche de Will. Aucune donnee de personne.'");
        DB::statement("COMMENT ON COLUMN events.notes IS 'Extrait public de la page. JAMAIS un nom ni une coordonnee : une personne va dans contacts. L''import retire e-mails et telephones.'");
        DB::statement("COMMENT ON COLUMN events.demarche_note IS 'Note de Will sur la demarche. JAMAIS un nom ni une coordonnee : une personne va dans contacts.'");
        DB::statement("COMMENT ON COLUMN events.external_ref IS 'Identifiant stable du sourcing : cle de re-import.'");
        DB::statement("COMMENT ON COLUMN events.participation IS 'repere | inscrit | rencontre. Jamais ecrit par un import.'");
        DB::statement("COMMENT ON COLUMN events.intervention IS 'aucune | proposee | acceptee | refusee | realisee. Jamais ecrit par un import.'");
    }

    private function createEventOrganizers(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS event_organizers (
                event_id      BIGINT      NOT NULL REFERENCES events(id) ON DELETE CASCADE,
                company_id    BIGINT      NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
                workspace_id  UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (event_id, company_id)
            )
            SQL
        );

        DB::statement('CREATE INDEX IF NOT EXISTS idx_event_organizers_company ON event_organizers (company_id)');
    }

    private function applyRls(): void
    {
        foreach (['events', 'event_organizers'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON {$table}");
            DB::statement(
                "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
                 USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                 WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
            );
        }
    }
};

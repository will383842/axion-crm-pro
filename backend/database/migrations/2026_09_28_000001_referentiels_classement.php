<?php

use App\Crm\Referentiels\Classement;
use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RÉFÉRENTIELS UNIQUES — le schéma (chantier 1, 2026-09-28).
 *
 * Cette migration ne RECLASSE AUCUNE fiche : les 4,3 M de lignes de
 * `companies` sont reclassées ensuite, par lots, par la commande
 * `crm:referentiels:reclasser` (essai à blanc d'abord). Elle ne fait que ce qui
 * est instantané ou minuscule :
 *
 * 1. `companies.naf_nomenclature` et `companies.naf_rev2` — colonnes NULLABLES
 *    sans défaut, donc ajoutées sans réécrire la table (PostgreSQL ≥ 11).
 *
 *    POURQUOI les stocker, alors que `NomenclatureNaf` sait les recalculer :
 *     - 472 785 fiches portent un code de 1993 et 29 406 un code de 1973.
 *       Un filtre « code NAF commence par 62 » les rate ou les prend à tort
 *       (l'ancien 64 = postes et télécoms, pas la finance). `naf_rev2` rend le
 *       ciblage par code d'activité juste sur toute la base, par une simple
 *       colonne — et c'est sur lui que se posera le « tag métier » (sous-classe
 *       NAF, chantier suivant) ;
 *     - `naf_nomenclature` rend la reprise MESURABLE : combien de fiches
 *       restent sur une nomenclature ancienne, sans recalcul ;
 *     - le code d'origine (`naf`) n'est JAMAIS réécrit : c'est ce que l'INSEE a
 *       dit, et la conversion d'un code rév. 1 est parfois ambiguë (la table
 *       officielle donne plusieurs codes rév. 2 ; on retient le principal).
 *
 *    Les deux CHECK sont posés `NOT VALID` : posé VALIDE, un CHECK relit les
 *    4,3 M de lignes sous verrou exclusif. `NOT VALID` le fait respecter par
 *    toute écriture nouvelle ; la validation (sans verrou bloquant) est faite
 *    par la migration suivante, hors transaction.
 *
 * 2. `events.region` passe au code INSEE (`AURA` → `84`, `IDF` → `11`) : c'est
 *    le seul moyen de croiser « événements et organisations d'une même
 *    région ». Une valeur non reconnue n'est pas perdue : elle est recopiée
 *    dans `notes` avant d'être vidée. Puis un CHECK ferme la liste.
 *
 * 3. `trg_set_updated_at()` apprend à laisser `updated_at` intact quand la
 *    transaction le demande (`SET LOCAL app.conserver_updated_at = 'on'`).
 *    Le reclassement de masse touche jusqu'à 4,3 M de fiches : sans cela, il
 *    les marquerait toutes « modifiées aujourd'hui » — l'accueil annoncerait
 *    4,3 M d'enrichissements en 24 h, le tri « récent » du hub serait brouillé,
 *    et `prospection:rescrape-archives` (qui choisit les fiches les plus
 *    anciennement modifiées) repousserait sa reprise de tout son délai. Même
 *    patron que `app.autoriser_suppression_protegee` (migration
 *    `2026_09_27_000001`) : toujours `SET LOCAL`, jamais `SET`, et aucun
 *    chemin applicatif ne le pose hormis cette commande.
 *
 * Pas de CHECK sur `sector_main` ni sur `size_category` dans cette PR : posé
 * avant le reclassement, même `NOT VALID`, il ferait échouer toute mise à jour
 * d'une fiche qui porte encore une ancienne valeur (un enrichissement, une
 * édition en console). Il viendra APRÈS le reclassement, quand la commande aura
 * rendu « 0 valeur hors référentiel ».
 */
return new class extends Migration
{
    public function up(): void
    {
        // En TÊTE : `ALTER TABLE companies` attend un verrou exclusif bref ;
        // sans délai, il ferait la queue derrière une requête longue — et
        // toutes les requêtes suivantes derrière lui.
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS naf_nomenclature TEXT');
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS naf_rev2 VARCHAR(6)');

        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_naf_nomenclature_check');
        DB::statement(
            'ALTER TABLE companies ADD CONSTRAINT companies_naf_nomenclature_check
             CHECK (naf_nomenclature IS NULL OR naf_nomenclature IN (' . Taxonomy::sqlList(Taxonomy::NAF_NOMENCLATURES) . ')) NOT VALID',
        );
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_naf_rev2_check');
        DB::statement(
            "ALTER TABLE companies ADD CONSTRAINT companies_naf_rev2_check
             CHECK (naf_rev2 IS NULL OR naf_rev2 ~ '^[0-9]{2}\\.[0-9]{2}[A-Z]\$') NOT VALID",
        );

        $this->regionsDesEvenements();
        $this->conserverUpdatedAtSurDemande();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_region_check');
        // Les codes INSEE posés dans `events.region` restent : ils sont plus
        // justes que les sigles, et l'ancien écran les affichait tels quels.

        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_naf_rev2_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_naf_nomenclature_check');
        DB::statement('ALTER TABLE companies DROP COLUMN IF EXISTS naf_rev2');
        DB::statement('ALTER TABLE companies DROP COLUMN IF EXISTS naf_nomenclature');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.trg_set_updated_at()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
              NEW.updated_at = now();
              RETURN NEW;
            END;
            $fn$;
        SQL);
    }

    private function regionsDesEvenements(): void
    {
        // `events` porte une RLS FORCÉE : sans contexte d'espace, même le
        // propriétaire n'y voit AUCUNE ligne, et une conversion « réussie »
        // n'aurait rien converti. On passe donc espace par espace, contexte
        // posé LOCALEMENT à la transaction de la migration.
        foreach (DB::table('workspaces')->pluck('id') as $espace) {
            DB::select('SELECT set_config(?, ?, true)', ['app.current_workspace_id', (string) $espace]);
            $this->convertirRegions();
        }
        DB::select('SELECT set_config(?, ?, true)', ['app.current_workspace_id', '']);

        $codes = array_map(static fn (int|string $c): string => (string) $c, array_keys(Taxonomy::REGIONS));
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_region_check');
        // Le contrôle d'un CHECK lit TOUTES les lignes, RLS ou pas : une valeur
        // oubliée dans un espace ferait échouer la migration, pas passer en
        // silence.
        DB::statement(
            'ALTER TABLE events ADD CONSTRAINT events_region_check
             CHECK (region IS NULL OR region IN (' . Taxonomy::sqlList($codes) . '))',
        );
    }

    private function convertirRegions(): void
    {
        $valeurs = DB::table('events')->whereNotNull('region')->distinct()->pluck('region');

        foreach ($valeurs as $valeur) {
            $valeur = (string) $valeur;
            $code = Classement::region($valeur);
            if ($code === $valeur) {
                continue;
            }
            if ($code !== null) {
                DB::table('events')->where('region', $valeur)->update(['region' => $code]);

                continue;
            }
            // Inconnue : on la garde dans les notes, on ne la jette pas.
            DB::statement(
                "UPDATE events
                 SET notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE notes || E'\\n' || ? END,
                     region = NULL
                 WHERE region = ?",
                ['Région d\'origine : ' . $valeur, 'Région d\'origine : ' . $valeur, $valeur],
            );
        }
    }

    private function conserverUpdatedAtSurDemande(): void
    {
        // `SET search_path` recopié de `2026_08_16_200000` : un
        // `CREATE OR REPLACE` sans lui effacerait le correctif qui rend les
        // sauvegardes restaurables.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.trg_set_updated_at()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS $fn$
            BEGIN
              IF COALESCE(current_setting('app.conserver_updated_at', true), '') = 'on' THEN
                RETURN NEW;
              END IF;
              NEW.updated_at = now();
              RETURN NEW;
            END;
            $fn$;
        SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ENTITÉS SANS SIREN — une entreprise ou un organisme qui n'est pas
 * immatriculé en France ne pouvait PHYSIQUEMENT pas exister dans le CRM :
 * `siren CHAR(9) NOT NULL` ferme la porte. La prospection internationale
 * (campagne Roumanie : 568 membres CCIFER dont ~460 entités de droit roumain,
 * associations, CCI, écoles et cabinets compris) a besoin de fiches réelles
 * pour porter un site, des contacts et des emails.
 *
 * Le SIREN n'a jamais été un besoin métier ici : il servait de CLÉ DE DÉDUP.
 * On généralise donc la clé au lieu de la supprimer.
 *
 *   - `country_code`  : pays d'immatriculation (ISO 3166-1 alpha-2). DEFAULT
 *                       'FR' → les 4,29 M de fiches Sirene existantes sont
 *                       rétro-qualifiées sans réécriture (metadata-only) ;
 *   - `foreign_id`    : identifiant au registre local (CUI roumain, VAT…) ou,
 *                       à défaut, clé stable dérivée de la source
 *                       (`ccifer:<slug>`). C'est la clé de dédup des fiches
 *                       sans SIREN ;
 *   - `entity_nature` : nature de l'entité. Permet de cibler « les
 *                       organismes » dans une campagne sans les confondre
 *                       avec les entreprises.
 *
 * ── PENSÉ POUR 4,29 M DE LIGNES ─────────────────────────────────────────────
 * - `ADD COLUMN … DEFAULT <constante>` est METADATA-ONLY depuis PostgreSQL 11 :
 *   aucune réécriture de table.
 * - `ALTER COLUMN siren DROP NOT NULL` ne touche que le catalogue : aucun scan.
 * - Les CHECK sont posés `NOT VALID` puis validés séparément (`VALIDATE
 *   CONSTRAINT` prend un SHARE UPDATE EXCLUSIVE : lectures ET écritures
 *   continuent, au lieu de l'ACCESS EXCLUSIVE d'un ADD CHECK direct).
 * - L'index unique partiel est créé CONCURRENTLY dans la migration suivante
 *   (`…120002`), hors transaction.
 *
 * ── POURQUOI UN CHECK D'ANCRAGE ─────────────────────────────────────────────
 * Rendre `siren` nullable SANS garde ouvrirait la porte à des fiches sans
 * aucune clé d'identité : le funnel dédupliquerait sur du vide et fabriquerait
 * des doublons en silence, exactement le défaut que l'ancre `siren` évitait.
 * Le CHECK impose « siren OU foreign_id » : toute fiche reste rattachable.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const NATURES = [
        'entreprise', 'association', 'cci', 'enseignement',
        'cabinet', 'institution', 'media',
    ];

    public function up(): void
    {
        // Sans lock_timeout, un ALTER qui attend derrière une requête longue
        // fait la queue — et TOUTES les requêtes suivantes sur `companies`
        // font la queue derrière lui : une panne, pas une lenteur.
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement("ALTER TABLE companies ADD COLUMN IF NOT EXISTS country_code CHAR(2) NOT NULL DEFAULT 'FR'");
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS foreign_id TEXT');
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS entity_nature TEXT');

        DB::statement('ALTER TABLE companies ALTER COLUMN siren DROP NOT NULL');

        $natures = implode(', ', array_map(
            static fn (string $n): string => "'" . $n . "'",
            self::NATURES,
        ));

        $this->addCheck('companies_identity_anchor_check', 'siren IS NOT NULL OR foreign_id IS NOT NULL');
        $this->addCheck('companies_entity_nature_check', 'entity_nature IS NULL OR entity_nature IN (' . $natures . ')');
        $this->addCheck('companies_country_code_check', "country_code ~ '^[A-Z]{2}$'");

        DB::statement("COMMENT ON COLUMN companies.country_code IS 'Pays d''immatriculation (ISO 3166-1 alpha-2). FR par défaut : les fiches Sirene historiques sont françaises par construction.'");
        DB::statement("COMMENT ON COLUMN companies.foreign_id IS 'Identifiant au registre local (CUI, VAT…) ou clé stable dérivée de la source. Clé de dédup des fiches SANS siren — jamais NULL quand siren l''est.'");
        DB::statement("COMMENT ON COLUMN companies.entity_nature IS 'Nature : entreprise, association, cci, enseignement, cabinet, institution, media. Cible les organismes sans les confondre avec les entreprises.'");
    }

    public function down(): void
    {
        // On ne REMET PAS le NOT NULL sur siren : des fiches étrangères
        // peuvent déjà exister, le rollback les rendrait invalides. Refermer
        // la colonne est une décision manuelle, jamais un effet de bord.
        foreach ([
            'companies_identity_anchor_check',
            'companies_entity_nature_check',
            'companies_country_code_check',
        ] as $constraint) {
            DB::statement("ALTER TABLE companies DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        foreach (['country_code', 'foreign_id', 'entity_nature'] as $column) {
            DB::statement("ALTER TABLE companies DROP COLUMN IF EXISTS {$column}");
        }
    }

    private function addCheck(string $name, string $expression): void
    {
        DB::statement("ALTER TABLE companies DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT {$name} CHECK ({$expression}) NOT VALID");
        DB::statement("ALTER TABLE companies VALIDATE CONSTRAINT {$name}");
    }
};

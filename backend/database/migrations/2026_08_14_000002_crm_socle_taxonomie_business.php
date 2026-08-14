<?php

use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot L1 — SOCLE DE DONNÉES, volet BUSINESS (plan CRM §2.2 a/b, §2.3, §2.4 ;
 * audit scraping §B.1 et §B.5).
 *
 * Ajoute sur `companies` et `contacts` :
 *   - `relation_type`   : TYPE de relation, enum FERMÉE (champ, pas tag : mono-valué,
 *                         porte les règles d'étanchéité, pilote le cycle de vie).
 *                         Aucune valeur `candidat_*` — la frontière entre univers
 *                         est portée par le CHECK SQL, pas par une convention.
 *   - `lifecycle_stage` : ÉTAPE de la relation. ⚠️ distinct de `prospection_status`,
 *                         qui décrit l'ENRICHISSEMENT (« a-t-on un email ? »).
 *                         Les deux axes sont orthogonaux et doivent le rester.
 *   - base légale + traçabilité du consentement (`legal_basis`, `consent_version`,
 *                         `consent_at`, `consent_text_ref`, `first_info_at`) ;
 *   - `external_ref`    : identifiant de la source (`site:submission:<uuid>`),
 *                         UNIQUE par workspace → rejouer un import ne crée jamais
 *                         de doublon ;
 *   - `person_key`      : sha256(email normalisé + sel), calculé CÔTÉ SITE avant
 *                         chiffrement. Seule clé qui traverse les 9 tables sources
 *                         sans exposer l'email, et seule façon de reconnaître
 *                         qu'un `j.dupont@acme.fr` scrapé et le « Jean Dupont »
 *                         d'un formulaire sont la même personne ;
 *   - `field_origins`   : provenance par champ (`declared` > `scraped`) — un
 *                         re-scrape ne doit jamais écraser une valeur déclarée.
 *
 * ── MIGRATION ADDITIVE, PENSÉE POUR 4,29 M DE LIGNES ─────────────────────────
 *
 * - `ADD COLUMN … DEFAULT <constante>` est METADATA-ONLY depuis PostgreSQL 11 :
 *   aucune réécriture de table, même sur `companies` (4 294 898 lignes).
 * - Les contraintes CHECK sont posées `NOT VALID` puis validées séparément :
 *   `VALIDATE CONSTRAINT` prend un `SHARE UPDATE EXCLUSIVE` (lectures ET
 *   écritures continuent) au lieu de l'`ACCESS EXCLUSIVE` d'un ADD CHECK direct.
 * - Les index sont créés dans une migration SÉPARÉE, en CONCURRENTLY (cf.
 *   `2026_08_14_000005`), pour ne bloquer aucune écriture.
 *
 * ── INERTIE ─────────────────────────────────────────────────────────────────
 * Purement additive : aucune colonne existante n'est modifiée, aucune valeur
 * n'est réécrite, aucun code de production ne lit encore ces colonnes. Le
 * comportement observable est inchangé.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sans lock_timeout, un `ALTER TABLE` qui attend derrière une requête
        // longue d'un worker fait la queue — et TOUTES les requêtes suivantes
        // sur `companies` (4,29 M lignes, 3,7 Go) font la queue derrière lui :
        // une panne, pas une lenteur. On préfère échouer vite : le déploiement
        // rougit, rien n'est bloqué, on rejoue.
        DB::statement("SET LOCAL lock_timeout = '30s'");

        $this->extendCompanies();
        $this->extendContacts();
    }

    public function down(): void
    {
        foreach ([
            'relation_type', 'lifecycle_stage', 'legal_basis', 'consent_version',
            'consent_at', 'consent_text_ref', 'first_info_at', 'field_origins',
            'external_ref',
        ] as $column) {
            DB::statement("ALTER TABLE companies DROP COLUMN IF EXISTS {$column}");
        }

        foreach ([
            'person_key', 'external_ref', 'legal_basis', 'consent_version',
            'consent_at', 'consent_text_ref', 'first_info_at', 'field_origins',
        ] as $column) {
            DB::statement("ALTER TABLE contacts DROP COLUMN IF EXISTS {$column}");
        }
    }

    private function extendCompanies(): void
    {
        DB::statement("ALTER TABLE companies ADD COLUMN IF NOT EXISTS relation_type TEXT NOT NULL DEFAULT 'prospect'");
        DB::statement("ALTER TABLE companies ADD COLUMN IF NOT EXISTS lifecycle_stage TEXT NOT NULL DEFAULT 'nouveau'");
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS legal_basis TEXT');
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS consent_version TEXT');
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS consent_at TIMESTAMPTZ');
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS consent_text_ref TEXT');
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS first_info_at TIMESTAMPTZ');
        DB::statement("ALTER TABLE companies ADD COLUMN IF NOT EXISTS field_origins JSONB NOT NULL DEFAULT '{}'::jsonb");
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS external_ref TEXT');

        $this->addCheck('companies', 'companies_relation_type_check', 'relation_type IN (' . Taxonomy::sqlList(Taxonomy::BUSINESS_RELATION_TYPES) . ')');
        $this->addCheck('companies', 'companies_lifecycle_stage_check', 'lifecycle_stage IN (' . Taxonomy::sqlList(Taxonomy::BUSINESS_LIFECYCLE_STAGES) . ')');
        $this->addCheck('companies', 'companies_legal_basis_check', 'legal_basis IS NULL OR legal_basis IN (' . Taxonomy::sqlList(Taxonomy::LEGAL_BASES) . ')');

        DB::statement("COMMENT ON COLUMN companies.lifecycle_stage IS 'Étape de la RELATION commerciale. Orthogonale à prospection_status, qui décrit l''ENRICHISSEMENT (a-t-on un canal de contact ?). Ne jamais confondre les deux.'");
        DB::statement("COMMENT ON COLUMN companies.first_info_at IS 'Art. 14 RGPD : horodatage de la première communication vers une fiche collectée (base legitimate_interest_b2b), qui doit porter le bloc d''information sur l''origine des données.'");
        DB::statement("COMMENT ON COLUMN companies.field_origins IS 'Provenance par champ : declared > scraped. Un re-scrape ne réécrit jamais une valeur déclarée par la personne.'");
    }

    private function extendContacts(): void
    {
        DB::statement('ALTER TABLE contacts ADD COLUMN IF NOT EXISTS person_key TEXT');
        DB::statement('ALTER TABLE contacts ADD COLUMN IF NOT EXISTS external_ref TEXT');
        DB::statement('ALTER TABLE contacts ADD COLUMN IF NOT EXISTS legal_basis TEXT');
        DB::statement('ALTER TABLE contacts ADD COLUMN IF NOT EXISTS consent_version TEXT');
        DB::statement('ALTER TABLE contacts ADD COLUMN IF NOT EXISTS consent_at TIMESTAMPTZ');
        DB::statement('ALTER TABLE contacts ADD COLUMN IF NOT EXISTS consent_text_ref TEXT');
        DB::statement('ALTER TABLE contacts ADD COLUMN IF NOT EXISTS first_info_at TIMESTAMPTZ');
        DB::statement("ALTER TABLE contacts ADD COLUMN IF NOT EXISTS field_origins JSONB NOT NULL DEFAULT '{}'::jsonb");

        $this->addCheck('contacts', 'contacts_legal_basis_check', 'legal_basis IS NULL OR legal_basis IN (' . Taxonomy::sqlList(Taxonomy::LEGAL_BASES) . ')');

        DB::statement("COMMENT ON COLUMN contacts.person_key IS 'sha256(email normalisé + sel), calculé côté site AVANT chiffrement. Clé de rapprochement des personnes, prioritaire sur normalized_hash (qui repose sur nom+company_id et ne voit pas qu''un contact scrapé et un lead entrant sont la même personne).'");
    }

    /**
     * CHECK posé NOT VALID puis validé : `ADD CONSTRAINT … CHECK` valide
     * immédiatement sous ACCESS EXCLUSIVE (table entière verrouillée) ;
     * `VALIDATE CONSTRAINT` ne prend qu'un SHARE UPDATE EXCLUSIVE.
     * Sur 4,29 M de lignes, la différence est un déploiement bloquant ou non.
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression}) NOT VALID");
        DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VÉRIFICATION DES E-MAILS (commande `crm:emails:verifier`).
 *
 * 1. `email_domaines` — le résultat DNS PAR DOMAINE, daté : on ne résout
 *    jamais deux fois le même domaine, et on le revérifie après N jours.
 *    Table GLOBALE (sans `workspace_id`) comme `email_validations` : un
 *    domaine reçoit du courrier ou non quel que soit l'espace qui le cite.
 *    Elle ne porte AUCUNE adresse ni personne — des noms de domaine, un
 *    verdict, l'hôte MX, le résolveur et la date. Aucune colonne JSON (le
 *    compte figé de `PortabiliteCompleteTest` n'a donc pas à bouger).
 *
 *    Pourquoi pas `email_validations` : c'est un cache PAR ADRESSE de la
 *    déduplication, à durée de vie de 30 jours, PURGÉ par `retention:purge`
 *    — une adresse n'y reste pas lisible pour audit. Et `email_verification_logs`
 *    est le journal du fournisseur Hunter (quota mensuel compté dessus).
 *
 * 2. `trg_set_updated_at()` laisse aussi `contacts.updated_at` intact quand la
 *    transaction le demande (`SET LOCAL app.conserver_updated_at = 'on'`) :
 *    vérifier l'adresse d'une personne n'est pas modifier sa fiche — sans
 *    cela, ~410 000 contacts seraient marqués « modifiés aujourd'hui ». Même
 *    patron, même garde que le reclassement (migration `2026_09_28_000001`) :
 *    toujours `SET LOCAL`, jamais `SET`, rétablit l'ANCIENNE valeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS email_domaines (
                domaine     TEXT PRIMARY KEY
                            CHECK (domaine = lower(domaine) AND domaine <> '' AND length(domaine) <= 253),
                verdict     TEXT NOT NULL
                            CHECK (verdict IN ('mx', 'a', 'mx_nul', 'sans_courrier', 'inexistant')),
                mx          TEXT,
                resolveur   TEXT NOT NULL,
                resolu_le   TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS idx_email_domaines_resolu_le ON email_domaines (resolu_le);
            COMMENT ON TABLE email_domaines IS
                'crm:emails:verifier — le domaine reçoit-il du courrier (MX, sinon A/AAAA), daté. Aucune adresse. Un indéterminé n''est jamais enregistré.';
        SQL);

        $this->fonctionUpdatedAt(['companies', 'tags', 'contacts']);
    }

    public function down(): void
    {
        $this->fonctionUpdatedAt(['companies', 'tags']);
        DB::statement('DROP TABLE IF EXISTS email_domaines');
    }

    /** @param  list<string>  $tables */
    private function fonctionUpdatedAt(array $tables): void
    {
        $liste = implode(', ', array_map(static fn (string $t): string => "'" . $t . "'", $tables));

        // `SET search_path` recopié de `2026_08_16_200000` : un
        // `CREATE OR REPLACE` sans lui effacerait le correctif qui rend les
        // sauvegardes restaurables.
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION public.trg_set_updated_at()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS \$fn\$
            BEGIN
              -- Levée LIMITÉE aux tables que les écritures de masse touchent
              -- (reclassement : companies, tags ; vérification des e-mails :
              -- companies, contacts), et qui RÉTABLIT l'ancienne valeur.
              IF TG_TABLE_NAME IN ({$liste})
                 AND COALESCE(current_setting('app.conserver_updated_at', true), '') = 'on' THEN
                NEW.updated_at := OLD.updated_at;
                RETURN NEW;
              END IF;
              NEW.updated_at = now();
              RETURN NEW;
            END;
            \$fn\$;
        SQL);
    }
};

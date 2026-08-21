<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 🔴 B10-001 (S1) — « une base dont pg_partman vit dans public ne peut plus
 * JAMAIS etre reconstruite par migrate:fresh, ET LA MIGRATION QUI CORRIGE CELA
 * EST INATTEIGNABLE PAR CE CHEMIN ».
 *
 * ── CE QUI MANQUAIT, ET CE N'ETAIT PAS LE CORRECTIF ───────────────────────
 *
 * Le correctif existe depuis le 2026-08-18
 * (`2026_08_18_100001_partman_dans_son_propre_schema`), et
 * `2026_05_16_000001_create_extensions_and_helpers` porte le meme bloc. Le
 * defaut restant est un probleme d'ORDRE, pas de contenu :
 *
 *     migrate:fresh
 *       1. db:wipe  → PostgresBuilder::dropAllTables()
 *                     UN SEUL `DROP TABLE … CASCADE` sur tout le search_path
 *                     → SQLSTATE 2BP01 si part_config y est
 *       2. migrate  → LES MIGRATIONS. Jamais atteintes.
 *
 * Une migration ne peut pas reparer ce qui empeche les migrations de tourner.
 * Le geste doit avoir lieu AVANT l'etape 1 — c'est tout ce que cette classe
 * apporte : le meme SQL, appelable au bon moment.
 *
 * ── POURQUOI UNE TROISIEME COPIE DU BLOC SQL ──────────────────────────────
 *
 * Les deux copies existantes vivent dans des MIGRATIONS. Une migration doit
 * rester lisible telle qu'elle a ete jouee a sa date : la faire dependre d'une
 * classe applicative qui evoluera ensuite, c'est reecrire l'histoire de la
 * base. Cette copie-ci est celle du chemin VIVANT (Makefile, crochet
 * migrate:fresh). La garde `ReconstructionAtteignableTest` verifie ce SQL-ci
 * sur une base temoin, et `ReconstructionBaseTest` verifie le resultat.
 *
 * ── SURETE ────────────────────────────────────────────────────────────────
 *
 * pg_partman est `relocatable = false` : `ALTER EXTENSION … SET SCHEMA` est
 * refuse, il faut DROP puis CREATE, ce qui detruit `part_config`. La
 * relocalisation est donc ABANDONNEE (avec un avertissement, sans faire rougir
 * quoi que ce soit) des que `part_config` porte la moindre ligne : dans ce cas
 * pg_partman gere reellement des partitions et on ne touche a rien.
 */
final class RelocalisationPartman
{
    /** Prefixe cherche par la garde et par l'exploitation dans les journaux. */
    public const PREFIXE_JOURNAL = '[RECONSTRUCTION] pg_partman';

    /**
     * Le meme bloc que les deux migrations. `DO $$ … $$` : tout est decide dans
     * le serveur, en une seule aller-retour, et rien ne leve d'exception cote
     * PHP — un `migrate:fresh` ne doit pas mourir sur une reparation de
     * confort.
     */
    public const SQL = <<<'SQL'
        DO $$
        DECLARE
            v_schema  TEXT;
            v_lignes  BIGINT;
        BEGIN
            SELECT n.nspname
            INTO   v_schema
            FROM   pg_extension e
            JOIN   pg_namespace n ON n.oid = e.extnamespace
            WHERE  e.extname = 'pg_partman';

            IF v_schema IS NULL THEN
                RAISE NOTICE 'pg_partman absent de cette base — rien a relocaliser.';
                RETURN;
            END IF;

            IF v_schema = 'partman' THEN
                RAISE NOTICE 'pg_partman est deja dans le schema partman — rien a faire.';
                RETURN;
            END IF;

            EXECUTE format('SELECT count(*) FROM %I.part_config', v_schema) INTO v_lignes;

            IF v_lignes > 0 THEN
                RAISE WARNING 'pg_partman vit dans le schema % et gere % ensemble(s) de partitions : '
                    'relocalisation ABANDONNEE (elle detruirait part_config). La base restera NON '
                    'RECONSTRUCTIBLE par migrate:fresh tant que ce ne sera pas traite a la main.',
                    v_schema, v_lignes;
                RETURN;
            END IF;

            EXECUTE 'DROP EXTENSION pg_partman';
            EXECUTE 'CREATE SCHEMA IF NOT EXISTS partman';
            EXECUTE 'CREATE EXTENSION pg_partman SCHEMA partman';

            RAISE NOTICE 'pg_partman relocalise de % vers partman.', v_schema;
        EXCEPTION WHEN OTHERS THEN
            RAISE WARNING 'Relocalisation de pg_partman impossible (%) — la base reste non '
                'reconstructible par migrate:fresh, mais rien n''est casse.', SQLERRM;
        END $$;
        SQL;

    /**
     * Joue la relocalisation et JOURNALISE ce qui s'est passe.
     *
     * Le journal n'est pas decoratif : les `RAISE NOTICE` du bloc PL/pgSQL
     * partent sur un flux que personne ne lit. C'est exactement ce qui a permis
     * a `partman.create_parent()` d'echouer en silence pendant des mois
     * (cf. le docblock de `2026_08_18_100001`).
     *
     * Ne leve JAMAIS : appelee au demarrage de `migrate:fresh`, elle ne doit pas
     * pouvoir empecher une reconstruction pour une raison qui lui est propre.
     *
     * @param  string|null  $connexion  nom de connexion, `null` = celle par defaut
     */
    public static function jouer(?string $connexion = null): string
    {
        try {
            $avant = self::schema($connexion);

            if ($avant === null) {
                $message = self::PREFIXE_JOURNAL . ' : extension absente de cette base — rien a relocaliser.';
                Log::info($message);

                return $message;
            }

            if ($avant === 'partman') {
                $message = self::PREFIXE_JOURNAL . ' : deja dans le schema partman — rien a faire.';
                Log::info($message);

                return $message;
            }

            DB::connection($connexion)->unprepared(self::SQL);

            $apres = self::schema($connexion);

            $message = $apres === 'partman'
                ? self::PREFIXE_JOURNAL . " : relocalise de « {$avant} » vers « partman ». La base "
                    . 'redevient reconstructible par migrate:fresh.'
                : self::PREFIXE_JOURNAL . " : relocalisation ABANDONNEE, l'extension est restee dans "
                    . "« {$apres} ». part_config n'est pas vide (pg_partman gere reellement des "
                    . 'partitions) : la reconstruction reste impossible tant que ce ne sera pas traite '
                    . 'a la main.';

            Log::info($message);

            return $message;
        } catch (Throwable $e) {
            $message = self::PREFIXE_JOURNAL . ' : geste impossible (' . $e->getMessage()
                . ') — rien n\'est casse, mais la base peut rester non reconstructible.';
            Log::info($message);

            return $message;
        }
    }

    private static function schema(?string $connexion): ?string
    {
        $valeur = DB::connection($connexion)->scalar(
            "SELECT n.nspname
               FROM pg_extension e
               JOIN pg_namespace n ON n.oid = e.extnamespace
              WHERE e.extname = 'pg_partman'",
        );

        return $valeur === null ? null : (string) $valeur;
    }
}

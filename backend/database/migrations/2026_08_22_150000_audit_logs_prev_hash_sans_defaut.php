<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B16-013 — `audit_logs.prev_hash` ne doit plus avoir AUCUN défaut SQL.
 *
 * Mesure du 2026-08-22 : la migration 2026_08_16_000001 devait retirer le
 * défaut ; elle a fait un `SET DEFAULT repeat('0', 64)`. Le piège a seulement
 * changé de valeur, il n'a pas été désarmé.
 *
 * 🔴 Pourquoi un défaut, QUELLE QUE SOIT sa valeur, est nuisible ici. Une ligne
 * insérée sans `prev_hash` — un import, une reprise manuelle, un script de
 * secours — hérite du maillon zéro. Elle s'insère sans bruit, et c'est plus
 * tard que le dégât se voit : `AuditHashChain::verifyChain()` compare le
 * maillon déclaré au maillon réel et rejette la ligne dès la deuxième, en
 * criant à la falsification alors qu'il n'y en a eu aucune. Une alerte
 * d'intégrité qui crie au loup finit par n'être plus lue — et c'est ce
 * moment-là qu'une vraie falsification attend.
 *
 * Sans défaut, la colonne reste `NOT NULL` : le même INSERT échoue
 * FRANCHEMENT, au moment où l'humain est encore devant son terminal. C'est
 * l'effet voulu : bruyant tout de suite plutôt que faux plus tard.
 *
 * Le produit, lui, ne risque rien : `AuditHashChain::record()` fournit toujours
 * `prev_hash` explicitement (voir l'`insertGetId()` du service).
 *
 * On ne réécrit AUCUNE ligne existante : toucher un journal d'audit a
 * posteriori serait exactement ce que ce journal existe pour empêcher.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `audit_logs` est PARTITIONNÉE depuis 2026_05_17_000011. Un INSERT
        // passe par le parent et prend SON défaut — mais une partition créée
        // avant ce jour a figé le défaut du parent au moment de sa création, et
        // un INSERT visant directement une partition prendrait celui-là. On
        // désarme donc le parent ET chaque partition, sinon la garde serait
        // verte sur un piège toujours chargé.
        DB::statement(<<<'SQL'
            DO $$
            DECLARE
                r RECORD;
            BEGIN
                EXECUTE 'ALTER TABLE audit_logs ALTER COLUMN prev_hash DROP DEFAULT';

                FOR r IN
                    SELECT c.oid::regclass AS partition
                    FROM   pg_inherits i
                    JOIN   pg_class c ON c.oid = i.inhrelid
                    WHERE  i.inhparent = 'audit_logs'::regclass
                LOOP
                    EXECUTE format('ALTER TABLE %s ALTER COLUMN prev_hash DROP DEFAULT', r.partition);
                END LOOP;
            END $$;
        SQL);
    }

    public function down(): void
    {
        // On remet `repeat('0', 64)`, l'état d'AVANT ce correctif — surtout pas
        // `'GENESIS'`, qui produirait un maillon de largeur non-SHA256 et
        // casserait l'invariant « prev_hash est toujours un digest 64-hex ».
        DB::statement("ALTER TABLE audit_logs ALTER COLUMN prev_hash SET DEFAULT repeat('0', 64)");
    }
};

<?php

/**
 * GARDE n°2 — la conversion d'`audit_logs` en table partitionnée ne doit pas
 * perdre (ni refuser) les lignes déjà présentes.
 *
 * Panne historique (reproduite le 2026-08-18, cf.
 * `_REPORTS/2026-08-18_RECONSTRUCTION-BASE.md`) :
 *
 *     2026_05_17_000011_setup_pg_partman_audit_logs ......... 1 s FAIL
 *     SQLSTATE[23514]: Check violation: 7 ERROR:
 *       no partition of relation "audit_logs" found for row
 *     DETAIL:  Partition key of the failing row contains (created_at) = (…)
 *     CONTEXT: SQL statement "INSERT INTO audit_logs SELECT * FROM audit_logs_old"
 *
 * Mécanique : la migration sauvegardait `audit_logs` dans `audit_logs_old`,
 * recréait `audit_logs` en `PARTITION BY RANGE (created_at)`, puis réinjectait
 * les lignes — AVANT d'avoir créé la moindre partition. Toute base portant au
 * moins une ligne d'audit (c'est-à-dire toute base ayant servi) tombait dessus.
 *
 * Ce test rejoue la vraie migration sur un `audit_logs` remis à plat et peuplé.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('convertit audit_logs en table partitionnée sans perdre les lignes déjà présentes', function () {
    // Remet `audit_logs` dans son état d'AVANT la migration 000011 : table
    // plate, portant des lignes dont une datée de MAINTENANT — c'est celle-là
    // qui faisait tomber la conversion.
    DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS audit_logs CASCADE;
        CREATE TABLE audit_logs (
            id              BIGSERIAL PRIMARY KEY,
            workspace_id    UUID,
            user_id         UUID REFERENCES users(id) ON DELETE SET NULL,
            event_type      TEXT NOT NULL,
            path            TEXT,
            status_code     SMALLINT,
            ip              INET,
            user_agent      TEXT,
            payload_hash    TEXT,
            prev_hash       TEXT NOT NULL DEFAULT 'GENESIS',
            current_hash    TEXT NOT NULL,
            created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
        );
    SQL);

    DB::table('audit_logs')->insert([
        ['event_type' => 'garde.reconstruction', 'current_hash' => 'maintenant', 'created_at' => now()],
        ['event_type' => 'garde.reconstruction', 'current_hash' => 'tres.vieux', 'created_at' => '2019-01-15 08:00:00+00'],
        ['event_type' => 'garde.reconstruction', 'current_hash' => 'tres.futur', 'created_at' => '2031-06-01 08:00:00+00'],
    ]);

    // Rejoue la vraie migration, exactement comme sur une base neuve.
    $migration = require database_path('migrations/2026_05_17_000011_setup_pg_partman_audit_logs.php');
    $migration->up();

    $partitionnee = DB::selectOne(<<<'SQL'
        SELECT EXISTS (
            SELECT 1
            FROM pg_partitioned_table p
            JOIN pg_class c ON c.oid = p.partrelid
            WHERE c.relname = 'audit_logs'
        ) AS oui
    SQL);

    expect((bool) $partitionnee->oui)
        ->toBeTrue('audit_logs devrait être partitionnée après la migration 000011.');

    expect(DB::table('audit_logs')->where('event_type', 'garde.reconstruction')->count())
        ->toBe(3, 'Les 3 lignes sauvegardées auraient dû être réinjectées dans la table partitionnée.');

    // Et la table doit rester ouverte à des dates arbitraires (partition DEFAULT
    // ou partition pg_partman) — sans quoi l'application se mettrait à refuser
    // des écritures d'audit en production.
    DB::table('audit_logs')->insert([
        'event_type' => 'garde.apres', 'current_hash' => 'apres', 'created_at' => '2040-02-03 00:00:00+00',
    ]);

    expect(DB::table('audit_logs')->where('event_type', 'garde.apres')->count())->toBe(1);
});

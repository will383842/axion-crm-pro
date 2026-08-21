<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A07-002 — SUPPRESSION DE LA POLICY PERMISSIVE SURVIVANTE SUR
 * `email_verification_logs`.
 *
 * ── Ce qui n'allait pas (mesuré, pas déduit) ─────────────────────────────────
 *
 * MESURÉ le 2026-08-20 sur `axion_crm_test_lot7`, rôle applicatif `axion_app`,
 * deux espaces semés d'une ligne chacun, contexte workspace RETIRÉ :
 *
 *     SELECT count(*) FROM email_verification_logs  →  2   (attendu : 0)
 *
 * La table portait DEUX policies. Postgres combine les policies PERMISSIVES
 * par OU logique : il suffit qu'UNE laisse passer pour que la ligne sorte.
 *
 *   1. `email_verif_workspace_isolation` — créée par
 *      `2026_05_19_000001_create_email_verification_logs.php` :
 *
 *          workspace_id::text = COALESCE(
 *              NULLIF(current_setting('app.current_workspace_id', true), ''),
 *              workspace_id::text)
 *
 *      Sans contexte, le `NULLIF` rend NULL, le `COALESCE` retombe donc sur
 *      `workspace_id::text`, et le prédicat devient
 *      `workspace_id::text = workspace_id::text` : TOUJOURS VRAI.
 *      C'est le repli permissif « pas de contexte ⇒ je vois tout », c'est-à-dire
 *      exactement ce que le lot L0 avait entrepris de supprimer partout.
 *
 *   2. `email_verification_logs_workspace_isolation` — créée par le durcissement
 *      `2026_08_14_000001_harden_workspace_isolation.php`, stricte :
 *
 *          workspace_id::text = NULLIF(current_setting('app.current_workspace_id', true), '')
 *
 * ── Pourquoi le durcissement l'a ratée ───────────────────────────────────────
 *
 * La migration de durcissement remplace chaque policy par sa version stricte au
 * moyen de :
 *
 *     DROP POLICY IF EXISTS <table>_workspace_isolation ON <table>;
 *
 * Le nom de la policy de 2026_05_19 est RACCOURCI — `email_verif_…` et non
 * `email_verification_logs_…`. Le `DROP` n'a donc rien trouvé (le `IF EXISTS`
 * l'a rendu silencieux), et la policy stricte s'est ajoutée À CÔTÉ de la
 * permissive au lieu de la remplacer. Le test structurel qui aurait dû le voir
 * cherchait `qual LIKE '%IS NULL%'` : il ne voyait pas la forme `COALESCE`.
 * Un détecteur écrit sur la FORME d'un repli en rate les autres formes.
 *
 * ── Portée du défaut ─────────────────────────────────────────────────────────
 *
 * Cette table contient des ADRESSES E-MAIL et la réponse brute du prestataire
 * de vérification (`raw_response` JSONB, Hunter.io). La fuite est réelle, pas
 * théorique — sous réserve que le rôle applicatif soit armé : tant que
 * `CRM_DB_APP_ROLE_ENABLED` reste à false, l'application se connecte avec
 * `axion`, SUPERUSER + BYPASSRLS, pour qui AUCUNE policy ne s'applique de toute
 * façon (cf. F36-007). Ce correctif rend la barrière correcte ; il ne l'arme pas.
 *
 * ── Balayage des sites jumeaux (patron A-011) ────────────────────────────────
 *
 * MESURÉ le 2026-08-20 sur le schéma `public` complet : sur l'ensemble des
 * tables portant `workspace_id`, `email_verif_workspace_isolation` est la SEULE
 * policy dont le nom s'écarte du canon `<table>_workspace_isolation`. Il n'y a
 * donc pas d'autre site à corriger, et
 * `tests/Feature/Rgpd/CloisonnementJournauxVerificationEmailTest.php`
 * (test « aucune table scopee ne porte de policy hors du nom canonique »)
 * empêche qu'un deuxième s'installe en silence.
 *
 * Gardes : `tests/Feature/Rgpd/CloisonnementJournauxVerificationEmailTest.php`.
 */
return new class extends Migration
{
    private const TABLE = 'email_verification_logs';

    private const POLICY_PERMISSIVE = 'email_verif_workspace_isolation';

    private const POLICY_STRICTE = 'email_verification_logs_workspace_isolation';

    private const PREDICAT_STRICT =
        "workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')";

    public function up(): void
    {
        // La table est créée conditionnellement par 2026_05_19_000001
        // (`if (Schema::hasTable(...)) return;`) : on ne présume pas de sa
        // présence sur une base partiellement migrée.
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS ' . self::POLICY_PERMISSIVE . ' ON ' . self::TABLE);

        // ⚠️ Retirer la permissive SANS s'assurer que la stricte existe
        // laisserait la table sous RLS active et SANS aucune policy — Postgres
        // refuse alors TOUTE ligne, y compris avec le bon contexte. On passerait
        // d'une fuite à une panne. On (re)pose donc la policy stricte de façon
        // idempotente, avec le prédicat EXACT du durcissement L0.
        DB::statement('ALTER TABLE ' . self::TABLE . ' ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE ' . self::TABLE . ' FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS ' . self::POLICY_STRICTE . ' ON ' . self::TABLE);
        DB::statement(sprintf(
            'CREATE POLICY %s ON %s FOR ALL USING (%s) WITH CHECK (%s)',
            self::POLICY_STRICTE,
            self::TABLE,
            self::PREDICAT_STRICT,
            self::PREDICAT_STRICT,
        ));
    }

    public function down(): void
    {
        // 🔴 CE ROLLBACK REMET LA FUITE. Il existe pour que la migration soit
        // un vrai inverse, pas parce qu'il serait souhaitable de le jouer :
        // recréer `email_verif_workspace_isolation` rouvre, sans contexte
        // workspace, la lecture de TOUTES les lignes de TOUS les espaces.
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE POLICY email_verif_workspace_isolation
                ON email_verification_logs
                FOR ALL
                USING (
                    workspace_id::TEXT = COALESCE(
                        NULLIF(current_setting('app.current_workspace_id', true), ''),
                        workspace_id::TEXT
                    )
                );
        SQL);
    }
};

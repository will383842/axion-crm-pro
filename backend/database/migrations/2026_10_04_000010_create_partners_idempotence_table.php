<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot N11 — REGISTRE D'IDEMPOTENCE du futur canal Axion Partners.
 *
 * Une ligne par (route, `Idempotency-Key`) acceptée sur une route signée du
 * canal (aujourd'hui la seule route technique `POST /api/internal/partners/v1/ping`).
 * Elle sert à rejouer la réponse d'une reprise (même clé, même corps) et à
 * refuser en 409 une clé réutilisée pour un autre corps. Ce n'est PAS un
 * journal d'événements : aucune charge n'y est stockée.
 *
 * MINIMISATION : route, clé, empreinte sha256 du corps, code de réponse,
 * résumé de réponse (64 caractères, jamais de donnée personnelle — pour le
 * ping, le mode du canal à la réception) et date. Jamais le corps, jamais la
 * réponse complète, jamais une adresse IP.
 *
 * Unicité sur (route, cle_idempotence) : une même clé envoyée à deux routes
 * désigne deux opérations. `code_reponse` borné à 100-599.
 *
 * Migration ADDITIVE, rejouable (rien n'est créé si la table existe déjà).
 *
 * ⛔ Le ping n'est PAS une sonde de supervision : chaque appel accepté laisse
 * une ligne, sans purge (≈ 200 Mo/an pour un appel par minute, sans valeur).
 *
 * ── Droits ───────────────────────────────────────────────────────────────
 * Le rôle applicatif (`axion_app`) LIT (rejouer une réponse) et INSÈRE ; il ne
 * modifie ni ne supprime : une ligne d'idempotence ne change pas après coup.
 * Les privilèges par défaut de `2026_08_14_000001_harden_workspace_isolation`
 * lui donneraient aussi UPDATE/DELETE : on les retire explicitement.
 * ⚠️ Relancer à la main le `GRANT … ON ALL TABLES` de cette migration du 14/08
 * (réparation de droits) les RÉTABLIRAIT en silence : rappelé dans le
 * commentaire SQL de la table, et `CanalPartnersSocleTest` rougit alors (test
 * jamais sauté en CI).
 *
 * Pas de RLS : table technique sans `workspace_id` (comme `job_batches`,
 * `cache`, `sessions`). Aucune route ne la lit pour l'exposer (testé).
 *
 * ── Retour arrière ───────────────────────────────────────────────────────
 * Sans risque tant que seul le ping écrit. ⚠️ Dès qu'une première route MÉTIER
 * écrira ici, un `down()` en production effacerait la mémoire d'idempotence :
 * une reprise rejouée après coup RÉEXÉCUTERAIT le traitement. Tout `down()` en
 * production à partir de ce moment exige un ADR.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partners_idempotence')) {
            Schema::create('partners_idempotence', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('route', 64);
                $table->string('cle_idempotence', 128);
                $table->char('empreinte_corps', 64);
                $table->smallInteger('code_reponse');
                $table->string('resume_reponse', 64)->nullable();
                $table->timestampTz('recu_le')->useCurrent();
                $table->unique(['route', 'cle_idempotence']);
            });

            DB::statement('ALTER TABLE partners_idempotence ADD CONSTRAINT partners_idempotence_code_reponse_http '
                . 'CHECK (code_reponse BETWEEN 100 AND 599)');
            DB::statement("COMMENT ON TABLE partners_idempotence IS 'Lot N11 — registre d''idempotence du canal Partners "
                . '(aucune charge, aucune donnée personnelle). Rôle applicatif : SELECT, INSERT SEULEMENT. '
                . 'ATTENTION : relancer le GRANT ON ALL TABLES de 2026_08_14_000001_harden_workspace_isolation '
                . "rétablirait UPDATE/DELETE — les retirer à nouveau (REVOKE UPDATE, DELETE, TRUNCATE).'");
        }

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT ON public.partners_idempotence TO ' . $role);
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.partners_idempotence FROM ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.partners_idempotence_id_seq TO ' . $role);
        }
    }

    /**
     * Retour arrière : retire la table. Voir l'en-tête — sans risque tant que
     * seul le ping écrit ; ADR obligatoire ensuite.
     */
    public function down(): void
    {
        Schema::dropIfExists('partners_idempotence');
    }
};

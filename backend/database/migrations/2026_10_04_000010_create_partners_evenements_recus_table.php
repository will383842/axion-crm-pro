<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot N11 — TABLE D'IDEMPOTENCE du futur canal Axion Partners.
 *
 * Une ligne par `Idempotency-Key` acceptée sur une route signée du canal
 * (aujourd'hui la seule route technique `POST /api/internal/partners/v1/ping`).
 * Elle sert à rejouer la réponse d'une reprise (même clé, même corps) et à
 * refuser en 409 une clé réutilisée pour un autre corps.
 *
 * MINIMISATION : la table ne porte QUE la clé, l'empreinte sha256 du corps, la
 * date de réception et le code de réponse. Jamais le corps, jamais la réponse,
 * jamais une adresse IP ni une donnée personnelle.
 *
 * Migration ADDITIVE : une table neuve, aucune table existante touchée.
 * Rejouable : rien n'est créé si la table existe déjà.
 *
 * ── Droits ───────────────────────────────────────────────────────────────
 * Le rôle applicatif (`axion_app`) LIT (rejouer une réponse) et INSÈRE ; il ne
 * modifie ni ne supprime : une ligne d'idempotence ne change pas après coup.
 * Les privilèges par défaut de `2026_08_14_000001_harden_workspace_isolation`
 * lui donneraient aussi UPDATE/DELETE : on les retire explicitement.
 *
 * Pas de RLS : table technique sans `workspace_id` (comme `job_batches`,
 * `cache`, `sessions`). Aucune route ne la lit pour l'exposer : garde
 * `tests/Feature/Internal/CanalPartnersSocleTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partners_evenements_recus')) {
            Schema::create('partners_evenements_recus', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('cle_idempotence', 128)->unique();
                $table->char('empreinte_corps', 64);
                $table->smallInteger('code_reponse');
                $table->timestampTz('recu_le')->useCurrent();
            });
        }

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT ON public.partners_evenements_recus TO ' . $role);
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.partners_evenements_recus FROM ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.partners_evenements_recus_id_seq TO ' . $role);
        }
    }

    /**
     * Retour arrière : retire la table. Elle ne porte que des clés techniques
     * et des empreintes ; aucune donnée métier n'y vit.
     */
    public function down(): void
    {
        Schema::dropIfExists('partners_evenements_recus');
    }
};

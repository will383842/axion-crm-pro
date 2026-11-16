<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi C1-0 — colonne `envoi_messages.id_injection_serveur` (cahier C1-F2A §1.3). MIGRATION SEULE : aucun code ne l'utilise encore.
 *
 * `id_injection` recevra (lot C1-1) l'identifiant STABLE choisi par le CRM, celui du registre ; l'identifiant rendu par le
 * serveur quand il en diffère (`sim-N` du faux serveur, identifiant Kumo) va dans cette colonne. NULLABLE, sans défaut :
 * l'ajout ne réécrit aucune ligne. Aucune donnée n'est lue ni modifiée.
 *
 * Verrous courts : `lock_timeout` borne l'attente ; le CHECK est posé NOT VALID (aucune relecture de la table sous verrou fort)
 * puis validé (VALIDATE CONSTRAINT ne prend qu'un verrou SHARE UPDATE EXCLUSIVE). Rejouable : ADD COLUMN IF NOT EXISTS ;
 * le DROP CONSTRAINT IF EXISTS ne vise que la contrainte créée ici, jamais une donnée.
 *
 * Droits : rien à ajouter. Le rôle applicatif a déjà SELECT, INSERT, UPDATE au niveau de la TABLE (2026_11_10_000032), ce qui couvre
 * une colonne ajoutée ; le test de schéma le prouve. RLS inchangée (FORCE).
 *
 * `down()` INERTE (corps vide voulu, NR-17) : retirer la colonne effacerait les identifiants serveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET lock_timeout = '10s'");
        try {
            DB::statement('ALTER TABLE envoi_messages ADD COLUMN IF NOT EXISTS id_injection_serveur TEXT');
            DB::statement('ALTER TABLE envoi_messages DROP CONSTRAINT IF EXISTS envoi_messages_id_injection_serveur_longueur');
            DB::statement(
                'ALTER TABLE envoi_messages ADD CONSTRAINT envoi_messages_id_injection_serveur_longueur
                 CHECK (id_injection_serveur IS NULL OR char_length(id_injection_serveur) BETWEEN 1 AND 200) NOT VALID',
            );
            DB::statement('ALTER TABLE envoi_messages VALIDATE CONSTRAINT envoi_messages_id_injection_serveur_longueur');
            DB::statement(
                "COMMENT ON COLUMN envoi_messages.id_injection_serveur IS
                 'C1 : identifiant rendu par le serveur quand il differe de l''identifiant du registre (envoi_messages.id_injection).'",
            );
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    /** Retour arrière INERTE : la colonne et la contrainte sont conservées. */
    public function down(): void {}
};

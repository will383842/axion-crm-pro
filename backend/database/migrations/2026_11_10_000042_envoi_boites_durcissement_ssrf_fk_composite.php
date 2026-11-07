<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L4 (audit sécurité) — durcissement des boîtes SMTP. ADDITIF SEULEMENT :
 * contraintes ajoutées, AUCUNE donnée modifiée ni effacée, aucune colonne retirée.
 *
 *  1. « aucun » (pas de chiffrement) n'est admis que pour la boucle locale 127.0.0.1
 *     (faux serveur de test) : jamais d'identifiants en clair vers un hôte réel.
 *  2. Ports : liste blanche 25, 465, 587, 2525 (sauf la boucle locale de test). Défense en
 *     profondeur de la garde applicative anti-SSRF.
 *  3. Clé étrangère COMPOSITE (workspace_id, boite_id) : une injection ne peut pas viser la
 *     boîte d'un autre espace.
 *
 * Les contraintes 1 et 2 sont posées NOT VALID : elles s'appliquent à toute écriture nouvelle
 * sans parcourir ni rejeter les lignes déjà présentes (on n'efface rien, on ne bloque pas le déploiement).
 * `down()` ne retire que ce que cette migration a ajouté.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE envoi_boites DROP CONSTRAINT IF EXISTS envoi_boites_aucun_boucle_locale');
        DB::statement("ALTER TABLE envoi_boites ADD CONSTRAINT envoi_boites_aucun_boucle_locale
            CHECK (chiffrement <> 'aucun' OR hote = '127.0.0.1') NOT VALID");

        DB::statement('ALTER TABLE envoi_boites DROP CONSTRAINT IF EXISTS envoi_boites_port_liste_blanche');
        DB::statement("ALTER TABLE envoi_boites ADD CONSTRAINT envoi_boites_port_liste_blanche
            CHECK (port IN (25, 465, 587, 2525) OR hote = '127.0.0.1') NOT VALID");

        DB::statement('ALTER TABLE envoi_boites DROP CONSTRAINT IF EXISTS envoi_boites_workspace_id_id_unique');
        DB::statement('ALTER TABLE envoi_boites ADD CONSTRAINT envoi_boites_workspace_id_id_unique UNIQUE (workspace_id, id)');

        DB::statement('ALTER TABLE envoi_injections_smtp DROP CONSTRAINT IF EXISTS envoi_injections_smtp_boite_workspace_fk');
        DB::statement('ALTER TABLE envoi_injections_smtp ADD CONSTRAINT envoi_injections_smtp_boite_workspace_fk
            FOREIGN KEY (workspace_id, boite_id) REFERENCES envoi_boites (workspace_id, id)');
    }

    /** Retour réel : ne retire que les contraintes posées ici. */
    public function down(): void
    {
        DB::statement('ALTER TABLE envoi_injections_smtp DROP CONSTRAINT IF EXISTS envoi_injections_smtp_boite_workspace_fk');
        DB::statement('ALTER TABLE envoi_boites DROP CONSTRAINT IF EXISTS envoi_boites_workspace_id_id_unique');
        DB::statement('ALTER TABLE envoi_boites DROP CONSTRAINT IF EXISTS envoi_boites_port_liste_blanche');
        DB::statement('ALTER TABLE envoi_boites DROP CONSTRAINT IF EXISTS envoi_boites_aucun_boucle_locale');
    }
};

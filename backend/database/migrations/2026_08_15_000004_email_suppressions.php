<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LISTE DE SUPPRESSION — les adresses auxquelles on ne doit plus jamais écrire.
 *
 * Le plan (§2.7) supposait `dnc_entries` et `unsubscribes` présentes en
 * « scaffold ». Vérifié le 2026-08-15 : elles N'EXISTENT PAS, ni l'une ni
 * l'autre, ni `email_events`. Le CRM savait donc enregistrer une opposition
 * volontaire (`opt_out`) mais RIEN d'un rebond ni d'une plainte.
 *
 * Pourquoi ce n'est pas cosmétique : les fournisseurs mesurent le taux de
 * rebond et de plainte. Au-delà d'environ 2 % de rebonds durs ou 0,1 % de
 * plaintes, Gmail et Outlook dégradent la délivrabilité puis rejettent. Sans
 * cette table, la deuxième campagne réécrit aux adresses mortes de la
 * première — c'est le mécanisme exact par lequel un domaine d'envoi se fait
 * blacklister.
 *
 * ── Pourquoi une table SÉPARÉE de `opt_out` ──────────────────────────────
 * On aurait pu tout empiler dans `opt_out`. Ce serait faux : une opposition
 * est une VOLONTÉ (elle a une valeur juridique, elle se prouve, elle ne
 * s'efface jamais), un rebond est un FAIT TECHNIQUE (l'adresse n'existe plus,
 * et elle peut réapparaître si l'entreprise recrée la boîte). Les confondre
 * rendrait impossible de répondre à « cette personne s'est-elle opposée ? » —
 * la seule question que pose la CNIL.
 *
 * ── Rebonds TEMPORAIRES ──────────────────────────────────────────────────
 * Une boîte pleine n'est pas une adresse morte. On compte les occurrences et
 * c'est le SERVICE qui décide du seuil : supprimer au premier rebond mou
 * jetterait des contacts valides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function ($table) {
            $table->bigIncrements('id');

            // Univers : les deux mondes sont étanches. Un désabonnement du
            // vivier candidats ne vaut pas suppression commerciale — même
            // frontière que `opt_out.scope`.
            $table->string('scope')->default('business');

            // L'adresse en clair ET son empreinte : les signaux venus du site
            // arrivent hachés, ceux d'un fournisseur d'envoi arrivent en
            // clair. Une liste qui ne reconnaîtrait qu'une forme ne garderait
            // rien la moitié du temps.
            $table->text('email')->nullable();
            $table->text('email_hash')->nullable();

            $table->string('reason');
            $table->string('source');

            // Combien de fois le signal est arrivé — c'est ce qui distingue un
            // incident isolé d'une adresse réellement morte.
            $table->unsignedInteger('occurrences')->default(1);

            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestampTz('last_seen_at')->useCurrent();
            $table->jsonb('metadata')->nullable();
        });

        // `citext` : les adresses ne sont pas sensibles à la casse, et
        // `Contact@X.fr` doit heurter la même entrée que `contact@x.fr`.
        DB::statement('ALTER TABLE email_suppressions ALTER COLUMN email TYPE citext USING email::citext');

        DB::statement("ALTER TABLE email_suppressions ADD CONSTRAINT email_suppressions_scope_check
            CHECK (scope IN ('business', 'vivier'))");

        // Vocabulaire FERMÉ : une raison libre deviendrait vite « bounce »,
        // « BOUNCE » et « rebond » dans la même colonne, et aucun décompte ne
        // serait fiable.
        DB::statement("ALTER TABLE email_suppressions ADD CONSTRAINT email_suppressions_reason_check
            CHECK (reason IN ('hard_bounce', 'complaint', 'soft_bounce_threshold', 'manual', 'invalid_syntax'))");

        // Au moins une forme d'identification, sinon la ligne ne peut heurter
        // personne : une entrée qui ne bloque rien est pire qu'absente.
        DB::statement('ALTER TABLE email_suppressions ADD CONSTRAINT email_suppressions_identifiable_check
            CHECK (email IS NOT NULL OR email_hash IS NOT NULL)');

        // Unicité par univers : un second rebond incrémente la ligne, il n'en
        // crée pas une deuxième.
        DB::statement('CREATE UNIQUE INDEX email_suppressions_scope_email_key
            ON email_suppressions (scope, email) WHERE email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX email_suppressions_scope_hash_key
            ON email_suppressions (scope, email_hash) WHERE email_hash IS NOT NULL');
        DB::statement('CREATE INDEX email_suppressions_reason_idx ON email_suppressions (scope, reason)');
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};

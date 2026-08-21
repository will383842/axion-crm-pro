<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX TRIGRAMMES POUR LA PALETTE ⌘K — constat P6-UI-002 (S0).
 *
 * POURQUOI CETTE MIGRATION EXISTE, ET POURQUOI ELLE EST INDISSOCIABLE DU
 * CORRECTIF QU'ELLE ACCOMPAGNE.
 *
 * `GET /search` rendait trois tableaux vides. Le correctif la fait chercher pour
 * de bon — et une palette de recherche doit trouver **« Boulangerie Martin »
 * quand on tape « Martin »**. C'est le cas normal d'usage : personne ne saisit
 * le premier mot d'une raison sociale.
 *
 * 🔴 Or `ILIKE '%martin%'` **ne peut utiliser aucun index B-tree**. `G41-003` a
 * mesuré exactement ce motif sur la liste entreprises : **65 secondes** au
 * volume de production (4 295 349 lignes) — et l'index de 110 Mo censé le servir
 * portait sur une autre colonne.
 *
 * Livrer la recherche sans cet index reviendrait donc à remplacer un défaut
 * (« la palette ne trouve rien ») par un autre, pire (« la palette gèle
 * l'application à chaque frappe »). **Les deux moitiés du correctif doivent
 * atterrir ensemble.**
 *
 * ── CE QUE FAIT UN INDEX TRIGRAMMES ────────────────────────────────────────
 *
 * `pg_trgm` découpe le texte en groupes de trois caractères et les indexe en
 * GIN. `%martin%` devient alors une recherche sur les trigrammes `mar`, `art`,
 * `rti`, `tin` — servie par l'index, sans balayage séquentiel.
 *
 * L'extension est DÉJÀ INSTALLÉE sur cette base (vérifié : `pg_extension`
 * contient `pg_trgm` et `unaccent`). On ne l'ajoute donc pas, on s'en sert.
 *
 * ── `CONCURRENTLY`, ET POURQUOI LA MIGRATION SORT DE SA TRANSACTION ────────
 *
 * `CREATE INDEX` pose un `SHARE` lock : sur `companies`, en production, il
 * bloquerait toute écriture pendant la durée de construction. `CONCURRENTLY`
 * l'évite — mais Postgres **refuse** `CONCURRENTLY` dans une transaction, et
 * Laravel enveloppe les migrations dans une transaction par défaut.
 *
 * D'où `$withinTransaction = false`. C'est la seule façon correcte, et elle a un
 * prix qu'il faut connaître : **une création concurrente qui échoue laisse un
 * index `INVALID` derrière elle**. On le nettoie donc explicitement au `down()`,
 * et le `up()` est écrit pour être rejouable.
 */
return new class extends Migration
{
    /** `CREATE INDEX CONCURRENTLY` est interdit dans une transaction. */
    public $withinTransaction = false;

    /**
     * @var list<array{table: string, index: string, colonne: string}>
     */
    private array $cibles = [
        // ⚠️ `companies` N'EST PAS DANS CETTE LISTE, ET C'EST DELIBERE.
        //
        // Un index `idx_companies_denomination_trgm` EXISTAIT DEJA depuis le
        // 2026-05-16 -- mais sur `denomination_normalized`, une colonne
        // GENERATED que Postgres maintient seul. La premiere version de cette
        // migration le recreait « sur `denomination` » sous LE MEME NOM : le
        // `IF NOT EXISTS` a donc silencieusement ne RIEN faire, et la recherche
        // est restee en Seq Scan pendant que la migration se declarait passee.
        //
        // C'est mot pour mot le constat `G41-003` : « l'index de 110 Mo cense
        // le servir porte sur une AUTRE colonne ». Le correctif n'est pas
        // d'ajouter un index, c'est de chercher sur la bonne colonne -- ce que
        // fait desormais `GlobalSearchController`.
        //
        // Garde : `tests/Feature/Infra/IndexServentLesRequetesTest.php`.
        ['table' => 'contacts', 'index' => 'idx_contacts_last_name_trgm', 'colonne' => 'last_name'],
        ['table' => 'contacts', 'index' => 'idx_contacts_first_name_trgm', 'colonne' => 'first_name'],
        ['table' => 'tags', 'index' => 'idx_tags_name_trgm', 'colonne' => 'name'],
    ];

    public function up(): void
    {
        // L'extension doit être là. Elle l'est sur cette base, mais une
        // migration qui le SUPPOSE casse le jour où quelqu'un reconstruit
        // ailleurs. On le dit plutôt que de le croire.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach ($this->cibles as $cible) {
            if (! Schema::hasTable($cible['table'])
                || ! Schema::hasColumn($cible['table'], $cible['colonne'])) {
                continue;
            }

            // `IF NOT EXISTS` rend la migration rejouable — indispensable avec
            // `CONCURRENTLY`, qui peut échouer à mi-course et laisser un index
            // INVALIDE qu'il faudra reprendre.
            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s USING gin (%s gin_trgm_ops)',
                $cible['index'],
                $cible['table'],
                $cible['colonne'],
            ));
        }
    }

    public function down(): void
    {
        foreach ($this->cibles as $cible) {
            DB::statement(sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $cible['index']));
        }
    }
};

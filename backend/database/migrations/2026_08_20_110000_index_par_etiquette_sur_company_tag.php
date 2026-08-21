<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX « PAR ÉTIQUETTE » SUR `company_tag` — constat `G41-001` (S0).
 *
 * ── LE DÉFAUT, ET POURQUOI IL A SURVÉCU SI LONGTEMPS ────────────────────────
 *
 * `company_tag` porte une clé primaire `(company_id, tag_id)`. Elle répond donc
 * très bien à « quelles étiquettes porte CETTE fiche ? » — et **pas du tout** à
 * « quelles fiches portent CETTE étiquette ? », parce que `tag_id` n'y est pas
 * la colonne de tête. Il n'existait aucun autre index utile : `\d company_tag`
 * ne montrait que la clé primaire et `idx_company_tag_workspace (workspace_id)`,
 * lequel ne discrimine rien (un seul workspace porte 4,29 M de fiches).
 *
 * L'index EXISTAIT pourtant — sur la table JUMELLE. `candidate_tag` porte
 * `idx_candidate_tag_tag (tag_id)` depuis le 2026-08-14
 * (`2026_08_14_000003_crm_socle_vivier_candidats.php:199`). Il n'a jamais été
 * porté sur `company_tag`, la seule des deux qui porte le volume de production.
 *
 * C'est le patron `A-011` du dépôt, dans sa forme la plus pure : **le correctif
 * existait déjà à deux pas, sur la table d'à côté.**
 *
 * ── CE QUE ÇA COÛTAIT, MESURÉ ───────────────────────────────────────────────
 *
 * L'écran d'accueil de la console (`temperature=actifs`, le défaut) demande
 * « cette fiche a-t-elle une provenance autre que le scraping ? ». Sans index
 * par étiquette, Postgres BALAIE `company_tag` en entier pour y répondre.
 *
 * Mesuré sur 150 000 fiches / 300 300 étiquettes, requête de l'écran d'accueil :
 *
 *     identifiants littéraux, SANS cet index ... Parallel Seq Scan   80,3 ms
 *     identifiants littéraux, AVEC cet index ... Index Only Scan      0,90 ms
 *
 * 89 fois. Et en production l'écart est bien pire que proportionnel : la table
 * de hachage construite par le balayage finit par déborder `work_mem`, et
 * Postgres retombe alors en ré-exécution ligne par ligne. C'est la falaise des
 * 3 minutes du constat `G41-001`.
 *
 * ── ⚠️ CET INDEX NE SUFFIT PAS À LUI SEUL ───────────────────────────────────
 *
 * Mesuré, et c'est contre-intuitif : posé SEUL, il ne change RIEN (73,8 ms).
 * Tant que le sous-select joint `tags`, Postgres estime très mal la sélectivité
 * (225 225 lignes estimées contre 300 réelles) et préfère le balayage. Il faut
 * AUSSI que le contrôleur résolve les identifiants d'étiquettes en amont et les
 * passe en valeurs — c'est fait dans
 * `ContactsHubController::applyTemperature()`. **Les deux moitiés atterrissent
 * ensemble, ou aucune ne sert.**
 *
 * ── LA FORME `(tag_id, company_id)` ─────────────────────────────────────────
 *
 * Deux colonnes, et non `(tag_id)` seul comme sur la table jumelle : la requête
 * ne veut QUE `company_id` en sortie, donc l'index la COUVRE et Postgres rend
 * un `Index Only Scan` — aucun accès au tas une fois la carte de visibilité à
 * jour. À ce volume les deux formes se valent à la mesure (0,67 ms contre
 * 0,79 ms, dans le bruit) ; la forme couvrante est celle qui tient à 4,29 M.
 *
 * ── ⚠️ LE NOM ───────────────────────────────────────────────────────────────
 *
 * `idx_company_tag_tag` — VÉRIFIÉ LIBRE le 2026-08-20, dans `pg_indexes` comme
 * dans `database/migrations/`. La vérification n'est pas rituelle : le
 * 2026-08-20, un `CREATE INDEX IF NOT EXISTS` posé sur un nom DÉJÀ PRIS par une
 * autre colonne s'est exécuté en SILENCE, la migration s'est déclarée passée, et
 * la requête est restée en balayage (c'est l'origine de `G41-003`). **Un
 * `IF NOT EXISTS` sur un nom déjà pris est un silence, pas une idempotence.**
 * La garde générique est dans `IndexServentLesRequetesTest`.
 *
 * Garde de ce constat : `tests/Feature/Infra/VolumeDeProductionHubConsoleTest.php`.
 */
return new class extends Migration
{
    /**
     * `CREATE INDEX CONCURRENTLY` est interdit dans une transaction, et Laravel
     * enveloppe les migrations par défaut. Sans `CONCURRENTLY`, la création
     * poserait un verrou `SHARE` sur `company_tag` : en production, toute
     * écriture d'étiquette bloquée pendant la construction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('company_tag')) {
            return;
        }

        // `IF NOT EXISTS` rend la migration rejouable — indispensable avec
        // `CONCURRENTLY`, qui peut échouer à mi-course en laissant un index
        // INVALIDE derrière lui. Le nom a été vérifié libre (cf. en-tête) :
        // sans cette vérification, ce `IF NOT EXISTS` serait un silence.
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_company_tag_tag '
            . 'ON company_tag (tag_id, company_id)',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_company_tag_tag');
    }
};

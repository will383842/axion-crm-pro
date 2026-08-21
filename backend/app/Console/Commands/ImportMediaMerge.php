<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;

/**
 * FUSION NON DESTRUCTIVE d'un registre officiel dans `media` (B17-008).
 *
 * ── Ce que ce trait remplace ────────────────────────────────────────────────
 * `media:import-opendatasoft` et `media:import-arcom` faisaient un
 * « full-refresh par source » :
 *
 *     DELETE FROM media WHERE source = ? AND workspace_id = ?;   -- puis INSERT
 *
 * programme 4 fois par semaine (routes/console.php:149-151 lundi 02:15/02:30/02:45,
 * ligne 157 dimanche 03:30). Mesure faite sur ce depot le 2026-08-20, garde
 * tests/Feature/Commands/ImportMediaRejeuSourceTest.php :
 *   - l'id de la fiche CHANGEAIT a chaque rejeu (1 → 2 pour SPEL, 5 → 7 pour ARCOM),
 *     preuve que la ligne etait detruite puis recreee ;
 *   - `journalists.media_id` (FK ON DELETE SET NULL, migration
 *     2026_07_06_000002:97) repassait a NULL → les journalistes etaient DETACHES ;
 *   - `media.parent_media_id` (FK ON DELETE SET NULL, ligne 36) idem → les
 *     emissions Wikidata perdaient leur chaine ;
 *   - email / email_confidence / phone / socials / enrich_status / enriched_at /
 *     editorial_theme / company_id repartaient a zero : une semaine entiere de
 *     `media:enrich` (toutes les 3 h), `media:find-websites`, `media:link-to-companies`
 *     et `journalists:scrape-ours` effacee ;
 *   - une reponse HTTP 200 au corps VIDE faisait tomber la source de 1 a 0 ligne :
 *     un export amont tronque effacait tout le registre sans le moindre bruit.
 *
 * ── La regle appliquee ──────────────────────────────────────────────────────
 * Meme doctrine que le funnel d'ingestion deja en place dans ce depot
 * (App\Crm\Scraping\ScrapedRecordIngestService : « le collecte ne remplace
 * JAMAIS une valeur existante ») et que le seul import media deja idempotent
 * ({@see ExtractMediaFromCompanies} : INSERT ... SELECT + LEFT JOIN, aucun DELETE) :
 *
 *   - $sourceOwned  : colonnes que le REGISTRE possede (identite, geo declaree).
 *                     Elles sont rafraichies a chaque rejeu — c'est ce qui rend
 *                     l'import encore utile (TEMOIN de la garde).
 *   - $backfillOnly : colonnes posees UNIQUEMENT si la base est vide dessus.
 *   - tout le reste : JAMAIS touche (email, phone, socials, enrich_status,
 *                     enriched_at, editorial_theme, company_id des lors qu'il est
 *                     deja pose, website_method/checked_at, parent_media_id…).
 *
 * ── Disparition d'une ligne de la source ────────────────────────────────────
 * Une fiche qui n'est plus dans le registre n'est PAS supprimee : elle est
 * ARCHIVEE (`deleted_at = now()`, colonne deja prevue par le schema, ligne 62 de
 * la migration). La FK reste intacte, donc les journalistes restent rattaches, et
 * le retour de la fiche au registre suivant la RESSUSCITE (`deleted_at = NULL`).
 * C'est reversible ; un DELETE ne l'etait pas.
 *
 * ── Limite assumee ──────────────────────────────────────────────────────────
 * La fusion repose sur une cle naturelle stable fournie par l'appelant. Si cette
 * cle change cote source (n° CPPAP reattribue, renommage d'une station ARCOM dont
 * l'`arcom_id` est synthetise a partir du nom), la fiche est vue comme nouvelle :
 * l'ancienne est archivee, une neuve est creee. On ne perd rien (l'archive garde
 * l'enrichissement et les rattachements), mais le raccord n'est pas automatique.
 */
trait ImportMediaMerge
{
    /**
     * Fusionne les lignes d'un registre dans `media` sans rien detruire.
     *
     * @param  string  $sourceTag  valeur de `media.source` possedee par ce registre
     * @param  array<int,array<string,mixed>>  $rows  lignes pretes a inserer
     * @param  callable(array<string,mixed>):string  $keyOf  cle naturelle stable
     * @param  list<string>  $sourceOwned  colonnes rafraichies a chaque rejeu
     * @param  list<string>  $backfillOnly  colonnes posees seulement si vides en base
     * @param  bool  $archiveAbsents  false quand le lot est VOLONTAIREMENT partiel
     *                                (`--limit=N`) : sans ce garde-fou, un
     *                                `media:import-arcom --limit=10` archiverait
     *                                les 1 234 autres stations du registre.
     * @return array{inserted:int,updated:int,unchanged:int,archived:int,revived:int,duplicates:int}
     */
    protected function mergeMediaRows(
        string $sourceTag,
        string $workspaceId,
        array $rows,
        callable $keyOf,
        array $sourceOwned,
        array $backfillOnly = [],
        bool $archiveAbsents = true,
    ): array {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'archived' => 0, 'revived' => 0, 'duplicates' => 0];

        // ── Dedup intra-lot : deux lignes de meme cle naturelle dans le meme
        // export (cas reel : un titre publie deux fois par la CPPAP). La premiere
        // gagne — sans ca l'UPDATE puis l'INSERT se marcheraient dessus.
        $parCle = [];
        foreach ($rows as $r) {
            $cle = $keyOf($r);
            if (isset($parCle[$cle])) {
                $stats['duplicates']++;

                continue;
            }
            $parCle[$cle] = $r;
        }

        DB::transaction(function () use ($sourceTag, $workspaceId, $parCle, $keyOf, $sourceOwned, $backfillOnly, $archiveAbsents, &$stats) {
            // ── Etat courant, SOFT-DELETES INCLUS : une fiche archivee au run
            // precedent doit etre retrouvee et ressuscitee, pas dupliquee (l'index
            // unique media_workspace_cppap_uidx la refuserait de toute facon).
            $existants = [];
            DB::table('media')
                ->where('workspace_id', $workspaceId)
                ->where('source', $sourceTag)
                ->orderBy('id')
                ->chunk(2000, function ($lignes) use (&$existants, $keyOf) {
                    foreach ($lignes as $ligne) {
                        $cle = $keyOf((array) $ligne);
                        // Premier arrive gagne (id le plus petit = fiche canonique).
                        $existants[$cle] ??= $ligne;
                    }
                });

            $vues = [];
            $aInserer = [];

            foreach ($parCle as $cle => $r) {
                $ancien = $existants[$cle] ?? null;
                if ($ancien === null) {
                    $aInserer[] = $r;

                    continue;
                }
                $vues[$cle] = true;

                $maj = [];

                // 1. Colonnes possedees par le registre → toujours rafraichies.
                foreach ($sourceOwned as $col) {
                    if (! array_key_exists($col, $r)) {
                        continue;
                    }
                    if (! self::memeValeur($ancien->{$col} ?? null, $r[$col])) {
                        $maj[$col] = $r[$col];
                    }
                }

                // 2. Colonnes backfill-only → posees seulement si la base est vide.
                foreach ($backfillOnly as $col) {
                    if (! array_key_exists($col, $r) || $r[$col] === null || $r[$col] === '') {
                        continue;
                    }
                    $actuel = $ancien->{$col} ?? null;
                    if ($actuel === null || $actuel === '') {
                        $maj[$col] = $r[$col];
                    }
                }

                // 3. Un site web pose par la source vaut « trouve » — mais on ne
                //    retrograde JAMAIS un site deja confirme par l'enrichissement.
                if (array_key_exists('website', $maj) && $maj['website'] !== null && $maj['website'] !== '') {
                    $maj['website_status'] = 'found';
                }

                // 4. La fiche etait archivee et revient au registre → resurrection.
                if (($ancien->deleted_at ?? null) !== null) {
                    $maj['deleted_at'] = null;
                    $stats['revived']++;
                }

                if ($maj === []) {
                    $stats['unchanged']++;

                    continue;
                }

                $maj['updated_at'] = now();
                DB::table('media')->where('id', $ancien->id)->update($maj);
                $stats['updated']++;
            }

            foreach (array_chunk($aInserer, 500) as $paquet) {
                DB::table('media')->insert($paquet);
                $stats['inserted'] += count($paquet);
            }

            // ── Sorties du registre : ARCHIVAGE, pas suppression. La FK reste
            // intacte → journalistes et emissions restent rattaches.
            if (! $archiveAbsents) {
                return; // lot volontairement partiel (--limit) : on n'archive rien
            }
            $aArchiver = [];
            foreach ($existants as $cle => $ligne) {
                if (isset($vues[$cle])) {
                    continue;
                }
                if (($ligne->deleted_at ?? null) !== null) {
                    continue; // deja archivee
                }
                $aArchiver[] = $ligne->id;
            }
            foreach (array_chunk($aArchiver, 1000) as $paquet) {
                DB::table('media')->whereIn('id', $paquet)->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
                $stats['archived'] += count($paquet);
            }
        });

        return $stats;
    }

    /**
     * Refus d'ecrire sur un lot VIDE. Mesure de la garde : sans ce garde-fou, une
     * reponse HTTP 200 au corps vide faisait passer la source de 1 fiche a 0.
     * Un export amont tronque ne doit pas pouvoir vider un registre.
     */
    protected function refuseLotVide(string $sourceTag): void
    {
        $this->error(
            "Aucune ligne exploitable pour la source « {$sourceTag} » : on n'ecrit RIEN. "
            . 'Un export vide ou tronque ne doit pas effacer le registre deja en base.',
        );
    }

    /**
     * Rapport de fusion, une ligne, avec les chiffres du run.
     *
     * Les six clefs sont LUES telles quelles ci-dessous : un `array` sans type
     * de valeur laissait l'analyse incapable de dire qu'une clef manquante ou
     * non numerique casserait le `sprintf`.
     *
     * @param  array{inserted: int, updated: int, unchanged: int, archived: int, revived: int, duplicates: int}  $stats
     */
    protected function afficheFusion(string $sourceTag, array $stats): void
    {
        $this->info(sprintf(
            '✓ source=%s — %d creees, %d mises a jour, %d inchangees, %d archivees (sorties du registre), %d ressuscitees, %d doublons intra-lot.',
            $sourceTag,
            $stats['inserted'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['archived'],
            $stats['revived'],
            $stats['duplicates'],
        ));
    }

    /**
     * Egalite tolerante base ↔ source : Postgres rend tout en chaine, la source
     * fabrique des null / int / Carbon. NULL et '' ne sont PAS confondus avec 0.
     */
    private static function memeValeur(mixed $enBase, mixed $deLaSource): bool
    {
        if ($enBase === null || $deLaSource === null) {
            return $enBase === null && $deLaSource === null;
        }

        return (string) $enBase === (string) $deLaSource;
    }
}

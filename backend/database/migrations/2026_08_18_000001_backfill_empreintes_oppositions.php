<?php

use App\Support\ListeSuppression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TEMPS 1 sur 2 — toute opposition porte son EMPREINTE.
 *
 * Décision du 2026-08-18 (`_REPORTS/2026-08-18_ARBITRAGES-PREALABLES-SECTION-4.md`,
 * décision 3) : l'adresse en clair de `opt_out` et `email_suppressions` doit
 * disparaître. `opt_out` recense des personnes dont le seul geste enregistré
 * est un REFUS ; conserver leur adresse en clair, c'est conserver la donnée
 * personnelle de quelqu'un qui a demandé qu'on l'oublie. L'empreinte SHA-256
 * remplit la seule fonction légitime : empêcher qu'un futur re-scrape ne les
 * réintroduise.
 *
 * ── Pourquoi surtout PAS en une seule migration ───────────────────────────
 * Les deux gardes (`EligibiliteCampagne::appliquerPortes()`,
 * `DeduplicationService::isOptedOut()`) interrogeaient jusqu'ici LES DEUX
 * formes, délibérément : « les signaux venus du site arrivent hachés, ceux
 * d'un fournisseur d'envoi arrivent en clair ». Retirer la colonne AVANT
 * d'avoir garanti que toute ligne porte son empreinte rendrait invisibles les
 * oppositions qui n'ont que l'adresse en clair — c'est-à-dire recontacter
 * quelqu'un qui s'y est opposé. Le correctif de conformité, mal séquencé,
 * produirait exactement le dommage qu'il prétend éviter.
 *
 * Cette migration ne fait donc QUE : remplir, puis interdire qu'on recommence.
 * Le `DROP COLUMN` est le temps 2, dans un déploiement SÉPARÉ, et sa condition
 * d'entrée est écrite dans `_REPORTS/2026-08-18_OPT-OUT-DROP-COLUMN-TEMPS-2.md`.
 *
 * ── 🔴 POURQUOI LE REMPLISSAGE EST FAIT EN PHP, ET NON EN SQL ─────────────
 * L'empreinte de référence est celle de `ListeSuppression::empreinte()` et de
 * `SiteSyncEvent::emailHash()` : `sha256(mb_strtolower(trim($email)))`, sans
 * sel. Le site la calcule de son côté, indépendamment ; toute divergence
 * rendrait la liste aveugle aux signaux du site, EN SILENCE.
 *
 * Le SQL équivalent employé ailleurs dans le dépôt —
 * `encode(digest(btrim(lower(col)), 'sha256'), 'hex')` — donne le même
 * résultat sur l'ASCII, et un résultat DIFFÉRENT dès qu'une majuscule
 * non-ASCII apparaît. Mesuré le 2026-08-18 sur l'image Postgres du projet,
 * dont la base est initialisée en `--lc-ctype=C` :
 *
 *     SQL  : lower('ÉRIC@ACME.FR')            → 'Éric@acme.fr'   (É INCHANGÉ)
 *     PHP  : mb_strtolower('ÉRIC@ACME.FR')    → 'éric@acme.fr'
 *
 * Deux empreintes différentes pour la même personne. `btrim` diverge de même
 * de `trim()` sur les tabulations et les retours à la ligne. Remplir en SQL
 * aurait donc fabriqué, pour ces adresses-là, une empreinte que PERSONNE
 * n'interroge — une ligne d'opposition qui ne bloque rien, et qui ne fait
 * aucun bruit. On remplit en PHP, par le SSOT.
 *
 * ── INERTIE ───────────────────────────────────────────────────────────────
 * Les deux tables sont petites (oppositions et suppressions, pas des fiches) :
 * balayage par lots de 500, `ADD CONSTRAINT` validé immédiatement.
 */
return new class extends Migration
{
    /** Tables porteuses d'une adresse d'opposition et de son empreinte. */
    private const TABLES = ['opt_out', 'email_suppressions'];

    private const LOT = 500;

    /**
     * Combien d'empreintes ont été calculées, par table.
     *
     * 🔴 Exposé en propriété plutôt qu'`echo`é : un `echo` dans une migration
     * rend RISKY tout test qui la rejoue (`beStrictAboutOutputDuringTests`),
     * et le compte est justement ce qu'un test doit pouvoir constater. Le même
     * chiffre part dans le journal pour l'exploitant.
     *
     * @var array<string, int>
     */
    public array $remplies = [];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->remplir($table);
            $this->exigerEmpreinte($table);
            $this->interdireLeRetour($table);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_empreinte_obligatoire_check");
        }
        // Les empreintes calculées ne sont PAS retirées : elles ne contiennent
        // rien qui ne fût déjà dans la colonne en clair, et les effacer
        // rouvrirait le trou d'anti-réinsertion que la migration vient de
        // fermer. Un `down()` qui détruit une garde n'est pas un retour en
        // arrière, c'est une régression.
    }

    /**
     * Calcule l'empreinte manquante de chaque ligne qui porte une adresse.
     *
     * Une adresse VIDE ou faite d'espaces seuls n'est pas hachée : son
     * empreinte serait celle de la chaîne vide, identique pour toutes, et une
     * telle ligne ne heurterait jamais personne. On la laisse telle quelle —
     * `exigerEmpreinte()` la signalera, avec son id, pour décision humaine.
     */
    private function remplir(string $table): void
    {
        $remplies = 0;
        $dernierId = 0;

        // 🔴 Curseur sur l'id, et NON « refaire la même requête jusqu'à ce
        // qu'elle ne rende plus rien » : les adresses vides sont sautées sans
        // être corrigées, donc elles resteraient éligibles à la requête et la
        // boucle tournerait à l'infini — ou s'arrêterait au premier lot vide,
        // en laissant derrière elle des lignes remplissables.
        while (true) {
            $lignes = DB::table($table)
                ->select('id', 'email')
                ->whereNull('email_hash')
                ->whereNotNull('email')
                ->where('id', '>', $dernierId)
                ->orderBy('id')
                ->limit(self::LOT)
                ->get();

            if ($lignes->isEmpty()) {
                break;
            }

            foreach ($lignes as $ligne) {
                $dernierId = (int) $ligne->id;

                $normalise = mb_strtolower(trim((string) $ligne->email));
                if ($normalise === '') {
                    continue;
                }

                DB::table($table)
                    ->where('id', $ligne->id)
                    ->update(['email_hash' => ListeSuppression::empreinte($normalise)]);

                $remplies++;
            }
        }

        $this->remplies[$table] = $remplies;
        Log::info('Remplissage des empreintes d’opposition', [
            'table' => $table,
            'empreintes_calculees' => $remplies,
        ]);
    }

    /**
     * ÉCHOUE BRUYAMMENT s'il reste une ligne porteuse d'adresse sans empreinte.
     *
     * Une migration de remplissage qui se termine en vert sur un travail
     * partiel est pire qu'absente : elle autorise le temps 2 (`DROP COLUMN`)
     * en laissant derrière elle des oppositions que plus personne ne verra.
     */
    private function exigerEmpreinte(string $table): void
    {
        $orphelines = DB::table($table)
            ->whereNull('email_hash')
            ->whereNotNull('email')
            ->orderBy('id')
            ->limit(20)
            ->pluck('id')
            ->all();

        if ($orphelines === []) {
            return;
        }

        $total = DB::table($table)->whereNull('email_hash')->whereNotNull('email')->count();

        // ⚠️ Ni `use RuntimeException;` (PHP le refuse comme sans effet dans
        // un fichier de l'espace global, et l'avertissement casse le
        // chargement de la migration), ni `\RuntimeException` (Pint le
        // raccourcit par `fully_qualified_strict_types`).
        throw new RuntimeException(
            "REFUS DE POURSUIVRE : {$total} ligne(s) de « {$table} » portent une adresse "
            . "IMPOSSIBLE à hacher (vide, ou faite d'espaces seuls). Une empreinte de chaîne "
            . "vide serait identique pour toutes et ne bloquerait personne.\n"
            . '  ids (20 premiers) : ' . implode(', ', array_map('strval', $orphelines)) . "\n"
            . "  Ce sont des lignes qui n'opposent RIEN. Après constat visuel, et à ce\n"
            . "  stade SEULEMENT (le remplissage vient de tourner : toute ligne encore\n"
            . "  sans empreinte est irrécupérable) :\n"
            . "    DELETE FROM {$table} WHERE email_hash IS NULL AND email IS NOT NULL;\n"
            . '  Puis rejouer cette migration.',
        );
    }

    /**
     * La garde de DEMAIN : une ligne ne peut plus naître avec une adresse et
     * sans son empreinte.
     *
     * C'est cette contrainte, et non le code applicatif, qui rend le temps 2
     * sûr : le jour du `DROP COLUMN`, aucune opposition ne pourra n'exister
     * que sous sa forme claire. Un `phone` seul reste permis — une opposition
     * par téléphone n'a pas d'adresse, et elle n'a rien à prouver ici.
     */
    private function interdireLeRetour(string $table): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_empreinte_obligatoire_check");
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$table}_empreinte_obligatoire_check
             CHECK (email IS NULL OR email_hash IS NOT NULL)",
        );
        DB::statement(
            "COMMENT ON CONSTRAINT {$table}_empreinte_obligatoire_check ON {$table} IS "
            . "'Temps 1 du retrait de l''adresse en clair (2026-08-18). Une opposition qui "
            . "n''existerait que sous forme claire deviendrait invisible au DROP COLUMN du "
            . "temps 2 — c''est-à-dire qu''on recontacterait quelqu''un qui s''y est opposé.'",
        );
    }
};

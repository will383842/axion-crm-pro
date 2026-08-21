<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * retention:prune-scraper-runs — borne la rétention de la table scraper_runs.
 *
 * scraper_runs journalise chaque appel d'enrichissement (≈ 7,6 M lignes constatées en
 * prod). On purge par lots les runs plus vieux que --days (défaut 90) pour ne pas
 * verrouiller la table. Suppression Postgres-safe (pas de DELETE ... LIMIT natif) via
 * sous-sélection d'ids.
 */
class PruneScraperRuns extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'retention:prune-scraper-runs {--days=90} {--chunk=50000} {--dry-run : Compte sans supprimer} {--force : Passe outre le plafond de proportion}';

    protected $description = 'Purge les scraper_runs plus vieux que N jours (défaut 90).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunk = max(1000, (int) $this->option('chunk'));
        // ── PLAFOND DE PROPORTION (B15-008) ──────────────────────────────
        //
        // 🔑 POURQUOI ON BLOQUE, MEME QUAND L'EFFACEMENT EST UNE OBLIGATION.
        //
        // Refuser une purge de retention retarde une echeance ; laisser passer
        // une purge ERRONEE detruit des donnees qui ne reviennent pas. Un jour
        // de retard se rattrape en relancant la commande a la main ; un vivier
        // efface par une condition trop large ne se rattrape pas.
        // **L'irreversible l'emporte.** Et le refus n'est pas silencieux : il
        // nomme la proportion, la table, et le geste qui debloque.
        //
        // B15-004 est la preuve que ce risque n'est pas theorique :
        // `prospection:purge-non-commercial` supprimait sur
        // `legal_form IS NULL OR ...` — c'est-a-dire presque tout — sans
        // qu'aucune barriere ni aucun test ne le dise.
        //
        // Ici la table est technique (`scraper_runs`), mais la commande tourne
        // TOUS LES JOURS a 04:20 : un `--days` mal passe (0, ou une valeur lue
        // d'une variable vide) viserait la table entiere.
        $cutoff = now()->subDays($days);

        $aPurger = DB::table('scraper_runs')->where('created_at', '<', $cutoff)->count();
        $totalTable = DB::table('scraper_runs')->count();

        if (! $this->ecritureAutoriseeSansOperateur('scraper_runs', $aPurger, $totalTable, 'purger')) {
            return self::FAILURE;
        }

        // ⚠️ `--dry-run` N'EST PAS DECORATIF, et son absence etait un vrai
        // manque : cette commande supprime definitivement, tous les jours, et
        // n'offrait AUCUN moyen de voir ce qu'elle ferait avant qu'elle le
        // fasse. Les cinq autres commandes destructives du depot l'ont ; le
        // trait de garde le suppose meme (`suppressionAutorisee()` le lit).
        // Ajoute ici, et HONORE : on sort avant la moindre suppression.
        if ((bool) $this->option('dry-run')) {
            $this->info("Essai a blanc : {$aPurger} run(s) SERAIENT purges (> {$days} j). Rien n'a ete supprime.");

            return self::SUCCESS;
        }

        $total = 0;

        do {
            $ids = DB::table('scraper_runs')
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += DB::table('scraper_runs')->whereIn('id', $ids)->delete();
            $this->line("… {$total} runs purgés");
        } while ($ids->count() === $chunk);

        $this->info("scraper_runs : {$total} lignes purgées (> {$days} j).");

        return self::SUCCESS;
    }
}

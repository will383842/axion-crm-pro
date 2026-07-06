<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic prospection : liste les workspaces avec leur nombre d'entreprises
 * et la répartition par taille. Sert à vérifier DANS QUEL workspace la collecte
 * a écrit (l'app affiche le workspace de l'utilisateur connecté).
 */
class ProspectionStats extends Command
{
    protected $signature = 'prospection:stats';

    protected $description = 'Diagnostic : entreprises par workspace + répartition taille.';

    public function handle(): int
    {
        $workspaces = DB::table('workspaces')->orderBy('created_at')->get(['id', 'slug', 'name', 'created_at']);
        $this->info(count($workspaces) . ' workspace(s) :');

        foreach ($workspaces as $w) {
            $n = DB::table('companies')->where('workspace_id', $w->id)->count();
            $this->line(sprintf(
                '• %s | slug=%s | name=%s | créé=%s → %d entreprises',
                substr((string) $w->id, 0, 8),
                $w->slug,
                $w->name,
                (string) $w->created_at,
                $n,
            ));
            if ($n > 0) {
                // Couverture des champs récupérés (adresse/SIRET/ville).
                $withAddr = DB::table('companies')->where('workspace_id', $w->id)->whereNotNull('address')->count();
                $withSiret = DB::table('companies')->where('workspace_id', $w->id)->whereNotNull('siret')->count();
                $withCity = DB::table('companies')->where('workspace_id', $w->id)->whereNotNull('city')->count();
                $this->line("      >> avec ADRESSE : {$withAddr} · avec SIRET : {$withSiret} · avec VILLE : {$withCity}");

                $withEff = DB::table('companies')->where('workspace_id', $w->id)
                    ->whereNotNull('effectif_range')
                    ->whereNotIn('effectif_range', ['NN', ''])
                    ->count();
                $this->line("      >> avec EFFECTIF salarié déclaré INSEE : {$withEff}");

                $bySize = DB::table('companies')
                    ->where('workspace_id', $w->id)
                    ->selectRaw("COALESCE(size_category, '(non classé)') AS sc, COUNT(*) AS c")
                    ->groupBy('sc')
                    ->orderByDesc('c')
                    ->get();
                foreach ($bySize as $r) {
                    $this->line("      taille {$r->sc} : {$r->c}");
                }
            }
        }

        return self::SUCCESS;
    }
}

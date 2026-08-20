<?php

namespace App\Console\Commands;

use App\Support\RelocalisationPartman;
use Illuminate\Console\Command;

/**
 * 🔴 B10-001 (S1) — rend la base reconstructible AVANT que `migrate:fresh`
 * n'essaie de la reconstruire.
 *
 * Le correctif SQL existait deja, mais dans une MIGRATION : sur une base ou
 * pg_partman vit dans `public`, `migrate:fresh` meurt a l'etape « Dropping all
 * tables » (SQLSTATE 2BP01) et aucune migration ne tourne. Le correctif ne
 * pouvait donc jamais s'appliquer la ou il servait.
 *
 * Cette commande est le meme geste, appelable AU BON MOMENT :
 *   · `make db-rebuild-local` / `make db-rebuild-check` l'appellent avant
 *     chaque `migrate:fresh` ;
 *   · `AppServiceProvider` l'appelle sur l'evenement `CommandStarting` de
 *     `migrate:fresh`, ce qui couvre aussi `RefreshDatabase`, c'est-a-dire
 *     toute la suite Pest — qui n'appelle pas le Makefile.
 *
 * Idempotente : sur une base deja relocalisee (ou sans pg_partman), elle ne
 * fait rien et rend 0.
 */
class PartmanRelocaliser extends Command
{
    protected $signature = 'db:partman-relocaliser
        {--database= : connexion a traiter (defaut : la connexion par defaut)}';

    protected $description = 'Deplace pg_partman hors du search_path applicatif, pour que migrate:fresh redevienne possible.';

    public function handle(): int
    {
        $connexion = $this->option('database');
        $connexion = is_string($connexion) && $connexion !== '' ? $connexion : null;

        $this->info(RelocalisationPartman::jouer($connexion));

        // Toujours 0 : ce geste est une reparation de confort jouee en amont
        // d'une reconstruction. Le faire echouer ferait rougir des chaines
        // (Makefile, CI) pour une base qui, sans lui, ne serait de toute facon
        // pas dans un pire etat. L'etat reel est dit dans le message et dans le
        // journal ; c'est `ReconstructionBaseTest` qui, lui, rougit.
        return self::SUCCESS;
    }
}

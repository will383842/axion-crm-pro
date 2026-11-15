<?php

namespace App\Console\Commands;

use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SEULE voie de changement de `envoi_domaines.adaptateur` (D11) : commande locale, journalisée par la fonction
 * `envoi_domaine_adaptateur_changer` (jamais par une route). Ne touche jamais `ENVOI_REEL_ACTIF`, aucun réseau.
 */
class EnvoiDomaineAdaptateur extends Command
{
    public const ADAPTATEURS = ['faux', 'kumo', 'smtp'];

    protected $signature = 'envoi:domaine-adaptateur {workspace : identifiant du workspace} {domaine : nom du domaine d\'envoi} {adaptateur : faux, kumo ou smtp} {--auteur= : qui demande}';

    protected $description = 'Choisit l\'adaptateur d\'envoi d\'un domaine (faux, kumo, smtp) — local, journalisé, jamais par API';

    public function handle(): int
    {
        $ws = (string) $this->argument('workspace');
        $domaine = mb_strtolower(trim((string) $this->argument('domaine')));
        $adaptateur = (string) $this->argument('adaptateur');
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        if (! in_array($adaptateur, self::ADAPTATEURS, true)) {
            $this->error('Adaptateur hors liste (faux, kumo, smtp) : rien n\'est changé.');

            return self::FAILURE;
        }
        if (! Str::isUuid($ws)) {
            $this->error('Identifiant de workspace invalide.');

            return self::FAILURE;
        }
        try {
            $change = WorkspaceContext::run($ws, fn (): bool => (bool) DB::selectOne(
                'SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?) AS ok',
                [$ws, $domaine, $adaptateur, $auteur],
            )->ok);
        } catch (QueryException $e) {
            $this->error('Refusé par la base : ' . (str_contains($e->getMessage(), 'domaine inconnu') ? 'domaine inconnu pour cet espace.' : 'auteur ou paramètres invalides.'));

            return self::FAILURE;
        }
        $this->warn($change ? "Adaptateur du domaine = {$adaptateur} (journalisé). L'envoi réel reste fermé par ENVOI_REEL_ACTIF et le feu vert." : "Déjà sur {$adaptateur} : rien n'est changé.");

        return self::SUCCESS;
    }
}

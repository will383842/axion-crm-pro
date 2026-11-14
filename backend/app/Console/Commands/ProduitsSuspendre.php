<?php

namespace App\Console\Commands;

use App\Socle\Produits\ProduitsEspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Suspend un produit pour un espace : `workspaces.settings -> produits.<produit> = suspendu`.
 *
 * Symétrique de `produits:activer` : locale, SANS route, idempotente, journalisée, ligne verrouillée
 * (`FOR UPDATE`, comme `PUT /workspace`). Elle ne suspend qu'un produit ACTIF : un produit jamais activé ou
 * résilié est refusé (elle ne « rouvre » ni ne « change » rien). La réactivation reste `produits:activer`.
 */
class ProduitsSuspendre extends Command
{
    protected $signature = 'produits:suspendre {espace : Identifiant (uuid) ou slug de l\'espace} {produit : Produit à suspendre (ex. envoi)}';

    protected $description = 'Suspend un produit pour un espace (chemin réservé à la console)';

    public function handle(): int
    {
        $produit = (string) $this->argument('produit');
        if (! in_array($produit, ProduitsEspace::PRODUITS, true)) {
            $this->error('Produit inconnu. Produits connus : ' . implode(', ', ProduitsEspace::PRODUITS) . '.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('espace');

        return DB::transaction(function () use ($reference, $produit): int {
            $requete = DB::table('workspaces')->whereNull('deleted_at')->lockForUpdate();
            $ligne = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference) === 1
                ? $requete->where('id', $reference)->first(['id', 'settings'])
                : $requete->where('slug', $reference)->first(['id', 'settings']);
            if ($ligne === null) {
                $this->error('Espace introuvable.');

                return self::FAILURE;
            }

            $settings = is_string($ligne->settings) ? json_decode($ligne->settings, true) : $ligne->settings;
            $settings = is_array($settings) ? $settings : [];
            $etat = $settings['produits'][$produit] ?? null;
            if ($etat === 'suspendu') {
                $this->info("Le produit « {$produit} » est déjà suspendu : rien à faire.");

                return self::SUCCESS;
            }
            if ($etat !== 'actif') {
                $this->error("Le produit « {$produit} » n'est pas actif : rien à suspendre.");

                return self::FAILURE;
            }

            $settings['produits'][$produit] = 'suspendu';
            DB::table('workspaces')->where('id', $ligne->id)->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
            Log::info('produits.suspendre', ['workspace_id' => $ligne->id, 'produit' => $produit]);
            $this->info("Produit « {$produit} » suspendu.");

            return self::SUCCESS;
        });
    }
}

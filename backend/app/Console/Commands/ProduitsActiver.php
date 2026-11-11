<?php

namespace App\Console\Commands;

use App\Socle\Produits\ProduitsEspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Active un produit pour un espace : `workspaces.settings -> produits.<produit> = actif`.
 *
 * SEUL chemin d'activation : `PUT /api/v1/workspace` refuse la clé `produits` (voir `ProduitsEspace::CLES_RESERVEES`).
 * Locale, sans route. Idempotente : relancée, elle ne change rien. Journalisée. Les autres réglages sont conservés.
 */
class ProduitsActiver extends Command
{
    protected $signature = 'produits:activer {espace : Identifiant (uuid) ou slug de l\'espace} {produit : Produit à activer (ex. envoi)}';

    protected $description = 'Active un produit pour un espace (chemin réservé à la console)';

    public function handle(): int
    {
        $produit = (string) $this->argument('produit');
        if (! in_array($produit, ProduitsEspace::PRODUITS, true)) {
            $this->error('Produit inconnu. Produits connus : ' . implode(', ', ProduitsEspace::PRODUITS) . '.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('espace');
        $code = DB::transaction(function () use ($reference, $produit): int {
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
            if (($settings['produits'][$produit] ?? null) === 'actif') {
                $this->info("Le produit « {$produit} » est déjà actif : rien à faire.");

                return self::SUCCESS;
            }

            $produits = is_array($settings['produits'] ?? null) ? $settings['produits'] : [];
            $produits[$produit] = 'actif';
            $settings['produits'] = $produits;
            DB::table('workspaces')->where('id', $ligne->id)->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
            Log::info('produits.activer', ['workspace_id' => $ligne->id, 'produit' => $produit]);
            $this->info("Produit « {$produit} » activé.");

            return self::SUCCESS;
        });

        return $code;
    }
}

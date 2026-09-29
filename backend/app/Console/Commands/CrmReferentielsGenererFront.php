<?php

namespace App\Console\Commands;

use App\Crm\Referentiels\ExportFront;
use Illuminate\Console\Command;

/**
 * Régénère `frontend/src/lib/referentiels.generated.ts` depuis `Taxonomy`.
 *
 * À lancer (depuis `backend/`, sur un poste de développement) après toute
 * modification d'un référentiel de classement : secteurs, tailles, natures,
 * régions. La garde `ReferentielsFrontTest` rougit tant que le fichier
 * versionné diffère de ce que cette commande produit.
 */
class CrmReferentielsGenererFront extends Command
{
    protected $signature = 'crm:referentiels:generer-front
                            {--verifier : Ne rien écrire ; échouer si le fichier n\'est pas à jour}';

    protected $description = 'Génère la copie frontend des référentiels (secteurs, tailles, natures, régions, fédérations, métiers).';

    public function handle(): int
    {
        $chemin = ExportFront::chemin();
        $attendu = ExportFront::contenu();
        $actuel = is_file($chemin) ? str_replace("\r\n", "\n", (string) file_get_contents($chemin)) : null;

        if ((bool) $this->option('verifier')) {
            if ($actuel === $attendu) {
                $this->info('À jour : ' . ExportFront::CHEMIN_RELATIF);

                return self::SUCCESS;
            }
            $this->error('PAS à jour : ' . ExportFront::CHEMIN_RELATIF . ' — lancer `php artisan crm:referentiels:generer-front`.');

            return self::FAILURE;
        }

        if (! is_dir(dirname($chemin))) {
            $this->error('Dossier du frontend introuvable : ' . dirname($chemin) . ' (commande de poste de développement).');

            return self::FAILURE;
        }
        if ($actuel === $attendu) {
            $this->info('Déjà à jour : ' . ExportFront::CHEMIN_RELATIF);

            return self::SUCCESS;
        }
        if (file_put_contents($chemin, $attendu) === false) {
            $this->error('Écriture impossible : ' . $chemin);

            return self::FAILURE;
        }
        $this->info('Écrit : ' . ExportFront::CHEMIN_RELATIF);

        return self::SUCCESS;
    }
}

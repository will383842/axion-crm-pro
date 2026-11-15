<?php

namespace App\Console\Commands;

use App\Envoi\Orchestration\EssaiABlanc;
use App\Envoi\Orchestration\RefusAvantEssai;
use Illuminate\Console\Command;
use Throwable;

/**
 * Essai à blanc de bout en bout (D10) : commande LOCALE, aucune route. Données fictives, faux serveur, transaction annulée ;
 * seule la ligne de résultat (sans adresse) est écrite. Code de sortie non nul si une étape est rouge.
 *
 * AUTORISATION : aucune route, aucun rôle applicatif. Seule une personne ayant un accès shell au serveur peut la lancer (artisan) ;
 * elle ne s'exécute jamais depuis le web. `--auteur` est une simple étiquette (sans « @ ») écrite dans la ligne de résultat,
 * pas une identité vérifiée. Aucun envoi réel : `ENVOI_REEL_ACTIF` reste false.
 */
class EnvoiEssaiABlanc extends Command
{
    protected $signature = 'envoi:essai-a-blanc {espace : identifiant de l\'espace} {--auteur= : qui lance l\'essai}';

    protected $description = 'Essai à blanc de bout en bout de l\'envoi (faux serveur, données fictives, rien n\'est envoyé ni conservé)';

    public function handle(EssaiABlanc $essai): int
    {
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        try {
            $rapport = $essai->executer((string) $this->argument('espace'), $auteur);
        } catch (RefusAvantEssai $e) {
            $this->error($e->getMessage() . ' Aucun essai lancé.');

            return self::FAILURE;
        } catch (Throwable) {
            // Message propre : jamais celui de l'exception (il pourrait porter une valeur ou une adresse). La ligne rouge est déjà écrite.
            $this->error('ESSAI ROUGE : le parcours a été interrompu par une erreur ; un résultat rouge a été enregistré.');

            return self::FAILURE;
        }
        $this->table(['étape', 'résultat'], array_map(static fn (array $e): array => [$e['nom'], $e['resultat']], $rapport->pourBase()));
        if (! $rapport->reussi()) {
            $this->error('ESSAI ROUGE : au moins une étape a échoué.');

            return self::FAILURE;
        }
        $this->info('Essai à blanc réussi : rien n\'a été envoyé ni conservé.');

        return self::SUCCESS;
    }
}

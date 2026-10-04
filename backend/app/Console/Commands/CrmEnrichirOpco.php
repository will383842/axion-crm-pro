<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Opco\EnrichissementOpco;
use App\Crm\Opco\FenetreOpco;
use App\Crm\Opco\SourceSiro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * IDCC ET OPCO DES ENTREPRISES (lot O14, chantier OPCO).
 *
 * Rapproche les fiches d'un espace de la table SIRET → OPCO de France
 * compétences (data.gouv.fr, licence ouverte 2.0) et reporte l'IDCC, l'OPCO
 * propriétaire et l'OPCO de gestion dans `companies_opco` — jamais dans
 * `companies`. Une ligne `saisie` n'est JAMAIS écrasée. La règle complète
 * est dans `App\Crm\Opco\EnrichissementOpco`.
 *
 *   php artisan crm:enrichir-opco --dry-run
 *   php artisan crm:enrichir-opco --limite=500000
 *
 * FENÊTRE : refuse de partir hors de 08:00-19:00 heure de Paris, tous les
 * jours (`FenetreOpco`).
 *
 * PAS PLANIFIÉE : aucune entrée dans `routes/console.php` — elle se lance à
 * la main, une fois par publication de la table (mensuelle).
 *
 * REPRISE : sans rien préciser, un passage inachevé (coupure, `--limite`)
 * sur la MÊME ressource reprend après la dernière ligne traitée.
 *
 * RIEN N'EST JAMAIS SUPPRIMÉ.
 */
class CrmEnrichirOpco extends Command
{
    protected $signature = 'crm:enrichir-opco'
        . ' {--dry-run : essai à blanc — lit la table SIRO et donne le bilan chiffré, n écrit RIEN}'
        . ' {--limite=0 : lignes du fichier traitées au plus (0 = sans limite) ; le passage reprendra ensuite}'
        . ' {--workspace= : identifiant ou slug de l espace (défaut : l espace de prospection)}'
        . ' {--releve-le= : mois de la DSN AAAA-MM, si la ressource data.gouv ne le dit pas}';

    protected $description = 'Reporte IDCC et OPCO (table SIRET → OPCO de France compétences) dans companies_opco — n écrase aucune saisie, ne supprime rien.';

    public function handle(SourceSiro $source): int
    {
        $refus = FenetreOpco::refus(now());
        if ($refus !== null) {
            $this->error($refus);
            $this->line('Fenêtre autorisée : tous les jours, 08:00-19:00 heure de Paris.');

            return self::FAILURE;
        }

        $releveLe = $this->option('releve-le');
        $releveLe = is_string($releveLe) && trim($releveLe) !== '' ? trim($releveLe) : null;
        if ($releveLe !== null) {
            if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $releveLe) !== 1) {
                $this->error("--releve-le invalide : « {$releveLe} » (attendu AAAA-MM).");

                return self::FAILURE;
            }
            $releveLe .= '-01';
        }

        $limite = (int) $this->option('limite');
        if ($limite < 0) {
            $this->error('--limite doit être positive ou nulle.');

            return self::FAILURE;
        }

        $designation = $this->option('workspace');
        $workspaceId = EspaceProspection::resoudre(is_string($designation) ? $designation : null);
        if ($workspaceId === null) {
            $this->error('Aucun espace cible (--workspace=UUID ou slug).');

            return self::FAILURE;
        }

        $essai = (bool) $this->option('dry-run');
        if ($essai) {
            $this->warn('ESSAI À BLANC — la table SIRO est lue depuis sa PREMIÈRE ligne (jamais depuis le curseur d\'un passage inachevé), RIEN n\'est écrit (ni companies_opco, ni journal).');
        }

        // 3,6 M de lignes : aucune requête SQL n'est gardée en mémoire.
        foreach (DB::getConnections() as $connexion) {
            $connexion->disableQueryLog();
            $connexion->flushQueryLog();
        }
        DB::connection()->disableQueryLog();

        try {
            $resultat = (new EnrichissementOpco($source))->executer(
                $workspaceId,
                $essai,
                $limite,
                $releveLe,
                fn (string $ligne) => $this->line($ligne),
            );
        } catch (\Throwable $e) {
            $this->error('Échec : ' . $e->getMessage());

            return self::FAILURE;
        }

        $b = $resultat['bilan'];
        $mois = $resultat['ressource']['releve_le'];
        $this->info(sprintf(
            '%s — DSN de %s%s — espace %s',
            $essai ? 'Bilan (ESSAI À BLANC)' : 'Bilan',
            $mois !== null ? substr($mois, 0, 7) : 'mois inconnu',
            $resultat['reprise'] ? ' (reprise du passage inachevé)' : '',
            substr($workspaceId, 0, 8),
        ));
        $this->line(sprintf('  lues : %d', $b['lues']));
        $this->line(sprintf('  rapprochées : %d (non rapprochées : %d, doublons dans un paquet : %d)', $b['rapprochees'], $b['non_rapprochees'], $b['doublons']));
        $this->line(sprintf('  %s : %d (inchangées : %d)', $essai ? 'à écrire' : 'écrites', $b['ecrites'], $b['inchangees']));
        $this->line(sprintf('  ignorées pour saisie : %d', $b['ignorees_saisie']));
        $this->line(sprintf('  exclues (non diffusibles INSEE) : %d', $b['exclues_non_diffusibles']));
        if ($resultat['fiches_sans_siret'] !== null) {
            $this->line(sprintf('  fiches de l\'espace sans SIRET (jamais rapprochables) : %d', $resultat['fiches_sans_siret']));
        }
        $this->line(sprintf(
            '  rejetées : SIRET malformé %d · IDCC vide %d · IDCC malformé %d · OPCO inconnu %d · OPCO de gestion inconnu %d · ligne malformée %d',
            $b['rejet_siret_malforme'],
            $b['rejet_idcc_vide'],
            $b['rejet_idcc_malforme'],
            $b['rejet_opco_inconnu'],
            $b['rejet_opco_gestion_inconnu'],
            $b['rejet_ligne_malformee'],
        ));
        $this->line(sprintf('  curseur : ligne %d', $resultat['curseur']));
        if ($mois === null) {
            $this->warn('Mois de DSN introuvable dans la ressource : relancer avec --releve-le=AAAA-MM pour le noter.');
        }
        if ($resultat['statut'] !== 'reussie') {
            $this->warn($essai
                ? 'ESSAI À BLANC INTERROMPU (--limite atteinte) : bilan PARTIEL des premières lignes seulement — rien n\'est mémorisé.'
                : 'Passage INACHEVÉ (--limite atteinte) : le curseur est mémorisé, le prochain passage reprendra.');
        }

        return self::SUCCESS;
    }
}

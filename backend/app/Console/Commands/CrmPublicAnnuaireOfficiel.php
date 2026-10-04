<?php

namespace App\Console\Commands;

use App\Crm\Annuaire\EnrichissementAnnuaire;
use App\Crm\Annuaire\SourceAnnuaire;
use App\Crm\EspaceProspection;
use App\Crm\Insee\MiseAJourMensuelle;
use App\Crm\TraitementsLourds;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ANNUAIRE OFFICIEL DE L'ADMINISTRATION → FICHES DU SECTEUR PUBLIC
 * (décision du propriétaire, 04/10/2026).
 *
 * E-mail, téléphone et site GÉNÉRIQUES des organismes publics, depuis
 * l'annuaire de Service-public (DILA, licence ouverte), rapprochés
 * UNIQUEMENT de façon certaine (SIRET, SIREN unique, ou mairie ↔ commune par
 * code INSEE). Rien n'est écrasé : un conflit devient une proposition. La
 * règle complète est dans `App\Crm\Annuaire\EnrichissementAnnuaire`.
 *
 *   php artisan crm:public:annuaire-officiel --dry-run
 *   php artisan crm:public:annuaire-officiel --limite=20000
 *
 * FENÊTRE : tous les jours, 08:00-19:00 heure de Paris (#324) ; un passage
 * s'arrête PROPREMENT à 19:00 et reprendra. VERROU : un seul traitement
 * lourd à la fois (`TraitementsLourds`). Refuse aussi de partir tant qu'un
 * passage de la mise à jour INSEE reste à finir : l'annuaire passe APRÈS.
 *
 * PLANIFIÉE (`routes/console.php`) : chaque mois, à partir du 6, jusqu'à ce
 * que le passage du mois soit fini.
 *
 * RIEN N'EST JAMAIS SUPPRIMÉ.
 */
class CrmPublicAnnuaireOfficiel extends Command
{
    public const SIGNATURE_PLANIFIEE = 'crm:public:annuaire-officiel';

    protected $signature = self::SIGNATURE_PLANIFIEE
        . ' {--dry-run : essai à blanc — lit l annuaire et donne le bilan chiffré, n écrit RIEN}'
        . ' {--limite=0 : organismes lus au plus (0 = sans limite) ; le passage reprendra ensuite}'
        . ' {--workspace= : identifiant ou slug de l espace (défaut : l espace de prospection)}'
        . ' {--url= : autre URL d export JSONL de l annuaire (hôtes de la liste fermée seulement)}';

    protected $description = 'Ajoute e-mail, téléphone et site officiels (annuaire Service-public) aux fiches du secteur public — rapprochement certain, n écrase rien, ne supprime rien.';

    public function handle(SourceAnnuaire $source): int
    {
        $refus = TraitementsLourds::refusFenetre(now());
        if ($refus !== null) {
            $this->error($refus);
            $this->line('Fenêtre autorisée : tous les jours, 08:00-19:00 heure de Paris.');

            return self::FAILURE;
        }

        $limite = (int) $this->option('limite');
        if ($limite < 0) {
            $this->error('--limite doit être positive ou nulle.');

            return self::FAILURE;
        }

        $url = $this->option('url');
        $url = is_string($url) && trim($url) !== '' ? trim($url) : SourceAnnuaire::urlParDefaut();
        $hote = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($hote, SourceAnnuaire::HOTES_AUTORISES, true)) {
            $this->error('--url refusée : hôte hors de la liste autorisée (' . implode(', ', SourceAnnuaire::HOTES_AUTORISES) . ').');

            return self::FAILURE;
        }

        $designation = $this->option('workspace');
        $workspaceId = EspaceProspection::resoudre(is_string($designation) ? $designation : null);
        if ($workspaceId === null) {
            $this->error('Aucun espace cible (--workspace=UUID ou slug).');

            return self::FAILURE;
        }

        if (MiseAJourMensuelle::repriseEnAttente($workspaceId)) {
            $this->error('Refusé : un passage de la mise à jour INSEE reste à finir — l\'annuaire passe APRÈS elle.');

            return self::FAILURE;
        }

        $essai = (bool) $this->option('dry-run');
        if ($essai) {
            $this->warn('ESSAI À BLANC — l\'annuaire est lu depuis sa PREMIÈRE ligne, RIEN n\'est écrit (ni fiche, ni proposition, ni curseur).');
        }

        foreach (DB::getConnections() as $connexion) {
            $connexion->disableQueryLog();
            $connexion->flushQueryLog();
        }
        DB::connection()->disableQueryLog();

        $verrous = WorkspaceContext::run($workspaceId, static fn (): ?array => TraitementsLourds::verrouiller($workspaceId));
        if ($verrous === null) {
            $this->error('Refusé : un autre traitement lourd est en cours (un seul à la fois).');

            return self::FAILURE;
        }

        try {
            $resultat = (new EnrichissementAnnuaire($source))->executer(
                $workspaceId,
                $url,
                $essai,
                $limite,
                TraitementsLourds::finDeFenetre(now()),
                fn (string $ligne) => $this->line($ligne),
            );
        } catch (\Throwable $e) {
            $this->error('Échec : ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            WorkspaceContext::run($workspaceId, static fn () => TraitementsLourds::liberer($verrous));
        }

        $b = $resultat['bilan'];
        $this->info(sprintf(
            '%s%s — espace %s',
            $essai ? 'Bilan (ESSAI À BLANC)' : 'Bilan',
            $resultat['reprise'] ? ' (reprise du passage du mois)' : '',
            substr($workspaceId, 0, 8),
        ));
        $this->line(sprintf('  organismes lus : %d (sans coordonnées : %d, lignes malformées : %d)', $b['lus'], $b['sans_coordonnees'], $b['lignes_malformees']));
        $this->line(sprintf('  rapprochés par SIRET : %d', $b['rapproches_siret']));
        $this->line(sprintf('  rapprochés par SIREN : %d', $b['rapproches_siren']));
        $this->line(sprintf('  rapprochés par commune : %d', $b['rapproches_commune']));
        $this->line(sprintf('  non rapprochés : %d (ambigus : %d, fiche déjà servie : %d, hors secteur public : %d, non diffusibles : %d)', $b['non_rapproches'], $b['ambigus'], $b['doublons'], $b['hors_secteur_public'], $b['exclues_non_diffusibles']));
        $verbe = $essai ? 'à ajouter' : 'ajoutés';
        $this->line(sprintf('  e-mails %s : %d (mis à jour : %d)', $verbe, $b['emails_ajoutes'], $b['emails_mis_a_jour']));
        $this->line(sprintf('  téléphones %s : %d (mis à jour : %d)', $verbe, $b['telephones_ajoutes'], $b['telephones_mis_a_jour']));
        $this->line(sprintf('  sites %s : %d (mis à jour : %d, sites devinés confirmés : %d)', $verbe, $b['sites_ajoutes'], $b['sites_mis_a_jour'], $b['sites_confirmes']));
        $this->line(sprintf('  conflits : %d (%s ; déjà proposées ou refusées : %d)', $b['conflits'], $essai ? 'propositions à ouvrir' : 'propositions ouvertes', $b['propositions_deja_faites']));
        $this->line(sprintf('  valeurs identiques : %d', $b['inchanges']));
        $av = $resultat['avant'];
        $this->line(sprintf('  avant : %d fiches du secteur public — %d avec e-mail, %d avec téléphone, %d avec site', $av['fiches'], $av['avec_email'], $av['avec_telephone'], $av['avec_site']));
        if ($resultat['apres'] !== null) {
            $ap = $resultat['apres'];
            $this->line(sprintf('  après : %d fiches du secteur public — %d avec e-mail, %d avec téléphone, %d avec site', $ap['fiches'], $ap['avec_email'], $ap['avec_telephone'], $ap['avec_site']));
        }
        $this->line(sprintf('  curseur : ligne %d', $resultat['curseur']));
        if ($resultat['statut'] !== 'reussie') {
            $this->warn($essai
                ? 'ESSAI À BLANC INTERROMPU (--limite ou 19:00) : bilan PARTIEL — rien n\'est mémorisé.'
                : 'Passage INACHEVÉ (--limite ou 19:00) : le curseur est mémorisé, le prochain passage du mois reprendra.');
        }

        return self::SUCCESS;
    }
}

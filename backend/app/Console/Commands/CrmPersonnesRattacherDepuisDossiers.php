<?php

namespace App\Console\Commands;

use App\Crm\Console\FilesATraiter;
use App\Crm\Ingest\ContactUpserter;
use App\Crm\Ingest\PersonnesIngestService;
use App\Crm\Ingest\SoldeDemandesEnAttente;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * RATTRAPAGE (demande #784, C3) : lie à son contact la personne née d'une
 * demande, quand sa fiche existe déjà dans un dossier client, puis rattache à
 * cette fiche ses demandes en attente. Même logique et mêmes gardes que C1
 * (`DossierClientIngest`), pour ce que C1 n'a pas vu passer : dossiers arrivés
 * avant l'ouverture du drapeau, ou rattachés par arbitrage humain.
 *
 * À BLANC PAR DÉFAUT : sans `--appliquer`, rien n'est écrit. La sortie compte
 * ce qui serait fait, jamais une adresse ni une clé de personne.
 *
 * Gardes (une personne qui en rencontre une est laissée telle quelle) :
 *  - opposition (`opposition_at`, ou opposition générale `opt_out` business) ;
 *  - clé présente dans PLUSIEURS entreprises actives : ambigu, rien ;
 *  - clé divergente : un contact actif de même adresse porte une AUTRE clé ;
 *  - JAMAIS de déplacement : seules les personnes sans lien (`contact_id` nul)
 *    sont examinées ; un lien existant, même vers un contact à la corbeille,
 *    n'est pas touché ;
 *  - contacts et entreprises à la corbeille ne comptent pas.
 *
 * Aucune suppression. Les demandes sont soldées seulement avec le drapeau des
 * demandes (même règle que C1) ; `pending_match` est gardé (historique).
 */
class CrmPersonnesRattacherDepuisDossiers extends Command
{
    public const SIGNATURE = 'crm:personnes-rattacher-depuis-dossiers';

    protected $signature = self::SIGNATURE
        . ' {--a-blanc : Compte sans rien écrire (comportement par défaut)}'
        . ' {--appliquer : Écrit réellement}';

    protected $description = 'Lie aux contacts des dossiers clients les personnes nées d\'une demande et solde leurs demandes (à blanc par défaut)';

    public function handle(ContactUpserter $contacts, SoldeDemandesEnAttente $solde): int
    {
        if ((bool) $this->option('a-blanc') && (bool) $this->option('appliquer')) {
            $this->error('--a-blanc et --appliquer sont contradictoires : choisir l\'un des deux.');

            return self::FAILURE;
        }
        $appliquer = (bool) $this->option('appliquer');

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;
        $demandesOuvertes = PersonnesIngestService::drapeauDemandesOuvert();

        $bilan = WorkspaceContext::run($workspaceId, function () use ($workspaceId, $appliquer, $demandesOuvertes, $contacts, $solde): array {
            $bilan = [
                'examinees' => 0, 'a_lier' => 0, 'demandes' => 0, 'opposees' => 0, 'plusieurs' => 0,
                'divergentes' => 0, 'sans_contact' => 0, 'liees' => 0, 'soldees' => 0,
            ];

            $ids = DB::table('personnes')->where('workspace_id', $workspaceId)->whereNull('contact_id')->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                $bilan['examinees']++;
                $this->traiter((int) $id, $workspaceId, $appliquer, $demandesOuvertes, $contacts, $solde, $bilan);
            }

            return $bilan;
        });

        $this->line(($appliquer ? 'APPLIQUÉ' : 'À BLANC — rien n\'a été écrit') . ' · personnes sans lien examinées : ' . $bilan['examinees']);
        $this->line('  personnes à lier : ' . $bilan['a_lier']);
        $this->line('  demandes à solder : ' . $bilan['demandes'] . ($demandesOuvertes ? '' : ' (drapeau des demandes fermé : laissées en attente)'));
        $this->line('  personnes opposées (laissées) : ' . $bilan['opposees']);
        $this->line('  clé dans plusieurs entreprises (laissées) : ' . $bilan['plusieurs']);
        $this->line('  clé divergente (laissées) : ' . $bilan['divergentes']);
        $this->line('  sans contact actif : ' . $bilan['sans_contact']);
        if ($appliquer) {
            $this->line('Personnes liées : ' . $bilan['liees'] . ' · demandes soldées : ' . $bilan['soldees']);
        }

        return self::SUCCESS;
    }

    /** @param  array<string, int>  $bilan */
    private function traiter(int $id, string $workspaceId, bool $appliquer, bool $demandesOuvertes, ContactUpserter $contacts, SoldeDemandesEnAttente $solde, array &$bilan): void
    {
        $personne = DB::table('personnes')->where('id', $id)->first(['person_key', 'email', 'email_hash', 'opposition_at', 'contact_id']);
        if ($personne === null || ! is_string($personne->person_key) || $personne->contact_id !== null) {
            return;
        }

        $cible = $this->entrepriseUnique($workspaceId, $personne->person_key, $bilan, $personne);
        if ($cible === null) {
            return;
        }
        if ($this->estOpposee($personne)) {
            $bilan['opposees']++;

            return;
        }

        $bilan['a_lier']++;
        $bilan['demandes'] += $demandesOuvertes ? $this->demandesEnAttente($workspaceId, $personne->person_key)->count() : 0;
        if (! $appliquer) {
            return;
        }

        DB::transaction(function () use ($workspaceId, $personne, $contacts, $solde, $demandesOuvertes, &$bilan): void {
            $cle = $personne->person_key;
            // Verrou de personne AVANT tout verrou de ligne (même ordre que C1 et C2).
            SoldeDemandesEnAttente::verrouillerPersonnes($workspaceId, $cle);
            $frais = DB::table('personnes')->where('workspace_id', $workspaceId)->where('person_key', $cle)
                ->lockForUpdate()->first(['contact_id', 'email', 'email_hash', 'opposition_at']);
            if ($frais === null || $frais->contact_id !== null || $this->estOpposee($frais)) {
                return;
            }
            $ignores = array_fill_keys(['examinees', 'a_lier', 'demandes', 'opposees', 'plusieurs', 'divergentes', 'sans_contact', 'liees', 'soldees'], 0);
            $cible = $this->entrepriseUnique($workspaceId, $cle, $ignores, $frais);
            if ($cible === null) {
                return;
            }

            $contacts->lierPersonne($workspaceId, $cle, $cible[1], $cible[0]);
            $bilan['liees']++;
            if ($demandesOuvertes) {
                $bilan['soldees'] += count($solde->solder($workspaceId, $cle, $cible[0], $cible[1], [
                    'attached_by' => 'rattrapage:dossiers',
                    'commande' => self::SIGNATURE,
                ]));
            }
        });
    }

    /**
     * L'entreprise active UNIQUE où la clé est contact actif : [company_id, contact_id],
     * ou null (le bilan dit pourquoi).
     *
     * @param  array<string, int>  $bilan
     * @return array{0: int, 1: int}|null
     */
    private function entrepriseUnique(string $workspaceId, string $cle, array &$bilan, stdClass $personne): ?array
    {
        $lignes = DB::table('contacts')
            ->join('companies', 'companies.id', '=', 'contacts.company_id')
            ->where('contacts.workspace_id', $workspaceId)
            ->where('contacts.person_key', $cle)
            ->whereNull('contacts.deleted_at')
            ->whereNull('companies.deleted_at')
            ->groupBy('contacts.company_id')
            ->limit(2)
            ->get(['contacts.company_id', DB::raw('MIN(contacts.id) AS contact_id')]);

        if ($lignes->count() > 1) {
            $bilan['plusieurs']++;

            return null;
        }
        $ligne = $lignes->first();
        if ($ligne !== null) {
            return [(int) $ligne->company_id, (int) $ligne->contact_id];
        }

        // Aucun contact de cette clé : un contact actif de même adresse qui en
        // porte une AUTRE est une clé divergente, jamais rapprochée.
        $divergent = is_string($personne->email) && $personne->email !== '' && DB::table('contacts')
            ->join('companies', 'companies.id', '=', 'contacts.company_id')
            ->where('contacts.workspace_id', $workspaceId)
            ->whereRaw('lower(contacts.email) = ?', [mb_strtolower($personne->email)])
            ->whereNotNull('contacts.person_key')
            ->where('contacts.person_key', '<>', $cle)
            ->whereNull('contacts.deleted_at')
            ->whereNull('companies.deleted_at')
            ->exists();
        $bilan[$divergent ? 'divergentes' : 'sans_contact']++;

        return null;
    }

    private function estOpposee(stdClass $personne): bool
    {
        return $personne->opposition_at !== null
            || ($personne->email_hash !== null
                && DB::table('opt_out')->where('scope', 'business')->where('email_hash', $personne->email_hash)->exists());
    }

    private function demandesEnAttente(string $workspaceId, string $cle): Builder
    {
        return FilesATraiter::aRattacher($workspaceId)
            ->where('person_key', $cle)
            ->whereIn('kind', PersonnesIngestService::DEMANDE_EVENT_TYPES);
    }
}

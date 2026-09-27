<?php

namespace App\Console\Commands;

use App\Services\Dedup\DeduplicationService;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Les RETOURS d'une campagne, quel que soit l'outil qui a envoyé.
 *
 * Une ligne JSON par événement : `type`, `email`, `campagne`, `date` (ISO),
 * `evenement_id` facultatif (`crm_ref` est toléré, mais c'est l'ADRESSE qui
 * fait foi : une boîte partagée porte plusieurs fiches). Types :
 *
 *  - `envoye` : le premier message est PARTI → `first_info_at` posé, s'il était
 *    vide, sur TOUTES les fiches de l'espace qui portent cette adresse
 *    (information art. 14 : chaque organisateur cité est informé) ; la date
 *    ne peut pas être dans le futur. L'événement cité, s'il appartient à l'un
 *    de ces organisateurs et en est encore à « aucune », passe « proposée » —
 *    jamais de recul d'une étape plus avancée ;
 *  - `desinscription` / `plainte` : opposition DÉFINITIVE (portée business),
 *    plus la suppression « plainte » ;
 *  - `rebond_dur` : adresse supprimée et marquée `invalid` sur ses fiches ;
 *  - `rebond_mou` : compté, supprimé au-delà du seuil (3). ⚠️ Ce compteur vit
 *    en cache, hors transaction : il n'est PAS appelé à blanc, et rejouer un
 *    fichier réel le recompte — ne rejouer que des retours nouveaux.
 *
 * Un retour ne fait que RETIRER ou CONSTATER : il n'ajoute jamais un
 * destinataire, ne crée jamais de fiche, ne lève jamais une opposition, ne
 * touche jamais une fiche à la corbeille.
 */
class CrmCampagneRetours extends Command
{
    protected $signature = 'crm:campagne:retours
                            {file : Fichier JSONL des retours de l\'outil d\'envoi}
                            {--dry-run : Tout parcourir puis tout annuler}';

    protected $description = 'Enregistre les retours d\'une campagne (envoyé, désinscription, plainte, rebonds).';

    private const TYPES = ['envoye', 'desinscription', 'plainte', 'rebond_dur', 'rebond_mou'];

    /** @var array<string, int> */
    private array $bilan = [];

    private bool $aBlanc = false;

    public function handle(DeduplicationService $dedup): int
    {
        $chemin = (string) $this->argument('file');
        if (! is_file($chemin) || ! is_readable($chemin)) {
            $this->error("Fichier illisible : {$chemin}");

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;
        $this->aBlanc = (bool) $this->option('dry-run');

        $this->bilan = array_fill_keys([
            'lignes', 'rejetees', 'erreurs_base', 'envoyes', 'fiches_informees', 'interventions_proposees',
            'desinscriptions', 'plaintes', 'rebonds_durs', 'rebonds_mous',
        ], 0);

        WorkspaceContext::run($workspaceId, function () use ($chemin, $workspaceId, $dedup): void {
            DB::beginTransaction();
            try {
                $flux = fopen($chemin, 'rb');
                if ($flux === false) {
                    throw new \RuntimeException('Ouverture du fichier impossible.');
                }
                try {
                    $numero = 0;
                    while (($ligne = fgets($flux)) !== false) {
                        $numero++;
                        if (trim($ligne) === '') {
                            continue;
                        }
                        $this->bilan['lignes']++;
                        try {
                            DB::transaction(fn () => $this->traiter($ligne, $workspaceId, $dedup));
                        } catch (InvalidArgumentException) {
                            $this->bilan['rejetees']++;
                        } catch (QueryException $e) {
                            // Le message SQL peut citer l'adresse : seul le code d'état sort.
                            $this->bilan['erreurs_base']++;
                            Log::warning('crm:campagne:retours : ligne refusee par la base', ['ligne' => $numero, 'sqlstate' => $e->getCode()]);
                        }
                    }
                } finally {
                    fclose($flux);
                }
            } catch (\Throwable $e) {
                DB::rollBack();

                throw $e;
            }
            if ($this->aBlanc) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        });

        $echec = $this->bilan['erreurs_base'] > 0;
        if ($echec) {
            $this->error('ÉCHEC : la base a refusé des lignes (voir le journal, sans adresse).');
        } else {
            $this->info($this->aBlanc ? '[À BLANC] rien n\'a été écrit.' : 'Retours enregistrés.');
        }
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($this->bilan),
            array_values($this->bilan),
        ));

        return $echec ? self::FAILURE : self::SUCCESS;
    }

    private function traiter(string $ligne, string $workspaceId, DeduplicationService $dedup): void
    {
        $r = json_decode($ligne, true);
        if (! is_array($r)) {
            throw new InvalidArgumentException('json_invalide');
        }
        $type = $r['type'] ?? null;
        $email = is_string($r['email'] ?? null) ? mb_strtolower(trim($r['email'])) : '';
        $campagne = is_string($r['campagne'] ?? null) && trim($r['campagne']) !== '' ? trim($r['campagne']) : 'sans-nom';
        if (! in_array($type, self::TYPES, true) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('ligne_invalide');
        }
        $source = 'campagne:' . $campagne;

        switch ($type) {
            case 'desinscription':
                $dedup->addOptOut($email, null, $source, 'unsubscribe', ['business']);
                $this->bilan['desinscriptions']++;
                break;

            case 'plainte':
                $dedup->addOptOut($email, null, $source, 'complaint', ['business']);
                ListeSuppression::inscrire($email, ListeSuppression::PLAINTE, $source, 'business');
                $this->bilan['plaintes']++;
                break;

            case 'rebond_dur':
                ListeSuppression::inscrire($email, ListeSuppression::REBOND_DUR, $source, 'business');
                DB::table('contacts')
                    ->where('workspace_id', $workspaceId)
                    ->whereNull('deleted_at')
                    ->where('email', $email)
                    ->update(['email_status' => 'invalid', 'updated_at' => now()]);
                $this->bilan['rebonds_durs']++;
                break;

            case 'rebond_mou':
                // Compteur en cache, hors transaction : jamais à blanc.
                if (! $this->aBlanc) {
                    ListeSuppression::rebondTemporaire($email, $source, 'business');
                }
                $this->bilan['rebonds_mous']++;
                break;

            case 'envoye':
                $this->envoye($r, $email, $campagne, $workspaceId);
                break;
        }
    }

    /** @param  array<mixed>  $r */
    private function envoye(array $r, string $email, string $campagne, string $workspaceId): void
    {
        // Preuve art. 14 : la date du fichier, jamais dans le futur.
        $horodatage = is_string($r['date'] ?? null) ? strtotime($r['date']) : false;
        $date = $horodatage !== false && $horodatage <= time()
            ? date('c', $horodatage)
            : now()->toIso8601String();

        // TOUTES les fiches de l'espace qui portent cette adresse.
        $contacts = DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->where('email', $email)
            ->get(['id', 'company_id']);
        $companyIds = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->whereRaw('lower(email_generic) = ?', [$email])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->merge($contacts->pluck('company_id')->map(fn ($id) => (int) $id))
            ->unique()
            ->values()
            ->all();

        if ($companyIds === []) {
            // Un « envoyé » vers une adresse que le CRM ne connaît pas : rien à
            // noter. Jamais de fiche créée par un retour.
            throw new InvalidArgumentException('adresse_inconnue');
        }
        $this->bilan['envoyes']++;

        $informees = DB::table('companies')->where('workspace_id', $workspaceId)->whereIn('id', $companyIds)
            ->whereNull('deleted_at')->whereNull('first_info_at')->update(['first_info_at' => $date]);
        if ($contacts->isNotEmpty()) {
            $informees += DB::table('contacts')->where('workspace_id', $workspaceId)
                ->whereIn('id', $contacts->pluck('id')->all())
                ->whereNull('first_info_at')->update(['first_info_at' => $date]);
        }
        $this->bilan['fiches_informees'] += $informees;

        $evenementId = $r['evenement_id'] ?? null;
        $evenementId = is_int($evenementId) || (is_string($evenementId) && ctype_digit($evenementId)) ? (int) $evenementId : null;
        if ($evenementId === null) {
            return;
        }

        // L'événement doit appartenir à l'un de CES organisateurs : un retour ne
        // touche jamais la démarche d'un événement qui ne les concerne pas.
        $proposes = DB::table('events')
            ->where('workspace_id', $workspaceId)
            ->where('id', $evenementId)
            ->where('intervention', 'aucune')
            ->whereExists(function ($q) use ($companyIds): void {
                $q->selectRaw('1')->from('event_organizers')
                    ->whereColumn('event_organizers.event_id', 'events.id')
                    ->whereIn('event_organizers.company_id', $companyIds);
            })
            ->update(['intervention' => 'proposee', 'updated_at' => now()]);

        if ($proposes > 0) {
            DB::table('activities')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'type' => 'intervention_proposee',
                'kind' => 'intervention_proposee',
                'occurred_at' => $date,
                'external_ref' => 'campagne:' . $campagne . ':event:' . $evenementId,
                'subject_type' => 'event',
                'subject_id' => $evenementId,
                'title' => 'Campagne ' . $campagne,
                'payload' => json_encode(['event_id' => $evenementId, 'avant' => 'aucune', 'apres' => 'proposee', 'campagne' => $campagne], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $this->bilan['interventions_proposees']++;
        }
    }
}

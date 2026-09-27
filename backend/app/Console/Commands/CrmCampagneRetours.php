<?php

namespace App\Console\Commands;

use App\Services\Dedup\DeduplicationService;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les RETOURS d'une campagne, quel que soit l'outil qui a envoyé.
 *
 * Une ligne JSON par événement : `type`, `email`, `crm_ref` (celui de
 * `crm:campagne:destinataires`), `campagne`, `date` (ISO), `evenement_id`
 * facultatif. Types :
 *
 *  - `envoye` : le premier message est PARTI → `first_info_at` posé s'il était
 *    vide (information art. 14), sur la fiche et le contact ; l'événement cité
 *    passe « intervention proposée » s'il était encore « aucune » — jamais de
 *    recul d'une étape plus avancée ;
 *  - `desinscription` / `plainte` : opposition DÉFINITIVE (portée business),
 *    plus la suppression « plainte » ;
 *  - `rebond_dur` : adresse supprimée et marquée `invalid` sur les fiches ;
 *  - `rebond_mou` : compté, supprimé au-delà du seuil (3).
 *
 * Un retour ne fait que RETIRER ou CONSTATER : il n'ajoute jamais un
 * destinataire, ne lève jamais une opposition. Rejouer le même fichier ne
 * change rien (idempotent).
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
        $dryRun = (bool) $this->option('dry-run');

        $this->bilan = array_fill_keys([
            'lignes', 'rejetees', 'envoyes', 'premiers_messages_notes', 'interventions_proposees',
            'desinscriptions', 'plaintes', 'rebonds_durs', 'rebonds_mous',
        ], 0);

        WorkspaceContext::run($workspaceId, function () use ($chemin, $workspaceId, $dryRun, $dedup): void {
            DB::beginTransaction();
            try {
                $flux = fopen($chemin, 'rb');
                if ($flux === false) {
                    throw new \RuntimeException("Ouverture impossible : {$chemin}");
                }
                try {
                    while (($ligne = fgets($flux)) !== false) {
                        if (trim($ligne) === '') {
                            continue;
                        }
                        $this->bilan['lignes']++;
                        try {
                            DB::transaction(fn () => $this->traiter($ligne, $workspaceId, $dedup));
                        } catch (InvalidArgumentException) {
                            $this->bilan['rejetees']++;
                        }
                    }
                } finally {
                    fclose($flux);
                }
            } catch (\Throwable $e) {
                DB::rollBack();

                throw $e;
            }
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        });

        $this->info($dryRun ? '[À BLANC] rien n\'a été écrit.' : 'Retours enregistrés.');
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($this->bilan),
            array_values($this->bilan),
        ));

        return self::SUCCESS;
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
        if (! in_array($type, self::TYPES, true) || $email === '' || ! str_contains($email, '@')) {
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
                    ->where('email', $email)
                    ->update(['email_status' => 'invalid', 'updated_at' => now()]);
                $this->bilan['rebonds_durs']++;
                break;

            case 'rebond_mou':
                ListeSuppression::rebondTemporaire($email, $source, 'business');
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
        $date = is_string($r['date'] ?? null) && strtotime($r['date']) !== false
            ? date('c', (int) strtotime($r['date']))
            : now()->toIso8601String();

        [$contactId, $companyId] = $this->fiches($r['crm_ref'] ?? null, $email, $workspaceId);
        if ($companyId === null) {
            // Un « envoyé » vers une adresse que le CRM ne connaît pas : rien à
            // noter. Jamais de fiche créée par un retour.
            throw new InvalidArgumentException('adresse_inconnue');
        }
        $this->bilan['envoyes']++;

        // Information art. 14 : la date du PREMIER message, jamais écrasée.
        $notes = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $companyId)
            ->whereNull('first_info_at')->update(['first_info_at' => $date]);
        if ($contactId !== null) {
            $notes += DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', $contactId)
                ->whereNull('first_info_at')->update(['first_info_at' => $date]);
        }
        $this->bilan['premiers_messages_notes'] += $notes > 0 ? 1 : 0;

        $evenementId = is_int($r['evenement_id'] ?? null) ? $r['evenement_id'] : null;
        if ($evenementId === null) {
            return;
        }

        // L'événement doit appartenir à CET organisateur : un retour ne touche
        // jamais la démarche d'un événement qui ne le concerne pas.
        $proposes = DB::table('events')
            ->where('workspace_id', $workspaceId)
            ->where('id', $evenementId)
            ->where('intervention', 'aucune')
            ->whereExists(function ($q) use ($companyId): void {
                $q->selectRaw('1')->from('event_organizers')
                    ->whereColumn('event_organizers.event_id', 'events.id')
                    ->where('event_organizers.company_id', $companyId);
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

    /**
     * La fiche visée : par `crm_ref` (contact:ID / organisation:ID), sinon par
     * l'adresse. L'adresse doit toujours correspondre à la fiche nommée.
     *
     * @return array{0: ?int, 1: ?int} [contact_id, company_id]
     */
    private function fiches(mixed $crmRef, string $email, string $workspaceId): array
    {
        if (is_string($crmRef) && preg_match('/^(contact|organisation):(\d+)$/', $crmRef, $m) === 1) {
            if ($m[1] === 'contact') {
                $c = DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', (int) $m[2])
                    ->where('email', $email)->first(['id', 'company_id']);

                return $c === null ? [null, null] : [(int) $c->id, (int) $c->company_id];
            }
            $id = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', (int) $m[2])
                ->where('email_generic', $email)->value('id');

            return [null, $id === null ? null : (int) $id];
        }

        $c = DB::table('contacts')->where('workspace_id', $workspaceId)->where('email', $email)
            ->orderBy('id')->first(['id', 'company_id']);
        if ($c !== null) {
            return [(int) $c->id, (int) $c->company_id];
        }
        $id = DB::table('companies')->where('workspace_id', $workspaceId)
            ->where('email_generic', $email)->orderBy('id')->value('id');

        return [null, $id === null ? null : (int) $id];
    }
}

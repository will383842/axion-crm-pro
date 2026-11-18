<?php

namespace App\Crm\Ingest;

use App\Crm\Console\FilesATraiter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * Rattache à une entreprise les DEMANDES en attente d'une personne (même
 * `person_key`). Corps de l'ancien `RattachementEnAttente::solderLesAutres`,
 * sorti pour que le dossier client (`DossierClientIngest`) solde de la même
 * façon que l'arbitrage humain (demande #784, C1).
 *
 * Jamais un dossier, une opposition ni une inscription : seulement
 * `PersonnesIngestService::DEMANDE_EVENT_TYPES`. `pending_match` est gardé :
 * c'est l'historique de ce qui a été déclaré.
 *
 * À appeler DANS le contexte d'espace et la transaction de l'appelant.
 */
final class SoldeDemandesEnAttente
{
    public function __construct(private readonly Container $conteneur) {}

    /**
     * Verrou de transaction par personne. À prendre AVANT tout verrou de ligne :
     * les clés sont dédoublonnées puis triées, deux appelants ne s'interbloquent pas.
     */
    public static function verrouillerPersonnes(string $workspaceId, string ...$personKeys): void
    {
        $cles = array_values(array_unique($personKeys));
        sort($cles, SORT_STRING);

        foreach ($cles as $cle) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['personne|' . $workspaceId . '|' . $cle]);
        }
    }

    /**
     * @param  array<string, mixed>  $trace  fusionné dans `payload.arbitrage`
     * @return list<int> ids des activités soldées
     */
    public function solder(string $workspaceId, string $personKey, int $companyId, ?int $contactId, array $trace, ?int $sauf = null): array
    {
        $autres = FilesATraiter::aRattacher($workspaceId)
            ->where('person_key', $personKey)
            // Seulement des DEMANDES : jamais une inscription à la lettre, une
            // opposition ni un dossier client, que d'autres gestes traitent.
            ->whereIn('kind', PersonnesIngestService::DEMANDE_EVENT_TYPES)
            ->when($sauf !== null, static fn ($q) => $q->where('id', '!=', $sauf))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $soldes = [];
        foreach ($autres as $autre) {
            $payload = json_decode(is_string($autre->payload ?? null) ? $autre->payload : '', true);
            if (! is_array($payload) || ! is_array($payload['pending_match'] ?? null)) {
                continue;
            }

            $payload['arbitrage'] = [
                'attached_at' => now()->toIso8601String(),
                'company_id' => $companyId,
                'contact_id' => $contactId,
            ] + $trace;

            DB::table('activities')->where('id', $autre->id)->whereNull('subject_id')->update([
                'subject_type' => 'company',
                'subject_id' => $companyId,
                'contact_id' => $contactId,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

            // Résolu À L'APPEL : `SiteSyncIngestService` dépend de
            // `DossierClientIngest`, qui dépend de ce service (boucle sinon).
            $this->conteneur->make(SiteSyncIngestService::class)->etiqueterRendezVousRattache(
                $workspaceId,
                $companyId,
                is_string($autre->kind ?? null) ? $autre->kind : '',
                $payload,
            );

            $soldes[] = (int) $autre->id;
        }

        return $soldes;
    }
}

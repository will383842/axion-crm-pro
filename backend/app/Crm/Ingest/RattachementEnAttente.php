<?php

namespace App\Crm\Ingest;

use App\Crm\Console\FilesATraiter;
use Illuminate\Support\Facades\DB;

/**
 * RATTACHER À UNE ENTREPRISE un événement resté dans la file d'arbitrage
 * (lot C3 de la fiche demande) : le corps de `ArbitrageController::attach`,
 * sorti du contrôleur pour qu'un autre appelant (écran des demandes, commande)
 * rattache EXACTEMENT comme lui. Deux implémentations finiraient par diverger.
 *
 * ── Ce que le rattachement solde en plus ───────────────────────────────────
 *
 * Depuis la fiche demande, une même personne peut avoir plusieurs événements
 * en attente (un formulaire, puis un rendez-vous). L'opérateur a choisi
 * l'entreprise de CETTE personne : ses autres événements en attente (même
 * `person_key`) sont rattachés à la même entreprise, dans la même
 * transaction, et la file se vide d'un coup. Jamais un événement
 * `dossier_client_synchro` : il porte une référence console qui demande son
 * propre geste (`DossierClientIngest::appliquerApresArbitrage`).
 *
 * À appeler DANS le contexte d'espace et la transaction de l'appelant. Les
 * refus sortent par `abort()` (404 / 409), comme avant l'extraction.
 */
final class RattachementEnAttente
{
    public function __construct(
        private readonly ContactUpserter $contacts,
        private readonly SiteSyncClassifier $classifier,
        private readonly SiteSyncIngestService $ingestion,
        private readonly DossierClientIngest $dossiers,
    ) {}

    /**
     * @return array{activity_id: int, company_id: int, contact_id: ?int, contact_created: bool, soldes: list<int>}
     */
    public function rattacher(string $workspaceId, int $activityId, int $companyId, string $parUtilisateur): array
    {
        $activity = $this->verrouiller($workspaceId, $activityId);

        if ($activity->subject_id !== null) {
            abort(409, 'Cet événement est déjà rattaché.');
        }

        $payload = $this->decoder($activity->payload ?? null);
        $pending = $payload['pending_match'] ?? null;
        if (! is_array($pending)) {
            abort(409, "Cet événement n'est pas en attente de rapprochement.");
        }

        $company = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->where('id', $companyId)
            ->whereNull('deleted_at')
            ->first();

        if ($company === null) {
            // 404 et non 403 : côté opérateur, une entreprise d'un autre
            // workspace et une entreprise inexistante sont la même chose
            // — et doivent le rester, sinon la réponse devient un
            // oracle d'existence inter-tenants.
            abort(404, 'Entreprise introuvable dans cet univers.');
        }

        $legalBasis = $this->texte($pending, 'legal_basis') ?? 'precontractual';

        // La base légale enregistrée à l'ingestion l'emporte sur celle
        // d'une fiche collectée (`legitimate_interest_b2b`) : la
        // personne s'est manifestée. On ne redescend jamais.
        DB::table('companies')->where('id', $companyId)->update([
            'legal_basis' => $this->classifier->mergeLegalBasis(
                is_string($company->legal_basis ?? null) ? $company->legal_basis : null,
                $legalBasis,
            ),
            'updated_at' => now(),
        ]);

        $contactId = null;
        $personKey = is_string($activity->person_key) ? $activity->person_key : null;

        if ($personKey !== null) {
            $upserted = $this->contacts->upsert(
                workspaceId: $workspaceId,
                companyId: $companyId,
                personKey: $personKey,
                externalRef: $this->texte($pending, 'subject_ref'),
                email: $this->texte($pending, 'email'),
                firstName: $this->texte($pending, 'first_name'),
                lastName: $this->texte($pending, 'last_name'),
                phone: $this->texte($pending, 'phone'),
                legalBasis: $legalBasis,
                consentVersion: $this->texte($pending, 'consent_version'),
                consentAt: $this->date($pending, 'consent_at'),
                consentTextRef: $this->texte($pending, 'consent_text_ref'),
                discoverySource: 'site',
            );

            $contactId = $upserted === null ? null : $upserted[0];
        }

        // Dossier client de la console (25/10/2026) : le rattachement
        // pose la référence console sur la fiche choisie et applique
        // le dossier le plus récent, avec les mêmes garde-fous que la
        // synchro automatique. Le geste est humain : c'est la SEULE
        // façon de lier un client sans SIREN, jamais une fusion.
        $dossier = null;
        if (($activity->kind ?? null) === DossierClient::EVENT_TYPE) {
            $refClient = $this->texte($pending, 'subject_ref');
            if ($refClient === null) {
                abort(409, "Ce dossier client n'a pas de référence console.");
            }
            $dossier = $this->dossiers->appliquerApresArbitrage($workspaceId, $companyId, $refClient, $activityId);
        }

        $payload['arbitrage'] = [
            'attached_at' => now()->toIso8601String(),
            'attached_by' => $parUtilisateur,
            'company_id' => $companyId,
            'contact_id' => $contactId,
        ];
        if ($dossier !== null) {
            $payload['arbitrage']['dossier'] = $dossier;
        }

        DB::table('activities')->where('id', $activityId)->update([
            'subject_type' => 'company',
            'subject_id' => $companyId,
            'contact_id' => $contactId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        // Un rendez-vous d'un prospect inconnu n'a reçu aucune
        // étiquette à l'ingestion (pas de fiche où la poser) : c'est
        // maintenant qu'elle trouve sa fiche.
        $this->ingestion->etiqueterRendezVousRattache(
            $workspaceId,
            $companyId,
            is_string($activity->kind ?? null) ? $activity->kind : '',
            $payload,
        );

        $soldes = $personKey === null || ($activity->kind ?? null) === DossierClient::EVENT_TYPE
            ? []
            : $this->solderLesAutres($workspaceId, $activityId, $personKey, $companyId, $contactId, $parUtilisateur);

        return [
            'activity_id' => $activityId,
            'company_id' => $companyId,
            'contact_id' => $contactId,
            // `null` n'est pas un échec : c'est le cas « aucun nom
            // transmis », où l'on refuse de fabriquer un patronyme
            // depuis une adresse électronique. L'événement reste dans la
            // timeline de l'entreprise.
            'contact_created' => $contactId !== null,
            'soldes' => $soldes,
        ];
    }

    /**
     * Les AUTRES événements en attente de la même personne rejoignent la même
     * entreprise. Verrouillés, comme l'événement principal : deux opérateurs
     * qui rattachent en même temps ne les soldent pas deux fois.
     *
     * @return list<int>
     */
    private function solderLesAutres(string $workspaceId, int $principal, string $personKey, int $companyId, ?int $contactId, string $parUtilisateur): array
    {
        $autres = FilesATraiter::aRattacher($workspaceId)
            ->where('person_key', $personKey)
            ->where('id', '!=', $principal)
            ->where('kind', '!=', DossierClient::EVENT_TYPE)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $soldes = [];
        foreach ($autres as $autre) {
            $payload = $this->decoder($autre->payload ?? null);
            if (! is_array($payload['pending_match'] ?? null)) {
                continue;
            }

            $payload['arbitrage'] = [
                'attached_at' => now()->toIso8601String(),
                'attached_by' => $parUtilisateur,
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'solde_avec' => $principal,
            ];

            DB::table('activities')->where('id', $autre->id)->whereNull('subject_id')->update([
                'subject_type' => 'company',
                'subject_id' => $companyId,
                'contact_id' => $contactId,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

            $this->ingestion->etiqueterRendezVousRattache(
                $workspaceId,
                $companyId,
                is_string($autre->kind ?? null) ? $autre->kind : '',
                $payload,
            );

            $soldes[] = (int) $autre->id;
        }

        return $soldes;
    }

    /** Verrou de ligne : deux opérateurs sur le même événement produisent un 409, pas deux fiches. */
    private function verrouiller(string $workspaceId, int $activityId): \stdClass
    {
        $activity = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->where('id', $activityId)
            ->lockForUpdate()
            ->first();

        if ($activity === null) {
            abort(404);
        }

        return $activity;
    }

    /** @return array<string, mixed> */
    private function decoder(mixed $brut): array
    {
        if (! is_string($brut) || $brut === '') {
            return [];
        }

        $decode = json_decode($brut, true);

        /** @var array<string, mixed> */
        return is_array($decode) ? $decode : [];
    }

    /** @param  array<mixed>  $data */
    private function texte(array $data, string $cle): ?string
    {
        $valeur = $data[$cle] ?? null;

        return is_string($valeur) && trim($valeur) !== '' ? trim($valeur) : null;
    }

    /** @param  array<mixed>  $data */
    private function date(array $data, string $cle): ?\DateTimeImmutable
    {
        $valeur = $this->texte($data, $cle);
        if ($valeur === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            return null;
        }
    }
}

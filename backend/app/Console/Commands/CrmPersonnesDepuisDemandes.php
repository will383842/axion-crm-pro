<?php

namespace App\Console\Commands;

use App\Crm\Ingest\PersonnesIngestService;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncRejection;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RATTRAPAGE de la fiche demande (lot C1) : une PERSONNE pour chaque demande
 * du site restée sans sujet dans la file d'arbitrage.
 *
 * Avant ce lot, une demande sans SIREN (formulaire, rendez-vous) ne créait
 * rien : elle restait en `pending_match` avec ses coordonnées dans le JSON.
 * Cette commande relit ces activités et crée (ou retrouve) la personne, avec
 * EXACTEMENT les règles de l'ingestion en direct (`PersonnesIngestService`).
 *
 * ── Ce qu'elle ne fait JAMAIS ──────────────────────────────────────────────
 *
 *   - supprimer quoi que ce soit ;
 *   - modifier une ligne d'`activities` (ni le sujet, ni le `pending_match` :
 *     les demandes restent dans la file d'arbitrage, à rattacher à la main) ;
 *   - écrire ailleurs que dans `personnes` ;
 *   - créer un abonnement (une personne née d'une demande n'est jamais
 *     éligible à une diffusion) ;
 *   - toucher un candidat, un apporteur, une opposition, un avis : seuls
 *     `form_submission` et `calendly_*` sont visés ;
 *   - imprimer une adresse, un nom ou un téléphone, en sortie ou au journal :
 *     des comptes seulement (dépôt public, journaux collectés).
 *
 * ── À blanc par défaut ─────────────────────────────────────────────────────
 *
 * Sans `--appliquer`, rien n'est écrit. Ordre de mise en production : copie de
 * la base, exécution à blanc, application SUR LA COPIE, comparaison des
 * comptes, sauvegarde, puis seulement la production. La commande ne dépend pas
 * du drapeau `CRM_INGEST_DEMANDES_PERSONNE_ENABLED` (geste délibéré), mais
 * exige la migration `2026_11_08_000010`.
 */
class CrmPersonnesDepuisDemandes extends Command
{
    public const SIGNATURE = 'crm:personnes-depuis-demandes';

    protected $signature = self::SIGNATURE
        . ' {--a-blanc : Compte sans rien écrire (comportement par défaut)}'
        . ' {--appliquer : Écrit réellement dans `personnes` — après copie de base et sauvegarde}';

    protected $description = 'Crée les personnes des demandes (formulaire, rendez-vous) restées sans sujet dans la file d arbitrage (à blanc par défaut)';

    public function handle(PersonnesIngestService $personnes): int
    {
        if ((bool) $this->option('a-blanc') && (bool) $this->option('appliquer')) {
            $this->error('--a-blanc et --appliquer sont contradictoires : choisir l\'un des deux.');

            return self::FAILURE;
        }

        $appliquer = (bool) $this->option('appliquer');

        if (! PersonnesIngestService::colonnesDemandePresentes()) {
            $this->error('Migration absente : colonnes `personnes.phone` et `personnes.entreprise_declaree` introuvables. Rien n\'a été fait.');

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;

        $bilan = WorkspaceContext::run($workspaceId, fn (): array => DB::transaction(
            fn (): array => $this->parcourir($personnes, $workspaceId, $appliquer),
        ));

        $this->line(($appliquer ? 'APPLIQUÉ' : 'À BLANC — rien n\'a été écrit') . ' · types : ' . implode(', ', PersonnesIngestService::DEMANDE_EVENT_TYPES));
        $this->line('Activités visées (sans sujet, en attente de rapprochement) : ' . $bilan['visees']);
        $this->line('  dont écartées par l\'opérateur (jamais traitées) : ' . $bilan['ecartees_operateur']);
        $this->line('  dont hors périmètre (échange apporteur, sans adresse) : ' . $bilan['hors_perimetre']);
        $this->line('  dont illisibles (clé de personne ou date invalide) : ' . $bilan['illisibles']);
        $this->line('  dont écartées par une opposition ou une mise à l\'écart : ' . $bilan['ecartees_opposition']);
        $this->line('Personnes distinctes concernées : ' . $bilan['personnes_distinctes']);
        $this->line('  dont à créer' . ($appliquer ? ' (créées)' : '') . ' : ' . $bilan['a_creer']);
        $this->line('  dont déjà présentes (mises à jour sans écraser) : ' . $bilan['existantes']);

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function parcourir(PersonnesIngestService $personnes, string $workspaceId, bool $appliquer): array
    {
        $bilan = [
            'visees' => 0, 'ecartees_operateur' => 0, 'hors_perimetre' => 0, 'illisibles' => 0,
            'ecartees_opposition' => 0, 'personnes_distinctes' => 0, 'a_creer' => 0, 'existantes' => 0,
        ];
        // Une personne = une seule fois, quel que soit le nombre de ses demandes.
        $vues = [];

        // Chronologique : la dernière valeur déclarée est lue en dernier.
        $requete = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->whereNull('subject_id')
            ->whereIn('kind', PersonnesIngestService::DEMANDE_EVENT_TYPES)
            ->whereRaw("payload -> 'pending_match' IS NOT NULL");

        $requete->orderBy('occurred_at')->orderBy('id')->chunk(500, function ($lignes) use ($personnes, $workspaceId, $appliquer, &$bilan, &$vues): void {
            foreach ($lignes as $ligne) {
                $bilan['visees']++;

                $payload = json_decode(is_string($ligne->payload) ? $ligne->payload : '{}', true);
                $payload = is_array($payload) ? $payload : [];

                if (isset($payload['arbitrage_dismissed'])) {
                    $bilan['ecartees_operateur']++;

                    continue;
                }

                $evenement = $this->evenement($ligne, $payload);
                if ($evenement === null) {
                    $bilan['illisibles']++;

                    continue;
                }

                if (! $personnes->estUneDemande($evenement, 'business')) {
                    $bilan['hors_perimetre']++;

                    continue;
                }

                $etat = $appliquer
                    ? $personnes->assurerDepuisDemande($evenement, $workspaceId)
                    : $personnes->evaluerDemande($evenement, $workspaceId);

                if ($etat === PersonnesIngestService::DEMANDE_ECARTEE) {
                    $bilan['ecartees_opposition']++;

                    continue;
                }

                $cle = $evenement->personKey();
                if (isset($vues[$cle])) {
                    continue;
                }
                $vues[$cle] = true;
                $bilan['personnes_distinctes']++;
                $bilan[$etat === PersonnesIngestService::DEMANDE_A_CREER ? 'a_creer' : 'existantes']++;
            }
        });

        return $bilan;
    }

    /**
     * Reconstitue l'événement du site depuis l'activité. `null` si la clé de
     * personne ou la date est inutilisable : l'activité est comptée, jamais
     * devinée.
     *
     * @param  array<string, mixed>  $payload
     */
    private function evenement(\stdClass $ligne, array $payload): ?SiteSyncEvent
    {
        $pending = is_array($payload['pending_match'] ?? null) ? $payload['pending_match'] : [];
        $texte = static fn (string $cle): ?string => is_string($pending[$cle] ?? null) && trim((string) $pending[$cle]) !== '' ? trim((string) $pending[$cle]) : null;

        $occurredAt = $ligne->occurred_at ?? $ligne->created_at ?? null;
        if (! is_string($occurredAt) || ! is_string($ligne->person_key ?? null)) {
            return null;
        }

        $sujet = $texte('subject_ref') ?? 'site:rattrapage:' . (int) $ligne->id;
        $slug = is_string($payload['source_slug'] ?? null) && $payload['source_slug'] !== '' ? $payload['source_slug'] : null;

        $brut = [
            'schema_version' => 1,
            'event_id' => 'rattrapage-demande-' . (int) $ligne->id,
            'event_type' => (string) $ligne->kind,
            'occurred_at' => (new \DateTimeImmutable($occurredAt))->format(DATE_ATOM),
            'subject_ref' => str_starts_with($sujet, 'site:') ? $sujet : 'site:rattrapage:' . (int) $ligne->id,
            'person' => array_filter([
                'person_key' => $ligne->person_key,
                'email' => $texte('email'),
                'first_name' => $texte('first_name'),
                'last_name' => $texte('last_name'),
                'phone' => $texte('phone'),
            ], static fn (?string $v): bool => $v !== null),
            'company' => array_filter(['name' => $texte('denomination')], static fn (?string $v): bool => $v !== null),
            'payload' => array_filter(
                ['eventTypeName' => is_string($payload['eventTypeName'] ?? null) ? $payload['eventTypeName'] : null],
                static fn (?string $v): bool => $v !== null,
            ),
        ];
        if ($slug !== null) {
            $brut['source_slug'] = $slug;
        }
        // Le type de formulaire n'est pas conservé dans l'activité : `autre`
        // suffit, la fiche demande ne s'en sert pas (le recrutement n'a jamais
        // de `pending_match`, il passe par l'univers vivier).
        if ($brut['event_type'] === 'form_submission') {
            $brut['form_type'] = 'autre';
        }

        try {
            return SiteSyncEvent::fromArray($brut);
        } catch (SiteSyncRejection|\Exception) {
            return null;
        }
    }
}

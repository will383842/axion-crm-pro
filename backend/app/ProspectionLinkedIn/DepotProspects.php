<?php

namespace App\ProspectionLinkedIn;

use App\Crm\Opposition\StatutOpposition;
use App\Support\LinkedinSlug;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * PONT-LINKEDIN LI-B1 — dépôt des prospects de la file LinkedIn (voie S : le fondateur clique lui-même). Ajout, rapprochement
 * avec une fiche, gestes journalisés. Jamais d'appel vers LinkedIn, jamais de suppression : un prospect écarté est définitif.
 * Chaque lecture et écriture est sous `WorkspaceContext::run` ET filtrée par `workspace_id`.
 */
final class DepotProspects
{
    public const SOURCES = ['recherche', 'commentaire_post', 'evenement', 'apporteur', 'base_crm'];

    public function __construct(
        private readonly ?StatutOpposition $opposition = null,
        private readonly ?Rapprochement $rapprochement = null,
        private readonly ?JournalProspection $journal = null,
    ) {}

    /**
     * @return array{refus: ?string, cree: bool, prospect_id: ?string, contact_id: ?int}
     *
     * @throws RuntimeException registre d'opposition ou base illisible : rien n'est créé (échec fermé)
     */
    public function ajouter(
        string $workspaceId,
        string $expediteurId,
        string $profil,
        string $source,
        ?string $sourceRef,
        string $sourceLe,
        ?string $entreprise = null,
        ?string $siren = null,
    ): array {
        $slug = LinkedinSlug::normalize($profil);
        if ($slug === null) {
            return self::refus('profil_invalide');
        }
        if (! in_array($source, self::SOURCES, true)) {
            return self::refus('source_invalide');
        }
        if ($sourceRef !== null && (mb_strlen($sourceRef) > 120 || str_contains($sourceRef, '@'))) {
            return self::refus('source_ref_invalide');
        }
        if ($siren !== null && preg_match('/^[0-9]{9}$/', $siren) !== 1) {
            return self::refus('siren_invalide');
        }
        if ($entreprise !== null && mb_strlen($entreprise) > 200) {
            return self::refus('entreprise_invalide');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $sourceLe);
        if ($date === false || $date->format('Y-m-d') !== $sourceLe) {
            return self::refus('date_invalide');
        }

        // Échec fermé : une exception ici (registre illisible) remonte, rien n'est créé. Un opposé n'est JAMAIS réinscrit.
        if (($this->opposition ?? new StatutOpposition)->profilsOpposes($workspaceId, [$slug])[$slug] ?? true) {
            return self::refus('oppose');
        }

        try {
            $existant = $this->trouver($workspaceId, $expediteurId, $slug);
            if ($existant !== null) {
                return ['refus' => null, 'cree' => false, 'prospect_id' => (string) $existant->id, 'contact_id' => $existant->contact_id === null ? null : (int) $existant->contact_id];
            }

            $rapproche = ($this->rapprochement ?? new Rapprochement)->contactPour($workspaceId, $slug);
            $id = (string) Str::uuid();
            $insere = WorkspaceContext::run($workspaceId, static fn (): bool => DB::table('pl_prospects')->insertOrIgnore([
                'id' => $id,
                'workspace_id' => $workspaceId,
                'expediteur_user_id' => $expediteurId,
                'linkedin_slug' => $slug,
                'contact_id' => $rapproche['contact_id'],
                'entreprise' => $entreprise,
                'siren' => $siren,
                'source' => $source,
                'source_ref' => $sourceRef,
                'source_le' => $sourceLe,
            ]) === 1);
            if (! $insere) { // course perdue : l'autre écriture a gagné, on rend la sienne
                $gagnant = $this->trouver($workspaceId, $expediteurId, $slug);
                if ($gagnant === null) {
                    throw new RuntimeException('Dépôt du prospect impossible.');
                }

                return ['refus' => null, 'cree' => false, 'prospect_id' => (string) $gagnant->id, 'contact_id' => $gagnant->contact_id === null ? null : (int) $gagnant->contact_id];
            }

            ($this->journal ?? new JournalProspection)->ecrire(
                $workspaceId,
                $id,
                'ajout',
                'systeme',
                'prospection_linkedin',
                'ajout-' . $id,
                ['source' => $source, 'rapprochement_ambigu' => $rapproche['ambigu']],
                null,
                'candidat',
            );

            return ['refus' => null, 'cree' => true, 'prospect_id' => $id, 'contact_id' => $rapproche['contact_id']];
        } catch (QueryException) {
            throw new RuntimeException('Dépôt du prospect impossible.');
        }
    }

    /**
     * Applique un geste du fondateur : verrou de la ligne, état, journal (idempotent), mise à jour seulement si changement.
     *
     * @param  array<string, mixed>  $details
     * @return array{etat: string, sans_effet: bool, rejeu: bool}
     *
     * @throws RuntimeException prospect introuvable dans cet espace
     * @throws InvalidArgumentException geste inconnu, ou clé déjà utilisée par un autre prospect
     */
    public function appliquerGeste(string $workspaceId, string $prospectId, string $geste, string $cleIdempotence, string $auteur, array $details = []): array
    {
        $journal = $this->journal ?? new JournalProspection;

        try {
            return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $prospectId, $geste, $cleIdempotence, $auteur, $details, $journal): array {
                return DB::transaction(function () use ($workspaceId, $prospectId, $geste, $cleIdempotence, $auteur, $details, $journal): array {
                    $prospect = DB::table('pl_prospects')->where('workspace_id', $workspaceId)->where('id', $prospectId)->lockForUpdate()->first();
                    if ($prospect === null) {
                        throw new RuntimeException('Prospect introuvable.');
                    }

                    $deja = $journal->trouver($workspaceId, $cleIdempotence);
                    if ($deja !== null) {
                        if ((string) $deja->prospect_id !== $prospectId) {
                            throw new InvalidArgumentException('Clé d’idempotence déjà utilisée pour un autre prospect.');
                        }

                        return ['etat' => (string) ($deja->etat_apres ?? $prospect->etat), 'sans_effet' => (bool) $deja->sans_effet, 'rejeu' => true];
                    }

                    $avant = (string) $prospect->etat;
                    $r = EtatsProspect::appliquer($avant, $geste);
                    $journal->ecrire($workspaceId, $prospectId, $geste, 'humain', $auteur, $cleIdempotence, $details, $avant, $r['etat'], $r['sans_effet']);
                    if ($r['etat'] !== $avant) {
                        DB::table('pl_prospects')->where('workspace_id', $workspaceId)->where('id', $prospectId)
                            ->update(['etat' => $r['etat'], 'maj_le' => now()]);
                    }

                    return ['etat' => $r['etat'], 'sans_effet' => $r['sans_effet'], 'rejeu' => false];
                });
            });
        } catch (QueryException) {
            throw new RuntimeException('Geste impossible à enregistrer.');
        }
    }

    private function trouver(string $workspaceId, string $expediteurId, string $slug): ?\stdClass
    {
        return WorkspaceContext::run($workspaceId, static fn (): ?\stdClass => DB::table('pl_prospects')
            ->where('workspace_id', $workspaceId)->where('expediteur_user_id', $expediteurId)->where('linkedin_slug', $slug)->first());
    }

    /** @return array{refus: string, cree: false, prospect_id: null, contact_id: null} */
    private static function refus(string $motif): array
    {
        return ['refus' => $motif, 'cree' => false, 'prospect_id' => null, 'contact_id' => null];
    }
}

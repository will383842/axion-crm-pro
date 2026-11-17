<?php

namespace App\Crm\Affaires;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « AFFAIRES » (lot G2-D, cahier G1-G2 § 11) : lecture et écriture des affaires (`deals`) de l'espace business.
 *
 * Les 7 étapes de l'écran vivent dans `deals.etape` (migration G2-C) ; `deals.status` est recalculé dans la même
 * requête. On ne SUPPRIME JAMAIS une affaire (ni requête de suppression ici, ni route : test de source). Les
 * étapes par espace (`pipeline_stages`) ne sont pas utilisées, et l'historique `deal_history` n'est jamais écrit :
 * le journal est une ligne `activities` de nature `stage_changed`.
 *
 * Le filtre `workspace_id` reste explicite dans chaque requête (le propriétaire, superutilisateur en test,
 * ignore la RLS) ; l'appelant pose aussi le `WorkspaceContext`.
 */
final class Affaires
{
    public const FUSEAU = 'Europe/Paris';

    public const LIMITE = 500;

    public const ETAPES = ['a_contacter', 'contacte', 'repondu', 'rendez_vous', 'devis', 'gagne', 'perdu'];

    private const STATUT_PAR_ETAPE = ['gagne' => 'won', 'perdu' => 'lost'];

    private const SELECTION = "d.id, c.denomination, d.company_id, d.value_eur, d.etape, d.probability, d.raison_perte,
        (d.expected_close AT TIME ZONE 'Europe/Paris')::date::text AS signature";

    public static function aujourdhui(CarbonImmutable $maintenant): string
    {
        return $maintenant->setTimezone(self::FUSEAU)->format('Y-m-d');
    }

    /** @return list<array<string,mixed>> */
    public static function liste(string $workspaceId): array
    {
        $lignes = DB::select(
            'SELECT ' . self::SELECTION . '
             FROM deals d JOIN companies c ON c.id = d.company_id AND c.workspace_id = d.workspace_id
             WHERE d.workspace_id = :ws AND c.deleted_at IS NULL
             ORDER BY d.created_at DESC, d.id DESC
             LIMIT ' . self::LIMITE,
            ['ws' => $workspaceId],
        );

        return array_map(self::projeter(...), array_values($lignes));
    }

    /**
     * Crée une affaire ouverte à l'étape initiale. Null = entreprise introuvable ou en corbeille (404).
     *
     * @return array<string,mixed>|null
     */
    public static function creer(string $workspaceId, int $entrepriseId, ?string $titre, ?float $montant, ?int $chance, ?string $signaturePrevue): ?array
    {
        $existe = DB::selectOne(
            'SELECT id FROM companies WHERE workspace_id = :ws AND id = :id AND deleted_at IS NULL',
            ['ws' => $workspaceId, 'id' => $entrepriseId],
        );
        if ($existe === null) {
            return null;
        }

        $echeance = $signaturePrevue === null
            ? null
            : CarbonImmutable::parse($signaturePrevue . ' 12:00', self::FUSEAU)->utc()->format('Y-m-d H:i:sP');

        $id = (int) DB::table('deals')->insertGetId([
            'workspace_id' => $workspaceId,
            'company_id' => $entrepriseId,
            'titre' => $titre,
            'value_eur' => $montant,
            'probability' => $chance,
            'expected_close' => $echeance,
            'etape' => 'a_contacter',
            'status' => 'open',
        ]);

        return self::une($workspaceId, $id);
    }

    /**
     * Change l'étape, recalcule `status`, journalise. Null = introuvable (autre espace, entreprise en corbeille).
     *
     * @return array<string,mixed>|null
     */
    public static function deplacer(string $workspaceId, string $userId, int $id, string $etape, ?string $raison, CarbonImmutable $maintenant): ?array
    {
        return DB::transaction(function () use ($workspaceId, $userId, $id, $etape, $raison, $maintenant): ?array {
            $ligne = DB::selectOne(
                'SELECT d.id, d.etape FROM deals d JOIN companies c ON c.id = d.company_id AND c.workspace_id = d.workspace_id
                 WHERE d.workspace_id = :ws AND d.id = :id AND c.deleted_at IS NULL FOR UPDATE OF d',
                ['ws' => $workspaceId, 'id' => $id],
            );
            if ($ligne === null) {
                return null;
            }

            $status = self::STATUT_PAR_ETAPE[$etape] ?? 'open';
            DB::table('deals')->where('workspace_id', $workspaceId)->where('id', $id)->update([
                'etape' => $etape,
                'status' => $status,
                'raison_perte' => $etape === 'perdu' ? $raison : null,
            ]);

            if ($ligne->etape !== $etape) {
                DB::table('activities')->insert([
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'type' => 'stage_changed',
                    'kind' => 'stage_changed',
                    'occurred_at' => $maintenant->utc()->format('Y-m-d H:i:sP'),
                    'subject_type' => 'deal',
                    'subject_id' => $id,
                    'deal_id' => $id,
                    'title' => 'Étape : ' . $ligne->etape . ' → ' . $etape,
                    'payload' => json_encode(['de' => $ligne->etape, 'vers' => $etape], JSON_THROW_ON_ERROR),
                    'created_at' => $maintenant->utc()->format('Y-m-d H:i:sP'),
                ]);
            }

            return self::une($workspaceId, $id);
        });
    }

    /** @return array<string,mixed>|null */
    private static function une(string $workspaceId, int $id): ?array
    {
        $ligne = DB::selectOne(
            'SELECT ' . self::SELECTION . '
             FROM deals d JOIN companies c ON c.id = d.company_id AND c.workspace_id = d.workspace_id
             WHERE d.workspace_id = :ws AND d.id = :id',
            ['ws' => $workspaceId, 'id' => $id],
        );

        return $ligne === null ? null : self::projeter($ligne);
    }

    /** @return array<string,mixed> */
    private static function projeter(\stdClass $l): array
    {
        return [
            'id' => (string) $l->id,
            'entreprise' => (string) $l->denomination,
            'entreprise_id' => (int) $l->company_id,
            'montant' => $l->value_eur === null ? null : (float) $l->value_eur,
            'etape' => (string) $l->etape,
            'chance' => (int) ($l->probability ?? 0),
            'signature_prevue' => $l->signature,
            'raison_perte' => $l->raison_perte,
        ];
    }
}

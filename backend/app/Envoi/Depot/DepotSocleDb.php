<?php

namespace App\Envoi\Depot;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Dépôt Postgres (RLS par workspace). Aucune suppression de ligne, nulle part. */
final class DepotSocleDb implements DepotSocle
{
    public function toutArreter(string $workspaceId): bool
    {
        return $this->ligne($workspaceId)?->tout_arreter === true;
    }

    public function modeTemoins(string $workspaceId): bool
    {
        // Fermé par défaut : sans ligne, le mode témoins est ALLUMÉ.
        return ($this->ligne($workspaceId)?->mode_temoins ?? true) === true;
    }

    public function estTemoin(string $workspaceId, string $adresse): bool
    {
        return WorkspaceContext::run($workspaceId, fn (): bool => DB::table('envoi_temoins')
            ->where('workspace_id', $workspaceId)
            ->whereRaw('lower(adresse) = ?', [mb_strtolower(trim($adresse))])
            ->exists());
    }

    public function injectesLe(string $workspaceId, DateTimeImmutable $jour): int
    {
        return WorkspaceContext::run($workspaceId, fn (): int => (int) DB::table('envoi_journal_commandes')
            ->where('workspace_id', $workspaceId)
            ->where('commande', 'injection')
            ->where('jour', $jour->format('Y-m-d'))
            ->count());
    }

    public function noterInjection(string $workspaceId, DateTimeImmutable $jour): void
    {
        $this->journaliser($workspaceId, 'injection', 'passerelle', [], $jour);
    }

    public function eteindreTemoins(string $workspaceId, string $auteur): void
    {
        WorkspaceContext::run($workspaceId, function () use ($workspaceId): void {
            DB::statement(
                'INSERT INTO envoi_drapeaux (workspace_id, mode_temoins) VALUES (?, false)
                 ON CONFLICT (workspace_id) DO UPDATE SET mode_temoins = false, mis_a_jour_le = now()',
                [$workspaceId],
            );
        });
        $this->journaliser($workspaceId, 'temoins_off', $auteur);
    }

    public function journaliser(string $workspaceId, string $commande, string $auteur, array $details = [], ?DateTimeImmutable $jour = null): void
    {
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $commande, $auteur, $details, $jour): void {
            DB::table('envoi_journal_commandes')->insert([
                'workspace_id' => $workspaceId,
                'commande' => $commande,
                'auteur' => $auteur,
                'details' => json_encode($details, JSON_THROW_ON_ERROR),
                'jour' => ($jour ?? new DateTimeImmutable)->format('Y-m-d'),
            ]);
        });
    }

    private function ligne(string $workspaceId): ?object
    {
        return WorkspaceContext::run($workspaceId, fn (): ?object => DB::table('envoi_drapeaux')
            ->where('workspace_id', $workspaceId)->first());
    }
}

<?php

namespace App\Envoi\Depot;

use App\Envoi\EmpreinteTemoin;
use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Dépôt Postgres (RLS par workspace). Aucune suppression de ligne, nulle part. */
final class DepotSocleDb implements DepotSocle
{
    public function toutArreter(string $workspaceId): bool
    {
        return ($this->ligne($workspaceId)['tout_arreter'] ?? false) === true;
    }

    public function modeTemoins(string $workspaceId): bool
    {
        // Fermé par défaut : sans ligne, le mode témoins est ALLUMÉ.
        return ($this->ligne($workspaceId)['mode_temoins'] ?? true) === true;
    }

    public function estTemoin(string $workspaceId, string $adresse): bool
    {
        $empreinte = EmpreinteTemoin::pourDecision($adresse);
        if ($empreinte === null) {
            return false; // adresse non ASCII : jamais témoin (fermé)
        }

        return WorkspaceContext::run($workspaceId, fn (): bool => DB::table('envoi_temoins')
            ->where('workspace_id', $workspaceId)
            ->where('empreinte', $empreinte)
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

    public function injectesLeGlobal(DateTimeImmutable $jour): int
    {
        return (int) (DB::table('envoi_compteur_global')->where('jour', $jour->format('Y-m-d'))->value('n') ?? 0);
    }

    public function dejaInjecte(string $workspaceId, string $cleIdempotence): bool
    {
        return WorkspaceContext::run($workspaceId, fn (): bool => DB::table('envoi_journal_commandes')
            ->where('workspace_id', $workspaceId)
            ->where('commande', 'injection')
            ->where('cle_idempotence', $cleIdempotence)
            ->exists());
    }

    public function sousVerrouInjection(DateTimeImmutable $jour, Closure $f): mixed
    {
        return DB::transaction(function () use ($jour, $f) {
            // Verrou consultatif de transaction, par jour : relâché au COMMIT/ROLLBACK.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi:injection:' . $jour->format('Y-m-d')]);

            return $f();
        });
    }

    public function noterInjection(string $workspaceId, DateTimeImmutable $jour, string $cleIdempotence): void
    {
        $insere = WorkspaceContext::run($workspaceId, fn (): int => DB::affectingStatement(
            "INSERT INTO envoi_journal_commandes (workspace_id, commande, auteur, details, jour, cle_idempotence)
             VALUES (?, 'injection', 'passerelle', '{}', ?, ?)
             ON CONFLICT (workspace_id, cle_idempotence) WHERE commande = 'injection' AND cle_idempotence IS NOT NULL DO NOTHING",
            [$workspaceId, $jour->format('Y-m-d'), $cleIdempotence],
        ));
        if ($insere > 0) {
            // Seule voie d'écriture du compteur : le rôle applicatif n'a ni INSERT ni UPDATE direct.
            DB::select('SELECT envoi_compteur_global_incrementer(?::date)', [$jour->format('Y-m-d')]);
        }
    }

    public function eteindreTemoins(string $workspaceId, string $auteur): void
    {
        // UNE transaction, journal d'abord : si le journal échoue, le mode reste allumé.
        DB::transaction(function () use ($workspaceId, $auteur): void {
            $this->journaliser($workspaceId, 'temoins_off', $auteur);
            $this->ecrireDrapeaux($workspaceId, true, fn () => DB::statement(
                'INSERT INTO envoi_drapeaux (workspace_id, mode_temoins) VALUES (?, false)
                 ON CONFLICT (workspace_id) DO UPDATE SET mode_temoins = false, mis_a_jour_le = now()',
                [$workspaceId],
            ));
        });
    }

    public function armerToutArreter(string $workspaceId, string $auteur): void
    {
        DB::transaction(function () use ($workspaceId, $auteur): void {
            $this->journaliser($workspaceId, 'tout_arreter', $auteur);
            $this->ecrireDrapeaux($workspaceId, false, fn () => DB::statement(
                'INSERT INTO envoi_drapeaux (workspace_id, tout_arreter) VALUES (?, true)
                 ON CONFLICT (workspace_id) DO UPDATE SET tout_arreter = true, mis_a_jour_le = now()',
                [$workspaceId],
            ));
        });
    }

    public function leverToutArreter(string $workspaceId, string $auteur): bool
    {
        return DB::transaction(function () use ($workspaceId, $auteur): bool {
            if (! $this->toutArreter($workspaceId)) {
                return false;
            }
            $this->journaliser($workspaceId, 'reprise', $auteur);
            $this->ecrireDrapeaux($workspaceId, true, fn () => DB::table('envoi_drapeaux')->where('workspace_id', $workspaceId)->update(['tout_arreter' => false, 'mis_a_jour_le' => now()]));

            return true;
        });
    }

    /**
     * Écrit `envoi_drapeaux` dans un SAVEPOINT. Si l'écriture échoue, le retour au savepoint garde la
     * transaction saine : sans lui, le nettoyage du contexte lèverait 25P02 et masquerait l'erreur
     * réelle. L'autorisation locale (lue par le trigger, qui exige en plus la ligne de journal) est
     * posée dans le savepoint et retirée dès l'écriture faite ; en cas d'échec le retour au savepoint
     * la retire aussi (pas de try/finally : sur transaction avortée, il lèverait 25P02).
     */
    private function ecrireDrapeaux(string $workspaceId, bool $gardee, Closure $ecriture): void
    {
        WorkspaceContext::run($workspaceId, fn () => DB::transaction(function () use ($gardee, $ecriture): void {
            if ($gardee) {
                DB::select("SELECT set_config('app.envoi_ecriture_gardee', 'oui', true)");
            }
            $ecriture();
            if ($gardee) {
                DB::select("SELECT set_config('app.envoi_ecriture_gardee', '', true)");
            }
        }));
    }

    public function journaliser(string $workspaceId, string $commande, string $auteur, array $details = [], ?DateTimeImmutable $jour = null): void
    {
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $commande, $auteur, $details, $jour): void {
            // SAVEPOINT propre à l'insertion : si elle échoue, la transaction englobante reste
            // saine (sinon 25P02 masquerait la vraie exception lors du nettoyage du contexte).
            DB::transaction(fn () => DB::table('envoi_journal_commandes')->insert([
                'workspace_id' => $workspaceId,
                'commande' => $commande,
                'auteur' => $auteur,
                'details' => json_encode($details, JSON_THROW_ON_ERROR),
                'jour' => ($jour ?? JourEnvoi::de())->format('Y-m-d'),
            ]));
        });
    }

    /** @return array<string, mixed>|null */
    private function ligne(string $workspaceId): ?array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId): ?array {
            $ligne = DB::table('envoi_drapeaux')->where('workspace_id', $workspaceId)->first();

            return $ligne === null ? null : (array) $ligne;
        });
    }
}

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
        $empreinte = EmpreinteTemoin::calculer($adresse);

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
            DB::statement(
                'INSERT INTO envoi_compteur_global (jour, n) VALUES (?, 1)
                 ON CONFLICT (jour) DO UPDATE SET n = envoi_compteur_global.n + 1',
                [$jour->format('Y-m-d')],
            );
        }
    }

    public function eteindreTemoins(string $workspaceId, string $auteur): void
    {
        // UNE transaction, journal d'abord : si le journal échoue, le mode reste allumé.
        DB::transaction(function () use ($workspaceId, $auteur): void {
            $this->journaliser($workspaceId, 'temoins_off', $auteur);
            WorkspaceContext::run($workspaceId, function () use ($workspaceId): void {
                // Autorisation locale à la transaction, lue par le trigger envoi_drapeaux_garde.
                DB::select("SELECT set_config('app.envoi_ecriture_gardee', 'oui', true)");
                DB::statement(
                    'INSERT INTO envoi_drapeaux (workspace_id, mode_temoins) VALUES (?, false)
                     ON CONFLICT (workspace_id) DO UPDATE SET mode_temoins = false, mis_a_jour_le = now()',
                    [$workspaceId],
                );
                // Autorisation retirée dès l'écriture faite : si cette transaction est elle-même
                // imbriquée (SAVEPOINT, tests), le réglage local survivrait sinon au « commit ».
                DB::select("SELECT set_config('app.envoi_ecriture_gardee', '', true)");
            });
        });
    }

    public function armerToutArreter(string $workspaceId, string $auteur): void
    {
        DB::transaction(function () use ($workspaceId, $auteur): void {
            $this->journaliser($workspaceId, 'tout_arreter', $auteur);
            WorkspaceContext::run($workspaceId, function () use ($workspaceId): void {
                DB::statement(
                    'INSERT INTO envoi_drapeaux (workspace_id, tout_arreter) VALUES (?, true)
                     ON CONFLICT (workspace_id) DO UPDATE SET tout_arreter = true, mis_a_jour_le = now()',
                    [$workspaceId],
                );
            });
        });
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

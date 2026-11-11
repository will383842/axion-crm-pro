<?php

namespace App\Envoi\Depot;

use App\Envoi\EmpreinteTemoin;
use App\Envoi\Exceptions\TemoinRefuse;
use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Dépôt Postgres (RLS par workspace). Aucune suppression de ligne, nulle part. */
final class DepotSocleDb implements DepotSocle
{
    /** Les 6 fournisseurs témoins (A10) ; la base porte le même CHECK. */
    public const FOURNISSEURS_TEMOINS = ['gmail', 'google_workspace', 'outlook_com', 'microsoft_365', 'yahoo', 'orange'];

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
            ->whereNotExists(fn ($q) => $this->conclusion($q, 'liberee'))
            ->count());
    }

    public function injectesLeGlobal(DateTimeImmutable $jour): int
    {
        return (int) (DB::table('envoi_compteur_global')->where('jour', $jour->format('Y-m-d'))->value('n') ?? 0);
    }

    public function dejaInjecte(string $workspaceId, string $cleIdempotence): bool
    {
        return $this->etatInjection($workspaceId, $cleIdempotence) !== null;
    }

    /** Sous-requête : une conclusion de ce statut existe pour la réservation de la ligne `j` (table du dessus). */
    private function conclusion(Builder $q, ?string $statut): void
    {
        $q->selectRaw('1')->from('envoi_journal_commandes as c')->where('c.commande', 'injection_issue')
            ->whereColumn('c.workspace_id', 'envoi_journal_commandes.workspace_id')
            ->whereColumn('c.cle_idempotence', 'envoi_journal_commandes.cle_idempotence');
        if ($statut !== null) {
            $q->where('c.statut', $statut);
        }
    }

    /**
     * Clé de journal de la tentative EN COURS : le journal est « ajout seul », une réservation libérée ne se rouvre
     * pas ; une nouvelle tentative prend donc `<clé>~2`, `<clé>~3`… (le serveur, lui, reçoit toujours la clé d'origine).
     */
    private function cleJournal(string $workspaceId, string $cle): string
    {
        $liberees = (int) DB::table('envoi_journal_commandes')->where('workspace_id', $workspaceId)
            ->where('commande', 'injection_issue')->where('statut', 'liberee')
            ->whereRaw('(cle_idempotence = ? OR starts_with(cle_idempotence, ?))', [$cle, $cle . '~'])->count();

        return $liberees === 0 ? $cle : $cle . '~' . ($liberees + 1);
    }

    public function etatInjection(string $workspaceId, string $cleIdempotence): ?string
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $cleIdempotence): ?string {
            $cle = $this->cleJournal($workspaceId, $cleIdempotence);
            $reservation = DB::table('envoi_journal_commandes')->where('workspace_id', $workspaceId)
                ->where('commande', 'injection')->where('cle_idempotence', $cle)->first();
            if ($reservation === null) {
                return null;
            }
            $conclusion = DB::table('envoi_journal_commandes')->where('workspace_id', $workspaceId)
                ->where('commande', 'injection_issue')->where('cle_idempotence', $cle)->value('statut');

            return (string) ($conclusion ?? $reservation->statut);
        });
    }

    public function reserverInjection(string $workspaceId, DateTimeImmutable $jour, string $cleIdempotence): bool
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $jour, $cleIdempotence): bool {
            $insere = DB::affectingStatement(
                "INSERT INTO envoi_journal_commandes (workspace_id, commande, auteur, details, jour, cle_idempotence, statut)
                 VALUES (?, 'injection', 'passerelle', '{}', ?, ?, 'reservee')
                 ON CONFLICT (workspace_id, cle_idempotence) WHERE commande = 'injection' AND cle_idempotence IS NOT NULL DO NOTHING",
                [$workspaceId, $jour->format('Y-m-d'), $this->cleJournal($workspaceId, $cleIdempotence)],
            );
            if ($insere > 0) {
                DB::select('SELECT envoi_compteur_global_incrementer(?::date)', [$jour->format('Y-m-d')]);
            }

            return $insere > 0;
        });
    }

    public function concluireInjection(string $workspaceId, DateTimeImmutable $jour, string $cleIdempotence, string $statut): bool
    {
        if (! in_array($statut, ['acceptee', 'liberee', 'a_verifier'], true)) {
            throw new InvalidArgumentException('Conclusion d\'injection inconnue.');
        }

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $jour, $cleIdempotence, $statut): bool {
            $cle = $this->cleJournal($workspaceId, $cleIdempotence);
            // Une conclusion NE se pose que sur une réservation existante ; l'index unique en fait une seule.
            $insere = DB::affectingStatement(
                "INSERT INTO envoi_journal_commandes (workspace_id, commande, auteur, details, jour, cle_idempotence, statut)
                 SELECT ?, 'injection_issue', 'passerelle', '{}', ?, ?, ?
                 WHERE EXISTS (SELECT 1 FROM envoi_journal_commandes WHERE workspace_id = ? AND commande = 'injection' AND cle_idempotence = ?)
                 ON CONFLICT (workspace_id, cle_idempotence) WHERE commande = 'injection_issue' AND cle_idempotence IS NOT NULL DO NOTHING",
                [$workspaceId, $jour->format('Y-m-d'), $cle, $statut, $workspaceId, $cle],
            );
            if ($insere > 0 && $statut === 'liberee') {
                DB::select('SELECT envoi_compteur_global_liberer(?::date)', [$jour->format('Y-m-d')]);
            }

            return $insere > 0;
        });
    }

    public function perimerReservations(string $workspaceId, DateTimeImmutable $avant): array
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $avant): array {
            $lignes = DB::table('envoi_journal_commandes')->where('workspace_id', $workspaceId)->where('commande', 'injection')
                ->where('statut', 'reservee')->where('cree_le', '<', $avant->format('c'))
                ->whereNotExists(fn ($q) => $this->conclusion($q, null))->get(['cle_idempotence', 'jour']);
            $cles = [];
            foreach ($lignes as $l) {
                DB::affectingStatement(
                    "INSERT INTO envoi_journal_commandes (workspace_id, commande, auteur, details, jour, cle_idempotence, statut)
                     VALUES (?, 'injection_issue', 'passerelle', '{}', ?, ?, 'a_verifier')
                     ON CONFLICT (workspace_id, cle_idempotence) WHERE commande = 'injection_issue' AND cle_idempotence IS NOT NULL DO NOTHING",
                    [$workspaceId, $l->jour, $l->cle_idempotence],
                );
                $cles[] = (string) $l->cle_idempotence;
            }

            return $cles;
        });
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

    public function ajouterTemoin(string $workspaceId, string $adresse, string $fournisseur, string $libelle, string $auteur): bool
    {
        $empreinte = EmpreinteTemoin::pourDecision($adresse);
        if ($empreinte === null || ! in_array($fournisseur, self::FOURNISSEURS_TEMOINS, true)) {
            throw new InvalidArgumentException('Témoin refusé : adresse non ASCII ou fournisseur inconnu.');
        }

        // UNE transaction : le témoin et sa trace dans le journal vont ensemble ou pas du tout.
        return DB::transaction(function () use ($workspaceId, $empreinte, $fournisseur, $libelle, $auteur): bool {
            // Verrou par workspace : deux ajouts simultanés ne dépassent ni le plafond ni « un par fournisseur ».
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['envoi_temoins:' . $workspaceId]);
            WorkspaceContext::run($workspaceId, function () use ($workspaceId, $empreinte, $fournisseur): void {
                $existe = DB::table('envoi_temoins')->where('workspace_id', $workspaceId)->where('empreinte', $empreinte)->exists();
                if ($existe) {
                    return; // doublon : idempotent, jamais refusé
                }
                if (DB::table('envoi_temoins')->where('workspace_id', $workspaceId)->count() >= TemoinRefuse::PLAFOND) {
                    throw TemoinRefuse::plafondAtteint();
                }
                if (DB::table('envoi_temoins')->where('workspace_id', $workspaceId)->where('fournisseur', $fournisseur)->exists()) {
                    throw TemoinRefuse::fournisseurDejaCouvert($fournisseur);
                }
            });
            $nouveau = WorkspaceContext::run($workspaceId, fn (): bool => DB::affectingStatement(
                'INSERT INTO envoi_temoins (workspace_id, empreinte, libelle, fournisseur) VALUES (?, ?, ?, ?)
                 ON CONFLICT (workspace_id, empreinte) DO NOTHING',
                [$workspaceId, $empreinte, $libelle, $fournisseur],
            ) > 0);
            $this->journaliser($workspaceId, 'temoin_ajoute', $auteur, [
                'empreinte' => substr($empreinte, 0, 12),
                'fournisseur' => $fournisseur,
                'nouveau' => $nouveau,
            ]);

            return $nouveau;
        });
    }

    public function listerTemoins(string $workspaceId): array
    {
        return array_values(WorkspaceContext::run($workspaceId, fn (): array => DB::table('envoi_temoins')
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->get(['empreinte', 'libelle', 'fournisseur', 'cree_le'])
            ->map(fn ($l): array => [
                'empreinte' => (string) $l->empreinte,
                'libelle' => (string) $l->libelle,
                'fournisseur' => $l->fournisseur === null ? null : (string) $l->fournisseur,
                'cree_le' => (string) $l->cree_le,
            ])->all()));
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

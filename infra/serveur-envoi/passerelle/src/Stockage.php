<?php

declare(strict_types=1);

namespace Passerelle;

use PDO;

/**
 * SQLite local. Journal des commandes en AJOUT SEUL (déclencheurs), nonces persistants,
 * événements numérotés par espace sans trou (numéro réservé dans la même transaction).
 */
final class Stockage
{
    public const RETENTION_EVENEMENTS = 30 * 86400;

    public const RETENTION_NONCES = 600;

    private PDO $pdo;

    /** @param \Closure():int $horloge */
    public function __construct(string $fichier, private readonly \Closure $horloge)
    {
        $this->pdo = new PDO('sqlite:'.$fichier);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000;');
        $this->migrer();
    }

    private function migrer(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS nonces (
  workspace_id TEXT NOT NULL, nonce TEXT NOT NULL, vu_le INTEGER NOT NULL,
  PRIMARY KEY (workspace_id, nonce)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS journal_commandes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  workspace_id TEXT NOT NULL, commande_id TEXT NOT NULL, operation TEXT NOT NULL,
  empreinte_corps TEXT NOT NULL, statut INTEGER NOT NULL, reponse TEXT NOT NULL, cree_le INTEGER NOT NULL,
  UNIQUE (workspace_id, commande_id));
CREATE TRIGGER IF NOT EXISTS journal_no_update BEFORE UPDATE ON journal_commandes
  BEGIN SELECT RAISE(ABORT, 'journal en ajout seul'); END;
CREATE TRIGGER IF NOT EXISTS journal_no_delete BEFORE DELETE ON journal_commandes
  BEGIN SELECT RAISE(ABORT, 'journal en ajout seul'); END;
CREATE TABLE IF NOT EXISTS sequences (workspace_id TEXT PRIMARY KEY, dernier INTEGER NOT NULL) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS evenements (
  workspace_id TEXT NOT NULL, sequence INTEGER NOT NULL, type TEXT NOT NULL,
  donnees TEXT NOT NULL, cree_le INTEGER NOT NULL,
  PRIMARY KEY (workspace_id, sequence)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS purge_etat (ouverte INTEGER NOT NULL);
INSERT INTO purge_etat SELECT 0 WHERE NOT EXISTS (SELECT 1 FROM purge_etat);
CREATE TRIGGER IF NOT EXISTS evenements_no_update BEFORE UPDATE ON evenements
  BEGIN SELECT RAISE(ABORT, 'evenement immuable'); END;
CREATE TRIGGER IF NOT EXISTS evenements_no_delete_recent BEFORE DELETE ON evenements
  WHEN (SELECT ouverte FROM purge_etat) = 0
  BEGIN SELECT RAISE(ABORT, 'evenement garde 30 jours'); END;
SQL);
    }

    private function now(): int
    {
        return ($this->horloge)();
    }

    /** @param \Closure():mixed $f */
    private function transaction(\Closure $f): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $r = $f();
            $this->pdo->exec('COMMIT');

            return $r;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Vrai si le nonce est nouveau (et le retient). Faux = rejeu. */
    public function retenirNonce(string $ws, string $nonce): bool
    {
        return (bool) $this->transaction(function () use ($ws, $nonce): bool {
            $this->pdo->prepare('DELETE FROM nonces WHERE vu_le < ?')->execute([$this->now() - self::RETENTION_NONCES]);
            $st = $this->pdo->prepare('INSERT OR IGNORE INTO nonces (workspace_id, nonce, vu_le) VALUES (?,?,?)');
            $st->execute([$ws, $nonce, $this->now()]);

            return $st->rowCount() === 1;
        });
    }

    /** @return array{empreinte_corps:string,statut:int,reponse:string}|null */
    public function commande(string $ws, string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT empreinte_corps, statut, reponse FROM journal_commandes WHERE workspace_id=? AND commande_id=?');
        $st->execute([$ws, $id]);
        $r = $st->fetch();

        return $r === false ? null : ['empreinte_corps' => $r['empreinte_corps'], 'statut' => (int) $r['statut'], 'reponse' => $r['reponse']];
    }

    public function journaliser(string $ws, string $id, string $operation, string $empreinte, int $statut, string $reponse): void
    {
        $this->pdo->prepare('INSERT INTO journal_commandes (workspace_id, commande_id, operation, empreinte_corps, statut, reponse, cree_le) VALUES (?,?,?,?,?,?,?)')
            ->execute([$ws, $id, $operation, $empreinte, $statut, $reponse, $this->now()]);
    }

    public function nombreCommandes(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM journal_commandes')->fetchColumn();
    }

    /** @param array<string,mixed> $donnees */
    public function ajouterEvenement(string $ws, string $type, array $donnees, ?int $creeLe = null): int
    {
        return (int) $this->transaction(function () use ($ws, $type, $donnees, $creeLe): int {
            $this->pdo->prepare('INSERT OR IGNORE INTO sequences (workspace_id, dernier) VALUES (?,0)')->execute([$ws]);
            $this->pdo->prepare('UPDATE sequences SET dernier = dernier + 1 WHERE workspace_id=?')->execute([$ws]);
            $st = $this->pdo->prepare('SELECT dernier FROM sequences WHERE workspace_id=?');
            $st->execute([$ws]);
            $seq = (int) $st->fetchColumn();
            // L'encodage JSON peut échouer APRÈS la réservation : la transaction annule alors tout.
            $json = json_encode($donnees, JSON_THROW_ON_ERROR);
            $this->pdo->prepare('INSERT INTO evenements (workspace_id, sequence, type, donnees, cree_le) VALUES (?,?,?,?,?)')
                ->execute([$ws, $seq, $type, $json, $creeLe ?? $this->now()]);

            return $seq;
        });
    }

    public function dernierSequence(string $ws): int
    {
        $st = $this->pdo->prepare('SELECT dernier FROM sequences WHERE workspace_id=?');
        $st->execute([$ws]);

        return (int) ($st->fetchColumn() ?: 0);
    }

    /** @return list<array{sequence:int,type:string,donnees:mixed,cree_le:int}> */
    public function evenementsApres(string $ws, int $curseur, int $limite): array
    {
        $st = $this->pdo->prepare('SELECT sequence, type, donnees, cree_le FROM evenements WHERE workspace_id=? AND sequence>? ORDER BY sequence LIMIT ?');
        $st->execute([$ws, $curseur, $limite]);

        return array_map(fn (array $e): array => [
            'sequence' => (int) $e['sequence'], 'type' => $e['type'],
            'donnees' => json_decode($e['donnees'], true), 'cree_le' => (int) $e['cree_le'],
        ], $st->fetchAll());
    }

    /** Plus petit curseur utilisable : séquence juste avant le plus ancien événement encore rejouable (72 h). */
    public function curseurMinimal(string $ws, int $fenetre): int
    {
        $st = $this->pdo->prepare('SELECT MAX(sequence) FROM evenements WHERE workspace_id=? AND cree_le < ?');
        $st->execute([$ws, $this->now() - $fenetre]);

        return (int) ($st->fetchColumn() ?: 0);
    }

    /**
     * Trous de la séquence retenue, sous forme de plages [de, à] (le début purgé n'est pas un trou).
     *
     * @return list<array{0:int,1:int}>
     */
    public function trous(string $ws): array
    {
        $st = $this->pdo->prepare('SELECT sequence FROM evenements WHERE workspace_id=? ORDER BY sequence');
        $st->execute([$ws]);
        $trous = [];
        $prec = null;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) {
            $s = (int) $s;
            if ($prec !== null && $s !== $prec + 1) {
                $trous[] = [$prec + 1, $s - 1];
            }
            $prec = $s;
        }
        if ($prec !== null && $prec !== $this->dernierSequence($ws)) {
            $trous[] = [$prec + 1, $this->dernierSequence($ws)];
        }

        return $trous;
    }

    /** Supprime les événements de plus de 30 jours (le déclencheur refuse toute autre suppression). */
    public function purger(): int
    {
        return (int) $this->transaction(function (): int {
            $this->pdo->exec('UPDATE purge_etat SET ouverte = 1');
            $st = $this->pdo->prepare('DELETE FROM evenements WHERE cree_le < ?');
            $st->execute([$this->now() - self::RETENTION_EVENEMENTS]);
            $this->pdo->exec('UPDATE purge_etat SET ouverte = 0');

            return $st->rowCount();
        });
    }
}

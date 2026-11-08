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

    /** Une réservation « en cours » plus vieille que cela est considérée abandonnée (processus disparu). */
    public const DELAI_RESERVATION = 60;

    public const TYPE_EVENEMENT = '/^[a-z][a-z0-9_]{0,63}$/';

    private PDO $pdo;

    private bool $purgeActive;

    /**
     * @param \Closure():int $horloge
     * @param bool|null      $purgeActive null = lu de PASSERELLE_PURGE_ACTIVE (faux par défaut : la purge ne fait rien)
     */
    public function __construct(string $fichier, private readonly \Closure $horloge, ?bool $purgeActive = null)
    {
        $this->purgeActive = $purgeActive ?? Configuration::purgeActive();
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
CREATE TABLE IF NOT EXISTS commandes_etat (
  workspace_id TEXT NOT NULL, commande_id TEXT NOT NULL, operation TEXT NOT NULL, empreinte_corps TEXT NOT NULL,
  etat TEXT NOT NULL CHECK (etat IN ('en_cours','echec','ok')), tentatives INTEGER NOT NULL, maj_le INTEGER NOT NULL,
  PRIMARY KEY (workspace_id, commande_id)) WITHOUT ROWID;
CREATE TRIGGER IF NOT EXISTS commandes_etat_no_delete BEFORE DELETE ON commandes_etat
  BEGIN SELECT RAISE(ABORT, 'etat de commande en ajout seul'); END;
CREATE TRIGGER IF NOT EXISTS commandes_etat_ok_final BEFORE UPDATE ON commandes_etat
  WHEN OLD.etat = 'ok' OR NEW.empreinte_corps <> OLD.empreinte_corps OR NEW.operation <> OLD.operation
  BEGIN SELECT RAISE(ABORT, 'commande terminee ou corps different'); END;
CREATE TABLE IF NOT EXISTS sequences (workspace_id TEXT PRIMARY KEY, dernier INTEGER NOT NULL) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS evenements (
  workspace_id TEXT NOT NULL, sequence INTEGER NOT NULL, type TEXT NOT NULL,
  donnees TEXT NOT NULL, cree_le INTEGER NOT NULL,
  PRIMARY KEY (workspace_id, sequence)) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS purge_borne (workspace_id TEXT PRIMARY KEY, dernier_purge INTEGER NOT NULL) WITHOUT ROWID;
CREATE TRIGGER IF NOT EXISTS purge_borne_no_delete BEFORE DELETE ON purge_borne
  BEGIN SELECT RAISE(ABORT, 'borne de purge definitive'); END;
CREATE TRIGGER IF NOT EXISTS purge_borne_no_recul BEFORE UPDATE ON purge_borne
  WHEN NEW.dernier_purge < OLD.dernier_purge
  BEGIN SELECT RAISE(ABORT, 'la borne de purge ne recule jamais'); END;
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

    public function etatCommande(string $ws, string $id): ?string
    {
        $st = $this->pdo->prepare('SELECT etat FROM commandes_etat WHERE workspace_id=? AND commande_id=?');
        $st->execute([$ws, $id]);
        $e = $st->fetchColumn();

        return $e === false ? null : (string) $e;
    }

    /**
     * Réserve le commande_id AVANT tout appel à KumoMTA (INSERT « en_cours » sous clé primaire, en transaction immédiate).
     * Une seule requête obtient « executer » ; les autres « attendre » (ou « rejouer » si déjà terminée, « conflit » si
     * le corps diffère). Un échec antérieur (« echec ») ou une réservation abandonnée se reprennent : le commande_id
     * n'est jamais perdu.
     *
     * @return array{action:'executer'|'attendre'|'conflit'|'rejouer',statut?:int,reponse?:string}
     */
    public function reserverCommande(string $ws, string $id, string $operation, string $empreinte): array
    {
        /** @var array{action:'executer'|'attendre'|'conflit'|'rejouer',statut?:int,reponse?:string} */
        return $this->transaction(function () use ($ws, $id, $operation, $empreinte): array {
            $st = $this->pdo->prepare('SELECT empreinte_corps, etat, maj_le FROM commandes_etat WHERE workspace_id=? AND commande_id=?');
            $st->execute([$ws, $id]);
            $r = $st->fetch();
            if ($r === false) {
                $this->pdo->prepare('INSERT INTO commandes_etat (workspace_id, commande_id, operation, empreinte_corps, etat, tentatives, maj_le) VALUES (?,?,?,?,?,1,?)')
                    ->execute([$ws, $id, $operation, $empreinte, 'en_cours', $this->now()]);

                return ['action' => 'executer'];
            }
            if (! hash_equals((string) $r['empreinte_corps'], $empreinte)) {
                return ['action' => 'conflit'];
            }
            if ($r['etat'] === 'ok') {
                $j = $this->pdo->prepare('SELECT statut, reponse FROM journal_commandes WHERE workspace_id=? AND commande_id=?');
                $j->execute([$ws, $id]);
                $f = $j->fetch();

                return ['action' => 'rejouer', 'statut' => (int) $f['statut'], 'reponse' => (string) $f['reponse']];
            }
            if ($r['etat'] === 'en_cours' && $this->now() - (int) $r['maj_le'] <= self::DELAI_RESERVATION) {
                return ['action' => 'attendre'];
            }
            // « echec » (réessai demandé par K1 avec le même identifiant) ou réservation abandonnée : on reprend.
            $this->pdo->prepare("UPDATE commandes_etat SET etat='en_cours', tentatives = tentatives + 1, maj_le=? WHERE workspace_id=? AND commande_id=?")
                ->execute([$this->now(), $ws, $id]);

            return ['action' => 'executer'];
        });
    }

    /** Succès : réponse définitive au journal (ajout seul) et état « ok », dans une même transaction. */
    public function terminerCommande(string $ws, string $id, string $operation, int $statut, string $reponse): void
    {
        $this->transaction(function () use ($ws, $id, $operation, $statut, $reponse): null {
            $st = $this->pdo->prepare('SELECT empreinte_corps FROM commandes_etat WHERE workspace_id=? AND commande_id=?');
            $st->execute([$ws, $id]);
            $empreinte = (string) $st->fetchColumn();
            $this->pdo->prepare('INSERT OR IGNORE INTO journal_commandes (workspace_id, commande_id, operation, empreinte_corps, statut, reponse, cree_le) VALUES (?,?,?,?,?,?,?)')
                ->execute([$ws, $id, $operation, $empreinte, $statut, $reponse, $this->now()]);
            $this->pdo->prepare("UPDATE commandes_etat SET etat='ok', maj_le=? WHERE workspace_id=? AND commande_id=? AND etat <> 'ok'")
                ->execute([$this->now(), $ws, $id]);

            return null;
        });
    }

    /** Échec (KumoMTA refuse ou panne) : le commande_id reste utilisable, le client peut rejouer. */
    public function echouerCommande(string $ws, string $id): void
    {
        $this->pdo->prepare("UPDATE commandes_etat SET etat='echec', maj_le=? WHERE workspace_id=? AND commande_id=? AND etat='en_cours'")
            ->execute([$this->now(), $ws, $id]);
    }

    public function nombreCommandes(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM journal_commandes')->fetchColumn();
    }

    /**
     * Ajoute un événement ; sa date est TOUJOURS celle de l'horloge (jamais fournie par l'appelant : la purge se fie
     * à l'ordre). Le type doit être lisible par K1 ; `id_injection`, s'il est donné, est une chaîne de 128 octets au plus.
     *
     * @param array<string,mixed> $donnees
     */
    public function ajouterEvenement(string $ws, string $type, array $donnees): int
    {
        if (preg_match(self::TYPE_EVENEMENT, $type) !== 1) {
            throw new \InvalidArgumentException('type d\'événement invalide');
        }
        if (isset($donnees['id_injection']) && (! is_string($donnees['id_injection']) || strlen($donnees['id_injection']) > 128)) {
            throw new \InvalidArgumentException('id_injection invalide');
        }

        return (int) $this->transaction(function () use ($ws, $type, $donnees): int {
            $this->pdo->prepare('INSERT OR IGNORE INTO sequences (workspace_id, dernier) VALUES (?,0)')->execute([$ws]);
            $this->pdo->prepare('UPDATE sequences SET dernier = dernier + 1 WHERE workspace_id=?')->execute([$ws]);
            $st = $this->pdo->prepare('SELECT dernier FROM sequences WHERE workspace_id=?');
            $st->execute([$ws]);
            $seq = (int) $st->fetchColumn();
            // L'encodage JSON peut échouer APRÈS la réservation : la transaction annule alors tout.
            $json = json_encode($donnees, JSON_THROW_ON_ERROR);
            $this->pdo->prepare('INSERT INTO evenements (workspace_id, sequence, type, donnees, cree_le) VALUES (?,?,?,?,?)')
                ->execute([$ws, $seq, $type, $json, $this->now()]);

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

    /** Dernier numéro purgé de l'espace (0 = rien purgé). Un curseur plus petit a perdu des événements. */
    public function dernierPurge(string $ws): int
    {
        $st = $this->pdo->prepare('SELECT dernier_purge FROM purge_borne WHERE workspace_id=?');
        $st->execute([$ws]);

        return (int) ($st->fetchColumn() ?: 0);
    }

    /**
     * Plus petit curseur utilisable : le plus grand de (dernier numéro PURGÉ, séquence juste avant le plus ancien
     * événement encore rejouable, 72 h). Un curseur inférieur doit recevoir 410, jamais un 200 qui saute des numéros.
     */
    public function curseurMinimal(string $ws, int $fenetre): int
    {
        $st = $this->pdo->prepare('SELECT MAX(sequence) FROM evenements WHERE workspace_id=? AND cree_le < ?');
        $st->execute([$ws, $this->now() - $fenetre]);

        return max($this->dernierPurge($ws), (int) ($st->fetchColumn() ?: 0));
    }

    /** Curseur « depuis une date » : numéro juste avant le premier événement daté de $ts ou après. */
    public function curseurDepuis(string $ws, int $ts): int
    {
        $st = $this->pdo->prepare('SELECT MIN(sequence) FROM evenements WHERE workspace_id=? AND cree_le >= ?');
        $st->execute([$ws, $ts]);
        $premier = $st->fetchColumn();

        return $premier === null || $premier === false ? $this->dernierSequence($ws) : (int) $premier - 1;
    }

    /**
     * Trous de la séquence, sous forme de plages [de, à]. Le préfixe purgé (jusqu'à la borne de purge) n'est pas un
     * trou ; tout le reste doit être contigu jusqu'au dernier numéro réservé.
     *
     * @return list<array{0:int,1:int}>
     */
    public function trous(string $ws): array
    {
        $st = $this->pdo->prepare('SELECT sequence FROM evenements WHERE workspace_id=? ORDER BY sequence');
        $st->execute([$ws]);
        $trous = [];
        $prec = $this->dernierPurge($ws);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) {
            $s = (int) $s;
            if ($s !== $prec + 1) {
                $trous[] = [$prec + 1, $s - 1];
            }
            $prec = $s;
        }
        if ($prec !== $this->dernierSequence($ws)) {
            $trous[] = [$prec + 1, $this->dernierSequence($ws)];
        }

        return $trous;
    }

    /**
     * Supprime les événements de plus de 30 jours, PAR NUMÉRO : seulement le préfixe contigu le plus ancien, jamais de
     * trou au milieu, et mémorise le dernier numéro purgé. INACTIF tant que PASSERELLE_PURGE_ACTIVE n'est pas « true »
     * (règle de rétention à valider avant activation) : ne fait alors rien et rend 0.
     */
    public function purger(): int
    {
        if (! $this->purgeActive) {
            return 0;
        }

        return (int) $this->transaction(function (): int {
            $limite = $this->now() - self::RETENTION_EVENEMENTS;
            $this->pdo->exec('UPDATE purge_etat SET ouverte = 1');
            $total = 0;
            $espaces = $this->pdo->query('SELECT DISTINCT workspace_id FROM evenements')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($espaces as $ws) {
                $st = $this->pdo->prepare('SELECT MIN(sequence) FROM evenements WHERE workspace_id=? AND cree_le >= ?');
                $st->execute([$ws, $limite]);
                $premierRecent = $st->fetchColumn();
                $st = $premierRecent === null || $premierRecent === false
                    ? $this->pdo->prepare('SELECT MAX(sequence) FROM evenements WHERE workspace_id=?')
                    : $this->pdo->prepare('SELECT MAX(sequence) FROM evenements WHERE workspace_id=? AND sequence < ?');
                $st->execute($premierRecent === null || $premierRecent === false ? [$ws] : [$ws, (int) $premierRecent]);
                $dernierASupprimer = $st->fetchColumn();
                if ($dernierASupprimer === null || $dernierASupprimer === false) {
                    continue;
                }
                $del = $this->pdo->prepare('DELETE FROM evenements WHERE workspace_id=? AND sequence <= ?');
                $del->execute([$ws, (int) $dernierASupprimer]);
                $total += $del->rowCount();
                $this->pdo->prepare('INSERT INTO purge_borne (workspace_id, dernier_purge) VALUES (?,?) ON CONFLICT (workspace_id) DO UPDATE SET dernier_purge = excluded.dernier_purge')
                    ->execute([$ws, (int) $dernierASupprimer]);
            }
            $this->pdo->exec('UPDATE purge_etat SET ouverte = 0');

            return $total;
        });
    }
}

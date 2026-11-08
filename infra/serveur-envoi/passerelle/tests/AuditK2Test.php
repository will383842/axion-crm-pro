<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\FauxKumo;
use Passerelle\GardeTls;
use Passerelle\Kumo;
use Passerelle\KumoInactif;
use Passerelle\Passerelle;
use Passerelle\Requete;
use Passerelle\Stockage;
use PDO;
use PDOStatement;

/** Compte les lignes réellement rapatriées en PHP (preuve que trous() ne lit plus tous les numéros). */
final class EnqueteCompteur extends PDOStatement
{
    public static int $lignes = 0;

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $r = parent::fetchAll($mode, ...$args);
        self::$lignes += count($r);

        return $r;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed
    {
        $r = parent::fetch($mode, $orientation, $offset);
        if ($r !== false) {
            self::$lignes++;
        }

        return $r;
    }
}

final class AuditK2Test extends PasserelleTestCase
{
    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->fichier);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    // --- (3) TLS : seul « false » explicite autorise l'absence de certificat client ---

    /** @dataProvider valeursActives */
    #[\PHPUnit\Framework\Attributes\DataProvider('valeursActives')]
    public function test_garde_tls_variable_absente_vide_ou_douteuse_exige_le_certificat(?string $valeur): void
    {
        $this->assertFalse(GardeTls::autorise([], $valeur));
        $this->assertFalse(GardeTls::autorise(['SSL_CLIENT_VERIFY' => 'NONE'], $valeur));
        $this->assertTrue(GardeTls::autorise(['SSL_CLIENT_VERIFY' => 'SUCCESS'], $valeur));
    }

    /** @return array<string,array{0:?string}> */
    public static function valeursActives(): array
    {
        return ['absente' => [null], 'vide' => [''], 'espaces' => ['  '], 'zero' => ['0'], 'non' => ['no'], 'true' => ['true'], 'bizarre' => ['faux']];
    }

    public function test_garde_tls_seul_false_explicite_laisse_passer_sans_certificat(): void
    {
        $this->assertTrue(GardeTls::autorise([], 'false'));
        $this->assertTrue(GardeTls::autorise([], ' FALSE '));
    }

    public function test_index_php_variable_absente_refuse_401_sans_certificat(): void
    {
        $cmd = 'env -u ENVOI_REEL_ACTIF PASSERELLE_BASE=/nonexistent/x.sqlite '.escapeshellarg(PHP_BINARY).' -d display_errors=0 '.escapeshellarg(__DIR__.'/../public/index.php').' 2>&1';
        exec($cmd, $sortie);
        $this->assertSame('{"erreur":"non_autorise"}', trim(implode("\n", $sortie)));
    }

    // --- (2) trous() : contrôle SQL, pas de lecture des numéros en mémoire PHP ---

    private function remplir(int $n): void
    {
        $pdo = $this->pdo();
        $pdo->exec('BEGIN');
        $st = $pdo->prepare('INSERT INTO evenements (workspace_id, sequence, type, donnees, cree_le) VALUES (?,?,?,?,?)');
        for ($i = 1; $i <= $n; $i++) {
            $st->execute([self::WS_A, $i, 'delivery', '{}', $this->maintenant]);
        }
        $pdo->exec("INSERT OR REPLACE INTO sequences (workspace_id, dernier) VALUES ('".self::WS_A."', $n)");
        $pdo->exec('COMMIT');
    }

    private function compter(\Closure $f): int
    {
        $r = new \ReflectionProperty(Stockage::class, 'pdo');
        $pdo = $r->getValue($this->stockage);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [EnqueteCompteur::class]);
        EnqueteCompteur::$lignes = 0;
        $f();

        return EnqueteCompteur::$lignes;
    }

    public function test_trous_10000_evenements_sans_rapatrier_les_numeros_en_php(): void
    {
        $this->remplir(10_000);
        $t = microtime(true);
        $lignes = $this->compter(function (): void {
            $this->assertSame([], $this->stockage->trous(self::WS_A));
        });
        $this->assertLessThan(50, $lignes, 'le contrôle est un COUNT/MIN en SQL, pas 10 000 lignes lues en PHP');
        $this->assertLessThan(1.0, microtime(true) - $t);
    }

    public function test_trou_reel_detecte_au_milieu_et_en_queue_avec_10000_evenements(): void
    {
        $this->remplir(10_000);
        $pdo = $this->pdo();
        $pdo->exec('DROP TRIGGER IF EXISTS evenements_no_delete_recent'); // sabotage de test
        $pdo->exec('DELETE FROM evenements WHERE sequence IN (5000, 5001, 10000)');
        $this->assertSame([[5000, 5001], [10000, 10000]], $this->stockage->trous(self::WS_A));
        $pdo->exec('DELETE FROM evenements WHERE sequence = 1'); // trou en tête (rien n'est purgé)
        $this->assertSame([[1, 1], [5000, 5001], [10000, 10000]], $this->stockage->trous(self::WS_A));
    }

    public function test_trous_apres_purge_le_prefixe_purge_n_est_pas_un_trou(): void
    {
        $this->purgeActive = true;
        $this->demarrer();
        $this->maintenant -= 40 * 86400;
        for ($i = 0; $i < 3; $i++) {
            $this->stockage->ajouterEvenement(self::WS_A, 'delivery', []);
        }
        $this->maintenant += 40 * 86400;
        $this->stockage->ajouterEvenement(self::WS_A, 'delivery', []);
        $this->assertSame(3, $this->stockage->purger());
        $this->assertSame([], $this->stockage->trous(self::WS_A));
    }

    public function test_curseur_minimal_utilise_un_index_sur_cree_le(): void
    {
        $plan = $this->pdo()->query('EXPLAIN QUERY PLAN SELECT MAX(sequence) FROM evenements WHERE workspace_id=\''.self::WS_A.'\' AND cree_le < 5')->fetchAll(PDO::FETCH_COLUMN, 3);
        $this->assertStringContainsString('evenements_cree_le', implode(' | ', $plan));
    }

    // --- (4) K4c : délai du client Kumo strictement plus court que la réservation ---

    public function test_delai_maximal_du_client_kumo_est_nettement_inferieur_a_la_reservation(): void
    {
        $this->assertLessThanOrEqual(Stockage::DELAI_RESERVATION / 2, Kumo::DELAI_MAX_SECONDES);
        $trouvees = 0;
        foreach (glob(__DIR__.'/../src/*.php') ?: [] as $f) {
            $classe = 'Passerelle\\'.basename($f, '.php');
            if (class_exists($classe) && is_subclass_of($classe, Kumo::class)) {
                $trouvees++;
                $delai = (new \ReflectionClass($classe))->newInstanceWithoutConstructor()->delaiMaximal();
                $this->assertLessThan(Stockage::DELAI_RESERVATION, $delai, $classe.' : le délai du client doit être < DELAI_RESERVATION (K4c bloquant)');
                $this->assertLessThanOrEqual(Kumo::DELAI_MAX_SECONDES, $delai, $classe);
            }
        }
        $this->assertGreaterThanOrEqual(2, $trouvees);
        $this->assertSame(Kumo::DELAI_MAX_SECONDES, (new FauxKumo)->delaiMaximal());
        $this->assertSame(Kumo::DELAI_MAX_SECONDES, (new KumoInactif)->delaiMaximal());
    }

    public function test_passerelle_refuse_un_client_kumo_trop_lent(): void
    {
        $lent = new class implements Kumo
        {
            public function delaiMaximal(): int
            {
                return Stockage::DELAI_RESERVATION;
            }

            public function executer(string $workspaceId, string $operation, array $params): array
            {
                return [];
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        new Passerelle($this->stockage, $lent, [self::WS_A => self::CLE_A]);
    }

    // --- (6) mineurs ---

    public function test_i5_second_pdo_garde_une_transaction_ouverte_exclusion_et_busy_timeout(): void
    {
        $court = new Stockage($this->fichier, fn (): int => $this->maintenant, null, 200);
        $verrou = $this->pdo();
        $verrou->exec('BEGIN IMMEDIATE');
        $t = microtime(true);
        try {
            $court->ajouterEvenement(self::WS_A, 'delivery', []);
            $this->fail('écriture attendue bloquée tant que l\'autre transaction est ouverte');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }
        $this->assertGreaterThanOrEqual(0.15, microtime(true) - $t, 'busy_timeout attend avant d\'échouer');
        $this->assertSame(0, $this->stockage->dernierSequence(self::WS_A), 'lecture WAL possible, aucun numéro consommé');
        $verrou->exec('COMMIT');
        $this->assertSame(1, $court->ajouterEvenement(self::WS_A, 'delivery', []), 'aucun trou après l\'échec');
        $this->assertSame([], $court->trous(self::WS_A));
    }

    public function test_i5_ecriture_attend_la_fin_du_verrou_d_un_autre_processus(): void
    {
        $code = '$p=new PDO("sqlite:".$argv[1]);$p->exec("PRAGMA busy_timeout=5000");$p->exec("BEGIN IMMEDIATE");echo "ok\n";usleep(400000);$p->exec("COMMIT");';
        $proc = proc_open([PHP_BINARY, '-r', $code, $this->fichier], [1 => ['pipe', 'w']], $tubes);
        $this->assertIsResource($proc);
        $this->assertSame("ok", trim((string) fgets($tubes[1])));
        $t = microtime(true);
        $s = new Stockage($this->fichier, fn (): int => $this->maintenant, null, 5000);
        $this->assertSame(1, $s->ajouterEvenement(self::WS_A, 'delivery', []));
        $this->assertGreaterThanOrEqual(0.2, microtime(true) - $t, 'a attendu le verrou de l\'autre processus');
        proc_close($proc);
    }

    public function test_get_en_minuscules_signe_en_majuscules_est_accepte(): void
    {
        $signee = $this->signer('GET', '/v1/evenements?curseur=&limite=1', '');
        $minuscules = new Requete('get', $signee->chemin, $signee->corps, $signee->entetes);
        $this->assertSame(200, $this->passerelle->traiter($minuscules)->statut);
    }
}

<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\Injection\RefusKumo;
use Passerelle\Kumo;
use Passerelle\KumoHttp;
use Passerelle\Stockage;
use PHPUnit\Framework\TestCase;

final class KumoHttpTest extends TestCase
{
    private const WS_A = '11111111-1111-4111-8111-111111111111';

    private const WS_B = '22222222-2222-4222-8222-222222222222';

    private const INJ = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /** @var resource|null */
    private $serveur;

    private string $journal;

    private string $base;

    private string $db;

    private Stockage $stockage;

    protected function setUp(): void
    {
        $this->journal = sys_get_temp_dir().'/fk-'.bin2hex(random_bytes(6)).'.log';
        $this->db = sys_get_temp_dir().'/fk-'.bin2hex(random_bytes(6)).'.sqlite';
        $this->stockage = new Stockage($this->db, fn (): int => 1_800_000_000, false);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serveur)) {
            proc_terminate($this->serveur);
            proc_close($this->serveur);
        }
        foreach ([$this->journal, $this->db, $this->db.'-wal', $this->db.'-shm'] as $f) {
            @unlink($f);
        }
    }

    private function demarrer(string $mode = 'ok'): KumoHttp
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);
        $this->serveur = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__.'/faux-kumomta/routeur.php'], [], $pipes, null,
            ['FAUX_KUMO_JOURNAL' => $this->journal, 'FAUX_KUMO_MODE' => $mode, 'PATH' => (string) getenv('PATH')],
        );
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $m, 0.1);
            if ($c !== false) {
                fclose($c);
                break;
            }
            usleep(50_000);
        }
        $this->base = "http://127.0.0.1:$port";

        return new KumoHttp($this->base, $this->stockage);
    }

    /** @return list<array{methode:string,chemin:string,corps:mixed}> */
    private function requetes(): array
    {
        if (! is_file($this->journal)) {
            return [];
        }

        return array_map(fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($this->journal)))));
    }

    public function test_injection_envoie_inject_v1_avec_campagne(): void
    {
        $k = $this->demarrer();
        $k->executer(self::WS_A, 'injecter', ['mail_from' => 'a@example.test', 'rcpt_to' => 'b@example.test', 'message' => "Subject: x\r\n\r\nhi", 'id_injection' => self::INJ]);
        $r = $this->requetes();
        $this->assertCount(1, $r);
        $this->assertSame('/api/inject/v1', $r[0]['chemin']);
        $this->assertSame('a@example.test', $r[0]['corps']['envelope_sender']);
        $this->assertSame(['b@example.test'], $r[0]['corps']['recipients'] ?? array_column($r[0]['corps']['recipients'], 'email'));
        $this->assertStringContainsString('X-Campaign: '.self::INJ, json_encode($r[0]['corps']));
        $this->assertStringContainsString(self::WS_A, json_encode($r[0]['corps']));
    }

    public function test_injection_5xx_est_une_exception_jamais_acceptee(): void
    {
        $k = $this->demarrer('500');
        $this->expectException(\RuntimeException::class);
        $k->executer(self::WS_A, 'injecter', ['mail_from' => 'a@example.test', 'rcpt_to' => 'b@example.test', 'message' => 'x', 'id_injection' => self::INJ]);
    }

    public function test_injection_4xx_est_un_refus_definitif(): void
    {
        $k = $this->demarrer('400');
        try {
            $k->executer(self::WS_A, 'injecter', ['mail_from' => 'a@example.test', 'rcpt_to' => 'b@example.test', 'message' => 'x', 'id_injection' => self::INJ]);
            $this->fail('refus attendu');
        } catch (RefusKumo $e) {
            $this->assertFalse($e->temporaire());
        }
    }

    public function test_connexion_refusee_est_une_exception(): void
    {
        $k = new KumoHttp('http://127.0.0.1:1', $this->stockage);
        $this->expectException(\RuntimeException::class);
        $k->executer(self::WS_A, 'pause', []);
    }

    public function test_pause_deux_fois_deux_suspensions_puis_reprise_deux_delete(): void
    {
        $k = $this->demarrer();
        $k->executer(self::WS_A, 'pause', []);
        $k->executer(self::WS_A, 'pause', []);
        $r = $this->requetes();
        $this->assertCount(2, $r);
        foreach ($r as $q) {
            $this->assertSame('/api/admin/suspend/v1', $q['chemin']);
            $this->assertSame(self::WS_A, $q['corps']['tenant']);
        }
        $this->assertCount(2, $this->stockage->suspensionsActives(self::WS_A));
        $this->assertSame(['levees' => 2], $k->executer(self::WS_A, 'reprise', []));
        $r = $this->requetes();
        $this->assertCount(4, $r);
        $this->assertSame('DELETE', $r[2]['methode']);
        $this->assertSame('DELETE', $r[3]['methode']);
        $this->assertSame([], $this->stockage->suspensionsActives(self::WS_A));
    }

    public function test_suspension_notee_perimee_nouvelle_pause_rappelle_kumo(): void
    {
        $k = $this->demarrer();
        // miroir local « suspendu » depuis plus de 30 jours, KumoMTA l'a oubliée : l'arrêt doit quand même repartir
        $this->stockage->noterSuspension(self::WS_A, 'susp-ancienne');
        $rep = $k->executer(self::WS_A, 'pause', []);
        $r = $this->requetes();
        $this->assertCount(1, $r);
        $this->assertSame('POST', $r[0]['methode']);
        $this->assertSame('/api/admin/suspend/v1', $r[0]['chemin']);
        $this->assertTrue($rep['suspendue']);
        $this->assertArrayNotHasKey('deja', $rep);
    }

    public function test_reprise_delete_avec_id_note_puis_ne_refait_rien(): void
    {
        $k = $this->demarrer();
        $k->executer(self::WS_A, 'pause', []);
        $id = $this->stockage->suspensionsActives(self::WS_A)[0];
        $k->executer(self::WS_A, 'reprise', []);
        $k->executer(self::WS_A, 'reprise', []);
        $r = $this->requetes();
        $this->assertCount(2, $r);
        $this->assertSame('DELETE', $r[1]['methode']);
        $this->assertSame($id, $r[1]['corps']['id']);
        $this->assertSame([], $this->stockage->suspensionsActives(self::WS_A));
    }

    public function test_reprise_sans_pause_zero_requete(): void
    {
        $k = $this->demarrer();
        $this->assertSame(['levees' => 0], $k->executer(self::WS_A, 'reprise', []));
        $this->assertCount(0, $this->requetes());
    }

    public function test_reprise_de_b_ne_leve_pas_la_suspension_de_a(): void
    {
        $k = $this->demarrer();
        $k->executer(self::WS_A, 'pause', []);
        $k->executer(self::WS_B, 'reprise', []);
        $this->assertCount(1, $this->requetes());
        $this->assertCount(1, $this->stockage->suspensionsActives(self::WS_A));
        $this->assertSame([], $this->stockage->suspensionsActives(self::WS_B));
    }

    public function test_retrait_de_trois_cibles_trois_rebonds_avec_campagne_et_tenant(): void
    {
        $k = $this->demarrer();
        $cibles = [self::INJ, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'];
        $k->executer(self::WS_A, 'retrait', ['cibles' => $cibles]);
        $r = $this->requetes();
        $this->assertCount(3, $r);
        foreach ($r as $i => $q) {
            $this->assertSame('/api/admin/bounce/v1', $q['chemin']);
            $this->assertSame($cibles[$i], $q['corps']['campaign']);
            $this->assertSame(self::WS_A, $q['corps']['tenant']);
        }
    }

    public function test_retrait_liste_vide_exception_zero_requete(): void
    {
        $k = $this->demarrer();
        $leve = false;
        try {
            $k->executer(self::WS_A, 'retrait', ['cibles' => []]);
        } catch (\RuntimeException) {
            $leve = true;
        }
        $this->assertTrue($leve);
        $this->assertCount(0, $this->requetes());
    }

    public function test_hote_non_local_refuse_a_la_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new KumoHttp('http://10.0.0.1:8000', $this->stockage);
    }

    public function test_delai_maximal_respecte_la_borne(): void
    {
        $this->assertLessThan(Kumo::DELAI_MAX_SECONDES, (new KumoHttp('http://127.0.0.1:8000', $this->stockage))->delaiMaximal());
    }
}

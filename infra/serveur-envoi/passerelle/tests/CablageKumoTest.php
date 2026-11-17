<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\KumoHttp;
use Passerelle\KumoInactif;
use Passerelle\Sante\MesureurSysteme;
use Passerelle\Stockage;
use PHPUnit\Framework\TestCase;

/** C4-B : choix de l'adaptateur KumoMTA (inerte par défaut) et mesure `file_age_s`. Aucun réseau réel (127.0.0.1 seulement). */
final class CablageKumoTest extends TestCase
{
    /** @var resource|null */
    private $serveur;

    /** @var list<string> */
    private array $fichiers = [];

    public static function setUpBeforeClass(): void
    {
        if (! defined('PASSERELLE_SANS_EXECUTION')) {
            define('PASSERELLE_SANS_EXECUTION', true);
        }
        require_once __DIR__.'/../public/index.php';
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serveur)) {
            proc_terminate($this->serveur);
            proc_close($this->serveur);
        }
        foreach ($this->fichiers as $f) {
            @unlink($f);
        }
    }

    private function stockage(): \Closure
    {
        $db = sys_get_temp_dir().'/ck-'.bin2hex(random_bytes(6)).'.sqlite';
        array_push($this->fichiers, $db, $db.'-wal', $db.'-shm');

        return fn (): Stockage => new Stockage($db, fn (): int => 1_800_000_000, false);
    }

    public function test_sans_variable_kumo_inactif(): void
    {
        $this->assertInstanceOf(KumoInactif::class, \choisirKumo([], $this->stockage()));
        $this->assertInstanceOf(KumoInactif::class, \choisirKumo(['ENVOI_REEL_ACTIF' => 'true'], $this->stockage()));
        $this->assertInstanceOf(KumoInactif::class, \choisirKumo(['PASSERELLE_KUMO_URL' => ''], $this->stockage()));
    }

    public function test_url_posee_mais_envoi_reel_pas_exactement_true_kumo_inactif(): void
    {
        foreach ([null, 'false', '1', 'TRUE', ' true', 'true ', '', 'yes', 'on'] as $v) {
            $env = ['PASSERELLE_KUMO_URL' => 'http://127.0.0.1:8000'] + ($v === null ? [] : ['ENVOI_REEL_ACTIF' => $v]);
            $this->assertInstanceOf(KumoInactif::class, \choisirKumo($env, $this->stockage()), var_export($v, true));
        }
    }

    public function test_les_deux_poses_kumo_http(): void
    {
        $k = \choisirKumo(['PASSERELLE_KUMO_URL' => 'http://127.0.0.1:8000', 'ENVOI_REEL_ACTIF' => 'true'], $this->stockage());
        $this->assertInstanceOf(KumoHttp::class, $k);
    }

    public function test_hote_non_local_refuse_au_demarrage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \choisirKumo(['PASSERELLE_KUMO_URL' => 'http://10.0.0.1:8000', 'ENVOI_REEL_ACTIF' => 'true'], $this->stockage());
    }

    public function test_hote_non_local_ignore_si_envoi_eteint(): void
    {
        $this->assertInstanceOf(KumoInactif::class, \choisirKumo(['PASSERELLE_KUMO_URL' => 'http://10.0.0.1:8000', 'ENVOI_REEL_ACTIF' => 'false'], $this->stockage()));
    }

    private function demarrerFaux(string $mode): string
    {
        $routeur = sys_get_temp_dir().'/ckr-'.bin2hex(random_bytes(6)).'.php';
        $this->fichiers[] = $routeur;
        file_put_contents($routeur, <<<'PHP'
<?php
header('Content-Type: application/json');
$mode = getenv('FAUX_METRICS');
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/metrics.json' || $_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(404); echo '{}'; return; }
if ($mode === '500') { http_response_code(500); echo '{}'; return; }
if ($mode === 'invalide') { echo 'pas du json'; return; }
if ($mode === 'texte') { echo '{"oldest_message_age_s":"12"}'; return; }
if ($mode === 'negatif') { echo '{"oldest_message_age_s":-5}'; return; }
if ($mode === 'vide') { echo '{}'; return; }
echo '{"oldest_message_age_s":742.9}';
PHP);
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $nom = (string) stream_socket_get_name($sock, false);
        $port = (int) substr($nom, strrpos($nom, ':') + 1);
        fclose($sock);
        $this->serveur = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $routeur], [], $pipes, null, ['FAUX_METRICS' => $mode, 'PATH' => (string) getenv('PATH')]);
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $m, 0.1);
            if ($c !== false) {
                fclose($c);
                break;
            }
            usleep(50_000);
        }

        return "http://127.0.0.1:$port";
    }

    /** @return array<string,mixed> */
    private function mesurer(?string $kumo): array
    {
        return (new MesureurSysteme(sys_get_temp_dir(), null, '/inexistant/meminfo', '/inexistant', $kumo))->mesurer();
    }

    public function test_file_age_present_quand_kumo_repond(): void
    {
        $m = $this->mesurer($this->demarrerFaux('ok'));
        $this->assertSame(742, $m['file_age_s']);
    }

    public function test_file_age_omis_sans_kumo(): void
    {
        $this->assertArrayNotHasKey('file_age_s', $this->mesurer(null));
    }

    public function test_file_age_omis_sur_toute_erreur(): void
    {
        foreach (['500', 'invalide', 'texte', 'negatif', 'vide'] as $mode) {
            $m = $this->mesurer($this->demarrerFaux($mode));
            $this->assertArrayNotHasKey('file_age_s', $m, $mode);
            $this->assertArrayHasKey('ntp_synchro', $m, $mode);
            proc_terminate($this->serveur);
            proc_close($this->serveur);
        }
    }

    public function test_file_age_omis_si_kumo_injoignable_ou_non_local(): void
    {
        $this->assertArrayNotHasKey('file_age_s', $this->mesurer('http://127.0.0.1:1'));
        $this->assertArrayNotHasKey('file_age_s', $this->mesurer('http://10.0.0.1:8000'));
    }
}

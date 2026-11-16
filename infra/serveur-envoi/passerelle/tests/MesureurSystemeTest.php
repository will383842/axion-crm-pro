<?php

declare(strict_types=1);

namespace Passerelle\Tests;

use Passerelle\Sante\MesureurSysteme;
use PHPUnit\Framework\TestCase;

/** C2-1 : mesures système lues dans des fichiers factices (aucun accès au vrai /proc). */
final class MesureurSystemeTest extends TestCase
{
    private function meminfo(string $contenu): string
    {
        $f = tempnam(sys_get_temp_dir(), 'meminfo');
        file_put_contents($f, $contenu);

        return $f;
    }

    public function test_memoire_en_pourcentage(): void
    {
        $m = (new MesureurSysteme(sys_get_temp_dir(), null, $this->meminfo("MemTotal:  1000 kB\nMemFree: 1 kB\nMemAvailable:  250 kB\n"), '/inexistant'))->mesurer();
        $this->assertSame(25.0, $m['memoire_libre_pct']);
        $this->assertFalse($m['ntp_synchro']);
        $this->assertArrayNotHasKey('file_age_s', $m);
        $this->assertArrayNotHasKey('tunnel_ok', $m);
        $this->assertArrayNotHasKey('certificat_jours', $m);
    }

    public function test_memoire_totale_nulle_ou_illisible_est_omise_jamais_zero(): void
    {
        foreach (["MemTotal: 0 kB\nMemAvailable: 0 kB\n", "MemAvailable: 10 kB\n", ''] as $contenu) {
            $m = (new MesureurSysteme(sys_get_temp_dir(), null, $this->meminfo($contenu), '/inexistant'))->mesurer();
            $this->assertArrayNotHasKey('memoire_libre_pct', $m, $contenu);
        }
        $m = (new MesureurSysteme(sys_get_temp_dir(), null, '/inexistant/meminfo', '/inexistant'))->mesurer();
        $this->assertArrayNotHasKey('memoire_libre_pct', $m);
    }
}

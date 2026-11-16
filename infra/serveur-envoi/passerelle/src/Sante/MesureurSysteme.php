<?php

declare(strict_types=1);

namespace Passerelle\Sante;

/** Mesures en lecture seule (aucun exec, aucun réseau). `file_age_s` et `tunnel_ok` : omis (API d'administration, lot C4). */
final class MesureurSysteme implements Mesureur
{
    public function __construct(
        private readonly string $dossierBase,
        private readonly ?string $certificat = null,
        private readonly string $meminfo = '/proc/meminfo',
        private readonly string $marqueNtp = '/run/systemd/timesync/synchronized',
    ) {}

    public function mesurer(): array
    {
        $m = [];
        $libre = @disk_free_space($this->dossierBase);
        $total = @disk_total_space($this->dossierBase);
        if ($libre !== false && $total !== false && $total > 0) {
            $m['disque_libre_pct'] = round($libre / $total * 100, 1);
        }
        $info = is_readable($this->meminfo) ? (string) @file_get_contents($this->meminfo) : '';
        if (preg_match('/^MemTotal:\s+(\d+)/m', $info, $t) === 1 && preg_match('/^MemAvailable:\s+(\d+)/m', $info, $a) === 1 && (int) $t[1] > 0) {
            $m['memoire_libre_pct'] = round((int) $a[1] / (int) $t[1] * 100, 1);
        }
        if ($this->certificat !== null && $this->certificat !== '' && is_readable($this->certificat)) {
            $x = @openssl_x509_parse((string) @file_get_contents($this->certificat));
            if (is_array($x) && isset($x['validTo_time_t'])) {
                $m['certificat_jours'] = intdiv((int) $x['validTo_time_t'] - time(), 86400);
            }
        }
        $m['ntp_synchro'] = file_exists($this->marqueNtp);

        return $m;
    }
}

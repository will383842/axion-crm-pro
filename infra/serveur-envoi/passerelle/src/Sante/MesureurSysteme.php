<?php

declare(strict_types=1);

namespace Passerelle\Sante;

/**
 * Mesures en lecture seule (aucun exec). `tunnel_ok` : omis. `file_age_s` : lu sur `GET /metrics.json` de KumoMTA
 * (hôte local seulement, délais courts) quand `$kumoBase` est fourni ; toute erreur ⇒ clé omise, jamais « vert ».
 * Clé lue : `oldest_message_age_s` (hypothèse ESCALADE E5, à confirmer sur l'image épinglée).
 */
final class MesureurSysteme implements Mesureur
{
    public function __construct(
        private readonly string $dossierBase,
        private readonly ?string $certificat = null,
        private readonly string $meminfo = '/proc/meminfo',
        private readonly string $marqueNtp = '/run/systemd/timesync/synchronized',
        private readonly ?string $kumoBase = null,
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
        $age = $this->ageFile();
        if ($age !== null) {
            $m['file_age_s'] = $age;
        }

        return $m;
    }

    /** Âge (secondes entières) du plus vieux message en file, ou null si la mesure est absente ou douteuse. */
    private function ageFile(): ?int
    {
        if ($this->kumoBase === null) {
            return null;
        }
        $p = parse_url($this->kumoBase);
        if ($p === false || ($p['scheme'] ?? '') !== 'http' || ! in_array($p['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            return null;
        }
        $ch = curl_init('http://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '').'/metrics.json');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        ]);
        $rep = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (! is_string($rep) || $code !== 200) {
            return null;
        }
        $d = json_decode($rep, true);
        $v = is_array($d) ? ($d['oldest_message_age_s'] ?? null) : null;
        if ((! is_int($v) && ! is_float($v)) || ! is_finite((float) $v) || $v < 0 || $v > 31_536_000) {
            return null;
        }

        return (int) floor($v);
    }
}

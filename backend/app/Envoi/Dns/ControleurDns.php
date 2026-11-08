<?php

namespace App\Envoi\Dns;

/** Exécute les 20 points de contrôle d'un domaine d'envoi. Lecture seule : aucun envoi. */
final class ControleurDns
{
    private const LIMITE_SPF = 10;

    public function __construct(private readonly ResolveurDns $dns) {}

    public function controler(ConfigurationDomaine $c): RapportControle
    {
        $r = [];
        $ok = static fn (bool $b, string $echec = 'ko'): string => $b ? 'ok' : $echec;

        // SPF
        $spf = $this->enregistrements($c->domaine, 'v=spf1');
        $unique = count($spf) === 1;
        $mots = [];
        $lookups = 0;
        if ($unique) {
            $mots = $this->mecanismesSpf($spf[0], $lookups, 0, [$c->domaine => true]);
        }
        $fin = $mots === [] ? '' : $mots[count($mots) - 1];
        $r['spf_present'] = $ok($spf !== []);
        $r['spf_unique'] = $ok($unique);
        $r['spf_se_termine_par_all'] = $ok(in_array($fin, ['-all', '~all'], true));
        $r['spf_10_resolutions_max'] = $ok($unique && $lookups <= self::LIMITE_SPF);
        $r['spf_autorise_ip'] = $ok($this->spfAutorise($mots, $c->ip));

        // DKIM
        // v=DKIM1 est facultatif (RFC 6376) : on retient tout TXT qui porte une étiquette p=.
        $dkim = [];
        foreach ($this->dns->txt($c->selecteur . '._domainkey.' . $c->domaine) as $t) {
            $e = $this->etiquettes($t);
            if (isset($e['p']) || stripos(trim($t), 'v=DKIM1') === 0) {
                $dkim = $e;
                break;
            }
        }
        $cle = (string) base64_decode((string) preg_replace('/\s+/', '', $dkim['p'] ?? ''), true);
        $r['dkim_present'] = $ok($dkim !== []);
        $r['dkim_cle_publique'] = $ok($cle !== '');
        $r['dkim_cle_2048'] = $ok(strlen($cle) >= 256, 'avert');

        // DMARC
        $dm = $this->enregistrements('_dmarc.' . $c->domaine, 'v=DMARC1');
        if ($dm === [] && substr_count($c->domaine, '.') >= 2) {
            // Repli RFC 7489 : sans enregistrement propre, la politique du domaine parent s'applique.
            $dm = $this->enregistrements('_dmarc.' . substr($c->domaine, (int) strpos($c->domaine, '.') + 1), 'v=DMARC1');
        }
        $e = $this->etiquettes($dm[0] ?? '');
        $r['dmarc_present'] = $ok($dm !== []);
        $r['dmarc_unique'] = $ok(count($dm) === 1);
        $r['dmarc_politique'] = $ok(in_array(strtolower($e['p'] ?? ''), ['none', 'quarantine', 'reject'], true));
        $r['dmarc_rua'] = $ok(str_starts_with(strtolower($e['rua'] ?? ''), 'mailto:'), 'avert');

        // PTR = HELO = A
        $ptr = $this->dns->ptr($c->ip);
        $r['helo_est_un_nom'] = $ok(true); // garanti par ConfigurationDomaine
        $r['ptr_existe'] = $ok($ptr !== []);
        $r['ptr_egal_helo'] = $ok(in_array($c->helo, $ptr, true));
        $v6 = filter_var($c->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $r['helo_a_vers_ip'] = $ok(in_array($c->ip, $v6 ? $this->dns->aaaa($c->helo) : $this->dns->a($c->helo), true));

        // MTA-STS (testing) et TLS-RPT : recommandés, non bloquants
        $r['mta_sts_enregistrement'] = $ok($this->enregistrements('_mta-sts.' . $c->domaine, 'v=STSv1') !== [], 'avert');
        $pol = $this->dns->politiqueMtaSts($c->domaine);
        $r['mta_sts_politique'] = $ok($pol !== null && str_contains($pol, 'version: STSv1'), 'avert');
        $r['mta_sts_mode_testing'] = $ok($pol !== null && preg_match('/^mode:\s*testing\s*$/mi', $pol) === 1, 'avert');
        $r['tls_rpt'] = $ok($this->enregistrements('_smtp._tls.' . $c->domaine, 'v=TLSRPTv1') !== [], 'avert');

        $points = [];
        foreach (PlanDns::codesPointsDeControle() as $code) {
            $points[] = new PointControle($code, $r[$code]);
        }

        return new RapportControle($c->domaine, $points);
    }

    /** @return list<string> TXT commençant par $debut (insensible à la casse) */
    private function enregistrements(string $nom, string $debut): array
    {
        return array_values(array_filter(
            $this->dns->txt($nom),
            static fn (string $t): bool => stripos((string) preg_replace('/\s*=\s*/', '=', trim($t), 1), $debut) === 0,
        ));
    }

    /** @return array<string, string> */
    private function etiquettes(string $txt): array
    {
        $t = [];
        foreach (explode(';', $txt) as $morceau) {
            $kv = explode('=', trim($morceau), 2);
            if (count($kv) === 2) {
                $t[strtolower(trim($kv[0]))] = trim($kv[1]);
            }
        }

        return $t;
    }

    /**
     * Mécanismes SPF, `include:` suivis (profondeur et boucles bornées). Compte les
     * mécanismes qui coûtent une résolution.
     *
     * @param  array<string, true>  $vus
     * @return list<string>
     */
    private function mecanismesSpf(string $spf, int &$lookups, int $profondeur, array $vus): array
    {
        $sortie = [];
        foreach (preg_split('/\s+/', strtolower(trim($spf))) ?: [] as $m) {
            if ($m === '' || $m === 'v=spf1') {
                continue;
            }
            if (preg_match('/^(include:|a$|a:|a\/|mx$|mx:|mx\/|ptr|exists:|redirect=)/', ltrim($m, '+-~?')) === 1) {
                $lookups++;
            }
            if (str_starts_with($m, 'redirect=') && $profondeur < self::LIMITE_SPF) {
                // redirect= REMPLACE la politique (RFC 7208 §6.1) : ses mécanismes, y compris son « all », sont repris.
                $cible = substr($m, 9);
                if (! isset($vus[$cible]) && $lookups <= self::LIMITE_SPF + 1) {
                    $vus[$cible] = true;
                    foreach ($this->enregistrements($cible, 'v=spf1') as $sous) {
                        foreach ($this->mecanismesSpf($sous, $lookups, $profondeur + 1, $vus) as $x) {
                            $sortie[] = $x;
                        }
                    }
                }

                continue;
            }
            if (str_starts_with($m, 'include:') && $profondeur < self::LIMITE_SPF) {
                $cible = substr($m, 8);
                if (! isset($vus[$cible]) && $lookups <= self::LIMITE_SPF + 1) {
                    $vus[$cible] = true;
                    foreach ($this->enregistrements($cible, 'v=spf1') as $sous) {
                        foreach ($this->mecanismesSpf($sous, $lookups, $profondeur + 1, $vus) as $x) {
                            if (! str_ends_with($x, 'all')) {
                                $sortie[] = $x;
                            }
                        }
                    }
                }

                continue;
            }
            $sortie[] = $m;
        }

        return $sortie;
    }

    /** @param list<string> $mots */
    private function spfAutorise(array $mots, string $ip): bool
    {
        $adresse = inet_pton($ip);
        if ($adresse === false) {
            return false;
        }
        $v6 = strlen($adresse) === 16;
        foreach ($mots as $m) {
            if (preg_match($v6 ? '#^\+?ip6:([0-9a-f:.]+)(?:/(\d{1,3}))?$#' : '#^\+?ip4:([0-9.]+)(?:/(\d{1,2}))?$#', $m, $x) !== 1) {
                continue;
            }
            $reseau = inet_pton($x[1]);
            $max = $v6 ? 128 : 32;
            $bits = isset($x[2]) ? (int) $x[2] : $max;
            if ($reseau === false || strlen($reseau) !== strlen($adresse) || $bits > $max) {
                continue;
            }
            $complet = intdiv($bits, 8);
            $reste = $bits % 8;
            if (substr($adresse, 0, $complet) !== substr($reseau, 0, $complet)) {
                continue;
            }
            if ($reste === 0 || ((ord($adresse[$complet]) ^ ord($reseau[$complet])) & (0xFF << (8 - $reste) & 0xFF)) === 0) {
                return true;
            }
        }

        return false;
    }
}

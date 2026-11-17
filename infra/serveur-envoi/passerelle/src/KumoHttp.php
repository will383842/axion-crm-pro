<?php

declare(strict_types=1);

namespace Passerelle;

use Passerelle\Injection\RefusKumo;

/**
 * Adaptateur de la passerelle vers l'API HTTP locale de KumoMTA (C4-A). Hôte local seulement ; aucune nouvelle
 * tentative interne ; jamais de « accepté » sur une erreur. Non câblé (C4-B) : inerte tant que rien ne le construit.
 */
final class KumoHttp implements Kumo
{
    private const CONNEXION_S = 2;

    private const TOTAL_S = 5;

    private string $base;

    public function __construct(string $base, private readonly Stockage $stockage)
    {
        $p = parse_url($base);
        if ($p === false || ($p['scheme'] ?? '') !== 'http' || ! in_array($p['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            throw new \InvalidArgumentException('hote_kumo_non_local');
        }
        $this->base = 'http://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }

    public function delaiMaximal(): int
    {
        return self::TOTAL_S;
    }

    public function executer(string $workspaceId, string $operation, array $params): array
    {
        return match ($operation) {
            'injecter' => $this->injecter($workspaceId, $params),
            'pause' => $this->pause($workspaceId),
            'reprise' => $this->reprise($workspaceId),
            'retrait' => $this->retrait($workspaceId, $params),
            default => throw new \RuntimeException('operation_inconnue'),
        };
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function injecter(string $ws, array $p): array
    {
        $entetes = 'X-Tenant: '.$ws."\r\n";
        if (isset($p['id_injection']) && is_string($p['id_injection'])) {
            $entetes .= 'X-Campaign: '.$p['id_injection']."\r\n";
        }
        $this->appeler('POST', '/api/inject/v1', [
            'envelope_sender' => (string) ($p['mail_from'] ?? ''),
            'recipients' => [(string) ($p['rcpt_to'] ?? '')],
            'content' => $entetes."\r\n".(string) ($p['message'] ?? ''),
        ], true);

        return ['accepte' => true];
    }

    /** @return array<string,mixed> */
    private function pause(string $ws): array
    {
        // Toujours rappeler KumoMTA : le miroir local peut être périmé (suspension expirée à 30 j, redémarrage).
        $rep = $this->appeler('POST', '/api/admin/suspend/v1', ['tenant' => $ws, 'reason' => 'tout-arreter', 'duration' => '30d']);
        $id = $rep['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('suspension_sans_id');
        }
        $this->stockage->noterSuspension($ws, $id);

        return ['suspendue' => true];
    }

    /** @return array<string,mixed> */
    private function reprise(string $ws): array
    {
        $n = 0;
        foreach ($this->stockage->suspensionsActives($ws) as $id) {
            $this->appeler('DELETE', '/api/admin/suspend/v1', ['id' => $id]);
            $this->stockage->lever($ws, $id);
            $n++;
        }

        return ['levees' => $n];
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function retrait(string $ws, array $p): array
    {
        $cibles = $p['cibles'] ?? null;
        if (! is_array($cibles) || $cibles === []) {
            throw new \RuntimeException('retrait_sans_cible'); // un rebond sans critère viderait la file
        }
        foreach ($cibles as $c) {
            if (! is_string($c) || $c === '') {
                throw new \RuntimeException('retrait_cible_invalide');
            }
        }
        foreach ($cibles as $c) {
            $this->appeler('POST', '/api/admin/bounce/v1', ['campaign' => $c, 'tenant' => $ws, 'reason' => 'retrait-opposition', 'duration' => '30d']);
        }

        return ['rebondies' => count($cibles)];
    }

    /**
     * @param  array<string,mixed>  $corps
     * @return array<string,mixed>
     */
    private function appeler(string $methode, string $chemin, array $corps, bool $refusDefinitif = false): array
    {
        $ch = curl_init($this->base.$chemin);
        if ($ch === false) {
            throw new \RuntimeException('kumo_injoignable');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_POSTFIELDS => json_encode($corps, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNEXION_S, CURLOPT_TIMEOUT => self::TOTAL_S,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        ]);
        $rep = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (! is_string($rep) || $code === 0) {
            throw new \RuntimeException('kumo_injoignable');
        }
        if ($code >= 400 && $code < 500) {
            if ($refusDefinitif) {
                throw new RefusKumo(550);
            }
            throw new \RuntimeException('kumo_refus_'.$code);
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('kumo_injoignable');
        }
        $d = json_decode($rep, true);

        return is_array($d) ? $d : [];
    }
}

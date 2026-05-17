<?php

namespace App\Services\Smtp;

use App\Contracts\SmtpProber;
use App\Data\Email\SmtpProbeResult;
use App\Services\Email\HunterEmailVerifier;

/**
 * SmtpProber backed by Hunter.io email-verifier API (Sprint H2 — 2026-05-17).
 *
 * Remplace RealSmtpProber (probe direct fsockopen sur IP Hetzner → ban Spamhaus).
 * Wired par MockServicesProvider quand MOCK_SMTP=false.
 *
 * Mapping Hunter status → SmtpProbeResult status :
 *   deliverable     → valid (score Hunter conservé)
 *   undeliverable   → invalid
 *   risky           → catchall (mieux que 'risky' qui n'existe pas dans le contrat)
 *   accept_all      → catchall
 *   unknown         → unknown
 *   disposable      → disposable
 *
 * Pas de rate limit côté code : Hunter gère son propre quota.
 */
class HunterSmtpProber implements SmtpProber
{
    public function __construct(
        private readonly HunterEmailVerifier $verifier,
    ) {}

    public function probe(string $email): SmtpProbeResult
    {
        $email = strtolower(trim($email));
        $result = $this->verifier->verify($email);

        $status = (string) ($result['status'] ?? 'unknown');
        $score  = (int) ($result['score'] ?? 0);

        $mapped = match ($status) {
            'deliverable'             => 'valid',
            'undeliverable', 'invalid'=> 'invalid',
            'risky', 'accept_all'     => 'catchall',
            'disposable'              => 'disposable',
            default                   => 'unknown',
        };

        return new SmtpProbeResult(
            email: $email,
            status: $mapped,
            score: $score,
            mxHost: null,
            message: $result['reason'] ?? null,
            isCatchAll: in_array($status, ['risky', 'accept_all'], true),
            isDisposable: (bool) ($result['disposable'] ?? false),
            isRole: false,
        );
    }
}

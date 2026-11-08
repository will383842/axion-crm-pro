<?php

namespace App\Envoi\Sante;

/** Mesures rendues par le serveur d'envoi (null = non mesurée : jamais un « vert » par défaut). */
final class MesuresSante
{
    public function __construct(
        public readonly ?int $ageFileSecondes,
        public readonly ?float $disqueLibrePct,
        public readonly ?float $memoireLibrePct,
        public readonly ?int $certificatJours,
        public readonly ?bool $tunnelOk,
        public readonly ?bool $ntpSynchro,
        public readonly ?int $horlogeUnix,
    ) {}

    /** @param array<string, mixed> $j */
    public static function depuis(array $j): self
    {
        $entier = static fn (mixed $v): ?int => is_int($v) ? $v : (is_float($v) && is_finite($v) ? (int) $v : null);
        $reel = static fn (mixed $v): ?float => (is_int($v) || is_float($v)) && is_finite((float) $v) ? (float) $v : null;
        $bool = static fn (mixed $v): ?bool => is_bool($v) ? $v : null;

        return new self(
            $entier($j['file_age_s'] ?? null),
            $reel($j['disque_libre_pct'] ?? null),
            $reel($j['memoire_libre_pct'] ?? null),
            $entier($j['certificat_jours'] ?? null),
            $bool($j['tunnel_ok'] ?? null),
            $bool($j['ntp_synchro'] ?? null),
            $entier($j['horloge_unix'] ?? null),
        );
    }

    /** @return array<string, int|float|bool|null> */
    public function enTableau(): array
    {
        return [
            'file_age_s' => $this->ageFileSecondes, 'disque_libre_pct' => $this->disqueLibrePct,
            'memoire_libre_pct' => $this->memoireLibrePct, 'certificat_jours' => $this->certificatJours,
            'tunnel_ok' => $this->tunnelOk, 'ntp_synchro' => $this->ntpSynchro, 'horloge_unix' => $this->horlogeUnix,
        ];
    }
}

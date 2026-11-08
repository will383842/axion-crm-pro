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
        // Types stricts (jamais de chaîne « 12 » ni de booléen pris pour un nombre) et bornes vérifiées AVANT tout cast :
        // un flottant hors plage ou à décimales n'est pas une mesure (-> absente, signalée), il ne se tronque pas en silence.
        $entier = static function (mixed $v, int $min, int $max): ?int {
            if (is_float($v)) {
                if (! is_finite($v) || $v < $min || $v > $max || floor($v) !== $v) {
                    return null;
                }

                return (int) $v;
            }

            return is_int($v) && $v >= $min && $v <= $max ? $v : null;
        };
        $reel = static fn (mixed $v, float $min, float $max): ?float => (is_int($v) || is_float($v)) && is_finite((float) $v) && $v >= $min && $v <= $max ? (float) $v : null;
        $bool = static fn (mixed $v): ?bool => is_bool($v) ? $v : null;

        return new self(
            $entier($j['file_age_s'] ?? null, 0, 31_536_000),
            $reel($j['disque_libre_pct'] ?? null, 0.0, 100.0),
            $reel($j['memoire_libre_pct'] ?? null, 0.0, 100.0),
            $entier($j['certificat_jours'] ?? null, -36_500, 36_500),
            $bool($j['tunnel_ok'] ?? null),
            $bool($j['ntp_synchro'] ?? null),
            $entier($j['horloge_unix'] ?? null, 0, 4_102_444_800),
        );
    }

    /** Vrai si AUCUNE mesure n'a pu être lue : une réponse signée sans rien de mesurable n'est pas une réponse de santé. */
    public function estVide(): bool
    {
        return array_filter($this->enTableau(), static fn ($v): bool => $v !== null) === [];
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

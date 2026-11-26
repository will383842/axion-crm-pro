<?php

namespace App\Crm\Sites;

use Closure;

/**
 * GARDE DISQUE de la recherche de site : avant chaque paquet, si plus de 85 %
 * du disque est utilisé, le traitement s'arrête (code de sortie 4).
 *
 * Exactement 85 % : on continue. Au-delà : arrêt.
 * Les mesures sont injectables, pour simuler un disque dans les tests.
 */
final class GardeDisque
{
    /** Part utilisée au-delà de laquelle on s'arrête, en pourcent. */
    public const SEUIL_POURCENT = 85;

    /** Code de sortie d'un arrêt pour cause de disque. */
    public const CODE_SORTIE = 4;

    /**
     * @param  Closure(string): float|null  $libre  octets libres du chemin
     * @param  Closure(string): float|null  $total  octets totaux du chemin
     */
    public function __construct(
        private readonly string $chemin = '/',
        private readonly ?Closure $libre = null,
        private readonly ?Closure $total = null,
    ) {}

    /** Le chemin vient de la configuration (`CRM_ENRICHISSEMENT_CHEMIN_DISQUE`), `/` par défaut. */
    public static function depuisConfig(): self
    {
        $chemin = config('crm.enrichissement.chemin_disque', '/');

        return new self(is_string($chemin) && $chemin !== '' ? $chemin : '/');
    }

    /** Part du disque utilisée, en pourcent (0 à 100) ; null si le disque est illisible. */
    public function pourcentUtilise(): ?float
    {
        $libre = $this->libre !== null ? ($this->libre)($this->chemin) : @disk_free_space($this->chemin);
        $total = $this->total !== null ? ($this->total)($this->chemin) : @disk_total_space($this->chemin);
        if ($libre === false || $total === false || $total <= 0) {
            return null;
        }

        return 100.0 * (1.0 - $libre / $total);
    }

    /** Vrai s'il faut s'arrêter. Disque illisible : on s'arrête (prudence). */
    public function doitArreter(): bool
    {
        $utilise = $this->pourcentUtilise();

        return $utilise === null || $utilise > self::SEUIL_POURCENT;
    }
}

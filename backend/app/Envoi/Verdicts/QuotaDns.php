<?php

namespace App\Envoi\Verdicts;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Borne les résolutions réseau : par passage (cette instance) et par jour (Cache, tous passages confondus).
 * FERMÉ : si le cache est indisponible, plus aucune résolution n'est accordée.
 */
final class QuotaDns
{
    private int $accordesCePassage = 0;

    public function __construct(private readonly int $maxParPassage = 200, private readonly int $maxParJour = 2000) {}

    /**
     * @param  list<string>  $domaines
     * @return list<string> ceux qu'on a le droit de résoudre maintenant
     */
    public function accorder(array $domaines, DateTimeImmutable $maintenant): array
    {
        $demande = min(count($domaines), max(0, $this->maxParPassage - $this->accordesCePassage));
        if ($demande <= 0) {
            return [];
        }
        try {
            $cle = 'envoi:verdicts:dns:' . $maintenant->format('Y-m-d');
            Cache::add($cle, 0, 172800);
            $total = Cache::increment($cle, $demande);
            if (! is_int($total)) {
                return [];
            }
            $accorde = max(0, $demande - max(0, $total - $this->maxParJour));
        } catch (Throwable) {
            return [];
        }
        $this->accordesCePassage += $accorde;

        return array_slice($domaines, 0, $accorde);
    }
}

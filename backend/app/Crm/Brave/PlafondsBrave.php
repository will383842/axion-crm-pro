<?php

namespace App\Crm\Brave;

/**
 * LA RÈGLE DU QUOTA BRAVE — une seule, pour la base et pour la mémoire.
 *
 * Une requête est permise si l'usage est sous son sous-quota ET si le total
 * du mois (tous usages) est sous le plafond global. Un usage inconnu a un
 * sous-quota nul : il n'envoie rien.
 */
abstract class PlafondsBrave implements QuotaBrave
{
    public const PLAFOND_GLOBAL_PAR_DEFAUT = 900;

    /** @var array<string, int> */
    public const SOUS_QUOTAS_PAR_DEFAUT = [
        self::FEDERATIONS => 900,
        self::ENRICHISSEMENT => 0,
    ];

    public function plafond(?string $usage = null): int
    {
        if ($usage === null) {
            return self::entier(config('crm.brave.quota_mensuel'), self::PLAFOND_GLOBAL_PAR_DEFAUT);
        }
        if (! in_array($usage, self::USAGES, true)) {
            return 0;
        }

        return self::entier(config('crm.brave.quotas.' . $usage), self::SOUS_QUOTAS_PAR_DEFAUT[$usage]);
    }

    public function restantes(string $usage): int
    {
        return max(0, min(
            $this->plafond($usage) - $this->consommees($usage),
            $this->plafond() - $this->consommees(),
        ));
    }

    /**
     * La règle, en calcul pur : `$consommeUsage` et `$consommeTotal` sont les
     * requêtes DÉJÀ réservées ce mois-ci.
     */
    protected function autorise(string $usage, int $consommeUsage, int $consommeTotal): bool
    {
        return $consommeUsage < $this->plafond($usage)
            && $consommeTotal < $this->plafond();
    }

    private static function entier(mixed $valeur, int $defaut): int
    {
        return max(0, is_numeric($valeur) ? (int) $valeur : $defaut);
    }
}

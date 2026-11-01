<?php

namespace Tests\Feature\NonRegression;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * OUTIL D’INSTANTANÉ DU FILET DE NON-RÉGRESSION (R0).
 *
 * Un instantané est une « photo de référence » prise une fois sur la version
 * actuelle du CRM et rangée dans `instantanes/<nom>.json`. Le test compare la
 * valeur mesurée à la photo : tout écart fait rougir.
 *
 * RÉGÉNÉRER est un geste EXPLICITE, jamais un effet de bord :
 *
 *     NR_REGENERER_INSTANTANES=1 php vendor/bin/pest --group=non-regression
 *
 * Le geste est refusé en CI (variable `CI` posée) : une photo ne se met pas à
 * jour toute seule sur une machine d’intégration. Le changement apparaît alors
 * en clair dans le diff de la PR, avec la ligne « Filet modifié : pourquoi ».
 */
final class Instantane
{
    public const DOSSIER = __DIR__ . '/instantanes';

    public static function chemin(string $nom): string
    {
        return self::DOSSIER . '/' . $nom . '.json';
    }

    public static function regeneration(): bool
    {
        return getenv('NR_REGENERER_INSTANTANES') === '1';
    }

    /** Encode de façon stable : mêmes octets pour la même valeur. */
    public static function encoder(mixed $valeur): string
    {
        return json_encode(
            $valeur,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * Compare `$valeur` à la photo `$nom`. Régénère la photo seulement sur
     * demande explicite (hors CI).
     */
    public static function verifier(string $nom, mixed $valeur): void
    {
        $chemin = self::chemin($nom);
        $mesure = self::encoder($valeur);

        if (self::regeneration()) {
            if (getenv('CI') !== false && getenv('CI') !== '') {
                throw new RuntimeException('Régénérer un instantané est interdit en CI : faites-le en local et commitez la photo.');
            }
            file_put_contents($chemin, $mesure);
        }

        Assert::assertFileExists(
            $chemin,
            "Instantané « {$nom} » absent. Le créer volontairement : NR_REGENERER_INSTANTANES=1 php vendor/bin/pest --group=non-regression",
        );

        Assert::assertSame(
            (string) file_get_contents($chemin),
            $mesure,
            "L’instantané « {$nom} » ne correspond plus à la réalité du CRM. Si le changement est voulu, régénérez la photo DANS LA MÊME PR "
            . 'et écrivez « Filet modifié : pourquoi » dans sa description (NR_REGENERER_INSTANTANES=1).',
        );
    }
}

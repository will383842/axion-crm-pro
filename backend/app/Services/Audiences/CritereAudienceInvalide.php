<?php

namespace App\Services\Audiences;

/**
 * Un critère d'audience mal formé — REFUSÉ, et non effacé (constat D26-001, S1).
 *
 * ── Pourquoi une exception, et pas un `return` ───────────────────────────────
 *
 * `AudienceBuilderService::applyCondition()` faisait `return;` quand le champ
 * ou l'opérateur n'était pas dans sa liste blanche. La condition disparaissait
 * alors de la requête, et il ne restait que `where workspace_id = ?` :
 * l'audience devenait LE WORKSPACE ENTIER.
 *
 * C'est le pire mode de panne d'un ciblage, parce qu'il ne ressemble pas à une
 * panne. On n'obtient pas d'erreur, on obtient un nombre — et l'écran de
 * prévisualisation présente ce nombre comme le résultat du ciblage demandé. On
 * croit viser 200 personnes, on en vise 1 319 567. Après l'envoi il n'y a plus
 * de rattrapage : ni les plaintes CNIL, ni la réputation d'expédition.
 *
 * Le mauvais réflexe serait de « tolérer » pour ne pas casser les audiences
 * existantes. Une audience dont les critères ne se compilent pas N'A PAS de
 * contenu défini : la refuser est la seule réponse qui ne mente pas sur ce
 * qu'elle contient.
 *
 * ── Étend `InvalidArgumentException` à dessein ───────────────────────────────
 *
 * Les appelants (`AudiencesController::preview` / `refresh`,
 * `RefreshAudienceChunkJob`) attrapent déjà `\Throwable` et échouent en le
 * disant. Hériter d'une exception standard laisse ce filet en place ; le type
 * dédié permet de la traiter en 422 plutôt qu'en 500 quand quelqu'un s'en
 * donnera la peine.
 */
final class CritereAudienceInvalide extends \InvalidArgumentException
{
    /**
     * @param  string  $motif  ce qui est refusé, en clair, avec la valeur fautive
     */
    public static function parce(string $motif): self
    {
        return new self("Critere d'audience invalide : {$motif}. Une audience dont les criteres ne se compilent pas viserait le workspace entier ; elle est refusee.");
    }
}

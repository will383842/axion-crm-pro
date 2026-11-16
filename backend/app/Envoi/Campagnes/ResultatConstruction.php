<?php

namespace App\Envoi\Campagnes;

/** Issue d'une construction : la séquence liée, la version de la campagne après liaison, et si le travail était déjà fait (aucune écriture). */
final class ResultatConstruction
{
    public function __construct(public readonly string $sequenceId, public readonly int $versionCampagne, public readonly bool $dejaFaite) {}
}

<?php

namespace App\Envoi\Chauffe;

use DateTimeImmutable;

/** Liaison par défaut de {@see LecteurReports} : aucune source de reports branchée (dette nommée dans l'interface). */
final class AucunReportConnu implements LecteurReports
{
    public function sur1Heure(string $ws, string $fournisseur, DateTimeImmutable $maintenant): array
    {
        return ['envoyes' => 0, 'reports' => 0];
    }
}

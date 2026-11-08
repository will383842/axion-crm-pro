<?php

namespace App\Envoi\Chauffe;

use DateTimeImmutable;

/**
 * Lecture des REPORTS (réponses 4xx « réessayez plus tard ») d'un fournisseur sur la dernière heure.
 *
 * DETTE (K9 / K18) : aucun compteur de reports n'existe encore dans `envoi_chauffe_jours` (il ne porte que
 * envoyés, rebonds durs, plaintes, réponses) et le retour Kumo n'en émet pas. Tant que cette interface n'est pas
 * branchée sur une vraie source, {@see AucunReportConnu} répond « rien » et le frein ne se déclenche jamais :
 * il est testé en isolation, pas encore actif en production.
 */
interface LecteurReports
{
    /** @return array{envoyes: int, reports: int} envois tentés et reports vers ce fournisseur sur [maintenant − 1 h, maintenant] */
    public function sur1Heure(string $ws, string $fournisseur, DateTimeImmutable $maintenant): array;
}

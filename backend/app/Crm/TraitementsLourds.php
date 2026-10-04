<?php

namespace App\Crm;

use App\Crm\Opco\FenetreOpco;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * LES TRAITEMENTS LOURDS DU CRM — fenêtre et verrou communs.
 *
 * FENÊTRE (décision du 04/10/2026, #324) : TOUS LES JOURS, dimanche et lundi
 * compris, de 08:00 à 19:00 heure de Paris. La règle est celle de
 * `FenetreOpco` (une seule définition) ; `finDeFenetre()` donne l'heure
 * d'arrêt PROPRE d'un passage commencé dans la fenêtre.
 *
 * VERROU « UN SEUL TRAITEMENT LOURD À LA FOIS » : verrou consultatif Postgres
 * de SESSION (`pg_try_advisory_lock`) sur `VERROU`, plus le verrou propre de
 * `crm:entreprises:verifier-sites` de l'espace : tant que l'un est tenu,
 * l'autre traitement refuse de partir. Un processus qui meurt rend ses
 * verrous avec sa connexion.
 */
final class TraitementsLourds
{
    public const FUSEAU = 'Europe/Paris';

    /** Le verrou partagé des traitements lourds. */
    public const VERROU = 'crm:traitement-lourd';

    /** L'heure (Paris) de fin de fenêtre. */
    public const HEURE_FIN = 19;

    /** Le motif du refus, ou null si l'instant est dans la fenêtre. */
    public static function refusFenetre(CarbonInterface $instant): ?string
    {
        return FenetreOpco::refus($instant);
    }

    /** L'instant (Paris) où un passage commencé à `$instant` doit s'arrêter. */
    public static function finDeFenetre(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::FUSEAU)->setTime(self::HEURE_FIN, 0);
    }

    /**
     * Prend les verrous (le commun et celui de la vérification des sites de
     * l'espace). Rend la liste des verrous pris, ou null — et rien n'est
     * gardé — si un autre traitement lourd tourne.
     *
     * @return ?list<string>
     */
    public static function verrouiller(string $workspaceId): ?array
    {
        $pris = [];
        foreach ([self::VERROU, 'crm:entreprises:verifier-sites:' . $workspaceId] as $verrou) {
            if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS ok', [$verrou])->ok) {
                self::liberer($pris);

                return null;
            }
            $pris[] = $verrou;
        }

        return $pris;
    }

    /** @param  list<string>  $verrous */
    public static function liberer(array $verrous): void
    {
        foreach (array_reverse($verrous) as $verrou) {
            DB::select('SELECT pg_advisory_unlock(hashtext(?))', [$verrou]);
        }
    }
}

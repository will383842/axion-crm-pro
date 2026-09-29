<?php

namespace App\Crm\Emails;

use App\Crm\Emails\Dns\ResultatDns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * LE RÉSULTAT DNS, PAR DOMAINE, DATÉ — table `email_domaines`.
 *
 * On ne résout jamais deux fois le même domaine : ~1,5 M d'adresses tiennent
 * dans ~200 000 domaines, et un domaine vérifié il y a moins de N jours
 * (`--revalider-apres`, 30 par défaut) est repris tel quel. Au-delà, il est
 * redemandé au DNS, et sa nouvelle date se reporte sur les adresses.
 *
 * Un `indetermine` (délai, SERVFAIL…) n'est JAMAIS enregistré : ce n'est pas
 * une réponse, et il ne doit pas écraser la dernière réponse connue. Il sera
 * redemandé au prochain passage.
 *
 * Table GLOBALE (sans `workspace_id`), comme `email_validations` : un domaine
 * reçoit ou non du courrier quel que soit l'espace qui le cite. Elle ne porte
 * AUCUNE adresse, seulement des noms de domaine.
 */
final class CacheDomaines
{
    /**
     * Les domaines résolus il y a moins de `$jours` jours.
     *
     * @param  list<string>  $domaines
     * @return array<string, array{resultat: ResultatDns, resolu_le: CarbonImmutable}>
     */
    public static function fraiches(array $domaines, int $jours): array
    {
        if ($domaines === []) {
            return [];
        }
        $frais = [];
        foreach (array_chunk($domaines, 1000) as $paquet) {
            $lignes = DB::select(
                'SELECT d.domaine, d.verdict, d.mx, d.resolu_le
                 FROM email_domaines d
                 WHERE d.domaine IN (' . implode(', ', array_fill(0, count($paquet), '?')) . ')
                   AND d.verdict <> ?
                   AND d.resolu_le >= now() - make_interval(days => ?)',
                array_merge($paquet, [ResultatDns::INDETERMINE, $jours]),
            );
            foreach ($lignes as $l) {
                if (! $l instanceof \stdClass) {
                    continue;
                }
                $frais[(string) $l->domaine] = [
                    'resultat' => new ResultatDns((string) $l->verdict, is_string($l->mx) ? $l->mx : null),
                    // Dans le fuseau de l'application : la DATE écrite sur les
                    // fiches doit être la même qu'à la résolution.
                    'resolu_le' => CarbonImmutable::parse((string) $l->resolu_le)->setTimezone((string) config('app.timezone', 'UTC')),
                ];
            }
        }

        return $frais;
    }

    /**
     * Enregistre les réponses (jamais les `indetermine`).
     *
     * @param  array<string, ResultatDns>  $resultats
     */
    public static function enregistrer(array $resultats, string $resolveur, CarbonImmutable $le): int
    {
        $lignes = [];
        foreach ($resultats as $domaine => $r) {
            if ($r->verdict === ResultatDns::INDETERMINE) {
                continue;
            }
            $lignes[] = [(string) $domaine, $r->verdict, $r->mx, $resolveur, $le->toIso8601String()];
        }
        $n = 0;
        foreach (array_chunk($lignes, 1000) as $paquet) {
            $liaisons = [];
            foreach ($paquet as $l) {
                array_push($liaisons, ...$l);
            }
            $n += DB::affectingStatement(
                'INSERT INTO email_domaines (domaine, verdict, mx, resolveur, resolu_le)
                 VALUES ' . implode(', ', array_fill(0, count($paquet), '(?, ?, ?, ?, ?::timestamptz)')) . '
                 ON CONFLICT (domaine) DO UPDATE
                 SET verdict = EXCLUDED.verdict, mx = EXCLUDED.mx,
                     resolveur = EXCLUDED.resolveur, resolu_le = EXCLUDED.resolu_le',
                $liaisons,
            );
        }

        return $n;
    }
}

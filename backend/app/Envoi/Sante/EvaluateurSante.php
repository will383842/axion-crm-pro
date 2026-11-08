<?php

namespace App\Envoi\Sante;

use DateTimeImmutable;

/**
 * Compare les mesures aux seuils chiffrés (`config('envoi.sante')`). Pure : aucun accès à la base ni au réseau.
 * Sens des seuils : file et horloge = STRICTEMENT au-delà ; disque et mémoire = STRICTEMENT en deçà ;
 * certificat = jours restants inférieurs ou égaux (30 j attention, 14 j attention renforcée, 7 j critique).
 */
final class EvaluateurSante
{
    /**
     * @param  array<string, mixed>  $seuils
     * @return list<Constat>
     */
    public static function evaluer(MesuresSante $m, DateTimeImmutable $maintenant, array $seuils): array
    {
        $c = [];
        $ajoute = static function (string $code, ?string $gravite, string $detail, ?int $palier = null) use (&$c): void {
            if ($gravite !== null) {
                $c[] = new Constat($code, $gravite, $detail, $palier);
            }
        };
        $absent = static function (string $code) use (&$c): void {
            $c[] = new Constat('mesure_absente_' . $code, Constat::ATTENTION, 'mesure « ' . $code . ' » absente de la réponse du serveur');
        };

        $m->ageFileSecondes === null ? $absent('file_age')
            : $ajoute('file_age', self::palier($m->ageFileSecondes, $seuils['file_age_s'], fn ($v, $s) => $v > $s), 'plus ancien message en file : ' . $m->ageFileSecondes . ' s');
        $m->disqueLibrePct === null ? $absent('disque')
            : $ajoute('disque', self::palier($m->disqueLibrePct, $seuils['disque_libre_pct'], fn ($v, $s) => $v < $s), 'disque libre : ' . $m->disqueLibrePct . ' %');
        $m->memoireLibrePct === null ? $absent('memoire')
            : $ajoute('memoire', self::palier($m->memoireLibrePct, $seuils['memoire_libre_pct'], fn ($v, $s) => $v < $s), 'mémoire libre : ' . $m->memoireLibrePct . ' %');
        if ($m->certificatJours === null) {
            $absent('certificat');
        } else {
            // ADR K0 : échéances à 30, 14 et 7 jours. 14 jours = encore « attention », mais palier 2 : une nouvelle alerte repart.
            $seuil = $seuils['certificat_jours'];
            $jours = $m->certificatJours;
            $gravite = self::palier($jours, $seuil, fn ($v, $s) => $v <= $s);
            $palier = $gravite === Constat::CRITIQUE ? 3 : (isset($seuil['renforce']) && $jours <= $seuil['renforce'] ? 2 : 1);
            $ajoute('certificat', $gravite, 'certificat : ' . $jours . ' jour(s) restant(s)', $palier);
        }
        if ($m->tunnelOk === null) {
            $absent('tunnel');
        } elseif (! $m->tunnelOk) {
            $ajoute('tunnel', Constat::ATTENTION, 'tunnel d’administration coupé');
        }
        if ($m->ntpSynchro === null) {
            $absent('ntp');
        } elseif (! $m->ntpSynchro) {
            $ajoute('ntp', Constat::ATTENTION, 'horloge du serveur non synchronisée (NTP)');
        }
        if ($m->horlogeUnix === null) {
            $absent('horloge');
        } else {
            $ecart = abs($m->horlogeUnix - $maintenant->getTimestamp());
            $ajoute('horloge', self::palier($ecart, $seuils['horloge_ecart_s'], fn ($v, $s) => $v > $s), 'écart d’horloge avec le CRM : ' . $ecart . ' s');
        }

        return $c;
    }

    /**
     * @param  array{attention: int|float, critique: int|float}  $seuil
     * @param  callable(int|float, int|float): bool  $franchi
     */
    private static function palier(int|float $valeur, array $seuil, callable $franchi): ?string
    {
        if ($franchi($valeur, $seuil['critique'])) {
            return Constat::CRITIQUE;
        }

        return $franchi($valeur, $seuil['attention']) ? Constat::ATTENTION : null;
    }
}

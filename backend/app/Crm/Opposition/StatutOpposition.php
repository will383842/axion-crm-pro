<?php

namespace App\Crm\Opposition;

use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Support\ListeSuppression;
use Illuminate\Support\Facades\DB;

/**
 * G4-API (D14) — « cette adresse est-elle opposée ? », LECTURE SEULE, par LOTS.
 *
 * Sortie par ligne : `['opposee' => bool, 'motifs' => list<'stop'|'art21'|'liste_refus'>]`. Jamais d'empreinte, de date, de
 * contenu de registre ni d'adresse.
 *
 * Sources (une requête chacune pour tout le lot, jamais une par ligne) :
 *  - `envoi_oppositions` (STOP : page ou un clic) de l'espace ............................ stop
 *  - `opt_out` (portée business) : raison `gdpr_art21` ........................................ art21 ; autre raison ... stop
 *  - `rgpd_requests` de l'espace, type `opposition`, ni refusée ni expirée (art. 21) ....... art21
 *  - `email_suppressions` (portée business) ................................................ liste_refus
 *  - `envoi_oppositions_registre` de l'espace : adresse, domaine (et parents), SIREN ....... liste_refus
 *
 * Aucune écriture, aucun événement vers le site (anti-boucle : rien pour `site-sync:*`, `gdpr_erasure_bisystem`, ni
 * `metadata.origin=site-sync-gdpr`). Une opposition met à l'écart, elle n'efface rien.
 */
final class StatutOpposition
{
    private const SANS = ['opposee' => false, 'motifs' => []];

    /**
     * @param  array<int|string, array{email: ?string, siren: ?string}>  $lignes  clé libre de l'appelant (id de ligne)
     * @return array<int|string, array{opposee: bool, motifs: list<string>}> mêmes clés
     */
    public function pourLignes(string $workspaceId, array $lignes): array
    {
        $sortie = [];
        $empreintes = []; // empreinte d'adresse => true
        $adresses = [];   // adresse normalisée => true
        $registre = [];   // empreinte (adresse, domaines, SIREN) => true
        $cles = [];       // clé => ['h' => empreinte adresse|null, 'cles' => list<[portee, empreinte]>, 'a' => adresse|null]

        foreach ($lignes as $cle => $l) {
            $sortie[$cle] = self::SANS;
            $adresse = is_string($l['email'] ?? null) ? mb_strtolower(trim($l['email'])) : '';
            $siren = is_string($l['siren'] ?? null) ? (string) preg_replace('/\s+/', '', $l['siren']) : '';
            $registreLigne = [];
            $h = null;
            if ($adresse !== '') {
                $h = ListeSuppression::empreinte($adresse);
                $empreintes[$h] = true;
                $adresses[$adresse] = true;
                $registreLigne[] = ['adresse', $h];
                foreach (self::domainesEtParents($adresse) as $d) {
                    $registreLigne[] = ['domaine', RegistreOppositionManuel::empreinte($d)];
                }
            }
            if (preg_match('/^\d{9}$/', $siren) === 1) {
                $registreLigne[] = ['organisation', RegistreOppositionManuel::empreinte($siren)];
            }
            foreach ($registreLigne as [, $e]) {
                $registre[$e] = true;
            }
            $cles[$cle] = ['h' => $h, 'a' => $adresse === '' ? null : $adresse, 'registre' => $registreLigne];
        }
        if ($cles === []) {
            return $sortie;
        }

        $motifs = []; // clé => [motif => true]
        $hashs = array_keys($empreintes);
        $parHash = []; // empreinte => motifs
        $parAdresse = []; // adresse => motifs

        if ($hashs !== []) {
            foreach (DB::table('envoi_oppositions')->where('workspace_id', $workspaceId)->whereIn('empreinte_adresse', $hashs)->pluck('empreinte_adresse') as $h) {
                $parHash[trim((string) $h)]['stop'] = true;
            }
            foreach (DB::table('opt_out')->where('scope', 'business')->whereIn('email_hash', $hashs)->get(['email_hash', 'reason']) as $r) {
                $parHash[(string) $r->email_hash][$r->reason === 'gdpr_art21' ? 'art21' : 'stop'] = true;
            }
            foreach (DB::table('email_suppressions')->where('scope', 'business')->whereIn('email_hash', $hashs)->pluck('email_hash') as $h) {
                $parHash[(string) $h]['liste_refus'] = true;
            }
        }
        if ($adresses !== []) {
            $demandes = DB::table('rgpd_requests')->where('workspace_id', $workspaceId)->where('type', 'opposition')
                ->whereNotIn('status', ['rejected', 'expired'])
                ->whereIn('subject_email', array_map('strval', array_keys($adresses)))->pluck('subject_email');
            foreach ($demandes as $a) {
                $parAdresse[mb_strtolower(trim((string) $a))]['art21'] = true;
            }
        }
        $presents = [];
        if ($registre !== []) {
            foreach (DB::table('envoi_oppositions_registre')->where('workspace_id', $workspaceId)
                ->whereIn('empreinte', array_keys($registre))->get(['portee', 'empreinte']) as $r) {
                $presents[$r->portee . '|' . $r->empreinte] = true;
            }
        }

        foreach ($cles as $cle => $c) {
            $m = ($c['h'] === null ? [] : ($parHash[$c['h']] ?? [])) + ($c['a'] === null ? [] : ($parAdresse[$c['a']] ?? []));
            foreach ($c['registre'] as [$portee, $e]) {
                if (isset($presents[$portee . '|' . $e])) {
                    $m['liste_refus'] = true;
                }
            }
            $liste = array_values(array_filter(['stop', 'art21', 'liste_refus'], static fn (string $x): bool => isset($m[$x])));
            $sortie[$cle] = ['opposee' => $liste !== [], 'motifs' => $liste];
        }

        return $sortie;
    }

    /** @return list<string> le domaine et ses parents (sans le TLD seul) */
    private static function domainesEtParents(string $adresse): array
    {
        $pos = strrpos($adresse, '@');
        $libelles = explode('.', $pos === false ? '' : substr($adresse, $pos + 1));
        $liste = [];
        while (count($libelles) >= 2 && $libelles[0] !== '') {
            $liste[] = implode('.', $libelles);
            array_shift($libelles);
        }

        return $liste;
    }
}

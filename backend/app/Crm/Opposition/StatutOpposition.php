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
 *  - `personnes.opposition_at` non nul DE L'ESPACE (mise à l'écart, `MiseAEcart`, sans ligne `opt_out`) ... art21
 *  - `opt_out` portée `lettre` (désabonnement de la lettre : bloque aussi tout courriel d'envoi) .......... stop
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
        $empreintes = []; // empreinte d'adresse (toutes variantes) => true
        $adresses = [];   // adresse normalisée (toutes variantes) => true
        $registre = [];   // empreinte (adresse, domaines, SIREN) => true
        $cles = [];       // clé => ['h' => list<empreinte>, 'a' => list<adresse>, 'registre' => list<[portee, empreinte]>]

        foreach ($lignes as $cle => $l) {
            $sortie[$cle] = self::SANS;
            $brute = is_string($l['email'] ?? null) ? $l['email'] : '';
            $siren = is_string($l['siren'] ?? null) ? (string) preg_replace('/\s+/', '', $l['siren']) : '';
            $registreLigne = [];
            $h = [];
            $a = [];
            if (mb_strtolower(trim($brute)) !== '') {
                // M5 : l'adresse sous TOUTES ses formes (point final, alias +, espaces Unicode, IDN). `ListeSuppression::empreinte`
                // reste la seule source d'empreinte, appelée sur chaque forme.
                foreach (FormesAdresse::variantes($brute) as $forme) {
                    $e = ListeSuppression::empreinte($forme);
                    $h[$e] = $e;
                    $a[$forme] = $forme;
                    $empreintes[$e] = true;
                    $adresses[$forme] = true;
                    $registreLigne[] = ['adresse', $e];
                }
                foreach (FormesAdresse::domaines($brute) as $domaine) {
                    foreach (self::domainesEtParents('x@' . $domaine) as $d) {
                        $registreLigne[] = ['domaine', RegistreOppositionManuel::empreinte($d)];
                    }
                }
            }
            if (preg_match('/^\d{9}$/', $siren) === 1) {
                $registreLigne[] = ['organisation', RegistreOppositionManuel::empreinte($siren)];
            }
            foreach ($registreLigne as [, $e]) {
                $registre[$e] = true;
            }
            $cles[$cle] = ['h' => array_values($h), 'a' => array_values($a), 'registre' => $registreLigne];
        }
        if ($cles === []) {
            return $sortie;
        }

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
            // P0 : désabonnement de la seule lettre. Même table que ci-dessus, autre portée ; MÊME absence de filtre d'espace
            // (la table n'a pas de `workspace_id`). Index (scope, email_hash).
            foreach (DB::table('opt_out')->where('scope', 'lettre')->whereIn('email_hash', $hashs)->pluck('email_hash') as $h) {
                $parHash[(string) $h]['stop'] = true;
            }
            // P0 : personne mise à l'écart DE L'ESPACE (`MiseAEcart` ne pose aucune ligne `opt_out`). Index
            // (workspace_id, email_hash). Aucune exception attrapée : une erreur de lecture fait échouer l'appelant.
            foreach (DB::table('personnes')->where('workspace_id', $workspaceId)->whereNotNull('opposition_at')->whereIn('email_hash', $hashs)->pluck('email_hash') as $h) {
                $parHash[trim((string) $h)]['art21'] = true;
            }
            foreach (DB::table('email_suppressions')->where('scope', 'business')->whereIn('email_hash', $hashs)->pluck('email_hash') as $h) {
                $parHash[(string) $h]['liste_refus'] = true;
            }
        }
        if ($adresses !== []) {
            $demandes = DB::table('rgpd_requests')->where('workspace_id', $workspaceId)->where('type', 'opposition')
                ->whereNotIn('status', ['rejected', 'expired'])
                ->whereIn('subject_email', array_map('strval', array_keys($adresses)))->pluck('subject_email');
            foreach ($demandes as $adresse) {
                $parAdresse[mb_strtolower(trim((string) $adresse))]['art21'] = true;
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
            $m = [];
            foreach ($c['h'] as $e) {
                $m += $parHash[$e] ?? [];
            }
            foreach ($c['a'] as $forme) {
                $m += $parAdresse[$forme] ?? [];
            }
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
        $libelles = explode('.', rtrim($pos === false ? '' : substr($adresse, $pos + 1), '.'));
        $liste = [];
        while (count($libelles) >= 2 && $libelles[0] !== '') {
            $liste[] = implode('.', $libelles);
            array_shift($libelles);
        }

        return $liste;
    }
}

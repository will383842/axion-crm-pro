<?php

namespace App\Crm\Pharmacies;

use App\Crm\FichesProtegees;
use App\Crm\Taxonomy;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use Throwable;

/**
 * E-MAILS, SITES ET TÉLÉPHONES DES OFFICINES DEPUIS OPENSTREETMAP — AJOUT SEUL (dépôt public : totaux uniquement).
 *
 * Source : points `amenity=pharmacy` d'OpenStreetMap (licence ODbL), réduits par le workflow à un CSV
 * `lat,lon,cp,telephone,email,site`. AUCUNE adresse n'est devinée ni construite : seule une valeur
 * saisie telle quelle dans OSM peut être écrite.
 *
 *  - fiches : NAF 47.73Z / 4773Z du workspace (index workspace_id, naf), ouvertes, hors corbeille,
 *    hors `non_officine` / `ecartee_motif`, hors protégées, hors personnes physiques ; jamais de requête
 *    par SIRET ou téléphone seul ;
 *  - rapprochement par DEUX preuves possibles : (a) même téléphone normalisé, OU (b) distance GPS
 *    inférieure à 80 m ET même code postal. Zéro ou plusieurs fiches candidates (ou une fiche visée par
 *    plusieurs points) : rien n'est écrit, le point est compté « ambigu » ;
 *  - écriture sur champ vide ou NULL seulement (condition portée par l'UPDATE), jamais d'écrasement ;
 *    origine `osm` dans `field_origins` ; e-mail non vérifié (`metadata.email_source`, `email_statut`) ;
 *    une origine protégée (déclarée, manuelle) bloque l'écriture du champ ;
 *  - opposition (opt_out, liste de suppression) sur le numéro ou l'e-mail à ajouter, ou déjà présents :
 *    fiche entière écartée ;
 *  - aucune suppression, aucun envoi ; seuls des totaux sont retournés.
 */
final class ContactsOsm
{
    public const SOURCE = 'osm';

    /** Distance maximale (mètres) pour la preuve GPS. */
    public const DISTANCE_MAX_M = 80.0;

    private const COLONNES = ['lat', 'lon', 'cp', 'telephone', 'email', 'site'];

    /**
     * @return array<string, int> bilan chiffré (aucun nom, aucune adresse, aucun numéro)
     */
    public function executer(string $workspaceId, string $fichier, bool $appliquer, int $paquet = 500): array
    {
        $bilan = [
            'points' => 0, 'rapproches' => 0, 'ambigus' => 0, 'non_rapproches' => 0, 'opposition' => 0,
            'emails_a_ajouter' => 0, 'emails_ajoutes' => 0, 'sites_a_ajouter' => 0, 'sites_ajoutes' => 0,
            'telephones_a_ajouter' => 0, 'telephones_ajoutes' => 0, 'lots_en_echec' => 0,
        ];

        $points = [];
        foreach ($this->lire($fichier) as $p) {
            $bilan['points']++;
            $points[] = $p;
        }

        $fiches = WorkspaceContext::run($workspaceId, fn (): array => $this->chargerFiches($workspaceId));
        $parTel = [];
        $parCp = [];
        foreach ($fiches as $id => $f) {
            if ($f['tel'] !== null) {
                $parTel[$f['tel']][] = $id;
            }
            if ($f['cp'] !== '' && $f['lat'] !== null && $f['lon'] !== null) {
                $parCp[$f['cp']][] = $id;
            }
        }

        // Fiche → points rapprochés ; une fiche visée par plusieurs points n'est servie par aucun.
        /** @var array<int, list<int>> $visees */
        $visees = [];
        foreach ($points as $i => $p) {
            $candidates = [];
            foreach ($p['tels'] as $t) {
                foreach ($parTel[$t] ?? [] as $id) {
                    $candidates[$id] = true;
                }
            }
            if ($p['cp'] !== '' && $p['lat'] !== null && $p['lon'] !== null) {
                foreach ($parCp[$p['cp']] ?? [] as $id) {
                    $f = $fiches[$id];
                    if ($f['lat'] !== null && $f['lon'] !== null
                        && self::distance($p['lat'], $p['lon'], $f['lat'], $f['lon']) < self::DISTANCE_MAX_M) {
                        $candidates[$id] = true;
                    }
                }
            }
            if (count($candidates) === 0) {
                $bilan['non_rapproches']++;
            } elseif (count($candidates) > 1) {
                $bilan['ambigus']++;
            } else {
                $visees[(int) array_key_first($candidates)][] = $i;
            }
        }

        /** @var array<int, array<string, mixed>> $plans */
        $plans = [];
        foreach ($visees as $id => $indices) {
            if (count($indices) > 1) {
                $bilan['ambigus'] += count($indices);

                continue;
            }
            $bilan['rapproches']++;
            $plan = $this->planifier($fiches[$id], $points[$indices[0]]);
            if ($plan !== []) {
                $plans[$id] = $plan;
            }
        }

        foreach (array_chunk($plans, max(1, $paquet), true) as $lot) {
            try {
                $this->traiter($workspaceId, $lot, $fiches, $appliquer, $bilan);
            } catch (Throwable) {
                // Jamais le message : une erreur de base peut contenir du SQL avec ses valeurs.
                $bilan['lots_en_echec']++;
            }
        }

        return $bilan;
    }

    /**
     * @return array<int, array{tel: ?string, phone: string, email: string, website: string, cp: string, lat: ?float, lon: ?float, origines: array<string, mixed>, meta: array<string, mixed>, methode: string}>
     */
    private function chargerFiches(string $workspaceId): array
    {
        $fiches = [];
        $q = SelectionEnrichissement::ouvertes($workspaceId);
        $q->select(['id', 'phone', 'email_generic', 'website', 'website_method', 'postcode', 'lat', 'lon', 'legal_form', 'metadata', 'field_origins'])
            ->chunkById(2000, function ($lignes) use (&$fiches): void {
            foreach ($lignes as $c) {
                $meta = json_decode((string) $c->metadata, true);
                $meta = is_array($meta) ? $meta : [];
                if (str_starts_with(trim((string) $c->legal_form), '1') || ($meta['personne_physique'] ?? false) === true) {
                    continue;
                }
                $origines = json_decode((string) $c->field_origins, true);
                $phone = trim((string) $c->phone);
                $fiches[(int) $c->id] = [
                    'tel' => $phone === '' ? null : TelephonesFiness::normaliser($phone),
                    'phone' => $phone,
                    'email' => trim((string) $c->email_generic),
                    'website' => trim((string) $c->website),
                    'cp' => preg_replace('/\s+/', '', (string) $c->postcode) ?? '',
                    'lat' => $c->lat === null ? null : (float) $c->lat,
                    'lon' => $c->lon === null ? null : (float) $c->lon,
                    'origines' => is_array($origines) ? $origines : [],
                    'meta' => $meta,
                    'methode' => trim((string) $c->website_method),
                ];
            }
        }, 'id');

        return $fiches;
    }

    /**
     * Ce que le point peut ajouter (champs vides seulement, origine non protégée).
     *
     * @param  array{tel: ?string, phone: string, email: string, website: string, cp: string, lat: ?float, lon: ?float, origines: array<string, mixed>, meta: array<string, mixed>, methode: string}  $f
     * @param  array{lat: ?float, lon: ?float, cp: string, tels: list<string>, email: string, site: string}  $p
     * @return array<string, mixed>
     */
    private function planifier(array $f, array $p): array
    {
        $plan = [];
        $libre = static fn (string $col, string $valeur): bool => $valeur === ''
            && ! in_array($f['origines'][$col] ?? null, Taxonomy::FIELD_ORIGINS_PROTEGEES, true);
        if ($p['email'] !== '' && $libre('email_generic', $f['email'])) {
            $plan['email_generic'] = $p['email'];
        }
        if ($p['site'] !== '' && $libre('website', $f['website'])) {
            $plan['website'] = $p['site'];
        }
        if ($p['tels'] !== [] && $libre('phone', $f['phone'])) {
            $plan['phone'] = $p['tels'][0];
        }

        return $plan;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lot  company_id → ajouts
     * @param  array<int, array{tel: ?string, phone: string, email: string, website: string, cp: string, lat: ?float, lon: ?float, origines: array<string, mixed>, meta: array<string, mixed>, methode: string}>  $fiches
     * @param  array<string, int>  $bilan
     */
    private function traiter(string $workspaceId, array $lot, array $fiches, bool $appliquer, array &$bilan): void
    {
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $lot, $fiches, $appliquer, &$bilan): void {
            $variantes = [];
            $emails = [];
            foreach ($lot as $id => $ajouts) {
                foreach ([$ajouts['phone'] ?? null, $fiches[$id]['phone']] as $tel) {
                    if (is_string($tel) && $tel !== '') {
                        array_push($variantes, ...ListeSuppression::variantesTelephone($tel));
                    }
                }
                foreach ([$ajouts['email_generic'] ?? null, $fiches[$id]['email']] as $mail) {
                    if (is_string($mail) && $mail !== '') {
                        $emails[] = $mail;
                    }
                }
            }
            $liste = new TelephonesFiness;
            $opposes = $liste->opposes(array_values(array_unique($variantes)));
            $emailsOpposes = $liste->emailsOpposes(array_values(array_unique($emails)));

            foreach ($lot as $id => $ajouts) {
                $f = $fiches[$id];
                $bloque = false;
                foreach ([$ajouts['phone'] ?? null, $f['phone']] as $tel) {
                    if (is_string($tel) && $tel !== '' && array_intersect(ListeSuppression::variantesTelephone($tel), $opposes) !== []) {
                        $bloque = true;
                    }
                }
                foreach ([$ajouts['email_generic'] ?? null, $f['email']] as $mail) {
                    if (is_string($mail) && $mail !== '' && isset($emailsOpposes[mb_strtolower(trim($mail))])) {
                        $bloque = true;
                    }
                }
                if ($bloque) {
                    $bilan['opposition']++;

                    continue;
                }
                foreach (['email_generic' => 'emails', 'website' => 'sites', 'phone' => 'telephones'] as $col => $nom) {
                    if (! isset($ajouts[$col])) {
                        continue;
                    }
                    $bilan[$nom . '_a_ajouter']++;
                    if ($appliquer) {
                        $bilan[$nom . '_ajoutes'] += $this->ecrire($workspaceId, $id, $col, (string) $ajouts[$col]);
                    }
                }
            }
        });
    }

    /** UPDATE conditionnel (champ vide ou NULL, fiche ouverte et non protégée) : 1 si la fiche a été complétée. */
    private function ecrire(string $workspaceId, int $id, string $col, string $valeur): int
    {
        $q = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $id)
            ->whereNull('deleted_at')->whereNull('insee_ferme_le')
            ->whereIn('naf', ['47.73Z', '4773Z'])
            ->whereRaw("COALESCE(metadata->>'type_etablissement', '') <> 'non_officine'")
            ->whereRaw("NOT jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'ecartee_motif')")
            ->whereRaw(FichesProtegees::conditionSql('companies.id', null, 'companies.workspace_id'))
            ->where(fn ($w) => $w->whereNull($col)->orWhereRaw("btrim({$col}) = ''"));

        $pdo = DB::getPdo();
        $objet = static fn (string $colonne): string => "CASE WHEN jsonb_typeof({$colonne}) = 'object' THEN {$colonne} ELSE '{}'::jsonb END";
        $meta = $objet('metadata') . " || jsonb_build_object('contacts_osm', jsonb_build_object('le', "
            . $pdo->quote(now()->toDateString()) . '::text, \'champ\', ' . $pdo->quote($col) . '::text))';
        if ($col === 'email_generic') {
            $meta .= " || jsonb_build_object('email_source', 'osm', 'email_statut', 'non_verifie')";
        }
        $maj = [
            $col => $valeur,
            'field_origins' => DB::raw($objet('field_origins') . " || jsonb_build_object({$pdo->quote($col)}::text, '" . self::SOURCE . "'::text)"),
            'metadata' => DB::raw($meta),
            'updated_at' => now(),
        ];
        if ($col === 'website') {
            $maj['website_method'] = self::SOURCE;
        }

        return $q->update($maj);
    }

    /** Distance en mètres (haversine). */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** E-mail syntaxiquement valide, en minuscules, ou null. Jamais construit : tel que saisi dans OSM. */
    public static function normaliserEmail(string $brut): ?string
    {
        $e = mb_strtolower(trim((string) preg_replace('/^mailto:/i', '', trim($brut))));

        return $e !== '' && strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false ? $e : null;
    }

    /** Site http(s) avec un nom de domaine, ou null. */
    public static function normaliserSite(string $brut): ?string
    {
        $s = trim($brut);
        if ($s === '' || preg_match('/\s/', $s) === 1) {
            return null;
        }
        if (preg_match('#^https?://#i', $s) !== 1) {
            $s = 'http://' . $s;
        }
        $hote = parse_url($s, PHP_URL_HOST);

        return is_string($hote) && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $hote) === 1 && strlen($s) <= 255 ? $s : null;
    }

    /** @return \Generator<array{lat: ?float, lon: ?float, cp: string, tels: list<string>, email: string, site: string}> */
    private function lire(string $fichier): \Generator
    {
        $f = new SplFileObject($fichier, 'r');
        $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $f->setCsvControl(',', '"', '');
        $entete = null;
        foreach ($f as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }
            if ($entete === null) {
                $entete = array_map(static fn ($c): string => trim(ltrim((string) $c, "\xEF\xBB\xBF")), $row);
                $manque = array_diff(self::COLONNES, $entete);
                if ($manque !== []) {
                    throw new \InvalidArgumentException('Colonnes manquantes : ' . implode(', ', $manque));
                }

                continue;
            }
            $v = [];
            foreach (self::COLONNES as $c) {
                $i = array_search($c, $entete, true);
                $v[$c] = $i === false ? '' : trim((string) ($row[$i] ?? ''));
            }
            $tels = [];
            foreach (preg_split('/[;|]/', $v['telephone']) ?: [] as $brut) {
                $t = TelephonesFiness::normaliser($brut);
                if ($t !== null) {
                    $tels[$t] = $t;
                }
            }
            $lat = is_numeric($v['lat']) ? (float) $v['lat'] : null;
            $lon = is_numeric($v['lon']) ? (float) $v['lon'] : null;
            // L'e-mail et le site du CSV sont déjà réduits à UNE valeur par le workflow ; revalidés ici.
            yield [
                'lat' => $lat,
                'lon' => $lon,
                'cp' => preg_replace('/\s+/', '', $v['cp']) ?? '',
                'tels' => array_values($tels),
                'email' => self::normaliserEmail($v['email']) ?? '',
                'site' => self::normaliserSite($v['site']) ?? '',
            ];
        }
    }
}

<?php

namespace App\Crm\Pharmacies;

use App\Crm\Emails\QualificationEmail;
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
 *  - rapprochement sur TOUTES les fiches NAF 47.73Z / 4773Z non supprimées de l'espace (index
 *    workspace_id, naf) ; n'est écrite que la fiche ÉLIGIBLE (ouverte, hors `non_officine` /
 *    `ecartee_motif`, hors protégées, hors personnes physiques — forme juridique vide comprise). Si la
 *    candidate unique est inéligible, le point est compté « exclue » et rien n'est reporté sur une voisine ;
 *  - rapprochement par DEUX preuves possibles : (a) même téléphone normalisé, OU (b) distance GPS
 *    inférieure à 80 m ET même code postal. Zéro ou plusieurs fiches candidates (ou une fiche visée par
 *    plusieurs points, contradictoires ou ineligibles compris) : rien n'est écrit, le point est compté
 *    « ambigu ». Preuves contradictoires
 *    (téléphones sans intersection, plus de 2 km, code postal différent, coordonnées partagées) : ambigu ;
 *  - un e-mail ou un site présent sur DEUX points OSM ou plus (réseau, groupement) est ignoré et compté
 *    (`valeurs_partagees_ignorees`) ; les réseaux sociaux et annuaires ne sont pas des sites d'officine ;
 *  - écriture sur champ vide ou NULL seulement (condition portée par l'UPDATE, avec la garde d'origine et
 *    la garde personne physique), jamais d'écrasement ; origine `osm` dans `field_origins` ;
 *  - e-mail : boîtes GÉNÉRIQUES seulement (`QualificationEmail::type`), les nominatives sont ignorées et
 *    comptées. L'adresse reste NON vérifiée : aucune fiche `signals.email_generic_verification` n'est écrite,
 *    or les campagnes (`VerificationEmail::statutDe`, `EligibiliteAdresse`) n'envoient qu'une adresse dont
 *    la vérification vaut `valide` ; `metadata.email_source` / `email_statut` ne sont qu'informatifs ;
 *  - `metadata.contacts_osm` : objet colonne → date, fusionné champ par champ, avec licence ODbL et
 *    attribution aux contributeurs d'OpenStreetMap ;
 *  - opposition (opt_out, liste de suppression) sur le numéro ou l'e-mail à ajouter, ou déjà présents :
 *    fiche entière écartée ;
 *  - aucune suppression, aucun envoi ; seuls des totaux sont retournés.
 *
 * @phpstan-type Fiche array{tel: ?string, phone: string, email: string, website: string, cp: string, lat: ?float, lon: ?float, origines: array<mixed>, eligible: bool}
 */
final class ContactsOsm
{
    public const SOURCE = 'osm';

    /** Distance maximale (mètres) pour la preuve GPS. */
    public const DISTANCE_MAX_M = 80.0;

    /** Distance maximale (mètres) au-delà de laquelle un rapprochement par téléphone est contradictoire. */
    public const DISTANCE_TEL_MAX_M = 2000.0;

    public const LICENCE = 'ODbL';

    public const ATTRIBUTION = "© les contributeurs d'OpenStreetMap";

    /** Réseaux sociaux, annuaires et cartes : ce ne sont pas le site d'une officine. */
    private const HOTES_NON_OFFICINE = [
        'facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'youtube.com', 'youtu.be',
        'tiktok.com', 'pinterest.com', 'linktr.ee', 'pagesjaunes.fr', 'doctolib.fr', 'google.com', 'google.fr', 'goo.gl', 'g.page',
        'mappy.com', 'yelp.com', 'yelp.fr', 'tripadvisor.com', 'tripadvisor.fr', 'wikipedia.org',
    ];

    private const COLONNES = ['lat', 'lon', 'cp', 'telephone', 'email', 'site'];

    /**
     * @return array<string, int> bilan chiffré (aucun nom, aucune adresse, aucun numéro)
     */
    public function executer(string $workspaceId, string $fichier, bool $appliquer, int $paquet = 500): array
    {
        $bilan = [
            'points' => 0, 'rapproches' => 0, 'ambigus' => 0, 'non_rapproches' => 0, 'exclues' => 0, 'opposition' => 0,
            'emails_a_ajouter' => 0, 'emails_ajoutes' => 0, 'sites_a_ajouter' => 0, 'sites_ajoutes' => 0,
            'telephones_a_ajouter' => 0, 'telephones_ajoutes' => 0, 'emails_nominatifs_ignores' => 0, 'valeurs_partagees_ignorees' => 0, 'lots_en_echec' => 0,
        ];

        $points = [];
        foreach ($this->lire($fichier) as $p) {
            $bilan['points']++;
            // Seule une boîte générique est retenue : une adresse nominative est une donnée personnelle.
            if ($p['email'] !== '' && QualificationEmail::type($p['email']) !== 'generique') {
                $p['email'] = '';
                $bilan['emails_nominatifs_ignores']++;
            }
            $points[] = $p;
        }

        // Une valeur portée par plusieurs points OSM est celle d'un réseau, pas d'une officine.
        $partagees = ['email' => [], 'site' => []];
        foreach ($points as $p) {
            foreach (['email', 'site'] as $k) {
                if ($p[$k] !== '') {
                    $cle = mb_strtolower($p[$k]);
                    $partagees[$k][$cle] = ($partagees[$k][$cle] ?? 0) + 1;
                }
            }
        }
        foreach ($points as $i => $p) {
            foreach (['email', 'site'] as $k) {
                if ($p[$k] !== '' && $partagees[$k][mb_strtolower($p[$k])] >= 2) {
                    $points[$i][$k] = '';
                    $bilan['valeurs_partagees_ignorees']++;
                }
            }
        }

        // Rapprochement sur TOUTES les fiches 47.73Z de l'espace (éligibles ou non) : une voisine protégée,
        // fermée ou écartée ne doit jamais faire reporter la valeur sur sa voisine ouverte.
        $fiches = WorkspaceContext::run($workspaceId, fn (): array => $this->chargerFiches($workspaceId));
        $parTel = [];
        $parCp = [];
        $coordonnees = [];
        foreach ($fiches as $id => $f) {
            if ($f['tel'] !== null) {
                $parTel[$f['tel']][] = $id;
            }
            if ($f['cp'] !== '' && $f['lat'] !== null && $f['lon'] !== null) {
                $parCp[$f['cp']][] = $id;
            }
            if ($f['lat'] !== null && $f['lon'] !== null) {
                $cle = self::cleCoordonnees($f['lat'], $f['lon']);
                $coordonnees[$cle] = ($coordonnees[$cle] ?? 0) + 1;
            }
        }

        // Fiche → TOUS les points qui la visent (contradictoires et ineligibles compris) ; une fiche visée
        // par plusieurs points n'est servie par aucun.
        /** @var array<int, list<int>> $visees */
        $visees = [];
        /** @var array<int, array{tel: bool, gps: bool}> $cibles point → preuves vers sa fiche */
        $cibles = [];
        foreach ($points as $i => $p) {
            /** @var array<int, array{tel: bool, gps: bool}> $voies */
            $voies = [];
            foreach ($p['tels'] as $t) {
                foreach ($parTel[$t] ?? [] as $id) {
                    $voies[$id] ??= ['tel' => false, 'gps' => false];
                    $voies[$id]['tel'] = true;
                }
            }
            if ($p['cp'] !== '' && $p['lat'] !== null && $p['lon'] !== null) {
                foreach ($parCp[$p['cp']] ?? [] as $id) {
                    $f = $fiches[$id];
                    if ($f['lat'] !== null && $f['lon'] !== null
                        && self::distance($p['lat'], $p['lon'], $f['lat'], $f['lon']) < self::DISTANCE_MAX_M) {
                        $voies[$id] ??= ['tel' => false, 'gps' => false];
                        $voies[$id]['gps'] = true;
                    }
                }
            }
            if (count($voies) === 0) {
                $bilan['non_rapproches']++;

                continue;
            }
            if (count($voies) > 1) {
                $bilan['ambigus']++;

                continue;
            }
            $id = (int) array_key_first($voies);
            $visees[$id][] = $i;
            $cibles[$i] = $voies[$id];
        }

        /** @var array<int, array<string, string>> $plans */
        $plans = [];
        foreach ($visees as $id => $indices) {
            if (count($indices) > 1) {
                $bilan['ambigus'] += count($indices);

                continue;
            }
            $i = $indices[0];
            if ($this->contradictoire($fiches[$id], $points[$i], $cibles[$i], $coordonnees)) {
                $bilan['ambigus']++;

                continue;
            }
            if (! $fiches[$id]['eligible']) {
                $bilan['exclues']++;

                continue;
            }
            $bilan['rapproches']++;
            $plan = $this->planifier($fiches[$id], $points[$i]);
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
     * Preuves contradictoires = ambigu. Par téléphone : fiche à plus de 2 km, ou code postal différent
     * (quand les deux sont connus). Par GPS : numéros présents des deux côtés sans intersection, ou
     * coordonnées de la fiche partagées par au moins deux fiches.
     *
     * @param  Fiche  $f
     * @param  array{lat: ?float, lon: ?float, cp: string, tels: list<string>, email: string, site: string}  $p
     * @param  array{tel: bool, gps: bool}  $voie
     * @param  array<string, int>  $coordonnees  coordonnées → nombre de fiches
     */
    private function contradictoire(array $f, array $p, array $voie, array $coordonnees): bool
    {
        if ($voie['tel']) {
            if ($p['lat'] !== null && $p['lon'] !== null && $f['lat'] !== null && $f['lon'] !== null
                && self::distance($p['lat'], $p['lon'], $f['lat'], $f['lon']) > self::DISTANCE_TEL_MAX_M) {
                return true;
            }
            if ($p['cp'] !== '' && $f['cp'] !== '' && $p['cp'] !== $f['cp']) {
                return true;
            }
        }
        if ($voie['gps']) {
            if ($p['tels'] !== [] && $f['tel'] !== null && ! in_array($f['tel'], $p['tels'], true)) {
                return true;
            }
            if ($f['lat'] !== null && $f['lon'] !== null
                && ($coordonnees[self::cleCoordonnees($f['lat'], $f['lon'])] ?? 0) >= 2) {
                return true;
            }
        }

        return false;
    }

    private static function cleCoordonnees(float $lat, float $lon): string
    {
        return sprintf('%.6F,%.6F', $lat, $lon);
    }

    /**
     * Toutes les fiches NAF 47.73Z / 4773Z non supprimées de l'espace (index workspace_id, naf), chargées
     * par id croissant sans tri supplémentaire.
     *
     * @return array<int, Fiche>
     */
    private function chargerFiches(string $workspaceId): array
    {
        $fiches = [];
        $lignes = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->whereIn('naf', ['47.73Z', '4773Z'])
            ->select(['id', 'phone', 'email_generic', 'website', 'postcode', 'lat', 'lon', 'legal_form', 'metadata', 'field_origins', 'insee_ferme_le'])
            ->selectRaw('CASE WHEN ' . FichesProtegees::conditionSql('companies.id', null, 'companies.workspace_id') . ' THEN 1 ELSE 0 END AS non_protegee')
            ->lazyById(2000, 'id');
        foreach ($lignes as $c) {
            $meta = json_decode((string) $c->metadata, true);
            $meta = is_array($meta) ? $meta : [];
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
                'eligible' => $c->insee_ferme_le === null
                    && (int) $c->non_protegee === 1
                    && ($meta['type_etablissement'] ?? '') !== 'non_officine'
                    && ! array_key_exists('ecartee_motif', $meta)
                    && ! SelectionEnrichissement::estPersonnePhysique($meta, $c->legal_form),
            ];
        }

        return $fiches;
    }

    /**
     * Ce que le point peut ajouter (champs vides seulement, origine non protégée).
     *
     * @param  Fiche  $f
     * @param  array{lat: ?float, lon: ?float, cp: string, tels: list<string>, email: string, site: string}  $p
     * @return array<string, string>
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
     * @param  array<int, array<string, string>>  $lot  company_id → ajouts
     * @param  array<int, Fiche>  $fiches
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
                        $bilan[$nom . '_ajoutes'] += $this->ecrire($workspaceId, $id, $col, $ajouts[$col]);
                    }
                }
            }
        });
    }

    /** UPDATE conditionnel (champ vide ou NULL, fiche ouverte, non protégée, origine libre) : 1 si la fiche a été complétée. */
    private function ecrire(string $workspaceId, int $id, string $col, string $valeur): int
    {
        $pdo = DB::getPdo();
        $protegees = implode(', ', array_map(static fn (string $o): string => $pdo->quote($o), Taxonomy::FIELD_ORIGINS_PROTEGEES));
        $q = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $id)
            ->whereNull('deleted_at')->whereNull('insee_ferme_le')
            ->whereIn('naf', ['47.73Z', '4773Z'])
            ->whereRaw("COALESCE(metadata->>'type_etablissement', '') <> 'non_officine'")
            ->whereRaw("NOT jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'ecartee_motif')")
            ->whereRaw('NOT ' . SelectionEnrichissement::PHYSIQUE_SQL)
            ->whereRaw("COALESCE(field_origins->>{$pdo->quote($col)}, '') NOT IN ({$protegees})")
            ->whereRaw(FichesProtegees::conditionSql('companies.id', null, 'companies.workspace_id'))
            ->where(fn ($w) => $w->whereNull($col)->orWhereRaw("btrim({$col}) = ''"));

        $objet = static fn (string $colonne): string => "CASE WHEN jsonb_typeof({$colonne}) = 'object' THEN {$colonne} ELSE '{}'::jsonb END";
        // contacts_osm : fusion champ par champ (colonne → date), jamais d'écrasement de l'existant.
        $osm = $objet("metadata->'contacts_osm'") . ' || jsonb_build_object(' . $pdo->quote($col) . '::text, '
            . $pdo->quote(now()->toDateString()) . '::text, \'licence\', ' . $pdo->quote(self::LICENCE) . '::text, \'attribution\', '
            . $pdo->quote(self::ATTRIBUTION) . '::text)';
        $meta = $objet('metadata') . " || jsonb_build_object('contacts_osm', {$osm})";
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

    /** Site http(s) avec un nom de domaine (hors réseaux sociaux et annuaires), ou null. */
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
        if (! is_string($hote) || preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $hote) !== 1 || strlen($s) > 255) {
            return null;
        }
        $hote = mb_strtolower($hote);
        foreach (self::HOTES_NON_OFFICINE as $domaine) {
            if ($hote === $domaine || str_ends_with($hote, '.' . $domaine)) {
                return null;
            }
        }

        return $s;
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

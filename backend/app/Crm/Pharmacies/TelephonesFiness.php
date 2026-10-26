<?php

namespace App\Crm\Pharmacies;

use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use SplFileObject;
use Throwable;

/**
 * TÉLÉPHONES DES OFFICINES FINESS — AJOUT SEUL (dépôt public : totaux uniquement).
 *
 *  - officines 620 état A du fichier brut `etablissements.csv` (séparateur « ; ») ;
 *  - fiche retrouvée par SIRET via `finess_etablissements` (index unique espace + SIRET),
 *    jamais par SIRET seul sur `companies` ;
 *  - `companies.phone` n'est écrit que s'il est NULL ou vide (condition portée par l'UPDATE) ;
 *  - forme stockée : 10 chiffres « 0XXXXXXXXX », comme les autres collectes du CRM ;
 *  - numéros en liste d'opposition (`opt_out`, mêmes variantes que l'ingestion) et numéros
 *    invalides écartés ; personnes physiques écartées (forme juridique « 1… » ou vide,
 *    ou métadonnée `personne_physique`) ;
 *  - ni e-mail, ni statut de prospection, ni contacts, ni étiquettes ;
 *  - `metadata.telephone_source = finess` fusionné seulement si le numéro vient d'être posé ;
 *  - EXTENSION (toujours en ajout seul, champ vide ou NULL seulement, condition portée par l'UPDATE) :
 *    adresse (numero_voie… lieu_dit, adresse_ligne_4 → address ; code_postal → postcode ;
 *    commune_acheminement → city / city_name), latitude + longitude si lat ET lon sont NULL,
 *    date d'ouverture → metadata.finess_date_ouverture (si la clé est absente), et téléphone de la
 *    société exploitante (`entites-juridiques.csv`, jointure finess_ej) seulement si l'officine n'a
 *    pas de téléphone propre dans FINESS et si companies.phone est vide (telephone_source = finess_ej) ;
 *  - JAMAIS le fax (telecopie) ni le courriel ; personnes physiques : ni adresse ni téléphone ;
 *  - un paquet qui échoue n'arrête pas les suivants ; seuls des totaux sont journalisés.
 */
final class TelephonesFiness
{
    public const SOURCE = 'finess';

    public const SOURCE_EJ = 'finess_ej';

    private const COLONNES = ['siret', 'categorie', 'etat', 'telephone'];

    /** Colonnes facultatives de etablissements.csv (absentes = vides). Aucune colonne fax/courriel. */
    private const COLONNES_OPTIONNELLES = [
        'finess_ej', 'numero_voie', 'type_voie', 'libelle_voie', 'complement_voie', 'lieu_dit', 'adresse_ligne_4',
        'code_postal', 'commune_acheminement', 'latitude', 'longitude', 'date_ouverture',
    ];

    /** @var array<string, string> finess_ej → téléphone brut de la société exploitante */
    private array $telephonesEj = [];

    /**
     * @return array<string, int> bilan chiffré (aucun numéro, aucun nom)
     */
    public function executer(string $workspaceId, string $fichier, bool $appliquer, int $paquet = 500, ?string $fichierEj = null): array
    {
        $this->telephonesEj = $fichierEj !== null ? $this->lireEntites($fichierEj) : [];
        $bilan = [
            'ej_a_ajouter' => 0, 'ej_ajoutees' => 0, 'adr_a_ajouter' => 0, 'adr_ajoutees' => 0,
            'gps_a_ajouter' => 0, 'gps_ajoutees' => 0, 'date_a_ajouter' => 0, 'date_ajoutees' => 0,
            'lignes' => 0, 'officines' => 0, 'sans_telephone' => 0, 'trouvees' => 0, 'introuvables' => 0,
            'personnes_physiques' => 0, 'deja_renseignees' => 0, 'invalides' => 0, 'opposition' => 0,
            'a_ajouter' => 0, 'ajoutees' => 0, 'lots_en_echec' => 0,
        ];
        $lot = [];
        $sirets = [];
        $traite = function () use (&$lot, $workspaceId, $appliquer, &$bilan): void {
            try {
                $this->traiter($workspaceId, $lot, $appliquer, $bilan);
            } catch (Throwable) {
                // Jamais le message : une erreur de base peut contenir du SQL avec ses valeurs.
                $bilan['lots_en_echec']++;
            }
            $lot = [];
        };
        foreach ($this->lire($fichier) as $l) {
            $bilan['lignes']++;
            if ($l['etat'] !== 'A' || $l['categorie'] !== ImportFiness::CATEGORIE_OFFICINE) {
                continue;
            }
            $bilan['officines']++;
            if ($l['telephone'] === '') {
                $bilan['sans_telephone']++;
            }
            if (preg_match('/^\d{14}$/', $l['siret']) !== 1 || isset($sirets[$l['siret']])) {
                continue;
            }
            $sirets[$l['siret']] = true;
            $lot[] = $l;
            if (count($lot) >= $paquet) {
                $traite();
            }
        }
        if ($lot !== []) {
            $traite();
        }

        return $bilan;
    }

    /** « 0XXXXXXXXX » depuis une écriture française quelconque, ou null si invalide. */
    public static function normaliser(string $brut): ?string
    {
        $chiffres = (string) preg_replace('/\D/', '', $brut);
        if (preg_match('/^0[1-9]\d{8}$/', $chiffres) === 1) {
            return $chiffres;
        }
        if (preg_match('/^(?:0033|33)0?([1-9]\d{8})$/', $chiffres, $m) === 1) {
            return '0' . $m[1];
        }

        return null;
    }

    /**
     * @param  list<array<string, string>>  $lot
     * @param  array<string, int>  $bilan
     */
    private function traiter(string $workspaceId, array $lot, bool $appliquer, array &$bilan): void
    {
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $lot, $appliquer, &$bilan): void {
            $ids = DB::table('finess_etablissements')->where('workspace_id', $workspaceId)
                ->whereIn('siret', array_column($lot, 'siret'))->pluck('company_id', 'siret')->all();
            /** @var array<int, FicheFiness> $fiches */
            $fiches = [];
            foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')
                ->whereIn('id', array_values(array_unique($ids)))
                ->get(['id', 'phone', 'legal_form', 'metadata', 'address', 'postcode', 'city', 'city_name', 'lat', 'lon']) as $c) {
                $fiches[(int) $c->id] = FicheFiness::depuisLigne($c);
            }

            // Numéros valides du lot, puis une seule requête d'opposition pour tout le paquet.
            /** @var array<int, array{0: string, 1: string}> $candidats id → [numéro, source] */
            $candidats = [];
            /** @var array<int, array{adresse?: array<string, string>, gps?: array{float, float}, date?: string}> $ajouts id → adresse / gps / date à ajouter */
            $ajouts = [];
            $variantes = [];
            $locale = ['trouvees' => 0, 'introuvables' => 0, 'personnes_physiques' => 0, 'deja_renseignees' => 0, 'invalides' => 0];
            foreach ($lot as $l) {
                $id = isset($ids[$l['siret']]) ? (int) $ids[$l['siret']] : null;
                if ($id === null || ! isset($fiches[$id])) {
                    $locale['introuvables']++;

                    continue;
                }
                $locale['trouvees']++;
                $f = $fiches[$id];
                if ($this->estPersonnePhysique($f->legalForm, $f->metadata)) {
                    $locale['personnes_physiques']++;

                    continue;
                }
                $ajouts[$id] = $this->collecterAjouts($f, $l, $ajouts[$id] ?? []);
                $phoneVide = trim($f->phone) === '';
                $source = self::SOURCE;
                $tel = null;
                if ($l['telephone'] !== '') {
                    if (! $phoneVide) {
                        $locale['deja_renseignees']++;
                    } else {
                        $tel = self::normaliser($l['telephone']);
                        if ($tel === null) {
                            $locale['invalides']++;
                        }
                    }
                } elseif ($phoneVide && ($this->telephonesEj[$l['finess_ej']] ?? '') !== '') {
                    // Pas de téléphone propre dans FINESS : repli sur la société exploitante.
                    $tel = self::normaliser($this->telephonesEj[$l['finess_ej']]);
                    $source = self::SOURCE_EJ;
                }
                if ($tel === null) {
                    continue;
                }
                // Une société à plusieurs officines n'a qu'une fiche : un seul numéro, le propre d'abord.
                if (isset($candidats[$id]) && ! ($candidats[$id][1] === self::SOURCE_EJ && $source === self::SOURCE)) {
                    continue;
                }
                $candidats[$id] = [$tel, $source];
                array_push($variantes, ...ListeSuppression::variantesTelephone($tel));
            }
            $opposes = $this->opposes(array_values(array_unique($variantes)));

            $n = ['a_ajouter' => 0, 'ajoutees' => 0, 'ej_a_ajouter' => 0, 'ej_ajoutees' => 0, 'opposition' => 0,
                'adr_a_ajouter' => 0, 'adr_ajoutees' => 0, 'gps_a_ajouter' => 0, 'gps_ajoutees' => 0, 'date_a_ajouter' => 0, 'date_ajoutees' => 0];
            foreach ($candidats as $id => [$tel, $source]) {
                if (array_intersect(ListeSuppression::variantesTelephone($tel), $opposes) !== []) {
                    $n['opposition']++;

                    continue;
                }
                $ej = $source === self::SOURCE_EJ;
                $n[$ej ? 'ej_a_ajouter' : 'a_ajouter']++;
                if (! $appliquer) {
                    continue;
                }
                // Condition SQL « phone vide » : jamais d'écrasement, même si la fiche a changé entre-temps.
                $n[$ej ? 'ej_ajoutees' : 'ajoutees'] += DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $id)
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q->whereNull('phone')->orWhereRaw("btrim(phone) = ''"))
                    ->update([
                        'phone' => $tel,
                        'metadata' => DB::raw("COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('telephone_source', '" . $source . "')"),
                        'updated_at' => now(),
                    ]);
            }

            foreach ($ajouts as $id => $a) {
                foreach (['adr' => 'adresse', 'gps' => 'gps', 'date' => 'date'] as $abrev => $type) {
                    if (! isset($a[$type])) {
                        continue;
                    }
                    $n[$abrev . '_a_ajouter']++;
                    if ($appliquer) {
                        $n[$abrev . '_ajoutees'] += $this->ecrire($workspaceId, $id, $type, $a[$type]);
                    }
                }
            }

            foreach ($locale as $cle => $v) {
                $bilan[$cle] += $v;
            }
            foreach ($n as $cle => $v) {
                $bilan[$cle] += $v;
            }
        });
    }

    /**
     * Ce que FINESS peut ajouter à la fiche (champs vides ou NULL seulement) ; le premier établissement gagne.
     *
     * @param  array<string, string>  $l
     * @param  array{adresse?: array<string, string>, gps?: array{float, float}, date?: string}  $a  ajouts déjà retenus pour la fiche
     * @return array{adresse?: array<string, string>, gps?: array{float, float}, date?: string}
     */
    private function collecterAjouts(FicheFiness $f, array $l, array $a): array
    {
        if (! isset($a['adresse'])) {
            $rue = trim((string) preg_replace('/\s+/', ' ', implode(' ', array_filter(
                array_map(static fn (string $c): string => $l[$c], ['numero_voie', 'type_voie', 'libelle_voie', 'complement_voie', 'lieu_dit', 'adresse_ligne_4']),
                static fn (string $v): bool => $v !== '',
            ))));
            $champs = [];
            if ($rue !== '' && trim($f->address) === '') {
                $champs['address'] = $rue;
            }
            if ($l['code_postal'] !== '' && trim($f->postcode) === '') {
                $champs['postcode'] = $l['code_postal'];
            }
            if ($l['commune_acheminement'] !== '') {
                if (trim($f->city) === '') {
                    $champs['city'] = $l['commune_acheminement'];
                }
                if (trim($f->cityName) === '') {
                    $champs['city_name'] = $l['commune_acheminement'];
                }
            }
            if ($champs !== []) {
                $a['adresse'] = $champs;
            }
        }
        if (! isset($a['gps']) && $f->lat === null && $f->lon === null) {
            $lat = str_replace(',', '.', $l['latitude']);
            $lon = str_replace(',', '.', $l['longitude']);
            if (is_numeric($lat) && is_numeric($lon) && abs((float) $lat) <= 90 && abs((float) $lon) <= 180
                && ((float) $lat !== 0.0 || (float) $lon !== 0.0)) {
                $a['gps'] = [(float) $lat, (float) $lon];
            }
        }
        if (! isset($a['date'])) {
            $date = self::normaliserDate($l['date_ouverture']);
            $meta = json_decode($f->metadata, true);
            if ($date !== null && ! (is_array($meta) && array_key_exists('finess_date_ouverture', $meta))) {
                $a['date'] = $date;
            }
        }

        return $a;
    }

    /** AAAA-MM-JJ depuis « AAAA-MM-JJ » ou « JJ/MM/AAAA », ou null. */
    public static function normaliserDate(string $brut): ?string
    {
        $brut = trim($brut);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $brut, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $brut, $m) === 1) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** UPDATE conditionnel (champ vide / clé absente) : renvoie 1 si la fiche a été complétée. */
    private function ecrire(string $workspaceId, int $id, string $type, mixed $valeur): int
    {
        $q = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $id)->whereNull('deleted_at');
        if ($type === 'gps') {
            return $q->whereNull('lat')->whereNull('lon')->update(['lat' => $valeur[0], 'lon' => $valeur[1], 'updated_at' => now()]);
        }
        if ($type === 'date') {
            return $q->whereRaw("NOT jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'finess_date_ouverture')")->update([
                'metadata' => DB::raw("COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('finess_date_ouverture', " . DB::getPdo()->quote((string) $valeur) . '::text)'),
                'updated_at' => now(),
            ]);
        }
        // Adresse : chaque champ garde sa valeur s'il est déjà renseigné ; au moins un doit être vide.
        $sets = ['updated_at' => now()];
        $vides = [];
        foreach ($valeur as $col => $v) {
            $sets[$col] = DB::raw("CASE WHEN {$col} IS NULL OR btrim({$col}) = '' THEN " . DB::getPdo()->quote((string) $v) . " ELSE {$col} END");
            $vides[] = "{$col} IS NULL OR btrim({$col}) = ''";
        }

        return $q->whereRaw('(' . implode(' OR ', $vides) . ')')->update($sets);
    }

    /**
     * @param  list<string>  $variantes
     * @return list<string> variantes (chiffres) présentes en liste d'opposition business
     */
    private function opposes(array $variantes): array
    {
        if ($variantes === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($variantes), '?'));

        return array_values(array_map('strval', DB::table('opt_out')->where('scope', 'business')
            ->whereRaw("regexp_replace(phone, '[^0-9]', '', 'g') IN ({$in})", $variantes)
            ->selectRaw("regexp_replace(phone, '[^0-9]', '', 'g') AS chiffres")->pluck('chiffres')->all()));
    }

    private function estPersonnePhysique(string $legalForm, string $metadata): bool
    {
        $forme = trim($legalForm);
        if ($forme === '' || str_starts_with($forme, '1')) {
            return true;
        }
        $meta = json_decode($metadata, true);

        return is_array($meta) && ! empty($meta['personne_physique']);
    }

    /**
     * entites-juridiques.csv : finess_ej → téléphone brut (renseignés seulement). Le fax n'est pas lu.
     *
     * @return array<string, string>
     */
    private function lireEntites(string $fichier): array
    {
        $f = new SplFileObject($fichier, 'r');
        $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $f->setCsvControl(';', '"', '');
        $entete = null;
        $iEj = $iTel = false;
        $map = [];
        foreach ($f as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }
            if ($entete === null) {
                $entete = array_map(static fn ($c): string => trim(ltrim((string) $c, "\xEF\xBB\xBF")), $row);
                $iEj = array_search('finess_ej', $entete, true);
                $iTel = array_search('telephone', $entete, true);
                if ($iEj === false || $iTel === false) {
                    throw new \InvalidArgumentException('Colonnes manquantes dans les entités juridiques : finess_ej, telephone');
                }

                continue;
            }
            $ej = trim((string) ($row[$iEj] ?? ''));
            $tel = trim((string) ($row[$iTel] ?? ''));
            if ($ej !== '' && $tel !== '') {
                $map[$ej] = $tel;
            }
        }

        return $map;
    }

    /** @return \Generator<array<string, string>> */
    private function lire(string $fichier): \Generator
    {
        $f = new SplFileObject($fichier, 'r');
        $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $f->setCsvControl(';', '"', '');
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
            $ligne = [];
            foreach (array_merge(self::COLONNES, self::COLONNES_OPTIONNELLES) as $c) {
                $i = array_search($c, $entete, true);
                $ligne[$c] = $i === false ? '' : trim((string) ($row[$i] ?? ''));
            }
            $ligne['etat'] = strtoupper($ligne['etat']);
            $ligne['siret'] = preg_replace('/\s+/', '', $ligne['siret']) ?? '';

            yield $ligne;
        }
    }
}

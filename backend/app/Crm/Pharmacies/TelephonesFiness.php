<?php

namespace App\Crm\Pharmacies;

use App\Crm\FichesProtegees;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\Query\Builder;
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
 *    invalides écartés ; personnes physiques écartées (forme juridique « 1… » ou métadonnée
 *    `personne_physique` posée par ImportFiness ; une forme vide est une personne morale) ;
 *  - fiches protégées (FichesProtegees), fermées à l'INSEE, `non_officine` ou écartées : jamais touchées
 *    (SELECT et chaque UPDATE) ; numéro ou e-mail générique en opposition : fiche entière écartée ;
 *  - un SIREN regroupe plusieurs établissements : lignes groupées par fiche avant le découpage en
 *    paquets ; adresse, GPS, date seulement depuis la ligne dont le SIRET est celui de la fiche, sinon
 *    si une seule officine active pointe vers elle ; GPS limité aux boîtes France ;
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
        'code_postal', 'commune', 'commune_acheminement', 'latitude', 'longitude', 'date_ouverture',
    ];

    /** @var array<string, string> finess_ej → téléphone brut de la société exploitante */
    private array $telephonesEj = [];

    /** Boîtes englobantes France : [lat min, lat max, lon min, lon max] — métropole, puis DOM-TOM (larges). */
    private const BOITES_FRANCE = [
        [41.0, 51.5, -5.5, 9.8],         // métropole + Corse
        [14.0, 18.2, -63.5, -60.5],      // Guadeloupe, Martinique, Saint-Martin, Saint-Barthélemy
        [2.0, 6.0, -55.0, -51.0],        // Guyane
        [-21.6, -20.7, 55.0, 56.0],      // La Réunion
        [-13.2, -12.5, 44.9, 45.5],      // Mayotte
        [46.6, 47.3, -56.7, -56.0],      // Saint-Pierre-et-Miquelon
        [-28.0, -7.0, -155.0, -134.0],   // Polynésie française
        [-23.5, -19.0, 163.0, 169.0],    // Nouvelle-Calédonie
        [-14.6, -13.0, -178.5, -175.5],  // Wallis-et-Futuna
    ];

    /** Nombre maximal d'identifiants de fiches par requête `companies`. */
    private const IDS_PAR_REQUETE = 500;

    /**
     * @return array<string, int> bilan chiffré (aucun numéro, aucun nom)
     */
    public function executer(string $workspaceId, string $fichier, bool $appliquer, int $paquet = 500, ?string $fichierEj = null): array
    {
        $this->telephonesEj = $fichierEj !== null ? $this->lireEntites($fichierEj) : [];
        $bilan = [
            'ej_a_ajouter' => 0, 'ej_ajoutees' => 0, 'adr_a_ajouter' => 0, 'adr_ajoutees' => 0,
            'gps_a_ajouter' => 0, 'gps_ajoutees' => 0, 'date_a_ajouter' => 0, 'date_ajoutees' => 0, 'gps_hors_france' => 0,
            'lignes' => 0, 'officines' => 0, 'sans_telephone' => 0, 'trouvees' => 0, 'introuvables' => 0, 'exclues' => 0,
            'personnes_physiques' => 0, 'deja_renseignees' => 0, 'invalides' => 0, 'opposition' => 0,
            'a_ajouter' => 0, 'ajoutees' => 0, 'lots_en_echec' => 0,
        ];
        $lignes = [];
        $sirets = [];
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
            $lignes[] = $l;
        }

        // Les établissements d'une même fiche restent TOUJOURS dans le même paquet (plus d'effet de paquet).
        /** @var array<int, list<array<string, string>>> $groupes company_id → lignes, dans l'ordre du fichier */
        $groupes = [];
        foreach (array_chunk($lignes, self::IDS_PAR_REQUETE) as $morceau) {
            try {
                $this->regrouper($workspaceId, $morceau, $groupes, $bilan);
            } catch (Throwable) {
                $bilan['lots_en_echec']++;
            }
        }

        $lot = [];
        $taille = 0;
        $traite = function () use (&$lot, &$taille, $workspaceId, $appliquer, &$bilan): void {
            try {
                $this->traiter($workspaceId, $lot, $appliquer, $bilan);
            } catch (Throwable) {
                // Jamais le message : une erreur de base peut contenir du SQL avec ses valeurs.
                $bilan['lots_en_echec']++;
            }
            $lot = [];
            $taille = 0;
        };
        foreach ($groupes as $id => $ls) {
            $lot[$id] = $ls;
            $taille += count($ls);
            if ($taille >= $paquet) {
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
     * Retrouve la fiche de chaque SIRET (finess_etablissements) et range les lignes par company_id.
     *
     * @param  list<array<string, string>>  $morceau
     * @param  array<int, list<array<string, string>>>  $groupes
     * @param  array<string, int>  $bilan
     */
    private function regrouper(string $workspaceId, array $morceau, array &$groupes, array &$bilan): void
    {
        $ids = WorkspaceContext::run($workspaceId, fn () => DB::table('finess_etablissements')->where('workspace_id', $workspaceId)
            ->whereIn('siret', array_column($morceau, 'siret'))->pluck('company_id', 'siret')->all());
        foreach ($morceau as $l) {
            if (! isset($ids[$l['siret']])) {
                $bilan['introuvables']++;

                continue;
            }
            $groupes[(int) $ids[$l['siret']]][] = $l;
        }
    }

    /** Fiches ouvertes, non protégées, de l'espace (≤ 500 ids par requête) : id → fiche et SIRET / e-mail générique. */
    /**
     * @param  list<int>  $ids
     * @return array{0: array<int, FicheFiness>, 1: array<int, array{siret: string, email: string}>}
     */
    private function fiches(string $workspaceId, array $ids): array
    {
        /** @var array<int, FicheFiness> $fiches */
        $fiches = [];
        /** @var array<int, array{siret: string, email: string}> $extra */
        $extra = [];
        foreach (array_chunk($ids, self::IDS_PAR_REQUETE) as $morceau) {
            $q = DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereIn('id', $morceau);
            $this->ouvertes($q);
            FichesProtegees::exclure($q);
            foreach ($q->get(['id', 'siret', 'email_generic', 'phone', 'legal_form', 'metadata', 'address', 'postcode', 'city', 'city_name', 'lat', 'lon']) as $c) {
                $fiches[(int) $c->id] = FicheFiness::depuisLigne($c);
                $extra[(int) $c->id] = ['siret' => trim((string) $c->siret), 'email' => trim((string) $c->email_generic)];
            }
        }

        return [$fiches, $extra];
    }

    /** Mêmes conditions que SelectionEnrichissement::ouvertes (hors NAF et protection) ; fonctionne en SELECT comme en UPDATE. */
    private function ouvertes(Builder $q): void
    {
        $q->whereNull('insee_ferme_le')
            ->whereRaw("COALESCE(metadata->>'type_etablissement', '') <> 'non_officine'")
            ->whereRaw("NOT jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'ecartee_motif')");
    }

    /** Garde portée par chaque UPDATE : fiche ouverte ET non protégée, même si elle a changé entre-temps. */
    private function gardes(Builder $q): Builder
    {
        $this->ouvertes($q);
        $q->whereRaw(FichesProtegees::conditionSql('companies.id', null, 'companies.workspace_id'));

        return $q;
    }

    /**
     * @param  array<int, list<array<string, string>>>  $lot  company_id → lignes (jamais coupé entre deux paquets)
     * @param  array<string, int>  $bilan
     */
    private function traiter(string $workspaceId, array $lot, bool $appliquer, array &$bilan): void
    {
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $lot, $appliquer, &$bilan): void {
            [$fiches, $extra] = $this->fiches($workspaceId, array_keys($lot));

            // Plan par fiche, puis une seule requête d'opposition pour tout le paquet.
            /** @var array<int, array{tel: ?string, source: string, ajouts: array<string, mixed>, email: string}> $plans */
            $plans = [];
            $variantes = [];
            $emails = [];
            $locale = ['trouvees' => 0, 'exclues' => 0, 'personnes_physiques' => 0, 'deja_renseignees' => 0, 'invalides' => 0, 'gps_hors_france' => 0];
            foreach ($lot as $id => $lignes) {
                if (! isset($fiches[$id])) {
                    $locale['exclues'] += count($lignes);

                    continue;
                }
                $locale['trouvees'] += count($lignes);
                $f = $fiches[$id];
                if ($this->estPersonnePhysique($f->legalForm, $f->metadata)) {
                    $locale['personnes_physiques']++;

                    continue;
                }
                // La ligne dont le SIRET est celui de la fiche passe en premier.
                $siretFiche = $extra[$id]['siret'];
                usort($lignes, static fn (array $a, array $b): int => ((int) ($b['siret'] === $siretFiche)) <=> ((int) ($a['siret'] === $siretFiche)));
                // Adresse, GPS, date : la ligne du SIRET de la fiche, sinon la seule officine active qui y pointe.
                $reference = $lignes[0]['siret'] === $siretFiche || count($lignes) === 1 ? $lignes[0] : null;
                $ajouts = $reference !== null ? $this->collecterAjouts($f, $reference, $locale) : [];

                $phoneVide = trim($f->phone) === '';
                $source = self::SOURCE;
                $tel = null;
                $propre = false;
                $invalide = false;
                foreach ($lignes as $l) {
                    if ($l['telephone'] === '') {
                        continue;
                    }
                    $propre = true;
                    if (! $phoneVide) {
                        break;
                    }
                    $tel = self::normaliser($l['telephone']);
                    if ($tel !== null) {
                        break;
                    }
                    $invalide = true;
                }
                if ($propre && ! $phoneVide) {
                    $locale['deja_renseignees']++;
                } elseif ($invalide && $tel === null) {
                    $locale['invalides']++;
                } elseif (! $propre && $phoneVide) {
                    // Pas de téléphone propre dans FINESS (aucun des établissements) : repli sur la société exploitante.
                    foreach ($lignes as $l) {
                        if (($this->telephonesEj[$l['finess_ej']] ?? '') !== '') {
                            $tel = self::normaliser($this->telephonesEj[$l['finess_ej']]);
                            $source = self::SOURCE_EJ;

                            break;
                        }
                    }
                }
                if ($tel === null && $ajouts === []) {
                    continue;
                }
                if ($tel !== null) {
                    array_push($variantes, ...ListeSuppression::variantesTelephone($tel));
                }
                if ($extra[$id]['email'] !== '') {
                    $emails[] = $extra[$id]['email'];
                }
                $plans[$id] = ['tel' => $tel, 'source' => $source, 'ajouts' => $ajouts, 'email' => $extra[$id]['email']];
            }
            $opposes = $this->opposes(array_values(array_unique($variantes)));
            $emailsOpposes = $this->emailsOpposes(array_values(array_unique($emails)));

            $n = ['a_ajouter' => 0, 'ajoutees' => 0, 'ej_a_ajouter' => 0, 'ej_ajoutees' => 0, 'opposition' => 0,
                'adr_a_ajouter' => 0, 'adr_ajoutees' => 0, 'gps_a_ajouter' => 0, 'gps_ajoutees' => 0, 'date_a_ajouter' => 0, 'date_ajoutees' => 0];
            foreach ($plans as $id => $p) {
                // Numéro à ajouter OU e-mail générique en opposition : la fiche entière est écartée.
                if (($p['tel'] !== null && array_intersect(ListeSuppression::variantesTelephone($p['tel']), $opposes) !== [])
                    || ($p['email'] !== '' && isset($emailsOpposes[mb_strtolower($p['email'])]))) {
                    $n['opposition']++;

                    continue;
                }
                if ($p['tel'] !== null) {
                    $ej = $p['source'] === self::SOURCE_EJ;
                    $n[$ej ? 'ej_a_ajouter' : 'a_ajouter']++;
                    if ($appliquer) {
                        // Condition SQL « phone vide » : jamais d'écrasement, même si la fiche a changé entre-temps.
                        $n[$ej ? 'ej_ajoutees' : 'ajoutees'] += $this->gardes(DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $id)
                            ->whereNull('deleted_at'))
                            ->where(fn ($q) => $q->whereNull('phone')->orWhereRaw("btrim(phone) = ''"))
                            ->update([
                                'phone' => $p['tel'],
                                'metadata' => DB::raw("COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('telephone_source', '" . $p['source'] . "')"),
                                'updated_at' => now(),
                            ]);
                    }
                }
                foreach (['adr' => 'adresse', 'gps' => 'gps', 'date' => 'date'] as $abrev => $type) {
                    if (! isset($p['ajouts'][$type])) {
                        continue;
                    }
                    $n[$abrev . '_a_ajouter']++;
                    if ($appliquer) {
                        $n[$abrev . '_ajoutees'] += $this->ecrire($workspaceId, $id, $type, $p['ajouts'][$type]);
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
     * Ce que FINESS peut ajouter à la fiche (champs vides ou NULL seulement) depuis UN établissement.
     *
     * @param  array<string, string>  $l
     * @param  array<string, int>  $locale  totaux du paquet (gps_hors_france)
     * @return array{adresse?: array{champs: array<string, string>, rue: string}, gps?: array{float, float}, date?: string}
     */
    private function collecterAjouts(FicheFiness $f, array $l, array &$locale): array
    {
        $a = [];
        $rue = trim((string) preg_replace('/\s+/', ' ', implode(' ', array_filter(
            array_map(static fn (string $c): string => $l[$c], ['numero_voie', 'type_voie', 'libelle_voie', 'complement_voie', 'lieu_dit', 'adresse_ligne_4']),
            static fn (string $v): bool => $v !== '',
        ))));
        $champs = [];
        $adresseVide = trim($f->address) === '';
        if ($rue !== '' && $adresseVide) {
            $champs['address'] = $rue;
        }
        // Code postal et ville seulement avec une adresse vide (même UPDATE) ou la même rue que FINESS.
        if ($adresseVide || ($rue !== '' && self::normaliserRue($f->address) === self::normaliserRue($rue))) {
            $commune = self::commune($l);
            if ($l['code_postal'] !== '' && trim($f->postcode) === '') {
                $champs['postcode'] = $l['code_postal'];
            }
            if ($commune !== '') {
                if (trim($f->city) === '') {
                    $champs['city'] = $commune;
                }
                if (trim($f->cityName) === '') {
                    $champs['city_name'] = $commune;
                }
            }
        }
        if ($champs !== []) {
            $a['adresse'] = ['champs' => $champs, 'rue' => self::normaliserRue($rue)];
        }
        if ($f->lat === null && $f->lon === null) {
            $lat = str_replace(',', '.', $l['latitude']);
            $lon = str_replace(',', '.', $l['longitude']);
            if (is_numeric($lat) && is_numeric($lon) && ((float) $lat !== 0.0 || (float) $lon !== 0.0)) {
                if (self::dansFrance((float) $lat, (float) $lon)) {
                    $a['gps'] = [(float) $lat, (float) $lon];
                } else {
                    $locale['gps_hors_france']++;
                }
            }
        }
        $date = self::normaliserDate($l['date_ouverture']);
        $meta = json_decode($f->metadata, true);
        if ($date !== null && ! (is_array($meta) && array_key_exists('finess_date_ouverture', $meta))) {
            $a['date'] = $date;
        }

        return $a;
    }

    /**
     * Commune comme l'import (colonne `commune`), sinon commune_acheminement sans code postal ni CEDEX.
     *
     * @param  array<string, string>  $l
     */
    private static function commune(array $l): string
    {
        if ($l['commune'] !== '') {
            return $l['commune'];
        }
        $c = (string) preg_replace('/^\s*\d{5}\s+/', '', $l['commune_acheminement']);
        $c = (string) preg_replace('/\s+CEDEX(\s+\d+)?\s*$/i', '', $c);

        return trim((string) preg_replace('/\s+/', ' ', $c));
    }

    private static function normaliserRue(string $rue): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $rue)));
    }

    private static function dansFrance(float $lat, float $lon): bool
    {
        foreach (self::BOITES_FRANCE as [$latMin, $latMax, $lonMin, $lonMax]) {
            if ($lat >= $latMin && $lat <= $latMax && $lon >= $lonMin && $lon <= $lonMax) {
                return true;
            }
        }

        return false;
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
        $q = $this->gardes(DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $id)->whereNull('deleted_at'));
        if ($type === 'gps') {
            return $q->whereNull('lat')->whereNull('lon')->update(['lat' => $valeur[0], 'lon' => $valeur[1], 'updated_at' => now()]);
        }
        if ($type === 'date') {
            return $q->whereRaw("NOT jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'finess_date_ouverture')")->update([
                'metadata' => DB::raw("COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('finess_date_ouverture', " . DB::getPdo()->quote((string) $valeur) . '::text)'),
                'updated_at' => now(),
            ]);
        }
        // Adresse : chaque champ garde sa valeur s'il est déjà renseigné ; code postal et ville
        // seulement si l'adresse est vide (même UPDATE) ou égale à la rue FINESS.
        $vide = static fn (string $col): string => "({$col} IS NULL OR btrim({$col}) = '')";
        $adresseOk = "(COALESCE(btrim(address), '') = '' OR lower(regexp_replace(btrim(address), '\\s+', ' ', 'g')) = " . DB::getPdo()->quote($valeur['rue']) . ')';
        $sets = ['updated_at' => now()];
        $vides = [];
        foreach ($valeur['champs'] as $col => $v) {
            $condition = $col === 'address' ? $vide($col) : "{$adresseOk} AND " . $vide($col);
            $sets[$col] = DB::raw("CASE WHEN {$condition} THEN " . DB::getPdo()->quote((string) $v) . " ELSE {$col} END");
            $vides[] = "({$condition})";
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

    /**
     * @param  list<string>  $emails
     * @return array<string, true> e-mails (minuscules) en opposition ou supprimés, univers business
     */
    private function emailsOpposes(array $emails): array
    {
        $opposes = [];
        $parHash = [];
        foreach ($emails as $e) {
            $parHash[ListeSuppression::empreinte($e)] = mb_strtolower(trim($e));
        }
        foreach (array_chunk(array_keys($parHash), 1000) as $hashes) {
            foreach (DB::table('opt_out')->where('scope', 'business')->whereIn('email_hash', $hashes)->pluck('email_hash') as $h) {
                $opposes[$parHash[(string) $h]] = true;
            }
        }
        foreach ($parHash as $email) {
            if (! isset($opposes[$email]) && ListeSuppression::estSupprimee($email)) {
                $opposes[$email] = true;
            }
        }

        return $opposes;
    }

    /**
     * Personne physique = ce que l'import FINESS marque (`metadata.personne_physique`) ou une forme
     * juridique « 1… ». `legal_form` est NULL pour une personne morale importée de FINESS : un champ
     * vide n'est donc PAS une personne physique.
     */
    private function estPersonnePhysique(string $legalForm, string $metadata): bool
    {
        if (str_starts_with(trim($legalForm), '1')) {
            return true;
        }
        $meta = json_decode($metadata, true);

        return is_array($meta) && ($meta['personne_physique'] ?? false) === true;
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
            /** @var array<string, string> $ligne */
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

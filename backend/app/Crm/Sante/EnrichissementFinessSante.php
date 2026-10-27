<?php

namespace App\Crm\Sante;

use App\Crm\FichesProtegees;
use App\Crm\Pharmacies\FicheFiness;
use App\Crm\Pharmacies\ImportFiness;
use App\Crm\Pharmacies\TelephonesFiness;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ENRICHISSEMENT DES ÉTABLISSEMENTS DE SANTÉ FINESS — AJOUT SEUL (dépôt public : totaux uniquement).
 *
 * Même circuit que `TelephonesFiness` (officines 620, déjà traitées, jamais reprises ici) : téléphone,
 * adresse et GPS depuis `etablissements.csv`, sur champ vide ou NULL seulement, condition portée par
 * chaque UPDATE. Aucune fiche créée, aucune date, ni fax, ni courriel.
 *
 *  - `finess_etablissements` ne contient que des officines : le rapprochement se fait dans `companies`
 *    (unique par espace + SIREN, `siret` non indexé) : `whereIn('siren')` par paquets, SIRET comparé en PHP ;
 *  - N1 : le SIRET de la fiche est celui de FINESS ; N2 (SIREN gardé) : fiche sans SIRET, un seul
 *    établissement FINESS actif (toutes catégories) pour ce SIREN, code postal égal (fiche sans code postal : jamais rapprochée) ;
 *  - fiche avec un AUTRE SIRET : `siret_different` ; code postal différent : `cp_divergent` ; plusieurs
 *    établissements actifs pour un SIREN sans SIRET de fiche : `siren_ambigu` ; un SIRET porté par plusieurs
 *    lignes actives de téléphone ou d'adresse différents : `siret_partage` ; rien n'est écrit dans ces cas ;
 *  - fiches protégées, fermées ou non diffusibles à l'INSEE, `non_officine` ou écartées : jamais touchées
 *    (SELECT et chaque UPDATE) ; la corbeille n'est jamais lue ni restaurée ;
 *  - personne physique (forme « 1… » ou métadonnée) : fiche écartée ; numéro à ajouter, numéro déjà présent
 *    ou e-mail générique en opposition : fiche écartée ;
 *  - bilan : totaux et `par_categorie` seulement ; `exclues` = SIREN sans fiche utilisable (absente,
 *    fermée, protégée, corbeille : non distinguables sans lire la corbeille) ; `exclues_non_officine` (sous-ensemble de
 *    `exclues`) = fiches volontairement exclues par `metadata.type_etablissement = non_officine` ; `fiches_siret_partage`
 *    = fiches dont le SIRET est partagé (déjà compté dans `siret_partage`) ; `trouvees_*` = fiches
 *    rapprochées après le contrôle du code postal ; `pp` et `opposition` en sont des sous-ensembles ;
 *  - un paquet en échec ne bloque pas les suivants ; ni message SQL ni numéro dans les journaux.
 *
 * @phpstan-type Compteurs array<string, int>
 * @phpstan-type Bilan array{lignes: int, lots_en_echec: int, invalides: int, gps_hors_france: int, actives: int, sans_siret: int, trouvees_n1: int, trouvees_n2: int, siren_ambigu: int, siret_partage: int, siret_different: int, cp_divergent: int, exclues: int, exclues_non_officine: int, fiches_siret_partage: int, personnes_physiques_ecartees: int, opposition: int, tel: int, adr: int, gps: int, fiches_enrichies: int, par_categorie: array<string, array<string, int>>}
 */
final class EnrichissementFinessSante
{
    private const EXCLUE = ImportFiness::CATEGORIE_OFFICINE;

    private const CLES = [
        'actives', 'sans_siret', 'trouvees_n1', 'trouvees_n2', 'siren_ambigu', 'siret_partage', 'siret_different',
        'cp_divergent', 'exclues', 'exclues_non_officine', 'fiches_siret_partage', 'pp', 'opposition', 'tel', 'adr', 'gps', 'fiches_enrichies',
    ];

    /**
     * @param  list<string>  $categories  codes FINESS à traiter ; vide = toutes sauf 620 (620 est toujours ignorée)
     * @return Bilan
     */
    public function executer(string $workspaceId, string $fichier, bool $appliquer, array $categories = [], int $paquet = 500): array
    {
        $tf = new TelephonesFiness;
        $filtre = array_flip(array_values(array_diff($categories, [self::EXCLUE])));
        $paquet = max(1, $paquet);

        /** @var array<string, array<string, int>> $par */
        $par = [];
        $bilan = ['lignes' => 0, 'lots_en_echec' => 0, 'invalides' => 0, 'gps_hors_france' => 0];

        /** @var array<string, array<string, true>> $parSiren siren → sirets actifs (toutes catégories) */
        $parSiren = [];
        /** @var array<string, list<array<string, string>>> $parSiret lignes actives par SIRET (toutes catégories) */
        $parSiret = [];
        foreach ($tf->lire($fichier) as $l) {
            $bilan['lignes']++;
            if ($l['etat'] !== 'A') {
                continue;
            }
            $cat = $l['categorie'];
            $cible = $cat !== self::EXCLUE && ($filtre === [] || isset($filtre[$cat]));
            if ($cible) {
                $par[$cat] ??= array_fill_keys(self::CLES, 0);
                $par[$cat]['actives']++;
            }
            if (preg_match('/^\d{14}$/', $l['siret']) !== 1) {
                if ($cible) {
                    $par[$cat]['sans_siret']++;
                }

                continue;
            }
            $parSiren[substr($l['siret'], 0, 9)][$l['siret']] = true;
            $parSiret[$l['siret']][] = $l;
        }

        // Établissements à traiter, par SIREN ; un SIRET partagé (téléphone ou adresse différents) est écarté.
        /** @var array<string, array<string, array<string, string>>> $cibles siren → siret → ligne */
        $cibles = [];
        /** @var array<string, array<string, string>> $partages siren → siret partagé → catégorie */
        $partages = [];
        foreach ($parSiret as $siret => $lignes) {
            $cat = null;
            $ligne = null;
            foreach ($lignes as $l) {
                if ($l['categorie'] !== self::EXCLUE && ($filtre === [] || isset($filtre[$l['categorie']]))) {
                    $cat = $l['categorie'];
                    $ligne = $l;

                    break;
                }
            }
            if ($cat === null || $ligne === null) {
                continue;
            }
            $siren = substr((string) $siret, 0, 9);
            if ($this->partage($lignes)) {
                $par[$cat]['siret_partage']++;
                $partages[$siren][(string) $siret] = $cat;

                continue;
            }
            $cibles[$siren][(string) $siret] = $ligne;
        }

        // Un SIREN dont tous les SIRET cibles sont partagés entre aussi dans un paquet : sa fiche est comptée à part.
        foreach (array_keys($partages) as $siren) {
            $cibles[(string) $siren] ??= [];
        }

        foreach (array_chunk(array_map('strval', array_keys($cibles)), $paquet) as $sirens) {
            $lot = [];
            foreach ($sirens as $s) {
                $lot[$s] = $cibles[$s];
            }
            try {
                $local = $this->traiter($tf, $workspaceId, $lot, $partages, $parSiren, $appliquer);
                foreach ($local['par'] as $cat => $c) {
                    $par[$cat] ??= array_fill_keys(self::CLES, 0);
                    foreach ($c as $cle => $v) {
                        $par[$cat][$cle] += $v;
                    }
                }
                $bilan['invalides'] += $local['invalides'];
                $bilan['gps_hors_france'] += $local['gps_hors_france'];
            } catch (Throwable) {
                // Jamais le message : une erreur de base peut contenir du SQL avec ses valeurs.
                $bilan['lots_en_echec']++;
            }
        }

        ksort($par);
        $t = array_fill_keys(self::CLES, 0);
        foreach ($par as $c) {
            foreach ($c as $cle => $v) {
                $t[$cle] += $v;
            }
        }

        return [
            'lignes' => $bilan['lignes'], 'lots_en_echec' => $bilan['lots_en_echec'], 'invalides' => $bilan['invalides'],
            'gps_hors_france' => $bilan['gps_hors_france'], 'actives' => $t['actives'], 'sans_siret' => $t['sans_siret'],
            'trouvees_n1' => $t['trouvees_n1'], 'trouvees_n2' => $t['trouvees_n2'], 'siren_ambigu' => $t['siren_ambigu'],
            'siret_partage' => $t['siret_partage'], 'siret_different' => $t['siret_different'], 'cp_divergent' => $t['cp_divergent'],
            'exclues' => $t['exclues'], 'exclues_non_officine' => $t['exclues_non_officine'], 'fiches_siret_partage' => $t['fiches_siret_partage'], 'personnes_physiques_ecartees' => $t['pp'], 'opposition' => $t['opposition'],
            'tel' => $t['tel'], 'adr' => $t['adr'], 'gps' => $t['gps'], 'fiches_enrichies' => $t['fiches_enrichies'],
            'par_categorie' => $par,
        ];
    }

    /**
     * Un SIRET sur plusieurs lignes actives dont le téléphone ou l'adresse diffère.
     *
     * @param  list<array<string, string>>  $lignes
     */
    private function partage(array $lignes): bool
    {
        if (count($lignes) < 2) {
            return false;
        }
        $empreintes = [];
        foreach ($lignes as $l) {
            $tel = TelephonesFiness::normaliser($l['telephone']) ?? trim($l['telephone']);
            $adresse = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', implode(' ', [
                $l['numero_voie'], $l['type_voie'], $l['libelle_voie'], $l['complement_voie'], $l['lieu_dit'], $l['adresse_ligne_4'], $l['code_postal'],
            ]))));
            $empreintes[$tel . '|' . $adresse] = true;
        }

        return count($empreintes) > 1;
    }

    /**
     * @param  array<string, array<string, array<string, string>>>  $lot  siren → siret → ligne
     * @param  array<string, array<string, string>>  $partages
     * @param  array<string, array<string, true>>  $parSiren
     * @return array{par: array<string, array<string, int>>, invalides: int, gps_hors_france: int}
     */
    private function traiter(TelephonesFiness $tf, string $workspaceId, array $lot, array $partages, array $parSiren, bool $appliquer): array
    {
        /** @var array{par: array<string, array<string, int>>, invalides: int, gps_hors_france: int} $sortie */
        $sortie = WorkspaceContext::run($workspaceId, function () use ($tf, $workspaceId, $lot, $partages, $parSiren, $appliquer): array {
            $q = DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereIn('siren', array_map('strval', array_keys($lot)));
            // Fiches ouvertes (INSEE, non officine, écartée) et non protégées : la corbeille n'est jamais lue.
            $tf->gardes($q);
            FichesProtegees::exclure($q);

            /** @var array<string, FicheFiness> $fiches */
            $fiches = [];
            /** @var array<string, array{siret: string, email: string, dep: string}> $extra */
            $extra = [];
            foreach ($q->get(['id', 'siren', 'siret', 'email_generic', 'phone', 'legal_form', 'metadata', 'address', 'postcode', 'city', 'city_name', 'lat', 'lon', 'department_code']) as $c) {
                $siren = (string) $c->siren;
                $fiches[$siren] = FicheFiness::depuisLigne($c);
                $extra[$siren] = ['siret' => trim((string) $c->siret), 'email' => trim((string) $c->email_generic), 'dep' => trim((string) $c->department_code)];
            }

            // Exclusion volontaire (non_officine) : une seule lecture par paquet, sans la corbeille.
            /** @var array<string, true> $nonOfficines */
            $nonOfficines = [];
            $no = DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')
                ->whereIn('siren', array_map('strval', array_keys($lot)))
                ->whereRaw("metadata->>'type_etablissement' = 'non_officine'");
            FichesProtegees::exclure($no);
            foreach ($no->pluck('siren') as $s) {
                $nonOfficines[(string) $s] = true;
            }

            /** @var array<string, array<string, int>> $par */
            $par = [];
            $compte = static function (string $cat, string $cle, int $n = 1) use (&$par): void {
                $par[$cat] ??= array_fill_keys(self::CLES, 0);
                $par[$cat][$cle] += $n;
            };
            $locale = ['gps_hors_france' => 0];
            $invalides = 0;

            /** @var array<string, array{tel: ?string, ajouts: array<string, mixed>, email: string, existant: list<string>, cat: string}> $plans */
            $plans = [];
            $variantes = [];
            $emails = [];
            foreach ($lot as $siren => $etablissements) {
                $siren = (string) $siren;
                $premiere = (string) (array_values($etablissements)[0]['categorie'] ?? array_values($partages[$siren] ?? [])[0] ?? '');
                if (! isset($fiches[$siren])) {
                    $compte($premiere, 'exclues');
                    if (isset($nonOfficines[$siren])) {
                        $compte($premiere, 'exclues_non_officine');
                    }

                    continue;
                }
                $f = $fiches[$siren];
                $ficheSiret = $extra[$siren]['siret'];
                $ligne = null;
                $niveau = 0;
                if ($ficheSiret !== '') {
                    if (isset($etablissements[$ficheSiret])) {
                        $ligne = $etablissements[$ficheSiret];
                        $niveau = 1;
                    } elseif (isset($partages[$siren][$ficheSiret])) {
                        $compte($premiere, 'fiches_siret_partage');
                    } else {
                        foreach ($etablissements as $l) {
                            $compte($l['categorie'], 'siret_different');
                        }
                    }
                } elseif (count($parSiren[$siren] ?? []) === 1 && count($etablissements) === 1) {
                    $ligne = array_values($etablissements)[0];
                    $niveau = 2;
                } else {
                    foreach ($etablissements as $l) {
                        $compte($l['categorie'], 'siren_ambigu');
                    }
                }
                if ($ligne === null) {
                    continue;
                }
                $cat = $ligne['categorie'];

                // Garde 2 : code postal. N1 : divergent seulement si les deux existent ; N2 : code postal exigé et égal.
                $cpFiche = trim($f->postcode);
                $cpLigne = trim($ligne['code_postal']);
                if ($niveau === 1) {
                    $divergent = $cpFiche !== '' && $cpLigne !== '' && $cpFiche !== $cpLigne;
                } else {
                    $divergent = $cpFiche === '' || $cpFiche !== $cpLigne;
                }
                if ($divergent) {
                    $compte($cat, 'cp_divergent');

                    continue;
                }
                $compte($cat, $niveau === 1 ? 'trouvees_n1' : 'trouvees_n2');

                if ($tf->estPersonnePhysique($f->legalForm, $f->metadata)) {
                    $compte($cat, 'pp');

                    continue;
                }

                $ajouts = $tf->collecterAjouts($f, $ligne, $locale);
                unset($ajouts['date']);
                $tel = null;
                $phoneVide = trim($f->phone) === '';
                if ($phoneVide && $ligne['telephone'] !== '') {
                    $tel = TelephonesFiness::normaliser($ligne['telephone']);
                    if ($tel === null) {
                        $invalides++;
                    }
                }
                if ($tel === null && $ajouts === []) {
                    continue;
                }
                if ($tel !== null) {
                    array_push($variantes, ...ListeSuppression::variantesTelephone($tel));
                }
                $existant = $phoneVide ? [] : ListeSuppression::variantesTelephone($f->phone);
                array_push($variantes, ...$existant);
                if ($extra[$siren]['email'] !== '') {
                    $emails[] = $extra[$siren]['email'];
                }
                $plans[$siren] = ['tel' => $tel, 'ajouts' => $ajouts, 'email' => $extra[$siren]['email'], 'existant' => $existant, 'cat' => $cat];
            }

            $opposes = $tf->opposes(array_values(array_unique($variantes)));
            $emailsOpposes = $tf->emailsOpposes(array_values(array_unique($emails)));

            foreach ($plans as $siren => $p) {
                $id = $fiches[$siren]->id;
                // Numéro à ajouter, numéro déjà présent OU e-mail générique en opposition : la fiche entière est écartée.
                if (($p['tel'] !== null && array_intersect(ListeSuppression::variantesTelephone($p['tel']), $opposes) !== [])
                    || array_intersect($p['existant'], $opposes) !== []
                    || ($p['email'] !== '' && isset($emailsOpposes[mb_strtolower($p['email'])]))) {
                    $compte($p['cat'], 'opposition');

                    continue;
                }
                $enrichie = false;
                if ($p['tel'] !== null) {
                    if (! $appliquer) {
                        $compte($p['cat'], 'tel');
                        $enrichie = true;
                    } else {
                        // Condition SQL « phone vide » : jamais d'écrasement, même si la fiche a changé entre-temps.
                        $n = $tf->gardes(DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $id)
                            ->whereNull('deleted_at'))
                            ->where(fn ($q) => $q->whereNull('phone')->orWhereRaw("btrim(phone) = ''"))
                            ->update([
                                'phone' => $p['tel'],
                                'metadata' => DB::raw("CASE WHEN jsonb_typeof(metadata) = 'object' THEN metadata ELSE '{}'::jsonb END || jsonb_build_object('telephone_source', '" . TelephonesFiness::SOURCE . "')"),
                                'updated_at' => now(),
                            ]);
                        $compte($p['cat'], 'tel', $n);
                        $enrichie = $n > 0;
                    }
                }
                foreach (['adr' => 'adresse', 'gps' => 'gps'] as $abrev => $type) {
                    if (! isset($p['ajouts'][$type])) {
                        continue;
                    }
                    $n = $appliquer ? $tf->ecrire($workspaceId, $id, $type, $p['ajouts'][$type]) : 1;
                    $compte($p['cat'], $abrev, $n);
                    $enrichie = $enrichie || $n > 0;
                }
                if ($enrichie) {
                    $compte($p['cat'], 'fiches_enrichies');
                }
            }

            return ['par' => $par, 'invalides' => $invalides, 'gps_hors_france' => $locale['gps_hors_france']];
        });

        return $sortie;
    }
}

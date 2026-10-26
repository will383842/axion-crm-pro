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
 *  - un paquet qui échoue n'arrête pas les suivants ; seuls des totaux sont journalisés.
 */
final class TelephonesFiness
{
    public const SOURCE = 'finess';

    private const COLONNES = ['siret', 'categorie', 'etat', 'telephone'];

    /**
     * @return array<string, int> bilan chiffré (aucun numéro, aucun nom)
     */
    public function executer(string $workspaceId, string $fichier, bool $appliquer, int $paquet = 500): array
    {
        $bilan = [
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

                continue;
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
            $fiches = [];
            foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at')
                ->whereIn('id', array_values(array_unique($ids)))->get(['id', 'phone', 'legal_form', 'metadata']) as $c) {
                $fiches[(int) $c->id] = $c;
            }

            // Numéros valides du lot, puis une seule requête d'opposition pour tout le paquet.
            $candidats = [];
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
                if ($this->estPersonnePhysique((string) $f->legal_form, (string) $f->metadata)) {
                    $locale['personnes_physiques']++;

                    continue;
                }
                if (trim((string) $f->phone) !== '') {
                    $locale['deja_renseignees']++;

                    continue;
                }
                $tel = self::normaliser($l['telephone']);
                if ($tel === null) {
                    $locale['invalides']++;

                    continue;
                }
                // Une société à plusieurs officines n'a qu'une fiche : un seul numéro, le premier.
                if (isset($candidats[$id])) {
                    continue;
                }
                $candidats[$id] = $tel;
                array_push($variantes, ...ListeSuppression::variantesTelephone($tel));
            }
            $opposes = $this->opposes(array_values(array_unique($variantes)));

            $aAjouter = 0;
            $ajoutees = 0;
            $ecartes = 0;
            foreach ($candidats as $id => $tel) {
                if (array_intersect(ListeSuppression::variantesTelephone($tel), $opposes) !== []) {
                    $ecartes++;

                    continue;
                }
                $aAjouter++;
                if (! $appliquer) {
                    continue;
                }
                // Condition SQL « phone vide » : jamais d'écrasement, même si la fiche a changé entre-temps.
                $ajoutees += DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $id)
                    ->whereNull('deleted_at')
                    ->where(fn ($q) => $q->whereNull('phone')->orWhereRaw("btrim(phone) = ''"))
                    ->update([
                        'phone' => $tel,
                        'metadata' => DB::raw("COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('telephone_source', '" . self::SOURCE . "')"),
                        'updated_at' => now(),
                    ]);
            }

            foreach ($locale as $cle => $n) {
                $bilan[$cle] += $n;
            }
            $bilan['opposition'] += $ecartes;
            $bilan['a_ajouter'] += $aAjouter;
            $bilan['ajoutees'] += $ajoutees;
        });
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
            foreach (self::COLONNES as $c) {
                $i = array_search($c, $entete, true);
                $ligne[$c] = $i === false ? '' : trim((string) ($row[$i] ?? ''));
            }
            $ligne['etat'] = strtoupper($ligne['etat']);
            $ligne['siret'] = preg_replace('/\s+/', '', $ligne['siret']) ?? '';

            yield $ligne;
        }
    }
}

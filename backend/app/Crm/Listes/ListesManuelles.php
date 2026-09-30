<?php

namespace App\Crm\Listes;

use App\Models\ListeManuelle;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * LES LISTES MANUELLES — ajouter, retirer, et dire qui en est membre.
 *
 * Une seule définition de « appartient à la liste », lue par le constructeur
 * d'audiences (critère `liste_manuelle`) et par le résolveur de destinataires
 * (`App\Crm\Campagnes\ResolveurDestinataires`) :
 *
 *  - une ORGANISATION appartient à une liste si elle y est cochée elle-même,
 *    OU si l'une de ses personnes (non supprimée) y est cochée ;
 *  - une PERSONNE appartient à une liste si elle y est cochée elle-même.
 *
 * Un membre RETIRÉ (`retire_le` posé) n'appartient plus à la liste ; sa ligne
 * reste, et un nouvel ajout la réactive. Rien n'est jamais supprimé ici — ni
 * la fiche, ni la ligne d'appartenance.
 */
final class ListesManuelles
{
    /** Au plus ce nombre de fiches par geste (cocher, retirer) : jamais « tout ». */
    public const MAX_PAR_GESTE = 500;

    /** Au plus ce nombre de listes dans un critère d'audience. */
    public const MAX_LISTES_PAR_CRITERE = 50;

    public const ORIGINE_COCHE = 'coche';

    public const ORIGINE_IMPORT = 'import';

    /**
     * Les organisations membres (directes, ou par une de leurs personnes) des
     * listes données — une sous-requête à poser sous `companies.id IN (…)`.
     *
     * Une UNION de deux ensembles plutôt qu'un `EXISTS … OR …` corrélé :
     * Postgres la hache une fois, au lieu de la rejouer pour chacune des
     * 4,29 M de fiches d'un espace.
     *
     * @param  list<int>  $listeIds
     */
    public static function organisationsMembres(array $listeIds): QueryBuilder
    {
        $directes = DB::table('listes_manuelles_membres as lmm_o')
            ->select('lmm_o.company_id')
            ->whereIn('lmm_o.liste_id', $listeIds)
            ->whereNull('lmm_o.retire_le')
            ->whereNotNull('lmm_o.company_id');

        $parPersonne = DB::table('listes_manuelles_membres as lmm_p')
            ->join('contacts as lmm_ct', 'lmm_ct.id', '=', 'lmm_p.contact_id')
            ->select('lmm_ct.company_id')
            ->whereIn('lmm_p.liste_id', $listeIds)
            ->whereNull('lmm_p.retire_le')
            ->whereNull('lmm_ct.deleted_at');

        return $directes->union($parPersonne);
    }

    /**
     * La même question, pour UNE organisation (évaluateur en mémoire).
     *
     * @param  list<int>  $listeIds
     */
    public static function organisationEstMembre(int $companyId, array $listeIds): bool
    {
        if ($listeIds === []) {
            return false;
        }

        return DB::table('listes_manuelles_membres as lmm_e')
            ->whereIn('lmm_e.liste_id', $listeIds)
            ->whereNull('lmm_e.retire_le')
            ->where(function (QueryBuilder $q) use ($companyId): void {
                $q->where('lmm_e.company_id', $companyId)
                    ->orWhereExists(function (QueryBuilder $s) use ($companyId): void {
                        $s->selectRaw('1')->from('contacts as lmm_ec')
                            ->whereColumn('lmm_ec.id', 'lmm_e.contact_id')
                            ->where('lmm_ec.company_id', $companyId)
                            ->whereNull('lmm_ec.deleted_at');
                    });
            })
            ->exists();
    }

    /**
     * Les PERSONNES cochées (elles-mêmes) dans ces listes, parmi ces
     * organisations.
     *
     * @param  list<int>  $listeIds
     * @param  list<int>  $companyIds
     * @return array<int, true> contact_id => true
     */
    public static function personnesMembres(array $listeIds, array $companyIds): array
    {
        if ($listeIds === [] || $companyIds === []) {
            return [];
        }
        $ids = DB::table('listes_manuelles_membres as lmm_pm')
            ->join('contacts as lmm_pc', 'lmm_pc.id', '=', 'lmm_pm.contact_id')
            ->whereIn('lmm_pm.liste_id', $listeIds)
            ->whereNull('lmm_pm.retire_le')
            ->whereNull('lmm_pc.deleted_at')
            ->whereIn('lmm_pc.company_id', $companyIds)
            ->pluck('lmm_pm.contact_id');

        $membres = [];
        foreach ($ids as $id) {
            $membres[(int) $id] = true;
        }

        return $membres;
    }

    /**
     * Les listes VIVANTES de l'espace parmi ces identifiants.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function existantes(string $workspaceId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ListeManuelle::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Ajoute des fiches à une liste. Seules les fiches VIVANTES de l'espace de
     * la liste sont retenues ; les autres identifiants sont comptés
     * `introuvables` — jamais créés, jamais devinés.
     *
     * @param  list<int>  $companyIds
     * @param  list<int>  $contactIds
     * @return array{ajoutes: int, reactives: int, deja_presents: int, introuvables: int}
     */
    public static function ajouter(ListeManuelle $liste, array $companyIds, array $contactIds, ?string $par, string $origine): array
    {
        $ws = (string) $liste->workspace_id;
        $companyIds = self::entiers($companyIds);
        $contactIds = self::entiers($contactIds);

        $companiesVivantes = $companyIds === [] ? [] : self::entiers(DB::table('companies')
            ->whereNull('deleted_at')
            ->where('workspace_id', $ws)
            ->whereIn('id', $companyIds)
            ->pluck('id')
            ->all());
        $contactsVivants = $contactIds === [] ? [] : self::entiers(DB::table('contacts')
            ->whereNull('deleted_at')
            ->where('workspace_id', $ws)
            ->whereIn('id', $contactIds)
            ->pluck('id')
            ->all());

        $bilan = [
            'ajoutes' => 0,
            'reactives' => 0,
            'deja_presents' => 0,
            'introuvables' => (count($companyIds) - count($companiesVivantes)) + (count($contactIds) - count($contactsVivants)),
        ];

        DB::transaction(function () use ($liste, $ws, $companiesVivantes, $contactsVivants, $par, $origine, &$bilan): void {
            foreach (['company_id' => $companiesVivantes, 'contact_id' => $contactsVivants] as $colonne => $ids) {
                foreach (array_chunk($ids, 1000) as $paquet) {
                    $existants = DB::table('listes_manuelles_membres')
                        ->where('liste_id', $liste->id)
                        ->whereIn($colonne, $paquet)
                        ->get([$colonne, 'retire_le']);
                    $actifs = [];
                    $retires = [];
                    foreach ($existants as $e) {
                        if ($e->retire_le === null) {
                            $actifs[] = (int) $e->{$colonne};
                        } else {
                            $retires[] = (int) $e->{$colonne};
                        }
                    }
                    $bilan['deja_presents'] += count($actifs);

                    if ($retires !== []) {
                        $bilan['reactives'] += DB::table('listes_manuelles_membres')
                            ->where('liste_id', $liste->id)
                            ->whereIn($colonne, $retires)
                            ->whereNotNull('retire_le')
                            ->update([
                                'retire_le' => null, 'retire_par' => null,
                                'ajoute_le' => now(), 'ajoute_par' => $par, 'origine' => $origine,
                            ]);
                    }

                    $nouveaux = array_values(array_diff($paquet, $actifs, $retires));
                    if ($nouveaux === []) {
                        continue;
                    }
                    $lignes = array_map(static fn (int $id): array => [
                        'workspace_id' => $ws,
                        'liste_id' => $liste->id,
                        $colonne => $id,
                        'origine' => $origine,
                        'ajoute_le' => now(),
                        'ajoute_par' => $par,
                    ], $nouveaux);
                    $bilan['ajoutes'] += DB::table('listes_manuelles_membres')->insertOrIgnore($lignes);
                }
            }
            $liste->touch();
        });

        return $bilan;
    }

    /**
     * Retire des fiches d'une liste : la ligne d'appartenance reçoit
     * `retire_le`. Ni la fiche ni la ligne ne sont supprimées.
     *
     * @param  list<int>  $companyIds
     * @param  list<int>  $contactIds
     * @return array{retires: int, absents: int}
     */
    public static function retirer(ListeManuelle $liste, array $companyIds, array $contactIds, ?string $par): array
    {
        $companyIds = self::entiers($companyIds);
        $contactIds = self::entiers($contactIds);
        $retires = 0;

        DB::transaction(function () use ($liste, $companyIds, $contactIds, $par, &$retires): void {
            foreach (['company_id' => $companyIds, 'contact_id' => $contactIds] as $colonne => $ids) {
                if ($ids === []) {
                    continue;
                }
                $retires += DB::table('listes_manuelles_membres')
                    ->where('liste_id', $liste->id)
                    ->whereIn($colonne, $ids)
                    ->whereNull('retire_le')
                    ->update(['retire_le' => now(), 'retire_par' => $par]);
            }
            $liste->touch();
        });

        return ['retires' => $retires, 'absents' => count($companyIds) + count($contactIds) - $retires];
    }

    /**
     * @param  array<mixed>  $valeurs
     * @return list<int>
     */
    public static function entiers(array $valeurs): array
    {
        $ids = [];
        foreach ($valeurs as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $n = (int) $v;
                if ($n > 0) {
                    $ids[$n] = $n;
                }
            }
        }

        return array_values($ids);
    }
}

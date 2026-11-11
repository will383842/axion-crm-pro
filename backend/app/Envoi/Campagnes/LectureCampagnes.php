<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lecture du suivi des campagnes (R09) : liste, calendrier, fiche, destinataires. LECTURE SEULE, aucune écriture.
 * Tant que `sequence_id` est nul (toujours, ce dépôt ne l'écrit jamais), il n'y a ni inscription ni destinataire : tous les
 * compteurs sont 0 et la liste des destinataires est vide. Les compteurs réels viendront de R04 (non fusionné ici).
 */
final class LectureCampagnes
{
    public const PAR_PAGE_MAX = 100;

    public const CALENDRIER_JOURS_MAX = 62;

    public const STATUTS = ['brouillon', 'a_valider', 'programmee', 'en_cours', 'en_pause', 'terminee', 'arretee'];

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>, par_statut: array<string, int>} */
    public function liste(string $ws, ?string $statut, ?string $recherche, int $page, int $parPage): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $statut, $recherche, $page, $parPage): array {
            $base = fn () => DB::table('envoi_campagnes')->where('workspace_id', $ws)
                ->when($recherche !== null && $recherche !== '', fn ($q) => $q->whereRaw("nom ILIKE ? ESCAPE '\\'", ['%' . addcslashes($recherche, '%_\\') . '%']));
            $parStatut = array_fill_keys(self::STATUTS, 0);
            foreach ($base()->selectRaw('statut, count(*) AS n')->groupBy('statut')->get() as $l) {
                $parStatut[$l->statut] = (int) $l->n;
            }
            $total = array_sum($parStatut);
            $lignes = $base()->when($statut !== null, fn ($q) => $q->where('statut', $statut))
                ->orderByDesc('cree_le')->orderBy('id')->offset(($page - 1) * $parPage)->limit($parPage)->get();
            $filtre = $statut === null ? $total : $parStatut[$statut];

            return [
                'data' => $lignes->map(fn ($c) => self::exposer($c))->all(),
                'meta' => ['page' => $page, 'par_page' => $parPage, 'total' => $filtre, 'pages' => (int) ceil($filtre / $parPage)],
                'par_statut' => $parStatut,
            ];
        });
    }

    /**
     * Une ligne par campagne dont la date prévue tombe dans la plage. `prevus` reste 0 : sans séquence, aucun destinataire.
     *
     * @return list<array<string, mixed>>
     */
    public function calendrier(string $ws, CarbonImmutable $du, CarbonImmutable $au): array
    {
        return WorkspaceContext::run($ws, fn () => DB::table('envoi_campagnes')->where('workspace_id', $ws)->whereNotNull('envoi_prevu_le')
            ->where('envoi_prevu_le', '>=', $du->startOfDay())->where('envoi_prevu_le', '<', $au->addDay()->startOfDay())
            ->orderBy('envoi_prevu_le')->orderBy('id')->get(['id', 'nom', 'statut', 'envoi_prevu_le'])
            ->map(fn ($c) => ['jour' => CarbonImmutable::parse($c->envoi_prevu_le)->format('Y-m-d'), 'campagne_id' => $c->id, 'nom' => $c->nom, 'statut' => $c->statut, 'prevus' => 0, 'envoyes' => 0])
            ->all());
    }

    /** @return array<string, mixed> forme `lireCampagne` du contrat */
    public function fiche(string $ws, string $id): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $id): array {
            $c = DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $id)->first() ?? throw CampagneRefusee::introuvable();
            $ids = self::boites($c->boites);
            $adresses = $ids === [] ? [] : DB::table('envoi_boites')->where('workspace_id', $ws)->whereIn('id', $ids)->pluck('adresse', 'id')->all();
            $parBoite = [];
            foreach ($ids as $b) {
                if (isset($adresses[$b])) {
                    $parBoite[] = ['boite_id' => $b, 'adresse' => $adresses[$b], 'envoyes' => 0, 'reponses' => 0, 'refus' => 0];
                }
            }

            return [
                'maintenant' => CarbonImmutable::now()->toIso8601ZuluString(),
                'campagne' => self::exposer($c),
                'sequence' => [],
                'derniers_evenements' => [],
                'compteurs' => array_fill_keys(['en_file', 'partis', 'refuses', 'ont_repondu', 'exclus', 'desinscrits', 'arretes_entreprise', 'en_pause_absence'], 0)
                    + ['taux_refus' => 0, 'limite_refus' => 0, 'taux_desinscription' => 0],
                'par_boite' => $parBoite,
                'par_jour' => [],
                'ab' => null,
                'fin_prevue_le' => null,
            ];
        });
    }

    /** Existence vérifiée (404 sinon) ; liste vide tant que la campagne n'a pas de séquence. */
    public function existe(string $ws, string $id): void
    {
        WorkspaceContext::run($ws, fn () => DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $id)->exists()) || throw CampagneRefusee::introuvable();
    }

    /** @return array<string, mixed> forme `CampagneSuivi` du contrat */
    public static function exposer(\stdClass $c): array
    {
        $cible = (array) json_decode($c->cible, true);

        return [
            'id' => $c->id, 'nom' => $c->nom, 'statut' => $c->statut,
            'cible' => ['type' => $cible['type'], 'reference' => $cible['reference'], 'libelle' => $cible['libelle'], 'total' => 0],
            'envoyes' => 0, 'total' => 0, 'reponses' => 0, 'reponses_interessees' => 0,
            'prochain_envoi_le' => $c->envoi_prevu_le === null ? null : CarbonImmutable::parse($c->envoi_prevu_le)->utc()->toIso8601ZuluString(),
            'cree_le' => CarbonImmutable::parse($c->cree_le)->utc()->toIso8601ZuluString(),
            'boites' => self::boites($c->boites), 'etapes' => (int) $c->nb_etapes,
            'envoi_ferme' => ! (bool) config('envoi.reel_actif'), 'version' => (int) $c->version,
        ];
    }

    /** @return list<string> */
    private static function boites(string $pg): array
    {
        $interieur = trim($pg, '{}');

        return $interieur === '' ? [] : explode(',', $interieur);
    }
}

<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lecture du suivi des campagnes (R09) : liste, calendrier, fiche, destinataires. LECTURE SEULE, aucune écriture.
 * Les compteurs viennent de `CompteursCampagne` (L6-A, lecture seule) : une requête pour toute la page de la liste, une pour la fiche.
 * Une campagne sans séquence (ou d'un autre espace) a des compteurs à 0 ; la liste des destinataires reste vide (liste nominative : hors lot).
 * Les gestes rendent la forme `CampagneSuivi` avec des zéros (`exposer` sans compteurs).
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
                ->when($recherche !== null && $recherche !== '', fn ($q) => $q->whereRaw("nom ILIKE ? ESCAPE '\\'", ['%' . addcslashes((string) $recherche, '%_\\') . '%']));
            $parStatut = array_fill_keys(self::STATUTS, 0);
            foreach ($base()->selectRaw('statut, count(*) AS n')->groupBy('statut')->get() as $l) {
                $parStatut[$l->statut] = (int) $l->n;
            }
            $total = array_sum($parStatut);
            $lignes = $base()->when($statut !== null, fn ($q) => $q->where('statut', $statut))
                ->orderByDesc('cree_le')->orderBy('id')->offset(($page - 1) * $parPage)->limit($parPage)->get();
            $filtre = $statut === null ? $total : $parStatut[$statut];

            $compteurs = (new CompteursCampagne)->pourListe($ws, array_values(array_map(static fn (\stdClass $l): string => (string) $l->id, $lignes->all())));
            $blocages = (new RaisonBlocage)->pour($ws, array_values($lignes->all()));

            return [
                'data' => array_values($lignes->map(fn ($c) => self::exposer($c, $compteurs[$c->id] ?? null, $blocages[$c->id] ?? null))->all()),
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
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_campagnes')->where('workspace_id', $ws)->whereNotNull('envoi_prevu_le')
            ->where('envoi_prevu_le', '>=', $du->startOfDay())->where('envoi_prevu_le', '<', $au->addDay()->startOfDay())
            ->orderBy('envoi_prevu_le')->orderBy('id')->get(['id', 'nom', 'statut', 'envoi_prevu_le'])
            ->map(fn ($c) => ['jour' => CarbonImmutable::parse($c->envoi_prevu_le)->format('Y-m-d'), 'campagne_id' => $c->id, 'nom' => $c->nom, 'statut' => $c->statut, 'prevus' => 0, 'envoyes' => 0])
            ->all());

        return array_values($lignes);
    }

    /** @return array<string, mixed> forme `lireCampagne` du contrat */
    public function fiche(string $ws, string $id): array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $id): array {
            $c = DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $id)->first() ?? throw CampagneRefusee::introuvable();
            $n = (new CompteursCampagne)->pour($ws, $id);
            $blocage = (new RaisonBlocage)->pour($ws, [$c])[$c->id] ?? null;
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
                'campagne' => self::exposer($c, $n, $blocage),
                'sequence' => [],
                'derniers_evenements' => [],
                'compteurs' => self::compteurs($n),
                'preparation' => $n['preparation'],
                'par_boite' => $parBoite,
                'par_jour' => [],
                'ab' => null,
                'fin_prevue_le' => null,
            ];
        });
    }

    /**
     * Forme `compteurs` du contrat : les clés historiques sont reliées aux définitions de L6-A (`partis` = touches, `ont_repondu` = reponses,
     * `desinscrits` = oppositions, `exclus` = exclus_preparation, `en_file` = actives) ; `en_pause_absence` reste 0 (l'état n'existe pas) ;
     * `limite_refus` reste 0 (hors périmètre). `suivi_ouvertures` n'est PAS exposé : la garde `aucune-ouverture-envoi` interdit ce mot dans les contrats (ESCALADE, voir L6-B.md) ; `ouverts` nul suffit à dire « non suivi ».
     *
     * @param  array<string, mixed>  $n  sortie de `CompteursCampagne`
     * @return array<string, mixed>
     */
    private static function compteurs(array $n): array
    {
        return [
            'en_file' => $n['actives'], 'partis' => $n['touches'], 'refuses' => $n['refuses'], 'ont_repondu' => $n['reponses'], 'exclus' => $n['exclus_preparation'],
            'desinscrits' => $n['oppositions'], 'arretes_entreprise' => $n['arretes_entreprise'], 'en_pause_absence' => 0,
            'taux_refus' => $n['taux_refus'], 'limite_refus' => 0, 'taux_desinscription' => $n['taux_desinscription'],
            'inscrits' => $n['inscrits'], 'envoyes' => $n['envoyes'], 'touches' => $n['touches'], 'ouverts' => $n['ouverts'],
            'reponses' => $n['reponses'], 'rebonds_durs' => $n['rebonds_durs'], 'rebonds_mous' => $n['rebonds_mous'], 'oppositions' => $n['oppositions'],
            'arretes_campagne' => $n['arretes_campagne'], 'exclus_preparation' => $n['exclus_preparation'], 'issues' => $n['issues'],
        ];
    }

    /** Existence vérifiée (404 sinon) ; liste vide tant que la campagne n'a pas de séquence. */
    public function existe(string $ws, string $id): void
    {
        WorkspaceContext::run($ws, fn () => DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $id)->exists()) || throw CampagneRefusee::introuvable();
    }

    /**
     * @param  array<string, mixed>|null  $compteurs  sortie de `CompteursCampagne` ; absente = zéros (les gestes rendent toujours la forme)
     * @param  array{code: string, message: string}|null  $blocage  sortie de `RaisonBlocage` (B8-A) ; absent = null (les gestes ne le calculent pas)
     * @return array<string, mixed> forme `CampagneSuivi` du contrat
     */
    public static function exposer(\stdClass $c, ?array $compteurs = null, ?array $blocage = null): array
    {
        $cible = (array) json_decode($c->cible, true);

        return [
            'id' => $c->id, 'nom' => $c->nom, 'statut' => $c->statut,
            'cible' => ['type' => $cible['type'], 'reference' => $cible['reference'], 'libelle' => $cible['libelle'], 'total' => 0],
            'envoyes' => (int) ($compteurs['envoyes'] ?? 0), 'total' => (int) ($compteurs['inscrits'] ?? 0), 'reponses' => (int) ($compteurs['reponses'] ?? 0), 'reponses_interessees' => 0,
            'prochain_envoi_le' => $c->envoi_prevu_le === null ? null : CarbonImmutable::parse($c->envoi_prevu_le)->utc()->toIso8601ZuluString(),
            'cree_le' => CarbonImmutable::parse($c->cree_le)->utc()->toIso8601ZuluString(),
            'boites' => self::boites($c->boites), 'etapes' => (int) $c->nb_etapes,
            'envoi_ferme' => ! (bool) config('envoi.reel_actif'), 'version' => (int) $c->version, 'blocage' => $blocage,
        ];
    }

    /** @return list<string> */
    private static function boites(string $pg): array
    {
        $interieur = trim($pg, '{}');

        return $interieur === '' ? [] : explode(',', $interieur);
    }
}

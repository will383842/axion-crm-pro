<?php

namespace App\Http\Controllers\Api;

use App\Models\Journalist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RATTACHEMENT des contacts presse à un média.
 *
 * ── Le problème, chiffré ──────────────────────────────────────────────────
 * Le fichier presse apporte **373 chaînes `media_raw` distinctes** pour 412
 * contacts (11 groupes seulement en partagent une). En face, `media` compte
 * **55 830 lignes** — et ce n'est pas un annuaire de rédactions : surtout des
 * entités juridiques SIRENE, des émissions Wikidata, et quelques marques. Une
 * recherche « TF1 » y rend **22 candidats dont 2** sont la rédaction visée.
 *
 * Un rapprochement automatique se tromperait donc environ 9 fois sur 10, et un
 * `media_id` faux est pire qu'un `media_id` vide : il fait disparaître la
 * question au lieu de la poser. **Cet écran propose, l'humain tranche.**
 *
 * ── Trois piles, parce que 373 lignes identiques feraient abandonner ──────
 * Traiter les 373 cas de la même façon, c'est s'arrêter au centième. Le tri est
 * automatique et sépare trois natures de travail :
 *
 *   - **Pile A — aucun média dans la chaîne.** « Journaliste » (25 contacts à
 *     lui seul), « Rédacteur en chef », « Journaliste tech & droit ». Il n'y a
 *     rien à rattacher : la chaîne ne nomme aucun média. Ces fiches ne sont pas
 *     un arbitrage, c'est de la RECHERCHE — les mêler aux autres ferait perdre
 *     du temps à chaque écran.
 *   - **Pile B — une évidence.** Un seul candidat au-dessus du seuil. Validable
 *     en lot.
 *   - **Pile C — plusieurs candidats, ou aucun.** Le vrai travail d'arbitrage.
 *
 * ⚠️ Le classement en pile A est une HEURISTIQUE (la chaîne ne contient que des
 * mots de métier). Elle se trompera. L'écran doit donc permettre de chercher un
 * média à la main sur n'importe quelle ligne, y compris en pile A : une pile
 * est une aide au tri, jamais un verdict.
 *
 * ── Comment les candidats sont trouvés ────────────────────────────────────
 * `media_raw` n'est pas un nom de média, c'est un blob (« TF1 - tech, sciences,
 * innovation (prix internationaux) »). Une similarité trigramme brute entre ce
 * blob et « TF1 » est proche de zéro — la comparaison est noyée par les mots
 * qui ne concernent pas le média.
 *
 * D'où deux étages :
 *   1. **Le crible**, index-able : `name_normalized % <sonde>`, où les sondes
 *      sont des fragments COURTS extraits du blob (avant le séparateur, puis
 *      les 2 et 3 premiers mots). C'est ce que l'index GIN trigramme accélère,
 *      et c'est la condition pour que l'écran s'affiche en moins d'une seconde.
 *   2. **Le score**, exact : `word_similarity(name_normalized, blob)`, qui
 *      mesure à quel point le nom du média se retrouve DANS le blob, où qu'il
 *      s'y trouve. Sans index, mais appliqué à quelques dizaines de lignes
 *      seulement, celles que le crible a laissées passer.
 *
 * Et le filtre qui fait le plus de travail : **`audiovisual_production` est
 * exclue par défaut**. Un journaliste ne travaille pas pour une société de
 * production. Sur « TF1 », ce seul filtre fait tomber 22 candidats à ~8.
 */
class JournalistAttachmentController extends ApiController
{
    /** Au-delà, un candidat unique est considéré comme une évidence (pile B). */
    private const SEUIL_EVIDENCE = 0.75;

    /** Nombre maximum de candidats montrés. Au-delà, on n'aide plus, on noie. */
    private const MAX_CANDIDATS = 5;

    /**
     * Mots qui décrivent un MÉTIER, pas un média.
     *
     * Une chaîne qui n'en contient pas d'autres ne nomme aucune rédaction : la
     * rapprocher des 55 830 médias ne rendrait que du bruit. La liste est
     * volontairement courte et sans accents (elle s'applique après
     * normalisation) — l'allonger indéfiniment ferait basculer en pile A des
     * chaînes qui nomment un vrai média, ce qui est l'erreur coûteuse : une
     * fiche rangée en « rien à rattacher » ne revient plus devant les yeux.
     *
     * @var list<string>
     */
    private const MOTS_METIER = [
        'journaliste', 'journalistes', 'redacteur', 'redactrice', 'redaction',
        'chef', 'cheffe', 'pigiste', 'reporter', 'grand', 'chroniqueur',
        'chroniqueuse', 'presentateur', 'presentatrice', 'correspondant',
        'correspondante', 'directeur', 'directrice', 'freelance', 'independant',
        'independante', 'editorialiste', 'en', 'de', 'du', 'la', 'le', 'les',
        'et', 'a', 'au', 'aux', 'des', 'un', 'une', 'pour', 'sur', 'specialise',
        'specialisee', 'senior', 'adjoint', 'adjointe',
    ];

    /**
     * Les groupes `media_raw` restant à rattacher, avec leurs candidats.
     */
    public function index(Request $request): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId || ! Schema::hasTable('journalists')) {
            return $this->ok(['data' => [], 'meta' => ['total' => 0]]);
        }

        $pileVoulue = $request->query('pile');
        $perPage = min(50, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        // Un groupe = une chaîne `media_raw`. On agrège en base plutôt qu'en
        // PHP : sur 412 fiches la différence est nulle, mais cet écran servira
        // aux imports suivants, et charger toutes les fiches pour les regrouper
        // en mémoire est le genre de raccourci qui tient jusqu'au jour où il ne
        // tient plus.
        $groupes = DB::table('journalists')
            ->select('media_raw', DB::raw('count(*) as nb'), DB::raw('min(id) as premier_id'))
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->whereNull('media_id')
            ->whereNotNull('media_raw')
            ->groupBy('media_raw')
            ->orderByRaw('count(*) DESC, media_raw ASC')
            ->get();

        $resultats = [];
        foreach ($groupes as $groupe) {
            $raw = (string) $groupe->media_raw;

            if ($this->sansMedia($raw)) {
                $pile = 'a';
                $candidats = [];
            } else {
                $candidats = $this->candidats($workspaceId, $raw);
                $evidence = count($candidats) === 1
                    || (count($candidats) > 1 && $candidats[0]->similarite >= self::SEUIL_EVIDENCE
                        && $candidats[1]->similarite < self::SEUIL_EVIDENCE);
                $pile = $evidence ? 'b' : 'c';
            }

            if ($pileVoulue !== null && $pileVoulue !== 'all' && $pile !== $pileVoulue) {
                continue;
            }

            $resultats[] = [
                'media_raw' => $raw,
                'nb_contacts' => (int) $groupe->nb,
                'pile' => $pile,
                'contacts' => $this->contacts($workspaceId, $raw),
                'candidats' => $candidats,
            ];
        }

        $total = count($resultats);

        return $this->ok([
            'data' => array_slice($resultats, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                // Les trois volumes, toujours renvoyés : c'est ce qui permet à
                // l'écran d'annoncer « il reste 37 arbitrages » plutôt qu'une
                // barre de progression qui ne dit pas ce qu'elle mesure.
                'piles' => $this->volumes($resultats),
            ],
        ]);
    }

    /**
     * Rattache toutes les fiches d'un groupe à un média.
     *
     * ⚠️ LE POINT DÉLICAT. Renseigner `media_id` CHANGE la clé `dedup_key`
     * (elle bascule de `nom@media_raw` à `nom@media_id`). Deux fiches jusque-là
     * distinctes peuvent donc devenir identiques au moment même du
     * rattachement. C'est voulu — c'est là que le doublon se révèle — mais ça
     * doit se présenter comme UNE FUSION À CONFIRMER, jamais comme une erreur
     * technique : l'utilisateur vient de faire le bon geste, il ne doit pas
     * recevoir une 500 en récompense.
     */
    public function store(Request $request): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $data = $request->validate([
            'media_raw' => ['required', 'string', 'max:500'],
            'media_id' => ['required', 'integer', 'exists:media,id'],
        ]);

        $collisions = $this->collisions($workspaceId, $data['media_raw'], (int) $data['media_id']);
        if ($collisions !== [] && ! $request->boolean('force')) {
            return $this->ok([
                'error' => 'fusion_requise',
                'message' => 'Ce rattachement rend certaines fiches identiques. '
                    . 'Vérifiez : ce sont soit des doublons à fusionner, soit de vrais homonymes.',
                'collisions' => $collisions,
                'force_param' => 'force=1',
            ], 409);
        }

        $misesAJour = DB::transaction(function () use ($workspaceId, $data) {
            $n = Journalist::query()
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->whereNull('media_id')
                ->where('media_raw', $data['media_raw'])
                ->update(['media_id' => (int) $data['media_id']]);

            $this->promouvoirVersLeMedia($workspaceId, (int) $data['media_id'], $data['media_raw']);

            return $n;
        });

        return $this->ok([
            'fiches_rattachees' => $misesAJour,
            'restant' => $this->restant($workspaceId),
        ]);
    }

    /**
     * La chaîne ne nomme-t-elle QUE des métiers ?
     *
     * Heuristique assumée : on retire les mots de métier et les mots-outils ; ce
     * qui reste est-il vide ? « Journaliste tech & droit » → « tech droit »
     * subsiste, donc ce n'est PAS pile A (et c'est correct : « tech » pourrait
     * appartenir à un titre). « Rédacteur en chef » → rien, donc pile A.
     */
    private function sansMedia(string $raw): bool
    {
        $normalise = $this->normaliser($raw);
        $restants = array_filter(
            preg_split('/\s+/', $normalise) ?: [],
            fn (string $mot): bool => $mot !== ''
                && mb_strlen($mot) > 1
                && ! in_array($mot, self::MOTS_METIER, true),
        );

        return $restants === [];
    }

    /**
     * Candidats médias pour une chaîne brute — crible indexé, puis score exact.
     *
     * @return list<object>
     */
    private function candidats(string $workspaceId, string $raw): array
    {
        if (! Schema::hasTable('media') || ! Schema::hasColumn('media', 'name_normalized')) {
            return [];
        }

        $sondes = $this->sondes($raw);
        if ($sondes === []) {
            return [];
        }

        // Le crible : une clause `%` par sonde, toutes en OR. C'est cette forme
        // que l'index GIN trigramme sait servir — un `word_similarity(...) > x`
        // en WHERE ne serait pas indexable et balaierait les 55 830 lignes.
        $crible = implode(' OR ', array_fill(0, count($sondes), 'm.name_normalized % ?'));

        $sql = "
            SELECT m.id, m.name, m.media_type, m.media_family, m.city,
                   m.department_code, m.email, m.cppap_number, m.arcom_id,
                   round(word_similarity(m.name_normalized, normalize_name(?))::numeric, 3) AS similarite
            FROM media m
            WHERE m.workspace_id = ?
              AND m.deleted_at IS NULL
              -- Un journaliste ne travaille pas pour une société de production.
              -- `IS DISTINCT FROM` et non `<>` : une famille NULL doit RESTER
              -- candidate — l'écarter ferait disparaître les médias non encore
              -- classés, qui sont souvent les plus pertinents.
              AND m.media_family IS DISTINCT FROM 'audiovisual_production'
              AND ({$crible})
            ORDER BY similarite DESC, length(m.name) ASC
            LIMIT " . self::MAX_CANDIDATS;

        $params = array_merge([$raw, $workspaceId], $sondes);

        return DB::select($sql, $params);
    }

    /**
     * Fragments COURTS extraits du blob, servant de sondes indexées.
     *
     * Trois formes, parce qu'aucune ne suffit seule : le nom du média est tantôt
     * avant un séparateur (« Le Figaro Économie - rédactrice en chef »), tantôt
     * les premiers mots sans séparateur (« Les Échos »), tantôt absent du début
     * (« Analyste IA, Journal du Net »). Le troisième cas est justement ce que
     * le score `word_similarity` rattrape sur les lignes que ces sondes ont
     * laissées passer.
     *
     * @return list<string>
     */
    private function sondes(string $raw): array
    {
        $normalise = $this->normaliser($raw);
        $mots = array_values(array_filter(preg_split('/\s+/', $normalise) ?: []));
        if ($mots === []) {
            return [];
        }

        $sondes = [];

        // Avant le premier séparateur explicite.
        $avant = preg_split('/\s+[-–—,\/]\s+/', $normalise);
        if (is_array($avant) && $avant !== [] && trim($avant[0]) !== '' && $avant[0] !== $normalise) {
            $sondes[] = trim($avant[0]);
        }

        // Les 2 et 3 premiers mots.
        $sondes[] = implode(' ', array_slice($mots, 0, 2));
        if (count($mots) >= 3) {
            $sondes[] = implode(' ', array_slice($mots, 0, 3));
        }

        return array_values(array_unique(array_filter(
            $sondes,
            fn (string $s): bool => mb_strlen($s) >= 3,
        )));
    }

    /**
     * Fiches devenant identiques à une autre une fois rattachées.
     *
     * On calcule la clé FUTURE (`nom@media_id`) et on cherche qui la porte déjà.
     * Le calcul est délégué à Postgres, comme partout ailleurs : réimplémenter
     * `normalize_name` en PHP créerait une seconde vérité, et le jour où les
     * deux divergent, ce contrôle laisse passer ce qu'il devait arrêter.
     *
     * @return list<object>
     */
    private function collisions(string $workspaceId, string $raw, int $mediaId): array
    {
        if (! Schema::hasColumn('journalists', 'dedup_key')) {
            return [];
        }

        return DB::select("
            WITH futur AS (
                SELECT j.id,
                       coalesce(j.first_name, '') || ' ' || coalesce(j.last_name, '') AS nom,
                       encode(digest(
                           normalize_name(coalesce(j.first_name,'') || '_' || coalesce(j.last_name,''))
                           || '@' || ?::TEXT,
                           'sha256'
                       ), 'hex') AS cle
                FROM journalists j
                WHERE j.workspace_id = ?
                  AND j.deleted_at IS NULL
                  AND j.media_id IS NULL
                  AND j.media_raw = ?
            )
            SELECT f.id AS fiche_id, f.nom AS fiche_nom,
                   e.id AS existant_id,
                   coalesce(e.first_name,'') || ' ' || coalesce(e.last_name,'') AS existant_nom
            FROM futur f
            JOIN journalists e
              ON e.workspace_id = ?
             AND e.deleted_at IS NULL
             AND e.id <> f.id
             AND e.dedup_key = f.cle
        ", [$mediaId, $workspaceId, $raw, $workspaceId]);
    }

    /**
     * Promeut vers le média ce que la fiche portait en transit — SANS écraser.
     *
     * `media_portee_raw` / `media_support_raw` viennent d'un fichier LinkedIn ;
     * `media.diffusion_zone` / `media.media_type` viennent, quand ils existent,
     * du CPPAP, de l'ARCOM ou de l'INSEE. Écraser les seconds par les premiers
     * remplacerait une donnée officielle par une déduction — d'où le
     * `WHERE ... IS NULL` : on ne comble qu'un vide.
     */
    private function promouvoirVersLeMedia(string $workspaceId, int $mediaId, string $raw): void
    {
        $source = DB::table('journalists')
            ->where('workspace_id', $workspaceId)
            ->where('media_raw', $raw)
            ->whereNotNull('media_portee_raw')
            ->first(['media_portee_raw']);

        $zone = match ($source->media_portee_raw ?? null) {
            'National' => 'national',
            'AURA', 'Régional' => 'régional',
            default => null,
        };

        if ($zone !== null) {
            DB::table('media')
                ->where('id', $mediaId)
                ->whereNull('diffusion_zone')
                ->update(['diffusion_zone' => $zone]);
        }
    }

    /** @return list<object> */
    private function contacts(string $workspaceId, string $raw): array
    {
        return DB::table('journalists')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->whereNull('media_id')
            ->where('media_raw', $raw)
            ->orderBy('priorite')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'role', 'beat', 'acces', 'priorite'])
            ->all();
    }

    private function restant(string $workspaceId): int
    {
        return DB::table('journalists')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->whereNull('media_id')
            ->whereNotNull('media_raw')
            ->distinct()
            ->count('media_raw');
    }

    /**
     * @param  list<array{pile: string, nb_contacts: int}>  $resultats
     * @return array<string, int>
     */
    private function volumes(array $resultats): array
    {
        $v = ['a' => 0, 'b' => 0, 'c' => 0];
        foreach ($resultats as $r) {
            $v[$r['pile']] = ($v[$r['pile']] ?? 0) + 1;
        }

        return $v;
    }

    private function normaliser(string $texte): string
    {
        $sansAccent = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
        $base = is_string($sansAccent) ? $sansAccent : $texte;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($base)) ?? '');
    }
}

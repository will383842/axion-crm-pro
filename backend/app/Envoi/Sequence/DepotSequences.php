<?php

namespace App\Envoi\Sequence;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Séquences : lecture (compteurs seulement, jamais un corps de message ni une personne) et gestes pause / reprise (D6).
 * L'état d'une séquence est son DERNIER geste dans `envoi_sequences_gestes` (ajout seul). Tout est cloisonné par espace
 * (filtre explicite `workspace_id` en plus de la RLS). Un geste se prend sous un verrou consultatif par séquence : pause et
 * reprise qui se croisent passent l'une après l'autre. Rien ici ne fait partir un message ni ne résout `ServeurEnvoi`.
 * La reprise refuse sous arrêt général ou sous une pause active (frein, pause automatique) et ne lève JAMAIS un frein.
 */
final class DepotSequences
{
    public function __construct(private readonly DepotSocle $socle, private readonly DepotChauffe $chauffe) {}

    /** @return list<array<string, mixed>> */
    public function lister(string $ws): array
    {
        return WorkspaceContext::run($ws, function () use ($ws): array {
            $temoins = $this->socle->modeTemoins($ws);
            $out = [];
            foreach (DB::table('envoi_sequences')->where('workspace_id', $ws)->orderByDesc('cree_le')->orderBy('id')->limit(500)->get() as $s) {
                $out[] = $this->resume($ws, $s, $temoins);
            }

            return $out;
        });
    }

    /** @return array<string, mixed>|null */
    public function lire(string $ws, string $id): ?array
    {
        return WorkspaceContext::run($ws, function () use ($ws, $id): ?array {
            $s = DB::table('envoi_sequences')->where('workspace_id', $ws)->where('id', $id)->first();

            return $s === null ? null : $this->detail($ws, $s, $this->socle->modeTemoins($ws));
        });
    }

    /**
     * Pause ou reprise. Rend la fiche après le geste, ou null si la séquence n'est pas de cet espace.
     * Idempotent : un geste déjà en vigueur n'écrit aucune ligne. @throws CampagneRefusee 409 si la reprise est bloquée.
     *
     * @return array<string, mixed>|null
     */
    public function geste(string $ws, string $id, string $geste, string $auteur): ?array
    {
        return WorkspaceContext::run($ws, fn (): ?array => DB::transaction(function () use ($ws, $id, $geste, $auteur): ?array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_sequence_geste|' . $ws . '|' . $id]);
            $s = DB::table('envoi_sequences')->where('workspace_id', $ws)->where('id', $id)->first();
            if ($s === null) {
                return null;
            }
            $enPause = $this->enPause($ws, $id);
            if ($geste === 'reprise' && $enPause && ($raison = $this->blocage($ws)) !== null) {
                throw CampagneRefusee::frein($raison[0], $raison[1]);
            }
            if ($enPause !== ($geste === 'pause')) {
                // `clock_timestamp()` : deux gestes d'une même transaction restent ordonnés (now() est figé par transaction).
                DB::table('envoi_sequences_gestes')->insert([
                    'workspace_id' => $ws, 'sequence_id' => $id, 'geste' => $geste, 'auteur' => mb_substr($auteur, 0, 100),
                    'cree_le' => DB::raw('clock_timestamp()'),
                ]);
            }

            return $this->detail($ws, $s, $this->socle->modeTemoins($ws));
        }));
    }

    private function enPause(string $ws, string $id): bool
    {
        return DB::table('envoi_sequences_gestes')->where('workspace_id', $ws)->where('sequence_id', $id)
            ->orderByDesc('cree_le')->orderByDesc('id')->value('geste') === 'pause';
    }

    /** @return array{0: string, 1: string}|null [code, raison] si une reprise est impossible maintenant. */
    private function blocage(string $ws): ?array
    {
        if ($this->socle->toutArreter($ws)) {
            return ['frein_arret_general', 'Reprise impossible : l’arrêt général est armé pour cet espace.'];
        }
        if ($this->chauffe->pausesActives($ws, new DateTimeImmutable('now')) !== []) {
            return ['frein_pause_automatique', 'Reprise impossible : une pause automatique est active (frein de réputation ou de chauffe).'];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function resume(string $ws, \stdClass $s, bool $temoins): array
    {
        $c = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('sequence_id', $s->id)
            ->selectRaw("count(*) AS total, count(*) FILTER (WHERE statut = 'active') AS en_cours, count(*) FILTER (WHERE statut = 'terminee') AS terminees,
                count(*) FILTER (WHERE statut = 'arretee') AS arretees, count(*) FILTER (WHERE statut = 'active' AND reports > 0) AS reportees,
                min(prochaine_echeance) FILTER (WHERE statut = 'active') AS prochain")->first() ?? throw new \LogicException('Agrégat sans ligne.');
        $enPause = $this->enPause($ws, (string) $s->id);
        $total = (int) $c->total;

        return [
            'id' => (string) $s->id, 'nom' => (string) $s->nom,
            'statut' => $enPause ? 'en_pause' : ($total > 0 && (int) $c->en_cours === 0 ? 'terminee' : 'active'),
            'motif_pause' => $enPause ? 'manuelle' : null,
            'reservee_aux_temoins' => $temoins,
            'nb_etapes' => max(1, (int) DB::table('envoi_sequence_etapes')->where('workspace_id', $ws)->where('sequence_id', $s->id)->count()),
            'inscriptions' => ['total' => $total, 'en_cours' => (int) $c->en_cours, 'terminees' => (int) $c->terminees, 'arretees' => (int) $c->arretees, 'reportees' => (int) $c->reportees],
            'prochain_envoi' => ! $enPause && $c->prochain !== null ? self::horodatage((string) $c->prochain) : null,
            'creee_le' => self::horodatage((string) $s->cree_le),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(string $ws, \stdClass $s, bool $temoins): array
    {
        $r = $this->resume($ws, $s, $temoins);
        $envoyes = DB::table('envoi_messages AS m')->join('envoi_inscriptions AS i', 'i.id', '=', 'm.inscription_id')
            ->where('i.workspace_id', $ws)->where('i.sequence_id', $s->id)->where('m.statut', 'envoye')
            ->groupBy('m.etape')->selectRaw('m.etape, count(*) AS n')->pluck('n', 'etape');
        // Les évènements ne portent pas l'étape : ils sont comptés à l'étape où l'inscription se trouvait (ou s'est arrêtée).
        $evt = DB::table('envoi_inscriptions_evenements AS e')->join('envoi_inscriptions AS i', 'i.id', '=', 'e.inscription_id')
            ->where('i.workspace_id', $ws)->where('i.sequence_id', $s->id)
            ->groupBy('i.etape_courante', 'e.evenement')->selectRaw('i.etape_courante AS rang, e.evenement, count(*) AS n')->get();
        $etapes = [];
        foreach (DB::table('envoi_sequence_etapes')->where('workspace_id', $ws)->where('sequence_id', $s->id)->orderBy('rang')->get() as $e) {
            $n = fn (array $noms): int => (int) $evt->where('rang', $e->rang)->whereIn('evenement', $noms)->sum('n');
            $etapes[] = [
                'rang' => (int) $e->rang, 'delai_jours' => (int) $e->delai_jours_ouvres, 'objet' => (string) $e->sujet,
                'envoyes' => (int) ($envoyes[$e->rang] ?? 0), 'reponses' => $n(['reponse']), 'rebonds_durs' => $n(['rebond_dur']), 'desinscriptions' => $n(['stop', 'opposition']),
            ];
        }
        $blocage = $r['statut'] === 'en_pause' ? $this->blocage($ws) : null;

        return $r + [
            'etapes' => $etapes,
            'peut_reprendre' => $r['statut'] === 'en_pause' && $blocage === null,
            'raison_refus_reprise' => $blocage[1] ?? null,
        ];
    }

    private static function horodatage(string $valeur): string
    {
        return CarbonImmutable::parse($valeur)->toIso8601ZuluString();
    }
}

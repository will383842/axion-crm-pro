<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * B8-A — « campagne bloquée, pourquoi ». LECTURE SEULE : aucune écriture, aucun geste. À appeler DANS un `WorkspaceContext::run`.
 * Une campagne `programmee`, `en_cours` ou `en_pause` reçoit au plus UNE raison, la première vraie dans cet ordre fermé :
 * reglage_non_applique, preparation_bloquee, aucun_destinataire, frein_arret_general, frein_pause_automatique, sequence_en_pause.
 * Tout autre statut : null. Un nombre CONSTANT de requêtes par page (matérialisations, derniers gestes, freins : une lecture chacun).
 * Aucune adresse, aucun nom de personne dans les messages.
 */
final class RaisonBlocage
{
    public const STATUTS = ['programmee', 'en_cours', 'en_pause'];

    private const MESSAGES_MOTIF = [
        'cible_introuvable' => 'La cible de la campagne est introuvable : choisissez une autre cible.',
        'cible_non_supportee' => 'Ce type de cible n’est pas pris en charge pour l’envoi.',
        'population_non_ouverte' => 'La population visée n’est pas encore ouverte à l’envoi.',
        'filtre_origine_inactif' => 'Le filtre par réseau d’origine n’est pas actif pour cet espace.',
        'jours_non_applicables' => 'Les jours d’envoi choisis ne sont pas encore appliqués par l’envoi.',
        'objet_b_non_applicable' => 'Le test A/B de l’objet n’est pas encore appliqué par l’envoi.',
        'etapes_invalides' => 'Les étapes de la séquence ne sont pas valides.',
        'sequence_incoherente' => 'La séquence de la campagne est incohérente.',
        'espace_non_emetteur' => 'Cet espace n’est pas encore autorisé à émettre.',
    ];

    /** Prédicat UNIQUE (partagé avec `MaterialisationCampagne`) : boîtes non vides ou plafond du jour renseigné. */
    public static function reglageNonApplique(object $c): bool
    {
        $contenu = json_decode((string) $c->saisie, true)['contenu'] ?? [];

        return trim((string) $c->boites, '{}') !== '' || (is_array($contenu) && ($contenu['plafond_jour'] ?? null) !== null);
    }

    /**
     * @param  list<\stdClass>  $campagnes  lignes `envoi_campagnes` (id, statut, boites, saisie, sequence_id) d'UN seul espace
     * @return array<string, array{code: string, message: string}|null> identifiant => blocage
     */
    public function pour(string $ws, array $campagnes): array
    {
        $sortie = [];
        $concernees = array_values(array_filter($campagnes, static fn (\stdClass $c): bool => in_array($c->statut, self::STATUTS, true)));
        foreach ($campagnes as $c) {
            $sortie[$c->id] = null;
        }
        if ($concernees === []) {
            return $sortie;
        }
        $ids = array_map(static fn (\stdClass $c): string => (string) $c->id, $concernees);
        $mat = [];
        foreach (DB::select(
            "SELECT campagne_id, etat, motif, compteurs->>'inscrits' AS inscrits FROM envoi_campagnes_materialisations WHERE workspace_id = ? AND campagne_id IN (" . self::marques($ids) . ')',
            [$ws, ...$ids],
        ) as $m) {
            $mat[$m->campagne_id] = $m;
        }
        $sequences = array_values(array_unique(array_map(
            static fn (\stdClass $c): string => (string) $c->sequence_id,
            array_filter($concernees, static fn (\stdClass $c): bool => $c->statut === 'en_cours' && $c->sequence_id !== null),
        )));
        $dernier = [];
        if ($sequences !== []) {
            foreach (DB::select(
                'SELECT DISTINCT ON (sequence_id) sequence_id, geste FROM envoi_sequences_gestes WHERE workspace_id = ? AND sequence_id IN (' . self::marques($sequences) . ') ORDER BY sequence_id, cree_le DESC, id DESC',
                [$ws, ...$sequences],
            ) as $g) {
                $dernier[$g->sequence_id] = $g->geste;
            }
        }
        $freins = null;   // lus une seule fois, et seulement si une campagne en a besoin
        foreach ($concernees as $c) {
            $m = $mat[$c->id] ?? null;
            if (self::reglageNonApplique($c)) {
                $sortie[$c->id] = ['code' => 'reglage_non_applique', 'message' => 'Boîtes ou plafond choisis : l’envoi ne sait pas encore les appliquer. Renvoyez la campagne à valider et recréez-la sans eux.'];

                continue;
            }
            if ($m?->etat === 'bloquee') {
                $sortie[$c->id] = ['code' => 'preparation_bloquee', 'message' => self::messagePreparation($m->motif)];

                continue;
            }
            if ($m?->etat === 'terminee' && (int) $m->inscrits === 0) {
                $sortie[$c->id] = ['code' => 'aucun_destinataire', 'message' => 'La préparation est terminée mais aucun destinataire n’est retenu : élargissez la cible.'];

                continue;
            }
            $freins ??= $this->freins($ws);
            if ($freins['arret_general']) {
                $sortie[$c->id] = ['code' => 'frein_arret_general', 'message' => 'L’arrêt général est armé pour cet espace : rien ne part tant qu’il n’est pas levé.'];
            } elseif ($freins['pause_automatique']) {
                $sortie[$c->id] = ['code' => 'frein_pause_automatique', 'message' => 'Une pause automatique est active (réputation ou chauffe) : rien ne part tant qu’elle n’est pas levée.'];
            } elseif ($c->statut === 'en_cours' && ($dernier[(string) $c->sequence_id] ?? null) === 'pause') {
                $sortie[$c->id] = ['code' => 'sequence_en_pause', 'message' => 'La séquence de la campagne est en pause : reprenez la campagne pour relancer les envois.'];
            }
        }

        return $sortie;
    }

    /** Phrase selon le motif ; un motif inconnu reçoit une phrase générique, sans code. */
    public static function messagePreparation(?string $motif): string
    {
        return self::MESSAGES_MOTIF[(string) $motif] ?? 'La préparation de la campagne est bloquée.';
    }

    /** @return array{arret_general: bool, pause_automatique: bool} */
    private function freins(string $ws): array
    {
        return [
            'arret_general' => app(DepotSocle::class)->toutArreter($ws),
            'pause_automatique' => app(DepotChauffe::class)->pausesActives($ws, new DateTimeImmutable('now')) !== [],
        ];
    }

    /** @param  list<string>  $valeurs */
    private static function marques(array $valeurs): string
    {
        return implode(',', array_fill(0, count($valeurs), '?'));
    }
}

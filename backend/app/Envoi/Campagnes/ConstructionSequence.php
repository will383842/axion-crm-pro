<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Creation\ValidationCampagne;
use App\Envoi\Exceptions\CampagneRefusee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lot X1-L3 : construit la séquence d'une campagne « programmee » (séquence + étapes tirées de la VERSION VALIDÉE des messages types)
 * puis la lie par DepotCampagnes::lierSequence. Aucune inscription, aucune matérialisation, aucun passage à « en_cours », aucun envoi.
 * Une seule transaction, sous les verrous consultatifs de l'espace puis de la campagne (les mêmes que ValidationCampagne::annuler).
 * Isolation READ COMMITTED supposée (jamais REPEATABLE READ ici). Rejouable : la séquence née de la campagne est la clé de reprise.
 */
final class ConstructionSequence
{
    public const ESSAIS_MAX = 3;

    public const MARGE_ANNULATION_MINUTES = 1;

    public function __construct(private readonly DepotCampagnes $depot, private readonly ServiceMessagesTypes $messages) {}

    /** @throws ConstructionRefusee */
    public function construire(string $ws, string $campagneId): ResultatConstruction
    {
        return self::relancer(fn () => $this->depot->sousVerrou($ws, $campagneId, fn () => $this->executer($ws, $campagneId)));
    }

    /**
     * Rejoue $f sur interblocage (40P01) ou échec de sérialisation (40001), HORS transaction englobante seulement.
     * Toute erreur de base devient une ConstructionRefusee : jamais de QueryException brute.
     *
     * @template T
     *
     * @param  \Closure(): T  $f
     * @return T
     */
    public static function relancer(\Closure $f, int $essais = self::ESSAIS_MAX): mixed
    {
        $restants = $essais;
        while (true) {
            try {
                return $f();
            } catch (ConstructionRefusee $e) {
                throw $e;
            } catch (CampagneRefusee $e) {
                throw $e->codeErreur === 'version_perimee'
                    ? new ConstructionRefusee('concurrence', true, 'La campagne a changé pendant la construction : réessayez.', $e)
                    : $e;
            } catch (QueryException $e) {
                $code = (string) $e->getCode();
                $restants--;
                if (in_array($code, ['40P01', '40001'], true) && DB::transactionLevel() === 0 && $restants > 0) {
                    usleep(random_int(20_000, 120_000));

                    continue;
                }
                throw match (true) {
                    in_array($code, ['40P01', '40001', '55P03', '57014', '23505'], true) => new ConstructionRefusee('concurrence', true, 'Construction en concurrence avec une autre écriture : réessayez.', $e),
                    $code === 'P0001' => new ConstructionRefusee('sequence_incoherente', false, 'La base refuse cette séquence pour cette campagne.', $e),
                    default => new ConstructionRefusee('erreur_base', false, 'Erreur de base de données pendant la construction.', $e),
                };
            }
        }
    }

    /** Corps de la transaction : déjà sous les verrous consultatifs. */
    private function executer(string $ws, string $campagneId): ResultatConstruction
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '10000'");
        $c = DB::selectOne('SELECT * FROM envoi_campagnes WHERE workspace_id = ? AND id = ? FOR UPDATE', [$ws, $campagneId])
            ?? throw new ConstructionRefusee('introuvable', false, 'Campagne introuvable dans cet espace.');

        if ($c->sequence_id !== null) {
            $liee = DB::selectOne('SELECT campagne_id FROM envoi_sequences WHERE workspace_id = ? AND id = ?', [$ws, $c->sequence_id]);
            if ($liee !== null && $liee->campagne_id === $campagneId) {
                return new ResultatConstruction((string) $c->sequence_id, (int) $c->version, true);
            }
            throw new ConstructionRefusee('sequence_incoherente', false, 'La campagne est liée à une séquence qui n\'est pas la sienne.');
        }
        if ($c->statut !== 'programmee') {
            throw new ConstructionRefusee('statut_non_programmee', false, "La campagne n'est pas programmée (statut {$c->statut}).");
        }

        $fenetre = DB::selectOne(
            "SELECT max(cree_le) AS v, now() >= max(cree_le) + make_interval(mins => ?::int) AS close FROM envoi_campagnes_journal
              WHERE workspace_id = ? AND campagne_id = ? AND transition = 'a_valider>programmee'",
            [ValidationCampagne::ANNULATION_MINUTES + self::MARGE_ANNULATION_MINUTES, $ws, $campagneId],
        );
        if ($fenetre === null || $fenetre->v === null) {
            throw new ConstructionRefusee('validation_introuvable', false, 'Aucune validation journalisée pour cette campagne.');
        }
        if (! $fenetre->close) {
            throw new ConstructionRefusee('fenetre_annulation_ouverte', true, 'La fenêtre d\'annulation de la validation n\'est pas close.');
        }

        $plan = PlanSequence::depuisCampagne($c);
        $textes = $this->textes($ws, $plan);

        $existante = DB::selectOne('SELECT id, fenetre_debut, fenetre_fin FROM envoi_sequences WHERE workspace_id = ? AND campagne_id = ?', [$ws, $campagneId]);
        if ($existante !== null) {
            $this->comparer($ws, $existante, $plan, $textes);
            $sequenceId = (string) $existante->id;
        } else {
            $sequenceId = (string) Str::uuid();
            DB::table('envoi_sequences')->insert(['id' => $sequenceId, 'workspace_id' => $ws, 'nom' => $c->nom,
                'fenetre_debut' => $plan->fenetreDebut, 'fenetre_fin' => $plan->fenetreFin, 'campagne_id' => $campagneId]);
            foreach ($plan->etapes as $e) {
                DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sequenceId, 'rang' => $e['rang'],
                    'delai_jours_ouvres' => $e['delai'], 'sujet' => $textes[$e['message_id']]['objet'], 'corps' => $textes[$e['message_id']]['corps']]);
            }
        }

        return new ResultatConstruction($sequenceId, $this->depot->lierSequence($ws, $campagneId, (int) $c->version, $sequenceId), false);
    }

    /**
     * Objet et corps de la version VALIDÉE de chaque message type, lus par identifiant croissant (ordre de verrou fixe).
     *
     * @return array<string, array{objet: string, corps: string}>
     */
    private function textes(string $ws, PlanSequence $plan): array
    {
        $ids = array_values(array_unique(array_column($plan->etapes, 'message_id')));
        sort($ids);
        $out = [];
        foreach ($ids as $id) {
            $m = DB::selectOne('SELECT statut, version_validee FROM envoi_modeles WHERE workspace_id = ? AND id = ? FOR SHARE', [$ws, $id]);
            if ($m === null || $m->statut !== 'valide' || $m->version_validee === null) {
                throw new ConstructionRefusee('etapes_invalides', false, 'Un message type de la séquence n\'est pas validé.');
            }
            $v = DB::selectOne('SELECT objet, corps FROM envoi_modele_versions WHERE workspace_id = ? AND modele_id = ? AND numero = ?', [$ws, $id, $m->version_validee]);
            if ($v === null || $this->messages->motifs((string) $v->objet, (string) $v->corps) !== []) {
                throw new ConstructionRefusee('etapes_invalides', false, 'Le contenu d\'un message type est refusé par les contrôles.');
            }
            $out[$id] = ['objet' => (string) $v->objet, 'corps' => (string) $v->corps];
        }

        return $out;
    }

    /**
     * Une séquence déjà née de cette campagne est réutilisée SEULEMENT si elle est identique au plan (jamais d'UPDATE ni de DELETE).
     *
     * @param  array<string, array{objet: string, corps: string}>  $textes
     */
    private function comparer(string $ws, \stdClass $existante, PlanSequence $plan, array $textes): void
    {
        $attendu = [];
        foreach ($plan->etapes as $e) {
            $attendu[] = [$e['rang'], $e['delai'], $textes[$e['message_id']]['objet'], $textes[$e['message_id']]['corps']];
        }
        $reel = array_map(
            static fn ($l): array => [(int) $l->rang, (int) $l->delai_jours_ouvres, (string) $l->sujet, (string) $l->corps],
            DB::select('SELECT rang, delai_jours_ouvres, sujet, corps FROM envoi_sequence_etapes WHERE workspace_id = ? AND sequence_id = ? ORDER BY rang', [$ws, $existante->id]),
        );
        if ((int) $existante->fenetre_debut !== $plan->fenetreDebut || (int) $existante->fenetre_fin !== $plan->fenetreFin || $reel !== $attendu) {
            throw new ConstructionRefusee('sequence_incoherente', false, 'Une séquence existe déjà pour cette campagne mais diffère du plan.');
        }
    }
}

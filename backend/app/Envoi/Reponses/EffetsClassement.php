<?php

namespace App\Envoi\Reponses;

use App\Envoi\Imap\InscriptionsDuRetour;
use App\Envoi\Sequence\Evenement;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use Illuminate\Support\Facades\DB;

/**
 * RR-5A — effets d'un classement HUMAIN : arrêt de la séquence (par `Inscriptions::evenement`, jamais d'UPDATE direct)
 * puis tâche CRM (`activities`, `kind = 'task'`, tâche système `user_id NULL`). Ajout seul : rien n'est supprimé,
 * aucune opposition levée, aucune inscription rouverte. Rejouable (tâche en `insertOrIgnore` sur `external_ref`).
 * À appeler DANS la transaction du classement et sous le `WorkspaceContext` de l'espace. Ni adresse ni extrait écrits.
 *
 * « absence » : le report de 7 jours (RR-4C, `ReportAbsence`) n'est pas encore sur `main` ; en attendant, aucun effet.
 */
/**
 * @phpstan-type LigneEffets object{id: int|string, evenement_id: int|string, empreinte_expediteur: string}
 */
final class EffetsClassement
{
    /** ESCALADE 3 : délai de la tâche « pas maintenant ». */
    public const JOURS_PAS_MAINTENANT = 90;

    private const FUSEAU = 'Europe/Paris';

    private const ARRETENT = ['interesse', 'question', 'pas_maintenant', 'refus'];

    public function __construct(private readonly ?Inscriptions $inscriptions = null) {}

    /**
     * @param  LigneEffets  $l  ligne de `ServiceReponses::BASE`
     * @return string|null titre de la tâche (créée ou déjà là), sinon null
     */
    public function appliquer(string $ws, object $l, string $classement, bool $creerTache): ?string
    {
        $ids = $this->inscriptionsVisees($ws, $l);
        if (in_array($classement, self::ARRETENT, true)) {
            $service = $this->inscriptions ?? new Inscriptions(new LecteurFicheDb);
            $maintenant = new \DateTimeImmutable('now');
            foreach ($ids as $id) {
                $service->evenement($ws, $id, Evenement::REPONSE, $maintenant);
            }
        }

        $tache = match ($classement) {
            'interesse' => $creerTache ? ['Rappeler : réponse intéressée', 'tache', 0] : null,
            'question' => $creerTache ? ['Répondre à la question reçue', 'tache', 0] : null,
            'pas_maintenant' => ['Recontacter (pas maintenant)', 'relance', self::JOURS_PAS_MAINTENANT],
            default => null,
        };
        if ($tache === null) {
            return null;
        }
        [$titre, $genre, $jours] = $tache;
        [$sujetType, $sujetId] = $this->sujet($ws, $l, $ids);
        $echeance = now()->setTimezone(self::FUSEAU)->startOfDay()->addDays($jours)->utc()->format('Y-m-d H:i:sP'); // avec décalage : la session SQL est en Europe/Paris

        DB::table('activities')->insertOrIgnore([
            'workspace_id' => $ws, 'user_id' => null, 'type' => 'task', 'kind' => 'task',
            'occurred_at' => now()->utc()->format('Y-m-d H:i:sP'), 'due_at' => $echeance, 'external_ref' => "envoi:reponse:{$l->id}:{$classement}",
            'subject_type' => $sujetType, 'subject_id' => $sujetId, 'title' => $titre, 'content' => null,
            'payload' => json_encode(['origine' => 'reponse', 'genre' => $genre, 'contexte' => 'Réponse n° ' . $l->id, 'reponse_id' => (int) $l->id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return $titre;
    }

    /**
     * Inscriptions du retour (injection de l'événement, sinon rattachement), à défaut les inscriptions ACTIVES de l'espace
     * pour l'empreinte de l'expéditeur.
     *
     * @param  LigneEffets  $l
     * @return list<string>
     */
    private function inscriptionsVisees(string $ws, object $l): array
    {
        $injection = DB::table('envoi_evenements')->where('workspace_id', $ws)->where('id', $l->evenement_id)->value('id_injection')
            ?? DB::table('envoi_rattachements')->where('workspace_id', $ws)->where('evenement_id', $l->evenement_id)->orderByDesc('id')->value('id_injection');
        if (is_string($injection) && $injection !== '') {
            $ids = (new InscriptionsDuRetour)->pour($ws, $injection);
            if ($ids !== []) {
                return $ids;
            }
        }

        return array_values(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('statut', 'active')
            ->where('empreinte_adresse', $l->empreinte_expediteur)->orderBy('id')->pluck('id')->map(fn ($v): string => (string) $v)->all());
    }

    /**
     * Entreprise de la première inscription visée, sinon fiche liée (geste « fiche »), sinon aucun sujet.
     * Chaque jointure est filtrée par espace : l'entreprise ou la fiche d'un autre espace n'est jamais lue.
     *
     * @param  LigneEffets  $l
     * @param  list<string>  $ids
     * @return array{0: string|null, 1: int|null}
     */
    private function sujet(string $ws, object $l, array $ids): array
    {
        if ($ids !== []) {
            $entreprise = DB::table('envoi_inscriptions as i')
                ->join('companies as c', fn ($j) => $j->on('c.id', '=', 'i.entreprise_id')->on('c.workspace_id', '=', 'i.workspace_id'))
                ->where('i.workspace_id', $ws)->where('c.workspace_id', $ws)->whereIn('i.id', $ids)->orderBy('i.id')->value('c.id');
            if ($entreprise !== null) {
                return ['company', (int) $entreprise];
            }
        }
        $contact = DB::table('envoi_reponses_classements as g')
            ->join('contacts as c', fn ($j) => $j->on('c.id', '=', 'g.contact_id')->on('c.workspace_id', '=', 'g.workspace_id'))
            ->where('g.workspace_id', $ws)->where('c.workspace_id', $ws)->where('g.reponse_id', $l->id)->where('g.geste', 'fiche')
            ->orderByDesc('g.id')->value('c.id');

        return $contact === null ? [null, null] : ['contact', (int) $contact];
    }
}

<?php

namespace App\ProspectionLinkedIn;

use App\Socle\Alertes\Alerte;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * PONT-LINKEDIN LI-E — veille quotidienne de la voie S : rappels de relance échus, arrêt automatique si moins de 20 % des
 * 50 dernières invitations sont acceptées, relevé hebdomadaire (le lundi). Les alertes vont au seul fondateur (cloche et
 * courriel, via `Alerte`, inertes tant que `alertes.actif` est éteint) ; aucun message ne part vers une personne.
 *
 * Écritures : ajout seul (`pl_arret`, `pl_journal`). Aucun nom, aucun profil, aucune adresse dans un message : des comptes.
 * Échec fermé : l'arrêt est posé AVANT l'alerte ; une alerte qui échoue ne défait rien et ne casse jamais la veille.
 */
final class Alertes
{
    public const FENETRE_INVITATIONS = 50;

    /** Acceptation minimale, en pour cent : en dessous, arrêt automatique (E5, défaut prudent). */
    public const SEUIL_ACCEPTATION_POURCENT = 20;

    public function __construct(private readonly Alerte $alerte, private readonly JournalProspection $journal) {}

    /** Tous les espaces non supprimés ; une erreur dans un espace n'empêche pas les suivants. */
    public function executer(CarbonImmutable $maintenant): array
    {
        $total = ['arrets' => 0, 'rappels' => 0, 'releves' => 0, 'erreurs' => 0];
        foreach (DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id') as $espace) {
            try {
                foreach ($this->executerEspace((string) $espace, $maintenant) as $cle => $n) {
                    $total[$cle] += $n;
                }
            } catch (Throwable $e) {
                $total['erreurs']++;
                Log::error('Veille LinkedIn : espace en erreur.', ['exception' => $e::class]);
            }
        }

        return $total;
    }

    /** @return array{arrets: int, rappels: int, releves: int} */
    public function executerEspace(string $ws, CarbonImmutable $maintenant): array
    {
        $r = ['arrets' => 0, 'rappels' => 0, 'releves' => 0];
        $expediteurs = WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('workspace_id', $ws)
            ->distinct()->pluck('expediteur_user_id')->map(static fn ($id): string => (string) $id)->sort()->values()->all());

        foreach ($expediteurs as $exp) {
            $r['arrets'] += $this->surveillerAcceptation($ws, $exp) ? 1 : 0;
            $r['rappels'] += $this->rappeler($ws, $exp, $maintenant) ? 1 : 0;
            $r['releves'] += $this->releverSiLundi($ws, $exp, $maintenant) ? 1 : 0;
        }

        return $r;
    }

    /** Vrai si un arrêt automatique vient d'être posé. */
    private function surveillerAcceptation(string $ws, string $exp): bool
    {
        if ((new Arret)->actif($ws, $exp)) {
            return false; // déjà arrêté (ou lecture impossible : échec fermé) — rien à ajouter
        }
        $dernieres = WorkspaceContext::run($ws, static fn () => DB::table('pl_journal as j')
            ->join('pl_prospects as p', static fn ($jn) => $jn->on('p.workspace_id', '=', 'j.workspace_id')->on('p.id', '=', 'j.prospect_id'))
            ->where('j.workspace_id', $ws)->where('p.expediteur_user_id', $exp)
            ->where('j.evenement', 'invitation_faite')->where('j.sans_effet', false)
            ->orderByDesc('j.recu_le')->orderByDesc('j.id')->limit(self::FENETRE_INVITATIONS)->get(['j.id', 'j.prospect_id']));
        if ($dernieres->count() < self::FENETRE_INVITATIONS) {
            return false; // pas assez d'invitations pour juger
        }
        $acceptees = (int) WorkspaceContext::run($ws, static fn () => DB::table('pl_journal')->where('workspace_id', $ws)
            ->whereIn('prospect_id', $dernieres->pluck('prospect_id')->all())
            ->where('evenement', 'invitation_acceptee')->where('sans_effet', false)->distinct()->count('prospect_id'));
        if ($acceptees * 100 >= self::SEUIL_ACCEPTATION_POURCENT * self::FENETRE_INVITATIONS) {
            return false;
        }

        $motif = 'Acceptation sous ' . self::SEUIL_ACCEPTATION_POURCENT . ' % sur les ' . self::FENETRE_INVITATIONS . ' dernières invitations : arrêt automatique.';
        DB::transaction(function () use ($ws, $exp, $motif, $dernieres): void {
            WorkspaceContext::run($ws, static fn () => DB::table('pl_arret')->insert([
                'workspace_id' => $ws, 'expediteur_user_id' => $exp, 'geste' => 'arret', 'motif' => $motif, 'auteur' => 'systeme', 'declencheur' => 'acceptation_basse',
            ]));
            $this->journal->ecrire($ws, null, 'arret', 'systeme', 'alertes', 'arret-acceptation-' . $exp . '-' . $dernieres->first()->id, ['motif' => 'acceptation_basse']);
        });
        $this->prevenir('LinkedIn : arrêt automatique', "Seulement {$acceptees} invitations acceptées sur " . self::FENETRE_INVITATIONS . ' (moins de '
            . self::SEUIL_ACCEPTATION_POURCENT . ' %). La prospection LinkedIn est arrêtée. Vous la reprenez vous-même, quand vous le décidez.', 'acceptation_basse');

        return true;
    }

    /** Vrai si une alerte de rappel est partie (une par expéditeur et par jour). */
    private function rappeler(string $ws, string $exp, CarbonImmutable $maintenant): bool
    {
        $jour = $maintenant->toDateString();
        $n = (int) WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('workspace_id', $ws)->where('expediteur_user_id', $exp)
            ->where('etat', 'plus_tard')->whereNotNull('relance_le')->where('relance_le', '<=', $jour)->count());
        if ($n === 0 || ! $this->journal->ecrire($ws, null, 'alerte', 'systeme', 'alertes', 'rappel-' . $exp . '-' . $jour, ['motif' => 'rappel_relance'])) {
            return false;
        }
        $this->prevenir('LinkedIn : ' . $n . ($n > 1 ? ' relances à faire' : ' relance à faire'), $n . ($n > 1 ? ' personnes attendent' : ' personne attend')
            . ' une relance de votre part aujourd’hui ou avant. Ouvrez la file LinkedIn pour les voir.', 'rappel_relance');

        return true;
    }

    /** Vrai si le relevé de la semaine vient de partir (le lundi, une fois par expéditeur et par semaine). */
    private function releverSiLundi(string $ws, string $exp, CarbonImmutable $maintenant): bool
    {
        if (! $maintenant->isMonday() || ! $this->journal->ecrire($ws, null, 'alerte', 'systeme', 'alertes', 'releve-' . $exp . '-' . $maintenant->format('o-\WW'), ['motif' => 'releve_hebdomadaire'])) {
            return false;
        }
        $this->prevenir('LinkedIn : relevé hebdomadaire', $this->corpsReleve($ws, $exp), 'releve_hebdomadaire');

        return true;
    }

    /** Comptes par état et par variante, rien d'autre : ni profil, ni nom, ni adresse. */
    public function corpsReleve(string $ws, string $exp): string
    {
        $lignes = WorkspaceContext::run($ws, static fn () => DB::table('pl_prospects')->where('workspace_id', $ws)->where('expediteur_user_id', $exp)
            ->selectRaw('etat, variante, count(*) as n')->groupBy('etat', 'variante')->get());
        $etats = [];
        $variantes = [];
        foreach ($lignes as $l) {
            $etats[(string) $l->etat] = ($etats[(string) $l->etat] ?? 0) + (int) $l->n;
            $v = $l->variante === null ? 'sans variante' : (preg_match('/^[\p{L}\p{N} _.\-]{1,60}$/u', (string) $l->variante) === 1 ? (string) $l->variante : 'autre');
            $variantes[$v] = ($variantes[$v] ?? 0) + (int) $l->n;
        }
        ksort($etats);
        ksort($variantes);
        $texte = fn (array $comptes): string => implode("\n", array_map(static fn ($k, $n): string => "- {$k} : {$n}", array_keys($comptes), $comptes));

        return "Prospects par état :\n" . $texte($etats) . "\n\nPar variante :\n" . $texte($variantes);
    }

    private function prevenir(string $titre, string $corps, string $declencheur): void
    {
        try {
            $this->alerte->envoyer(Str::limit($titre, 120, ''), $corps, 'crm', 'critique', null, ['declencheur' => $declencheur]);
        } catch (Throwable) {
            // une alerte qui échoue ne défait rien : l'arrêt et le journal sont déjà écrits
        }
    }
}

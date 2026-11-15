<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Depot\DepotSocle;
use App\Envoi\JourEnvoi;
use App\Envoi\Sante\VueSante;
use App\Socle\Alertes\Alerte;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Socle d'Envoi (contrat `openapi/envoi/socle.yaml`) : état global, compteurs d'en-tête, arrêt général.
 * Lectures seules sauf `arretGeneral`, qui arme le drapeau de l'espace courant et rien d'autre : aucune route ne lève
 * l'arrêt (la reprise est la commande locale `envoi:reprendre`) et `ServeurEnvoi` n'est jamais résolu.
 */
class EnvoiSocleController extends ApiController
{
    /** Longueur maximale du motif (caractères). */
    public const MOTIF_MAX = 200;

    public function etat(VueSante $vue): JsonResponse
    {
        return response()->json($this->etatDe($this->espace(), $vue));
    }

    public function compteurs(DepotSocle $depot): JsonResponse
    {
        $ws = $this->espace();
        $jour = JourEnvoi::de();
        $compter = fn (string $table, array $ou = []): int => (int) WorkspaceContext::run($ws, fn () => DB::table($table)->where('workspace_id', $ws)->where($ou)->count());

        return response()->json([
            'envoyes_aujourdhui' => $depot->injectesLe($ws, $jour),
            'plafond_jour' => $depot->plafondEspace($ws),
            'en_file' => (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')
                ->where('workspace_id', $ws)->where('commande', 'injection')->where('statut', 'reservee')->where('jour', $jour->format('Y-m-d'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('envoi_journal_commandes as c')
                    ->whereIn('c.commande', ['injection_issue', 'injection_confirmee'])->whereIn('c.statut', ['liberee', 'acceptee'])
                    ->whereColumn('c.workspace_id', 'envoi_journal_commandes.workspace_id')
                    ->whereColumn('c.cle_idempotence', 'envoi_journal_commandes.cle_idempotence'))
                ->count()),
            'reponses_a_traiter' => (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_reponses')->where('workspace_id', $ws)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('envoi_reponses_classements as k')->whereColumn('k.reponse_id', 'envoi_reponses.id'))
                ->count()),
            'alertes_ouvertes' => $compter('notifications', ['produit' => 'envoi', 'read_at' => null]),
            'campagnes_en_cours' => $compter('envoi_campagnes', ['statut' => 'en_cours']),
            'boites_pretes' => $compter('envoi_boites', ['etat' => 'active']),
            'boites_total' => $compter('envoi_boites'),
        ]);
    }

    /**
     * Arrêt général de l'espace courant. Lecture puis armement sous un verrou par espace : `armerToutArreter` journalise
     * sans condition, deux appels simultanés liraient « faux » tous les deux. Idempotent : déjà arrêté, 200 sans écriture
     * ni alerte. L'alerte part après la validation. Le motif est borné et refuse « @ » ; il n'est recopié ni dans le journal
     * ni dans l'alerte.
     */
    public function arretGeneral(Request $request, VueSante $vue, DepotSocle $depot, Alerte $alerte): JsonResponse
    {
        $motif = $request->json('motif');
        if ($motif !== null && (! is_string($motif) || mb_strlen($motif) > self::MOTIF_MAX || str_contains($motif, '@'))) {
            return response()->json(['error' => 'validation', 'message' => 'Le motif doit être un texte de ' . self::MOTIF_MAX . ' caractères au plus, sans « @ ».'], 422);
        }
        $ws = $this->espace();
        $auteur = mb_substr(trim((string) $request->user()?->name), 0, 100) ?: 'inconnu';

        $arme = DB::transaction(function () use ($ws, $auteur, $depot): bool {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('envoi_arret_general|' || ?, 0))", [$ws]);
            if ($depot->toutArreter($ws)) {
                return false;
            }
            $depot->armerToutArreter($ws, $auteur);

            return true;
        });
        if ($arme) {
            $alerte->envoyer('Tout arrêter armé', "Auteur : {$auteur}. Plus aucune injection pour cet espace.", 'envoi', 'critique');
        }

        return response()->json($this->etatDe($ws, $vue));
    }

    private function espace(): string
    {
        return (string) WorkspaceContext::current();
    }

    /** @return array{etat: string, feu: string, depuis: string, par: ?string, motif: null} */
    private function etatDe(string $ws, VueSante $vue): array
    {
        $arrete = app(DepotSocle::class)->toutArreter($ws);
        $dernier = WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('workspace_id', $ws)
            ->whereIn('commande', $arrete ? ['tout_arreter'] : ['reprise'])->orderByDesc('id')->first(['auteur', 'cree_le']));
        $depuis = $dernier->cree_le
            ?? WorkspaceContext::run($ws, fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->value('mis_a_jour_le'))
            ?? DB::table('workspaces')->where('id', $ws)->value('created_at');

        return [
            'etat' => $arrete ? 'arrete' : 'actif',
            'feu' => $arrete ? 'rouge' : (string) $vue->pour($ws, now()->toDateTimeImmutable())['feu'],
            'depuis' => (new DateTimeImmutable((string) $depuis))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'par' => $dernier === null ? null : (string) $dernier->auteur,
            'motif' => null,
        ];
    }
}

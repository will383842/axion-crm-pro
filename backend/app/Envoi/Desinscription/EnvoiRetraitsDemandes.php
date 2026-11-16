<?php

namespace App\Envoi\Desinscription;

use App\Envoi\Serveur\ClientServeurEnvoiHmac;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\JournalCommandes;
use App\Support\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * C3-3 — envoi des demandes de retrait (hors transaction, une fois par minute). Pour chaque demande `en_attente` :
 * relit les identifiants d'injection, aucun => `sans_objet` ; sinon écrit le `commande_id` AVANT l'appel, envoie la
 * commande `retrait` v3 (cibles) par la source active de l'espace, puis `envoye`. Interrupteurs éteints : le client
 * refuse tout appel, la demande reste `en_attente` (tentatives + 1). Ne supprime rien : un retrait met à l'écart.
 */
final class EnvoiRetraitsDemandes
{
    public const LOT = 100;

    /** Cible ADR : un retrait part en moins de 5 minutes. */
    public const RETARD_MINUTES = 5;

    public function __construct(private readonly CommandeurServeur $commandeur, private readonly JournalCommandes $journal) {}

    /**
     * Les `id_injection` kumo réservés (clé `seq-<inscription>-<étape>`) des inscriptions de cette empreinte, moins de 30 jours.
     * Inclut les injections réservées mais pas encore postées. Aucune adresse lue. Espace explicite ET contexte d'espace.
     *
     * @return list<string>
     */
    public function cibles(string $workspaceId, string $empreinteAdresse): array
    {
        return WorkspaceContext::run($workspaceId, fn (): array => array_values(array_map(
            fn ($l): string => (string) $l->id_injection,
            DB::select(
                "SELECT l.id_injection FROM envoi_injections_locales l
                 JOIN envoi_inscriptions i ON i.workspace_id = l.workspace_id
                      AND l.cle_idempotence ~ ('^seq-' || i.id::text || '-[1-3]$')
                 WHERE l.workspace_id = ? AND i.workspace_id = ? AND i.empreinte_adresse = ?
                   AND l.adaptateur = 'kumo' AND l.cree_le > now() - interval '30 days'
                 ORDER BY l.id_injection LIMIT " . ClientServeurEnvoiHmac::MAX_CIBLES_RETRAIT,
                [$workspaceId, $workspaceId, $empreinteAdresse],
            ),
        )));
    }

    /** @return array{envoyees: int, sans_objet: int, echecs: int, en_attente: int, en_retard: int} */
    public function envoyer(): array
    {
        /** @var array{envoyees: int, sans_objet: int, echecs: int, en_attente: int, en_retard: int} $bilan */
        $bilan = ['envoyees' => 0, 'sans_objet' => 0, 'echecs' => 0, 'en_attente' => 0, 'en_retard' => 0];
        if (! Schema::hasTable('envoi_retraits_demandes')) {
            return $bilan;
        }
        foreach (DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id') as $espace) {
            $this->espace((string) $espace, $bilan);
        }

        return $bilan;
    }

    /** @param  array{envoyees: int, sans_objet: int, echecs: int, en_attente: int, en_retard: int}  $bilan */
    private function espace(string $ws, array &$bilan): void
    {
        $demandes = WorkspaceContext::run($ws, fn () => DB::table('envoi_retraits_demandes')->where('workspace_id', $ws)
            ->where('statut', 'en_attente')->orderBy('cree_le')->orderBy('id')->limit(self::LOT)->get());
        foreach ($demandes as $d) {
            try {
                $this->demande($ws, $d, $bilan);
            } catch (Throwable $e) {
                // Jamais de contenu de réponse ni d'empreinte dans le journal : classe d'erreur seulement.
                $bilan['echecs']++;
                Log::warning('envoi.retrait_echec', ['workspace_id' => $ws, 'demande' => (string) $d->id, 'motif' => $e::class]);
            }
            $this->surveillerRetard($ws, $d, $bilan);
        }
    }

    /** @param  array{envoyees: int, sans_objet: int, echecs: int, en_attente: int, en_retard: int}  $bilan */
    private function demande(string $ws, \stdClass $d, array &$bilan): void
    {
        $commandeId = is_string($d->commande_id) ? $d->commande_id : null;
        // Une commande déjà écrite au journal garde SES cibles (même commande_id => même corps) ; sinon on relit.
        $cibles = ($commandeId !== null ? $this->journal->cibles($ws, $commandeId) : null) ?? $this->cibles($ws, trim((string) $d->empreinte_adresse));
        if ($cibles === []) {
            $this->terminer($ws, $d, 'sans_objet', 0);
            $bilan['sans_objet']++;

            return;
        }
        $source = $this->sourceActive($ws);
        $commandeId ??= (string) Str::uuid();
        // commande_id écrit AVANT l'appel (jamais d'intention orpheline sans trace), tentative comptée dans tous les cas.
        WorkspaceContext::run($ws, fn () => DB::table('envoi_retraits_demandes')->where('workspace_id', $ws)->where('id', $d->id)->where('statut', 'en_attente')
            ->update(['commande_id' => $commandeId, 'tentatives' => DB::raw('tentatives + 1')]));
        if ($source === null) {
            $bilan['echecs']++;
            Log::warning('envoi.retrait_source_ambigue', ['workspace_id' => $ws, 'demande' => (string) $d->id]);

            return;
        }
        $this->commandeur->commander($ws, $source, $commandeId, 'retrait', $cibles); // lève si éteint, injoignable ou refusé
        $this->terminer($ws, $d, 'envoye', count($cibles));
        $bilan['envoyees']++;
    }

    /** La source active de l'espace : exactement une, sinon null (aucune supposition). */
    private function sourceActive(string $ws): ?string
    {
        $sources = WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->where('workspace_id', $ws)->where('actif', true)->pluck('source')->all());

        return count($sources) === 1 ? (string) $sources[0] : null;
    }

    private function terminer(string $ws, \stdClass $d, string $statut, int $nb): void
    {
        WorkspaceContext::run($ws, fn () => DB::table('envoi_retraits_demandes')->where('workspace_id', $ws)->where('id', $d->id)->where('statut', 'en_attente')
            ->update(['statut' => $statut, 'cibles_nb' => $nb, 'traite_le' => now()]));
    }

    /** @param  array{envoyees: int, sans_objet: int, echecs: int, en_attente: int, en_retard: int}  $bilan */
    private function surveillerRetard(string $ws, \stdClass $d, array &$bilan): void
    {
        $statut = WorkspaceContext::run($ws, fn () => DB::table('envoi_retraits_demandes')->where('workspace_id', $ws)->where('id', $d->id)->value('statut'));
        if ($statut !== 'en_attente') {
            return;
        }
        $bilan['en_attente']++;
        $minutes = (int) Carbon::parse((string) $d->cree_le)->diffInMinutes(Carbon::now(), false);
        if ($minutes > self::RETARD_MINUTES && config('envoi.reel_actif') === true) {
            $bilan['en_retard']++;
            Log::critical('envoi.retrait_en_retard : un retrait attend depuis plus de ' . self::RETARD_MINUTES . ' minutes.', ['workspace_id' => $ws, 'demande' => (string) $d->id, 'minutes' => $minutes]);
        }
    }
}

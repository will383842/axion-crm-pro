<?php

namespace App\Jobs;

use App\Crm\Rgpd\EffacementCoordonneesFiches;
use App\Jobs\Concerns\RunsInWorkspace;
use App\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * LA PREUVE D'UN EFFACEMENT (art. 17), EN DIFFÉRÉ (relecture P1, 2026-09-29).
 *
 * L'effacement lui-même est synchrone et n'emploie que des recherches servies
 * par un index (`EffacementCoordonneesFiches`) : le site coupe sa requête à
 * 10 s. Ce qui PARCOURT des tables entières vit ici :
 *
 *  1. la timeline (`activities.payload`) balayée par les numéros PERSONNELS
 *     de la personne (relecture E4 — elle ne l'était que par le numéro de la
 *     demande, que la console ne fournit jamais) ;
 *  2. la recherche des résidus (`residus()` : texte de `signals`, `metadata`,
 *     notes libres).
 *
 * Puis la DEMANDE (`rgpd_requests`) reçoit le verdict — le vocabulaire est
 * celui du CHECK de la colonne :
 *  - rien ne reste : `done`, `metadata.verification = 'complete'` ;
 *  - quelque chose reste : `processing` (elle reste « en cours » dans la
 *    console), `metadata.verification = 'incomplete'`, `metadata.motif`, et
 *    les emplacements (des COMPTES, jamais des valeurs).
 * Et le journal le dit, avec l'empreinte de l'adresse, jamais l'adresse.
 *
 * La charge porte l'adresse et les numéros : elle est CHIFFRÉE dans la file
 * (`ShouldBeEncrypted`). L'espace porté est celui de la DEMANDE ; les
 * parcours, eux, se font espace par espace, dans leur contexte (RLS).
 */
class VerifierEffacementRgpd implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace, SerializesModels;

    public int $tries = 3;

    /**
     * SOUS le `retry_after` de la connexion `redis` (600 s,
     * `config/queue.php`), relecture R1 : au-delà, la file croit la tâche
     * perdue et en relance une seconde copie pendant que la première tourne —
     * jusqu'à trois preuves simultanées sur 4,3 M de fiches. Garde :
     * `TimeoutsDesJobsSousRetryAfterTest`. Une preuve plus longue échoue,
     * et `failed()` le rend visible.
     */
    public int $timeout = 540;

    public const MOTIF_INCOMPLET = 'Coordonnees encore presentes apres effacement : a traiter a la main (voir residus).';

    public const MOTIF_ECHEC = 'La verification de l effacement a echoue : la relancer, ou verifier a la main (voir journal).';

    /**
     * @param  list<string>  $personnels  numéros personnels de la personne
     * @param  ?int  $demande  la ligne `rgpd_requests` à mettre à jour
     * @param  ?string  $seulEspace  la porte du site n'efface que dans UN espace
     */
    public function __construct(
        public readonly string $email,
        public readonly array $personnels,
        public readonly ?int $demande = null,
        public readonly ?string $seulEspace = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(): void
    {
        try {
            $residus = self::constater($this->email, $this->personnels, $this->seulEspace);
        } catch (QueryException $e) {
            throw self::nettoyee($e);
        }
        $complet = $residus === [];

        $contexte = ['email_hash' => hash('sha256', mb_strtolower(trim($this->email))), 'demande' => $this->demande, 'residus' => $residus];
        if ($complet) {
            Log::info('GDPR erasure complete', $contexte);
        } else {
            Log::warning('GDPR erasure INCOMPLETE : coordonnees encore presentes', $contexte);
        }

        if ($this->demande !== null) {
            $this->inscrire(
                $complet ? 'done' : 'processing',
                $complet ? 'complete' : 'incomplete',
                $complet ? null : self::MOTIF_INCOMPLET,
                $residus,
            );
        }
    }

    /**
     * Relecture R7 : une preuve qui échoue (délai, base, …) ne laisse PAS la
     * demande « en attente » pour toujours. Elle reste « En traitement »,
     * marquée `echec` avec un motif SANS donnée personnelle, visible dans la
     * console ; `rgpd:verifications-en-attente` la signale aussi.
     */
    public function failed(?Throwable $erreur): void
    {
        $contexte = ['demande' => $this->demande, 'email_hash' => hash('sha256', mb_strtolower(trim($this->email))), 'erreur' => $erreur instanceof QueryException ? self::nettoyee($erreur)->getMessage() : ($erreur === null ? null : $erreur::class)];
        Log::error('GDPR erasure verification FAILED', $contexte);
        if ($this->demande === null) {
            return;
        }
        try {
            $this->inscrire('processing', 'echec', self::MOTIF_ECHEC, null);
        } catch (Throwable $e) {
            // Ne jamais relancer depuis `failed()` : le journal ci-dessus
            // porte déjà l'échec, et la commande planifiée le signalera.
            Log::error('GDPR erasure verification : la demande n a pas pu etre marquee en echec', ['demande' => $this->demande, 'erreur' => $e::class]);
        }
    }

    /**
     * Écrit le verdict sur la demande, dans SON espace (RLS forcée sur
     * `rgpd_requests`). Refuse bruyamment une demande sans espace, ou une
     * écriture qui ne touche aucune ligne (relecture R7) : un UPDATE à zéro
     * ligne laisserait croire la demande soldée.
     *
     * @param  ?array<string, int>  $residus
     */
    private function inscrire(string $statut, string $verification, ?string $motif, ?array $residus): void
    {
        $espace = $this->espaceDuJob();
        if ($espace === null) {
            throw new RuntimeException("VerifierEffacementRgpd : la demande #{$this->demande} n'a pas d'espace ; verdict non inscrit.");
        }

        $this->inWorkspace($espace, function () use ($statut, $verification, $motif, $residus): void {
            $ligne = DB::table('rgpd_requests')->where('id', $this->demande)->first(['metadata']);
            if ($ligne === null) {
                throw new RuntimeException("VerifierEffacementRgpd : la demande #{$this->demande} est introuvable dans son espace ; verdict non inscrit.");
            }
            $metadata = (array) json_decode((string) $ligne->metadata, true);
            $metadata['verification'] = $verification;
            $metadata['verifie_le'] = now()->toIso8601String();
            if ($residus !== null) {
                $metadata['residus'] = $residus;
            }
            if ($motif === null) {
                unset($metadata['motif']);
            } else {
                $metadata['motif'] = $motif;
            }
            $touchees = DB::table('rgpd_requests')->where('id', $this->demande)->update([
                'status' => $statut,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            if ($touchees !== 1) {
                throw new RuntimeException("VerifierEffacementRgpd : la demande #{$this->demande} n'a pas ete mise a jour ; verdict non inscrit.");
            }
        });
    }

    /**
     * Relecture R6 : le message d'une `QueryException` porte la requête AVEC
     * ses valeurs — l'adresse et les numéros de la personne. Il finirait dans
     * `failed_jobs.exception` et le journal. On relance une exception neuve :
     * le SQLSTATE et l'endroit du code, jamais les valeurs, et SANS
     * l'exception d'origine (sa chaîne serait sérialisée avec).
     */
    public static function nettoyee(QueryException $e): RuntimeException
    {
        $endroit = 'inconnu';
        foreach ($e->getTrace() as $cadre) {
            $fichier = (string) ($cadre['file'] ?? '');
            if (str_contains($fichier, DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR)) {
                $endroit = basename($fichier) . ':' . (int) ($cadre['line'] ?? 0);
                break;
            }
        }

        return new RuntimeException('VerifierEffacementRgpd : requete en echec (SQLSTATE ' . (string) $e->getCode() . ') a ' . $endroit . '.');
    }

    /**
     * Le travail lui-même : la timeline, puis les résidus. Vide : complet.
     *
     * @param  list<string>  $personnels
     * @return array<string, int>
     */
    public static function constater(string $email, array $personnels, ?string $seulEspace = null): array
    {
        $espaces = $seulEspace !== null ? [$seulEspace] : EffacementCoordonneesFiches::espaces();
        foreach ($espaces as $espace) {
            WorkspaceContext::run($espace, static fn (): int => EffacementCoordonneesFiches::effacerTimeline($personnels, $espace));
        }

        if ($seulEspace === null) {
            return EffacementCoordonneesFiches::residusPartout($email, $personnels);
        }

        return WorkspaceContext::run($seulEspace, static fn (): array => EffacementCoordonneesFiches::residus($email, $personnels, $seulEspace));
    }
}

<?php

namespace App\Jobs;

use App\Crm\Rgpd\EffacementCoordonneesFiches;
use App\Jobs\Concerns\RunsInWorkspace;
use App\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public int $timeout = 1800;

    public const MOTIF_INCOMPLET = 'Coordonnees encore presentes apres effacement : a traiter a la main (voir residus).';

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
        $residus = self::constater($this->email, $this->personnels, $this->seulEspace);
        $complet = $residus === [];

        $contexte = ['email_hash' => hash('sha256', mb_strtolower(trim($this->email))), 'demande' => $this->demande, 'residus' => $residus];
        if ($complet) {
            Log::info('GDPR erasure complete', $contexte);
        } else {
            Log::warning('GDPR erasure INCOMPLETE : coordonnees encore presentes', $contexte);
        }

        if ($this->demande === null) {
            return;
        }
        $inscrire = function () use ($complet, $residus): void {
            $ligne = DB::table('rgpd_requests')->where('id', $this->demande)->first(['metadata']);
            if ($ligne === null) {
                return;
            }
            $metadata = (array) json_decode((string) $ligne->metadata, true);
            $metadata['verification'] = $complet ? 'complete' : 'incomplete';
            $metadata['verifie_le'] = now()->toIso8601String();
            $metadata['residus'] = $residus;
            if ($complet) {
                unset($metadata['motif']);
            } else {
                $metadata['motif'] = self::MOTIF_INCOMPLET;
            }
            DB::table('rgpd_requests')->where('id', $this->demande)->update([
                'status' => $complet ? 'done' : 'processing',
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        };

        // L'espace de la DEMANDE (RLS forcée sur `rgpd_requests`). Une
        // demande sans espace n'est lisible que par le rôle propriétaire.
        $espace = $this->espaceDuJob();
        if ($espace === null) {
            $inscrire();

            return;
        }
        $this->inWorkspace($espace, $inscrire);
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

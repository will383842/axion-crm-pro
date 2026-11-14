<?php

namespace App\Console\Commands;

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Crm\Outbound\OppositionSortante;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rattrapage (« réconciliation ») des oppositions nées au CRM qui n'ont pas de ligne d'outbox.
 *
 * STOP-T4-CRM, décision D2 : cette commande est LIVRÉE mais NI planifiée NI lancée. Par défaut elle COMPTE
 * seulement et n'écrit rien. `--depuis` est OBLIGATOIRE (aucun défaut : envoyer l'historique est une décision
 * écrite du fondateur). `--appliquer` met en file, par lots (`--limit`), via {@see OppositionSortante}
 * (liste blanche de sources : une opposition venue du site n'est jamais rattrapée).
 *
 * Les sources `gdpr_erasure` et `gdpr_art21_console` existaient AVANT la source `gdpr_erasure_demande_site` : une
 * opposition antérieure peut donc venir d'une demande du site, traitée en console. Une telle ligne n'est reprise
 * QUE si l'on PROUVE qu'une demande RGPD d'erasure/opposition de même empreinte existe et qu'AUCUNE demande de
 * même empreinte n'a `metadata.origin = site-sync-gdpr`. Sinon (preuve impossible, demande introuvable ou
 * invisible) elle est exclue et comptée à part (`exclues_origine_douteuse`).
 *
 * Aucune suppression, aucune modification d'une ligne existante : on ne fait qu'ajouter des lignes d'outbox.
 * Rien ne sort tant que `CRM_OUTBOUND_ENABLED` est faux.
 */
class CrmOutboundRattraper extends Command
{
    protected $signature = 'crm:outbound-rattraper
        {--depuis= : Date (AAAA-MM-JJ) à partir de laquelle compter les oppositions — OBLIGATOIRE}
        {--appliquer : Met réellement en file (sinon : compte seulement)}
        {--inclure-abandonnes : Reprend aussi les événements abandonnés (gave_up) hors refus 422}
        {--limit=500 : Nombre maximal d\'événements mis en file par passage (avec --appliquer)}';

    protected $description = 'Compte (ou rattrape avec --appliquer) les oppositions CRM sans événement sortant. Non planifiée.';

    /** Sources antérieures à `gdpr_erasure_demande_site` : l'origine doit être prouvée par la demande liée. */
    private const SOURCES_A_PROUVER = ['gdpr_erasure', 'gdpr_art21_console'];

    /**
     * Empreintes des demandes RGPD d'effacement/opposition, par origine.
     *
     * `console` : demande SANS `origin = site-sync-gdpr` ; `site` : demande avec cette origine (toutes natures de
     * demande : au moindre doute on exclut). `rgpd_requests` a une RLS forcée : lecture espace par espace. Une
     * demande sans espace ou invisible n'est pas vue, donc la ligne reste exclue (prudence).
     *
     * @return array{console: array<string, true>, site: array<string, true>}
     */
    private function originesDesDemandes(): array
    {
        $r = ['console' => [], 'site' => []];
        foreach (DB::table('workspaces')->pluck('id')->all() as $espace) {
            $lignes = WorkspaceContext::run((string) $espace, static fn () => DB::table('rgpd_requests')
                ->where('workspace_id', (string) $espace)
                ->whereNotNull('subject_email')
                ->get(['type', 'subject_email', DB::raw("metadata->>'origin' as origine")])
                ->all());
            foreach ($lignes as $d) {
                $hash = ConsentOutboundRecorder::hashEmail((string) $d->subject_email);
                if ($d->origine === 'site-sync-gdpr') {
                    $r['site'][$hash] = true;
                } elseif (in_array($d->type, ['erasure', 'opposition'], true)) {
                    $r['console'][$hash] = true;
                }
            }
        }

        return $r;
    }

    public function handle(OppositionSortante $sortante): int
    {
        $depuisBrut = $this->option('depuis');
        if (! is_string($depuisBrut) || trim($depuisBrut) === '') {
            $this->error('--depuis est obligatoire (ex. --depuis=2026-11-14) : aucun défaut, envoyer l\'historique est une décision écrite.');

            return self::FAILURE;
        }
        try {
            $depuis = Carbon::parse($depuisBrut)->startOfDay();
        } catch (Throwable) {
            $this->error('--depuis : date illisible.');

            return self::FAILURE;
        }

        $appliquer = (bool) $this->option('appliquer');
        $inclureAbandonnes = (bool) $this->option('inclure-abandonnes');
        $limite = max(1, (int) $this->option('limit'));

        $bilan = ['a_rattraper' => 0, 'rattrapes' => 0, 'ignores_site' => 0, 'ignores_deja_en_file' => 0, 'ignores_abandonnes' => 0, 'exclues_origine_douteuse' => 0, 'erreurs' => 0];
        $demandes = null; // chargé une seule fois, et seulement si une source ambiguë est rencontrée

        // Une ligne par (portée, empreinte) : la plus ancienne, dont la source dit qui a posé l'opposition.
        $lignes = DB::table(DB::raw('(select distinct on (scope, email_hash) scope, email_hash, source, created_at from opt_out '
            . "where email_hash is not null and scope in ('business', 'vivier') order by scope, email_hash, created_at, id) o"))
            ->where('created_at', '>=', $depuis)
            ->orderBy('created_at')
            ->orderBy('email_hash')
            ->select(['scope', 'email_hash', 'source']);

        foreach ($lignes->cursor() as $l) {
            if (! OppositionSortante::sourceEstCrm((string) $l->source)) {
                $bilan['ignores_site']++;

                continue;
            }

            if (in_array((string) $l->source, self::SOURCES_A_PROUVER, true)) {
                $demandes ??= $this->originesDesDemandes();
                if (! isset($demandes['console'][$l->email_hash]) || isset($demandes['site'][$l->email_hash])) {
                    $bilan['exclues_origine_douteuse']++;

                    continue;
                }
            }

            $statuts = DB::table('crm_outbound_events')
                ->where('event_type', 'consent_optout')
                ->where('email_hash', $l->email_hash)
                ->where('scope', $l->scope)
                ->get(['status', 'last_error']);

            if ($statuts->contains(fn ($e): bool => in_array($e->status, ['pending', 'failed', 'sent'], true))) {
                $bilan['ignores_deja_en_file']++;

                continue;
            }
            if ($statuts->isNotEmpty()) {
                // Seules lignes : `gave_up`. Un refus 422 du site est définitif : jamais repris.
                $refus422 = $statuts->contains(fn ($e): bool => str_starts_with((string) $e->last_error, 'HTTP 422'));
                if ($refus422 || ! $inclureAbandonnes) {
                    $bilan['ignores_abandonnes']++;

                    continue;
                }
            }

            if ($appliquer && $bilan['rattrapes'] >= $limite) {
                break; // avant le compteur : a_rattraper ne dépasse pas la limite du lot
            }
            $bilan['a_rattraper']++;
            if (! $appliquer) {
                continue;
            }
            try {
                if ($sortante->signaler((string) $l->email_hash, (string) $l->scope, 'rattrapage', 'rattrapage') !== null) {
                    $bilan['rattrapes']++;
                }
            } catch (Throwable $e) {
                $bilan['erreurs']++;
                report($e);
            }
        }

        $this->info($appliquer ? 'Rattrapage appliqué (par lot).' : '[COMPTE SEULEMENT] rien n\'a été écrit.');
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($bilan),
            array_values($bilan),
        ));

        return $bilan['erreurs'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

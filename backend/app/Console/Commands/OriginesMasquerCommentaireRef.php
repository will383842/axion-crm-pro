<?php

namespace App\Console\Commands;

use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrat v3 de l'origine (ADR § 7, I5) — MASQUE `commentaire_ref` (l'empreinte
 * du commentaire) au bout de `origines.commentaire_ref_mois` (12) mois.
 *
 * Masquer = MISE À L'ÉCART RÉVERSIBLE : on pose un marqueur horodaté `masque_le`
 * (UTC, ISO) sur l'entrée d'`origines` de la fiche ET sur la ligne d'archive
 * correspondante (colonne `masque_le`), pour qu'elles restent alignées. La
 * VALEUR de `commentaire_ref` n'est JAMAIS touchée (ni null, ni effacée) ; aucune
 * suppression et aucune mise à null dans ce fichier. Les écrans et
 * l'API ne renvoient jamais une entrée masquée (elle est retirée de la sortie,
 * pas de la base) ; l'export d'accès (art. 15) la RESTITUE avec son marqueur
 * (ADR I5). L'âge est celui de la `date` de l'entrée. Idempotente : une entrée
 * déjà masquée garde son premier `masque_le`. Retirer le marqueur lève la mise à l'écart.
 *
 * Inerte tant que l'interrupteur PROPRE `origines.masquage_commentaire_ref_enabled`
 * est éteint (défaut) ou que `origines.v3_enabled` l'est : elle sort alors en
 * succès sans rien lire. Jamais `CRM_PURGE_ENABLED`.
 *
 * `personne_origines_archive` reste en AJOUT SEUL pour le rôle applicatif : il n'a
 * que l'UPDATE de la colonne `masque_le` (migration 2026_11_07_000010), jamais
 * celui de `origine`, ni suppression.
 */
class OriginesMasquerCommentaireRef extends Command
{
    public const SIGNATURE = 'origines:masquer-commentaire-ref';

    protected $signature = self::SIGNATURE;

    protected $description = 'Met à l\'écart (masque_le) les origines de plus de 12 mois — commentaire_ref conservé, rien n\'est supprimé, derrière son interrupteur';

    public function handle(): int
    {
        if (! (bool) config('origines.v3_enabled', false) || ! (bool) config('origines.masquage_commentaire_ref_enabled', false)) {
            $this->info('Masquage de commentaire_ref : interrupteur éteint, rien à faire.');

            return self::SUCCESS;
        }
        if (! Schema::hasColumn('personnes', 'origines')) {
            return self::SUCCESS;
        }

        $limite = now()->subMonths(max(1, (int) config('origines.commentaire_ref_mois', 12)))->utc();
        $masquees = 0;
        $maintenant = now()->utc()->format('Y-m-d\TH:i:s\Z');

        foreach (DB::table('workspaces')->whereNull('deleted_at')->pluck('id') as $workspaceId) {
            $masquees += WorkspaceContext::run((string) $workspaceId, fn (): int => $this->masquerEspace((string) $workspaceId, $limite, $maintenant));
        }

        $this->info("Entrées masquées : {$masquees}.");

        return self::SUCCESS;
    }

    private function masquerEspace(string $workspaceId, \DateTimeInterface $limite, string $maintenant): int
    {
        return $this->masquerFiches($workspaceId, $limite, $maintenant) + $this->masquerArchive($workspaceId, $limite, $maintenant);
    }

    /** Ligne d'archive alignée : seul `masque_le` est écrit, `origine` n'est jamais réécrite. */
    private function masquerArchive(string $workspaceId, \DateTimeInterface $limite, string $maintenant): int
    {
        if (! Schema::hasTable('personne_origines_archive') || ! Schema::hasColumn('personne_origines_archive', 'masque_le')) {
            return 0;
        }
        $total = 0;
        $dernier = 0;

        while (true) {
            $lignes = DB::table('personne_origines_archive')
                ->where('workspace_id', $workspaceId)
                ->where('id', '>', $dernier)
                ->whereNull('masque_le')
                ->orderBy('id')
                ->limit(200)
                ->get(['id', 'origine']);
            if ($lignes->isEmpty()) {
                return $total;
            }
            $dernier = (int) $lignes->last()->id;

            foreach ($lignes as $ligne) {
                $o = is_string($ligne->origine) ? json_decode($ligne->origine, true) : $ligne->origine;
                if (! is_array($o) || ! is_string($o['commentaire_ref'] ?? null) || $o['commentaire_ref'] === '') {
                    continue;
                }
                $date = $this->date($o['date'] ?? null);
                if ($date !== null && $date < $limite) {
                    $total += DB::table('personne_origines_archive')->where('id', $ligne->id)->whereNull('masque_le')
                        ->update(['masque_le' => $maintenant]);
                }
            }
        }
    }

    private function masquerFiches(string $workspaceId, \DateTimeInterface $limite, string $maintenant): int
    {
        $total = 0;
        $dernier = 0;

        while (true) {
            $ids = DB::table('personnes')
                ->where('workspace_id', $workspaceId)
                ->where('id', '>', $dernier)
                ->whereRaw("origines::text ~ '\"commentaire_ref\": ?\"'")
                ->orderBy('id')
                ->limit(200)
                ->pluck('id');
            if ($ids->isEmpty()) {
                return $total;
            }
            $dernier = (int) $ids->last();

            foreach ($ids as $id) {
                $total += DB::transaction(function () use ($id, $limite, $maintenant): int {
                    $ligne = DB::table('personnes')->where('id', $id)->lockForUpdate()->first(['origines']);
                    if ($ligne === null) {
                        return 0;
                    }
                    $liste = is_string($ligne->origines ?? null) ? json_decode($ligne->origines, true) : ($ligne->origines ?? null);
                    if (! is_array($liste)) {
                        return 0;
                    }

                    $n = 0;
                    foreach ($liste as $i => $entree) {
                        if (! is_array($entree) || ! is_string($entree['commentaire_ref'] ?? null) || $entree['commentaire_ref'] === '') {
                            continue;
                        }
                        if (is_string($entree['masque_le'] ?? null) && $entree['masque_le'] !== '') {
                            continue; // déjà mise à l'écart : le premier horodatage fait foi
                        }
                        $date = $this->date($entree['date'] ?? null);
                        if ($date !== null && $date < $limite) {
                            $liste[$i]['masque_le'] = $maintenant;
                            $n++;
                        }
                    }
                    if ($n > 0) {
                        DB::table('personnes')->where('id', $id)->update([
                            'origines' => json_encode(array_values($liste), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        ]);
                    }

                    return $n;
                });
            }
        }
    }

    private function date(mixed $brut): ?\DateTimeImmutable
    {
        try {
            return is_string($brut) && $brut !== '' ? (new \DateTimeImmutable($brut))->setTimezone(new \DateTimeZone('UTC')) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

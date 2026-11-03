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
 * Masquer = passer la valeur à `null` dans l'entrée d'`origines` de la fiche.
 * RIEN d'autre n'est modifié et RIEN n'est supprimé : ni entrée, ni personne,
 * ni ligne d'archive (aucun DELETE, aucun TRUNCATE dans ce fichier). L'âge est
 * celui de la `date` de l'entrée. Idempotente. L'export d'accès (art. 15) est
 * lu sur ce qui reste : l'empreinte masquée n'y est plus.
 *
 * Inerte tant que l'interrupteur PROPRE `origines.masquage_commentaire_ref_enabled`
 * est éteint (défaut) ou que `origines.v3_enabled` l'est : elle sort alors en
 * succès sans rien lire. Jamais `CRM_PURGE_ENABLED`.
 *
 * Limite assumée : `personne_origines_archive` est en AJOUT SEUL (le rôle
 * applicatif n'a ni UPDATE ni DELETE) ; ses entrées ne sont donc pas masquées
 * ici — c'est une décision de droits à prendre à part.
 */
class OriginesMasquerCommentaireRef extends Command
{
    public const SIGNATURE = 'origines:masquer-commentaire-ref';

    protected $signature = self::SIGNATURE;

    protected $description = 'Masque (null) commentaire_ref des origines de plus de 12 mois — rien n\'est supprimé, derrière son interrupteur';

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

        foreach (DB::table('workspaces')->whereNull('deleted_at')->pluck('id') as $workspaceId) {
            $masquees += WorkspaceContext::run((string) $workspaceId, fn (): int => $this->masquerEspace((string) $workspaceId, $limite));
        }

        $this->info("Entrées masquées : {$masquees}.");

        return self::SUCCESS;
    }

    private function masquerEspace(string $workspaceId, \DateTimeInterface $limite): int
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
                $total += DB::transaction(function () use ($id, $limite): int {
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
                        $date = $this->date($entree['date'] ?? null);
                        if ($date !== null && $date < $limite) {
                            $liste[$i]['commentaire_ref'] = null;
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

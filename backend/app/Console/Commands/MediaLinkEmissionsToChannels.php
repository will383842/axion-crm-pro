<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RÉPARATION des ÉMISSIONS ORPHELINES.
 *
 * Une émission (media_type='tv_emission') importée de Wikidata peut rester sans
 * chaîne parente (`parent_media_id IS NULL`) quand, au moment de l'import, la
 * chaîne diffuseuse n'était pas encore en base (ordre d'ingestion, chaîne créée
 * plus tard par ARCOM/NAF, etc.). Cette commande tente de RATTACHER a posteriori
 * ces orphelines à leur chaîne :
 *   1. via le label diffuseur conservé dans socials->>'broadcaster' (posé à
 *      l'import Wikidata) ;
 *   2. par rapprochement sur nom AGRESSIVEMENT normalisé (articles/suffixes retirés)
 *      contre les médias tv/radio du workspace.
 *
 * Ne crée JAMAIS de chaîne (contrairement à l'import) : si aucune chaîne connue ne
 * correspond, l'émission reste orpheline (elle sera rattachée à un prochain run,
 * une fois la chaîne présente). N'écrase jamais un lien existant (ne traite que
 * parent_media_id IS NULL). REPRENABLE (curseur d'id) + IDEMPOTENT + --dry-run.
 */
class MediaLinkEmissionsToChannels extends Command
{
    protected $signature = 'media:link-emissions-to-channels {--dry-run : Compte les rattachements sans écrire} {--limit=0 : Nombre max d\'orphelines à traiter (0 = toutes)} {--workspace= : UUID du workspace cible (défaut = tous)}';

    protected $description = 'Rattache les émissions TV/radio orphelines (parent NULL) à leur chaîne (broadcaster + nom normalisé). Reprenable.';

    /**
     * Index chaînes tv/radio par nom agressif → id, par workspace
     * (clé « workspaceId|aggNorm »).
     *
     * @var array<string,int>
     */
    private array $channelIndex = [];

    /** @var array<string,bool> */
    private array $channelIndexBuilt = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $workspaceFilter = $this->option('workspace') ?: null;

        if ($dryRun) {
            $this->warn('DRY-RUN : aucune écriture en base.');
        }

        $processed = 0;
        $linked = 0;
        $lastId = 0;
        $batch = 1000;

        while (true) {
            $take = $batch;
            if ($limit > 0) {
                $take = min($take, $limit - $processed);
            }
            if ($take <= 0) {
                break;
            }

            $orphans = DB::table('media')
                ->where('media_type', 'tv_emission')
                ->whereNull('parent_media_id')
                ->whereNull('deleted_at')
                ->when($workspaceFilter, fn ($q) => $q->where('workspace_id', $workspaceFilter))
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($take)
                ->get(['id', 'workspace_id', 'name', 'socials']);

            if ($orphans->isEmpty()) {
                break;
            }

            $updates = []; // [emissionId => channelId]
            foreach ($orphans as $o) {
                $lastId = (int) $o->id;
                $processed++;

                $broadcaster = $this->broadcasterFromSocials($o->socials);
                if ($broadcaster === null) {
                    continue; // pas de label diffuseur → rien à rapprocher
                }

                $channelId = $this->matchChannel((string) $o->workspace_id, $broadcaster);
                if ($channelId !== null) {
                    $updates[(int) $o->id] = $channelId;
                    $linked++;
                }
            }

            if (! $dryRun && $updates !== []) {
                $this->flush($updates);
            }

            $this->info("  … {$processed} orphelines examinées · {$linked} rattachées.");

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $remaining = DB::table('media')
            ->where('media_type', 'tv_emission')
            ->whereNull('parent_media_id')
            ->whereNull('deleted_at')
            ->when($workspaceFilter, fn ($q) => $q->where('workspace_id', $workspaceFilter))
            ->count();

        $label = $dryRun ? 'DRY-RUN (aucune écriture)' : 'Terminé';
        $this->info("✅ {$label} : {$processed} orphelines examinées · {$linked} rattachées · {$remaining} restent orphelines.");

        return self::SUCCESS;
    }

    /** Extrait le label diffuseur du JSON socials (socials->>'broadcaster'). */
    private function broadcasterFromSocials(mixed $socials): ?string
    {
        if ($socials === null || $socials === '') {
            return null;
        }
        $data = is_array($socials) ? $socials : json_decode((string) $socials, true);
        if (! is_array($data)) {
            return null;
        }
        $b = trim((string) ($data['broadcaster'] ?? ''));

        return $b !== '' ? $b : null;
    }

    /** Match une chaîne tv/radio du workspace sur nom agressivement normalisé. */
    private function matchChannel(string $workspaceId, string $broadcaster): ?int
    {
        $this->buildChannelIndex($workspaceId);
        $agg = $this->aggressiveNormalize($broadcaster);
        if ($agg === '') {
            return null;
        }

        return $this->channelIndex[$workspaceId . '|' . $agg] ?? null;
    }

    /** Charge (une fois par workspace) l'index tv/radio → id sur nom agressif. */
    private function buildChannelIndex(string $workspaceId): void
    {
        if (isset($this->channelIndexBuilt[$workspaceId])) {
            return;
        }
        $this->channelIndexBuilt[$workspaceId] = true;

        DB::table('media')
            ->select('id', 'name')
            ->where('workspace_id', $workspaceId)
            ->whereIn('media_type', ['tv', 'radio'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use ($workspaceId) {
                foreach ($rows as $r) {
                    $agg = $this->aggressiveNormalize((string) $r->name);
                    if ($agg === '') {
                        continue;
                    }
                    $this->channelIndex[$workspaceId . '|' . $agg] ??= (int) $r->id;
                }
            });
    }

    /**
     * Normalisation AGRESSIVE (identique à ImportMediaEmissionsFromWikidata) :
     * minuscules, sans accents, sans suffixe descriptif, sans article de tête,
     * alphanumérique compacté.
     */
    private function aggressiveNormalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
        $s = preg_replace('/\b(chaine|station|radio|television|tv) de (television|radio)\b/', ' ', $s) ?? $s;
        $s = preg_replace('/^(la|le|les|l|the)\s+/', '', trim($s)) ?? $s;
        $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? '';

        return trim($s);
    }

    /**
     * Écrit les rattachements (bulk UPDATE … FROM VALUES). Garde-fou anti-course :
     * parent_media_id IS NULL → n'écrase jamais un lien déjà posé.
     *
     * @param  array<int,int>  $updates  emissionId => channelId
     */
    private function flush(array $updates): void
    {
        $now = now()->format('Y-m-d H:i:sP');
        $rows = [];
        $bindings = [$now];
        foreach ($updates as $emissionId => $channelId) {
            $rows[] = '(?::bigint, ?::bigint)';
            $bindings[] = $emissionId;
            $bindings[] = $channelId;
        }

        $values = implode(',', $rows);
        $sql = "UPDATE media AS m
                SET parent_media_id = v.channel_id,
                    updated_at = ?::timestamptz
                FROM (VALUES {$values}) AS v(id, channel_id)
                WHERE m.id = v.id
                  AND m.parent_media_id IS NULL";

        DB::transaction(fn () => DB::update($sql, $bindings));
    }
}

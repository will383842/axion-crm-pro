<?php

namespace App\Console\Commands;

use App\Models\Journalist;
use App\Models\Media;
use App\Models\Workspace;
use App\Services\Email\MxEmailValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import des KITS PRESSE constitués par Will (chantier « Base MÉDIAS +
 * journalistes », 2026-07). La source de vérité est du JSON versionné
 * (backend/database/data/press-kit/*.json), produit hors-ligne à partir des
 * 3 fichiers HTML — le runtime ne dépend PAS des HTML (absents du serveur).
 *
 *   - emissions.json → chaînes TV (media_type='tv') + émissions
 *     (media_type='tv_emission', parent_media_id → chaîne) + présentateurs
 *     (journalists, role='présentateur').
 *   - medias.json    → contacts presse national + départementaux + Grenoble/38.
 *
 * Doctrine « 0 email douteux » : chaque email passe par {@see MxEmailValidator}.
 * On REJETTE `invalid` / `disposable` ; on garde `verified` / `risky` / `role`
 * (les emails rédaction génériques — redaction@, contact@, press@ — sont
 * légitimes et attendus en relations presse).
 *
 * Idempotent : ré-exécutable sans doublon.
 *   - médias de diffusion : dédup par (nom normalisé, department_code) ;
 *   - chaînes TV/radio    : dédup par (nom normalisé, type tv|radio) ;
 *   - émissions           : dédup par (parent_media_id, nom normalisé) ;
 *   - présentateurs       : unique (workspace_id, media_id, last_name, first_name).
 * Un média existant sans email est backfillé (source inchangée, marque
 * socials.press_kit=true pour la traçabilité).
 */
class ImportMediaPressKit extends Command
{
    protected $signature = 'media:import-press-kit {--dry-run} {--workspace=}';

    protected $description = 'Importe les kits presse de Will (émissions TV + présentateurs + emails presse), MX-validés.';

    private const SOURCE = 'press-kit';

    private const DATA_DIR = 'data/press-kit';

    private MxEmailValidator $validator;

    /** @var array<string,string> cache email → statut MX (évite les lookups répétés) */
    private array $mxCache = [];

    private int $emailsRejected = 0;

    private int $emailsKept = 0;

    public function handle(MxEmailValidator $validator): int
    {
        $this->validator = $validator;
        $dry = (bool) $this->option('dry-run');

        $workspaceId = $this->option('workspace') ?: Workspace::query()->orderBy('created_at')->value('id');
        if (! $workspaceId) {
            $this->error('Aucun workspace cible (base vide ?). Passez --workspace=UUID.');

            return self::FAILURE;
        }

        $emissionsPath = database_path(self::DATA_DIR . '/emissions.json');
        $mediasPath = database_path(self::DATA_DIR . '/medias.json');
        if (! is_file($emissionsPath) || ! is_file($mediasPath)) {
            $this->error('JSON du kit presse introuvable dans ' . self::DATA_DIR . '/.');

            return self::FAILURE;
        }

        /** @var array<int,array<string,mixed>> $emissions */
        $emissions = json_decode((string) file_get_contents($emissionsPath), true) ?: [];
        /** @var array<int,array<string,mixed>> $medias */
        $medias = json_decode((string) file_get_contents($mediasPath), true) ?: [];

        $this->info(sprintf(
            '%s workspace=%s · %d émissions · %d médias de diffusion',
            $dry ? '[DRY-RUN]' : '[IMPORT]',
            $workspaceId,
            count($emissions),
            count($medias),
        ));

        $stats = [
            'channels' => 0,
            'emissions' => 0,
            'presenters' => 0,
            'medias_new' => 0,
            'medias_backfill' => 0,
            'medias_skipped' => 0,
            'no_email' => 0,
        ];

        $this->importEmissions($workspaceId, $emissions, $dry, $stats);
        $this->importMedias($workspaceId, $medias, $dry, $stats);

        $this->newLine();
        $this->info('— Résultat —');
        $this->line("  Chaînes TV/radio créées/réutilisées : {$stats['channels']}");
        $this->line("  Émissions insérées                  : {$stats['emissions']}");
        $this->line("  Présentateurs (journalists)         : {$stats['presenters']}");
        $this->line("  Médias diffusion créés              : {$stats['medias_new']}");
        $this->line("  Médias diffusion email backfillé    : {$stats['medias_backfill']}");
        $this->line("  Médias diffusion déjà à jour        : {$stats['medias_skipped']}");
        $this->line("  Entrées sans email exploitable      : {$stats['no_email']}");
        $this->line("  Emails validés MX (gardés)          : {$this->emailsKept}");
        $this->line("  Emails REJETÉS MX (invalid/dispos.) : {$this->emailsRejected}");

        if ($dry) {
            $this->warn('DRY-RUN : aucune écriture effectuée.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int,array<string,mixed>>  $emissions
     * @param  array<string,int>  $stats
     */
    private function importEmissions(string $workspaceId, array $emissions, bool $dry, array &$stats): void
    {
        // Regroupe par chaîne (nom de base déjà normalisé côté parser).
        $byChannel = [];
        foreach ($emissions as $em) {
            $byChannel[(string) $em['chaine']][] = $em;
        }

        foreach ($byChannel as $channelName => $rows) {
            // Email de chaîne = 1er chaine_email validé rencontré.
            $channelEmail = null;
            foreach ($rows as $r) {
                $candidate = $this->keepEmail($r['chaine_email'] ?? null);
                if ($candidate !== null) {
                    $channelEmail = $candidate;
                    break;
                }
            }

            $channel = $this->findOrMakeChannel($workspaceId, $channelName, $channelEmail, $dry, $stats);

            foreach ($rows as $r) {
                $emissionName = trim((string) $r['emission']);
                if ($emissionName === '') {
                    continue;
                }
                $email = $this->keepEmail($r['email'] ?? null);
                $emission = $this->findOrMakeEmission($workspaceId, $channel, $emissionName, $email, (string) ($r['angle'] ?? ''), $dry, $stats);
                $this->importPresenters($workspaceId, $emission, (string) ($r['presentateur'] ?? ''), $dry, $stats);
            }
        }
    }

    /**
     * @param  array<string,int>  $stats
     * @return array{id:?int,department_code:?string,diffusion_zone:?string}
     */
    private function findOrMakeChannel(string $workspaceId, string $name, ?string $email, bool $dry, array &$stats): array
    {
        $norm = $this->normalize($name);

        $existing = Media::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('media_type', ['tv', 'radio'])
            ->get(['id', 'name', 'email', 'department_code', 'diffusion_zone'])
            ->first(fn (Media $m) => $this->normalize((string) $m->name) === $norm);

        $stats['channels']++;

        if ($existing) {
            if ($email !== null && empty($existing->email) && ! $dry) {
                $existing->email = $email;
                $this->flagPressKit($existing);
                $existing->save();
            }

            return ['id' => $existing->id, 'department_code' => $existing->department_code, 'diffusion_zone' => $existing->diffusion_zone];
        }

        if ($dry) {
            return ['id' => null, 'department_code' => null, 'diffusion_zone' => 'national'];
        }

        $channel = Media::create([
            'workspace_id' => $workspaceId,
            'name' => mb_substr($name, 0, 240),
            'media_type' => 'tv',
            'diffusion_zone' => 'national',
            'email' => $email,
            'enrich_status' => 'pending',
            'source' => self::SOURCE,
            'socials' => ['press_kit' => true],
        ]);

        return ['id' => $channel->id, 'department_code' => null, 'diffusion_zone' => 'national'];
    }

    /**
     * @param  array{id:?int,department_code:?string,diffusion_zone:?string}  $channel
     * @param  array<string,int>  $stats
     * @return array{id:?int}
     */
    private function findOrMakeEmission(string $workspaceId, array $channel, string $name, ?string $email, string $angle, bool $dry, array &$stats): array
    {
        $norm = $this->normalize($name);

        if ($channel['id'] !== null) {
            $existing = Media::query()
                ->where('workspace_id', $workspaceId)
                ->where('parent_media_id', $channel['id'])
                ->where('media_type', 'tv_emission')
                ->get(['id', 'name', 'email'])
                ->first(fn (Media $m) => $this->normalize((string) $m->name) === $norm);

            if ($existing) {
                if ($email !== null && empty($existing->email) && ! $dry) {
                    $existing->email = $email;
                    $existing->save();
                }

                return ['id' => $existing->id];
            }
        }

        $stats['emissions']++;

        if ($dry || $channel['id'] === null) {
            return ['id' => null];
        }

        $emission = Media::create([
            'workspace_id' => $workspaceId,
            'parent_media_id' => $channel['id'],
            'name' => mb_substr($name, 0, 240),
            'media_type' => 'tv_emission',
            'editorial_theme' => $angle !== '' ? mb_substr($angle, 0, 120) : null,
            'diffusion_zone' => $channel['diffusion_zone'] ?? 'national',
            'department_code' => $channel['department_code'],
            'email' => $email,
            'enrich_status' => 'pending',
            'source' => self::SOURCE,
        ]);

        return ['id' => $emission->id];
    }

    /**
     * @param  array{id:?int}  $emission
     * @param  array<string,int>  $stats
     */
    private function importPresenters(string $workspaceId, array $emission, string $raw, bool $dry, array &$stats): void
    {
        foreach ($this->splitPresenters($raw) as $person) {
            [$first, $last] = $person;
            if ($last === null || $last === '') {
                continue;
            }
            $stats['presenters']++;
            if ($dry || $emission['id'] === null) {
                continue;
            }
            Journalist::firstOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'media_id' => $emission['id'],
                    'last_name' => $last,
                    'first_name' => $first,
                ],
                [
                    'role' => 'présentateur',
                    'source' => self::SOURCE,
                    'source_url' => 'Emissions_TV_Completes_Axion-IA.html',
                ],
            );
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $medias
     * @param  array<string,int>  $stats
     */
    private function importMedias(string $workspaceId, array $medias, bool $dry, array &$stats): void
    {
        foreach (array_chunk($medias, 200) as $chunk) {
            DB::transaction(function () use ($workspaceId, $chunk, $dry, &$stats): void {
                foreach ($chunk as $row) {
                    $email = $this->keepEmail($row['email'] ?? null);
                    if ($email === null) {
                        // Kit presse = base d'emails ; une entrée sans email exploitable
                        // (ligne récapitulative, contact manquant) n'a pas d'intérêt.
                        $stats['no_email']++;

                        continue;
                    }

                    $name = trim((string) $row['name']);
                    if ($name === '') {
                        $stats['no_email']++;

                        continue;
                    }
                    $dept = $row['department_code'] !== null ? (string) $row['department_code'] : null;
                    $norm = $this->normalize($name);

                    $existing = Media::query()
                        ->where('workspace_id', $workspaceId)
                        ->when($dept !== null, fn ($q) => $q->where('department_code', $dept))
                        ->when($dept === null, fn ($q) => $q->whereNull('department_code'))
                        ->get(['id', 'name', 'email', 'socials'])
                        ->first(fn (Media $m) => $this->normalize((string) $m->name) === $norm);

                    if ($existing) {
                        if (empty($existing->email)) {
                            $stats['medias_backfill']++;
                            if (! $dry) {
                                $existing->email = $email;
                                $this->flagPressKit($existing);
                                $existing->save();
                            }
                        } else {
                            $stats['medias_skipped']++;
                        }

                        continue;
                    }

                    $stats['medias_new']++;
                    if ($dry) {
                        continue;
                    }

                    Media::create([
                        'workspace_id' => $workspaceId,
                        'name' => mb_substr($name, 0, 240),
                        'media_type' => $this->mediaTypeFromHint((string) $row['media_type_hint']),
                        'diffusion_zone' => $dept !== null ? 'départemental' : 'national',
                        'department_code' => $dept,
                        'email' => $email,
                        'enrich_status' => 'pending',
                        'source' => self::SOURCE,
                        'socials' => ['press_kit' => true],
                    ]);
                }
            });
        }
    }

    /** Marque un média existant comme touché par le kit presse (source inchangée). */
    private function flagPressKit(Media $m): void
    {
        $socials = is_array($m->socials) ? $m->socials : [];
        $socials['press_kit'] = true;
        $m->socials = $socials;
    }

    /**
     * Valide un email via MX. Retourne l'email (minuscule) s'il est gardé
     * (verified/risky/role), null s'il est rejeté (invalid/disposable) ou vide.
     */
    private function keepEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '') {
            return null;
        }
        $email = strtolower($email);

        $status = $this->mxCache[$email] ??= $this->validator->quickStatus($email);

        if ($status === 'invalid' || $status === 'disposable') {
            $this->emailsRejected++;

            return null;
        }
        $this->emailsKept++;

        return $email;
    }

    private function mediaTypeFromHint(string $hint): string
    {
        $h = mb_strtolower($hint);

        return match (true) {
            str_contains($h, 'agence') => 'agence_presse',
            str_contains($h, 'télévision') || str_contains($h, 'tv') => 'tv',
            str_contains($h, 'radio') => 'radio',
            str_contains($h, 'blog') => 'blog',
            str_contains($h, 'podcast') || str_contains($h, 'youtube')
                || str_contains($h, 'newsletter') || str_contains($h, 'pure player')
                || str_contains($h, 'web') => 'portail_web',
            str_contains($h, 'magazine') => 'presse_revue',
            str_contains($h, 'quotidien') => 'presse_journal',
            default => 'presse_autre',
        };
    }

    /**
     * Découpe "A / B et C, D" en couples [prénom, nom]. Ignore les valeurs non
     * nominatives (Divers, —, vide).
     *
     * @return array<int,array{0:?string,1:?string}>
     */
    private function splitPresenters(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*[\/,]\s*|\s+et\s+/u', $raw) ?: [];
        $people = [];
        foreach ($parts as $part) {
            $part = trim($part);
            $low = mb_strtolower($part);
            if ($part === '' || $part === '—' || $low === 'divers' || $low === 'à confirmer') {
                continue;
            }
            $tokens = preg_split('/\s+/u', $part) ?: [];
            if (count($tokens) === 1) {
                $people[] = [null, mb_substr($tokens[0], 0, 120)];
            } else {
                $first = array_shift($tokens);
                $people[] = [mb_substr($first, 0, 120), mb_substr(implode(' ', $tokens), 0, 120)];
            }
        }

        return $people;
    }

    /** Normalise un nom pour le matching (minuscule, sans accents ni ponctuation). */
    private function normalize(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'û' => 'u', 'ü' => 'u', 'ù' => 'u', 'ú' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ]);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
}

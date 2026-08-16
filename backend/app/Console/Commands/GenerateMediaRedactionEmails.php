<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Workspace;
use App\Services\Email\MxEmailValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Génère des emails RÉDACTION *déterministes* pour les médias qui ont un site web
 * mais aucun email (L3 enrichissement presse). AUCUN scraping, AUCUNE devinette
 * nominative : on ne teste QUE des boîtes rédaction génériques et légitimes sur le
 * domaine du média, et on écrit la 1re qui résout côté DNS (MX).
 *
 * Doctrine « 0 email douteux » : on n'écrit que si un candidat passe le
 * {@see MxEmailValidator} (statut ≠ invalid/disposable). Un `redaction@` /
 * `contact@` générique sur un domaine qui a des MX est parfaitement légitime en
 * presse ; on garde donc verified/risky/role/unknown, on rejette invalid/disposable.
 *
 * REPRENABLE : ne traite que les médias sans email → un 2e run reprend là où le
 * 1er s'est arrêté (les emails écrits sortent d'office du périmètre). Borné par
 * `--limit`, transactionnel par chunk. Le MX check est un simple lookup DNS
 * (léger), donc pas de service systemd nécessaire.
 *
 * ⚠️ JAMAIS d'adresse nominative (prénom.nom@) — uniquement des boîtes rédaction.
 */
class GenerateMediaRedactionEmails extends Command
{
    protected $signature = 'media:generate-redaction-emails
        {--limit=0 : Nombre max de médias à traiter (0 = tous)}
        {--dry-run : Ne rien écrire, juste compter}
        {--workspace= : Workspace UUID cible (default: le plus ancien)}
        {--batch=200 : Taille de chunk (borne mémoire + transaction)}';

    protected $description = 'Génère des emails rédaction déterministes (redaction@/contact@…) validés MX pour les médias sans email. Reprenable.';

    /**
     * Candidats rédaction, dans l'ordre de préférence presse. Uniquement des
     * boîtes GÉNÉRIQUES (jamais nominatives). Le 1er qui passe le MX gagne.
     *
     * @var list<string>
     */
    private const CANDIDATES = [
        'redaction',
        'contact',
        'info',
        'contact.redaction',
        'presse',
    ];

    public function handle(MxEmailValidator $validator): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $batch = max(50, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');

        $workspaceId = $this->option('workspace')
            ?: Workspace::query()->orderBy('created_at')->value('id');

        if (! $workspaceId) {
            $this->error('Aucun workspace trouvé (et --workspace non fourni).');

            return self::FAILURE;
        }

        $processed = 0;   // médias examinés
        $written = 0;     // emails écrits
        $rejected = 0;    // médias sans aucun candidat valide
        $start = microtime(true);

        // Cache MX par domaine : plusieurs médias partagent parfois le même site,
        // et surtout on ne relance jamais 2× le même lookup dans un run.
        $mxCache = [];

        // ⚠️ BOUCLE INFINIE corrigée (2026-08-16). L'ancienne pagination relisait
        // à chaque tour la MÊME première page (`limit($batch)` sans curseur) en
        // comptant sur le fait que les médias traités sortent du périmètre une
        // fois leur email écrit. Or un média SANS candidat valide (domaine sans
        // MX, URL non exploitable…) garde `email IS NULL` : il ressortait
        // indéfiniment, `$medias` n'était jamais vide et la commande tournait
        // sans fin — cas nominal en production, où les domaines morts abondent.
        // Le `--dry-run` avait déjà son rustine (break après un chunk) : c'était
        // le même défaut, corrigé à moitié.
        //
        // On pagine désormais par CURSEUR sur `id` : chaque média n'est examiné
        // qu'une seule fois, écrit ou non. La préférence « sites confirmés
        // d'abord » (ex-`ORDER BY CASE website_status='found'`) est conservée en
        // scannant en DEUX PASSES, ce qui garde une mémoire constante.
        $stop = false;

        foreach ([true, false] as $confirmedOnly) {
            if ($stop) {
                break;
            }

            $lastId = 0;

            while (true) {
                // REPRENABLE : les emails déjà écrits sortent du périmètre (email null).
                $medias = Media::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereNull('email')
                    ->whereNotNull('website')
                    ->where('website', '<>', '')
                    ->when(
                        $confirmedOnly,
                        fn ($q) => $q->where('website_status', 'found'),
                        fn ($q) => $q->where(
                            fn ($w) => $w->where('website_status', '<>', 'found')
                                ->orWhereNull('website_status'),
                        ),
                    )
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit($batch)
                    ->get(['id', 'website', 'website_status']);

                if ($medias->isEmpty()) {
                    break;
                }

                $lastId = (int) $medias->last()->id;
                $updates = []; // [mediaId => email]

                foreach ($medias as $media) {
                    $processed++;

                    $domain = $this->extractDomain($media->website);
                    if ($domain === null) {
                        $rejected++;

                        continue;
                    }

                    // Pré-check MX (1 seul lookup par domaine) : si le domaine n'a AUCUN
                    // MX, aucun candidat ne peut passer → on évite 5 lookups inutiles.
                    if (! array_key_exists($domain, $mxCache)) {
                        $mxCache[$domain] = $validator->resolveMxRecords($domain);
                    }
                    if ($mxCache[$domain] === []) {
                        $rejected++;

                        continue;
                    }

                    $chosen = null;
                    foreach (self::CANDIDATES as $prefix) {
                        $candidate = $prefix . '@' . $domain;
                        $status = $validator->validate($candidate)['status'];
                        // Doctrine « 0 email douteux » : on rejette invalid/disposable,
                        // on garde verified/risky/role/unknown (boîte générique légitime).
                        if ($status !== 'invalid' && $status !== 'disposable') {
                            $chosen = $candidate;
                            break;
                        }
                    }

                    if ($chosen === null) {
                        $rejected++;

                        continue;
                    }

                    $updates[$media->id] = $chosen;
                    $written++;

                    if ($limit > 0 && $processed >= $limit) {
                        break;
                    }
                }

                if (! $dryRun && $updates !== []) {
                    $this->flush($updates);
                }

                $elapsed = max(1, (int) round(microtime(true) - $start));
                $this->info("  … {$processed} traités · {$written} emails · {$rejected} rejetés · " . round($processed / $elapsed, 1) . '/s');

                if ($limit > 0 && $processed >= $limit) {
                    $stop = true;
                    break;
                }
            }
        }

        $label = $dryRun ? 'DRY-RUN (aucune écriture)' : 'Terminé';
        $this->info("✅ {$label} : {$processed} médias · {$written} emails rédaction · {$rejected} sans candidat valide.");

        return self::SUCCESS;
    }

    /**
     * Écrit un chunk d'emails en une transaction (bulk UPDATE ... FROM VALUES).
     *
     * @param  array<int, string>  $updates  mediaId => email
     */
    private function flush(array $updates): void
    {
        $now = now()->format('Y-m-d H:i:sP');
        $rows = [];
        // Deux placeholders de tête (dans l'ordre d'apparition dans le SQL) :
        // enriched_at puis updated_at.
        $bindings = [$now, $now];

        foreach ($updates as $id => $email) {
            $rows[] = '(?::bigint, ?::text)';
            $bindings[] = $id;
            $bindings[] = $email;
        }

        $values = implode(',', $rows);
        // Un email rédaction validé ⇒ média enrichi (COALESCE conserve le 1er
        // enriched_at). Cohérent avec le backfill de la migration taxonomy.
        $sql = "UPDATE media AS m
                SET email = v.email,
                    enrich_status = 'enriched',
                    enriched_at = COALESCE(m.enriched_at, ?::timestamptz),
                    updated_at = ?::timestamptz
                FROM (VALUES {$values}) AS v(id, email)
                WHERE m.id = v.id
                  AND m.email IS NULL"; // garde-fou anti-course (idempotent)

        DB::transaction(fn () => DB::update($sql, $bindings));
    }

    /**
     * Extrait le domaine d'une URL de média (parse_url, retire le `www.`).
     * Retourne null si l'URL ne donne pas un domaine exploitable.
     */
    private function extractDomain(string $website): ?string
    {
        $website = trim($website);
        if ($website === '') {
            return null;
        }

        // parse_url exige un scheme pour peupler `host` ; on en ajoute un au besoin.
        if (! preg_match('#^https?://#i', $website)) {
            $website = 'http://' . ltrim($website, '/');
        }

        $host = parse_url($website, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // Domaine plausible : au moins un point + un TLD alpha.
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            return null;
        }

        return $host;
    }
}

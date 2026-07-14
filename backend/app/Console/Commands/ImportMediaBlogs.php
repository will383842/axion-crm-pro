<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * media:import-blogs — ingestion d'une liste CURÉE de blogs (media_type=blog).
 *
 * Les blogs ne sont ni immatriculés sous un NAF média, ni présents dans les registres
 * officiels (CPPAP/ARCOM/Wikidata) : ils ont donc leur propre voie d'entrée. Cette
 * commande lit un JSON curé (défaut : backend/database/data/blogs/blogs.json) et insère
 * chaque blog en media_type='blog', media_family='editorial', source='blog-curated'.
 *
 * Idempotent : dédup sur (workspace_id, website). La DÉCOUVERTE WEB automatique de blogs
 * reste un chantier ultérieur — ici on pose la catégorie + le canal d'ajout manuel.
 */
class ImportMediaBlogs extends Command
{
    protected $signature = 'media:import-blogs {--file=} {--dry-run} {--workspace=}';

    protected $description = 'Importe une liste curée de blogs (media_type=blog) depuis un JSON.';

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/blogs/blogs.json');
        if (! is_file($path)) {
            $this->error("Fichier introuvable : {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->error('JSON invalide (attendu : tableau d\'objets blog).');

            return self::FAILURE;
        }

        $workspaceId = $this->option('workspace') ?: Workspace::query()->orderBy('created_at')->value('id');
        if (! $workspaceId) {
            $this->error('Aucun workspace cible (base vide ?). Passez --workspace=UUID.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $website = trim((string) ($r['website'] ?? ''));
            if ($name === '' || $website === '') {
                $skipped++;

                continue;
            }

            $exists = DB::table('media')
                ->where('workspace_id', $workspaceId)
                ->where('website', $website)
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            if ($dry) {
                $this->line("[dry] blog : {$name} — {$website}");
                $inserted++;

                continue;
            }

            DB::table('media')->insert([
                'workspace_id'       => $workspaceId,
                'name'               => mb_substr($name, 0, 240),
                'media_type'         => 'blog',
                'media_family'       => 'editorial',
                'editorial_theme'    => $r['editorial_theme'] ?? null,
                'department_code'    => $r['department_code'] ?? null,
                'region_code'        => $r['region_code'] ?? null,
                'city'               => $r['city'] ?? null,
                'website'            => $website,
                'website_status'     => 'found',
                'website_method'     => 'curated',
                'website_checked_at' => now(),
                'source'             => 'blog-curated',
                'enrich_status'      => 'pending',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
            $inserted++;
        }

        $this->info("Blogs importés : {$inserted}, ignorés (déjà présents/invalides) : {$skipped}.");

        return self::SUCCESS;
    }
}

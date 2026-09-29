<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fixtures FICTIVES des tests de doublons (chantier 5) — dépôt public : noms
 * « ZZ », SIREN 94xxxxxxx, domaines en `.example.invalid`.
 */
final class DoublonsFixtures
{
    private static int $seq = 0;

    public static function espace(string $prefixe = 'zz-doublons'): string
    {
        $id = (string) Str::uuid();
        DB::table('workspaces')->insert([
            'id' => $id, 'slug' => $prefixe . '-' . substr(str_replace('-', '', $id), 0, 8), 'name' => 'ZZ doublons',
            'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public static function slug(string $espace): string
    {
        return (string) DB::table('workspaces')->where('id', $espace)->value('slug');
    }

    /** Un SIREN fictif unique (94xxxxxxx). */
    public static function siren(): string
    {
        self::$seq++;

        return '94' . str_pad((string) (self::$seq % 10000000), 7, '0', STR_PAD_LEFT);
    }

    /** @param  array<string, mixed>  $attrs */
    public static function fiche(string $espace, string $nom, array $attrs = []): int
    {
        self::$seq++;
        $defauts = [
            'workspace_id' => $espace,
            'denomination' => $nom,
            'discovery_source' => 'insee',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (! array_key_exists('siren', $attrs) && ! array_key_exists('foreign_id', $attrs)) {
            $defauts['siren'] = self::siren();
        }
        if (array_key_exists('siren', $attrs) && $attrs['siren'] === null && ! array_key_exists('foreign_id', $attrs)) {
            $defauts['foreign_id'] = 'evt:zz-' . self::$seq . '-' . Str::random(6);
        }

        return (int) DB::table('companies')->insertGetId(array_merge($defauts, $attrs));
    }

    /** Une fiche SANS SIREN venue d'une collecte (organisateur d'événements par défaut). */
    public static function sansSiren(string $espace, string $nom, array $attrs = []): int
    {
        return self::fiche($espace, $nom, array_merge(['siren' => null, 'discovery_source' => 'evenements-pro'], $attrs));
    }

    /** @param  array<string, mixed>  $attrs */
    public static function tag(string $espace, string $slug, array $attrs = []): int
    {
        $existant = DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->value('id');
        if ($existant !== null) {
            return (int) $existant;
        }

        return (int) DB::table('tags')->insertGetId(array_merge([
            'workspace_id' => $espace, 'slug' => $slug, 'name' => $slug, 'category' => 'custom', 'kind' => 'auto',
            'rules' => '[]', 'is_locked' => false, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    public static function lier(string $espace, int $companyId, int $tagId): void
    {
        DB::table('company_tag')->insert([
            'company_id' => $companyId, 'tag_id' => $tagId, 'workspace_id' => $espace,
            'assigned_at' => now(), 'assigned_by' => 'auto-rule',
        ]);
    }

    public static function proteger(string $espace, int $companyId, string $slug): void
    {
        self::lier($espace, $companyId, self::tag($espace, $slug, ['is_locked' => true, 'category' => 'intent']));
    }

    /** @param  array<string, mixed>  $attrs */
    public static function contact(string $espace, int $companyId, string $prenom, string $nom, array $attrs = []): int
    {
        return (int) DB::table('contacts')->insertGetId(array_merge([
            'workspace_id' => $espace, 'company_id' => $companyId, 'first_name' => $prenom, 'last_name' => $nom,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** Les étiquettes d'une fiche, triées. @return list<string> */
    public static function slugs(int $companyId): array
    {
        $slugs = DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('company_tag.company_id', $companyId)->pluck('tags.slug')->map(static fn ($s): string => (string) $s)->all();
        sort($slugs);

        return array_values($slugs);
    }

    /** La valeur d'un compteur du bilan d'une commande. */
    public static function compteur(string $sortie, string $cle): ?int
    {
        return preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m) === 1 ? (int) $m[1] : null;
    }

    /** Le tableau du bilan, sans l'en-tête qui dit « à blanc » ou non. */
    public static function bilan(string $sortie): string
    {
        preg_match_all('/^\|.*\|$/m', $sortie, $m);

        return implode("\n", $m[0]);
    }
}

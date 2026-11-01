<?php

namespace Tests\Feature\NonRegression;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Les deux espaces factices du filet : « Espace A » et « Espace B ».
 * Aucune personne réelle, adresses en `@example.test` uniquement.
 */
final class EspacesFactices
{
    /** @return array{a: string, b: string} identifiants des deux espaces */
    public static function creer(): array
    {
        return ['a' => self::espace('Espace A', 'nr-a'), 'b' => self::espace('Espace B', 'nr-b')];
    }

    public static function espace(string $nom, string $prefixe): string
    {
        $id = (string) Str::uuid();
        DB::table('workspaces')->insert([
            'id' => $id,
            'slug' => $prefixe . '-' . Str::lower(Str::random(8)),
            'name' => $nom,
            'settings' => '{}',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

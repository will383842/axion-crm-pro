<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SearchEnginesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['google',     'Google',     'https://www.google.com/search?q=',       3],
            ['bing',       'Bing',       'https://www.bing.com/search?q=',         2],
            ['duckduckgo', 'DuckDuckGo', 'https://duckduckgo.com/?q=',             1],
        ];

        foreach ($rows as [$slug, $name, $url, $weight]) {
            DB::table('search_engines')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'base_url' => $url, 'weight' => $weight, 'enabled' => true],
            );
        }
    }
}

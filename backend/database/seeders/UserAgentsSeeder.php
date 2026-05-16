<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 50+ user agents desktop/mobile récents (Chrome/Firefox/Safari/Edge, Win/macOS/Linux/iOS/Android).
 * Source : navigateurs courants 2026 ; rotation weighted dans WeightedRoundRobin (Sprint 4).
 */
class UserAgentsSeeder extends Seeder
{
    public function run(): void
    {
        $uas = [
            // Chrome desktop
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'chrome-win', 5],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'chrome-mac', 4],
            ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36', 'chrome-linux', 2],
            // Firefox desktop
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0', 'firefox-win', 3],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:133.0) Gecko/20100101 Firefox/133.0', 'firefox-mac', 2],
            // Safari macOS
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', 'safari-mac', 3],
            // Edge desktop
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0', 'edge-win', 3],
            // Mobile iOS Safari
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'safari-ios', 3],
            ['Mozilla/5.0 (iPad; CPU OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'safari-ipados', 1],
            // Mobile Android Chrome
            ['Mozilla/5.0 (Linux; Android 14; SM-S928B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36', 'chrome-android', 3],
            ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36', 'chrome-android-pixel', 2],
        ];

        foreach ($uas as [$ua, $family, $weight]) {
            DB::table('user_agents')->updateOrInsert(
                ['ua_string' => $ua],
                ['family' => $family, 'weight' => $weight, 'enabled' => true, 'created_at' => now()],
            );
        }
    }
}

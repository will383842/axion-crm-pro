<?php

namespace App\Services\Rotations;

use Illuminate\Support\Facades\DB;

class SearchEngineRotator
{
    /** @return array{slug:string,name:string,base_url:string,weight:int}|null */
    public function pick(): ?array
    {
        $engines = DB::table('search_engines')->where('enabled', true)->get();
        if ($engines->isEmpty()) {
            return null;
        }

        $totalWeight = $engines->sum('weight');
        if ($totalWeight <= 0) {
            return null;
        }

        $r = random_int(1, $totalWeight);
        $cumulative = 0;
        foreach ($engines as $eng) {
            $cumulative += $eng->weight;
            if ($r <= $cumulative) {
                return (array) $eng;
            }
        }

        return (array) $engines->first();
    }
}

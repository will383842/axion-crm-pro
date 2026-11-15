<?php

namespace App\Providers\Envoi;

use App\Envoi\FeuVert\DecisionFeuVert;
use App\Envoi\FeuVert\ListeFeuVert;
use Illuminate\Support\ServiceProvider;

/** K17-FV (D12) : la décision de feu vert lit la liste du conteneur (substituable en test). Aucune configuration modifiée. */
class FeuVertDecisionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DecisionFeuVert::class, static fn ($app): DecisionFeuVert => new DecisionFeuVert($app->make(ListeFeuVert::class)));
    }
}

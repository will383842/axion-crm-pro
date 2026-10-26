<?php

use App\Crm\Brave\QuotaBrave;
use App\Crm\Brave\RechercheBrave;
use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use Illuminate\Support\Facades\Http;

/**
 * Faux `RechercheBrave` COMPTEUR : prouve que `findAvecMethode($c, true)`
 * (mode « ajout seulement », Brave plafond 0) n'émet aucune recherche, et que
 * le mode normal en émet une. Données fictives (dépôt public).
 */
function fauxBrave(): RechercheBrave
{
    return new class extends RechercheBrave
    {
        public int $appels = 0;

        public function __construct() {}

        public function chercher(string $requete, string $usage, int $nombre = 10): array
        {
            $this->appels++;

            return ['etat' => 'sans_cle', 'urls' => [], 'code' => null];
        }
    };
}

it('sansBrave vrai : Brave n est jamais appelé', function () {
    Http::fake(['*' => Http::response('', 404)]);
    $brave = fauxBrave();
    $company = new Company(['denomination' => 'ZZ', 'city_name' => 'ZZVille']);

    (new DomainFinderService($brave))->findAvecMethode($company, true);

    expect($brave->appels)->toBe(0);
});

it('sansBrave faux : Brave est appelé une fois (témoin)', function () {
    Http::fake(['*' => Http::response('', 404)]);
    $brave = fauxBrave();
    $company = new Company(['denomination' => 'ZZ', 'city_name' => 'ZZVille']);

    (new DomainFinderService($brave))->findAvecMethode($company, false);

    expect($brave->appels)->toBe(1)->and(QuotaBrave::ENRICHISSEMENT)->toBeString();
});

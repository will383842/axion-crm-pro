<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiels géographiques — régions et départements, code + libellé.
 *
 * Existe pour une raison précise : la liste Entreprises proposait une SAISIE
 * LIBRE pour le département (« Dept (75…) ») et RIEN pour la région, alors que
 * `region_code` était déclaré dans l'état du composant — un filtre déclaré,
 * jamais réglable, donc du code mort.
 *
 * On sert les valeurs RÉELLES des tables `regions` et `departments` plutôt que
 * de recopier 102 départements dans le frontend : une liste recopiée finit
 * toujours par diverger de la base, et c'est la base qui décide ce qu'un
 * filtre peut trouver.
 *
 * Public au sein de l'application (pas de scope workspace) : ce sont des
 * référentiels administratifs français, pas des données de client.
 */
class ReferentielsGeoController extends ApiController
{
    /**
     * Une heure de cache : ces référentiels changent au rythme des réformes
     * territoriales, pas à celui des requêtes. Sans cache, chaque ouverture
     * d'écran relirait 120 lignes pour rien.
     */
    private const CACHE_SECONDES = 3600;

    public function index(): JsonResponse
    {
        $donnees = Cache::remember('referentiels.geo', self::CACHE_SECONDES, static function (): array {
            return [
                'regions' => self::lire('regions'),
                'departments' => self::lire('departments'),
            ];
        });

        return $this->ok($donnees);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    private static function lire(string $table): array
    {
        // Table absente sur un environnement frais → liste vide plutôt qu'une
        // 500 : un écran sans référentiel reste utilisable, un écran en erreur
        // ne l'est pas.
        if (! Schema::hasTable($table)) {
            return [];
        }

        $lignes = DB::table($table)
            ->select('code', 'name')
            ->orderBy('code')
            ->get();

        // Boucle explicite plutôt que `map()->all()` : la collection Laravel
        // ne garantit pas à l'analyse statique des clés 0..n contiguës, et un
        // tableau à trous se sérialise en OBJET JSON au lieu d'un tableau —
        // le frontend recevrait `{"0":…}` et ne pourrait plus itérer.
        $sortie = [];
        foreach ($lignes as $ligne) {
            $sortie[] = [
                'code' => (string) $ligne->code,
                'name' => (string) $ligne->name,
            ];
        }

        return $sortie;
    }
}

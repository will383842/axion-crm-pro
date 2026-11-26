<?php

namespace App\Crm\Claude;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LA BASCULE AUTOMATIQUE ENTRE LES CLÉS CLAUDE.
 *
 * On utilise la première clé disponible de la liste ordonnée. L'état de chaque
 * alias est gardé dans `cles_claude_etat` pour qu'un redémarrage ne réessaie
 * pas en boucle une clé vide :
 *  - `epuisee`  : retentée au plus une fois par 24 h ;
 *  - `invalide` : retentée seulement après `crm:claude:cles --reactiver=<alias>`.
 * Seul l'alias sort de cette classe, jamais la valeur de la clé.
 */
class RotationCles
{
    public const DELAI_RETENTE_H = 24;

    /** @var list<string> */
    private array $alias = [];

    /**
     * @param  list<string>  $liste  alias ordonnés (CRM_CLAUDE_CLES_ENRICHISSEMENT)
     * @param  array<string,string>  $cles  alias => valeur de la clé
     */
    public function __construct(array $liste, private readonly array $cles)
    {
        foreach ($liste as $a) {
            if (($cles[$a] ?? '') === '') {
                Log::warning('Claude : alias ignoré (inconnu ou clé vide).', ['alias' => $a]);

                continue;
            }
            $this->alias[] = $a;
        }
    }

    public static function depuisConfig(): self
    {
        /** @var list<string> $liste */
        $liste = (array) config('services.anthropic.cles_enrichissement', []);
        /** @var array<string,string> $cles */
        $cles = (array) config('services.anthropic.cles', []);

        return new self($liste, $cles);
    }

    /** @return list<string> alias valides de la liste (avant tout état) */
    public function aliasValides(): array
    {
        return $this->alias;
    }

    /** Première clé utilisable maintenant, ou null si plus aucune. */
    public function courante(): ?string
    {
        $etats = $this->lireEtats();
        foreach ($this->alias as $a) {
            $e = $etats[$a] ?? null;
            if ($e === null || $e['etat'] === 'disponible') {
                return $a;
            }
            if ($e['etat'] === 'epuisee' && $e['depuis']->lte(Carbon::now('UTC')->subHours(self::DELAI_RETENTE_H))) {
                return $a;
            }
        }

        return null;
    }

    public function cle(string $alias): string
    {
        return $this->cles[$alias] ?? '';
    }

    public function marquer(string $alias, string $etat): void
    {
        if ($etat === 'invalide') {
            Log::warning('Claude : clé invalide ou révoquée, écartée.', ['alias' => $alias]);
        }
        $this->ecrireEtat($alias, $etat);
    }

    public function reactiver(string $alias): void
    {
        $this->ecrireEtat($alias, 'disponible');
    }

    /** @return array<string,array{etat:string,depuis:Carbon}> */
    public function etats(): array
    {
        return $this->lireEtats();
    }

    /** @return array<string,array{etat:string,depuis:Carbon}> */
    protected function lireEtats(): array
    {
        $r = [];
        foreach (DB::table('cles_claude_etat')->get() as $l) {
            $r[(string) $l->alias] = ['etat' => (string) $l->etat, 'depuis' => Carbon::parse((string) $l->depuis, 'UTC')];
        }

        return $r;
    }

    protected function ecrireEtat(string $alias, string $etat): void
    {
        DB::statement(
            'INSERT INTO cles_claude_etat (alias, etat, depuis, created_at, updated_at)
             VALUES (?, ?, now(), now(), now())
             ON CONFLICT (alias) DO UPDATE SET etat = EXCLUDED.etat, depuis = now(), updated_at = now()',
            [$alias, $etat],
        );
    }
}

<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Supprime les fiches dont la dénomination est `[ND]` — « non diffusible » au
 * sens de l'INSEE : l'entreprise a demandé que ses données ne soient pas
 * publiées. Suppression DÉFINITIVE.
 *
 * 🔴 CETTE COMMANDE SUPPRIMAIT SANS AUCUNE GARDE (audit 360, B15-004).
 * Sa condition est plus étroite que celle de sa jumelle, mais elle méritait la
 * même protection : un essai à blanc, un plafond, une confirmation.
 */
class ProspectionPurgeNonDiffusible extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'prospection:purge-non-diffusible
                            {--dry-run : Montre ce qui serait supprimé, sans rien supprimer}
                            {--force : Assume la suppression, y compris au-delà du plafond}';

    protected $description = 'Supprime les fiches non diffusibles ([ND]) au sens INSEE.';

    public function handle(): int
    {
        $aSupprimer = $this->fichesNonDiffusibles()->count();
        $total = DB::table('companies')->count();

        if (! $this->suppressionAutorisee('companies', $aSupprimer, $total)) {
            return self::SUCCESS;
        }

        $supprimees = $this->fichesNonDiffusibles()->delete();
        $this->info("✅ {$supprimees} fiches non diffusibles supprimées.");

        return self::SUCCESS;
    }

    /**
     * LES FICHES QUE CETTE PURGE RECONNAÎT — une SEULE définition, employée par
     * le comptage ET par la suppression.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * 🔴 `C19-010`, SECOND VOLET — elle n'en reconnaissait qu'UNE SUR TROIS
     * ═══════════════════════════════════════════════════════════════════════
     *
     * L'ENTRÉE est fermée depuis le 2026-08-20 : les trois voies de
     * `HttpInseeClient` écartent une unité opposée. Restait ce qui était DÉJÀ
     * ENTRÉ, et que cette commande devait rattraper. Sa condition était une
     * égalité stricte sur une chaîne :
     *
     *     ->where('denomination', '[ND]')
     *
     * Or les voies non filtrées écrivaient TROIS formes, toutes mesurées sur le
     * banc avant réparation :
     *
     *   FORME 1 · `'[ND]'` ....... personne MORALE opposée .......... reconnue
     *   FORME 2 · `'[ND] [ND]'` .. personne PHYSIQUE, voie `fetchBySiren()` :
     *             la dénomination est absente, le code retombe sur
     *             `trim(prenom . ' ' . nom)` — deux champs masqués, donc la
     *             chaîne « [ND] [ND] » ........................ NON reconnue
     *   FORME 3 · `NULL` ......... personne PHYSIQUE, voie `/siren` .. NON
     *             reconnue, et NON RECONNAISSABLE ici — voir plus bas.
     *
     * `position('[ND]' in denomination) > 0` couvre les FORMES 1 et 2 sans
     * dépendre d'un échappement `LIKE` : en SQL, `[` n'est pas un métacaractère,
     * mais l'oublier est le genre de détail qui se paie six mois plus tard.
     *
     * ⚠️ LA FORME 3 EST LAISSÉE DEHORS, ET C'EST DÉLIBÉRÉ. Une fiche sans
     * dénomination n'est pas une preuve d'opposition : un entrepreneur
     * individuel LÉGITIME et diffusible arrive lui aussi sans dénomination par
     * cette même voie. Purger sur `denomination IS NULL` serait exactement le
     * piège `B15-004` — « `legal_form IS NULL` » — qui a failli effacer la base
     * entière. Le rattrapage de la FORME 3 demande de rejouer l'INSEE sur les
     * fiches sans dénomination issues de `discovery_source = 'insee'` : c'est un
     * travail de commande, pas d'une condition. Arbitrage à Will.
     *
     * ⚠️ UNE SEULE DÉFINITION, et la raison compte : la condition était écrite
     * DEUX FOIS — une pour compter, une pour supprimer. Qui n'en corrigeait
     * qu'une faisait mentir le plafond de `RefuseUneSuppressionMassive`, qui
     * aurait alors autorisé une suppression sur la foi d'un décompte plus
     * étroit qu'elle.
     */
    private function fichesNonDiffusibles(): Builder
    {
        return DB::table('companies')->whereRaw("position('[ND]' in denomination) > 0");
    }
}

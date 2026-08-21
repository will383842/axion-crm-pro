<?php

namespace App\Console\Commands;

use App\Services\Alertes\AlerteTelegram;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SONDE `C19-010` — UNE PERSONNE QUI A DEMANDÉ À NE PAS ÊTRE PUBLIÉE EST-ELLE
 * ENTRÉE MALGRÉ TOUT ?
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE SONDE, ET PAS UNE COMMANDE DE RATTRAPAGE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `C19-010` protège les unités « non diffusibles » au sens de l'INSEE : des
 * personnes qui ont explicitement demandé que leurs données ne soient pas
 * publiées. Trois formes de marquage étaient possibles à l'entrée :
 *
 *   FORME 1 · `denomination = '[ND]'` ....... personne morale
 *   FORME 2 · `denomination = '[ND] [ND]'` .. personne physique, voie `/siren`
 *   FORME 3 · `denomination IS NULL` ........ personne physique, voie `/siren`
 *
 * L'ENTRÉE est fermée (les trois voies de `HttpInseeClient` écartent une unité
 * opposée), et le RATTRAPAGE reconnaît désormais les formes 1 et 2.
 *
 * ── CE QUE LA MESURE A MONTRÉ, LE 2026-08-21 ───────────────────────────────
 *
 * Comptage sur la production, sur 4 295 349 fiches :
 *
 *     fiches marquées `[ND]` ..................... 0
 *     fiches SANS dénomination ................... 0
 *     fiches en corbeille ........................ 0
 *
 * *Il n'y avait rien à rattraper.* Écrire une commande qui rejoue l'INSEE fiche
 * par fiche pour zéro ligne aurait été du travail pour rien — et du code non
 * exercé, donc du code qui pourrit.
 *
 * ── CE QUI RESTE VRAIMENT À DÉFENDRE ───────────────────────────────────────
 *
 * La FORME 3 n'est PAS rattrapable par une condition, et ne doit pas l'être :
 * une fiche sans dénomination est indiscernable d'un entrepreneur individuel
 * parfaitement légitime, arrivé par la même voie. La purger serait exactement le
 * piège `B15-004` — « `legal_form IS NULL` » — qui a failli effacer la base.
 *
 * Ce qu'on peut faire, en revanche, c'est **le voir arriver**. Si une seule
 * fiche non diffusible réapparaît, c'est que l'entrée s'est rouverte — et c'est
 * cela qu'il faut apprendre le jour même, pas six mois plus tard.
 *
 * *On ne répare pas ce qui n'est pas cassé ; on installe de quoi savoir si ça
 * casse.*
 *
 * ── Ce qu'elle rend ────────────────────────────────────────────────────────
 *
 *   FAILURE + alerte  au moins une fiche marquée `[ND]` (formes 1 ou 2)
 *   FAILURE + alerte  au moins une fiche INSEE sans dénomination (forme 3)
 *   SUCCESS           aucune des deux — et elle dit sur quoi elle a compté
 */
class CrmSondeNonDiffusibles extends Command
{
    public const SIGNATURE_PLANIFIEE = 'crm:sonde-non-diffusibles';

    public const PREFIXE_ALERTE = '[C19-010] une fiche NON DIFFUSIBLE est en base';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Verifie qu aucune unite opposee a la diffusion INSEE n est entree en base.';

    public function handle(): int
    {
        // ⚠️ `runWithoutScope()` : cette sonde compte à travers TOUS les espaces,
        // et elle n'en a aucun — c'est une tâche planifiée. La ceinture
        // applicative est levée explicitement, et la raison part au journal.
        [$marquees, $sansNom, $total] = WorkspaceContext::runWithoutScope(
            'sonde C19-010 : compter les fiches non diffusibles, tous espaces confondus',
            static fn (): array => [
                (int) DB::table('companies')
                    ->whereRaw("position('[ND]' in denomination) > 0")
                    ->count(),
                // ⚠️ On restreint à `discovery_source = 'insee'` : une fiche sans
                // dénomination venue d'ailleurs (import manuel, campagne) n'a rien
                // à voir avec l'opposition INSEE, et la compter ferait crier la
                // sonde sur un stock qu'aucun geste ne peut réduire. Une alarme
                // qu'on ne peut pas éteindre finit ignorée.
                (int) DB::table('companies')
                    ->whereNull('denomination')
                    ->where('discovery_source', 'insee')
                    ->count(),
                (int) DB::table('companies')->count(),
            ],
        );

        if ($marquees === 0 && $sansNom === 0) {
            // Le silence est mérité — et il dit sur quoi il a été mesuré, sans
            // quoi personne ne peut juger si la sonde a vraiment regardé.
            $this->info(sprintf(
                'Aucune fiche non diffusible : 0 marquee `[ND]`, 0 sans denomination, sur %s fiches.',
                number_format($total, 0, ',', ' '),
            ));

            return self::SUCCESS;
        }

        $message = self::PREFIXE_ALERTE . ' : '
            . $marquees . ' fiche(s) portent le marqueur `[ND]`, et '
            . $sansNom . ' fiche(s) INSEE sont SANS DENOMINATION (sur '
            . number_format($total, 0, ',', ' ') . ').'
            . ' Ces personnes ont demande a l INSEE de NE PAS etre publiees.'
            . ' GESTE : (1) jouer `prospection:purge-non-diffusible --dry-run` pour voir ce que'
            . ' le rattrapage effacerait ; (2) pour les fiches SANS denomination, NE PAS purger'
            . ' en masse — elles sont indiscernables d un entrepreneur individuel legitime'
            . ' (piege B15-004) : il faut rejouer l INSEE fiche par fiche.'
            . ' Et surtout : leur presence signifie que l ENTREE s est rouverte —'
            . ' verifier `HttpInseeClient` avant tout le reste.';

        Log::critical($message, ['marquees' => $marquees, 'sans_denomination' => $sansNom]);
        $this->error($message);

        app(AlerteTelegram::class)->envoyer(
            '🔴 CRM — donnee personnelle qui ne devrait pas etre la',
            $message,
            ['constat' => 'C19-010', 'marquees' => $marquees, 'sans_denomination' => $sansNom],
        );

        return self::FAILURE;
    }
}

<?php

/**
 * GARDE — F39-007 (S2) : L'ECRAN DE SANTE NE REND PAS ZERO EN SILENCE.
 *
 * ── CE QUI A ETE MESURE, LE 2026-08-22 ──────────────────────────────────────
 *
 * `app/Http/Controllers/Api/ObservabilityController.php` interceptait toute
 * exception dans SIX de ses rubriques et rendait une valeur neutre :
 *
 *     :70-72   ['ingested_today' => 0, 'ingested_7d' => 0, 'last_ingested_at' => null]
 *     :101-103 ['pending' => 0, 'gave_up' => 0]
 *     :122-126 $used = 0; $limit = 11500; $pending = 0;
 *     :158-160 $count = 0
 *     :191-193 return 0
 *     :214-216 return []
 *
 * et le fichier n'importait meme pas `Log` — ses `use` s'arretaient a
 * `Illuminate\Support\Facades\DB`. Aucun des six avalements ne laissait donc la
 * moindre trace, nulle part. Sur l'ecran qui sert precisement a savoir si le
 * produit va bien, « rien a signaler » et « je n'ai pas pu regarder » etaient
 * indiscernables — et le second etait le plus rassurant des deux.
 *
 * ── CE QUE CETTE GARDE MESURE, ET CE QU'ELLE NE MESURE PAS ──────────────────
 *
 * Elle LIT le controleur et exige que CHAQUE bloc d'interception journalise.
 * Elle ne joue pas de panne : provoquer une vraie erreur SQL demanderait de
 * casser une table de la base partagee par toute la suite, ce qui coute plus
 * cher que ce que la garde rapporterait.
 *
 * ⚠️ ELLE NE PROUVE DONC PAS que le journal part vraiment (un `Log::warning`
 * peut etre filtre par la configuration de canaux). Elle prouve que le code ne
 * peut plus avaler une panne SANS ecrire — ce qui est exactement le defaut
 * nomme par F39-007.
 *
 * ⚠️ ELLE N'EXIGE PAS non plus de marqueur d'etat dans le JSON (`degraded`,
 * `status: indisponible`). Ce serait un changement de CONTRAT de l'endpoint et
 * d'apparence du tableau de bord : une decision de produit, laissee a Will.
 */

use Tests\TestCase;

uses(TestCase::class);

/** L'aiguille cherchee : le debut d'un bloc d'interception de ce controleur. */
const F39007_AIGUILLE = 'catch (\Throwable';

/**
 * Les CORPS des blocs d'interception d'une source PHP, par comptage d'accolades.
 *
 * On ne se contente pas de chercher `Log::` dans le fichier entier : une seule
 * journalisation, posee n'importe ou, ferait alors passer les six avalements.
 * C'est bloc par bloc que la question a un sens.
 *
 * @return list<string>
 */
function corpsDesInterceptionsF39007(string $source): array
{
    $corps = [];
    $decalage = 0;
    $longueur = strlen($source);

    while (($debut = strpos($source, F39007_AIGUILLE, $decalage)) !== false) {
        $ouvrante = strpos($source, '{', $debut);
        if ($ouvrante === false) {
            break;
        }

        $profondeur = 1;
        $i = $ouvrante + 1;
        while ($i < $longueur && $profondeur > 0) {
            if ($source[$i] === '{') {
                $profondeur++;
            } elseif ($source[$i] === '}') {
                $profondeur--;
            }
            $i++;
        }

        $corps[] = substr($source, $ouvrante + 1, $i - $ouvrante - 2);
        $decalage = $i;
    }

    return $corps;
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOIN — le decoupage lit-il bien UN bloc, et rien que lui ?
// ─────────────────────────────────────────────────────────────────────────────

test('F39-007 — TEMOIN : le decoupage isole chaque bloc d interception', function () {
    $echantillon = <<<'PHP'
    <?php
    function a() {
        try { risque(); } catch (\Throwable $e) {
            if (estActif()) { Log::warning('a'); }
            return 0;
        }
    }
    function b() {
        try { risque(); } catch (\Throwable $e) {
            return 0;
        }
    }
    PHP;

    $corps = corpsDesInterceptionsF39007($echantillon);

    expect(count($corps))->toBe(2);
    // Le premier bloc contient des accolades IMBRIQUEES : si le comptage etait
    // faux, il s'arreterait a la premiere fermante et le second bloc serait
    // perdu — la garde ne verrait alors qu'une moitie du fichier.
    expect(str_contains($corps[0], 'Log::warning'))->toBeTrue();
    expect(str_contains($corps[1], 'Log::'))->toBeFalse();
    expect(str_contains($corps[1], 'function b'))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// LA GARDE
// ─────────────────────────────────────────────────────────────────────────────

test('F39-007 — aucune rubrique d observabilite ne rend une valeur neutre sans journaliser', function () {
    $chemin = app_path('Http/Controllers/Api/ObservabilityController.php');
    $this->assertFileExists($chemin, 'ObservabilityController introuvable : la garde F39-007 n aurait rien inspecte.');

    $source = (string) file_get_contents($chemin);

    // 1. La facade doit etre IMPORTEE. Sans le `use`, `Log::warning` designerait
    //    `App\Http\Controllers\Api\Log`, qui n'existe pas : l'erreur ne se
    //    produirait que dans la branche d'interception — donc uniquement le jour
    //    d'une panne, et elle transformerait une rubrique degradee en 500.
    expect(str_contains($source, 'use Illuminate\Support\Facades\Log;'))->toBeTrue(
        'F39-007 : `Illuminate\Support\Facades\Log` n est plus importe par '
        . "ObservabilityController.\n\n"
        . 'Mesure du 2026-08-22 : le fichier ne l importait pas, et c est ce qui rendait les '
        . "six avalements totalement muets.\n\n"
        . 'GESTE : remettre `use Illuminate\Support\Facades\Log;` en tete du fichier.'
    );

    // 2. Chaque bloc d'interception journalise.
    $corps = corpsDesInterceptionsF39007($source);

    // TEMOIN DE NON-VACUITE : si plus aucun bloc n'est reconnu, la boucle
    // ci-dessous ne verifie rien et rend un vert vide.
    expect(count($corps) > 0)->toBeTrue(
        'F39-007 : aucun bloc `catch (\Throwable …)` reconnu dans ObservabilityController. '
        . 'Soit le controleur ne rattrape plus rien (bonne nouvelle : verifier, puis retirer '
        . 'cette garde), soit la forme des interceptions a change et ce test ne lit plus rien. '
        . 'GESTE : verifier le fichier avant de toucher a ce test.'
    );

    $muets = [];
    foreach ($corps as $index => $bloc) {
        if (! str_contains($bloc, 'Log::')) {
            $muets[] = '#' . ($index + 1) . ' : ' . trim(preg_replace('/\s+/', ' ', $bloc) ?? '');
        }
    }

    expect($muets === [])->toBeTrue(
        'F39-007 : ' . count($muets) . ' bloc(s) d interception rendent une valeur neutre SANS '
        . "journaliser :\n" . implode("\n", $muets) . "\n\n"
        . 'Sur l ecran de sante du produit, une rubrique tombee ressemble alors exactement a '
        . 'une rubrique calme — et « 0 echec » est la lecture la plus rassurante qui soit. '
        . "C est le defaut mesure le 2026-08-22.\n\n"
        . 'GESTE : ajouter dans ce bloc `Log::warning(\'observability.<rubrique> indisponible\', '
        . '[\'exception\' => $e->getMessage()]);` AVANT de rendre la valeur neutre. Si la '
        . 'rubrique n a pas besoin de filet, retirer l interception plutot que de la laisser '
        . 'muette : une panne bruyante vaut mieux qu un zero qui ment.'
    );
});

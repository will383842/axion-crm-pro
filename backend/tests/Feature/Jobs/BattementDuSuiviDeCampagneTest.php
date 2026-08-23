<?php

/**
 * GARDE — audit 360, B17-011 (S2) : « MonitorCampaignProgressJob a tries=1 :
 * une seule exception fige la campagne en running pour toujours ».
 *
 * CE QU'ON A MESURE LE 2026-08-22, AVANT CORRECTIF :
 *
 *   MonitorCampaignProgressJob.php:34   public int $tries = 1;
 *   MonitorCampaignProgressJob.php:184  dispatch(new self(...))->delay(60s)   <- DERNIERE instruction
 *   grep -rn 'public function failed' app/Jobs/*.php   ->  RIEN
 *
 * Le suivi d'une campagne n'est pas une tache planifiee : c'est un job qui se
 * re-dispatche lui-meme, et le re-dispatch est la derniere ligne du corps. Le
 * seul `try/catch` present ne couvre que le recompte des agregats ; tout ce qui
 * vient apres (`update($aggregates)`, `shouldAutoPause()`, les `update()` de
 * pause et de fin) peut lever hors de ce filet. Avec `tries = 1`, une exception
 * unique termine le job en echec — et, sans `failed()`, PERSONNE ne reprogramme
 * le tour suivant. La campagne reste `running` : plus jamais d'auto-pause sur
 * quota, plus jamais de passage en `completed`. Et `routes/console.php` ne
 * planifie aucun guetteur de campagnes bloquees qui viendrait rattraper.
 *
 * CE QUE LA GARDE EXIGE :
 *   1. un echec reprogramme le battement (60 s plus tard, meme campagne, meme
 *      espace) et incremente le compteur d'echecs consecutifs ;
 *   2. cette relance est BORNEE — au-dela du plafond elle s'arrete et journalise
 *      un `critical` qui nomme la campagne et dit le geste. Une relance non
 *      bornee sur une panne permanente remplacerait un suivi mort par une boucle
 *      infinie de jobs en echec ;
 *   3. tout job qui entretient son propre battement (il se re-dispatche
 *      lui-meme) declare un `failed()`. C'est la forme generale du defaut : sans
 *      cette regle, le prochain job de ce genre le repaiera.
 *
 * TEMOINS (sans eux ce vert ne prouverait rien) :
 *   - `tries === 1` est verifie : si un jour le job gagnait des tentatives, le
 *      raisonnement ci-dessus changerait, et la garde doit le dire plutot que
 *      rester verte sur une premisse morte ;
 *   - le balayage structurel compte les jobs auto-re-dispatches et ROUGIT s'il
 *     n'en voit aucun (chemin faux = zero fichier = faux vert) ;
 *   - negatif : le detecteur structurel est braque sur une source FABRIQUEE qui
 *     porte le defaut, et doit l'y voir.
 */

use App\Jobs\MonitorCampaignProgressJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

// ─────────────────────────────────────────────────────────────────────────────
// L'INSTRUMENT
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Le plafond de relances, lu sur la classe elle-meme et non recopie : une garde
 * qui redeclare la constante qu'elle surveille ne surveille plus rien.
 */
function b17011Plafond(): int
{
    $r = new ReflectionClass(MonitorCampaignProgressJob::class);

    return (int) $r->getConstant('RELANCES_MAX_APRES_ECHEC');
}

/**
 * Parcours de `app/Jobs` par scandir recursif.
 *
 * ⚠️ PAS de `RecursiveDirectoryIterator` : sur le montage Docker de ce depot il
 * TRONQUE le parcours (14 fichiers vus sur 56 reels, mesure consignee par les
 * lots precedents). Une garde qui ne voit qu'un quart de l'arbre certifie ce
 * qu'elle n'a pas inspecte.
 *
 * @return array<int, string>
 */
function b17011FichiersJobs(): array
{
    $racine = app_path('Jobs');
    if (! is_dir($racine)) {
        return [];
    }

    $trouves = [];
    $pile = [$racine];

    while ($pile !== []) {
        $dossier = array_pop($pile);
        foreach (scandir($dossier) ?: [] as $entree) {
            if ($entree === '.' || $entree === '..') {
                continue;
            }
            $chemin = $dossier . DIRECTORY_SEPARATOR . $entree;
            if (is_dir($chemin)) {
                $pile[] = $chemin;

                continue;
            }
            if (str_ends_with($chemin, '.php')) {
                $trouves[] = $chemin;
            }
        }
    }

    sort($trouves);

    return $trouves;
}

/**
 * Un job « a battement » : son propre corps re-dispatche une instance de
 * lui-meme. C'est ce motif — et lui seul — qui fait qu'une exception ne coute
 * pas un tour mais TOUS les tours suivants.
 */
function b17011EstUnBattement(string $source): bool
{
    return (bool) preg_match('/dispatch\(\s*\(?\s*new self\s*\(/', $source);
}

function b17011DeclareFailed(string $source): bool
{
    return (bool) preg_match('/public function failed\s*\(/', $source);
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. LE CONSTAT
// ═════════════════════════════════════════════════════════════════════════════

test('B17-011 — un echec du suivi reprogramme le battement au lieu de le tuer', function () {
    Queue::fake();

    $job = new MonitorCampaignProgressJob(4242);
    $job->pourEspace('11111111-1111-4111-8111-111111111111');

    // PREMISSE VERIFIEE : c'est `tries = 1` qui rend une exception fatale au
    // battement. Si elle changeait, cette garde raisonnerait dans le vide.
    expect($job->tries === 1)->toBeTrue(
        'B17-011 : le job ne porte plus `tries = 1` (' . $job->tries . '). Ce n est pas '
        . 'forcement un defaut, mais la garde ci-dessous raisonne sur « une exception = un echec '
        . 'immediat ». Geste : relire failed() et cette garde ensemble avant de changer le chiffre.',
    );

    $job->failed(new RuntimeException('colonne disparue'));

    // On relit la file plutot que d'employer `assertPushed($classe, $fermeture)` :
    // une fermeture qui rend false ne dit que « closure returned false », et un
    // message d'echec doit nommer CE qui manque et LE GESTE.
    $pousses = Queue::pushed(MonitorCampaignProgressJob::class);

    expect($pousses->count() === 1)->toBeTrue(
        'B17-011 : le `failed()` de MonitorCampaignProgressJob doit reprogrammer EXACTEMENT un '
        . 'battement (vu : ' . $pousses->count() . '). Zero = la campagne reste bloquee en '
        . '« running » pour toujours, sans auto-pause ni fin ; plus d un = boucle de jobs. '
        . 'Geste : garder un seul `dispatch(new self(...))` dans failed().',
    );

    /** @var MonitorCampaignProgressJob $suivant */
    $suivant = $pousses->first();

    expect($suivant->campaignId === 4242)->toBeTrue(
        'B17-011 : le battement reprogramme suit la campagne ' . $suivant->campaignId
        . ' au lieu de 4242. Geste : reconstruire le job avec `$this->campaignId`.',
    );
    expect($suivant->echecsConsecutifs === 1)->toBeTrue(
        'B17-011 : le compteur d echecs consecutifs vaut ' . $suivant->echecsConsecutifs
        . ' au lieu de 1. Sans lui la relance n est plus BORNEE : une panne permanente '
        . 'fabriquerait une file infinie de jobs en echec. Geste : reporter le compteur '
        . 'incremente sur le job reprogramme.',
    );
    expect($suivant->espaceCible === '11111111-1111-4111-8111-111111111111')->toBeTrue(
        'B17-011 : l espace de travail ne voyage plus avec la relance (vu : '
        . var_export($suivant->espaceCible, true) . '). Sous RLS stricte le tour suivant ne '
        . 'lirait AUCUNE ligne et sortirait en silence (constat B11-002). Geste : rappeler '
        . '`pourEspace()` sur le job reprogramme.',
    );
    expect($suivant->delay instanceof DateTimeInterface)->toBeTrue(
        'B17-011 : la relance part SANS delai. Sur une panne qui dure, le battement repartirait '
        . 'immediatement et boucherait la file. Geste : garder le `->delay(now()->addSeconds(60))`.',
    );
});

test('B17-011 — la relance apres echec est bornee et le dit', function () {
    Queue::fake();
    Log::spy();

    $plafond = b17011Plafond();

    // TEMOIN : un plafond nul ou absent rendrait le test vert sans rien borner.
    expect($plafond >= 1 && $plafond <= 60)->toBeTrue(
        'B17-011 : RELANCES_MAX_APRES_ECHEC vaut ' . $plafond . ', hors de [1, 60]. A zero, le '
        . 'battement ne repart jamais (le defaut d origine revient) ; trop haut, une panne '
        . 'permanente inonde Horizon de jobs en echec. Geste : reposer une borne dans '
        . 'MonitorCampaignProgressJob.',
    );

    $job = new MonitorCampaignProgressJob(4242);
    $job->pourEspace('11111111-1111-4111-8111-111111111111');
    $job->echecsConsecutifs = $plafond; // le prochain echec depasse la borne

    $job->failed(new RuntimeException('espace introuvable'));

    Queue::assertNothingPushed();

    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $m): bool => str_contains($m, 'B17-011')
            && str_contains($m, 'running')
            && str_contains($m, 'geste'))
        ->once();
});

// ═════════════════════════════════════════════════════════════════════════════
// 2. LA FORME GENERALE — pour que le prochain job ne repaie pas ce constat
// ═════════════════════════════════════════════════════════════════════════════

test('B17-011 — tout job qui entretient son propre battement declare failed()', function () {
    $fichiers = b17011FichiersJobs();

    // TEMOIN DE COUVERTURE, chiffre : mesure du 2026-08-22, `app/Jobs` porte au
    // moins six jobs, dont un a battement. Zero fichier lu = chemin faux.
    $this->assertGreaterThanOrEqual(
        5,
        count($fichiers),
        'Le balayage de ' . app_path('Jobs') . ' ne voit que ' . count($fichiers) . ' fichiers '
        . '(au moins 6 mesures le 2026-08-22). Geste : corriger b17011FichiersJobs() avant de '
        . 'croire ce vert — un parcours tronque certifie ce qu il n a pas lu.',
    );

    $battements = [];
    $sansFilet = [];

    foreach ($fichiers as $chemin) {
        $source = (string) file_get_contents($chemin);
        if (! b17011EstUnBattement($source)) {
            continue;
        }
        $battements[] = $chemin;
        if (! b17011DeclareFailed($source)) {
            $sansFilet[] = $chemin;
        }
    }

    $this->assertGreaterThanOrEqual(
        1,
        count($battements),
        'Le detecteur ne reconnait plus AUCUN job a battement, alors que '
        . 'MonitorCampaignProgressJob en est un. Geste : reparer b17011EstUnBattement() — son '
        . 'vert est actuellement un faux vert.',
    );

    $this->assertSame(
        [],
        $sansFilet,
        "B17-011 : ces jobs se re-dispatchent eux-memes SANS declarer failed() :\n - "
        . implode("\n - ", $sansFilet)
        . "\nUne seule exception y tue la chaine entiere, en silence. Geste : ajouter un "
        . '`public function failed(\Throwable $e): void` qui reprogramme le battement, avec un '
        . 'compteur d echecs consecutifs BORNE (voir MonitorCampaignProgressJob).',
    );
});

// ═════════════════════════════════════════════════════════════════════════════
// 3. TEMOIN NEGATIF — le detecteur voit-il encore le defaut ?
// ═════════════════════════════════════════════════════════════════════════════

test('B17-011 — le detecteur structurel retrouve le defaut sur une source fabriquee', function () {
    $avecDefaut = <<<'PHP'
        class JobSansFilet implements ShouldQueue
        {
            public int $tries = 1;

            public function handle(): void
            {
                dispatch((new self($this->id))->pourEspace($this->ws))->delay(now()->addSeconds(60));
            }
        }
        PHP;

    expect(b17011EstUnBattement($avecDefaut))->toBeTrue(
        'Le detecteur ne voit plus un `dispatch((new self(...)))` : son vert sur app/Jobs ne '
        . 'prouve plus rien. Geste : reparer la regexp de b17011EstUnBattement().',
    );
    expect(b17011DeclareFailed($avecDefaut))->toBeFalse(
        'Le detecteur croit voir un failed() la ou il n y en a pas. Geste : reparer '
        . 'b17011DeclareFailed().',
    );

    $reparee = $avecDefaut . "\nclass X { public function failed(\\Throwable \$e): void {} }";
    expect(b17011DeclareFailed($reparee))->toBeTrue(
        'Le detecteur ne reconnait pas un failed() pourtant present : il accuserait a tort tous '
        . 'les jobs du depot. Geste : reparer b17011DeclareFailed().',
    );
});

<?php

/**
 * GARDE — audit 360, B16-006 / F39-006 / B17-003 (S1) : le controle d'integrite
 * planifie a 03:00 N'AVERTISSAIT PERSONNE.
 *
 * Mesure du 2026-08-20, code casse :
 *   - `routes/console.php:14` : `Schedule::command('audit:verify-chain')->dailyAt('03:00')`,
 *     sans `onFailure`, sans `emailOutputOnFailure`, sans rien. Le planificateur
 *     de Laravel N'INTERPRETE PAS le code de sortie d'une commande planifiee :
 *     un `return self::FAILURE` disparait dans le neant.
 *   - `AuditVerifyChain::25` : la commande ecrivait la rupture avec
 *     `$this->error(...)`, c'est-a-dire sur la sortie standard d'erreur d'un
 *     processus que PERSONNE ne lit, suivie du commentaire
 *     « En prod : envoi Slack/Telegram + ouverture incident » — une intention,
 *     pas un code.
 *
 * Une chaine d'audit dont la rupture n'alerte personne ne prouve rien : elle
 * transforme une falsification detectee en une falsification detectee ET tue.
 *
 * Ces gardes exigent DEUX choses distinctes, et c'est volontaire :
 *   1. que la COMMANDE emette une alerte de niveau critique ;
 *   2. que la LIGNE PLANIFIEE la fasse remonter quand la commande echoue —
 *    verifie en executant reellement les rappels d'echec de l'evenement.
 */

use App\Services\Audit\AuditHashChain;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Le marqueur que doit porter toute alerte d'integrite de la chaine.
 *
 * ⚠️ Ecrit ICI EN DUR, et non repris d'une constante de la commande : une garde
 * qui importe la constante qu'elle controle ne controle plus rien — renommer la
 * valeur des deux cotes la laisserait verte. Sous-chaine SANS ACCENT a dessein.
 */
const MARQUEUR_ALERTE_INTEGRITE = 'ALERTE INTEGRITE';

/**
 * Le secret est lu par `config()`, resolu UNE FOIS a l'amorcage : poser la
 * variable d'environnement seule ne suffirait pas (cf. ChaineAuditSecretTest).
 */
function poserSecretDeChaine(string $valeur): void
{
    config(['services.audit.hash_chain_secret' => $valeur]);
}

/** L'evenement planifie qui porte `audit:verify-chain`, ou null. */
function evenementPlanifieDeVerification(): ?Event
{
    // Le fichier `routes/console.php` n'est charge qu'a l'amorcage du noyau
    // console : sans cette ligne, un test HTTP lirait un planning VIDE et la
    // garde passerait au vert en ne mesurant rien.
    app()->make(Kernel::class)->bootstrap();

    foreach (app(Schedule::class)->events() as $evenement) {
        if (str_contains((string) $evenement->command, 'audit:verify-chain')) {
            return $evenement;
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. LA COMMANDE ALERTE
// ─────────────────────────────────────────────────────────────────────────────

test('B16-006 — une chaine ROMPUE fait sortir la commande en echec ET emet une alerte critique', function () {
    poserSecretDeChaine('un-secret-de-chaine-reellement-secret-2026');
    $chaine = new AuditHashChain;
    $chaine->record(['method' => 'POST', 'path' => '/origine', 'status' => 201]);

    // TEMOIN 1 — avant falsification, la chaine est bien valide : ce que la
    // garde va voir ensuite est donc la RUPTURE, et pas un secret manquant ni
    // une table vide.
    expect($chaine->verifyChain())->toBeTrue();

    // La falsification : on reecrit le chemin sans toucher aux condenses.
    DB::table('audit_logs')->update(['path' => '/falsifie']);

    $capture = null;
    $journal = Log::spy();

    $this->artisan('audit:verify-chain')->assertExitCode(1);

    $journal->shouldHaveReceived('critical')->withArgs(function ($message) use (&$capture) {
        $capture = (string) $message;

        return true;
    });

    // Sous-chaine SANS ACCENT : le message est francais, la garde ne doit pas
    // dependre de l'encodage du fichier qui la porte.
    $this->assertStringContainsString(
        MARQUEUR_ALERTE_INTEGRITE,
        (string) $capture,
        "La rupture de chaine n'a produit aucune alerte de niveau critique.",
    );
    $this->assertStringContainsString(
        'rompue',
        (string) $capture,
        "L'alerte ne dit pas que la chaine est ROMPUE.",
    );
});

test('B16-006 — TEMOIN : une chaine INTACTE ne reveille personne', function () {
    // Sans ce temoin, une commande qui hurlerait a chaque passage passerait pour
    // une reussite — et une alerte qui se declenche toutes les nuits est une
    // alerte que plus personne ne lit.
    poserSecretDeChaine('un-secret-de-chaine-reellement-secret-2026');
    $chaine = new AuditHashChain;
    $chaine->record(['method' => 'POST', 'path' => '/a', 'status' => 201]);
    $chaine->record(['method' => 'PUT', 'path' => '/b', 'status' => 200]);

    $journal = Log::spy();

    $this->artisan('audit:verify-chain')->assertExitCode(0);

    $journal->shouldNotHaveReceived('critical');
});

test('B16-006 — un secret inutilisable est annonce comme tel, pas comme une falsification', function () {
    // DECOUVERTE de ce lot : `verifyChain()` rend `false` DANS LES DEUX CAS —
    // chaine rompue et secret absent (constat B16-001). La commande annoncait
    // alors « possible falsification detectee » a un exploitant dont le seul
    // tort etait une variable d'environnement manquante. Une alerte qui envoie
    // chercher au mauvais endroit coute la nuit de celui qui la recoit.
    poserSecretDeChaine('');

    $capture = null;
    $journal = Log::spy();

    $this->artisan('audit:verify-chain')->assertExitCode(1);

    $journal->shouldHaveReceived('critical')->withArgs(function ($message) use (&$capture) {
        $capture = (string) $message;

        return true;
    });

    $this->assertStringContainsString(
        'AUDIT_HASH_CHAIN_SECRET',
        (string) $capture,
        "L'alerte ne nomme pas la vraie cause : le secret de chaine.",
    );
    $this->assertStringNotContainsString(
        'falsification',
        (string) $capture,
        'Un secret absent est annonce comme une falsification : le diagnostic envoie au mauvais endroit.',
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LA LIGNE PLANIFIEE FAIT REMONTER L'ECHEC
// ─────────────────────────────────────────────────────────────────────────────

test('B16-006 — la tache de 03:00 existe bien et porte cet horaire', function () {
    $evenement = evenementPlanifieDeVerification();

    // TEMOIN — si la recherche ne trouvait rien, la garde suivante passerait au
    // vert sur un planning vide.
    $this->assertNotNull($evenement, 'Aucune tache planifiee ne lance `audit:verify-chain`.');
    $this->assertSame(
        '0 3 * * *',
        $evenement->expression,
        "La verification d'integrite n'est plus planifiee a 03:00.",
    );
});

test('B16-006 — quand la tache de 03:00 ECHOUE, une alerte critique part', function () {
    $evenement = evenementPlanifieDeVerification();
    $this->assertNotNull($evenement);

    // On ne simule pas l'alerte : on met l'evenement dans l'etat « la commande
    // vient de sortir en echec » et on execute REELLEMENT ses rappels de fin.
    // C'est exactement ce que fait le planificateur apres chaque execution.
    $evenement->exitCode = 1;

    $capture = null;
    $journal = Log::spy();

    $evenement->callAfterCallbacks(app());

    $journal->shouldHaveReceived('critical')->withArgs(function ($message) use (&$capture) {
        $capture = (string) $message;

        return true;
    });

    $this->assertStringContainsString(
        MARQUEUR_ALERTE_INTEGRITE,
        (string) $capture,
        "L'echec de la tache planifiee de 03:00 n'avertit personne (constat B16-006).",
    );
});

test('B16-006 — TEMOIN : quand la tache de 03:00 REUSSIT, rien ne part', function () {
    $evenement = evenementPlanifieDeVerification();
    $this->assertNotNull($evenement);

    // Sans ce temoin, un rappel qui alerterait a chaque passage — reussite
    // comprise — passerait pour un correctif.
    $evenement->exitCode = 0;

    $journal = Log::spy();

    $evenement->callAfterCallbacks(app());

    $journal->shouldNotHaveReceived('critical');
});

<?php

/**
 * GARDE — audit 360, B16-009 (S1, documentation) : suivre le runbook « disque
 * plein » rendait le controle d'integrite DEFINITIVEMENT rouge.
 *
 * `infra/runbooks/02-disk-full.md`, section 3, ligne 21-24 :
 *
 *     # Détacher anciennes partitions audit_logs
 *     SELECT partman.run_maintenance('public.audit_logs', p_jobmon := false);
 *
 * `AuditHashChain::verifyChain()` part de la sentinelle GENESIS (64 zeros) et
 * exige que la PREMIERE ligne lue porte ce `prev_hash`. Detacher les partitions
 * anciennes, c'est retirer les PREMIERS maillons : la premiere ligne restante
 * pointe alors sur un condense qui n'est plus dans la table. Le controle rend
 * `false` — et il le rendra a chaque passage, pour toujours, sans qu'aucune
 * falsification ait eu lieu.
 *
 * Consequence pratique, et c'est ce qui en fait un S1 : le runbook est ecrit
 * pour etre applique EN INCIDENT, a 3 heures du matin, par quelqu'un qui n'a
 * pas le temps de lire le code du service d'audit. Il transformait une nuit de
 * disque plein en perte definitive de la valeur probante du journal — et, une
 * fois `audit:verify-chain` cable sur une alerte (B16-006), en une alerte
 * critique quotidienne que plus personne ne pourra eteindre.
 *
 * Ce fichier fait DEUX choses, et les deux comptent :
 *   1. il MESURE la mecanique (retirer le premier maillon casse la chaine pour
 *      de bon) — sans quoi la garde documentaire ne serait qu'une opinion ;
 *   2. il exige que le runbook porte l'avertissement, la ou se trouve la
 *      commande dangereuse.
 */

use App\Services\Audit\AuditHashChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function cheminRunbookDisquePlein(): string
{
    // `base_path('..')` : les runbooks vivent a la racine du depot, pas dans
    // `backend/`.
    return (realpath(base_path('..')) ?: base_path('..')) . '/infra/runbooks/02-disk-full.md';
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. LA MECANIQUE, MESUREE
// ─────────────────────────────────────────────────────────────────────────────

test('B16-009 — retirer les PREMIERS maillons casse la chaine DEFINITIVEMENT', function () {
    config(['services.audit.hash_chain_secret' => 'un-secret-de-chaine-reellement-secret-2026']);
    $chaine = new AuditHashChain;

    $chaine->record(['method' => 'POST', 'path' => '/le-plus-ancien', 'status' => 201]);
    $chaine->record(['method' => 'POST', 'path' => '/milieu', 'status' => 201]);
    $chaine->record(['method' => 'POST', 'path' => '/recent', 'status' => 201]);

    // TEMOIN — la chaine est intacte AVANT. Sans lui, le `false` mesure ensuite
    // pourrait venir d'un secret absent ou d'une table mal remplie.
    expect($chaine->verifyChain())->toBeTrue();

    $premierId = (int) DB::table('audit_logs')->min('id');

    // C'est EXACTEMENT ce que fait un detachement de partition ancienne : les
    // lignes les plus vieilles quittent la table, les recentes restent.
    DB::table('audit_logs')->where('id', $premierId)->delete();

    $this->assertFalse(
        $chaine->verifyChain(),
        "Retirer le premier maillon devrait casser la verification : si ce n'est "
        . 'pas le cas, la garde documentaire ci-dessous ne repose sur rien.',
    );

    // « DEFINITIVEMENT » : rien ne se repare tout seul au passage suivant, et
    // aucune ecriture ulterieure ne rend la chaine a nouveau verifiable.
    $chaine->record(['method' => 'POST', 'path' => '/apres-incident', 'status' => 201]);
    $this->assertFalse(
        $chaine->verifyChain(),
        'La chaine redevient valide apres coup : la mesure ci-dessus etait fausse.',
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LE RUNBOOK LE DIT
// ─────────────────────────────────────────────────────────────────────────────

test('B16-009 — le runbook AVERTIT avant de detacher des partitions audit_logs', function () {
    $chemin = cheminRunbookDisquePlein();

    $this->assertFileExists($chemin, 'Le runbook « disque plein » est introuvable.');
    $contenu = (string) file_get_contents($chemin);

    // TEMOIN 1 — c'est bien le bon fichier, et il porte toujours la commande
    // dangereuse : la garde ne passe pas au vert sur un fichier vide ou tronque.
    $this->assertStringContainsString(
        'run_maintenance',
        $contenu,
        'Le runbook ne parle plus de partitionnement : cette garde ne mesure plus rien.',
    );
    $this->assertStringContainsString('df -h', $contenu, "Ce n'est pas le runbook « disque plein ».");

    // Sous-chaines SANS ACCENT : le texte est francais, la garde ne doit pas
    // dependre de l'encodage du fichier qu'elle lit.
    $this->assertStringContainsString(
        'audit:verify-chain',
        $contenu,
        "Le runbook ne nomme pas le controle qu'il casse : celui qui l'applique en "
        . 'incident ne peut pas savoir ce qu il vient de perdre.',
    );
    $this->assertStringContainsString(
        'NE PAS DETACHER',
        $contenu,
        "Le runbook n'interdit pas le detachement des partitions `audit_logs` : "
        . "l'appliquer rend la verification d'integrite definitivement rouge (B16-009).",
    );
});

test('B16-009 — l avertissement est AVANT la commande dangereuse, pas apres', function () {
    // Un avertissement place en fin de document n'est pas lu par quelqu'un qui
    // deroule un runbook a 3 heures du matin. La position est le correctif.
    $contenu = (string) file_get_contents(cheminRunbookDisquePlein());

    $positionAvertissement = strpos($contenu, 'NE PAS DETACHER');
    $positionCommande = strpos($contenu, 'run_maintenance');

    $this->assertNotFalse($positionAvertissement, "L'avertissement est absent.");
    $this->assertNotFalse($positionCommande, 'La commande de partitionnement est absente.');
    $this->assertLessThan(
        $positionCommande,
        $positionAvertissement,
        "L'avertissement est ecrit APRES la commande qu'il concerne : il sera lu trop tard.",
    );
});

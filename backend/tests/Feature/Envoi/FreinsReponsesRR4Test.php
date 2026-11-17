<?php

/**
 * RR-4B — les réponses humaines relevées comptent dans les freins ; le frein humain est nourri par la file réelle des réponses.
 * Chaque test de cloisonnement tourne sous le rôle applicatif (RLS) ET sous le propriétaire. Données fictives (example.test).
 */

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\Freins;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\RecepteurRetoursFreins;
use App\Envoi\Imap\RetourDetecte;
use App\Envoi\JourEnvoi;
use App\Envoi\Reponses\ServiceReponses;
use App\Envoi\Reponses\StockerReponse;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const RR4B_MODES = ['app', 'proprio'];

/** Exécute sous le mode : « app » = rôle applicatif non propriétaire sous RLS, « proprio » = propriétaire. */
function rr4bSous(string $mode, string $ws, Closure $f): mixed
{
    WorkspaceContext::set($ws);
    if ($mode === 'app') {
        DB::statement('SET ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/** Un retour relevé de la classe donnée, transmis au maillon des freins. */
function rr4bRetour(string $ws, ClasseRetour $classe, string $cle, string $mode = 'proprio'): void
{
    rr4bSous($mode, $ws, fn () => app(RecepteurRetoursFreins::class)->recevoir(
        $ws,
        new RetourDetecte($classe, 'inj-' . $cle, "{$cle}@zz-client.example.test", null, hash('sha256', $cle)),
    ));
}

function rr4bReponses(string $ws): int
{
    return app(DepotChauffe::class)->totaux7Jours($ws, JourEnvoi::de(now()->toDateTimeImmutable()), 'domaine')['reponses'];
}

/** Une réponse stockée dans la file (événement + réponse) ; rattachée ou non. Rend l'identifiant de la réponse. */
function rr4bFile(string $ws, string $cle, bool $rattachee = true): int
{
    $emp = hash('sha256', $cle);
    $evenement = WorkspaceContext::run($ws, fn () => DB::table('envoi_evenements')->insertGetId([
        'workspace_id' => $ws, 'boite' => 'b@zz-rr4b.example.test', 'uid_validity' => 1, 'uid' => random_int(1, 1_000_000),
        'empreinte_message' => $emp, 'classe' => 'reponse_humaine', 'statut' => $rattachee ? 'rattache' : 'a_rattacher',
        'empreinte_expediteur' => ListeSuppression::empreinte("{$cle}@zz-client.example.test"), 'recu_le' => now(),
    ]));
    app(StockerReponse::class)->enregistrer($ws, $emp, 'Objet', 'Bonjour', "{$cle}@zz-client.example.test", 'reponse_humaine');

    return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_reponses')->where('evenement_id', $evenement)->value('id'));
}

/** Classement par un humain : la réponse sort de la file. */
function rr4bClasser(string $ws, int $id): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_reponses_classements')->insert([
        'workspace_id' => $ws, 'reponse_id' => $id, 'geste' => 'classement', 'classement' => 'refus',
        'confiance' => 100, 'auteur' => 'humain', 'cree_le' => now(),
    ]));
}

beforeEach(function () {
    $this->a = F::espace('zz-rr4b-a');
    $this->b = F::espace('zz-rr4b-b');
    Cache::flush();
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('F1 deux réponses humaines relevées comptent 2 dans les freins ; la même relue en rattrapage reste à 2', function () {
    rr4bRetour($this->a, ClasseRetour::ReponseHumaine, 'f1-un');
    rr4bRetour($this->a, ClasseRetour::ReponseHumaine, 'f1-deux');
    expect(rr4bReponses($this->a))->toBe(2);
    rr4bRetour($this->a, ClasseRetour::ReponseHumaine, 'f1-un');
    rr4bRetour($this->a, ClasseRetour::ReponseHumaine, 'f1-deux');
    expect(rr4bReponses($this->a))->toBe(2);
});

test('F2 une auto-réponse et un rebond mou ne changent pas les réponses comptées', function () {
    rr4bRetour($this->a, ClasseRetour::AutoReponse, 'f2-auto');
    rr4bRetour($this->a, ClasseRetour::RebondMou, 'f2-mou');
    expect(rr4bReponses($this->a))->toBe(0);
});

test('F3 15 réponses non classées = pause ; une classée = 14 = plus de pause (file réelle, sans journal)', function () {
    $ids = [];
    foreach (range(1, 15) as $i) {
        $ids[] = rr4bFile($this->a, "f3-{$i}");
    }
    $depot = app(DepotChauffe::class);
    expect(ServiceReponses::nonTraitees($this->a))->toBe(15)
        ->and($depot->reponsesEnAttente($this->a))->toBe(15)
        ->and(Freins::freinHumain($depot->reponsesEnAttente($this->a)))->toBe(Freins::PAUSE);
    rr4bClasser($this->a, $ids[0]);
    expect($depot->reponsesEnAttente($this->a))->toBe(14)
        ->and(Freins::freinHumain($depot->reponsesEnAttente($this->a)))->toBe(Freins::RALENTI);
});

test('F3b « à rattacher » compte dans la file ; le journal garde son sens (le maximum des deux)', function () {
    rr4bFile($this->a, 'f3b-1', false);
    rr4bFile($this->a, 'f3b-2', true);
    $depot = app(DepotChauffe::class);
    expect($depot->reponsesEnAttente($this->a))->toBe(2);
    foreach (range(1, 5) as $_) {
        $depot->journaliser($this->a, 'reponse_recue', 'test');
    }
    expect($depot->reponsesEnAttente($this->a))->toBe(5); // journal 5 − 0 > file 2
});

test('F4 les réponses de l’Espace B ne comptent pas pour A (app ET proprio)', function () {
    foreach (RR4B_MODES as $mode) {
        $avantA = rr4bSous($mode, $this->a, fn () => ServiceReponses::nonTraitees($this->a));
        $avantFreinA = rr4bReponses($this->a);
        rr4bFile($this->b, "f4-{$mode}-1");
        rr4bFile($this->b, "f4-{$mode}-2");
        rr4bRetour($this->b, ClasseRetour::ReponseHumaine, "f4-{$mode}", $mode);
        expect(rr4bSous($mode, $this->a, fn () => ServiceReponses::nonTraitees($this->a)))->toBe($avantA, $mode)
            ->and(rr4bSous($mode, $this->a, fn () => app(DepotChauffe::class)->reponsesEnAttente($this->a)))->toBe($avantA, $mode)
            ->and(rr4bReponses($this->a))->toBe($avantFreinA, $mode)
            ->and(rr4bSous($mode, $this->b, fn () => ServiceReponses::nonTraitees($this->b)))->toBeGreaterThanOrEqual(2, $mode);
    }
});

<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Reponses\ServiceReponses;
use App\Envoi\Reponses\StockerReponse;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\DoublonsFixtures as F;

/**
 * RR-6A — annuler un classement humain, corriger depuis « Traitées », STOP non annulable (service seul).
 * Données fictives (example.test), aucun réseau, aucun envoi.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

/** « app » = rôle applicatif sous RLS ; « proprio » = propriétaire (seul le filtre d'espace protège). */
function ac6Sous(string $mode, string $ws, \Closure $f): mixed
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

/** @return array{entreprise: int, ins: string, reponse: int, adresse: string} */
function ac6Monde(string $ws, string $domaine = 'societe-a'): array
{
    $adresse = 'zoe.martin@' . $domaine . '.example.test';

    return WorkspaceContext::run($ws, function () use ($ws, $domaine, $adresse): array {
        $sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
        $e = F::fiche($ws, 'ZZ Entreprise ' . $domaine);
        $c = (int) DB::table('contacts')->insertGetId([
            'workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Zoe', 'last_name' => 'T-' . substr(md5($adresse), 0, 8),
            'email' => $adresse, 'email_status' => 'valid', 'created_at' => now(),
        ]);
        $ins = (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $sid, $c, new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('Europe/Paris')));
        $cle = hash('sha256', Str::random(20));
        $evenement = DB::table('envoi_evenements')->insertGetId([
            'workspace_id' => $ws, 'boite' => 'b@example.test', 'uid_validity' => 1, 'uid' => random_int(1, 1_000_000),
            'empreinte_message' => $cle, 'classe' => 'reponse_humaine', 'statut' => 'a_rattacher',
            'empreinte_expediteur' => ListeSuppression::empreinte($adresse), 'recu_le' => now(),
        ]);
        app(StockerReponse::class)->enregistrer($ws, $cle, 'Objet de test', 'Extrait de la reponse.', $adresse, 'reponse_humaine');

        return ['entreprise' => $e, 'ins' => $ins, 'adresse' => $adresse, 'reponse' => (int) DB::table('envoi_reponses')->where('evenement_id', $evenement)->value('id')];
    });
}

function ac6Lignes(string $ws, int $reponse, ?string $geste = null): int
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_reponses_classements')->where('workspace_id', $ws)->where('reponse_id', $reponse)
        ->when($geste, fn ($q) => $q->where('geste', $geste))->count());
}

function ac6Statut(string $ws, string $ins): string
{
    return WorkspaceContext::run($ws, fn () => (string) DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $ins)->value('statut'));
}

function ac6Svc(): ServiceReponses
{
    return app(ServiceReponses::class);
}

beforeEach(function () {
    $this->ws = $this->espace(true);
    $this->uid = (string) Str::uuid();
});

test('N1 « intéressé » puis annuler : de nouveau à traiter, deux lignes, rien défait (arrêt, tâche)', function () {
    foreach (['app', 'proprio'] as $mode) {
        $m = ac6Monde($this->ws->id, 'n1-' . $mode);
        ac6Sous($mode, $this->ws->id, fn () => ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'interesse', true));
        [$etat, $res] = ac6Sous($mode, $this->ws->id, fn () => ac6Svc()->annuler($this->ws->id, $this->uid, $m['reponse']));
        expect($etat)->toBe('ok', $mode)->and($res['message'])->toBe('Classement annulé. Les arrêts et les tâches déjà créés sont conservés.')
            ->and($res['traitee'])->toBeFalse()->and($res['classement'])->not->toBe('interesse');
        // la réponse de ce monde n'est pas rattachée : elle revient dans « à rattacher » (et plus dans « traitées »)
        $liste = ac6Sous($mode, $this->ws->id, fn () => ac6Svc()->lister($this->ws->id, 'a_rattacher', null, null, 1, 50));
        $traitees = ac6Sous($mode, $this->ws->id, fn () => ac6Svc()->lister($this->ws->id, 'traitees', null, null, 1, 50));
        expect(collect($liste['data'])->pluck('id')->all())->toContain((string) $m['reponse'])
            ->and(collect($traitees['data'])->pluck('id')->all())->not->toContain((string) $m['reponse'])
            ->and(ac6Lignes($this->ws->id, $m['reponse']))->toBe(3) // classificateur + humain + annulation
            ->and(ac6Statut($this->ws->id, $m['ins']))->toBe('arretee')
            ->and(WorkspaceContext::run($this->ws->id, fn () => DB::table('activities')->where('external_ref', "envoi:reponse:{$m['reponse']}:interesse")->count()))->toBe(1);
    }
});

test('N2 annuler deux fois : la seconde « pas_traitee », une seule ligne d’annulation', function () {
    $m = ac6Monde($this->ws->id);
    ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'refus');
    expect(ac6Svc()->annuler($this->ws->id, $this->uid, $m['reponse'])[0])->toBe('ok')
        ->and(ac6Svc()->annuler($this->ws->id, $this->uid, $m['reponse']))->toBe(['pas_traitee', null])
        ->and(ac6Lignes($this->ws->id, $m['reponse'], 'annulation'))->toBe(1);
});

test('N3 « stop » non annulable ni corrigeable ; opposition et liste de suppression intactes', function () {
    foreach (['app', 'proprio'] as $mode) {
        $m = ac6Monde($this->ws->id, 'n3-' . $mode);
        ac6Sous($mode, $this->ws->id, fn () => ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'stop'));
        $avant = ac6Lignes($this->ws->id, $m['reponse']);
        $opp = WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_oppositions')->where('workspace_id', $this->ws->id)->count());
        expect(ac6Sous($mode, $this->ws->id, fn () => ac6Svc()->annuler($this->ws->id, $this->uid, $m['reponse'])))->toBe(['stop_definitif', null]);
        expect(fn () => ac6Sous($mode, $this->ws->id, fn () => ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'refus')))
            ->toThrow(\DomainException::class, ServiceReponses::STOP_DEFINITIF);
        expect(ac6Lignes($this->ws->id, $m['reponse']))->toBe($avant)
            ->and(WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_oppositions')->where('workspace_id', $this->ws->id)->count()))->toBe($opp);
        // rejouer « stop » reste permis
        expect(ac6Sous($mode, $this->ws->id, fn () => ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'stop'))['classement'])->toBe('stop');
    }
});

test('N4 « refus » puis corriger en « intéressé » : classement intéressé, tâche créée', function () {
    $m = ac6Monde($this->ws->id);
    ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'refus');
    $r = ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'interesse', true);
    expect($r['classement'])->toBe('interesse')->and($r['traitee'])->toBeTrue()->and($r['resume_crm']['tache'])->toBe('Rappeler : réponse intéressée');
});

test('N5 annuler puis reclasser le même classement : une nouvelle ligne (pas pris pour un doublon)', function () {
    $m = ac6Monde($this->ws->id);
    ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'question');
    ac6Svc()->annuler($this->ws->id, $this->uid, $m['reponse']);
    $avant = ac6Lignes($this->ws->id, $m['reponse'], 'classement');
    $r = ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'question');
    expect($r['classement'])->toBe('question')->and($r['traitee'])->toBeTrue()->and(ac6Lignes($this->ws->id, $m['reponse'], 'classement'))->toBe($avant + 1);
    // rejeu immédiat : toujours pas de doublon
    ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'question');
    expect(ac6Lignes($this->ws->id, $m['reponse'], 'classement'))->toBe($avant + 1);
});

test('N6 réponse d’un autre espace : « inconnue » (app ET proprio)', function () {
    $autre = $this->espace(true);
    $b = ac6Monde($autre->id);
    foreach (['app', 'proprio'] as $mode) {
        expect(ac6Sous($mode, $this->ws->id, fn () => ac6Svc()->annuler($this->ws->id, $this->uid, $b['reponse'])))->toBe(['inconnue', null], $mode);
    }
    expect(ac6Lignes($autre->id, $b['reponse'], 'annulation'))->toBe(0);
});

test('N7 « non traitées » augmente de 1 après une annulation', function () {
    $m = ac6Monde($this->ws->id);
    ac6Svc()->classer($this->ws->id, $this->uid, $m['reponse'], 'refus');
    $avant = ServiceReponses::nonTraitees($this->ws->id);
    ac6Svc()->annuler($this->ws->id, $this->uid, $m['reponse']);
    expect(ServiceReponses::nonTraitees($this->ws->id))->toBe($avant + 1);
});

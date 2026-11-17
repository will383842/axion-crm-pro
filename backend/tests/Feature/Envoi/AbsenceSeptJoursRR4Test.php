<?php

/**
 * RR-4C — une auto-réponse d'absence repousse la relance de 7 jours, sans rien arrêter ni opposer.
 * Rôle applicatif (RLS) ET propriétaire. Horloge figée en UTC. Données fictives (example.test), aucun réseau.
 */

use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\RecepteurRetoursSequence;
use App\Envoi\Imap\RetourDetecte;
use App\Envoi\Sequence\ReportAbsence;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const RR4C_MODES = ['app', 'proprio'];

function rr4cSous(string $mode, string $ws, Closure $f): mixed
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

/** Une inscription (statut, échéance) avec un message envoyé portant l'injection ; rend [id inscription, injection, adresse]. */
function rr4cInscription(string $ws, string $statut, string $echeance): array
{
    return WorkspaceContext::run($ws, function () use ($ws, $statut, $echeance): array {
        $sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
        $adresse = 'zoe.' . Str::lower(Str::random(8)) . '@zz-client.example.test';
        $id = (string) Str::uuid();
        $injection = (string) Str::uuid();
        DB::table('envoi_inscriptions')->insert([
            'id' => $id, 'workspace_id' => $ws, 'sequence_id' => $sid, 'contact_id' => random_int(1, 1_000_000), 'entreprise_id' => random_int(1, 1_000_000),
            'empreinte_adresse' => ListeSuppression::empreinte($adresse), 'statut' => $statut, 'motif_arret' => $statut === 'arretee' ? 'opposition' : null,
            'prochaine_echeance' => $echeance, 'version_figee' => '{}',
        ]);
        DB::table('envoi_messages')->insert([
            'workspace_id' => $ws, 'inscription_id' => $id, 'etape' => 1, 'statut' => 'envoye', 'message_id' => "<m-{$id}@zz.example.test>", 'id_injection' => $injection,
        ]);

        return [$id, $injection, $adresse];
    });
}

function rr4cAbsence(string $mode, string $ws, string $injection, string $adresse): void
{
    rr4cSous($mode, $ws, fn () => RecepteurRetoursSequence::parDefaut()->recevoir(
        $ws,
        new RetourDetecte(ClasseRetour::AutoReponse, $injection, $adresse, null, hash('sha256', Str::random(12))),
    ));
}

function rr4cLigne(string $ws, string $id): stdClass
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $id)->selectRaw("statut, motif_arret, to_char(prochaine_echeance AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS echeance")->first());
}

beforeEach(function () {
    $this->a = F::espace('zz-rr4c-a');
    $this->b = F::espace('zz-rr4c-b');
});

afterEach(function () {
    Carbon::setTestNow();
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('B1 absence relevée le 07/12 à 10:00 : échéance au 14/12 à 10:00 (au lieu du 08/12)', function () {
    foreach (RR4C_MODES as $mode) {
        Carbon::setTestNow(new DateTimeImmutable('2026-12-07 10:00:00', new DateTimeZone('UTC')));
        [$id, $inj, $adr] = rr4cInscription($this->a, 'active', '2026-12-08 09:00:00+00');
        rr4cAbsence($mode, $this->a, $inj, $adr);
        expect(rr4cLigne($this->a, $id)->echeance)->toBe('2026-12-14 10:00', $mode);
    }
});

test('B2 deuxième absence : 7 jours après la dernière ; une échéance plus lointaine n’est jamais avancée', function () {
    foreach (RR4C_MODES as $mode) {
        [$id, $inj, $adr] = rr4cInscription($this->a, 'active', '2026-12-08 09:00:00+00');
        Carbon::setTestNow(new DateTimeImmutable('2026-12-07 10:00:00', new DateTimeZone('UTC')));
        rr4cAbsence($mode, $this->a, $inj, $adr);
        Carbon::setTestNow(new DateTimeImmutable('2026-12-09 10:00:00', new DateTimeZone('UTC')));
        rr4cAbsence($mode, $this->a, $inj, $adr);
        expect(rr4cLigne($this->a, $id)->echeance)->toBe('2026-12-16 10:00', $mode);

        [$loin, $injL, $adrL] = rr4cInscription($this->a, 'active', '2027-01-10 09:00:00+00');
        rr4cAbsence($mode, $this->a, $injL, $adrL);
        expect(rr4cLigne($this->a, $loin)->echeance)->toBe('2027-01-10 09:00', $mode);
    }
});

test('B3 une inscription arrêtée ou terminée n’est jamais touchée ni rouverte', function () {
    foreach (RR4C_MODES as $mode) {
        Carbon::setTestNow(new DateTimeImmutable('2026-12-07 10:00:00', new DateTimeZone('UTC')));
        [$arretee, $injA, $adrA] = rr4cInscription($this->a, 'arretee', '2026-12-08 09:00:00+00');
        [$terminee, $injT, $adrT] = rr4cInscription($this->a, 'terminee', '2026-12-08 09:00:00+00');
        rr4cAbsence($mode, $this->a, $injA, $adrA);
        rr4cAbsence($mode, $this->a, $injT, $adrT);
        expect(rr4cLigne($this->a, $arretee))->toMatchObject(['statut' => 'arretee', 'echeance' => '2026-12-08 09:00'])
            ->and(rr4cLigne($this->a, $terminee))->toMatchObject(['statut' => 'terminee', 'echeance' => '2026-12-08 09:00']);
    }
});

test('B4 le service ne touche jamais l’espace voisin (filtre explicite, rôle applicatif ET propriétaire)', function () {
    foreach (RR4C_MODES as $mode) {
        Carbon::setTestNow(new DateTimeImmutable('2026-12-07 10:00:00', new DateTimeZone('UTC')));
        [$idA] = rr4cInscription($this->a, 'active', '2026-12-08 09:00:00+00');
        [$idB, $injB, $adrB] = rr4cInscription($this->b, 'active', '2026-12-08 09:00:00+00');
        // L'absence vient de l'Espace B : rien ne bouge dans A, même si l'on désigne l'inscription de A.
        rr4cSous($mode, $this->b, fn () => (new ReportAbsence)->reporter($this->b, [$idA], new DateTimeImmutable('2026-12-07 10:00:00', new DateTimeZone('UTC'))));
        expect(rr4cLigne($this->a, $idA)->echeance)->toBe('2026-12-08 09:00', $mode);
        rr4cAbsence($mode, $this->b, $injB, $adrB);
        expect(rr4cLigne($this->b, $idB)->echeance)->toBe('2026-12-14 10:00', $mode)
            ->and(rr4cLigne($this->a, $idA)->echeance)->toBe('2026-12-08 09:00', $mode);
    }
});

test('B5 l’auto-réponse n’arrête rien et n’oppose rien', function () {
    foreach (RR4C_MODES as $mode) {
        Carbon::setTestNow(new DateTimeImmutable('2026-12-07 10:00:00', new DateTimeZone('UTC')));
        [$id, $inj, $adr] = rr4cInscription($this->a, 'active', '2026-12-08 09:00:00+00');
        rr4cAbsence($mode, $this->a, $inj, $adr);
        expect(rr4cLigne($this->a, $id)->statut)->toBe('active', $mode);
        expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_oppositions')->where('workspace_id', $this->a)->count()))->toBe(0, $mode);
        expect(WorkspaceContext::run($this->a, fn () => DB::table('envoi_inscriptions_evenements')->where('inscription_id', $id)->count()))->toBe(0, $mode);
    }
});

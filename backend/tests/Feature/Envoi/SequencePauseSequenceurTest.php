<?php

/**
 * T-SEQ (D6) — le séquenceur saute une séquence en pause : l'inscription n'est pas traitée et son compteur de reports ne bouge pas
 * (elle ne consomme donc pas REPORTS_MAX). Dernier geste = état. Fixtures FICTIVES, FauxServeurEnvoi seulement, aucun réseau.
 */

use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\ComposeurSequence;
use App\Envoi\Sequence\DemandeComposition;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ParametresExpedition;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

const PAUSE_DOMAINE = 'zz-pause.example.invalid';

beforeEach(function () {
    $this->ws = F::espace('zz-t-seq');
    $this->maintenant = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    (new DepotSocleDb)->eteindreTemoins($this->ws, 'test-pause');
    WorkspaceContext::set($this->ws);
    $this->faux = new FauxServeurEnvoi;
    $this->sequenceur = fn (): Sequenceur => new Sequenceur(
        new PasserelleLocale($this->faux, new DepotSocleDb, true, 300, fn () => $this->maintenant, $this->ws, 300, fn () => WorkspaceContext::current()),
        new LecteurFicheDb,
        new class implements ComposeurSequence
        {
            public function composer(DemandeComposition $d): string
            {
                return "Message-ID: {$d->messageId}\r\nSubject: {$d->sujet}\r\n\r\n{$d->corps}";
            }
        },
        new ParametresExpedition(PAUSE_DOMAINE, 'from@' . PAUSE_DOMAINE, 'ZZ Expediteur SAS'),
    );
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
        // transaction avortée : rien d'autre à nettoyer
    }
});

/** Une séquence d'une étape et une inscription échue dont `reports` vaut 3 ; retourne [séquence, inscription]. */
function pauseSequenceEtInscription(object $t, int $contact): array
{
    $sid = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $t->ws, 'nom' => 'Séquence fictive']);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $t->ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    $ins = (string) Str::uuid();
    DB::table('envoi_inscriptions')->insert([
        'id' => $ins, 'workspace_id' => $t->ws, 'sequence_id' => $sid, 'contact_id' => $contact, 'entreprise_id' => 5000 + $contact,
        'empreinte_adresse' => str_repeat('b', 62) . sprintf('%02d', $contact), 'prochaine_echeance' => $t->maintenant->modify('-1 hour')->format('c'),
        'version_figee' => '{}', 'reports' => 3,
    ]);

    return [$sid, $ins];
}

function pauseGeste(object $t, string $sequence, string $geste, ?string $instant = null): void
{
    DB::table('envoi_sequences_gestes')->insert([
        'workspace_id' => $t->ws, 'sequence_id' => $sequence, 'geste' => $geste, 'auteur' => 'test',
        'cree_le' => DB::raw($instant === null ? 'clock_timestamp()' : "'{$instant}'::timestamptz"),
    ]);
}

test('une séquence en pause n\'est pas traitée et le compteur de reports de ses inscriptions ne bouge pas', function () {
    [$sid, $ins] = pauseSequenceEtInscription($this, 1);
    pauseGeste($this, $sid, 'pause');

    $avant = DB::table('envoi_inscriptions')->where('id', $ins)->first();
    expect(($this->sequenceur)()->executer($this->ws, $this->maintenant))->toBe([]);
    $ligne = DB::table('envoi_inscriptions')->where('id', $ins)->first();
    expect((int) $ligne->reports)->toBe(3)->and($ligne->statut)->toBe('active')->and($ligne->prochaine_echeance)->toBe($avant->prochaine_echeance);
    expect(DB::table('envoi_messages')->where('inscription_id', $ins)->count())->toBe(0);
});

test('le dernier geste fait l\'état : pause puis reprise, l\'inscription est de nouveau traitée', function () {
    [$sid, $ins] = pauseSequenceEtInscription($this, 2);
    pauseGeste($this, $sid, 'pause', '2026-11-16 08:00:00+01');
    pauseGeste($this, $sid, 'reprise', '2026-11-16 09:00:00+01');

    $bilan = ($this->sequenceur)()->executer($this->ws, $this->maintenant);
    expect(array_sum($bilan))->toBe(1);
});

test('une pause ne saute que SA séquence : l\'autre séquence de l\'espace est traitée', function () {
    [$pausee, $insPausee] = pauseSequenceEtInscription($this, 3);
    [, $libre] = pauseSequenceEtInscription($this, 4);
    pauseGeste($this, $pausee, 'pause');

    $bilan = ($this->sequenceur)()->executer($this->ws, $this->maintenant);
    expect(array_sum($bilan))->toBe(1)
        ->and(DB::table('envoi_inscriptions')->where('id', $libre)->value('statut'))->not->toBe('active')   // traitée (ici arrêtée : fiche fictive absente)
        ->and(DB::table('envoi_inscriptions')->where('id', $insPausee)->first())->toMatchObject(['statut' => 'active', 'reports' => 3]);
});

<?php

/**
 * VERDICTS-CODE-2 — rafraîchissement planifié (T2.1 à T2.8, T2.10). Aucun réseau : DNS simulé, `Http::preventStrayRequests()`.
 * Fixtures FICTIVES (`*.example.test`).
 */

use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\Dns\ResolveurDnsInterdit;
use App\Crm\Emails\Dns\ResultatDns;
use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise;
use App\Envoi\Verdicts\EcrivainVerdicts;
use App\Envoi\Verdicts\RafraichissementVerdicts;
use App\Envoi\Verdicts\VerificateurAdresse;
use App\Support\WorkspaceContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

require_once __DIR__ . '/VerdictsOutils.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->maintenant = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('UTC'));
    $this->dns = new ResolveurDnsSimule;
    app()->instance(ResolveurDns::class, $this->dns);
});

afterEach(fn () => WorkspaceContext::clear());

/** Un espace avec le produit Envoi (ou non) et une inscription active, échue dans $jours jours ; rend [ws, contact]. */
function vdrInscription(string $email, float|int $jours = 1, bool $produit = true): array
{
    $ws = F::espace('zz-vdr');
    if ($produit) {
        DB::table('workspaces')->where('id', $ws)->update(['settings' => json_encode(['produits' => ['envoi' => 'actif']])]);
    }
    $societe = F::fiche($ws, 'ZZ Societe ' . random_int(1, 999999));
    $contact = F::contact($ws, $societe, 'Zoe', 'Martin' . random_int(1, 999999), ['email' => $email]);
    $sequence = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sequence, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sequence, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'S', 'corps' => 'C']);
    DB::table('envoi_inscriptions')->insert([
        'workspace_id' => $ws, 'sequence_id' => $sequence, 'contact_id' => $contact, 'entreprise_id' => $societe,
        'empreinte_adresse' => vdcEmpreinte($email), 'version_figee' => '{}',
        'prochaine_echeance' => (new DateTimeImmutable('2026-11-16 10:00:00'))->modify(($jours >= 0 ? '+' : '') . (int) ($jours * 24) . ' hours')->format('c'),
    ]);

    return [$ws, $contact];
}

function vdrVerdict(string $ws, string $email, string $verdict, DateTimeImmutable $le): void
{
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $ws, 'empreinte_adresse' => vdcEmpreinte($email), 'verdict' => $verdict, 'verifie_le' => $le->format('c'),
        'source' => 'interne-v1', 'motif' => 'mx',
    ]);
}

function vdrPassage(object $t, ?string $espace = null, bool $essai = false): array
{
    return app(RafraichissementVerdicts::class)->passage($t->maintenant, $espace, $essai);
}

test('T2.1 sans inscription active : aucune requête DNS, aucune ligne (même sous le résolveur interdit)', function () {
    $ws = F::espace('zz-vdr');
    DB::table('workspaces')->where('id', $ws)->update(['settings' => json_encode(['produits' => ['envoi' => 'actif']])]);
    app()->bind(ResolveurDns::class, ResolveurDnsInterdit::class);
    $b = vdrPassage($this, $ws);
    expect($b['adresses'])->toBe(0)->and($b['ecrits'])->toBe(0)->and($b['erreurs'])->toBe(0)
        ->and($this->dns->demandes)->toBe([])->and(vdcLignes($ws))->toBe([]);
});

test('T2.2 échéance dans 2 jours sans verdict : verdict écrit ; dans 4 jours : rien', function () {
    [$proche] = vdrInscription('zoe.proche@societe-a.example.test', 2);
    [$loin] = vdrInscription('zoe.loin@societe-b.example.test', 4);
    $b = vdrPassage($this);
    expect($b['ecrits'])->toBe(1)->and(count(vdcLignes($proche)))->toBe(1)->and(vdcLignes($loin))->toBe([])
        ->and(vdcLignes($proche)[0]->verdict)->toBe('valide')->and(vdcLignes($proche)[0]->source)->toBe('interne-v1');
    expect($this->dns->demandes)->toBe(['societe-a.example.test']);
});

test('T2.3 verdict valide de 21 jours : nouveau verdict ; de 5 jours : rien', function () {
    [$vieux] = vdrInscription('zoe.vieux@societe-a.example.test');
    [$recent] = vdrInscription('zoe.recent@societe-b.example.test');
    vdrVerdict($vieux, 'zoe.vieux@societe-a.example.test', 'valide', $this->maintenant->modify('-21 days'));
    vdrVerdict($recent, 'zoe.recent@societe-b.example.test', 'valide', $this->maintenant->modify('-5 days'));
    vdrPassage($this);
    expect(count(vdcLignes($vieux)))->toBe(2)->and(count(vdcLignes($recent)))->toBe(1);
});

test('T2.4 espace sans produit Envoi ou en corbeille : ignoré', function () {
    [$sans] = vdrInscription('zoe.sans@societe-a.example.test', 1, false);
    [$corbeille] = vdrInscription('zoe.corbeille@societe-b.example.test');
    DB::table('workspaces')->where('id', $corbeille)->update(['deleted_at' => now()]);
    $b = vdrPassage($this);
    expect($b['ecrits'])->toBe(0)->and(vdcLignes($sans))->toBe([])->and(vdcLignes($corbeille))->toBe([])->and($this->dns->demandes)->toBe([]);
});

test('T2.5 une erreur non DNS dans un espace : les autres sont traités, code de sortie 1, journal sans adresse', function () {
    Log::spy();
    [$a] = vdrInscription('zoe.erreur@societe-a.example.test');
    [$b] = vdrInscription('zoe.bonne@societe-b.example.test');
    $dns = new class implements ResolveurDns
    {
        public function resoudre(array $domaines): array
        {
            if (in_array('societe-a.example.test', $domaines, true)) {
                throw new LogicException('panne zoe.erreur@societe-a.example.test');
            }

            return array_fill_keys($domaines, new ResultatDns(ResultatDns::MX));
        }

        public function nom(): string
        {
            return 'faux';
        }
    };
    app()->instance(ResolveurDns::class, $dns);
    $code = Artisan::call('envoi:verdicts:rafraichir');
    expect($code)->toBe(1)->and(vdcLignes($a))->toBe([])->and(count(vdcLignes($b)))->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(fn ($m, $ctx = []) => ! str_contains($m . json_encode($ctx), 'zoe') && ($ctx['exception'] ?? null) === LogicException::class)->once();
});

test('T2.6 borne de temps : aucun nouvel espace au-delà, hors_borne compté', function () {
    vdrInscription('zoe.un@societe-a.example.test');
    vdrInscription('zoe.deux@societe-b.example.test');
    $t = 0;
    $horloge = function () use (&$t): DateTimeImmutable {
        return (new DateTimeImmutable('2026-11-16 10:00:00'))->modify('+' . ($t += 100) . ' seconds');
    };
    $r = new RafraichissementVerdicts(app(VerificateurAdresse::class), new EcrivainVerdicts, $horloge);
    $b = $r->passage($this->maintenant);
    expect($b['hors_borne'])->toBeGreaterThanOrEqual(1);
});

test('T2.7 --essai : calcule, n’écrit ni verdict ni cache de domaine', function () {
    [$ws] = vdrInscription('zoe.essai@societe-a.example.test');
    $avant = DB::table('email_domaines')->count();
    $b = vdrPassage($this, $ws, true);
    expect($b['ecrits'])->toBe(1)->and(vdcLignes($ws))->toBe([])->and(DB::table('email_domaines')->count())->toBe($avant);
});

test('T2.8 garde de schéma : sans la colonne motif, la tâche planifiée n’est pas due et la commande n’écrit rien', function () {
    $evenement = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'envoi:verdicts:rafraichir'));
    expect($evenement)->not->toBeNull()->and($evenement->expression)->toBe('17 * * * *');
    expect($evenement->filtersPass(app()))->toBeTrue();
    Schema::shouldReceive('hasTable')->andReturn(true);
    Schema::shouldReceive('hasColumn')->andReturn(false);
    expect($evenement->filtersPass(app()))->toBeFalse();
    expect(Artisan::call('envoi:verdicts:rafraichir'))->toBe(0);
});

test('T2.10 de bout en bout : un verdict écrit pour une adresse à casse mélangée lève le refus « verdict absent »', function () {
    $email = 'Zoe.Martin@Societe-A.example.test';
    [$ws] = vdrInscription($email);
    $demande = fn () => new DemandeEnvoi(
        $ws,
        $email,
        [['verification' => 'valide']],
        'FR',
        null,
        'zz-envoi.example.test',
        'ZZ Expediteur SAS',
        $this->maintenant->modify('-5 days'),
        null,
        'federations',
        true,
    );
    WorkspaceContext::set($ws);
    expect(EnvoiAutorise::verifier($demande(), $this->maintenant)->refus)->toBe(EnvoiAutorise::VERDICT_ABSENT);
    WorkspaceContext::clear();
    vdrPassage($this, $ws);
    WorkspaceContext::set($ws);
    expect(EnvoiAutorise::verifier($demande(), $this->maintenant)->refus)->not->toBe(EnvoiAutorise::VERDICT_ABSENT);
});

<?php

/**
 * Envoi P0 L1 (B1) — l'opposition par lien de désinscription arrête la personne ET ses collègues de la même
 * entreprise, dans CET espace seulement. De bout en bout : jeton -> POST -> événement -> implémentation L1.
 * Fixtures FICTIVES (dépôt public).
 */

use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Sequence\Evenement;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const ARRET_ADRESSE = 'clic@zz-arret.example.invalid';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
    config(['envoi.cle_desinscription' => 'cle-de-test-seulement-0123456789', 'envoi.desinscription_validite_jours' => 30]);
});

afterEach(fn () => Carbon::setTestNow());

/** Une inscription active (ou autre statut) dans l'espace $ws ; retourne son id. */
function arretInscription(string $ws, int $entreprise, string $email, string $statut = 'active', ?string $motif = null): string
{
    return WorkspaceContext::run($ws, function () use ($ws, $entreprise, $email, $statut, $motif): string {
        $sequence = (string) DB::table('envoi_sequences')->where('workspace_id', $ws)->value('id');
        if ($sequence === '') {
            $sequence = (string) Str::uuid();
            DB::table('envoi_sequences')->insert(['id' => $sequence, 'workspace_id' => $ws, 'nom' => 'ZZ arret']);
            DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sequence, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'S', 'corps' => 'C']);
        }
        $contact = F::contact($ws, $entreprise, 'Zz', 'Arret-' . Str::random(6), ['email' => $email]);
        $id = (string) Str::uuid();
        DB::table('envoi_inscriptions')->insert([
            'id' => $id, 'workspace_id' => $ws, 'sequence_id' => $sequence, 'contact_id' => $contact, 'entreprise_id' => $entreprise,
            'empreinte_adresse' => ListeSuppression::empreinte($email), 'statut' => $statut, 'motif_arret' => $motif,
            'prochaine_echeance' => now()->addDay(), 'version_figee' => '{}',
        ]);

        return $id;
    });
}

function arretEtat(string $ws, string $id): array
{
    $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions')->where('id', $id)->first());

    return [$l->statut, $l->motif_arret];
}

function arretCliquer(string $ws, string $adresse): void
{
    $ref = InscriptionsEnvoi::creer($ws, $adresse);
    test()->post('/desinscription/' . JetonDesinscription::emettre($ws, $ref))->assertOk();
}

test('B1 de bout en bout : le clic arrête la personne et ses collègues de la même entreprise, aucun autre espace', function () {
    $a = F::espace('zz-arret-a');
    $b = F::espace('zz-arret-b');
    $entA = F::fiche($a, 'ZZ Entreprise A');
    $autreEntA = F::fiche($a, 'ZZ Autre entreprise A');
    $entB = F::fiche($b, 'ZZ Entreprise B');

    $personne = arretInscription($a, $entA, ARRET_ADRESSE);
    $collegue = arretInscription($a, $entA, 'collegue@zz-arret.example.invalid');
    $etranger = arretInscription($a, $autreEntA, 'autre@zz-arret.example.invalid');
    $memeAdresseAilleurs = arretInscription($b, $entB, ARRET_ADRESSE);
    $collegueAilleurs = arretInscription($b, $entB, 'collegue-b@zz-arret.example.invalid');

    arretCliquer($a, ARRET_ADRESSE);

    expect(arretEtat($a, $personne))->toBe(['arretee', 'opposition']);
    expect(arretEtat($a, $collegue))->toBe(['arretee', 'opposition']);
    expect(arretEtat($a, $etranger))->toBe(['active', null]);
    expect(arretEtat($b, $memeAdresseAilleurs))->toBe(['active', null]);
    expect(arretEtat($b, $collegueAilleurs))->toBe(['active', null]);
    // La trace d'événement existe pour la personne (elle servira aux inscriptions FUTURES de l'entreprise).
    expect(WorkspaceContext::run($a, fn () => DB::table('envoi_inscriptions_evenements')->where('evenement', Evenement::OPPOSITION)->where('inscription_id', $personne)->count()))->toBe(1);
    expect(WorkspaceContext::run($b, fn () => DB::table('envoi_inscriptions_evenements')->count()))->toBe(0);
});

test('B1 : une inscription déjà arrêtée par la porte (garde:opposition) propage quand même à ses collègues', function () {
    $ws = F::espace('zz-arret-garde');
    $ent = F::fiche($ws, 'ZZ Entreprise');
    arretInscription($ws, $ent, ARRET_ADRESSE, 'arretee', Evenement::MOTIF_GARDE_OPPOSITION);
    $collegue = arretInscription($ws, $ent, 'collegue@zz-arret.example.invalid');

    arretCliquer($ws, ARRET_ADRESSE);

    expect(arretEtat($ws, $collegue))->toBe(['arretee', 'opposition']);
});

test('B1 : idempotent — un second déclenchement ne change rien et ne casse rien', function () {
    $ws = F::espace('zz-arret-idem');
    $ent = F::fiche($ws, 'ZZ Entreprise');
    arretInscription($ws, $ent, ARRET_ADRESSE);
    $collegue = arretInscription($ws, $ent, 'collegue@zz-arret.example.invalid');

    $arret = app(ArretParEntreprise::class);
    $arret->declencher($ws, ListeSuppression::empreinte(ARRET_ADRESSE));
    $arret->declencher($ws, ListeSuppression::empreinte(ARRET_ADRESSE));

    expect(arretEtat($ws, $collegue))->toBe(['arretee', 'opposition']);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions_evenements')->count()))->toBe(1);
});

test('B1 : la liaison par défaut est l\'implémentation L1, plus l\'« absente »', function () {
    expect(app(ArretParEntreprise::class))->not->toBeInstanceOf(ArretParEntrepriseAbsent::class);
});

test('M2 : envoi réel tenu pour inactif tant que l\'arrêt par entreprise est la liaison « absente »', function () {
    $ws = F::espace('zz-arret-m2');
    app()->instance(RetraitEnvoisEnFile::class, new class implements RetraitEnvoisEnFile
    {
        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            return 0;
        }
    });
    app()->instance(ArretParEntreprise::class, new ArretParEntrepriseAbsent);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire);
    config(['envoi.reel_actif' => true]);
    $m = new MessageAEnvoyer($ws, 'a@example.test', 'f@example.test', 'S', 'C', 'k');

    expect(fn () => WorkspaceContext::run($ws, fn () => app(ServeurEnvoi::class)->injecter($m)))->toThrow(EnvoiRefuse::class, 'Envoi réel inactif');
});

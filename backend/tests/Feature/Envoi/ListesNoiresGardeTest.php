<?php

/**
 * Envoi K13 — de bout en bout : un domaine ou une IP d'envoi listé (ou « non fiable » trop
 * longtemps) est REFUSÉ par la porte unique `EnvoiAutorise`. Aucun réseau : faux résolveur.
 * Fixtures FICTIVES (dépôt public) : `.example.invalid`, 203.0.113.x (documentation).
 */

use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise as P;
use App\Envoi\ListesNoires\Alerteur;
use App\Envoi\ListesNoires\DepotControlesDb;
use App\Envoi\ListesNoires\ResolveurDns;
use App\Envoi\ListesNoires\Ressource;
use App\Envoi\ListesNoires\StatutListe;
use App\Envoi\ListesNoires\SurveillanceListes;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const K13_ADRESSE = 'contact@zz-client.example.invalid';
const K13_DOMAINE = 'zz-envoi.example.invalid';
const K13_IP = '203.0.113.5';

beforeEach(function () {
    $this->ws = F::espace('zz-k13');
    $this->now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $this->ws, 'empreinte_adresse' => ListeSuppression::empreinte(K13_ADRESSE),
        'verdict' => 'valide', 'verifie_le' => $this->now->modify('-5 days')->format('c'), 'source' => 'test',
    ]);
    $dns = $this->now->format('c');
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $this->ws, 'nom' => K13_DOMAINE, 'achete_le' => $this->now->modify('-30 days')->format('Y-m-d'),
        'dmarc_verifie_le' => $dns, 'spf_verifie_le' => $dns, 'dkim_verifie_le' => $dns,
    ]);
    FonctionsEspace::regler($this->ws, 'federations', true);
    config(['envoi.listes_noires.ips' => [K13_IP . '@' . $this->ws]]);
});

function k13Demande(object $t): DemandeEnvoi
{
    return new DemandeEnvoi(
        $t->ws,
        K13_ADRESSE,
        [['verification' => 'valide']],
        'FR',
        null,
        K13_DOMAINE,
        'ZZ Expediteur SAS',
        $t->now->modify('-5 days'),
        null,
        'federations',
        true,
    );
}

/** Faux résolveur local, répondant à la sonde de contrôle, avec les réponses données. */
function k13Resolveur(array $reponses): ResolveurDns
{
    return new class($reponses) implements ResolveurDns
    {
        public function __construct(private array $r) {}

        public function adresses(): array
        {
            return ['127.0.0.1'];
        }

        public function interroger(string $nom): array
        {
            return $nom === SurveillanceListes::NOM_SONDE ? ['127.0.0.2'] : ($this->r[$nom] ?? []);
        }
    };
}

function k13Surveillance(ResolveurDns $res): SurveillanceListes
{
    $muet = new class implements Alerteur
    {
        public function alerter(Ressource $r, string $zone, string $reponse): void {}

        public function alerterNonFiable(Ressource $r, string $raison): void {}
    };

    return new SurveillanceListes($res, new DepotControlesDb, $muet, ['zen.spamhaus.org'], ['dbl.spamhaus.org'], fn () => new DateTimeImmutable);
}

test('M1 : domaine d\'envoi listé (K13) => EnvoiAutorise refuse DOMAINE_LISTE_NOIRE, sans toucher envoi_domaines.liste_noire', function () {
    expect(P::verifier(k13Demande($this), $this->now)->refus)->toBeNull();

    $s = k13Surveillance(k13Resolveur(['zz-envoi.example.invalid.dbl.spamhaus.org' => ['127.0.1.2']]));
    expect($s->controlerUne(new Ressource($this->ws, Ressource::DOMAINE, K13_DOMAINE)))->toBe(StatutListe::Liste);

    expect(DB::table('envoi_domaines')->where('nom', K13_DOMAINE)->value('liste_noire'))->toBeFalsy()
        ->and(P::verifier(k13Demande($this), $this->now)->refus)->toBe(P::DOMAINE_LISTE_NOIRE);
});

test('M1 : IP d\'envoi de l\'espace listée => refus IP_LISTE_NOIRE ; l\'IP d\'un autre espace ne compte pas', function () {
    $s = k13Surveillance(k13Resolveur(['5.113.0.203.zen.spamhaus.org' => ['127.0.0.4']]));
    $s->controlerUne(new Ressource($this->ws, Ressource::IP, K13_IP));
    expect(P::verifier(k13Demande($this), $this->now)->refus)->toBe(P::IP_LISTE_NOIRE);

    config(['envoi.listes_noires.ips' => [K13_IP . '@' . F::espace('zz-k13-autre')]]);
    expect(P::verifier(k13Demande($this), $this->now)->refus)->toBeNull();
});

test('M3 : « non fiable » depuis plus de 30 min => traité comme en pause (fail-closed) ; avant, non', function () {
    $depot = new DepotControlesDb;
    $r = new Ressource($this->ws, Ressource::DOMAINE, K13_DOMAINE);

    $depot->enregistrer($r, StatutListe::NonFiable, '-', 'sonde_ko', false, new DateTimeImmutable('-10 minutes'));
    expect($depot->enPause($r))->toBeFalse()
        ->and(P::verifier(k13Demande($this), $this->now)->refus)->toBeNull();

    $autre = new Ressource($this->ws, Ressource::DOMAINE, 'zz-autre.example.invalid');
    $depot->enregistrer($autre, StatutListe::NonListe, '*', null, false, new DateTimeImmutable('-50 minutes'));
    $depot->enregistrer($autre, StatutListe::NonFiable, '-', 'sonde_ko', false, new DateTimeImmutable('-40 minutes'));
    $depot->enregistrer($autre, StatutListe::NonFiable, '-', 'sonde_ko', false, new DateTimeImmutable('-1 minutes'));
    expect($depot->enPause($autre))->toBeTrue();

    // la durée se compte depuis le PREMIER « non fiable » de la série : un « non listé » la remet à zéro
    $depot->enregistrer($autre, StatutListe::NonListe, '*', null, false, new DateTimeImmutable('-30 seconds'));
    expect($depot->enPause($autre))->toBeFalse();

    $r2 = new Ressource($this->ws, Ressource::DOMAINE, 'zz-long.example.invalid');
    $depot->enregistrer($r2, StatutListe::NonFiable, '-', 'resolveur_public', false, new DateTimeImmutable('-31 minutes'));
    $depot->enregistrer($r2, StatutListe::NonFiable, '-', 'resolveur_public', false, new DateTimeImmutable);
    expect($depot->enPause($r2))->toBeTrue();
});

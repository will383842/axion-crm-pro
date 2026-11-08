<?php

use App\Envoi\Sante\Constat;
use App\Envoi\Sante\EmetteurAlerteSocle;
use App\Socle\Alertes\Alerte;
use App\Socle\Alertes\AlerteMail;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceAlerte(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'T', 'slug' => 'k15a-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

test('S2 : le VRAI regroupement du Socle n’absorbe pas l’alerte de l’espace B (l’espace est dans le titre) ; le même espace reste regroupé', function () {
    Mail::fake();
    config(['alertes.actif' => true, 'alertes.email.destinataire' => 'resp@example.test', 'mail.from.address' => 'alertes@app.example.test']);
    $a = espaceAlerte();
    $b = espaceAlerte();
    $emetteur = new EmetteurAlerteSocle(app(Alerte::class)); // vrai Alerte, vrai Regroupement (cache)
    $c = new Constat('disque', Constat::CRITIQUE, 'disque libre : 5 %');

    expect($emetteur->alerter($a, 'src', $c))->toBeTrue()
        ->and($emetteur->alerter($b, 'src', $c))->toBeTrue()  // autre espace, même code, même source, même gravité
        ->and($emetteur->alerter($a, 'src', $c))->toBeFalse(); // même espace : absorbée dans la fenêtre
    Mail::assertSent(AlerteMail::class, 2);
});

test('le palier 2 (certificat à 14 jours) a son propre titre : jamais absorbé par l’alerte à 30 jours du même quart d’heure', function () {
    Mail::fake();
    config(['alertes.actif' => true, 'alertes.email.destinataire' => 'resp@example.test', 'mail.from.address' => 'alertes@app.example.test']);
    $ws = espaceAlerte();
    $emetteur = new EmetteurAlerteSocle(app(Alerte::class));
    expect($emetteur->alerter($ws, 'src', new Constat('certificat', Constat::ATTENTION, '30 j')))->toBeTrue()
        ->and($emetteur->alerter($ws, 'src', new Constat('certificat', Constat::ATTENTION, '14 j', 2)))->toBeTrue();
});

test('expéditeur de l’application : boîte, domaine, sous-domaine, même domaine organisationnel ou adresse illisible => REFUSÉ ; domaine étranger => accepté', function (string $expediteur, bool $refuse) {
    $ws = espaceAlerte();
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'news.prospect.example', 'achete_le' => '2026-01-01']);
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'envoi.exemple.co.uk', 'achete_le' => '2026-01-01']);
        DB::table('envoi_boites')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $ws, 'adresse' => 'Commercial@Autre.Example', 'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'x']);
    });
    expect(EmetteurAlerteSocle::expediteurEstDeProspection($expediteur))->toBe($refuse);
})->with([
    'domaine d’envoi exact' => ['alertes@news.prospect.example', true],
    'sous-domaine d’un domaine d’envoi' => ['alertes@a.b.news.prospect.example', true],
    'même domaine organisationnel (même DMARC)' => ['alertes@app.prospect.example', true],
    'domaine organisationnel nu' => ['alertes@prospect.example', true],
    'suffixe public à 3 libellés (co.uk)' => ['alertes@app.exemple.co.uk', true],
    'autre organisation en co.uk' => ['alertes@app.autre.co.uk', false],
    'boîte de prospection (casse ignorée)' => ['commercial@autre.example', true],
    'autre adresse du domaine d’une boîte' => ['alertes@autre.example', false],
    'expéditeur vide' => ['', true],
    'sans arobase' => ['alertes', true],
    'domaine vide' => ['alertes@', true],
    'domaine étranger' => ['alertes@app.example.test', false],
]);

test('domaine organisationnel approché', function (string $in, string $out) {
    expect(EmetteurAlerteSocle::domaineOrganisationnel($in))->toBe($out);
})->with([['a.b.example.com', 'example.com'], ['Example.COM.', 'example.com'], ['x.exemple.co.uk', 'exemple.co.uk'], ['localhost', 'localhost'], ['exemple.fr', 'exemple.fr']]);

test('une alerte dont l’expéditeur est de prospection est refusée et ne part par aucun canal', function () {
    Mail::fake();
    config(['alertes.actif' => true, 'alertes.email.destinataire' => 'resp@example.test', 'mail.from.address' => '']);
    expect((new EmetteurAlerteSocle(app(Alerte::class)))->alerter(espaceAlerte(), 'src', new Constat('disque', Constat::CRITIQUE, 'x')))->toBeFalse();
    Mail::assertNothingSent();
});

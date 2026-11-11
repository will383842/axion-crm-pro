<?php

/**
 * L8-F12 — relève IMAP planifiée. AUCUN réseau : lecteur en mémoire. Fixtures FICTIVES (`.example.invalid`).
 */

use App\Envoi\Imap\LecteurBoite;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RegistreMessagesEnvoyesMemoire;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\ReleveImapCommande;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->ws = F::espace('zz-releve-planifiee');
    config(['crm.ingest.business_workspace' => F::slug($this->ws)]);
    $this->boite = (string) Str::uuid();
    DB::table('envoi_boites')->insert([
        'id' => $this->boite, 'workspace_id' => $this->ws, 'adresse' => 'zz-planifiee@test.invalid',
        'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre',
    ]);
    $this->lecteur = new LecteurBoiteMemoire;
    $this->instancies = 0;
    $this->app->bind(ReleveImap::class, function () {
        $this->instancies++;

        return new ReleveImap($this->lecteur, new RegistreMessagesEnvoyesMemoire, new RecepteurRetoursCrm);
    });
    $this->app->bind(LecteurBoite::class, function () {
        $this->instancies++;

        return $this->lecteur;
    });
});

function planifiMessage(int $uid): MessageBrut
{
    return new MessageBrut(
        $uid,
        "p{$uid}@zz-client.example.invalid",
        'inconnu@zz.example.invalid',
        [],
        'contact@zz-client.example.invalid',
        new DateTimeImmutable('2026-12-02 10:00:00', new DateTimeZone('Europe/Paris')),
        'Re: bonjour',
        'Merci.',
        [],
        null,
        null,
        null,
    );
}

test('interrupteur éteint : aucun lecteur ni relève instancié, aucune connexion', function () {
    config(['envoi.reel_actif' => true, 'envoi.releve_actif' => false]);
    $this->lecteur->deposer($this->boite, planifiMessage(1));

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(0)
        ->and($this->instancies)->toBe(0)
        ->and(DB::table('envoi_curseurs_imap')->count())->toBe(0);
});

test('ENVOI_REEL_ACTIF éteint : la relève reste inerte même si releve_actif est vrai', function () {
    config(['envoi.reel_actif' => false, 'envoi.releve_actif' => true]);
    $this->lecteur->deposer($this->boite, planifiMessage(1));

    Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);

    expect($this->instancies)->toBe(0)->and(DB::table('envoi_curseurs_imap')->count())->toBe(0);
});

test('allumé : le curseur avance et un message n\'est traité qu\'une seule fois', function () {
    config(['envoi.reel_actif' => true, 'envoi.releve_actif' => true]);
    $this->lecteur->definirUidValidity($this->boite, 7);
    $this->lecteur->deposer($this->boite, planifiMessage(1));

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(0);
    expect((int) DB::table('envoi_curseurs_imap')->where('boite', $this->boite)->value('dernier_uid'))->toBe(1);
    $evenements = DB::table('envoi_evenements')->count();

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(0)
        ->and(DB::table('envoi_evenements')->count())->toBe($evenements)
        ->and($evenements)->toBe(1)
        ->and((int) DB::table('envoi_curseurs_imap')->where('boite', $this->boite)->value('dernier_uid'))->toBe(1);
});

test('la tâche est enregistrée chaque minute, sans chevauchement, sur un seul serveur, derrière les deux interrupteurs', function () {
    $evenement = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, ReleveImapCommande::SIGNATURE_PLANIFIEE));

    expect($evenement)->not->toBeNull()
        ->and($evenement->expression)->toBe('* * * * *')
        ->and($evenement->withoutOverlapping)->toBeTrue()
        ->and($evenement->onOneServer)->toBeTrue();

    foreach ([[false, false], [true, false], [false, true]] as [$reel, $releve]) {
        config(['envoi.reel_actif' => $reel, 'envoi.releve_actif' => $releve]);
        expect($evenement->filtersPass($this->app))->toBeFalse();
    }
    config(['envoi.reel_actif' => true, 'envoi.releve_actif' => true]);
    expect($evenement->filtersPass($this->app))->toBeTrue();
});

test('par défaut releve_actif est faux', function () {
    expect(config('envoi.releve_actif'))->toBeFalse();
});

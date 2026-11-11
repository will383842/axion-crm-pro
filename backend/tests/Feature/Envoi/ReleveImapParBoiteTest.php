<?php

/**
 * Dettes de la relève IMAP (suite de #488) : UN lecteur par boîte (fabrique), isolement d'une boîte en panne,
 * journal d'une panne une seule fois par changement d'état. AUCUN réseau : lecteurs en mémoire, données FICTIVES.
 */

use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\LecteurBoite;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RegistreMessagesEnvoyesMemoire;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\Imap\ReleveImapCommande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Fabrique de test : un lecteur DISTINCT par boîte, et la liste des boîtes demandées. */
final class FabriquePourTest implements FabriqueLecteurs
{
    /** @var array<string, LecteurBoiteMemoire> */
    public array $lecteurs = [];

    /** @var list<string> */
    public array $demandes = [];

    public function fabrique(string $boite): LecteurBoite
    {
        $this->demandes[] = $boite;

        return $this->lecteurs[$boite] ??= new LecteurBoiteMemoire;
    }
}

function messageParBoite(int $uid): MessageBrut
{
    return new MessageBrut(
        $uid,
        "m{$uid}@zz-client.example.invalid",
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

beforeEach(function () {
    Cache::flush();
    $this->ws = F::espace('zz-releve-par-boite');
    config(['crm.ingest.business_workspace' => F::slug($this->ws), 'envoi.reel_actif' => true, 'envoi.releve_actif' => true]);
    $this->boites = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($this->boites as $i => $id) {
        DB::table('envoi_boites')->insert([
            'id' => $id, 'workspace_id' => $this->ws, 'adresse' => "zz-boite{$i}@test.invalid",
            'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre',
        ]);
    }
    $this->fabrique = new FabriquePourTest;
    $this->app->bind(ReleveImap::class, fn () => new ReleveImap($this->fabrique, new RegistreMessagesEnvoyesMemoire, new RecepteurRetoursCrm));
});

function curseurDe(string $boite): ?int
{
    $v = DB::table('envoi_curseurs_imap')->where('boite', $boite)->value('dernier_uid');

    return $v === null ? null : (int) $v;
}

test('M1 : la relève demande un lecteur PAR boîte ; une boîte en panne n\'empêche pas l\'autre d\'avancer', function () {
    [$panne, $saine] = $this->boites;
    $this->fabrique->fabrique($panne)->mettreEnPanne($panne);
    $this->fabrique->fabrique($saine)->deposer($saine, messageParBoite(1));
    $this->fabrique->fabrique($saine)->deposer($saine, messageParBoite(2));
    $this->fabrique->demandes = [];

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(1)
        ->and(curseurDe($saine))->toBe(2)
        ->and(curseurDe($panne))->toBeNull()
        ->and(array_unique($this->fabrique->demandes))->toEqualCanonicalizing($this->boites)
        ->and($this->fabrique->lecteurs[$panne])->not->toBe($this->fabrique->lecteurs[$saine]);
});

test('M1 : une fois la panne levée, la boîte rattrape ses messages sans toucher au curseur de l\'autre', function () {
    [$panne, $saine] = $this->boites;
    $lecteurPanne = $this->fabrique->fabrique($panne);
    $lecteurPanne->deposer($panne, messageParBoite(1));
    $lecteurPanne->mettreEnPanne($panne);
    $this->fabrique->fabrique($saine)->deposer($saine, messageParBoite(1));

    Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);
    $lecteurPanne->retablir($panne);

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(0)
        ->and(curseurDe($panne))->toBe(1)
        ->and(curseurDe($saine))->toBe(1);
});

test('M1 : un lecteur ne sert qu\'une boîte ; la relève ne mélange jamais deux boîtes sur un même lecteur', function () {
    $releve = new ReleveImap($this->fabrique, new RegistreMessagesEnvoyesMemoire, new RecepteurRetoursCrm);
    foreach ($this->boites as $b) {
        $this->fabrique->fabrique($b)->deposer($b, messageParBoite(1));
        $releve->relever($this->ws, $b);
    }

    expect(array_keys($this->fabrique->lecteurs))->toEqualCanonicalizing($this->boites);
});

test('m2 : une boîte qui reste en panne n\'écrit Log::error qu\'une fois, pas à chaque minute', function () {
    [$panne] = $this->boites;
    $this->fabrique->fabrique($panne)->mettreEnPanne($panne);
    Log::spy();

    foreach (range(1, 5) as $_) {
        Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);
    }

    Log::shouldHaveReceived('error')->once();
});

test('m2 : chaque boîte en panne est journalisée une fois ; le rétablissement est noté, puis une nouvelle panne re-journalise', function () {
    [$a, $b] = $this->boites;
    $this->fabrique->fabrique($a)->mettreEnPanne($a);
    $this->fabrique->fabrique($b)->mettreEnPanne($b);
    Log::spy();

    Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);
    Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);
    Log::shouldHaveReceived('error')->twice(); // une fois par boîte

    $this->fabrique->lecteurs[$a]->retablir($a);
    Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);
    Log::shouldHaveReceived('info')->once(); // boîte A rétablie

    $this->fabrique->lecteurs[$a]->mettreEnPanne($a);
    Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);
    Log::shouldHaveReceived('error')->times(3); // A retombe : nouvelle entrée, B reste muette
});

test('m2 : une panne qui dure plus d\'une heure est re-journalisée (au plus une fois par heure et par boîte)', function () {
    [$panne] = $this->boites;
    $this->fabrique->fabrique($panne)->mettreEnPanne($panne);
    Log::spy();

    Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);
    $this->travel(61)->minutes();
    Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);

    Log::shouldHaveReceived('error')->twice();
});

test('m2 : le journal ne porte que la classe de l\'exception, jamais son message (il peut contenir un hôte)', function () {
    [$panne] = $this->boites;
    $this->fabrique->fabrique($panne)->mettreEnPanne($panne);
    Log::spy();

    Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE);

    Log::shouldHaveReceived('error')->withArgs(fn ($message, $contexte) => ($contexte['exception'] ?? null) === RuntimeException::class
        && ! str_contains(json_encode($contexte), 'imap.exemple'))->once();
});

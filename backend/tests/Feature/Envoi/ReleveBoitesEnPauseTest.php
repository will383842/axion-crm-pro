<?php

use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Imap\AccesImapAbsent;
use App\Envoi\Imap\DepotAccesImap;
use App\Envoi\Imap\FabriqueLecteurFixe;
use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\FabriqueLecteursBoites;
use App\Envoi\Imap\LecteurBoiteMemoire;
use App\Envoi\Imap\MessageBrut;
use App\Envoi\Imap\ReleveImapCommande;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

/*
 * Lot D1-L2 — la relève lit AUSSI les boîtes en pause ; une boîte sans accès IMAP est une panne visible, sans secret.
 * Lecteur simulé ou faux protocole : aucun réseau. Fixtures `example.test`.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->app->instance(ResolveurFournisseur::class, new ResolveurEnMemoire);
    $this->ws = F::espace('zz-releve-pause');
    config(['crm.ingest.business_workspace' => F::slug($this->ws), 'envoi.reel_actif' => true, 'envoi.releve_actif' => true]);
});

function rbpBoite(object $t, string $etat = 'active'): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_boites')->insert(['id' => $id, 'workspace_id' => $t->ws, 'adresse' => 'b-' . substr($id, 0, 6) . '@societe-a.example.test',
        'hote' => 'smtp.societe-a.example.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'chiffre-fictif', 'etat' => $etat]);

    return $id;
}

/** Un STOP d'un destinataire dont l'envoi est enregistré (rattachement par Message-ID). */
function rbpStop(object $t, string $boite, int $uid): MessageBrut
{
    $adresse = "zoe{$uid}@client.example.test";
    F::contact($t->ws, F::fiche($t->ws, 'ZZ ' . $uid), 'Zoe', 'Martin', ['email' => $adresse]);
    $injection = (string) Str::uuid();
    DB::table('envoi_injections_smtp')->insert(['workspace_id' => $t->ws, 'boite_id' => $boite, 'id_injection' => $injection, 'cle_idempotence' => 'zz-' . $injection]);
    $mid = "envoi-{$uid}@d.example.test";
    DB::table('envoi_messages_envoyes')->insert(['workspace_id' => $t->ws, 'message_id_empreinte' => hash('sha256', 'mid:' . $mid),
        'id_injection' => $injection, 'empreinte_destinataire' => ListeSuppression::empreinte($adresse)]);

    return new MessageBrut($uid, "retour-{$uid}@client.example.test", $mid, [], $adresse, new DateTimeImmutable('2026-12-02 10:00:00', new DateTimeZone('UTC')), 'Re: bonjour', 'STOP, ne me contactez plus.', []);
}

test('D1-L2 F5 une boîte en pause est relevée : STOP lu, événement écrit, curseur posé', function () {
    $boite = rbpBoite($this, 'en_pause');
    $lecteur = new LecteurBoiteMemoire;
    $lecteur->definirUidValidity($boite, 9);
    $lecteur->deposer($boite, rbpStop($this, $boite, 1));
    $this->app->instance(FabriqueLecteurs::class, new FabriqueLecteurFixe($lecteur));

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(0);
    expect(DB::table('envoi_evenements')->where('workspace_id', $this->ws)->where('statut', 'rattache')->count())->toBe(1)
        ->and(DB::table('envoi_curseurs_imap')->where('boite', $boite)->count())->toBe(1);
});

test('D1-L2 F3 sans accès IMAP posé : code 1, message fixe, aucun curseur ni événement', function () {
    rbpBoite($this);
    $this->app->bind(FabriqueLecteurs::class, fn () => new FabriqueLecteursBoites(new DepotAccesImap));

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(1)
        ->and(Artisan::output())->toContain(AccesImapAbsent::MESSAGE)
        ->and(DB::table('envoi_curseurs_imap')->count())->toBe(0)
        ->and(DB::table('envoi_evenements')->count())->toBe(0);
});

test('D1-L2 F6 ni hôte, ni identifiant, ni mot de passe dans les journaux ni la sortie, même si le protocole lève', function () {
    $boite = rbpBoite($this);
    (new DepotAccesImap)->poser($this->ws, $boite, 'imap.gmail.com', 'releve@societe-a.example.test', 'motdepasse-temoin-zz');
    $this->app->bind(FabriqueLecteurs::class, fn () => new FabriqueLecteursBoites(
        new DepotAccesImap,
        fn () => throw new RuntimeException('connexion imap.gmail.com refusée pour releve@societe-a.example.test mot de passe motdepasse-temoin-zz'),
    ));
    $journal = '';
    Log::listen(function ($e) use (&$journal) {
        $journal .= $e->message . json_encode($e->context);
    });

    expect(Artisan::call(ReleveImapCommande::SIGNATURE_PLANIFIEE))->toBe(1);
    $tout = Artisan::output() . $journal;
    foreach (['imap.gmail.com', 'releve@societe-a.example.test', 'motdepasse-temoin-zz'] as $secret) {
        expect($tout)->not->toContain($secret);
    }
    expect($journal)->not->toBe(''); // la panne est bien journalisée
});

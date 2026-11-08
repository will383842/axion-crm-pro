<?php

/**
 * Envoi P0 L5 — désinscription (test S22). Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\EffetsOpposition;
use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Desinscription\OppositionEnregistree;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\Desinscription\TerminerEffetsOpposition;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const DESINSCR_ADRESSE = 'personne@zz-desinscription.example.invalid';

// Clé de TEST (32 caractères au moins, longueur minimale contrôlée à l'émission) : fictive.
const DESINSCR_CLE = 'cle-de-test-seulement-0123456789';

beforeEach(function () {
    // Horloge figée en UTC : ni l'heure de lancement ni le fuseau de l'application ne comptent.
    Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
    config(['envoi.cle_desinscription' => DESINSCR_CLE, 'envoi.desinscription_validite_jours' => 30]);
    $this->ws = F::espace('zz-desinscr');
    $this->ref = InscriptionsEnvoi::creer($this->ws, DESINSCR_ADRESSE);
    $this->jeton = JetonDesinscription::emettre($this->ws, $this->ref);
    $this->chemin = '/desinscription/' . $this->jeton;
});

afterEach(fn () => Carbon::setTestNow());

function desinscrOppositions(string $ws): int
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions')->count());
}

function desinscrOccurrences(): int
{
    return (int) DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte(DESINSCR_ADRESSE))->sum('occurrences');
}

test('S22 GET (avec ou sans suivi de redirections) : un seul bouton, aucune opposition', function () {
    $r = $this->get($this->chemin);
    $r->assertOk();
    expect($r->isRedirection())->toBeFalse();
    expect(substr_count($r->getContent(), '<button'))->toBe(1);
    expect($r->getContent())->toContain('Confirmer la désinscription');

    // « Suivi de redirections » : un robot qui rejoue la page ou suit un éventuel Location.
    $this->followingRedirects()->get($this->chemin)->assertOk();
    $this->head($this->chemin);

    expect(desinscrOppositions($this->ws))->toBe(0);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeFalse();
    expect(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('S22 POST du bouton : une opposition, adresse en liste de suppression, événement émis', function () {
    Event::fake([OppositionEnregistree::class]);
    $this->post($this->chemin)->assertOk()->assertSee('Désinscription confirmée', false);

    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();
    expect(DB::table('crm_outbound_events')->where('email_hash', ConsentOutboundRecorder::hashEmail(DESINSCR_ADRESSE))->where('event_type', 'consent_optout')->count())->toBe(1);
    Event::assertDispatchedTimes(OppositionEnregistree::class, 1);
});

test('S22 POST one-click RFC 8058 : opposition immédiate, sans page', function () {
    $r = $this->post($this->chemin, ['List-Unsubscribe' => 'One-Click']);
    $r->assertOk();
    expect(trim($r->getContent()))->toBe('');
    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();
});

test('S22 rejeu du même jeton : une seule opposition, un seul comptage, un seul événement', function () {
    $this->post($this->chemin)->assertOk();
    $this->post($this->chemin)->assertOk();
    $this->post($this->chemin, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(desinscrOccurrences())->toBe(1);
    expect(DB::table('crm_outbound_events')->count())->toBe(1);
});

test('jetons invalides, falsifiés ou expirés : même refus, sans fuite, sans écriture', function () {
    $autre = F::espace('zz-desinscr-b');
    $inconnu = JetonDesinscription::emettre($this->ws, '00000000-0000-4000-8000-000000000000');
    // Même signature, autre espace : la signature couvre l'espace.
    $croise = str_replace($this->ws, $autre, $this->jeton);
    $mauvaiseCle = (function () {
        config(['envoi.cle_desinscription' => 'une-autre-cle-de-test-0123456789ab']);
        $j = JetonDesinscription::emettre($this->ws, $this->ref);
        config(['envoi.cle_desinscription' => DESINSCR_CLE]);

        return $j;
    })();

    $corps = [];
    foreach (['n-importe-quoi', substr($this->jeton, 0, -3), $inconnu, $croise, $mauvaiseCle, str_repeat('a', 5000)] as $j) {
        foreach (['get', 'post'] as $m) {
            $r = $this->{$m}('/desinscription/' . $j);
            $r->assertNotFound();
            expect($r->getContent())->not->toContain('zz-desinscription')->not->toContain($this->ref);
            $corps[] = $r->getContent();
        }
    }
    expect(array_unique($corps))->toHaveCount(1); // le même refus, quelle que soit la cause
    expect(desinscrOppositions($this->ws))->toBe(0);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeFalse();
});

test('clé absente : la porte reste fermée, rien n’est émis ni accepté', function () {
    config(['envoi.cle_desinscription' => '']);
    expect(fn () => JetonDesinscription::emettre($this->ws, $this->ref))->toThrow(RuntimeException::class);
    $this->post($this->chemin)->assertNotFound();
    expect(desinscrOppositions($this->ws))->toBe(0);
});

test('aucune donnée de personne dans le lien', function () {
    expect($this->jeton)->not->toContain('zz-desinscription')->not->toContain('@')
        ->not->toContain(ListeSuppression::empreinte(DESINSCR_ADRESSE))
        ->not->toContain(base64_encode(DESINSCR_ADRESSE));
    config(['envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => 'https://app.zz-desinscription.example.invalid']]);
    expect(JetonDesinscription::url($this->ws, $this->ref))->toContain('/desinscription/');
});

test('page publique : française, sans image, sans script, sans ressource distante, sans suivi', function () {
    $r = $this->get($this->chemin);
    $html = $r->getContent();
    expect($html)->toContain('<html lang="fr"')->toContain('<title>')
        ->not->toContain('<img')->not->toContain('<script')->not->toContain('<iframe')
        ->not->toContain('<link')->not->toContain('http://')->not->toContain('https://')
        ->not->toContain('@import')->not->toContain('url(');
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
    expect($r->headers->get('Referrer-Policy'))->toBe('no-referrer');
    expect($r->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    expect($r->headers->get('X-Robots-Tag'))->toContain('noindex');
    expect($r->headers->get('Set-Cookie'))->toBeNull();
    expect($html)->not->toContain(DESINSCR_ADRESSE);
});

test('effets : retrait des envois en file et arrêt par entreprise déclenchés une seule fois', function () {
    $retrait = new class implements RetraitEnvoisEnFile
    {
        /** @var list<array{string,string}> */
        public array $appels = [];

        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            $this->appels[] = [$workspaceId, $empreinteAdresse];

            return 2;
        }
    };
    $arret = new class implements ArretParEntreprise
    {
        /** @var list<array{string,string}> */
        public array $appels = [];

        public function declencher(string $workspaceId, string $empreinteAdresse): void
        {
            $this->appels[] = [$workspaceId, $empreinteAdresse];
        }
    };
    app()->instance(RetraitEnvoisEnFile::class, $retrait);
    app()->instance(ArretParEntreprise::class, $arret);

    $this->post($this->chemin)->assertOk();
    $this->post($this->chemin)->assertOk();

    $attendu = [[$this->ws, ListeSuppression::empreinte(DESINSCR_ADRESSE)]];
    expect($retrait->appels)->toBe($attendu);
    expect($arret->appels)->toBe($attendu);
});

test('effets interrompus : le rejeu du jeton les termine, sans doublon d’opposition', function () {
    $retrait = new class implements RetraitEnvoisEnFile
    {
        public int $n = 0;

        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            $this->n++;
            if ($this->n === 1) {
                throw new RuntimeException('panne simulée');
            }

            return 0;
        }
    };
    app()->instance(RetraitEnvoisEnFile::class, $retrait);

    $this->post($this->chemin)->assertOk(); // l'opposition est acquise malgré la panne
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();
    $this->post($this->chemin)->assertOk();

    expect($retrait->n)->toBe(2);
    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(desinscrOccurrences())->toBe(1);
});

test('l’opposition n’efface rien : l’inscription et ses lignes restent', function () {
    $this->post($this->chemin)->assertOk();
    $n = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_liens_desinscription')->where('reference', $this->ref)->count());
    expect($n)->toBe(1);
});

test('RLS FORCE sur les deux tables', function () {
    foreach (['envoi_liens_desinscription', 'envoi_oppositions'] as $t) {
        $r = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$t]);
        expect($r->relrowsecurity)->toBeTrue();
        expect($r->relforcerowsecurity)->toBeTrue();
    }
});

// ── Correctifs de l'audit sécurité/RGPD (B1, B2, mineurs) ───────────────────────────────────

test('B2 l’adresse en clair n’est écrite nulle part : empreinte seule, liste de suppression sans adresse', function () {
    $ligne = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_liens_desinscription')->where('reference', $this->ref)->first());
    expect(property_exists($ligne, 'adresse'))->toBeFalse(); // la colonne n'existe plus
    expect($ligne->empreinte_adresse)->toBe(ListeSuppression::empreinte(DESINSCR_ADRESSE));

    $this->post($this->chemin)->assertOk();

    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();
    $liste = DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte(DESINSCR_ADRESSE))->first();
    expect($liste->email)->toBeNull();
    $sortants = json_encode(DB::table('crm_outbound_events')->get()->all());
    expect($sortants)->not->toContain(DESINSCR_ADRESSE);
});

test('B2 la référence au contact est conservée à la place de l’adresse', function () {
    $ref = InscriptionsEnvoi::creer($this->ws, 'autre@zz-desinscription.example.invalid', 'business', 4242);
    $ligne = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_liens_desinscription')->where('reference', $ref)->first());
    expect((int) $ligne->contact_id)->toBe(4242);
    expect(property_exists($ligne, 'adresse'))->toBeFalse();
});

test('B1 EffetsOpposition tente les DEUX effets même si le premier lève une erreur', function () {
    $arret = new class implements ArretParEntreprise
    {
        public int $n = 0;

        public function declencher(string $workspaceId, string $empreinteAdresse): void
        {
            $this->n++;
        }
    };
    $retrait = new class implements RetraitEnvoisEnFile
    {
        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            throw new RuntimeException('panne du retrait');
        }
    };
    $effets = new EffetsOpposition($retrait, $arret);

    expect(fn () => $effets->handle(new OppositionEnregistree($this->ws, str_repeat('a', 64), 'business', 'page')))
        ->toThrow(RuntimeException::class, 'panne du retrait');
    expect($arret->n)->toBe(1);
});

test('B1 effets en échec : la commande planifiée les relance SANS nouveau clic, une seule fois', function () {
    $retrait = new class implements RetraitEnvoisEnFile
    {
        public int $n = 0;

        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            $this->n++;
            if ($this->n === 1) {
                throw new RuntimeException('panne simulée');
            }

            return 0;
        }
    };
    app()->instance(RetraitEnvoisEnFile::class, $retrait);

    $this->post($this->chemin)->assertOk();
    $nonTermines = fn () => WorkspaceContext::run($this->ws, fn () => DB::table('envoi_oppositions')->whereNull('effets_termines_le')->count());
    expect($nonTermines())->toBe(1);

    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful(); // aucun nouveau POST
    expect($retrait->n)->toBe(2);
    expect($nonTermines())->toBe(0);

    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(2);
    expect(desinscrOppositions($this->ws))->toBe(1);
});

test('B1 la relance est planifiée chaque minute', function () {
    $evenements = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'envoi:relancer-effets-opposition'));
    expect($evenements)->toHaveCount(1);
    expect($evenements->first()->expression)->toBe('* * * * *');
});

test('deux terminaisons simultanées des effets : un seul événement', function () {
    // Opposition acquise, effets non terminés (sans UPDATE : le trigger d'ajout seul l'interdit désormais).
    $id = WorkspaceContext::run($this->ws, function () {
        $lien = DB::table('envoi_liens_desinscription')->where('reference', $this->ref)->first();

        return (int) DB::table('envoi_oppositions')->insertGetId([
            'workspace_id' => $this->ws, 'lien_id' => $lien->id, 'empreinte_adresse' => $lien->empreinte_adresse, 'canal' => 'page',
        ]);
    });
    Event::fake([OppositionEnregistree::class]);

    $t = app(TerminerEffetsOpposition::class);
    $premier = $t->pour($this->ws, $id);
    $second = $t->pour($this->ws, $id);

    expect([$premier, $second])->toBe([true, false]);
    Event::assertDispatchedTimes(OppositionEnregistree::class, 1);
});

test('one-click RFC 8058 envoyé en multipart/form-data', function () {
    $r = $this->call('POST', $this->chemin, ['List-Unsubscribe' => 'One-Click'], [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=----zz']);
    $r->assertOk();
    expect($r->getContent())->toBe('');
    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(WorkspaceContext::run($this->ws, fn () => DB::table('envoi_oppositions')->value('canal')))->toBe('one_click');
});

test('lien expiré : page de refus avec un moyen de contact générique, sans distinguer la cause', function () {
    config(['envoi.contact_desinscription' => 'desinscription@exemple.invalid']);
    // Un jeton RÉELLEMENT échu mais d'une référence inconnue : refusé (même page), sans fuite de la cause.
    $echuInconnu = JetonDesinscription::emettre($this->ws, '00000000-0000-4000-8000-000000000000', Carbon::now()->subYears(2));
    $r = $this->get('/desinscription/' . $echuInconnu);
    $r->assertNotFound();
    expect($r->getContent())->toContain('desinscription@exemple.invalid')->toContain('expiré');
});

// ── Correctifs des audits Opus (sécurité + données) ─────────────────────────────────────────

test('RFC 8058 : une désinscription en un clic ne reçoit JAMAIS 429, même en rafale depuis une même IP', function () {
    for ($i = 0; $i < 150; $i++) {
        $this->post($this->chemin, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    }
    // Des jetons DIFFÉRENTS depuis la même IP (messagerie d'entreprise derrière un seul NAT) : jamais limités entre eux.
    for ($i = 0; $i < 60; $i++) {
        $ref = InscriptionsEnvoi::creer($this->ws, "p{$i}@zz-desinscription.example.invalid");
        $this->get('/desinscription/' . JetonDesinscription::emettre($this->ws, $ref))->assertOk();
    }
});

test('jeton signé mais EXPIRÉ : le GET (page, aucune écriture) et le POST sont tous deux acceptés', function () {
    $expire = JetonDesinscription::emettre($this->ws, $this->ref, Carbon::now()->subYears(2));
    $this->get('/desinscription/' . $expire)->assertOk()->assertSee('Confirmer la désinscription', false);
    expect(desinscrOppositions($this->ws))->toBe(0);

    $this->post('/desinscription/' . $expire, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();

    // Signature falsifiée : toujours refusée, expirée ou non.
    $this->post('/desinscription/' . substr($expire, 0, -2) . 'zz')->assertNotFound();
});

test('page de refus : le moyen de contact n’est jamais vide, même sans réglage dédié', function () {
    config(['envoi.contact_desinscription' => '', 'mail.from.address' => 'expediteur@exemple.invalid']);
    $r = $this->get('/desinscription/v1.invalide');
    $r->assertNotFound();
    expect($r->getContent())->toContain('expediteur@exemple.invalid');

    config(['mail.from.address' => '']);
    $sans = $this->get('/desinscription/v1.invalide')->getContent();
    expect($sans)->toContain('répondez')->not->toContain('ou écrivez à  ')->not->toMatch('/désinscription\s*\./u');
});

test('triggers d’ajout seul : envoi_oppositions n’autorise que NULL -> valeur sur effets_termines_le', function () {
    $this->post($this->chemin)->assertOk();
    $refus = fn (string $sql) => expect(fn () => DB::transaction(fn () => WorkspaceContext::run($this->ws, fn () => DB::statement($sql))))->toThrow(QueryException::class);

    $refus('UPDATE envoi_oppositions SET effets_termines_le = NULL');           // valeur -> NULL
    $refus("UPDATE envoi_oppositions SET effets_termines_le = now() + interval '1 day'"); // valeur -> autre valeur
    $refus("UPDATE envoi_oppositions SET canal = 'page'");
    $refus("UPDATE envoi_oppositions SET empreinte_adresse = repeat('b', 64)");
    $refus('DELETE FROM envoi_oppositions');
    $refus('TRUNCATE envoi_oppositions');
    $refus('DELETE FROM envoi_liens_desinscription');
    $refus('TRUNCATE envoi_liens_desinscription CASCADE');
    expect(desinscrOppositions($this->ws))->toBe(1);
});

test('trigger : le passage NULL -> valeur reste permis (c’est le seul)', function () {
    Event::fake([OppositionEnregistree::class]);
    $this->post($this->chemin)->assertOk();
    $id = (int) WorkspaceContext::run($this->ws, fn () => DB::table('envoi_oppositions')->value('id'));
    expect(WorkspaceContext::run($this->ws, fn () => DB::table('envoi_oppositions')->where('id', $id)->whereNotNull('effets_termines_le')->count()))->toBe(1);
    // Les fonctions de trigger fixent leur search_path (convention de 000011).
    $n = DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname LIKE 'envoi_%ajout_seul%' AND (proconfig IS NULL OR NOT proconfig::text LIKE '%search_path%')");
    expect((int) $n->n)->toBe(0);
});

test('table des liens sans colonne adresse ; clé étrangère composite (espace, lien)', function () {
    $cols = DB::table('information_schema.columns')->where('table_name', 'envoi_liens_desinscription')->pluck('column_name')->all();
    expect($cols)->not->toContain('adresse')->toContain('empreinte_adresse');

    $fk = DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conrelid = 'envoi_oppositions'::regclass AND contype = 'f' AND confrelid = 'envoi_liens_desinscription'::regclass");
    expect($fk->d)->toContain('(workspace_id, lien_id)')->toContain('(workspace_id, id)');

    // Une opposition d'un AUTRE espace ne peut pas pointer sur ce lien.
    $autre = F::espace('zz-desinscr-fk');
    $lien = (int) WorkspaceContext::run($this->ws, fn () => DB::table('envoi_liens_desinscription')->value('id'));
    expect(fn () => DB::transaction(fn () => WorkspaceContext::run($autre, fn () => DB::table('envoi_oppositions')->insert([
        'workspace_id' => $autre, 'lien_id' => $lien, 'empreinte_adresse' => str_repeat('a', 64), 'canal' => 'page',
    ]))))->toThrow(QueryException::class);
});

test('M1 l’opposition est écrite via addOptOut (raison unsubscribe) ET dans la liste de suppression', function () {
    $this->post($this->chemin)->assertOk();
    $h = ListeSuppression::empreinte(DESINSCR_ADRESSE);
    $lignes = DB::table('opt_out')->where('email_hash', $h)->get();
    expect($lignes)->not->toBeEmpty();
    expect($lignes->pluck('reason')->unique()->all())->toBe(['unsubscribe']);
    expect($lignes->pluck('email')->filter()->all())->toBe([]); // jamais l'adresse en clair
    // Le blocage par email_suppressions n'est pas cassé.
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();
    expect(DB::table('email_suppressions')->where('email_hash', $h)->value('reason'))->toBe(ListeSuppression::MANUEL); // vocabulaire fermé de cette table
    // Rejeu : pas de doublon.
    $n = $lignes->count();
    $this->post($this->chemin)->assertOk();
    expect(DB::table('opt_out')->where('email_hash', $h)->count())->toBe($n);
});

test('inscrireEmpreinte : exception si l’empreinte est mal formée, rien n’est écrit', function () {
    foreach (['', 'abc', str_repeat('Z', 64), str_repeat('a', 63), str_repeat('a', 65)] as $mauvaise) {
        expect(fn () => ListeSuppression::inscrireEmpreinte($mauvaise, ListeSuppression::MANUEL, 'test'))->toThrow(InvalidArgumentException::class);
    }
    expect(DB::table('email_suppressions')->count())->toBe(0);
});

test('inscrireEmpreinte : deux appels = une seule ligne (ON CONFLICT), occurrences cumulées', function () {
    $h = ListeSuppression::empreinte('x@zz-desinscription.example.invalid');
    ListeSuppression::inscrireEmpreinte($h, ListeSuppression::MANUEL, 'test');
    ListeSuppression::inscrireEmpreinte($h, ListeSuppression::MANUEL, 'test');
    expect(DB::table('email_suppressions')->where('email_hash', $h)->count())->toBe(1);
    expect((int) DB::table('email_suppressions')->where('email_hash', $h)->value('occurrences'))->toBe(2);
});

test('l’événement sortant en panne n’annule PAS l’opposition ; la relance le reprend une seule fois', function () {
    $panne = true;
    $essais = 0;
    app()->instance(TerminerEffetsOpposition::class, new TerminerEffetsOpposition(function () use (&$panne, &$essais) {
        $essais++;
        if ($panne) {
            throw new RuntimeException('panne de l’événement sortant');
        }
        (new ConsentOutboundRecorder)->record('consent_optout', ListeSuppression::empreinte(DESINSCR_ADRESSE), 'business', null, []);
    }));

    $this->post($this->chemin)->assertOk(); // la réponse ne dépend pas de la panne
    expect(desinscrOppositions($this->ws))->toBe(1);
    expect(ListeSuppression::estSupprimee(DESINSCR_ADRESSE))->toBeTrue();
    expect(DB::table('crm_outbound_events')->count())->toBe(0);

    $panne = false;
    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect(DB::table('crm_outbound_events')->count())->toBe(1);
    expect($essais)->toBe(2);
});

test('M2 opposition -> envoi réel : refusé tant que le retrait des envois en file est celui par défaut', function () {
    $ws = $this->ws;
    $m = new MessageAEnvoyer($ws, 'a@example.test', 'f@example.test', 'S', 'C', 'k');
    $this->post($this->chemin)->assertOk();
    config(['envoi.reel_actif' => true]);

    // Retrait « absent » branché : l'envoi réel est tenu pour inactif, rien ne part.
    expect(app(RetraitEnvoisEnFile::class))->toBeInstanceOf(RetraitEnvoisEnFileAbsent::class);
    expect(fn () => WorkspaceContext::run($ws, fn () => app(ServeurEnvoi::class)->injecter($m)))->toThrow(EnvoiRefuse::class, 'Envoi réel inactif');
});

test('M2 la garde s’efface quand un vrai retrait est branché (l’envoi reste soumis aux autres portes)', function () {
    app()->instance(RetraitEnvoisEnFile::class, new class implements RetraitEnvoisEnFile
    {
        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            return 0;
        }
    });
    config(['envoi.reel_actif' => true]);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire); // aucun réseau en test (L7)
    $m = new MessageAEnvoyer($this->ws, 'a@example.test', 'f@example.test', 'S', 'C', 'k');
    try {
        WorkspaceContext::run($this->ws, fn () => app(ServeurEnvoi::class)->injecter($m));
    } catch (EnvoiRefuse $e) {
        expect($e->getMessage())->not->toContain('Envoi réel inactif'); // refusé pour une autre raison (témoins), pas par cette garde
    }
});

test('concurrence RÉELLE : deux processus simultanés sur le même lien = une opposition, un comptage, un événement', function () {
    $proprio = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $ref = (string) Str::uuid();
    $h = ListeSuppression::empreinte('conc@zz-desinscription.example.invalid');
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-conc-' . substr($ws, 0, 6), 'name' => 'Concurrence', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $proprio->table('envoi_liens_desinscription')->insert(['workspace_id' => $ws, 'reference' => $ref, 'empreinte_adresse' => $h, 'portee' => 'business']);
    $fichierEffets = tempnam(sys_get_temp_dir(), 'effets-opposition-');
    try {
        $depart = microtime(true) + 4;
        $procs = [];
        foreach ([1, 2, 3] as $i) {
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/Support/opposition-concurrente.php'), $ws, $ref, sprintf('%.4F', $depart), $fichierEffets], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes[$i]);
        }
        $codes = [];
        foreach ($procs as $i => $p) {
            $sortie = stream_get_contents($tubes[$i][1]) . stream_get_contents($tubes[$i][2]);
            $codes[$i] = proc_close($p);
            expect($codes[$i])->toBe(0, $sortie);
        }
        expect($proprio->table('envoi_oppositions')->where('workspace_id', $ws)->count())->toBe(1);
        expect((int) $proprio->table('email_suppressions')->where('email_hash', $h)->sum('occurrences'))->toBe(1);
        expect($proprio->table('email_suppressions')->where('email_hash', $h)->count())->toBe(1);
        expect($proprio->table('crm_outbound_events')->where('email_hash', $h)->count())->toBe(1);
        expect($proprio->table('opt_out')->where('email_hash', $h)->count())->toBe(1); // portée « business »
        // Les EFFETS déclenchés (pas seulement les lignes en base) : sans lockForUpdate, deux processus les déclencheraient chacun.
        expect(substr_count((string) file_get_contents($fichierEffets), "effet\n"))->toBe(1);
    } finally {
        @unlink($fichierEffets);
        // Nettoyage par le propriétaire : les triggers d'ajout seul sont suspendus le temps du nettoyage de CES lignes.
        $proprio->statement("SET session_replication_role = 'replica'");
        $proprio->table('crm_outbound_events')->where('email_hash', $h)->delete();
        $proprio->table('opt_out')->where('email_hash', $h)->delete();
        $proprio->table('email_suppressions')->where('email_hash', $h)->delete();
        $proprio->table('envoi_oppositions')->where('workspace_id', $ws)->delete();
        $proprio->table('envoi_liens_desinscription')->where('workspace_id', $ws)->delete();
        $proprio->table('workspaces')->where('id', $ws)->delete();
        $proprio->statement("SET session_replication_role = 'origin'");
        $proprio->disconnect();
    }
});

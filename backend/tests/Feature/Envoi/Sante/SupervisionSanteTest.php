<?php

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Sante\Constat;
use App\Envoi\Sante\CouplageSante;
use App\Envoi\Sante\DepotSanteDb;
use App\Envoi\Sante\EmetteurAlerteSante;
use App\Envoi\Sante\EmetteurAlerteSocle;
use App\Envoi\Sante\MesuresSante;
use App\Envoi\Sante\SondeSante;
use App\Envoi\Sante\SuperviseurSante;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Alertes\Alerte;
use App\Socle\Alertes\AlerteMail;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceSante(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'T', 'slug' => 'k15-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function t(string $hms): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-13 ' . $hms, new DateTimeZone('UTC'));
}

function saines(string $hms = '10:00:00', array $surcharge = []): MesuresSante
{
    return MesuresSante::depuis($surcharge + [
        'file_age_s' => 10, 'disque_libre_pct' => 60.0, 'memoire_libre_pct' => 50.0, 'certificat_jours' => 80,
        'tunnel_ok' => true, 'ntp_synchro' => true, 'horloge_unix' => t($hms)->getTimestamp(),
    ]);
}

final class SondeScriptee implements SondeSante
{
    /** @var list<MesuresSante|Throwable> */
    public array $file = [];

    public function mesurer(string $workspaceId, string $source): MesuresSante
    {
        $x = array_shift($this->file) ?? throw new ServeurEnvoiRefuse('muet');
        if ($x instanceof Throwable) {
            throw $x;
        }

        return $x;
    }
}

final class EmetteurEnregistreur implements EmetteurAlerteSante
{
    /** @var list<array{string, string}> code, gravité */
    public array $envoyees = [];

    public function alerter(string $workspaceId, string $source, Constat $c): bool
    {
        $this->envoyees[] = [$c->code, $c->gravite];

        return true;
    }
}

final class CommandeurEnregistreur implements CommandeurServeur
{
    /** @var list<string> */
    public array $commandes = [];

    public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool
    {
        $this->commandes[] = $commande;

        return true;
    }
}

function monter(?EmetteurAlerteSante $em = null, ?CommandeurServeur $cmd = null): array
{
    $sonde = new SondeScriptee;
    $em ??= new EmetteurEnregistreur;
    $cmd ??= new CommandeurEnregistreur;
    $seuils = (array) config('envoi.sante');

    return [new SuperviseurSante($sonde, new DepotSanteDb, $em, new DepotChauffe, $cmd, $seuils), $sonde, $em, $cmd];
}

function pausesDe(string $ws): array
{
    return array_map(fn ($p) => $p->motif, (new DepotChauffe)->pausesActives($ws, t('12:00:00')));
}

test('chaque seuil franchi => UNE alerte, même si la sonde repasse 3 fois (pas de rafale)', function (array $surcharge, string $code, string $gravite) {
    $ws = espaceSante();
    [$sup, $sonde, $em] = monter();
    foreach (['10:00:00', '10:05:00', '10:10:00'] as $h) {
        $sonde->file[] = saines($h, $surcharge);
        $sup->superviser($ws, 'src', t($h));
    }
    expect($em->envoyees)->toBe([[$code, $gravite]]);
})->with([
    'file' => [['file_age_s' => 901], 'file_age', Constat::ATTENTION],
    'disque' => [['disque_libre_pct' => 9.0], 'disque', Constat::CRITIQUE],
    'mémoire' => [['memoire_libre_pct' => 14.0], 'memoire', Constat::ATTENTION],
    'certificat' => [['certificat_jours' => 7], 'certificat', Constat::CRITIQUE],
    'tunnel' => [['tunnel_ok' => false], 'tunnel', Constat::ATTENTION],
    'ntp' => [['ntp_synchro' => false], 'ntp', Constat::ATTENTION],
]);

test('escalade attention -> critique : une 2e alerte ; retour à la normale puis nouveau franchissement : une 3e', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em] = monter();
    foreach ([['10:00:00', 19.0], ['10:05:00', 5.0], ['10:10:00', 5.0], ['10:15:00', 60.0], ['10:20:00', 19.0]] as [$h, $pct]) {
        $sonde->file[] = saines($h, ['disque_libre_pct' => $pct]);
        $sup->superviser($ws, 'src', t($h));
    }
    expect($em->envoyees)->toBe([['disque', 'attention'], ['disque', 'critique'], ['disque', 'attention']]);
});

test('alerte critique => pause de la ressource ; la pause n’est JAMAIS levée seule', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em, $cmd] = monter();
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0]), saines('10:05:00'), saines('10:10:00')];
    $sup->superviser($ws, 'src', t('10:00:00'));
    expect(pausesDe($ws))->toBe(['sante:disque:src'])->and($cmd->commandes)->toBe(['pause']);

    $sup->superviser($ws, 'src', t('10:05:00')); // tout est redevenu sain
    $sup->superviser($ws, 'src', t('10:10:00'));
    expect(pausesDe($ws))->toBe(['sante:disque:src'])->and($cmd->commandes)->toBe(['pause']); // ni levée, ni 2e commande
    $ligne = WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->where('workspace_id', $ws)->first());
    expect($ligne->levee_le)->toBeNull();
});

test('une alerte « attention » ne met rien en pause', function () {
    $ws = espaceSante();
    [$sup, $sonde, , $cmd] = monter();
    $sonde->file = [saines('10:00:00', ['file_age_s' => 1000])];
    $sup->superviser($ws, 'src', t('10:00:00'));
    expect(pausesDe($ws))->toBe([])->and($cmd->commandes)->toBe([]);
});

test('écart d’horloge > 5 min => alerte critique et pause', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em] = monter();
    $sonde->file = [saines('10:00:00', ['horloge_unix' => t('10:00:00')->getTimestamp() + 301])];
    $sup->superviser($ws, 'src', t('10:00:00'));
    expect($em->envoyees)->toBe([['horloge', 'critique']])->and(pausesDe($ws))->toBe(['sante:horloge:src']);
});

test('tunnel coupé : alerte d’ATTENTION avant 10 min, critique et pause avant 15 min, MÊME avec le décalage de quelques secondes du planificateur', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em, $cmd] = monter();
    $sonde->file = [saines('10:00:03')]; // dernier battement bon à 10:00:03 ; ensuite la file est vide = muet
    $sup->superviser($ws, 'src', t('10:00:03'));
    foreach (['10:05:01', '10:08:59'] as $h) { // 298 s et 536 s : rien
        $sup->superviser($ws, 'src', t($h));
    }
    expect($em->envoyees)->toBe([])->and(pausesDe($ws))->toBe([]);

    $sup->superviser($ws, 'src', t('10:10:00')); // 597 s (et non 600) : le passage qui tombe 3 s « trop tôt » alerte quand même
    expect($em->envoyees)->toBe([['serveur_muet', 'attention']])->and(pausesDe($ws))->toBe([])->and($cmd->commandes)->toBe([]);
    $sup->superviser($ws, 'src', t('10:13:59')); // 836 s : pas encore critique
    expect($em->envoyees)->toHaveCount(1)->and(pausesDe($ws))->toBe([]);

    $sup->superviser($ws, 'src', t('10:15:00')); // 897 s (et non 900) : critique + pause, pas à 10:20
    expect($em->envoyees)->toBe([['serveur_muet', 'attention'], ['serveur_muet', 'critique']])
        ->and(pausesDe($ws))->toBe(['sante:serveur_muet:src'])
        ->and($cmd->commandes)->toBe(['pause']);
});

test('homme mort : un serveur jamais joint compte le silence depuis le premier relevé', function () {
    $ws = espaceSante();
    [$sup, , $em] = monter();
    $sup->superviser($ws, 'src', t('10:00:00'));
    $sup->superviser($ws, 'src', t('10:08:59'));
    expect($em->envoyees)->toBe([]);
    $sup->superviser($ws, 'src', t('10:09:00'));
    expect($em->envoyees)->toBe([['serveur_muet', 'attention']]);
    $sup->superviser($ws, 'src', t('10:13:59'));
    expect($em->envoyees)->toHaveCount(1);
    $sup->superviser($ws, 'src', t('10:14:00'));
    expect($em->envoyees)->toBe([['serveur_muet', 'attention'], ['serveur_muet', 'critique']]);
});

test('M1 : premier passage après un arrêt de la supervision = un seul échec ne déclenche PAS « serveur muet » ; le silence compte depuis la reprise', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em] = monter();
    $sonde->file = [saines('10:00:00')];
    $sup->superviser($ws, 'src', t('10:00:00')); // dernier bon battement, puis la supervision s'arrête 2 jours
    $reprise = fn (string $min) => t('10:00:00')->modify('+2 days ' . $min);
    $sup->superviser($ws, 'src', $reprise('+0 minutes'));
    $sup->superviser($ws, 'src', $reprise('+5 minutes'));
    expect($em->envoyees)->toBe([])->and(constatsEn($ws))->toBe([]);
    $sup->superviser($ws, 'src', $reprise('+10 minutes')); // 10 min de silence APRÈS la reprise : attention
    expect($em->envoyees)->toBe([['serveur_muet', 'attention']]);
});

test('la commande de pause en échec n’empêche ni la pause locale ni l’alerte', function () {
    $ws = espaceSante();
    $cmd = new class implements CommandeurServeur
    {
        public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool
        {
            throw new ServeurEnvoiRefuse('injoignable');
        }
    };
    [$sup, $sonde, $em] = monter(null, $cmd);
    $sonde->file = [saines('10:00:00', ['memoire_libre_pct' => 2.0])];
    $sup->superviser($ws, 'src', t('10:00:00'));
    expect(pausesDe($ws))->toBe(['sante:memoire:src'])->and($em->envoyees)->toBe([['memoire', 'critique']]);
});

test('K12 et K13 : DNS pas prêt = attention, DNS en pause = critique, domaine listé = critique', function () {
    $ws = espaceSante();
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'a.example', 'controle_le' => t('09:00:00')->format('c'), 'pret' => false, 'pause' => false]);
        DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'b.example', 'controle_le' => t('09:00:00')->format('c'), 'pret' => false, 'pause' => true]);
        DB::table('envoi_controles_listes')->insert(['workspace_id' => $ws, 'type' => 'domaine', 'ressource' => 'c.example', 'statut' => 'liste', 'zone' => 'dbl.spamhaus.org', 'pause' => true, 'controle_le' => t('09:30:00')->format('c')]);
    });
    [$sup, $sonde, $em] = monter();
    $sonde->file = [saines('10:00:00'), saines('10:05:00')];
    $sup->superviser($ws, 'src', t('10:00:00'));
    $sup->superviser($ws, 'src', t('10:05:00'));
    $vues = collect($em->envoyees)->sortBy(0)->values()->all();
    expect($vues)->toBe([['dns:a.example', 'attention'], ['dns:b.example', 'critique'], ['liste_noire:domaine:c.example', 'critique']]);
});

test('un contrôle DNS trop ancien (> 36 h) est signalé', function () {
    $ws = espaceSante();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'a.example', 'controle_le' => t('10:00:00')->modify('-37 hours')->format('c'), 'pret' => true, 'pause' => false]));
    [$sup, $sonde, $em] = monter();
    $sonde->file = [saines('10:00:00')];
    $sup->superviser($ws, 'src', t('10:00:00'));
    expect($em->envoyees)->toBe([['dns:a.example', 'attention']]);
});

test('l’alerte part par le Socle (transactionnel), JAMAIS par une boîte ou un domaine de prospection', function () {
    $ws = espaceSante();
    Mail::fake();
    config(['alertes.actif' => true, 'alertes.email.destinataire' => 'resp@example.test', 'mail.from.address' => 'alertes@app.example.test']);
    $faux = app(FauxServeurEnvoi::class);
    [$sup, $sonde] = monter(new EmetteurAlerteSocle(app(Alerte::class)));
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0])];
    $sup->superviser($ws, 'src', t('10:00:00'));
    Mail::assertSent(AlerteMail::class, 1);
    expect($faux->etat()->injectes)->toBe(0); // la supervision n'injecte rien

    // Même alerte, mais l'expéditeur de l'application est un domaine d'envoi de prospection : REFUSÉE, rien ne part.
    Mail::fake();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'prospect.example', 'achete_le' => '2026-01-01']));
    config(['mail.from.address' => 'Alertes@Prospect.Example']);
    $sonde->file = [saines('10:05:00', ['memoire_libre_pct' => 2.0])];
    $sup->superviser($ws, 'src', t('10:05:00'));
    Mail::assertNothingSent();
});

test('la supervision est tenue par espace : l’état d’un espace ne masque pas l’alerte d’un autre', function () {
    $a = espaceSante();
    $b = espaceSante();
    [$sup, $sonde, $em] = monter();
    foreach ([$a, $b] as $ws) {
        $sonde->file[] = saines('10:00:00', ['file_age_s' => 5000]);
        $sup->superviser($ws, 'src', t('10:00:00'));
    }
    expect($em->envoyees)->toBe([['file_age', 'critique'], ['file_age', 'critique']]);
    expect(pausesDe($a))->toBe(['sante:file_age:src'])->and(pausesDe($b))->toBe(['sante:file_age:src']);
});

test('la commande est INERTE tant que l’envoi réel ou K1 est éteint, REFUSE si seule la supervision est éteinte, et supervise chaque serveur actif sinon', function () {
    $ws = espaceSante();
    DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => 'src', 'url_base' => 'https://envoi.example.test']);
    $sonde = new SondeScriptee;
    $this->app->instance(SondeSante::class, $sonde);
    $this->app->instance(EmetteurAlerteSante::class, $em = new EmetteurEnregistreur);
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 1.0, 'horloge_unix' => now()->getTimestamp()])];

    foreach ([['reel_actif'], ['k1_actif']] as [$cle]) {
        config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.sante.actif' => true, 'envoi.' . $cle => false]);
        $this->artisan('envoi:supervision-sante')->assertSuccessful();
    }
    // Envoi réel + K1 allumés, supervision éteinte : ni silence ni succès (ADR K0, homme mort) — l'échec est visible.
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.sante.actif' => false]);
    $this->artisan('envoi:supervision-sante')->assertFailed();
    expect($sonde->file)->toHaveCount(1)->and($em->envoyees)->toBe([])->and(DB::table('envoi_releves_sante')->count())->toBe(0);

    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.sante.actif' => true]);
    $this->artisan('envoi:supervision-sante')->assertSuccessful();
    expect($em->envoyees)->toBe([['disque', 'critique']])->and(DB::table('envoi_releves_sante')->count())->toBe(1);
});

test('serveur injoignable (< 15 min) : les constats ouverts restent ouverts, et le retour du même défaut ne ré-alerte pas', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em] = monter();
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0])];
    $sup->superviser($ws, 'src', t('10:00:00'));
    $sup->superviser($ws, 'src', t('10:05:00')); // muet
    expect(array_keys((new DepotSanteDb)->constatsOuverts($ws, 'src')))->toBe(['disque']);

    $sonde->file = [saines('10:10:00', ['disque_libre_pct' => 5.0])];
    $sup->superviser($ws, 'src', t('10:10:00'));
    expect($em->envoyees)->toBe([['disque', 'critique']]);
});

// ─────────────────────────── K15 suite : exactitude (relecture Opus) ───────────────────────────

/** Fait ÉCHOUER l'insertion d'une pause (trigger de test, annulé avec la transaction du test) ; $motif = null : n'échoue plus. */
function casserPause(?string $motif): void
{
    DB::unprepared('CREATE OR REPLACE FUNCTION zz_casse_pause() RETURNS trigger AS $f$ BEGIN IF current_setting(\'zz.casse\', true) = NEW.motif THEN RAISE EXCEPTION \'pause cassée (test)\'; END IF; RETURN NEW; END $f$ LANGUAGE plpgsql');
    DB::unprepared('DROP TRIGGER IF EXISTS zz_casse_pause ON envoi_pauses');
    DB::unprepared('CREATE TRIGGER zz_casse_pause BEFORE INSERT ON envoi_pauses FOR EACH ROW EXECUTE FUNCTION zz_casse_pause()');
    DB::select('SELECT set_config(\'zz.casse\', ?, false)', [$motif ?? '']);
}

/** Lève la pause par le chemin JOURNALISÉ (envoi:lever-pause) : le trigger de envoi_pauses refuse tout autre chemin. */
function leverComme_un_humain($test, string $ws): void
{
    $id = (new DepotChauffe)->pausesActives($ws, t('10:01:00'))[0]->id;
    $test->artisan('envoi:lever-pause', ['workspace' => $ws, 'pause' => (string) $id, '--auteur' => 'humain-test'])->assertSuccessful();
}

function constatsEn(string $ws, string $source = 'src'): array
{
    return array_map(fn ($c) => $c['gravite'] . '/' . $c['palier'] . '/' . ($c['alerte_emise'] ? 'emise' : 'a_emettre'), (new DepotSanteDb)->constatsOuverts($ws, $source));
}

test('E1 : pause en échec => constat ouvert + alerte partie, puis la pause est RETENTÉE au passage suivant (sans 2e alerte)', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em, $cmd] = monter();
    casserPause('sante:disque:src');
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0]), saines('10:05:00', ['disque_libre_pct' => 5.0])];
    expect(fn () => $sup->superviser($ws, 'src', t('10:00:00')))->toThrow(RuntimeException::class);
    expect($em->envoyees)->toBe([['disque', 'critique']])->and(pausesDe($ws))->toBe([])->and(constatsEn($ws))->toBe(['disque' => 'critique/3/emise']);

    casserPause(null);
    $sup->superviser($ws, 'src', t('10:05:00'));
    expect(pausesDe($ws))->toBe(['sante:disque:src'])->and($cmd->commandes)->toBe(['pause'])->and($em->envoyees)->toHaveCount(1);
});

test('E1 : une exception sur un constat ne saute pas les autres (alerte et pause des suivants posées), puis le passage échoue', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em] = monter();
    casserPause('sante:disque:src');
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0, 'memoire_libre_pct' => 2.0, 'file_age_s' => 1000])];
    expect(fn () => $sup->superviser($ws, 'src', t('10:00:00')))->toThrow(RuntimeException::class, '1 constat');
    $codes = array_column($em->envoyees, 0);
    expect($codes)->toContain('disque', 'memoire', 'file_age')->and(pausesDe($ws))->toBe(['sante:memoire:src']);
    casserPause(null);
});

test('E1 : un humain lève la pause alors que le défaut critique persiste => la supervision la REPOSE (une seule alerte)', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em, $cmd] = monter();
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0]), saines('10:05:00', ['disque_libre_pct' => 5.0])];
    $sup->superviser($ws, 'src', t('10:00:00'));
    leverComme_un_humain($this, $ws);
    expect(pausesDe($ws))->toBe([]);

    $sup->superviser($ws, 'src', t('10:05:00'));
    expect(pausesDe($ws))->toBe(['sante:disque:src'])->and($cmd->commandes)->toBe(['pause', 'pause'])->and($em->envoyees)->toHaveCount(1);
});

test('E1 : défaut critique GUÉRI puis pause levée par un humain => la supervision ne la repose pas', function () {
    $ws = espaceSante();
    [$sup, $sonde] = monter();
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0]), saines('10:05:00'), saines('10:10:00')];
    $sup->superviser($ws, 'src', t('10:00:00'));
    $sup->superviser($ws, 'src', t('10:05:00'));
    leverComme_un_humain($this, $ws);
    $sup->superviser($ws, 'src', t('10:10:00'));
    expect(pausesDe($ws))->toBe([]);
});

/** Un envoi (boîte, injection, message) daté, et un retour daté, dans de VRAIES tables. */
function semerEnvoi(string $ws, DateTimeImmutable $quand): void
{
    WorkspaceContext::run($ws, function () use ($ws, $quand) {
        $boite = (string) Str::uuid();
        $inj = (string) Str::uuid();
        DB::table('envoi_boites')->insert(['id' => $boite, 'workspace_id' => $ws, 'adresse' => 'b-' . substr($boite, 0, 8) . '@boite.test', 'hote' => 'smtp.exemple.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'x']);
        DB::table('envoi_injections_smtp')->insert(['workspace_id' => $ws, 'boite_id' => $boite, 'id_injection' => $inj, 'cle_idempotence' => 'k-' . $inj]);
        DB::table('envoi_messages_envoyes')->insert(['workspace_id' => $ws, 'message_id_empreinte' => hash('sha256', $inj), 'id_injection' => $inj, 'empreinte_destinataire' => hash('sha256', 'd' . $inj), 'cree_le' => $quand->format('c')]);
    });
}

/** Un retour (événement) daté. */
function semerRetour(string $ws, DateTimeImmutable $quand): void
{
    $u = (string) Str::uuid();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_evenements')->insert([
        'workspace_id' => $ws, 'boite' => 'r-' . $u, 'uid_validity' => 1, 'uid' => 1, 'empreinte_message' => hash('sha256', $u), 'classe' => 'rebond_dur',
        'statut' => 'a_rattacher', 'empreinte_expediteur' => hash('sha256', 'e' . $u), 'recu_le' => $quand->format('c'), 'cree_le' => $quand->format('c'),
    ]));
}

/** Une relève IMAP menée à son terme (curseur mis à jour) à cet instant. */
function semerReleve(string $ws, DateTimeImmutable $quand, string $boite = 'boite-1'): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_curseurs_imap')->insert(['workspace_id' => $ws, 'boite' => $boite, 'uid_validity' => 1, 'dernier_uid' => 1, 'maj_le' => $quand->format('c')]));
}

test('B1 : relève IMAP à l’arrêt en campagne => critique + pause ; une campagne saine SANS AUCUN retour mais relève vivante ne déclenche rien', function (array $envois, ?string $releve, bool $muet) {
    $ws = espaceSante();
    DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => 'src', 'url_base' => 'https://envoi.example.test']);
    foreach ($envois as $min) {
        semerEnvoi($ws, t('12:00:00')->modify("-{$min} minutes"));
    }
    if ($releve !== null) {
        semerReleve($ws, t('12:00:00')->modify($releve));
    }
    [$sup, $sonde, $em, $cmd] = monter();
    $sonde->file = [saines('12:00:00')];
    $sup->superviser($ws, 'src', t('12:00:00'));
    expect($em->envoyees)->toBe($muet ? [['retours_muets', 'critique']] : [])
        ->and(pausesDe($ws))->toBe($muet ? ['sante:retours_muets'] : [])
        ->and($cmd->commandes)->toBe($muet ? ['pause'] : []);
    expect(array_keys((new DepotSanteDb)->constatsOuverts($ws, SuperviseurSante::ESPACE)))->toBe($muet ? ['retours_muets'] : []);
})->with([
    'envois depuis 3 h, aucun retour, relève vivante (il y a 5 min) : campagne saine' => [[180, 30], '-5 minutes', false],
    'relève vivante il y a 1 h 59' => [[180, 30], '-119 minutes', false],
    'relève jamais tournée, envois depuis 3 h' => [[180, 30], null, true],
    'relève arrêtée depuis 5 h' => [[180, 30], '-300 minutes', true],
    'relève arrêtée depuis 3 h, un envoi vieux de 2 h 30 est parti après' => [[150, 30], '-180 minutes', true],
    'message resté 2 h pile après la dernière relève' => [[120, 30], null, true],
    'message resté 1 h 59 sans relève : pas encore' => [[119, 30], null, false],
    'dernière relève il y a 2 h pile, message parti ensuite et vieux de moins de 2 h' => [[180, 100], '-120 minutes', false],
    'relève il y a 2 h 30, envois avant elle et récent' => [[180, 30], '-150 minutes', false],
    'campagne qui démarre (envois < 2 h)' => [[90, 30], null, false],
    'campagne finie (envois > 2 h seulement)' => [[300, 200], null, false],
    'aucun envoi' => [[], null, false],
]);

test('B1 : un retour (rebond…) n’est PAS une preuve de vie de la relève : retour récent mais relève arrêtée => constat', function () {
    $ws = espaceSante();
    semerEnvoi($ws, t('12:00:00')->modify('-180 minutes'));
    semerEnvoi($ws, t('12:00:00')->modify('-30 minutes'));
    semerRetour($ws, t('12:00:00')->modify('-10 minutes'));
    [$sup, $sonde, $em] = monter();
    $sonde->file = [saines('12:00:00')];
    $sup->superviser($ws, 'src', t('12:00:00'));
    expect($em->envoyees)->toBe([['retours_muets', 'critique']]);
});

test('B1 : le constat « retours muets » se rétablit dès que la relève repasse, la pause reste ; plus de pose à l’envoi suivant', function () {
    $ws = espaceSante();
    semerEnvoi($ws, t('12:00:00')->modify('-180 minutes'));
    semerEnvoi($ws, t('12:00:00')->modify('-30 minutes'));
    [$sup, $sonde] = monter();
    $sonde->file = [saines('12:00:00'), saines('12:05:00')];
    $sup->superviser($ws, 'src', t('12:00:00'));
    semerReleve($ws, t('12:04:00'));
    $sup->superviser($ws, 'src', t('12:05:00'));
    expect((new DepotSanteDb)->constatsOuverts($ws, SuperviseurSante::ESPACE))->toBe([])->and(pausesDe($ws))->toBe(['sante:retours_muets']);
});

test('E4 : refus de la passerelle pour horloge => constat « horloge » CRITIQUE + pause, pas « serveur_muet » (même après 15 min)', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em] = monter();
    $sonde->file = [ServeurEnvoiRefuse::horloge(), ServeurEnvoiRefuse::horloge()];
    $sup->superviser($ws, 'src', t('10:00:00'));
    $sup->superviser($ws, 'src', t('10:20:00'));
    expect($em->envoyees)->toBe([['horloge', 'critique']])->and(pausesDe($ws))->toBe(['sante:horloge:src']);
});

test('E4 : source active sans secret ni adresse => motif de CONFIGURATION (attention), jamais « serveur_muet »', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em, $cmd] = monter();
    $sonde->file = [ServeurEnvoiRefuse::configuration('secret absent'), ServeurEnvoiRefuse::configuration('secret absent')];
    $sup->superviser($ws, 'src', t('10:00:00'));
    $sup->superviser($ws, 'src', t('10:30:00'));
    expect($em->envoyees)->toBe([['configuration_serveur', 'attention']])->and($cmd->commandes)->toBe([])->and(pausesDe($ws))->toBe([]);
});

test('E5 : l’heure de référence est prise juste avant CHAQUE sonde (serveurs lents => aucune fausse alerte d’horloge)', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-11-13 10:00:00', 'UTC'));
    $ws = espaceSante();
    foreach (['a', 'b', 'c'] as $s) {
        DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => $s, 'url_base' => 'https://envoi.example.test']);
    }
    $lente = new class implements SondeSante
    {
        public function mesurer(string $workspaceId, string $source): MesuresSante
        {
            $lecture = Carbon\Carbon::now()->getTimestamp(); // le serveur lit son horloge à la réception
            Carbon\Carbon::setTestNow(Carbon\Carbon::now()->addSeconds(40)); // puis la réponse met 40 s à revenir

            return MesuresSante::depuis(['file_age_s' => 1, 'disque_libre_pct' => 60.0, 'memoire_libre_pct' => 50.0, 'certificat_jours' => 80, 'tunnel_ok' => true, 'ntp_synchro' => true, 'horloge_unix' => $lecture]);
        }
    };
    $this->app->instance(SondeSante::class, $lente);
    $this->app->instance(EmetteurAlerteSante::class, $em = new EmetteurEnregistreur);
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.sante.actif' => true]);
    $this->artisan('envoi:supervision-sante')->assertSuccessful();
    Carbon\Carbon::setTestNow();
    expect($em->envoyees)->toBe([]); // avec une référence unique, le 3e serveur (80 s de retard) lèverait « horloge »
});

test('E6 : DNS et listes noires sont PAR ESPACE : deux serveurs, un seul constat et une seule alerte par défaut', function () {
    $ws = espaceSante();
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'a.example', 'controle_le' => t('09:00:00')->format('c'), 'pret' => false, 'pause' => false]);
        DB::table('envoi_controles_listes')->insert(['workspace_id' => $ws, 'type' => 'ip', 'ressource' => '203.0.113.7', 'statut' => 'liste', 'zone' => 'z', 'pause' => true, 'controle_le' => t('09:00:00')->format('c')]);
    });
    [$sup, $sonde, $em] = monter();
    $sonde->file = [saines('10:00:00'), saines('10:00:00'), saines('10:05:00'), saines('10:05:00')];
    foreach (['10:00:00', '10:05:00'] as $h) {
        $sup->superviser($ws, 'src-1', t($h));
        $sup->superviser($ws, 'src-2', t($h));
    }
    expect(collect($em->envoyees)->sortBy(0)->values()->all())->toBe([['dns:a.example', 'attention'], ['liste_noire:ip:203.0.113.7', 'critique']])
        ->and(array_keys((new DepotSanteDb)->constatsOuverts($ws, SuperviseurSante::ESPACE)))->toBe(['dns:a.example', 'liste_noire:ip:203.0.113.7'])
        ->and((new DepotSanteDb)->constatsOuverts($ws, 'src-1'))->toBe([])
        ->and((new DepotSanteDb)->constatsOuverts($ws, 'src-2'))->toBe([]);
});

test('E7 : une alerte qui n’est pas partie est journalisée, le constat reste « à émettre » et elle est RENVOYÉE jusqu’au succès', function () {
    $ws = espaceSante();
    Log::spy();
    $em = new class implements EmetteurAlerteSante
    {
        public int $essais = 0;

        public function alerter(string $workspaceId, string $source, Constat $c): bool
        {
            return ++$this->essais >= 3;
        }
    };
    [$sup, $sonde] = monter($em);
    foreach (['10:00:00', '10:05:00', '10:10:00', '10:15:00'] as $i => $h) {
        $sonde->file[] = saines($h, ['file_age_s' => 1000]);
        $sup->superviser($ws, 'src', t($h));
        expect(constatsEn($ws))->toBe(['file_age' => $i < 2 ? 'attention/1/a_emettre' : 'attention/1/emise']);
    }
    expect($em->essais)->toBe(3); // 2 échecs, 1 succès, puis plus rien
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'alerte non émise'))->twice();
});

test('E10 : certificat à 30, 14 puis 7 jours => trois alertes (palier ou gravité qui monte), jamais de rafale', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em] = monter();
    foreach ([['10:00:00', 30], ['10:05:00', 30], ['10:10:00', 15], ['10:15:00', 14], ['10:20:00', 14], ['10:25:00', 8], ['10:30:00', 7], ['10:35:00', 7], ['10:40:00', 12]] as [$h, $j]) {
        $sonde->file[] = saines($h, ['certificat_jours' => $j]);
        $sup->superviser($ws, 'src', t($h));
    }
    expect($em->envoyees)->toBe([['certificat', 'attention'], ['certificat', 'attention'], ['certificat', 'critique']])
        ->and(constatsEn($ws))->toBe(['certificat' => 'attention/2/emise']); // redescendu sans nouvelle alerte
    expect(pausesDe($ws))->toBe(['sante:certificat:src']);
});

test('critique -> attention sans retour au vert : pas de nouvelle alerte, mais le franchissement suivant du critique ré-alerte', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em] = monter();
    foreach ([['10:00:00', 5.0], ['10:05:00', 15.0], ['10:10:00', 15.0], ['10:15:00', 5.0]] as [$h, $pct]) {
        $sonde->file[] = saines($h, ['disque_libre_pct' => $pct]);
        $sup->superviser($ws, 'src', t($h));
    }
    expect($em->envoyees)->toBe([['disque', 'critique'], ['disque', 'critique']]);
});

test('K12/K13 : un critique DNS ou liste noire ne pose AUCUNE pause de supervision (K12 et K13 pausent déjà) ; commandes serveur : aucune', function () {
    $ws = espaceSante();
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'b.example', 'controle_le' => t('09:00:00')->format('c'), 'pret' => false, 'pause' => true]);
        DB::table('envoi_controles_listes')->insert(['workspace_id' => $ws, 'type' => 'domaine', 'ressource' => 'c.example', 'statut' => 'liste', 'zone' => 'z', 'pause' => true, 'controle_le' => t('09:00:00')->format('c')]);
    });
    [$sup, $sonde, , $cmd] = monter();
    $sonde->file = [saines('10:00:00')];
    $sup->superviser($ws, 'src', t('10:00:00'));
    expect(pausesDe($ws))->toBe([])->and($cmd->commandes)->toBe([]);
});

test('K13 : ressource plus listée mais pause K13 toujours active => le constat reste OUVERT ; il se rétablit quand la pause n’existe plus', function () {
    $ws = espaceSante();
    $ligne = fn (string $statut, bool $pause, string $h) => WorkspaceContext::run($ws, fn () => DB::table('envoi_controles_listes')->insert(['workspace_id' => $ws, 'type' => 'ip', 'ressource' => '203.0.113.7', 'statut' => $statut, 'zone' => 'z', 'pause' => $pause, 'controle_le' => t($h)->format('c')]));
    [$sup, $sonde, $em] = monter();
    $sonde->file = [saines('10:00:00'), saines('10:05:00'), saines('10:10:00')];
    $ligne('liste', true, '09:55:00');
    $sup->superviser($ws, 'src', t('10:00:00'));
    $ligne('non_liste', true, '10:03:00'); // la pause est reportée par K13 tant qu'un humain ne l'a pas levée
    $sup->superviser($ws, 'src', t('10:05:00'));
    expect(array_keys((new DepotSanteDb)->constatsOuverts($ws, SuperviseurSante::ESPACE)))->toBe(['liste_noire:ip:203.0.113.7']);
    $ligne('non_liste', false, '10:08:00');
    $sup->superviser($ws, 'src', t('10:10:00'));
    expect((new DepotSanteDb)->constatsOuverts($ws, SuperviseurSante::ESPACE))->toBe([])->and($em->envoyees)->toHaveCount(1);
});

test('constat DNS rétabli même si le serveur est muet ; les constats du SERVEUR restent ouverts', function () {
    $ws = espaceSante();
    $dns = fn (bool $pret, string $h) => WorkspaceContext::run($ws, fn () => DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'a.example', 'controle_le' => t($h)->format('c'), 'pret' => $pret, 'pause' => false]));
    $dns(false, '09:50:00');
    [$sup, $sonde] = monter();
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 15.0])]; // puis muet
    $sup->superviser($ws, 'src', t('10:00:00'));
    $dns(true, '10:01:00');
    $sup->superviser($ws, 'src', t('10:05:00')); // serveur muet (< 10 min)
    expect(array_keys((new DepotSanteDb)->constatsOuverts($ws, SuperviseurSante::ESPACE)))->toBe([])
        ->and(array_keys((new DepotSanteDb)->constatsOuverts($ws, 'src')))->toBe(['disque']);
});

test('la commande COMPTE les échecs : un serveur dont le passage échoue => code de sortie en échec, les autres serveurs sont supervisés', function () {
    $ws = espaceSante();
    foreach (['a', 'b'] as $s) {
        DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => $s, 'url_base' => 'https://envoi.example.test']);
    }
    $sonde = new SondeScriptee;
    $this->app->instance(SondeSante::class, $sonde);
    $this->app->instance(EmetteurAlerteSante::class, $em = new EmetteurEnregistreur);
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 1.0, 'horloge_unix' => now()->getTimestamp()]), saines('10:00:00', ['memoire_libre_pct' => 1.0, 'horloge_unix' => now()->getTimestamp()])];
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.sante.actif' => true]);
    casserPause('sante:disque:a');
    $this->artisan('envoi:supervision-sante')->assertFailed();
    casserPause(null);
    expect(array_column($em->envoyees, 0))->toContain('disque', 'memoire');
});

test('E9 : envoi réel + K1 sans supervision => journal critique au démarrage et alerte du Socle ; rien si la supervision est allumée', function () {
    Log::spy();
    Mail::fake();
    config(['alertes.actif' => true, 'alertes.email.destinataire' => 'resp@example.test', 'mail.from.address' => 'alertes@app.example.test', 'envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.sante.actif' => true]);
    CouplageSante::oublier();
    CouplageSante::signaler(true);
    Log::shouldNotHaveReceived('critical', [Mockery::on(fn ($m) => str_contains((string) $m, 'supervision de santé est éteinte'))]);
    Mail::assertNothingSent();

    config(['envoi.sante.actif' => false]);
    CouplageSante::signaler(true);
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains((string) $m, 'supervision de santé est éteinte'))->once();
    Mail::assertSent(AlerteMail::class, 1);
    CouplageSante::oublier();
});

test('E7 : l’état « alerte émise » est celui de l’OUVERTURE COURANTE : une réouverture ou une escalade dont l’alerte échoue redevient « à émettre »', function () {
    $ws = espaceSante();
    $em = new class implements EmetteurAlerteSante
    {
        public bool $part = true;

        public int $essais = 0;

        public function alerter(string $workspaceId, string $source, Constat $c): bool
        {
            $this->essais++;

            return $this->part;
        }
    };
    [$sup, $sonde] = monter($em);
    $passe = function (string $h, array $mesures) use ($sup, $sonde, $ws) {
        $sonde->file[] = saines($h, $mesures);
        $sup->superviser($ws, 'src', t($h));
    };
    $passe('10:00:00', ['file_age_s' => 1000]);                         // attention, alerte partie
    expect(constatsEn($ws))->toBe(['file_age' => 'attention/1/emise']);
    $em->part = false;
    $passe('10:05:00', ['file_age_s' => 5000]);                         // escalade critique, alerte perdue
    expect(constatsEn($ws))->toBe(['file_age' => 'critique/3/a_emettre']);
    $em->part = true;
    $passe('10:10:00', ['file_age_s' => 5000]);                         // renvoyée
    $passe('10:15:00', []);                                             // rétabli
    $em->part = false;
    $passe('10:20:00', ['file_age_s' => 1000]);                         // réouverture, alerte perdue : l'ancienne « émise » ne compte plus
    expect(constatsEn($ws))->toBe(['file_age' => 'attention/1/a_emettre']);
    $essais = $em->essais;
    $passe('10:25:00', ['file_age_s' => 1000]);
    expect($em->essais)->toBe($essais + 1);
});

final class EmetteurScripte implements EmetteurAlerteSante
{
    /** @var list<bool> */
    public array $reponses = [];

    /** @var list<Constat> */
    public array $recus = [];

    public function alerter(string $workspaceId, string $source, Constat $c): bool
    {
        $this->recus[] = $c;

        return array_shift($this->reponses) ?? true;
    }
}

test('I2 : alerte critique perdue puis constat redescendu en « attention » => RIEN n’est acquitté sans envoi : l’alerte part, et dit que la pause reste', function () {
    $ws = espaceSante();
    $em = new EmetteurScripte;
    $em->reponses = [false, true];
    [$sup, $sonde] = monter($em);
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0]), saines('10:05:00', ['disque_libre_pct' => 15.0]), saines('10:10:00', ['disque_libre_pct' => 15.0])];
    $sup->superviser($ws, 'src', t('10:00:00'));
    expect(constatsEn($ws))->toBe(['disque' => 'critique/3/a_emettre']);
    $sup->superviser($ws, 'src', t('10:05:00'));
    expect($em->recus)->toHaveCount(2)->and($em->recus[1]->gravite)->toBe('attention')
        ->and($em->recus[1]->detail)->toContain('pause')->toContain('ACTIVE')
        ->and(constatsEn($ws))->toBe(['disque' => 'attention/1/emise'])->and(pausesDe($ws))->toBe(['sante:disque:src']);
    $sup->superviser($ws, 'src', t('10:10:00'));
    expect($em->recus)->toHaveCount(2); // acquittée pour de bon : pas de rafale
});

test('I2 : alerte critique PARTIE puis redescente => toujours pas de nouvelle alerte', function () {
    $ws = espaceSante();
    $em = new EmetteurScripte;
    [$sup, $sonde] = monter($em);
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0]), saines('10:05:00', ['disque_libre_pct' => 15.0])];
    $sup->superviser($ws, 'src', t('10:00:00'));
    $sup->superviser($ws, 'src', t('10:05:00'));
    expect($em->recus)->toHaveCount(1);
});

test('I2 bis : deux serveurs d’un même espace en défaut critique sur le même code => CHACUN reçoit sa commande de pause et sa propre pause (motif avec la source)', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em, $cmd] = monter();
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 5.0]), saines('10:00:00', ['disque_libre_pct' => 5.0])];
    $sup->superviser($ws, 'srv-a', t('10:00:00'));
    $sup->superviser($ws, 'srv-b', t('10:00:00'));
    expect($cmd->commandes)->toBe(['pause', 'pause'])
        ->and(pausesDe($ws))->toEqualCanonicalizing(['sante:disque:srv-a', 'sante:disque:srv-b'])
        ->and($em->envoyees)->toBe([['disque', 'critique'], ['disque', 'critique']]);
});

test('M3 : un refus d’horloge (réponse signée = la passerelle vit) rétablit « serveur muet » ; les autres constats serveur restent ouverts', function () {
    $ws = espaceSante();
    [$sup, $sonde] = monter();
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 15.0])];
    $sup->superviser($ws, 'src', t('10:00:00'));
    $sup->superviser($ws, 'src', t('10:09:00')); // muet, attention
    expect(array_keys(constatsEn($ws)))->toBe(['disque', 'serveur_muet']);
    $sonde->file = [ServeurEnvoiRefuse::horloge()];
    $sup->superviser($ws, 'src', t('10:10:00'));
    expect(array_keys(constatsEn($ws)))->toBe(['disque', 'horloge']);
});

test('M2 : l’alerte « supervision éteinte alors que l’envoi réel est allumé » part au plus UNE fois par jour, pas toutes les 5 min', function () {
    Mail::fake();
    config(['alertes.actif' => true, 'alertes.email.destinataire' => 'resp@example.test', 'mail.from.address' => 'alertes@app.example.test', 'envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.sante.actif' => false]);
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-11-13 10:00:00'));
    CouplageSante::oublier();
    foreach (['10:00:00', '10:20:00', '11:00:00', '20:00:00'] as $h) { // hors de la fenêtre de regroupement du Socle (15 min)
        Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-11-13 ' . $h));
        $this->artisan('envoi:supervision-sante')->assertFailed(); // l'échec de la commande, lui, reste à chaque passage
    }
    Mail::assertSent(AlerteMail::class, 1);
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-11-14 10:30:00'));
    $this->artisan('envoi:supervision-sante')->assertFailed();
    Mail::assertSent(AlerteMail::class, 2);
    Carbon\Carbon::setTestNow();
    CouplageSante::oublier();
});

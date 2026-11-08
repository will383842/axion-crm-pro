<?php

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Sante\Constat;
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
    expect(pausesDe($ws))->toBe(['sante:disque'])->and($cmd->commandes)->toBe(['pause']);

    $sup->superviser($ws, 'src', t('10:05:00')); // tout est redevenu sain
    $sup->superviser($ws, 'src', t('10:10:00'));
    expect(pausesDe($ws))->toBe(['sante:disque'])->and($cmd->commandes)->toBe(['pause']); // ni levée, ni 2e commande
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
    expect($em->envoyees)->toBe([['horloge', 'critique']])->and(pausesDe($ws))->toBe(['sante:horloge']);
});

test('homme mort : serveur muet 15 min => une alerte critique et la pause ; avant, rien', function () {
    $ws = espaceSante();
    [$sup, $sonde, $em, $cmd] = monter();
    $sonde->file = [saines('10:00:00')]; // dernier battement bon à 10:00 ; ensuite la file est vide = muet
    foreach (['10:00:00', '10:05:00', '10:10:00', '10:14:59'] as $h) {
        $sup->superviser($ws, 'src', t($h));
    }
    expect($em->envoyees)->toBe([])->and(pausesDe($ws))->toBe([]);

    foreach (['10:15:00', '10:20:00', '10:25:00'] as $h) {
        $sup->superviser($ws, 'src', t($h));
    }
    expect($em->envoyees)->toBe([['serveur_muet', 'critique']])
        ->and(pausesDe($ws))->toBe(['sante:serveur_muet'])
        ->and($cmd->commandes)->toBe(['pause']);
});

test('homme mort : un serveur jamais joint compte le silence depuis le premier relevé', function () {
    $ws = espaceSante();
    [$sup, , $em] = monter();
    $sup->superviser($ws, 'src', t('10:00:00'));
    $sup->superviser($ws, 'src', t('10:14:00'));
    expect($em->envoyees)->toBe([]);
    $sup->superviser($ws, 'src', t('10:15:00'));
    expect($em->envoyees)->toBe([['serveur_muet', 'critique']]);
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
    expect(pausesDe($ws))->toBe(['sante:memoire'])->and($em->envoyees)->toBe([['memoire', 'critique']]);
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
    expect(pausesDe($a))->toBe(['sante:file_age'])->and(pausesDe($b))->toBe(['sante:file_age']);
});

test('la commande est INERTE tant que les trois interrupteurs ne sont pas vrais, et supervise chaque serveur actif sinon', function () {
    $ws = espaceSante();
    DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => 'src', 'url_base' => 'https://envoi.example.test']);
    $sonde = new SondeScriptee;
    $this->app->instance(SondeSante::class, $sonde);
    $this->app->instance(EmetteurAlerteSante::class, $em = new EmetteurEnregistreur);
    $sonde->file = [saines('10:00:00', ['disque_libre_pct' => 1.0, 'horloge_unix' => now()->getTimestamp()])];

    foreach ([['reel_actif'], ['k1_actif'], ['sante.actif']] as [$cle]) {
        config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.sante.actif' => true, 'envoi.' . $cle => false]);
        $this->artisan('envoi:supervision-sante')->assertSuccessful();
    }
    expect($sonde->file)->toHaveCount(1)->and($em->envoyees)->toBe([])->and(DB::table('envoi_sante_releves')->count())->toBe(0);

    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.sante.actif' => true]);
    $this->artisan('envoi:supervision-sante')->assertSuccessful();
    expect($em->envoyees)->toBe([['disque', 'critique']])->and(DB::table('envoi_sante_releves')->count())->toBe(1);
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

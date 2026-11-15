<?php

/**
 * Envoi L8-ESSAI (D10) — essai à blanc de bout en bout. Données fictives (`*.example.test`), faux serveur, aucune route, aucun envoi
 * réel : tout se passe dans une transaction annulée ; seule la ligne de résultat (`envoi_essais_blanc`, sans adresse) reste.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Orchestration\EssaiABlanc;
use App\Envoi\Orchestration\RapportEssai;
use App\Envoi\Orchestration\RefusAvantEssai;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Alertes\Alerte;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Tables dont le comptage ne doit pas bouger : toutes les `envoi_*` (sauf le résultat), contacts, personnes, suppressions, oppositions, sortant. */
function essaiComptages(): array
{
    $tables = collect(DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE 'envoi\\_%' AND table_name <> 'envoi_essais_blanc' ORDER BY 1"))
        ->pluck('table_name')->all();
    $tables = array_merge($tables, ['contacts', 'personnes', 'email_suppressions', 'opt_out', 'crm_outbound_events', 'companies', 'workspaces', 'tags', 'company_tag', 'fonctions_espace']);
    $n = [];
    foreach ($tables as $t) {
        if (Schema::hasTable($t)) {
            $n[$t] = DB::table($t)->count();
        }
    }

    return $n;
}

beforeEach(function () {
    $this->ws = F::espace('zz-essai');
    config([
        'envoi.reel_actif' => false, 'envoi.cle_empreinte' => 'cle-de-test-seulement', 'envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app',
        'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'],
    ]);
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('le parcours complet passe : toutes les étapes sont vertes et le résultat est écrit sans adresse', function () {
    $rapport = app(EssaiABlanc::class)->executer($this->ws, 'test-essai');

    expect($rapport)->toBeInstanceOf(RapportEssai::class)
        ->and(array_keys($rapport->etapes()))->toBe(EssaiABlanc::ETAPES)
        ->and($rapport->reussi())->toBeTrue()
        ->and(array_unique(array_values($rapport->etapes())))->toBe([true]);
    $ligne = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_essais_blanc')->where('workspace_id', $this->ws)->first());
    expect($ligne)->not->toBeNull()
        ->and((bool) $ligne->reussi)->toBeTrue()
        ->and($ligne->auteur)->toBe('test-essai')
        ->and((string) $ligne->etapes)->not->toContain('@');
});

test('après l\'essai, aucune ligne nouvelle hors envoi_essais_blanc (STOP, rebond, opposition et sortant compris)', function () {
    $avant = essaiComptages();

    app(EssaiABlanc::class)->executer($this->ws, 'test-essai');

    expect(essaiComptages())->toBe($avant)
        ->and(config('envoi.reel_actif'))->toBeFalse();
});

test('ni ServeurEnvoi du conteneur, ni RecepteurRetoursCrm, ni Alerte ne sont résolus ; aucun courriel, aucune écriture de cache ni fichier temporaire', function () {
    app()->bind(ServeurEnvoi::class, fn () => throw new RuntimeException('ServeurEnvoi du conteneur résolu'));
    app()->bind(RecepteurRetoursCrm::class, fn () => throw new RuntimeException('RecepteurRetoursCrm résolu'));
    app()->bind(Alerte::class, fn () => throw new RuntimeException('Alerte réelle résolue'));
    Mail::fake();
    $ecritures = 0;
    Event::listen(KeyWritten::class, function () use (&$ecritures): void {
        $ecritures++;
    });
    $temp = fn (): array => glob(sys_get_temp_dir() . '/imap-retour-*') ?: [];
    $avantTemp = $temp();

    $rapport = app(EssaiABlanc::class)->executer($this->ws, 'test-essai');

    expect($rapport->reussi())->toBeTrue()
        ->and($ecritures)->toBe(0)
        ->and($temp())->toBe($avantTemp);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('le faux serveur est construit APRÈS l\'ouverture de la transaction, sur un jour fictif lointain', function () {
    $niveauAvant = DB::transactionLevel();
    $vu = null;
    $essai = new EssaiABlanc(function () use (&$vu): FauxServeurEnvoi {
        $vu = DB::transactionLevel();

        return new FauxServeurEnvoi;
    });

    $essai->executer($this->ws, 'test-essai');

    expect($vu)->toBeGreaterThan($niveauAvant)
        ->and(EssaiABlanc::JOUR_FICTIF)->toBeGreaterThan((int) date('Y') + 20);
});

test('une étape rouge : rapport rouge, résultat rouge écrit, code de sortie non nul', function () {
    app()->bind(EssaiABlanc::class, fn () => new EssaiABlanc(fn () => (new FauxServeurEnvoi)->refuserDefinitivement(550)));

    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => $this->ws, '--auteur' => 'test-essai']);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->not->toContain('@');
    $ligne = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_essais_blanc')->where('workspace_id', $this->ws)->first());
    expect((bool) $ligne->reussi)->toBeFalse();
});

test('la commande réussit en code 0, sans adresse en sortie, et aucune route ne l\'expose', function () {
    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => $this->ws, '--auteur' => 'test-essai']);

    expect($code)->toBe(0)->and(Artisan::output())->not->toContain('@');
    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toContain('essai');
    }
});

test('un espace inconnu : échec propre, aucun résultat écrit', function () {
    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => '00000000-0000-4000-8000-000000000000']);

    expect($code)->not->toBe(0)->and(DB::table('envoi_essais_blanc')->count())->toBe(0);
});

/** Vraies inscriptions actives, échues, dans l'espace réel : dont une sur une adresse témoin. Retourne [séquence, ids]. */
function essaiPoserVraiesInscriptions(string $ws, int $nombre): array
{
    $t = new DateTimeImmutable('2026-01-05 10:00:00', new DateTimeZone('Europe/Paris'));
    WorkspaceContext::run($ws, function () use ($ws, $nombre, $t, &$sid, &$ids): void {
        $sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Vraie séquence', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Vrai sujet', 'corps' => 'Vrai corps']);
        DB::table('fonctions_espace')->updateOrInsert(['espace_id' => $ws, 'fonction' => 'segment.federations'], ['ouverte' => true, 'updated_at' => now()]);
        $slug = FichesProtegees::TAG_FEDERATIONS;
        $tag = DB::table('tags')->insertGetId(['workspace_id' => $ws, 'slug' => $slug, 'name' => $slug, 'category' => 'custom', 'kind' => 'auto', 'rules' => '[]', 'is_locked' => false, 'created_at' => now(), 'updated_at' => now()]);
        $inscriptions = new Inscriptions(new LecteurFicheDb);
        $ids = [];
        for ($i = 0; $i < $nombre; $i++) {
            $adresse = "vrai-{$i}@prospect.example.test";
            $entreprise = (int) DB::table('companies')->insertGetId([
                'workspace_id' => $ws, 'denomination' => 'Vraie Entreprise ' . $i, 'discovery_source' => 'insee', 'siren' => '93' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'country_code' => 'FR', 'first_info_at' => '2025-12-01', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('company_tag')->insert(['company_id' => $entreprise, 'tag_id' => $tag, 'workspace_id' => $ws, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
            $contact = (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $entreprise, 'last_name' => 'Vrai', 'email' => $adresse, 'email_status' => 'valid', 'created_at' => '2025-12-01', 'first_info_at' => '2025-12-01']);
            DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($adresse), 'verdict' => 'valide', 'verifie_le' => '2025-12-10', 'source' => 'essai']);
            if ($i === 0) {
                DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::pourDecision($adresse), 'libelle' => 'vrai témoin', 'fournisseur' => DepotSocleDb::FOURNISSEURS_TEMOINS[0]]);
            }
            $ids[] = $inscriptions->inscrire($ws, $sid, $contact, $t->modify('-' . ($nombre - $i) . ' minutes'));
        }
    });

    return [$sid, $ids];
}

function essaiEtatVraiesInscriptions(string $ws): array
{
    return WorkspaceContext::run($ws, fn (): array => [
        'inscriptions' => DB::table('envoi_inscriptions')->where('workspace_id', $ws)->orderBy('id')->get()->toJson(),
        'messages' => DB::table('envoi_messages')->where('workspace_id', $ws)->count(),
        'envoyes' => DB::table('envoi_messages_envoyes')->where('workspace_id', $ws)->count(),
        'temoins' => DB::table('envoi_temoins')->where('workspace_id', $ws)->count(),
    ]);
}

test('de VRAIES inscriptions actives échues (dont une sur une adresse témoin, 100 et plus) ne sont ni composées, ni modifiées, ni reportées ; l\'essai reste vert', function () {
    [, $ids] = essaiPoserVraiesInscriptions($this->ws, 105);
    $avant = essaiEtatVraiesInscriptions($this->ws);
    expect(count($ids))->toBe(105);

    $rapport = app(EssaiABlanc::class)->executer($this->ws, 'test-essai');

    expect($rapport->reussi())->toBeTrue()
        ->and(essaiEtatVraiesInscriptions($this->ws))->toBe($avant);
    $ligne = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_essais_blanc')->where('workspace_id', $this->ws)->first());
    expect((bool) $ligne->reussi)->toBeTrue();
});

test('une exception non rattrapée : la commande échoue proprement (code non nul, message sans valeur) et une ligne rouge est écrite', function () {
    app()->bind(EssaiABlanc::class, fn () => new EssaiABlanc(function (): FauxServeurEnvoi {
        throw new RuntimeException('secret-valeur@client.example.test');
    }));

    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => $this->ws, '--auteur' => 'test-essai']);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->not->toContain('@')->and(Artisan::output())->not->toContain('secret-valeur');
    $ligne = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_essais_blanc')->where('workspace_id', $this->ws)->first());
    expect((bool) $ligne->reussi)->toBeFalse()
        ->and((string) $ligne->etapes)->not->toContain('@')->and((string) $ligne->etapes)->not->toContain('secret-valeur')
        ->and((string) $ligne->etapes)->toContain('inscription');
});

test('un auteur contenant un @ est refusé AVANT l\'essai : aucun résultat, aucun parcours', function () {
    $vu = false;
    app()->bind(EssaiABlanc::class, fn () => new EssaiABlanc(function () use (&$vu): FauxServeurEnvoi {
        $vu = true;

        return new FauxServeurEnvoi;
    }));

    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => $this->ws, '--auteur' => 'quelquun@example.test']);

    expect($code)->not->toBe(0)->and($vu)->toBeFalse()
        ->and(DB::table('envoi_essais_blanc')->count())->toBe(0);
});

test('les deux contrôles préalables lèvent une exception dédiée, et la commande n\'affiche que son message', function () {
    expect(fn () => app(EssaiABlanc::class)->executer((string) Str::uuid(), 'test-essai'))->toThrow(RefusAvantEssai::class, 'Espace inconnu.');
    expect(fn () => app(EssaiABlanc::class)->executer($this->ws, 'x@client.example.test'))->toThrow(RefusAvantEssai::class);

    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => (string) Str::uuid(), '--auteur' => 'test-essai']);
    expect($code)->not->toBe(0)->and(Artisan::output())->toContain('Espace inconnu.')->toContain('Aucun essai lancé.');
    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => $this->ws, '--auteur' => 'x@client.example.test']);
    expect($code)->not->toBe(0)->and(Artisan::output())->toContain('Auteur invalide')->toContain('Aucun essai lancé.');
});

test('une InvalidArgumentException venue du parcours n\'est PAS un refus avant lancement : message fixe, jamais son texte', function () {
    app()->bind(EssaiABlanc::class, fn () => new EssaiABlanc(function (): FauxServeurEnvoi {
        throw new InvalidArgumentException('secret-argument@client.example.test');
    }));

    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => $this->ws, '--auteur' => 'test-essai']);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('ESSAI ROUGE')->not->toContain('secret-argument')->not->toContain('Aucun essai lancé.');
    $ligne = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_essais_blanc')->where('workspace_id', $this->ws)->first());
    expect((bool) $ligne->reussi)->toBeFalse();
});

test('un serveur qui n\'est pas un FauxServeurEnvoi est refusé (instanceof), essai rouge', function () {
    app()->bind(EssaiABlanc::class, fn () => new EssaiABlanc(fn () => new stdClass));

    expect(fn () => app(EssaiABlanc::class)->executer($this->ws, 'test-essai'))->toThrow(LogicException::class);

    // L'essai rouge laisse une trace durable : une ligne reussi = false, rattachée à l'espace demandé.
    $lignes = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_essais_blanc')->where('workspace_id', $this->ws)->get());
    expect($lignes)->toHaveCount(1)->and((bool) $lignes[0]->reussi)->toBeFalse();
});

test('la commande documente son autorisation (console locale, aucune route)', function () {
    $src = (string) file_get_contents(app_path('Console/Commands/EnvoiEssaiABlanc.php'));
    expect($src)->toContain('AUTORISATION');
});

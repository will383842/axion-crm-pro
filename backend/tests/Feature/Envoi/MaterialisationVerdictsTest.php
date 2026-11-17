<?php

use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\Dns\ResultatDns;
use App\Envoi\Campagnes\ConstructionSequence;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Campagnes\MaterialisationRefusee;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Verdicts\EcrivainVerdicts;
use App\Envoi\Verdicts\PreparationVerdicts;
use App\Envoi\Verdicts\VerdictAdresse;
use App\Envoi\Verdicts\VerificateurAdresse;
use App\Envoi\Verdicts\VerificateurInterne;
use App\Services\Email\MxEmailValidator;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

/*
 * Lot B3-A — la matérialisation vérifie (et écrit) les verdicts d'adresse AVANT d'inscrire : invalide et risque écartés et comptés,
 * valide et indéterminé inscrits (la porte reste seule juge). Joué sous le rôle applicatif ET sous le propriétaire.
 * Données fictives (`*.example.test`), DNS simulé, aucun réseau, aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const MVD_MODES = ['app', 'proprio'];

function mvdLundi(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-16 07:00', new DateTimeZone('Europe/Paris'));
}

function mvdEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Mvd', 'slug' => 'mvd-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/**
 * Campagne « programmee » sur une liste manuelle : une entreprise et un contact par adresse.
 *
 * @param  list<string>  $emails
 * @return array{id: string, contacts: list<int>}
 */
function mvdProgrammee(string $ws, array $emails): array
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . Str::random(6)]);
    $contacts = [];
    foreach ($emails as $i => $email) {
        $e = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => F::siren(), 'denomination' => 'Société fictive', 'discovery_source' => 'insee']);
        $contacts[] = (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $i, 'email' => $email]);
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $e, 'origine' => 'coche']);
    }
    $service = app(ServiceMessagesTypes::class);
    $etapes = [];
    foreach ([0, 3] as $i => $d) {
        $m = $service->creer($ws, 'Etape ' . ($i + 1), 'Objet ' . ($i + 1), 'Corps ' . ($i + 1), 'le fondateur');
        $service->valider($ws, $m['id'], 1);
        $etapes[] = ['etape' => $i + 1, 'message_id' => $m['id'], 'delai_jours' => $d];
    }
    $depot = app(DepotCampagnes::class);
    $id = $depot->creer(
        $ws,
        'Campagne fictive',
        ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Liste fictive'],
        [],
        2,
        ['contenu' => ['sequence' => $etapes], 'etapes_faites' => []],
        'le fondateur',
    );
    $depot->transitionner($ws, $id, 'a_valider', 1, 'le fondateur', 'test', 'cle-mvd-' . Str::random(12));
    DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'programmee', 'version' => 3]);
    DB::table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $id, 'transition' => 'a_valider>programmee', 'auteur' => 'le fondateur',
        'motif' => 'test', 'cle_idempotence' => 'cle-j-' . Str::random(12), 'cree_le' => DB::raw("now() - interval '10 minutes'")]);

    return ['id' => $id, 'contacts' => $contacts];
}

function mvdService(?PreparationVerdicts $verdicts = null): MaterialisationCampagne
{
    return new MaterialisationCampagne(
        app(DepotCampagnes::class),
        new SelectionDestinataires,
        app(ConstructionSequence::class),
        app(DepotSocle::class),
        MaterialisationCampagne::TAILLE_LOT,
        MaterialisationCampagne::LOTS_PAR_APPEL,
        $verdicts,
    );
}

/** Joue $f sous le mode : « app » = rôle applicatif non propriétaire sous RLS. */
function mvdSous(string $mode, Closure $f): mixed
{
    MaterialisationCampagne::$niveauTolere = DB::transactionLevel();
    if ($mode === 'proprio') {
        return $f();
    }
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/** Vérificateur réel, sauf pour les adresses de $risque (verdict « risque », motif « generique »). */
function mvdVerificateurRisque(array $risque): VerificateurAdresse
{
    return new class($risque) implements VerificateurAdresse
    {
        public function __construct(private readonly array $risque) {}

        public function source(): string
        {
            return VerificateurInterne::SOURCE;
        }

        public function verifier(string $ws, array $adresses, DateTimeImmutable $maintenant): array
        {
            $sortie = app(VerificateurInterne::class)->verifier($ws, $adresses, $maintenant);
            foreach ($adresses as $a) {
                if (in_array($a, $this->risque, true)) {
                    $sortie[ListeSuppression::empreinte($a)] = new VerdictAdresse(VerdictAdresse::RISQUE, 'generique', $maintenant);
                }
            }

            return $sortie;
        }
    };
}

beforeEach(function () {
    config(['envoi.plafond_jour' => 20]);
    Http::preventStrayRequests();
    $this->ws = mvdEspace();
});

afterEach(function () {
    MaterialisationCampagne::$niveauTolere = 0;
});

test('B3-A 1 tri : 2 valides + 1 indéterminé inscrits ; 1 jetable (invalide) et 1 risque écartés et comptés ; membres - retenus exact', function () {
    foreach (MVD_MODES as $mode) {
        $ws = mvdEspace();
        $jetable = 'prenom@' . MxEmailValidator::DISPOSABLE_DOMAINS[0];
        $emails = ['un@ok.example.test', 'deux@ok.example.test', $jetable, 'risque@ok.example.test', 'trois@muet.example.test'];
        app()->instance(ResolveurDns::class, $dns = new ResolveurDnsSimule(['muet.example.test' => ResultatDns::INDETERMINE]));
        $p = mvdProgrammee($ws, $emails);
        $verdicts = new PreparationVerdicts(mvdVerificateurRisque(['risque@ok.example.test']), app(EcrivainVerdicts::class));
        $r = mvdSous($mode, fn () => mvdService($verdicts)->materialiser($ws, $p['id'], mvdLundi()));
        $c = $r->compteurs;
        expect($r->etat)->toBe('terminee', $mode)->and($r->inscrits)->toBe(3)
            ->and($c['adresse_invalide'])->toBe(1)->and($c['adresse_risquee'])->toBe(1)
            ->and($c['membres'])->toBe(5)->and($c['retenus'])->toBe(3)->and($c['membres'] - $c['retenus'])->toBe(2)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(3);
        $inscrits = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->pluck('contact_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        expect($inscrits)->toBe([$p['contacts'][0], $p['contacts'][1], $p['contacts'][4]]);
    }
});

test('B3-A 2 les verdicts sont écrits AVANT l\'inscription : empreintes seules, source interne-v1', function () {
    foreach (MVD_MODES as $mode) {
        $ws = mvdEspace();
        $jetable = 'prenom@' . MxEmailValidator::DISPOSABLE_DOMAINS[0];
        app()->instance(ResolveurDns::class, new ResolveurDnsSimule(['muet.example.test' => ResultatDns::INDETERMINE]));
        $p = mvdProgrammee($ws, ['un@ok.example.test', 'deux@ok.example.test', $jetable, 'trois@muet.example.test']);
        mvdSous($mode, fn () => mvdService()->materialiser($ws, $p['id'], mvdLundi()));
        $lignes = DB::table('envoi_verdicts_adresse')->where('workspace_id', $ws)->get();
        // le domaine muet reste sans verdict (rien n'est écrit pour un indéterminé) : 3 lignes (2 valides + 1 jetable)
        expect($lignes)->toHaveCount(3, $mode)->and($lignes->pluck('source')->unique()->all())->toBe(['interne-v1']);
        foreach ($lignes as $l) {
            expect(trim((string) $l->empreinte_adresse))->toMatch('/^[0-9a-f]{64}$/');
        }
        expect(json_encode($lignes))->not->toContain('@');
        expect($lignes->pluck('verdict')->sort()->values()->all())->toBe(['invalide', 'valide', 'valide']);
    }
});

test('B3-A 3 aucun verrou d\'étalement tenu pendant le DNS', function () {
    foreach (MVD_MODES as $mode) {
        $ws = mvdEspace();
        $dns = new ResolveurDnsSimule;
        $obtenu = [];
        $dns->pendant = function () use ($ws, &$obtenu): void {
            $c = config('database.connections.' . config('database.default'));
            $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
            $q = $pdo->prepare('SELECT pg_try_advisory_lock(hashtextextended(?, 0))');
            $q->execute(['envoi_etalement:' . $ws]);
            $obtenu[] = (bool) $q->fetchColumn();
            $pdo->query('SELECT pg_advisory_unlock_all()');
        };
        app()->instance(ResolveurDns::class, $dns);
        $p = mvdProgrammee($ws, ["un@dns-{$mode}.example.test", "deux@dns-{$mode}.example.test"]);
        mvdSous($mode, fn () => mvdService()->materialiser($ws, $p['id'], mvdLundi()));
        expect($obtenu)->not->toBe([], $mode)->and(array_unique($obtenu))->toBe([true]);
    }
});

test('B3-A 4 schéma incomplet : verdicts_indisponibles (réessayable), 0 inscription, curseur inchangé', function () {
    foreach (MVD_MODES as $mode) {
        $ws = mvdEspace();
        app()->instance(ResolveurDns::class, new ResolveurDnsSimule);
        $p = mvdProgrammee($ws, ['un@ok.example.test', 'deux@ok.example.test']);
        DB::statement('ALTER TABLE envoi_verdicts_adresse RENAME COLUMN motif TO motif_absent'); // schéma incomplet simulé (DDL transactionnel, annulé en fin de test)
        $refus = null;
        DB::statement('SAVEPOINT mvd_refus');
        try {
            mvdSous($mode, fn () => mvdService()->materialiser($ws, $p['id'], mvdLundi()));
        } catch (MaterialisationRefusee $e) {
            $refus = $e;
            DB::statement('ROLLBACK TO SAVEPOINT mvd_refus');
            DB::statement('RESET ROLE');
        }
        DB::statement('ALTER TABLE envoi_verdicts_adresse RENAME COLUMN motif_absent TO motif');
        expect($refus)->not->toBeNull($mode)->and($refus->motif)->toBe('verdicts_indisponibles')->and($refus->reessayable)->toBeTrue()
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(0)
            ->and((int) DB::table('envoi_campagnes_materialisations')->where('campagne_id', $p['id'])->value('curseur'))->toBe(0);
    }
});

test('B3-A 5 exception du vérificateur : verdicts_indisponibles, rien inscrit, curseur inchangé', function () {
    foreach (MVD_MODES as $mode) {
        $ws = mvdEspace();
        $p = mvdProgrammee($ws, ['un@ok.example.test']);
        $casse = new class implements VerificateurAdresse
        {
            public function source(): string
            {
                return 'interne-v1';
            }

            public function verifier(string $ws, array $adresses, DateTimeImmutable $maintenant): array
            {
                throw new RuntimeException('panne simulée');
            }
        };
        $refus = null;
        DB::statement('SAVEPOINT mvd_refus');
        try {
            mvdSous($mode, fn () => mvdService(new PreparationVerdicts($casse, app(EcrivainVerdicts::class)))->materialiser($ws, $p['id'], mvdLundi()));
        } catch (MaterialisationRefusee $e) {
            $refus = $e;
            DB::statement('ROLLBACK TO SAVEPOINT mvd_refus');
            DB::statement('RESET ROLE');
        }
        expect($refus?->motif)->toBe('verdicts_indisponibles', $mode)->and($refus->reessayable)->toBeTrue()
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(0)
            ->and((int) DB::table('envoi_campagnes_materialisations')->where('campagne_id', $p['id'])->value('curseur'))->toBe(0);
    }
});

test('B3-A 6 cloisonnement : les contacts de l\'espace B passés à l\'espace A sont ignorés (aucun verdict écrit pour B)', function () {
    foreach (MVD_MODES as $mode) {
        $a = mvdEspace();
        $b = mvdEspace();
        app()->instance(ResolveurDns::class, new ResolveurDnsSimule);
        $pb = mvdProgrammee($b, ['autre@espace-b.example.test']);
        $res = mvdSous($mode, fn () => WorkspaceContext::run($a, fn () => app(PreparationVerdicts::class)->pourContacts($a, $pb['contacts'], mvdLundi())));
        expect($res)->toBe([], $mode)->and(DB::table('envoi_verdicts_adresse')->where('workspace_id', $b)->count())->toBe(0)
            ->and(DB::table('envoi_verdicts_adresse')->where('workspace_id', $a)->count())->toBe(0);
    }
});

test('B3-A 7 une personne opposée n\'est ni vérifiée ni inscrite : 0 requête DNS pour son domaine', function () {
    foreach (MVD_MODES as $mode) {
        $ws = mvdEspace();
        app()->instance(ResolveurDns::class, $dns = new ResolveurDnsSimule);
        $p = mvdProgrammee($ws, ["opposee@opposition-{$mode}.example.test", "ok@libre-{$mode}.example.test"]);
        $lecteur = fn (string $w, array $c): array => array_map(
            fn (array $x): array => ['opposee' => str_ends_with((string) $x['email'], '@opposition-' . $mode . '.example.test'), 'motifs' => []],
            $c,
        );
        $service = new MaterialisationCampagne(app(DepotCampagnes::class), new SelectionDestinataires($lecteur), app(ConstructionSequence::class), app(DepotSocle::class));
        $r = mvdSous($mode, fn () => $service->materialiser($ws, $p['id'], mvdLundi()));
        expect($r->inscrits)->toBe(1, $mode)->and($r->compteurs['opposition'])->toBe(1)
            ->and($dns->demandes)->not->toContain("opposition-{$mode}.example.test")->and($dns->demandes)->toContain("libre-{$mode}.example.test")
            ->and(DB::table('envoi_verdicts_adresse')->where('workspace_id', $ws)->where('empreinte_adresse', ListeSuppression::empreinte("opposee@opposition-{$mode}.example.test"))->count())->toBe(0);
    }
});

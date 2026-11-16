<?php

/**
 * K17-AIG (D11) — aiguilleur par domaine. AUCUN réseau, AUCUN serveur réel : les « adaptateurs réels » sont des DOUBLES de test
 * (fabriques comptées) ; `ENVOI_REEL_ACTIF` reste faux (la porte est une fermeture du test). Domaines en `*.example.test`.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ToutArreter;
use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\RenvoiParMemeCle;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Exceptions\InjectionIncertaine;
use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DecisionFeuVert;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Envoi\Infrastructure\LectureInfrastructure;
use App\Envoi\JourEnvoi;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sante\DepotSante;
use App\Envoi\Sequence\ComposeurSequence;
use App\Envoi\Sequence\DemandeComposition;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ParametresExpedition;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Serveur\AiguilleurServeurEnvoi;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

/** Double d'adaptateur réel : compte chaque appel, ne touche jamais le réseau. */
final class AigDouble implements ServeurEnvoi
{
    /** @var array<string, int> */
    public array $appels = [];

    public bool $sante = true;

    public function __construct(public readonly string $nom = 'kumo') {}

    private function noter(string $m): void
    {
        $this->appels[$m] = ($this->appels[$m] ?? 0) + 1;
    }

    public function total(): int
    {
        return array_sum($this->appels);
    }

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        $this->noter('injecter');

        return ResultatInjection::accepte('double-' . $this->nom);
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        $this->noter('etatEnvoi');

        return EtatMessage::Inconnu;
    }

    public function pause(): void
    {
        $this->noter('pause');
    }

    public function reprise(): void
    {
        $this->noter('reprise');
    }

    public function modeTemoins(): bool
    {
        return true;
    }

    public function etat(): EtatServeur
    {
        $this->noter('etat');

        return new EtatServeur(false, 7);
    }

    public function sante(): SanteServeur
    {
        $this->noter('sante');

        return new SanteServeur($this->sante, 'double');
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        $this->noter('lireEvenements');

        return new LotEvenements([], 'curseur-double');
    }
}

/** Liste simulée (aucun serveur réel au palier 0) : tout vert sauf les codes passés en rouge. */
function aigSimuler(array $rouges = []): void
{
    app()->bind(ListeFeuVert::class, fn () => new class(app(DepotPreuves::class), app(DepotSante::class), $rouges) extends ListeFeuVert
    {
        /** @param list<string> $rouges */
        public function __construct(DepotPreuves $p, DepotSante $s, private readonly array $rouges)
        {
            parent::__construct($p, $s);
        }

        public function points(string $ws): array
        {
            $r = [];
            foreach (CatalogueManuels::POINTS as $code => $c) {
                $fait = $code === 'auto.reel_actif_faux' ? config('envoi.reel_actif') === false : ! in_array($code, $this->rouges, true);
                $r[] = new PointFeuVert($code, $c['libelle'], str_starts_with($code, 'auto.'), $fait, 'simulé', $c['source'], $c['groupe'], $fait ? 'fait' : 'a_faire');
            }

            return $r;
        }
    });
}

/** Décision consignée par DecisionFeuVert lui-même (jamais posée à la main). */
function aigDecider(string $ws, string $decision): void
{
    $liste = app(ListeFeuVert::class);
    $r = app(DecisionFeuVert::class)->decider($ws, $decision, 'Décision de test après relecture, réf. exemple-01.', 'responsable', $liste->empreinte($ws, $liste->points($ws)));
    expect($r['statut'])->toBe(200);
}

function aigDns(string $ws, string $domaine, bool $pret = true, bool $pause = false, ?DateTimeImmutable $le = null): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_controles_dns')->insert([
        'workspace_id' => $ws, 'domaine' => $domaine, 'controle_le' => ($le ?? new DateTimeImmutable)->format('c'), 'pret' => $pret, 'pause' => $pause,
    ]));
}

function aigAdaptateur(string $ws, string $domaine, string $adaptateur): void
{
    WorkspaceContext::run($ws, fn () => DB::selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$ws, $domaine, $adaptateur, 'auteur-test']));
}

function aigInjectionLocale(string $ws, string $adaptateur): string
{
    $id = (string) Str::uuid();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_injections_locales')->insert(['workspace_id' => $ws, 'id_injection' => $id, 'cle_idempotence' => 'cle-' . $id, 'adaptateur' => $adaptateur]));

    return $id;
}

function aigMessage(string $ws, string $domaine, string $cle = 'cle-1'): MessageAEnvoyer
{
    return new MessageAEnvoyer($ws, 'dest@client.example.test', 'from@' . $domaine, 'Sujet', 'Corps', $cle);
}

/**
 * @return array{ws: string, domaine: string, faux: FauxServeurEnvoi, kumo: AigDouble, smtp: AigDouble, construits: ArrayObject<int, string>, porte: ArrayObject<int, bool>, aig: AiguilleurServeurEnvoi}
 */
function aigMontage(string $adaptateur = 'kumo', bool $porte = true, bool $accorde = true, bool $dns = true): array
{
    $ws = F::espace('zz-aig');
    $domaine = 'aig-' . Str::lower(Str::random(6)) . '.example.test';
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $domaine, 'achete_le' => '2026-01-01']);
    if ($adaptateur !== 'faux') {
        aigAdaptateur($ws, $domaine, $adaptateur);
    }
    aigSimuler();
    if ($accorde) {
        aigDecider($ws, 'accorde');
    }
    if ($dns) {
        aigDns($ws, $domaine);
    }
    $construits = new ArrayObject;
    $etat = new ArrayObject([$porte]);
    $doubles = ['kumo' => new AigDouble('kumo'), 'smtp' => new AigDouble('smtp')];
    $fabriques = [];
    foreach ($doubles as $nom => $d) {
        $fabriques[$nom] = function () use ($nom, $d, $construits): ServeurEnvoi {
            $construits[] = $nom;

            return $d;
        };
    }
    $faux = new FauxServeurEnvoi;
    WorkspaceContext::set($ws);

    return [
        'ws' => $ws, 'domaine' => $domaine, 'faux' => $faux, 'kumo' => $doubles['kumo'], 'smtp' => $doubles['smtp'], 'construits' => $construits, 'porte' => $etat,
        'aig' => new AiguilleurServeurEnvoi($faux, $fabriques, fn (): bool => $etat[0], fn (): ListeFeuVert => app(ListeFeuVert::class)),
    ];
}

function aigRaison(Closure $f): EnvoiRefuse
{
    try {
        $f();
    } catch (EnvoiRefuse $e) {
        return $e;
    }
    throw new RuntimeException('EnvoiRefuse attendue.');
}

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

// ── injecter : les trois conditions ──────────────────────────────────────────

test('positif : porte ouverte + décision « accorde » consignée par DecisionFeuVert + DNS du jour prêt ⇒ le double est appelé UNE fois (kumo et smtp)', function (string $a) {
    $m = aigMontage($a);
    expect($m['aig']->injecter(aigMessage($m['ws'], $m['domaine']))->accepte)->toBeTrue()
        ->and($m[$a]->appels)->toBe(['injecter' => 1])
        ->and($m['construits']->getArrayCopy())->toBe([$a])
        ->and($m['faux']->appels)->toBe(0);
})->with(['kumo', 'smtp']);

test('domaine kumo + porte fermée ⇒ refus ATTENTE, aucun constructeur appelé (témoin : porte ouverte ⇒ le constructeur est appelé)', function () {
    $m = aigMontage('kumo', false);
    $e = aigRaison(fn () => $m['aig']->injecter(aigMessage($m['ws'], $m['domaine'])));
    expect($e->estAttente())->toBeTrue()->and($e)->not->toBeInstanceOf(ServeurEnvoiRefuse::class)->and($e->getMessage())->toContain('envoi_reel_inactif')
        ->and($m['construits']->getArrayCopy())->toBe([])->and($m['faux']->appels)->toBe(0);
    $m['porte'][0] = true; // témoin : sans la porte fermée, l'adaptateur est bien construit
    $m['aig']->injecter(aigMessage($m['ws'], $m['domaine'], 'cle-2'));
    expect($m['construits']->getArrayCopy())->toBe(['kumo']);
});

test('porte ouverte + feu vert non accordé (aucune décision, « retire », ou bloquant rouge maintenant) ⇒ refus, aucun constructeur', function (string $cas) {
    $m = aigMontage('kumo', true, false);
    if ($cas === 'retire') {
        aigDecider($m['ws'], 'retire');
    } elseif ($cas === 'bloquant') {
        aigDecider($m['ws'], 'accorde');
        aigSimuler(['auto.essai_a_blanc_recent']); // rouge maintenant, après la décision
    }
    $e = aigRaison(fn () => $m['aig']->injecter(aigMessage($m['ws'], $m['domaine'])));
    expect($e->estAttente())->toBeTrue()->and($e->getMessage())->toContain('feu_vert_non_accorde')
        ->and($m['construits']->getArrayCopy())->toBe([])->and($m['kumo']->total())->toBe(0);
})->with(['aucune', 'retire', 'bloquant']);

test('auto.reel_actif_faux rouge n\'empêche PAS l\'adaptateur (le feu vert ne touche jamais le drapeau d\'exploitation)', function () {
    $m = aigMontage();
    config(['envoi.reel_actif' => true]); // le point auto.reel_actif_faux est rouge dans la liste simulée
    expect($m['aig']->injecter(aigMessage($m['ws'], $m['domaine']))->accepte)->toBeTrue();
});

test('porte ouverte, feu vert accordé, DNS non vert ⇒ refus pour la raison DNS (absent, pas prêt, en pause, daté d\'hier, dernière ligne rouge)', function (string $cas) {
    $m = aigMontage('kumo', true, true, false);
    $hier = JourEnvoi::de()->modify('-1 hour')->setTime(12, 0)->modify('-1 day');
    match ($cas) {
        'pret_faux' => aigDns($m['ws'], $m['domaine'], false),
        'pause' => aigDns($m['ws'], $m['domaine'], true, true),
        'hier' => aigDns($m['ws'], $m['domaine'], true, false, $hier),
        'derniere_rouge' => (function () use ($m): void {
            aigDns($m['ws'], $m['domaine'], true, false, new DateTimeImmutable('-2 minutes'));
            aigDns($m['ws'], $m['domaine'], false);
        })(),
        default => null,
    };
    $e = aigRaison(fn () => $m['aig']->injecter(aigMessage($m['ws'], $m['domaine'])));
    expect($e->estAttente())->toBeTrue()->and($e->getMessage())->toContain('dns_non_vert')->and($m['construits']->getArrayCopy())->toBe([]);
})->with(['absent', 'pret_faux', 'pause', 'hier', 'derniere_rouge']);

test('domaine inconnu de envoi_domaines ⇒ refus ; domaine de l\'autre espace ⇒ refus', function () {
    $m = aigMontage();
    $e = aigRaison(fn () => $m['aig']->injecter(aigMessage($m['ws'], 'inconnu.example.test')));
    expect($e->estAttente())->toBeTrue()->and($e->getMessage())->toContain('domaine_inconnu');
    $autre = F::espace('zz-aig-autre');
    WorkspaceContext::set($autre);
    expect(aigRaison(fn () => $m['aig']->injecter(aigMessage($autre, $m['domaine'])))->getMessage())->toContain('domaine_inconnu')
        ->and($m['kumo']->total())->toBe(0);
});

test('domaine faux + porte ouverte ⇒ refus domaine_sur_faux_serveur ; aucun repli silencieux sur le faux serveur', function () {
    $m = aigMontage('faux');
    $e = aigRaison(fn () => $m['aig']->injecter(aigMessage($m['ws'], $m['domaine'])));
    expect($e->estAttente())->toBeTrue()->and($e->getMessage())->toContain('domaine_sur_faux_serveur')->and($m['faux']->appels)->toBe(0);
});

test('garde (témoin) : domaine faux + porte fermée ⇒ faux serveur, aucun adaptateur réel construit', function () {
    $m = aigMontage('faux', false);
    expect($m['aig']->injecter(aigMessage($m['ws'], $m['domaine']))->accepte)->toBeTrue()
        ->and($m['faux']->appels)->toBe(1)->and($m['construits']->getArrayCopy())->toBe([]);
});

test('AiguilleurServeurEnvoi n\'implémente PAS RenvoiParMemeCle ; rejeu d\'une clé a_verifier (smtp ou kumo) ⇒ EnvoiRefuse issue incertaine, aucun adaptateur rappelé', function (string $a) {
    $m = aigMontage($a);
    expect($m['aig'])->not->toBeInstanceOf(RenvoiParMemeCle::class);
    (new DepotSocleDb)->eteindreTemoins($m['ws'], 'test-aig');
    $coupure = new class($m[$a]) implements ServeurEnvoi
    {
        public function __construct(public AigDouble $d) {}

        public function injecter(MessageAEnvoyer $message): ResultatInjection
        {
            $this->d->appels['injecter'] = ($this->d->appels['injecter'] ?? 0) + 1;
            throw new RuntimeException('coupure après envoi possible');
        }

        public function etatEnvoi(string $i): EtatMessage
        {
            return EtatMessage::Inconnu;
        }

        public function pause(): void {}

        public function reprise(): void {}

        public function modeTemoins(): bool
        {
            return true;
        }

        public function etat(): EtatServeur
        {
            return new EtatServeur(false, 0);
        }

        public function sante(): SanteServeur
        {
            return new SanteServeur(true, '');
        }

        public function lireEvenements(?string $c, int $l = 100): LotEvenements
        {
            return new LotEvenements([], $c);
        }
    };
    $aig = new AiguilleurServeurEnvoi($m['faux'], [$a => fn () => $coupure], fn () => true, fn () => app(ListeFeuVert::class));
    $p = new PasserelleLocale($aig, new DepotSocleDb, true, 300, null, $m['ws'], 300, fn () => WorkspaceContext::current());
    $msg = aigMessage($m['ws'], $m['domaine'], 'rejeu-1');
    expect(fn () => $p->injecter($msg))->toThrow(InjectionIncertaine::class);
    expect(fn () => $p->injecter($msg))->toThrow(EnvoiRefuse::class)->and($m[$a]->appels['injecter'])->toBe(1);
})->with(['kumo', 'smtp']);

test('11 cycles refusés (feu vert non accordé, DNS non vert ; témoin : tout vert) ⇒ inscription toujours active, reports inchangé, adaptateur jamais appelé', function (string $cas) {
    $m = aigMontage('kumo', true, $cas !== 'feu_vert', $cas !== 'dns');
    $ws = $m['ws'];
    DB::table('envoi_domaines')->where('workspace_id', $ws)->update(['dmarc_verifie_le' => now(), 'spf_verifie_le' => now(), 'dkim_verifie_le' => now()]);
    (new DepotSocleDb)->eteindreTemoins($ws, 'test-aig');
    $lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    $maintenant = $lundi;
    // Le DNS « du jour » suit l'horloge de la passerelle : l'aiguilleur lit le jour de la même horloge.
    $aig = new AiguilleurServeurEnvoi($m['faux'], ['kumo' => fn () => $m['kumo']], fn () => true, fn () => app(ListeFeuVert::class), function () use (&$maintenant) {
        return $maintenant;
    });
    $sid = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    $fiche = aigFiche($ws, $lundi);
    $ins = (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $sid, $fiche, $maintenant);
    $composeur = new class implements ComposeurSequence
    {
        public function composer(DemandeComposition $d): string
        {
            return "Message-ID: {$d->messageId}\r\nSubject: {$d->sujet}\r\n\r\n{$d->corps}";
        }
    };
    $dnsLe = $maintenant;
    if ($cas === 'dns') {
        // Pas de contrôle DNS du jour (témoin : un contrôle vert daté de l'horloge du test rend l'adaptateur atteignable).
        DB::table('envoi_controles_dns')->where('workspace_id', $ws)->delete();
    } else {
        DB::table('envoi_controles_dns')->where('workspace_id', $ws)->delete();
        aigDns($ws, $m['domaine'], true, false, $dnsLe);
    }
    for ($i = 0; $i < Sequenceur::REPORTS_MAX + 1; $i++) {
        $echeance = new DateTimeImmutable((string) DB::table('envoi_inscriptions')->where('id', $ins)->value('prochaine_echeance'));
        $maintenant = $echeance > $maintenant ? $echeance : $maintenant;
        // Le contrôle DNS propre du séquenceur (envoi_domaines) doit dater du jour de l'envoi ; celui de l'aiguilleur reste ce qu'on a posé.
        DB::table('envoi_domaines')->where('workspace_id', $ws)->update(['dmarc_verifie_le' => $maintenant->format('c'), 'spf_verifie_le' => $maintenant->format('c'), 'dkim_verifie_le' => $maintenant->format('c')]);
        $seq = new Sequenceur(
            new PasserelleLocale($aig, new DepotSocleDb, true, 300, fn () => $maintenant, $ws, 300, fn () => WorkspaceContext::current()),
            new LecteurFicheDb,
            $composeur,
            new ParametresExpedition($m['domaine'], 'from@' . $m['domaine'], 'ZZ Expediteur SAS'),
        );
        $seq->traiter($ws, $ins, $maintenant);
        if ($cas !== 'feu_vert' && $cas !== 'dns') {
            break;
        }
    }
    $etat = DB::table('envoi_inscriptions')->where('id', $ins)->first();
    if ($cas === 'temoin') { // témoin : les trois conditions valent ⇒ le double est appelé, le test n'est pas vert par construction
        expect($m['kumo']->appels)->toBe(['injecter' => 1]);

        return;
    }
    expect($etat->statut)->toBe('active')->and((int) $etat->reports)->toBe(0)->and($m['kumo']->total())->toBe(0);
})->with(['feu_vert', 'dns', 'temoin']);

function aigFiche(string $ws, DateTimeImmutable $lundi): int
{
    $e = F::fiche($ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $lundi->modify('-3 days')->format('c')]);
    F::lier($ws, $e, F::tag($ws, FichesProtegees::TAG_FEDERATIONS));
    FonctionsEspace::regler($ws, 'federations', true);
    $email = 'a@zz-client.example.invalid';
    $c = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $ws, 'company_id' => $e, 'last_name' => 'Dupont-aig', 'email' => $email, 'email_status' => 'valid',
        'created_at' => $lundi->modify('-3 days')->format('c'), 'first_info_at' => $lundi->modify('-3 days')->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($email),
        'verdict' => 'valide', 'verifie_le' => $lundi->modify('-2 days')->format('c'), 'source' => 'test',
    ]);

    return $c;
}

// ── Hors injecter : un test par méthode ──────────────────────────────────────

test('etat(), sante(), reprise(), lireEvenements(), etatEnvoi() : sans les trois conditions, réponse du faux serveur, aucun adaptateur appelé', function (string $methode) {
    $m = aigMontage('kumo', true, false); // feu vert non accordé
    $aig = $m['aig'];
    $r = match ($methode) {
        'etat' => $aig->etat(),
        'sante' => $aig->sante(),
        'reprise' => $aig->reprise(),
        'lireEvenements' => $aig->lireEvenements('c0'),
        'etatEnvoi' => $aig->etatEnvoi('id-1'),
    };
    expect($m['kumo']->total())->toBe(0)->and($m['construits']->getArrayCopy())->toBe([]);
    if ($methode === 'sante') {
        expect($r->ok)->toBeFalse()->and($r->detail)->toContain('aucun adaptateur réel');
    }
    if ($methode === 'etat') {
        expect($r)->toEqual($m['faux']->etat());
    }
})->with(['etat', 'sante', 'reprise', 'lireEvenements', 'etatEnvoi']);

test('etat(), sante(), reprise(), lireEvenements(), etatEnvoi() : les trois conditions valent ⇒ l\'adaptateur répond (une fois) ; hors contexte ⇒ faux serveur', function (string $methode) {
    $m = aigMontage();
    $id = aigInjectionLocale($m['ws'], 'kumo');
    $appel = fn () => match ($methode) {
        'etat' => $m['aig']->etat(),
        'sante' => $m['aig']->sante(),
        'reprise' => $m['aig']->reprise(),
        'lireEvenements' => $m['aig']->lireEvenements('c0'),
        'etatEnvoi' => $m['aig']->etatEnvoi($id),
    };
    $appel();
    expect($m['kumo']->appels)->toBe([$methode => 1]);
    WorkspaceContext::clear(); // hors contexte d'espace : sens sûr
    $m['kumo']->appels = [];
    $r = $appel();
    expect($m['kumo']->total())->toBe(0);
    if ($methode === 'sante') {
        expect($r->ok)->toBeFalse();
    }
})->with(['etat', 'sante', 'reprise', 'lireEvenements', 'etatEnvoi']);

test('sante() hors contexte : ok = false et AUCUN battement noté par la passerelle', function () {
    $m = aigMontage();
    WorkspaceContext::clear();
    $p = new PasserelleLocale($m['aig'], new DepotSocleDb, true, 300, null, '', 300, null, app(GardeChauffe::class));
    expect($p->sante()->ok)->toBeFalse()->and(app(DepotChauffe::class)->dernierBattement())->toBeNull()->and($m['kumo']->total())->toBe(0);
    WorkspaceContext::set($m['ws']);
    expect($p->sante()->ok)->toBeTrue()->and(app(DepotChauffe::class)->dernierBattement())->not->toBeNull(); // témoin : en contexte, battement noté
});

// ── pause() : fonction, connexion applicative sans contexte ──────────────────

/** @return array{0: string, 1: string} espace et domaine committés (connexion propriétaire) */
function aigCommitte(): array
{
    $o = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $o->table('workspaces')->insert(['id' => $ws, 'name' => 'AIG', 'slug' => 'aig-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $o->table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => 'k.example.test', 'achete_le' => '2026-01-01']);
    $o->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
    $o->selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$ws, 'k.example.test', 'kumo', 'auteur-test']);
    $o->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

    return [$ws, 'k.example.test'];
}

function aigNettoyer(string $ws): void
{
    $o = DB::connection('pgsql_owner');
    foreach (['envoi_journal_commandes', 'envoi_drapeaux'] as $t) {
        $o->transaction(function () use ($o, $t, $ws): void {
            $o->statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
            $o->table($t)->where('workspace_id', $ws)->delete();
            $o->statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
        });
    }
    $o->table('envoi_domaines')->where('workspace_id', $ws)->delete();
    $o->table('workspaces')->where('id', $ws)->delete();
}

/** `ToutArreter::armer` (comme envoi:tout-arreter) sur la connexion applicative SANS contexte, via la passerelle et l'aiguilleur. */
function aigArmerSansContexte(string $ws, ?Closure $lecteur): AigDouble
{
    $double = new AigDouble('kumo');
    aigSimuler();
    $faux = new FauxServeurEnvoi(true, 0);
    $aig = new AiguilleurServeurEnvoi($faux, ['kumo' => fn () => $double], fn () => true, fn () => app(ListeFeuVert::class), null, $lecteur);
    // Feu vert RETIRÉ (aucune décision) : « Tout arrêter » atteint quand même Kumo (le stop gagne).
    $passerelle = new PasserelleLocale($aig, new DepotSocleDb, true, 300, null, '', 300, fn () => WorkspaceContext::current());
    DB::setDefaultConnection('pgsql_app');
    WorkspaceContext::clear();
    (new ToutArreter(new DepotSocleDb, $passerelle))->armer($ws, 'auteur-test');

    return $double;
}

test('pause() : domaine kumo, porte ouverte, feu vert retiré ⇒ le double reçoit pause UNE fois, sur pgsql_app sans contexte (comme envoi:tout-arreter)', function () {
    [$ws] = aigCommitte();
    $defaut = DB::getDefaultConnection();
    try {
        $double = aigArmerSansContexte($ws, null);
        expect($double->appels)->toBe(['pause' => 1]);
    } finally {
        DB::setDefaultConnection($defaut);
        DB::connection('pgsql_app')->disconnect();
        aigNettoyer($ws);
        DB::connection('pgsql_owner')->disconnect();
    }
});

test('garde avec témoin : un SELECT direct sur envoi_domaines (RLS) à la place de la fonction ⇒ le double ne reçoit PAS pause', function () {
    [$ws] = aigCommitte();
    $defaut = DB::getDefaultConnection();
    try {
        $direct = fn (): array => array_map(fn ($l) => (string) $l->adaptateur, DB::select("SELECT DISTINCT adaptateur FROM envoi_domaines WHERE adaptateur <> 'faux'"));
        $double = aigArmerSansContexte($ws, $direct);
        expect($double->appels)->toBe([]); // sans contexte la RLS cache tout : la lecture directe est fausse en production
    } finally {
        DB::setDefaultConnection($defaut);
        DB::connection('pgsql_app')->disconnect();
        aigNettoyer($ws);
        DB::connection('pgsql_owner')->disconnect();
    }
});

test('pause() : porte fermée ⇒ la passerelle ne transmet rien, aucun adaptateur réel construit ni appelé ; aiguilleur seul : le faux est mis en pause', function () {
    $m = aigMontage();
    $p = new PasserelleLocale($m['aig'], new DepotSocleDb, false, 300);
    $p->pause();
    expect($m['kumo']->total())->toBe(0)->and($m['construits']->getArrayCopy())->toBe([]);
    $m['aig']->pause();
    expect($m['kumo']->appels)->toBe(['pause' => 1]); // l'aiguilleur seul transmet (la porte est testée par la passerelle)
});

test('garde (témoin) : tant que le seul serveur est le faux, aucune pause n\'atteint un serveur réel (aucune fabrique réelle liée)', function () {
    $aig = app('envoi.serveur_interne');
    expect($aig)->toBeInstanceOf(AiguilleurServeurEnvoi::class);
    $aig->pause(); // aucun domaine réel : ne construit rien, ne lève rien
    $propriete = new ReflectionProperty($aig, 'fabriques');
    expect($propriete->getValue($aig))->toBe([]);
});

// ── Commande journalisée, seule voie ─────────────────────────────────────────

test('envoi:domaine-adaptateur : change et journalise ; valeur hors liste refusée, rien n\'est changé ni journalisé', function () {
    $m = aigMontage('faux', true, false, false);
    $ws = $m['ws'];
    expect(Artisan::call('envoi:domaine-adaptateur', ['workspace' => $ws, 'domaine' => $m['domaine'], 'adaptateur' => 'postfix', '--auteur' => 'auteur-test']))->toBe(1)
        ->and(DB::table('envoi_domaines')->where('workspace_id', $ws)->value('adaptateur'))->toBe('faux')
        ->and(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'domaine-adaptateur')->count())->toBe(0);
    WorkspaceContext::clear();
    expect(Artisan::call('envoi:domaine-adaptateur', ['workspace' => $ws, 'domaine' => $m['domaine'], 'adaptateur' => 'kumo', '--auteur' => 'auteur-test']))->toBe(0)
        ->and(DB::table('envoi_domaines')->where('workspace_id', $ws)->value('adaptateur'))->toBe('kumo');
    $j = DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'domaine-adaptateur')->first();
    expect($j->auteur)->toBe('auteur-test')->and($j->details)->toContain('kumo');
    expect(Artisan::call('envoi:domaine-adaptateur', ['workspace' => $ws, 'domaine' => 'inconnu.example.test', 'adaptateur' => 'kumo', '--auteur' => 'auteur-test']))->toBe(1);
});

test('gardes (témoin : sans le déclencheur elles rougiraient) : UPDATE et INSERT directs de adaptateur refusés même par le propriétaire', function () {
    $m = aigMontage('faux', true, false, false);
    expect(fn () => DB::statement("UPDATE envoi_domaines SET adaptateur = 'kumo' WHERE workspace_id = ?", [$m['ws']]))->toThrow(QueryException::class, 'envoi_domaines');
});

test('gardes : INSERT direct avec adaptateur = kumo refusé', function () {
    $m = aigMontage('faux', true, false, false);
    expect(fn () => DB::table('envoi_domaines')->insert(['workspace_id' => $m['ws'], 'nom' => 'z.example.test', 'achete_le' => '2026-01-01', 'adaptateur' => 'kumo']))
        ->toThrow(QueryException::class, 'envoi_domaines');
});

/** @param iterable<Illuminate\Routing\Route> $routes */
function aigRoutesQuiChangent(iterable $routes): array
{
    $trouvees = [];
    foreach ($routes as $r) {
        $ecrit = array_diff($r->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [];
        if ($ecrit && (str_contains($r->uri(), 'adaptateur') || str_contains((string) $r->getActionName(), 'Adaptateur'))) {
            $trouvees[] = $r->uri();
        }
    }

    return $trouvees;
}

test('aucune route ne change l\'adaptateur (témoin : une route ajoutée est vue)', function () {
    expect(aigRoutesQuiChangent(Route::getRoutes()))->toBe([]);
    Route::put('/api/v1/envoi/domaines/{id}/adaptateur', fn () => 'non');
    expect(aigRoutesQuiChangent(Route::getRoutes()))->toHaveCount(1);
});

test('lecture : l\'infrastructure expose le champ adaptateur du domaine', function () {
    $m = aigMontage('kumo', true, false, false);
    $d = app(LectureInfrastructure::class)->domaines($m['ws'])['data'][0];
    expect($d['adaptateur'])->toBe('kumo');
});

// ── A1 : fabriques par (espace, domaine), aucun cache ────────────────────────

test('deux espaces sur kumo : chacun reçoit SON adaptateur (aucun cache entre espaces)', function () {
    $recus = [];
    $fabrique = function (string $ws, string $domaine) use (&$recus): ServeurEnvoi {
        $recus[] = [$ws, $domaine];

        return new AigDouble('kumo');
    };
    $faux = new FauxServeurEnvoi;
    $aig = new AiguilleurServeurEnvoi($faux, ['kumo' => $fabrique], fn () => true, fn () => app(ListeFeuVert::class));
    aigSimuler();
    $domaines = [];
    foreach (['zz-aig-a', 'zz-aig-b'] as $slug) {
        $ws = F::espace($slug);
        $d = $slug . '.example.test';
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $d, 'achete_le' => '2026-01-01']);
        aigAdaptateur($ws, $d, 'kumo');
        aigDecider($ws, 'accorde');
        aigDns($ws, $d);
        $domaines[$ws] = $d;
    }
    foreach ($domaines as $ws => $d) {
        WorkspaceContext::set($ws);
        expect($aig->injecter(aigMessage($ws, $d, 'cle-' . $ws))->accepte)->toBeTrue();
    }
    expect($recus)->toBe(array_map(fn ($ws, $d) => [$ws, $d], array_keys($domaines), array_values($domaines)));
});

test('etatEnvoi d\'une injection smtp interroge smtp même si kumo est actif ; id inconnu ou non UUID ⇒ Inconnu, aucun adaptateur', function () {
    $m = aigMontage('kumo');
    aigAdaptateur($m['ws'], $m['domaine'], 'smtp'); // le domaine porte smtp : smtp et kumo ne peuvent pas être tous deux actifs ici
    $id = aigInjectionLocale($m['ws'], 'smtp');
    $idKumo = aigInjectionLocale($m['ws'], 'kumo');
    expect($m['aig']->etatEnvoi($id))->toBe(EtatMessage::Inconnu)
        ->and($m['smtp']->appels)->toBe(['etatEnvoi' => 1])->and($m['kumo']->total())->toBe(0);
    expect($m['aig']->etatEnvoi($idKumo))->toBe(EtatMessage::Inconnu)->and($m['kumo']->total())->toBe(0); // kumo n'est pas actif (aucun domaine kumo) : jamais deviné
    expect($m['aig']->etatEnvoi((string) Str::uuid()))->toBe(EtatMessage::Inconnu)->and($m['aig']->etatEnvoi('pas-un-uuid'))->toBe(EtatMessage::Inconnu)
        ->and($m['smtp']->total())->toBe(1);
});

<?php

/**
 * IDCC ET OPCO DES ENTREPRISES (lot O14) — `crm:enrichir-opco`.
 *
 * La table SIRET → OPCO de France compétences est SIMULÉE : un double de
 * `SourceSiro` écrit un PETIT fichier fictif au lieu d'appeler data.gouv.
 * Fixtures FICTIVES (dépôt public) : SIREN de la plage 94xxxxxxx dont la clé
 * de Luhn est INVALIDE, SIRET dont la clé de Luhn est INVALIDE aussi
 * (`opcoSiret`) — garantis sans correspondance avec une entreprise réelle ;
 * dénominations « ZZ ».
 *
 * Ce qui est verrouillé :
 *  - la fenêtre : refus le lundi, le dimanche, le 2 du mois, à 20:00 ;
 *  - `--dry-run` : bilan chiffré, RIEN d'écrit (ni table, ni journal) ;
 *  - l'idempotence : deux passages donnent le même état, le second n'écrit rien ;
 *  - une ligne `saisie` n'est JAMAIS écrasée ;
 *  - libellé d'OPCO inconnu, IDCC vide, SIRET malformé : rejetés et COMPTÉS ;
 *  - `--limite` puis reprise par le curseur ;
 *  - le fichier temporaire supprimé, même sur une erreur ;
 *  - aucune planification ; la fiche entreprise expose l'IDCC et l'OPCO ;
 *  - (réserves de #322) une fiche NON DIFFUSIBLE n'est jamais enrichie ; une
 *    ligne trop longue est rejetée sans avaler la suite ; une ressource
 *    remplacée sous le même identifiant repart de la ligne 1 ; l'essai à
 *    blanc lit tout depuis la ligne 1 et dit quand son bilan est partiel ;
 *    mémoire constante sur 100 000 lignes ; le VRAI `telecharger()` sous
 *    `Http::fake` : https seul, port 443, hôtes de la liste, redirections
 *    revérifiées et bornées, volume reçu borné.
 */

use App\Crm\Opco\EnrichissementOpco;
use App\Crm\Opco\FenetreOpco;
use App\Crm\Opco\LectureOpco;
use App\Crm\Opco\Opco;
use App\Crm\Opco\SourceSiro;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionsAndRolesSeeder;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Un mardi, 10:00 heure de Paris : dans la fenêtre. */
const OPCO_DANS_LA_FENETRE = '2026-10-06 10:00:00';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(OPCO_DANS_LA_FENETRE, 'Europe/Paris'));
});

// ── Fixtures ──────────────────────────────────────────────────────────────

function opcoLuhnValide(string $nombre): bool
{
    $somme = 0;
    foreach (array_reverse(str_split($nombre)) as $i => $chiffre) {
        $v = (int) $chiffre * ($i % 2 === 1 ? 2 : 1);
        $somme += $v > 9 ? $v - 9 : $v;
    }

    return $somme % 10 === 0;
}

/** Complète `$base` d'un dernier chiffre qui rend la clé de Luhn FAUSSE. */
function opcoCleFausse(string $base): string
{
    for ($c = 0; $c <= 9; $c++) {
        if (! opcoLuhnValide($base . $c)) {
            return $base . $c;
        }
    }

    throw new LogicException('impossible');
}

/**
 * Un SIRET FICTIF : SIREN 94xxxxxxx à clé de Luhn fausse, NIC 0001x, et le
 * SIRET entier à clé de Luhn fausse — aucun établissement réel.
 *
 * @return array{siren: string, siret: string}
 */
function opcoSiret(): array
{
    static $n = 0;
    $n++;
    $siren = opcoCleFausse('94' . str_pad((string) (700000 + $n), 6, '0', STR_PAD_LEFT));

    return ['siren' => $siren, 'siret' => opcoCleFausse($siren . '0001')];
}

/**
 * Le double de `SourceSiro` : une ressource fixe, un fichier écrit à la
 * demande. Il garde le chemin du fichier temporaire pour vérifier qu'il est
 * supprimé.
 */
function opcoSource(string $contenu, ?string $releve = 'Table SIRO — DSN de juillet 2026', bool $echouer = false): SourceSiro
{
    $source = new class extends SourceSiro
    {
        public string $contenu = '';

        public ?string $titre = null;

        public bool $echouer = false;

        /** @var list<string> */
        public array $chemins = [];

        public int $appels = 0;

        /** `last_modified` publié : le changer simule un fichier remplacé. */
        public string $modifie = '2026-08-10T00:00:00';

        public function ressourceCourante(): array
        {
            $this->appels++;

            return self::choisirRessource(['resources' => [[
                'id' => 'zz-ressource-1',
                'title' => (string) $this->titre,
                'format' => 'csv',
                'type' => 'main',
                'url' => 'https://example.invalid/zz-siro.csv',
                'last_modified' => $this->modifie,
            ]]]);
        }

        public function telecharger(string $url, string $chemin): void
        {
            $this->chemins[] = $chemin;
            file_put_contents($chemin, $this->contenu);
            if ($this->echouer) {
                throw new RuntimeException('coupure simulée');
            }
        }
    };
    $source->contenu = $contenu;
    $source->titre = $releve;
    $source->echouer = $echouer;
    app()->instance(SourceSiro::class, $source);

    return $source;
}

/** @param  list<array{0: string, 1: string, 2: string, 3: string}>  $lignes */
function opcoCsv(array $lignes): string
{
    $sortie = "SIRET|IDCC|OPCO_PROPRIETAIRE|OPCO_GESTION\n";
    foreach ($lignes as $l) {
        $sortie .= implode('|', $l) . "\n";
    }

    return $sortie;
}

/**
 * Un espace et trois fiches à SIRET connu.
 *
 * @return array{ws: string, a: array{id: int, siret: string}, b: array{id: int, siret: string}, c: array{id: int, siret: string}}
 */
function opcoEspace(): array
{
    $ws = F::espace('zz-opco');
    $e = ['ws' => $ws];
    foreach (['a', 'b', 'c'] as $k) {
        $s = opcoSiret();
        $e[$k] = ['id' => F::fiche($ws, 'ZZ OPCO ' . strtoupper($k), ['siren' => $s['siren'], 'siret' => $s['siret']]), 'siret' => $s['siret']];
    }

    return $e;
}

function opcoLancer(string $ws, array $options = []): array
{
    $code = Artisan::call('crm:enrichir-opco', ['--workspace' => $ws] + $options);

    return [$code, Artisan::output()];
}

function opcoCompteur(string $sortie, string $libelle): ?int
{
    return preg_match('/' . preg_quote($libelle, '/') . ' :? ?(\d+)/u', $sortie, $m) === 1 ? (int) $m[1] : null;
}

/** L'état comparable de la table (sans les horodatages de création). */
function opcoEtat(string $ws): array
{
    return DB::table('companies_opco')->where('workspace_id', $ws)->orderBy('company_id')
        ->get(['company_id', 'siret', 'idcc', 'opco', 'opco_gestion', 'source', 'releve_le', 'updated_at'])
        ->map(fn ($r) => (array) $r)->all();
}

// ── Fenêtre horaire ───────────────────────────────────────────────────────

dataset('hors fenêtre', [
    'un lundi' => ['2026-10-05 10:00:00', 'mardi au samedi'],
    'un dimanche' => ['2026-10-11 10:00:00', 'mardi au samedi'],
    'le 2 du mois' => ['2026-10-02 10:00:00', '1er, 2 et 3'],
    'à 20:00' => ['2026-10-06 20:00:00', '08:00 et 19:00'],
]);

test('fenêtre : REFUSE de partir hors du mardi→samedi 08:00-19:00 Paris et les 1er/2/3', function (string $instant, string $motif) {
    $e = opcoEspace();
    $source = opcoSource(opcoCsv([[$e['a']['siret'], '1486', 'ATLAS', 'ATLAS']]));
    $this->travelTo(CarbonImmutable::parse($instant, 'Europe/Paris'));

    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(1)
        ->and($sortie)->toContain('Refusé')
        ->and($sortie)->toContain($motif)
        ->and($source->appels)->toBe(0)
        ->and(DB::table('companies_opco')->count())->toBe(0)
        ->and(DB::table('companies_opco_passages')->count())->toBe(0);
})->with('hors fenêtre');

test('fenêtre : 08:00 un samedi et 18:59 un mardi sont acceptés, 19:00 refusé', function () {
    expect(FenetreOpco::refus(CarbonImmutable::parse('2026-10-10 08:00', 'Europe/Paris')))->toBeNull()
        ->and(FenetreOpco::refus(CarbonImmutable::parse('2026-10-06 18:59', 'Europe/Paris')))->toBeNull()
        ->and(FenetreOpco::refus(CarbonImmutable::parse('2026-10-06 19:00', 'Europe/Paris')))->not->toBeNull()
        // Un instant UTC est lu à l'heure de Paris (06:30 UTC = 08:30 Paris en octobre).
        ->and(FenetreOpco::refus(CarbonImmutable::parse('2026-10-06 06:30', 'UTC')))->toBeNull();
});

test('aucune planification : crm:enrichir-opco ne figure pas dans le scheduler', function () {
    $evenements = collect(app(Schedule::class)->events())
        ->filter(fn ($ev) => str_contains((string) $ev->command, 'crm:enrichir-opco'));

    expect($evenements)->toHaveCount(0);
});

// ── Essai à blanc ─────────────────────────────────────────────────────────

test('--dry-run : bilan chiffré, RIEN d’écrit, fichier supprimé', function () {
    $e = opcoEspace();
    $source = opcoSource(opcoCsv([
        [$e['a']['siret'], '1486', 'ATLAS', 'ATLAS'],
        [$e['b']['siret'], '2216', 'OPCOMMERCE', 'OPCOMMERCE'],
        [opcoSiret()['siret'], '0016', 'MOBILITES', ''],
    ]));

    [$code, $sortie] = opcoLancer($e['ws'], ['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($sortie)->toContain('ESSAI À BLANC')
        ->and(opcoCompteur($sortie, 'lues'))->toBe(3)
        ->and(opcoCompteur($sortie, 'rapprochées'))->toBe(2)
        ->and(opcoCompteur($sortie, 'à écrire'))->toBe(2)
        ->and($sortie)->toContain('DSN de 2026-07')
        ->and(DB::table('companies_opco')->count())->toBe(0)
        ->and(DB::table('companies_opco_passages')->count())->toBe(0)
        ->and($source->chemins)->toHaveCount(1)
        ->and(file_exists($source->chemins[0]))->toBeFalse();
});

// ── Écriture et idempotence ───────────────────────────────────────────────

test('écrit IDCC, OPCO propriétaire et de gestion, source siro, mois de DSN — companies intacte', function () {
    $e = opcoEspace();
    $avant = DB::table('companies')->where('workspace_id', $e['ws'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    opcoSource(opcoCsv([
        [$e['a']['siret'], '1486', 'ATLAS', 'ATLAS'],
        [$e['b']['siret'], '16', 'OPCO MOBILITES', 'AKTO'],
    ]));

    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(0)->and(opcoCompteur($sortie, 'écrites'))->toBe(2);
    $a = DB::table('companies_opco')->where('company_id', $e['a']['id'])->first();
    $b = DB::table('companies_opco')->where('company_id', $e['b']['id'])->first();
    expect($a->idcc)->toBe('1486')
        ->and($a->opco)->toBe('atlas')
        ->and($a->opco_gestion)->toBe('atlas')
        ->and($a->source)->toBe('siro')
        ->and($a->siret)->toBe($e['a']['siret'])
        ->and(substr((string) $a->releve_le, 0, 10))->toBe('2026-07-01')
        // Zéros de tête rétablis ; OPCO_PROPRIETAIRE fait foi, OPCO_GESTION à part.
        ->and($b->idcc)->toBe('0016')
        ->and($b->opco)->toBe('mobilites')
        ->and($b->opco_gestion)->toBe('akto')
        ->and(DB::table('companies_opco')->where('company_id', $e['c']['id'])->exists())->toBeFalse();

    // `companies` n'est JAMAIS écrite.
    $apres = DB::table('companies')->where('workspace_id', $e['ws'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    expect($apres)->toBe($avant);

    $passage = DB::table('companies_opco_passages')->where('workspace_id', $e['ws'])->first();
    expect($passage->statut)->toBe('reussie')->and((int) $passage->curseur)->toBe(2);
});

test('idempotence : deux passages donnent le MÊME état, le second n’écrit rien', function () {
    $e = opcoEspace();
    opcoSource(opcoCsv([
        [$e['a']['siret'], '1486', 'ATLAS', 'ATLAS'],
        [$e['b']['siret'], '2216', 'OPCOMMERCE', ''],
    ]));

    opcoLancer($e['ws']);
    $premier = opcoEtat($e['ws']);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 11:00:00', 'Europe/Paris'));
    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(0)
        ->and(opcoCompteur($sortie, 'écrites'))->toBe(0)
        ->and(opcoCompteur($sortie, 'inchangées'))->toBe(2)
        ->and(opcoEtat($e['ws']))->toBe($premier)
        ->and(count($premier))->toBe(2);
});

test('une ligne SAISIE n’est JAMAIS écrasée, et elle est comptée', function () {
    $e = opcoEspace();
    DB::table('companies_opco')->insert([
        'workspace_id' => $e['ws'], 'company_id' => $e['a']['id'], 'siret' => $e['a']['siret'],
        'idcc' => '3248', 'opco' => 'opco2i', 'opco_gestion' => null, 'source' => 'saisie', 'releve_le' => null,
    ]);
    $avant = opcoEtat($e['ws']);
    opcoSource(opcoCsv([
        [$e['a']['siret'], '1486', 'ATLAS', 'ATLAS'],
        [$e['b']['siret'], '1486', 'ATLAS', 'ATLAS'],
    ]));

    [, $sortie] = opcoLancer($e['ws']);

    $a = DB::table('companies_opco')->where('company_id', $e['a']['id'])->first();
    expect($a->source)->toBe('saisie')
        ->and($a->idcc)->toBe('3248')
        ->and($a->opco)->toBe('opco2i')
        ->and(opcoCompteur($sortie, 'ignorées pour saisie'))->toBe(1)
        ->and(opcoCompteur($sortie, 'écrites'))->toBe(1)
        ->and((array) DB::table('companies_opco')->where('company_id', $e['a']['id'])
            ->first(['company_id', 'siret', 'idcc', 'opco', 'opco_gestion', 'source', 'releve_le', 'updated_at']))->toBe($avant[0]);
});

test('une ligne siro existante est mise à jour par une nouvelle valeur', function () {
    $e = opcoEspace();
    DB::table('companies_opco')->insert([
        'workspace_id' => $e['ws'], 'company_id' => $e['a']['id'], 'siret' => $e['a']['siret'],
        'idcc' => '3248', 'opco' => 'opco2i', 'source' => 'siro', 'releve_le' => '2026-06-01',
    ]);
    opcoSource(opcoCsv([[$e['a']['siret'], '1486', 'ATLAS', 'ATLAS']]));

    opcoLancer($e['ws']);

    $a = DB::table('companies_opco')->where('company_id', $e['a']['id'])->first();
    expect($a->idcc)->toBe('1486')->and($a->opco)->toBe('atlas')->and(substr((string) $a->releve_le, 0, 10))->toBe('2026-07-01');
});

// ── Rejets ────────────────────────────────────────────────────────────────

test('libellé d’OPCO inconnu, IDCC vide, SIRET malformé : rejetés et COMPTÉS, rien d’inventé', function () {
    $e = opcoEspace();
    opcoSource(opcoCsv([
        [$e['a']['siret'], '1486', 'OPCO IMAGINAIRE', 'ATLAS'],
        [$e['b']['siret'], '', 'ATLAS', 'ATLAS'],
        ['123', '1486', 'ATLAS', 'ATLAS'],
        [$e['c']['siret'], '1486', 'ATLAS', 'GESTIONNAIRE INCONNU'],
        [opcoSiret()['siret'], 'ABCD', 'ATLAS', ''],
    ]));

    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(0)
        ->and(opcoCompteur($sortie, 'lues'))->toBe(5)
        ->and(opcoCompteur($sortie, 'OPCO inconnu'))->toBe(1)
        ->and(opcoCompteur($sortie, 'IDCC vide'))->toBe(1)
        ->and(opcoCompteur($sortie, 'SIRET malformé'))->toBe(1)
        ->and(opcoCompteur($sortie, 'OPCO de gestion inconnu'))->toBe(1)
        ->and(opcoCompteur($sortie, 'IDCC malformé'))->toBe(1)
        ->and(DB::table('companies_opco')->count())->toBe(0);

    $bilan = json_decode((string) DB::table('companies_opco_passages')->value('bilan'), true);
    expect($bilan['rejet_opco_inconnu'])->toBe(1)->and($bilan['rejet_idcc_vide'])->toBe(1);
});

test('traduction FERMÉE des libellés SIRO', function () {
    expect(Opco::depuisLibelleSiro('ATLAS'))->toBe('atlas')
        ->and(Opco::depuisLibelleSiro('OPCO 2I'))->toBe('opco2i')
        ->and(Opco::depuisLibelleSiro('OPCO Santé'))->toBe('opco_sante')
        ->and(Opco::depuisLibelleSiro('OPCO EP'))->toBe('opco_ep')
        ->and(Opco::depuisLibelleSiro("L'OPCOMMERCE"))->toBe('opcommerce')
        ->and(Opco::depuisLibelleSiro('OPCO MOBILITES'))->toBe('mobilites')
        // Les 12 libellés RÉELS de siro-202606.csv (relevés par le pilote), tous reconnus.
        ->and(Opco::depuisLibelleSiro('UNIFORMATION COHESION SOCIALE'))->toBe('uniformation')
        ->and(Opco::depuisLibelleSiro('AFDAS'))->toBe('afdas')
        ->and(Opco::depuisLibelleSiro('AKTO'))->toBe('akto')
        ->and(Opco::depuisLibelleSiro('ATLAS'))->toBe('atlas')
        ->and(Opco::depuisLibelleSiro('CONSTRUCTYS'))->toBe('constructys')
        ->and(Opco::depuisLibelleSiro('OCAPIAT'))->toBe('ocapiat')
        ->and(Opco::depuisLibelleSiro('OPCO SANTE'))->toBe('opco_sante')
        ->and(Opco::depuisLibelleSiro('OPCO2I'))->toBe('opco2i')
        ->and(Opco::depuisLibelleSiro('OPCO IMAGINAIRE'))->toBeNull()
        ->and(Opco::depuisLibelleSiro(''))->toBeNull();
    // Chaque cible de la traduction est dans la liste fermée de la contrainte.
    expect(array_diff(array_values(Opco::LIBELLES_SIRO), Opco::VALEURS))->toBe([]);
});

// ── Reprise ───────────────────────────────────────────────────────────────

test('--limite puis REPRISE par le curseur : aucune ligne lue deux fois, rien de perdu', function () {
    $e = opcoEspace();
    opcoSource(opcoCsv([
        [$e['a']['siret'], '1486', 'ATLAS', 'ATLAS'],
        [opcoSiret()['siret'], '1486', 'ATLAS', 'ATLAS'],
        [$e['b']['siret'], '2216', 'OPCOMMERCE', ''],
        [$e['c']['siret'], '1517', 'OPCOMMERCE', ''],
    ]));

    [$code, $sortie] = opcoLancer($e['ws'], ['--limite' => 2]);
    expect($code)->toBe(0)
        ->and($sortie)->toContain('INACHEVÉ')
        ->and(opcoCompteur($sortie, 'lues'))->toBe(2);
    $passage = DB::table('companies_opco_passages')->where('workspace_id', $e['ws'])->first();
    expect($passage->statut)->toBe('en_cours')->and((int) $passage->curseur)->toBe(2)
        ->and(DB::table('companies_opco')->pluck('company_id')->all())->toBe([$e['a']['id']]);

    [, $sortie] = opcoLancer($e['ws']);
    expect($sortie)->toContain('reprise')
        ->and(opcoCompteur($sortie, 'lues'))->toBe(2)
        ->and(opcoCompteur($sortie, 'écrites'))->toBe(2);

    expect(DB::table('companies_opco_passages')->where('workspace_id', $e['ws'])->count())->toBe(1);
    $passage = DB::table('companies_opco_passages')->where('workspace_id', $e['ws'])->first();
    $bilan = json_decode((string) $passage->bilan, true);
    expect($passage->statut)->toBe('reussie')
        ->and((int) $passage->curseur)->toBe(4)
        ->and($bilan['lues'])->toBe(4)
        ->and($bilan['ecrites'])->toBe(3)
        ->and(DB::table('companies_opco')->where('workspace_id', $e['ws'])->count())->toBe(3);
});

test('erreur en cours de passage : passage « echouee », fichier SUPPRIMÉ quand même', function () {
    $e = opcoEspace();
    $source = opcoSource(opcoCsv([[$e['a']['siret'], '1486', 'ATLAS', 'ATLAS']]), echouer: true);

    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(1)
        ->and($sortie)->toContain('Échec')
        ->and(file_exists($source->chemins[0]))->toBeFalse()
        ->and(DB::table('companies_opco_passages')->value('statut'))->toBe('echouee')
        ->and(DB::table('companies_opco')->count())->toBe(0);
});

test('en-tête sans colonne IDCC : échec clair, fichier supprimé', function () {
    $e = opcoEspace();
    $source = opcoSource("SIRET;OPCO\n" . $e['a']['siret'] . ";ATLAS\n");

    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(1)
        ->and($sortie)->toContain('colonne IDCC absente')
        ->and(file_exists($source->chemins[0]))->toBeFalse();
});

test('séparateur et en-tête détectés (« ; », casse, BOM, guillemets)', function () {
    expect(EnrichissementOpco::separateur("SIRET|IDCC|OPCO_PROPRIETAIRE|OPCO_GESTION\n"))->toBe('|')
        ->and(EnrichissementOpco::separateur("siret;idcc;opco_proprietaire;opco_gestion\n"))->toBe(';')
        ->and(EnrichissementOpco::colonnes(["\u{FEFF}\"siret\"", 'Idcc', 'OPCO PROPRIETAIRE', 'opco_gestion']))
        ->toBe(['SIRET' => 0, 'IDCC' => 1, 'OPCO_PROPRIETAIRE' => 2, 'OPCO_GESTION' => 3, 'nombre' => 4]);

    $e = opcoEspace();
    opcoSource("\u{FEFF}siret;idcc;opco_proprietaire;opco_gestion\n" . $e['a']['siret'] . ";1486;ATLAS;ATLAS\n");
    opcoLancer($e['ws']);
    expect(DB::table('companies_opco')->where('company_id', $e['a']['id'])->value('idcc'))->toBe('1486');
});

// ── Ressource data.gouv ───────────────────────────────────────────────────

test('ressource : la DERNIÈRE ressource CSV du jeu, jamais un identifiant figé ; mois de DSN lu', function () {
    $r = SourceSiro::choisirRessource(['resources' => [
        ['id' => 'ancienne', 'title' => 'Table SIRO DSN de juin 2026', 'format' => 'csv', 'type' => 'main', 'url' => 'https://example.invalid/a.csv', 'last_modified' => '2026-07-10T00:00:00'],
        ['id' => 'doc', 'title' => 'Notice', 'format' => 'pdf', 'type' => 'documentation', 'url' => 'https://example.invalid/notice.pdf', 'last_modified' => '2026-09-01T00:00:00'],
        ['id' => 'recente', 'title' => 'Table SIRO', 'description' => 'Issue de la DSN de juillet 2026', 'format' => 'CSV', 'type' => 'main', 'url' => 'https://example.invalid/b.csv', 'last_modified' => '2026-08-10T00:00:00'],
        ['id' => 'en-clair', 'title' => 'Table SIRO', 'format' => 'csv', 'type' => 'main', 'url' => 'http://example.invalid/c.csv', 'last_modified' => '2026-09-10T00:00:00'],
    ]]);

    expect($r['id'])->toBe('recente')
        ->and($r['releve_le'])->toBe('2026-07-01')
        ->and(SourceSiro::moisDsn('siro_202608.csv'))->toBe('2026-08-01')
        ->and(SourceSiro::moisDsn('Table sans date'))->toBeNull();
});

test('--releve-le impose le mois de DSN quand la ressource ne le dit pas', function () {
    $e = opcoEspace();
    opcoSource(opcoCsv([[$e['a']['siret'], '1486', 'ATLAS', 'ATLAS']]), 'Table SIRO');

    opcoLancer($e['ws'], ['--releve-le' => '2026-05']);

    expect(substr((string) DB::table('companies_opco')->value('releve_le'), 0, 10))->toBe('2026-05-01');
});

// ── Fiche entreprise ──────────────────────────────────────────────────────

test('mention de source : France compétences et le mois de la DSN', function () {
    expect(LectureOpco::mention('siro', '2026-07-01'))->toBe('source : France compétences (DSN de juillet 2026)')
        ->and(LectureOpco::mention('saisie', null))->toBe('source : saisie');
});

test('GET /companies/{id} expose IDCC et OPCO en lecture seule (null sinon)', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $e = opcoEspace();
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'zz-opco-' . Str::random(6) . '@example.invalid',
        'name' => 'ZZ OPCO',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $e['ws'],
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($e['ws']);
    $user->assignRole('admin');
    DB::table('companies_opco')->insert([
        'workspace_id' => $e['ws'], 'company_id' => $e['a']['id'], 'siret' => $e['a']['siret'],
        'idcc' => '1486', 'opco' => 'atlas', 'opco_gestion' => 'atlas', 'source' => 'siro', 'releve_le' => '2026-07-01',
    ]);

    $this->actingAs($user)->getJson('/api/v1/companies/' . $e['a']['id'])
        ->assertOk()
        ->assertJsonPath('opco.idcc', '1486')
        ->assertJsonPath('opco.opco', 'atlas')
        ->assertJsonPath('opco.mention', 'source : France compétences (DSN de juillet 2026)');

    $this->actingAs($user)->getJson('/api/v1/companies/' . $e['b']['id'])
        ->assertOk()
        ->assertJsonPath('opco', null);
});


// ── Réserves de #322 : RGPD, lignes bornées, reprise, essai à blanc ───────

test('RGPD : une fiche NON DIFFUSIBLE (INSEE) n’est jamais enrichie, et elle est comptée', function () {
    $e = opcoEspace();
    DB::table('companies')->where('id', $e['b']['id'])->update(['insee_non_diffusible_le' => '2026-09-01']);
    opcoSource(opcoCsv([
        [$e['a']['siret'], '1486', 'ATLAS', 'ATLAS'],
        [$e['b']['siret'], '1486', 'ATLAS', 'ATLAS'],
    ]));

    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(0)
        ->and(opcoCompteur($sortie, 'exclues (non diffusibles INSEE)'))->toBe(1)
        ->and(opcoCompteur($sortie, 'écrites'))->toBe(1)
        ->and(DB::table('companies_opco')->pluck('company_id')->all())->toBe([$e['a']['id']]);
    $bilan = json_decode((string) DB::table('companies_opco_passages')->value('bilan'), true);
    expect($bilan['exclues_non_diffusibles'])->toBe(1);
});

test('ligne trop longue ou guillemet non fermé : rejetée et comptée, la suite est lue', function () {
    $e = opcoEspace();
    $contenu = "SIRET|IDCC|OPCO_PROPRIETAIRE|OPCO_GESTION\n"
        . $e['a']['siret'] . '|1486|ATLAS|' . str_repeat('X', EnrichissementOpco::LONGUEUR_MAX_LIGNE * 3) . "\n"
        . '"' . $e['b']['siret'] . "|1486|ATLAS|ATLAS\n"
        . $e['c']['siret'] . "|2216|OPCOMMERCE|\n";
    opcoSource($contenu);

    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(0)
        ->and(opcoCompteur($sortie, 'lues'))->toBe(3)
        // La ligne trop longue, et celle au guillemet non fermé (un seul
        // champ : mauvais nombre de colonnes) — la ligne suivante est lue.
        ->and(opcoCompteur($sortie, 'ligne malformée'))->toBe(2)
        ->and(DB::table('companies_opco')->pluck('company_id')->all())->toBe([$e['c']['id']]);
});

test('lecture bornée : une ligne trop longue rend false, jamais plus de LONGUEUR_MAX_LIGNE en mémoire', function () {
    $flux = fopen('php://temp', 'w+b');
    fwrite($flux, str_repeat('A', EnrichissementOpco::LONGUEUR_MAX_LIGNE + 10) . "\nB|C\r\n\nD");
    rewind($flux);

    expect(EnrichissementOpco::lireLigne($flux))->toBeFalse()
        ->and(EnrichissementOpco::lireLigne($flux))->toBe('B|C')
        ->and(EnrichissementOpco::lireLigne($flux))->toBe('')
        ->and(EnrichissementOpco::lireLigne($flux))->toBe('D')
        ->and(EnrichissementOpco::lireLigne($flux))->toBeNull();
    fclose($flux);
});

test('en-tête inattendu : le contenu lu est TRONQUÉ dans le message', function () {
    $e = opcoEspace();
    opcoSource('SIRET;' . str_repeat('Z', 500) . "\n" . $e['a']['siret'] . ";1\n");

    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(1)
        ->and($sortie)->toContain('colonne IDCC absente')
        ->and($sortie)->not->toContain(str_repeat('Z', 100));
});

test('ressource REMPLACÉE sous le même identifiant : nouveau passage depuis la ligne 1, l’ancien clos', function () {
    $e = opcoEspace();
    $lignes = [
        [$e['a']['siret'], '1486', 'ATLAS', 'ATLAS'],
        [$e['b']['siret'], '2216', 'OPCOMMERCE', ''],
        [$e['c']['siret'], '1517', 'OPCOMMERCE', ''],
    ];
    opcoSource(opcoCsv($lignes));
    opcoLancer($e['ws'], ['--limite' => 1]);
    expect((int) DB::table('companies_opco_passages')->value('curseur'))->toBe(1);

    // Même identifiant, même URL, fichier republié (autre `last_modified`).
    $source = opcoSource(opcoCsv($lignes));
    $source->modifie = '2026-09-10T00:00:00';
    [$code, $sortie] = opcoLancer($e['ws']);

    expect($code)->toBe(0)
        ->and($sortie)->not->toContain('Reprise du passage')
        ->and(opcoCompteur($sortie, 'lues'))->toBe(3);
    $passages = DB::table('companies_opco_passages')->where('workspace_id', $e['ws'])->orderBy('id')->get();
    expect($passages)->toHaveCount(2)
        ->and($passages[0]->statut)->toBe('echouee')
        ->and($passages[0]->erreur)->toContain('Ressource remplacée')
        ->and($passages[1]->statut)->toBe('reussie')
        ->and($passages[1]->ressource_version)->toBe('2026-09-10T00:00:00')
        ->and(DB::table('companies_opco')->where('workspace_id', $e['ws'])->count())->toBe(3);
});

test('version de ressource : somme de contrôle publiée, sinon last_modified', function () {
    $base = ['id' => 'r', 'format' => 'csv', 'url' => 'https://static.data.gouv.fr/r.csv', 'last_modified' => '2026-08-10T00:00:00'];

    expect(SourceSiro::choisirRessource(['resources' => [$base + ['checksum' => ['type' => 'sha1', 'value' => 'abc']]]])['version'])->toBe('sha1:abc')
        ->and(SourceSiro::choisirRessource(['resources' => [$base]])['version'])->toBe('2026-08-10T00:00:00');
});

test('--dry-run --limite : bilan PARTIEL annoncé, jamais « curseur mémorisé »', function () {
    $e = opcoEspace();
    opcoSource(opcoCsv([
        [$e['a']['siret'], '1486', 'ATLAS', 'ATLAS'],
        [$e['b']['siret'], '2216', 'OPCOMMERCE', ''],
    ]));

    [$code, $sortie] = opcoLancer($e['ws'], ['--dry-run' => true, '--limite' => 1]);

    expect($code)->toBe(0)
        ->and($sortie)->toContain('bilan PARTIEL')
        ->and($sortie)->not->toContain('le curseur est mémorisé')
        ->and(opcoCompteur($sortie, 'lues'))->toBe(1)
        ->and(DB::table('companies_opco_passages')->count())->toBe(0);
});

test('--dry-run pendant un passage inachevé : lit TOUT depuis la ligne 1, le passage reste intact', function () {
    $e = opcoEspace();
    opcoSource(opcoCsv([
        [$e['a']['siret'], '1486', 'ATLAS', 'ATLAS'],
        [$e['b']['siret'], '2216', 'OPCOMMERCE', ''],
        [$e['c']['siret'], '1517', 'OPCOMMERCE', ''],
    ]));
    opcoLancer($e['ws'], ['--limite' => 2]);
    $avant = (array) DB::table('companies_opco_passages')->first();

    [$code, $sortie] = opcoLancer($e['ws'], ['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and(opcoCompteur($sortie, 'lues'))->toBe(3)
        ->and($sortie)->not->toContain('Reprise du passage')
        ->and(opcoCompteur($sortie, 'fiches de l\'espace sans SIRET (jamais rapprochables)'))->toBe(0)
        ->and((array) DB::table('companies_opco_passages')->first())->toBe($avant);
});

test('mêmes valeurs dans une publication plus récente : rien n’est réécrit', function () {
    $e = opcoEspace();
    DB::table('companies_opco')->insert([
        'workspace_id' => $e['ws'], 'company_id' => $e['a']['id'], 'siret' => $e['a']['siret'],
        'idcc' => '1486', 'opco' => 'atlas', 'opco_gestion' => 'atlas', 'source' => 'siro', 'releve_le' => '2026-06-01',
    ]);
    $avant = opcoEtat($e['ws']);
    opcoSource(opcoCsv([[$e['a']['siret'], '1486', 'ATLAS', 'ATLAS']]));

    [, $sortie] = opcoLancer($e['ws']);

    expect(opcoCompteur($sortie, 'écrites'))->toBe(0)
        ->and(opcoCompteur($sortie, 'inchangées'))->toBe(1)
        ->and(opcoEtat($e['ws']))->toBe($avant);
});

// ── Mémoire constante ─────────────────────────────────────────────────────

test('mémoire constante : 100 000 lignes lues en flux, pic borné, tout compté', function () {
    $e = opcoEspace();
    $n = 100000;
    $source = new class extends SourceSiro
    {
        public int $n = 0;

        public string $connu = '';

        public function ressourceCourante(): array
        {
            return self::choisirRessource(['resources' => [[
                'id' => 'zz-memoire', 'title' => 'Table SIRO — DSN de juillet 2026', 'format' => 'csv',
                'url' => 'https://example.invalid/zz-memoire.csv', 'last_modified' => '2026-08-10T00:00:00',
            ]]]);
        }

        /** Le fichier est ÉCRIT ligne à ligne : le banc ne garde rien en mémoire. */
        public function telecharger(string $url, string $chemin): void
        {
            $f = fopen($chemin, 'wb');
            fwrite($f, "SIRET|IDCC|OPCO_PROPRIETAIRE|OPCO_GESTION\n");
            fwrite($f, $this->connu . "|1486|ATLAS|ATLAS\n");
            for ($i = 1; $i < $this->n; $i++) {
                fwrite($f, opcoSiret()['siret'] . "|1486|ATLAS|ATLAS\n");
            }
            fclose($f);
        }
    };
    $source->n = $n;
    $source->connu = $e['a']['siret'];
    DB::connection()->disableQueryLog();

    gc_collect_cycles();
    $base = memory_get_usage();
    memory_reset_peak_usage();
    $resultat = (new EnrichissementOpco($source))->executer($e['ws']);
    $pic = memory_get_peak_usage() - $base;

    // Tout garder (100 000 lignes en tableau) coûterait plusieurs dizaines de Mo.
    expect($resultat['statut'])->toBe('reussie')
        ->and($resultat['bilan']['lues'])->toBe($n)
        ->and($resultat['bilan']['rapprochees'])->toBe(1)
        ->and($resultat['bilan']['non_rapprochees'])->toBe($n - 1)
        ->and($resultat['curseur'])->toBe($n)
        ->and($pic)->toBeLessThan(12 * 1024 * 1024);
});

// ── Le VRAI téléchargement (garde SSRF, hôtes, redirections, taille) ──────

dataset('URL refusées', [
    'en http' => ['http://static.data.gouv.fr/resources/siro.csv', 'https'],
    'hôte hors liste' => ['https://example.org/siro.csv', 'hors de la liste'],
    'IP interne littérale' => ['https://169.254.169.254/latest/meta-data', 'hors de la liste'],
    'IP de boucle locale' => ['https://127.0.0.1/siro.csv', 'hors de la liste'],
    'autre port' => ['https://static.data.gouv.fr:8443/siro.csv', 'port 443'],
    'identifiants dans l’URL' => ['https://moi:secret@static.data.gouv.fr/siro.csv', 'identifiants'],
    'hôte voisin' => ['https://static.data.gouv.fr.example.org/siro.csv', 'hors de la liste'],
]);

test('telecharger : URL refusée AVANT toute requête', function (string $url, string $motif) {
    Http::fake();
    $chemin = tempnam(sys_get_temp_dir(), 'zz-siro-');

    expect(fn () => (new SourceSiro)->telecharger($url, $chemin))->toThrow(RuntimeException::class, $motif);
    Http::assertNothingSent();
    @unlink($chemin);
})->with('URL refusées');

dataset('redirections refusées', [
    'vers http' => ['http://static.data.gouv.fr/siro.csv', 'https'],
    'vers une IP interne' => ['https://10.0.0.5/siro.csv', 'hors de la liste'],
    'vers le service de métadonnées' => ['http://169.254.169.254/latest/meta-data', 'https'],
    'vers un autre hôte' => ['https://example.org/siro.csv', 'hors de la liste'],
    'vers un autre port' => ['https://www.data.gouv.fr:8080/siro.csv', 'port 443'],
]);

test('telecharger : une redirection est REVÉRIFIÉE et la cible refusée n’est jamais contactée', function (string $cible, string $motif) {
    $urls = [];
    Http::fake(function (Request $r) use ($cible, &$urls) {
        $urls[] = $r->url();

        return Http::response('', 302, ['Location' => $cible]);
    });
    $chemin = tempnam(sys_get_temp_dir(), 'zz-siro-');

    expect(fn () => (new SourceSiro)->telecharger('https://www.data.gouv.fr/fr/datasets/r/zz', $chemin))
        ->toThrow(RuntimeException::class, $motif);
    expect($urls)->toBe(['https://www.data.gouv.fr/fr/datasets/r/zz']);
    @unlink($chemin);
})->with('redirections refusées');

test('telecharger : redirection autorisée suivie (relative comprise), fichier écrit en flux', function () {
    $urls = [];
    Http::fake(function (Request $r) use (&$urls) {
        $urls[] = $r->url();

        return match (count($urls)) {
            1 => Http::response('', 302, ['Location' => 'https://static.data.gouv.fr/resources/zz/siro.csv']),
            2 => Http::response('', 301, ['Location' => '/resources/zz/siro-202606.csv']),
            default => Http::response("SIRET|IDCC|OPCO_PROPRIETAIRE|OPCO_GESTION\n", 200),
        };
    });
    $chemin = tempnam(sys_get_temp_dir(), 'zz-siro-');

    (new SourceSiro)->telecharger('https://www.data.gouv.fr/fr/datasets/r/zz', $chemin);

    expect($urls)->toBe([
        'https://www.data.gouv.fr/fr/datasets/r/zz',
        'https://static.data.gouv.fr/resources/zz/siro.csv',
        'https://static.data.gouv.fr/resources/zz/siro-202606.csv',
    ])->and(file_get_contents($chemin))->toBe("SIRET|IDCC|OPCO_PROPRIETAIRE|OPCO_GESTION\n");
    @unlink($chemin);
});

test('telecharger : au plus 3 redirections', function () {
    $appels = 0;
    Http::fake(function () use (&$appels) {
        $appels++;

        return Http::response('', 302, ['Location' => 'https://static.data.gouv.fr/boucle-' . $appels . '.csv']);
    });
    $chemin = tempnam(sys_get_temp_dir(), 'zz-siro-');

    expect(fn () => (new SourceSiro)->telecharger('https://static.data.gouv.fr/depart.csv', $chemin))
        ->toThrow(RuntimeException::class, 'redirections');
    expect($appels)->toBe(SourceSiro::MAX_REDIRECTIONS + 1);
    @unlink($chemin);
});

/** Une source dont la taille maximale est réduite à 64 octets. */
function opcoSourcePetite(): SourceSiro
{
    return new class extends SourceSiro
    {
        protected function tailleMax(): int
        {
            return 64;
        }
    };
}

test('telecharger : taille ANNONCÉE au-delà du maximum → refus', function () {
    Http::fake(fn () => Http::response('court', 200, ['Content-Length' => '65']));
    $chemin = tempnam(sys_get_temp_dir(), 'zz-siro-');

    expect(fn () => opcoSourcePetite()->telecharger('https://static.data.gouv.fr/siro.csv', $chemin))
        ->toThrow(RuntimeException::class, 'trop volumineuse');
    @unlink($chemin);
});

test('telecharger : SANS Content-Length, le volume REÇU est borné (flux coupé)', function () {
    $produits = 0;
    Http::fake(function () use (&$produits) {
        // Un corps produit à la demande, sans longueur annoncée (« chunked »).
        return Http::response(new PumpStream(function () use (&$produits) {
            $produits++;

            return $produits > 1000 ? false : str_repeat('Z', 32);
        }), 200);
    });
    $chemin = tempnam(sys_get_temp_dir(), 'zz-siro-');

    expect(fn () => opcoSourcePetite()->telecharger('https://static.data.gouv.fr/siro.csv', $chemin))
        ->toThrow(RuntimeException::class, 'trop volumineuse');
    clearstatcache(true, $chemin);
    expect((int) filesize($chemin))->toBeLessThanOrEqual(64);
    @unlink($chemin);
});

test('copie bornée : coupe dès que le volume reçu dépasse le maximum', function () {
    $fichier = fopen('php://temp', 'w+b');

    expect(SourceSiro::copierBorne(Utils::streamFor(str_repeat('a', 64)), $fichier, 64))->toBe(64)
        ->and(fn () => SourceSiro::copierBorne(Utils::streamFor(str_repeat('a', 65)), $fichier, 64))
        ->toThrow(RuntimeException::class, 'trop volumineuse');
    fclose($fichier);
});

test('ressourceCourante : aucune redirection suivie depuis l’API data.gouv', function () {
    $appels = 0;
    Http::fake(function () use (&$appels) {
        $appels++;

        return Http::response('', 302, ['Location' => 'https://example.org/faux-jeu.json']);
    });

    expect(fn () => (new SourceSiro)->ressourceCourante())->toThrow(RuntimeException::class, 'statut HTTP 302');
    expect($appels)->toBe(1);
});

test('ressourceCourante : réponse JSON trop volumineuse refusée', function () {
    Http::fake(fn () => Http::response('{}', 200, ['Content-Length' => (string) (SourceSiro::TAILLE_MAX_JSON + 1)]));

    expect(fn () => (new SourceSiro)->ressourceCourante())->toThrow(RuntimeException::class, 'trop volumineuse');
});

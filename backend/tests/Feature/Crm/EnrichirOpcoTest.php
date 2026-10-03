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
 *  - aucune planification ; la fiche entreprise expose l'IDCC et l'OPCO.
 */

use App\Crm\Opco\EnrichissementOpco;
use App\Crm\Opco\LectureOpco;
use App\Crm\Opco\Opco;
use App\Crm\Opco\SourceSiro;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
    $source = new class($contenu, $releve, $echouer) extends SourceSiro
    {
        /** @var list<string> */
        public array $chemins = [];

        public int $appels = 0;

        public function __construct(private string $contenu, private ?string $titre, private bool $echouer) {}

        public function ressourceCourante(): array
        {
            $this->appels++;

            return self::choisirRessource(['resources' => [[
                'id' => 'zz-ressource-1',
                'title' => (string) $this->titre,
                'format' => 'csv',
                'type' => 'main',
                'url' => 'https://example.invalid/zz-siro.csv',
                'last_modified' => '2026-08-10T00:00:00',
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
    expect(\App\Crm\Opco\FenetreOpco::refus(CarbonImmutable::parse('2026-10-10 08:00', 'Europe/Paris')))->toBeNull()
        ->and(\App\Crm\Opco\FenetreOpco::refus(CarbonImmutable::parse('2026-10-06 18:59', 'Europe/Paris')))->toBeNull()
        ->and(\App\Crm\Opco\FenetreOpco::refus(CarbonImmutable::parse('2026-10-06 19:00', 'Europe/Paris')))->not->toBeNull()
        // Un instant UTC est lu à l'heure de Paris (06:30 UTC = 08:30 Paris en octobre).
        ->and(\App\Crm\Opco\FenetreOpco::refus(CarbonImmutable::parse('2026-10-06 06:30', 'UTC')))->toBeNull();
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

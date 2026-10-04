<?php

/**
 * ANNUAIRE OFFICIEL DE L'ADMINISTRATION — `crm:public:annuaire-officiel`
 * (décision du propriétaire, 04/10/2026).
 *
 * L'annuaire est SIMULÉ : un double de `SourceAnnuaire` écrit un PETIT
 * fichier JSONL fictif au lieu d'appeler le réseau ; le vrai téléchargement
 * est éprouvé sous `Http::fake`. Fixtures FICTIVES (dépôt public) : SIREN
 * 94xxxxxxx, numéros de la plage de fiction 01 99 00 xx xx, domaines
 * `.example`, codes de commune 999xx (aucune commune réelle).
 *
 * Ce qui est verrouillé :
 *  - `--dry-run` n'écrit RIEN (ni fiche, ni proposition, ni curseur) ;
 *  - rapprochement par SIRET ; mairie ↔ commune (7210) par code INSEE ;
 *    AUCUN rapprochement par le nom ; code de commune ambigu = rien ;
 *  - une valeur saisie n'est JAMAIS écrasée : une proposition est ouverte,
 *    une seule fois ;
 *  - le site de l'annuaire est FIABLE (`SiteFiable`), son adresse hors de la
 *    quarantaine, un site deviné identique est confirmé ;
 *  - hôte hors liste refusé (URL initiale, redirection, `--url`) ;
 *  - fenêtre 08:00-19:00, verrou « un seul traitement lourd », reprise ;
 *  - planification mensuelle après la mise à jour INSEE.
 */

use App\Crm\Annuaire\EnrichissementAnnuaire;
use App\Crm\Annuaire\OrganismeAnnuaire;
use App\Crm\Annuaire\SourceAnnuaire;
use App\Crm\FichesProtegees;
use App\Crm\Propositions\PropositionImpossible;
use App\Crm\Propositions\Propositions;
use App\Crm\Sites\QuarantaineSite;
use App\Crm\Sites\SiteFiable;
use App\Crm\TraitementsLourds;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Un mercredi 7 du mois, 10:00 heure de Paris : dans la fenêtre. */
const ANNUAIRE_DANS_LA_FENETRE = '2026-10-07 10:00:00';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(ANNUAIRE_DANS_LA_FENETRE, 'Europe/Paris'));
});

// ── Fixtures ──────────────────────────────────────────────────────────────

/**
 * Le double de `SourceAnnuaire` : le fichier est écrit à la demande ; les
 * chemins sont gardés pour vérifier qu'ils sont supprimés.
 *
 * @param  list<array<string, mixed>>  $organismes
 */
function annuaireSource(array $organismes): SourceAnnuaire
{
    $source = new class extends SourceAnnuaire
    {
        public string $contenu = '';

        /** @var list<string> */
        public array $chemins = [];

        public function telecharger(string $url, string $chemin): void
        {
            self::verifierUrlSansReseau($url);
            $this->chemins[] = $chemin;
            file_put_contents($chemin, $this->contenu);
        }

        /** La liste fermée d'hôtes, sans la résolution DNS de la garde SSRF. */
        public static function verifierUrlSansReseau(string $url): void
        {
            if (! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::HOTES_AUTORISES, true)) {
                throw new RuntimeException('hôte hors de la liste autorisée');
            }
        }
    };
    $source->contenu = implode("\n", array_map(
        static fn (array $o): string => json_encode($o, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $organismes,
    )) . "\n";
    app()->instance(SourceAnnuaire::class, $source);

    return $source;
}

/**
 * Un organisme au format de l'API (colonnes JSON en TEXTE, comme l'export).
 *
 * @param  array<string, mixed>  $attrs
 * @return array<string, mixed>
 */
function annuaireOrganisme(string $id, array $attrs = [], ?string $email = null, ?string $tel = null, ?string $site = null): array
{
    return array_merge([
        'id' => $id,
        'nom' => 'ZZ organisme ' . $id,
        'siret' => null,
        'siren' => null,
        'pivot' => '[]',
        'code_insee_commune' => null,
        'type_organisme' => null,
        'adresse_courriel' => $email,
        'telephone' => $tel === null ? null : json_encode([['valeur' => $tel, 'description' => '']]),
        'site_internet' => $site === null ? null : json_encode([['libelle' => '', 'valeur' => $site]]),
    ], $attrs);
}

/** @return array<string, mixed> */
function annuaireMairie(string $id, string $code, ?string $email = null, ?string $tel = null, ?string $site = null): array
{
    return annuaireOrganisme($id, [
        'pivot' => json_encode([['type_service_local' => 'mairie', 'code_insee_commune' => [$code]]]),
        'code_insee_commune' => $code,
    ], $email, $tel, $site);
}

/**
 * Une fiche du secteur public (catégorie 7…) à SIREN et SIRET fictifs.
 *
 * @param  array<string, mixed>  $attrs
 * @return array{id: int, siren: string, siret: string}
 */
function annuaireFiche(string $ws, string $nom, array $attrs = []): array
{
    $siren = F::siren();
    $siret = $siren . '00017';
    $id = F::fiche($ws, $nom, array_merge(['siren' => $siren, 'siret' => $siret, 'legal_form' => '7220'], $attrs));

    return ['id' => $id, 'siren' => $siren, 'siret' => $siret];
}

function annuaireLancer(string $ws, array $options = []): array
{
    $code = Artisan::call('crm:public:annuaire-officiel', ['--workspace' => $ws] + $options);

    return [$code, Artisan::output()];
}

function annuaireCompteur(string $sortie, string $libelle): ?int
{
    return preg_match('/' . preg_quote($libelle, '/') . ' : (\d+)/u', $sortie, $m) === 1 ? (int) $m[1] : null;
}

/** @return array<string, mixed> */
function annuaireLigne(int $id): array
{
    return (array) DB::table('companies')->where('id', $id)->first();
}

// ── Essai à blanc ─────────────────────────────────────────────────────────

test('--dry-run : bilan chiffré, RIEN d’écrit (fiche, proposition, curseur), fichier supprimé', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A');
    $b = annuaireFiche($ws, 'ZZ SYNDICAT B', ['email_generic' => 'saisie@zz-b.example']);
    $commune = F::fiche($ws, 'ZZ COMMUNE', ['legal_form' => '7210', 'commune_code' => '99901']);
    $avant = DB::table('companies')->where('workspace_id', $ws)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $source = annuaireSource([
        annuaireOrganisme('zz-1', ['siret' => $a['siret']], 'accueil@zz-a.example', '01 99 00 00 01', 'https://www.zz-a.example'),
        annuaireOrganisme('zz-2', ['siret' => $b['siret']], 'contact@zz-b.example'),
        annuaireMairie('zz-3', '99901', 'mairie@zz-commune.example'),
        annuaireOrganisme('zz-4', ['siret' => '94999999900011'], 'inconnu@zz.example'),
    ]);

    [$code, $sortie] = annuaireLancer($ws, ['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($sortie)->toContain('ESSAI À BLANC')
        ->and(annuaireCompteur($sortie, 'rapprochés par SIRET'))->toBe(2)
        ->and(annuaireCompteur($sortie, 'rapprochés par commune'))->toBe(1)
        ->and(annuaireCompteur($sortie, 'non rapprochés'))->toBe(1)
        ->and(annuaireCompteur($sortie, 'e-mails à ajouter'))->toBe(2)
        ->and(annuaireCompteur($sortie, 'téléphones à ajouter'))->toBe(1)
        ->and(annuaireCompteur($sortie, 'sites à ajouter'))->toBe(1)
        ->and(annuaireCompteur($sortie, 'conflits'))->toBe(1)
        ->and(DB::table('companies')->where('workspace_id', $ws)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($avant)
        ->and(DB::table('propositions_champs')->where('workspace_id', $ws)->count())->toBe(0)
        ->and(DB::table('curseurs_traitements')->where('workspace_id', $ws)->count())->toBe(0)
        ->and($source->chemins)->toHaveCount(1)
        ->and(file_exists($source->chemins[0]))->toBeFalse();
    expect($commune)->toBeInt();
});

// ── Rapprochement ─────────────────────────────────────────────────────────

test('SIRET : e-mail, téléphone et site écrits, origine et date tracées', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A');
    annuaireSource([
        annuaireOrganisme('zz-1', ['siret' => $a['siret']], 'Accueil@ZZ-A.example', '01 99 00 00 01', 'https://www.zz-a.example'),
    ]);

    [$code, $sortie] = annuaireLancer($ws);

    expect($code)->toBe(0)->and(annuaireCompteur($sortie, 'rapprochés par SIRET'))->toBe(1);
    $f = annuaireLigne($a['id']);
    $origines = json_decode((string) $f['field_origins'], true);
    $metadata = json_decode((string) $f['metadata'], true);
    expect($f['email_generic'])->toBe('accueil@zz-a.example')
        ->and($f['phone'])->toBe('01 99 00 00 01')
        ->and($f['website'])->toBe('https://www.zz-a.example')
        ->and($f['website_method'])->toBe('annuaire-service-public')
        ->and($origines)->toMatchArray([
            'email_generic' => 'annuaire-service-public',
            'phone' => 'annuaire-service-public',
            'website' => 'annuaire-service-public',
        ])
        ->and($metadata['annuaire_service_public'])->toBe(['id' => 'zz-1', 'le' => '2026-10-07']);
    expect($sortie)->toContain('avant : 1 fiches du secteur public — 0 avec e-mail')
        ->and($sortie)->toContain('après : 1 fiches du secteur public — 1 avec e-mail, 1 avec téléphone, 1 avec site');
});

test('MAIRIE : rapprochée de la commune (7210) par le code INSEE, sans SIRET', function () {
    $ws = F::espace('zz-annuaire');
    $commune = F::fiche($ws, 'ZZ COMMUNE', ['legal_form' => '7210', 'insee' => '99902']);
    annuaireSource([annuaireMairie('zz-m', '99902', 'mairie@zz-commune.example', '01 99 00 00 02')]);

    [$code, $sortie] = annuaireLancer($ws);

    expect($code)->toBe(0)->and(annuaireCompteur($sortie, 'rapprochés par commune'))->toBe(1);
    $f = annuaireLigne($commune);
    expect($f['email_generic'])->toBe('mairie@zz-commune.example')
        ->and($f['phone'])->toBe('01 99 00 00 02');
});

test('MAIRIE : code de commune ambigu (deux mairies, ou deux fiches) — rien n’est rapproché', function () {
    $ws = F::espace('zz-annuaire');
    $c1 = F::fiche($ws, 'ZZ COMMUNE 1', ['legal_form' => '7210', 'commune_code' => '99903']);
    $c2 = F::fiche($ws, 'ZZ COMMUNE 2', ['legal_form' => '7210', 'commune_code' => '99904']);
    $c2bis = F::fiche($ws, 'ZZ COMMUNE 2 BIS', ['legal_form' => '7210', 'commune_code' => '99904']);
    // Une commune dont les deux codes connus divergent.
    $c3 = F::fiche($ws, 'ZZ COMMUNE 3', ['legal_form' => '7210', 'commune_code' => '99905', 'insee' => '99906']);
    annuaireSource([
        annuaireMairie('zz-m1', '99903', 'm1@zz.example'),
        annuaireMairie('zz-m1-deleguee', '99903', 'm1bis@zz.example'),
        annuaireMairie('zz-m2', '99904', 'm2@zz.example'),
        annuaireMairie('zz-m3', '99905', 'm3@zz.example'),
    ]);

    [$code, $sortie] = annuaireLancer($ws);

    expect($code)->toBe(0)
        ->and(annuaireCompteur($sortie, 'rapprochés par commune'))->toBe(0)
        ->and(annuaireCompteur($sortie, 'ambigus'))->toBe(4);
    foreach ([$c1, $c2, $c2bis, $c3] as $id) {
        expect(annuaireLigne($id)['email_generic'])->toBeNull();
    }
});

test('AUCUN rapprochement par le nom : même dénomination, sans SIRET ni code — fiche intacte', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ OFFICE PUBLIC DE TEST');
    $avant = annuaireLigne($a['id']);
    annuaireSource([
        annuaireOrganisme('zz-nom', ['nom' => 'ZZ OFFICE PUBLIC DE TEST'], 'office@zz.example', '01 99 00 00 03'),
    ]);

    [$code, $sortie] = annuaireLancer($ws);

    expect($code)->toBe(0)
        ->and(annuaireCompteur($sortie, 'non rapprochés'))->toBe(1)
        ->and(annuaireCompteur($sortie, 'rapprochés par SIRET'))->toBe(0)
        ->and(annuaireLigne($a['id']))->toBe($avant);
});

test('un SIRET porté par deux organismes de l’annuaire est ambigu : rien n’est écrit', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A');
    annuaireSource([
        annuaireOrganisme('zz-1', ['siret' => $a['siret']], 'un@zz.example'),
        annuaireOrganisme('zz-2', ['siret' => $a['siret']], 'deux@zz.example'),
    ]);

    [, $sortie] = annuaireLancer($ws);

    expect(annuaireCompteur($sortie, 'ambigus'))->toBe(2)
        ->and(annuaireLigne($a['id'])['email_generic'])->toBeNull();
});

test('hors secteur public et non diffusible : jamais enrichis', function () {
    $ws = F::espace('zz-annuaire');
    $privee = annuaireFiche($ws, 'ZZ SAS', ['legal_form' => '5710']);
    $nd = annuaireFiche($ws, 'ZZ ND', ['insee_non_diffusible_le' => '2026-09-01']);
    annuaireSource([
        annuaireOrganisme('zz-p', ['siret' => $privee['siret']], 'p@zz.example'),
        annuaireOrganisme('zz-n', ['siret' => $nd['siret']], 'n@zz.example'),
    ]);

    [, $sortie] = annuaireLancer($ws);

    expect(annuaireLigne($privee['id'])['email_generic'])->toBeNull()
        ->and(annuaireLigne($nd['id'])['email_generic'])->toBeNull()
        ->and($sortie)->toContain('hors secteur public : 1')
        ->and($sortie)->toContain('non diffusibles : 1');
});

// ── Jamais d'écrasement ───────────────────────────────────────────────────

test('valeur SAISIE jamais écrasée : une proposition est ouverte, une seule fois', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A', [
        'phone' => '01 99 00 00 09',
        'email_generic' => 'saisie@zz-a.example',
        'field_origins' => json_encode(['email_generic' => 'declared']),
    ]);
    annuaireSource([
        annuaireOrganisme('zz-1', ['siret' => $a['siret']], 'accueil@zz-a.example', '01 99 00 00 01'),
    ]);

    [$code, $sortie] = annuaireLancer($ws);

    $f = annuaireLigne($a['id']);
    expect($code)->toBe(0)
        ->and($f['email_generic'])->toBe('saisie@zz-a.example')
        ->and($f['phone'])->toBe('01 99 00 00 09')
        ->and(annuaireCompteur($sortie, 'conflits'))->toBe(2);
    $propositions = DB::table('propositions_champs')->where('workspace_id', $ws)->orderBy('champ')->get();
    expect($propositions)->toHaveCount(2)
        ->and($propositions[0]->champ)->toBe('email_generic')
        ->and($propositions[0]->valeur_actuelle)->toBe('saisie@zz-a.example')
        ->and($propositions[0]->valeur_proposee)->toBe('accueil@zz-a.example')
        ->and($propositions[0]->origine)->toBe('annuaire-service-public')
        ->and($propositions[0]->statut)->toBe('en_attente')
        ->and($propositions[1]->champ)->toBe('phone');

    // Mois suivant : la même valeur n'ouvre pas une seconde proposition.
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00', 'Europe/Paris'));
    [, $sortie2] = annuaireLancer($ws);
    expect(DB::table('propositions_champs')->where('workspace_id', $ws)->count())->toBe(2)
        ->and($sortie2)->toContain('déjà proposées ou refusées : 2');
});

test('fiche PROTÉGÉE : même un champ vide n’est pas rempli — proposition', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ FEDERATION PUBLIQUE');
    F::proteger($ws, $a['id'], FichesProtegees::TAGS[0]);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], 'accueil@zz-a.example')]);

    annuaireLancer($ws);

    expect(annuaireLigne($a['id'])['email_generic'])->toBeNull()
        ->and(DB::table('propositions_champs')->where('entite_id', $a['id'])->value('champ'))->toBe('email_generic');
});

test('une valeur posée par un passage PRÉCÉDENT de l’annuaire suit l’annuaire', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A', [
        'email_generic' => 'ancienne@zz-a.example',
        'field_origins' => json_encode(['email_generic' => 'annuaire-service-public']),
    ]);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], 'nouvelle@zz-a.example')]);

    [, $sortie] = annuaireLancer($ws);

    expect(annuaireLigne($a['id'])['email_generic'])->toBe('nouvelle@zz-a.example')
        ->and(DB::table('propositions_champs')->count())->toBe(0)
        ->and($sortie)->toContain('(mis à jour : 1)');
});

// ── Le site de l'annuaire est FIABLE ──────────────────────────────────────

test('site de l’annuaire = FIABLE : jamais « non vérifié », adresse hors quarantaine', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A');
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], 'accueil@zz-a.example', null, 'https://www.zz-a.example')]);

    annuaireLancer($ws);

    $r = DB::selectOne(
        'SELECT ' . SiteFiable::fiableSql('c') . ' AS fiable, ' . SiteFiable::nonVerifieSql('c') . ' AS non_verifie, '
        . QuarantaineSite::generiqueSql('c') . ' AS quarantaine FROM companies c WHERE c.id = ?',
        [$a['id']],
    );
    expect($r->fiable)->toBeTrue()
        ->and($r->non_verifie)->toBeFalse()
        ->and($r->quarantaine)->toBeFalse()
        ->and(SiteFiable::estMethodeDevinee(annuaireLigne($a['id'])['website_method']))->toBeFalse();
});

test('site DEVINÉ identique à celui de l’annuaire : confirmé (marqueur vérifié), rien d’écrasé', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A', ['website' => 'http://zz-a.example/', 'website_method' => 'guess']);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], null, null, 'https://www.zz-a.example')]);

    [, $sortie] = annuaireLancer($ws);

    $f = annuaireLigne($a['id']);
    expect($f['website'])->toBe('http://zz-a.example/')
        ->and($f['website_method'])->toBe('guess')
        ->and(SiteFiable::estNonVerifie($f['website_method'], $f['metadata']))->toBeFalse()
        ->and(json_decode((string) $f['metadata'], true)['site_entreprise']['preuve'])->toBe('annuaire-service-public')
        ->and($sortie)->toContain('sites devinés confirmés : 1');
});

test('site DEVINÉ différent : proposition ; acceptée, le site devient fiable', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A', ['website' => 'https://zz-mauvais.example', 'website_method' => 'guess']);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], null, null, 'https://www.zz-a.example')]);

    annuaireLancer($ws);

    $p = DB::table('propositions_champs')->where('entite_id', $a['id'])->first();
    expect($p->champ)->toBe('website')
        ->and(annuaireLigne($a['id'])['website'])->toBe('https://zz-mauvais.example');

    $fiche = DB::table('companies')->where('id', $a['id'])->first();
    $owner = User::create([
        'id' => (string) Str::uuid(), 'email' => 'zz-owner-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ owner',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $owner->assignRole('owner');
    app(Propositions::class)->accepter($ws, (int) $p->id, $owner, Propositions::empreinte(Propositions::ENTREPRISE, $fiche, 'website'));

    $f = annuaireLigne($a['id']);
    expect($f['website'])->toBe('https://www.zz-a.example')
        ->and($f['website_method'])->toBe('annuaire-service-public')
        ->and(SiteFiable::estNonVerifie($f['website_method'], $f['metadata']))->toBeFalse();
});

// ── Relecture #329 : quarantaine et unicité du SIREN ──────────────────────

test('SIREN : un service sans SIRET partageant le SIREN de la mairie est AMBIGU — la fiche reçoit la mairie', function () {
    $ws = F::espace('zz-annuaire');
    $commune = annuaireFiche($ws, 'ZZ COMMUNE Y', ['legal_form' => '7210']);
    annuaireSource([
        // La ligne du service arrive AVANT celle de la mairie.
        annuaireOrganisme('zz-mediatheque', ['siren' => $commune['siren']], 'mediatheque@zz-y.example', '01 99 00 00 05'),
        annuaireOrganisme('zz-mairie', ['siret' => $commune['siret']], 'mairie@zz-y.example', '01 99 00 00 06'),
    ]);

    [, $sortie] = annuaireLancer($ws);

    $f = annuaireLigne($commune['id']);
    expect($f['email_generic'])->toBe('mairie@zz-y.example')
        ->and($f['phone'])->toBe('01 99 00 00 06')
        ->and(annuaireCompteur($sortie, 'rapprochés par SIRET'))->toBe(1)
        ->and(annuaireCompteur($sortie, 'rapprochés par SIREN'))->toBe(0)
        ->and($sortie)->toContain('ambigus : 1');
});

test('site deviné CONFIRMÉ mais adresse générique d’un autre domaine : rien n’est marqué, l’adresse reste en quarantaine', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A', [
        'website' => 'http://zz-a.example/', 'website_method' => 'guess', 'email_generic' => 'contact@zz-ailleurs.example',
    ]);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], null, null, 'https://www.zz-a.example')]);

    [, $sortie] = annuaireLancer($ws);

    $f = annuaireLigne($a['id']);
    $r = DB::selectOne('SELECT ' . QuarantaineSite::generiqueSql('c') . ' AS q FROM companies c WHERE c.id = ?', [$a['id']]);
    expect(SiteFiable::estNonVerifie($f['website_method'], $f['metadata']))->toBeTrue()
        ->and($r->q)->toBeTrue()
        ->and($sortie)->toContain('sites devinés confirmés : 0')
        ->and($sortie)->toContain('adresses non garanties sur la fiche) : 1');
});

test('site écrit sur une fiche « devinée » dont une personne vient de l’ancien site : site non écrit', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A', ['website_method' => 'guess']);
    F::contact($ws, $a['id'], 'Zz', 'Releve', ['email' => 'zz.releve@zz-ailleurs.example', 'discovery_source' => 'site']);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], null, null, 'https://www.zz-a.example')]);

    annuaireLancer($ws);

    $f = annuaireLigne($a['id']);
    expect($f['website'])->toBeNull()->and($f['website_method'])->toBe('guess');
});

test('accepter le site de l’annuaire ne rend pas envoyable l’adresse tirée de l’ancien site deviné', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ COMMUNE X', [
        'legal_form' => '7210',
        'website' => 'https://zz-immobilier.example', 'website_method' => 'guess',
        'email_generic' => 'contact@zz-immobilier.example',
    ]);
    F::contact($ws, $a['id'], 'Zz', 'Agent', ['email' => 'agent@zz-immobilier.example', 'discovery_source' => 'site']);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], null, null, 'https://www.zz-ville-x.example')]);
    annuaireLancer($ws);
    $p = DB::table('propositions_champs')->where('entite_id', $a['id'])->where('champ', 'website')->first();
    $fiche = DB::table('companies')->where('id', $a['id'])->first();
    $owner = User::create([
        'id' => (string) Str::uuid(), 'email' => 'zz-owner-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ owner',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $owner->assignRole('owner');

    expect(fn () => app(Propositions::class)->accepter($ws, (int) $p->id, $owner, Propositions::empreinte(Propositions::ENTREPRISE, $fiche, 'website')))
        ->toThrow(PropositionImpossible::class, 'ancien site deviné');

    $f = annuaireLigne($a['id']);
    $r = DB::selectOne('SELECT ' . QuarantaineSite::generiqueSql('c') . ' AS q FROM companies c WHERE c.id = ?', [$a['id']]);
    expect($f['website'])->toBe('https://zz-immobilier.example')
        ->and($f['website_method'])->toBe('guess')
        ->and($r->q)->toBeTrue()
        ->and(DB::table('propositions_champs')->where('id', $p->id)->value('statut'))->toBe('en_attente');
});

test('site écrit sur une fiche « devinée » dont un CANAL typé vient d’un autre domaine : site non écrit', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A', [
        'website_method' => 'guess',
        'signals' => json_encode(['contact_channels' => [
            'emails' => ['contact@zz-immobilier.example'],
            'details' => ['agence@zz-immobilier.example' => ['type' => 'generique']],
        ]]),
    ]);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], null, null, 'https://www.zz-a.example')]);

    annuaireLancer($ws);

    $f = annuaireLigne($a['id']);
    expect($f['website'])->toBeNull()->and($f['website_method'])->toBe('guess');
});

test('site écrit sur une fiche « devinée » dont une personne est liée à un contact du site SUPPRIMÉ : site non écrit', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ DEPARTEMENT A', ['website_method' => 'guess']);
    $contact = F::contact($ws, $a['id'], 'Zz', 'Releve', [
        'email' => 'zz.releve@zz-ailleurs.example', 'discovery_source' => 'site', 'deleted_at' => now(),
    ]);
    DB::table('personnes')->insert([
        'workspace_id' => $ws, 'company_id' => $a['id'], 'contact_id' => $contact, 'email' => 'zz.releve@zz-ailleurs.example',
        'person_key' => hash('sha256', 'zz-releve-supprime'), 'premiere_source' => 'newsletter', 'premiere_source_at' => now(), 'legal_basis' => 'consent',
    ]);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], null, null, 'https://www.zz-a.example')]);

    annuaireLancer($ws);

    $f = annuaireLigne($a['id']);
    expect($f['website'])->toBeNull()->and($f['website_method'])->toBe('guess');
});

test('accepter le site de l’annuaire ne rend pas envoyable un CANAL typé tiré de l’ancien site deviné', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ COMMUNE X', [
        'legal_form' => '7210',
        'website' => 'https://zz-immobilier.example', 'website_method' => 'guess',
        'signals' => json_encode(['contact_channels' => [
            'emails' => ['contact@zz-immobilier.example', 'agence@zz-immobilier.example'],
            'details' => ['contact@zz-immobilier.example' => ['type' => 'generique'], 'agence@zz-immobilier.example' => ['type' => 'generique']],
        ]]),
    ]);
    annuaireSource([annuaireOrganisme('zz-1', ['siret' => $a['siret']], null, null, 'https://www.zz-ville-x.example')]);
    annuaireLancer($ws);
    $p = DB::table('propositions_champs')->where('entite_id', $a['id'])->where('champ', 'website')->first();
    $fiche = DB::table('companies')->where('id', $a['id'])->first();
    $owner = User::create([
        'id' => (string) Str::uuid(), 'email' => 'zz-owner-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ owner',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $owner->assignRole('owner');

    expect($p)->not->toBeNull();
    expect(fn () => app(Propositions::class)->accepter($ws, (int) $p->id, $owner, Propositions::empreinte(Propositions::ENTREPRISE, $fiche, 'website')))
        ->toThrow(PropositionImpossible::class, 'ancien site deviné');

    $f = annuaireLigne($a['id']);
    expect($f['website'])->toBe('https://zz-immobilier.example')
        ->and($f['website_method'])->toBe('guess')
        ->and(DB::table('propositions_champs')->where('id', $p->id)->value('statut'))->toBe('en_attente');
});

test('la migration rejouée garde une origine ÉTRANGÈRE déjà admise par le CHECK (union prouvée)', function () {
    $def = (string) DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'propositions_champs_origine_check'",
    )->d;
    preg_match_all("/'([a-z][a-z0-9_-]{0,63})'/", $def, $m);
    $origines = array_values(array_unique(array_merge($m[1], ['zz-autre'])));
    DB::statement('ALTER TABLE propositions_champs DROP CONSTRAINT propositions_champs_origine_check');
    DB::statement('ALTER TABLE propositions_champs ADD CONSTRAINT propositions_champs_origine_check CHECK (origine IN ('
        . implode(', ', array_map(static fn (string $o): string => "'{$o}'", $origines)) . '))');

    (require database_path('migrations/2026_10_07_000010_propositions_origine_annuaire.php'))->up();

    $apres = (string) DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'propositions_champs_origine_check'",
    )->d;
    expect($apres)->toContain("'zz-autre'")->and($apres)->toContain("'annuaire-service-public'");
    foreach (Propositions::ORIGINES_EN_BASE as $origine) {
        expect($apres)->toContain("'{$origine}'");
    }
});

test('le CHECK d’origine garde les origines déjà admises par une autre migration (union)', function () {
    $def = (string) DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'propositions_champs_origine_check'",
    )->d;

    foreach (Propositions::ORIGINES_EN_BASE as $origine) {
        expect($def)->toContain("'{$origine}'");
    }
});

// ── Hôtes, fenêtre, verrou, reprise, planification ────────────────────────

dataset('URL refusées', [
    'hôte hors liste' => ['https://example.org/annuaire.jsonl', 'hors de la liste'],
    'hôte voisin' => ['https://api-lannuaire.service-public.fr.example.org/x', 'hors de la liste'],
    'IP interne' => ['https://169.254.169.254/latest/meta-data', 'hors de la liste'],
    'en http' => ['http://api-lannuaire.service-public.gouv.fr/x', 'https'],
    'autre port' => ['https://www.data.gouv.fr:8443/x', 'port 443'],
    'identifiants' => ['https://moi:secret@static.data.gouv.fr/x', 'identifiants'],
]);

test('hôte hors liste refusé AVANT toute requête', function (string $url, string $motif) {
    Http::fake();
    $chemin = tempnam(sys_get_temp_dir(), 'zz-annuaire-');

    expect(fn () => (new SourceAnnuaire)->telecharger($url, $chemin))->toThrow(RuntimeException::class, $motif);
    Http::assertNothingSent();
    @unlink($chemin);
})->with('URL refusées');

test('une redirection vers un hôte hors liste est refusée, la cible jamais contactée', function () {
    $urls = [];
    Http::fake(function (Request $r) use (&$urls) {
        $urls[] = $r->url();

        return Http::response('', 302, ['Location' => 'https://example.org/annuaire.jsonl']);
    });
    $chemin = tempnam(sys_get_temp_dir(), 'zz-annuaire-');

    expect(fn () => (new SourceAnnuaire)->telecharger('https://www.data.gouv.fr/fr/datasets/r/zz', $chemin))
        ->toThrow(RuntimeException::class, 'hors de la liste');
    expect($urls)->toBe(['https://www.data.gouv.fr/fr/datasets/r/zz']);
    @unlink($chemin);
});

test('--url hors liste : refus, rien n’est lu', function () {
    $ws = F::espace('zz-annuaire');
    $source = annuaireSource([]);

    [$code, $sortie] = annuaireLancer($ws, ['--url' => 'https://example.org/annuaire.jsonl']);

    expect($code)->toBe(1)->and($sortie)->toContain('hors de la liste')->and($source->chemins)->toBe([]);
});

test('l’URL par défaut est sur un hôte de la liste et ne demande que les colonnes utiles', function () {
    $url = SourceAnnuaire::urlParDefaut();

    expect(SourceAnnuaire::HOTES_AUTORISES)->toContain(parse_url($url, PHP_URL_HOST))
        ->and($url)->toStartWith('https://')
        ->and($url)->toContain('exports/jsonl')
        ->and(urldecode($url))->toContain('select=id,nom,siret,siren,pivot');
});

test('fenêtre : refus à 19:00 et avant 08:00, heure de Paris', function () {
    $ws = F::espace('zz-annuaire');
    $source = annuaireSource([]);
    foreach (['2026-10-07 19:00:00', '2026-10-11 07:59:00'] as $instant) {
        $this->travelTo(CarbonImmutable::parse($instant, 'Europe/Paris'));
        [$code, $sortie] = annuaireLancer($ws);
        expect($code)->toBe(1)->and($sortie)->toContain('08:00 et 19:00');
    }
    expect($source->chemins)->toBe([]);
});

test('verrou : un autre traitement lourd en cours — refus', function () {
    $ws = F::espace('zz-annuaire');
    $source = annuaireSource([]);
    config(['database.connections.zz_autre_session' => config('database.connections.' . config('database.default'))]);
    $autre = DB::connection('zz_autre_session');
    $autre->select('SELECT pg_advisory_lock(hashtext(?))', [TraitementsLourds::VERROU]);

    try {
        [$code, $sortie] = annuaireLancer($ws);
    } finally {
        $autre->select('SELECT pg_advisory_unlock(hashtext(?))', [TraitementsLourds::VERROU]);
        DB::purge('zz_autre_session');
    }

    expect($code)->toBe(1)->and($sortie)->toContain('un seul à la fois')->and($source->chemins)->toBe([]);
});

test('reprise : --limite puis le passage suivant reprend au curseur ; fini, le mois est marqué', function () {
    $ws = F::espace('zz-annuaire');
    $a = annuaireFiche($ws, 'ZZ A');
    $b = annuaireFiche($ws, 'ZZ B');
    annuaireSource([
        annuaireOrganisme('zz-1', ['siret' => $a['siret']], 'a@zz.example'),
        annuaireOrganisme('zz-2', ['siret' => $b['siret']], 'b@zz.example'),
    ]);

    [, $sortie] = annuaireLancer($ws, ['--limite' => 1]);
    expect($sortie)->toContain('INACHEVÉ')
        ->and(annuaireLigne($a['id'])['email_generic'])->toBe('a@zz.example')
        ->and(annuaireLigne($b['id'])['email_generic'])->toBeNull()
        ->and(EnrichissementAnnuaire::moisTermine($ws, now()))->toBeFalse();

    [, $sortie2] = annuaireLancer($ws);
    expect($sortie2)->toContain('reprise')
        ->and(annuaireCompteur($sortie2, 'organismes lus'))->toBe(1)
        ->and(annuaireLigne($b['id'])['email_generic'])->toBe('b@zz.example')
        ->and(EnrichissementAnnuaire::moisTermine($ws, now()))->toBeTrue();
});

test('planification : mensuelle, à partir du 6 (après la mise à jour INSEE), sans chevauchement', function () {
    $evenements = collect(app(Schedule::class)->events())
        ->filter(fn ($ev) => str_contains((string) $ev->command, 'crm:public:annuaire-officiel'))
        ->values();

    expect($evenements)->toHaveCount(1)
        ->and($evenements[0]->expression)->toBe('0 15 * * *')
        ->and($evenements[0]->withoutOverlapping)->toBeTrue()
        ->and($evenements[0]->timezone)->toBe('Europe/Paris')
        ->and(EnrichissementAnnuaire::estJourPlanifie(CarbonImmutable::parse('2026-10-04 15:00', 'Europe/Paris')))->toBeFalse()
        ->and(EnrichissementAnnuaire::estJourPlanifie(CarbonImmutable::parse('2026-10-05 15:00', 'Europe/Paris')))->toBeFalse()
        ->and(EnrichissementAnnuaire::estJourPlanifie(CarbonImmutable::parse('2026-10-06 15:00', 'Europe/Paris')))->toBeTrue()
        ->and(EnrichissementAnnuaire::estJourPlanifie(CarbonImmutable::parse('2026-10-06 19:00', 'Europe/Paris')))->toBeFalse();
});

// ── Lecture d'une ligne de l'annuaire ─────────────────────────────────────

test('lecture : colonnes JSON en texte ou décodées, valeurs au format inattendu ignorées', function () {
    $o = OrganismeAnnuaire::depuis([
        'id' => 'zz',
        'siret' => '949 999 999 00017',
        'pivot' => [['type_service_local' => 'mairie', 'code_insee_commune' => ['99907']]],
        'adresse_courriel' => 'pas-une-adresse;Contact@ZZ.example',
        'telephone' => '[{"valeur": "abc"}, {"valeur": "+33 1 99 00 00 04"}]',
        'site_internet' => '[{"valeur": "zz.example"}, {"valeur": "https://www.zz.example"}]',
    ]);

    expect($o->siret)->toBe('94999999900017')
        ->and($o->siren)->toBe('949999999')
        ->and($o->estMairie)->toBeTrue()
        ->and($o->codeMairie)->toBe('99907')
        ->and($o->email)->toBe('contact@zz.example')
        ->and($o->telephone)->toBe('+33 1 99 00 00 04')
        // jamais de schéma deviné : « zz.example » est ignoré
        ->and($o->site)->toBe('https://www.zz.example')
        ->and(OrganismeAnnuaire::depuisLigne('{pas du json'))->toBeNull();
});

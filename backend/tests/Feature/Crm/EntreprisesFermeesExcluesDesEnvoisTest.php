<?php

/**
 * ENTREPRISES FERMÉES SELON L'INSEE : PLUS JAMAIS DESTINATAIRES D'UN ENVOI,
 * TOUJOURS DANS LES EXPORTS (décision du propriétaire, 05/10/2026 : « on
 * peut toujours les exporter, mais on ne leur écrit plus »).
 *
 * Une adresse rattachée à une fiche marquée fermée par la mise à jour INSEE
 * (`companies.insee_ferme_le`) est exclue, motif `entreprise_fermee`, par la
 * règle unique (`EligibiliteAdresse`) — donc dans l'aperçu d'une audience
 * (`ResolveurDestinataires`) ET dans la liste en fichier
 * (`crm:campagne:destinataires`). Une fiche OUVERTE identique part. L'export
 * CSV des entreprises, lui, garde la fermée. Rien n'est effacé ni modifié.
 *
 * Le dernier test rejoue le résolveur sous le rôle de production
 * (`axion_app`, RLS forcée) : il COMMIT et nettoie ce qu'il a semé.
 * Fixtures FICTIVES (dépôt public) : dénominations « ZZ », adresses en
 * `example.invalid`, SIREN fictifs.
 */

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Campagnes\ReglageDestinataires;
use App\Crm\Campagnes\ResolveurDestinataires;
use App\Crm\Emails\VerificationEmail;
use App\Crm\FichesProtegees;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Les `signals` d'une fiche dont la boîte générique est vérifiée VALIDE, avec
 * en option un CANAL typé « générique » (autre adresse de la fiche, #255),
 * vérifié VALIDE lui aussi.
 */
function fevSignals(string $email, ?string $canal = null): string
{
    $signals = ['email_generic_verification' => [
        'statut' => VerificationEmail::VALIDE, 'motif' => 'mx', 'verifie_par' => VerificationEmail::SOURCE,
        'empreinte' => VerificationEmail::empreinte(mb_strtolower(trim($email))), 'type' => 'generique',
    ]];
    if ($canal !== null) {
        $signals['contact_channels'] = ['details' => [$canal => [
            'type' => 'generique', 'statut' => VerificationEmail::VALIDE, 'motif' => 'mx', 'verifie_par' => VerificationEmail::SOURCE,
            'empreinte' => VerificationEmail::empreinte(mb_strtolower(trim($canal))),
        ]]];
    }

    return (string) json_encode($signals);
}

/** Les `metadata` d'une personne dont l'adresse est vérifiée VALIDE. */
function fevMetaPersonne(string $email): string
{
    return (string) json_encode(['email_verification' => [
        'statut' => VerificationEmail::VALIDE, 'motif' => 'mx', 'verifie_par' => VerificationEmail::SOURCE,
        'empreinte' => VerificationEmail::empreinte(mb_strtolower(trim($email))),
    ]]);
}

/**
 * Ce que la mise à jour INSEE pose sur une fiche fermée (état `C`). Aucune
 * porte d'envoi ne filtre `prospection_status` : c'est bien le motif qui
 * écarte la fiche, pas son archivage.
 *
 * @return array<string, string>
 */
function fevFermee(): array
{
    return ['insee_ferme_le' => '2026-09-15', 'prospection_status' => 'archived_no_email', 'archive_reason' => 'entreprise_radiee'];
}

test('la règle : le motif entreprise_fermee, sa place dans l ordre des motifs', function () {
    $base = ['status' => null, 'verification' => VerificationEmail::VALIDE, 'perso' => false, 'deja_informe' => false];
    $email = 'contact@zz-fev-regle.example.invalid';
    $motif = static fn (array ...$occ): ?string => EligibiliteAdresse::motif($email, array_map(static fn (array $o): array => $o + $base, $occ));

    expect(array_slice(EligibiliteAdresse::MOTIFS, 0, 3))->toBe([
        EligibiliteAdresse::NON_DIFFUSIBLE, EligibiliteAdresse::ENTREPRISE_FERMEE, EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE,
    ])
        // Une adresse parfaite d'une entreprise fermée ne part pas.
        ->and($motif(['entreprise_fermee' => true]))->toBe(EligibiliteAdresse::ENTREPRISE_FERMEE)
        // Une seule occurrence fermée suffit (même boîte portée par une fiche ouverte).
        ->and($motif(['entreprise_fermee' => false], ['entreprise_fermee' => true]))->toBe(EligibiliteAdresse::ENTREPRISE_FERMEE)
        // Opposée ET fermée : reste comptée comme opposée (motif inchangé).
        ->and($motif(['non_diffusible' => true, 'entreprise_fermee' => true]))->toBe(EligibiliteAdresse::NON_DIFFUSIBLE)
        // Fermée passe avant l'EI, la quarantaine, le tiers et l'invalide.
        ->and($motif(['entreprise_fermee' => true, 'entreprise_individuelle' => true, 'site_non_verifie' => true]))
        ->toBe(EligibiliteAdresse::ENTREPRISE_FERMEE)
        ->and($motif(['entreprise_fermee' => true, 'information_tiers_insuffisante' => true, 'verification' => VerificationEmail::INVALIDE]))
        ->toBe(EligibiliteAdresse::ENTREPRISE_FERMEE)
        // Témoin : sans drapeau, l'adresse part.
        ->and($motif([]))->toBeNull();
});

test('aperçu d une audience : la fermée, son canal et sa personne sont exclus (entreprise_fermee), l ouverte identique part', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $ws = F::espace('zz-fev-apercu');
    $cible = F::tag($ws, 'zz-cible-fev');
    $ids = [];
    foreach (['ouverte' => [], 'fermee' => fevFermee()] as $cle => $insee) {
        $generique = 'contact@zz-fev-' . $cle . '.example.invalid';
        $personne = 'zoe@zz-fev-' . $cle . '.example.invalid';
        // Un CANAL : une autre adresse de la fiche (chemin propre du résolveur).
        $canal = 'accueil@zz-fev-' . $cle . '.example.invalid';
        $ids[$cle] = F::fiche($ws, 'ZZ FEV ' . strtoupper($cle), $insee + [
            'legal_form' => '5710', 'email_generic' => $generique, 'signals' => fevSignals($generique, $canal),
        ]);
        F::lier($ws, $ids[$cle], $cible);
        F::contact($ws, $ids[$cle], 'Zoe', 'ZZFEV', ['email' => $personne, 'metadata' => fevMetaPersonne($personne)]);
    }

    $r = app(ResolveurDestinataires::class)->resoudre(
        $ws,
        ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible-fev']]]],
        new ReglageDestinataires(ReglageDestinataires::LES_DEUX),
        null,
    );

    $adresses = collect($r['lignes'])->pluck('email')->sort()->values()->all();
    // Générique, canal ET personne : l'ouverte les envoie (témoin des trois
    // chemins), la fermée aucun.
    expect($adresses)->toBe([
        'accueil@zz-fev-ouverte.example.invalid', 'contact@zz-fev-ouverte.example.invalid', 'zoe@zz-fev-ouverte.example.invalid',
    ])
        ->and($r['exclues'][EligibiliteAdresse::ENTREPRISE_FERMEE])->toBe(3)
        ->and($r['organisations'])->toBe(2)
        ->and($r['organisations_sans_destinataire'])->toBe(1);

    // Rien n'a bougé en base.
    $ligne = DB::table('companies')->where('id', $ids['fermee'])->first();
    expect($ligne->deleted_at)->toBeNull()->and((string) $ligne->insee_ferme_le)->toStartWith('2026-09-15');
});

test('liste en fichier : la fermée et sa personne sont écartées (ecartees_entreprise_fermee), l ouverte part', function () {
    $ws = F::espace('zz-fev-liste');
    config(['crm.ingest.business_workspace' => F::slug($ws)]);
    foreach ([['ZZ FEV Ouverte', [], 'bureau@zz-fev-ouverte.example.invalid'], ['ZZ FEV Fermee', fevFermee(), 'bureau@zz-fev-fermee.example.invalid']] as [$nom, $insee, $email]) {
        $id = F::fiche($ws, $nom, $insee + ['legal_form' => '5710', 'email_generic' => $email]);
        F::proteger($ws, $id, FichesProtegees::TAG_ORGANISATEURS);
        // Une PERSONNE de la fiche (chemin propre des contacts), vérifiée par
        // `toutVerifier()` comme la boîte générique.
        F::contact($ws, $id, 'Zoe', 'ZZFEV', ['email' => str_replace('bureau@', 'zoe@', $email)]);
    }

    ResolveurDnsSimule::toutVerifier();
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-fev-');
    try {
        Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $fichier]);
        $sortie = Artisan::output();
        $emails = [];
        foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
            $emails[] = (string) json_decode($ligne, true)['email'];
        }
    } finally {
        @unlink($fichier);
    }

    sort($emails);
    // La boîte ET la personne de l'ouverte partent (témoin des deux chemins) ;
    // rien de la fermée.
    expect($emails)->toBe(['bureau@zz-fev-ouverte.example.invalid', 'zoe@zz-fev-ouverte.example.invalid'])
        ->and(F::compteur($sortie, 'ecartees_entreprise_fermee'))->toBe(2)
        ->and(F::compteur($sortie, 'ecartees_entreprise_individuelle'))->toBe(0);
});

test('export CSV des entreprises : la fermée Y RESTE (décision : on exporte toujours)', function () {
    $ws = F::espace('zz-fev-export');
    $commun = ['siren' => null, 'department_code' => '38'];
    F::fiche($ws, 'ZZ FEV EXPORT OUVERTE', $commun + ['email_generic' => 'contact@zz-fev-export-ouverte.example.invalid', 'prospection_status' => 'ready_for_outreach']);
    F::fiche($ws, 'ZZ FEV EXPORT FERMEE', $commun + fevFermee() + ['email_generic' => 'contact@zz-fev-export-fermee.example.invalid']);
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ws);
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => 'owner-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ owner',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws,
        'first_login_completed_at' => now(),
    ]);
    $u->assignRole('owner');
    $this->actingAs($u);

    $csv = $this->get('/api/v1/companies/export')->assertOk()->streamedContent();

    expect($csv)->toContain('ZZ FEV EXPORT OUVERTE')
        ->and($csv)->toContain('ZZ FEV EXPORT FERMEE')
        ->and($csv)->toContain('zz-fev-export-fermee.example.invalid');
});

function fevProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return array{id: string, ouverte: int, fermee: int} */
function fevEspace(): array
{
    $owner = fevProprio();
    $id = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $id), 0, 8);
    $owner->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-fev-rls-' . $marque, 'name' => 'ZZ FEV RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ids = [];
    foreach (['ouverte' => null, 'fermee' => '2026-09-15'] as $cle => $fermeLe) {
        $email = $cle . '@zz-fev-rls-' . $marque . '.example.invalid';
        $ids[$cle] = (int) $owner->table('companies')->insertGetId([
            'workspace_id' => $id, 'siren' => '95' . random_int(1000000, 9999999), 'denomination' => 'ZZ FEV Rls ' . $cle . ' ' . $marque,
            'legal_form' => '5710', 'email_generic' => $email, 'metadata' => '{}', 'quality_score' => 0, 'insee_ferme_le' => $fermeLe,
            'signals' => fevSignals($email), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['id' => $id, 'ouverte' => $ids['ouverte'], 'fermee' => $ids['fermee']];
}

/** @param  array{id: string}  $e */
function fevNettoyer(array $e): void
{
    $owner = fevProprio();
    $owner->transaction(function () use ($owner, $e): void {
        // Nettoyage du TEST (données semées ici) — le produit ne supprime rien.
        foreach (['contacts', 'companies'] as $table) {
            $owner->table($table)->where('workspace_id', $e['id'])->delete();
        }
        $owner->table('workspaces')->where('id', $e['id'])->delete();
    });
}

test('sous axion_app (RLS) : la fermée est exclue, l ouverte part, rien de l espace voisin (lui aussi une ouverte et une fermée)', function () {
    $a = fevEspace();
    // L'espace voisin porte SA PROPRE ouverte et SA PROPRE fermée : aucune des
    // deux ne doit apparaître, ni comme destinataire, ni comme exclue.
    $b = fevEspace();
    $precedente = DB::getDefaultConnection();

    try {
        DB::setDefaultConnection('pgsql_app');
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();
        DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $a['id']]);

        $criteres = ['all' => [['field' => 'country_code', 'op' => 'eq', 'value' => 'FR']]];
        $r = app(ResolveurDestinataires::class)->resoudre($a['id'], $criteres, new ReglageDestinataires(ReglageDestinataires::GENERIQUE), null);

        $marqueA = substr(str_replace('-', '', $a['id']), 0, 8);
        $marqueB = substr(str_replace('-', '', $b['id']), 0, 8);
        $emails = collect($r['lignes'])->pluck('email')->all();
        expect($emails)->toBe(['ouverte@zz-fev-rls-' . $marqueA . '.example.invalid'])
            ->and(implode(' ', $emails))->not->toContain($marqueB)
            // Une seule exclue (celle de A) et deux organisations (celles de A) :
            // ni l'ouverte ni la fermée de B ne sont lues.
            ->and($r['exclues'][EligibiliteAdresse::ENTREPRISE_FERMEE])->toBe(1)
            ->and($r['organisations'])->toBe(2);
    } finally {
        DB::setDefaultConnection($precedente);
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::connection('pgsql_app')->disconnect();
        fevNettoyer($a);
        fevNettoyer($b);
        fevProprio()->disconnect();
    }
});

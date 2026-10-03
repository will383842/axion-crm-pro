<?php

/**
 * LES ENTREPRENEURS INDIVIDUELS NE SONT JAMAIS DESTINATAIRES D'UNE CAMPAGNE
 * (03/10/2026).
 *
 * Une adresse rattachée à une fiche dont la catégorie juridique INSEE
 * (`companies.legal_form`) commence par 1 est exclue, motif
 * `entreprise_individuelle`, par la règle unique (`EligibiliteAdresse`) —
 * donc dans l'aperçu d'une audience (`ResolveurDestinataires`) ET dans la
 * liste en fichier (`crm:campagne:destinataires`). Une société (5710) part ;
 * une forme juridique absente ou inconnue n'exclut pas.
 *
 * Le dernier test rejoue le résolveur sous le rôle de production
 * (`axion_app`, RLS forcée) : il COMMIT et nettoie ce qu'il a semé.
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Campagnes\ReglageDestinataires;
use App\Crm\Campagnes\ResolveurDestinataires;
use App\Crm\Emails\VerificationEmail;
use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Les `signals` d'une fiche dont la boîte générique est vérifiée VALIDE. */
function eiSignals(string $email): string
{
    return (string) json_encode(['email_generic_verification' => [
        'statut' => VerificationEmail::VALIDE, 'motif' => 'mx', 'verifie_par' => VerificationEmail::SOURCE,
        'empreinte' => VerificationEmail::empreinte(mb_strtolower(trim($email))), 'type' => 'generique',
    ]]);
}

test('la règle : catégorie juridique commençant par 1, et rien d autre', function () {
    expect(EligibiliteAdresse::estEntrepriseIndividuelle('1000'))->toBeTrue()
        ->and(EligibiliteAdresse::estEntrepriseIndividuelle(' 1300 '))->toBeTrue()
        ->and(EligibiliteAdresse::estEntrepriseIndividuelle('5710'))->toBeFalse()
        ->and(EligibiliteAdresse::estEntrepriseIndividuelle('SAS'))->toBeFalse()
        ->and(EligibiliteAdresse::estEntrepriseIndividuelle(''))->toBeFalse()
        ->and(EligibiliteAdresse::estEntrepriseIndividuelle(null))->toBeFalse();

    // Le motif passe AVANT tout autre : même une adresse parfaite ne part pas.
    $occ = ['status' => null, 'verification' => VerificationEmail::VALIDE, 'perso' => false, 'deja_informe' => false];
    expect(EligibiliteAdresse::motif('contact@zz-ei.example.invalid', [$occ + ['entreprise_individuelle' => true]]))
        ->toBe(EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE)
        ->and(EligibiliteAdresse::MOTIFS)->toContain(EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE);
});

test('aperçu d une audience : la fiche EI est exclue avec ce motif ; société et forme inconnue partent', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $ws = F::espace('zz-ei-apercu');
    $cible = F::tag($ws, 'zz-cible-ei');
    $fiches = [
        'ei' => ['1000', 'contact@zz-ei.example.invalid'],
        'societe' => ['5710', 'contact@zz-societe.example.invalid'],
        'inconnue' => [null, 'contact@zz-inconnue.example.invalid'],
    ];
    foreach ($fiches as $cle => [$forme, $email]) {
        $id = F::fiche($ws, 'ZZ ' . $cle, ['legal_form' => $forme, 'email_generic' => $email, 'signals' => eiSignals($email)]);
        F::lier($ws, $id, $cible);
        if ($cle === 'ei') {
            // La personne d'une fiche EI ne part pas non plus.
            F::contact($ws, $id, 'Zoe', 'ZZEI', ['email' => 'zoe@zz-ei-perso.example.invalid', 'metadata' => json_encode([
                'email_verification' => ['statut' => VerificationEmail::VALIDE, 'motif' => 'mx', 'verifie_par' => VerificationEmail::SOURCE,
                    'empreinte' => VerificationEmail::empreinte('zoe@zz-ei-perso.example.invalid')],
            ])]);
        }
    }

    $r = app(ResolveurDestinataires::class)->resoudre(
        $ws,
        ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible-ei']]]],
        new ReglageDestinataires(ReglageDestinataires::LES_DEUX),
        null,
    );

    $adresses = collect($r['lignes'])->pluck('email')->sort()->values()->all();
    expect($adresses)->toBe(['contact@zz-inconnue.example.invalid', 'contact@zz-societe.example.invalid'])
        ->and($r['exclues'][EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE])->toBe(2)
        ->and($r['organisations'])->toBe(3)
        ->and($r['organisations_sans_destinataire'])->toBe(1);
});

test('liste en fichier : la fiche EI est comptée dans ecartees_entreprise_individuelle', function () {
    $ws = F::espace('zz-ei-liste');
    config(['crm.ingest.business_workspace' => F::slug($ws)]);
    foreach ([['ZZ Ei', '1000', 'bureau@zz-ei.example.invalid'], ['ZZ Societe', '5710', 'bureau@zz-societe.example.invalid'],
        ['ZZ Inconnue', null, 'bureau@zz-inconnue.example.invalid']] as [$nom, $forme, $email]) {
        $id = F::fiche($ws, $nom, ['legal_form' => $forme, 'email_generic' => $email]);
        F::proteger($ws, $id, FichesProtegees::TAG_ORGANISATEURS);
    }

    ResolveurDnsSimule::toutVerifier();
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-ei-');
    try {
        Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $fichier]);
        $sortie = Artisan::output();
        $emails = [];
        foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
            $emails[] = (string) json_decode($ligne, true)['email'];
        }
        sort($emails);
    } finally {
        @unlink($fichier);
    }

    expect($emails)->toBe(['bureau@zz-inconnue.example.invalid', 'bureau@zz-societe.example.invalid'])
        ->and(F::compteur($sortie, 'ecartees_entreprise_individuelle'))->toBe(1);
});

function eiProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

/** @return array{id: string, ei: int, societe: int} */
function eiEspace(): array
{
    $owner = eiProprio();
    $id = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $id), 0, 8);
    $owner->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-ei-rls-' . $marque, 'name' => 'ZZ EI RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ids = [];
    foreach (['ei' => '1000', 'societe' => '5710'] as $cle => $forme) {
        $email = $cle . '@zz-ei-rls-' . $marque . '.example.invalid';
        $ids[$cle] = (int) $owner->table('companies')->insertGetId([
            'workspace_id' => $id, 'siren' => '95' . random_int(1000000, 9999999), 'denomination' => 'ZZ EI Rls ' . $cle . ' ' . $marque,
            'legal_form' => $forme, 'email_generic' => $email, 'metadata' => '{}', 'quality_score' => 0,
            'signals' => eiSignals($email), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['id' => $id, 'ei' => $ids['ei'], 'societe' => $ids['societe']];
}

/** @param  array{id: string}  $e */
function eiNettoyer(array $e): void
{
    $owner = eiProprio();
    $owner->transaction(function () use ($owner, $e): void {
        // Nettoyage du TEST (données semées ici) — le produit ne supprime rien.
        foreach (['contacts', 'companies'] as $table) {
            $owner->table($table)->where('workspace_id', $e['id'])->delete();
        }
        $owner->table('workspaces')->where('id', $e['id'])->delete();
    });
}

test('sous axion_app (RLS) : la fiche EI est exclue, la société part, rien d un autre espace', function () {
    $a = eiEspace();
    $b = eiEspace();
    $precedente = DB::getDefaultConnection();

    try {
        DB::setDefaultConnection('pgsql_app');
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();
        DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $a['id']]);

        $criteres = ['all' => [['field' => 'country_code', 'op' => 'eq', 'value' => 'FR']]];
        $r = app(ResolveurDestinataires::class)->resoudre($a['id'], $criteres, new ReglageDestinataires(ReglageDestinataires::GENERIQUE), null);

        $marqueA = substr(str_replace('-', '', $a['id']), 0, 8);
        expect(collect($r['lignes'])->pluck('email')->all())->toBe(['societe@zz-ei-rls-' . $marqueA . '.example.invalid'])
            ->and($r['exclues'][EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE])->toBe(1)
            ->and($r['organisations'])->toBe(2);
    } finally {
        DB::setDefaultConnection($precedente);
        DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        DB::connection('pgsql_app')->disconnect();
        eiNettoyer($a);
        eiNettoyer($b);
        eiProprio()->disconnect();
    }
});

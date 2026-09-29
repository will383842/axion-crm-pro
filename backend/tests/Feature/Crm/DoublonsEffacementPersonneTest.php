<?php

/**
 * R1 (4e tour) — EFFACER UNE PERSONNE RETIRE TOUTES SES EMPREINTES DU JOURNAL
 * DES FUSIONS, pas seulement celles de son adresse et de ses numéros.
 *
 * Une fusion recopie sur l'homonyme de la fiche gardée les coordonnées qu'il
 * n'avait pas — dont son LinkedIn — et en garde l'empreinte salée
 * (`fusions_empreintes`, chemin `jumeaux.N.*`). L'effacement ne connaît que
 * l'adresse et les numéros : il doit quand même emporter le LinkedIn (et toute
 * autre colonne `jumeaux.*`) de la personne. Trois chemins, un test chacun :
 *
 *   - par l'ADRESSE : la personne de l'espace à cette adresse ;
 *   - par un NUMÉRO : la personne dont une valeur recopiée est ce numéro ;
 *   - par la SUPPRESSION de la personne, quel qu'en soit le chemin (cascade).
 *
 * Et de bout en bout par la console : après l'effacement, AUCUNE ligne de
 * `fusions_empreintes` ne correspond à une valeur de la personne. Témoin : les
 * empreintes d'une autre personne de la même fusion restent.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Services\Audit\AuditHashChain;
use App\Services\Rgpd\GdprErasureService;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const DEP_EMAIL = 'zoe.personne@zz-efface-personne.example.invalid';
const DEP_LINKEDIN = 'https://www.linkedin.com/in/zz-zoe-personne-fictive';
const DEP_MOBILE = '06 12 34 56 79';
const DEP_LINKEDIN_TEMOIN = 'https://www.linkedin.com/in/zz-zed-temoin-fictif';

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    Queue::fake();
    config(['crm.ingest.business_workspace' => 'axion-ia']);
    $this->ws = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->ws)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->ws, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $garde = F::fiche($this->ws, 'ZZ Efface Personne', ['postcode' => '69070']);
    $absorbee = F::sansSiren($this->ws, 'ZZ Efface Personne', ['postcode' => '69070']);
    // La personne : son adresse est déjà sur la fiche gardée ; la fusion y
    // recopie son LinkedIn et son mobile, que seule la fiche absorbée portait.
    $this->personne = F::contact($this->ws, $garde, 'Zoe', 'ZZPERSONNE', ['email' => DEP_EMAIL]);
    F::contact($this->ws, $absorbee, 'Zoe', 'ZZPERSONNE', ['linkedin_url' => DEP_LINKEDIN, 'phone' => DEP_MOBILE]);
    // Le témoin : une autre personne de la même fusion, avec son LinkedIn.
    $this->temoin = F::contact($this->ws, $garde, 'Zed', 'ZZTEMOIN');
    F::contact($this->ws, $absorbee, 'Zed', 'ZZTEMOIN', ['linkedin_url' => DEP_LINKEDIN_TEMOIN]);

    $this->fusion = WorkspaceContext::run($this->ws, fn (): int => app(FusionFiches::class)->fusionner(
        $this->ws,
        $garde,
        $absorbee,
        Rapprochement::NOM_CP,
        FusionFiches::MODE_MANUEL,
        null,
        null,
        'test',
    ));
});

function depEmpreinte(string $colonne, string $valeur): string
{
    return (string) DB::selectOne('SELECT public.doublons_empreinte(public.doublons_normaliser(?, ?)) AS h', [$colonne, $valeur])->h;
}

/** Les empreintes du journal qui correspondent à une valeur de la personne. @return list<string> */
function depCellesDeLaPersonne(): array
{
    $valeurs = [
        depEmpreinte('email', DEP_EMAIL),
        depEmpreinte('linkedin_url', DEP_LINKEDIN),
        depEmpreinte('phone', DEP_MOBILE),
    ];

    return DB::table('fusions_empreintes')->whereIn('empreinte', $valeurs)->orderBy('chemin')->pluck('chemin')
        ->map(static fn ($c): string => (string) $c)->all();
}

function depChemins(int $fusion): array
{
    return DB::table('fusions_empreintes')->where('fusion_id', $fusion)->orderBy('chemin')->pluck('chemin')
        ->map(static fn ($c): string => (string) $c)->all();
}

function depEffacer(string $ws, string $email, array $telephones): int
{
    return (int) WorkspaceContext::run($ws, static fn (): mixed => DB::selectOne(
        'SELECT public.doublons_effacer(?::uuid, ?, ?::jsonb) AS n',
        [$ws, $email, json_encode($telephones, JSON_THROW_ON_ERROR)],
    ))->n;
}

test('TÉMOIN — la fusion a bien journalisé le LinkedIn et le mobile recopiés de la personne', function () {
    expect(depChemins($this->fusion))->toBe(['jumeaux.0.linkedin_url', 'jumeaux.0.phone', 'jumeaux.1.linkedin_url'])
        ->and(depCellesDeLaPersonne())->toBe(['jumeaux.0.linkedin_url', 'jumeaux.0.phone'])
        ->and(DB::table('fusions_empreintes')->where('chemin', 'like', 'jumeaux.0.%')->distinct()->pluck('contact_id')->map(static fn ($i): int => (int) $i)->all())->toBe([$this->personne]);
});

test('par l ADRESSE seule : le LinkedIn et le mobile de la personne partent aussi, le témoin reste', function () {
    // La personne est encore là (appel AVANT sa suppression) ; son adresse
    // n'est dans aucune empreinte : seule sa fiche la désigne.
    depEffacer($this->ws, DEP_EMAIL, []);

    expect(depCellesDeLaPersonne())->toBe([])
        ->and(depChemins($this->fusion))->toBe(['jumeaux.1.linkedin_url'])
        ->and(DB::table('contacts')->where('id', $this->personne)->exists())->toBeTrue();
});

test('par un NUMÉRO seul : la personne dont c est la valeur perd AUSSI son LinkedIn, le témoin reste', function () {
    depEffacer($this->ws, '', [DEP_MOBILE]);

    expect(depCellesDeLaPersonne())->toBe([])
        ->and(depChemins($this->fusion))->toBe(['jumeaux.1.linkedin_url']);
});

test('par la SUPPRESSION de la personne, quel qu en soit le chemin : ses empreintes partent avec elle', function () {
    DB::table('contacts')->where('id', $this->personne)->delete();

    expect(depCellesDeLaPersonne())->toBe([])
        ->and(depChemins($this->fusion))->toBe(['jumeaux.1.linkedin_url']);
});

test('de bout en bout (console) : aucune empreinte du journal ne correspond plus à la personne', function () {
    app(GdprErasureService::class)->erase(DEP_EMAIL);

    expect(depCellesDeLaPersonne())->toBe([])
        ->and(depChemins($this->fusion))->toBe(['jumeaux.1.linkedin_url']);
});

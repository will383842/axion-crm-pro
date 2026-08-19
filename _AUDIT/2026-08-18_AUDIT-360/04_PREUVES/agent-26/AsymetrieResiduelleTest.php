<?php

/**
 * AGENT 26 — les entrees que `SymetrieEvaluateursTest` NE couvre PAS.
 *
 * Ce fichier ne vit PAS dans le depot : il est copie dans /tmp du conteneur et
 * joue avec une configuration PHPUnit dediee (/tmp/phpunit-a26.xml, base
 * axion_crm_a26). Aucun fichier du produit n'est modifie.
 *
 * `SymetrieEvaluateursTest` compare les deux evaluateurs sur 22 conditions
 * VALIDES. Les quatre entrees ci-dessous sont, elles, MAL FORMEES d'une facon
 * que la validation laisse passer — et sur ces quatre-la, `applyCondition()` /
 * `buildPositive()` rendent `null` (condition RETIREE de la requete => la
 * requete S'ELARGIT) tandis que `evalCondition()` rend `false` (la fiche est
 * EXCLUE => on RETRECIT). Les deux evaluateurs partent donc dans des
 * directions OPPOSEES.
 *
 * L'invariant que le test d'origine se donne, ecrit dans son propre en-tete,
 * est « le contenu d'une meme audience ne doit pas dependre du chemin qui l'a
 * calculee ». C'est cet invariant-la qu'on mesure ici.
 */

use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-asym',
        'name' => 'WS asymetrie',
        'settings' => [],
    ]);

    $lignes = [
        'avec-email-generic' => ['38', 'btp', 'oui'],
        'avec-contact-valide' => ['69', 'sante', 'contact'],
        'sans-aucun-email' => ['75', null, null],
        'sans-email-secteur-null' => [null, null, null],
    ];

    $this->ids = [];
    foreach ($lignes as $repere => [$dept, $secteur, $email]) {
        $f = Company::create([
            'workspace_id' => $this->workspace->id,
            'siren' => (string) random_int(100000000, 999999999),
            'denomination' => $repere,
            'department_code' => $dept,
            'sector_main' => $secteur,
            'prospection_status' => 'pending',
            'signals' => [],
            'metadata' => [],
        ]);
        if ($email === 'oui') {
            Company::whereKey($f->id)->update(['email_generic' => 'contact@exemple.fr']);
        }
        if ($email === 'contact') {
            DB::table('contacts')->insert([
                'workspace_id' => $this->workspace->id,
                'company_id' => $f->id,
                'last_name' => 'Joignable',
                'email' => 'p@exemple.fr',
                'email_status' => 'valid',
                'sources' => '[]',
                'metadata' => '{}',
            ]);
        }
        $this->ids[$repere] = (int) $f->id;
    }

    $this->service = app(AudienceBuilderService::class);
});

/** Le chemin SQL : celui de refresh() et de preview(). */
function a26Sql(AudienceBuilderService $s, string $ws, array $criteria): array
{
    $ids = array_map('intval', $s->buildPublicQuery($ws, $criteria)->pluck('id')->all());
    sort($ids);

    return array_values($ids);
}

/** Le chemin EN MEMOIRE : l'entree publique du waterfall step12. */
function a26Memoire(AudienceBuilderService $s, string $ws, array $criteria, array $ids): array
{
    $a = EmailAudience::create([
        'workspace_id' => $ws,
        'name' => 'sonde-' . Str::random(8),
        'criteria' => $criteria,
        'is_active' => true,
        'auto_refresh' => true,
    ]);

    $retenus = [];
    foreach ($ids as $id) {
        if (in_array($a->id, $s->evaluateForCompany(Company::findOrFail($id)), true)) {
            $retenus[] = (int) $id;
        }
    }
    $a->forceDelete();
    sort($retenus);

    return array_values($retenus);
}

test('AGENT 26 — les quatre entrees mal formees que la validation laisse passer', function () {
    $cas = [
        // `has_email` et `neq` sont TOUS DEUX dans les listes blanches :
        // StoreEmailAudienceRequest ACCEPTE cette condition.
        // buildPositive() : `if ($op !== 'eq') return null;`  => condition retiree.
        // evalCondition() : ne regarde JAMAIS $op                => compare quand meme.
        'has_email avec op neq' => ['field' => 'has_email', 'op' => 'neq', 'value' => true],

        // `criteria.*.value` n'est valide NULLE PART : une valeur scalaire
        // pour un operateur de tableau passe la validation.
        'in avec valeur scalaire' => ['field' => 'sector_main', 'op' => 'in', 'value' => 'btp'],
        'not_in avec valeur scalaire' => ['field' => 'sector_main', 'op' => 'not_in', 'value' => 'btp'],

        // Champ hors liste blanche : REFUSE par POST /audiences, mais
        // POST /audiences/preview ne valide que `criteria => required|array`.
        'champ hors liste blanche' => ['field' => 'chiffre_affaires', 'op' => 'eq', 'value' => 1000],
    ];

    $total = count($this->ids);
    $ecarts = [];

    echo "\n";
    echo "Base                 : " . DB::connection()->getDatabaseName() . "\n";
    echo "Fiches du workspace  : $total\n\n";
    printf("%-30s | %-9s | %-9s | %s\n", 'ENTREE', 'SQL rend', 'MEM rend', 'DIRECTION');
    echo str_repeat('-', 92) . "\n";

    foreach ($cas as $label => $cond) {
        $criteria = ['all' => [$cond]];
        $sql = a26Sql($this->service, $this->workspace->id, $criteria);
        $mem = a26Memoire($this->service, $this->workspace->id, $criteria, $this->ids);

        $direction = $sql === $mem
            ? 'accord'
            : sprintf('DESACCORD (SQL %s / memoire %s)',
                count($sql) === $total ? 'elargit a TOUT' : 'restreint',
                count($mem) === 0 ? 'ne vise PERSONNE' : 'restreint');

        printf("%-30s | %9d | %9d | %s\n", $label, count($sql), count($mem), $direction);

        if ($sql !== $mem) {
            $ecarts[] = "$label : SQL=" . count($sql) . " memoire=" . count($mem);
        }
    }

    echo "\n=== TEMOIN NEGATIF : une condition BIEN formee, sur les memes fiches ===\n";
    echo "Si le controle sait distinguer, ces lignes doivent dire « accord ».\n";
    foreach ([
        'has_email eq true (bien forme)' => ['field' => 'has_email', 'op' => 'eq', 'value' => true],
        'in avec tableau (bien forme)' => ['field' => 'sector_main', 'op' => 'in', 'value' => ['btp']],
        'not_in tableau sur NULL' => ['field' => 'sector_main', 'op' => 'not_in', 'value' => ['btp']],
        'neq sur colonne NULL' => ['field' => 'sector_main', 'op' => 'neq', 'value' => 'btp'],
    ] as $label => $cond) {
        $criteria = ['all' => [$cond]];
        $sql = a26Sql($this->service, $this->workspace->id, $criteria);
        $mem = a26Memoire($this->service, $this->workspace->id, $criteria, $this->ids);
        printf("%-32s | SQL=%d | MEM=%d | %s\n", $label, count($sql), count($mem), $sql === $mem ? 'accord' : 'DESACCORD');
    }

    echo "\n";

    expect($ecarts)->toBe([], "Asymetries residuelles :\n  " . implode("\n  ", $ecarts));
});

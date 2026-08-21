<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * C21-004 — LA REPRISE DU STOCK : `crm:recalculer-quality-score`.
 *
 * Le correctif de la migration `2026_08_20_000001` ne soigne que l'AVENIR : les
 * lignes ecrites avant lui gardent leur score faux (3 546 986 en production le
 * 2026-08-19). Cette commande les reprend. Ce fichier verifie les proprietes
 * sans lesquelles une reprise sur 3,5 M de lignes serait dangereuse :
 *
 *   1. l'ESSAI A BLANC n'ecrit rien — et compte quand meme juste ;
 *   2. la reprise reelle realigne, et le dit ;
 *   3. elle est REJOUABLE — une deuxieme execution ecrit zero ligne ;
 *   4. `--max-lots` la borne vraiment, et le RESTE est annonce ;
 *   5. sans le bareme en base, elle REFUSE au lieu de rendre « 0 corrigee ».
 *
 * ⚠️ TEMOIN. Une commande qui ne trouve rien annonce « 0 fiche corrigee », ce
 * qui ressemble a un succes. Chaque garde verifie donc D'ABORD que le stock est
 * bien divergent AVANT de lancer la commande, et que le compte annonce est
 * celui qu'on a fabrique — pas zero.
 *
 * ⚠️ Sous-chaines de controle SANS lettre accentuee : la sortie de la commande
 * en porte.
 */
function rqsWorkspace(): string
{
    $id = (string) Str::uuid();

    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'rqs-' . substr($id, 0, 8),
        'name' => 'RQS C21-004',
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Fabrique une fiche dont le score STOCKE contredit le bareme.
 *
 * Le desalignement passe par `SET quality_score = …`, qui ne cite aucune des
 * neuf colonnes ecoutees par `companies_recompute_score` : le declencheur ne se
 * rallume donc pas et la divergence tient. C'est exactement l'etat que
 * l'`INSERT` non ecoute produisait avant le correctif.
 *
 * @param  array<string, mixed>  $attrs
 */
function rqsFicheDesalignee(string $workspaceId, int $scoreFaux, array $attrs = []): int
{
    $id = (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ RQS',
        'signals' => '{}',
        'metadata' => '{}',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));

    DB::table('companies')->where('id', $id)->update(['quality_score' => $scoreFaux]);

    return $id;
}

function rqsStocke(int $id): int
{
    return (int) DB::table('companies')->where('id', $id)->value('quality_score');
}

function rqsBareme(int $id): int
{
    return (int) DB::selectOne(
        'SELECT company_quality_score_calcul(c) AS s FROM companies c WHERE c.id = ?',
        [$id],
    )->s;
}

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function rqsJouer(array $options = []): array
{
    $code = Artisan::call('crm:recalculer-quality-score', $options);

    return [$code, Artisan::output()];
}

test('TEMOIN — le jeu d essai est bien divergent avant toute commande', function () {
    $ws = rqsWorkspace();
    $id = rqsFicheDesalignee($ws, 0, ['website' => 'https://exemple.test', 'phone' => '+33100000000']);

    expect(rqsBareme($id))->toBe(25);
    expect(rqsStocke($id))->toBe(0);
    expect(rqsStocke($id))->not->toBe(rqsBareme($id));
});

test('l essai a blanc compte juste et n ecrit RIEN', function () {
    $ws = rqsWorkspace();
    $a = rqsFicheDesalignee($ws, 0, ['website' => 'https://exemple.test']);   // bareme 15, sous-evaluee
    $b = rqsFicheDesalignee($ws, 90, ['phone' => '+33100000000']);            // bareme 10, sur-evaluee
    $c = rqsFicheDesalignee($ws, 0);                                          // bareme 0, DEJA aligne

    expect([rqsStocke($a), rqsStocke($b), rqsStocke($c)])->toBe([0, 90, 0]);

    [$code, $sortie] = rqsJouer(['--simulation' => true]);

    expect($code)->toBe(0);

    // Le compte annonce est celui qu'on a fabrique : 2 divergentes, dont 1
    // sous-evaluee et 1 sur-evaluee. La troisieme fiche est deja juste.
    $this->assertStringContainsString('2 a corriger', $sortie);
    $this->assertStringContainsString('1 sous-evaluee(s), 1 sur-evaluee(s)', $sortie);
    $this->assertStringContainsString('ESSAI A BLANC', $sortie);

    // …et le stock n'a pas bouge d'un point.
    expect([rqsStocke($a), rqsStocke($b), rqsStocke($c)])->toBe([0, 90, 0]);
});

test('la reprise realigne le stock sur le bareme', function () {
    $ws = rqsWorkspace();
    $a = rqsFicheDesalignee($ws, 0, ['website' => 'https://exemple.test']);
    $b = rqsFicheDesalignee($ws, 90, ['phone' => '+33100000000']);

    [$code, $sortie] = rqsJouer();

    expect($code)->toBe(0);
    $this->assertStringContainsString('2 corrigee(s)', $sortie);

    expect(rqsStocke($a))->toBe(rqsBareme($a))->toBe(15);
    expect(rqsStocke($b))->toBe(rqsBareme($b))->toBe(10);
});

test('la reprise est REJOUABLE — la seconde execution ecrit zero ligne', function () {
    $ws = rqsWorkspace();
    $a = rqsFicheDesalignee($ws, 0, ['website' => 'https://exemple.test']);

    [, $premiere] = rqsJouer();
    $this->assertStringContainsString('1 corrigee(s)', $premiere);

    [, $seconde] = rqsJouer();
    $this->assertStringContainsString('0 corrigee(s)', $seconde);
    expect(rqsStocke($a))->toBe(15);

    // Et l'essai a blanc, apres coup, ne trouve plus rien a faire.
    [, $blanc] = rqsJouer(['--simulation' => true]);
    $this->assertStringContainsString('0 a corriger', $blanc);
});

test('--max-lots borne vraiment la reprise, et le reste est annonce', function () {
    $ws = rqsWorkspace();
    $ids = [];
    for ($n = 0; $n < 5; $n++) {
        $ids[] = rqsFicheDesalignee($ws, 0, ['website' => "https://exemple{$n}.test"]);
    }

    // Un seul lot de 2 fiches : la commande doit s'arreter apres 2, pas 5.
    [, $sortie] = rqsJouer(['--taille-lot' => 2, '--max-lots' => 1]);
    $this->assertStringContainsString('2 fiche(s) examinee(s), 2 corrigee(s)', $sortie);
    $this->assertStringContainsString('1 lot(s)', $sortie);
    $this->assertStringContainsString('Il reste 3 fiche(s)', $sortie);

    $corrigees = 0;
    foreach ($ids as $id) {
        if (rqsStocke($id) === 15) {
            $corrigees++;
        }
    }
    expect($corrigees)->toBe(2, 'La borne --max-lots n a pas ete tenue : la commande est sortie de sa fenetre.');

    // Relancee sans borne, elle finit le travail.
    rqsJouer(['--taille-lot' => 2]);
    foreach ($ids as $id) {
        expect(rqsStocke($id))->toBe(15);
    }
});

test('la commande REFUSE si le bareme n est pas en base', function () {
    $ws = rqsWorkspace();
    $id = rqsFicheDesalignee($ws, 0, ['website' => 'https://exemple.test']);

    // Le declencheur depend de la fonction : il part avec elle.
    DB::statement('DROP TRIGGER IF EXISTS companies_recompute_score ON companies');
    DB::statement('DROP FUNCTION IF EXISTS company_quality_score_calcul(companies) CASCADE');

    [$code, $sortie] = rqsJouer(['--simulation' => true]);

    expect($code)->not->toBe(0, 'Sans le bareme, la commande a rendu un succes : elle annoncerait « 0 fiche corrigee » sur un stock divergent.');
    $this->assertStringContainsString('REFUS', $sortie);

    // TEMOIN — le stock etait bien divergent : le refus n'est pas un « rien a faire ».
    expect(rqsStocke($id))->toBe(0);
});

/**
 * Le decompte final « Il reste N fiche(s) » coute un parcours COMPLET de
 * `companies` avec un appel de fonction par ligne — et cette fonction porte
 * elle-meme un `EXISTS` sur `contacts`. Sur les 4,3 M de fiches de production
 * c'est la requete la plus chere de toute la commande.
 *
 * En essai a blanc, son resultat n'est jamais affiche : le payer serait une
 * dizaine de minutes de base brulees pour rien, invisibles a l'appelant. La
 * garde regarde donc les requetes REELLEMENT emises.
 */
test('l essai a blanc ne paye pas le parcours complet du decompte final', function () {
    $ws = rqsWorkspace();
    rqsFicheDesalignee($ws, 0, ['website' => 'https://exemple.test']);

    $sondeur = static function (array &$sac) {
        DB::listen(static function ($requete) use (&$sac) {
            if (str_contains($requete->sql, 'IS DISTINCT FROM company_quality_score_calcul')) {
                $sac[] = $requete->sql;
            }
        });
    };

    $enBlanc = [];
    $sondeur($enBlanc);
    [, $sortie] = rqsJouer(['--simulation' => true]);

    // TEMOIN — l essai a blanc a bien TRAVAILLE : sans cela, « zero parcours
    // complet » serait vrai d une commande qui n a rien fait du tout.
    $this->assertStringContainsString('1 a corriger', $sortie);

    expect($enBlanc)->toBe(
        [],
        'L essai a blanc a lance ' . count($enBlanc) . ' parcours complet(s) de companies pour un '
        . 'decompte qu il n affiche jamais. Sur 4,3 M de fiches c est la requete la plus chere de la '
        . 'commande, payee pour rien.',
    );

    // TEMOIN — la sonde SAIT voir ce parcours : la reprise reelle, elle, le
    // fait, parce qu'elle en affiche le resultat.
    $enReel = [];
    $sondeur($enReel);
    rqsJouer();

    expect(count($enReel))->toBeGreaterThan(
        0,
        'La sonde n a vu aucun parcours complet meme pendant la reprise reelle : elle est aveugle, '
        . 'et le vert ci-dessus ne prouve rien.',
    );
});

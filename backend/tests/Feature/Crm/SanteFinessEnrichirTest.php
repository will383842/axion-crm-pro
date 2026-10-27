<?php

/**
 * `crm:sante:enrichir-finess` — fixtures FICTIVES (ZZ, SIREN 94xxxxxxx).
 * Verrouillé : essai à blanc par défaut ; ajout sur champ vide seulement ; jamais d'écrasement ; 620 ignorée ;
 * rapprochement N1 (SIRET) / N2 (SIREN) et gardes ; opposition écartée ; corbeille jamais touchée ; isolation par espace.
 */

use App\Crm\Pharmacies\TelephonesFiness;
use App\Crm\Sante\EnrichissementFinessSante;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @param list<array<string, string>> $lignes */
function csvSante(array $lignes): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'zzsante');
    $f = fopen($chemin, 'w');
    $defauts = [
        'finess_et' => '990000001', 'finess_ej' => '990000002', 'siret' => '', 'categorie' => '101', 'etat' => 'A', 'telephone' => '',
        'telecopie' => '', 'courriel' => '', 'numero_voie' => '', 'type_voie' => '', 'libelle_voie' => '', 'complement_voie' => '',
        'lieu_dit' => '', 'adresse_ligne_4' => '', 'code_postal' => '', 'commune_acheminement' => '', 'latitude' => '', 'longitude' => '',
        'date_ouverture' => '', 'commune' => '',
    ];
    foreach ($lignes as $l) {
        $defauts += array_fill_keys(array_keys($l), '');
    }
    $colonnes = array_keys($defauts);
    fputcsv($f, $colonnes, ';', '"', '');
    foreach ($lignes as $l) {
        $complete = array_merge($defauts, $l);
        fputcsv($f, array_map(static fn (string $col): string => $complete[$col], $colonnes), ';', '"', '');
    }
    fclose($f);

    return $chemin;
}

/** Fiche (sans ligne finess_etablissements) ; renvoie [id, siret FINESS attendu, siren]. */
function ficheSante(string $espace, array $attrs = []): array
{
    $siren = F::siren();
    $siret = $siren . '00011';
    $id = F::fiche($espace, 'ZZ CLINIQUE', array_merge(['siren' => $siren, 'siret' => $siret, 'legal_form' => '5499'], $attrs));

    return [$id, $siret, $siren];
}

function lancerSante(string $espace, string $csv, bool $apply = true, ?string $categories = null): string
{
    Artisan::call('crm:sante:enrichir-finess', array_filter(['fichier' => $csv, '--workspace' => $espace, '--apply' => $apply ?: null, '--categories' => $categories]));

    return Artisan::output();
}

/** @param list<string> $categories */
function bilanSante(string $espace, string $csv, bool $apply = true, array $categories = []): array
{
    return (new EnrichissementFinessSante)->executer($espace, $csv, $apply, $categories);
}

function ligneSante(string $siret, string $categorie = '101'): array
{
    return ['siret' => $siret, 'categorie' => $categorie, 'telephone' => '01 40 00 00 01', 'numero_voie' => '3', 'libelle_voie' => 'RUE ZZ',
        'code_postal' => '99003', 'commune_acheminement' => '99003 ZZVILLE', 'latitude' => '48.1', 'longitude' => '2.1', 'date_ouverture' => '2001-01-01'];
}

function metaSante(int $id): array
{
    return json_decode((string) (DB::table('companies')->where('id', $id)->value('metadata') ?? '{}'), true) ?? [];
}

it('N1 : ajoute téléphone normalisé, adresse et GPS, sans aucune date', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);

    $sortie = lancerSante($espace, csvSante([ligneSante($siret)]));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->phone)->toBe('0140000001')->and($c->address)->toBe('3 RUE ZZ')->and($c->postcode)->toBe('99003')
        ->and($c->city)->toBe('ZZVILLE')->and((float) $c->lat)->toBe(48.1)->and((float) $c->lon)->toBe(2.1);
    expect(metaSante($id)['telephone_source'])->toBe('finess')->and(metaSante($id))->not->toHaveKey('finess_date_ouverture');
    expect($sortie)->toContain('fiches enrichies : 1')->not->toContain('0140000001');
});

it('n\'écrase jamais un champ déjà renseigné', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace, ['phone' => '0499999999', 'address' => '9 RUE EXISTANTE', 'postcode' => '99003', 'city' => 'AUTRE', 'city_name' => 'AUTRE', 'lat' => 45.0, 'lon' => 1.0]);

    $b = bilanSante($espace, csvSante([ligneSante($siret)]));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->phone)->toBe('0499999999')->and($c->address)->toBe('9 RUE EXISTANTE')->and($c->city)->toBe('AUTRE')->and((float) $c->lat)->toBe(45.0);
    expect($b['tel'])->toBe(0)->and($b['adr'])->toBe(0)->and($b['gps'])->toBe(0)->and($b['fiches_enrichies'])->toBe(0);
});

it('ignore la catégorie 620', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);

    $b = bilanSante($espace, csvSante([ligneSante($siret, '620')]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($b['actives'])->toBe(0)->and($b['par_categorie'])->toBe([]);
});

it('--categories filtre les catégories traitées', function () {
    $espace = F::espace();
    [$a, $sa] = ficheSante($espace);
    [$b, $sb] = ficheSante($espace);

    lancerSante($espace, csvSante([ligneSante($sa, '101'), ligneSante($sb, '106')]), true, '101');

    expect(DB::table('companies')->where('id', $a)->value('phone'))->toBe('0140000001');
    expect(DB::table('companies')->where('id', $b)->value('phone'))->toBeNull();
});

it('--categories=620 ou invalide est refusé', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);
    $csv = csvSante([ligneSante($siret, '620')]);

    foreach (['620', '101,620', '12', 'abc'] as $valeur) {
        $code = Artisan::call('crm:sante:enrichir-finess', ['fichier' => $csv, '--workspace' => $espace, '--apply' => true, '--categories' => $valeur]);
        expect($code)->toBe(1);
    }
    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
});

it('N2 : fiche sans SIRET, un seul établissement actif, code postal égal', function () {
    $espace = F::espace();
    [$a, $sa] = ficheSante($espace, ['siret' => null, 'postcode' => '99003', 'address' => '1 RUE ZZ']);

    $r = bilanSante($espace, csvSante([ligneSante($sa)]));

    expect(DB::table('companies')->where('id', $a)->value('phone'))->toBe('0140000001');
    expect($r['trouvees_n2'])->toBe(1)->and($r['trouvees_n1'])->toBe(0);
});

it('N2 refusé : fiche sans code postal, même avec le même département', function () {
    $espace = F::espace();
    [$b, $sb] = ficheSante($espace, ['siret' => '', 'postcode' => null, 'department_code' => '99']);

    $r = bilanSante($espace, csvSante([ligneSante($sb)]));

    expect(DB::table('companies')->where('id', $b)->value('phone'))->toBeNull();
    expect($r['cp_divergent'])->toBe(1)->and($r['trouvees_n2'])->toBe(0);
});

it('N2 refusé : deux établissements actifs pour le SIREN, ou code postal différent', function () {
    $espace = F::espace();
    [$a, $sa, $sirenA] = ficheSante($espace, ['siret' => null]);
    [$b, $sb] = ficheSante($espace, ['siret' => null, 'postcode' => '75001']);
    [$c, $sc] = ficheSante($espace, ['siret' => null, 'postcode' => null, 'department_code' => null]);

    $r = bilanSante($espace, csvSante([ligneSante($sa), ligneSante($sirenA . '00029', '106'), ligneSante($sb), ligneSante($sc)]));

    foreach ([$a, $b, $c] as $id) {
        expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    }
    expect($r['siren_ambigu'])->toBe(2)->and($r['cp_divergent'])->toBe(2)->and($r['trouvees_n2'])->toBe(0);
});

it('fiche avec un AUTRE SIRET : rien (siret_different)', function () {
    $espace = F::espace();
    [$id, $siret, $siren] = ficheSante($espace);

    $r = bilanSante($espace, csvSante([ligneSante($siren . '00029')]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($r['siret_different'])->toBe(1)->and($r['trouvees_n1'])->toBe(0);
});

it('SIRET partagé par des lignes de téléphone différent : rien (siret_partage)', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);
    $l1 = ligneSante($siret);
    $l2 = ['telephone' => '04 00 00 00 02'] + $l1;

    $r = bilanSante($espace, csvSante([$l1, $l2]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($r['siret_partage'])->toBe(1)->and($r['fiches_enrichies'])->toBe(0);
});

it('N1 avec code postal différent : rien (cp_divergent)', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace, ['postcode' => '75001']);

    $r = bilanSante($espace, csvSante([ligneSante($siret)]));

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->phone)->toBeNull()->and($c->address)->toBeNull();
    expect($r['cp_divergent'])->toBe(1);
});

it('compte les lignes sans SIRET valide et ignore les établissements dont l\'état n\'est pas A', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);

    $r = bilanSante($espace, csvSante([['siret' => '', 'telephone' => '0140000001'], ['siret' => '123', 'telephone' => '0140000001'], ['etat' => 'F'] + ligneSante($siret)]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($r['actives'])->toBe(2)->and($r['sans_siret'])->toBe(2)->and($r['par_categorie']['101']['sans_siret'])->toBe(2);
});

it('fiche fermée, non diffusible, écartée ou en corbeille : rien n\'est écrit, la corbeille reste en corbeille', function () {
    $espace = F::espace();
    [$f, $sf] = ficheSante($espace, ['insee_ferme_le' => '2020-01-01']);
    [$n, $sn] = ficheSante($espace, ['insee_non_diffusible_le' => now()]);
    [$e, $se] = ficheSante($espace, ['metadata' => json_encode(['ecartee_motif' => 'test'])]);
    [$t, $st] = ficheSante($espace, ['deleted_at' => now()]);

    $r = bilanSante($espace, csvSante([ligneSante($sf), ligneSante($sn), ligneSante($se), ligneSante($st)]));

    foreach ([$f, $n, $e, $t] as $id) {
        $c = DB::table('companies')->where('id', $id)->first();
        expect($c->phone)->toBeNull()->and($c->address)->toBeNull()->and($c->lat)->toBeNull();
    }
    expect(DB::table('companies')->where('id', $t)->value('deleted_at'))->not->toBeNull();
    expect($r['exclues'])->toBe(4)->and($r['fiches_enrichies'])->toBe(0);
});

it('fiches protégées (organisateurs, GOFAB) : rien n\'est écrit', function () {
    $espace = F::espace();
    [$a, $sa] = ficheSante($espace);
    [$b, $sb] = ficheSante($espace);
    F::proteger($espace, $a, 'src:scraping-evenements-pro');
    F::proteger($espace, $b, 'src:scraping-gofab-2026');

    $r = bilanSante($espace, csvSante([ligneSante($sa), ligneSante($sb)]));

    foreach ([$a, $b] as $id) {
        $c = DB::table('companies')->where('id', $id)->first();
        expect($c->phone)->toBeNull()->and($c->address)->toBeNull()->and($c->lat)->toBeNull();
    }
    expect($r['exclues'])->toBe(2);
});

it('personne physique : fiche écartée et comptée', function () {
    $espace = F::espace();
    [$a, $sa] = ficheSante($espace, ['legal_form' => ' 1000']);
    [$b, $sb] = ficheSante($espace, ['legal_form' => null, 'metadata' => json_encode(['personne_physique' => true])]);
    [$c, $sc] = ficheSante($espace, ['legal_form' => null]);

    $r = bilanSante($espace, csvSante([ligneSante($sa), ligneSante($sb), ligneSante($sc)]));

    expect(DB::table('companies')->whereIn('id', [$a, $b])->whereNotNull('phone')->count())->toBe(0);
    expect(DB::table('companies')->where('id', $c)->value('phone'))->toBe('0140000001');
    expect($r['personnes_physiques_ecartees'])->toBe(2)->and($r['par_categorie']['101']['pp'])->toBe(2);
});

it('opposition : numéro à ajouter, numéro déjà présent ou e-mail générique écartent la fiche entière', function () {
    $espace = F::espace();
    $email = 'zz-opposee@zz.example.invalid';
    [$a, $sa] = ficheSante($espace);
    [$b, $sb] = ficheSante($espace, ['phone' => '01 40 00 00 05']);
    [$c, $sc] = ficheSante($espace, ['email_generic' => $email]);
    DB::table('opt_out')->insert(['phone' => '+33 1 40 00 00 01', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    DB::table('opt_out')->insert(['phone' => '+33 1 40 00 00 05', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    DB::table('opt_out')->insert(['email_hash' => hash('sha256', $email), 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    $ligneB = ['telephone' => ''] + ligneSante($sb);
    $ligneC = ['telephone' => '04 00 00 00 09'] + ligneSante($sc);

    $r = bilanSante($espace, csvSante([ligneSante($sa), $ligneB, $ligneC]));

    foreach ([$a, $b, $c] as $id) {
        $f = DB::table('companies')->where('id', $id)->first();
        expect($f->address)->toBeNull()->and($f->lat)->toBeNull();
    }
    expect(DB::table('companies')->where('id', $a)->value('phone'))->toBeNull();
    expect(DB::table('companies')->where('id', $c)->value('phone'))->toBeNull();
    expect($r['opposition'])->toBe(3)->and($r['fiches_enrichies'])->toBe(0);
});

it('respecte l\'isolation par espace de travail', function () {
    $autre = F::espace();
    $espace = F::espace();
    [$idAutre, $siret] = ficheSante($autre);

    $r = bilanSante($espace, csvSante([ligneSante($siret)]));

    expect(DB::table('companies')->where('id', $idAutre)->value('phone'))->toBeNull();
    expect($r['fiches_enrichies'])->toBe(0)->and($r['exclues'])->toBe(1);
});

it('essai à blanc : rien n\'est écrit, bilan par catégorie exact', function () {
    $espace = F::espace();
    [$a, $sa] = ficheSante($espace);
    [$b, $sb] = ficheSante($espace, ['postcode' => '75001']);
    [$c, $sc] = ficheSante($espace, ['siret' => null, 'postcode' => '99003', 'address' => '1 RUE ZZ']);
    $csv = csvSante([ligneSante($sa, '101'), ligneSante($sb, '101'), ['siret' => $sc, 'categorie' => '106', 'telephone' => '0140000007', 'code_postal' => '99003'], ['siret' => '', 'categorie' => '106']]);

    $sortie = lancerSante($espace, $csv, false);
    $r = bilanSante($espace, $csv, false);

    foreach ([$a, $b, $c] as $id) {
        $f = DB::table('companies')->where('id', $id)->first();
        expect($f->phone)->toBeNull()->and($f->lat)->toBeNull();
    }
    expect($sortie)->toContain('ESSAI À BLANC');
    $zero = array_fill_keys(['actives', 'sans_siret', 'trouvees_n1', 'trouvees_n2', 'siren_ambigu', 'siret_partage', 'siret_different', 'cp_divergent', 'exclues', 'exclues_non_officine', 'fiches_siret_partage', 'pp', 'opposition', 'tel', 'adr', 'gps', 'fiches_enrichies', 'classees', 'categorie_conflit', 'non_officine_incluses', 'deja_classee', 'classement_opposition'], 0);
    expect($r['par_categorie']['101'])->toEqual(array_merge($zero, ['actives' => 2, 'trouvees_n1' => 1, 'cp_divergent' => 1, 'tel' => 1, 'adr' => 1, 'gps' => 1, 'fiches_enrichies' => 1]));
    expect($r['par_categorie']['106'])->toEqual(array_merge($zero, ['actives' => 2, 'sans_siret' => 1, 'trouvees_n2' => 1, 'tel' => 1, 'fiches_enrichies' => 1]));
    expect($r['tel'])->toBe(2)->and($r['fiches_enrichies'])->toBe(2)->and($r['actives'])->toBe(4);
});

it('deuxième passage : aucun ajout', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);
    $csv = csvSante([ligneSante($siret)]);

    $premier = bilanSante($espace, $csv);
    $second = bilanSante($espace, $csv);

    expect($premier['fiches_enrichies'])->toBe(1);
    expect($second['tel'])->toBe(0)->and($second['adr'])->toBe(0)->and($second['gps'])->toBe(0)->and($second['fiches_enrichies'])->toBe(0);
});

it('ne lit ni n\'écrit jamais companies sans filtre sur le SIREN ou l\'identifiant', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);
    $requetes = [];
    DB::listen(function ($q) use (&$requetes): void {
        $requetes[] = $q->sql;
    });

    bilanSante($espace, csvSante([ligneSante($siret)]));

    $visees = array_filter($requetes, static fn (string $sql): bool => preg_match('/(from|update) "companies"/i', $sql) === 1);
    expect($visees)->not->toBeEmpty();
    foreach ($visees as $sql) {
        expect(preg_match('/"siren" in|"id" = /', $sql))->toBe(1);
    }
});

it('SIREN avec un SIRET en 620 et un autre en 101, fiche sans SIRET : siren_ambigu', function () {
    $espace = F::espace();
    [$id, $siret, $siren] = ficheSante($espace, ['siret' => null, 'postcode' => '99003']);

    $r = bilanSante($espace, csvSante([ligneSante($siren . '00029', '620'), ligneSante($siren . '00037', '101')]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($r['siren_ambigu'])->toBe(1)->and($r['trouvees_n2'])->toBe(0)->and($r['fiches_enrichies'])->toBe(0);
});

it('même SIRET en 620 et en 101 avec des téléphones différents : siret_partage', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);
    $l620 = ['telephone' => '04 00 00 00 02'] + ligneSante($siret, '620');

    $r = bilanSante($espace, csvSante([ligneSante($siret, '101'), $l620]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($r['siret_partage'])->toBe(1)->and($r['fiches_siret_partage'])->toBe(1)->and($r['fiches_enrichies'])->toBe(0);
});

it('deux lignes identiques pour un même SIRET : pas un partage, la fiche est enrichie', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);

    $r = bilanSante($espace, csvSante([ligneSante($siret), ligneSante($siret)]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBe('0140000001');
    expect($r['siret_partage'])->toBe(0)->and($r['fiches_enrichies'])->toBe(1);
});

it('fiche non_officine : exclusion volontaire comptée à part', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace, ['metadata' => json_encode(['type_etablissement' => 'non_officine'])]);

    $r = bilanSante($espace, csvSante([ligneSante($siret)]));

    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($r['exclues'])->toBe(1)->and($r['exclues_non_officine'])->toBe(1);
});

it('ecrire() directement : 0 ligne sur une fiche protégée ou déjà remplie', function () {
    $espace = F::espace();
    [$protegee] = ficheSante($espace);
    [$remplie] = ficheSante($espace, ['address' => '9 RUE EXISTANTE', 'postcode' => '99003', 'city' => 'AUTRE', 'lat' => 45.0, 'lon' => 1.0]);
    F::proteger($espace, $protegee, 'src:scraping-evenements-pro');
    $adresse = ['rue' => '3 rue zz', 'champs' => ['address' => '3 RUE ZZ', 'postcode' => '99003', 'city' => 'ZZVILLE']];

    $ecrits = WorkspaceContext::run($espace, function () use ($espace, $protegee, $remplie, $adresse): int {
        $tf = new TelephonesFiness;

        return $tf->ecrire($espace, $protegee, 'gps', [48.1, 2.1])
            + $tf->ecrire($espace, $protegee, 'adresse', $adresse)
            + $tf->ecrire($espace, $remplie, 'gps', [48.1, 2.1])
            + $tf->ecrire($espace, $remplie, 'adresse', $adresse);
    });

    expect($ecrits)->toBe(0);
    expect(DB::table('companies')->where('id', $protegee)->value('lat'))->toBeNull();
    expect((float) DB::table('companies')->where('id', $remplie)->value('lat'))->toBe(45.0);
});

/** @return array<string, string> ligne CSV avec libellés de catégorie */
function ligneClassee(string $siret, string $categorie = '355', string $libelle = 'Centre Hospitalier ZZ', string $famille = 'Établissements hospitaliers'): array
{
    return array_merge(ligneSante($siret, $categorie), ['categorie_libelle' => $libelle, 'categorie_agregat_niv1_libelle' => $famille]);
}

function bilanClasse(string $espace, string $csv, bool $apply = true, bool $inclure = false): array
{
    return (new EnrichissementFinessSante)->executer($espace, $csv, $apply, [], 500, true, $inclure);
}

it('classer : fiche N1 déjà avec téléphone est classée avec le libellé du CSV', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace, ['phone' => '0499999999', 'metadata' => json_encode(['sector_main' => 'sante'])]);

    $r = bilanClasse($espace, csvSante([ligneClassee($siret)]));

    $m = metaSante($id);
    expect($m['finess_categorie'])->toBe('355')->and($m['finess_categorie_libelle'])->toBe('Centre Hospitalier ZZ')
        ->and($m['finess_categorie_famille'])->toBe('Établissements hospitaliers')->and($m['sector_main'])->toBe('sante');
    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBe('0499999999');
    expect($r['classees'])->toBe(1)->and($r['categorie_conflit'])->toBe(0);
});

it('classer : CSV sans libellé → libellé de secours, code inconnu → libellé neutre', function () {
    $espace = F::espace();
    [$a, $sa] = ficheSante($espace);
    [$b, $sb] = ficheSante($espace);

    bilanClasse($espace, csvSante([ligneClassee($sa, '500', '', ''), ligneClassee($sb, '999', '', '')]));

    expect(metaSante($a)['finess_categorie_libelle'])->toBe('EHPAD')->and(metaSante($a))->not->toHaveKey('finess_categorie_famille');
    expect(metaSante($b)['finess_categorie_libelle'])->toBe('Établissement de santé (FINESS 999)');
});

it('classer : finess_categorie existant → rien écrit, categorie_conflit compté', function () {
    $espace = F::espace();
    [$a, $sa] = ficheSante($espace, ['metadata' => json_encode(['finess_categorie' => '620'])]);
    [$b, $sb] = ficheSante($espace, ['metadata' => json_encode(['finess_categorie' => '101'])]);

    $r = bilanClasse($espace, csvSante([ligneClassee($sa), ligneClassee($sb, '355')]));

    expect(metaSante($a)['finess_categorie'])->toBe('620')->and(metaSante($a))->not->toHaveKey('finess_categorie_libelle');
    expect(metaSante($b)['finess_categorie'])->toBe('101');
    expect($r['classees'])->toBe(0)->and($r['categorie_conflit'])->toBe(2);
});

it('non_officine : écartée sans l\'option, enrichie et classée avec elle, marqueur gardé', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace, ['metadata' => json_encode(['type_etablissement' => 'non_officine'])]);
    $csv = csvSante([ligneClassee($siret)]);

    bilanClasse($espace, $csv);
    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull()->and(metaSante($id))->not->toHaveKey('finess_categorie');

    $r = bilanClasse($espace, $csv, true, true);

    $c = DB::table('companies')->where('id', $id)->first();
    expect($c->phone)->toBe('0140000001')->and($c->address)->toBe('3 RUE ZZ');
    expect(metaSante($id)['type_etablissement'])->toBe('non_officine')->and(metaSante($id)['finess_categorie'])->toBe('355');
    expect($r['non_officine_incluses'])->toBe(1)->and($r['classees'])->toBe(1);
});

it('classer : fiche fermée, protégée, en corbeille ou écartée → rien', function () {
    $espace = F::espace();
    [$f, $sf] = ficheSante($espace, ['insee_ferme_le' => '2020-01-01']);
    [$p, $sp] = ficheSante($espace);
    F::proteger($espace, $p, 'src:scraping-evenements-pro');
    [$t, $st] = ficheSante($espace, ['deleted_at' => now()]);
    [$e, $se] = ficheSante($espace, ['metadata' => json_encode(['ecartee_motif' => 'test'])]);

    $r = bilanClasse($espace, csvSante([ligneClassee($sf), ligneClassee($sp), ligneClassee($st), ligneClassee($se)]), true, true);

    foreach ([$f, $p, $t, $e] as $id) {
        expect(metaSante($id))->not->toHaveKey('finess_categorie');
    }
    expect($r['classees'])->toBe(0);
});

it('classer : essai à blanc n\'écrit rien, le passage suivant classe puis le troisième ne classe plus', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);
    $csv = csvSante([ligneClassee($siret)]);

    $blanc = bilanClasse($espace, $csv, false);
    expect($blanc['classees'])->toBe(1)->and(metaSante($id))->not->toHaveKey('finess_categorie');

    expect(bilanClasse($espace, $csv)['classees'])->toBe(1);
    expect(bilanClasse($espace, $csv)['classees'])->toBe(0);
});

it('--max-classer dépassé : apply refusé, rien n\'est écrit', function () {
    $espace = F::espace();
    [$a, $sa] = ficheSante($espace);
    [$b, $sb] = ficheSante($espace);
    $csv = csvSante([ligneClassee($sa), ligneClassee($sb)]);

    $code = Artisan::call('crm:sante:enrichir-finess', ['fichier' => $csv, '--workspace' => $espace, '--apply' => true, '--classer' => true, '--max-classer' => 1]);

    expect($code)->toBe(1);
    foreach ([$a, $b] as $id) {
        expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull()->and(metaSante($id))->not->toHaveKey('finess_categorie');
    }
    $code = Artisan::call('crm:sante:enrichir-finess', ['fichier' => $csv, '--workspace' => $espace, '--apply' => true, '--classer' => true, '--max-classer' => 2]);
    expect($code)->toBe(0)->and(metaSante($a)['finess_categorie'])->toBe('355');
});

it('classer : fiche N2 (rapprochée par SIREN) jamais classée', function () {
    $espace = F::espace();
    $siren = F::siren();
    $id = F::fiche($espace, 'ZZ CLINIQUE', ['siren' => $siren, 'siret' => null, 'legal_form' => '5499', 'postcode' => '99003']);

    $r = bilanClasse($espace, csvSante([ligneClassee($siren . '00011')]));

    expect($r['trouvees_n2'])->toBe(1)->and($r['classees'])->toBe(0);
    expect(metaSante($id))->not->toHaveKey('finess_categorie');
});

it('classer : fiche en opposition (téléphone, e-mail) jamais classée, compteur dédié', function () {
    $espace = F::espace();
    $email = 'zz-classe-opposee@zz.example.invalid';
    [$a, $sa] = ficheSante($espace, ['phone' => '01 40 00 00 05']);
    [$b, $sb] = ficheSante($espace, ['email_generic' => $email]);
    DB::table('opt_out')->insert(['phone' => '+33 1 40 00 00 05', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    DB::table('opt_out')->insert(['email_hash' => hash('sha256', $email), 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);

    $r = bilanClasse($espace, csvSante([ligneClassee($sa), ['telephone' => ''] + ligneClassee($sb)]));

    foreach ([$a, $b] as $id) {
        expect(metaSante($id))->not->toHaveKey('finess_categorie');
    }
    expect($r['classement_opposition'])->toBe(2)->and($r['classees'])->toBe(0);
});

it('classer : même code déjà présent → deja_classee, ni classees ni categorie_conflit', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace, ['metadata' => json_encode(['finess_categorie' => '355'])]);

    $r = bilanClasse($espace, csvSante([ligneClassee($siret)]));

    expect($r['deja_classee'])->toBe(1)->and($r['classees'])->toBe(0)->and($r['categorie_conflit'])->toBe(0);
    expect(metaSante($id))->not->toHaveKey('finess_categorie_libelle');
});

it('classer : metadata JSON null → fiche classée', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);
    DB::table('companies')->where('id', $id)->update(['metadata' => DB::raw("'null'::jsonb")]);

    $r = bilanClasse($espace, csvSante([ligneClassee($siret)]));

    expect($r['classees'])->toBe(1)->and(metaSante($id)['finess_categorie'])->toBe('355');
});

it('--classer sans --apply via Artisan : rien n\'est écrit, sortie en totaux seulement', function () {
    $espace = F::espace();
    [$id, $siret] = ficheSante($espace);
    $csv = csvSante([ligneClassee($siret)]);

    $code = Artisan::call('crm:sante:enrichir-finess', ['fichier' => $csv, '--workspace' => $espace, '--classer' => true]);
    $sortie = Artisan::output();

    expect($code)->toBe(0)->and(metaSante($id))->not->toHaveKey('finess_categorie');
    expect(DB::table('companies')->where('id', $id)->value('phone'))->toBeNull();
    expect($sortie)->toContain('ESSAI À BLANC')->toContain('fiches à classer : 1')->not->toContain('01 40 00 00 01')->not->toContain($siret);
});

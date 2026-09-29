<?php

/**
 * DOUBLONS — la DÉTECTION (`crm:doublons:detecter`, chantier 5).
 *
 * Ce que chaque test prouve, par son EFFET en base :
 *  - les paires attendues, avec leur motif, leur sens (a = gardée, b =
 *    absorbée) et leur preuve (`fusion_auto`) ;
 *  - JAMAIS de paire entre deux SIREN différents, ni pour une adresse
 *    partagée (cabinet comptable, domiciliation) ;
 *  - les adresses partagées, par EMPREINTE, avec leur nature ;
 *  - l'essai à blanc n'écrit rien et annonce exactement le bilan du réel ;
 *  - relancer ne double rien ; une paire écartée n'est jamais reproposée ;
 *  - la clé unique des SIREN est VÉRIFIÉE, et son absence déclenche la
 *    recherche « même SIREN » ;
 *  - un nom trop répandu n'est pas rapproché.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\Rapprochement;
use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace();
    $ws = $this->ws;

    // ALPHA — fiche de collecte sans SIREN + fiche INSEE : même nom, même CP,
    // même site (écrit autrement) → preuve certaine.
    $this->alphaInsee = F::fiche($ws, 'ZZ Alpha', ['postcode' => '69001', 'website' => 'https://www.zz-alpha.example.invalid']);
    $this->alphaEvt = F::sansSiren($ws, 'ZZ ALPHA', ['postcode' => '69001', 'website' => 'zz-alpha.example.invalid/contact']);

    // BETA — même nom, même CP, pas de site d'un côté → à vérifier.
    $this->betaInsee = F::fiche($ws, 'ZZ Beta', ['postcode' => '69002']);
    $this->betaEvt = F::sansSiren($ws, 'ZZ Beta', ['postcode' => '69002', 'website' => 'https://zz-beta.example.invalid']);

    // GAMMA — deux SIREN différents, même nom, même CP, même site : JAMAIS une paire.
    $this->gamma1 = F::fiche($ws, 'ZZ Gamma', ['postcode' => '69003', 'website' => 'https://zz-gamma.example.invalid']);
    $this->gamma2 = F::fiche($ws, 'ZZ Gamma', ['postcode' => '69003', 'website' => 'https://zz-gamma.example.invalid']);

    // DELTA — tout concorde, mais la fiche à SIREN ne vient pas de l'INSEE → à vérifier.
    $this->deltaAnnuaire = F::fiche($ws, 'ZZ Delta', ['postcode' => '69004', 'website' => 'https://zz-delta.example.invalid', 'discovery_source' => 'annuaire-entreprises']);
    $this->deltaEvt = F::sansSiren($ws, 'ZZ Delta', ['postcode' => '69004', 'website' => 'https://zz-delta.example.invalid']);

    // EPSILON — deux fiches sans SIREN, même nom, même CP ; la protégée est gardée.
    $this->epsilon1 = F::sansSiren($ws, 'ZZ Epsilon', ['postcode' => '69005', 'discovery_source' => 'gplaces']);
    $this->epsilon2 = F::sansSiren($ws, 'ZZ Epsilon', ['postcode' => '69005']);
    F::proteger($ws, $this->epsilon2, FichesProtegees::TAG_FEDERATIONS);

    // ETA — deux pages Facebook ne font pas « le même site ».
    $this->etaInsee = F::fiche($ws, 'ZZ Eta', ['postcode' => '69007', 'website' => 'https://www.facebook.com/zz-eta']);
    $this->etaEvt = F::sansSiren($ws, 'ZZ Eta', ['postcode' => '69007', 'website' => 'https://www.facebook.com/zz-eta']);

    // L'ADRESSE D'UN CABINET COMPTABLE, sur trois fiches de noms différents.
    $this->email = 'Compta@zz-cabinet.example.invalid';
    $this->cabinet = F::fiche($ws, 'ZZ Cabinet', ['email_generic' => $this->email, 'naf_rev2' => '69.20Z', 'website' => 'https://zz-cabinet.example.invalid']);
    $this->client1 = F::fiche($ws, 'ZZ Client Un', ['email_generic' => strtolower($this->email), 'postcode' => '69008']);
    $this->client2 = F::sansSiren($ws, 'ZZ Client Deux', ['email_generic' => $this->email, 'postcode' => '69008']);
    // Une adresse portée par une seule fiche n'est pas partagée.
    F::fiche($ws, 'ZZ Seule', ['email_generic' => 'contact@zz-seule.example.invalid']);
});

/** @param  array<string, mixed>  $options */
function ddDetecter(string $ws, array $options = []): array
{
    $code = Artisan::call('crm:doublons:detecter', ['--workspace' => $ws] + $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

/** @return array<string, array{motif: string, auto: bool, traitee: bool}> « a-b » => paire */
function ddPaires(string $ws): array
{
    $paires = [];
    foreach (DB::table('duplicate_flags')->where('workspace_id', $ws)->orderBy('id')->get() as $d) {
        $paires[$d->entity_a_id.'-'.$d->entity_b_id] = ['motif' => (string) $d->motif, 'auto' => (bool) $d->fusion_auto, 'traitee' => $d->reviewed_at !== null];
    }

    return $paires;
}

test('les paires attendues, leur sens, leur motif et leur preuve — et aucune entre deux SIREN', function () {
    $r = ddDetecter($this->ws);

    expect($r['code'])->toBe(0);
    expect(ddPaires($this->ws))->toBe([
        "{$this->alphaInsee}-{$this->alphaEvt}" => ['motif' => Rapprochement::NOM_CP_SITE, 'auto' => true, 'traitee' => false],
        "{$this->betaInsee}-{$this->betaEvt}" => ['motif' => Rapprochement::NOM_CP, 'auto' => false, 'traitee' => false],
        "{$this->deltaAnnuaire}-{$this->deltaEvt}" => ['motif' => Rapprochement::NOM_CP_SITE, 'auto' => false, 'traitee' => false],
        "{$this->epsilon2}-{$this->epsilon1}" => ['motif' => Rapprochement::SANS_SIREN_NOM, 'auto' => false, 'traitee' => false],
        "{$this->etaInsee}-{$this->etaEvt}" => ['motif' => Rapprochement::NOM_CP, 'auto' => false, 'traitee' => false],
    ]);
    // Deux SIREN différents, ou une adresse partagée : aucune paire.
    foreach ([$this->gamma1, $this->gamma2, $this->cabinet, $this->client1, $this->client2] as $id) {
        expect(DB::table('duplicate_flags')->where('entity_a_id', $id)->orWhere('entity_b_id', $id)->exists())->toBeFalse();
    }
    expect(F::compteur($r['sortie'], 'fusions_certaines'))->toBe(1)
        ->and(F::compteur($r['sortie'], 'file_verification'))->toBe(4)
        ->and(F::compteur($r['sortie'], 'paires_nouvelles'))->toBe(5)
        ->and(F::compteur($r['sortie'], 'paires_nom_cp_site'))->toBe(2)
        ->and(F::compteur($r['sortie'], 'cle_unique_siren_presente'))->toBe(1)
        ->and(F::compteur($r['sortie'], 'cle_unique_identifiant_presente'))->toBe(1);
    // Rien n'a été fusionné ni mis à la corbeille.
    expect(DB::table('companies')->where('workspace_id', $this->ws)->whereNotNull('deleted_at')->count())->toBe(0);
});

test('l adresse du cabinet comptable est inscrite par son EMPREINTE, avec sa nature, jamais en clair', function () {
    $r = ddDetecter($this->ws);

    $lignes = DB::table('adresses_partagees')->where('workspace_id', $this->ws)->get();
    expect($lignes)->toHaveCount(1);
    $ligne = $lignes->first();
    expect($ligne->email_empreinte)->toBe(ListeSuppression::empreinte($this->email))
        ->and($ligne->nb_fiches)->toBe(3)
        ->and($ligne->nature)->toBe(Rapprochement::CABINET_COMPTABLE)
        ->and($ligne->domaine)->toBe('zz-cabinet.example.invalid')
        ->and(json_encode($ligne))->not->toContain('compta@');
    expect(F::compteur($r['sortie'], 'adresses_partagees'))->toBe(1)
        ->and(F::compteur($r['sortie'], 'adresses_cabinet_comptable'))->toBe(1)
        ->and(F::compteur($r['sortie'], 'fiches_portant_une_adresse_partagee'))->toBe(3);
});

test('l essai à blanc n écrit RIEN et annonce exactement le bilan de l exécution réelle', function () {
    $blanc = ddDetecter($this->ws, ['--dry-run' => true]);

    expect($blanc['code'])->toBe(0)
        ->and(DB::table('duplicate_flags')->where('workspace_id', $this->ws)->count())->toBe(0)
        ->and(DB::table('adresses_partagees')->where('workspace_id', $this->ws)->count())->toBe(0)
        ->and($blanc['sortie'])->toContain('[À BLANC]');

    $reel = ddDetecter($this->ws);
    expect(F::bilan($blanc['sortie']))->toBe(F::bilan($reel['sortie']))
        ->and(DB::table('duplicate_flags')->where('workspace_id', $this->ws)->count())->toBe(5);
});

test('relancer ne double rien ; une paire écartée n est jamais reproposée ni modifiée', function () {
    ddDetecter($this->ws);
    $beta = DB::table('duplicate_flags')->where('entity_a_id', $this->betaInsee)->first();
    DB::table('duplicate_flags')->where('id', $beta->id)->update(['reviewed_at' => now()->subDay(), 'resolution' => 'keep_both', 'motif' => Rapprochement::NOM_SITE]);

    $r = ddDetecter($this->ws);

    $apres = DB::table('duplicate_flags')->where('id', $beta->id)->first();
    expect(DB::table('duplicate_flags')->where('workspace_id', $this->ws)->count())->toBe(5)
        ->and($apres->resolution)->toBe('keep_both')
        // La ligne écartée n'est pas réécrite (motif laissé tel quel).
        ->and($apres->motif)->toBe(Rapprochement::NOM_SITE)
        ->and(F::compteur($r['sortie'], 'paires_nouvelles'))->toBe(0)
        ->and(F::compteur($r['sortie'], 'paires_deja_connues'))->toBe(4)
        ->and(F::compteur($r['sortie'], 'paires_deja_traitees'))->toBe(1)
        ->and(F::compteur($r['sortie'], 'file_verification'))->toBe(3);
});

test('une paire déjà inscrite DANS L AUTRE SENS n est pas doublée', function () {
    DB::table('duplicate_flags')->insert([
        'workspace_id' => $this->ws, 'entity_type' => 'company', 'entity_a_id' => $this->betaEvt, 'entity_b_id' => $this->betaInsee,
        'similarity' => 0.5, 'motif' => Rapprochement::NOM_CP,
    ]);

    $r = ddDetecter($this->ws);

    expect(DB::table('duplicate_flags')->where('workspace_id', $this->ws)
        ->whereIn('entity_a_id', [$this->betaEvt, $this->betaInsee])->count())->toBe(1)
        ->and(F::compteur($r['sortie'], 'paires_deja_connues'))->toBe(1);
});

test('la clé unique des SIREN est VÉRIFIÉE : sans elle, deux fiches au même SIREN forment une paire certaine', function () {
    // La clé tombe DANS la transaction du test (annulée ensuite).
    DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_workspace_id_siren_key');
    $un = F::fiche($this->ws, 'ZZ Theta', ['siren' => '949999991']);
    $deux = F::fiche($this->ws, 'ZZ Theta bis', ['siren' => '949999991']);

    $r = ddDetecter($this->ws);

    expect(ddPaires($this->ws)["{$un}-{$deux}"] ?? null)->toBe(['motif' => Rapprochement::MEME_SIREN, 'auto' => true, 'traitee' => false])
        ->and(F::compteur($r['sortie'], 'cle_unique_siren_presente'))->toBe(0)
        ->and($r['sortie'])->toContain('ABSENTE');
});

test('les adresses qui ne sont plus partagées quittent la table à la fin d un parcours COMPLET seulement', function () {
    $perimee = ['workspace_id' => $this->ws, 'email_empreinte' => hash('sha256', 'zz-plus-partagee'), 'nb_fiches' => 2, 'nature' => 'inconnue', 'calculee_le' => now()->subDay()];
    DB::table('adresses_partagees')->insert($perimee);

    // Une reprise ne revoit pas les lots précédents : elle ne retire rien.
    $reprise = ddDetecter($this->ws, ['--depuis-id' => $this->alphaInsee]);
    expect(DB::table('adresses_partagees')->where('email_empreinte', $perimee['email_empreinte'])->exists())->toBeTrue()
        ->and(F::compteur($reprise['sortie'], 'adresses_plus_partagees'))->toBe(0);

    // À blanc, le parcours complet ANNONCE le retrait sans le faire.
    $blanc = ddDetecter($this->ws, ['--dry-run' => true]);
    expect(F::compteur($blanc['sortie'], 'adresses_plus_partagees'))->toBe(1)
        ->and(DB::table('adresses_partagees')->where('email_empreinte', $perimee['email_empreinte'])->exists())->toBeTrue();

    $complet = ddDetecter($this->ws);
    expect(DB::table('adresses_partagees')->where('email_empreinte', $perimee['email_empreinte'])->exists())->toBeFalse()
        ->and(DB::table('adresses_partagees')->where('workspace_id', $this->ws)->count())->toBe(1)
        ->and(F::compteur($complet['sortie'], 'adresses_plus_partagees'))->toBe(1);
});

test('par lots, et reprenable : deux demi-parcours valent un parcours entier', function () {
    // Du volume : 250 fiches ordinaires, pour trois lots de 100.
    DB::statement("
        INSERT INTO companies (workspace_id, siren, denomination, discovery_source, created_at, updated_at)
        SELECT ?, '93' || lpad(g::text, 7, '0'), 'ZZ Volume ' || g::text, 'insee', now(), now()
        FROM generate_series(1, 250) g
    ", [$this->ws]);
    $tard = F::sansSiren($this->ws, 'ZZ Volume 7', ['postcode' => null, 'website' => null]);
    DB::table('companies')->where('workspace_id', $this->ws)->where('denomination', 'ZZ Volume 7')->where('id', '<>', $tard)
        ->update(['website' => 'https://zz-volume7.example.invalid']);
    DB::table('companies')->where('id', $tard)->update(['website' => 'https://zz-volume7.example.invalid']);

    $premier = ddDetecter($this->ws, ['--lot' => 100, '--max-lots' => 1]);
    preg_match('/--depuis-id=(\d+)/', $premier['sortie'], $m);
    expect($m)->not->toBeEmpty();
    $suite = ddDetecter($this->ws, ['--lot' => 100, '--depuis-id' => $m[1]]);

    expect(F::compteur($premier['sortie'], 'lots'))->toBe(1)
        ->and(F::compteur($suite['sortie'], 'lots'))->toBeGreaterThanOrEqual(2)
        ->and(DB::table('duplicate_flags')->where('workspace_id', $this->ws)->count())->toBe(6)
        ->and(DB::table('duplicate_flags')->where('entity_b_id', $tard)->value('motif'))->toBe(Rapprochement::NOM_SITE);
});

test('un nom porté par plus de 50 fiches n est pas rapproché : compté, jamais proposé', function () {
    $x = F::sansSiren($this->ws, 'ZZ Commun', ['postcode' => '69099']);
    for ($i = 0; $i < 51; $i++) {
        F::fiche($this->ws, 'ZZ Commun', ['postcode' => '69099']);
    }

    $r = ddDetecter($this->ws);

    expect(DB::table('duplicate_flags')->where('entity_b_id', $x)->exists())->toBeFalse()
        ->and(F::compteur($r['sortie'], 'noms_trop_repandus'))->toBe(1);
});

test('TÉMOIN — à 50 homonymes, le nom est encore rapproché', function () {
    $x = F::sansSiren($this->ws, 'ZZ Commun', ['postcode' => '69099']);
    for ($i = 0; $i < 50; $i++) {
        F::fiche($this->ws, 'ZZ Commun', ['postcode' => '69099']);
    }

    $r = ddDetecter($this->ws);

    expect(DB::table('duplicate_flags')->where('entity_b_id', $x)->count())->toBe(50)
        ->and(F::compteur($r['sortie'], 'noms_trop_repandus'))->toBe(0);
});

test('les journaux publics ne montrent que des nombres (--compteurs-seulement)', function () {
    $r = ddDetecter($this->ws, ['--compteurs-seulement' => true]);

    expect($r['sortie'])->not->toContain($this->ws)
        ->and($r['sortie'])->not->toContain('ZZ ')
        ->and($r['sortie'])->not->toContain('@');
});

test('le ménage de la table dérivée a un PLAFOND : un détecteur qui se tromperait ne la viderait pas', function () {
    // 1 500 adresses « plus partagées » : au-dessus du plancher de 1 000, et
    // presque toute la table.
    DB::statement("
        INSERT INTO adresses_partagees (workspace_id, email_empreinte, nb_fiches, nature, calculee_le)
        SELECT ?, encode(digest('zz-perimee-' || g::text, 'sha256'), 'hex'), 2, 'inconnue', now() - interval '1 day'
        FROM generate_series(1, 1500) g
    ", [$this->ws]);

    $refus = ddDetecter($this->ws);
    expect($refus['sortie'])->toContain('REFUS')
        ->and(F::compteur($refus['sortie'], 'adresses_plus_partagees'))->toBe(1500)
        ->and(DB::table('adresses_partagees')->where('workspace_id', $this->ws)->count())->toBe(1501);

    // TÉMOIN : levé à la main, le ménage se fait.
    $force = ddDetecter($this->ws, ['--force' => true]);
    expect($force['code'])->toBe(0)
        ->and(DB::table('adresses_partagees')->where('workspace_id', $this->ws)->count())->toBe(1);
});

<?php

/**
 * L'IMPORT DES FÉDÉRATIONS PAR PAQUETS (2026-09-29).
 *
 * L'essai à blanc du 29/09 sur 35 597 lignes est mort en production sur
 * `out of shared memory` (HINT : `max_locks_per_transaction`) : dans UNE
 * transaction, chaque point de sauvegarde qui écrit garde le verrou de son
 * identifiant de transaction jusqu'à la fin — environ quatre par ligne,
 * mesurés en CI (92 pour 20 lignes, 332 pour 80).
 *
 * Ces tests tournent SANS `RefreshDatabase` : sous elle, toute la commande
 * vivrait dans la transaction du test, et un paquet « validé » ne rendrait
 * rien. Ici, chaque paquet est une VRAIE transaction. Espace propre au test,
 * nettoyé à la fin ; la chaîne d'audit (partagée) est simulée.
 *
 * Fixtures FICTIVES uniquement (dépôt public).
 */

use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    config(['crm.scrape_funnel.validate_mx' => false]);

    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-fed-paquets-' . substr(str_replace('-', '', $this->espace), 0, 8);
    DB::table('workspaces')->insert([
        'id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ import par paquets', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['crm.ingest.business_workspace' => $this->slug]);
    $GLOBALS['zz_fpq_fichiers'] = [];
});

afterEach(function () {
    // Une transaction laissée ouverte par la commande (ce que le test de
    // reprise interdit) ne doit pas bloquer la suite : on la referme d'abord.
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($GLOBALS['zz_fpq_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    DB::statement('DROP TRIGGER IF EXISTS zz_fpq_panne ON companies');
    DB::statement('DROP TRIGGER IF EXISTS zz_fpq_panne ON federations');
    DB::statement('DROP FUNCTION IF EXISTS zz_fpq_panne()');
    $espace = $this->espace;
    DB::transaction(function () use ($espace): void {
        // Les fiches sont PROTÉGÉES : levée volontaire documentée.
        DB::statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        foreach (['federations', 'company_tag', 'contacts', 'activities', 'scraper_runs', 'business_events'] as $table) {
            DB::table($table)->where('workspace_id', $espace)->delete();
        }
        DB::table('companies')->where('workspace_id', $espace)->delete();
        DB::table('contacts_retires')->where('workspace_id', $espace)->delete();
        DB::table('tags')->where('workspace_id', $espace)->delete();
        DB::table('workspaces')->where('id', $espace)->delete();
    });
});

/**
 * `$n` organismes fictifs : une nationale puis ses antennes, chacune avec une
 * personne et une adresse (tout ce qu'une ligne écrit en vrai).
 *
 * @return list<array<string, mixed>>
 */
function fpqLignes(string $prefixe, int $n): array
{
    $lignes = [];
    for ($i = 1; $i <= $n; $i++) {
        $siren = $prefixe . str_pad((string) $i, 9 - strlen($prefixe), '0', STR_PAD_LEFT);
        $lignes[] = [
            'siren' => $siren, 'nom' => 'ZZ PAQUET ' . $siren, 'famille' => 'federation_syndicat_pro',
            'niveau' => $i === 1 ? 'national' : 'departemental', 'secteurs' => ['btp'], 'tailles_adherents' => ['tpe'],
            'pertinence' => 'haute', 'contactabilite' => 'email_verifie', 'departement' => '69',
            'email_generique' => 'contact@zz-' . $siren . '.example.invalid',
            'personnes' => [['prenom' => 'Zed', 'nom' => 'ZZP' . $siren, 'fonction' => 'Président', 'email' => 'p@zz-' . $siren . '.example.invalid', 'linkedin' => null]],
            'tete_de_reseau' => $i === 1 ? null : $prefixe . str_pad('1', 9 - strlen($prefixe), '0', STR_PAD_LEFT),
        ];
    }

    return $lignes;
}

/** @param  list<array<string, mixed>>  $lignes */
function fpqFichier(array $lignes): string
{
    $chemin = (string) tempnam(sys_get_temp_dir(), 'zz-fpq-');
    $GLOBALS['zz_fpq_fichiers'][] = $chemin;
    file_put_contents($chemin, implode("\n", array_map(static fn (array $l): string => (string) json_encode($l), $lignes)) . "\n");

    return $chemin;
}

/**
 * @param  list<array<string, mixed>>  $lignes
 * @param  array<string, mixed>  $options
 * @return array{code: int, sortie: string, verrous: int, tx: int}
 */
function fpqImporter(array $lignes, array $options = []): array
{
    $code = Artisan::call('crm:import-federations', ['file' => fpqFichier($lignes)] + $options);
    $sortie = Artisan::output();
    preg_match('/Verrous tenus au plus en fin de paquet : (\d+) \(dont (\d+) d/', $sortie, $m);
    expect($m)->not->toBeEmpty('La sortie ne dit pas les verrous tenus.');

    return ['code' => $code, 'sortie' => $sortie, 'verrous' => (int) $m[1], 'tx' => (int) $m[2]];
}

function fpqCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);
    expect($m)->not->toBeEmpty("Compteur « {$cle} » absent de la sortie.");

    return (int) $m[1];
}

/** Le tableau du bilan, sans la ligne d'en-tête qui dit « à blanc » ou non. */
function fpqBilan(string $sortie): string
{
    preg_match_all('/^\|.*\|$/m', $sortie, $m);

    return implode("\n", $m[0]);
}

function fpqFiches(string $espace): int
{
    return DB::table('companies')->where('workspace_id', $espace)->count();
}

test('les verrous tenus sont bornes par le PAQUET, pas par le fichier — en reel comme a blanc', function () {
    $petit = fpqImporter(fpqLignes('9701', 15), ['--paquet' => '10']);
    $grand = fpqImporter(fpqLignes('9702', 60), ['--paquet' => '10']);
    $blanc = fpqImporter(fpqLignes('9703', 60), ['--paquet' => '10', '--dry-run' => true]);

    // TÉMOIN : la sonde voit bien ce qui grandit. Le même fichier en UN seul
    // paquet tient quatre fois plus de verrous d'identifiants de transaction —
    // c'est la structure d'avant, celle qui a débordé en production.
    $unSeul = fpqImporter(fpqLignes('9704', 60), ['--paquet' => '60']);

    expect($petit['code'])->toBe(0)
        ->and($grand['code'])->toBe(0)
        ->and(fpqCompteur($grand['sortie'], 'paquets'))->toBe(6)
        ->and(fpqCompteur($grand['sortie'], 'fiches_creees'))->toBe(60)
        ->and(fpqCompteur($grand['sortie'], 'tetes_liees'))->toBe(59)
        ->and($petit['tx'])->toBeGreaterThan(0)
        // Quatre fois plus de lignes, pas un verrou de plus.
        ->and($grand['tx'])->toBeLessThanOrEqual($petit['tx'] + 4)
        ->and($grand['verrous'])->toBeLessThanOrEqual($petit['verrous'] + 8)
        ->and($blanc['tx'])->toBeLessThanOrEqual($petit['tx'] + 4)
        ->and($unSeul['tx'])->toBeGreaterThan($grand['tx'] * 3)
        // À blanc, rien n'est resté.
        ->and(DB::table('companies')->where('workspace_id', $this->espace)->where('siren', 'like', '9703%')->exists())->toBeFalse();
});

test('un import INTERROMPU garde ses paquets valides ; relance, il reprend sans doublon', function () {
    $lignes = fpqLignes('9705', 30);
    // Panne simulée à la 25e fiche (3e paquet de 10) : la fiche naît à la
    // corbeille, la commande ne la retrouve pas et s'interrompt.
    $panne = 'ZZ PAQUET ' . $lignes[24]['siren'];
    DB::unprepared(<<<SQL
        CREATE FUNCTION zz_fpq_panne() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_catalog AS \$fn\$
        BEGIN
            IF NEW.denomination = '{$panne}' THEN NEW.deleted_at := now(); END IF;
            RETURN NEW;
        END \$fn\$;
        CREATE TRIGGER zz_fpq_panne BEFORE INSERT ON companies FOR EACH ROW EXECUTE FUNCTION zz_fpq_panne();
    SQL);

    $interrompu = null;
    try {
        Artisan::call('crm:import-federations', ['file' => fpqFichier($lignes), '--paquet' => '10']);
    } catch (RuntimeException $e) {
        $interrompu = $e->getMessage();
    }
    $sortie = Artisan::output();

    // Les deux premiers paquets sont en base, le troisième est annulé en entier
    // — et aucune transaction n'est restée ouverte derrière la commande.
    expect($interrompu)->toBe('fiche_introuvable_apres_ingestion')
        ->and(DB::transactionLevel())->toBe(0)
        ->and($sortie)->toContain('INTERROMPU après 2 paquet(s) de fiches et 0 paquet(s) de têtes')
        ->and(fpqFiches($this->espace))->toBe(20)
        ->and(DB::table('federations')->where('workspace_id', $this->espace)->count())->toBe(20);

    DB::statement('DROP TRIGGER zz_fpq_panne ON companies');

    $reprise = fpqImporter($lignes, ['--paquet' => '10']);

    expect($reprise['code'])->toBe(0)
        ->and(fpqCompteur($reprise['sortie'], 'fiches_creees'))->toBe(10)
        ->and(fpqCompteur($reprise['sortie'], 'federations_inchangees'))->toBe(20)
        ->and(fpqFiches($this->espace))->toBe(30)
        ->and(DB::table('contacts')->where('workspace_id', $this->espace)->count())->toBe(30)
        ->and(DB::table('federations')->where('workspace_id', $this->espace)->whereNotNull('parent_company_id')->count())->toBe(29);
});

test('a blanc par paquets : le bilan est celui du reel, tetes estimees a travers les paquets, boucle et lien inchange compris', function () {
    // Déjà en base : A ← B (B a pour tête A), et C ← A (A a pour tête C).
    $fiche = function (string $siren, ?int $parent = null): int {
        $id = (int) DB::table('companies')->insertGetId([
            'workspace_id' => $this->espace, 'siren' => $siren, 'denomination' => 'ZZ EXISTANTE ' . $siren,
            'entity_nature' => 'federation', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('federations')->insert([
            'company_id' => $id, 'workspace_id' => $this->espace, 'famille' => 'confederation', 'niveau' => 'national',
            'pertinence' => 'haute', 'contactabilite' => 'aucun_contact', 'parent_company_id' => $parent,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $c = $fiche('970600003');
    $a = $fiche('970600001', $c);
    $fiche('970600002', $a);

    $ligne = static fn (string $siren, string $niveau, ?string $tete): array => [
        'siren' => $siren, 'nom' => 'ZZ ESTIME ' . $siren, 'famille' => 'confederation', 'niveau' => $niveau,
        'secteurs' => ['interprofessionnel'], 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact',
        'personnes' => [], 'tete_de_reseau' => $tete,
    ];
    $lignes = [];
    // Douze antennes AVANT leur tête : elles sont dans les paquets 1 et 2, la
    // tête dans le paquet 2 — à blanc, le paquet 1 ne l'a jamais vue.
    for ($i = 1; $i <= 12; $i++) {
        $lignes[] = $ligne('9706001' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'departemental', '970600099');
    }
    $lignes[] = $ligne('970600099', 'national', null);
    $lignes[] = $ligne('970600003', 'national', '970600002');   // C → B : B → A → C, boucle
    $lignes[] = $ligne('970600001', 'national', '970600003');   // A → C : déjà posé, inchangé
    $lignes[] = $ligne('970600050', 'local', '970600098');      // tête introuvable

    $avant = [fpqFiches($this->espace), DB::table('federations')->where('workspace_id', $this->espace)->count()];
    $blanc = fpqImporter($lignes, ['--paquet' => '10', '--dry-run' => true]);
    expect([fpqFiches($this->espace), DB::table('federations')->where('workspace_id', $this->espace)->count()])->toBe($avant);

    $reel = fpqImporter($lignes, ['--paquet' => '10']);

    expect(fpqBilan($blanc['sortie']))->toBe(fpqBilan($reel['sortie']))
        ->and(fpqCompteur($reel['sortie'], 'tetes_liees'))->toBe(12)
        ->and(fpqCompteur($reel['sortie'], 'tetes_refusees_cycle'))->toBe(1)
        ->and(fpqCompteur($reel['sortie'], 'tetes_inchangees'))->toBe(1)
        ->and(fpqCompteur($reel['sortie'], 'tetes_introuvables'))->toBe(1)
        ->and($blanc['sortie'])->toContain('MESURÉ : la 1re passe', 'ESTIMÉ d\'après la 1re passe')
        ->and($reel['sortie'])->not->toContain('ESTIMÉ')
        ->and(FichesProtegees::estProtegee((int) DB::table('companies')->where('workspace_id', $this->espace)->where('siren', '970600099')->value('id')))->toBeTrue();
});

test('--paquet refuse une valeur qui n est pas un entier positif', function () {
    foreach (['0', '-3', 'dix'] as $valeur) {
        $code = Artisan::call('crm:import-federations', ['file' => fpqFichier([]), '--paquet' => $valeur]);
        expect($code)->toBe(1)->and(Artisan::output())->toContain('--paquet doit être un entier positif');
    }
});

test('E2 — interrompu dans la 2e passe : le message compte aussi les paquets de tetes valides ; la relance reprend', function () {
    $lignes = fpqLignes('9707', 30);
    // Panne simulée au lien de tête de la 15e fiche : 2e paquet de têtes
    // (29 liens, par 10). Le 1er paquet de têtes est validé.
    $panne = 'ZZ PAQUET ' . $lignes[14]['siren'];
    DB::unprepared(<<<SQL
        CREATE FUNCTION zz_fpq_panne() RETURNS trigger LANGUAGE plpgsql SET search_path = public, pg_catalog AS \$fn\$
        BEGIN
            IF NEW.parent_company_id IS NOT NULL
               AND (SELECT c.denomination FROM companies c WHERE c.id = NEW.company_id) = '{$panne}' THEN
                RAISE EXCEPTION 'zz_panne_deuxieme_passe';
            END IF;
            RETURN NEW;
        END \$fn\$;
        CREATE TRIGGER zz_fpq_panne BEFORE UPDATE ON federations FOR EACH ROW EXECUTE FUNCTION zz_fpq_panne();
    SQL);

    $interrompu = false;
    try {
        Artisan::call('crm:import-federations', ['file' => fpqFichier($lignes), '--paquet' => '10']);
    } catch (QueryException $e) {
        $interrompu = str_contains($e->getMessage(), 'zz_panne_deuxieme_passe');
    }
    $sortie = Artisan::output();
    $lies = DB::table('federations')->where('workspace_id', $this->espace)->whereNotNull('parent_company_id')->count();

    expect($interrompu)->toBeTrue()
        ->and($sortie)->toContain('INTERROMPU après 3 paquet(s) de fiches et 1 paquet(s) de têtes')
        ->and(DB::transactionLevel())->toBe(0)
        ->and($lies)->toBe(10);

    DB::statement('DROP TRIGGER zz_fpq_panne ON federations');
    $reprise = fpqImporter($lignes, ['--paquet' => '10']);

    expect(fpqCompteur($reprise['sortie'], 'tetes_liees'))->toBe(19)
        ->and(fpqCompteur($reprise['sortie'], 'tetes_inchangees'))->toBe(10);
});

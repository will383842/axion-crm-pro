<?php

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * B17-008 — LES IMPORTS HEBDOMADAIRES DE MEDIAS DETRUISENT CE QU'ILS ENRICHISSENT.
 *
 * Constat mesure sur ce depot (2026-08-20) :
 *   - `media:import-opendatasoft` (ImportMediaFromOpendatasoft.php:130-139) et
 *     `media:import-arcom` (ImportMediaFromArcom.php:169-178) font un
 *     `DELETE FROM media WHERE source=... AND workspace_id=...` suivi d'un
 *     `INSERT` de toutes les lignes de la source.
 *   - `routes/console.php:149-151,157` les programme 4 fois par semaine
 *     (cppap 02:15, spel 02:30, agences 02:45 le lundi ; arcom 03:30 le dimanche).
 *   - Consequence schema (2026_07_06_000002_create_media_and_journalists.php) :
 *       * `journalists.media_id BIGINT NULL REFERENCES media(id) ON DELETE SET NULL`
 *         → chaque journaliste rattache est DETACHE (media_id passe a NULL) ;
 *       * `media.parent_media_id ... ON DELETE SET NULL` → les emissions perdent
 *         leur chaine parente ;
 *       * `media.company_id`, `email`, `phone`, `socials`, `enrich_status`,
 *         `enriched_at`, `editorial_theme`, `website*` : la ligne est recreee avec
 *         un id NEUF et `enrich_status='pending'` → tout l'enrichissement de la
 *         semaine (media:enrich toutes les 3 h, media:find-websites 2x/jour,
 *         media:link-to-companies quotidien, journalists:scrape-ours quotidien)
 *         est perdu.
 *
 * CE QUE CETTE GARDE PROUVE (les 3 exigences du mandat) :
 *   1. un rejeu de la MEME source ne detruit pas l'enrichissement pose entre-temps ;
 *   2. les journalistes rattaches restent rattaches ;
 *   3. TEMOIN : une donnee qui VIENT DE LA SOURCE est bien rafraichie — un
 *      correctif qui se contenterait de ne plus rien importer echouerait ici.
 *
 * ANTI-VERT-DEGUISE : chaque scenario verifie d'abord que le 1er import a bien
 * cree la ligne (id > 0) et que l'appel HTTP a bien eu lieu (assertSentCount).
 * Une table absente, une reponse vide ou zero fichier font ECHOUER la garde,
 * elles ne la font pas passer.
 */
function b17WorkspaceRejeu(): Workspace
{
    return Workspace::create([
        'id'   => (string) Str::uuid(),
        'slug' => 'b17-' . Str::random(6),
        'name' => 'WS Rejeu Import',
    ]);
}

/**
 * Pose sur un media l'enrichissement typique d'une semaine de pipeline :
 * email + telephone (media:enrich), socials, statut enrichi, theme editorial,
 * rattachement entreprise (media:link-to-companies).
 */
function b17PoseEnrichissement(int $mediaId, int $companyId): void
{
    DB::table('media')->where('id', $mediaId)->update([
        'email'            => 'redaction@alpha.example',
        'email_confidence' => 'A',
        'phone'            => '0102030405',
        'socials'          => json_encode(['twitter' => '@alpha']),
        'enrich_status'    => 'enriched',
        'enriched_at'      => now(),
        'editorial_theme'  => 'sport',
        'company_id'       => $companyId,
        'updated_at'       => now(),
    ]);
}

function b17InsereCompany(string $ws, string $siren): int
{
    return DB::table('companies')->insertGetId([
        'workspace_id' => $ws,
        'siren'        => $siren,
        'denomination' => 'Editions Alpha',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

/** Journaliste rattache au media (ce que pose journalists:scrape-ours). */
function b17InsereJournaliste(string $ws, int $mediaId): int
{
    return DB::table('journalists')->insertGetId([
        'workspace_id' => $ws,
        'media_id'     => $mediaId,
        'first_name'   => 'Jean',
        'last_name'    => 'Dupont',
        'role'         => 'redacteur en chef',
        'email'        => 'jean.dupont@alpha.example',
        'source'       => 'ours',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

/** Charge utile SPEL (data.culture.gouv.fr) — `editeur` est le TEMOIN source. */
function b17PayloadSpel(string $editeur): array
{
    return [[
        'service'      => 'Journal Test Alpha',
        'editeur'      => $editeur,
        'departement'  => '38',
        'numero_cppap' => '1234 W 56789',
        'url'          => 'alpha.example',
    ]];
}

/**
 * XLSX ARCOM minimal (ZipArchive natif, cellules inlineStr) : en-tete + donnees.
 * Colonnes A..I = nom, nature, categorie, denomination, forme, adresse, CP,
 * commune, pays. Retourne les OCTETS du fichier (pour Http::fake).
 *
 * @param  array<int,array<int,string>>  $dataRows
 */
function b17XlsxArcom(array $dataRows): string
{
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
    $rowsXml = '<row r="1">';
    foreach (['Nom', 'Nature', 'Categorie', 'Denomination', 'Forme', 'Adresse', 'CP', 'Commune', 'Pays'] as $i => $val) {
        $rowsXml .= '<c r="' . $cols[$i] . '1" t="inlineStr"><is><t>' . $val . '</t></is></c>';
    }
    $rowsXml .= '</row>';

    foreach ($dataRows as $n => $row) {
        $r = $n + 2;
        $cellsXml = '';
        foreach ($row as $i => $val) {
            if ($val === '') {
                continue;
            }
            $cellsXml .= '<c r="' . $cols[$i] . $r . '" t="inlineStr"><is><t>'
                . htmlspecialchars($val, ENT_XML1) . '</t></is></c>';
        }
        $rowsXml .= '<row r="' . $r . '">' . $cellsXml . '</row>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $rowsXml . '</sheetData></worksheet>';

    $path = tempnam(sys_get_temp_dir(), 'b17_arcom_') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="xml" ContentType="application/xml"/></Types>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. OPENDATASOFT (cppap | spel | agences) — 3 des 4 executions hebdomadaires
// ─────────────────────────────────────────────────────────────────────────────

it('media:import-opendatasoft rejoue la meme source sans detruire enrichissement ni rattachement', function () {
    $ws = b17WorkspaceRejeu();
    $companyId = b17InsereCompany($ws->id, '123456789');

    // ── Semaine 1 : import initial ───────────────────────────────────────────
    // ⚠️ Un SEUL Http::fake() par test : les stubs sont resolus par
    // `filter()->first()` (PendingRequest.php:1512-1521), donc un 2e Http::fake()
    // n'ecrase PAS le 1er — il est ignore. La charge utile est donc pilotee par
    // reference (verifie : sans ce detail, la garde passait au VERT sans jamais
    // rejouer la source modifiee).
    $payload = b17PayloadSpel('Editions Alpha');
    Http::fake(['data.culture.gouv.fr/*' => function () use (&$payload) {
        return Http::response($payload);
    }]);
    $this->artisan('media:import-opendatasoft', ['source' => 'spel', '--workspace' => $ws->id])
        ->assertExitCode(0);

    $avant = DB::table('media')->where('workspace_id', $ws->id)->where('source', 'spel')->first();
    // ANTI-VERT-DEGUISE : sans ligne creee, le reste de la garde ne veut rien dire.
    expect($avant)->not->toBeNull();
    $idAvant = (int) $avant->id;
    expect($idAvant)->toBeGreaterThan(0);

    // ── Entre-temps : une semaine de pipeline d'enrichissement ───────────────
    b17PoseEnrichissement($idAvant, $companyId);
    $journalisteId = b17InsereJournaliste($ws->id, $idAvant);

    // ── Semaine 2 : rejeu, la source a corrige l'editeur (TEMOIN) ────────────
    $payload = b17PayloadSpel('Editions Alpha Nouvelle');
    $this->artisan('media:import-opendatasoft', ['source' => 'spel', '--workspace' => $ws->id])
        ->assertExitCode(0);

    // La source a bien ete reinterrogee DEUX fois (un correctif qui n'importerait
    // plus rien, ou qui sortirait en erreur avant l'appel, echoue ici).
    Http::assertSentCount(2);

    $apres = DB::table('media')->where('workspace_id', $ws->id)->where('source', 'spel')->whereNull('deleted_at')->get();
    expect($apres)->toHaveCount(1);
    $ligne = $apres->first();

    // (1) La ligne est la MEME (pas supprimee/reinseree) : id stable.
    expect((int) $ligne->id)->toBe($idAvant);

    // (1) L'enrichissement de la semaine survit.
    expect($ligne->email)->toBe('redaction@alpha.example');
    expect($ligne->email_confidence)->toBe('A');
    expect($ligne->phone)->toBe('0102030405');
    expect($ligne->enrich_status)->toBe('enriched');
    expect($ligne->enriched_at)->not->toBeNull();
    expect($ligne->editorial_theme)->toBe('sport');
    expect((int) $ligne->company_id)->toBe($companyId);
    $this->assertStringContainsString('@alpha', (string) $ligne->socials);

    // (2) Le journaliste reste rattache (FK ON DELETE SET NULL sinon).
    $mediaIdJournaliste = DB::table('journalists')->where('id', $journalisteId)->value('media_id');
    expect($mediaIdJournaliste)->not->toBeNull();
    expect((int) $mediaIdJournaliste)->toBe($idAvant);

    // (3) TEMOIN : la donnee de la source est bien rafraichie.
    expect($ligne->publisher)->toBe('Editions Alpha Nouvelle');
});

it('media:import-opendatasoft ne vide pas la source quand la reponse est vide', function () {
    $ws = b17WorkspaceRejeu();

    $payload = b17PayloadSpel('Editions Alpha');
    Http::fake(['data.culture.gouv.fr/*' => function () use (&$payload) {
        return Http::response($payload);
    }]);
    $this->artisan('media:import-opendatasoft', ['source' => 'spel', '--workspace' => $ws->id])
        ->assertExitCode(0);
    expect(DB::table('media')->where('workspace_id', $ws->id)->where('source', 'spel')->count())->toBe(1);

    // Reponse 200 mais dataset vide (coupure amont, export tronque, dataset renomme).
    // Le code d'origine supprimait tout puis n'inserait rien : effacement total.
    $payload = [];
    $this->artisan('media:import-opendatasoft', ['source' => 'spel', '--workspace' => $ws->id]);
    Http::assertSentCount(2);

    expect(DB::table('media')->where('workspace_id', $ws->id)->where('source', 'spel')->whereNull('deleted_at')->count())
        ->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. ARCOM — la 4e execution hebdomadaire (dimanche 03:30)
// ─────────────────────────────────────────────────────────────────────────────

it('media:import-arcom rejoue la meme source sans detruire enrichissement ni rattachement', function () {
    $ws = b17WorkspaceRejeu();
    $companyId = b17InsereCompany($ws->id, '987654321');

    // Meme precaution qu'au-dessus : un seul Http::fake(), charge utile par reference.
    $xlsx = b17XlsxArcom([['Radio Alpha', 'Radio', 'A', 'Editions Alpha', 'Association', '1 rue X', '38000', 'Grenoble', 'France']]);
    Http::fake(['www.arcom.fr/*' => function () use (&$xlsx) {
        return Http::response($xlsx);
    }]);
    $this->artisan('media:import-arcom', ['--workspace' => $ws->id])->assertExitCode(0);

    $avant = DB::table('media')->where('workspace_id', $ws->id)->where('source', 'arcom')->first();
    expect($avant)->not->toBeNull();
    $idAvant = (int) $avant->id;
    expect($idAvant)->toBeGreaterThan(0);

    b17PoseEnrichissement($idAvant, $companyId);
    $journalisteId = b17InsereJournaliste($ws->id, $idAvant);

    // Une emission Wikidata rattachee a cette chaine (parent_media_id ON DELETE SET NULL).
    $emissionId = DB::table('media')->insertGetId([
        'workspace_id'    => $ws->id,
        'parent_media_id' => $idAvant,
        'name'            => 'Emission Alpha',
        'media_type'      => 'tv_emission',
        'media_family'    => 'editorial',
        'enrich_status'   => 'pending',
        'source'          => 'wikidata',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    // Rejeu : la source a corrige la denomination de l'editeur (TEMOIN).
    $xlsx = b17XlsxArcom([['Radio Alpha', 'Radio', 'A', 'Editions Alpha Nouvelle', 'Association', '1 rue X', '38000', 'Grenoble', 'France']]);
    $this->artisan('media:import-arcom', ['--workspace' => $ws->id])->assertExitCode(0);

    Http::assertSentCount(2);

    $apres = DB::table('media')->where('workspace_id', $ws->id)->where('source', 'arcom')->whereNull('deleted_at')->get();
    expect($apres)->toHaveCount(1);
    $ligne = $apres->first();

    expect((int) $ligne->id)->toBe($idAvant);
    expect($ligne->email)->toBe('redaction@alpha.example');
    expect($ligne->enrich_status)->toBe('enriched');
    expect($ligne->editorial_theme)->toBe('sport');
    expect((int) $ligne->company_id)->toBe($companyId);

    $mediaIdJournaliste = DB::table('journalists')->where('id', $journalisteId)->value('media_id');
    expect($mediaIdJournaliste)->not->toBeNull();
    expect((int) $mediaIdJournaliste)->toBe($idAvant);

    // L'emission garde sa chaine parente.
    $parent = DB::table('media')->where('id', $emissionId)->value('parent_media_id');
    expect($parent)->not->toBeNull();
    expect((int) $parent)->toBe($idAvant);

    // TEMOIN source.
    expect($ligne->publisher)->toBe('Editions Alpha Nouvelle');
});

it('media:import-arcom ne vide pas la source quand le fichier ne contient aucune station', function () {
    $ws = b17WorkspaceRejeu();

    $xlsx = b17XlsxArcom([['Radio Alpha', 'Radio', 'A', 'Editions Alpha', 'Association', '1 rue X', '38000', 'Grenoble', 'France']]);
    Http::fake(['www.arcom.fr/*' => function () use (&$xlsx) {
        return Http::response($xlsx);
    }]);
    $this->artisan('media:import-arcom', ['--workspace' => $ws->id])->assertExitCode(0);
    expect(DB::table('media')->where('workspace_id', $ws->id)->where('source', 'arcom')->count())->toBe(1);

    // Fichier valide mais sans aucune ligne de donnees exploitable.
    $xlsx = b17XlsxArcom([]);
    $this->artisan('media:import-arcom', ['--workspace' => $ws->id]);
    Http::assertSentCount(2);

    expect(DB::table('media')->where('workspace_id', $ws->id)->where('source', 'arcom')->whereNull('deleted_at')->count())
        ->toBe(1);
});

// ---------------------------------------------------------------------------
// 3. SORTIE DU REGISTRE : archivage reversible, jamais de suppression
// ---------------------------------------------------------------------------

it('archive au lieu de supprimer une fiche sortie du registre, et la ressuscite a son retour', function () {
    $ws = b17WorkspaceRejeu();

    $payload = [
        ['service' => 'Journal Test Alpha', 'editeur' => 'Editions Alpha', 'departement' => '38', 'numero_cppap' => '1234 W 56789', 'url' => 'alpha.example'],
        ['service' => 'Journal Test Beta',  'editeur' => 'Editions Beta',  'departement' => '75', 'numero_cppap' => '9999 W 11111', 'url' => 'beta.example'],
    ];
    Http::fake(['data.culture.gouv.fr/*' => function () use (&$payload) {
        return Http::response($payload);
    }]);
    $this->artisan('media:import-opendatasoft', ['source' => 'spel', '--workspace' => $ws->id])->assertExitCode(0);

    $betaId = (int) DB::table('media')->where('cppap_number', '9999 W 11111')->value('id');
    expect($betaId)->toBeGreaterThan(0);
    $journalisteId = b17InsereJournaliste($ws->id, $betaId);

    // Beta disparait du registre.
    $payload = [$payload[0]];
    $this->artisan('media:import-opendatasoft', ['source' => 'spel', '--workspace' => $ws->id])->assertExitCode(0);

    // La ligne EXISTE toujours (archivee), donc le journaliste reste rattache :
    // un DELETE aurait mis media_id a NULL (FK ON DELETE SET NULL).
    $beta = DB::table('media')->where('id', $betaId)->first();
    expect($beta)->not->toBeNull();
    expect($beta->deleted_at)->not->toBeNull();
    expect((int) DB::table('journalists')->where('id', $journalisteId)->value('media_id'))->toBe($betaId);

    // Beta revient au registre suivant : resurrection sur la MEME ligne.
    $payload = [
        ['service' => 'Journal Test Alpha', 'editeur' => 'Editions Alpha', 'departement' => '38', 'numero_cppap' => '1234 W 56789', 'url' => 'alpha.example'],
        ['service' => 'Journal Test Beta',  'editeur' => 'Editions Beta',  'departement' => '75', 'numero_cppap' => '9999 W 11111', 'url' => 'beta.example'],
    ];
    $this->artisan('media:import-opendatasoft', ['source' => 'spel', '--workspace' => $ws->id])->assertExitCode(0);

    $beta = DB::table('media')->where('id', $betaId)->first();
    expect($beta->deleted_at)->toBeNull();
    expect(DB::table('media')->where('workspace_id', $ws->id)->where('source', 'spel')->count())->toBe(2);
    Http::assertSentCount(3);
});

it('media:import-arcom --limit n archive pas les stations hors du lot tronque', function () {
    $ws = b17WorkspaceRejeu();

    $xlsx = b17XlsxArcom([
        ['Radio Alpha', 'Radio', 'A', 'Editions Alpha', 'Association', '1 rue X', '38000', 'Grenoble', 'France'],
        ['Radio Beta',  'Radio', 'A', 'Editions Beta',  'Association', '2 rue Y', '75000', 'Paris',    'France'],
    ]);
    Http::fake(['www.arcom.fr/*' => function () use (&$xlsx) {
        return Http::response($xlsx);
    }]);
    $this->artisan('media:import-arcom', ['--workspace' => $ws->id])->assertExitCode(0);
    expect(DB::table('media')->where('source', 'arcom')->whereNull('deleted_at')->count())->toBe(2);

    // Lot VOLONTAIREMENT partiel : --limit=1 ne dit rien sur la station absente.
    $this->artisan('media:import-arcom', ['--workspace' => $ws->id, '--limit' => 1])->assertExitCode(0);

    expect(DB::table('media')->where('source', 'arcom')->whereNull('deleted_at')->count())->toBe(2);
    Http::assertSentCount(2);
});

<?php

namespace Tests\Feature\Envoi\Conformite;

use App\Envoi\Desinscription\EnregistrerOpposition;
use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\DoublonsFixtures as F;

/**
 * R05b — GET /envoi/opposition/export (CSV). Propriétaire (workspaces.manage) ET data.export, flux, journal sans adresse,
 * cellules neutralisées, 2 par heure. Données fictives (.test, .example.invalid).
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const R05B_URI = '/api/v1/envoi/opposition/export';

function r05bOpposer(Workspace $espace, string $adresse, string $canal = 'page'): void
{
    $fiche = F::fiche($espace->id, 'Fiche ' . substr(md5($adresse), 0, 5));
    $contact = F::contact($espace->id, $fiche, 'P', 'N', ['email' => $adresse]);
    $ref = InscriptionsEnvoi::creer($espace->id, $adresse, 'business', $contact);
    app(EnregistrerOpposition::class)->executer($espace->id, $ref, $canal);
}

/** Lit le corps d'une réponse en flux. */
function r05bCorps($reponse): string
{
    expect($reponse->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    return $reponse->streamedContent();
}

beforeEach(function () {
    $this->espace = $this->espace(true);
    $this->proprio = $this->compte($this->espace, 'owner');
});

test('sans session 401 ; sans produit envoi 404 avant toute permission', function () {
    $this->getJson(R05B_URI)->assertUnauthorized();
    $this->actingAs($this->compte($this->espace(false), 'owner'))->getJson(R05B_URI)->assertNotFound();
});

test('403 pour admin, operator et viewer (réservé au propriétaire)', function (string $role) {
    $this->actingAs($this->compte($this->espace, $role))->get(R05B_URI)->assertForbidden();
})->with(['admin', 'operator', 'viewer']);

test('403 pour un compte qui a workspaces.manage SANS data.export', function () {
    $u = $this->compte($this->espace, 'viewer');
    setPermissionsTeamId($this->espace->id);
    $u->givePermissionTo('workspaces.manage');
    expect($u->can('data.export'))->toBeFalse();
    $this->actingAs($u)->get(R05B_URI)->assertForbidden();
});

test('403 pour un compte qui a data.export SANS workspaces.manage', function () {
    $u = $this->compte($this->espace, 'viewer');
    setPermissionsTeamId($this->espace->id);
    $u->givePermissionTo('data.export');
    expect($u->can('workspaces.manage'))->toBeFalse();
    $this->actingAs($u)->get(R05B_URI)->assertForbidden();
});

test('le propriétaire reçoit un CSV en flux : BOM, en-tête, lignes de SON espace seulement, sans empreinte', function () {
    r05bOpposer($this->espace, 'a@zz-r05.example.invalid');
    r05bOpposer($this->espace, 'b@zz-r05.example.invalid', 'one_click');
    $autre = $this->espace(true);
    r05bOpposer($autre, 'zoe@zz-autre.example.invalid');

    $r = $this->actingAs($this->proprio)->get(R05B_URI)->assertOk();
    expect($r->headers->get('Content-Type'))->toContain('text/csv')
        ->and($r->headers->get('Content-Disposition'))->toContain('attachment');
    $corps = r05bCorps($r);
    $lignes = array_values(array_filter(preg_split('/\r?\n/', ltrim($corps, "\xEF\xBB\xBF"))));
    expect(str_starts_with($corps, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($lignes)->toHaveCount(3)
        ->and(str_getcsv($lignes[0]))->toBe(['Identifiant', 'Portée', 'Adresse', 'Adresse masquée', 'Motif', 'Source', 'Inscrite le'])
        ->and($corps)->toContain('a@zz-r05.example.invalid')->and($corps)->toContain('b@zz-r05.example.invalid')
        ->and($corps)->not->toContain('zoe@')->and($corps)->not->toContain('zz-autre')
        ->and($corps)->not->toContain(hash('sha256', 'a@zz-r05.example.invalid'));
});

test('injection CSV : toute cellule commençant par = + - @ (ou tabulation) est neutralisée', function () {
    foreach (['=cmd@zz-r05.example.invalid', '+cmd@zz-r05.example.invalid', '-cmd@zz-r05.example.invalid', '@cmd@zz-r05.example.invalid'] as $a) {
        r05bOpposer($this->espace, $a);
    }
    $corps = r05bCorps($this->actingAs($this->proprio)->get(R05B_URI)->assertOk());
    $lignes = array_values(array_filter(preg_split('/\r?\n/', ltrim($corps, "\xEF\xBB\xBF"))));
    expect($lignes)->toHaveCount(5);
    foreach (array_slice($lignes, 1) as $ligne) {
        foreach (str_getcsv($ligne) as $cellule) {
            expect($cellule === '' || ! in_array($cellule[0], ['=', '+', '-', '@', "\t", "\r"], true))->toBeTrue("cellule non neutralisée : $cellule");
        }
    }
    expect($corps)->toContain("'=cmd@zz-r05")->and($corps)->toContain("'+cmd@zz-r05");
});

test('journal : qui, quand, combien de lignes ; jamais d’adresse ; une seule ligne', function () {
    r05bOpposer($this->espace, 'a@zz-r05.example.invalid');
    r05bOpposer($this->espace, 'b@zz-r05.example.invalid');
    r05bCorps($this->actingAs($this->proprio)->get(R05B_URI)->assertOk());

    $lignes = DB::table('audit_logs')->where('path', 'like', '%opposition/export%')->get();
    expect($lignes)->toHaveCount(1);
    $l = $lignes->first();
    expect($l->user_id)->toBe($this->proprio->id)->and($l->workspace_id)->toBe($this->espace->id)
        ->and($l->path)->toContain('lignes=2')->and($l->created_at)->not->toBeNull();
    $tout = DB::table('audit_logs')->get()->map(fn ($x) => json_encode($x))->implode('|');
    expect($tout)->not->toContain('zz-r05')->and($tout)->not->toContain('a@');
});

test('limite : 2 exports par heure, le troisième répond 429', function () {
    r05bOpposer($this->espace, 'a@zz-r05.example.invalid');
    for ($i = 0; $i < 2; $i++) {
        r05bCorps($this->actingAs($this->proprio)->get(R05B_URI)->assertOk());
    }
    $this->actingAs($this->proprio)->get(R05B_URI)->assertStatus(429);
});

test('aucune écriture ni suppression : le registre est identique après l’export, route en GET seulement', function () {
    r05bOpposer($this->espace, 'a@zz-r05.example.invalid');
    $avant = DB::table('envoi_oppositions')->count();
    $drapeaux = DB::table('envoi_drapeaux')->count();
    r05bCorps($this->actingAs($this->proprio)->get(R05B_URI)->assertOk());
    expect(DB::table('envoi_oppositions')->count())->toBe($avant)->and(DB::table('envoi_drapeaux')->count())->toBe($drapeaux)->and($this->resolutions)->toBe(0);
    $this->actingAs($this->proprio)->deleteJson(R05B_URI)->assertStatus(405);
    $this->actingAs($this->proprio)->postJson(R05B_URI)->assertStatus(405);
});

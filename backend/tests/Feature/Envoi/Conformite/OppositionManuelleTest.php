<?php

namespace Tests\Feature\Envoi\Conformite;

use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise;
use App\Socle\Espace\FonctionsEspace;
use App\Support\EligibiliteCampagne;
use App\Support\ListeSuppression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;

/**
 * R13 — POST /envoi/opposition (registre d'opposition MANUEL : adresse, organisation, domaine). Propriétaire seul.
 * Données fictives (.test, .example.invalid).
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const R13_URI = '/api/v1/envoi/opposition';

beforeEach(function () {
    $this->espace = $this->espace(true);
    $this->proprio = $this->compte($this->espace, 'owner');
});

/** Espace en règle pour écrire à $adresse : seule l'opposition manuelle peut alors refuser. */
function r13Nominal(object $t, string $ws, string $adresse): DemandeEnvoi
{
    $now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($ws, 'federations', true);
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($adresse),
        'verdict' => 'valide', 'verifie_le' => $now->modify('-5 days')->format('c'), 'source' => 'test',
    ]);
    $d = $now->format('c');
    DB::table('envoi_domaines')->updateOrInsert(['workspace_id' => $ws, 'nom' => 'zz-envoi.example.invalid'], [
        'achete_le' => $now->modify('-30 days')->format('Y-m-d'), 'dmarc_verifie_le' => $d, 'spf_verifie_le' => $d, 'dkim_verifie_le' => $d,
    ]);

    return new DemandeEnvoi($ws, $adresse, [['verification' => 'valide']], 'FR', '123456782', 'zz-envoi.example.invalid',
        'ZZ Expediteur SAS', $now->modify('-5 days'), null, 'federations', true);
}

function r13Maintenant(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
}

test('sans session 401 ; sans produit envoi 404 ; 403 pour admin, operator et viewer (propriétaire seul)', function () {
    $corps = ['portee' => 'domaine', 'valeur' => 'zz-client.example.invalid', 'motif' => 'Demande écrite reçue'];
    $this->postJson(R13_URI, $corps)->assertUnauthorized();
    $this->actingAs($this->compte($this->espace(false), 'owner'))->postJson(R13_URI, $corps)->assertNotFound();
    foreach (['admin', 'operator', 'viewer'] as $role) {
        $this->actingAs($this->compte($this->espace, $role))->postJson(R13_URI, $corps)->assertForbidden();
    }
    expect(DB::table('envoi_oppositions_registre')->count())->toBe(0);
});

test('entrées refusées en 422 : portée inconnue, motif vide/trop long/avec adresse, valeur mal formée', function (array $corps) {
    $this->actingAs($this->proprio)->postJson(R13_URI, $corps)->assertUnprocessable();
    expect(DB::table('envoi_oppositions_registre')->count())->toBe(0);
})->with([
    'portée inconnue' => [['portee' => 'telephone', 'valeur' => '0102030405', 'motif' => 'x']],
    'motif vide' => [['portee' => 'domaine', 'valeur' => 'zz-a.example.invalid', 'motif' => '']],
    'motif trop long' => [['portee' => 'domaine', 'valeur' => 'zz-a.example.invalid', 'motif' => str_repeat('a', 501)]],
    'motif avec adresse' => [['portee' => 'domaine', 'valeur' => 'zz-a.example.invalid', 'motif' => 'écrit par jean@zz-a.example.invalid']],
    'adresse mal formée' => [['portee' => 'adresse', 'valeur' => 'pas-une-adresse', 'motif' => 'x']],
    'domaine mal formé' => [['portee' => 'domaine', 'valeur' => 'pas un domaine', 'motif' => 'x']],
    'domaine sans point' => [['portee' => 'domaine', 'valeur' => 'localhost', 'motif' => 'x']],
    'organisation pas un SIREN' => [['portee' => 'organisation', 'valeur' => 'ABC', 'motif' => 'x']],
    'organisation SIREN à clé fausse' => [['portee' => 'organisation', 'valeur' => '123456783', 'motif' => 'x']],
]);

test('domaine de messagerie grand public refusé en portée domaine (422 expliqué), sous-domaines compris', function (string $domaine) {
    $r = $this->actingAs($this->proprio)->postJson(R13_URI, ['portee' => 'domaine', 'valeur' => $domaine, 'motif' => 'Demande reçue'])->assertUnprocessable();
    expect($r->json('message'))->toContain('grand public')
        ->and(DB::table('envoi_oppositions_registre')->count())->toBe(0);
})->with(['gmail.com', 'GMAIL.COM', 'mail.yahoo.com', 'orange.fr']);

test('domaine inscrit : EnvoiAutorise refuse toute adresse du domaine et de ses sous-domaines, pas les autres ni un autre espace', function () {
    $autre = $this->espace(true);
    $this->actingAs($this->proprio)->postJson(R13_URI, ['portee' => 'domaine', 'valeur' => 'ZZ-Client.example.invalid', 'motif' => 'Demande écrite reçue'])
        ->assertCreated();

    foreach (['a@zz-client.example.invalid', 'b@mail.zz-client.example.invalid'] as $adresse) {
        $d = r13Nominal($this, $this->espace->id, $adresse);
        expect(EnvoiAutorise::verifier($d, r13Maintenant())->refus)->toBe(EnvoiAutorise::OPPOSITION_REGISTRE);
    }
    expect(EnvoiAutorise::verifier(r13Nominal($this, $this->espace->id, 'c@zz-voisin.example.invalid'), r13Maintenant())->refus)->toBeNull()
        ->and(EnvoiAutorise::verifier(r13Nominal($this, $this->espace->id, 'd@xzz-client.example.invalid'), r13Maintenant())->refus)->toBeNull()
        ->and(EnvoiAutorise::verifier(r13Nominal($this, $autre->id, 'a@zz-client.example.invalid'), r13Maintenant())->refus)->toBeNull();
});

test('organisation inscrite (SIREN) : EnvoiAutorise refuse toute adresse de cette organisation', function () {
    $this->actingAs($this->proprio)->postJson(R13_URI, ['portee' => 'organisation', 'valeur' => '123 456 782', 'motif' => 'Demande de la direction'])->assertCreated();
    $d = r13Nominal($this, $this->espace->id, 'x@zz-client.example.invalid');
    expect(EnvoiAutorise::verifier($d, r13Maintenant())->refus)->toBe(EnvoiAutorise::OPPOSITION_REGISTRE);
});

test('adresse inscrite : empreinte + chiffrée (jamais en clair), écrite aussi dans email_suppressions, réponse masquée', function () {
    $r = $this->actingAs($this->proprio)->postJson(R13_URI, ['portee' => 'adresse', 'valeur' => ' Jean.Test@ZZ-Client.example.invalid ', 'motif' => 'Courrier reçu'])->assertCreated();
    $ligne = DB::table('envoi_oppositions_registre')->first();
    expect($ligne->portee)->toBe('adresse')->and($ligne->source)->toBe('manuel')
        ->and($ligne->valeur)->toBeNull()
        ->and($ligne->empreinte)->toBe(ListeSuppression::empreinte('jean.test@zz-client.example.invalid'))
        ->and($ligne->valeur_chiffree)->not->toBeNull()
        ->and(json_encode($ligne))->not->toContain('zz-client')
        ->and($r->getContent())->not->toContain('jean.test@zz-client')
        ->and(ContratOpenApiEnvoi::ecarts('commun.yaml', 'Opposition', $r->json()))->toBe([])
        ->and(DB::table('email_suppressions')->where('email_hash', ListeSuppression::empreinte('jean.test@zz-client.example.invalid'))->where('reason', 'manual')->count())->toBe(1)
        ->and(EligibiliteCampagne::peutRecevoir('jean.test@zz-client.example.invalid', 'business'))->toBeFalse();
});

test('double POST identique : 201 puis 200, une seule ligne, un seul effet', function () {
    $corps = ['portee' => 'domaine', 'valeur' => 'zz-client.example.invalid', 'motif' => 'Demande écrite reçue'];
    $premier = $this->actingAs($this->proprio)->postJson(R13_URI, $corps)->assertCreated();
    $second = $this->actingAs($this->proprio)->postJson(R13_URI, ['valeur' => 'ZZ-CLIENT.example.invalid', 'motif' => 'Autre texte'] + $corps)->assertOk();
    expect($second->json('id'))->toBe($premier->json('id'))
        ->and($second->json('motif'))->toBe('Demande écrite reçue')
        ->and(ContratOpenApiEnvoi::ecarts('commun.yaml', 'Opposition', $second->json()))->toBe([])
        ->and(DB::table('envoi_oppositions_registre')->count())->toBe(1);
});

test('la ligne ne dépend que de l’espace : même domaine dans un autre espace = ligne distincte', function () {
    $corps = ['portee' => 'domaine', 'valeur' => 'zz-client.example.invalid', 'motif' => 'Demande reçue'];
    $this->actingAs($this->proprio)->postJson(R13_URI, $corps)->assertCreated();
    $autre = $this->espace(true);
    $this->actingAs($this->compte($autre, 'owner'))->postJson(R13_URI, $corps)->assertCreated();
    expect(DB::table('envoi_oppositions_registre')->count())->toBe(2);
});

test('journal d’audit : un geste, sans la valeur saisie', function () {
    $avant = DB::table('audit_logs')->count();
    $this->actingAs($this->proprio)->postJson(R13_URI, ['portee' => 'adresse', 'valeur' => 'secret@zz-client.example.invalid', 'motif' => 'Courrier reçu'])->assertCreated();
    $lignes = DB::table('audit_logs')->orderByDesc('id')->limit(DB::table('audit_logs')->count() - $avant)->get();
    expect($lignes->count())->toBe(1)->and(json_encode($lignes))->not->toContain('secret@')->and(json_encode($lignes))->toContain('portee=adresse');
});

test('registre en ajout seul : UPDATE, DELETE et TRUNCATE refusés ; aucune résolution de ServeurEnvoi', function () {
    $this->actingAs($this->proprio)->postJson(R13_URI, ['portee' => 'domaine', 'valeur' => 'zz-client.example.invalid', 'motif' => 'Demande reçue'])->assertCreated();
    foreach (['UPDATE envoi_oppositions_registre SET motif = \'x\'', 'DELETE FROM envoi_oppositions_registre', 'TRUNCATE envoi_oppositions_registre'] as $sql) {
        $refus = false;
        DB::beginTransaction();
        try {
            DB::statement($sql);
        } catch (\Throwable) {
            $refus = true;
        }
        DB::rollBack();
        expect($refus)->toBeTrue($sql);
    }
    expect($this->resolutions)->toBe(0);
});

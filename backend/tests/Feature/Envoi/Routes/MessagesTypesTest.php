<?php

namespace Tests\Feature\Envoi\Routes;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\Support\ContratOpenApiEnvoi;

/** Aide : rend publics les assistants protégés de la base (espace, compte) pour le jeu d'essai. */
abstract class BaseMessagesTypes extends BaseRoutesEnvoi
{
    /** @return array{0: User, 1: User, 2: string} propriétaire, administrateur, id d'un message créé */
    public function jeu(): array
    {
        $e = $this->espace(true);
        $owner = $this->compte($e, 'owner');
        $id = $this->actingAs($owner)->postJson(MSG, msgSaisie())->assertCreated()->json('id');

        return [$owner, $this->compte($e, 'admin'), $id];
    }

    public function utilisateur(string $espaceId, string $role): User
    {
        return $this->compte(Workspace::findOrFail($espaceId), $role);
    }

    public function autreOwner(): User
    {
        return $this->compte($this->espace(true), 'owner');
    }
}

uses(BaseMessagesTypes::class)->group('envoi');

const MSG = '/api/v1/envoi/messages';

function msgSaisie(array $plus = []): array
{
    return $plus + ['nom' => 'Premier contact fictif', 'objet' => 'Bonjour {{prénom|vous}}', 'corps' => 'Texte simple pour {{entreprise|votre société}}.', 'bloc_information' => true];
}

test('POST crée un modèle brouillon version 1, conforme au contrat', function () {
    [$owner, , $id] = $this->jeu();
    $r = $this->actingAs($owner)->getJson(MSG . "/{$id}")->assertOk()->json();
    expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'MessageTypeEtat', $r))->toBe([])
        ->and($r['statut'])->toBe('brouillon')->and($r['version_courante'])->toBe(1)->and($r['version_validee'])->toBeNull();
});

test('PUT crée une nouvelle version, sans jamais modifier l’ancienne', function () {
    [$owner, , $id] = $this->jeu();
    $this->actingAs($owner)->putJson(MSG . "/{$id}", msgSaisie(['objet' => 'Objet deux']))->assertOk()->assertJsonPath('version_courante', 2);
    $v = DB::table('envoi_modele_versions')->where('modele_id', $id)->orderBy('numero')->pluck('objet', 'numero')->all();
    expect($v)->toBe([1 => 'Bonjour {{prénom|vous}}', 2 => 'Objet deux']);
});

test('PUT avec If-Match : version de base périmée → 409 version_perimee, une seule version ajoutée', function () {
    [$owner, , $id] = $this->jeu();
    $this->actingAs($owner)->withHeaders(['If-Match' => '"1"'])->putJson(MSG . "/{$id}", msgSaisie(['objet' => 'Objet deux']))->assertOk()->assertJsonPath('version_courante', 2);
    $this->actingAs($owner)->withHeaders(['If-Match' => '"1"'])->putJson(MSG . "/{$id}", msgSaisie(['objet' => 'Objet trois']))
        ->assertStatus(409)->assertJsonPath('error', 'version_perimee');
    expect(DB::table('envoi_modele_versions')->where('modele_id', $id)->count())->toBe(2);
    $this->withoutHeader('If-Match');
    // Corps : champ `version_base` ; sans en-tête ni champ, comportement inchangé (compatibilité).
    $this->actingAs($owner)->putJson(MSG . "/{$id}", msgSaisie(['objet' => 'Objet trois', 'version_base' => 1]))->assertStatus(409)->assertJsonPath('error', 'version_perimee');
    $this->actingAs($owner)->putJson(MSG . "/{$id}", msgSaisie(['objet' => 'Objet trois', 'version_base' => 2]))->assertOk()->assertJsonPath('version_courante', 3);
    $this->actingAs($owner)->putJson(MSG . "/{$id}", msgSaisie(['objet' => 'Objet quatre']))->assertOk()->assertJsonPath('version_courante', 4);
});

test('restaurer recopie l’ancienne version dans une nouvelle', function () {
    [$owner, , $id] = $this->jeu();
    $this->actingAs($owner)->putJson(MSG . "/{$id}", msgSaisie(['objet' => 'Objet deux']))->assertOk();
    $this->actingAs($owner)->postJson(MSG . "/{$id}/versions/1/restaurer")->assertOk()->assertJsonPath('version_courante', 3);
    expect(DB::table('envoi_modele_versions')->where('modele_id', $id)->where('numero', 3)->value('objet'))->toBe('Bonjour {{prénom|vous}}');
    $this->actingAs($owner)->postJson(MSG . "/{$id}/versions/9/restaurer")->assertNotFound();
});

test('une version validée reste immuable : modifier le modèle crée une version et efface la validation, jamais la version', function () {
    [$owner, , $id] = $this->jeu();
    $this->actingAs($owner)->postJson(MSG . "/{$id}/versions/1/valider")->assertOk()->assertJsonPath('statut', 'valide')->assertJsonPath('version_validee', 1);
    $this->actingAs($owner)->putJson(MSG . "/{$id}", msgSaisie(['corps' => 'Autre texte.']))->assertOk()->assertJsonPath('statut', 'brouillon')->assertJsonPath('version_validee', null);
    expect(DB::table('envoi_modele_versions')->where('modele_id', $id)->where('numero', 1)->value('corps'))->toBe('Texte simple pour {{entreprise|votre société}}.');
});

test('valider une version qui n’est pas la dernière : 422 ; HTML dangereux : 422 à la création et à la validation', function () {
    [$owner, , $id] = $this->jeu();
    $this->actingAs($owner)->putJson(MSG . "/{$id}", msgSaisie(['objet' => 'Deux']))->assertOk();
    $this->actingAs($owner)->postJson(MSG . "/{$id}/versions/1/valider")->assertStatus(422);
    $this->actingAs($owner)->postJson(MSG, msgSaisie(['corps' => '<script>alert(1)</script>']))->assertStatus(422);
    $this->actingAs($owner)->postJson(MSG, msgSaisie(['corps' => '<img src="https://t.example.invalid/p.gif">']))->assertStatus(422);
});

test('archivage : jamais de suppression ; désarchiver est refusé (409) ; aucune route DELETE', function () {
    [$owner, , $id] = $this->jeu();
    $this->actingAs($owner)->postJson(MSG . "/{$id}/archivage", ['archive' => true])->assertOk()->assertJsonPath('statut', 'archive');
    $this->actingAs($owner)->postJson(MSG . "/{$id}/archivage", ['archive' => false])->assertStatus(409);
    $this->actingAs($owner)->putJson(MSG . "/{$id}", msgSaisie())->assertStatus(409);
    $this->actingAs($owner)->deleteJson(MSG . "/{$id}")->assertStatus(405);
    expect(DB::table('envoi_modeles')->where('id', $id)->exists())->toBeTrue();
    $liste = $this->actingAs($owner)->getJson(MSG)->assertOk()->json();
    expect(collect($liste['data'])->pluck('id'))->not->toContain($id)->and($liste)->toHaveKey('blocs');
    expect(collect($this->actingAs($owner)->getJson(MSG . '?archives=1')->json('data'))->pluck('id'))->toContain($id);
});

test('permissions : lecture owner/admin, écriture owner seul, opérateur et lecteur refusés partout', function () {
    [$owner, $admin, $id] = $this->jeu();
    $e = $owner->current_workspace_id;
    $this->actingAs($admin)->getJson(MSG)->assertOk();
    $this->actingAs($admin)->postJson(MSG, msgSaisie())->assertForbidden();
    $this->actingAs($admin)->putJson(MSG . "/{$id}", msgSaisie())->assertForbidden();
    $this->actingAs($admin)->postJson(MSG . "/{$id}/versions/1/valider")->assertForbidden();
    foreach (['operator', 'viewer'] as $role) {
        $u = $this->utilisateur($e, $role);
        $this->actingAs($u)->getJson(MSG)->assertForbidden();
        $this->actingAs($u)->postJson(MSG . '/apercu', ['objet' => 'a', 'corps' => 'b'])->assertForbidden();
        $this->actingAs($u)->postJson(MSG, msgSaisie())->assertForbidden();
    }
});

test('étanchéité : un autre espace ne voit ni ne touche le modèle (404)', function () {
    [, , $id] = $this->jeu();
    $autre = $this->autreOwner();
    $this->actingAs($autre)->getJson(MSG . "/{$id}")->assertNotFound();
    $this->actingAs($autre)->putJson(MSG . "/{$id}", msgSaisie())->assertNotFound();
    $this->actingAs($autre)->postJson(MSG . "/{$id}/archivage", ['archive' => true])->assertNotFound();
    expect($this->actingAs($autre)->getJson(MSG)->json('data'))->toBe([]);
});

test('aperçu : rend le message sans rien écrire ni toucher ServeurEnvoi', function () {
    [, $admin] = $this->jeu();
    $avant = [DB::table('envoi_modeles')->count(), DB::table('envoi_modele_versions')->count()];
    $r = $this->actingAs($admin)->postJson(MSG . '/apercu', ['objet' => 'Bonjour {{prénom|vous}}', 'corps' => 'Chez {{entreprise|votre société}}'])->assertOk()->json();
    expect($r['objet'])->toBe('Bonjour vous')->and($r['corps'])->toContain('votre société')->and(ContratOpenApiEnvoi::ecarts('creation.yaml', 'SaisieMessage', msgSaisie()))->toBe([])
        ->and([DB::table('envoi_modeles')->count(), DB::table('envoi_modele_versions')->count()])->toBe($avant)
        ->and($this->resolutions)->toBe(0);
});

test('aperçu : variable sans repli ou HTML dangereux refusés (422)', function () {
    [, $admin] = $this->jeu();
    $this->actingAs($admin)->postJson(MSG . '/apercu', ['objet' => 'a', 'corps' => 'Bonjour {{prénom}}'])->assertStatus(422);
    $this->actingAs($admin)->postJson(MSG . '/apercu', ['objet' => 'a', 'corps' => '<iframe src="x"></iframe>'])->assertStatus(422);
});

test('contrôler : liste les contrôles du contrat, bloque ce qui est interdit, sans écrire', function () {
    [, $admin] = $this->jeu();
    $r = $this->actingAs($admin)->postJson(MSG . '/controler', msgSaisie(['corps' => 'Voir https://bit.ly/x et {{prénom}}']))->assertOk()->json();
    expect(collect($r['controles'])->pluck('code')->all())->toBe(['mots_interdits', 'liens', 'bloc_information', 'texte_simple', 'valeurs_repli'])
        ->and($r['bloquants'])->toBeGreaterThan(0);
    foreach ($r['controles'] as $c) {
        expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'ControleContenu', $c))->toBe([]);
    }
    $ok = $this->actingAs($admin)->postJson(MSG . '/controler', msgSaisie())->assertOk()->json();
    expect($ok['bloquants'])->toBe(0);
});

test('aucune mesure d’ouverture : image, pixel ou lien traqueur refusés à la création et à l’aperçu', function () {
    [$owner] = $this->jeu();
    foreach (['<img src="https://a.example.invalid/p.gif" width="1" height="1">', 'https://click.example.invalid/r?u=1'] as $corps) {
        $this->actingAs($owner)->postJson(MSG, msgSaisie(['corps' => $corps]))->assertStatus(422);
        $this->actingAs($owner)->postJson(MSG . '/apercu', ['objet' => 'a', 'corps' => $corps])->assertStatus(422);
    }
});

test('test : 409 envoi_ferme_palier_0, sans aucun effet ; suggestion : 501 sans appel', function () {
    [$owner, $admin] = $this->jeu();
    $avant = [DB::table('envoi_modeles')->count(), DB::table('envoi_modele_versions')->count(), DB::table('envoi_messages_envoyes')->count()];
    foreach ([$owner, $admin] as $u) {
        $this->actingAs($u)->postJson(MSG . '/test', ['objet' => 'a', 'corps' => 'b', 'a_moi' => true])
            ->assertStatus(409)->assertJsonPath('error', 'envoi_ferme_palier_0')->assertJsonStructure(['message']);
    }
    $this->actingAs($admin)->postJson(MSG . '/suggestion', [])->assertStatus(501)->assertJsonPath('error', 'non_disponible');
    expect([DB::table('envoi_modeles')->count(), DB::table('envoi_modele_versions')->count(), DB::table('envoi_messages_envoyes')->count()])->toBe($avant)
        ->and($this->resolutions)->toBe(0);
});

test('contexte et champs de fusion', function () {
    [, $admin] = $this->jeu();
    $c = $this->actingAs($admin)->getJson('/api/v1/envoi/creation/contexte')->assertOk()->json();
    expect($c)->toHaveKeys(['maintenant', 'fuseau']);
    $b = $this->actingAs($admin)->getJson('/api/v1/envoi/messages')->json('blocs');
    expect($b)->toBeArray();
    $r = $this->actingAs($admin)->postJson('/api/v1/envoi/creation/champs', ['cible' => ['type' => 'liste', 'reference' => 'x'],
        'sequence' => [['etape' => 1, 'message_id' => $this->actingAs($admin)->getJson(MSG)->json('data.0.id'), 'delai_jours' => 0]], 'repli' => ['entreprise' => 'votre société']])->assertOk()->json();
    expect(collect($r['champs'])->pluck('nom')->sort()->values()->all())->toBe(['entreprise', 'prénom']);
    foreach ($r['champs'] as $ch) {
        expect(ContratOpenApiEnvoi::ecarts('creation.yaml', 'ChampUtilise', $ch))->toBe([]);
    }
});

test('écriture : bloc d’information retiré ou objet B demandé = 422 (non pris en charge), corps trop long = 422', function () {
    [$owner] = $this->jeu();
    $this->actingAs($owner)->postJson(MSG, msgSaisie(['bloc_information' => false]))->assertStatus(422)->assertJsonPath('error', 'validation');
    $this->actingAs($owner)->postJson(MSG, msgSaisie(['objet_b' => 'Autre objet']))->assertStatus(422);
    $this->actingAs($owner)->postJson(MSG, msgSaisie(['corps' => str_repeat('a', 20001)]))->assertStatus(422);
    expect(DB::table('envoi_modeles')->count())->toBe(1);
});

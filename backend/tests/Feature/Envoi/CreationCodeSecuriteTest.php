<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Campagnes\DepotCampagnes;
use App\Socle\Alertes\CourrielTransactionnel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;

/** R11, corrections d'audit : le code ne fuit pas dans les journaux, verrou d'espace, clé d'idempotence, 3 codes par 15 minutes. */
uses(BaseRoutesEnvoi::class)->group('envoi');

const CS_CLE = 'cle-de-test-r11-seulement-0123456789abcdef';
const CS = '/api/v1/envoi/brouillons';

beforeEach(function () {
    config(['envoi.cles_codes_campagne' => ['1' => CS_CLE], 'envoi.cle_codes_campagne_version' => 1]);
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
});

/** Brouillon prêt (cible + boîte) créé par les routes ; rend son identifiant. */
function csPret($t): string
{
    $id = $t->actingAs($t->owner)->postJson(CS, ['nom' => 'Campagne sécurité'])->assertCreated()->json('id');
    $ws = $t->ws->id;
    DB::statement('SET session_replication_role = replica');
    $audience = DB::table('email_audiences')->insertGetId(['workspace_id' => $ws, 'name' => 'Audience ' . Str::random(5), 'member_count' => 1]);
    DB::table('audience_members')->insert(['audience_id' => $audience, 'company_id' => 1, 'workspace_id' => $ws]);
    DB::statement('SET session_replication_role = origin');
    $boite = (string) Str::uuid();
    DB::table('envoi_boites')->insert(['id' => $boite, 'workspace_id' => $ws, 'adresse' => 'boite@exemple.test', 'hote' => 'smtp.exemple.test', 'port' => 587, 'identifiants_chiffres' => 'x', 'limite_jour' => 20]);
    $v = (int) DB::table('envoi_campagnes')->where('id', $id)->value('version');
    $v = (int) $t->putJson(CS . "/{$id}/etapes/1", ['cible' => ['type' => 'audience', 'reference' => (string) $audience, 'libelle' => 'Audience']], ['If-Match' => (string) $v])->assertOk()->json('version');
    $t->putJson(CS . "/{$id}/etapes/3", ['boites' => [$boite], 'plafond_jour' => 10], ['If-Match' => (string) $v])->assertOk();

    return $id;
}

/** Envoi réel du code (courriel simulé) avec en-tête d'idempotence facultatif ; rend le code lu dans le courriel. */
function csCode($t, string $id, ?string $cle = null): string
{
    Mail::fake();
    $t->actingAs($t->owner)->postJson(CS . "/{$id}/validation/code", [], $cle ? ['Idempotency-Key' => $cle] : [])->assertOk();
    preg_match('/\b(\d{6})\b/', Mail::sent(CourrielTransactionnel::class)->last()->corps, $m);

    return $m[1];
}

test('le code part par le mailer d’authentification, jamais par le mailer par défaut', function () {
    [$defaut, $auth] = ['array', 'auth-test'];
    config(['mail.default' => $defaut, 'mail.auth_mailer' => $auth, 'mail.mailers.auth-test' => ['transport' => 'array']]);
    $id = csPret($this);
    $this->actingAs($this->owner)->postJson(CS . "/{$id}/validation/code")->assertOk();
    $mails = app('mail.manager');
    expect($mails->mailer($auth)->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and($mails->mailer($defaut)->getSymfonyTransport()->messages())->toHaveCount(0);
});

test('transport `log` (même en secours) : 503, aucun code émis, rien dans aucun journal', function (array $mailer) {
    $fichier = tempnam(sys_get_temp_dir(), 'cs-log');
    config([
        'logging.channels.cs-test' => ['driver' => 'single', 'path' => $fichier, 'level' => 'debug'],
        'mail.mailers.log' => ['transport' => 'log', 'channel' => 'cs-test'],
        'mail.mailers.auth-test' => $mailer,
        'mail.auth_mailer' => 'auth-test',
    ]);
    $id = csPret($this);
    $avant = DB::table('envoi_campagne_codes')->count();
    $this->actingAs($this->owner)->postJson(CS . "/{$id}/validation/code")->assertStatus(503)->assertJsonPath('error', 'transport_courriel_non_sur');
    expect(DB::table('envoi_campagne_codes')->count())->toBe($avant)
        ->and(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('brouillon')
        ->and((string) file_get_contents($fichier))->not->toMatch('/\b\d{6}\b/');
    @unlink($fichier);
})->with([
    'log' => [['transport' => 'log', 'channel' => 'cs-test']],
    'failover contenant log' => [['transport' => 'failover', 'mailers' => ['array', 'log']]],
]);

test('transport `array` refusé hors environnement de test', function () {
    config(['mail.mailers.auth-test' => ['transport' => 'array'], 'mail.auth_mailer' => 'auth-test']);
    $id = csPret($this);
    $this->app['env'] = 'production';
    try {
        $this->actingAs($this->owner)->postJson(CS . "/{$id}/validation/code")->assertStatus(503)->assertJsonPath('error', 'transport_courriel_non_sur');
    } finally {
        $this->app['env'] = 'testing';
    }
});

test('Idempotency-Key réutilisée avec un autre corps : 409, jamais un faux succès, code non consommé', function () {
    $id = csPret($this);
    $code = csCode($this, $id, 'cle-code-0000001');
    $this->actingAs($this->owner)->postJson(CS . "/{$id}/valider", ['code' => $code], ['Idempotency-Key' => 'cle-valid-0000001'])->assertOk();
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/campagnes/{$id}/annuler-validation", [], ['Idempotency-Key' => 'cle-annul-0000001'])->assertOk();
    $autre = csCode($this, $id, 'cle-code-0000002');
    $r = $this->actingAs($this->owner)->postJson(CS . "/{$id}/valider", ['code' => $autre], ['Idempotency-Key' => 'cle-valid-0000001']);
    expect($r->status())->toBeIn([409, 422])
        ->and(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('a_valider')
        ->and(DB::table('envoi_campagne_codes')->where('campagne_id', $id)->whereNull('consomme_le')->count())->toBeGreaterThan(0);
    // une clé neuve avec le bon code passe : le refus ne bloque pas la campagne
    $this->actingAs($this->owner)->postJson(CS . "/{$id}/valider", ['code' => $autre], ['Idempotency-Key' => 'cle-valid-0000002'])->assertOk();
});

test('Idempotency-Key d’un code déjà utilisée après retour au brouillon : 409, pas de code émis', function () {
    $id = csPret($this);
    csCode($this, $id, 'cle-code-0000009');
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/campagnes/{$id}/annuler-validation", [], ['Idempotency-Key' => 'cle-annul-0000009'])->assertOk();
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('brouillon');
    $avant = DB::table('envoi_campagne_codes')->where('campagne_id', $id)->count();
    $this->actingAs($this->owner)->postJson(CS . "/{$id}/validation/code", [], ['Idempotency-Key' => 'cle-code-0000009'])->assertStatus(409);
    expect(DB::table('envoi_campagne_codes')->where('campagne_id', $id)->count())->toBe($avant)
        ->and(DB::table('envoi_campagnes')->where('id', $id)->value('statut'))->toBe('brouillon');
});

test('journal d’audit : ni code, ni adresse e-mail', function () {
    $id = csPret($this);
    $code = csCode($this, $id);
    $this->actingAs($this->owner)->postJson(CS . "/{$id}/valider", ['code' => $code])->assertOk();
    $lignes = json_encode(DB::table('business_events')->where('workspace_id', $this->ws->id)->where('action', 'like', 'envoi.brouillon.%')->get());
    expect($lignes)->not->toContain($code)->not->toContain($this->owner->email)->not->toContain('@');
});

/**
 * Campagne à valider créée par la connexion propriétaire (pour des processus réels) ; rend [espace, campagne, auteur, nettoyage].
 *
 * @return array{0: string, 1: string, 2: string, 3: \Closure}
 */
function csCampagneConcurrente(int $codesDeja): array
{
    $proprio = DB::connection('pgsql_owner');
    [$ws, $camp, $boite, $auteur] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), 'auteur-concurrent'];
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-cs-' . substr($ws, 0, 6), 'name' => 'Concurrence code', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $proprio->statement("SET session_replication_role = 'replica'");
    $audience = $proprio->table('email_audiences')->insertGetId(['workspace_id' => $ws, 'name' => 'Concurrence', 'member_count' => 1]);
    $proprio->table('audience_members')->insert(['audience_id' => $audience, 'company_id' => 1, 'workspace_id' => $ws]);
    $proprio->statement("SET session_replication_role = 'origin'");
    $proprio->table('envoi_boites')->insert(['id' => $boite, 'workspace_id' => $ws, 'adresse' => 'boite@exemple.test', 'hote' => 'smtp.exemple.test', 'port' => 587, 'identifiants_chiffres' => 'x']);
    $cible = ['type' => 'audience', 'reference' => (string) $audience, 'libelle' => 'x'];
    $proprio->table('envoi_campagnes')->insert(['id' => $camp, 'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode($cible), 'boites' => '{' . $boite . '}', 'nb_etapes' => 1,
        'saisie' => json_encode(['contenu' => ['cible' => $cible, 'plafond_jour' => 5], 'etapes_faites' => [1, 3]]), 'cree_par' => $auteur]);
    $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => 'a_valider', 'version' => 2]);
    for ($i = 0; $i < $codesDeja; $i++) {
        $proprio->table('envoi_campagne_codes')->insert(['workspace_id' => $ws, 'campagne_id' => $camp, 'empreinte' => DepotCampagnes::empreinteCode(sprintf('%06d', 100 + $i), 1), 'cle_version' => 1, 'expire_le' => now()->addMinutes(10)]);
    }
    putenv('ENVOI_CLES_CODES_CAMPAGNE=' . json_encode(['1' => CS_CLE]));
    putenv('MAIL_MAILER_AUTH=array');   // les sous-processus lisent `.env` (MAIL_MAILER_AUTH vide en CI) : l'environnement l'emporte

    return [$ws, $camp, $auteur, function () use ($proprio, $ws): void {
        putenv('ENVOI_CLES_CODES_CAMPAGNE');
        putenv('MAIL_MAILER_AUTH');
        $proprio->statement("SET session_replication_role = 'replica'");
        foreach (['envoi_campagnes_journal', 'envoi_campagne_codes', 'envoi_campagnes', 'envoi_boites', 'audience_members', 'email_audiences'] as $t) {
            $proprio->table($t)->where('workspace_id', $ws)->delete();
        }
        $proprio->table('workspaces')->where('id', $ws)->delete();
        $proprio->statement("SET session_replication_role = 'origin'");
    }];
}

function csProcessus(string $ws, string $auteur, string $camp, float $depart)
{
    $p = proc_open([PHP_BINARY, base_path('tests/Support/campagne-code-demande-concurrent.php'), $ws, $auteur, $camp, sprintf('%.4F', $depart)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);

    return [$p, $tubes];
}

test('trois demandes de code CONCURRENTES alors que deux codes existent : une seule passe (limite sous verrou)', function () {
    [$ws, $camp, $auteur, $nettoyer] = csCampagneConcurrente(2);
    try {
        $depart = microtime(true) + 4;
        $procs = array_map(fn () => csProcessus($ws, $auteur, $camp, $depart), [1, 2, 3, 4]);
        $reussites = 0;
        foreach ($procs as [$p, $tubes]) {
            $sortie = stream_get_contents($tubes[1]) . stream_get_contents($tubes[2]);
            $code = proc_close($p);
            expect($code)->toBeIn([0, 3], $sortie);
            $reussites += $code === 0 ? 1 : 0;
        }
        expect($reussites)->toBe(1)
            ->and(DB::connection('pgsql_owner')->table('envoi_campagne_codes')->where('campagne_id', $camp)->count())->toBe(3);
    } finally {
        $nettoyer();
    }
});

test('verrou de l’espace : une demande de code attend qu’une autre écriture de l’espace ait fini (deux connexions)', function () {
    [$ws, $camp, $auteur, $nettoyer] = csCampagneConcurrente(0);
    $proprio = DB::connection('pgsql_owner');
    $proprio->beginTransaction();
    try {
        $proprio->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [DepotCampagnes::cleVerrouEspace($ws)]);
        [$p, $tubes] = csProcessus($ws, $auteur, $camp, 0.0);
        usleep(2_500_000);
        $bloque = proc_get_status($p)['running'];
        $proprio->commit();
        $fin = microtime(true) + 30;
        while (proc_get_status($p)['running'] && microtime(true) < $fin) {
            usleep(100_000);
        }
        $sortie = stream_get_contents($tubes[1]) . stream_get_contents($tubes[2]);
        expect($bloque)->toBeTrue('la demande n’a pas attendu le verrou de l’espace')
            ->and(proc_close($p))->toBe(0, $sortie);
    } finally {
        if ($proprio->transactionLevel() > 0) {
            $proprio->rollBack();
        }
        $nettoyer();
    }
});

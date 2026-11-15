<?php

namespace Tests\Feature\Envoi;

use App\Envoi\Conformite\ImportOppositions;
use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Http\Middleware\AuditHashChainLogger;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;

/**
 * P1-LN-B — POST /envoi/opposition/import (D8) : simulation puis confirmation, corps JSON, propriétaire seul.
 * Données fictives (*.example.test). Le CSV n'est jamais écrit sur disque ni dans un journal.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const IMP_URI = '/api/v1/envoi/opposition/import';

beforeEach(function () {
    $this->espace = $this->espace(true);
    $this->proprio = $this->compte($this->espace, 'owner');
});

/** @return array<string,mixed> */
function impSimuler(object $t, string $csv, int $statut = 200): array
{
    return $t->actingAs($t->proprio)->postJson(IMP_URI, ['etape' => 'simulation', 'contenu' => $csv])->assertStatus($statut)->json();
}

function impConfirmer(object $t, string $csv, ?string $jeton): TestResponse
{
    return $t->actingAs($t->proprio)->postJson(IMP_URI, ['etape' => 'confirmation', 'contenu' => $csv, 'jeton' => $jeton]);
}

function impRegistre(object $t): int
{
    return DB::table('envoi_oppositions_registre')->where('workspace_id', $t->espace->id)->count();
}

function impEvenements(): int
{
    return DB::table('crm_outbound_events')->where('event_type', 'consent_optout')->count();
}

test('propriétaire seul ; 401 sans session ; 403 pour les autres rôles', function () {
    $corps = ['etape' => 'simulation', 'contenu' => "a@clients.example.test\n"];
    $this->postJson(IMP_URI, $corps)->assertUnauthorized();
    foreach (['admin', 'operator', 'viewer'] as $role) {
        $this->actingAs($this->compte($this->espace, $role))->postJson(IMP_URI, $corps)->assertForbidden();
    }
});

test('simulation : compte sans écrire, sans adresse dans la réponse, jeton rendu', function () {
    $csv = "alice@clients.example.test\nsociete.example.invalid\nbob@gmail.com\n=cmd@clients.example.test\n";
    $r = impSimuler($this, $csv);
    expect($r['etape'])->toBe('simulation')->and($r['valides'])->toBe(3)->and($r['deja_presentes'])->toBe(0)
        ->and($r['refusees'])->toHaveCount(1)->and($r['refusees'][0]['ligne'])->toBe(4)
        ->and($r['refusees'][0]['motif'])->toBe('caractère de tête refusé')->and($r['jeton'])->toBeString();
    $brut = json_encode($r);
    expect($brut)->not->toContain('alice')->not->toContain('@')->not->toContain('clients.example');
    expect(impRegistre($this))->toBe(0)->and(DB::table('envoi_oppositions_imports')->count())->toBe(0);
});

test('lignes refusées avec numéro et motif : = + - @ tabulation CR, domaine grand public', function () {
    $csv = "=a@x.example.test\n+b@x.example.test\n-c@x.example.test\n@d.example.test\n\te@x.example.test\n\rf@x.example.test\ngmail.com\nok@x.example.test\n";
    $r = impSimuler($this, $csv);
    expect(array_column($r['refusees'], 'ligne'))->toBe([1, 2, 3, 4, 5, 6, 7])->and($r['valides'])->toBe(1);
    expect($r['refusees'][6]['motif'])->toContain('grand public');
    foreach ($r['refusees'] as $l) {
        expect($l['motif'])->not->toContain('@')->not->toContain('x.example');
    }
});

test('5 001 lignes ou plus d’un Mo ⇒ 422', function () {
    $this->actingAs($this->proprio)->postJson(IMP_URI, ['etape' => 'simulation', 'contenu' => str_repeat("a@c.example.test\n", 5001)])->assertStatus(422);
    $this->actingAs($this->proprio)->postJson(IMP_URI, ['etape' => 'simulation', 'contenu' => str_repeat('x', 1048577)])->assertStatus(422);
    $this->actingAs($this->proprio)->postJson(IMP_URI, ['etape' => 'simulation', 'contenu' => str_repeat("a@c.example.test\n", 5000)])->assertOk();
});

test('multipart refusé : le contrôleur ne lit jamais de fichier', function () {
    $src = (string) file_get_contents(app_path('Http/Controllers/Api/EnvoiOppositionImportController.php'));
    expect($src)->not->toContain('->file(')->not->toContain('allFiles')->not->toContain('Storage::')->not->toContain('file_put_contents');
});

test('confirmation sans jeton, jeton inconnu, jeton d’un autre utilisateur ⇒ 409, rien d’écrit', function () {
    $csv = "a@clients.example.test\n";
    impConfirmer($this, $csv, null)->assertStatus(409);
    impConfirmer($this, $csv, (string) Str::uuid())->assertStatus(409);
    $jeton = impSimuler($this, $csv)['jeton'];
    $autre = $this->compte($this->espace, 'owner');
    $this->actingAs($autre)->postJson(IMP_URI, ['etape' => 'confirmation', 'contenu' => $csv, 'jeton' => $jeton])->assertStatus(409);
    expect(impRegistre($this))->toBe(0);
});

test('contenu différent (l’empreinte est normalisée : minuscules + rognage, via ListeSuppression::empreinte) ⇒ 409, rien d’écrit', function () {
    $jeton = impSimuler($this, "a@clients.example.test\n")['jeton'];
    impConfirmer($this, "b@clients.example.test\n", $jeton)->assertStatus(409);
    expect(impRegistre($this))->toBe(0);
});

test('fichier commençant par des lignes vides : la ligne refusée garde son vrai numéro (pas de rognage du champ contenu)', function () {
    $r = impSimuler($this, "\n\n=a@x.example.test\nok@x.example.test\n");
    expect($r['refusees'])->toHaveCount(1)->and($r['refusees'][0]['ligne'])->toBe(3)->and($r['valides'])->toBe(1);
});

test('tabulation en tête de la ligne 1 ⇒ refusée (jamais rognée en silence)', function () {
    $r = impSimuler($this, "\te@x.example.test\nok@x.example.test\n");
    expect(array_column($r['refusees'], 'ligne'))->toBe([1])->and($r['valides'])->toBe(1);
});

test('1 048 577 octets finissant par un saut de ligne ⇒ 422 (le plafond ne se contourne pas d’un octet)', function () {
    $contenu = str_repeat('x', 1048576) . "\n";
    expect(strlen($contenu))->toBe(1048577);
    $this->actingAs($this->proprio)->postJson(IMP_URI, ['etape' => 'simulation', 'contenu' => $contenu])->assertStatus(422);
});

test('valeur entre guillemets commençant par = ⇒ refusée', function () {
    $r = impSimuler($this, "\"=a@x.example.test\"\nok@x.example.test\n");
    expect(array_column($r['refusees'], 'ligne'))->toBe([1])->and($r['valides'])->toBe(1);
});

test('refus avant le contrôleur (403) : payload_hash ne dépend ni du contenu ni du jeton (pas d’oracle par dictionnaire)', function () {
    $csv = "seule@clients.example.test\n";
    $this->actingAs($this->compte($this->espace, 'admin'))->postJson(IMP_URI, ['etape' => 'simulation', 'contenu' => $csv])->assertForbidden();
    $l = DB::table('audit_logs')->where('path', 'like', '%opposition/import%')->first();
    expect($l)->not->toBeNull();
    expect($l->payload_hash)->not->toBe(AuditHashChainLogger::empreinteDuCorps(['etape' => 'simulation', 'contenu' => $csv]));
    expect($l->payload_hash)->toBe(AuditHashChainLogger::empreinteDuCorps(['etape' => 'simulation']));
});

test('422 : une ligne d’audit propre est écrite (statut, sans contenu ni adresse)', function () {
    $this->actingAs($this->proprio)->postJson(IMP_URI, ['etape' => 'simulation', 'contenu' => str_repeat('secretx', 200000)])->assertStatus(422);
    $this->actingAs($this->proprio)->postJson(IMP_URI, ['etape' => 'nimporte', 'contenu' => 'secretx'])->assertStatus(422);
    $lignes = DB::table('audit_logs')->where('path', 'like', '%opposition/import%')->get();
    expect($lignes)->toHaveCount(2);
    foreach ($lignes as $l) {
        expect($l->status)->toBe(422)->and(json_encode($l))->not->toContain('secret');
    }
});

test('jeton expiré (15 min) ⇒ 409', function () {
    $jeton = impSimuler($this, "a@clients.example.test\n")['jeton'];
    Cache::forget($jeton);
    impConfirmer($this, "a@clients.example.test\n", $jeton)->assertStatus(409);
});

test('confirmation : écrit registre, email_suppressions, opt_out, import_id, motif sans @ ; jeton à usage unique', function () {
    $csv = "alice@clients.example.test\nALICE@clients.example.test\nsociete.example.invalid\n";
    $jeton = impSimuler($this, $csv)['jeton'];
    $r = impConfirmer($this, $csv, $jeton)->assertOk()->json();
    expect($r['etape'])->toBe('confirmation')->and($r['valides'])->toBe(2)->and($r['deja_presentes'])->toBe(1)->and($r['import_id'])->toBeString();
    expect(json_encode($r))->not->toContain('alice');
    $lignes = DB::table('envoi_oppositions_registre')->where('workspace_id', $this->espace->id)->get();
    expect($lignes)->toHaveCount(2);
    foreach ($lignes as $l) {
        expect($l->import_id)->toBe($r['import_id'])->and($l->source)->toBe('manuel')->and($l->motif)->toBe('import ' . $r['import_id'])->and($l->motif)->not->toContain('@');
    }
    $emp = ListeSuppression::empreinte('alice@clients.example.test');
    expect(DB::table('email_suppressions')->where('email_hash', $emp)->exists())->toBeTrue()
        ->and(DB::table('opt_out')->where('email_hash', $emp)->exists())->toBeTrue();
    $imp = DB::table('envoi_oppositions_imports')->first();
    expect($imp->nb_adresses)->toBe(1)->and($imp->nb_domaines)->toBe(1);
    impConfirmer($this, $csv, $jeton)->assertStatus(409); // jeton consommé
    expect(impRegistre($this))->toBe(2);
});

test('déjà présentes : aucune ligne en double', function () {
    (new RegistreOppositionManuel)->inscrire($this->espace->id, 'domaine', 'societe.example.invalid', 'test', 'u');
    $csv = "societe.example.invalid\nnouveau@clients.example.test\n";
    $r = impSimuler($this, $csv);
    expect($r['valides'])->toBe(1)->and($r['deja_presentes'])->toBe(1);
    impConfirmer($this, $csv, $r['jeton'])->assertOk();
    expect(impRegistre($this))->toBe(2);
});

test('aucune ligne audit_logs ne porte l’empreinte du corps ; la ligne propre ne contient ni contenu, ni jeton, ni adresse', function () {
    $csv = "seule@clients.example.test\n";
    $corpsS = ['etape' => 'simulation', 'contenu' => $csv];
    $jeton = impSimuler($this, $csv)['jeton'];
    $corpsC = ['etape' => 'confirmation', 'contenu' => $csv, 'jeton' => $jeton];
    impConfirmer($this, $csv, $jeton)->assertOk();
    $interdits = [AuditHashChainLogger::empreinteDuCorps($corpsS), AuditHashChainLogger::empreinteDuCorps($corpsC)];
    $lignes = DB::table('audit_logs')->where('path', 'like', '%opposition/import%')->get();
    expect($lignes)->toHaveCount(2);
    foreach ($lignes as $l) {
        expect($interdits)->not->toContain($l->payload_hash);
        expect(json_encode($l))->not->toContain('seule')->not->toContain($jeton)->not->toContain('contenu')->not->toContain('@');
    }
});

test('le CSV n’est écrit ni sur disque ni dans un journal (aucun niveau, message ou contexte)', function () {
    $avant = [glob(sys_get_temp_dir() . '/*') ?: [], glob(storage_path('**/*')) ?: [], glob(storage_path('*/*/*')) ?: []];
    // Écoute réelle de TOUT message journalisé, quel que soit le niveau et le canal (Log::spy() ne contrôlait qu'un niveau).
    $journalises = [];
    Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$journalises) {
        $journalises[] = $m->level . ' ' . $m->message . ' ' . json_encode($m->context);
    });
    $csv = "secret-unique@clients.example.test\n";
    $jeton = impSimuler($this, $csv)['jeton'];
    impConfirmer($this, $csv, $jeton)->assertOk();
    $apres = [glob(sys_get_temp_dir() . '/*') ?: [], glob(storage_path('**/*')) ?: [], glob(storage_path('*/*/*')) ?: []];
    foreach ($avant as $i => $a) {
        expect(array_values(array_diff($apres[$i], $a)))->toBe([]);
    }
    foreach ($journalises as $ligne) {
        expect($ligne)->not->toContain('secret-unique');
    }
    foreach (glob(storage_path('logs/*.log')) ?: [] as $f) {
        expect((string) file_get_contents($f))->not->toContain('secret-unique');
    }
    // le cache ne garde jamais le contenu : valeur = utilisateur, espace, empreinte
    expect(json_encode(Cache::get($jeton)))->not->toContain('secret-unique');
});

test('tout ou rien : une erreur en cours d’écriture annule tout, y compris la ligne d’import (le jeton reste utilisable)', function () {
    $csv = "a@clients.example.test\nb@clients.example.test\n";
    $jeton = impSimuler($this, $csv)['jeton'];
    DB::unprepared("CREATE OR REPLACE FUNCTION zz_echec() RETURNS trigger AS \$f\$ BEGIN IF NEW.source = 'registre_manuel' THEN RAISE EXCEPTION 'echec voulu'; END IF; RETURN NEW; END \$f\$ LANGUAGE plpgsql");
    DB::unprepared('CREATE TRIGGER zz_echec BEFORE INSERT ON email_suppressions FOR EACH ROW EXECUTE FUNCTION zz_echec()');
    $this->withoutExceptionHandling();
    $leve = false;
    try {
        impConfirmer($this, $csv, $jeton);
    } catch (\Throwable) {
        $leve = true;
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS zz_echec ON email_suppressions');
    }
    $emp = [ListeSuppression::empreinte('a@clients.example.test'), ListeSuppression::empreinte('b@clients.example.test')];
    expect(DB::table('opt_out')->whereIn('email_hash', $emp)->count())->toBe(0)->and(DB::table('email_suppressions')->whereIn('email_hash', $emp)->count())->toBe(0);
    expect(DB::table('audit_logs')->where('path', 'like', '%opposition/import%')->where('status', 500)->count())->toBe(1);
    expect($leve)->toBeTrue()->and(impRegistre($this))->toBe(0)->and(DB::table('envoi_oppositions_imports')->count())->toBe(0)->and(impEvenements())->toBe(0);
});

test('événements sortants : un par adresse nouvelle, aucun pour une adresse venue du site, émis après le COMMIT, sans verrou restant', function () {
    $venue = 'site@clients.example.test';
    DB::table('opt_out')->insert(['email' => null, 'email_hash' => ListeSuppression::empreinte($venue), 'scope' => 'business', 'source' => 'site-sync:x', 'created_at' => now()]);
    $csv = "{$venue}\nnouvelle@clients.example.test\nsociete.example.invalid\n";
    $jeton = impSimuler($this, $csv)['jeton'];
    $avant = impEvenements();
    impConfirmer($this, $csv, $jeton)->assertOk();
    expect(impEvenements() - $avant)->toBe(1);
    expect(DB::table('crm_outbound_events')->where('email_hash', ListeSuppression::empreinte($venue))->exists())->toBeFalse();
    expect(DB::table('crm_outbound_events')->where('email_hash', ListeSuppression::empreinte('nouvelle@clients.example.test'))->count())->toBe(1);
});

test('l’écriture de lot n’appelle jamais signalerSansBloquer', function () {
    $src = (string) file_get_contents(app_path('Envoi/Conformite/RegistreOppositionManuel.php'));
    preg_match('/function inscrireLot.*?\n    }\n/s', $src, $m);
    expect($m)->not->toBe([])->and($m[0])->not->toContain('signaler')->not->toContain('prevenirLeSite');
});

test('import de 5 000 lignes : 5 000 événements sortants, aucune erreur avalée', function () {
    $csv = '';
    for ($i = 0; $i < 5000; $i++) {
        $csv .= "p{$i}@clients.example.test\n";
    }
    $jeton = impSimuler($this, $csv)['jeton'];
    $avant = impEvenements();
    $r = impConfirmer($this, $csv, $jeton)->assertOk()->json();
    expect($r['valides'])->toBe(5000)->and(impEvenements() - $avant)->toBe(5000);
});

test('deux confirmations avec le même jeton : une connexion déjà passée ⇒ la seconde reçoit 409 (unicité en base)', function () {
    // Espace validé (auto-commit) par une AUTRE connexion, comme PlafondConcurrenceTest : la ligne d'import de la
    // première confirmation y est visible de toutes les connexions ; la seconde viole l'unicité (workspace, jeton).
    $owner = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $owner->table('workspaces')->insert(['id' => $ws, 'name' => 'Conc', 'slug' => 'conc-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
    $csv = "a@clients.example.test\n";
    $jeton = (string) Str::uuid();
    $owner->table('envoi_oppositions_imports')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $ws, 'auteur' => 'u1', 'empreinte_fichier' => hash('sha256', $csv), 'jeton_simulation' => $jeton,
    ]);
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    Cache::put($jeton, ['utilisateur' => 'u2', 'espace' => $ws, 'empreinte' => hash('sha256', $csv)], 900);
    $import = app(ImportOppositions::class);
    $statut = null;
    try {
        WorkspaceContext::run($ws, fn () => $import->confirmer($ws, 'u2', $csv, $jeton));
    } catch (HttpException $e) {
        $statut = $e->getStatusCode();
    }
    expect($statut)->toBe(409);
    expect($owner->table('envoi_oppositions_registre')->where('workspace_id', $ws)->count())->toBe(0);
});

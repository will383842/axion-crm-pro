<?php

/**
 * D26-001 (S1) — UN CRITERE MAL FORME N'ETAIT PAS REFUSE : IL ETAIT EFFACE.
 *
 * `AudienceBuilderService::applyCondition()` faisait `return;` sur un champ
 * hors liste blanche (ligne 280), sur un operateur hors liste blanche (283) et
 * sur un predicat inconstructible (295). `buildQuery()` ignorait de meme un
 * `all` / `any` / `not` qui n'etait pas un tableau, et toute cle de premier
 * niveau qu'il ne connaissait pas.
 *
 * La condition disparaissait donc de la requete, et il ne restait que
 * `where workspace_id = ?` : L'AUDIENCE DEVENAIT LE WORKSPACE ENTIER.
 *
 * C'est le pire mode de panne d'un ciblage. On ne recoit pas d'erreur, on
 * recoit un nombre — et l'ecran de previsualisation affiche ce nombre comme
 * s'il etait le resultat du ciblage demande. On croit viser 200 personnes, on
 * en vise 1 319 567. Il n'y a pas de rattrapage apres l'envoi.
 *
 * ── Ce que le depot avait ecrit AVANT ───────────────────────────────────────
 *
 * `AudienceBuilderServiceTest` portait un test nomme « skips invalid
 * fields/ops silently » qui ASSERTAIT ce comportement (`toBe(1)` sur une base
 * d'une fiche, alors que le critere demandait autre chose). Le defaut etait
 * donc garde par un test. Ce test est corrige dans le meme geste : un vert qui
 * garde un defaut vaut moins que pas de test du tout.
 *
 * ── Pourquoi ces gardes ne peuvent pas verdir a vide ────────────────────────
 *
 * Chaque test de refus est double d'une mesure du nombre AVANT refus : le
 * premier test compte ce que la requete rendrait si le critere etait efface
 * (TEMOIN DE VOLUME). Si la base de test etait vide, ce temoin rougirait et
 * l'ensemble ne pourrait pas passer par absence de donnee.
 */

use App\Models\AudienceMember;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ws = Workspace::create(['id' => Str::uuid()->toString(), 'name' => 'T', 'slug' => 'ws-' . uniqid()]);
    $this->service = new AudienceBuilderService;

    // Trois fiches, dont UNE SEULE dans le 75. Tout critere correctement
    // applique sur `department_code = 75` doit rendre 1 ; un critere EFFACE
    // rend 3, c'est-a-dire le workspace entier.
    $this->cible = fabriquerFiche($this->ws->id, ['department_code' => '75']);
    fabriquerFiche($this->ws->id, ['department_code' => '92']);
    fabriquerFiche($this->ws->id, ['department_code' => '69']);
});

function fabriquerFiche(string $wsId, array $attrs = []): Company
{
    return Company::create(array_merge([
        'workspace_id' => $wsId,
        'siren' => str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
    ], $attrs));
}

/**
 * Joue `preview()` et rend l'exception levee, ou `null` si aucune ne l'a ete.
 * On ne se contente pas de `toThrow` : on veut aussi pouvoir DIRE, quand rien
 * n'est leve, combien de fiches l'audience aurait vise.
 */
function refusOuCompte(AudienceBuilderService $service, string $wsId, array $criteria): array
{
    try {
        return ['refus' => null, 'companies' => $service->preview($wsId, $criteria)['companies']];
    } catch (Throwable $e) {
        return ['refus' => $e, 'companies' => null];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// TEMOINS
// ═══════════════════════════════════════════════════════════════════════════

test('TEMOIN de volume : un critere efface rend bien TOUT le workspace', function () {
    // Ce test ne mesure pas le correctif : il mesure l'ENJEU. Sans critere du
    // tout, la requete rend les 3 fiches. C'est le nombre que le defaut
    // servait quand un critere etait ecarte. S'il rendait 0, tous les tests de
    // refus qui suivent seraient des verts par absence de donnee.
    expect($this->service->preview($this->ws->id, [])['companies'])->toBe(3);
});

test('TEMOIN : un critere BIEN forme passe et filtre reellement', function () {
    // Une garde qui refuserait tout serait pire que le defaut : elle rendrait
    // le ciblage inutilisable. Le refus doit etre chirurgical.
    $criteria = ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]];

    expect($this->service->preview($this->ws->id, $criteria)['companies'])->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════
// LE DEFAUT — chaque forme d'effacement silencieux
// ═══════════════════════════════════════════════════════════════════════════

test('un CHAMP hors liste blanche est REFUSE, pas efface', function () {
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'all' => [['field' => 'DROP TABLE users', 'op' => 'eq', 'value' => 'x']],
    ]);

    expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
    // Le message doit NOMMER le champ fautif : un refus qu'on ne sait pas
    // diagnostiquer se contourne en effacant le critere a la main.
    $this->assertStringContainsString('DROP TABLE users', $resultat['refus']->getMessage());
});

test('un OPERATEUR hors liste blanche est REFUSE, pas efface', function () {
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'all' => [['field' => 'department_code', 'op' => 'INVALID', 'value' => '75']],
    ]);

    expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
    $this->assertStringContainsString('INVALID', $resultat['refus']->getMessage());
});

test('un champ ABSENT de la condition est REFUSE', function () {
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'all' => [['op' => 'eq', 'value' => '75']],
    ]);

    expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
});

test('« in » avec une valeur qui n est PAS une liste est REFUSE', function () {
    // Cas tres realiste : le front envoie « 75 » au lieu de [« 75 »]. Avant,
    // `buildPositive()` rendait null, la condition disparaissait, et le
    // ciblage « dans ces departements » devenait « tout le monde ».
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'all' => [['field' => 'department_code', 'op' => 'in', 'value' => '75']],
    ]);

    expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
});

test('« not_in » avec une valeur qui n est PAS une liste est REFUSE', function () {
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'all' => [['field' => 'region_code', 'op' => 'not_in', 'value' => '11']],
    ]);

    expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
});

test('« has_email » avec un operateur autre que « eq » est REFUSE', function () {
    // `has_email` n'admet que `eq`. Avec `in`, `buildPositive()` rendait null
    // -- et « ceux qui ont un e-mail » devenait « tout le monde », y compris
    // les fiches sans aucune adresse.
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'all' => [['field' => 'has_email', 'op' => 'in', 'value' => [true]]],
    ]);

    expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
    $this->assertStringContainsString('has_email', $resultat['refus']->getMessage());
});

test('une CLE de premier niveau inconnue est REFUSEE', function () {
    // `buildQuery()` ne lit que `all`, `any` et `not`. Une faute de frappe
    // (`alll`, `conditions`, `filters`) produisait une requete SANS AUCUN
    // critere -- et donc le workspace entier -- sans un mot.
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'alll' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']],
    ]);

    expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
    $this->assertStringContainsString('alll', $resultat['refus']->getMessage());
});

test('un bloc « all » qui n est pas une liste est REFUSE', function () {
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'all' => 'department_code=75',
    ]);

    expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
});

test('une condition qui n est pas un objet est REFUSEE', function () {
    // Avant, PHP levait un TypeError sur le parametre type `array $cond` :
    // bruyant, mais illisible, et attrape en `\Throwable` par le controleur
    // qui rendait alors « preview_failed » sans dire quoi.
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'all' => ['department_code=75'],
    ]);

    expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
});

test('le refus vaut aussi sous « any » et sous « not »', function () {
    // `not` est le combinateur d'EXCLUSION. Un critere d'exclusion efface,
    // c'est une liste noire qui n'exclut plus personne.
    foreach (['any', 'not'] as $bloc) {
        $resultat = refusOuCompte($this->service, $this->ws->id, [
            $bloc => [['field' => 'champ_invente', 'op' => 'eq', 'value' => 'x']],
        ]);

        expect($resultat['refus'])->toBeInstanceOf(InvalidArgumentException::class);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// REFRESH — le refus doit arriver AVANT toute destruction
// ═══════════════════════════════════════════════════════════════════════════

test('refresh REFUSE une audience aux criteres invalides SANS detruire ses membres', function () {
    // `refresh()` commence par supprimer tous les membres puis reinsere. Si le
    // refus arrivait apres la suppression, un critere mal forme VIDERAIT
    // l'audience -- on remplacerait une audience trop large par une audience
    // detruite. Le refus doit precede la transaction.
    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'ZZ criteres casses',
        'criteria' => ['all' => [['field' => 'champ_invente', 'op' => 'eq', 'value' => 'x']]],
    ]);
    AudienceMember::create([
        'audience_id' => $audience->id,
        'company_id' => $this->cible->id,
        'workspace_id' => $this->ws->id,
        'added_at' => now(),
    ]);

    $refus = null;
    try {
        $this->service->refresh($audience);
    } catch (Throwable $e) {
        $refus = $e;
    }

    expect($refus)->toBeInstanceOf(InvalidArgumentException::class);
    // Le membre d'avant est toujours la : rien n'a ete detruit.
    expect(AudienceMember::where('audience_id', $audience->id)->count())->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════
// CE QUI NE DOIT PAS CHANGER — les cas deja FERMES par construction
// ═══════════════════════════════════════════════════════════════════════════

test('« tags » avec une liste VIDE ne vise personne et ne leve PAS', function () {
    // Ce cas-la etait deja traite, et bien : `buildPositive()` rend `1 = 0`.
    // Une liste de tags videe est une audience vide, pas une erreur de forme --
    // et surtout PAS le workspace entier. On garde ce comportement intact.
    $resultat = refusOuCompte($this->service, $this->ws->id, [
        'all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => []]],
    ]);

    expect($resultat['refus'])->toBeNull();
    expect($resultat['companies'])->toBe(0);
});

test('l evaluateur EN MEMOIRE ne fait pas correspondre une audience aux criteres casses', function () {
    // `evaluateForCompany()` (chemin waterfall step12) est appele par fiche,
    // pendant l'enrichissement. Y lever tuerait l'enrichissement du workspace
    // entier pour une audience mal saisie. Il doit donc FERMER -- ne rattacher
    // personne -- la ou le SQL refuse. Les deux directions sont sures ; ce qui
    // ne l'etait pas, c'est que le SQL, lui, OUVRAIT.
    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'ZZ casse memoire',
        'criteria' => ['all' => [['field' => 'champ_invente', 'op' => 'eq', 'value' => 'x']]],
        'is_active' => true,
        'auto_refresh' => true,
    ]);

    expect($this->service->evaluateForCompany($this->cible))->not->toContain($audience->id);
});

test('EN MEMOIRE aussi, un « not » casse ne doit pas rattacher tout le monde', function () {
    // Decouvert en ecrivant la garde, non signale par l'audit : la version en
    // memoire ferme sur `all` et sur `any` (une condition fausse fait echouer
    // le bloc) mais OUVRE sur `not` -- `evalCondition()` repond faux, donc la
    // fiche n'est PAS exclue, donc elle est retenue. Une audience dont le seul
    // critere est une exclusion mal ecrite rattachait ainsi CHAQUE fiche
    // enrichie, en silence, par le chemin waterfall step12.
    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'ZZ not casse',
        'criteria' => ['not' => [['field' => 'champ_invente', 'op' => 'eq', 'value' => 'x']]],
        'is_active' => true,
        'auto_refresh' => true,
    ]);

    expect($this->service->evaluateForCompany($this->cible))->not->toContain($audience->id);
});

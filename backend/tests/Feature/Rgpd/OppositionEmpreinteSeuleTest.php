<?php

/**
 * 🔴 UNE ADRESSE OPPOSÉE NE REVIENT PAS — QUELLE QUE SOIT LA FORME DU SIGNAL.
 *
 * Décision du 2026-08-18 (temps 1 sur 2) : `opt_out` et `email_suppressions`
 * cessent d'écrire ET de lire l'adresse en clair ; l'empreinte suffit à
 * l'anti-réinsertion, et c'est le seul motif légitime de conservation. Le
 * `DROP COLUMN` est le temps 2, dans un déploiement séparé.
 *
 * Le danger de cette étape n'est pas de casser un test, c'est de **resserrer
 * une garde en la rendant aveugle** : si deux points d'écriture normalisent
 * l'adresse différemment avant de la hacher, ils produisent deux empreintes
 * pour la même personne, et la garde ne heurte plus rien. Le repli sur la
 * colonne en clair masquait la moitié de ce risque ; en le retirant, on
 * l'expose entièrement.
 *
 * Ces tests couvrent donc les DEUX moitiés :
 *   · le signal arrive EN CLAIR côté appelant (fournisseur d'envoi, console,
 *     effacement RGPD) → il doit fermer la porte ;
 *   · le signal arrive HACHÉ en base (site) → il doit fermer la porte aussi,
 *     y compris pour un appelant qui ne connaît que l'adresse lisible.
 *
 * Et ils couvrent le socle : une ligne d'opposition ne peut PLUS naître sans
 * empreinte — c'est une contrainte de table, pas une promesse de code.
 */

use App\Models\Company;
use App\Models\Contact;
use App\Models\Workspace;
use App\Services\Dedup\DeduplicationService;
use App\Support\EligibiliteCampagne;
use App\Support\ListeSuppression;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-empreinte', 'name' => 'WS', 'settings' => [],
    ]);
});

function ficheEmpreinte(string $workspaceId, string $email, string $nom): Company
{
    return Company::create([
        'workspace_id' => $workspaceId,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => $nom,
        'email_generic' => $email,
        'signals' => [],
        'metadata' => [],
    ]);
}

/** @return list<string> */
function eligiblesEmpreinte(string $workspaceId): array
{
    return EligibiliteCampagne::appliquer(
        Company::query()->where('workspace_id', $workspaceId),
    )->orderBy('denomination')->pluck('denomination')->all();
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. L'ANTI-RÉINSERTION, DANS LES DEUX SENS
// ─────────────────────────────────────────────────────────────────────────────

test('🔴 signal EN CLAIR côté appelant, empreinte en base : la fiche sort de l’audience éligible', function () {
    ficheEmpreinte($this->workspace->id, 'rebond@acme.fr', 'REBONDIE');
    ficheEmpreinte($this->workspace->id, 'ok@acme.fr', 'OK');

    // Ce que fait un webhook de fournisseur d'envoi : il ne connaît que
    // l'adresse lisible.
    ListeSuppression::inscrire('rebond@acme.fr', ListeSuppression::REBOND_DUR, 'esp');

    // Et ce qui est réellement écrit : l'empreinte, jamais l'adresse.
    $ligne = DB::table('email_suppressions')->first();
    expect($ligne->email)->toBeNull()
        ->and($ligne->email_hash)->toBe(ListeSuppression::empreinte('rebond@acme.fr'));

    expect(eligiblesEmpreinte($this->workspace->id))->toBe(['OK']);
    expect(EligibiliteCampagne::peutRecevoir('rebond@acme.fr'))->toBeFalse();
    // Casse et espaces n'ouvrent aucune brèche : une adresse collée d'un
    // tableur arrive rarement propre.
    expect(EligibiliteCampagne::peutRecevoir('  REBOND@Acme.FR '))->toBeFalse();
});

test('🔴 signal HACHÉ en base (site), appelant qui ne connaît que le clair : la porte reste fermée', function () {
    ficheEmpreinte($this->workspace->id, 'Oppose@Acme.FR', 'OPPOSEE');
    ficheEmpreinte($this->workspace->id, 'ok@acme.fr', 'OK');

    // Ce que `SiteSyncIngestService::recordOpposition()` écrit : le hash seul.
    DB::table('opt_out')->insert([
        'email' => null,
        'email_hash' => hash('sha256', 'oppose@acme.fr'),
        'scope' => 'business',
        'source' => 'site-sync:opt_out',
        'created_at' => now(),
    ]);

    expect(eligiblesEmpreinte($this->workspace->id))->toBe(['OK']);
    expect(EligibiliteCampagne::peutRecevoir('oppose@acme.fr'))->toBeFalse();
    expect(app(DeduplicationService::class)->isOptedOut('oppose@acme.fr'))->toBeTrue();
});

test('les PERSONNES sont protégées comme les entreprises', function () {
    // 410 481 contacts portent une adresse contre 255 290 génériques : une
    // garde qui ne couvrirait que l'entreprise laisserait la MAJORITÉ des
    // envois possibles hors de toute protection.
    $hote = ficheEmpreinte($this->workspace->id, 'siege@acme.fr', 'HOTE');
    Contact::create([
        'workspace_id' => $this->workspace->id, 'company_id' => $hote->id,
        'last_name' => 'JOIGNABLE', 'email' => 'ok@acme.fr', 'sources' => [], 'metadata' => [],
    ]);
    Contact::create([
        'workspace_id' => $this->workspace->id, 'company_id' => $hote->id,
        'last_name' => 'OPPOSEE', 'email' => 'non@acme.fr', 'sources' => [], 'metadata' => [],
    ]);

    DB::table('opt_out')->insert([
        'email' => null, 'email_hash' => hash('sha256', 'non@acme.fr'),
        'scope' => 'business', 'source' => 'site', 'created_at' => now(),
    ]);

    $restants = EligibiliteCampagne::appliquerContacts(
        Contact::query()->where('workspace_id', $this->workspace->id),
    )->pluck('last_name')->all();

    expect($restants)->toBe(['JOIGNABLE']);
});

test('un contact SANS adresse traverse la garde : on n’exclut personne pour une adresse qu’il n’a pas', function () {
    $hote = ficheEmpreinte($this->workspace->id, 'siege@acme.fr', 'HOTE');
    Contact::create([
        'workspace_id' => $this->workspace->id, 'company_id' => $hote->id,
        'last_name' => 'SANS ADRESSE', 'sources' => [], 'metadata' => [],
    ]);

    DB::table('opt_out')->insert([
        'email' => null, 'email_hash' => hash('sha256', 'quelquun@acme.fr'),
        'scope' => 'business', 'source' => 'site', 'created_at' => now(),
    ]);

    // `digest(NULL)` vaut NULL, la comparaison d'empreintes est donc fausse,
    // et `whereNotExists` est vrai. C'est le comportement voulu : la garde ne
    // doit pas emporter plus que ce qu'elle protège.
    $noms = EligibiliteCampagne::exclureOpposes(
        Contact::query()->where('workspace_id', $this->workspace->id),
        'contacts.email',
    )->pluck('last_name')->all();

    expect($noms)->toBe(['SANS ADRESSE']);
});

test('les deux univers restent étanches', function () {
    ficheEmpreinte($this->workspace->id, 'x@acme.fr', 'BUSINESS');

    DB::table('opt_out')->insert([
        'email' => null, 'email_hash' => hash('sha256', 'x@acme.fr'),
        'scope' => 'vivier', 'source' => 'site', 'created_at' => now(),
    ]);

    expect(eligiblesEmpreinte($this->workspace->id))->toBe(['BUSINESS']);
    expect(EligibiliteCampagne::peutRecevoir('x@acme.fr', 'business'))->toBeTrue();
    expect(EligibiliteCampagne::peutRecevoir('x@acme.fr', 'vivier'))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LA NORMALISATION — le vrai piège du resserrement
// ─────────────────────────────────────────────────────────────────────────────

test('🔴 une opposition écrite par le SITE est vue par la garde du scraping — accents compris', function () {
    // `SiteSyncEvent::emailHash()` normalise avec `mb_strtolower`.
    // `DeduplicationService::isOptedOut()` employait `strtolower`, qui ignore
    // les majuscules non-ASCII : les deux empreintes différaient, et la garde
    // ne voyait PAS l'opposition. Le repli sur la colonne en clair ne
    // rattrapait rien — une ligne née du site n'a pas d'adresse lisible.
    //
    // Retirer ce repli SANS aligner la normalisation aurait rendu la garde
    // plus aveugle en la croyant resserrée.
    $adresse = 'ÉRIC@ACME.FR';

    DB::table('opt_out')->insert([
        'email' => null,
        'email_hash' => hash('sha256', mb_strtolower(trim($adresse))),
        'scope' => 'business',
        'source' => 'site-sync:opt_out',
        'created_at' => now(),
    ]);

    expect(app(DeduplicationService::class)->isOptedOut($adresse))->toBeTrue();
    expect(app(DeduplicationService::class)->isOptedOut('éric@acme.fr'))->toBeTrue();
    expect(EligibiliteCampagne::peutRecevoir($adresse))->toBeFalse();
});

test('🔴 une opposition écrite par un EFFACEMENT est vue par le site — accents compris', function () {
    // Le sens inverse : `addOptOut()` employait `strtolower`, donc l'empreinte
    // qu'il écrivait n'était pas celle que le site interroge.
    app(DeduplicationService::class)->addOptOut('ÉRIC@ACME.FR', null, source: 'gdpr_erasure');

    $ligne = DB::table('opt_out')->first();

    expect($ligne->email)->toBeNull()
        ->and($ligne->email_hash)->toBe(hash('sha256', 'éric@acme.fr'));

    // Reproduction EXACTE de la requête de `ScrapedRecordIngestService` :
    // c'est elle qui décide si une fiche revient par un re-scrape.
    expect(DB::table('opt_out')
        ->where('scope', 'business')
        ->where('email_hash', hash('sha256', mb_strtolower(trim('  Éric@Acme.fr  '))))
        ->exists())->toBeTrue();
});

test('un téléphone seul reste une opposition valable, sans empreinte d’adresse', function () {
    // La contrainte de table exige l'empreinte QUAND une adresse est présente,
    // pas dans l'absolu : une opposition par téléphone n'a rien à hacher.
    app(DeduplicationService::class)->addOptOut(null, '06 12 34 56 78', source: 'manual');

    $ligne = DB::table('opt_out')->first();
    expect($ligne->email)->toBeNull()
        ->and($ligne->email_hash)->toBeNull()
        ->and($ligne->phone)->toBe('0612345678');

    expect(app(DeduplicationService::class)->isOptedOut(null, '06.12.34.56.78'))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. LE SOCLE — une ligne sans empreinte ne peut PLUS exister
// ─────────────────────────────────────────────────────────────────────────────

test('🔴 une ligne porteuse d’adresse SANS empreinte est REFUSÉE par la base', function () {
    // C'est une contrainte de table, pas une promesse de code : c'est elle qui
    // rendra le `DROP COLUMN` du temps 2 sûr. Sans elle, une opposition
    // pourrait n'exister que sous forme claire et deviendrait invisible le
    // jour où la colonne part — c'est-à-dire qu'on recontacterait quelqu'un
    // qui s'y est opposé.
    foreach (['opt_out', 'email_suppressions'] as $table) {
        $ligne = $table === 'opt_out'
            ? ['email' => 'orpheline@acme.fr', 'email_hash' => null, 'scope' => 'business', 'source' => 'legacy', 'created_at' => now()]
            : ['email' => 'orpheline@acme.fr', 'email_hash' => null, 'scope' => 'business', 'reason' => 'manual', 'source' => 'legacy', 'first_seen_at' => now(), 'last_seen_at' => now()];

        // ⚠️ `DB::transaction()` IMBRIQUÉE, et non un simple try/catch : une
        // violation de contrainte AVORTE la transaction Postgres courante —
        // celle de `RefreshDatabase` — et toute requête suivante répondrait
        // « current transaction is aborted » au lieu du vrai motif. Laravel
        // pose un SAVEPOINT pour une transaction imbriquée et y revient.
        $refus = null;
        try {
            DB::transaction(function () use ($table, $ligne): void {
                DB::table($table)->insert($ligne);
            });
        } catch (QueryException $e) {
            $refus = $e->getMessage();
        }

        expect($refus)->not->toBeNull("« {$table} » a ACCEPTÉ une ligne avec une adresse en clair et sans empreinte.");
        expect($refus)->toContain($table . '_empreinte_obligatoire_check');
    }
});

test('🔴 aucune ligne d’opposition ne porte plus d’adresse en clair après un cycle d’écriture complet', function () {
    // Le balayage des quatre points d'écriture réels du système.
    ListeSuppression::inscrire('rebond@acme.fr', ListeSuppression::REBOND_DUR, 'esp');
    ListeSuppression::inscrire('rebond@acme.fr', ListeSuppression::PLAINTE, 'esp');
    ListeSuppression::inscrire('autre@acme.fr', ListeSuppression::MANUEL, 'console', 'vivier');
    app(DeduplicationService::class)->addOptOut('efface@acme.fr', null, source: 'gdpr_erasure');

    foreach (['opt_out', 'email_suppressions'] as $table) {
        expect(DB::table($table)->whereNotNull('email')->count())
            ->toBe(0, "« {$table} » conserve encore une adresse en clair.");
        expect(DB::table($table)->whereNull('email_hash')->count())->toBe(0);
    }

    // L'idempotence n'est pas perdue au passage : un second signal incrémente,
    // il ne crée pas une seconde ligne, et la raison la plus grave l'emporte.
    $lignes = DB::table('email_suppressions')
        ->where('email_hash', ListeSuppression::empreinte('rebond@acme.fr'))
        ->where('scope', 'business')
        ->get();
    expect($lignes)->toHaveCount(1)
        ->and((int) $lignes->first()->occurrences)->toBe(2)
        ->and($lignes->first()->reason)->toBe(ListeSuppression::PLAINTE);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. LA MIGRATION DE REMPLISSAGE — jouée pour de vrai sur des lignes héritées
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Rejoue la migration `2026_08_18_000001` sur l'état courant de la base.
 *
 * 🔴 On charge le fichier de migration RÉEL et on appelle son `up()` : une
 * réimplémentation du remplissage dans le test ne prouverait rien de ce qui
 * partira en production.
 */
function rejouerRemplissage(): object
{
    /** @var object $migration */
    $migration = require database_path('migrations/2026_08_18_000001_backfill_empreintes_oppositions.php');

    return $migration;
}

function retirerLesContraintes(): void
{
    // On remet la base dans l'état d'AVANT la migration pour pouvoir y semer
    // des lignes héritées. C'est le seul moyen honnête de la tester : sur une
    // base fraîche, la migration n'a rien à remplir et se terminerait verte
    // sans avoir rien fait.
    foreach (['opt_out', 'email_suppressions'] as $table) {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_empreinte_obligatoire_check");
    }
}

test('🔴 la migration remplit l’empreinte des lignes héritées, avec le calcul du SSOT', function () {
    retirerLesContraintes();

    // ⚠️ Quatre `insert()` distincts et non un tableau de quatre lignes : un
    // insert de masse exige les MÊMES clés partout, et la ligne « téléphone
    // seul » en porte une de plus.
    DB::table('opt_out')->insert(['email' => 'Legacy.Un@ACME.fr', 'email_hash' => null, 'scope' => 'business', 'source' => 'legacy', 'created_at' => now()]);
    DB::table('opt_out')->insert(['email' => '  legacy.deux@acme.fr  ', 'email_hash' => null, 'scope' => 'vivier', 'source' => 'legacy', 'created_at' => now()]);
    // Déjà pourvue : elle ne doit pas être recalculée ni écrasée.
    DB::table('opt_out')->insert(['email' => 'deja@acme.fr', 'email_hash' => 'empreinte-posee-par-le-site', 'scope' => 'business', 'source' => 'site', 'created_at' => now()]);
    // Téléphone seul : rien à hacher, et rien à reprocher.
    DB::table('opt_out')->insert(['email' => null, 'email_hash' => null, 'phone' => '0612345678', 'scope' => 'business', 'source' => 'manual', 'created_at' => now()]);
    // 🔴 Une MAJUSCULE ACCENTUÉE, et c'est le cas qui compte : c'est le seul
    // qui distingue le remplissage PHP (SSOT, `mb_strtolower`) du remplissage
    // SQL (`lower()` sous `lc_ctype=C`, qui laisse le « É » intact). Sans
    // cette ligne, un remplissage écrit en SQL passerait ce test — il « marche »
    // sur tout l'ASCII — et fabriquerait en production des empreintes que
    // personne n'interroge.
    DB::table('opt_out')->insert(['email' => 'ÉRIC@ACME.FR', 'email_hash' => null, 'scope' => 'business', 'source' => 'legacy-accent', 'created_at' => now()]);
    DB::table('email_suppressions')->insert([
        'email' => 'REBOND@Acme.FR', 'email_hash' => null, 'scope' => 'business',
        'reason' => 'hard_bounce', 'source' => 'legacy', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $migration = rejouerRemplissage();
    $migration->up();

    // Le COMPTE, constaté et non supposé : trois lignes portaient une adresse
    // sans empreinte, la quatrième en avait déjà une, la cinquième n'a qu'un
    // téléphone.
    expect($migration->remplies)->toBe(['opt_out' => 3, 'email_suppressions' => 1]);

    // Le cas qui sépare le SSOT PHP du SQL équivalent.
    expect(DB::table('opt_out')->where('source', 'legacy-accent')->value('email_hash'))
        ->toBe(ListeSuppression::empreinte('ÉRIC@ACME.FR'))
        ->and(DB::table('opt_out')->where('source', 'legacy-accent')->value('email_hash'))
        ->toBe(hash('sha256', 'éric@acme.fr'));

    expect(DB::table('opt_out')->where('source', 'legacy')->where('scope', 'business')->value('email_hash'))
        ->toBe(ListeSuppression::empreinte('legacy.un@acme.fr'));
    expect(DB::table('opt_out')->where('scope', 'vivier')->value('email_hash'))
        ->toBe(ListeSuppression::empreinte('legacy.deux@acme.fr'));
    expect(DB::table('opt_out')->where('source', 'site')->value('email_hash'))
        ->toBe('empreinte-posee-par-le-site');
    expect(DB::table('opt_out')->where('phone', '0612345678')->value('email_hash'))->toBeNull();
    expect(DB::table('email_suppressions')->value('email_hash'))
        ->toBe(ListeSuppression::empreinte('rebond@acme.fr'));

    // Et le remplissage referme la porte derrière lui.
    expect(DB::table('opt_out')->whereNotNull('email')->whereNull('email_hash')->count())->toBe(0);

    // La ligne héritée devient VISIBLE de la garde, ce qu'elle n'était pas :
    // c'est tout l'objet du temps 1.
    ficheEmpreinte($this->workspace->id, 'legacy.un@acme.fr', 'HERITEE');
    ficheEmpreinte($this->workspace->id, 'libre@acme.fr', 'LIBRE');
    expect(eligiblesEmpreinte($this->workspace->id))->toBe(['LIBRE']);
});

test('🔴 la migration ÉCHOUE BRUYAMMENT sur une ligne irrécupérable, elle ne finit pas en vert', function () {
    retirerLesContraintes();

    // Une adresse faite d'espaces seuls : son empreinte serait celle de la
    // chaîne vide, identique pour toutes, et une telle ligne ne heurterait
    // jamais personne. La remplir serait pire que de s'arrêter — elle passerait
    // pour une opposition active.
    DB::table('opt_out')->insert([
        'email' => '   ', 'email_hash' => null, 'scope' => 'business', 'source' => 'legacy', 'created_at' => now(),
    ]);
    $idFautif = (int) DB::table('opt_out')->value('id');

    $erreur = null;
    try {
        rejouerRemplissage()->up();
    } catch (RuntimeException $e) {
        $erreur = $e->getMessage();
    }

    expect($erreur)->not->toBeNull('la migration s’est terminée en vert sur un travail partiel');
    expect($erreur)->toContain('REFUS DE POURSUIVRE')
        ->and($erreur)->toContain('opt_out')
        // L'id fautif est NOMMÉ : sans lui, le message dit qu'il y a un
        // problème sans dire lequel, et personne ne peut agir.
        ->and($erreur)->toContain((string) $idFautif)
        // Et le remède est écrit, pas à deviner.
        ->and($erreur)->toContain('DELETE FROM opt_out WHERE email_hash IS NULL');

    // La contrainte n'a PAS été posée : on ne verrouille pas une table dont
    // l'invariant n'est pas établi.
    expect(DB::selectOne(
        "select 1 as ok from pg_constraint where conname = 'opt_out_empreinte_obligatoire_check'",
    ))->toBeNull();
});

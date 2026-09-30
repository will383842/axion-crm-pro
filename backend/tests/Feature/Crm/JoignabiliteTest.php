<?php

/**
 * JOIGNABILITÉ — `crm:joignabilite:calculer`, le recalcul par
 * `crm:emails:verifier`, les filtres de liste (chantier D).
 *
 * Rien n'est supprimé : une adresse invalide reste sur sa fiche. Fixtures
 * FICTIVES (dépôt public) : domaines `.example` / `.invalid`.
 */

use App\Crm\Doublons\Rapprochement;
use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\Dns\ResultatDns;
use App\Crm\Emails\VerificationEmail;
use App\Crm\FichesProtegees;
use App\Crm\Joignabilite\Joignabilite;
use App\Crm\Taxonomy;
use App\Models\User;
use App\Models\Workspace;
use App\Support\EligibiliteCampagne;
use App\Support\ListeSuppression;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-joignable-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ joignabilité', 'settings' => []]);
});

/** La fiche de vérification que `crm:emails:verifier` aurait écrite. */
function jgVerif(string $email, string $statut): string
{
    return (string) json_encode([
        'verifie_par' => VerificationEmail::SOURCE,
        'empreinte' => VerificationEmail::empreinte($email),
        'statut' => $statut,
    ]);
}

/** @param  array<string, mixed>  $attrs */
function jgFiche(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace, 'siren' => (string) (943000000 + $seq), 'denomination' => 'ZZ joignable ' . $seq,
        'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
    ], $attrs));
}

/** @param  array<string, mixed>  $attrs */
function jgPersonne(string $espace, int $fiche, array $attrs = []): int
{
    return (int) DB::table('contacts')->insertGetId(array_merge([
        'workspace_id' => $espace, 'company_id' => $fiche, 'first_name' => 'ZZ', 'last_name' => 'Personne ' . Str::random(6),
        'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
    ], $attrs));
}

function jgEtat(string $table, int $id): ?string
{
    $v = DB::table($table)->where('id', $id)->value('joignabilite');

    return $v === null ? null : (string) $v;
}

function jgCalculer(string $slug, array $options = []): array
{
    $code = Artisan::call('crm:joignabilite:calculer', array_merge(['--workspace' => $slug], $options));

    return ['code' => $code, 'sortie' => Artisan::output()];
}

/**
 * Le jeu : un cas par état.
 *
 * @return array<string, int>
 */
function jgJeu(string $espace): array
{
    $j = [];
    $j['valide'] = jgFiche($espace, ['email_generic' => 'accueil@zz-valide.example', 'signals' => json_encode(['email_generic_verification' => json_decode(jgVerif('accueil@zz-valide.example', 'valide'), true)])]);
    // Générique invalide, mais une personne valide : la fiche est joignable.
    $j['par_personne'] = jgFiche($espace, ['email_generic' => 'mort@zz-mort.example', 'signals' => json_encode(['email_generic_verification' => json_decode(jgVerif('mort@zz-mort.example', 'invalide'), true)])]);
    $j['personne_valide'] = jgPersonne($espace, $j['par_personne'], ['email' => 'dir@zz-vivant.example', 'metadata' => json_encode(['email_verification' => json_decode(jgVerif('dir@zz-vivant.example', 'valide'), true)])]);
    // Seule adresse : une personne opposée (vérifiée valide, mais opposition).
    $j['interdite'] = jgFiche($espace);
    $j['personne_opposee'] = jgPersonne($espace, $j['interdite'], ['email' => 'non@zz-oppose.example', 'metadata' => json_encode(['email_verification' => json_decode(jgVerif('non@zz-oppose.example', 'valide'), true)])]);
    DB::table('opt_out')->insert(['email_hash' => ListeSuppression::empreinte('non@zz-oppose.example'), 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    // Statut `invalid` (rebond dur) : invalide, adresse GARDÉE.
    $j['invalide'] = jgFiche($espace);
    $j['personne_invalide'] = jgPersonne($espace, $j['invalide'], ['email' => 'rebond@zz-rebond.example', 'email_status' => 'invalid']);
    $j['non_verifiee'] = jgFiche($espace, ['email_generic' => 'contact@zz-jamais.example']);
    $j['telephone'] = jgFiche($espace, ['phone' => '+33 1 00 00 00 00']);
    $j['personne_telephone'] = jgPersonne($espace, $j['invalide'], ['phone' => '+33 6 00 00 00 00']);
    $j['rien'] = jgFiche($espace);
    $j['corbeille'] = jgFiche($espace, ['deleted_at' => now()]);

    return $j;
}

test('chaque fiche et chaque personne recoit son etat — aucune adresse supprimee ni reecrite', function () {
    $j = jgJeu($this->espace);
    $adresses = DB::table('contacts')->orderBy('id')->pluck('email')->all();
    $generiques = DB::table('companies')->orderBy('id')->pluck('email_generic')->all();
    $avant = DB::table('companies')->where('id', $j['valide'])->value('updated_at');

    $r = jgCalculer($this->slug);

    expect($r['code'])->toBe(0)
        ->and(jgEtat('companies', $j['valide']))->toBe(Joignabilite::EMAIL_VALIDE)
        ->and(jgEtat('companies', $j['par_personne']))->toBe(Joignabilite::EMAIL_VALIDE)
        ->and(jgEtat('contacts', $j['personne_valide']))->toBe(Joignabilite::EMAIL_VALIDE)
        ->and(jgEtat('companies', $j['interdite']))->toBe(Joignabilite::EMAIL_INTERDIT)
        ->and(jgEtat('contacts', $j['personne_opposee']))->toBe(Joignabilite::EMAIL_INTERDIT)
        ->and(jgEtat('companies', $j['invalide']))->toBe(Joignabilite::EMAIL_INVALIDE)
        ->and(jgEtat('contacts', $j['personne_invalide']))->toBe(Joignabilite::EMAIL_INVALIDE)
        ->and(jgEtat('contacts', $j['personne_telephone']))->toBe(Joignabilite::SANS_EMAIL_AVEC_TELEPHONE)
        ->and(jgEtat('companies', $j['non_verifiee']))->toBe(Joignabilite::EMAIL_NON_VERIFIE)
        ->and(jgEtat('companies', $j['telephone']))->toBe(Joignabilite::SANS_EMAIL_AVEC_TELEPHONE)
        ->and(jgEtat('companies', $j['rien']))->toBe(Joignabilite::SANS_CONTACT)
        ->and(jgEtat('companies', $j['corbeille']))->toBeNull()
        // Rien n'est supprimé ni réécrit.
        ->and(DB::table('contacts')->orderBy('id')->pluck('email')->all())->toBe($adresses)
        ->and(DB::table('companies')->orderBy('id')->pluck('email_generic')->all())->toBe($generiques)
        // Calculer n'est pas modifier la fiche.
        ->and(DB::table('companies')->where('id', $j['valide'])->value('updated_at'))->toBe($avant)
        ->and(DB::table('audit_logs')->where('event_type', 'JOIGNABILITE_LOT')->count())->toBeGreaterThanOrEqual(1);
});

test('l essai a blanc n ecrit rien ; le passage reel est idempotent et reprenable', function () {
    jgJeu($this->espace);
    $audits = DB::table('audit_logs')->count();

    $blanc = jgCalculer($this->slug, ['--dry-run' => true]);
    expect(DB::table('companies')->whereNotNull('joignabilite')->count())->toBe(0)
        ->and(DB::table('contacts')->whereNotNull('joignabilite')->count())->toBe(0)
        ->and(DB::table('audit_logs')->count())->toBe($audits);

    // Par lots d'une fiche, arrêté après deux lots, puis repris.
    $partiel = jgCalculer($this->slug, ['--lot' => 1, '--max-lots' => 2]);
    preg_match('/--depuis-id=(\d+)/', $partiel['sortie'], $m);
    jgCalculer($this->slug, ['--lot' => 1, '--depuis-id' => $m[1]]);
    $relance = jgCalculer($this->slug);

    $n = static fn (string $sortie, string $c): ?int => preg_match('/\|\s*' . $c . '\s*\|\s*(\d+)\s*\|/', $sortie, $x) === 1 ? (int) $x[1] : null;
    expect(DB::table('companies')->whereNull('deleted_at')->whereNull('joignabilite')->count())->toBe(0)
        ->and($n($blanc['sortie'], 'entreprises_a_modifier'))->toBe(DB::table('companies')->whereNull('deleted_at')->count())
        ->and($n($relance['sortie'], 'entreprises_a_modifier'))->toBe(0)
        ->and($n($relance['sortie'], 'personnes_a_modifier'))->toBe(0);
});

test('le lot en une requete dit exactement ce que peutRecevoir dit, adresse par adresse', function () {
    DB::table('opt_out')->insert(['email_hash' => ListeSuppression::empreinte('oppose@zz-sym.example'), 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    DB::table('email_suppressions')->insert(['scope' => 'business', 'email_hash' => ListeSuppression::empreinte('plainte@zz-sym.example'), 'reason' => 'complaint', 'source' => 'test']);
    // Une autre portée ne compte pas pour `business`.
    DB::table('email_suppressions')->insert(['scope' => 'vivier', 'email_hash' => ListeSuppression::empreinte('vivier@zz-sym.example'), 'reason' => 'manual', 'source' => 'test']);
    $adresses = ['oppose@zz-sym.example', 'PLAINTE@zz-sym.example', 'vivier@zz-sym.example', 'libre@zz-sym.example'];

    $interdites = Joignabilite::interditesParmi($adresses, 'business');
    foreach ($adresses as $a) {
        expect(isset($interdites[ListeSuppression::empreinte(strtolower($a))]))->toBe(! EligibiliteCampagne::peutRecevoir($a));
    }
    expect(count($interdites))->toBe(2);
});

test('crm:emails:verifier recalcule la joignabilite des fiches dont il change le statut', function () {
    app()->instance(ResolveurDns::class, new ResolveurDnsSimule(['zz-sans-courrier.example' => ResultatDns::INEXISTANT], ResultatDns::MX));
    $vivante = jgFiche($this->espace);
    $personneVivante = jgPersonne($this->espace, $vivante, ['email' => 'p@zz-vivant.example']);
    $morte = jgFiche($this->espace);
    $personneMorte = jgPersonne($this->espace, $morte, ['email' => 'p@zz-sans-courrier.example']);
    jgCalculer($this->slug);
    expect(jgEtat('contacts', $personneVivante))->toBe(Joignabilite::EMAIL_NON_VERIFIE);

    $code = Artisan::call('crm:emails:verifier', ['--workspace' => $this->slug, '--source' => 'contacts']);

    expect($code)->toBe(0)
        ->and(jgEtat('contacts', $personneVivante))->toBe(Joignabilite::EMAIL_VALIDE)
        ->and(jgEtat('companies', $vivante))->toBe(Joignabilite::EMAIL_VALIDE)
        ->and(jgEtat('contacts', $personneMorte))->toBe(Joignabilite::EMAIL_INVALIDE)
        ->and(jgEtat('companies', $morte))->toBe(Joignabilite::EMAIL_INVALIDE)
        // L'adresse invalide reste sur la fiche.
        ->and(DB::table('contacts')->where('id', $personneMorte)->value('email'))->toBe('p@zz-sans-courrier.example');
});

test('les listes Entreprises et Contacts filtrent par joignabilite', function () {
    $j = jgJeu($this->espace);
    jgCalculer($this->slug);
    $this->seed(PermissionsAndRolesSeeder::class);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'adm-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ adm',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->espace);
    $user->assignRole('admin');
    $this->actingAs($user);

    $entreprises = collect($this->getJson('/api/v1/companies?filter[joignabilite]=email_valide')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    $contacts = $this->getJson('/api/v1/contacts?filter[joignabilite]=email_interdit')->assertOk()->json('data');

    expect($entreprises)->toBe(collect([$j['valide'], $j['par_personne']])->sort()->values()->all())
        ->and(array_column($contacts, 'id'))->toBe([$j['personne_opposee']])
        ->and($contacts[0]['joignabilite'])->toBe(Joignabilite::EMAIL_INTERDIT);
});

// ── Relecture de #265 ───────────────────────────────────────────────────────

/** Une fiche d'ORGANISATEUR (segment de campagne), adresse générique vérifiée. */
function jgOrganisateur(string $espace, ?string $email, string $verification = 'valide'): int
{
    $attrs = ['siren' => null, 'foreign_id' => 'zz-org-' . Str::random(8)];
    if ($email !== null) {
        $attrs['email_generic'] = $email;
        $attrs['signals'] = json_encode(['email_generic_verification' => json_decode(jgVerif($email, $verification), true)]);
    }
    $id = jgFiche($espace, $attrs);
    F::proteger($espace, $id, FichesProtegees::TAG_ORGANISATEURS);

    return $id;
}

test('R3 — ACCORD : email_valide <=> l adresse part dans crm:campagne:destinataires', function () {
    config(['crm.ingest.business_workspace' => $this->slug]);
    $valide = jgOrganisateur($this->espace, 'bureau@zz-part.example.invalid');
    $opposee = jgOrganisateur($this->espace, 'non@zz-oppose.example.invalid');
    DB::table('opt_out')->insert(['email_hash' => ListeSuppression::empreinte('non@zz-oppose.example.invalid'), 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    // Opposée ET invalide : interdite (l'opposition d'abord).
    $opposeeInvalide = jgOrganisateur($this->espace, 'mort-et-oppose@zz-oppose.example.invalid', 'invalide');
    DB::table('opt_out')->insert(['email_hash' => ListeSuppression::empreinte('mort-et-oppose@zz-oppose.example.invalid'), 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    // Vérifiée valide sur UNE fiche, `invalid` sur une autre : condamnée PARTOUT.
    $condamnee = jgOrganisateur($this->espace, 'rebond@zz-rebond.example.invalid');
    $autre = jgOrganisateur($this->espace, null);
    jgPersonne($this->espace, $autre, ['email' => 'rebond@zz-rebond.example.invalid', 'email_status' => 'invalid']);
    $nonVerifiee = jgOrganisateur($this->espace, 'jamais@zz-jamais.example.invalid', 'aucune');
    $perso = jgOrganisateur($this->espace, 'zz-fictif@yahoo.zz');
    // Cabinet comptable porté par 3 fiches : exclu par défaut.
    $cabinets = [];
    foreach ([1, 2, 3] as $i) {
        $cabinets[] = jgOrganisateur($this->espace, 'compta@zz-cabinet.example.invalid');
    }
    DB::table('adresses_partagees')->insert([
        'workspace_id' => $this->espace, 'email_empreinte' => F::empreinteAdresse('compta@zz-cabinet.example.invalid'), 'nb_fiches' => 3, 'nature' => Rapprochement::CABINET_COMPTABLE,
    ]);
    // À la CORBEILLE : une fiche et une personne d'une fiche supprimée qui
    // condamnent `bureau@` — la campagne ne les lit pas, la joignabilité non plus.
    jgFiche($this->espace, ['email_generic' => 'bureau@zz-part.example.invalid', 'deleted_at' => now(),
        'signals' => json_encode(['email_generic_verification' => json_decode(jgVerif('bureau@zz-part.example.invalid', 'invalide'), true)])]);
    $supprimee = jgFiche($this->espace, ['deleted_at' => now()]);
    jgPersonne($this->espace, $supprimee, ['email' => 'bureau@zz-part.example.invalid', 'email_status' => 'invalid']);
    // HORS SEGMENT (limite documentée) : `seg@` n'est pas vérifiée dans le
    // segment, mais l'est sur une fiche vivante HORS segment (sans le tag).
    jgOrganisateur($this->espace, 'seg@zz-seg.example.invalid', 'aucune');
    jgFiche($this->espace, ['email_generic' => 'seg@zz-seg.example.invalid',
        'signals' => json_encode(['email_generic_verification' => json_decode(jgVerif('seg@zz-seg.example.invalid', 'valide'), true)])]);

    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-jg-');
    Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $fichier]);
    $partent = [];
    foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
        $partent[] = (string) json_decode($ligne, true)['email'];
    }
    @unlink($fichier);
    jgCalculer($this->slug);

    $toutes = ['bureau@zz-part.example.invalid', 'non@zz-oppose.example.invalid', 'mort-et-oppose@zz-oppose.example.invalid',
        'rebond@zz-rebond.example.invalid', 'jamais@zz-jamais.example.invalid', 'zz-fictif@yahoo.zz', 'compta@zz-cabinet.example.invalid'];
    $etats = Joignabilite::etatsAdresses($this->espace, $toutes, 'business');
    $valides = array_keys(array_filter($etats, static fn (string $e): bool => $e === Joignabilite::EMAIL_VALIDE));
    sort($valides);
    sort($partent);

    // L'équivalence, sur les adresses dont toutes les occurrences sont dans le segment…
    expect($valides)->toBe($partent)
        ->and($partent)->toBe(['bureau@zz-part.example.invalid'])
        // … et la LIMITE, figée : hors segment, l'espace voit une vérification
        // que le segment n'a pas ; la joignabilité dit valide, la campagne
        // l'écarte. La liste de campagne reste la vérité de l'envoi.
        ->and(Joignabilite::etatsAdresses($this->espace, ['seg@zz-seg.example.invalid'], 'business'))->toBe(['seg@zz-seg.example.invalid' => Joignabilite::EMAIL_VALIDE])
        ->and(jgEtat('companies', $valide))->toBe(Joignabilite::EMAIL_VALIDE)
        ->and(jgEtat('companies', $opposee))->toBe(Joignabilite::EMAIL_INTERDIT)
        ->and(jgEtat('companies', $opposeeInvalide))->toBe(Joignabilite::EMAIL_INTERDIT)
        ->and(jgEtat('companies', $condamnee))->toBe(Joignabilite::EMAIL_INVALIDE)
        ->and(jgEtat('companies', $autre))->toBe(Joignabilite::EMAIL_INVALIDE)
        ->and(jgEtat('companies', $nonVerifiee))->toBe(Joignabilite::EMAIL_NON_VERIFIE)
        ->and(jgEtat('companies', $perso))->toBe(Joignabilite::EMAIL_PERSONNEL)
        ->and(jgEtat('companies', $cabinets[0]))->toBe(Joignabilite::EMAIL_PARTAGE);
});

test('Securite R1 — l univers de la liste de suppression est celui de l espace (vivier pour les candidats)', function () {
    $vivier = (string) DB::table('workspaces')->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)->value('id');
    if ($vivier === '') {
        $vivier = (string) Str::uuid();
        Workspace::create(['id' => $vivier, 'slug' => Taxonomy::VIVIER_WORKSPACE_SLUG, 'name' => 'ZZ vivier', 'settings' => []]);
    }
    DB::table('email_suppressions')->insert(['scope' => 'vivier', 'email_hash' => ListeSuppression::empreinte('supprime@zz-vivier.example'), 'reason' => 'manual', 'source' => 'test']);
    $dansLeVivier = jgFiche($vivier, ['email_generic' => 'supprime@zz-vivier.example']);
    // TÉMOIN : la même adresse dans un espace business n'est pas interdite.
    $dansLeBusiness = jgFiche($this->espace, ['email_generic' => 'supprime@zz-vivier.example']);

    expect(Joignabilite::universDe($vivier))->toBe('vivier')
        ->and(Joignabilite::universDe($this->espace))->toBe('business');

    Artisan::call('crm:joignabilite:calculer', ['--workspace' => $vivier]);
    jgCalculer($this->slug);

    expect(jgEtat('companies', $dansLeVivier))->toBe(Joignabilite::EMAIL_INTERDIT)
        ->and(jgEtat('companies', $dansLeBusiness))->toBe(Joignabilite::EMAIL_NON_VERIFIE);
});

test('R4 — le calcul relit ses donnees sous verrou, DANS la transaction du lot qui ecrit', function () {
    jgJeu($this->espace);
    // Le test tourne déjà dans une transaction (RefreshDatabase) : on mesure
    // le niveau AU-DESSUS de celui-ci.
    $base = DB::transactionLevel();
    $journal = [];
    DB::listen(function ($q) use (&$journal): void {
        $journal[] = ['sql' => strtolower($q->sql), 'niveau' => DB::transactionLevel()];
    });

    jgCalculer($this->slug);

    $verrous = array_values(array_filter($journal, static fn (array $e): bool => str_contains($e['sql'], 'for update')
        && (str_contains($e['sql'], '"companies"') || str_contains($e['sql'], '"contacts"'))));
    $ecritures = array_values(array_filter($journal, static fn (array $e): bool => str_starts_with(ltrim($e['sql']), 'update') && str_contains($e['sql'], 'joignabilite')));
    expect(count($verrous))->toBeGreaterThanOrEqual(2)
        ->and(count($ecritures))->toBeGreaterThanOrEqual(1);
    // Chaque lecture verrouillée ET chaque écriture sont DANS la transaction
    // du lot (un niveau au-dessus de celui du test).
    foreach (array_merge($verrous, $ecritures) as $e) {
        expect($e['niveau'])->toBeGreaterThan($base);
    }
    // TÉMOIN : l'essai à blanc ne verrouille rien.
    $journal = [];
    jgCalculer($this->slug, ['--dry-run' => true]);
    expect(array_filter($journal, static fn (array $e): bool => str_contains($e['sql'], 'for update')))->toBe([]);
    // Et l'écriture suit la lecture verrouillée dans la MÊME transaction :
    // aucune lecture de `companies` NON verrouillée entre les deux.
    $premierVerrou = array_search($verrous[0], $journal, true);
    $premiereEcriture = array_search($ecritures[0], $journal, true);
    expect($premierVerrou)->toBeLessThan($premiereEcriture);
});

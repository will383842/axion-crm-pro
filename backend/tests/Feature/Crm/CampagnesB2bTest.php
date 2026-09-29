<?php

/**
 * CAMPAGNES B2B, CÔTÉ CRM (2026-09-27) — la liste des destinataires et les
 * retours, sans aucun envoi. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Emails\Dns\ResultatDns;
use App\Crm\FichesProtegees;
use App\Models\Workspace;
use App\Support\EligibiliteCampagne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $slug = 'zz-camp-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $slug, 'name' => 'ZZ campagnes']);
    config(['crm.ingest.business_workspace' => $slug]);

    $this->tag = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAGS[0], 'name' => 'Organisateurs',
        'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->club = campOrga($this, 'evt:zz-club', 'ZZ Club', 'bureau@zz-club.example.invalid');
    $this->jumeau = campOrga($this, 'evt:zz-club-jumeau', 'ZZ Club (autre nom)', 'bureau@zz-club.example.invalid');

    $this->pro = campContact($this, $this->club, 'ZZ Pro', 'pro@zz-club.example.invalid');
    $this->perso = campContact($this, $this->club, 'ZZ Perso', 'zz.perso@gmail.com', ['metadata' => json_encode(['email_nature' => 'perso'])]);
    $this->invalide = campContact($this, $this->club, 'ZZ Invalide', 'invalide@zz-club.example.invalid', ['email_status' => 'invalid']);
    $this->oppose = campContact($this, $this->club, 'ZZ Oppose', 'oppose@zz-club.example.invalid');
    DB::table('opt_out')->insert([
        'email_hash' => hash('sha256', 'oppose@zz-club.example.invalid'), 'scope' => 'business',
        'source' => 'test', 'created_at' => now(),
    ]);

    $this->evenement = campEvenement($this, $this->club, now()->addDays(10)->toDateString());
    $this->passe = campEvenement($this, $this->club, now()->subDays(10)->toDateString());

    // Témoin : une entreprise ORDINAIRE (non organisatrice) n'est jamais visée.
    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'siren' => '900000941', 'denomination' => 'ZZ Ordinaire',
        'email_generic' => 'ordinaire@zz-autre.example.invalid', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function () {
    foreach ($GLOBALS['zz_camp_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['zz_camp_fichiers'] = [];
});

function campOrga(object $t, string $fid, string $nom, ?string $generique): int
{
    $id = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $t->espace, 'siren' => null, 'country_code' => 'FR', 'foreign_id' => $fid,
        'entity_nature' => 'reseau', 'denomination' => $nom, 'email_generic' => $generique,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $id, 'tag_id' => $t->tag, 'workspace_id' => $t->espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);

    return $id;
}

/** @param  array<string, mixed>  $attrs */
function campContact(object $t, int $companyId, string $nom, string $email, array $attrs = []): int
{
    return (int) DB::table('contacts')->insertGetId(array_merge([
        'workspace_id' => $t->espace, 'company_id' => $companyId, 'last_name' => $nom,
        'email' => $email, 'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

function campEvenement(object $t, int $companyId, string $date): int
{
    $id = (int) DB::table('events')->insertGetId([
        'workspace_id' => $t->espace, 'external_ref' => 'zz-' . Str::random(8), 'nom' => 'ZZ Salon',
        'type' => 'salon', 'date_debut' => $date, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('event_organizers')->insert([
        'event_id' => $id, 'company_id' => $companyId, 'workspace_id' => $t->espace, 'created_at' => now(),
    ]);

    return $id;
}

function campFichier(array $lignes = []): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'zz-camp-');
    $GLOBALS['zz_camp_fichiers'][] = $chemin;
    file_put_contents($chemin, implode("\n", array_map(fn ($l) => is_string($l) ? $l : json_encode($l), $lignes)) . "\n");

    return $chemin;
}

/** @return list<array<string, mixed>> */
function campDestinataires(array $options = []): array
{
    // La liste ne retient que des adresses VÉRIFIÉES valides : on vérifie
    // d'abord, avec un DNS simulé où tout domaine reçoit.
    ResolveurDnsSimule::toutVerifier();
    $sortie = campFichier();
    Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $sortie] + $options);

    return array_values(array_filter(array_map(
        fn ($l) => json_decode($l, true),
        file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    )));
}

function campRetours(array $lignes, bool $aBlanc = false): string
{
    Artisan::call('crm:campagne:retours', ['file' => campFichier($lignes)] + ($aBlanc ? ['--dry-run' => true] : []));

    return Artisan::output();
}

test('un segment ferme ou inconnu est refuse', function () {
    $code = Artisan::call('crm:campagne:destinataires', ['segment' => 'prospects-insee', 'sortie' => campFichier()]);

    expect($code)->toBe(1)->and(Artisan::output())->toContain('Segment fermé');
});

test('la liste ne retient que les adresses autorisees, une seule fois chacune', function () {
    $emails = collect(campDestinataires())->pluck('email')->sort()->values()->all();

    // Retenues : la boîte générique (UNE fois pour deux organisateurs) et le
    // contact pro. Écartées : perso, invalide, opposée, et l'entreprise ordinaire.
    expect($emails)->toBe(['bureau@zz-club.example.invalid', 'pro@zz-club.example.invalid']);
});

test('une boite partagee cite tous ses organisateurs et l evenement a venir le plus proche', function () {
    $ligne = collect(campDestinataires())->firstWhere('email', 'bureau@zz-club.example.invalid');

    expect($ligne['organisations'])->toContain('ZZ Club')->toContain('ZZ Club (autre nom)')
        ->and($ligne['evenement']['id'])->toBe($this->evenement)
        ->and($ligne['crm_ref'])->toBe('organisation:' . $this->club);
});

test('une adresse qui a deja recu le premier message est ecartee avec --non-informes', function () {
    DB::table('contacts')->where('id', $this->pro)->update(['first_info_at' => now()]);

    $emails = collect(campDestinataires(['--non-informes' => true]))->pluck('email')->all();

    expect($emails)->not->toContain('pro@zz-club.example.invalid')
        ->and($emails)->toContain('bureau@zz-club.example.invalid');
});

test('la liste n ecrit rien en base', function () {
    $avant = [DB::table('opt_out')->count(), DB::table('activities')->count(), DB::table('events')->where('intervention', '!=', 'aucune')->count()];

    campDestinataires();

    expect([DB::table('opt_out')->count(), DB::table('activities')->count(), DB::table('events')->where('intervention', '!=', 'aucune')->count()])
        ->toBe($avant);
});

test('envoye note le premier message et propose l intervention, une seule fois', function () {
    $ligne = ['type' => 'envoye', 'email' => 'pro@zz-club.example.invalid', 'crm_ref' => 'contact:' . $this->pro,
        'campagne' => 'zz-oct', 'date' => '2026-10-05T10:00:00+02:00', 'evenement_id' => $this->evenement];

    campRetours([$ligne]);
    $premier = DB::table('contacts')->where('id', $this->pro)->value('first_info_at');

    expect($premier)->not->toBeNull()
        ->and(DB::table('companies')->where('id', $this->club)->value('first_info_at'))->not->toBeNull()
        ->and(DB::table('events')->where('id', $this->evenement)->value('intervention'))->toBe('proposee');

    // Rejouer : rien ne bouge, pas de seconde ligne d'historique, date inchangée.
    campRetours([array_merge($ligne, ['date' => '2026-10-20T10:00:00+02:00'])]);
    expect(DB::table('contacts')->where('id', $this->pro)->value('first_info_at'))->toBe($premier)
        ->and(DB::table('activities')->where('subject_type', 'event')->where('subject_id', $this->evenement)->count())->toBe(1);
});

test('envoye ne fait jamais reculer une etape, ni ne touche l evenement d un autre organisateur', function () {
    DB::table('events')->where('id', $this->evenement)->update(['intervention' => 'acceptee']);
    $autre = campEvenement($this, campOrga($this, 'evt:zz-autre', 'ZZ Autre', null), now()->addDays(5)->toDateString());

    campRetours([
        ['type' => 'envoye', 'email' => 'pro@zz-club.example.invalid', 'crm_ref' => 'contact:' . $this->pro, 'campagne' => 'zz', 'evenement_id' => $this->evenement],
        ['type' => 'envoye', 'email' => 'pro@zz-club.example.invalid', 'crm_ref' => 'contact:' . $this->pro, 'campagne' => 'zz', 'evenement_id' => $autre],
    ]);

    expect(DB::table('events')->where('id', $this->evenement)->value('intervention'))->toBe('acceptee')
        ->and(DB::table('events')->where('id', $autre)->value('intervention'))->toBe('aucune');
});

test('desinscription, plainte et rebond dur retirent l adresse des listes suivantes', function () {
    expect(EligibiliteCampagne::peutRecevoir('pro@zz-club.example.invalid', 'business'))->toBeTrue();

    campRetours([
        ['type' => 'desinscription', 'email' => 'pro@zz-club.example.invalid', 'campagne' => 'zz'],
        ['type' => 'plainte', 'email' => 'bureau@zz-club.example.invalid', 'campagne' => 'zz'],
    ]);
    $autre = campContact($this, $this->club, 'ZZ Rebond', 'rebond@zz-club.example.invalid');
    campRetours([['type' => 'rebond_dur', 'email' => 'rebond@zz-club.example.invalid', 'campagne' => 'zz']]);

    expect(EligibiliteCampagne::peutRecevoir('pro@zz-club.example.invalid', 'business'))->toBeFalse()
        ->and(EligibiliteCampagne::peutRecevoir('bureau@zz-club.example.invalid', 'business'))->toBeFalse()
        ->and(DB::table('contacts')->where('id', $autre)->value('email_status'))->toBe('invalid')
        ->and(campDestinataires())->toBe([]);
});

test('une ligne invalide ou une adresse inconnue est rejetee sans rien creer', function () {
    $avant = DB::table('contacts')->count() + DB::table('companies')->count();

    $sortie = campRetours([
        ['type' => 'kermesse', 'email' => 'pro@zz-club.example.invalid'],
        ['type' => 'envoye', 'email' => 'inconnu@zz-nulle-part.example.invalid', 'campagne' => 'zz'],
        '{pas du json',
    ]);

    expect($sortie)->toMatch('/rejetees\s*\|\s*3/')
        ->and(DB::table('contacts')->count() + DB::table('companies')->count())->toBe($avant);
});

test('les retours a blanc n ecrivent rien', function () {
    campRetours([['type' => 'desinscription', 'email' => 'pro@zz-club.example.invalid', 'campagne' => 'zz']], true);

    expect(EligibiliteCampagne::peutRecevoir('pro@zz-club.example.invalid', 'business'))->toBeTrue();
});

test('une boite partagee envoyee informe TOUS ses organisateurs, et ne ressort plus en non-informes', function () {
    campRetours([['type' => 'envoye', 'email' => 'bureau@zz-club.example.invalid', 'crm_ref' => 'organisation:' . $this->club, 'campagne' => 'zz']]);

    expect(DB::table('companies')->where('id', $this->club)->value('first_info_at'))->not->toBeNull()
        ->and(DB::table('companies')->where('id', $this->jumeau)->value('first_info_at'))->not->toBeNull()
        ->and(collect(campDestinataires(['--non-informes' => true]))->pluck('email')->all())
        ->not->toContain('bureau@zz-club.example.invalid');
});

test('une adresse invalide sur UNE de ses fiches est ecartee partout', function () {
    campContact($this, $this->jumeau, 'ZZ Meme boite', 'bureau@zz-club.example.invalid', ['email_status' => 'invalid']);

    expect(collect(campDestinataires())->pluck('email')->all())->not->toContain('bureau@zz-club.example.invalid');
});

test('une adresse de domaine pro marquee personnelle est ecartee', function () {
    campContact($this, $this->club, 'ZZ Marquee', 'marquee@zz-club.example.invalid', ['metadata' => json_encode(['email_nature' => 'perso'])]);

    expect(collect(campDestinataires())->pluck('email')->all())->not->toContain('marquee@zz-club.example.invalid');
});

test('un rebond mou a blanc ne compte pas', function () {
    $ligne = ['type' => 'rebond_mou', 'email' => 'pro@zz-club.example.invalid', 'campagne' => 'zz'];
    campRetours([$ligne], true);
    campRetours([$ligne], true);
    campRetours([$ligne], true);
    campRetours([$ligne]);

    // Un seul rebond réel : sous le seuil de 3, l'adresse reste joignable.
    expect(EligibiliteCampagne::peutRecevoir('pro@zz-club.example.invalid', 'business'))->toBeTrue();
});

test('envoye retrouve une boite generique saisie en majuscules', function () {
    $maj = campOrga($this, 'evt:zz-maj', 'ZZ Majuscules', 'Accueil.Maj@zz-club.example.invalid');

    campRetours([['type' => 'envoye', 'email' => 'accueil.maj@zz-club.example.invalid', 'campagne' => 'zz']]);

    expect(DB::table('companies')->where('id', $maj)->value('first_info_at'))->not->toBeNull();
});

test('envoye ne touche jamais une fiche a la corbeille, ni ne date dans le futur', function () {
    $supprime = campContact($this, $this->club, 'ZZ Corbeille', 'corbeille@zz-club.example.invalid', ['deleted_at' => now()]);

    campRetours([['type' => 'envoye', 'email' => 'corbeille@zz-club.example.invalid', 'campagne' => 'zz']]);
    campRetours([['type' => 'envoye', 'email' => 'pro@zz-club.example.invalid', 'campagne' => 'zz', 'date' => '2031-01-01T10:00:00+01:00']]);

    expect(DB::table('contacts')->where('id', $supprime)->value('first_info_at'))->toBeNull()
        ->and(strtotime((string) DB::table('contacts')->where('id', $this->pro)->value('first_info_at')))->toBeLessThanOrEqual(time());
});

test('la liste refuse un chemin dans le depot', function () {
    $code = Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => base_path('zz-liste.jsonl')]);

    expect($code)->toBe(1)->and(file_exists(base_path('zz-liste.jsonl')))->toBeFalse();
});

// ── La vérification des e-mails (crm:emails:verifier, 2026-09-30) ──────────

test('une adresse JAMAIS verifiee n est pas retenue, et le bilan le dit', function () {
    $sortie = campFichier();
    Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $sortie]);

    expect(file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])->toBe([])
        // bureau@ (une adresse, deux organisateurs), pro@, la perso et l'opposée ;
        // invalide@ l'est déjà par son `email_status`.
        ->and(Artisan::output())->toMatch('/ecartees_non_verifiees\s*\|\s*4\s*\|/');
});

test('une adresse dont le domaine ne recoit rien est ecartee, meme verifiee', function () {
    // Tout domaine répond « n'existe pas » : bureau@ et pro@ passent à
    // `invalide` — ils restent sur leurs fiches, mais pas dans la liste.
    ResolveurDnsSimule::toutVerifier(ResultatDns::INEXISTANT);
    $sortie = campFichier();
    Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $sortie]);
    $bilan = Artisan::output();

    expect(file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])->toBe([])
        // Les cinq adresses de l'organisateur, perso et opposée comprises :
        // toutes INVALIDES — et pas « non vérifiées ».
        ->and($bilan)->toMatch('/ecartees_invalides\s*\|\s*5\s*\|/')
        ->and($bilan)->toMatch('/ecartees_non_verifiees\s*\|\s*0\s*\|/')
        ->and(DB::table('companies')->where('id', $this->club)->value('email_generic'))->toBe('bureau@zz-club.example.invalid');
});

test('une verification ecrite pour une AUTRE adresse ne vaut pas pour celle-ci', function () {
    ResolveurDnsSimule::toutVerifier();
    // L'adresse de la fiche change APRÈS la vérification.
    DB::table('contacts')->where('id', $this->pro)->update(['email' => 'nouvelle@zz-club.example.invalid']);
    $sortie = campFichier();
    Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $sortie]);
    $emails = array_map(fn ($l) => json_decode($l, true)['email'], file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

    expect($emails)->toBe(['bureau@zz-club.example.invalid']);
});

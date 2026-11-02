<?php

/**
 * NR-03 — R5, les retours de campagne (`crm:campagne:retours`).
 *
 * `envoye` pose la date de premier message (art. 14) sans jamais la reculer ;
 * `desinscription` et `plainte` = opposition définitive ; `rebond_dur` =
 * suppression ; la commande ne fait que RETIRER, jamais ajouter ; `--dry-run`
 * n’écrit rien.
 *
 * Ajout du filet : UN fichier de retours factice COMPLET rejoué sur un jeu fixe,
 * et l’ÉTAT FINAL de la base comparé à la photo `instantanes/nr03-retours.json`.
 * Les trois gardes existantes qui citent la commande (`CampagnesB2bTest` et
 * deux autres) sont rattachées au groupe par `tests/Pest.php`.
 *
 * Fixtures FICTIVES (`@example.test`).
 */

use App\Crm\FichesProtegees;
use App\Models\Workspace;
use App\Support\EligibiliteCampagne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $slug = 'nr03-' . Str::lower(Str::random(6));
    Workspace::create(['id' => $this->espace, 'slug' => $slug, 'name' => 'NR-03']);
    config(['crm.ingest.business_workspace' => $slug]);

    $this->tag = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAGS[0], 'name' => 'Organisateurs',
        'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->club = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => null, 'country_code' => 'FR', 'foreign_id' => 'evt:nr03-club',
        'entity_nature' => 'reseau', 'denomination' => 'Club Nr03', 'email_generic' => 'bureau@club.example.test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $this->club, 'tag_id' => $this->tag, 'workspace_id' => $this->espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);

    foreach (['envoye', 'desinscrit', 'plaignant', 'rebondeur', 'mou'] as $nom) {
        $this->{'c_' . $nom} = (int) DB::table('contacts')->insertGetId([
            'workspace_id' => $this->espace, 'company_id' => $this->club, 'last_name' => ucfirst($nom),
            'email' => $nom . '@club.example.test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->evenement = (int) DB::table('events')->insertGetId([
        'workspace_id' => $this->espace, 'external_ref' => 'nr03-evt', 'nom' => 'Salon Nr03',
        'type' => 'salon', 'date_debut' => '2099-05-01', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('event_organizers')->insert([
        'event_id' => $this->evenement, 'company_id' => $this->club, 'workspace_id' => $this->espace, 'created_at' => now(),
    ]);
});

afterEach(function () {
    foreach ($GLOBALS['nr03_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['nr03_fichiers'] = [];
});

function nr03Retours(array $lignes, bool $aBlanc = false): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'nr03-');
    $GLOBALS['nr03_fichiers'][] = $chemin;
    file_put_contents($chemin, implode("\n", array_map(fn ($l) => is_string($l) ? $l : json_encode($l), $lignes)) . "\n");
    Artisan::call('crm:campagne:retours', ['file' => $chemin] + ($aBlanc ? ['--dry-run' => true] : []));

    return Artisan::output();
}

/** @return list<array<string, mixed>> le fichier de retours complet : un exemple de chaque type connu */
function nr03FichierComplet(object $t): array
{
    return [
        ['type' => 'envoye', 'email' => 'envoye@club.example.test', 'crm_ref' => 'contact:' . $t->c_envoye, 'campagne' => 'nr03', 'date' => '2026-10-05T10:00:00+02:00', 'evenement_id' => $t->evenement],
        ['type' => 'envoye', 'email' => 'bureau@club.example.test', 'crm_ref' => 'organisation:' . $t->club, 'campagne' => 'nr03', 'date' => '2026-10-05T10:00:00+02:00'],
        ['type' => 'desinscription', 'email' => 'desinscrit@club.example.test', 'campagne' => 'nr03'],
        ['type' => 'plainte', 'email' => 'plaignant@club.example.test', 'campagne' => 'nr03'],
        ['type' => 'rebond_dur', 'email' => 'rebondeur@club.example.test', 'campagne' => 'nr03'],
        ['type' => 'rebond_mou', 'email' => 'mou@club.example.test', 'campagne' => 'nr03'],
        ['type' => 'kermesse', 'email' => 'envoye@club.example.test'],
        '{pas du json',
    ];
}

/** L’état final, SANS identifiants ni dates du jour (instables). */
function nr03Etat(object $t): array
{
    $contacts = DB::table('contacts')->where('workspace_id', $t->espace)->orderBy('email')->get(['email', 'email_status', 'first_info_at']);

    return [
        'contacts' => $contacts->map(fn ($c) => [
            'email' => $c->email,
            'statut_email' => $c->email_status,
            'premier_message_pose' => $c->first_info_at !== null,
        ])->all(),
        'organisation_premier_message_pose' => DB::table('companies')->where('id', $t->club)->value('first_info_at') !== null,
        'evenement_intervention' => DB::table('events')->where('id', $t->evenement)->value('intervention'),
        'oppositions' => DB::table('opt_out')->orderBy('email_hash')->get(['email_hash', 'scope'])->map(fn ($o) => ['empreinte' => $o->email_hash, 'univers' => $o->scope])->all(),
        'suppressions' => DB::table('email_suppressions')->orderBy('email_hash')->get(['email_hash', 'scope', 'reason', 'occurrences'])
            ->map(fn ($s) => ['empreinte' => $s->email_hash, 'univers' => $s->scope, 'raison' => $s->reason, 'occurrences' => (int) $s->occurrences])->all(),
    ];
}

test('NR-03 un fichier de retours complet donne l’état final de la photo de référence', function () {
    $sortie = nr03Retours(nr03FichierComplet($this));

    // Deux lignes invalides (type inconnu, JSON cassé) sont rejetées, rien de créé.
    expect($sortie)->toMatch('/rejetees\s*\|\s*2/');
    Instantane::verifier('nr03-retours', nr03Etat($this));
});

test('NR-03 chaque retour fait ce que le plan 21 § 6 annonce', function () {
    nr03Retours(nr03FichierComplet($this));

    expect(EligibiliteCampagne::peutRecevoir('desinscrit@club.example.test'))->toBeFalse()
        ->and(EligibiliteCampagne::peutRecevoir('plaignant@club.example.test'))->toBeFalse()
        ->and(EligibiliteCampagne::peutRecevoir('rebondeur@club.example.test'))->toBeFalse()
        ->and(DB::table('contacts')->where('id', $this->c_rebondeur)->value('email_status'))->toBe('invalid')
        ->and(EligibiliteCampagne::peutRecevoir('mou@club.example.test'))->toBeTrue() // un rebond mou : sous le seuil de 3
        ->and(DB::table('contacts')->where('id', $this->c_envoye)->value('first_info_at'))->not->toBeNull();
});

test('NR-03 « envoye » ne recule jamais la date du premier message', function () {
    $ligne = ['type' => 'envoye', 'email' => 'envoye@club.example.test', 'crm_ref' => 'contact:' . $this->c_envoye, 'campagne' => 'nr03', 'date' => '2026-10-05T10:00:00+02:00'];
    nr03Retours([$ligne]);
    $premier = DB::table('contacts')->where('id', $this->c_envoye)->value('first_info_at');

    nr03Retours([array_merge($ligne, ['date' => '2026-10-20T10:00:00+02:00'])]);
    nr03Retours([array_merge($ligne, ['date' => '2026-09-01T10:00:00+02:00'])]);

    expect(DB::table('contacts')->where('id', $this->c_envoye)->value('first_info_at'))->toBe($premier);
});

test('NR-03 --dry-run n’écrit RIEN en base', function () {
    $avant = nr03Etat($this);

    nr03Retours(nr03FichierComplet($this), true);

    expect(nr03Etat($this))->toBe($avant)
        ->and(EligibiliteCampagne::peutRecevoir('desinscrit@club.example.test'))->toBeTrue();

    // Témoin : le MÊME fichier, joué pour de vrai, change bien l’état (sinon ce
    // test serait vert pour une commande qui ne fait jamais rien).
    nr03Retours(nr03FichierComplet($this));
    expect(nr03Etat($this))->not->toBe($avant);
});

test('NR-03 rejouer le même fichier ne change pas l’état (hors compteur de rebonds mous)', function () {
    nr03Retours(nr03FichierComplet($this));
    $apres = nr03Etat($this);

    nr03Retours(nr03FichierComplet($this));
    $rejoue = nr03Etat($this);

    // Opposition et date du premier message : identiques. Seul le compteur
    // d’occurrences d’une suppression peut monter (réserve connue du plan 21 § 6).
    foreach (['contacts', 'organisation_premier_message_pose', 'evenement_intervention', 'oppositions'] as $cle) {
        expect($rejoue[$cle])->toBe($apres[$cle]);
    }
    expect(count($rejoue['suppressions']))->toBe(count($apres['suppressions']));
});

test('NR-03 la commande ne crée aucune fiche pour une adresse inconnue', function () {
    $avant = DB::table('contacts')->count() + DB::table('companies')->count();

    nr03Retours([['type' => 'envoye', 'email' => 'inconnu@nulle-part.example.test', 'campagne' => 'nr03']]);

    expect(DB::table('contacts')->count() + DB::table('companies')->count())->toBe($avant);
});

<?php

/**
 * ÉVÉNEMENTS PROFESSIONNELS — schéma et import (2026-09-27).
 *
 * Fixtures FICTIVES uniquement (dépôt public) : `example.invalid`, noms « ZZ ».
 */

use App\Crm\Taxonomy;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $slug = 'zz-evt-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $slug, 'name' => 'ZZ événements']);
    config(['crm.ingest.business_workspace' => $slug]);

    $this->club = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace,
        'siren' => null,
        'country_code' => 'FR',
        'foreign_id' => 'evt:zz-club-affaires',
        'entity_nature' => 'reseau',
        'denomination' => 'ZZ Club d affaires',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->chambre = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace,
        'siren' => '900000901',
        'entity_nature' => 'cci',
        'denomination' => 'ZZ Chambre',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function () {
    foreach ($GLOBALS['zz_evt_fichiers'] ?? [] as $chemin) {
        @unlink($chemin);
    }
    $GLOBALS['zz_evt_fichiers'] = [];
});

/** @param  list<array<string, mixed>|string>  $lignes */
function evtFichier(array $lignes): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'zz-evt-');
    $GLOBALS['zz_evt_fichiers'][] = $chemin;
    file_put_contents($chemin, implode("\n", array_map(
        static fn (array|string $l): string => is_string($l) ? $l : json_encode($l, JSON_UNESCAPED_UNICODE),
        $lignes,
    )) . "\n");

    return $chemin;
}

/** @param  array<string, mixed>  $surcharge */
function evtLigne(string $ref, array $surcharge = []): array
{
    return array_merge([
        'external_ref' => $ref,
        'nom' => 'ZZ Événement ' . $ref,
        'type' => 'club-affaires',
        'date_debut' => '2026-10-15',
        'ville' => 'Lyon',
        'region' => 'AURA',
        'verifie' => true,
        'organisateurs' => [['country' => 'FR', 'foreign_id' => 'evt:zz-club-affaires']],
    ], $surcharge);
}

function evtBilan(string $sortie, string $compteur): ?int
{
    return preg_match('/\|\s*' . preg_quote($compteur, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m) === 1 ? (int) $m[1] : null;
}

test('les CHECK des evenements en base suivent la taxonomie', function () {
    $attendus = [
        'events_type_check' => Taxonomy::EVENEMENT_TYPES,
        'events_participation_check' => Taxonomy::EVENEMENT_PARTICIPATIONS,
        'events_intervention_check' => Taxonomy::EVENEMENT_INTERVENTIONS,
        'events_appel_intervenants_check' => Taxonomy::EVENEMENT_APPELS_INTERVENANTS,
    ];
    foreach ($attendus as $contrainte => $valeurs) {
        $def = (string) DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = ?',
            [$contrainte],
        )->d;
        foreach ($valeurs as $valeur) {
            expect($def)->toContain("'" . $valeur . "'");
        }
    }
});

test('la nature reseau est acceptee par la base et par le format pivot', function () {
    expect(DB::table('companies')->where('id', $this->club)->value('entity_nature'))->toBe('reseau')
        ->and(array_keys(Taxonomy::ENTITY_NATURES))->toContain('reseau');
});

test('la source evenements-pro est au registre, active, des la migration', function () {
    // Pas de `seed()` ici : c'est la MIGRATION qui doit l'avoir posée, car les
    // seeders ne tournent pas au déploiement.
    expect(DB::table('scraping_sources')->where('slug', 'evenements-pro')->value('enabled'))->toBeTrue();
});

test('l import cree l evenement et le relie a ses organisateurs, par foreign_id et par siren', function () {
    $fichier = evtFichier([
        evtLigne('zz-1', ['organisateurs' => [
            ['country' => 'FR', 'foreign_id' => 'evt:zz-club-affaires'],
            ['siren' => '900000901'],
        ]]),
    ]);

    Artisan::call('crm:import-evenements', ['file' => $fichier]);

    $event = DB::table('events')->where('external_ref', 'zz-1')->first();
    expect($event)->not->toBeNull()
        ->and($event->participation)->toBe('repere')
        ->and($event->intervention)->toBe('aucune')
        ->and(DB::table('event_organizers')->where('event_id', $event->id)->pluck('company_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect([$this->club, $this->chambre])->sort()->values()->all());
});

test('l essai a blanc n ecrit rien et rend le MEME bilan que l import reel', function () {
    // Le MÊME événement deux fois : en réel, la 2e ligne le trouve (1 créé,
    // 1 inchangé). Un essai à blanc qui annulerait ligne par ligne l'aurait
    // compté « créé » deux fois.
    $fichier = evtFichier([evtLigne('zz-a'), evtLigne('zz-a')]);

    Artisan::call('crm:import-evenements', ['file' => $fichier, '--dry-run' => true]);
    $aBlanc = Artisan::output();

    expect(DB::table('events')->count())->toBe(0)
        ->and(DB::table('event_organizers')->count())->toBe(0);

    Artisan::call('crm:import-evenements', ['file' => $fichier]);
    $reel = Artisan::output();

    foreach (['crees', 'inchanges', 'liens_crees', 'rejetes'] as $compteur) {
        expect(evtBilan($aBlanc, $compteur))->toBe(evtBilan($reel, $compteur));
    }
    expect(evtBilan($reel, 'crees'))->toBe(1)
        ->and(evtBilan($reel, 'inchanges'))->toBe(1)
        ->and(evtBilan($reel, 'liens_crees'))->toBe(1)
        ->and(DB::table('events')->count())->toBe(1);
});

test('un re-import met a jour la description mais jamais la demarche de Will', function () {
    Artisan::call('crm:import-evenements', ['file' => evtFichier([evtLigne('zz-r')])]);
    DB::table('events')->where('external_ref', 'zz-r')->update([
        'participation' => 'inscrit',
        'intervention' => 'proposee',
        'demarche_note' => 'ZZ note',
    ]);

    Artisan::call('crm:import-evenements', ['file' => evtFichier([evtLigne('zz-r', ['ville' => 'Grenoble'])])]);

    $event = DB::table('events')->where('external_ref', 'zz-r')->first();
    expect(evtBilan(Artisan::output(), 'mis_a_jour'))->toBe(1)
        ->and($event->ville)->toBe('Grenoble')
        ->and($event->participation)->toBe('inscrit')
        ->and($event->intervention)->toBe('proposee')
        ->and($event->demarche_note)->toBe('ZZ note')
        ->and(DB::table('events')->count())->toBe(1);

    // Témoin : sans changement, rien n'est réécrit.
    Artisan::call('crm:import-evenements', ['file' => evtFichier([evtLigne('zz-r', ['ville' => 'Grenoble'])])]);
    expect(evtBilan(Artisan::output(), 'inchanges'))->toBe(1);
});

test('une ligne fautive est rejetee seule, les autres passent', function () {
    $fichier = evtFichier([
        evtLigne('zz-ok'),
        evtLigne('zz-cle', ['champ_inconnu' => 'x']),
        evtLigne('zz-type', ['type' => 'kermesse']),
        evtLigne('zz-date', ['date_debut' => '2026-13-45']),
        evtLigne('zz-inverse', ['date_debut' => '2026-10-20', 'date_fin' => '2026-10-01']),
        evtLigne('zz-ancre', ['organisateurs' => [['denomination' => 'ZZ']]]),
        '{pas du json',
    ]);

    Artisan::call('crm:import-evenements', ['file' => $fichier]);
    $sortie = Artisan::output();

    expect(DB::table('events')->pluck('external_ref')->all())->toBe(['zz-ok'])
        ->and(evtBilan($sortie, 'rejetes'))->toBe(6)
        ->and($sortie)->toContain('cle_inconnue')
        ->and($sortie)->toContain('type_inconnu')
        ->and($sortie)->toContain('date_invalide')
        ->and($sortie)->toContain('dates_inversees')
        ->and($sortie)->toContain('organisateur_sans_ancre')
        ->and($sortie)->toContain('json_invalide');
});

test('une erreur de la base annule SA ligne seule, et la commande le signale en echec', function () {
    // Le lien vers la chambre est refusé par la base APRÈS l'insertion de
    // l'événement : seul le point de sauvegarde de la ligne peut l'annuler.
    DB::statement("ALTER TABLE event_organizers ADD CONSTRAINT zz_refuse_chambre CHECK (company_id <> {$this->chambre}) NOT VALID");

    $code = Artisan::call('crm:import-evenements', ['file' => evtFichier([
        evtLigne('zz-bon'),
        evtLigne('zz-casse', ['organisateurs' => [['siren' => '900000901']]]),
    ])]);
    $sortie = Artisan::output();

    expect(DB::table('events')->pluck('external_ref')->all())->toBe(['zz-bon'])
        ->and($sortie)->toContain('erreur_base')
        ->and($sortie)->toContain('ÉCHEC')
        ->and($code)->toBe(1);
});

test('les compteurs d une ligne rejetee ne sont pas reportes', function () {
    // Un organisateur introuvable, PUIS une ancre invalide : la ligne est
    // rejetée, son « introuvable » ne doit pas rester dans le bilan.
    Artisan::call('crm:import-evenements', ['file' => evtFichier([evtLigne('zz-mixte', ['organisateurs' => [
        ['country' => 'FR', 'foreign_id' => 'evt:zz-inconnu'],
        ['denomination' => 'ZZ'],
    ]])])]);
    $sortie = Artisan::output();

    expect(evtBilan($sortie, 'rejetes'))->toBe(1)
        ->and(evtBilan($sortie, 'organisateurs_introuvables'))->toBe(0);
});

test('verifie doit etre un vrai booleen', function () {
    Artisan::call('crm:import-evenements', ['file' => evtFichier([evtLigne('zz-v', ['verifie' => 'true'])])]);

    expect(DB::table('events')->count())->toBe(0)
        ->and(Artisan::output())->toContain('type_de_valeur_invalide');
});

test('les coordonnees presentes dans les notes sont retirees a l import', function () {
    Artisan::call('crm:import-evenements', ['file' => evtFichier([evtLigne('zz-n', [
        'notes' => 'Contact : zz.personne@example.invalid ou 06 12 34 56 78, entree libre.',
    ])])]);

    $notes = (string) DB::table('events')->where('external_ref', 'zz-n')->value('notes');
    expect($notes)->not->toContain('@')
        ->and($notes)->not->toContain('06 12')
        ->and($notes)->toContain('entree libre')
        ->and(evtBilan(Artisan::output(), 'notes_expurgees'))->toBe(1);
});

test('un organisateur absent est compte, jamais invente', function () {
    $avant = DB::table('companies')->count();
    $fichier = evtFichier([evtLigne('zz-orphelin', ['organisateurs' => [['country' => 'FR', 'foreign_id' => 'evt:zz-inconnu']]])]);

    Artisan::call('crm:import-evenements', ['file' => $fichier]);
    $sortie = Artisan::output();

    expect(DB::table('companies')->count())->toBe($avant)
        ->and(evtBilan($sortie, 'organisateurs_introuvables'))->toBe(1)
        ->and(evtBilan($sortie, 'sans_organisateur'))->toBe(1)
        ->and(DB::table('events')->where('external_ref', 'zz-orphelin')->exists())->toBeTrue();
});

test('un evenement recurrent sans date est accepte', function () {
    $fichier = evtFichier([evtLigne('zz-bni', ['date_debut' => null, 'recurrence' => 'chaque mardi 7h'])]);

    Artisan::call('crm:import-evenements', ['file' => $fichier]);

    $event = DB::table('events')->where('external_ref', 'zz-bni')->first();
    expect($event->date_debut)->toBeNull()
        ->and($event->recurrence)->toBe('chaque mardi 7h');
});

// ── Référentiels (2026-09-28) : la région est un code INSEE ────────────────

test('la region du sourcing (AURA) est enregistree en code INSEE (84)', function () {
    // `events.region` et `companies.region_code` parlent désormais la même
    // langue : on peut croiser événements et organisations d'une région.
    Artisan::call('crm:import-evenements', ['file' => evtFichier([
        evtLigne('zz-reg-1'),
        evtLigne('zz-reg-2', ['region' => 'Île-de-France']),
        evtLigne('zz-reg-3', ['region' => null, 'departement_code' => '69']),
    ])]);

    expect(DB::table('events')->where('external_ref', 'zz-reg-1')->value('region'))->toBe('84')
        ->and(DB::table('events')->where('external_ref', 'zz-reg-2')->value('region'))->toBe('11')
        // Sans région, elle se déduit du département.
        ->and(DB::table('events')->where('external_ref', 'zz-reg-3')->value('region'))->toBe('84');
});

test('une region illisible est refusee, et la base refuse aussi un sigle', function () {
    Artisan::call('crm:import-evenements', ['file' => evtFichier([
        evtLigne('zz-reg-x', ['region' => 'Atlantide']),
    ])]);

    expect(Artisan::output())->toContain('region_inconnue')
        ->and(DB::table('events')->where('external_ref', 'zz-reg-x')->exists())->toBeFalse();

    // Le CHECK `events_region_check` : un chemin qui oublierait la conversion
    // ne pourrait pas écrire un sigle.
    expect(fn () => DB::table('events')->insert([
        'workspace_id' => $this->espace,
        'external_ref' => 'zz-reg-sigle',
        'nom' => 'ZZ sigle',
        'type' => 'salon',
        'region' => 'AURA',
    ]))->toThrow(QueryException::class, 'events_region_check');
});

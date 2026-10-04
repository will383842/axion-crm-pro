<?php

/**
 * DOUBLONS — la FUSION et son ANNULATION (`FusionFiches`,
 * `crm:doublons:fusionner`, chantier 5).
 *
 * RÈGLE DE WILL : aucun contact supprimé, aucune fiche supprimée en dur, tout
 * est réversible. Chaque garde face à son témoin, prouvée par son EFFET :
 *  - tout ce qui pointait vers la fiche absorbée pointe vers la fiche gardée
 *    (personnes, étiquettes et protection, événements, fédération et
 *    antennes, activités, affaires, audiences, collectes, médias,
 *    journalistes, praticiens, personnes de la lettre) ;
 *  - la fiche absorbée est à la CORBEILLE, jamais supprimée — et la base
 *    refuse de la supprimer en dur, purges comprises ;
 *  - l'annulation remet la base EXACTEMENT dans son état d'avant ;
 *  - chaque refus n'écrit RIEN ;
 *  - le journal ne garde aucune coordonnée ;
 *  - la fusion automatique re-vérifie la preuve, et ne reprend jamais une
 *    paire défaite par un humain.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Crm\Doublons\RefusFusion;
use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace();
    $ws = $this->ws;

    // La fiche INSEE (gardée) et l'organisateur sans SIREN (absorbé) : même
    // nom, même code postal, même site.
    $this->garde = F::fiche($ws, 'ZZ Omega', ['postcode' => '69010', 'website' => 'https://zz-omega.example.invalid', 'phone' => null]);
    $this->absorbee = F::sansSiren($ws, 'ZZ Omega', [
        'postcode' => '69010', 'website' => 'https://www.zz-omega.example.invalid/',
        'email_generic' => 'contact@zz-omega.example.invalid', 'phone' => '+33 4 00 00 00 10',
    ]);
    F::proteger($ws, $this->absorbee, FichesProtegees::TAG_ORGANISATEURS);
    $commun = F::tag($ws, 'zz-commun');
    F::lier($ws, $this->garde, $commun);
    F::lier($ws, $this->absorbee, $commun);
    F::lier($ws, $this->absorbee, F::tag($ws, 'zz-seulement-absorbee'));

    // Personnes : une seule côté absorbée ; deux homonymes (la gardée n'a pas
    // d'e-mail, l'absorbée en a un).
    $this->seule = F::contact($ws, $this->absorbee, 'Zoe', 'ZZSEULE', ['email' => 'zoe@zz-omega.example.invalid']);
    $this->jumelleGarde = F::contact($ws, $this->garde, 'Zed', 'ZZJUMEAU', ['phone' => '+33 6 00 00 00 11']);
    $this->jumelleAbsorbee = F::contact($ws, $this->absorbee, 'Zed', 'ZZJUMEAU', ['email' => 'zed@zz-omega.example.invalid', 'email_status' => 'valid', 'phone' => '+33 6 00 00 00 11']);

    // Événements : un seul côté absorbée, un aux deux.
    $evt = static fn (string $ref): int => (int) DB::table('events')->insertGetId([
        'workspace_id' => $ws, 'external_ref' => $ref, 'nom' => 'ZZ ' . $ref, 'type' => 'salon', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->evtSeul = $evt('zz-evt-seul');
    $this->evtCommun = $evt('zz-evt-commun');
    foreach ([[$this->evtSeul, $this->absorbee], [$this->evtCommun, $this->absorbee], [$this->evtCommun, $this->garde]] as [$e, $c]) {
        DB::table('event_organizers')->insert(['event_id' => $e, 'company_id' => $c, 'workspace_id' => $ws, 'created_at' => now()]);
    }

    // La fiche absorbée est une fédération, avec une antenne.
    DB::table('federations')->insert(['company_id' => $this->absorbee, 'workspace_id' => $ws, 'famille' => 'ordre', 'niveau' => 'national', 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact']);
    $this->antenne = F::fiche($ws, 'ZZ Omega Antenne');
    DB::table('federations')->insert(['company_id' => $this->antenne, 'workspace_id' => $ws, 'famille' => 'ordre', 'niveau' => 'departemental', 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact', 'parent_company_id' => $this->absorbee]);

    // Tout le reste de ce qui pointe vers une fiche.
    $this->evenementMetier = (int) DB::table('business_events')->insertGetId([
        'workspace_id' => $ws, 'action' => 'zz.tag', 'resource_type' => 'company', 'resource_id' => (string) $this->absorbee, 'created_at' => now(),
    ]);
    $this->activite = (int) DB::table('activities')->insertGetId(['workspace_id' => $ws, 'type' => 'note', 'kind' => 'scraped', 'subject_type' => 'company', 'subject_id' => $this->absorbee, 'created_at' => now()]);
    $pipeline = (int) DB::table('crm_pipelines')->insertGetId(['workspace_id' => $ws, 'name' => 'ZZ Pipeline', 'slug' => 'zz-pipeline']);
    $etape = (int) DB::table('pipeline_stages')->insertGetId(['workspace_id' => $ws, 'pipeline_id' => $pipeline, 'slug' => 'zz-etape', 'name' => 'ZZ Étape']);
    $this->deal = (int) DB::table('deals')->insertGetId(['workspace_id' => $ws, 'company_id' => $this->absorbee, 'pipeline_id' => $pipeline, 'stage_id' => $etape]);
    $audience = (int) DB::table('email_audiences')->insertGetId(['workspace_id' => $ws, 'name' => 'ZZ Audience']);
    $this->membre = (int) DB::table('audience_members')->insertGetId(['workspace_id' => $ws, 'audience_id' => $audience, 'company_id' => $this->absorbee, 'contact_id' => $this->seule]);
    $this->collecte = (int) DB::table('scraper_runs')->insertGetId(['workspace_id' => $ws, 'source' => 'zz', 'status' => 'pending', 'company_id' => $this->absorbee]);
    $this->media = (int) DB::table('media')->insertGetId(['workspace_id' => $ws, 'name' => 'ZZ Média', 'media_type' => 'blog', 'company_id' => $this->absorbee]);
    $this->journaliste = (int) DB::table('journalists')->insertGetId(['workspace_id' => $ws, 'media_id' => $this->media, 'company_id' => $this->absorbee, 'last_name' => 'ZZ Journaliste']);
    $this->praticien = (int) DB::table('health_practitioners')->insertGetId(['workspace_id' => $ws, 'company_id' => $this->absorbee, 'nom' => 'ZZ Praticien', 'rpps' => '90000000001']);
    $this->personne = (int) DB::table('personnes')->insertGetId(['workspace_id' => $ws, 'company_id' => $this->absorbee, 'person_key' => hash('sha256', 'zz-personne'), 'premiere_source' => 'newsletter', 'premiere_source_at' => now(), 'legal_basis' => 'consent']);

    $this->paire = (int) DB::table('duplicate_flags')->insertGetId([
        'workspace_id' => $ws, 'entity_type' => 'company', 'entity_a_id' => $this->garde, 'entity_b_id' => $this->absorbee,
        'similarity' => 0.99, 'motif' => Rapprochement::NOM_CP_SITE, 'fusion_auto' => true, 'detected_at' => now(),
    ]);
});

function dfFusionner(string $ws, int $garde, int $absorbee, string $mode = FusionFiches::MODE_MANUEL, ?int $paire = null, string $motif = Rapprochement::NOM_CP_SITE): int
{
    return WorkspaceContext::run($ws, fn (): int => app(FusionFiches::class)->fusionner($ws, $garde, $absorbee, $motif, $mode, $paire, null, 'test'));
}

/** @return array<string, int> */
function dfAnnuler(string $ws, int $fusion): array
{
    return WorkspaceContext::run($ws, fn (): array => app(FusionFiches::class)->annuler($ws, $fusion, 'test'));
}

function dfRefus(callable $geste): ?string
{
    try {
        $geste();
    } catch (RefusFusion $r) {
        return $r->raison;
    }

    return null;
}

/**
 * Une photographie de TOUT ce que la fusion touche — hors `updated_at` et
 * `quality_score` (recalculés par les déclencheurs de la base).
 */
function dfPhoto(string $ws): string
{
    $photo = [];
    $tables = [
        'companies' => ['id', 'deleted_at', 'email_generic', 'phone', 'website', 'linkedin_url', 'first_info_at'],
        'contacts' => ['id', 'company_id', 'first_name', 'last_name', 'email', 'email_status', 'phone', 'linkedin_url', 'deleted_at'],
        'company_tag' => ['company_id', 'tag_id', 'assigned_by'],
        'event_organizers' => ['event_id', 'company_id'],
        'federations' => ['company_id', 'parent_company_id'],
        'activities' => ['id', 'subject_type', 'subject_id'],
        'deals' => ['id', 'company_id'],
        'audience_members' => ['id', 'company_id', 'contact_id'],
        'scraper_runs' => ['id', 'company_id'],
        'media' => ['id', 'company_id'],
        'journalists' => ['id', 'company_id'],
        'health_practitioners' => ['id', 'company_id'],
        'personnes' => ['id', 'company_id'],
        'duplicate_flags' => ['id', 'reviewed_at', 'resolution'],
        'business_events' => ['id', 'resource_type', 'resource_id'],
    ];
    foreach ($tables as $table => $colonnes) {
        $photo[$table] = DB::table($table)->where('workspace_id', $ws)->orderBy($colonnes[0])->orderBy($colonnes[1])->get($colonnes)->map(fn ($l) => (array) $l)->all();
    }

    return json_encode($photo, JSON_THROW_ON_ERROR);
}

test('la fusion rattache TOUT à la fiche gardée et met l absorbée à la corbeille, sans rien supprimer', function () {
    $contactsAvant = DB::table('contacts')->where('workspace_id', $this->ws)->count();
    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee, FusionFiches::MODE_MANUEL, $this->paire);

    $absorbee = DB::table('companies')->where('id', $this->absorbee)->first();
    expect($fusion)->toBeGreaterThan(0)
        // À la corbeille, jamais supprimée.
        ->and($absorbee)->not->toBeNull()
        ->and($absorbee->deleted_at)->not->toBeNull()
        // Aucune personne supprimée.
        ->and(DB::table('contacts')->where('workspace_id', $this->ws)->count())->toBe($contactsAvant)
        ->and(DB::table('contacts')->where('id', $this->seule)->value('company_id'))->toBe($this->garde)
        // L'homonyme reste sur la fiche absorbée ; le sien reçoit l'e-mail qu'il n'avait pas, garde son téléphone.
        ->and(DB::table('contacts')->where('id', $this->jumelleAbsorbee)->value('company_id'))->toBe($this->absorbee)
        ->and(DB::table('contacts')->where('id', $this->jumelleGarde)->value('email'))->toBe('zed@zz-omega.example.invalid')
        ->and(DB::table('contacts')->where('id', $this->jumelleGarde)->value('email_status'))->toBe('valid')
        ->and(DB::table('contacts')->where('id', $this->jumelleGarde)->value('phone'))->toBe('+33 6 00 00 00 11')
        // Étiquettes, protection comprise : la gardée en HÉRITE.
        ->and(F::slugs($this->garde))->toBe([FichesProtegees::TAG_ORGANISATEURS, 'zz-commun', 'zz-seulement-absorbee'])
        ->and(FichesProtegees::estProtegee($this->garde))->toBeTrue()
        // Événements, sans doubler le lien commun.
        ->and(DB::table('event_organizers')->where('company_id', $this->garde)->orderBy('event_id')->pluck('event_id')->all())->toBe([$this->evtSeul, $this->evtCommun])
        // Fédération et antenne.
        ->and(DB::table('federations')->where('company_id', $this->garde)->exists())->toBeTrue()
        ->and(DB::table('federations')->where('company_id', $this->antenne)->value('parent_company_id'))->toBe($this->garde)
        // Tout le reste.
        ->and(DB::table('activities')->where('id', $this->activite)->value('subject_id'))->toBe($this->garde)
        ->and(DB::table('business_events')->where('id', $this->evenementMetier)->value('resource_id'))->toBe((string) $this->garde)
        ->and(DB::table('deals')->where('id', $this->deal)->value('company_id'))->toBe($this->garde)
        ->and(DB::table('audience_members')->where('id', $this->membre)->value('company_id'))->toBe($this->garde)
        ->and(DB::table('scraper_runs')->where('id', $this->collecte)->value('company_id'))->toBe($this->garde)
        ->and(DB::table('media')->where('id', $this->media)->value('company_id'))->toBe($this->garde)
        ->and(DB::table('journalists')->where('id', $this->journaliste)->value('company_id'))->toBe($this->garde)
        ->and(DB::table('health_practitioners')->where('id', $this->praticien)->value('company_id'))->toBe($this->garde)
        ->and(DB::table('personnes')->where('id', $this->personne)->value('company_id'))->toBe($this->garde)
        // Les coordonnées que la gardée n'avait pas.
        ->and(DB::table('companies')->where('id', $this->garde)->value('email_generic'))->toBe('contact@zz-omega.example.invalid')
        ->and(DB::table('companies')->where('id', $this->garde)->value('phone'))->toBe('+33 4 00 00 00 10')
        // La paire est traitée, le journal écrit.
        ->and(DB::table('duplicate_flags')->where('id', $this->paire)->value('resolution'))->toBe('merge')
        ->and(DB::table('fusions_fiches')->where('id', $fusion)->value('absorbee_id'))->toBe($this->absorbee);
});

test('le journal ne garde AUCUNE coordonnée : des identifiants et des empreintes', function () {
    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee);

    $journal = (string) DB::table('fusions_fiches')->where('id', $fusion)->value('journal');
    expect($journal)->not->toContain('zz-omega.example.invalid')
        ->and($journal)->not->toContain('00 00 00 10')
        ->and($journal)->not->toContain('@')
        // S1 — ni en clair, ni par une empreinte NON SALÉE qu'on retrouverait
        // en essayant des adresses connues : l'empreinte est salée par la clé
        // de la base (`doublons_empreinte`).
        ->and($journal)->not->toContain(hash('sha256', 'contact@zz-omega.example.invalid'))
        // Les empreintes vivent À PART, illisibles par le rôle applicatif.
        ->and($journal)->not->toContain(F::empreinteAdresse('contact@zz-omega.example.invalid'))
        ->and(DB::table('fusions_empreintes')->where('fusion_id', $fusion)->where('chemin', 'champs.email_generic')->value('empreinte'))
        ->toBe(F::empreinteAdresse('contact@zz-omega.example.invalid'));
});

test('l annulation remet la base EXACTEMENT dans son état d avant', function () {
    $avant = dfPhoto($this->ws);
    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee, FusionFiches::MODE_MANUEL, $this->paire);
    expect(dfPhoto($this->ws))->not->toBe($avant);

    $bilan = dfAnnuler($this->ws, $fusion);

    expect(dfPhoto($this->ws))->toBe($avant)
        ->and($bilan['non_retrouves'])->toBe(0)
        ->and($bilan['champs_modifies_depuis'])->toBe(0)
        ->and(FichesProtegees::estProtegee($this->garde))->toBeFalse()
        ->and(FichesProtegees::estProtegee($this->absorbee))->toBeTrue()
        ->and(DB::table('fusions_fiches')->where('id', $fusion)->value('annulee_at'))->not->toBeNull()
        // Deux fois : refusé.
        ->and(dfRefus(fn () => dfAnnuler($this->ws, $fusion)))->toBe('deja_annulee');
});

test('annuler par la commande ; une valeur changée depuis la fusion est laissée, et comptée', function () {
    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee);
    DB::table('companies')->where('id', $this->garde)->update(['phone' => '+33 4 99 99 99 99']);

    $code = Artisan::call('crm:doublons:fusionner', ['--workspace' => $this->ws, '--annuler' => (string) $fusion]);
    $sortie = Artisan::output();

    expect($code)->toBe(0)
        ->and(F::compteur($sortie, 'champs_modifies_depuis'))->toBe(1)
        ->and(DB::table('companies')->where('id', $this->garde)->value('phone'))->toBe('+33 4 99 99 99 99')
        ->and(DB::table('companies')->where('id', $this->garde)->value('email_generic'))->toBeNull()
        ->and(DB::table('companies')->where('id', $this->absorbee)->value('deleted_at'))->toBeNull();
});

test('l annulation est refusée si la fiche absorbée a quitté la corbeille entre-temps', function () {
    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee);
    DB::table('companies')->where('id', $this->absorbee)->update(['deleted_at' => null]);
    $avant = dfPhoto($this->ws);

    expect(dfRefus(fn () => dfAnnuler($this->ws, $fusion)))->toBe('absorbee_modifiee')
        ->and(dfPhoto($this->ws))->toBe($avant);
});

test('la base REFUSE de supprimer en dur une fiche absorbée ; TÉMOIN : après annulation, elle le permet', function () {
    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee);
    // La protection est passée à la fiche gardée : seul le verrou des fusions tient l'absorbée.
    expect(FichesProtegees::estProtegee($this->absorbee))->toBeFalse();

    $refus = null;
    DB::beginTransaction();
    try {
        DB::table('companies')->where('id', $this->absorbee)->delete();
    } catch (QueryException $e) {
        $refus = $e->getMessage();
    }
    DB::rollBack();
    expect($refus)->toContain('fiche_absorbee');

    // S4 — la fiche GARDÉE non plus : elle porte les personnes rattachées.
    $refusGarde = null;
    DB::beginTransaction();
    try {
        DB::table('companies')->where('id', $this->garde)->delete();
    } catch (QueryException $e) {
        $refusGarde = $e->getMessage();
    }
    DB::rollBack();
    expect($refusGarde)->toContain('fiche_absorbee');

    dfAnnuler($this->ws, $fusion);
    DB::table('company_tag')->where('company_id', $this->absorbee)->delete();
    DB::table('federations')->where('company_id', $this->antenne)->update(['parent_company_id' => null]);
    expect(DB::table('companies')->where('id', $this->absorbee)->delete())->toBe(1);
});

test('les purges écartent les fiches absorbée ET gardée au lieu d échouer', function () {
    // Sans protection : seule la fusion en cours doit les écarter.
    DB::table('company_tag')->where('company_id', $this->absorbee)->delete();
    dfFusionner($this->ws, $this->garde, $this->absorbee);
    expect(FichesProtegees::estProtegee($this->garde))->toBeFalse();
    // Une fiche ordinaire sans forme juridique : la purge la supprime (témoin).
    $temoin = F::fiche($this->ws, 'ZZ Temoin purge');

    $code = Artisan::call('prospection:purge-non-commercial', ['--force' => true]);

    expect($code)->toBe(0)
        ->and(DB::table('companies')->where('id', $temoin)->exists())->toBeFalse()
        ->and(DB::table('companies')->where('id', $this->absorbee)->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('id', $this->jumelleAbsorbee)->exists())->toBeTrue()
        // S4 — la fiche gardée (sans forme juridique ici) et les personnes
        // qu'elle a reçues restent : l'annulation reste possible.
        ->and(DB::table('companies')->where('id', $this->garde)->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('id', $this->seule)->value('company_id'))->toBe($this->garde);
});

test('S4 — la purge de rétention RGPD épargne les personnes d une fiche gardée par une fusion en cours', function () {
    config(['crm.purges_enabled' => true]);
    // Sans protection : seule la fusion en cours doit les épargner.
    DB::table('company_tag')->where('company_id', $this->absorbee)->delete();
    dfFusionner($this->ws, $this->garde, $this->absorbee);
    DB::table('contacts')->where('workspace_id', $this->ws)->update(['created_at' => now()->subYears(4), 'legal_basis' => 'legitimate_interest_b2b']);
    // TÉMOIN : une personne d'une fiche ordinaire, aussi ancienne, est purgée.
    $ordinaire = F::fiche($this->ws, 'ZZ Ordinaire purge');
    $temoin = F::contact($this->ws, $ordinaire, 'Zut', 'ZZTEMOINPURGE', ['created_at' => now()->subYears(4), 'legal_basis' => 'legitimate_interest_b2b']);

    Artisan::call('rgpd:purge-business-prospects');

    expect(DB::table('contacts')->where('id', $temoin)->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('id', $this->seule)->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('id', $this->jumelleGarde)->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('id', $this->jumelleAbsorbee)->exists())->toBeTrue();
});

/** Un refus : la bonne raison, et RIEN d'écrit. */
function dfRefusSansEcriture(string $ws, string $raison, int $garde, int $absorbee, string $mode = FusionFiches::MODE_MANUEL, ?int $paire = null): void
{
    $avant = dfPhoto($ws);

    expect(dfRefus(fn () => dfFusionner($ws, $garde, $absorbee, $mode, $paire)))->toBe($raison)
        ->and(dfPhoto($ws))->toBe($avant)
        ->and(DB::table('fusions_fiches')->where('workspace_id', $ws)->count())->toBe(0);
}

test('REFUS — deux SIREN différents : jamais fusionnées', function () {
    dfRefusSansEcriture($this->ws, 'sirens_differents', $this->garde, F::fiche($this->ws, 'ZZ Omega'));
});

test('REFUS — deux fédérations', function () {
    DB::table('federations')->insert(['company_id' => $this->garde, 'workspace_id' => $this->ws, 'famille' => 'ordre', 'niveau' => 'national', 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact']);
    dfRefusSansEcriture($this->ws, 'deux_federations', $this->garde, $this->absorbee);
});

test('REFUS — l une est la tête de réseau de l autre', function () {
    DB::table('federations')->where('company_id', $this->absorbee)->delete();
    DB::table('federations')->where('company_id', $this->antenne)->update(['parent_company_id' => null]);
    DB::table('federations')->insert(['company_id' => $this->absorbee, 'workspace_id' => $this->ws, 'famille' => 'ordre', 'niveau' => 'departemental', 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact', 'parent_company_id' => $this->garde]);
    dfRefusSansEcriture($this->ws, 'lien_de_reseau_entre_les_deux', $this->garde, $this->absorbee);
});

test('REFUS — homonymes aux e-mails différents', function () {
    DB::table('contacts')->where('id', $this->jumelleGarde)->update(['email' => 'autre@zz-omega.example.invalid']);
    dfRefusSansEcriture($this->ws, 'personnes_homonymes_en_conflit', $this->garde, $this->absorbee);
});

test('REFUS — homonymes aux téléphones différents', function () {
    DB::table('contacts')->where('id', $this->jumelleGarde)->update(['phone' => '+33 6 99 99 99 99']);
    dfRefusSansEcriture($this->ws, 'personnes_homonymes_en_conflit', $this->garde, $this->absorbee);
});

test('REFUS — fusion automatique dont la preuve ne tient plus (site changé)', function () {
    DB::table('companies')->where('id', $this->absorbee)->update(['website' => 'https://zz-ailleurs.example.invalid']);
    dfRefusSansEcriture($this->ws, 'preuve_insuffisante', $this->garde, $this->absorbee, FusionFiches::MODE_AUTO, $this->paire);
});

test('TÉMOIN — la même fusion automatique passe quand la preuve tient', function () {
    expect(dfFusionner($this->ws, $this->garde, $this->absorbee, FusionFiches::MODE_AUTO, $this->paire))->toBeGreaterThan(0);
});

test('REFUS — paire déjà écartée', function () {
    DB::table('duplicate_flags')->where('id', $this->paire)->update(['reviewed_at' => now(), 'resolution' => 'keep_both']);
    dfRefusSansEcriture($this->ws, 'deja_traitee', $this->garde, $this->absorbee, FusionFiches::MODE_MANUEL, $this->paire);
});

test('REFUS — la paire désigne d autres fiches', function () {
    // Deux fiches qui PEUVENT être fusionnées (un seul SIREN), mais pas cette paire-là.
    dfRefusSansEcriture($this->ws, 'paire_inconnue', $this->antenne, $this->absorbee, FusionFiches::MODE_MANUEL, $this->paire);
});

test('REFUS — une fiche à la corbeille', function () {
    DB::table('companies')->where('id', $this->garde)->update(['deleted_at' => now()]);
    dfRefusSansEcriture($this->ws, 'fiche_a_la_corbeille', $this->garde, $this->absorbee);
});

test('REFUS — une fiche d un autre espace', function () {
    dfRefusSansEcriture($this->ws, 'fiche_introuvable', F::fiche(F::espace(), 'ZZ Omega'), $this->absorbee);
});

test('la commande ne fusionne que les paires CERTAINES, re-vérifiées ; l essai à blanc n écrit rien et dit le même bilan', function () {
    // Une seconde paire marquée certaine, mais dont la preuve ne tient plus.
    $autre = F::fiche($this->ws, 'ZZ Sigma', ['postcode' => '69011', 'website' => 'https://zz-sigma.example.invalid']);
    $autreEvt = F::sansSiren($this->ws, 'ZZ Sigma', ['postcode' => '69012', 'website' => 'https://zz-sigma.example.invalid']);
    DB::table('duplicate_flags')->insert([
        'workspace_id' => $this->ws, 'entity_type' => 'company', 'entity_a_id' => $autre, 'entity_b_id' => $autreEvt,
        'similarity' => 0.99, 'motif' => Rapprochement::NOM_CP_SITE, 'fusion_auto' => true,
    ]);
    // Une paire à vérifier : jamais fusionnée par la commande.
    $b = F::fiche($this->ws, 'ZZ Tau', ['postcode' => '69013']);
    $bEvt = F::sansSiren($this->ws, 'ZZ Tau', ['postcode' => '69013']);
    DB::table('duplicate_flags')->insert([
        'workspace_id' => $this->ws, 'entity_type' => 'company', 'entity_a_id' => $b, 'entity_b_id' => $bEvt,
        'similarity' => 0.85, 'motif' => Rapprochement::NOM_CP, 'fusion_auto' => false,
    ]);
    $avant = dfPhoto($this->ws);

    Artisan::call('crm:doublons:fusionner', ['--workspace' => $this->ws, '--dry-run' => true]);
    $blanc = Artisan::output();
    expect(dfPhoto($this->ws))->toBe($avant)
        ->and(DB::table('fusions_fiches')->where('workspace_id', $this->ws)->count())->toBe(0);

    Artisan::call('crm:doublons:fusionner', ['--workspace' => $this->ws]);
    $reel = Artisan::output();

    expect(F::bilan($blanc))->toBe(F::bilan($reel))
        ->and(F::compteur($reel, 'fusionnees'))->toBe(1)
        ->and(F::compteur($reel, 'refusees'))->toBe(1)
        ->and(F::compteur($reel, 'refus_preuve_insuffisante'))->toBe(1)
        ->and(DB::table('companies')->where('id', $this->absorbee)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('companies')->where('id', $autreEvt)->value('deleted_at'))->toBeNull()
        ->and(DB::table('companies')->where('id', $bEvt)->value('deleted_at'))->toBeNull()
        ->and($reel)->toMatch('/Verrous tenus au plus pendant une fusion : \d+/');
});

test('une paire dont la fusion a été ANNULÉE ne repart jamais seule', function () {
    Artisan::call('crm:doublons:fusionner', ['--workspace' => $this->ws]);
    $fusion = (int) DB::table('fusions_fiches')->where('workspace_id', $this->ws)->value('id');
    dfAnnuler($this->ws, $fusion);
    expect(DB::table('duplicate_flags')->where('id', $this->paire)->value('reviewed_at'))->toBeNull();

    Artisan::call('crm:doublons:fusionner', ['--workspace' => $this->ws]);
    $sortie = Artisan::output();

    expect(F::compteur($sortie, 'paires_lues'))->toBe(0)
        ->and(DB::table('companies')->where('id', $this->absorbee)->value('deleted_at'))->toBeNull()
        // Mais un humain peut toujours la fusionner depuis l'écran.
        ->and(dfFusionner($this->ws, $this->garde, $this->absorbee, FusionFiches::MODE_MANUEL, $this->paire))->toBeGreaterThan(0);
});

test('GARDE DE COUVERTURE — toute clé étrangère vers companies est rattachée par la fusion', function () {
    $references = DB::table('pg_constraint as k')
        ->join('pg_class as t', 't.oid', '=', 'k.conrelid')
        ->join('pg_class as r', 'r.oid', '=', 'k.confrelid')
        ->join('pg_attribute as a', function ($j) {
            $j->on('a.attrelid', '=', 'k.conrelid')->whereRaw('a.attnum = ANY(k.conkey)');
        })
        ->where('k.contype', 'f')
        ->where('r.relname', 'companies')
        ->selectRaw("t.relname || '.' || a.attname AS ref")
        ->pluck('ref')
        ->map(fn ($r): string => (string) $r)
        ->unique()
        ->sort()
        ->values()
        ->all();

    // TÉMOIN : le catalogue répond (sinon la garde serait verte sur du vide).
    expect($references)->toContain('contacts.company_id');
    expect($references)->toBe(FusionFiches::REFERENCES);
});

test('GARDE DE COUVERTURE — les références SANS clé étrangère (`*_type = company`) sont toutes connues', function () {
    // Le catalogue ne voit pas une référence polymorphe : on balaye le code
    // qui ÉCRIT ou LIT « <préfixe>_type = 'company' ».
    $prefixes = [];
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $fichier) {
        if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($fichier->getPathname());
        preg_match_all("/'(\\w+)_type'\\s*=>\\s*'company'|\\b(\\w+)_type\\s*=\\s*'company'/", $source, $m, PREG_SET_ORDER);
        foreach ($m as $trouve) {
            $prefixes[($trouve[1] ?? '') !== '' ? $trouve[1] : ($trouve[2] ?? '')] = true;
        }
    }
    $prefixes = array_keys($prefixes);
    sort($prefixes);

    // `entity` : la file des doublons elle-même (`duplicate_flags`), qui
    // désigne des PAIRES et n'a pas à être rattachée.
    $connus = ['entity' => null];
    foreach (array_keys(FusionFiches::REFERENCES_SANS_CLE) as $colonne) {
        [$table] = explode('.', $colonne);
        $connus[$table === 'activities' ? 'subject' : 'resource'] = $colonne;
    }
    $attendus = array_keys($connus);
    sort($attendus);

    // TÉMOIN : le balayage voit bien les écritures connues.
    expect($prefixes)->toContain('subject')->toContain('resource');
    expect($prefixes)->toBe($attendus);
    expect(array_keys(FusionFiches::REFERENCES_SANS_CLE))->toBe(['activities.subject_id', 'business_events.resource_id']);
});

test('E3 — une personne SUPPRIMÉE de la fiche absorbée ne transmet rien et ne bloque rien', function () {
    DB::table('contacts')->where('id', $this->jumelleAbsorbee)->update(['deleted_at' => now(), 'email' => 'autre@zz-omega.example.invalid']);

    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee);

    expect($fusion)->toBeGreaterThan(0)
        ->and(DB::table('contacts')->where('id', $this->jumelleGarde)->value('email'))->toBeNull()
        ->and(DB::table('contacts')->where('id', $this->jumelleAbsorbee)->value('company_id'))->toBe($this->absorbee);
});

test('E3 — une personne VIVANTE dont l homonyme de la fiche gardée est supprimé : fusion refusée', function () {
    DB::table('contacts')->where('id', $this->jumelleGarde)->update(['deleted_at' => now()]);

    dfRefusSansEcriture($this->ws, 'homonyme_supprime_sur_la_fiche_gardee', $this->garde, $this->absorbee);
});

test('E1/E2 — la preuve désigne DEUX fiches INSEE : aucune fusion automatique, à blanc comme en vrai', function () {
    // X sans SIREN : même nom, même code postal, même site que A et B, deux
    // fiches INSEE aux SIREN différents. Des paires marquées « certaines »
    // (détection d'hier, données changées depuis) ne doivent pas partir.
    $a = F::fiche($this->ws, 'ZZ Ambigu', ['postcode' => '69020', 'website' => 'https://zz-ambigu.example.invalid']);
    $b = F::fiche($this->ws, 'ZZ Ambigu', ['postcode' => '69020', 'website' => 'https://www.zz-ambigu.example.invalid']);
    $x = F::sansSiren($this->ws, 'ZZ Ambigu', ['postcode' => '69020', 'website' => 'zz-ambigu.example.invalid']);
    DB::table('duplicate_flags')->where('id', $this->paire)->update(['fusion_auto' => false]);
    foreach ([$a, $b] as $garde) {
        DB::table('duplicate_flags')->insert([
            'workspace_id' => $this->ws, 'entity_type' => 'company', 'entity_a_id' => $garde, 'entity_b_id' => $x,
            'similarity' => 0.99, 'motif' => Rapprochement::NOM_CP_SITE, 'fusion_auto' => true,
        ]);
    }

    Artisan::call('crm:doublons:fusionner', ['--workspace' => $this->ws, '--dry-run' => true]);
    $blanc = Artisan::output();
    Artisan::call('crm:doublons:fusionner', ['--workspace' => $this->ws]);
    $reel = Artisan::output();

    expect(F::bilan($blanc))->toBe(F::bilan($reel))
        ->and(F::compteur($reel, 'fusionnees'))->toBe(0)
        ->and(F::compteur($reel, 'refus_preuve_ambigue'))->toBe(2)
        ->and(DB::table('companies')->where('id', $x)->value('deleted_at'))->toBeNull()
        ->and(DB::table('duplicate_flags')->where('entity_b_id', $x)->whereNull('reviewed_at')->count())->toBe(2);
    // TÉMOIN : une fusion MANUELLE reste possible (un humain choisit).
    expect(dfFusionner($this->ws, $a, $x))->toBeGreaterThan(0);
});

test('E1 — plus de 50 homonymes à SIREN : l unicité ne se vérifie pas, la fusion automatique est refusée (preuve_ambigue)', function () {
    // 51 homonymes qui ne satisfont PAS la preuve (autre code postal) : la
    // recherche s'arrête avant d'avoir tout vu, elle ne conclut donc rien.
    for ($i = 0; $i < 51; $i++) {
        F::fiche($this->ws, 'ZZ Omega', ['postcode' => '69099']);
    }

    dfRefusSansEcriture($this->ws, 'preuve_ambigue', $this->garde, $this->absorbee, FusionFiches::MODE_AUTO, $this->paire);
});

test('TÉMOIN — à 50 homonymes non concluants, la fusion automatique passe', function () {
    for ($i = 0; $i < 50; $i++) {
        F::fiche($this->ws, 'ZZ Omega', ['postcode' => '69099']);
    }

    expect(dfFusionner($this->ws, $this->garde, $this->absorbee, FusionFiches::MODE_AUTO, $this->paire))->toBeGreaterThan(0);
});

test('IDCC / OPCO (O14) : la ligne de l absorbée passe à la gardée qui n en a pas, et revient à l annulation', function () {
    DB::table('companies_opco')->insert([
        'workspace_id' => $this->ws, 'company_id' => $this->absorbee, 'siret' => null,
        'idcc' => '1486', 'opco' => 'atlas', 'opco_gestion' => null, 'source' => 'saisie', 'releve_le' => null,
    ]);
    $ligne = (int) DB::table('companies_opco')->where('company_id', $this->absorbee)->value('id');

    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee, FusionFiches::MODE_MANUEL, $this->paire);
    expect(DB::table('companies_opco')->where('id', $ligne)->value('company_id'))->toBe($this->garde);

    dfAnnuler($this->ws, $fusion);
    expect(DB::table('companies_opco')->where('id', $ligne)->value('company_id'))->toBe($this->absorbee)
        ->and(DB::table('companies_opco')->where('workspace_id', $this->ws)->count())->toBe(1);
});

test('IDCC / OPCO (O14) : la gardée garde la sienne, celle de l absorbée reste sur l absorbée (rien supprimé), le conflit est tracé', function () {
    foreach ([[$this->garde, '1486'], [$this->absorbee, '2216']] as [$id, $idcc]) {
        DB::table('companies_opco')->insert([
            'workspace_id' => $this->ws, 'company_id' => $id, 'siret' => null,
            'idcc' => $idcc, 'opco' => 'atlas', 'opco_gestion' => null, 'source' => 'saisie', 'releve_le' => null,
        ]);
    }
    $ligneAbsorbee = (int) DB::table('companies_opco')->where('company_id', $this->absorbee)->value('id');

    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee, FusionFiches::MODE_MANUEL, $this->paire);

    expect(DB::table('companies_opco')->where('company_id', $this->garde)->value('idcc'))->toBe('1486')
        ->and(DB::table('companies_opco')->where('company_id', $this->absorbee)->value('idcc'))->toBe('2216')
        ->and(DB::table('companies_opco')->where('workspace_id', $this->ws)->count())->toBe(2);
    $journal = json_decode((string) DB::table('fusions_fiches')->where('id', $fusion)->value('journal'), true);
    expect($journal['deplacements']['companies_opco_restees'])->toBe([$ligneAbsorbee])
        ->and($journal['deplacements']['companies_opco_echanges'])->toBe([]);

    dfAnnuler($this->ws, $fusion);
    expect(DB::table('companies_opco')->where('company_id', $this->garde)->value('idcc'))->toBe('1486')
        ->and(DB::table('companies_opco')->where('company_id', $this->absorbee)->value('idcc'))->toBe('2216')
        ->and(DB::table('companies_opco')->where('workspace_id', $this->ws)->count())->toBe(2);
});

test('IDCC / OPCO (O14) : gardée siro, absorbée saisie — la SAISIE passe sur la gardée, et revient à l annulation', function () {
    DB::table('companies_opco')->insert([
        'workspace_id' => $this->ws, 'company_id' => $this->garde, 'siret' => '00000000000017',
        'idcc' => '1486', 'opco' => 'atlas', 'opco_gestion' => 'atlas', 'source' => 'siro', 'releve_le' => '2026-07-01',
    ]);
    DB::table('companies_opco')->insert([
        'workspace_id' => $this->ws, 'company_id' => $this->absorbee, 'siret' => null,
        'idcc' => '2216', 'opco' => 'akto', 'opco_gestion' => null, 'source' => 'saisie', 'releve_le' => null,
    ]);
    $ligneGarde = (int) DB::table('companies_opco')->where('company_id', $this->garde)->value('id');
    $ligneAbsorbee = (int) DB::table('companies_opco')->where('company_id', $this->absorbee)->value('id');
    $lire = fn (int $company): array => (array) DB::table('companies_opco')->where('company_id', $company)
        ->first(['id', 'idcc', 'opco', 'source', 'siret']);

    $fusion = dfFusionner($this->ws, $this->garde, $this->absorbee, FusionFiches::MODE_MANUEL, $this->paire);

    expect($lire($this->garde))->toMatchArray(['id' => $ligneGarde, 'idcc' => '2216', 'opco' => 'akto', 'source' => 'saisie', 'siret' => null])
        ->and($lire($this->absorbee))->toMatchArray(['id' => $ligneAbsorbee, 'idcc' => '1486', 'opco' => 'atlas', 'source' => 'siro', 'siret' => '00000000000017'])
        ->and(DB::table('companies_opco')->where('workspace_id', $this->ws)->count())->toBe(2);
    $journal = json_decode((string) DB::table('fusions_fiches')->where('id', $fusion)->value('journal'), true);
    expect($journal['deplacements']['companies_opco_restees'])->toBe([$ligneAbsorbee])
        ->and($journal['deplacements']['companies_opco_echanges'][0]['garde'])->toBe($ligneGarde)
        ->and($journal['deplacements']['companies_opco_echanges'][0]['absorbee'])->toBe($ligneAbsorbee);

    dfAnnuler($this->ws, $fusion);

    expect($lire($this->garde))->toMatchArray(['id' => $ligneGarde, 'idcc' => '1486', 'opco' => 'atlas', 'source' => 'siro', 'siret' => '00000000000017'])
        ->and($lire($this->absorbee))->toMatchArray(['id' => $ligneAbsorbee, 'idcc' => '2216', 'opco' => 'akto', 'source' => 'saisie', 'siret' => null])
        ->and(DB::table('companies_opco')->where('workspace_id', $this->ws)->count())->toBe(2);
});

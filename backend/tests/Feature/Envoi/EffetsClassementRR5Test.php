<?php

namespace Tests\Feature\Envoi;

use App\Crm\Taches\MesTaches;
use App\Envoi\Reponses\ServiceReponses;
use App\Envoi\Reponses\StockerReponse;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\DoublonsFixtures as F;

/**
 * RR-5A — le classement HUMAIN d'une réponse arrête la séquence et crée la tâche CRM prévue.
 * Routes réelles ; données fictives (example.test), aucun réseau, aucun envoi.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

/** Mode « app » = rôle applicatif sous RLS, « proprio » = propriétaire (seul le filtre d'espace protège). */
function ecl5Sous(string $mode, string $ws, \Closure $f): mixed
{
    WorkspaceContext::set($ws);
    if ($mode === 'app') {
        DB::statement('SET ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/**
 * Entreprise, deux contacts (le premier à l'adresse de l'expéditeur), séquence, inscriptions, et une réponse « à rattacher ».
 *
 * @return array{entreprise: int, ins: string, collegue: string, reponse: int, evenement: int}
 */
function ecl5Monde(string $ws, string $domaine = 'societe-a'): array
{
    $adresse1 = 'zoe.martin@' . $domaine . '.example.test';

    return WorkspaceContext::run($ws, function () use ($ws, $domaine, $adresse1): array {
        $maintenant = new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('Europe/Paris'));
        $sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $sid, 'rang' => 2, 'delai_jours_ouvres' => 3, 'sujet' => 'Relance', 'corps' => 'Corps']);
        $e = F::fiche($ws, 'ZZ Entreprise ' . $domaine);
        $ids = [];
        foreach ([$adresse1, 'paul.durand@' . $domaine . '.example.test'] as $adresse) {
            $c = (int) DB::table('contacts')->insertGetId([
                'workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Zoe', 'last_name' => 'T-' . substr(md5($adresse), 0, 8),
                'email' => $adresse, 'email_status' => 'valid', 'created_at' => now(),
            ]);
            $ids[] = (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $sid, $c, $maintenant);
        }
        $cle = hash('sha256', Str::random(20));
        $evenement = DB::table('envoi_evenements')->insertGetId([
            'workspace_id' => $ws, 'boite' => 'b@example.test', 'uid_validity' => 1, 'uid' => random_int(1, 1_000_000),
            'empreinte_message' => $cle, 'classe' => 'reponse_humaine', 'statut' => 'a_rattacher',
            'empreinte_expediteur' => ListeSuppression::empreinte($adresse1), 'recu_le' => now(),
        ]);
        app(StockerReponse::class)->enregistrer($ws, $cle, 'Objet de test', 'Extrait secret de la reponse.', $adresse1, 'reponse_humaine');

        return [
            'entreprise' => $e, 'ins' => $ids[0], 'collegue' => $ids[1], 'evenement' => (int) $evenement,
            'reponse' => (int) DB::table('envoi_reponses')->where('evenement_id', $evenement)->value('id'),
        ];
    });
}

/** Rattache la réponse à l'envoi de l'inscription (registre local, clé « seq-<inscription>-1 »). */
function ecl5Rattache(string $ws, array $m): void
{
    WorkspaceContext::run($ws, function () use ($ws, $m): void {
        DB::table('envoi_messages')->insert(['workspace_id' => $ws, 'inscription_id' => $m['ins'], 'etape' => 1, 'statut' => 'envoye', 'message_id' => 'm-' . Str::uuid() . '@example.test']);
        $uuid = (string) DB::table('envoi_injections_locales')->insertGetId(['workspace_id' => $ws, 'cle_idempotence' => "seq-{$m['ins']}-1", 'adaptateur' => 'faux'], 'id_injection');
        DB::table('envoi_rattachements')->insert(['workspace_id' => $ws, 'evenement_id' => $m['evenement'], 'id_injection' => $uuid, 'empreinte_destinataire' => str_repeat('a', 64), 'decide_par' => 'test']);
    });
}

function ecl5Etat(string $ws, string $ins): array
{
    $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $ins)->first());

    return [$l->statut, $l->motif_arret, $l->prochaine_echeance];
}

function ecl5Taches(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('activities')->where('workspace_id', $ws)->where('kind', 'task')->orderBy('id')->get()->all());
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-17 09:00:00');
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
});

afterEach(fn () => Carbon::setTestNow());

test('C1 réponse rattachée, inscription déjà arrêtée : « intéressé » + creer_tache => tâche système sur l’entreprise, visible dans Mes tâches', function () {
    $m = ecl5Monde($this->ws->id);
    ecl5Rattache($this->ws->id, $m);
    WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_inscriptions')->where('id', $m['ins'])->update(['statut' => 'arretee', 'motif_arret' => 'reponse', 'arretee_le' => now()]));

    $r = $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$m['reponse']}/classer", ['classement' => 'interesse', 'creer_tache' => true])->assertOk();
    $taches = ecl5Taches($this->ws->id);
    expect($taches)->toHaveCount(1)
        ->and($taches[0]->user_id)->toBeNull()
        ->and($taches[0]->subject_type)->toBe('company')
        ->and((int) $taches[0]->subject_id)->toBe($m['entreprise'])
        ->and($taches[0]->title)->toBe('Rappeler : réponse intéressée')
        ->and($r->json('resume_crm.tache'))->toBe('Rappeler : réponse intéressée');
    // « Mes tâches » (G2) lit `user_id IS NULL` : la tâche système y figure pour n'importe quel utilisateur de l'espace.
    $mes = WorkspaceContext::run($this->ws->id, fn () => MesTaches::liste($this->ws->id, (string) Str::uuid(), CarbonImmutable::now()));
    expect(json_encode($mes, JSON_UNESCAPED_UNICODE))->toContain('Rappeler : réponse intéressée');
    // le collègue de l'entreprise est arrêté par la réponse
    expect(ecl5Etat($this->ws->id, $m['collegue'])[1])->toBe('entreprise_arretee');
});

test('C1b sans creer_tache, « intéressé » ne crée aucune tâche', function () {
    $m = ecl5Monde($this->ws->id);
    $r = $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$m['reponse']}/classer", ['classement' => 'interesse'])->assertOk();
    expect(ecl5Taches($this->ws->id))->toBeEmpty()->and($r->json('resume_crm.tache'))->toBeNull();
});

test('C2 réponse « à rattacher » : « pas maintenant » arrête l’inscription active, le collègue, et crée la tâche à J+90', function () {
    $m = ecl5Monde($this->ws->id);
    $r = $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$m['reponse']}/classer", ['classement' => 'pas_maintenant'])->assertOk();
    [$statut, $motif] = ecl5Etat($this->ws->id, $m['ins']);
    expect($statut)->toBe('arretee')->and($motif)->toBe('reponse')
        ->and(ecl5Etat($this->ws->id, $m['collegue'])[1])->toBe('entreprise_arretee');
    $t = ecl5Taches($this->ws->id);
    expect($t)->toHaveCount(1)->and($t[0]->title)->toBe('Recontacter (pas maintenant)')
        ->and(Carbon::parse($t[0]->due_at)->utc()->toDateTimeString())->toBe('2027-02-14 23:00:00') // 17/11/2026 + 90 jours, minuit Paris
        ->and($r->json('resume_crm.tache'))->toBe('Recontacter (pas maintenant)');
});

test('C3 « refus » arrête sans tâche ; « stop » et « question » ne touchent pas à l’arrêt de façon inattendue', function () {
    $m = ecl5Monde($this->ws->id);
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$m['reponse']}/classer", ['classement' => 'refus'])->assertOk();
    expect(ecl5Etat($this->ws->id, $m['ins'])[0])->toBe('arretee')->and(ecl5Taches($this->ws->id))->toBeEmpty();
});

test('C4 rejouer « intéressé » ne crée qu’une tâche ; corriger vers « pas maintenant » en ajoute une, sans rien supprimer', function () {
    $m = ecl5Monde($this->ws->id);
    $url = "/api/v1/envoi/reponses/{$m['reponse']}/classer";
    $this->actingAs($this->owner)->postJson($url, ['classement' => 'interesse', 'creer_tache' => true])->assertOk();
    $this->actingAs($this->owner)->postJson($url, ['classement' => 'interesse', 'creer_tache' => true])->assertOk();
    expect(ecl5Taches($this->ws->id))->toHaveCount(1);
    $this->actingAs($this->owner)->postJson($url, ['classement' => 'pas_maintenant'])->assertOk();
    $t = ecl5Taches($this->ws->id);
    expect($t)->toHaveCount(2)->and(array_map(fn ($x) => $x->title, $t))->toBe(['Rappeler : réponse intéressée', 'Recontacter (pas maintenant)']);
});

test('C5 une réponse d’un autre espace : 404, ni tâche ni arrêt ; l’entreprise de l’autre espace n’est jamais le sujet (app ET proprio)', function () {
    $autre = $this->espace(true);
    $b = ecl5Monde($autre->id);
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$b['reponse']}/classer", ['classement' => 'pas_maintenant'])->assertNotFound();
    foreach (['app', 'proprio'] as $mode) {
        expect(ecl5Sous($mode, $this->ws->id, fn () => app(ServiceReponses::class)->classer($this->ws->id, (string) Str::uuid(), $b['reponse'], 'pas_maintenant')))->toBeNull($mode);
    }
    expect(ecl5Etat($autre->id, $b['ins'])[0])->toBe('active')->and(ecl5Taches($autre->id))->toBeEmpty()->and(ecl5Taches($this->ws->id))->toBeEmpty();

    // L'espace B a lui aussi une entreprise et des inscriptions : le sujet de la tâche de A reste l'entreprise de A (FK composite + filtre d'espace).
    foreach (['app', 'proprio'] as $mode) {
        $m = ecl5Monde($this->ws->id, 'societe-' . $mode);
        $r = ecl5Sous($mode, $this->ws->id, fn () => app(ServiceReponses::class)->classer($this->ws->id, (string) Str::uuid(), $m['reponse'], 'pas_maintenant'));
        expect($r['resume_crm']['tache'])->toBe('Recontacter (pas maintenant)', $mode);
        $t = array_values(array_filter(ecl5Taches($this->ws->id), fn ($x) => $x->external_ref === "envoi:reponse:{$m['reponse']}:pas_maintenant"));
        expect($t)->toHaveCount(1)->and((int) $t[0]->subject_id)->toBe($m['entreprise'])->and((int) $t[0]->subject_id)->not->toBe($b['entreprise']);
    }
});

test('C6 ni adresse ni extrait dans la tâche', function () {
    $m = ecl5Monde($this->ws->id);
    $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$m['reponse']}/classer", ['classement' => 'pas_maintenant'])->assertOk();
    $t = ecl5Taches($this->ws->id)[0];
    expect($t->content)->toBeNull();
    $payload = (string) $t->payload;
    expect($payload)->not->toContain('@')->not->toContain('Extrait secret')->toContain('Réponse n° ' . $m['reponse']);
});

test('C3b « absence » repousse la relance de 7 jours : inscription restée active, aucune tâche, jamais avancée ni rouverte', function () {
    $m = ecl5Monde($this->ws->id);
    $maintenant = now();
    $avant = ecl5Etat($this->ws->id, $m['ins'])[2];
    // le collègue a déjà une échéance bien plus lointaine : elle ne doit jamais être avancée (GREATEST)
    $loin = $maintenant->copy()->addDays(30)->utc()->format('Y-m-d H:i:sP');
    WorkspaceContext::run($this->ws->id, fn () => DB::table('envoi_inscriptions')->where('id', $m['collegue'])->update(['prochaine_echeance' => $loin]));
    $loinLu = ecl5Etat($this->ws->id, $m['collegue'])[2];

    $r = $this->actingAs($this->owner)->postJson("/api/v1/envoi/reponses/{$m['reponse']}/classer", ['classement' => 'absence'])->assertOk();

    [$statut, $motif, $echeance] = ecl5Etat($this->ws->id, $m['ins']);
    expect($statut)->toBe('active')->and($motif)->toBeNull()
        ->and(Carbon::parse($echeance)->gte($maintenant->copy()->addDays(7)->subSecond()))->toBeTrue()
        ->and(Carbon::parse($echeance)->gt(Carbon::parse($avant)))->toBeTrue()
        ->and(ecl5Taches($this->ws->id))->toBeEmpty()
        ->and($r->json('resume_crm.tache'))->toBeNull();
    // l'inscription visée est celle de l'expéditeur seul ; l'échéance plus lointaine du collègue n'a pas bougé
    expect(ecl5Etat($this->ws->id, $m['collegue'])[0])->toBe('active')
        ->and(Carbon::parse(ecl5Etat($this->ws->id, $m['collegue'])[2])->equalTo(Carbon::parse($loinLu)))->toBeTrue();
});

<?php

/**
 * RR-5C (cahier docs/envoi/lots/D3-D6-cahier.md § 10) — le séquenceur s'arrête sur un rendez-vous pris ou une entreprise
 * devenue cliente. Séquenceur réel, faux serveur, horloge figée, aucun réseau. Adresses en `*.example.test`.
 * Les tests de cloisonnement tournent sous `pgsql_app` (RLS) ET sous le propriétaire (seul le filtre d'espace protège).
 */

use App\Crm\FichesProtegees;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\ComposeurSequence;
use App\Envoi\Sequence\DemandeComposition;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ParametresExpedition;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const RR5_DOMAINE = 'zz-envoi.example.test';

beforeEach(function () {
    $this->ws = F::espace('zz-rr5c');
    $this->lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($this->ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($this->ws, 'test-rr5c');
    WorkspaceContext::set($this->ws);
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $this->ws, 'nom' => RR5_DOMAINE, 'achete_le' => '2026-01-01',
        'dmarc_verifie_le' => $this->lundi->format('c'), 'spf_verifie_le' => $this->lundi->format('c'), 'dkim_verifie_le' => $this->lundi->format('c'),
    ]);
    $this->faux = new FauxServeurEnvoi;
    $this->seq = fn (): Sequenceur => new Sequenceur(
        new PasserelleLocale($this->faux, new DepotSocleDb, true, 300, fn () => $this->lundi, $this->ws, 300, fn () => WorkspaceContext::current()),
        new LecteurFicheDb,
        rr5Composeur(),
        new ParametresExpedition(RR5_DOMAINE, 'from@' . RR5_DOMAINE, 'ZZ Expediteur SAS'),
    );
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
        // transaction avortée : rien d'autre à nettoyer
    }
});

/** Exécute sous le mode : « app » = rôle applicatif sous RLS, « proprio » = propriétaire. */
function rr5Sous(string $mode, Closure $f): mixed
{
    if ($mode === 'app') {
        DB::statement('SET ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

function rr5Composeur(): ComposeurSequence
{
    return new class implements ComposeurSequence
    {
        public function composer(DemandeComposition $d): string
        {
            return "Message-ID: {$d->messageId}\r\nSubject: {$d->sujet}\r\n\r\n{$d->corps}";
        }
    };
}

/** Une inscription prête à partir (séquence d'une étape, fenêtre 9-17) : [entreprise, contact, inscription]. */
function rr5Inscrit(object $t, string $email, ?int $entreprise = null): array
{
    if (! isset($t->sid)) {
        $t->sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $t->sid, 'workspace_id' => $t->ws, 'nom' => 'Séquence RR-5C', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $t->ws, 'sequence_id' => $t->sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    }
    $avant = $t->lundi->modify('-3 days')->format('c');
    if ($entreprise === null) {
        $entreprise = F::fiche($t->ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $avant]);
        F::lier($t->ws, $entreprise, F::tag($t->ws, FichesProtegees::TAG_FEDERATIONS));
    }
    $contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $t->ws, 'company_id' => $entreprise, 'last_name' => 'Dupont-' . substr(md5($email), 0, 6),
        'email' => $email, 'email_status' => 'valid', 'created_at' => $avant, 'first_info_at' => $avant,
    ]);
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $t->ws, 'empreinte_adresse' => ListeSuppression::empreinte($email),
        'verdict' => 'valide', 'verifie_le' => $t->lundi->modify('-2 days')->format('c'), 'source' => 'test',
    ]);
    $ins = (new Inscriptions(new LecteurFicheDb))->inscrire($t->ws, $t->sid, $contact, $t->lundi);

    return [$entreprise, $contact, $ins];
}

function rr5Statut(string $ins): array
{
    $l = DB::table('envoi_inscriptions')->where('id', $ins)->first();

    return [$l->statut, $l->motif_arret];
}

function rr5Activite(string $ws, array $attrs): void
{
    DB::table('activities')->insert(array_merge([
        'workspace_id' => $ws, 'type' => 'note', 'kind' => 'calendly_booked', 'subject_type' => 'company',
        'created_at' => '2026-11-14 09:00:00+01',
    ], $attrs));
}

function rr5Messages(string $ins): int
{
    return DB::table('envoi_messages')->where('inscription_id', $ins)->count();
}

test('E1 : entreprise passée client après l\'inscription ⇒ arrêt motif client, rien de réservé ni envoyé, collègue aussi', function () {
    [$e, , $ins1] = rr5Inscrit($this, 'a@zz-client.example.test');
    [, , $ins2] = rr5Inscrit($this, 'b@zz-client.example.test', $e);
    DB::table('companies')->where('id', $e)->update(['lifecycle_stage' => 'client']);

    $bilan = ($this->seq)()->executer($this->ws, $this->lundi);

    expect($bilan)->toBe([Sequenceur::ARRETEE => 2])
        ->and(rr5Statut($ins1))->toBe(['arretee', 'client'])
        ->and(rr5Statut($ins2))->toBe(['arretee', 'client'])
        ->and(rr5Messages($ins1) + rr5Messages($ins2))->toBe(0)
        ->and(DB::table('envoi_messages')->where('workspace_id', $this->ws)->count())->toBe(0)
        ->and($this->faux->appels)->toBe(0);
});

test('E2 : calendly_booked sur l\'entreprise ⇒ rendez_vous ; tâche genre rendez_vous sur le contact ⇒ rendez_vous', function () {
    [$e, , $ins] = rr5Inscrit($this, 'c@zz-client.example.test');
    rr5Activite($this->ws, ['subject_id' => $e]);
    expect(($this->seq)()->executer($this->ws, $this->lundi))->toBe([Sequenceur::ARRETEE => 1])
        ->and(rr5Statut($ins))->toBe(['arretee', 'rendez_vous']);

    [, $c2, $ins2] = rr5Inscrit($this, 'd@zz-client.example.test');
    rr5Activite($this->ws, ['kind' => 'task', 'subject_type' => 'contact', 'subject_id' => $c2, 'payload' => json_encode(['genre' => 'rendez_vous'])]);
    expect(($this->seq)()->executer($this->ws, $this->lundi))->toBe([Sequenceur::ARRETEE => 1])
        ->and(rr5Statut($ins2))->toBe(['arretee', 'rendez_vous']);
});

test('E2 bis : une tâche d\'un autre genre ne vaut pas rendez-vous', function () {
    [$e, , $ins] = rr5Inscrit($this, 'e@zz-client.example.test');
    rr5Activite($this->ws, ['kind' => 'task', 'subject_id' => $e, 'payload' => json_encode(['genre' => 'rappel'])]);

    expect(($this->seq)()->executer($this->ws, $this->lundi))->toBe([Sequenceur::ENVOYE => 1])
        ->and(rr5Messages($ins))->toBe(1);
});

test('E3 : rendez-vous 60 jours avant l\'inscription ⇒ l\'envoi part ; 10 jours avant ⇒ arrêt', function () {
    [$e, , $ins] = rr5Inscrit($this, 'f@zz-client.example.test');
    rr5Activite($this->ws, ['subject_id' => $e, 'created_at' => $this->lundi->modify('-60 days')->format('c')]);
    expect(($this->seq)()->executer($this->ws, $this->lundi))->toBe([Sequenceur::ENVOYE => 1])
        ->and(rr5Messages($ins))->toBe(1);

    [$e2, , $ins2] = rr5Inscrit($this, 'g@zz-client.example.test');
    rr5Activite($this->ws, ['subject_id' => $e2, 'created_at' => $this->lundi->modify('-10 days')->format('c')]);
    expect(($this->seq)()->executer($this->ws, $this->lundi))->toBe([Sequenceur::ARRETEE => 1])
        ->and(rr5Statut($ins2))->toBe(['arretee', 'rendez_vous']);
});

test('E4 : une entreprise cliente ou une activité de l\'Espace B n\'arrête rien dans l\'Espace A', function (string $mode) {
    $b = F::espace('zz-rr5c-b');
    [$e, , $ins] = rr5Inscrit($this, 'h@zz-client.example.test');
    $eB = F::fiche($b, 'ZZ Entreprise B', ['lifecycle_stage' => 'client']);
    // Activités de B portant l'identifiant numérique de l'entreprise de A : sans filtre d'espace, A s'arrêterait.
    rr5Activite($b, ['subject_id' => $e]);
    rr5Activite($b, ['kind' => 'task', 'subject_type' => 'company', 'subject_id' => $e, 'payload' => json_encode(['genre' => 'rendez_vous'])]);
    expect($eB)->not->toBe($e);

    $bilan = rr5Sous($mode, fn () => ($this->seq)()->executer($this->ws, $this->lundi));

    expect($bilan)->toBe([Sequenceur::ENVOYE => 1])
        ->and(rr5Statut($ins)[0])->not->toBe('arretee')
        ->and(rr5Messages($ins))->toBe(1);
})->with(['app', 'proprio']);

test('E5 : entreprise opportunité ou perdue ⇒ l\'envoi part (aucun faux arrêt)', function (string $etape) {
    [$e, , $ins] = rr5Inscrit($this, 'i@zz-client.example.test');
    DB::table('companies')->where('id', $e)->update(['lifecycle_stage' => $etape]);

    expect(($this->seq)()->executer($this->ws, $this->lundi))->toBe([Sequenceur::ENVOYE => 1])
        ->and(rr5Messages($ins))->toBe(1);
})->with(['opportunite', 'perdu']);

test('E6 : le contrôle ajoute au plus 2 requêtes par tirage', function () {
    rr5Inscrit($this, 'j@zz-client.example.test');
    $n = 0;
    DB::listen(function ($q) use (&$n) {
        if (preg_match('/lifecycle_stage|"activities"/', $q->sql)) {
            $n++;
        }
    });

    expect(($this->seq)()->executer($this->ws, $this->lundi))->toBe([Sequenceur::ENVOYE => 1])
        ->and($n)->toBeLessThanOrEqual(2);
});

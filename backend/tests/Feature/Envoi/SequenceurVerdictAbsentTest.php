<?php

/**
 * Lot B3-B — verdict d'adresse ABSENT : report sans compteur, jamais d'arrêt ; opposition contrôlée avant.
 * Chaque test joue sous le rôle applicatif (RLS) ET sous le propriétaire. Fixtures FICTIVES (`*.example.test`).
 * Transport : FauxServeurEnvoi derrière la PasserelleLocale. Aucun réseau, aucun envoi réel.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Gardes\EnvoiAutorise;
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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SVA_DOMAINE = 'zz-sva.example.test';
const SVA_MODES = ['app', 'proprio'];
const SVA_ALERTE = 'envoi.verdict_absent_persistant';

beforeEach(function () {
    $this->ws = F::espace('zz-sva-a');
    $this->lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    $this->maintenant = $this->lundi;
    FonctionsEspace::regler($this->ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($this->ws, 'test-sva');
    WorkspaceContext::set($this->ws);
    DB::table('envoi_domaines')->insert(['workspace_id' => $this->ws, 'nom' => SVA_DOMAINE, 'achete_le' => '2026-01-01']);
    $this->faux = new FauxServeurEnvoi;
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
        // transaction avortée : rien d'autre à nettoyer
    }
});

function svaSequenceur(object $t): Sequenceur
{
    $d = $t->maintenant->format('c');
    DB::table('envoi_domaines')->where('workspace_id', $t->ws)->update(['dmarc_verifie_le' => $d, 'spf_verifie_le' => $d, 'dkim_verifie_le' => $d]);
    $composeur = new class implements ComposeurSequence
    {
        public function composer(DemandeComposition $d): string
        {
            return "Message-ID: {$d->messageId}\r\nSubject: {$d->sujet}\r\n\r\n{$d->corps}";
        }
    };

    return new Sequenceur(
        new PasserelleLocale($t->faux, new DepotSocleDb, true, 300, fn () => $t->maintenant, $t->ws, 300, fn () => WorkspaceContext::current()),
        new LecteurFicheDb,
        $composeur,
        new ParametresExpedition(SVA_DOMAINE, 'from@' . SVA_DOMAINE, 'ZZ Expediteur SAS'),
    );
}

/** Une inscription active SANS verdict d'adresse (verdict optionnel) ; rend [id inscription, adresse]. */
function svaInscrit(object $t, string $local, ?string $verdict = null, ?string $verifieLe = null, string $domaine = 'zz-client.example.test'): array
{
    $email = $local . '@' . $domaine;
    $sequence = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sequence, 'workspace_id' => $t->ws, 'nom' => 'Sequence B3-B', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $t->ws, 'sequence_id' => $sequence, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    $entreprise = F::fiche($t->ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $t->lundi->modify('-3 days')->format('c')]);
    F::lier($t->ws, $entreprise, F::tag($t->ws, FichesProtegees::TAG_FEDERATIONS));
    $contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $t->ws, 'company_id' => $entreprise, 'last_name' => 'Dupont-' . substr(md5($email), 0, 6), 'email' => $email,
        'email_status' => 'valid', 'created_at' => $t->lundi->modify('-3 days')->format('c'), 'first_info_at' => $t->lundi->modify('-3 days')->format('c'),
    ]);
    if ($verdict !== null) {
        svaVerdict($t->ws, $email, $verdict, $verifieLe ?? $t->lundi->modify('-2 days')->format('c'));
    }

    return [(new Inscriptions(new LecteurFicheDb))->inscrire($t->ws, $sequence, $contact, $t->maintenant), $email];
}

function svaVerdict(string $ws, string $email, string $verdict, string $verifieLe): void
{
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($email), 'verdict' => $verdict, 'verifie_le' => $verifieLe, 'source' => 'test',
    ]);
}

/** Un passage du séquenceur sous le mode demandé (« app » = rôle applicatif non propriétaire sous RLS). */
function svaTraiter(object $t, string $mode, string $ins): string
{
    $seq = svaSequenceur($t);
    if ($mode === 'proprio') {
        return $seq->traiter($t->ws, $ins, $t->maintenant);
    }
    WorkspaceContext::set($t->ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $seq->traiter($t->ws, $ins, $t->maintenant);
    } finally {
        DB::statement('RESET ROLE');
    }
}

function svaEtat(string $ins): object
{
    return DB::table('envoi_inscriptions')->where('id', $ins)->first();
}

function svaMessages(string $ins): array
{
    return DB::table('envoi_messages')->where('inscription_id', $ins)->orderBy('etape')->get()->all();
}

test('1. aucun verdict : REPORTÉ sans compteur, inscription active, rien d\'envoyé ; verdict « valide » écrit => ENVOYÉ au passage suivant', function (string $mode) {
    [$ins] = svaInscrit($this, 'un');

    expect(svaTraiter($this, $mode, $ins))->toBe(Sequenceur::REPORTE)
        ->and(svaEtat($ins)->statut)->toBe('active')
        ->and((int) svaEtat($ins)->reports)->toBe(0)
        ->and(svaMessages($ins))->toHaveCount(1)
        ->and(svaMessages($ins)[0]->statut)->toBe('reporte')
        ->and(svaMessages($ins)[0]->refus)->toBe(EnvoiAutorise::VERDICT_ABSENT)
        ->and($this->faux->appels)->toBe(0);

    svaVerdict($this->ws, 'un@zz-client.example.test', 'valide', $this->maintenant->modify('-1 hour')->format('c'));
    $this->maintenant = $this->lundi->modify('+1 day');
    expect(svaTraiter($this, $mode, $ins))->toBe(Sequenceur::ENVOYE)
        ->and($this->faux->appels)->toBe(1);
})->with(SVA_MODES);

test('2. dernier verdict « inconnu » : même chose que l\'absence de verdict', function (string $mode) {
    [$ins] = svaInscrit($this, 'deux', 'inconnu');

    expect(svaTraiter($this, $mode, $ins))->toBe(Sequenceur::REPORTE)
        ->and(svaEtat($ins)->statut)->toBe('active')
        ->and((int) svaEtat($ins)->reports)->toBe(0)
        ->and($this->faux->appels)->toBe(0);
})->with(SVA_MODES);

test('3. aucun verdict + STOP de l\'espace (envoi_oppositions) : refus DÉFINITIF avec le motif d\'opposition, jamais « reporte »', function (string $mode) {
    [$ins, $mail] = svaInscrit($this, 'stop');
    $h = ListeSuppression::empreinte($mail);
    $lien = DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $this->ws, 'empreinte_adresse' => $h, 'portee' => 'business']);
    DB::table('envoi_oppositions')->insert(['workspace_id' => $this->ws, 'lien_id' => $lien, 'empreinte_adresse' => $h, 'canal' => 'one_click']);

    expect(svaTraiter($this, $mode, $ins))->toBe(Sequenceur::REFUSE)
        ->and(svaEtat($ins)->statut)->toBe('arretee')
        ->and(svaEtat($ins)->motif_arret)->toBe('garde:' . EnvoiAutorise::OPPOSITION_STOP)
        ->and(svaMessages($ins)[0]->statut)->toBe('refuse')
        ->and(svaMessages($ins)[0]->refus)->not->toBe(EnvoiAutorise::VERDICT_ABSENT)
        ->and($this->faux->appels)->toBe(0);
})->with(SVA_MODES);

test('4. aucun verdict + art. 21 en cours : OPPOSITION_ART21 ; + registre manuel : OPPOSITION_REGISTRE ; jamais « reporte »', function (string $mode) {
    [$a, $mailA] = svaInscrit($this, 'art21');
    [$r] = svaInscrit($this, 'registre', null, null, 'zz-registre.example.test');
    DB::table('rgpd_requests')->insert(['workspace_id' => $this->ws, 'type' => 'opposition', 'status' => 'pending', 'subject_email' => $mailA]);
    DB::table('envoi_oppositions_registre')->insert([ // le registre manuel vise un DOMAINE (ou un SIREN) de l'espace
        'workspace_id' => $this->ws, 'portee' => 'domaine', 'empreinte' => RegistreOppositionManuel::empreinte('zz-registre.example.test'),
        'valeur' => 'zz-registre.example.test', 'motif' => 'demande du fondateur', 'auteur' => 'zz-test',
    ]);

    expect(svaTraiter($this, $mode, $a))->toBe(Sequenceur::REFUSE)
        ->and(svaEtat($a)->motif_arret)->toBe('garde:' . EnvoiAutorise::OPPOSITION_ART21)
        ->and(svaTraiter($this, $mode, $r))->toBe(Sequenceur::REFUSE)
        ->and(svaEtat($r)->motif_arret)->toBe('garde:' . EnvoiAutorise::OPPOSITION_REGISTRE)
        ->and($this->faux->appels)->toBe(0);
})->with(SVA_MODES);

test('5. verdict « invalide » (ou « risque ») : refus définitif, inchangé', function (string $mode, string $verdict) {
    [$ins] = svaInscrit($this, 'inv', $verdict);

    expect(svaTraiter($this, $mode, $ins))->toBe(Sequenceur::REFUSE)
        ->and(svaEtat($ins)->statut)->toBe('arretee')
        ->and(svaMessages($ins)[0]->refus)->toBe(EnvoiAutorise::VERDICT_ABSENT)
        ->and($this->faux->appels)->toBe(0);
})->with(SVA_MODES)->with(['invalide', 'risque']);

test('6. verdict « valide » de 31 jours : report PLAFONNÉ, arrêt au 10e passage (non-régression)', function (string $mode) {
    [$ins] = svaInscrit($this, 'perime', 'valide', '2026-10-01T09:00:00+02:00');
    for ($i = 1; $i < Sequenceur::REPORTS_MAX; $i++) {
        $this->maintenant = $this->lundi->modify('+' . (7 * ($i - 1)) . ' days');
        expect(svaTraiter($this, $mode, $ins))->toBe(Sequenceur::REPORTE)
            ->and((int) svaEtat($ins)->reports)->toBe($i);
    }
    // Un verdict de plus de 30 jours à chaque passage : on le rajeunit en arrière pour qu'il reste périmé.
    $this->maintenant = $this->lundi->modify('+' . (7 * (Sequenceur::REPORTS_MAX - 1)) . ' days');
    expect(svaTraiter($this, $mode, $ins))->toBe(Sequenceur::REFUSE)
        ->and(svaEtat($ins)->motif_arret)->toBe(Sequenceur::MOTIF_REPORTS_EPUISES);
})->with(SVA_MODES);

test('7. 30 passages sans verdict : toujours active, une seule ligne de message « reporte », aucun événement ; alerte après 7 jours, sans adresse', function (string $mode) {
    [$ins] = svaInscrit($this, 'long');
    $journal = [];
    Log::listen(function (MessageLogged $e) use (&$journal) {
        if ($e->message === SVA_ALERTE) {
            $journal[] = ['jour' => (string) $this->maintenant->format('Y-m-d'), 'contexte' => $e->context];
        }
    });

    $reportes = 0;
    for ($k = 0; $reportes < 30 && $k < 90; $k++) {
        $this->maintenant = $this->lundi->modify("+{$k} days");
        $r = svaTraiter($this, $mode, $ins);
        expect($r)->toBeIn([Sequenceur::REPORTE, Sequenceur::HORS_FENETRE, Sequenceur::IGNORE]);
        $reportes += $r === Sequenceur::REPORTE ? 1 : 0;
    }

    expect($reportes)->toBe(30)
        ->and(svaEtat($ins)->statut)->toBe('active')
        ->and((int) svaEtat($ins)->reports)->toBe(0)
        ->and(svaMessages($ins))->toHaveCount(1)
        ->and(DB::table('envoi_inscriptions_evenements')->where('inscription_id', $ins)->count())->toBe(0)
        ->and($journal)->not->toBeEmpty()
        ->and(min(array_column($journal, 'jour')))->toBeGreaterThanOrEqual('2026-11-23')
        ->and(count(array_unique(array_column($journal, 'jour'))))->toBe(count($journal)) // au plus une alerte par jour
        ->and(json_encode($journal))->not->toContain('@')
        ->and($this->faux->appels)->toBe(0);
})->with(SVA_MODES);

test('7bis. avant 7 jours : aucune alerte', function (string $mode) {
    [$ins] = svaInscrit($this, 'court');
    $vu = 0;
    Log::listen(function (MessageLogged $e) use (&$vu) {
        $vu += $e->message === SVA_ALERTE ? 1 : 0;
    });
    foreach ([0, 1, 2, 3, 4, 6] as $k) { // lundi à samedi : moins de 7 jours calendaires
        $this->maintenant = $this->lundi->modify("+{$k} days");
        svaTraiter($this, $mode, $ins);
    }

    expect($vu)->toBe(0);
})->with(SVA_MODES);

test('8. cloisonnement : un verdict « valide » de la même empreinte dans l\'espace B ne débloque pas l\'espace A', function (string $mode) {
    [$ins, $mail] = svaInscrit($this, 'cloison');
    $autre = F::espace('zz-sva-b');
    svaVerdict($autre, $mail, 'valide', $this->maintenant->modify('-1 hour')->format('c'));

    expect(svaTraiter($this, $mode, $ins))->toBe(Sequenceur::REPORTE)
        ->and((int) svaEtat($ins)->reports)->toBe(0) // report SANS compteur : le « valide » de B n'a pas été lu
        ->and($this->faux->appels)->toBe(0);
})->with(SVA_MODES);

test('9. porte fermée (envoi réel éteint) : le cycle ne traite rien, aucun message', function () {
    [$ins] = svaInscrit($this, 'ferme');
    expect(config('envoi.reel_actif'))->toBeFalsy();

    $this->artisan('envoi:cycle')->assertExitCode(0);

    expect(DB::table('envoi_messages')->where('inscription_id', $ins)->count())->toBe(0);
});

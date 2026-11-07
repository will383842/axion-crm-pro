<?php

/**
 * Envoi P0 L1 — le séquenceur, testé aux bornes. Fixtures FICTIVES (`.example.invalid`).
 * Transport : FauxServeurEnvoi seulement, derrière la PasserelleLocale. Aucun réseau.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\ComposeurSequence;
use App\Envoi\Sequence\DemandeComposition;
use App\Envoi\Sequence\Evenement;
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
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SEQ_DOMAINE = 'zz-envoi.example.invalid';

/** Un composeur de test : en-têtes de fil visibles, aucun suivi d'ouverture. */
function seqComposeur(): ComposeurSequence
{
    return new class implements ComposeurSequence
    {
        /** @var list<DemandeComposition> */
        public array $vues = [];

        public function composer(DemandeComposition $d): string
        {
            $this->vues[] = $d;

            return "Message-ID: {$d->messageId}\r\nIn-Reply-To: " . ($d->inReplyTo ?? '') . "\r\nReferences: " . implode(' ', $d->references) . "\r\nSubject: {$d->sujet}\r\n\r\n{$d->corps}";
        }
    };
}

beforeEach(function () {
    $this->ws = F::espace('zz-envoi-l1');
    $this->lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($this->ws, 'federations', true);
    DB::table('envoi_drapeaux')->insert(['workspace_id' => $this->ws, 'mode_temoins' => false]); // le mode témoins est testé ailleurs (PasserelleLocaleTest)
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $this->ws, 'nom' => SEQ_DOMAINE, 'achete_le' => '2026-01-01',
        'dmarc_verifie_le' => $this->lundi->format('c'), 'spf_verifie_le' => $this->lundi->format('c'), 'dkim_verifie_le' => $this->lundi->format('c'),
    ]);
    $this->faux = new FauxServeurEnvoi;
    $this->composeur = seqComposeur();
    $this->maintenant = $this->lundi;
    $this->seq = function (): Sequenceur {
        // Le contrôle DNS doit dater du jour (Paris) de l'envoi : on le date de « maintenant ».
        DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update([
            'dmarc_verifie_le' => $this->maintenant->format('c'), 'spf_verifie_le' => $this->maintenant->format('c'), 'dkim_verifie_le' => $this->maintenant->format('c'),
        ]);

        return new Sequenceur(
        new PasserelleLocale($this->faux, new DepotSocleDb, true, 300, fn () => $this->maintenant, $this->ws, 300, fn () => WorkspaceContext::current()),
        new LecteurFicheDb,
        $this->composeur,
        new ParametresExpedition(SEQ_DOMAINE, 'from@' . SEQ_DOMAINE, 'ZZ Expediteur SAS'),
        );
    };
});

/** Une séquence de $etapes étapes (délais 0, 2, 3 jours ouvrés) ; retourne son id. */
function seqSequence(object $t, int $etapes = 3, int $debut = 9, int $fin = 17): string
{
    $id = (string) Illuminate\Support\Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $id, 'workspace_id' => $t->ws, 'nom' => 'Séquence test', 'fenetre_debut' => $debut, 'fenetre_fin' => $fin]);
    foreach (array_slice([0, 2, 3], 0, $etapes) as $i => $delai) {
        DB::table('envoi_sequence_etapes')->insert([
            'workspace_id' => $t->ws, 'sequence_id' => $id, 'rang' => $i + 1, 'delai_jours_ouvres' => $delai,
            'sujet' => 'Sujet v1 étape ' . ($i + 1), 'corps' => 'Corps v1 étape ' . ($i + 1),
        ]);
    }

    return $id;
}

/** Une fiche contactable : [entreprise, contact]. */
function seqFiche(object $t, string $email, ?int $entreprise = null): array
{
    if ($entreprise === null) {
        $entreprise = F::fiche($t->ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $t->lundi->modify('-3 days')->format('c')]);
        $tag = F::tag($t->ws, FichesProtegees::TAG_FEDERATIONS);
        F::lier($t->ws, $entreprise, $tag);
    }
    $contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $t->ws, 'company_id' => $entreprise, 'last_name' => 'Dupont-' . substr(md5($email), 0, 6),
        'email' => $email, 'email_status' => 'valid',
    ]);
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $t->ws, 'empreinte_adresse' => ListeSuppression::empreinte($email),
        'verdict' => 'valide', 'verifie_le' => $t->lundi->modify('-2 days')->format('c'), 'source' => 'test',
    ]);

    return [$entreprise, $contact];
}

function seqInscrire(object $t, string $sequence, int $contact): string
{
    return (new Inscriptions(new LecteurFicheDb))->inscrire($t->ws, $sequence, $contact, $t->maintenant);
}

function seqMessages(string $inscription): array
{
    return DB::table('envoi_messages')->where('inscription_id', $inscription)->orderBy('etape')->get()->all();
}

test('nominal : étape 1 envoyée, puis relances 2 et 3 dans le MÊME fil (In-Reply-To / References)', function () {
    $sid = seqSequence($this);
    [, $contact] = seqFiche($this, 'a@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $contact);

    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::ENVOYE => 1]);
    $m1 = seqMessages($ins)[0]->message_id;
    expect($this->composeur->vues[0]->inReplyTo)->toBeNull();

    $this->maintenant = $this->lundi->modify('+2 days'); // mercredi 10h : 2 jours ouvrés plus tard
    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::ENVOYE => 1]);
    expect($this->composeur->vues[1]->inReplyTo)->toBe($m1)
        ->and($this->composeur->vues[1]->references)->toBe([$m1])
        ->and($this->composeur->vues[1]->sujet)->toBe('Re: Sujet v1 étape 2');

    $this->maintenant = $this->lundi->modify('+7 days'); // lundi suivant : 3 jours ouvrés après mercredi
    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::ENVOYE => 1]);
    $m2 = seqMessages($ins)[1]->message_id;
    expect($this->composeur->vues[2]->inReplyTo)->toBe($m1)
        ->and($this->composeur->vues[2]->references)->toBe([$m1, $m2])
        ->and(DB::table('envoi_inscriptions')->where('id', $ins)->value('statut'))->toBe('terminee')
        ->and(count(seqMessages($ins)))->toBe(3);
});

test('version FIGÉE : modifier la séquence après l\'inscription ne change pas le message', function () {
    $sid = seqSequence($this, 1);
    [, $contact] = seqFiche($this, 'b@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $contact);
    DB::table('envoi_sequence_etapes')->where('sequence_id', $sid)->update(['sujet' => 'Sujet v2', 'corps' => 'Corps v2']);

    ($this->seq)()->executer($this->ws, $this->maintenant);
    expect($this->composeur->vues[0]->sujet)->toBe('Sujet v1 étape 1')
        ->and($this->composeur->vues[0]->corps)->toBe('Corps v1 étape 1');
    expect(fn () => DB::table('envoi_inscriptions')->where('id', $ins)->update(['version_figee' => '{}']))->toThrow(Illuminate\Database\QueryException::class);
});

test('hors fenêtre / week-end / férié : rien n\'est envoyé, l\'échéance est reportée au prochain créneau', function () {
    $sid = seqSequence($this, 1);
    [, $contact] = seqFiche($this, 'c@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $contact);
    DB::table('envoi_inscriptions')->where('id', $ins)->update(['prochaine_echeance' => '2026-11-01 00:00:00+01']);

    foreach (['2026-11-16 08:59', '2026-11-16 17:00', '2026-11-11 10:00' /* 11 novembre */, '2026-11-22 10:00' /* dimanche */] as $quand) {
        $this->maintenant = new DateTimeImmutable($quand, new DateTimeZone('Europe/Paris'));
        DB::table('envoi_inscriptions')->where('id', $ins)->update(['prochaine_echeance' => '2026-11-01 00:00:00+01']);
        expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::HORS_FENETRE);
    }
    expect($this->faux->appels)->toBe(0)->and(seqMessages($ins))->toBe([]);

    $this->maintenant = new DateTimeImmutable('2026-11-17 09:00', new DateTimeZone('Europe/Paris'));
    DB::table('envoi_inscriptions')->where('id', $ins)->update(['prochaine_echeance' => $this->maintenant->format('c')]);
    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ENVOYE);
});

test('arrêt automatique : réponse, STOP, opposition, rebond dur', function (string $evenement) {
    $sid = seqSequence($this);
    [, $contact] = seqFiche($this, "x-{$evenement}@zz-client.example.invalid");
    $ins = seqInscrire($this, $sid, $contact);
    ($this->seq)()->executer($this->ws, $this->maintenant);

    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $ins, $evenement, $this->maintenant);
    $this->maintenant = $this->lundi->modify('+2 days');
    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([])
        ->and(DB::table('envoi_inscriptions')->where('id', $ins)->value('motif_arret'))->toBe($evenement)
        ->and(count(seqMessages($ins)))->toBe(1);
})->with(Evenement::TOUS);

test('arrêt par ENTREPRISE : une réponse arrête les autres personnes, même inscrites après', function () {
    $sid = seqSequence($this);
    [$ent, $c1] = seqFiche($this, 'p1@zz-client.example.invalid');
    [, $c2] = seqFiche($this, 'p2@zz-client.example.invalid', $ent);
    [, $c3] = seqFiche($this, 'p3@zz-client.example.invalid', $ent);
    [$autreEnt, $cAutre] = seqFiche($this, 'q1@zz-client.example.invalid');
    $i1 = seqInscrire($this, $sid, $c1);
    $i2 = seqInscrire($this, $sid, $c2);
    $iAutre = seqInscrire($this, $sid, $cAutre);

    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $i1, Evenement::REPONSE, $this->maintenant);
    expect(DB::table('envoi_inscriptions')->where('id', $i2)->value('motif_arret'))->toBe(Evenement::MOTIF_ENTREPRISE);

    $i3 = seqInscrire($this, $sid, $c3); // inscrite APRÈS la réponse : arrêtée au moment d'envoyer
    $bilan = ($this->seq)()->executer($this->ws, $this->maintenant);
    expect($bilan)->toBe([Sequenceur::ARRETEE => 1, Sequenceur::ENVOYE => 1])
        ->and(seqMessages($i2))->toBe([])->and(seqMessages($i3))->toBe([])
        ->and(count(seqMessages($iAutre)))->toBe(1); // l'autre entreprise n'est pas touchée
});

test('l\'opposition arrête aussi l\'entreprise ; un STOP ou un rebond dur ne touche que la personne', function () {
    $sid = seqSequence($this);
    [$ent, $c1] = seqFiche($this, 'o1@zz-client.example.invalid');
    [, $c2] = seqFiche($this, 'o2@zz-client.example.invalid', $ent);
    [, $c3] = seqFiche($this, 'o3@zz-client.example.invalid', $ent);
    $i1 = seqInscrire($this, $sid, $c1);
    $i2 = seqInscrire($this, $sid, $c2);
    $i3 = seqInscrire($this, $sid, $c3);
    $api = new Inscriptions(new LecteurFicheDb);

    $api->evenement($this->ws, $i1, Evenement::STOP, $this->maintenant);
    $api->evenement($this->ws, $i2, Evenement::REBOND_DUR, $this->maintenant);
    expect(DB::table('envoi_inscriptions')->where('id', $i3)->value('statut'))->toBe('active');

    $api->evenement($this->ws, $i3, Evenement::OPPOSITION, $this->maintenant);
    expect(DB::table('envoi_inscriptions')->whereIn('id', [$i1, $i2, $i3])->pluck('statut')->unique()->all())->toBe(['arretee']);
});

test('la porte EnvoiAutorise s\'applique : adresse sans verdict, segment fermé, domaine inconnu => refus et arrêt, rien envoyé', function () {
    $sid = seqSequence($this, 1);
    [, $contact] = seqFiche($this, 'g@zz-client.example.invalid');
    DB::table('envoi_verdicts_adresse')->where('workspace_id', $this->ws)->update(['verdict' => 'invalide']);
    $ins = seqInscrire($this, $sid, $contact);

    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::REFUSE => 1])
        ->and($this->faux->appels)->toBe(0)
        ->and(seqMessages($ins)[0]->statut)->toBe('refuse')
        ->and(seqMessages($ins)[0]->refus)->toBe('verdict_adresse_absent_ou_perime');
});

test('la fiche est lue EN BASE : pays étranger en base => refus, même si rien d\'autre ne le dit', function () {
    $sid = seqSequence($this, 1);
    [$ent, $contact] = seqFiche($this, 'h@zz-client.example.invalid');
    DB::table('companies')->where('id', $ent)->update(['country_code' => 'DE']);
    $ins = seqInscrire($this, $sid, $contact);

    ($this->seq)()->executer($this->ws, $this->maintenant);
    expect(seqMessages($ins)[0]->refus)->toBe('pays_hors_france')->and($this->faux->appels)->toBe(0);
});

test('envoi réel éteint ou plafond atteint : message « reporté », rien n\'est perdu, reprise le lendemain ouvré', function () {
    $sid = seqSequence($this, 1);
    [, $contact] = seqFiche($this, 'r@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $contact);
    $eteint = new Sequenceur(
        new PasserelleLocale($this->faux, new DepotSocleDb, false, 300, fn () => $this->maintenant, $this->ws, 300, fn () => WorkspaceContext::current()),
        new LecteurFicheDb, $this->composeur, new ParametresExpedition(SEQ_DOMAINE, 'from@' . SEQ_DOMAINE, 'ZZ Expediteur SAS'),
    );

    expect($eteint->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::REPORTE)
        ->and($this->faux->appels)->toBe(0)
        ->and(seqMessages($ins)[0]->statut)->toBe('reporte');

    $this->maintenant = $this->lundi->modify('+1 day');
    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::ENVOYE => 1])
        ->and(count(seqMessages($ins)))->toBe(1); // la même ligne est reprise : jamais deux lignes
});

test('unicité (inscription, étape) en base : un doublon direct est refusé par Postgres', function () {
    $sid = seqSequence($this, 1);
    [, $contact] = seqFiche($this, 'u@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $contact);
    $ligne = ['workspace_id' => $this->ws, 'inscription_id' => $ins, 'etape' => 1, 'statut' => 'reserve', 'message_id' => '<a@x>'];
    DB::table('envoi_messages')->insert($ligne);
    expect(fn () => DB::table('envoi_messages')->insert($ligne))->toThrow(Illuminate\Database\QueryException::class);
});

test('une séquence compte de 1 à 3 étapes (la quatrième est refusée en base)', function () {
    $sid = seqSequence($this, 3);
    expect(fn () => DB::table('envoi_sequence_etapes')->insert([
        'workspace_id' => $this->ws, 'sequence_id' => $sid, 'rang' => 4, 'delai_jours_ouvres' => 1, 'sujet' => 's', 'corps' => 'c',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

test('aucune adresse en clair dans les tables du séquenceur, aucune mesure d\'ouverture', function () {
    foreach (['envoi_sequences', 'envoi_sequence_etapes', 'envoi_inscriptions', 'envoi_messages'] as $table) {
        $colonnes = DB::table('information_schema.columns')->where('table_name', $table)->pluck('column_name')->all();
        expect(array_intersect($colonnes, ['email', 'adresse', 'destinataire', 'ouvert', 'ouvert_le', 'ouvertures', 'pixel']))->toBe([]);
    }
});

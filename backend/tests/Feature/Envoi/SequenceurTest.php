<?php

/**
 * Envoi P0 L1 — le séquenceur, testé aux bornes. Fixtures FICTIVES (`.example.invalid`).
 * Transport : FauxServeurEnvoi seulement, derrière la PasserelleLocale. Aucun réseau.
 */

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\FichesProtegees;
use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\Gardes\EnvoiAutorise;
use App\Envoi\ListesNoires\DepotControlesDb;
use App\Envoi\ListesNoires\Ressource;
use App\Envoi\ListesNoires\StatutListe;
use App\Envoi\Message\MessageRefuse;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\ComposeurSequence;
use App\Envoi\Sequence\DemandeComposition;
use App\Envoi\Sequence\Evenement;
use App\Envoi\Sequence\FenetreHoraire;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ParametresExpedition;
use App\Envoi\Sequence\RefusSequenceur;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Envoi\Smtp\EnvoiSmtpRefuse;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ServeurEspion;
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
    // Le mode témoins ne s'éteint que par la commande locale (déclencheur envoi_drapeaux_garde) : on passe par elle.
    (new DepotSocleDb)->eteindreTemoins($this->ws, 'test-sequenceur'); // le mode témoins est testé ailleurs (PasserelleLocaleTest)
    // La passerelle refuse tout message dont l'espace n'est pas le contexte courant : l'appelant (worker) l'ouvre.
    WorkspaceContext::set($this->ws);
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

afterEach(function () {
    // Un test qui attend une exception SQL laisse la transaction de RefreshDatabase avortée : le
    // `set_config` de `clear()` y échoue, mais l'état PHP est déjà remis à zéro et le rollback défait le reste.
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
        // transaction avortée : rien d'autre à nettoyer
    }
});

/** Une séquence de $etapes étapes (délais 0, 2, 3 jours ouvrés) ; retourne son id. */
function seqSequence(object $t, int $etapes = 3, int $debut = 9, int $fin = 17): string
{
    $id = (string) Str::uuid();
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
        // Dates du CONTACT (article 14) fixées par rapport à « lundi » : jamais le now() réel de la base.
        'created_at' => $t->lundi->modify('-3 days')->format('c'), 'first_info_at' => $t->lundi->modify('-3 days')->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $t->ws, 'empreinte_adresse' => ListeSuppression::empreinte($email),
        'verdict' => 'valide', 'verifie_le' => $t->lundi->modify('-2 days')->format('c'), 'source' => 'test',
    ]);

    return [$entreprise, $contact];
}

/** Fiche avec attributs d'entreprise et de contact imposés : [entreprise, contact]. */
function seqFicheAvec(object $t, string $email, array $entreprise = [], array $contact = [], bool $presse = false): array
{
    $e = F::fiche($t->ws, 'ZZ Entreprise', array_merge(['country_code' => 'FR', 'first_info_at' => $t->lundi->modify('-3 days')->format('c')], $entreprise));
    F::lier($t->ws, $e, F::tag($t->ws, FichesProtegees::TAG_FEDERATIONS));
    $c = (int) DB::table('contacts')->insertGetId(array_merge([
        'workspace_id' => $t->ws, 'company_id' => $e, 'last_name' => 'Martin-' . substr(md5($email . microtime()), 0, 6),
        'email' => $email, 'email_status' => 'valid',
        'created_at' => $t->lundi->modify('-3 days')->format('c'), 'first_info_at' => $t->lundi->modify('-3 days')->format('c'),
    ], $contact));
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $t->ws, 'empreinte_adresse' => ListeSuppression::empreinte($email),
        'verdict' => 'valide', 'verifie_le' => $t->lundi->modify('-2 days')->format('c'), 'source' => 'test',
    ]);

    return [$e, $c];
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
    expect($this->composeur->vues[2]->inReplyTo)->toBe($m2) // la relance répond au message PRÉCÉDENT (étape 2)
        ->and($this->composeur->vues[2]->references)->toBe([$m1, $m2]) // References : tout le fil, dans l'ordre
        ->and(seqMessages($ins)[2]->in_reply_to)->toBe($m2)
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
    expect(fn () => DB::table('envoi_inscriptions')->where('id', $ins)->update(['version_figee' => '{}']))->toThrow(QueryException::class);
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
    ksort($bilan); // l'ordre de traitement n'est pas garanti : on compare le bilan, pas la séquence
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

test('la porte EnvoiAutorise s\'applique : verdict d\'adresse INVALIDE => refus et arrêt, rien envoyé', function () {
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
        new LecteurFicheDb,
        $this->composeur,
        new ParametresExpedition(SEQ_DOMAINE, 'from@' . SEQ_DOMAINE, 'ZZ Expediteur SAS'),
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
    expect(fn () => DB::table('envoi_messages')->insert($ligne))->toThrow(QueryException::class);
});

test('une séquence compte de 1 à 3 étapes (la quatrième est refusée en base)', function () {
    $sid = seqSequence($this, 3);
    expect(fn () => DB::table('envoi_sequence_etapes')->insert([
        'workspace_id' => $this->ws, 'sequence_id' => $sid, 'rang' => 4, 'delai_jours_ouvres' => 1, 'sujet' => 's', 'corps' => 'c',
    ]))->toThrow(QueryException::class);
});

test('aucune adresse en clair dans les tables du séquenceur, aucune mesure d\'ouverture', function () {
    foreach (['envoi_sequences', 'envoi_sequence_etapes', 'envoi_inscriptions', 'envoi_messages'] as $table) {
        $colonnes = DB::table('information_schema.columns')->where('table_name', $table)->pluck('column_name')->all();
        expect(array_intersect($colonnes, ['email', 'adresse', 'destinataire', 'ouvert', 'ouvert_le', 'ouvertures', 'pixel']))->toBe([]);
    }
});

/* ───────── Audit 07/11 : la fiche transmise à la porte est COMPLÈTE ───────── */

test('fiche complète : non diffusible, fermée, entrepreneur individuel, OSM, personnelle => refus avec le bon motif', function (array $entreprise, array $contact, string $motif) {
    $sid = seqSequence($this, 1);
    [, $c] = seqFicheAvec($this, 'k-' . $motif . '@zz-client.example.invalid', $entreprise, $contact);
    $ins = seqInscrire($this, $sid, $c);

    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::REFUSE => 1])
        ->and(seqMessages($ins)[0]->refus)->toBe($motif)
        ->and($this->faux->appels)->toBe(0);
})->with([
    'non diffusible' => [['insee_non_diffusible_le' => '2026-10-01 00:00:00+02'], [], 'non_diffusible'],
    'entreprise fermée' => [['insee_ferme_le' => '2026-10-01 00:00:00+02'], [], 'entreprise_fermee'],
    'entrepreneur individuel' => [['legal_form' => '1000'], [], 'entreprise_individuelle'],
    'source OSM' => [['metadata' => json_encode(['email_source' => 'osm'])], [], 'source_osm'],
    'personnelle' => [[], ['metadata' => json_encode(['email_nature' => 'perso'])], 'personnelle'],
]);

test('fiche complète : site deviné non vérifié (adresse du domaine du site) => site_non_verifie', function () {
    $sid = seqSequence($this, 1);
    [, $c] = seqFicheAvec($this, 'k@zz-site.example.invalid', [
        'website' => 'https://zz-site.example.invalid', 'website_method' => 'guess:test',
    ], ['discovery_source' => 'site']);
    $ins = seqInscrire($this, $sid, $c);

    ($this->seq)()->executer($this->ws, $this->maintenant);
    expect(seqMessages($ins)[0]->refus)->toBe('site_non_verifie')->and($this->faux->appels)->toBe(0);
});

test('« une occurrence suffit » : la même adresse sur une AUTRE fiche non diffusible refuse l\'envoi', function () {
    $sid = seqSequence($this, 1);
    [, $c] = seqFicheAvec($this, 'meme@zz-client.example.invalid');
    seqFicheAvec($this, 'meme@zz-client.example.invalid', ['insee_non_diffusible_le' => '2026-10-01 00:00:00+02']);
    $ins = seqInscrire($this, $sid, $c);

    ($this->seq)()->executer($this->ws, $this->maintenant);
    expect(seqMessages($ins)[0]->refus)->toBe('non_diffusible')->and($this->faux->appels)->toBe(0);
});

test('GardePresse : une personne de la presse, ou une fiche de presse, ne reçoit jamais de séquence', function () {
    $sid = seqSequence($this, 1);
    [, $c] = seqFicheAvec($this, 'journaliste@zz-client.example.invalid', [], ['external_ref' => 'journaliste:42']);
    $ins = seqInscrire($this, $sid, $c);
    ($this->seq)()->executer($this->ws, $this->maintenant);
    expect(seqMessages($ins)[0]->refus)->toBe('contact_presse');

    [$ent, $c2] = seqFicheAvec($this, 'redaction@zz-client.example.invalid');
    F::lier($this->ws, $ent, F::tag($this->ws, FichesProtegees::TAG_PRESSE));
    $ins2 = seqInscrire($this, $sid, $c2);
    ($this->seq)()->executer($this->ws, $this->maintenant);
    expect(seqMessages($ins2)[0]->refus)->toBe('contact_presse')->and($this->faux->appels)->toBe(0);
});

test('GardeConsoleClient : une adresse portée par un client de la console ne reçoit jamais de séquence', function () {
    $sid = seqSequence($this, 1);
    [, $c] = seqFicheAvec($this, 'client@zz-client.example.invalid');
    seqFicheAvec($this, 'client@zz-client.example.invalid', [], ['discovery_source' => 'console']);
    $ins = seqInscrire($this, $sid, $c);

    ($this->seq)()->executer($this->ws, $this->maintenant);
    expect(seqMessages($ins)[0]->refus)->toBe('fiche_console')->and($this->faux->appels)->toBe(0);
});

/* ───────── Audit 07/11 : article 14 RGPD, au niveau du CONTACT ───────── */

test('article 14 : un contact ajouté SANS information alors que l\'entreprise a été informée est refusé après 30 jours', function () {
    $sid = seqSequence($this, 1);
    // L'entreprise a été informée il y a 3 jours ; le contact a été collecté il y a 40 jours et n'a jamais reçu l'information.
    [, $c] = seqFicheAvec($this, 'art14@zz-client.example.invalid', [], [
        'created_at' => $this->lundi->modify('-40 days')->format('c'), 'first_info_at' => null,
    ]);
    $ins = seqInscrire($this, $sid, $c);

    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::REFUSE => 1])
        ->and(seqMessages($ins)[0]->refus)->toBe('art14_information_non_donnee_j30');
});

test('article 14 : la date de collecte est celle du CONTACT (et non de l\'entreprise) ; contact informé = accepté', function () {
    $sid = seqSequence($this, 1);
    // Entreprise ancienne (non informée, créée il y a 90 jours) mais contact récent (hier) : dans le délai.
    [, $recent] = seqFicheAvec(
        $this,
        'recent@zz-client.example.invalid',
        ['first_info_at' => null, 'created_at' => $this->lundi->modify('-90 days')->format('c')],
        ['created_at' => $this->lundi->modify('-1 day')->format('c'), 'first_info_at' => null],
    );
    // Contact collecté il y a 40 jours MAIS informé il y a 35 jours : accepté.
    [, $informe] = seqFicheAvec(
        $this,
        'informe@zz-client.example.invalid',
        ['first_info_at' => null],
        ['created_at' => $this->lundi->modify('-40 days')->format('c'), 'first_info_at' => $this->lundi->modify('-35 days')->format('c')],
    );
    $i1 = seqInscrire($this, $sid, $recent);
    $i2 = seqInscrire($this, $sid, $informe);

    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::ENVOYE => 2])
        ->and(seqMessages($i1)[0]->statut)->toBe('envoye')->and(seqMessages($i2)[0]->statut)->toBe('envoye');
});

/* ───────── Audit 07/11 : STOP / opposition / rebond dur valent pour l'ADRESSE ───────── */

test('un contact inscrit aux séquences A et B qui répond STOP sur A ne reçoit plus rien de B', function (string $evenement) {
    $a = seqSequence($this, 3);
    $b = seqSequence($this, 3);
    [, $c] = seqFiche($this, "ab-{$evenement}@zz-client.example.invalid");
    $insA = seqInscrire($this, $a, $c);
    $insB = seqInscrire($this, $b, $c);

    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $insA, $evenement, $this->maintenant);
    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([])
        ->and(seqMessages($insA))->toBe([])->and(seqMessages($insB))->toBe([])
        ->and($this->faux->appels)->toBe(0)
        ->and(DB::table('envoi_inscriptions')->where('id', $insB)->value('statut'))->toBe('arretee');
})->with([Evenement::STOP, Evenement::OPPOSITION, Evenement::REBOND_DUR]);

test('STOP : refusé aussi pour une inscription créée APRÈS le STOP (sans passer par evenement()) — filet dans traiter()', function () {
    $a = seqSequence($this, 1);
    $b = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'apres@zz-client.example.invalid');
    $insA = seqInscrire($this, $a, $c);
    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $insA, Evenement::STOP, $this->maintenant);
    $insB = seqInscrire($this, $b, $c); // inscrite après le STOP

    expect(($this->seq)()->traiter($this->ws, $insB, $this->maintenant))->toBe(Sequenceur::ARRETEE)
        ->and($this->faux->appels)->toBe(0)->and(seqMessages($insB))->toBe([]);
});

test('une réponse arrête, par l\'entreprise, les autres séquences de la même personne', function () {
    $a = seqSequence($this, 1);
    $b = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'rep@zz-client.example.invalid');
    $insA = seqInscrire($this, $a, $c);
    $insB = seqInscrire($this, $b, $c);
    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $insA, Evenement::REPONSE, $this->maintenant);

    expect(DB::table('envoi_inscriptions')->where('id', $insB)->value('motif_arret'))->toBe(Evenement::MOTIF_ENTREPRISE);
});

/* ───────── Audit 07/11 : refus passagers = report, jamais arrêt ───────── */

test('DNS non vérifié ce jour : le message est REPORTÉ (inscription active), pas arrêté ; repris quand le DNS est vérifié', function () {
    $sid = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'dns@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $c);
    $seq = ($this->seq)();
    DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['dmarc_verifie_le' => $this->maintenant->modify('-1 day')->format('c')]);

    expect($seq->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::REPORTE)
        ->and(DB::table('envoi_inscriptions')->where('id', $ins)->value('statut'))->toBe('active')
        ->and(seqMessages($ins)[0]->statut)->toBe('reporte')
        ->and(seqMessages($ins)[0]->refus)->toBe('domaine_envoi_dns_non_verifie_ce_jour')
        ->and($this->faux->appels)->toBe(0);

    $this->maintenant = $this->lundi->modify('+1 day');
    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::ENVOYE => 1])
        ->and(count(seqMessages($ins)))->toBe(1);
});

test('verdict d\'adresse PÉRIMÉ : reporté (le verdict peut être renouvelé) ; verdict INVALIDE : arrêté pour de bon', function () {
    $sid = seqSequence($this, 1);
    [, $c1] = seqFiche($this, 'perime@zz-client.example.invalid');
    [, $c2] = seqFiche($this, 'invalide@zz-client.example.invalid');
    DB::table('envoi_verdicts_adresse')->where('empreinte_adresse', ListeSuppression::empreinte('perime@zz-client.example.invalid'))
        ->update(['verifie_le' => $this->lundi->modify('-45 days')->format('c')]);
    DB::table('envoi_verdicts_adresse')->where('empreinte_adresse', ListeSuppression::empreinte('invalide@zz-client.example.invalid'))
        ->update(['verdict' => 'invalide']);
    $i1 = seqInscrire($this, $sid, $c1);
    $i2 = seqInscrire($this, $sid, $c2);
    $seq = ($this->seq)();

    expect($seq->traiter($this->ws, $i1, $this->maintenant))->toBe(Sequenceur::REPORTE)
        ->and(DB::table('envoi_inscriptions')->where('id', $i1)->value('statut'))->toBe('active')
        ->and($seq->traiter($this->ws, $i2, $this->maintenant))->toBe(Sequenceur::REFUSE)
        ->and(DB::table('envoi_inscriptions')->where('id', $i2)->value('statut'))->toBe('arretee');
});

/* ───────── Audit 07/11 : intégrité référentielle composite ───────── */

test('clé étrangère composite (workspace_id, inscription_id) : un message ne peut pas pointer l\'inscription d\'un autre espace', function () {
    $fk = DB::select("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'envoi_messages'::regclass AND contype = 'f'");
    $defs = array_map(fn ($r): string => (string) $r->def, $fk);
    expect(implode(' | ', $defs))->toContain('FOREIGN KEY (workspace_id, inscription_id) REFERENCES envoi_inscriptions(workspace_id, id)');

    $autre = F::espace('zz-envoi-l1-autre');
    $sid = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'fk@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $c);
    expect(fn () => DB::table('envoi_messages')->insert([
        'workspace_id' => $autre, 'inscription_id' => $ins, 'etape' => 1, 'statut' => 'reserve', 'message_id' => '<fk@x>',
    ]))->toThrow(QueryException::class);
});

/* ───────── Relecture d'exactitude (B1, B2, I2–I5) ───────── */

/** Un séquenceur sur mesure : composeur, entité expéditrice et chauffe au choix (DNS daté de « maintenant »). */
function seqSurMesure(object $t, ?ComposeurSequence $composeur = null, string $entite = 'ZZ Expediteur SAS', ?GardeChauffe $chauffe = null): Sequenceur
{
    DB::table('envoi_domaines')->where('workspace_id', $t->ws)->update([
        'dmarc_verifie_le' => $t->maintenant->format('c'), 'spf_verifie_le' => $t->maintenant->format('c'), 'dkim_verifie_le' => $t->maintenant->format('c'),
    ]);

    return new Sequenceur(
        new PasserelleLocale($t->faux, new DepotSocleDb, true, 300, fn () => $t->maintenant, $t->ws, 300, fn () => WorkspaceContext::current(), $chauffe),
        new LecteurFicheDb,
        $composeur ?? $t->composeur,
        new ParametresExpedition(SEQ_DOMAINE, 'from@' . SEQ_DOMAINE, $entite),
    );
}

function seqEtat(string $ins): object
{
    return DB::table('envoi_inscriptions')->where('id', $ins)->first();
}

test('B1 : CHAQUE code de refus de la porte est classé « reporter » ou « arrêter » (un code ajouté sans classement fait échouer ce test)', function () {
    $codes = [];
    foreach ([EnvoiAutorise::class, EligibiliteAdresse::class] as $classe) {
        foreach ((new ReflectionClass($classe))->getConstants() as $nom => $valeur) {
            if (is_string($valeur) && ! in_array($nom, ['FUSEAU'], true) && ! str_starts_with($nom, 'AGE_') && ! str_starts_with($nom, 'ART14_ALERTE') && ! str_ends_with($nom, '_JOURS')) {
                $codes[$nom] = $valeur;
            }
        }
    }
    expect($codes)->not->toBeEmpty();
    $classes = array_merge(RefusSequenceur::TEMPORAIRES, RefusSequenceur::DEFINITIFS);
    foreach ($codes as $nom => $valeur) {
        expect(in_array($valeur, $classes, true))->toBeTrue("Le refus {$nom} ({$valeur}) n'est classé ni temporaire ni définitif.");
    }
    expect(array_intersect(RefusSequenceur::TEMPORAIRES, RefusSequenceur::DEFINITIFS))->toBe([]);
});

test('B1 : refus TEMPORAIRE ou réglage d\'espace => REPORTÉ (inscription active, échéance repoussée, journalisé), repris dès que la cause disparaît', function (string $code) {
    $sid = seqSequence($this, 1);
    [, $c] = seqFiche($this, "tmp-{$code}@zz-client.example.invalid");
    $ins = seqInscrire($this, $sid, $c);
    $entite = 'ZZ Expediteur SAS';
    $leve = function () {};
    switch ($code) {
        case 'domaine_envoi_sur_liste_noire':
            DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['liste_noire' => true]);
            $leve = fn () => DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['liste_noire' => false]);
            break;
        case 'ip_envoi_sur_liste_noire':
            config(['envoi.listes_noires.ips' => ['203.0.113.5@' . $this->ws]]);
            $r = new Ressource($this->ws, Ressource::IP, '203.0.113.5');
            (new DepotControlesDb)->enregistrer($r, StatutListe::Liste, 'zen.spamhaus.org', '127.0.0.4', true, new DateTimeImmutable);
            $leve = fn () => config(['envoi.listes_noires.ips' => []]);
            break;
        case 'domaine_envoi_trop_jeune':
            DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['achete_le' => $this->lundi->modify('-5 days')->format('Y-m-d')]);
            $leve = fn () => DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['achete_le' => '2026-01-01']);
            break;
        case 'domaine_envoi_inconnu':
            DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['nom' => 'zz-autre.example.invalid']);
            $leve = fn () => DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['nom' => SEQ_DOMAINE]);
            break;
        case 'entite_legale_expeditrice_absente':
            $entite = '';
            break;
        case 'segment_ferme':
            FonctionsEspace::regler($this->ws, 'federations', false);
            $leve = fn () => FonctionsEspace::regler($this->ws, 'federations', true);
            break;
    }

    expect(seqSurMesure($this, null, $entite)->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::REPORTE)
        ->and(seqEtat($ins)->statut)->toBe('active')
        ->and(seqEtat($ins)->motif_arret)->toBeNull()
        ->and((new DateTimeImmutable(seqEtat($ins)->prochaine_echeance))->getTimestamp())->toBeGreaterThan($this->maintenant->getTimestamp())
        ->and(seqMessages($ins)[0]->statut)->toBe('reporte')
        ->and(seqMessages($ins)[0]->refus)->toBe($code)
        ->and($this->faux->appels)->toBe(0);

    $leve();
    $this->maintenant = $this->lundi->modify('+1 day');
    expect(seqSurMesure($this)->executer($this->ws, $this->maintenant))->toBe([Sequenceur::ENVOYE => 1])
        ->and(count(seqMessages($ins)))->toBe(1);
})->with([
    'domaine sur liste noire' => 'domaine_envoi_sur_liste_noire',
    'IP sur liste noire (K13)' => 'ip_envoi_sur_liste_noire',
    'domaine trop jeune' => 'domaine_envoi_trop_jeune',
    'domaine inconnu' => 'domaine_envoi_inconnu',
    'entité expéditrice absente' => 'entite_legale_expeditrice_absente',
    'segment fermé' => 'segment_ferme',
]);

test('B1 : refus DÉFINITIF lié à la personne (opposition / liste de suppression) => inscription ARRÊTÉE pour de bon', function () {
    $sid = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'def@zz-client.example.invalid');
    ListeSuppression::inscrire('def@zz-client.example.invalid', 'complaint', 'zz-test');
    $ins = seqInscrire($this, $sid, $c);

    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::REFUSE)
        ->and(seqEtat($ins)->statut)->toBe('arretee')
        ->and(seqEtat($ins)->motif_arret)->toBe('garde:opposition')
        ->and($this->faux->appels)->toBe(0);
});

test('B2 : une réponse reçue APRÈS la dernière étape (inscription « terminée ») arrête quand même le collègue inscrit à une autre séquence', function () {
    $a = seqSequence($this, 1);
    $b = seqSequence($this, 1);
    [$ent, $c1] = seqFiche($this, 'fin1@zz-client.example.invalid');
    [, $c2] = seqFiche($this, 'fin2@zz-client.example.invalid', $ent);
    $insA = seqInscrire($this, $a, $c1);
    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::ENVOYE => 1])
        ->and(seqEtat($insA)->statut)->toBe('terminee');

    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $insA, Evenement::REPONSE, $this->maintenant);
    expect(seqEtat($insA)->statut)->toBe('terminee'); // le statut ne change pas : la trace est ailleurs

    $insB = seqInscrire($this, $b, $c2);
    expect(($this->seq)()->traiter($this->ws, $insB, $this->maintenant))->toBe(Sequenceur::ARRETEE)
        ->and(seqMessages($insB))->toBe([])
        ->and($this->faux->appels)->toBe(1); // seul le premier message est parti
});

test('B2 : un STOP reçu après la dernière étape vaut pour l\'adresse : une autre séquence de la même personne ne part pas', function () {
    $a = seqSequence($this, 1);
    $b = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'stopfin@zz-client.example.invalid');
    $insA = seqInscrire($this, $a, $c);
    ($this->seq)()->executer($this->ws, $this->maintenant);
    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $insA, Evenement::STOP, $this->maintenant);

    $insB = seqInscrire($this, $b, $c);
    expect(($this->seq)()->traiter($this->ws, $insB, $this->maintenant))->toBe(Sequenceur::ARRETEE)
        ->and($this->faux->appels)->toBe(1);
});

test('B2 : la trace d\'un événement est en AJOUT SEUL (aucune modification, aucun effacement)', function () {
    $a = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'trace@zz-client.example.invalid');
    $insA = seqInscrire($this, $a, $c);
    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $insA, Evenement::REPONSE, $this->maintenant);
    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $insA, Evenement::REPONSE, $this->maintenant); // rejeu : pas de doublon

    expect(DB::table('envoi_inscriptions_evenements')->where('inscription_id', $insA)->count())->toBe(1);
    expect(fn () => DB::table('envoi_inscriptions_evenements')->where('inscription_id', $insA)->update(['evenement' => 'stop']))->toThrow(QueryException::class);
});

test('I2 : opposition par le canal RÉEL (liste de suppression) => le collègue de la même entreprise est ARRÊTÉ, rien ne part', function () {
    $sid = seqSequence($this, 1);
    [$ent, $c1] = seqFiche($this, 'opp1@zz-client.example.invalid');
    [, $c2] = seqFiche($this, 'opp2@zz-client.example.invalid', $ent);
    ListeSuppression::inscrire('opp1@zz-client.example.invalid', 'complaint', 'zz-test');
    $i1 = seqInscrire($this, $sid, $c1);
    $i2 = seqInscrire($this, $sid, $c2);
    $seq = ($this->seq)();

    expect($seq->traiter($this->ws, $i1, $this->maintenant))->toBe(Sequenceur::REFUSE)
        ->and($seq->traiter($this->ws, $i2, $this->maintenant))->toBe(Sequenceur::ARRETEE)
        ->and(seqEtat($i2)->motif_arret)->toBe(Evenement::MOTIF_ENTREPRISE)
        ->and($this->faux->appels)->toBe(0);
});

test('I3 : une fenêtre qui commence avant 8 h (7 h–17 h) est rognée à 8 h–19 h : à 7 h 30 rien ne part, l\'échéance est 8 h, et ça part à 8 h', function () {
    $sid = seqSequence($this, 1, 7, 17);
    [, $c] = seqFiche($this, 'tot@zz-client.example.invalid');
    $this->maintenant = new DateTimeImmutable('2026-11-16 07:30:00', new DateTimeZone('Europe/Paris'));
    $ins = seqInscrire($this, $sid, $c);
    DB::table('envoi_inscriptions')->where('id', $ins)->update(['prochaine_echeance' => '2026-11-16 07:00:00+01']);

    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::HORS_FENETRE)
        ->and((new DateTimeImmutable(seqEtat($ins)->prochaine_echeance))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i'))->toBe('2026-11-16 08:00');

    $this->maintenant = new DateTimeImmutable('2026-11-16 08:00:00', new DateTimeZone('Europe/Paris'));
    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ENVOYE);
});

test('I3 : une fenêtre qui finit après 19 h est rognée à 19 h ; une fenêtre entièrement hors 8–19 h est refusée', function () {
    $f = new FenetreHoraire(7, 22);
    expect($f->debut)->toBe(8)->and($f->fin)->toBe(19);
    expect(fn () => new FenetreHoraire(20, 23))->toThrow(InvalidArgumentException::class);
    expect(fn () => new FenetreHoraire(0, 7))->toThrow(InvalidArgumentException::class);
});

test('I4 : une inscription qui lève une exception (composeur refusé, SQL en échec) n\'interrompt pas les autres, est marquée en erreur avec compteur, puis alerte après 5 échecs', function (Closure $panne) {
    Log::spy();
    $sid = seqSequence($this, 1);
    [, $cMauvais] = seqFiche($this, 'mauvais@zz-client.example.invalid');
    [, $cBon] = seqFiche($this, 'bon@zz-client.example.invalid');
    $mauvais = seqInscrire($this, $sid, $cMauvais);
    $bon = seqInscrire($this, $sid, $cBon);
    DB::table('envoi_inscriptions')->where('id', $mauvais)->update(['prochaine_echeance' => $this->lundi->modify('-1 hour')->format('c')]); // première de file
    $composeur = new class($panne) implements ComposeurSequence
    {
        public function __construct(private Closure $panne) {}

        public function composer(DemandeComposition $d): string
        {
            if (str_starts_with($d->destinataire, 'mauvais@')) {
                ($this->panne)();
            }

            return "Message-ID: {$d->messageId}\r\n\r\n{$d->corps}";
        }
    };

    $bilan = seqSurMesure($this, $composeur)->executer($this->ws, $this->maintenant);
    expect($bilan)->toBe([Sequenceur::ERREUR => 1, Sequenceur::ENVOYE => 1])
        ->and(seqEtat($bon)->statut)->toBe('terminee')
        ->and(seqEtat($mauvais)->statut)->toBe('active')
        ->and((int) seqEtat($mauvais)->echecs)->toBe(1)
        ->and(seqEtat($mauvais)->derniere_erreur)->not->toBeNull()
        ->and((new DateTimeImmutable(seqEtat($mauvais)->prochaine_echeance))->getTimestamp())->toBeGreaterThan($this->maintenant->getTimestamp())
        ->and(seqMessages($mauvais))->toBe([]); // la réservation a été défaite avec la transaction : l'étape reste reprenable
    Log::shouldNotHaveReceived('critical');

    for ($i = 2; $i <= 5; $i++) {
        $this->maintenant = $this->maintenant->modify('+1 hour'); // reste dans la fenêtre du lundi
        DB::table('envoi_inscriptions')->where('id', $mauvais)->update(['prochaine_echeance' => $this->maintenant->modify('-1 hour')->format('c')]);
        seqSurMesure($this, $composeur)->executer($this->ws, $this->maintenant);
    }
    expect((int) seqEtat($mauvais)->echecs)->toBe(5);
    Log::shouldHaveReceived('critical')->once();
})->with([
    'message refusé (L2)' => [fn () => throw new MessageRefuse(['Adresse postale absente'])],
    'erreur SQL (transaction avortée)' => [fn () => DB::select('SELECT * FROM table_qui_nexiste_pas_zz')],
]);

test('I5 : boîte en pause ou quota de chauffe atteint (GardeChauffe réelle branchée) => inscription active et reportée, rien d\'envoyé', function (string $cas) {
    config(['envoi.cle_empreinte' => 'cle-de-test-seulement']);
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), new ResolveurEnMemoire, null);
    $boite = (string) Str::uuid();
    DB::table('envoi_boites')->insert([
        'id' => $boite, 'workspace_id' => $this->ws, 'adresse' => 'from@' . SEQ_DOMAINE, 'hote' => 'smtp.example.test', 'port' => 587,
        'identifiants_chiffres' => 'chiffre-de-test',
    ]);
    if ($cas === 'pause') {
        app(DepotChauffe::class)->ouvrirPause($this->ws, DecisionFrein::REBONDS, null, $this->maintenant);
    } else {
        app(DepotChauffe::class)->incrementer($this->ws, $boite, $this->maintenant, 'autre', 'envoyes', 1000);
    }
    $sid = seqSequence($this, 1);
    [, $c] = seqFiche($this, "chauffe-{$cas}@zz-client.example.invalid");
    $ins = seqInscrire($this, $sid, $c);
    $passerelle = new PasserelleLocale($this->faux, new DepotSocleDb, true, 300, fn () => $this->maintenant, $this->ws, 300, fn () => WorkspaceContext::current(), $garde);
    $passerelle->sante(); // homme mort : un battement à l'heure courante
    $seq = seqSurMesure($this, null, 'ZZ Expediteur SAS', $garde);

    expect($seq->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::REPORTE)
        ->and(seqEtat($ins)->statut)->toBe('active')
        ->and(seqMessages($ins)[0]->statut)->toBe('reporte')
        ->and($this->faux->appels)->toBe(0);
})->with(['pause', 'quota']);

test('mineur : le verrou de ligne est RÉELLEMENT demandé (SELECT … FOR UPDATE sur envoi_inscriptions) à chaque traitement', function () {
    $sid = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'verrou@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $c);
    $requetes = [];
    DB::listen(function ($q) use (&$requetes) {
        $requetes[] = strtolower($q->sql);
    });
    ($this->seq)()->traiter($this->ws, $ins, $this->maintenant);

    $verrous = array_filter($requetes, fn (string $sql): bool => str_contains($sql, 'from "envoi_inscriptions"') && str_contains($sql, 'for update'));
    expect($verrous)->not->toBeEmpty();
});

test('mineur : un délai de 0 jour ouvré n\'est autorisé qu\'à l\'étape 1 ; les relances (étapes 2 et 3) attendent au moins 1 jour ouvré', function () {
    $sid = seqSequence($this, 2);
    expect(fn () => DB::transaction(fn () => DB::table('envoi_sequence_etapes')->where('sequence_id', $sid)->where('rang', 2)->update(['delai_jours_ouvres' => 0])))->toThrow(QueryException::class);
    DB::table('envoi_sequence_etapes')->where('sequence_id', $sid)->where('rang', 1)->update(['delai_jours_ouvres' => 0]); // l'étape 1 : tout de suite
    expect(DB::table('envoi_sequence_etapes')->where('sequence_id', $sid)->where('rang', 1)->value('delai_jours_ouvres'))->toBe(0);
});

test('mineur : l\'échéance d\'une relance utilise l\'instant passé en paramètre, pas l\'horloge de la machine', function () {
    $source = file_get_contents(base_path('app/Envoi/Sequence/Sequenceur.php'));
    expect($source)->not->toContain('now()');
});

test('M2 : les reports pour refus PASSAGER ont un plafond ; au plafond l\'inscription est arrêtée, le message refusé, et une alerte critique est émise', function () {
    Log::spy();
    $sid = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'plafond@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $c);
    DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['liste_noire' => true]); // refus passager permanent

    $plafond = Sequenceur::REPORTS_MAX;
    for ($i = 1; $i < $plafond; $i++) {
        $this->maintenant = $this->lundi->modify('+' . (7 * ($i - 1)) . ' days'); // un lundi à chaque tour
        expect(seqSurMesure($this)->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::REPORTE);
        expect(seqEtat($ins)->statut)->toBe('active');
    }
    Log::shouldNotHaveReceived('critical');

    $this->maintenant = $this->lundi->modify('+' . (7 * ($plafond - 1)) . ' days');
    expect(seqSurMesure($this)->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::REFUSE)
        ->and(seqEtat($ins)->statut)->toBe('arretee')
        ->and(seqEtat($ins)->motif_arret)->toBe(Sequenceur::MOTIF_REPORTS_EPUISES)
        ->and(seqMessages($ins)[0]->statut)->toBe('refuse')
        ->and($this->faux->appels)->toBe(0);
    Log::shouldHaveReceived('critical')->once();
});

test('M2 : un envoi réussi remet le compteur de reports à zéro', function () {
    $sid = seqSequence($this, 2);
    [, $c] = seqFiche($this, 'raz@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $c);
    DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['liste_noire' => true]);
    expect(seqSurMesure($this)->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::REPORTE);
    expect((int) seqEtat($ins)->reports)->toBe(1);

    DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['liste_noire' => false]);
    $this->maintenant = $this->lundi->modify('+1 day');
    expect(seqSurMesure($this)->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ENVOYE);
    expect((int) seqEtat($ins)->reports)->toBe(0);
});

test('M3 : la réservation d\'un message porte l\'instant PASSÉ en paramètre, pas le now() de la base', function () {
    $sid = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'horloge@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $c);
    DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update(['liste_noire' => true]); // le message reste « reporte », cree_le intact

    seqSurMesure($this)->traiter($this->ws, $ins, $this->maintenant);

    $m = seqMessages($ins)[0];
    expect((new DateTimeImmutable($m->cree_le))->getTimestamp())->toBe($this->maintenant->getTimestamp());
    expect((new DateTimeImmutable($m->maj_le))->getTimestamp())->toBe($this->maintenant->getTimestamp());
});

test('M1 : evenement() verrouille les inscriptions de l\'adresse puis de l\'entreprise dans l\'ordre des identifiants (ORDER BY id)', function () {
    $sid = seqSequence($this, 1);
    [$e, $c1] = seqFiche($this, 'ordre1@zz-client.example.invalid');
    [, $c2] = seqFiche($this, 'ordre2@zz-client.example.invalid', $e);
    $i1 = seqInscrire($this, $sid, $c1);
    seqInscrire($this, $sid, $c2);
    $requetes = [];
    DB::listen(function ($q) use (&$requetes) {
        $requetes[] = strtolower($q->sql);
    });

    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $i1, Evenement::OPPOSITION, $this->maintenant);

    $verrous = array_values(array_filter($requetes, fn (string $sql): bool => str_contains($sql, 'from "envoi_inscriptions"') && str_contains($sql, 'for update') && str_contains($sql, '"id" <>')));
    expect(count($verrous))->toBe(2);
    foreach ($verrous as $sql) {
        expect($sql)->toContain('order by "id"');
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// L8-F14a (B1) — « réserver, injecter, conclure » côté séquenceur : l'appel au serveur est HORS transaction.
// ---------------------------------------------------------------------------------------------------------------------

/** Séquenceur derrière un serveur fourni par le test (DNS du jour posé par `$this->seq`). */
function seqAvecServeur(object $t, ServeurEnvoi $serveur): Sequenceur
{
    ($t->seq)(); // pose les contrôles DNS du jour
    $s = new Sequenceur($serveur, new LecteurFicheDb, $t->composeur, new ParametresExpedition(SEQ_DOMAINE, 'from@' . SEQ_DOMAINE, 'ZZ Expediteur SAS'));

    return $s;
}

test('B1 : l\'injection a lieu HORS de toute transaction du séquenceur ; la réservation est déjà validée ; l\'étape n\'avance qu\'UNE fois', function () {
    $base = DB::transactionLevel(); // RefreshDatabase tient déjà un niveau : on compare à CE niveau, pas à 0
    $sid = seqSequence($this);
    [, $contact] = seqFiche($this, 'b1@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $contact);
    $vu = [];
    $seq = null;
    $espion = new ServeurEspion(function ($m, $s) use (&$vu, &$seq, $ins) {
        // Pendant l'appel : la réservation est LÀ (état « reserve »), et un second passage n'envoie rien (DEJA).
        $vu['message'] = DB::table('envoi_messages')->where('inscription_id', $ins)->where('etape', 1)->value('statut');
        $vu['second'] = $seq->traiter($this->ws, $ins, $this->maintenant);

        return ResultatInjection::accepte('id-b1');
    });
    $seq = seqAvecServeur($this, $espion);

    expect($seq->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ENVOYE);
    expect($espion->niveaux)->toBe([$base])  // ROUGE si l'injection est remise dans une DB::transaction (niveau $base + 1)
        ->and($espion->appels)->toBe(1)
        ->and($vu)->toBe(['message' => 'reserve', 'second' => Sequenceur::DEJA]);
    $etat = seqEtat($ins);
    expect((int) $etat->etape_courante)->toBe(2)                 // une seule avance
        ->and(count(seqMessages($ins)))->toBe(1)
        ->and(seqMessages($ins)[0]->statut)->toBe('envoye')
        ->and(seqMessages($ins)[0]->id_injection)->toBe('id-b1');
});

test('B1 : refus net, report ou issue incertaine du serveur => l\'étape n\'avance pas ; incertain = message gardé « reserve », inscription arrêtée, alerte APRÈS le COMMIT', function () {
    $base = DB::transactionLevel();
    $sid = seqSequence($this);

    // 1. Refus net (EnvoiRefuse) : message « reporte », reprenable, étape inchangée.
    [, $c1] = seqFiche($this, 'b1a@zz-client.example.invalid');
    $i1 = seqInscrire($this, $sid, $c1);
    $refus = seqAvecServeur($this, new ServeurEspion(fn () => throw EnvoiRefuse::toutArreter()));
    expect($refus->traiter($this->ws, $i1, $this->maintenant))->toBe(Sequenceur::REPORTE);
    expect(seqMessages($i1)[0]->statut)->toBe('reporte')->and((int) seqEtat($i1)->etape_courante)->toBe(1);

    // 2. Report INCERTAIN rapporté sans exception : étape inchangée, message « reporte » (la passerelle garde la place).
    [, $c2] = seqFiche($this, 'b1b@zz-client.example.invalid');
    $i2 = seqInscrire($this, $sid, $c2);
    $inc = seqAvecServeur($this, new ServeurEspion(fn () => ResultatInjection::incertain(new DateTimeImmutable('2026-11-17'))));
    expect($inc->traiter($this->ws, $i2, $this->maintenant))->toBe(Sequenceur::REPORTE);
    expect((int) seqEtat($i2)->etape_courante)->toBe(1);

    // 3. Exception inconnue (coupure) : le message ne repart JAMAIS ; alerte émise HORS transaction (niveau de base).
    [, $c3] = seqFiche($this, 'b1c@zz-client.example.invalid');
    $i3 = seqInscrire($this, $sid, $c3);
    $niveauAlerte = null;
    Log::shouldReceive('critical')->once()->andReturnUsing(function () use (&$niveauAlerte) {
        $niveauAlerte = DB::transactionLevel();
    });
    $coupe = seqAvecServeur($this, new ServeurEspion(fn () => throw new RuntimeException('coupure après envoi possible')));
    expect($coupe->traiter($this->ws, $i3, $this->maintenant))->toBe(Sequenceur::ARRETEE);
    expect(seqMessages($i3)[0]->statut)->toBe('reserve')
        ->and(seqEtat($i3)->statut)->toBe('arretee')->and(seqEtat($i3)->motif_arret)->toBe(Sequenceur::MOTIF_ISSUE_INCERTAINE)
        ->and((int) seqEtat($i3)->etape_courante)->toBe(1)
        ->and($niveauAlerte)->toBe($base);
});

test('B1 : refus DÉFINITIF du serveur (5xx) => refus ET arrêt, pas de report quotidien, sans doublon', function () {
    $sid = seqSequence($this);
    [, $c] = seqFiche($this, 'b1d@zz-client.example.invalid');
    $i = seqInscrire($this, $sid, $c);
    $seq = seqAvecServeur($this, new ServeurEspion(fn () => throw new RefusDefinitifServeur('5xx')));
    expect($seq->executer($this->ws, $this->maintenant))->toBe([Sequenceur::REFUSE => 1]);
    expect(seqMessages($i)[0]->statut)->toBe('refuse')->and(seqEtat($i)->statut)->toBe('arretee')->and((int) seqEtat($i)->etape_courante)->toBe(1);
});

test('B1 : un STOP reçu PENDANT l\'injection n\'est pas écrasé : le message est noté envoyé, mais l\'inscription arrêtée n\'avance pas', function () {
    $sid = seqSequence($this);
    [, $c] = seqFiche($this, 'b1e@zz-client.example.invalid');
    $i = seqInscrire($this, $sid, $c);
    $espion = new ServeurEspion(function () use ($i) {
        DB::table('envoi_inscriptions')->where('id', $i)->update(['statut' => 'arretee', 'motif_arret' => 'stop', 'arretee_le' => $this->maintenant->format('c')]);

        return ResultatInjection::accepte('id-e');
    });
    expect(seqAvecServeur($this, $espion)->traiter($this->ws, $i, $this->maintenant))->toBe(Sequenceur::ENVOYE);
    expect(seqMessages($i)[0]->statut)->toBe('envoye')->and(seqEtat($i)->statut)->toBe('arretee')->and((int) seqEtat($i)->etape_courante)->toBe(1);
});

test('B3 de bout en bout : la chaîne séquenceur -> passerelle -> simulateur injecte au niveau de base ; envelopper injecter() dans DB::transaction ROUGIT', function () {
    $sid = seqSequence($this, 1);
    [, $c] = seqFiche($this, 'b3@zz-client.example.invalid');
    $ins = seqInscrire($this, $sid, $c);
    $niveaux = [];
    $espion = new ServeurEspion(function ($m) use (&$niveaux) {
        $niveaux[] = DB::transactionLevel();

        return $this->faux->injecter($m); // le simulateur garde sa propre garde
    });
    $passerelle = new PasserelleLocale($espion, new DepotSocleDb, true, 300, fn () => $this->maintenant, $this->ws, 300, fn () => WorkspaceContext::current());
    ($this->seq)();
    $seq = new Sequenceur($passerelle, new LecteurFicheDb, $this->composeur, new ParametresExpedition(SEQ_DOMAINE, 'from@' . SEQ_DOMAINE, 'ZZ Expediteur SAS'));
    expect($seq->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ENVOYE);
    expect($niveaux)->toBe([DB::transactionLevel()])->and($espion->niveaux)->toBe([DB::transactionLevel()]);

    // Contre-épreuve : le même simulateur appelé DANS une transaction est refusé.
    $msg = new MessageAEnvoyer($this->ws, 'x@zz-client.example.invalid', 'from@' . SEQ_DOMAINE, 'S', 'C', 'seq-faux');
    expect(fn () => DB::transaction(fn () => $this->faux->injecter($msg)))->toThrow(ServeurEnvoiRefuse::class, 'transaction englobante');
});

// Audit L8-F14a, bloquant 1 : issue incertaine = arrêt SANS report ; refus certain = pas d'alerte « incertaine » ; reports plafonnés.
function seqViaPasserelle(object $t, ServeurEnvoi $serveur): Sequenceur
{
    ($t->seq)();

    return new Sequenceur(
        new PasserelleLocale($serveur, new DepotSocleDb, true, 300, fn () => $t->maintenant, $t->ws, 300, fn () => WorkspaceContext::current()),
        new LecteurFicheDb,
        $t->composeur,
        new ParametresExpedition(SEQ_DOMAINE, 'from@' . SEQ_DOMAINE, 'ZZ Expediteur SAS'),
    );
}

test('issue incertaine en SMTP direct : reportée UNE fois, puis le lendemain l\'inscription est ARRÊTÉE (pas de report sans fin), une alerte, aucun renvoi', function () {
    Log::spy();
    $sid = seqSequence($this);
    [, $c] = seqFiche($this, 'inc@zz-client.example.invalid');
    $i = seqInscrire($this, $sid, $c);
    $espion = new ServeurEspion(fn () => ResultatInjection::incertain(new DateTimeImmutable('tomorrow')));
    $seq = seqViaPasserelle($this, $espion);
    expect($seq->traiter($this->ws, $i, $this->maintenant))->toBe(Sequenceur::REPORTE);

    $this->maintenant = $this->lundi->modify('+1 day');
    $seq = seqViaPasserelle($this, $espion);
    expect($seq->traiter($this->ws, $i, $this->maintenant))->toBe(Sequenceur::ARRETEE)
        ->and(seqEtat($i)->motif_arret)->toBe(Sequenceur::MOTIF_ISSUE_INCERTAINE)
        ->and($espion->appels)->toBe(1);
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains($m, 'sequenceur_issue_incertaine'))->once();
});

test('refus CERTAIN avant tout envoi (EnvoiSmtpRefuse) : ni arrêt, ni alerte « incertaine » ; la place est rendue', function () {
    Log::spy();
    $sid = seqSequence($this);
    [, $c] = seqFiche($this, 'cer@zz-client.example.invalid');
    $i = seqInscrire($this, $sid, $c);
    $seq = seqViaPasserelle($this, new ServeurEspion(fn () => throw EnvoiSmtpRefuse::motif('enveloppe invalide')));
    expect($seq->traiter($this->ws, $i, $this->maintenant))->toBe(Sequenceur::REPORTE)
        ->and(seqEtat($i)->statut)->toBe('active')
        ->and((int) seqEtat($i)->reports)->toBe(1)
        ->and((new DepotSocleDb)->etatInjection($this->ws, 'seq-' . $i . '-1'))->toBeNull();
    Log::shouldNotHaveReceived('critical');
});

test('tout report issu de l\'injection passe par le plafond : au plafond, refus + arrêt + alerte', function () {
    Log::spy();
    $sid = seqSequence($this);
    [, $c] = seqFiche($this, 'plaf@zz-client.example.invalid');
    $i = seqInscrire($this, $sid, $c);
    DB::table('envoi_inscriptions')->where('id', $i)->update(['reports' => Sequenceur::REPORTS_MAX - 1]);
    $seq = seqViaPasserelle($this, new ServeurEspion(fn () => ResultatInjection::reporte(new DateTimeImmutable('tomorrow'))));
    expect($seq->traiter($this->ws, $i, $this->maintenant))->toBe(Sequenceur::REFUSE)
        ->and(seqEtat($i)->motif_arret)->toBe(Sequenceur::MOTIF_REPORTS_EPUISES);
    Log::shouldHaveReceived('critical')->once();
});

test('réservation d\'étape restée « reserve » (plantage entre la réservation et l\'appel) : arrêt + UNE alerte, jamais de renvoi', function () {
    Log::spy();
    $sid = seqSequence($this);
    [, $c] = seqFiche($this, 'orph@zz-client.example.invalid');
    $i = seqInscrire($this, $sid, $c);
    $vieux = $this->maintenant->modify('-3 hours')->format('c');
    DB::table('envoi_messages')->insert(['workspace_id' => $this->ws, 'inscription_id' => $i, 'etape' => 1, 'statut' => 'reserve', 'message_id' => '<o@' . SEQ_DOMAINE . '>', 'cree_le' => $vieux, 'maj_le' => $vieux]);
    $espion = new ServeurEspion(fn () => ResultatInjection::accepte('x'));
    expect(seqAvecServeur($this, $espion)->traiter($this->ws, $i, $this->maintenant))->toBe(Sequenceur::ARRETEE)
        ->and(seqEtat($i)->motif_arret)->toBe(Sequenceur::MOTIF_RESERVATION_ORPHELINE)
        ->and($espion->appels)->toBe(0);
    Log::shouldHaveReceived('critical')->once();
});

<?php

/**
 * Lot L5b (PLAN ADRESSES GÉNÉRALES, #680) — le séquenceur et le repli sur l'adresse générale : UN seul message, jamais de relance ;
 * règle R7 revérifiée à l'envoi (interrupteur éteint, contact nommé apparu) ⇒ arrêt `repli_generique_caduc`, jamais de report.
 * Transport : FauxServeurEnvoi derrière la PasserelleLocale. Fixtures FICTIVES (`.example.invalid`, adresses `prenom.nom@`). Propriétaire de la base, et les trois cas de sécurité rejoués SOUS LE RÔLE
 * APPLICATIF `pgsql_app` (SET LOCAL ROLE + RLS) en fin de fichier.
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
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SQR_DOMAINE = 'zz-envoi.example.invalid';
const SQR_GENERIQUE = 'contact@zz-repli.example.invalid';

beforeEach(function () {
    $this->ws = F::espace('zz-envoi-l5b');
    $this->lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($this->ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($this->ws, 'test-sequenceur-repli');
    WorkspaceContext::set($this->ws);
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $this->ws, 'nom' => SQR_DOMAINE, 'achete_le' => '2026-01-01',
        'dmarc_verifie_le' => $this->lundi->format('c'), 'spf_verifie_le' => $this->lundi->format('c'), 'dkim_verifie_le' => $this->lundi->format('c'),
    ]);
    $this->faux = new FauxServeurEnvoi;
    $this->maintenant = $this->lundi;
    $this->composeur = new class implements ComposeurSequence
    {
        public function composer(DemandeComposition $d): string
        {
            return "Message-ID: {$d->messageId}\r\nSubject: {$d->sujet}\r\n\r\n{$d->corps}";
        }
    };
    $this->seq = function (): Sequenceur {
        DB::table('envoi_domaines')->where('workspace_id', $this->ws)->update([
            'dmarc_verifie_le' => $this->maintenant->format('c'), 'spf_verifie_le' => $this->maintenant->format('c'), 'dkim_verifie_le' => $this->maintenant->format('c'),
        ]);

        return new Sequenceur(
            new PasserelleLocale($this->faux, new DepotSocleDb, true, 300, fn () => $this->maintenant, $this->ws, 300, fn () => WorkspaceContext::current()),
            new LecteurFicheDb,
            $this->composeur,
            new ParametresExpedition(SQR_DOMAINE, 'from@' . SQR_DOMAINE, 'ZZ Expediteur SAS'),
        );
    };
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

function sqrInterrupteur(string $ws, string $valeur): void
{
    WorkspaceContext::run($ws, function () use ($ws, $valeur): void {
        if (DB::table('envoi_reglages')->where('workspace_id', $ws)->where('cle', 'envoi.repli_generique')->exists()) {
            DB::table('envoi_reglages')->where('workspace_id', $ws)->where('cle', 'envoi.repli_generique')->update(['valeur' => $valeur]);
        } else {
            DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => 'envoi.repli_generique', 'valeur' => $valeur, 'auteur' => 'fondateur']);
        }
    });
}

function sqrSequence(object $t, int $etapes): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $id, 'workspace_id' => $t->ws, 'nom' => 'Séquence repli', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    foreach (array_slice([0, 2, 3], 0, $etapes) as $i => $delai) {
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $t->ws, 'sequence_id' => $id, 'rang' => $i + 1, 'delai_jours_ouvres' => $delai, 'sujet' => 'Sujet ' . ($i + 1), 'corps' => 'Corps ' . ($i + 1)]);
    }

    return $id;
}

/** Contact (et verdict d'adresse récent) dans l'entreprise $entreprise (créée si null) ; retourne [entreprise, contact]. */
function sqrContact(object $t, string $email, ?int $entreprise = null, string $verdict = 'valide', ?string $motif = null): array
{
    if ($entreprise === null) {
        $entreprise = F::fiche($t->ws, 'ZZ Entreprise', ['country_code' => 'FR', 'first_info_at' => $t->lundi->modify('-3 days')->format('c')]);
        F::lier($t->ws, $entreprise, F::tag($t->ws, FichesProtegees::TAG_FEDERATIONS));
    }
    $contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $t->ws, 'company_id' => $entreprise, 'last_name' => 'Zz-' . substr(md5($email), 0, 6), 'email' => $email, 'email_status' => 'valid',
        'created_at' => $t->lundi->modify('-3 days')->format('c'), 'first_info_at' => $t->lundi->modify('-3 days')->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $t->ws, 'empreinte_adresse' => ListeSuppression::empreinte($email),
        'verdict' => $verdict, 'motif' => $motif, 'verifie_le' => $t->lundi->modify('-2 days')->format('c'), 'source' => 'test']);

    return [$entreprise, $contact];
}

/** Une inscription de REPLI (une seule étape, `repli_generique = true`, interrupteur allumé) sur `contact@` ; retourne [entreprise, inscription]. */
function sqrRepli(object $t, array $plus = []): array
{
    sqrInterrupteur($t->ws, 'true');
    [$entreprise, $contact] = sqrContact($t, SQR_GENERIQUE, null, 'risque', 'generique');
    $id = (string) Str::uuid();
    DB::table('envoi_inscriptions')->insert($plus + [
        'id' => $id, 'workspace_id' => $t->ws, 'sequence_id' => sqrSequence($t, 1), 'contact_id' => $contact, 'entreprise_id' => $entreprise,
        'empreinte_adresse' => ListeSuppression::empreinte(SQR_GENERIQUE), 'repli_generique' => true,
        'prochaine_echeance' => $t->maintenant->format('c'),
        'version_figee' => json_encode(['fenetre' => ['debut' => 9, 'fin' => 17], 'etapes' => [['rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet 1', 'corps' => 'Corps 1']]]),
    ]);

    return [$entreprise, $id];
}

/** Un passage du séquenceur SOUS LE RÔLE APPLICATIF (non propriétaire, RLS active) ; le rôle est toujours rendu. */
function sqrApp(object $t, string $ins): string
{
    WorkspaceContext::set($t->ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return ($t->seq)()->traiter($t->ws, $ins, $t->maintenant);
    } finally {
        DB::statement('RESET ROLE');
    }
}

function sqrEtat(string $id): array
{
    $l = DB::table('envoi_inscriptions')->where('id', $id)->first();

    return [$l->statut, $l->motif_arret];
}

test('L5b : un seul message au repli, puis « terminee », et aucune relance même longtemps après', function () {
    [, $ins] = sqrRepli($this);

    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([Sequenceur::ENVOYE => 1]);
    expect(sqrEtat($ins))->toBe(['terminee', null]);

    $this->maintenant = $this->lundi->modify('+30 days');
    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([]);
    expect($this->faux->appels)->toBe(1)->and(DB::table('envoi_messages')->where('inscription_id', $ins)->count())->toBe(1);
});

test('L5b R7 : interrupteur éteint après l\'inscription ⇒ arrêt « repli_generique_caduc », rien ne part, jamais reporté', function () {
    [, $ins] = sqrRepli($this);
    sqrInterrupteur($this->ws, 'false');

    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ARRETEE);

    expect(sqrEtat($ins))->toBe(['arretee', 'repli_generique_caduc']);
    expect($this->faux->appels)->toBe(0)->and(DB::table('envoi_messages')->where('inscription_id', $ins)->count())->toBe(0);
});

test('L5b R7 : interrupteur supprimé de l\'espace ou allumé seulement ailleurs ⇒ arrêt (échec fermé, cloisonnement workspace_id)', function () {
    $autre = F::espace('zz-envoi-l5b-autre');
    sqrInterrupteur($autre, 'true');
    [, $ins] = sqrRepli($this);
    sqrInterrupteur($this->ws, 'false');

    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ARRETEE);
    expect(sqrEtat($ins))->toBe(['arretee', 'repli_generique_caduc']);
});

test('L5b R7 : un contact nommé (adresse nominative) apparu depuis l\'inscription ⇒ arrêt « repli_generique_caduc »', function () {
    [$entreprise, $ins] = sqrRepli($this);
    sqrContact($this, 'prenom.nom@zz-repli.example.invalid', $entreprise);

    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ARRETEE);

    expect(sqrEtat($ins))->toBe(['arretee', 'repli_generique_caduc']);
    expect($this->faux->appels)->toBe(0);
});

test('L5b R7 : un autre générique, un contact supprimé ou d\'une autre entreprise ne rendent PAS le repli caduc', function () {
    [$entreprise, $ins] = sqrRepli($this);
    sqrContact($this, 'info@zz-repli.example.invalid', $entreprise);
    [$autreEntreprise] = sqrContact($this, 'prenom.autre@zz-autre.example.invalid');
    [, $supprime] = sqrContact($this, 'prenom.supprime@zz-repli.example.invalid', $entreprise);
    DB::table('contacts')->where('id', $supprime)->update(['deleted_at' => $this->lundi->format('c')]);

    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ENVOYE);
    expect(sqrEtat($ins))->toBe(['terminee', null])->and($autreEntreprise)->not->toBe($entreprise);
});

test('L5b : opposition d\'une autre personne de l\'entreprise ⇒ arrêt, rien ne part', function () {
    [$entreprise, $ins] = sqrRepli($this);
    [, $contact] = sqrContact($this, 'prenom.nom@zz-repli.example.invalid', $entreprise);
    $autre = (new Inscriptions(new LecteurFicheDb))->inscrire($this->ws, sqrSequence($this, 1), $contact, $this->maintenant);
    (new Inscriptions(new LecteurFicheDb))->evenement($this->ws, $autre, Evenement::OPPOSITION, $this->maintenant);

    expect(DB::table('envoi_inscriptions')->where('id', $ins)->value('statut'))->toBe('arretee'); // déjà arrêtée par l'événement
    expect(($this->seq)()->executer($this->ws, $this->maintenant))->toBe([]);
    expect($this->faux->appels)->toBe(0);
});

test('L5b : défense — une inscription de repli au rang 2 est arrêtée (« etape_inconnue »), jamais envoyée', function () {
    [, $ins] = sqrRepli($this, ['etape_courante' => 2]);

    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ARRETEE);

    expect(sqrEtat($ins))->toBe(['arretee', 'etape_inconnue']);
    expect($this->faux->appels)->toBe(0);
});

test('L5b non-régression : une inscription ordinaire (3 étapes) n\'est pas touchée, interrupteur absent ou éteint', function () {
    foreach ([null, 'false'] as $etat) {
        if ($etat !== null) {
            sqrInterrupteur($this->ws, $etat);
        }
        $this->faux = new FauxServeurEnvoi;
        $email = 'ordinaire-' . ($etat ?? 'absent') . '@zz-client.example.invalid';
        [, $contact] = sqrContact($this, $email);
        $ins = (new Inscriptions(new LecteurFicheDb))->inscrire($this->ws, sqrSequence($this, 3), $contact, $this->maintenant);
        $this->maintenant = $this->lundi;
        DB::table('envoi_inscriptions')->where('id', $ins)->update(['prochaine_echeance' => $this->maintenant->format('c')]);

        expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::ENVOYE);
        expect(sqrEtat($ins))->toBe(['active', null])->and((int) DB::table('envoi_inscriptions')->where('id', $ins)->value('etape_courante'))->toBe(2);
    }
});

test('L5b non-régression : un `risque/generique` sur une inscription ORDINAIRE reste refusé par la porte, même interrupteur allumé', function () {
    sqrInterrupteur($this->ws, 'true');
    [, $contact] = sqrContact($this, SQR_GENERIQUE, null, 'risque', 'generique');
    $ins = (new Inscriptions(new LecteurFicheDb))->inscrire($this->ws, sqrSequence($this, 1), $contact, $this->maintenant);
    DB::table('envoi_inscriptions')->where('id', $ins)->update(['prochaine_echeance' => $this->maintenant->format('c')]);

    expect(($this->seq)()->traiter($this->ws, $ins, $this->maintenant))->toBe(Sequenceur::REFUSE);
    expect($this->faux->appels)->toBe(0);
});

test('L5b sous le rôle applicatif : un seul message au repli, puis « terminee »', function () {
    [, $ins] = sqrRepli($this);

    expect(sqrApp($this, $ins))->toBe(Sequenceur::ENVOYE);

    expect(sqrEtat($ins))->toBe(['terminee', null]);
    expect($this->faux->appels)->toBe(1)->and(DB::table('envoi_messages')->where('inscription_id', $ins)->count())->toBe(1);
});

test('L5b sous le rôle applicatif : interrupteur éteint ⇒ arrêt « repli_generique_caduc », rien ne part', function () {
    [, $ins] = sqrRepli($this);
    sqrInterrupteur($this->ws, 'false');

    expect(sqrApp($this, $ins))->toBe(Sequenceur::ARRETEE);

    expect(sqrEtat($ins))->toBe(['arretee', 'repli_generique_caduc']);
    expect($this->faux->appels)->toBe(0);
});

test('L5b sous le rôle applicatif : un contact nommé apparu ⇒ arrêt « repli_generique_caduc », rien ne part', function () {
    [$entreprise, $ins] = sqrRepli($this);
    sqrContact($this, 'prenom.nom@zz-repli.example.invalid', $entreprise);

    expect(sqrApp($this, $ins))->toBe(Sequenceur::ARRETEE);

    expect(sqrEtat($ins))->toBe(['arretee', 'repli_generique_caduc']);
    expect($this->faux->appels)->toBe(0);
});

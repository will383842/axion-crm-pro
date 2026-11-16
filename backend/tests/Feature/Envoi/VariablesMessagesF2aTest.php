<?php

/**
 * Envoi F2a-1 — variables des messages : liste fermée, nettoyage, lecteur minimal, branchement séquenceur + composeur.
 * Cahier F2a (PR #608), §2.2 à §2.5 et §2.8 (tests 1 à 9). Données FICTIVES (example.test), aucun réseau, aucun envoi réel.
 */

use App\Crm\FichesProtegees;
use App\Envoi\Campagnes\DepotModeles;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Message\LecteurVariables;
use App\Envoi\Message\LecteurVariablesDb;
use App\Envoi\Message\MessageRefuse;
use App\Envoi\Message\ValeursVariables;
use App\Envoi\Message\VariablesMessage;
use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\RegistreInjectionsSequence;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Sequence\SourceExpeditionDb;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ServeurEspion;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const F2A_ADRESSE = 'dest@client.example.test';

beforeEach(function () {
    $this->ws = F::espace('zz-variables-f2a');
    config(['envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test']]);
    $this->lundi = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('Europe/Paris'));
    FonctionsEspace::regler($this->ws, 'federations', true);
    (new DepotSocleDb)->eteindreTemoins($this->ws, 'test-variables-f2a');
    WorkspaceContext::set($this->ws);
    app(DepotReglages::class)->enregistrerIdentite($this->ws, [
        'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
        'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Source publique (article 14 du RGPD).',
    ], 'test');
    $jour = $this->lundi->format('c');
    DB::table('envoi_domaines')->insert(['workspace_id' => $this->ws, 'nom' => 'zz-f2a.example.test', 'achete_le' => '2026-01-01', 'dmarc_verifie_le' => $jour, 'spf_verifie_le' => $jour, 'dkim_verifie_le' => $jour]);
    DB::table('envoi_boites')->insert(['workspace_id' => $this->ws, 'adresse' => 'contact@zz-f2a.example.test', 'hote' => 'imap.example.test', 'port' => 587, 'identifiants_chiffres' => 'x']);

    $this->sid = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $this->sid, 'workspace_id' => $this->ws, 'nom' => 'Séquence test', 'fenetre_debut' => 9, 'fenetre_fin' => 17]);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $this->ws, 'sequence_id' => $this->sid, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'Sujet', 'corps' => 'Corps']);
    $this->entreprise = F::fiche($this->ws, 'Exemple SAS', ['country_code' => 'FR', 'first_info_at' => $this->lundi->modify('-3 days')->format('c')]);
    F::lier($this->ws, $this->entreprise, F::tag($this->ws, FichesProtegees::TAG_FEDERATIONS));
    $this->contact = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->ws, 'company_id' => $this->entreprise, 'first_name' => 'Jeanne', 'last_name' => 'Exemple', 'email' => F2A_ADRESSE, 'email_status' => 'valid',
        'created_at' => $this->lundi->modify('-3 days')->format('c'), 'first_info_at' => $this->lundi->modify('-3 days')->format('c'),
    ]);
    DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $this->ws, 'empreinte_adresse' => ListeSuppression::empreinte(F2A_ADRESSE), 'verdict' => 'valide', 'verifie_le' => $this->lundi->modify('-2 days')->format('c'), 'source' => 'test']);

    $this->espion = new ServeurEspion(fn (MessageAEnvoyer $m) => ResultatInjection::accepte($m->idInjection));
    /** Un cycle d'envoi du séquenceur de production ; $lecteur : lecteur de variables au choix (espion). */
    $this->cycle = function (?LecteurVariables $lecteur = null): array {
        // Le gabarit est FIGÉ à l'inscription : on inscrit après l'avoir posé.
        if (! DB::table('envoi_inscriptions')->where('workspace_id', $this->ws)->exists()) {
            (new Inscriptions(new LecteurFicheDb))->inscrire($this->ws, $this->sid, $this->contact, $this->lundi);
        }
        $source = app(SourceExpeditionDb::class);
        $seq = new Sequenceur($this->espion, new LecteurFicheDb, $source->composeurPour($this->ws), $source->parametresPour($this->ws), new RegistreInjectionsSequence, $lecteur);

        return $seq->executer($this->ws, $this->lundi);
    };
    /** Pose le gabarit de l'étape 1. */
    $this->gabarit = function (string $sujet, string $corps = 'Corps'): void {
        DB::table('envoi_sequence_etapes')->where('sequence_id', $this->sid)->where('rang', 1)->update(['sujet' => $sujet, 'corps' => $corps]);
    };
    /** Sujet et texte du message parti, dépliés et décodés. */
    $this->parti = function (): array {
        $brut = preg_replace('/\r\n[ \t]+/', ' ', $this->espion->recus[0]->corps);
        [$tete, $reste] = explode("\r\n\r\n", $brut, 2);
        preg_match('/^Subject: (.*)$/m', $tete, $m);

        return ['tete' => $tete, 'sujet' => trim($m[1] ?? ''), 'corps' => quoted_printable_decode($reste)];
    };
    $this->roleApp = fn () => DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('1. prénom du contact : « Bonjour {{prenom|Madame, Monsieur}} » donne « Bonjour Jeanne »', function () {
    ($this->gabarit)('Bonjour {{prenom|Madame, Monsieur}}', 'Cher {{prenom|Madame, Monsieur}},');

    expect(($this->cycle)())->toHaveKey('envoye', 1);
    $p = ($this->parti)();
    expect($p['sujet'])->toBe('Bonjour Jeanne')->and($p['corps'])->toContain('Cher Jeanne,');
});

test('1b. même scénario sous le rôle applicatif (pgsql_app)', function () {
    ($this->roleApp)();
    ($this->gabarit)('Bonjour {{prenom|Madame, Monsieur}}');

    expect(($this->cycle)())->toHaveKey('envoye', 1);
    expect(($this->parti)()['sujet'])->toBe('Bonjour Jeanne');
});

test('2. prénom vide : repli ; JEANNE : Jeanne ; enseigne vide : dénomination ; enseigne présente : enseigne', function (string $champ, ?string $valeur, string $attendu) {
    ($this->gabarit)('X {{prenom|REPLI}} / {{organisation|ORG}}');
    if ($champ === 'prenom') {
        DB::table('contacts')->where('id', $this->contact)->update(['first_name' => $valeur]);
    } else {
        DB::table('companies')->where('id', $this->entreprise)->update(['enseigne' => $valeur]);
    }

    expect(($this->cycle)())->toHaveKey('envoye', 1);
    expect(($this->parti)()['sujet'])->toBe($attendu);
})->with([
    'prénom vide' => ['prenom', '', 'X REPLI / Exemple SAS'],
    'prénom blanc' => ['prenom', '   ', 'X REPLI / Exemple SAS'],
    'prénom nul' => ['prenom', null, 'X REPLI / Exemple SAS'],
    'prénom en majuscules' => ['prenom', 'JEANNE', 'X Jeanne / Exemple SAS'],
    'enseigne vide' => ['enseigne', '', 'X Jeanne / Exemple SAS'],
    'enseigne blanche' => ['enseigne', '  ', 'X Jeanne / Exemple SAS'],
    'enseigne présente' => ['enseigne', 'Enseigne Test', 'X Jeanne / Enseigne Test'],
]);

test('3. ville « Grenoble » ou organisation « exemple.example.test/x » : repli, message composé, inscription envoyée (pas d\'échec)', function () {
    ($this->gabarit)('Ville {{ville|VREPLI}} / {{organisation|OREPLI}}');
    DB::table('companies')->where('id', $this->entreprise)->update(['city' => 'Grenoble', 'enseigne' => 'exemple.example.test/x']);

    expect(($this->cycle)())->toHaveKey('envoye', 1);
    expect(($this->parti)()['sujet'])->toBe('Ville VREPLI / OREPLI')
        ->and(DB::table('envoi_inscriptions')->where('workspace_id', $this->ws)->value('statut'))->not->toBe('echouee');
});

test('3b. même scénario sous le rôle applicatif (pgsql_app)', function () {
    ($this->roleApp)();
    ($this->gabarit)('Ville {{ville|VREPLI}}');
    DB::table('companies')->where('id', $this->entreprise)->update(['city' => 'Grenoble']);

    expect(($this->cycle)())->toHaveKey('envoye', 1);
    expect(($this->parti)()['sujet'])->toBe('Ville VREPLI');
});

test('3c. nettoyage : écartées (repli) si jamais valides, jamais tronquées, jamais journalisées', function () {
    $r = ValeursVariables::nettoyer([
        'prenom' => str_repeat('a', 41), 'nom' => 'a{{b', 'fonction' => 'x@y', 'organisation' => 'axion-ia.com', 'ville' => 'www.exemple', 'inconnu' => 'zzz',
    ]);
    expect($r['valeurs'])->toBe([])->and($r['ecartees'])->toEqualCanonicalizing(['prenom', 'nom', 'fonction', 'organisation', 'ville']);

    $r = ValeursVariables::nettoyer(['prenom' => str_repeat('a', 40), 'fonction' => "  Directrice \u{0007} générale  ", 'ville' => '<b>', 'nom' => 'a>b']);
    expect($r['valeurs'])->toBe(['prenom' => 'A' . str_repeat('a', 39), 'fonction' => 'Directrice générale'])->and($r['ecartees'])->toEqualCanonicalizing(['ville', 'nom']);

    // Un nom d'organisation à la façon d'un domaine ou d'un chemin est écarté (V6) ; « S.A.S. » reste permis.
    expect(ValeursVariables::nettoyer(['organisation' => 'exemple.example.test/x', 'ville' => 'Test.fr'])['ecartees'])->toBe(['organisation', 'ville'])
        ->and(ValeursVariables::nettoyer(['organisation' => 'Exemple S.A.S.'])['valeurs'])->toBe(['organisation' => 'Exemple S.A.S.']);
    expect(ValeursVariables::nettoyer(['prenom' => 'jean-PIERRE'])['valeurs'])->toBe(['prenom' => 'jean-PIERRE'])
        ->and(ValeursVariables::nettoyer(['prenom' => 'JEAN-PIERRE'])['valeurs'])->toBe(['prenom' => 'Jean-Pierre'])
        ->and(ValeursVariables::nettoyer(['nom' => 'de la Tour'])['valeurs'])->toBe(['nom' => 'de la Tour']);
});

test('4. repli « l\'équipe » : le HTML porte « l&#039;équipe » une seule fois échappé', function () {
    ($this->gabarit)('Sujet', 'Bonjour {{prenom|l\'équipe}} & <b>merci</b>');
    DB::table('contacts')->where('id', $this->contact)->update(['first_name' => null]);

    expect(($this->cycle)())->toHaveKey('envoye', 1);
    $html = ($this->parti)()['corps'];
    expect(substr_count($html, 'l&#039;équipe'))->toBe(1)
        ->and($html)->not->toContain('&amp;#039;')
        ->and($html)->toContain('&amp; &lt;b&gt;merci&lt;/b&gt;');
});

test('5. valeur avec saut de ligne dans le sujet : aucun en-tête injecté', function () {
    ($this->gabarit)('Bonjour {{prenom|REPLI}}');
    DB::table('contacts')->where('id', $this->contact)->update(['first_name' => "Jeanne\r\nBcc: autre"]);

    expect(($this->cycle)())->toHaveKey('envoye', 1);
    $p = ($this->parti)();
    expect($p['tete'])->not->toMatch('/^Bcc:/mi')->and($p['sujet'])->toBe('Bonjour Jeanne Bcc: autre');
    expect(ValeursVariables::nettoyer(['fonction' => "a\nb\r\nc\x00d"])['valeurs']['fonction'])->toBe('a b cd');
});

test('6. gabarit sans variable : zéro requête du lecteur', function () {
    $espion = new class implements LecteurVariables
    {
        public int $appels = 0;

        public function pourContacts(string $ws, array $contactIds, array $noms): array
        {
            $this->appels++;

            return [];
        }
    };
    ($this->gabarit)('Sujet fixe', 'Corps fixe');
    expect(($this->cycle)($espion))->toHaveKey('envoye', 1)->and($espion->appels)->toBe(0);
});

test('6b. le lecteur ne lit QUE les colonnes des noms demandés', function () {
    DB::connection()->enableQueryLog();
    $r = (new LecteurVariablesDb)->pourContacts($this->ws, [$this->contact], ['prenom']);
    $sql = strtolower(implode("\n", array_column(DB::connection()->getQueryLog(), 'query')));

    expect($r)->toBe([$this->contact => ['prenom' => 'Jeanne']])
        ->and($sql)->toContain('first_name')
        ->and($sql)->not->toContain('last_name')->and($sql)->not->toContain('city')->and($sql)->not->toContain('title')->and($sql)->not->toContain('enseigne');
    expect(fn () => (new LecteurVariablesDb)->pourContacts($this->ws, [$this->contact], ['email']))->toThrow(InvalidArgumentException::class);
    expect((new LecteurVariablesDb)->pourContacts($this->ws, [], ['prenom']))->toBe([]);
});

test('6c. liste fermée : utilisees et inconnues', function () {
    expect(VariablesMessage::NOMS)->toBe(['prenom', 'nom', 'organisation', 'ville', 'fonction'])
        ->and(VariablesMessage::utilisees('A {{ville|x}} {{prenom|y}}', 'B {{ville|z}} {{societe|q}}'))->toBe(['prenom', 'societe', 'ville'])
        ->and(VariablesMessage::inconnues('A {{societe|x}} {{prenom|y}}', '{{societe|z}}'))->toBe(['Variable {{societe}} inconnue : champs permis : prenom, nom, organisation, ville, fonction.']);
});

test('7. message type avec {{societe|x}} : refusé à l\'écriture et à la validation ; version figée ancienne : envoyée avec « x »', function () {
    $svc = app(ServiceMessagesTypes::class);
    expect(fn () => $svc->creer($this->ws, 'Message inconnu', 'Objet {{societe|x}}', 'Corps', 'test'))->toThrow(MessageRefuse::class);

    $m = $svc->creer($this->ws, 'Message ok', 'Objet {{prenom|x}}', 'Corps {{prenom|x}}', 'test');
    expect(fn () => $svc->enregistrer($this->ws, $m['id'], 'Objet {{societe|x}}', 'Corps', 'test'))->toThrow(MessageRefuse::class);

    // Version plus ancienne que la liste fermée, écrite hors service (cas d'une version déjà figée) : la validation la refuse.
    app(DepotModeles::class)->nouvelleVersion($this->ws, $m['id'], 'Objet {{societe|x}}', 'Corps', 'test');
    $v = $svc->lire($this->ws, $m['id']);
    expect(fn () => $svc->valider($this->ws, $m['id'], $v['version_courante']))->toThrow(MessageRefuse::class);

    // À l'envoi, la version figée avec un nom inconnu n'échoue pas : le repli part.
    ($this->gabarit)('Offre {{societe|x}}');
    expect(($this->cycle)())->toHaveKey('envoye', 1);
    expect(($this->parti)()['sujet'])->toBe('Offre x');
});

test('8. cloisonnement : pourContacts(B, [contact de A]) ne rend rien (propriétaire)', function () {
    $b = F::espace('zz-variables-f2a-b');
    expect((new LecteurVariablesDb)->pourContacts($b, [$this->contact], ['prenom', 'organisation']))->toBe([])
        ->and((new LecteurVariablesDb)->pourContacts($this->ws, [$this->contact], ['prenom', 'organisation']))->toBe([$this->contact => ['prenom' => 'Jeanne', 'organisation' => 'Exemple SAS']]);

    // Entreprise d'un AUTRE espace rattachée au contact de A : la jointure filtre aussi `o.workspace_id`.
    $ob = F::fiche($b, 'Autre SAS');
    DB::table('contacts')->where('id', $this->contact)->update(['company_id' => $ob]);
    expect((new LecteurVariablesDb)->pourContacts($this->ws, [$this->contact], ['organisation']))->toBe([]);
});

test('8b. cloisonnement sous le rôle applicatif (pgsql_app)', function () {
    $b = F::espace('zz-variables-f2a-b');
    ($this->roleApp)();
    expect((new LecteurVariablesDb)->pourContacts($b, [$this->contact], ['prenom']))->toBe([])
        ->and((new LecteurVariablesDb)->pourContacts($this->ws, [$this->contact], ['prenom']))->toBe([$this->contact => ['prenom' => 'Jeanne']]);
});

test('8c. un contact supprimé (deleted_at) n\'est pas lu', function () {
    DB::table('contacts')->where('id', $this->contact)->update(['deleted_at' => now()]);
    expect((new LecteurVariablesDb)->pourContacts($this->ws, [$this->contact], ['prenom']))->toBe([]);
});

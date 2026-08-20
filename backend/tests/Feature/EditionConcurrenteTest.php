<?php

/**
 * G43-005 (S0) — DEUX SAISIES SIMULTANEES, UNE DISPARAIT EN SILENCE.
 *
 * Constat de l'audit : « aucun mecanisme ne detecte une edition concurrente :
 * une saisie disparait en silence, et les deux enregistrements repondent
 * "succes" ». Site nomme : `CompaniesController::update` et les 11 routes PUT de
 * `routes/api.php`.
 *
 * ── CE QUI A ETE MESURE SUR CE DEPOT LE 2026-08-20 ────────────────────────────
 *
 * 1. `CompaniesController::update` fait `$company->update($validated)` sur ce
 *    que la resolution de route a charge. Aucune comparaison avec l'etat que le
 *    client croyait modifier. Le dernier arrive gagne, et le premier n'apprend
 *    jamais que sa saisie a ete effacee : il a recu 200.
 *
 * 2. Des 11 routes PUT citees par l'audit, SEULES QUATRE ecrivent reellement
 *    aujourd'hui — companies, tags, audiences, campaigns. Les sept autres
 *    (workspace, users, contacts, llm/use-cases x2, proxy-providers, rotations)
 *    repondent `notImplemented(...)`, donc 501. Le perimetre reel du defaut est
 *    de quatre routes, pas onze. (Mesure : `awk '/public function update/,/^ }/'`
 *    sur les dix controleurs concernes.)
 *
 * 3. `companies.updated_at` N'EST PAS ECRIT PAR LARAVEL A L'UPDATE. Un trigger
 *    Postgres le fait :
 *
 *      CREATE TRIGGER companies_updated_at BEFORE UPDATE ON public.companies
 *      FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at()  -- NEW.updated_at = now()
 *
 *    Et `now()` en Postgres est l'heure de DEBUT DE TRANSACTION. Deux ecritures
 *    dans une meme transaction portent donc le MEME `updated_at`, a la
 *    microseconde pres : un jeton fonde dessus n'y voit rien. A l'INSERT, en
 *    revanche, c'est Laravel qui ecrit la colonne, au format `Y-m-d H:i:s`
 *    (`Grammar::getDateFormat()`, non surcharge par le pilote pgsql) — donc
 *    tronquee a la seconde. La colonne melange deux precisions selon qui l'ecrit.
 *
 *    C'EST CE QUI A FAIT ROUGIR MA PREMIERE CORRECTION, une fois sur deux : elle
 *    comparait les horodatages A LA SECONDE, et confondait alors « 15:08:10.000000 »
 *    (etat lu) avec « 15:08:10.614374 » (etat d'apres). La garde l'a attrapee.
 *    C'est aussi pourquoi le jeton fort (`If-Match`) est une empreinte du CONTENU
 *    de la ligne, pas une date : il ne depend ni de l'horloge, ni du decoupage en
 *    transactions, ni de la precision du client. Le test « MEME TRANSACTION »
 *    plus bas le mesure au lieu de le supposer.
 *
 * 4. ⚠️ LE `updated_at` RENDU PAR LE PUT EST FAUX, ET IL L'ETAIT DEJA. La reponse
 *    serialise le modele EN MEMOIRE : son `updated_at` est celui que Laravel
 *    croit avoir ecrit, pas celui que le trigger a pose. Mesure : corps
 *    « 15:08:11.000000 », base « 15:08:10.614374 ». Idem pour les colonnes
 *    GENERATED (`denomination_normalized` rendait encore la valeur d'avant). Ce
 *    lot NE CORRIGE PAS ce corps — cela changerait ce que voit l'appelant — mais
 *    en tire une consequence : le jeton se prend dans l'en-tete `ETag` (calcule
 *    sur une RELECTURE) ou dans un GET, jamais dans le corps d'un PUT.
 *
 * ── POURQUOI CETTE GARDE NE PEUT PAS VERDIR A VIDE ────────────────────────────
 *
 * - TEMOIN DE PRESENCE : la fiche existe, la route repond 200, et l'ecriture
 *   ATTERRIT EN BASE. Sans lui, un 404 (fiche absente, mauvais espace) ou une
 *   route morte ferait passer « la seconde saisie est refusee » au vert par
 *   ABSENCE d'ecriture.
 * - TEMOIN ANTI-409-SYSTEMATIQUE : un jeton A JOUR doit passer en 200 et
 *   l'ecriture doit atterrir. Sans lui, « refuser tout le monde tout le temps »
 *   serait un vert — et un produit inutilisable.
 * - TEMOIN DE COMPATIBILITE : sans jeton, le comportement est INCHANGE (200,
 *   dernier arrive gagne). C'est la promesse du mandat : aucun client existant
 *   ne casse. Une pose de verrouillage OBLIGATOIRE le ferait rougir.
 * - TEMOIN DE NON-CONTAMINATION : `PUT /tags/{tag}` (l'une des trois autres
 *   routes qui ecrivent vraiment) reste inchangee, meme avec un `If-Match`
 *   perime. Le correctif porte sur UNE route, et cette garde le prouve au lieu
 *   de le promettre.
 * - TEMOIN DE CONVERGENCE : apres un 409, le jeton rendu permet de rejouer et de
 *   reussir. Sans lui, « refuser » pourrait etre un cul-de-sac ou le client
 *   boucle — un vert qui cacherait un produit bloque.
 * - TEMOIN DE CLOISONNEMENT : sur la fiche d'un AUTRE espace, la reponse reste
 *   404 meme avec un jeton perime. Un 409 y confirmerait l'existence de la fiche
 *   et rouvrirait la fuite fermee par B12-001 / F36-005.
 */

use App\Models\Company;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Les deux saisies. Elles portent des sous-chaines SANS ACCENT : un controle sur
// du texte francais accentue se joue autrement selon l'encodage de la reponse.
const CONC_SAISIE_A_NOM = 'ACME Ile de France';
const CONC_SAISIE_A_TEL = '+33111111111';
const CONC_SAISIE_B_NOM = 'ACME Grand Ouest';
const CONC_SAISIE_B_TEL = '+33222222222';
const CONC_NOM_INITIAL = 'ACME';
const CONC_TEL_INITIAL = '+33100000000';

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->espace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-concurrence', 'name' => 'WS', 'settings' => [],
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->espace->id);

    $this->entreprise = Company::create([
        'workspace_id' => $this->espace->id,
        'siren' => '987654321',
        'denomination' => CONC_NOM_INITIAL,
        'phone' => CONC_TEL_INITIAL,
        'signals' => [], 'metadata' => [],
    ]);

    $this->actingAs(utilisateurConcurrence($this->espace->id, 'owner'));
});

function utilisateurConcurrence(string $espaceId, ?string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => Str::uuid() . '@example.com',
        'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $espaceId,
        'first_login_completed_at' => now(),
    ]);

    if ($role !== null) {
        $u->assignRole($role);
    }

    return $u;
}

/** Le formulaire complet, tel qu'une interface le renvoie : tous les champs. */
function formulaireConcurrence(string $nom, string $tel): array
{
    return ['denomination' => $nom, 'phone' => $tel];
}

// ═══════════════════════════════════════════════════════════════════════════
// TEMOINS — ils passent AVANT correction comme APRES. Ils interdisent les deux
// faux verts : « rien n'existe » et « tout est refuse ».
// ═══════════════════════════════════════════════════════════════════════════

test('TEMOIN de presence : la route ecrit vraiment, et la fiche existe en base', function () {
    // Sans ce test, un 404, un 501 ou une fiche absente ferait verdir « la
    // seconde saisie est refusee » par ABSENCE d'ecriture, pas par verrouillage.
    $reponse = $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        formulaireConcurrence(CONC_SAISIE_A_NOM, CONC_SAISIE_A_TEL),
    )->assertOk();

    expect($reponse->json('denomination'))->toBe(CONC_SAISIE_A_NOM);

    $this->entreprise->refresh();
    expect($this->entreprise->denomination)->toBe(CONC_SAISIE_A_NOM);
    expect($this->entreprise->phone)->toBe(CONC_SAISIE_A_TEL);
});

test('TEMOIN de compatibilite : SANS jeton, deux saisies concurrentes repondent 200 et la premiere disparait', function () {
    // C'EST LE DEFAUT G43-005 LUI-MEME, et c'est aussi la promesse de
    // compatibilite : un client qui n'envoie pas de jeton garde EXACTEMENT ce
    // comportement. Ce test doit donc rester vert apres correction. S'il rougit,
    // c'est qu'on a impose le verrouillage a tout le monde — ce que le mandat
    // interdit (11 routes, clients existants).
    $etatLuParLesDeux = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk();
    expect($etatLuParLesDeux->json('denomination'))->toBe(CONC_NOM_INITIAL);

    // Le commercial A enregistre sa fiche.
    $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        formulaireConcurrence(CONC_SAISIE_A_NOM, CONC_SAISIE_A_TEL),
    )->assertOk();

    // Le commercial B, parti du MEME etat lu avant, enregistre la sienne.
    $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        formulaireConcurrence(CONC_SAISIE_B_NOM, CONC_SAISIE_B_TEL),
    )->assertOk();

    // Les deux ont lu « succes ». Une seule saisie survit. Personne n'est averti.
    $this->entreprise->refresh();
    expect($this->entreprise->denomination)->toBe(CONC_SAISIE_B_NOM);
    expect($this->entreprise->phone)->toBe(CONC_SAISIE_B_TEL);
    expect($this->entreprise->denomination)->not->toBe(CONC_SAISIE_A_NOM);
});

test('TEMOIN anti-409-systematique : un jeton A JOUR passe en 200 et l ecriture atterrit', function () {
    // « Refuser tout le monde tout le temps » verdirait les tests de conflit et
    // rendrait le produit inutilisable. Ce temoin l'interdit.
    $lecture = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk();
    $jetonAJour = $lecture->json('updated_at');
    expect($jetonAJour)->not->toBeNull();

    $this->withHeaders(['If-Match' => (string) jetonFortConcurrence($this)])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_A_NOM, CONC_SAISIE_A_TEL),
        )->assertOk();

    $this->entreprise->refresh();
    expect($this->entreprise->denomination)->toBe(CONC_SAISIE_A_NOM);
});

// ═══════════════════════════════════════════════════════════════════════════
// LE DEFAUT — la perte silencieuse, quand le client A DIT sur quel etat il
// travaillait. Ces tests rougissent AVANT correction.
// ═══════════════════════════════════════════════════════════════════════════

test('la reponse 200 porte un en-tete ETag, sinon aucun client ne peut obtenir de jeton', function () {
    // Un verrou dont le jeton n'est jamais servi est un verrou sans clef :
    // personne ne pourrait s'en servir, et le mecanisme serait du decor.
    $reponse = $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        formulaireConcurrence(CONC_SAISIE_A_NOM, CONC_SAISIE_A_TEL),
    )->assertOk();

    $etag = $reponse->headers->get('ETag');
    $this->assertIsString($etag, "La reponse 200 de PUT /companies/{id} ne porte pas d'en-tete ETag.");
    expect($etag)->not->toBe('');

    // Et ce jeton est bien celui de l'etat COURANT : le rejouer immediatement
    // doit passer, sinon le client boucle sur des 409 apres chaque succes.
    $this->withHeaders(['If-Match' => $etag])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_B_NOM, CONC_SAISIE_B_TEL),
        )->assertOk();
});

test('If-Match perime : la seconde saisie est REFUSEE en 409 et la premiere survit', function () {
    // Les deux commerciaux ouvrent la meme fiche : meme jeton.
    $jetonPartage = (string) jetonFortConcurrence($this);
    expect($jetonPartage)->not->toBe('');

    // A enregistre : la fiche change, donc le jeton de B devient perime.
    $this->withHeaders(['If-Match' => $jetonPartage])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_A_NOM, CONC_SAISIE_A_TEL),
        )->assertOk();

    // B enregistre a son tour, avec le jeton d'AVANT.
    $conflit = $this->withHeaders(['If-Match' => $jetonPartage])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_B_NOM, CONC_SAISIE_B_TEL),
        );

    $conflit->assertStatus(409);
    expect($conflit->json('error'))->toBe('version_conflict');

    // La saisie de A est INTACTE : rien n'a ete ecrase en silence.
    $this->entreprise->refresh();
    expect($this->entreprise->denomination)->toBe(CONC_SAISIE_A_NOM);
    expect($this->entreprise->phone)->toBe(CONC_SAISIE_A_TEL);
});

test('updated_at perime dans le corps : la seconde saisie est REFUSEE en 409', function () {
    // Forme degradee mais utile : un client qui renvoie l'horodatage lu.
    //
    // ⚠️ L'horodatage lu ici est celui de l'INSERT, ecrit par Laravel au format
    // `Y-m-d H:i:s` — donc tronque a la seconde, `.000000`. Apres la premiere
    // mise a jour, c'est le TRIGGER Postgres `companies_updated_at` qui pose la
    // valeur (`NEW.updated_at = now()`), avec ses microsecondes. Les deux valeurs
    // different donc TOUJOURS, et le test ne depend d'aucune temporisation.
    $etatLu = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk();
    $horodatageLu = $etatLu->json('updated_at');
    expect($horodatageLu)->not->toBeNull();

    $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        formulaireConcurrence(CONC_SAISIE_A_NOM, CONC_SAISIE_A_TEL),
    )->assertOk();

    // MESURE, pas supposition : sans ce controle, un `updated_at` reste identique
    // ferait verdir un 200 par ABSENCE de conflit reel plutot que par detection.
    $apresA = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk()->json('updated_at');
    expect($apresA)->not->toBe($horodatageLu);

    $conflit = $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        formulaireConcurrence(CONC_SAISIE_B_NOM, CONC_SAISIE_B_TEL) + ['updated_at' => $horodatageLu],
    );

    $conflit->assertStatus(409);
    expect($conflit->json('error'))->toBe('version_conflict');

    $this->entreprise->refresh();
    expect($this->entreprise->denomination)->toBe(CONC_SAISIE_A_NOM);
});

test('TEMOIN : un updated_at A JOUR passe, a la precision entiere comme tronque a la seconde', function () {
    // DEUX FAUX 409 A INTERDIRE, et ils ont des causes differentes.
    //
    // 1. La valeur entiere doit passer, sinon le mecanisme est inutilisable.
    // 2. La MEME valeur tronquee a la seconde doit passer AUSSI : toute date
    //    JavaScript est arrondie a la milliseconde, et un client qui arrondit
    //    recevrait sinon un 409 perpetuel — il ne pourrait plus JAMAIS
    //    enregistrer. La comparaison se fait donc a la precision que le client
    //    fournit (cf. `VerrouOptimiste::refuserSiVersionPerimee`).
    $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        ['denomination' => 'Etat de depart'],
    )->assertOk();

    $aJour = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk()->json('updated_at');
    $this->assertIsString($aJour);
    // TEMOIN de la mesure : cette valeur porte bien des microsecondes non nulles
    // (c'est le trigger qui l'a posee). Sans cela, le second cas ci-dessous ne
    // testerait rien — tronquer `.000000` ne change rien.
    $this->assertStringNotContainsString('.000000', $aJour);

    $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        ['denomination' => 'Precision entiere'] + ['updated_at' => $aJour],
    )->assertOk();

    // Meme instant, tronque a la seconde : « 2026-08-20T15:08:10.614374Z » →
    // « 2026-08-20T15:08:10Z ». L'etat en base n'a pas change entre les deux
    // requetes : le trigger repose `now()`, constant dans une transaction.
    $tronque = preg_replace('/\.\d+Z$/', 'Z', $aJour);
    $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        ['denomination' => 'Precision seconde'] + ['updated_at' => $tronque],
    )->assertOk();

    expect($this->entreprise->fresh()->denomination)->toBe('Precision seconde');
});

test('MEME TRANSACTION : If-Match detecte encore le conflit, la ou updated_at est aveugle', function () {
    // POURQUOI CE TEST EXISTE, ET CE QU'IL MESURE VRAIMENT.
    //
    // `companies.updated_at` n'est pas ecrit par Laravel a l'UPDATE : un trigger
    // Postgres pose `NEW.updated_at = now()`. Et `now()` en Postgres est l'heure
    // de DEBUT DE TRANSACTION, pas l'instant de l'instruction. Deux ecritures
    // dans une meme transaction portent donc le MEME horodatage, a la
    // microseconde pres — un jeton fonde dessus n'y voit rien.
    //
    // La suite tourne sous `RefreshDatabase`, donc TOUT le test vit dans une
    // seule transaction : c'est ce qui rend la demonstration possible ici. En
    // production, deux requetes concurrentes sont deux transactions et
    // l'horodatage differe ; mais deux ecritures groupees dans une transaction
    // restent indiscernables. Le jeton fort, lui, est une empreinte du CONTENU :
    // il ne depend ni de l'horloge ni du decoupage en transactions.
    $this->putJson(
        "/api/v1/companies/{$this->entreprise->id}",
        ['denomination' => 'Etat de depart'],
    )->assertOk();

    $avant = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk()->json('updated_at');
    $jetonPartage = (string) jetonFortConcurrence($this);

    $this->withHeaders(['If-Match' => $jetonPartage])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_A_NOM, CONC_SAISIE_A_TEL),
        )->assertOk();

    $apres = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk()->json('updated_at');

    // MESURE, pas supposition : l'horodatage n'a PAS bouge.
    expect($apres)->toBe($avant);

    // Et pourtant le conflit est vu.
    $conflit = $this->withHeaders(['If-Match' => $jetonPartage])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_B_NOM, CONC_SAISIE_B_TEL),
        );

    $conflit->assertStatus(409);

    $this->entreprise->refresh();
    expect($this->entreprise->denomination)->toBe(CONC_SAISIE_A_NOM);
});

test('apres un 409, le jeton rendu permet de rejouer : le client CONVERGE au lieu de boucler', function () {
    // Un refus qui ne dit pas comment reussir est un cul-de-sac : le client
    // rejouerait indefiniment le meme jeton perime. La reponse 409 porte donc
    // l'etat courant, dans `current_version` et dans l'en-tete `ETag`.
    $jetonPerime = (string) jetonFortConcurrence($this);

    $this->withHeaders(['If-Match' => $jetonPerime])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_A_NOM, CONC_SAISIE_A_TEL),
        )->assertOk();

    $conflit = $this->withHeaders(['If-Match' => $jetonPerime])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_B_NOM, CONC_SAISIE_B_TEL),
        );
    $conflit->assertStatus(409);

    $jetonRendu = $conflit->json('current_version');
    $this->assertIsString($jetonRendu);
    expect($jetonRendu)->not->toBe($jetonPerime);
    expect($conflit->headers->get('ETag'))->toBe($jetonRendu);

    // Le client a relu, fusionne, et rejoue avec le jeton que le refus lui a
    // donne. Cette fois il passe.
    $this->withHeaders(['If-Match' => $jetonRendu])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_B_NOM, CONC_SAISIE_B_TEL),
        )->assertOk();

    expect($this->entreprise->fresh()->denomination)->toBe(CONC_SAISIE_B_NOM);
});

test('un If-Match illisible est un conflit, pas une erreur serveur', function () {
    // Un jeton corrompu (proxy, cache, client fautif) ne doit produire ni 500 ni
    // ecriture silencieuse : on refuse, et le client relit.
    $conflit = $this->withHeaders(['If-Match' => '"nimportequoi-0000"'])
        ->putJson(
            "/api/v1/companies/{$this->entreprise->id}",
            formulaireConcurrence(CONC_SAISIE_B_NOM, CONC_SAISIE_B_TEL),
        );

    $conflit->assertStatus(409);

    $this->entreprise->refresh();
    expect($this->entreprise->denomination)->toBe(CONC_NOM_INITIAL);
});

test('le verrou ne parle JAMAIS avant le cloisonnement : une fiche d un autre espace reste un 404', function () {
    // Un 409 rendu sur la fiche d'un AUTRE espace confirmerait son existence,
    // et re-ouvrirait exactement la fuite fermee par B12-001 / F36-005 : les
    // identifiants sont des entiers consecutifs, il suffirait de balayer. Le
    // controle de version est donc pose APRES `refuserHorsEspace`, jamais avant.
    $autreEspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-autre', 'name' => 'Autre', 'settings' => [],
    ]);

    $ficheDAutrui = Company::create([
        'workspace_id' => $autreEspace->id,
        'siren' => '111222333',
        'denomination' => 'Chez les autres',
        'signals' => [], 'metadata' => [],
    ]);

    $this->withHeaders(['If-Match' => '"jeton-perime"'])
        ->putJson("/api/v1/companies/{$ficheDAutrui->id}", formulaireConcurrence(CONC_SAISIE_B_NOM, CONC_SAISIE_B_TEL))
        ->assertNotFound();

    expect($ficheDAutrui->fresh()->denomination)->toBe('Chez les autres');
});

// ═══════════════════════════════════════════════════════════════════════════
// NON-CONTAMINATION — le correctif porte sur UNE route. On le PROUVE.
// ═══════════════════════════════════════════════════════════════════════════

test('TEMOIN de non-contamination : PUT /tags reste inchange avec un If-Match perime', function () {
    // `TagsController::update` est l'une des trois AUTRES routes PUT qui ecrivent
    // vraiment. Elle ne connait pas le verrou : un jeton perime n'y change rien.
    // Le jour ou on l'etendra, CE test devra etre modifie en connaissance de
    // cause — c'est le but.
    $etiquette = Tag::create([
        'workspace_id' => $this->espace->id,
        'slug' => 'concurrence',
        'name' => 'Avant',
        'kind' => 'manual',
        'rules' => [],
    ]);

    $reponse = $this->withHeaders(['If-Match' => '"jeton-totalement-perime"'])
        ->putJson("/api/v1/tags/{$etiquette->id}", ['name' => 'Apres']);

    $reponse->assertOk();
    expect($etiquette->fresh()->name)->toBe('Apres');
});

/**
 * Le jeton fort de l'etat COURANT de la fiche, tel qu'un client l'obtiendrait :
 * par l'en-tete `ETag` d'une reponse. On passe par une ecriture neutre (le
 * meme nom, la meme valeur) pour ne pas dependre d'un `ETag` sur le GET, que ce
 * lot ne pose pas (perimetre : la methode `update` seule).
 */
function jetonFortConcurrence($test): ?string
{
    $reponse = $test->putJson(
        "/api/v1/companies/{$test->entreprise->id}",
        ['denomination' => $test->entreprise->fresh()->denomination],
    );

    return $reponse->headers->get('ETag');
}

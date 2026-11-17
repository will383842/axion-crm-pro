<?php

use App\Envoi\Campagnes\ConstructionSequence;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\EtalementEcheances;
use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Campagnes\MaterialisationRefusee;
use App\Envoi\Campagnes\ResultatMaterialisation;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Sequence\FenetreHoraire;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Support\ListeSuppression;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

/*
 * Lot X1-L4-B — matérialisation d'une campagne programmée (inscriptions étalées, inertes). Chaque test de comportement est joué SOUS LE
 * RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE. Données fictives (`*.example.test`). Aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const MAT_MODES = ['app', 'proprio'];

function matLundi(string $heure = '07:00'): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-16 ' . $heure, new DateTimeZone('Europe/Paris'));
}

function matEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Mat', 'slug' => 'mat-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** @return list<array<string, mixed>> */
function matEtapes(string $ws, array $delais = [0, 3]): array
{
    $s = app(ServiceMessagesTypes::class);
    $out = [];
    foreach ($delais as $i => $d) {
        $m = $s->creer($ws, 'Etape ' . ($i + 1), 'Objet ' . ($i + 1), 'Corps ' . ($i + 1), 'le fondateur');
        $s->valider($ws, $m['id'], 1);
        $out[] = ['etape' => $i + 1, 'message_id' => $m['id'], 'delai_jours' => $d];
    }

    return $out;
}

/** $n entreprises (une adresse nominative chacune) dans une liste. @return list<array{int,int}> [entreprise, contact] */
function matCible(string $ws, int $n, string $prefixe = 'a'): array
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . Str::random(6)]);
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $e = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => F::siren(), 'denomination' => 'Société fictive', 'discovery_source' => 'insee']);
        $c = (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $i,
            'email' => "prenom.nom{$prefixe}{$i}.{$e}@societe.example.test"]);
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $e, 'origine' => 'coche']);
        $out[] = [$e, $c];
    }
    $GLOBALS['mat_liste'] = $liste;

    return $out;
}

/**
 * Campagne « programmee » sans lien, sur une liste de $n entreprises (ou la liste courante si $n = null), SANS boîte ni plafond saisi.
 *
 * @return array{id: string, contacts: list<int>}
 */
function matProgrammee(string $ws, int $n = 5, array $contenu = [], array $boites = [], ?int $liste = null): array
{
    $contacts = $liste === null ? array_column(matCible($ws, $n), 1) : [];
    $contenu += ['sequence' => matEtapes($ws)];
    $depot = app(DepotCampagnes::class);
    $id = $depot->creer(
        $ws,
        'Campagne fictive',
        ['type' => 'liste', 'reference' => (string) ($liste ?? $GLOBALS['mat_liste']), 'libelle' => 'Liste fictive'],
        $boites,
        count($contenu['sequence']),
        ['contenu' => $contenu, 'etapes_faites' => []],
        'le fondateur',
    );
    $depot->transitionner($ws, $id, 'a_valider', 1, 'le fondateur', 'test', 'cle-mat-' . Str::random(12));
    DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'programmee', 'version' => 3]);
    DB::table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $id, 'transition' => 'a_valider>programmee', 'auteur' => 'le fondateur',
        'motif' => 'test', 'cle_idempotence' => 'cle-j-' . Str::random(12), 'cree_le' => DB::raw("now() - interval '10 minutes'")]);

    return ['id' => $id, 'contacts' => $contacts];
}

function matService(?Closure $lecteur = null, int $taille = MaterialisationCampagne::TAILLE_LOT, int $lots = MaterialisationCampagne::LOTS_PAR_APPEL): MaterialisationCampagne
{
    return new MaterialisationCampagne(app(DepotCampagnes::class), new SelectionDestinataires($lecteur), app(ConstructionSequence::class), app(DepotSocle::class), $taille, $lots);
}

/** Joue $f sous le mode : « app » = rôle applicatif non propriétaire sous RLS. */
function matSous(string $mode, Closure $f): mixed
{
    MaterialisationCampagne::$niveauTolere = DB::transactionLevel(); // la transaction de RefreshDatabase est tolérée dans les tests, jamais ailleurs
    if ($mode === 'proprio') {
        return $f();
    }
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

function matRun(string $mode, string $ws, string $id, ?DateTimeImmutable $maintenant = null, ?MaterialisationCampagne $service = null): ResultatMaterialisation
{
    return matSous($mode, fn () => ($service ?? matService())->materialiser($ws, $id, $maintenant ?? matLundi()));
}

function matMotif(Closure $f): ?string
{
    DB::statement('SAVEPOINT mat_refus');
    try {
        $f();
        DB::statement('RELEASE SAVEPOINT mat_refus');

        return null;
    } catch (MaterialisationRefusee $e) {
        DB::statement('ROLLBACK TO SAVEPOINT mat_refus');
        DB::statement('RESET ROLE');

        return $e->motif;
    }
}

/** @return array<string, int> */
function matComptes(string $ws): array
{
    return ['sequences' => DB::table('envoi_sequences')->where('workspace_id', $ws)->count(), 'mats' => DB::table('envoi_campagnes_materialisations')->where('workspace_id', $ws)->count(),
        'inscriptions' => DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count(), 'messages' => DB::table('envoi_messages')->where('workspace_id', $ws)->count()];
}

/** @return array<string, string> contact => échéance (Y-m-d H:i:s Paris) */
function matEcheances(string $ws, ?string $sequence = null): array
{
    $q = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->orderBy('contact_id');
    if ($sequence !== null) {
        $q->where('sequence_id', $sequence);
    }

    return $q->selectRaw("contact_id, to_char(prochaine_echeance AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD HH24:MI:SS') AS e")->pluck('e', 'contact_id')->all();
}

/** @return array<string, int> jour (Paris) => nombre */
function matParJour(string $ws, ?string $sequence = null): array
{
    $par = [];
    foreach (matEcheances($ws, $sequence) as $e) {
        $par[substr($e, 0, 10)] = ($par[substr($e, 0, 10)] ?? 0) + 1;
    }
    ksort($par);

    return $par;
}

function matInvariants(object $mat): void
{
    $c = json_decode((string) $mat->compteurs, true);
    $somme = array_sum(array_map(static fn (string $m): int => $c[$m], SelectionDestinataires::MOTIFS));
    expect($c['membres'])->toBe($c['retenus'] + $somme)->and($c['retenus'])->toBe($c['inscrits'] + $c['ecartes_insertion']);
}

function matLigne(string $id): object
{
    return DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->first();
}

/** Inscription ACTIVE d'un contact dans une autre séquence (une autre campagne), échéance donnée. */
function matAilleurs(string $ws, int $contact, int $entreprise, string $adresse, string $echeance): void
{
    $s = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $s, 'workspace_id' => $ws, 'nom' => 'Autre séquence']);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'S', 'corps' => 'C']);
    DB::table('envoi_inscriptions')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'contact_id' => $contact, 'entreprise_id' => $entreprise,
        'empreinte_adresse' => ListeSuppression::empreinte($adresse), 'prochaine_echeance' => $echeance, 'version_figee' => '{}']);
}

beforeEach(function () {
    config(['envoi.plafond_jour' => 20]);
    $this->ws = matEspace();
});

afterEach(function () {
    MaterialisationCampagne::$niveauTolere = 0;
});

test('X1-L4-B 1 nominal : inscriptions étalées (lundi 9 h - 17 h, jamais HH:00:00), version figée de Inscriptions, matérialisation terminée, compteurs exacts', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 5);
        $r = matRun($mode, $ws, $p['id']);
        $mat = matLigne($p['id']);
        $campagne = DB::table('envoi_campagnes')->where('id', $p['id'])->first();
        expect($r->etat)->toBe('terminee', $mode)->and($r->inscrits)->toBe(5)->and($mat->etat)->toBe('terminee')->and($mat->sequence_id)->toBe($campagne->sequence_id)->and($campagne->statut)->toBe('programmee');
        matInvariants($mat);
        $c = json_decode($mat->compteurs, true);
        expect($c['inscrits'])->toBe(5)->and($c['ecartes_insertion'])->toBe(0)->and($c['membres'])->toBe(5);
        $echeances = matEcheances($ws);
        expect($echeances)->toHaveCount(5);
        foreach ($echeances as $e) {
            expect(substr($e, 0, 10))->toBe('2026-11-16')->and(substr($e, 11, 2))->toBeGreaterThanOrEqual('09')->and(substr($e, 11, 2))->toBeLessThan('17')->and(substr($e, 13))->not->toBe(':00:00');
        }
        $ins = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->first();
        expect($ins->population)->toBe('entreprises')->and($ins->statut)->toBe('active');
        // version figée : celle que produit Inscriptions::inscrire pour le même contact dans la même séquence
        DB::table('envoi_inscriptions')->where('id', $ins->id)->update(['statut' => 'terminee']);
        $attendue = DB::table('envoi_inscriptions')->where('id', (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $campagne->sequence_id, (int) $ins->contact_id, matLundi()))->value('version_figee');
        expect(json_decode((string) $ins->version_figee, true))->toBe(json_decode((string) $attendue, true));
    }
});

test('X1-L4-B 2 idempotence : un second appel ne change ni les comptes, ni les échéances, ni les compteurs', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 4);
        matRun($mode, $ws, $p['id']);
        $avant = [matComptes($ws), matEcheances($ws), matLigne($p['id'])->compteurs];
        $r = matRun($mode, $ws, $p['id'], matLundi('09:00'));
        expect($r->etat)->toBe('terminee')->and([matComptes($ws), matEcheances($ws), matLigne($p['id'])->compteurs])->toBe($avant);
    }
});

test('X1-L4-B 3 reprise par curseur : lots de 2, un lot par appel ; curseurs croissants ; mêmes échéances que d\'un trait', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 5);
        $service = matService(null, 2, 1);
        $curseurs = [];
        $etats = [];
        for ($i = 0; $i < 3; $i++) {
            $r = matRun($mode, $ws, $p['id'], matLundi(), $service);
            $curseurs[] = $r->curseur;
            $etats[] = $r->etat;
        }
        expect($etats)->toBe(['en_cours', 'en_cours', 'terminee'], $mode)->and($curseurs[0])->toBeLessThan($curseurs[1])->and($curseurs[1])->toBeLessThanOrEqual($curseurs[2])
            ->and(count(matEcheances($ws)))->toBe(5);
        matInvariants(matLigne($p['id']));
        $seq = DB::table('envoi_campagnes')->where('id', $p['id'])->value('sequence_id');
        $fenetre = DB::table('envoi_sequences')->where('id', $seq)->first();
        $attendues = EtalementEcheances::repartir(
            new FenetreHoraire((int) $fenetre->fenetre_debut, (int) $fenetre->fenetre_fin),
            matLundi(),
            matLundi(),
            20,
            [],
            array_map(fn ($c) => $p['id'] . '|' . $c, $p['contacts']),
            $p['id'],
        );
        $reelles = [];
        foreach (matEcheances($ws) as $contact => $e) {
            $reelles[$p['id'] . '|' . $contact] = $e;
        }
        expect($reelles)->toBe(array_map(fn ($d) => $d->format('Y-m-d H:i:s'), $attendues));
    }
});

test('X1-L4-B 4 plafond par jour : 45 destinataires, 5 déjà prévus lundi par une autre campagne : 15 lundi, 20 mardi, 10 mercredi', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        foreach (matCible($ws, 5, 'z') as [$e, $c]) {
            matAilleurs($ws, $c, $e, DB::table('contacts')->where('id', $c)->value('email'), '2026-11-16 15:00:00+01');
        }
        $p = matProgrammee($ws, 45);
        $r = matRun($mode, $ws, $p['id']);
        $seq = DB::table('envoi_campagnes')->where('id', $p['id'])->value('sequence_id');
        expect($r->etat)->toBe('terminee')->and(matParJour($ws, $seq))->toBe(['2026-11-16' => 15, '2026-11-17' => 20, '2026-11-18' => 10], $mode);
    }
});

test('X1-L4-B 5 horizon : plafond 1 et 30 destinataires = 20 inscrits, en_cours ; un jour ouvré plus tard, un de plus', function () {
    config(['envoi.plafond_jour' => 1]);
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 30);
        $r = matRun($mode, $ws, $p['id']);
        expect($r->etat)->toBe('en_cours', $mode)->and($r->inscrits)->toBe(20)->and($r->motif)->toBe('horizon_plein')->and(matLigne($p['id'])->curseur)->toBe($r->curseur);
        expect(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(20);
        $r2 = matRun($mode, $ws, $p['id'], matLundi()->modify('+1 day'));
        expect($r2->inscrits)->toBe(1)->and($r2->curseur)->toBeGreaterThan($r->curseur)->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(21);
        matInvariants(matLigne($p['id']));
    }
});

test('X1-L4-B 6 E8 : jours hors lundi-vendredi ou objet_b : matérialisation bloquée, aucune inscription', function () {
    foreach (MAT_MODES as $mode) {
        foreach ([['jours_non_applicables', ['jours' => ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam']]], ['objet_b_non_applicable', 'objet_b']] as [$motif, $contenu]) {
            $ws = matEspace();
            $sequence = matEtapes($ws);
            if ($contenu === 'objet_b') {
                $sequence[1]['objet_b'] = 'Autre objet';
                $contenu = [];
            }
            $p = matProgrammee($ws, 3, $contenu + ['sequence' => $sequence]);
            $r = matRun($mode, $ws, $p['id']);
            expect($r->etat)->toBe('bloquee', $mode . $motif)->and($r->motif)->toBe($motif)->and(matLigne($p['id'])->etat)->toBe('bloquee')->and(matLigne($p['id'])->motif)->toBe($motif)
                ->and(matComptes($ws)['inscriptions'])->toBe(0);
            expect(matRun($mode, $ws, $p['id'])->etat)->toBe('bloquee'); // idempotent
        }
    }
});

test('X1-L4-B 7 réglage non appliqué : boîtes choisies ou plafond_jour saisi : refus, AUCUNE ligne (ni séquence, ni matérialisation, ni inscription)', function () {
    foreach (MAT_MODES as $mode) {
        foreach ([['boites' => [(string) Str::uuid()], 'contenu' => []], ['boites' => [], 'contenu' => ['plafond_jour' => 10]]] as $cas) {
            $ws = matEspace();
            $p = matProgrammee($ws, 3, $cas['contenu'], $cas['boites']);
            $avant = matComptes($ws);
            expect(matMotif(fn () => matRun($mode, $ws, $p['id'])))->toBe('reglage_non_applique', $mode)
                ->and(matComptes($ws))->toBe($avant)->and($avant['sequences'])->toBe(0)->and($avant['mats'])->toBe(0)->and($avant['inscriptions'])->toBe(0)
                ->and(DB::table('envoi_campagnes')->where('id', $p['id'])->value('statut'))->toBe('programmee');
        }
    }
});

test('X1-L4-B 8 statut brouillon, a_valider, arretee, terminee : refus statut_non_materialisable, rien d\'écrit', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 2);
        $brouillon = app(DepotCampagnes::class)->creer($ws, 'Brouillon', ['type' => 'liste', 'reference' => '1', 'libelle' => 'x'], [], 1, ['contenu' => [], 'etapes_faites' => []], 'le fondateur');
        $aValider = app(DepotCampagnes::class)->creer($ws, 'À valider', ['type' => 'liste', 'reference' => '1', 'libelle' => 'x'], [], 1, ['contenu' => [], 'etapes_faites' => []], 'le fondateur');
        app(DepotCampagnes::class)->transitionner($ws, $aValider, 'a_valider', 1, 'le fondateur', 'test', 'cle-mat-' . Str::random(12));
        app(ConstructionSequence::class)->construire($ws, $p['id']);
        foreach ([['arretee'], ['en_cours', 'terminee']] as $chemin) {
            foreach ($chemin as $statut) {
                DB::table('envoi_campagnes')->where('id', $p['id'])->update(['statut' => $statut, 'version' => DB::raw('version + 1')]);
            }
            $avant = matComptes($ws);
            expect(matMotif(fn () => matRun($mode, $ws, $p['id'])))->toBe('statut_non_materialisable', $mode . end($chemin))->and(matComptes($ws))->toBe($avant);
            if ($chemin === ['arretee']) {
                break; // un statut final ne se quitte pas : un seul cas par campagne
            }
        }
        foreach ([$brouillon, $aValider] as $id) {
            $avant = matComptes($ws);
            expect(matMotif(fn () => matRun($mode, $ws, $id)))->toBe('statut_non_materialisable', $mode)->and(matComptes($ws))->toBe($avant);
        }
    }
});

test('X1-L4-B 9 cible introuvable (liste en corbeille après la validation) : matérialisation bloquée cible_introuvable', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 2);
        DB::table('listes_manuelles')->where('id', $GLOBALS['mat_liste'])->update(['deleted_at' => now()]);
        $r = matRun($mode, $ws, $p['id']);
        expect($r->etat)->toBe('bloquee', $mode)->and($r->motif)->toBe('cible_introuvable')->and(matLigne($p['id'])->motif)->toBe('cible_introuvable')->and(matComptes($ws)['inscriptions'])->toBe(0);
    }
});

test('X1-L4-B 10 adresse active ailleurs : comptée deja_en_sequence ; course entre sélection et écriture : ecartes_insertion = 1, sans erreur', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 3);
        $adresse = DB::table('contacts')->where('id', $p['contacts'][0])->value('email');
        matAilleurs($ws, $p['contacts'][0], (int) DB::table('contacts')->where('id', $p['contacts'][0])->value('company_id'), $adresse, '2026-11-17 10:00:00+01');
        $r = matRun($mode, $ws, $p['id']);
        $c = json_decode(matLigne($p['id'])->compteurs, true);
        expect($r->inscrits)->toBe(2)->and($c['deja_en_sequence'])->toBe(1)->and($c['ecartes_insertion'])->toBe(0);
        matInvariants(matLigne($p['id']));

        // course : l'adresse du dernier contact est prise juste AVANT l'écriture du lot (après la sélection).
        $ws = matEspace();
        $p = matProgrammee($ws, 3);
        $dernier = $p['contacts'][2];
        $armee = new ArrayObject(['oui' => true]); // un objet par tour : les crochets des tours précédents restent inertes
        DB::beforeExecuting(function (string $sql) use ($armee, $ws, $dernier): void {
            if ($armee['oui'] && str_starts_with(ltrim($sql), 'INSERT INTO envoi_inscriptions')) {
                $armee['oui'] = false;
                matAilleurs($ws, $dernier, (int) DB::table('contacts')->where('id', $dernier)->value('company_id'), DB::table('contacts')->where('id', $dernier)->value('email'), '2026-11-17 11:00:00+01');
            }
        });
        $r = matRun($mode, $ws, $p['id']);
        $c = json_decode(matLigne($p['id'])->compteurs, true);
        expect($r->etat)->toBe('terminee')->and($c['ecartes_insertion'])->toBe(1)->and($c['inscrits'])->toBe(2)->and($c['retenus'])->toBe(3)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('statut', 'active')->count())->toBe(3);
        matInvariants(matLigne($p['id']));
    }
});

/** Lance un sous-processus ; rend [processus, tubes]. */
function matLance(string $ws, string $campagne, float $depart): array
{
    $p = proc_open([PHP_BINARY, base_path('tests/Support/materialisation-concurrente.php'), $ws, $campagne, sprintf('%.4F', $depart)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);

    return [$p, $tubes];
}

test('X1-L4-B 11 concurrence en processus réels : trois materialiser = chaque contact inscrit une fois, compteurs justes, perdants « concurrence »', function () {
    $proprio = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-mat-' . substr($ws, 0, 6), 'name' => 'Concurrence matérialisation', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    try {
        $sequence = [];
        foreach ([1, 2] as $rang) {
            $m = (string) Str::uuid();
            $proprio->table('envoi_modeles')->insert(['id' => $m, 'workspace_id' => $ws, 'nom' => 'M' . $rang, 'cree_par' => 'r']);
            $proprio->table('envoi_modele_versions')->insert(['workspace_id' => $ws, 'modele_id' => $m, 'numero' => 1, 'objet' => 'Objet ' . $rang, 'corps' => 'Corps ' . $rang, 'auteur' => 'r']);
            $proprio->table('envoi_modeles')->where('id', $m)->update(['statut' => 'a_relire']);
            $proprio->table('envoi_modeles')->where('id', $m)->update(['statut' => 'valide', 'version_validee' => 1]);
            $sequence[] = ['etape' => $rang, 'message_id' => $m, 'delai_jours' => $rang - 1];
        }
        $liste = (int) $proprio->table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste']);
        for ($i = 1; $i <= 6; $i++) {
            $e = (int) $proprio->table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => F::siren(), 'denomination' => 'Société fictive', 'discovery_source' => 'insee']);
            $proprio->table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $i, 'email' => "prenom.nomc{$i}.{$e}@societe.example.test"]);
            $proprio->table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $e, 'origine' => 'coche']);
        }
        $camp = (string) Str::uuid();
        $proprio->table('envoi_campagnes')->insert(['id' => $camp, 'workspace_id' => $ws, 'nom' => 'Campagne concurrente', 'cible' => json_encode(['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'l']),
            'nb_etapes' => 2, 'saisie' => json_encode(['contenu' => ['sequence' => $sequence], 'etapes_faites' => []]), 'cree_par' => 'r']);
        foreach (['a_valider' => 2, 'programmee' => 3] as $statut => $v) {
            $proprio->table('envoi_campagnes')->where('id', $camp)->update(['statut' => $statut, 'version' => $v]);
        }
        $proprio->table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $camp, 'transition' => 'a_valider>programmee', 'auteur' => 'r', 'motif' => 'x',
            'cle_idempotence' => 'cle-mc-' . substr($ws, 0, 6), 'cree_le' => $proprio->raw("now() - interval '10 minutes'")]);
        $depart = microtime(true) + 4;
        $procs = [];
        foreach ([1, 2, 3] as $i) {
            $procs[$i] = matLance($ws, $camp, $depart);
        }
        $motifs = [];
        foreach ($procs as $i => [$p, $tubes]) {
            $sortie = stream_get_contents($tubes[1]);
            $err = stream_get_contents($tubes[2]);
            $code = proc_close($p);
            expect($code)->toBeIn([0, 3], $i . ' : ' . $sortie . $err);
            if ($code === 3) {
                $motifs[] = $sortie;
            }
        }
        // les perdants s'arrêtent (« concurrence ») : des appels SEULS terminent le travail (reprise par curseur)
        for ($reprise = 0; $reprise < 6 && $proprio->table('envoi_campagnes_materialisations')->where('campagne_id', $camp)->value('etat') !== 'terminee'; $reprise++) {
            [$p, $tubes] = matLance($ws, $camp, 0.0);
            $sortie = stream_get_contents($tubes[1]);
            $err = stream_get_contents($tubes[2]);
            expect(proc_close($p))->toBe(0, $sortie . $err);
        }
        expect($motifs)->each->toBeIn(['concurrence', 'construction_a_reessayer']);
        $ins = $proprio->table('envoi_inscriptions')->where('workspace_id', $ws)->pluck('contact_id')->all();
        $mat = $proprio->table('envoi_campagnes_materialisations')->where('campagne_id', $camp)->first();
        $c = json_decode($mat->compteurs, true);
        expect($ins)->toHaveCount(6)->and(array_unique($ins))->toHaveCount(6)->and($mat->etat)->toBe('terminee')->and($c['membres'])->toBe(6)->and($c['inscrits'])->toBe(6)->and($c['retenus'])->toBe(6);
    } finally {
        try {
            $proprio->statement("SET session_replication_role = 'replica'");
            foreach (['envoi_inscriptions', 'envoi_campagnes_materialisations', 'envoi_sequences_gestes', 'envoi_sequence_etapes', 'envoi_campagnes_journal', 'listes_manuelles_membres'] as $t) {
                $proprio->table($t)->where('workspace_id', $ws)->delete();
            }
            $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->update(['sequence_id' => null]);
            $proprio->table('envoi_sequences')->where('workspace_id', $ws)->delete();
            $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->delete();
            $proprio->table('envoi_modeles')->where('workspace_id', $ws)->update(['version_validee' => null, 'statut' => 'brouillon']);
            foreach (['envoi_modele_versions', 'envoi_modeles', 'listes_manuelles', 'contacts', 'companies'] as $t) {
                $proprio->table($t)->where('workspace_id', $ws)->delete();
            }
            $proprio->table('workspaces')->where('id', $ws)->delete();
        } finally {
            $proprio->statement("SET session_replication_role = 'origin'");
        }
    }
});

test('X1-L4-B 12 pas d\'envoi : la séquence est en pause, aucune ligne envoi_messages, toutes les inscriptions inertes', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 3);
        matRun($mode, $ws, $p['id']);
        $seq = DB::table('envoi_campagnes')->where('id', $p['id'])->value('sequence_id');
        $geste = DB::table('envoi_sequences_gestes')->where('sequence_id', $seq)->orderByDesc('id')->value('geste');
        expect($geste)->toBe('pause')->and(matComptes($ws)['messages'])->toBe(0)->and(DB::table('envoi_campagnes')->where('id', $p['id'])->value('statut'))->toBe('programmee');
    }
});

test('X1-L4-B 13 Inscriptions::inscrire d\'une adresse déjà active ailleurs : InvalidArgumentException (plus de chaîne vide)', function () {
    $ws = matEspace();
    $p = matProgrammee($ws, 2);
    matRun('proprio', $ws, $p['id']);
    $autre = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $autre, 'workspace_id' => $ws, 'nom' => 'Autre']);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $autre, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'S', 'corps' => 'C']);
    expect(fn () => (new Inscriptions(new LecteurFicheDb))->inscrire($ws, $autre, $p['contacts'][0], matLundi()))
        ->toThrow(InvalidArgumentException::class, 'Adresse déjà inscrite à une séquence active dans cet espace.');
});

test('X1-L4-B 14 jamais de QueryException brute : 40P01 deux fois puis réussite = résultat ; trois fois = concurrence', function () {
    $erreur = function (string $code): QueryException {
        $pdo = new PDOException('simulée');
        $pdo->errorInfo = [$code, 0, 'simulée'];
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $code);

        return new QueryException('pgsql', 'SELECT 1', [], $pdo);
    };
    $niveau = DB::transactionLevel();
    for ($i = 0; $i < $niveau; $i++) {
        DB::rollBack();
    }
    try {
        $n = 0;
        expect(MaterialisationCampagne::relancer(function () use (&$n, $erreur) {
            if (++$n <= 2) {
                throw $erreur('40P01');
            }

            return 'ok';
        }))->toBe('ok')->and($n)->toBe(3);
        $n = 0;
        $e = null;
        try {
            MaterialisationCampagne::relancer(function () use (&$n, $erreur) {
                $n++;
                throw $erreur('40P01');
            });
        } catch (MaterialisationRefusee $e) {
        }
        expect($e?->motif)->toBe('concurrence')->and($e->reessayable)->toBeTrue()->and($n)->toBe(3);
        $e = null;
        try {
            MaterialisationCampagne::relancer(fn () => throw $erreur('23514'));
        } catch (MaterialisationRefusee $e) {
        }
        expect($e?->motif)->toBe('erreur_base')->and($e->reessayable)->toBeFalse();
    } finally {
        for ($i = 0; $i < $niveau; $i++) {
            DB::beginTransaction();
        }
    }
});

test('X1-L4-B 15 dans une transaction englobante non tolérée : LogicException, rien d\'écrit', function () {
    $ws = matEspace();
    $p = matProgrammee($ws, 1);
    MaterialisationCampagne::$niveauTolere = 0;
    expect(fn () => matService()->materialiser($ws, $p['id'], matLundi()))->toThrow(LogicException::class);
    expect(matComptes($ws)['sequences'])->toBe(0);
});

/** Pose un filtre « réseau d'origine » sur la campagne (écriture de la colonne en propriétaire, avant la liaison). */
function matFiltrer(string $id, array $reseaux): void
{
    DB::table('envoi_campagnes')->where('id', $id)->update(['filtre_origine' => json_encode(['reseaux' => $reseaux]), 'version' => DB::raw('version + 1')]);
}

test('X1-L2b-C 14 campagne avec filtre et drapeau éteint : refus filtre_origine_inactif non réessayable, rien d\'écrit, campagne toujours programmée', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 2);
        matFiltrer($p['id'], ['linkedin']);
        config(['origines.lecture_enabled' => false]);
        $e = null;
        try {
            matRun($mode, $ws, $p['id']);
        } catch (MaterialisationRefusee $e) {
            DB::statement('RESET ROLE');
        }
        // Comme `cible_non_supportee` : la séquence (en pause) et la ligne `en_cours` à curseur 0 existent déjà au moment de la sélection ; rien d'autre.
        $apres = matComptes($ws);
        expect($e?->motif)->toBe('filtre_origine_inactif', $mode)->and($e->reessayable)->toBeFalse()->and($apres['inscriptions'])->toBe(0)->and($apres['messages'])->toBe(0)
            ->and(DB::table('envoi_campagnes')->where('id', $p['id'])->value('statut'))->toBe('programmee');
        expect(matLigne($p['id']))->toMatchObject(['etat' => 'en_cours', 'curseur' => 0]);
        expect(fn () => matRun($mode, $ws, $p['id']))->toThrow(MaterialisationRefusee::class); // reprise : même refus, aucun doublon
        DB::statement('RESET ROLE');
        expect(matComptes($ws))->toBe($apres);
    }
});

test('X1-L2b-C 15 même campagne, drapeau allumé : seules les unités du réseau sont inscrites ; hors_reseau écrit dans les compteurs', function () {
    foreach (MAT_MODES as $mode) {
        $ws = matEspace();
        $p = matProgrammee($ws, 4);
        $entreprises = DB::table('contacts')->whereIn('id', $p['contacts'])->orderBy('id')->pluck('company_id')->all();
        foreach (array_slice($entreprises, 0, 2) as $e) {
            OriginesJeu::personne($ws, 'p' . $e . '@example.test', [OriginesJeu::entree(['reseau' => 'linkedin'])], ['company_id' => $e]);
        }
        OriginesJeu::allumer();
        OriginesJeu::ouvrir($ws);
        matFiltrer($p['id'], ['linkedin']);
        $r = matRun($mode, $ws, $p['id']);
        $mat = matLigne($p['id']);
        $c = json_decode((string) $mat->compteurs, true);
        expect($r->inscrits)->toBe(2, $mode)->and($c['hors_reseau'])->toBe(2)->and($c['retenus'])->toBe(2)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->orderBy('contact_id')->pluck('contact_id')->map(fn ($v) => (int) $v)->all())->toBe(array_slice($p['contacts'], 0, 2));
        matInvariants($mat);
    }
});

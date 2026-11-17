<?php

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\Destinataire;
use App\Envoi\Campagnes\LotDestinataires;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Campagnes\SelectionRefusee;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

/*
 * Lot X1-L2b-C — filtre « réseau d'origine » dans SelectionDestinataires (LECTURE SEULE). Chaque test tourne sous le rôle applicatif (RLS)
 * ET sous le propriétaire. Cahier X1-L2-bis (#648) § 5. Données fictives (`*.example.test`). Aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const SFO_MODES = ['app', 'proprio'];

beforeEach(function () {
    config(['origines.lecture_enabled' => false, 'origines.v3_enabled' => false]);
});

afterEach(function () {
    WorkspaceContext::clear();
});

function sfoEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Sfo', 'slug' => 'sfo-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function sfoN(): int
{
    static $n = 30000000;

    return ++$n;
}

function sfoEntreprise(string $ws): int
{
    return (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => F::siren(), 'denomination' => 'Société fictive']);
}

function sfoContact(string $ws, int $co, ?string $email = null): int
{
    $n = sfoN();

    return (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $co, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $n, 'email' => $email ?? "prenom.nom{$n}@societe.example.test"]);
}

/** @param list<array{contact?: int, company?: int}> $membres */
function sfoCampagne(string $ws, array $membres): string
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . sfoN()]);
    foreach ($membres as $m) {
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'contact_id' => $m['contact'] ?? null, 'company_id' => $m['company'] ?? null, 'origine' => 'coche']);
    }

    return app(DepotCampagnes::class)->creer($ws, 'Campagne fictive', ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Cible fictive'], [], 1, [], 'le fondateur');
}

/** Pose le filtre (écriture de la colonne en propriétaire, version + 1) : `null` = pas de filtre. @param list<string>|null $reseaux */
function sfoFiltrer(string $ws, string $campagne, ?array $reseaux): void
{
    DB::table('envoi_campagnes')->where('workspace_id', $ws)->where('id', $campagne)
        ->update(['filtre_origine' => $reseaux === null ? null : json_encode(['reseaux' => $reseaux]), 'version' => DB::raw('version + 1')]);
}

function sfoAllumer(string $ws): void
{
    OriginesJeu::allumer();
    OriginesJeu::ouvrir($ws);
}

/** @param list<string> $reseaux @param array<string, mixed> $sur */
function sfoPersonne(string $ws, array $reseaux, array $sur = []): int
{
    return OriginesJeu::personne($ws, 'p' . sfoN() . '@example.test', array_map(static fn (string $r): array => OriginesJeu::entree(['reseau' => $r]), $reseaux), $sur);
}

function sfoSel(string $mode, string $ws, string $campagne, int $apres = 0, int $taille = 200, ?Closure $lecteur = null): LotDestinataires
{
    if ($mode === 'app') {
        WorkspaceContext::set($ws);
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return (new SelectionDestinataires($lecteur))->lot($ws, $campagne, 'entreprises', $apres, $taille);
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    }
}

/** @return array{string, bool}|null [motif, réessayable] d'un refus, null si accepté */
function sfoRefus(string $mode, string $ws, string $campagne, ?Closure $lecteur = null): ?array
{
    DB::statement('SAVEPOINT sfo_refus');
    try {
        sfoSel($mode, $ws, $campagne, 0, 200, $lecteur);
        DB::statement('RELEASE SAVEPOINT sfo_refus');

        return null;
    } catch (SelectionRefusee $e) {
        DB::statement('ROLLBACK TO SAVEPOINT sfo_refus');
        DB::statement('RESET ROLE');

        return [$e->motif, $e->reessayable];
    }
}

/** L'invariant du cahier (avec `hors_reseau`), vérifié à CHAQUE lot. */
function sfoInvariant(LotDestinataires $l): LotDestinataires
{
    $c = $l->compteurs;
    expect($c)->toHaveKey('hors_reseau');
    expect(SelectionDestinataires::MOTIFS)->toContain('hors_reseau')->and(SelectionDestinataires::MOTIFS[count(SelectionDestinataires::MOTIFS) - 1])->toBe('hors_reseau');
    $somme = array_sum(array_map(static fn (string $m): int => $c[$m], SelectionDestinataires::MOTIFS));
    expect($c['membres'])->toBe($c['retenus'] + $somme)->and($c['retenus'])->toBe(count($l->destinataires));

    return $l;
}

/** @return list<int> */
function sfoIds(LotDestinataires $l): array
{
    $ids = array_map(static fn (Destinataire $d): int => $d->contactId, $l->destinataires);
    sort($ids);

    return $ids;
}

function sfoLecteurLibre(): Closure
{
    return static fn (string $ws, array $lignes): array => array_map(static fn ($l): array => ['opposee' => false, 'motifs' => []], $lignes);
}

/** SQL vu pendant $f (le SQL du test lui-même est exclu : on n'écoute qu'ici). @return list<string> */
function sfoRequetes(Closure $f): array
{
    $vues = [];
    DB::listen(function ($q) use (&$vues) {
        $vues[] = $q->sql;
    });
    $f();

    return $vues;
}

test('X1-L2b-C 1 sans filtre rien ne change, drapeau éteint puis allumé ; aucune requête sur personnes, archive, fonctions_espace', function () {
    foreach (SFO_MODES as $mode) {
        $ws = sfoEspace();
        $e1 = sfoEntreprise($ws);
        $k1 = sfoContact($ws, $e1);
        $e2 = sfoEntreprise($ws);
        sfoContact($ws, $e2, 'contact@societe.example.test'); // générique : jamais choisi
        $c3 = sfoContact($ws, sfoEntreprise($ws));
        $campagne = sfoCampagne($ws, [['company' => $e1], ['company' => $e2], ['contact' => $c3]]);
        $resultats = [];
        foreach ([false, true] as $allume) {
            $allume ? sfoAllumer($ws) : config(['origines.lecture_enabled' => false]);
            $vues = sfoRequetes(function () use (&$resultats, $mode, $ws, $campagne) {
                $resultats[] = sfoInvariant(sfoSel($mode, $ws, $campagne, 0, 200, sfoLecteurLibre()));
            });
            expect(preg_grep('/personnes|personne_origines_archive|fonctions_espace/', $vues))->toBe([], "{$mode} : aucune lecture des origines");
        }
        expect(sfoIds($resultats[0]))->toBe([$k1, $c3])->and($resultats[1]->compteurs)->toBe($resultats[0]->compteurs)->and($resultats[0]->compteurs['hors_reseau'])->toBe(0);
    }
});

test('X1-L2b-C 2 filtre + drapeau éteint : refus filtre_origine_inactif non réessayable, aucune lecture de la cible ni des personnes', function () {
    foreach (SFO_MODES as $mode) {
        $ws = sfoEspace();
        $e = sfoEntreprise($ws);
        sfoContact($ws, $e);
        $campagne = sfoCampagne($ws, [['company' => $e]]);
        sfoFiltrer($ws, $campagne, ['linkedin']);
        $refus = null;
        $vues = sfoRequetes(function () use (&$refus, $mode, $ws, $campagne) {
            $refus = sfoRefus($mode, $ws, $campagne);
        });
        expect($refus)->toBe(['filtre_origine_inactif', false], $mode)->and(preg_grep('/listes_manuelles|contacts|companies|personnes|personne_origines_archive/', $vues))->toBe([], "{$mode} : refus avant toute lecture");
        expect(config('origines.lecture_enabled'))->toBeFalse(); // le test ne touche pas au drapeau
    }
});

test('X1-L2b-C 3 filtre + chaque autre porte fermée, ou forme invalide : même refus (fermé)', function () {
    $ws = sfoEspace();
    $e = sfoEntreprise($ws);
    sfoContact($ws, $e);
    $campagne = sfoCampagne($ws, [['company' => $e]]);
    sfoFiltrer($ws, $campagne, ['linkedin']);
    foreach (SFO_MODES as $mode) {
        OriginesJeu::allumer();
        OriginesJeu::ouvrir($ws, false);
        DB::table('fonctions_espace')->where('espace_id', $ws)->delete();
        expect(sfoRefus($mode, $ws, $campagne))->toBe(['filtre_origine_inactif', false], "{$mode} : fonction d'espace absente");
        OriginesJeu::ouvrir($ws, false);
        expect(sfoRefus($mode, $ws, $campagne))->toBe(['filtre_origine_inactif', false], "{$mode} : fonction fermée");
        OriginesJeu::ouvrir($ws);
        config(['origines.v3_enabled' => false]);
        expect(sfoRefus($mode, $ws, $campagne))->toBe(['filtre_origine_inactif', false], "{$mode} : v3 éteint");
    }
    sfoAllumer($ws); // portes ouvertes : seule la forme du filtre est en cause
    // défense : le CHECK de la base interdit déjà une forme invalide ; on le lève (DDL transactionnel, annulé en fin de test)
    DB::statement('ALTER TABLE envoi_campagnes DROP CONSTRAINT envoi_campagnes_filtre_origine_forme');
    foreach (SFO_MODES as $mode) {
        foreach (['{"reseaux": []}', '{"reseaux": ["LinkedIn"]}', '{"autre": 1}', '"x"'] as $brut) {
            DB::statement('UPDATE envoi_campagnes SET filtre_origine = ?::jsonb, version = version + 1 WHERE id = ?', [$brut, $campagne]);
            expect(sfoRefus($mode, $ws, $campagne))->toBe(['filtre_origine_inactif', false], "{$mode} : forme {$brut}");
        }
    }
});

test('X1-L2b-C 4 liste mixte filtrée linkedin : E1 et C3 retenus, hors_reseau = 2 ; deux réseaux : E2 aussi', function () {
    foreach (SFO_MODES as $mode) {
        $ws = sfoEspace();
        $e1 = sfoEntreprise($ws);
        $k1 = sfoContact($ws, $e1);
        $e2 = sfoEntreprise($ws);
        $k2 = sfoContact($ws, $e2);
        $c3 = sfoContact($ws, sfoEntreprise($ws));
        $c4 = sfoContact($ws, sfoEntreprise($ws));
        sfoPersonne($ws, ['linkedin'], ['company_id' => $e1]);
        sfoPersonne($ws, ['facebook'], ['company_id' => $e2]);
        sfoPersonne($ws, ['linkedin'], ['contact_id' => $c3]);
        $eSept = sfoEntreprise($ws);
        $c7 = sfoContact($ws, $eSept); // contact ciblé sans personne propre, mais son entreprise est du réseau (R2)
        sfoPersonne($ws, ['linkedin'], ['company_id' => $eSept]);
        $campagne = sfoCampagne($ws, [['company' => $e1], ['company' => $e2], ['contact' => $c3], ['contact' => $c4], ['contact' => $c7]]);
        sfoAllumer($ws);
        sfoFiltrer($ws, $campagne, ['linkedin']);
        $l = sfoInvariant(sfoSel($mode, $ws, $campagne));
        expect(sfoIds($l))->toBe([$k1, $c3, $c7])->and($l->compteurs['hors_reseau'])->toBe(2, $mode);
        sfoFiltrer($ws, $campagne, ['facebook', 'linkedin']);
        $l = sfoInvariant(sfoSel($mode, $ws, $campagne));
        expect(sfoIds($l))->toBe([$k1, $k2, $c3, $c7])->and($l->compteurs['hors_reseau'])->toBe(1, $mode);
    }
});

test('X1-L2b-C 6 personne opposée jamais sélectionnée : O1 hors_reseau, O2 opposition, un autre nominatif retenu', function () {
    foreach (SFO_MODES as $mode) {
        $ws = sfoEspace();
        $e5 = sfoEntreprise($ws);
        $c5 = sfoContact($ws, $e5);
        $c5b = sfoContact($ws, $e5);
        sfoPersonne($ws, ['linkedin'], ['contact_id' => $c5, 'opposition_at' => now()]); // adresse de la personne ≠ adresse du contact
        $parEntreprise = sfoCampagne($ws, [['company' => $e5]]);
        $parContact = sfoCampagne($ws, [['contact' => $c5]]); // listes séparées : sinon E5 serait « déjà couverte » par C5
        sfoAllumer($ws);
        sfoFiltrer($ws, $parEntreprise, ['linkedin']);
        sfoFiltrer($ws, $parContact, ['linkedin']);
        foreach ([$parEntreprise, $parContact] as $id) {
            $l = sfoInvariant(sfoSel($mode, $ws, $id));
            expect(sfoIds($l))->toBe([])->and($l->compteurs['hors_reseau'])->toBe(1, "{$mode} O1 : une personne opposée ne fait entrer personne");
        }
        sfoPersonne($ws, ['linkedin'], ['company_id' => $e5]); // E5 entre par une personne non opposée
        $l = sfoInvariant(sfoSel($mode, $ws, $parEntreprise));
        expect(sfoIds($l))->toBe([$c5b])->and($l->compteurs['opposition'])->toBe(0)->and($l->compteurs['hors_reseau'])->toBe(0, "{$mode} O2 : C5 écarté, C5b retenu pour E5");
        $l = sfoInvariant(sfoSel($mode, $ws, $parContact));
        expect(sfoIds($l))->toBe([])->and($l->compteurs['opposition'])->toBe(1)->and($l->compteurs['hors_reseau'])->toBe(0, "{$mode} O2 : C5 ciblé = opposition");
        // E6 dont le seul contact est rattaché à une personne opposée : jamais choisi
        $e6 = sfoEntreprise($ws);
        $c6 = sfoContact($ws, $e6);
        sfoPersonne($ws, ['linkedin'], ['contact_id' => $c6, 'opposition_at' => now()]);
        sfoPersonne($ws, ['linkedin'], ['company_id' => $e6]);
        $id = sfoCampagne($ws, [['company' => $e6]]);
        sfoFiltrer($ws, $id, ['linkedin']);
        $l = sfoInvariant(sfoSel($mode, $ws, $id));
        expect(sfoIds($l))->toBe([])->and($l->compteurs['opposition'])->toBe(1, "{$mode} : le seul contact de E6 est opposé");
    }
});

test('X1-L2b-C 7 les exclusions existantes restent pour une unité du réseau (opposition, rgpd en cours, déjà en séquence)', function () {
    foreach (SFO_MODES as $mode) {
        $ws = sfoEspace();
        $membres = [];
        $contacts = [];
        foreach (['opp', 'rgpd', 'seq', 'ok'] as $cas) {
            $e = sfoEntreprise($ws);
            $contacts[$cas] = [sfoContact($ws, $e, "{$cas}.nom@societe.example.test"), $e];
            sfoPersonne($ws, ['linkedin'], ['company_id' => $e]);
            $membres[] = ['company' => $e];
        }
        $h = ListeSuppression::empreinte('opp.nom@societe.example.test');
        $lien = DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $ws, 'empreinte_adresse' => $h, 'portee' => 'business']);
        DB::table('envoi_oppositions')->insert(['workspace_id' => $ws, 'lien_id' => $lien, 'empreinte_adresse' => $h, 'canal' => 'one_click']);
        DB::table('rgpd_requests')->insert(['workspace_id' => $ws, 'type' => 'access', 'status' => 'pending', 'subject_email' => 'rgpd.nom@societe.example.test']);
        $s = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $s, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'S', 'corps' => 'C']);
        DB::table('envoi_inscriptions')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'contact_id' => $contacts['seq'][0], 'entreprise_id' => $contacts['seq'][1],
            'empreinte_adresse' => ListeSuppression::empreinte('seq.nom@societe.example.test'), 'statut' => 'active', 'prochaine_echeance' => now()->addDay(), 'version_figee' => '{}']);
        $campagne = sfoCampagne($ws, $membres);
        sfoAllumer($ws);
        sfoFiltrer($ws, $campagne, ['linkedin']);
        $l = sfoInvariant(sfoSel($mode, $ws, $campagne));
        expect(sfoIds($l))->toBe([$contacts['ok'][0]])->and([$l->compteurs['opposition'], $l->compteurs['rgpd_en_cours'], $l->compteurs['deja_en_sequence'], $l->compteurs['hors_reseau']])->toBe([1, 1, 1, 0], $mode);
    }
});

test('X1-L2b-C 8 archive et masque_le : origine seulement dans l\'archive = retenue ; seulement masquée = hors_reseau', function () {
    foreach (SFO_MODES as $mode) {
        $ws = sfoEspace();
        $e1 = sfoEntreprise($ws);
        $k1 = sfoContact($ws, $e1);
        $e2 = sfoEntreprise($ws);
        sfoContact($ws, $e2);
        OriginesJeu::archive($ws, sfoPersonne($ws, [], ['company_id' => $e1]), OriginesJeu::entree(['reseau' => 'linkedin']));
        OriginesJeu::archive($ws, sfoPersonne($ws, [], ['company_id' => $e2]), OriginesJeu::entree(['reseau' => 'linkedin']), '2026-11-02T10:00:00Z');
        $campagne = sfoCampagne($ws, [['company' => $e1], ['company' => $e2]]);
        sfoAllumer($ws);
        sfoFiltrer($ws, $campagne, ['linkedin']);
        $l = sfoInvariant(sfoSel($mode, $ws, $campagne));
        expect(sfoIds($l))->toBe([$k1])->and($l->compteurs['hors_reseau'])->toBe(1, $mode);
    }
});

test('X1-L2b-C 9 cloisonnement : l\'origine d\'un autre espace n\'a aucun effet ; campagne d\'un autre espace = campagne_introuvable', function () {
    foreach (SFO_MODES as $mode) {
        $a = sfoEspace();
        $b = sfoEspace();
        $eA = sfoEntreprise($a);
        sfoContact($a, $eA);
        $eB = sfoEntreprise($b);
        sfoContact($b, $eB);
        sfoPersonne($b, ['linkedin'], ['company_id' => $eA]); // identifiant de A, personne de B : sans effet dans A
        sfoPersonne($b, ['linkedin'], ['company_id' => $eB]);
        $campagneA = sfoCampagne($a, [['company' => $eA]]);
        $campagneB = sfoCampagne($b, [['company' => $eB]]);
        sfoAllumer($a);
        sfoFiltrer($a, $campagneA, ['linkedin']);
        $l = sfoInvariant(sfoSel($mode, $a, $campagneA));
        expect(sfoIds($l))->toBe([])->and($l->compteurs['hors_reseau'])->toBe(1, $mode);
        expect(sfoRefus($mode, $a, $campagneB))->toBe(['campagne_introuvable', false]);
    }
});

test('X1-L2b-C 10 curseur : 5 membres, taille 2, filtre actif = 3 lots ; la somme des compteurs est un lot de 5', function () {
    foreach (SFO_MODES as $mode) {
        $ws = sfoEspace();
        $membres = [];
        for ($i = 0; $i < 5; $i++) {
            $e = sfoEntreprise($ws);
            sfoContact($ws, $e);
            $i < 3 ? sfoPersonne($ws, ['linkedin'], ['company_id' => $e]) : null;
            $membres[] = ['company' => $e];
        }
        $campagne = sfoCampagne($ws, $membres);
        sfoAllumer($ws);
        sfoFiltrer($ws, $campagne, ['linkedin']);
        $lots = [];
        for ($apres = 0, $n = 0; $n < 10; $n++) {
            $lots[] = sfoInvariant(sfoSel($mode, $ws, $campagne, $apres, 2));
            $apres = $lots[$n]->curseurSuivant ?? -1;
            if ($apres < 0) {
                break;
            }
        }
        expect($lots)->toHaveCount(3)->and(array_sum(array_map(static fn ($l) => $l->compteurs['retenus'], $lots)))->toBe(3)
            ->and(array_sum(array_map(static fn ($l) => $l->compteurs['hors_reseau'], $lots)))->toBe(2)->and(array_sum(array_map(static fn ($l) => $l->compteurs['membres'], $lots)))->toBe(5);
    }
});

test('X1-L2b-C 11 lecture seule : aucune écriture ; statement_timeout posé avant la première lecture', function () {
    $ws = sfoEspace();
    $e = sfoEntreprise($ws);
    sfoContact($ws, $e);
    sfoPersonne($ws, ['linkedin'], ['company_id' => $e]);
    $campagne = sfoCampagne($ws, [['company' => $e]]);
    sfoAllumer($ws);
    sfoFiltrer($ws, $campagne, ['linkedin']);
    $vues = sfoRequetes(fn () => sfoSel('proprio', $ws, $campagne));
    expect(preg_grep('/^\s*(insert|update|delete)\b/i', $vues))->toBe([]);
    $premiere = array_key_first(preg_grep('/from "?envoi_campagnes"?/i', $vues));
    expect(array_key_first(preg_grep('/statement_timeout/', $vues)))->toBeLessThan($premiere);
});

test('X1-L2b-C 12 erreur de lecture des origines : lecture_origine_impossible réessayable, aucun destinataire', function () {
    $ws = sfoEspace();
    $e = sfoEntreprise($ws);
    sfoContact($ws, $e);
    sfoPersonne($ws, ['linkedin'], ['company_id' => $e]);
    $campagne = sfoCampagne($ws, [['company' => $e]]);
    sfoAllumer($ws);
    sfoFiltrer($ws, $campagne, ['linkedin']);
    DB::statement('REVOKE SELECT ON personne_origines_archive FROM "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    expect(sfoRefus('app', $ws, $campagne))->toBe(['lecture_origine_impossible', true]);
});

test('X1-L2b-C 13 la requête de campagne lit la colonne par to_jsonb(k) -> filtre_origine (déploiement : code avant migration)', function () {
    expect(file_get_contents(app_path('Envoi/Campagnes/SelectionDestinataires.php')))->toContain("to_jsonb(k) -> 'filtre_origine'");
});

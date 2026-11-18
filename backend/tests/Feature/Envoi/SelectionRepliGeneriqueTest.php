<?php

use App\Crm\Emails\QualificationEmail;
use App\Crm\Opposition\StatutOpposition;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\LotDestinataires;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Campagnes\SelectionRefusee;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot L3 (PLAN ADRESSES GÉNÉRALES, #680) — le repli sur l'adresse générale dans la règle E7 de la sélection. Sous le rôle applicatif
 * (RLS) ET sous le propriétaire. Données fictives (`*.example.test`), aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => WorkspaceContext::clear());
afterEach(fn () => WorkspaceContext::clear());

const SRG_MODES = ['app', 'proprio'];

function srgEspace(bool $ouvert = true): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Srg', 'slug' => 'srg-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    if ($ouvert) {
        srgInterrupteur($id, 'true');
    }

    return $id;
}

function srgInterrupteur(string $ws, string $valeur): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_reglages')->updateOrInsert(['workspace_id' => $ws, 'cle' => 'envoi.repli_generique'], ['valeur' => $valeur, 'auteur' => 'fondateur']));
}

function srgN(): int
{
    static $n = 20000000;

    return ++$n;
}

function srgEntreprise(string $ws, array $plus = []): int
{
    return (int) DB::table('companies')->insertGetId($plus + ['workspace_id' => $ws, 'siren' => str_pad((string) srgN(), 9, '0', STR_PAD_LEFT), 'denomination' => 'Société fictive']);
}

function srgContact(string $ws, int $co, string $email): int
{
    return (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $co, 'first_name' => 'Prenom', 'last_name' => 'Nom' . srgN(), 'email' => $email]);
}

function srgCampagne(string $ws, array $entreprises): string
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . srgN()]);
    foreach ($entreprises as $e) {
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'contact_id' => $e['contact'] ?? null, 'company_id' => $e['company'] ?? null, 'origine' => 'coche']);
    }

    return app(DepotCampagnes::class)->creer($ws, 'Campagne fictive', ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Cible fictive'], [], 1, [], 'le fondateur');
}

function srgSel(string $mode, string $ws, string $campagne, ?Closure $lecteur = null): LotDestinataires
{
    if ($mode === 'app') {
        WorkspaceContext::set($ws);
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return (new SelectionDestinataires($lecteur))->lot($ws, $campagne, 'entreprises');
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    }
}

function srgInvariant(LotDestinataires $l): LotDestinataires
{
    $c = $l->compteurs;
    $somme = array_sum(array_map(static fn (string $m): int => $c[$m], SelectionDestinataires::MOTIFS));
    expect($c['membres'])->toBe($c['retenus'] + $somme)->and($c['retenus'])->toBe(count($l->destinataires))
        ->and(strlen((string) json_encode($c)))->toBeLessThan(2048);
    if (isset($c['retenus_repli_generique'])) {
        expect($c['retenus_repli_generique'])->toBe(count(array_filter($l->destinataires, static fn ($d) => $d->repliGenerique)));
    }

    return $l;
}

function srgOppose(string $ws, string $adresse): void
{
    $h = ListeSuppression::empreinte($adresse);
    $lien = DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $ws, 'empreinte_adresse' => $h, 'portee' => 'business']);
    DB::table('envoi_oppositions')->insert(['workspace_id' => $ws, 'lien_id' => $lien, 'empreinte_adresse' => $h, 'canal' => 'one_click']);
}

/** Inscription fictive ; `repli` = inscription de repli (une seule étape, l'interrupteur doit être allumé). */
function srgInscription(string $ws, int $contact, int $entreprise, string $adresse, string $statut = 'active', bool $repli = false): void
{
    $s = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $s, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);
    DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'S', 'corps' => 'C']);
    DB::table('envoi_inscriptions')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'contact_id' => $contact, 'entreprise_id' => $entreprise,
        'empreinte_adresse' => ListeSuppression::empreinte($adresse), 'statut' => $statut, 'motif_arret' => $statut === 'arretee' ? 'test' : null,
        'prochaine_echeance' => now()->addDay(), 'version_figee' => $repli ? '{"etapes":[{"rang":1}]}' : '{}', 'repli_generique' => $repli]);
}

/** Une entreprise + ses adresses ; rend [entreprise, [contact ids]]. */
function srgFiche(string $ws, array $adresses, array $plus = []): array
{
    $e = srgEntreprise($ws, $plus);

    return [$e, array_map(fn (string $a): int => srgContact($ws, $e, $a), $adresses)];
}

test('L3 interrupteur éteint : résultat identique à aujourd\'hui, aucun repli, aucun sous-compte', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace(false);
        [$e] = srgFiche($ws, ['contact@a.example.test']);
        [$f, $ids] = srgFiche($ws, ['info@b.example.test', 'anne.lefort@b.example.test']);
        $l = srgInvariant(srgSel($mode, $ws, srgCampagne($ws, [['company' => $e], ['company' => $f]])));
        expect($l->destinataires)->toHaveCount(1)->and($l->destinataires[0]->contactId)->toBe($ids[1])->and($l->destinataires[0]->repliGenerique)->toBeFalse()
            ->and($l->compteurs['sans_contact'])->toBe(1)->and($l->compteurs['sans_contact_generique_disponible'])->toBe(1)
            ->and($l->compteurs)->not->toHaveKey('retenus_repli_generique');
        srgInterrupteur($ws, 'false'); // posé à « false » : éteint (les valeurs illisibles sont couvertes par les tests de L1)
        expect(srgSel($mode, $ws, srgCampagne($ws, [['company' => $e]]))->destinataires)->toBe([]);
    }
});

test('L3 ouvert + seule adresse générale admise : retenue en repli, drapeau posé, compteurs', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace();
        [$e, $ids] = srgFiche($ws, ['info@a.example.test', 'contact@a.example.test']);
        [$f] = srgFiche($ws, ['presse@b.example.test']);
        $l = srgInvariant(srgSel($mode, $ws, srgCampagne($ws, [['company' => $e], ['company' => $f]])));
        expect($l->destinataires)->toHaveCount(1)->and($l->destinataires[0]->repliGenerique)->toBeTrue()
            ->and($l->destinataires[0]->contactId)->toBe($ids[1]) // l'ordre de la liste blanche : contact avant info
            ->and($l->destinataires[0]->empreinte)->toBe(ListeSuppression::empreinte('contact@a.example.test'))
            ->and($l->compteurs['retenus'])->toBe(1)->and($l->compteurs['retenus_repli_generique'])->toBe(1)
            ->and($l->compteurs['sans_contact'])->toBe(1); // presse@ seul : hors liste blanche, jamais retenu
        expect(json_encode($l))->not->toContain('@');
    }
});

test('L3 un nommé existe mais est exclu : jamais de repli (le générique ne contourne pas une exclusion)', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace();
        [$a, $ia] = srgFiche($ws, ['contact@a.example.test', 'paul.opp@a.example.test']);
        srgOppose($ws, 'paul.opp@a.example.test');
        [$b, $ib] = srgFiche($ws, ['contact@b.example.test', 'bruno.seq@b.example.test']);
        srgInscription($ws, $ib[1], $b, 'bruno.seq@b.example.test');
        $l = srgInvariant(srgSel($mode, $ws, srgCampagne($ws, [['company' => $a], ['company' => $b]])));
        expect($l->destinataires)->toBe([])->and($l->compteurs['opposition'])->toBe(1)->and($l->compteurs['deja_en_sequence'])->toBe(1)
            ->and($l->compteurs['retenus_repli_generique'])->toBe(0);
    }
});

test('L3 SIREN opposé, adresse générale elle-même opposée : aucun repli, motif opposition', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace();
        [$a] = srgFiche($ws, ['contact@a.example.test']);
        [$b] = srgFiche($ws, ['info@b.example.test']);
        srgOppose($ws, 'info@b.example.test');
        // Le SIREN de A est opposé : la ligne « entreprise » (`o<id>`) revient opposée, le reste est lu pour de vrai.
        $lecteur = function (string $w, array $lignes) use ($a): array {
            $r = (new StatutOpposition)->pourLignes($w, $lignes);
            $r['o' . $a]['opposee'] = true;

            return $r;
        };
        $l = srgInvariant(srgSel($mode, $ws, srgCampagne($ws, [['company' => $a], ['company' => $b]]), $lecteur));
        expect($l->destinataires)->toBe([])->and($l->compteurs['opposition'])->toBe(2);
    }
});

test('L3 20 contacts lus (lecture tronquée) : pas de repli ; 19 : repli', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace();
        $a = srgEntreprise($ws);
        srgContact($ws, $a, 'contact@a.example.test');
        for ($i = 1; $i < SelectionDestinataires::CONTACTS_PAR_ENTREPRISE; $i++) {
            srgContact($ws, $a, "presse{$i}@a.example.test");
        }
        $b = srgEntreprise($ws);
        srgContact($ws, $b, 'contact@b.example.test');
        for ($i = 1; $i < SelectionDestinataires::CONTACTS_PAR_ENTREPRISE - 1; $i++) {
            srgContact($ws, $b, "presse{$i}@b.example.test");
        }
        $l = srgInvariant(srgSel($mode, $ws, srgCampagne($ws, [['company' => $a], ['company' => $b]])));
        expect($l->destinataires)->toHaveCount(1)->and($l->destinataires[0]->entrepriseId)->toBe($b)->and($l->compteurs['sans_contact'])->toBe(1);
    }
});

test('L3 repli déjà fait (tous statuts) ⇒ deja_en_sequence ; repli actif ⇒ idem ; inscription non-repli terminée ne bloque pas', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace();
        [$a, $ia] = srgFiche($ws, ['contact@a.example.test']);
        srgInscription($ws, $ia[0], $a, 'contact@a.example.test', 'terminee', true);
        [$b, $ib] = srgFiche($ws, ['contact@b.example.test']);
        srgInscription($ws, $ib[0], $b, 'contact@b.example.test', 'active', true);
        [$c, $ic] = srgFiche($ws, ['contact@c.example.test']);
        srgInscription($ws, $ic[0], $c, 'contact@c.example.test', 'terminee', false);
        $l = srgInvariant(srgSel($mode, $ws, srgCampagne($ws, [['company' => $a], ['company' => $b], ['company' => $c]])));
        expect($l->compteurs['deja_en_sequence'])->toBe(2)->and(array_map(fn ($d) => $d->entrepriseId, $l->destinataires))->toBe([$c]);
    }
});

test('L3 adresse partagée par plusieurs fiches (D10) ⇒ exclue, gardée en sans_contact', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace();
        [$a] = srgFiche($ws, ['contact@cabinet.example.test']);
        srgFiche($ws, ['contact@cabinet.example.test']);
        [$b] = srgFiche($ws, ['contact@seule.example.test']);
        $l = srgInvariant(srgSel($mode, $ws, srgCampagne($ws, [['company' => $a], ['company' => $b]])));
        expect(array_map(fn ($d) => $d->entrepriseId, $l->destinataires))->toBe([$b])->and($l->compteurs['sans_contact'])->toBe(1);
    }
});

test('L3 contact ciblé générique : generique_cible inchangé ; doublon d\'adresse dans le lot', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace();
        [$a, $ia] = srgFiche($ws, ['contact@a.example.test']);
        $l = srgInvariant(srgSel($mode, $ws, srgCampagne($ws, [['contact' => $ia[0]]])));
        expect($l->destinataires)->toBe([])->and($l->compteurs['generique_cible'])->toBe(1);
    }
});

test('L3 suffixe non exclu (contact.dupont) : ne passe que s\'il est déjà classé « générique » par QualificationEmail', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace();
        [$a] = srgFiche($ws, ['contact.dupont@a.example.test']);
        [$b] = srgFiche($ws, ['marie.contact@b.example.test']); // nominatif : ne devient jamais un repli
        $l = srgInvariant(srgSel($mode, $ws, srgCampagne($ws, [['company' => $a], ['company' => $b]])));
        $attendu = QualificationEmail::type('contact.dupont@a.example.test') === 'generique' ? [$a, $b] : [$b];
        expect(array_map(fn ($d) => $d->entrepriseId, $l->destinataires))->toBe($attendu);
        $repli = array_values(array_filter($l->destinataires, fn ($d) => $d->repliGenerique));
        expect(array_map(fn ($d) => $d->entrepriseId, $repli))->toBe(in_array($a, $attendu, true) ? [$a] : []);
    }
});

test('L3 exception de lecture avec le repli ouvert : SelectionRefusee, aucun destinataire', function () {
    foreach (SRG_MODES as $mode) {
        $ws = srgEspace();
        [$a] = srgFiche($ws, ['contact@a.example.test']);
        $campagne = srgCampagne($ws, [['company' => $a]]);
        DB::statement('SAVEPOINT srg');
        expect(fn () => srgSel($mode, $ws, $campagne, fn () => throw new RuntimeException('lecture impossible')))->toThrow(SelectionRefusee::class);
        DB::statement('ROLLBACK TO SAVEPOINT srg');
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    }
});

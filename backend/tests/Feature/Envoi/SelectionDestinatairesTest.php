<?php

use App\Crm\FichesProtegees;
use App\Crm\Presse\QualificationPresse;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\Destinataire;
use App\Envoi\Campagnes\LotDestinataires;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Campagnes\SelectionRefusee;
use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot X1-L2 — sélection des destinataires d'une campagne (LECTURE SEULE). Chaque test tourne sous le rôle applicatif (RLS)
 * ET sous le propriétaire. Données fictives (`*.example.test`), aucune personne réelle, aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const SDX_MODES = ['app', 'proprio'];

function sdxEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Sdx', 'slug' => 'sdx-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function sdxN(): int
{
    static $n = 10000000;

    return ++$n;
}

/** SIREN fictif à clé de contrôle valide (algorithme de Luhn), ex. `sdxSiren(12345678)`. */
function sdxSiren(int $base): string
{
    $b = str_pad((string) $base, 8, '0', STR_PAD_LEFT);
    for ($cle = 0; $cle <= 9; $cle++) {
        $somme = 0;
        foreach (str_split($b . $cle) as $i => $d) {
            $n = (int) $d * ($i % 2 === 1 ? 2 : 1);
            $somme += $n > 9 ? $n - 9 : $n;
        }
        if ($somme % 10 === 0) {
            return $b . $cle;
        }
    }
    throw new LogicException('inatteignable');
}

function sdxEntreprise(string $ws, array $plus = []): int
{
    return (int) DB::table('companies')->insertGetId($plus + ['workspace_id' => $ws, 'siren' => sdxSiren(sdxN()), 'denomination' => 'Société fictive']);
}

function sdxContact(string $ws, int $co, ?string $email, array $plus = []): int
{
    return (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $co, 'first_name' => 'Prenom', 'last_name' => 'Nom' . sdxN(), 'email' => $email] + $plus);
}

function sdxTag(string $ws, string $slug): int
{
    $id = DB::table('tags')->where('workspace_id', $ws)->where('slug', $slug)->value('id');

    return (int) ($id ?? DB::table('tags')->insertGetId(['workspace_id' => $ws, 'slug' => $slug, 'name' => $slug]));
}

function sdxEtiqueter(string $ws, int $co, string $slug, ?int $tag = null): void
{
    DB::table('company_tag')->insert(['company_id' => $co, 'tag_id' => $tag ?? sdxTag($ws, $slug), 'workspace_id' => $ws]);
}

/** @param list<array{contact?: int, company?: int, retire?: bool}> $membres */
function sdxListe(string $ws, array $membres, bool $corbeille = false): int
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . sdxN(), 'deleted_at' => $corbeille ? now() : null]);
    foreach ($membres as $m) {
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'contact_id' => $m['contact'] ?? null, 'company_id' => $m['company'] ?? null,
            'origine' => 'coche', 'retire_le' => ($m['retire'] ?? false) ? now() : null]);
    }

    return $liste;
}

function sdxCampagne(string $ws, string $type, string|int $ref, array $saisie = []): string
{
    return app(DepotCampagnes::class)->creer($ws, 'Campagne fictive', ['type' => $type, 'reference' => (string) $ref, 'libelle' => 'Cible fictive'], [], 1, $saisie, 'le fondateur');
}

/** Rend la liste de membres « une entreprise par ligne » puis la campagne sur cette liste. */
function sdxCampagneListe(string $ws, array $membres, array $saisie = []): string
{
    return sdxCampagne($ws, 'liste', sdxListe($ws, $membres), $saisie);
}

/** Exécute sous le mode demandé (« app » = rôle applicatif non propriétaire sous RLS ; « proprio » = propriétaire). */
function sdxSel(string $mode, string $ws, string $campagne, string $population = 'entreprises', int $apres = 0, int $taille = 200, ?Closure $lecteur = null): LotDestinataires
{
    if ($mode === 'app') {
        WorkspaceContext::set($ws);
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return (new SelectionDestinataires($lecteur))->lot($ws, $campagne, $population, $apres, $taille);
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    }
}

/** Le motif d'un refus (null = accepté). */
function sdxMotif(string $mode, string $ws, string $campagne, string $population = 'entreprises', int $apres = 0, int $taille = 200, ?Closure $lecteur = null): ?string
{
    DB::statement('SAVEPOINT sdx_refus');
    try {
        sdxSel($mode, $ws, $campagne, $population, $apres, $taille, $lecteur);
        DB::statement('RELEASE SAVEPOINT sdx_refus');

        return null;
    } catch (SelectionRefusee $e) {
        DB::statement('ROLLBACK TO SAVEPOINT sdx_refus');
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }

        return $e->motif;
    }
}

/** L'invariant du cahier, vérifié à CHAQUE lot. */
function sdxInvariant(LotDestinataires $l): LotDestinataires
{
    $c = $l->compteurs;
    foreach ([...SelectionDestinataires::MOTIFS, 'membres', 'retenus', 'sans_contact_generique_disponible'] as $cle) {
        expect($c)->toHaveKey($cle);
        expect($c[$cle])->toBeInt();
    }
    $somme = array_sum(array_map(static fn (string $m): int => $c[$m], SelectionDestinataires::MOTIFS));
    expect($c['membres'])->toBe($c['retenus'] + $somme)->and($c['retenus'])->toBe(count($l->destinataires));

    return $l;
}

/** @return list<int> */
function sdxIds(LotDestinataires $l): array
{
    $ids = array_map(static fn (Destinataire $d): int => $d->contactId, $l->destinataires);
    sort($ids);

    return $ids;
}

function sdxOppose(string $ws, string $adresse): void
{
    $h = ListeSuppression::empreinte($adresse);
    $lien = DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $ws, 'empreinte_adresse' => $h, 'portee' => 'business']);
    DB::table('envoi_oppositions')->insert(['workspace_id' => $ws, 'lien_id' => $lien, 'empreinte_adresse' => $h, 'canal' => 'one_click']);
}

function sdxDemande(string $ws, string $adresse, string $type, string $statut): void
{
    DB::table('rgpd_requests')->insert(['workspace_id' => $ws, 'type' => $type, 'status' => $statut, 'subject_email' => $adresse]);
}

function sdxInscription(string $ws, int $contact, int $entreprise, string $adresse, string $statut = 'active'): void
{
    static $sequences = [];
    $sequences[$ws] ??= (function () use ($ws): string {
        $s = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $s, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);
        DB::table('envoi_sequence_etapes')->insert(['workspace_id' => $ws, 'sequence_id' => $s, 'rang' => 1, 'delai_jours_ouvres' => 0, 'sujet' => 'S', 'corps' => 'C']);

        return $s;
    })();
    DB::table('envoi_inscriptions')->insert(['workspace_id' => $ws, 'sequence_id' => $sequences[$ws], 'contact_id' => $contact, 'entreprise_id' => $entreprise,
        'empreinte_adresse' => ListeSuppression::empreinte($adresse), 'statut' => $statut, 'motif_arret' => $statut === 'arretee' ? 'test' : null,
        'prochaine_echeance' => now()->addDay(), 'version_figee' => '{}']);
}

test('X1-L2 1 population « personnes » ou inconnue : refus population_non_ouverte, ZÉRO requête', function () {
    foreach (SDX_MODES as $mode) {
        foreach (['personnes', 'xyz'] as $population) {
            $requetes = 0;
            DB::listen(function () use (&$requetes) {
                $requetes++;
            });
            try {
                sdxSel($mode, sdxEspace(), (string) Str::uuid(), $population);
                $motif = null;
                $rea = null;
            } catch (SelectionRefusee $e) {
                $motif = $e->motif;
                $rea = $e->reessayable;
            }
            // `sdxEspace()` et `SET LOCAL ROLE` sont des requêtes du test : on mesure la seule sélection
            expect($motif)->toBe('population_non_ouverte', "{$mode} {$population}")->and($rea)->toBeFalse();
        }
        $ws = sdxEspace();
        $campagne = sdxCampagneListe($ws, []);
        $vues = [];
        DB::listen(function ($q) use (&$vues) {
            $vues[] = $q->sql;
        });
        expect(fn () => (new SelectionDestinataires)->lot($ws, $campagne, 'personnes'))->toThrow(SelectionRefusee::class);
        expect($vues)->toBe([], "{$mode} : refus avant toute requête");
    }
});

test('X1-L2 2 liste mixte (contact + entreprise) : forme typée exacte, empreinte de l\'adresse BRUTE', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $brute = '  Jeanne.MARTIN@Exemple.example.test ';
        $e1 = sdxEntreprise($ws);
        $c1 = sdxContact($ws, $e1, $brute);
        $e2 = sdxEntreprise($ws);
        $c2 = sdxContact($ws, $e2, 'paul.durand@societe.example.test');
        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, [['contact' => $c1], ['company' => $e2]])));

        expect($l->destinataires)->toHaveCount(2)->and($l->curseurSuivant)->toBeNull();
        $parContact = [];
        foreach ($l->destinataires as $d) {
            expect($d)->toBeInstanceOf(Destinataire::class)->and($d->population)->toBe('entreprises')->and($d->personneId)->toBeNull();
            expect($d->empreinte)->toMatch('/^[0-9a-f]{64}$/');
            $parContact[$d->contactId] = $d;
        }
        expect($parContact[$c1]->entrepriseId)->toBe($e1)->and($parContact[$c1]->empreinte)->toBe(ListeSuppression::empreinte($brute))
            ->and($parContact[$c2]->entrepriseId)->toBe($e2)
            ->and($l->compteurs['membres'])->toBe(2)->and($l->compteurs['retenus'])->toBe(2);
        // aucune adresse en sortie
        expect(json_encode($l))->not->toContain('@');
    }
});

test('X1-L2 3 règle E7 : nominatif jamais générique, plus petit id, repli si opposé, sans_contact', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        // A : générique au plus petit id + nominatif => le nominatif
        $a = sdxEntreprise($ws);
        sdxContact($ws, $a, 'contact@a.example.test');
        $aNom = sdxContact($ws, $a, 'anne.lefort@a.example.test');
        // B : deux nominatifs => le plus petit id
        $b = sdxEntreprise($ws);
        $bPetit = sdxContact($ws, $b, 'bruno.petit@b.example.test');
        sdxContact($ws, $b, 'berthe.grand@b.example.test');
        // C : générique seul ; D : email_generic de la fiche seul ; E : aucun contact
        $c = sdxEntreprise($ws);
        sdxContact($ws, $c, 'info@c.example.test');
        $d = sdxEntreprise($ws, ['email_generic' => 'contact@d.example.test']);
        $e = sdxEntreprise($ws);
        // F : le plus petit nominatif est opposé => on prend le suivant
        $f = sdxEntreprise($ws);
        $fOppose = sdxContact($ws, $f, 'fabien.opp@f.example.test');
        $fSuivant = sdxContact($ws, $f, 'fanny.suite@f.example.test');
        sdxOppose($ws, 'fabien.opp@f.example.test');

        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, array_map(static fn (int $x): array => ['company' => $x], [$a, $b, $c, $d, $e, $f]))));

        expect(sdxIds($l))->toBe([$aNom, $bPetit, $fSuivant], $mode)
            ->and($l->compteurs['sans_contact'])->toBe(3)
            ->and($l->compteurs['sans_contact_generique_disponible'])->toBe(2)
            ->and($l->compteurs['membres'])->toBe(6)->and($l->compteurs['retenus'])->toBe(3)
            ->and($l->compteurs['opposition'])->toBe(0);
        expect(in_array($fOppose, sdxIds($l), true))->toBeFalse();
    }
});

test('X1-L2 3b tous les nominatifs exclus : le motif le plus prioritaire rencontré', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $e = sdxEntreprise($ws);
        sdxContact($ws, $e, 'olivia.opp@z.example.test');
        sdxContact($ws, $e, 'jean.presse@z.example.test', ['external_ref' => 'journaliste:42']);
        sdxOppose($ws, 'olivia.opp@z.example.test');
        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, [['company' => $e]])));
        expect($l->destinataires)->toBe([])->and($l->compteurs['opposition'])->toBe(1)->and($l->compteurs['presse'])->toBe(0)->and($l->compteurs['membres'])->toBe(1);
    }
});

test('X1-L2 4 priorité : opposition avant presse ; un SIREN au registre exclut l\'entreprise en « opposition »', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $e1 = sdxEntreprise($ws);
        $both = sdxContact($ws, $e1, 'jeanne.presse@p.example.test', ['external_ref' => 'journaliste:7']);
        sdxOppose($ws, 'jeanne.presse@p.example.test');
        $e2 = sdxEntreprise($ws, ['siren' => sdxSiren(1234567)]);
        sdxContact($ws, $e2, 'sylvie.siren@s.example.test');
        (new RegistreOppositionManuel)->inscrire($ws, 'organisation', sdxSiren(1234567), 'test', 'test');
        // fiche de presse dont le SIREN est opposé => opposition ; fiche de presse seule => presse
        $e3 = sdxEntreprise($ws, ['siren' => sdxSiren(1234568)]);
        sdxEtiqueter($ws, $e3, FichesProtegees::TAG_PRESSE);
        (new RegistreOppositionManuel)->inscrire($ws, 'organisation', sdxSiren(1234568), 'test', 'test');
        $e4 = sdxEntreprise($ws);
        sdxEtiqueter($ws, $e4, FichesProtegees::TAG_PRESSE);
        sdxContact($ws, $e4, 'marc.fiche@q.example.test');

        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, [['contact' => $both], ['company' => $e2], ['company' => $e3], ['company' => $e4]])));
        expect($l->destinataires)->toBe([])->and($l->compteurs['opposition'])->toBe(3)->and($l->compteurs['presse'])->toBe(1)->and($l->compteurs['membres'])->toBe(4);
    }
});

test('X1-L2 5 lecteur d\'opposition en panne : lecture_opposition_impossible (réessayable), aucun destinataire ; clé absente = opposée', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $e = sdxEntreprise($ws);
        sdxContact($ws, $e, 'alain.test@o.example.test');
        $campagne = sdxCampagneListe($ws, [['company' => $e]]);

        $panne = static function (string $w, array $lignes): array {
            throw new RuntimeException('lecture impossible fictive');
        };
        DB::statement('SAVEPOINT sdx5');
        try {
            sdxSel($mode, $ws, $campagne, lecteur: $panne);
            $refus = null;
        } catch (SelectionRefusee $r) {
            $refus = $r;
            DB::statement('ROLLBACK TO SAVEPOINT sdx5');
            DB::statement('RESET ROLE');
        }
        expect($refus)->toBeInstanceOf(SelectionRefusee::class)->and($refus->motif)->toBe('lecture_opposition_impossible')->and($refus->reessayable)->toBeTrue()
            ->and($refus->getPrevious())->toBeInstanceOf(RuntimeException::class);

        // une clé absente de la réponse compte comme opposée (fermé)
        $vide = static fn (string $w, array $lignes): array => [];
        $l = sdxInvariant(sdxSel($mode, $ws, $campagne, lecteur: $vide));
        expect($l->destinataires)->toBe([])->and($l->compteurs['opposition'])->toBe(1);
    }
});

test('X1-L2 6 E6 : demandes RGPD pending/processing excluent (5 types) ; done/rejected/expired access, autre espace : non', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $autre = sdxEspace();
        $membres = [];
        $i = 0;
        foreach (['access', 'portability', 'erasure', 'rectification', 'opposition'] as $type) {
            foreach (['pending', 'processing'] as $statut) {
                $adresse = 'Rgpd' . ++$i . '@r.example.test';
                $e = sdxEntreprise($ws);
                $membres[] = ['contact' => sdxContact($ws, $e, $adresse)];
                sdxDemande($ws, strtolower($adresse), $type, $statut); // casse différente : subject_email est CITEXT
            }
        }
        $propres = [];
        foreach (['done', 'rejected', 'expired'] as $statut) {
            $adresse = "ok.{$statut}@r.example.test";
            $e = sdxEntreprise($ws);
            $propres[] = $membres[] = ['contact' => sdxContact($ws, $e, $adresse)];
            sdxDemande($ws, $adresse, 'access', $statut);
        }
        $e = sdxEntreprise($ws);
        $propres[] = $membres[] = ['contact' => sdxContact($ws, $e, 'ok.autre@r.example.test')];
        sdxDemande($autre, 'ok.autre@r.example.test', 'access', 'pending');

        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, $membres)));
        expect($l->compteurs['rgpd_en_cours'])->toBe(8, $mode)->and($l->compteurs['opposition'])->toBe(2)->and($l->compteurs['retenus'])->toBe(4)
            ->and(sdxIds($l))->toBe(array_values(array_map(static fn (array $m): int => $m['contact'], $propres)));
    }
});

test('X1-L2 7 doublon : même adresse à la casse près => un seul retenu', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $e1 = sdxEntreprise($ws);
        $c1 = sdxContact($ws, $e1, 'Dup.Licate@d.example.test');
        $e2 = sdxEntreprise($ws);
        sdxContact($ws, $e2, 'dup.licate@d.example.test');
        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, [['company' => $e1], ['company' => $e2]])));
        expect(sdxIds($l))->toBe([$c1])->and($l->compteurs['doublon'])->toBe(1)->and($l->compteurs['membres'])->toBe(2);
    }
});

test('X1-L2 8 déjà en séquence : active (contact, empreinte, entreprise) exclut ; arretee/terminee non', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $e = sdxEntreprise($ws);
        $actif = sdxContact($ws, $e, 'actif@q.example.test');
        $parAdresse = sdxContact($ws, sdxEntreprise($ws), 'empreinte@q.example.test');
        $arretee = sdxContact($ws, sdxEntreprise($ws), 'arretee@q.example.test');
        $terminee = sdxContact($ws, sdxEntreprise($ws), 'terminee@q.example.test');
        sdxInscription($ws, $actif, $e, 'actif@q.example.test');
        sdxInscription($ws, $parAdresse + 9_000_000, 5_000_001, 'EMPREINTE@q.example.test'); // autre contact, même adresse
        sdxInscription($ws, $arretee, 5_000_002, 'arretee@q.example.test', 'arretee');
        sdxInscription($ws, $terminee, 5_000_003, 'terminee@q.example.test', 'terminee');
        // entreprise dont un AUTRE contact a une inscription active : exclue entière
        $eAutre = sdxEntreprise($ws);
        sdxContact($ws, $eAutre, 'un.contact@q.example.test');
        sdxInscription($ws, 8_000_001, $eAutre, 'ailleurs@q.example.test');

        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, [['contact' => $actif], ['contact' => $parAdresse], ['contact' => $arretee], ['contact' => $terminee], ['company' => $eAutre]])));
        expect(sdxIds($l))->toBe([$arretee, $terminee], $mode)->and($l->compteurs['deja_en_sequence'])->toBe(3);
    }
});

test('X1-L2 9 contact de presse, fiche de presse, client de la console : exclus', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $ePresse = sdxEntreprise($ws);
        $cPresse = sdxContact($ws, $ePresse, 'journ.aliste@j.example.test', ['sources' => json_encode([QualificationPresse::SOURCE])]);
        $eFiche = sdxEntreprise($ws);
        $cFiche = sdxContact($ws, $eFiche, 'fiche.presse@j.example.test');
        sdxEtiqueter($ws, $eFiche, FichesProtegees::TAG_PRESSE);
        $eClient = sdxEntreprise($ws);
        $cClient = sdxContact($ws, $eClient, 'client.console@j.example.test', ['discovery_source' => 'console']);
        $eTag = sdxEntreprise($ws);
        $cTag = sdxContact($ws, $eTag, 'client.etiquette@j.example.test');
        sdxEtiqueter($ws, $eTag, FichesProtegees::TAG_CONSOLE_CLIENT);
        $ok = sdxContact($ws, sdxEntreprise($ws), 'libre.ok@j.example.test');

        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, [['contact' => $cPresse], ['contact' => $cFiche], ['contact' => $cClient], ['contact' => $cTag], ['contact' => $ok]])));
        expect(sdxIds($l))->toBe([$ok], $mode)->and($l->compteurs['presse'])->toBe(2)->and($l->compteurs['client_console'])->toBe(2);
    }
});

test('X1-L2 9b motifs de fiche : sans adresse, générique ciblé, sans entreprise, fiche supprimée', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $e = sdxEntreprise($ws);
        $vide = sdxContact($ws, $e, '');
        $nul = sdxContact($ws, sdxEntreprise($ws), null);
        $generique = sdxContact($ws, sdxEntreprise($ws), 'contact@g.example.test');
        $suppr = sdxContact($ws, sdxEntreprise($ws), 'supprime@g.example.test', ['deleted_at' => now()]);
        $eSuppr = sdxEntreprise($ws, ['deleted_at' => now()]);
        $cEntSuppr = sdxContact($ws, $eSuppr, 'entreprise.supprimee@g.example.test');
        // contact dont l'entreprise est d'un AUTRE espace : jamais lue => « sans_entreprise »
        $autre = sdxEspace();
        $eEtrangere = sdxEntreprise($autre);
        DB::statement('SET session_replication_role = replica');
        $orphelin = sdxContact($ws, $eEtrangere, 'orphelin@g.example.test');
        DB::statement('SET session_replication_role = origin');
        $ok = sdxContact($ws, sdxEntreprise($ws), 'nina.valide@g.example.test');

        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, array_map(static fn (int $c): array => ['contact' => $c], [$vide, $nul, $generique, $suppr, $cEntSuppr, $orphelin, $ok]))));
        expect(sdxIds($l))->toBe([$ok], $mode)->and($l->compteurs['sans_adresse'])->toBe(2)->and($l->compteurs['generique_cible'])->toBe(1)
            ->and($l->compteurs['fiche_supprimee'])->toBe(2)->and($l->compteurs['sans_entreprise'])->toBe(1);
    }
});

test('X1-L2 10 liste : membre retiré ignoré, liste à la corbeille introuvable, entreprise déjà couverte', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $e1 = sdxEntreprise($ws);
        $c1 = sdxContact($ws, $e1, 'anna.liste@l.example.test');
        $retire = sdxContact($ws, sdxEntreprise($ws), 'retire@l.example.test');
        $e3 = sdxEntreprise($ws);
        sdxContact($ws, $e3, 'eva.couverte@l.example.test');
        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagneListe($ws, [['contact' => $c1], ['contact' => $retire, 'retire' => true], ['company' => $e1]])));
        expect(sdxIds($l))->toBe([$c1], $mode)->and($l->compteurs['entreprise_deja_couverte'])->toBe(1)->and($l->compteurs['membres'])->toBe(2);

        $corbeille = sdxCampagne($ws, 'liste', sdxListe($ws, [['company' => $e3]], corbeille: true));
        expect(sdxMotif($mode, $ws, $corbeille))->toBe('cible_introuvable', $mode)
            ->and(sdxMotif($mode, $ws, sdxCampagne($ws, 'liste', 'abc')))->toBe('cible_introuvable')
            ->and(sdxMotif($mode, $ws, sdxCampagne($ws, 'liste', '99999999')))->toBe('cible_introuvable');
    }
});

test('X1-L2 10 audience : inactive ou supprimée introuvable ; contact_id NULL suit E7 ; déjà couverte', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $audience = static fn (array $plus = []): int => (int) DB::table('email_audiences')->insertGetId(['workspace_id' => $ws, 'name' => 'Audience ' . sdxN()] + $plus);
        $a = $audience();
        $e1 = sdxEntreprise($ws);
        $c1 = sdxContact($ws, $e1, 'audrey.cible@a.example.test');
        $e2 = sdxEntreprise($ws);
        $c2 = sdxContact($ws, $e2, 'axel.nominatif@a.example.test');
        DB::table('audience_members')->insert([
            ['audience_id' => $a, 'company_id' => $e1, 'contact_id' => $c1, 'workspace_id' => $ws],
            ['audience_id' => $a, 'company_id' => $e1, 'contact_id' => null, 'workspace_id' => $ws], // couverte par la ligne précédente
            ['audience_id' => $a, 'company_id' => $e2, 'contact_id' => null, 'workspace_id' => $ws], // E7
        ]);
        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagne($ws, 'audience', $a)));
        expect(sdxIds($l))->toBe([$c1, $c2], $mode)->and($l->compteurs['entreprise_deja_couverte'])->toBe(1)->and($l->compteurs['membres'])->toBe(3);

        $inactive = $audience(['is_active' => false]);
        $supprimee = $audience(['deleted_at' => now()]);
        expect(sdxMotif($mode, $ws, sdxCampagne($ws, 'audience', $inactive)))->toBe('cible_introuvable', $mode)
            ->and(sdxMotif($mode, $ws, sdxCampagne($ws, 'audience', $supprimee)))->toBe('cible_introuvable');
    }
});

test('X1-L2 10 étiquette : une fiche d\'un autre espace n\'est jamais lue ; un slug d\'un autre espace est introuvable', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $autre = sdxEspace();
        $e1 = sdxEntreprise($ws);
        $c1 = sdxContact($ws, $e1, 'ethan.etiquette@t.example.test');
        sdxEtiqueter($ws, $e1, 'segment-a');
        // fiche d'un AUTRE espace reliée à l'étiquette de CET espace : company_tag n'a pas de contrôle d'espace
        $etrangere = sdxEntreprise($autre);
        sdxContact($autre, $etrangere, 'etrangere@t.example.test');
        sdxEtiqueter($ws, $etrangere, 'segment-a');
        sdxEtiqueter($autre, sdxEntreprise($autre), 'segment-b');

        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagne($ws, 'etiquette', 'segment-a')));
        expect(sdxIds($l))->toBe([$c1], $mode)->and($l->compteurs['membres'])->toBe(1);
        expect(sdxMotif($mode, $ws, sdxCampagne($ws, 'etiquette', 'segment-b')))->toBe('cible_introuvable', $mode);
    }
});

test('X1-L2 10 carnet : une fiche ; fiche d\'un autre espace ou référence invalide introuvable', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $autre = sdxEspace();
        $e = sdxEntreprise($ws);
        $c = sdxContact($ws, $e, 'carole.carnet@c.example.test');
        $l = sdxInvariant(sdxSel($mode, $ws, sdxCampagne($ws, 'carnet', $e)));
        expect(sdxIds($l))->toBe([$c], $mode);
        expect(sdxMotif($mode, $ws, sdxCampagne($ws, 'carnet', sdxEntreprise($autre))))->toBe('cible_introuvable', $mode)
            ->and(sdxMotif($mode, $ws, sdxCampagne($ws, 'carnet', 'abc')))->toBe('cible_introuvable')
            ->and(sdxMotif($mode, $ws, sdxCampagne($ws, 'carnet', str_repeat('9', 18))))->toBe('cible_introuvable');
    }
});

test('X1-L2 11 curseur : 5 membres, taille 2 => 3 lots ; l\'union égale un lot de 500 ; tailles invalides refusées', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $membres = [];
        for ($i = 1; $i <= 5; $i++) {
            $membres[] = ['contact' => sdxContact($ws, sdxEntreprise($ws), "curseur{$i}@k.example.test")];
        }
        $campagne = sdxCampagneListe($ws, $membres);
        $curseur = 0;
        $lots = [];
        $curseurs = [];
        do {
            $lot = sdxInvariant(sdxSel($mode, $ws, $campagne, 'entreprises', $curseur, 2));
            $lots[] = $lot;
            $curseur = $lot->curseurSuivant;
            $curseurs[] = $curseur;
        } while ($curseur !== null);

        expect($lots)->toHaveCount(3, $mode)->and($curseurs[2])->toBeNull()->and($curseurs[0])->toBeLessThan($curseurs[1]);
        $tout = sdxInvariant(sdxSel($mode, $ws, $campagne, 'entreprises', 0, 500));
        $ids = array_merge(...array_map('sdxIds', $lots));
        sort($ids);
        expect($ids)->toBe(sdxIds($tout))->and($tout->curseurSuivant)->toBeNull();
        foreach (['membres', 'retenus'] as $cle) {
            expect(array_sum(array_map(static fn (LotDestinataires $l): int => $l->compteurs[$cle], $lots)))->toBe($tout->compteurs[$cle]);
        }
        foreach ([[0, 0], [0, 501], [-1, 10]] as [$apres, $taille]) {
            expect(sdxMotif($mode, $ws, $campagne, 'entreprises', $apres, $taille))->toBe('taille_invalide', "{$mode} {$apres}/{$taille}");
        }
    }
});

test('X1-L2 12 cloisonnement : la campagne d\'un autre espace est introuvable', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $autre = sdxEspace();
        $campagneAutre = sdxCampagneListe($autre, []);
        expect(sdxMotif($mode, $ws, $campagneAutre))->toBe('campagne_introuvable', $mode)
            ->and(sdxMotif($mode, $ws, (string) Str::uuid()))->toBe('campagne_introuvable');
    }
});

test('X1-L2 13 lecture seule : aucune écriture ; statement_timeout posé avant la première lecture', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $e = sdxEntreprise($ws);
        sdxContact($ws, $e, 'lecture.seule@w.example.test');
        $campagne = sdxCampagneListe($ws, [['company' => $e]]);
        $vues = [];
        DB::listen(function ($q) use (&$vues) {
            $vues[] = $q->sql;
        });
        sdxInvariant(sdxSel($mode, $ws, $campagne));

        $ecritures = array_filter($vues, static fn (string $s): bool => preg_match('/^\s*(insert|update|delete|truncate)\b/i', $s) === 1);
        expect($ecritures)->toBe([], $mode);
        $timeout = array_key_first(array_filter($vues, static fn (string $s): bool => stripos($s, 'statement_timeout') !== false && stripos($s, '3000') !== false));
        $premiere = array_key_first(array_filter($vues, static fn (string $s): bool => stripos($s, 'envoi_campagnes') !== false));
        expect($timeout)->not->toBeNull()->and($premiere)->not->toBeNull()->and($timeout)->toBeLessThan($premiere);
    }
});

test('X1-L2 13b transaction racine : REPEATABLE READ + READ ONLY puis statement_timeout, avant toute lecture', function () {
    // RefreshDatabase enveloppe chaque test : on sort de cette enveloppe pour observer le cas « appelant sans transaction ».
    // Aucune donnée n'est créée ni lue (campagne inconnue) : rien à nettoyer.
    DB::rollBack();
    expect(DB::transactionLevel())->toBe(0);
    $vues = [];
    DB::listen(function ($q) use (&$vues) {
        $vues[] = $q->sql;
    });
    expect(fn () => (new SelectionDestinataires)->lot((string) Str::uuid(), (string) Str::uuid(), 'entreprises'))->toThrow(SelectionRefusee::class);

    $lecture = array_key_first(array_filter($vues, static fn (string $s): bool => stripos($s, 'READ ONLY') !== false));
    $timeout = array_key_first(array_filter($vues, static fn (string $s): bool => stripos($s, 'statement_timeout') !== false));
    $premiere = array_key_first(array_filter($vues, static fn (string $s): bool => stripos($s, 'envoi_campagnes') !== false));
    expect($lecture)->not->toBeNull()->and($timeout)->not->toBeNull()->and($premiere)->not->toBeNull()
        ->and($lecture)->toBeLessThan($timeout)->and($timeout)->toBeLessThan($premiere);
    expect(implode(' ', $vues))->toContain('REPEATABLE READ');
});

test('X1-L2 14 saisie.contenu.cible.contact_ids non vide : cible_non_supportee (vide : accepté)', function () {
    foreach (SDX_MODES as $mode) {
        $ws = sdxEspace();
        $e = sdxEntreprise($ws);
        sdxContact($ws, $e, 'ids.cibles@i.example.test');
        $liste = sdxListe($ws, [['company' => $e]]);
        $avec = sdxCampagne($ws, 'liste', $liste, ['contenu' => ['cible' => ['contact_ids' => [1, 2]]]]);
        $vide = sdxCampagne($ws, 'liste', $liste, ['contenu' => ['cible' => ['contact_ids' => []]]]);
        expect(sdxMotif($mode, $ws, $avec))->toBe('cible_non_supportee', $mode)->and(sdxMotif($mode, $ws, $vide))->toBeNull();
    }
});

test('X1-L2 fabriques : motifs en liste fermée, réessayables seulement pour lecture', function () {
    $r = [SelectionRefusee::populationNonOuverte(), SelectionRefusee::cibleIntrouvable(), SelectionRefusee::cibleNonSupportee(), SelectionRefusee::lectureOppositionImpossible(new RuntimeException('x')),
        SelectionRefusee::lectureTropLongue(), SelectionRefusee::campagneIntrouvable(), SelectionRefusee::tailleInvalide()];
    expect(array_map(static fn (SelectionRefusee $x): string => $x->motif, $r))->toBe(['population_non_ouverte', 'cible_introuvable', 'cible_non_supportee', 'lecture_opposition_impossible', 'lecture_trop_longue', 'campagne_introuvable', 'taille_invalide'])
        ->and(array_map(static fn (SelectionRefusee $x): bool => $x->reessayable, $r))->toBe([false, false, false, true, true, false, false]);
});

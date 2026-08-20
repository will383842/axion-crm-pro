<?php

/**
 * GARDES DE C21-003 (S1) — « aucune contrainte d'unicite ne protege
 * `contacts.email` : 176 218 doublons de personne en production, soit 42,93 %
 * des contacts joignables ».
 *
 * ── CE QUE CES GARDES PROUVENT, ET DANS QUEL ORDRE ─────────────────────────
 *
 *  1. qu'une MESURE existe (`crm:doublons-personnes`) et qu'elle est JUSTE sur
 *     un peuplement dont on connait la reponse a la main ;
 *  2. qu'elle ne compte pas ce qui n'est pas un doublon (temoin negatif), et
 *     qu'elle NE PASSE PAS au vert sur une table absente ni sur zero ligne ;
 *  3. que le chiffre de production est FIGE dans le code, et arithmetiquement
 *     coherent avec lui-meme : on verra donc s'il empire ;
 *  4. LE TEMOIN CAPITAL : que poser la contrainte UNIQUE aujourd'hui
 *     ECHOUERAIT vraiment (SQLSTATE 23505) — et que le MEME index passe des
 *     que les doublons sont retires. C'est la demonstration, en base, que le
 *     correctif n'est pas « poser l'index » mais « fusionner PUIS poser
 *     l'index ». Sans ce temoin, « on ne pose pas la contrainte » ne serait
 *     qu'une opinion.
 *
 * ── POURQUOI TOUTES LES ASSERTIONS DE TEXTE SONT SANS ACCENT ───────────────
 *
 * Les sorties de la commande sont ecrites sans lettre accentuee, et les
 * controles se jouent sur des sous-chaines sans accent : un piege deja paye
 * ailleurs dans ce depot. Les commentaires, eux, sont accentues — ils ne sont
 * compares a rien.
 *
 * ⚠️ `expect()->toContain()` n'est employe NULLE PART sur du texte : Pest est
 * variadique et le message d'echec y deviendrait une valeur a chercher.
 * On emploie `assertStringContainsString`.
 */

use App\Console\Commands\CrmDoublonsPersonnes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Un espace de travail neuf. */
function espaceA35Doublons(string $suffixe): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'a35-dbl-' . $suffixe . '-' . Str::random(6),
        'name' => 'A35 doublons ' . $suffixe,
    ]);

    return $id;
}

/**
 * Une entreprise porteuse. Le SIREN n'est pas decoratif : `companies` porte
 * `CHECK (siren IS NOT NULL OR foreign_id IS NOT NULL)` — mesure
 * `pg_get_constraintdef('companies_identity_anchor_check')`. Un semis sans lui
 * echoue en 23514, et c'est bien ainsi : une entreprise sans ancre d'identite
 * n'existe pas dans ce modele.
 */
function entrepriseA35Doublons(string $espace): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => 'A35 Entreprise ' . Str::random(6),
    ]);
}

/**
 * Une fiche personne. Le nom est TOUJOURS distinct : `contacts` porte
 * `UNIQUE (workspace_id, normalized_hash)` sur `normalize_name(prenom_nom) +
 * company_id`, et c'est precisement ce qui laisse passer les doublons d'adresse
 * — deux noms differents, une meme adresse, aucune contrainte heurtee.
 */
function ficheA35Doublons(string $espace, int $entreprise, string $nom, ?string $email, bool $supprimee = false): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $espace,
        'company_id' => $entreprise,
        'last_name' => $nom,
        'email' => $email,
        'deleted_at' => $supprimee ? now() : null,
    ]);
}

/**
 * Le peuplement de reference, dont la reponse est calculee A LA MAIN :
 *
 *   a@x.fr x3 (dont une en MAJUSCULES) · b@x.fr x2 · c@x.fr x1 · une sans adresse
 *
 *   lignes 7 · avec_email 6 · distincts 3 · groupes 2 · fiches 5 · surnumeraires 3
 *   taux = 3 / 6 = 50,00 %
 *
 * @return array{espace: string, entreprise: int, ids: array<string, int>}
 */
function peuplementA35Doublons(): array
{
    $espace = espaceA35Doublons('principal');
    $entreprise = entrepriseA35Doublons($espace);

    $ids = [];
    $ids['a1'] = ficheA35Doublons($espace, $entreprise, 'Nom01', 'a@x.fr');
    $ids['a2'] = ficheA35Doublons($espace, $entreprise, 'Nom02', 'A@X.FR');
    $ids['a3'] = ficheA35Doublons($espace, $entreprise, 'Nom03', 'a@x.fr');
    $ids['b1'] = ficheA35Doublons($espace, $entreprise, 'Nom04', 'b@x.fr');
    $ids['b2'] = ficheA35Doublons($espace, $entreprise, 'Nom05', 'b@x.fr');
    $ids['c1'] = ficheA35Doublons($espace, $entreprise, 'Nom06', 'c@x.fr');
    $ids['sans'] = ficheA35Doublons($espace, $entreprise, 'Nom07', null);

    return ['espace' => $espace, 'entreprise' => $entreprise, 'ids' => $ids];
}

/**
 * Joue la commande en JSON et rend la mesure decodee.
 *
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function mesureA35Doublons(array $options = []): array
{
    $code = Artisan::call('crm:doublons-personnes', ['--json' => true] + $options);
    $sortie = Artisan::output();

    $mesure = json_decode($sortie, true);

    // Une sortie illisible est un ECHEC, pas un zero : sans ce garde-fou, un
    // json_decode rendant null ferait passer toutes les assertions suivantes
    // sur des `null` silencieux.
    if (! is_array($mesure)) {
        throw new RuntimeException("La commande n'a pas rendu de JSON exploitable (code {$code}) : " . $sortie);
    }

    $mesure['__code'] = $code;

    return $mesure;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. TEMOIN POSITIF — la mesure est juste sur un peuplement connu
// ─────────────────────────────────────────────────────────────────────────────

test('elle compte exactement les doublons semes, casse repliee', function () {
    peuplementA35Doublons();

    // ANTI-VACUITE : si le semis avait echoue en silence, tout ce qui suit
    // passerait au vert sur une base vide. On constate d'abord qu'il y a bien
    // quelque chose a compter.
    expect(DB::table('contacts')->count())->toBe(7);

    $c = mesureA35Doublons()['tables']['contacts'];

    expect($c['lignes'])->toBe(7)
        ->and($c['avec_email'])->toBe(6)
        ->and($c['distincts'])->toBe(3)          // a@x.fr, b@x.fr, c@x.fr — « A@X.FR » est repliee par citext
        ->and($c['groupes'])->toBe(2)
        ->and($c['fiches_impliquees'])->toBe(5)
        ->and($c['surnumeraires'])->toBe(3)
        // Cast explicite : JSON ne distingue pas 50.0 de 50, et `json_decode`
        // rend donc un `int` des que le taux tombe rond. Sans ce cast, la garde
        // rougirait sur « 50 n'est pas identique a 50.0 » — un faux rouge qui
        // apprendrait a ne plus lire les echecs de ce fichier.
        ->and((float) $c['taux_pourcent'])->toBe(50.0);

    // Les DEUX chemins de calcul tombent d'accord : c'est ce que la commande
    // verifie elle-meme, et qu'elle signalerait en `incoherences`.
    expect($c['fiches_impliquees'] - $c['groupes'])->toBe($c['surnumeraires']);
});

test('la casse seule ne fait pas echapper un doublon', function () {
    $espace = espaceA35Doublons('casse');
    $entreprise = entrepriseA35Doublons($espace);
    ficheA35Doublons($espace, $entreprise, 'Casse01', 'Jean.Dupont@Exemple.Fr');
    ficheA35Doublons($espace, $entreprise, 'Casse02', 'jean.dupont@exemple.fr');

    $c = mesureA35Doublons()['tables']['contacts'];

    // Si la mesure comparait des `text` bruts au lieu du `citext` de la
    // colonne, elle verrait deux adresses distinctes et rendrait 0.
    expect($c['distincts'])->toBe(1)
        ->and($c['surnumeraires'])->toBe(1);
});

test('la fiche en suppression douce est comptee a part, car l index UNIQUE sera partiel', function () {
    $espace = espaceA35Doublons('supprimees');
    $entreprise = entrepriseA35Doublons($espace);
    ficheA35Doublons($espace, $entreprise, 'Sup01', 'd@x.fr');
    ficheA35Doublons($espace, $entreprise, 'Sup02', 'd@x.fr');
    ficheA35Doublons($espace, $entreprise, 'Sup03', 'd@x.fr', supprimee: true);

    $c = mesureA35Doublons()['tables']['contacts'];

    // 3 fiches, 1 adresse → 2 surnumeraires au total ; mais l'index vise porte
    // `WHERE deleted_at IS NULL`, et de ce point de vue il n'y en a qu'UN.
    // Confondre les deux, c'est annoncer un chantier plus gros qu'il n'est.
    expect($c['surnumeraires'])->toBe(2)
        ->and($c['surnumeraires_actifs'])->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. TEMOINS NEGATIFS — elle ne trouve pas ce qui n'est pas la
// ─────────────────────────────────────────────────────────────────────────────

test('elle ne compte aucun doublon quand toutes les adresses sont distinctes', function () {
    $espace = espaceA35Doublons('propre');
    $entreprise = entrepriseA35Doublons($espace);
    foreach (range(1, 5) as $i) {
        ficheA35Doublons($espace, $entreprise, 'Propre0' . $i, "propre{$i}@x.fr");
    }

    expect(DB::table('contacts')->count())->toBe(5);

    $c = mesureA35Doublons()['tables']['contacts'];

    expect($c['avec_email'])->toBe(5)
        ->and($c['distincts'])->toBe(5)
        ->and($c['groupes'])->toBe(0)
        ->and($c['surnumeraires'])->toBe(0)
        ->and((float) $c['taux_pourcent'])->toBe(0.0);
});

test('une base sans aucune fiche joignable ne rend PAS le verdict AMELIORE', function () {
    // LE PIEGE QUE CETTE COMMANDE EXISTE POUR DEBUSQUER, APPLIQUE A ELLE-MEME.
    // Sur une base fraichement migree, 0 surnumeraire est inferieur a 176 218 :
    // une comparaison naive annonce « AMELIORE » et fait croire le probleme
    // resolu par un tableau vide. Constate sur `axion_crm_test_lot7` avant
    // correctif, la commande le disait vraiment.
    expect(DB::table('contacts')->count())->toBe(0);

    $c = mesureA35Doublons()['tables']['contacts'];

    expect($c['verdict'])->toBe('MESURE VIDE')
        ->and($c['verdict'])->not->toBe('AMELIORE');

    // TEMOIN : des qu'UNE SEULE fiche joignable existe, le verdict redevient
    // une vraie comparaison. Sans ce contre-controle, « MESURE VIDE » pourrait
    // etre rendu toujours, et la garde ne verrait rien.
    $espace = espaceA35Doublons('temoin-vide');
    ficheA35Doublons($espace, entrepriseA35Doublons($espace), 'Vide01', 'seule@x.fr');

    expect(mesureA35Doublons()['tables']['contacts']['verdict'])->toBe('AMELIORE');
});

test('la meme adresse dans DEUX espaces n est pas un doublon', function () {
    // C'est le controle qui distingue une vraie mesure d'un `GROUP BY email`
    // naif. L'audit a mesure `GROUP BY workspace_id, email` : deux clients
    // distincts qui connaissent la meme personne ne sont pas un doublon, et
    // les fusionner serait une fuite entre espaces.
    $a = espaceA35Doublons('espace-a');
    $b = espaceA35Doublons('espace-b');
    ficheA35Doublons($a, entrepriseA35Doublons($a), 'Cloison01', 'partagee@x.fr');
    ficheA35Doublons($b, entrepriseA35Doublons($b), 'Cloison02', 'partagee@x.fr');

    $c = mesureA35Doublons()['tables']['contacts'];

    expect($c['avec_email'])->toBe(2)
        ->and($c['distincts'])->toBe(2)   // 1 par espace
        ->and($c['groupes'])->toBe(0)
        ->and($c['surnumeraires'])->toBe(0);
});

test('une table absente FAIT ECHOUER la mesure, elle ne la rend pas nulle', function () {
    // Un test ignore est un vert deguise, et « 0 doublon » sur une table qui
    // n'existe pas est la meme chose. On retire vraiment la table (la
    // transaction de RefreshDatabase la remettra) et on constate le refus.
    DB::statement('DROP TABLE journalists CASCADE');

    $code = Artisan::call('crm:doublons-personnes', ['--tables' => 'journalists']);
    $sortie = Artisan::output();

    expect($code)->toBe(1);
    $this->assertStringContainsString("n'existe pas", $sortie);
    $this->assertStringContainsString('ne vaut pas', $sortie);
});

test('une table hors liste blanche est refusee, le nom de table n est jamais une saisie libre', function () {
    $code = Artisan::call('crm:doublons-personnes', ['--tables' => 'users; DROP TABLE contacts']);
    $sortie = Artisan::output();

    expect($code)->toBe(1);
    $this->assertStringContainsString('hors liste blanche', $sortie);
    expect(DB::table('contacts')->count())->toBe(0); // la table est toujours la
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. LE CHIFFRE FIGE
// ─────────────────────────────────────────────────────────────────────────────

test('le chiffre de production est fige, non nul, et coherent avec lui-meme', function () {
    $r = CrmDoublonsPersonnes::REFERENCE_PRODUCTION;

    // Non nul : personne ne fait taire le repere en le remettant a zero.
    expect($r['surnumeraires'])->toBe(176218)
        ->and($r['avec_email'])->toBe(410481)
        ->and($r['constat'])->toBe('C21-003');

    // Les trois chemins de la mesure d'audit doivent se rejoindre. Modifier un
    // seul de ces nombres sans les autres fait rougir ici — c'est le seul
    // moyen qu'une mise a jour distraite ne fabrique pas un repere faux.
    expect($r['avec_email'] - $r['distincts'])->toBe($r['surnumeraires']);
    expect($r['fiches_impliquees'] - $r['groupes'])->toBe($r['surnumeraires']);
    expect(round($r['surnumeraires'] * 100 / $r['avec_email'], 2))->toBe($r['taux_pourcent']);
    expect($r['avec_email'])->toBeLessThan($r['lignes']);
});

test('la comparaison au repere sait dire EMPIRE, STABLE et AMELIORE', function () {
    // Methode pure, exercee sur des valeurs choisies : aucun peuplement de test
    // ne permettrait de franchir 176 218, et une garde qui ne peut pas produire
    // le cas « empire » ne prouve pas que la commande saurait le dire.
    expect(CrmDoublonsPersonnes::verdict(176219, 176218))->toBe('EMPIRE')
        ->and(CrmDoublonsPersonnes::verdict(176218, 176218))->toBe('STABLE')
        ->and(CrmDoublonsPersonnes::verdict(0, 176218))->toBe('AMELIORE')
        ->and(CrmDoublonsPersonnes::verdict(5, 0))->toBe('SANS REFERENCE');
});

test('le plafond fait echouer une tache planifiee quand la mesure le depasse', function () {
    peuplementA35Doublons(); // 3 surnumeraires

    expect(Artisan::call('crm:doublons-personnes', ['--plafond' => '3']))->toBe(0);

    $code = Artisan::call('crm:doublons-personnes', ['--plafond' => '2']);
    $this->assertStringContainsString('PLAFOND DEPASSE', Artisan::output());
    expect($code)->toBe(1);
});

test('la mesure ne rend jamais une adresse en entier', function () {
    $espace = espaceA35Doublons('masquage');
    $entreprise = entrepriseA35Doublons($espace);
    ficheA35Doublons($espace, $entreprise, 'Masq01', 'jean.dupont@mairie.fr');
    ficheA35Doublons($espace, $entreprise, 'Masq02', 'jean.dupont@mairie.fr');

    $mesure = mesureA35Doublons();
    $json = (string) json_encode($mesure);

    // La partie locale ne doit apparaitre nulle part ; le domaine, si — c'est
    // lui qui permet de reconnaitre une boite generique.
    $this->assertStringNotContainsString('jean.dupont', $json);
    $this->assertStringContainsString('mairie.fr', $json);

    expect(CrmDoublonsPersonnes::masquer('jean.dupont@mairie.fr'))->toBe('j...(11)@mairie.fr');
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. LE TEMOIN CAPITAL — l'unicite echouerait, et le meme index passe apres
// ─────────────────────────────────────────────────────────────────────────────

test('poser l unicite MAINTENANT echouerait en 23505, et le MEME index passe une fois les doublons retires', function () {
    $p = peuplementA35Doublons();

    // ── Temps 1 : la contrainte visee, telle qu'elle est ecrite dans l'en-tete
    // de la commande. `CONCURRENTLY` est retire ici, et uniquement ici :
    // PostgreSQL l'interdit dans une transaction, et la suite tourne dans celle
    // de RefreshDatabase. La clause partielle et les colonnes sont identiques.
    $poser = static fn () => DB::transaction(static function (): void {
        DB::statement(
            'CREATE UNIQUE INDEX temoin_a35_unicite_email ON contacts (workspace_id, email) '
            . 'WHERE email IS NOT NULL AND deleted_at IS NULL',
        );
    });

    // Le `DB::transaction` imbrique pose un SAVEPOINT : sans lui, l'echec du
    // CREATE laisserait la transaction de la suite en etat abandonne (25P02) et
    // tout ce qui suit tomberait pour une mauvaise raison.
    $echec = null;
    try {
        $poser();
    } catch (QueryException $e) {
        $echec = $e;
    }

    // `fail()` plutot que `expect()->not->toBeNull()` : c'est le seul echec qui
    // rende la suite lisible ici. Si l'index PASSAIT alors qu'on vient de semer
    // trois doublons, tout le raisonnement de ce lot s'ecroule — et le dire en
    // une phrase vaut mieux qu'une erreur « method on null » trois lignes plus
    // bas. (C'est aussi ce qui rend le controle typable : apres `fail()`, la
    // variable n'est plus nullable.)
    if ($echec === null) {
        $this->fail('L index UNIQUE a ete pose SANS ERREUR sur une table qui porte 3 doublons d adresse. '
            . 'Soit le semis n a rien ecrit, soit la clause de l index ne correspond plus a celle '
            . 'annoncee par crm:doublons-personnes. Dans les deux cas ce temoin ne prouve plus rien.');
    }

    expect($echec->getCode())->toBe('23505');
    $this->assertStringContainsString('temoin_a35_unicite_email', $echec->getMessage());

    // ── Temps 2 : on retire les surnumeraires (ici a la main, en test ; en
    // production c'est une campagne de FUSION, pas une suppression — les 10
    // tables qui referencent `contacts.id` en dependent).
    DB::table('contacts')->whereIn('id', [$p['ids']['a2'], $p['ids']['a3'], $p['ids']['b2']])->delete();

    expect(mesureA35Doublons()['tables']['contacts']['surnumeraires'])->toBe(0);

    // ── Temps 3 : le MEME index passe. C'est le temoin negatif du temoin :
    // le 23505 ci-dessus n'etait pas une erreur de syntaxe ni un nom deja pris,
    // c'etaient bien les donnees.
    $poser();

    $existe = DB::selectOne(
        "SELECT 1 AS ok FROM pg_class WHERE relname = 'temoin_a35_unicite_email' AND relkind = 'i'",
    );
    expect($existe)->not->toBeNull();

    // On le retire : la transaction de RefreshDatabase le ferait, mais un index
    // unique laisse en place changerait le comportement de tout test qui
    // partagerait la connexion.
    DB::statement('DROP INDEX temoin_a35_unicite_email');
});

test('la meme absence d unicite frappe les tables de personne jumelles', function () {
    // L'audit ne nomme que `contacts`. La mesure des index UNIQUE portant sur
    // une colonne `email` en montre quatre logees a la meme enseigne. On le
    // CONSTATE plutot que de le corriger : poser l'unicite sur `candidates`
    // (0 ligne en production) serait gratuit, mais c'est un autre lot.
    $sansUnicite = DB::select(<<<'SQL'
        SELECT c.table_name
        FROM   information_schema.columns c
        WHERE  c.table_schema = 'public'
          AND  c.column_name = 'email'
          AND  c.table_name = ANY (?)
          AND  NOT EXISTS (
                SELECT 1
                FROM   pg_index i
                JOIN   pg_class t ON t.oid = i.indrelid
                JOIN   pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (i.indkey::int[])
                WHERE  t.relname = c.table_name AND i.indisunique AND a.attname = 'email'
          )
        ORDER  BY 1
    SQL, ['{' . implode(',', CrmDoublonsPersonnes::TABLES_PERSONNE) . '}']);

    $noms = array_map(static fn ($l): string => $l->table_name, $sansUnicite);

    // TEMOIN : la meme requete, tournee vers une table QUI PORTE l'unicite,
    // ne la remonte pas. Sans ce contre-controle, une requete cassee
    // remonterait « tout », et le constat serait vrai par accident.
    $temoin = DB::select(<<<'SQL'
        SELECT c.table_name
        FROM   information_schema.columns c
        WHERE  c.table_schema = 'public'
          AND  c.column_name = 'email'
          AND  c.table_name = 'users'
          AND  NOT EXISTS (
                SELECT 1
                FROM   pg_index i
                JOIN   pg_class t ON t.oid = i.indrelid
                JOIN   pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (i.indkey::int[])
                WHERE  t.relname = c.table_name AND i.indisunique AND a.attname = 'email'
          )
    SQL);

    expect($temoin)->toBe([]);           // `users.email` est bien unique — la requete voit les contraintes
    $this->assertContains('contacts', $noms);
    $this->assertContains('candidates', $noms);
    $this->assertContains('journalists', $noms);
    $this->assertContains('health_practitioners', $noms);
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. LA COMMANDE MESURE AUSSI LES JUMELLES QUAND ON LE DEMANDE
// ─────────────────────────────────────────────────────────────────────────────

test('elle mesure les quatre tables de personne, et ne fige un repere que sur contacts', function () {
    $espace = espaceA35Doublons('toutes');
    $entreprise = entrepriseA35Doublons($espace);
    ficheA35Doublons($espace, $entreprise, 'Tout01', 'e@x.fr');
    ficheA35Doublons($espace, $entreprise, 'Tout02', 'e@x.fr');

    // Le vivier ne vit pas dans n'importe quel espace : le trigger
    // `candidates_enforce_vivier_workspace()` refuse une candidature dont
    // l'espace n'a pas un slug en « vivier* » (etancheite des deux univers,
    // B15-001). Le semis doit donc respecter le cloisonnement, pas le
    // contourner — c'est aussi ce qui rend cette mesure realiste.
    $vivier = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $vivier,
        'slug' => 'vivier-a35-' . Str::random(6),
        'name' => 'A35 vivier',
    ]);
    DB::table('candidates')->insert([
        ['workspace_id' => $vivier, 'last_name' => 'Cand01', 'relation_type' => 'candidat_autre', 'email' => 'f@x.fr'],
        ['workspace_id' => $vivier, 'last_name' => 'Cand02', 'relation_type' => 'candidat_autre', 'email' => 'f@x.fr'],
    ]);

    $mesure = mesureA35Doublons(['--tables' => 'toutes']);

    expect(array_keys($mesure['tables']))->toBe(CrmDoublonsPersonnes::TABLES_PERSONNE);
    expect($mesure['tables']['contacts']['surnumeraires'])->toBe(1)
        ->and($mesure['tables']['candidates']['surnumeraires'])->toBe(1)
        ->and($mesure['tables']['journalists']['surnumeraires'])->toBe(0);

    // Le repere n'est figé que la ou une mesure de production existe.
    expect($mesure['tables']['contacts']['verdict'])->toBe('AMELIORE')
        ->and($mesure['tables']['candidates']['verdict'])->toBe('SANS REFERENCE')
        ->and($mesure['tables']['candidates']['reference'])->toBeNull();

    expect($mesure['incoherences'])->toBe([]);
});

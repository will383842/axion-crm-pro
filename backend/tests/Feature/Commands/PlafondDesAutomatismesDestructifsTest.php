<?php

/**
 * GARDE : UN AUTOMATISME QUI DÉTRUIT DOIT AVOIR UN PLAFOND — constat `B15-008`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI MANQUAIT, ET POURQUOI LE TRAIT EXISTANT NE POUVAIT PAS LE COMBLER
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `RefuseUneSuppressionMassive` protège les commandes qu'un humain lance : essai
 * à blanc, plafond, confirmation. Sa dernière barrière exige `--force` dès que
 * l'entrée n'est pas interactive.
 *
 * `media:clean-emails` n'est pas lancée par un humain. Elle tourne SEULE, tous
 * les jours à 05:05 (`routes/console.php:275`), et met `media.email` à NULL sur
 * les adresses qu'elle juge parasites ou « sur-partagées ». Lui poser le trait
 * tel quel l'aurait rendue MUETTE — elle aurait refusé chaque nuit, en silence.
 * Et lui ajouter `--force` dans le planificateur aurait retiré la garde tout en
 * ayant l'air de la poser.
 *
 * D'où `ecritureAutoriseeSansOperateur()` : le PLAFOND, sans la confirmation.
 * C'est la seule des trois barrières qui protège d'un accident de masse quand
 * personne ne regarde.
 *
 * ── Ce que ça arrête concrètement ──────────────────────────────────────────
 *
 * Le détecteur de cette commande classe « sur-partagé » tout email d'un domaine
 * GRAND PUBLIC présent sur plus de `--threshold` médias. Un seuil trop bas, ou
 * un domaine professionnel mal classé, et c'est le registre presse entier qui
 * perd ses adresses en une nuit. Aucun test ne disait ce que cette commande
 * supprime — c'est le reproche exact que `RefuseUneSuppressionMassive` porte en
 * tête de fichier à propos de `prospection:purge-non-commercial`.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Un média joignable, avec l'adresse demandée. */
function b15008Media(string $espace, string $nom, ?string $email): int
{
    return (int) DB::table('media')->insertGetId([
        'workspace_id' => $espace,
        'name' => $nom,
        'media_type' => 'presse_quotidien',
        'media_family' => 'presse_ecrite',
        'source' => 'test',
        'enrich_status' => 'done',
        'email' => $email,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Sème N médias partageant la même adresse, en un seul INSERT.
 *
 * ⚠️ L'ECHELLE N'EST PAS DECORATIVE. Le plafond ne s'applique qu'au-delà de
 * `$plancherLignes` (1000) : en deçà, une proportion élevée ne signale AUCUN
 * accident de masse — purger 2 candidats sur 4, c'est 50 %, et c'est le ménage
 * attendu. Un témoin à douze lignes ne mesurerait donc plus rien depuis que ce
 * plancher existe. Il faut passer la barre pour que la garde ait un objet.
 */
function b15008Semer(string $espace, string $prefixe, ?string $email, int $combien): void
{
    $lot = [];
    for ($i = 1; $i <= $combien; $i++) {
        $lot[] = [
            'workspace_id' => $espace,
            'name' => $prefixe . ' ' . $i,
            'media_type' => 'presse_quotidien',
            'media_family' => 'presse_ecrite',
            'source' => 'test',
            'enrich_status' => 'done',
            'email' => $email === null ? "unique{$i}@journal{$i}.test" : $email,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (count($lot) === 500) {
            DB::table('media')->insert($lot);
            $lot = [];
        }
    }
    if ($lot !== []) {
        DB::table('media')->insert($lot);
    }
}

function b15008Espace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'b15008-' . Str::random(6),
        'name' => 'B15-008',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('B15-008 — TEMOIN : sans plafond, le detecteur viserait bien la quasi-totalite du registre', function () {
    $espace = b15008Espace();

    // 1200 médias partagent la MÊME adresse grand public : c'est ce que le
    // détecteur appelle « sur-partagé » au-delà de --threshold=10, et c'est
    // au-dessus du plancher de 1000 — donc dans le domaine du plafond.
    b15008Semer($espace, 'Partage', 'jean.dupont@gmail.com', 1200);
    // Cent médias portent une adresse professionnelle légitime.
    b15008Semer($espace, 'Presse', null, 100);

    $vises = DB::table('media')->where('email', 'jean.dupont@gmail.com')->count();
    $totalAvecEmail = DB::table('media')->whereNotNull('email')->count();

    // 1200 sur 1300 : 92 %. Très au-delà du plafond de 30 %, et au-dessus du
    // plancher de 1000 : les deux conditions du refus sont réunies.
    expect($vises)->toBe(1200);
    expect($totalAvecEmail)->toBe(1300);
    expect($vises / $totalAvecEmail)->toBeGreaterThan(0.30);
});

test('B15-008 — le plafond ARRETE une purge de masse, et l automatisme n ecrit RIEN', function () {
    $espace = b15008Espace();

    b15008Semer($espace, 'Partage', 'jean.dupont@gmail.com', 1200);
    b15008Semer($espace, 'Presse', null, 100);

    // Exactement la ligne du planificateur : ni --force, ni --dry-run, aucun
    // opérateur devant l'écran.
    $code = $this->artisan('media:clean-emails', ['--threshold' => 10])
        ->expectsOutputToContain('REFUS')
        ->run();

    expect($code)->toBe(1, 'La commande doit SORTIR EN ECHEC, pas reussir a moitie.');

    // 🔑 L'ASSERTION QUI COMPTE : rien n'a ete detruit.
    expect((int) DB::table('media')->whereNotNull('email')->count())->toBe(
        1300,
        'Le plafond a laisse passer la purge : les adresses ont ete nullifiees.',
    );
});

test('B15-008 — TEMOIN NEGATIF : sous le plafond, l automatisme fait bien son travail', function () {
    $espace = b15008Espace();

    // 1100 médias sur-partagent une adresse grand public — AU-DESSUS du
    // plancher, donc le plafond a bien son mot à dire...
    b15008Semer($espace, 'Partage', 'demo@gmail.com', 1100);
    // ... mais le registre en compte 9000 autres, parfaitement légitimes.
    b15008Semer($espace, 'Presse', null, 9000);

    // 1100 sur 10 100 : 10,9 %, sous le plafond de 30 %.
    $code = $this->artisan('media:clean-emails', ['--threshold' => 10])->run();

    expect($code)->toBe(0, 'Sous le plafond, la commande doit agir normalement.');

    // Les 1100 sur-partagees sont nullifiees...
    expect((int) DB::table('media')->where('email', 'demo@gmail.com')->count())->toBe(0);
    // ... et les 9000 legitimes sont INTACTES.
    expect((int) DB::table('media')->whereNotNull('email')->count())->toBe(9000);
});

test('B15-008 — RECENSEMENT : toute commande destructive porte une garde, ou est nommee ici', function () {
    // ⚠️ `scandir` recursif et non `RecursiveDirectoryIterator` : sur le montage
    // Docker de Windows, ce dernier ne rend que 14 des 56 fichiers de
    // `app/Console/Commands` (mesure du 2026-08-21). Une enumeration batie
    // dessus se declarerait complete sur un quart du repertoire.
    $dossier = app_path('Console/Commands');
    $fichiers = [];
    foreach (scandir($dossier) ?: [] as $entree) {
        if (str_ends_with($entree, '.php')) {
            $fichiers[] = $dossier . DIRECTORY_SEPARATOR . $entree;
        }
    }
    sort($fichiers);

    // TEMOIN DE COUVERTURE : un balayage qui ne voit rien certifierait le vide.
    expect(count($fichiers))->toBeGreaterThanOrEqual(
        50,
        'Seulement ' . count($fichiers) . ' commandes vues, contre 56 relevees le 2026-08-21 : '
        . 'le balayage ne voit pas ce qu il croit voir.',
    );

    /**
     * Commandes destructives SANS garde.
     *
     * 🔑 CETTE LISTE EST VIDE DEPUIS LE 2026-08-21, ET C'EST LA FERMETURE DE
     *    `B15-008`. Les six commandes qui detruisent portent toutes une
     *    barriere : `suppressionAutorisee()` pour celles qu'un humain lance,
     *    `ecritureAutoriseeSansOperateur()` pour les quatre qui tournent
     *    SEULES sous le planificateur.
     *
     * Une liste vide ne prouve rien sans le temoin de couverture ci-dessus :
     * c'est lui qui garantit que le balayage a bien vu les 56 commandes.
     *
     * Si une commande neuve detruit sans garde, POSEZ-LA. Ce tableau n'est pas
     * une derogation : c'est le registre de ce qui reste a faire, et il doit
     * rester vide.
     */
    $connuesSansGarde = [];

    $sansGarde = [];
    foreach ($fichiers as $chemin) {
        $source = (string) file_get_contents($chemin);

        // Detruit-elle ? (suppression de lignes, ou mise a NULL d'une colonne)
        $detruit = preg_match('/->\s*(delete|truncate|forceDelete)\s*\(/', $source) === 1
            || preg_match("/'email'\s*=>\s*null/", $source) === 1;

        if (! $detruit) {
            continue;
        }

        if (! str_contains($source, 'RefuseUneSuppressionMassive')) {
            $sansGarde[] = basename($chemin);
        }
    }

    sort($sansGarde);
    sort($connuesSansGarde);

    expect($sansGarde)->toBe(
        $connuesSansGarde,
        "L inventaire des commandes destructives sans garde a change.\n"
        . 'Vues sans garde : ' . implode(', ', $sansGarde) . "\n"
        . 'Attendues       : ' . implode(', ', $connuesSansGarde) . "\n"
        . 'Si une commande neuve detruit sans garde, posez-la. Si une des connues a '
        . 'recu la sienne, retirez-la de cette liste dans le MEME commit.',
    );
});

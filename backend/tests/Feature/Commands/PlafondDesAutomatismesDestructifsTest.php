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

    // Douze médias partagent la MÊME adresse grand public : c'est exactement ce
    // que le détecteur appelle « sur-partagé » au-delà de --threshold=10.
    for ($i = 1; $i <= 12; $i++) {
        b15008Media($espace, "Media {$i}", 'jean.dupont@gmail.com');
    }
    // Un seul média porte une adresse professionnelle légitime.
    b15008Media($espace, 'Le Vrai Journal', 'redaction@levraijournal.fr');

    $vises = DB::table('media')->where('email', 'jean.dupont@gmail.com')->count();
    $totalAvecEmail = DB::table('media')->whereNotNull('email')->count();

    // 12 sur 13 : 92 %. Très au-delà du plafond de 30 %.
    expect($vises)->toBe(12);
    expect($totalAvecEmail)->toBe(13);
    expect($vises / $totalAvecEmail)->toBeGreaterThan(0.30);
});

test('B15-008 — le plafond ARRETE une purge de masse, et l automatisme n ecrit RIEN', function () {
    $espace = b15008Espace();

    for ($i = 1; $i <= 12; $i++) {
        b15008Media($espace, "Media {$i}", 'jean.dupont@gmail.com');
    }
    b15008Media($espace, 'Le Vrai Journal', 'redaction@levraijournal.fr');

    // Exactement la ligne du planificateur : ni --force, ni --dry-run, aucun
    // opérateur devant l'écran.
    $code = $this->artisan('media:clean-emails', ['--threshold' => 10])
        ->expectsOutputToContain('REFUS')
        ->run();

    expect($code)->toBe(1, 'La commande doit SORTIR EN ECHEC, pas reussir a moitie.');

    // 🔑 L'ASSERTION QUI COMPTE : rien n'a ete detruit.
    expect((int) DB::table('media')->whereNotNull('email')->count())->toBe(
        13,
        'Le plafond a laisse passer la purge : les adresses ont ete nullifiees.',
    );
});

test('B15-008 — TEMOIN NEGATIF : sous le plafond, l automatisme fait bien son travail', function () {
    $espace = b15008Espace();

    // Douze médias sur-partagent une adresse grand public...
    for ($i = 1; $i <= 12; $i++) {
        b15008Media($espace, "Partage {$i}", 'demo@gmail.com');
    }
    // ... mais le registre en compte cent autres, parfaitement legitimes.
    for ($i = 1; $i <= 100; $i++) {
        b15008Media($espace, "Presse {$i}", "redaction{$i}@journal{$i}.fr");
    }

    // 12 sur 112 : 10,7 %, sous le plafond de 30 %.
    $code = $this->artisan('media:clean-emails', ['--threshold' => 10])->run();

    expect($code)->toBe(0, 'Sous le plafond, la commande doit agir normalement.');

    // Les douze sur-partagees sont nullifiees...
    expect((int) DB::table('media')->where('email', 'demo@gmail.com')->count())->toBe(0);
    // ... et les cent legitimes sont INTACTES.
    expect((int) DB::table('media')->whereNotNull('email')->count())->toBe(100);
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
     * Commandes destructives SANS garde, avec la raison — chacune est un reste
     * ouvert de `B15-008`, pas un oubli. Retirer un nom d'ici sans poser la
     * garde fera rougir ce test.
     */
    $connuesSansGarde = [
        'PruneScraperRuns.php',
        'RgpdPurgeBusinessProspects.php',
        'RgpdPurgeVivier.php',
    ];

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

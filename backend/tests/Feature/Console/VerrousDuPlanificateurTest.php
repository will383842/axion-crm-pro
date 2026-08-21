<?php

/**
 * GARDE — audit 360, B17-002 (S1) : « le verrou `withoutOverlapping()` a un TTL
 * de 24 h et le deploiement tue le planificateur en pleine tache ».
 *
 * LA MECANIQUE, MESUREE DANS LE CADRE (Laravel 12.62, vendor du conteneur) :
 *
 *   ManagesAttributes.php:145   public function withoutOverlapping($expiresAt = 1440)
 *   ManagesAttributes.php:70    public $expiresAt = 1440;
 *   CacheEventMutex.php:44      ->lock($event->mutexName(), $event->expiresAt * 60)
 *
 * `withoutOverlapping()` ecrit SANS argument pose donc un verrou de
 * 1 440 minutes = 86 400 s = 24 heures. Le verrou n'est retire que par
 * `Event::removeMutex()`, appele depuis `finish()` — c'est-a-dire seulement si
 * le processus VA AU BOUT.
 *
 * CE QUI LE REND VIVANT ICI, ET NON THEORIQUE :
 *
 *   - `docker-compose.yml:245` — le conteneur `scheduler` lance
 *     `php artisan schedule:work` ; aucun `stop_grace_period` n'est declare
 *     dans les quatre fichiers Compose du depot, donc Docker applique son
 *     defaut : SIGTERM, puis SIGKILL au bout de 10 s.
 *   - `.github/workflows/deploy-direct-ssh.yml:200` —
 *     `docker compose up -d --build --force-recreate --no-deps api app horizon scheduler`
 *     recree le planificateur a CHAQUE deploiement. Une tache en cours depuis
 *     plus de 10 s est donc tuee net : `finish()` n'est jamais atteint.
 *   - `backend/config/cache.php:4` — le magasin par defaut est `redis`, et
 *     `redis` n'est PAS dans la liste recreee (`--no-deps`, services nommes).
 *     Le verrou SURVIT donc au deploiement qui a tue son porteur.
 *
 * Resultat : une tache tuee par un redeploiement ne repart pas avant 24 h. Sur
 * `retention:prune-scraper-runs` (quotidienne) c'est une purge sautee ; sur les
 * quotidiennes de 05:00-05:40 c'est une journee entiere sans rattrapage.
 *
 * CE QUE LA GARDE EXIGE :
 *   1. tout verrou planifie porte un TTL EXPLICITE et borne (plafond 360 min) ;
 *   2. ce TTL n'est pas si court qu'il ne verrouille plus rien (plancher 5 min) ;
 *   3. le redemarrage du planificateur libere les verrous restes poses.
 *
 * TEMOINS (sans eux ce vert ne prouverait rien) :
 *   - couverture : un balayage qui ne voit AUCUNE tache verrouillee ROUGIT
 *     (chemin faux / ordonnanceur non charge = zero evenement = faux vert) ;
 *   - negatif : l'instrument doit VOIR un TTL de 24 h quand on lui en donne un,
 *     et laisser passer un TTL borne ;
 *   - mecanique : on mesure la consequence, pas la constante — a 1 440 le verrou
 *     tient encore 30 minutes plus tard, a 10 il est tombe.
 */

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

// ─────────────────────────────────────────────────────────────────────────────
// L'INSTRUMENT
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Plafond du TTL d'un verrou planifie, en minutes.
 *
 * 6 heures : c'est la perte MAXIMALE qu'un verrou orphelin peut infliger. Elle
 * laisse passer la tache quotidienne suivante (24 h d'ecart) et, pour les
 * taches infra-horaires, elle est de toute facon reduite plus bas, tache par
 * tache, dans `routes/console.php`.
 */
function b17002Plafond(): int
{
    return 360;
}

/**
 * Plancher du TTL, en minutes. Un verrou plus court que la tache elle-meme
 * n'empeche plus le recouvrement : « reparer » le TTL en le mettant a 1 minute
 * reintroduirait le defaut que `withoutOverlapping` sert a eviter.
 */
function b17002Plancher(): int
{
    return 5;
}

/**
 * Temoin de couverture chiffre : le depot planifie au moins ce nombre de taches
 * verrouillees. Mesure du 2026-08-21 : 29 sur 38 taches planifiees.
 */
function b17002CouvertureMinimale(): int
{
    return 25;
}

/**
 * LE CONTROLE, isole de sa source de donnees — c'est ce qui permet de le
 * braquer sur un ordonnanceur VIDE (temoin de couverture) et sur des evenements
 * FABRIQUES (temoin negatif), et pas seulement sur le vrai depot.
 *
 * @param  iterable<int, Event>  $evenements
 * @return array<int, string> la liste des reproches ; vide = tout va bien
 */
function b17002Reproches(iterable $evenements): array
{
    $reproches = [];
    $verrouillees = 0;

    foreach ($evenements as $evenement) {
        if ($evenement->withoutOverlapping !== true) {
            continue;
        }

        $verrouillees++;
        $ttl = (int) $evenement->expiresAt;
        $nom = trim((string) $evenement->command);

        if ($ttl > b17002Plafond()) {
            $reproches[] = sprintf(
                'TTL %d min (plafond %d) — %s',
                $ttl,
                b17002Plafond(),
                $nom,
            );
        } elseif ($ttl < b17002Plancher()) {
            $reproches[] = sprintf(
                'TTL %d min (plancher %d) — %s',
                $ttl,
                b17002Plancher(),
                $nom,
            );
        }
    }

    if ($verrouillees === 0) {
        $reproches[] = 'COUVERTURE NULLE : le balayage n a vu AUCUNE tache portant withoutOverlapping.';
    }

    return $reproches;
}

/**
 * Les evenements REELLEMENT planifies par `routes/console.php`.
 *
 * L'appel a `list` force le chargement paresseux des routes console avant
 * qu'on interroge l'ordonnanceur — meme idiome que
 * `tests/Feature/Commands/AutomatismesDePurgeTest.php`.
 *
 * @return array<int, Event>
 */
function b17002EvenementsPlanifies(): array
{
    Artisan::call('list', ['--format' => 'txt']);

    return app(Schedule::class)->events();
}

function b17002CheminEntrypoint(): string
{
    // L'entrypoint vit a la racine du depot, pas dans `backend/`.
    return (realpath(base_path('..')) ?: base_path('..')) . '/infra/docker/entrypoint-prod.sh';
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. LE CONSTAT
// ═════════════════════════════════════════════════════════════════════════════

test('B17-002 — aucune tache planifiee ne garde le verrou par defaut de 24 h', function () {
    $evenements = b17002EvenementsPlanifies();

    // TEMOIN DE COUVERTURE, chiffre : si l'ordonnanceur n'etait pas charge, ou
    // si le balayage regardait le mauvais objet, on lirait zero tache et la
    // boucle ci-dessous serait vide — vert sans avoir rien inspecte.
    $verrouillees = array_values(array_filter(
        $evenements,
        fn (Event $e): bool => $e->withoutOverlapping === true,
    ));

    $this->assertGreaterThanOrEqual(
        b17002CouvertureMinimale(),
        count($verrouillees),
        'Le balayage ne voit plus les taches verrouillees : ' . count($verrouillees)
        . ' trouvee(s), au moins ' . b17002CouvertureMinimale() . ' attendues. '
        . 'Un chemin faux ou un ordonnanceur non charge donnerait un faux vert.',
    );

    $reproches = b17002Reproches($evenements);

    $this->assertSame(
        [],
        $reproches,
        "Des verrous planifies ont un TTL hors bornes.\n"
        . "Un verrou de 24 h laisse une tache tuee par un redeploiement a l arret pendant 24 h.\n"
        . implode("\n", $reproches),
    );
});

// ═════════════════════════════════════════════════════════════════════════════
// 2. TEMOIN DE COUVERTURE — un balayage aveugle doit ROUGIR
// ═════════════════════════════════════════════════════════════════════════════

test('B17-002 — TEMOIN DE COUVERTURE : un balayage qui ne voit rien rougit', function () {
    // Un ordonnanceur vide, c'est exactement ce que produirait un chemin faux,
    // un `routes/console.php` non charge, ou un filtre trop etroit.
    $reproches = b17002Reproches([]);

    $this->assertNotSame([], $reproches, 'Un balayage vide doit produire un reproche, pas un vert.');
    $this->assertStringContainsString(
        'COUVERTURE NULLE',
        implode("\n", $reproches),
        'Le reproche doit NOMMER le fait que rien n a ete inspecte.',
    );
});

// ═════════════════════════════════════════════════════════════════════════════
// 3. TEMOIN NEGATIF — l'instrument sait discriminer
// ═════════════════════════════════════════════════════════════════════════════

test('B17-002 — TEMOIN NEGATIF : l instrument voit un TTL de 24 h et laisse passer un TTL borne', function () {
    $mutex = new CacheEventMutex(app('cache'));

    $defaut = new Event($mutex, 'php artisan b17002:defaut');
    $defaut->withoutOverlapping(); // le defaut du cadre : 1 440 minutes

    $borne = new Event($mutex, 'php artisan b17002:borne');
    $borne->withoutOverlapping(30);

    $troisCourt = new Event($mutex, 'php artisan b17002:trop-court');
    $troisCourt->withoutOverlapping(1);

    $sansVerrou = new Event($mutex, 'php artisan b17002:sans-verrou');

    // Le defaut du cadre EST le defaut B17-002 : l'instrument doit le voir.
    $this->assertSame(1440, $defaut->expiresAt, 'Le cadre a change de defaut : relire la garde.');
    $vuDefaut = b17002Reproches([$defaut]);
    $this->assertCount(1, $vuDefaut);
    $this->assertStringContainsString('1440', $vuDefaut[0]);
    $this->assertStringContainsString('b17002:defaut', $vuDefaut[0]);

    // ...et il doit laisser passer un TTL borne, sinon il rougirait sur tout et
    // son rouge ne dirait rien.
    $this->assertSame([], b17002Reproches([$borne]));

    // ...et voir aussi l'exces inverse : un verrou d une minute ne verrouille rien.
    $vuCourt = b17002Reproches([$troisCourt]);
    $this->assertCount(1, $vuCourt);
    $this->assertStringContainsString('plancher', $vuCourt[0]);

    // Une tache SANS verrou n'est pas concernee : elle ne doit produire ni
    // reproche de TTL, ni compter comme couverture (d ou le COUVERTURE NULLE).
    $this->assertSame(
        ['COUVERTURE NULLE : le balayage n a vu AUCUNE tache portant withoutOverlapping.'],
        b17002Reproches([$sansVerrou]),
    );
});

// ═════════════════════════════════════════════════════════════════════════════
// 4. LA MECANIQUE, MESUREE — pas la constante, la consequence
// ═════════════════════════════════════════════════════════════════════════════

test('B17-002 — a 1440 le verrou tient encore 30 min plus tard, a 10 il est tombe', function () {
    $mutex = new CacheEventMutex(app('cache'));
    $mutex->useStore('array');

    $vingtQuatreHeures = new Event($mutex, 'php artisan b17002:24h');
    $vingtQuatreHeures->withoutOverlapping();

    $dixMinutes = new Event($mutex, 'php artisan b17002:10min');
    $dixMinutes->withoutOverlapping(10);

    // Les deux verrous se posent : c'est le processus qui vient d'etre tue.
    $this->assertTrue($mutex->create($vingtQuatreHeures));
    $this->assertTrue($mutex->create($dixMinutes));

    // TEMOIN — les deux sont bien poses AVANT le voyage. Sans lui, un « verrou
    // absent » 30 minutes plus tard pourrait vouloir dire « jamais pose ».
    $this->assertTrue($mutex->exists($vingtQuatreHeures), 'Le verrou 24 h doit etre pose.');

    $this->travel(30)->minutes();

    try {
        // LE DEFAUT, en une ligne : la tache n'a pas repris.
        $this->assertTrue(
            $mutex->exists($vingtQuatreHeures),
            'Un verrou de 1440 min tient toujours 30 minutes apres la mort de son porteur.',
        );
        // LE CORRECTIF, en une ligne : la tache peut repartir au passage suivant.
        $this->assertFalse(
            $mutex->exists($dixMinutes),
            'Un verrou de 10 min doit etre tombe 30 minutes plus tard.',
        );
    } finally {
        $this->travelBack();
    }
});

// ═════════════════════════════════════════════════════════════════════════════
// 5. LA LIBERATION AU REDEMARRAGE
// ═════════════════════════════════════════════════════════════════════════════

test('B17-002 — schedule:clear-cache RETIRE reellement un verrou reste pose', function () {
    // On ne se contente pas de lire le nom de la commande dans un script :
    // on verifie qu'elle fait ce qu'on attend d'elle sur un evenement REEL.
    $evenements = array_values(array_filter(
        b17002EvenementsPlanifies(),
        fn (Event $e): bool => $e->withoutOverlapping === true,
    ));

    $this->assertNotSame([], $evenements, 'Aucune tache verrouillee : rien a mesurer (faux vert).');

    $cible = $evenements[0];
    $this->assertTrue($cible->mutex->create($cible), 'Le verrou doit pouvoir se poser.');
    $this->assertTrue($cible->mutex->exists($cible), 'Le verrou doit etre pose AVANT le nettoyage.');

    Artisan::call('schedule:clear-cache');

    $this->assertFalse(
        $cible->mutex->exists($cible),
        'schedule:clear-cache doit avoir retire le verrou de ' . trim((string) $cible->command),
    );
});

test('B17-002 — le demarrage du planificateur libere les verrous restes poses', function () {
    $chemin = b17002CheminEntrypoint();

    // TEMOIN DE COUVERTURE — un chemin faux rendrait `false`/'' et toutes les
    // recherches de sous-chaine ci-dessous echoueraient pour la mauvaise raison.
    $this->assertFileExists($chemin, 'Chemin de l entrypoint introuvable : la garde ne mesure rien.');
    $script = (string) file_get_contents($chemin);
    $this->assertGreaterThan(
        500,
        strlen($script),
        'Entrypoint lu vide ou tronque : le balayage ne voit rien.',
    );

    // TEMOIN — on lit bien l entrypoint de production, pas un autre fichier.
    $this->assertStringContainsString('exec "$@"', $script);

    $this->assertStringContainsString(
        'schedule:clear-cache',
        $script,
        'Le demarrage du planificateur doit liberer les verrous laisses par le processus tue. '
        . 'Sans cela, le TTL borne est le SEUL filet, et il coute jusqu a ' . b17002Plafond() . ' minutes.',
    );
    $this->assertStringContainsString(
        'schedule:work',
        $script,
        'La liberation doit etre CONDITIONNEE au planificateur : la purger depuis l API ou '
        . 'Horizon retirerait le verrou d une tache en cours d execution.',
    );
});

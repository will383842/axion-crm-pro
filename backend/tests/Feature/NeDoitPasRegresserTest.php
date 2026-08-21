<?php

use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Rgpd\SiteGdprService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * « NE DOIT PAS RÉGRESSER » — les quatre acquis d'août 2026, rejoués.
 *
 * Ce fichier ne cherche pas de nouveaux défauts. Il rejoue quatre corrections
 * déjà payées, dont trois ont coûté cher :
 *
 *   1. ISOLATION SANS CONTEXTE → 0 LIGNE. La RLS était intégralement contournée
 *      (rôle applicatif SUPERUSER + BYPASSRLS) et les policies portaient un
 *      repli « pas de contexte ⇒ je vois tout ».
 *   2. RESTAURATION D'UN DUMP. La sauvegarde de production a échoué 91 fois sur
 *      91, trois mois durant, sans que personne ne le voie ; et quand on a
 *      enfin essayé de restaurer, la base sortait VIDE malgré « aucune erreur ».
 *   3. HORODATAGE. Un décalage de 2 h faisait tourner 5 h une campagne plafonnée
 *      à 3 h, et faussait `consent_at` — la date qui PROUVE le consentement.
 *   4. EXPORT RGPD PAR EMPREINTE. L'export ne filtrait que sur l'adresse et
 *      rendait vide pour les fiches nées de la synchro, qui portent une
 *      empreinte (`person_key`) et pas forcément la même adresse.
 *
 * ── Ce qu'il mesure, sans ambiguïté ─────────────────────────────────────────
 *
 * §1 mesure la BARRIÈRE SQL (connexion `pgsql_app`, rôle non-propriétaire
 * `axion_app`), pas la ceinture applicative : tant que `CRM_DB_APP_ROLE_ENABLED`
 * vaut false, l'application se connecte en `axion`, SUPERUSER et BYPASSRLS, et
 * rien de ce que §1 prouve ne s'applique au trafic réel.
 *
 * §2 exécute une VRAIE restauration (pg_dump → psql) sur une base jetable.
 * §3 et §4 mesurent le comportement applicatif de bout en bout.
 */
function nePasRegresserOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function nePasRegresserApp(): Connection
{
    return DB::connection('pgsql_app');
}

/**
 * Deux workspaces + une entreprise et un contact dans chacun, écrits par le
 * PROPRIÉTAIRE en auto-commit (donc visibles depuis l'autre session).
 *
 * @return array{0: string, 1: string}
 */
function nePasRegresserSemer(): array
{
    $owner = nePasRegresserOwner();
    $wsA = (string) Str::uuid();
    $wsB = (string) Str::uuid();

    foreach ([$wsA => 'A', $wsB => 'B'] as $id => $lettre) {
        $owner->table('workspaces')->insert([
            'id' => $id,
            'slug' => 'zz-ndpr-' . strtolower($lettre) . '-' . substr($id, 0, 8),
            'name' => 'ZZ NDPR ' . $lettre,
            'settings' => '{}',
            'cost_cap_eur' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $companyId = $owner->table('companies')->insertGetId([
            'workspace_id' => $id,
            'siren' => str_pad((string) random_int(100000000, 999999999), 9, '0'),
            'denomination' => 'ZZ NDPR ' . $lettre,
        ]);

        $owner->table('contacts')->insert([
            'workspace_id' => $id,
            'company_id' => $companyId,
            'last_name' => 'ZZ NDPR ' . $lettre,
        ]);
    }

    return [$wsA, $wsB];
}

/**
 * @param  list<string>  $workspaceIds
 */
function nePasRegresserNettoyer(array $workspaceIds): void
{
    $owner = nePasRegresserOwner();
    $owner->table('contacts')->whereIn('workspace_id', $workspaceIds)->delete();
    $owner->table('companies')->whereIn('workspace_id', $workspaceIds)->delete();
    $owner->table('workspaces')->whereIn('id', $workspaceIds)->delete();
}

afterEach(function () {
    nePasRegresserApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    nePasRegresserApp()->disconnect();
    nePasRegresserOwner()->disconnect();
});

// ═════════════════════════════════════════════════════════════════════════════
// ACQUIS 1 — ISOLATION SANS CONTEXTE : 0 LIGNE
// ═════════════════════════════════════════════════════════════════════════════

test('ACQUIS 1 — sans contexte workspace, le rôle applicatif ne voit AUCUNE ligne', function () {
    [$wsA, $wsB] = nePasRegresserSemer();

    try {
        $app = nePasRegresserApp();

        // Témoin de charge : sans lui, une base vide rendrait ce test vert sans
        // rien prouver — le piège exact du test d'étanchéité qui pré-insérait la
        // ligne qu'il devait faire produire.
        $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $wsA]);
        expect($app->table('companies')->count())->toBe(1)
            ->and($app->table('contacts')->count())->toBe(1);

        // Et l'acquis : plus de contexte, plus rien.
        $app->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        expect($app->table('companies')->count())->toBe(0)
            ->and($app->table('contacts')->count())->toBe(0);
    } finally {
        nePasRegresserNettoyer([$wsA, $wsB]);
    }
});

test('ACQUIS 1 (suite) — le rôle applicatif n’est ni superutilisateur ni BYPASSRLS', function () {
    // C'était LA cause racine : les policies existaient déjà, elles étaient
    // contournées parce que le rôle était `axion`, SUPERUSER + BYPASSRLS.
    $role = (string) config('database.connections.pgsql_app.username');

    $attrs = nePasRegresserOwner()->selectOne(
        'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?',
        [$role],
    );

    expect($attrs)->not->toBeNull()
        ->and($attrs->rolsuper)->toBeFalsy()
        ->and($attrs->rolbypassrls)->toBeFalsy();
});

// ═════════════════════════════════════════════════════════════════════════════
// ACQUIS 2 — RESTAURATION D'UN DUMP
//
// 🔴 Ce test exécute réellement le cycle dump → restauration → vérification,
// avec LES MÊMES OPTIONS que la production (`infra/scripts/backup-postgres.sh`
// et `restore-postgres.sh`), sur une base jetable. Il ne remplace pas
// `infra/scripts/dr-drill.sh` — celui-ci rapatrie la copie HORS-SITE réelle et
// compare aux comptages de production, ce qu'aucun test unitaire ne peut faire.
// Ce que ce test garde, c'est la partie reproductible : « le dump que produit
// notre recette se restaure sans erreur, avec ses données et ses policies ».
// ═════════════════════════════════════════════════════════════════════════════

test('ACQUIS 2 — un dump produit avec la recette de production se restaure sans erreur, AVEC ses données et ses policies', function () {
    foreach (['pg_dump', 'psql', 'gzip'] as $binaire) {
        $sortie = [];
        exec('command -v ' . escapeshellarg($binaire) . ' 2>/dev/null', $sortie, $code);
        if ($code !== 0) {
            // Un `skip` est un aveu, pas une victoire : on nomme ce qui manque
            // et où le contrôle tourne pour de vrai.
            $this->markTestSkipped(
                "« {$binaire} » est absent de cet environnement. Ce contrôle s'exécute en CI "
                . '(runner ubuntu-24.04, client PostgreSQL 16 préinstallé, job `backend` de '
                . 'ci.yml) et à la main via `infra/scripts/dr-drill.sh`. Il n’est PAS exécuté '
                . "dans le conteneur `api` de docker-compose, qui n'embarque pas le client "
                . 'PostgreSQL — `make test-backend` ne le joue donc pas.',
            );
        }
    }

    $config = config('database.connections.pgsql_owner');
    $source = (string) $config['database'];
    $cible = 'zz_restauration_' . bin2hex(random_bytes(4));
    $dump = sys_get_temp_dir() . '/zz-restauration-' . bin2hex(random_bytes(4)) . '.sql.gz';

    $psqlBase = sprintf(
        'PGPASSWORD=%s psql -h %s -p %s -U %s -v ON_ERROR_STOP=1',
        escapeshellarg((string) $config['password']),
        escapeshellarg((string) $config['host']),
        escapeshellarg((string) $config['port']),
        escapeshellarg((string) $config['username']),
    );

    [$wsA, $wsB] = nePasRegresserSemer();

    try {
        // Comptages de RÉFÉRENCE, pris AVANT le dump. Les lignes semées sont
        // committées : elles sont donc bien dans le dump. C'est ce qui distingue
        // ce contrôle d'une restauration « sans erreur » mais VIDE — le symptôme
        // exact du 2026-08-16.
        $refTables = (int) nePasRegresserOwner()->selectOne(
            "SELECT count(*) AS n FROM information_schema.tables WHERE table_schema = 'public'",
        )->n;
        $refCompanies = (int) nePasRegresserOwner()->selectOne('SELECT count(*) AS n FROM companies')->n;
        $refPolicies = (int) nePasRegresserOwner()->selectOne(
            "SELECT count(*) AS n FROM pg_policies WHERE schemaname = 'public'",
        )->n;

        expect($refCompanies)->toBeGreaterThan(0, 'Rien à sauvegarder : le contrôle serait vrai par vacuité.');

        // ── 1. Le dump, avec les options EXACTES de backup-postgres.sh ────────
        // `-Fp --no-owner --no-acl --clean --if-exists`, précédé du bloc
        // CREATE EXTENSION (pg_dump en texte simple ne le pose pas), le tout
        // compressé en gzip. Reproduire la recette est le seul moyen que ce
        // contrôle parle de la VRAIE sauvegarde.
        $extensions = implode("\n", array_map(
            static fn (string $ext): string => "CREATE EXTENSION IF NOT EXISTS \"{$ext}\";",
            ['pg_trgm', 'unaccent', 'btree_gin', 'btree_gist', 'pgcrypto', 'citext', 'uuid-ossp', 'postgis', 'vector'],
        ));

        $commandeDump = sprintf(
            '{ printf %%s\\\\n %s; PGPASSWORD=%s pg_dump -h %s -p %s -U %s -Fp --no-owner --no-acl --clean --if-exists %s; } | gzip -9 > %s',
            escapeshellarg($extensions),
            escapeshellarg((string) $config['password']),
            escapeshellarg((string) $config['host']),
            escapeshellarg((string) $config['port']),
            escapeshellarg((string) $config['username']),
            escapeshellarg($source),
            escapeshellarg($dump),
        );

        $sortieDump = [];
        exec($commandeDump . ' 2>&1', $sortieDump, $codeDump);
        expect($codeDump)->toBe(0, "pg_dump a échoué :\n" . implode("\n", $sortieDump));
        expect(file_exists($dump) && filesize($dump) > 0)->toBeTrue('Le dump est vide.');

        // ── 2. La restauration, avec la recette de restore-postgres.sh ────────
        exec($psqlBase . ' -d postgres -c ' . escapeshellarg("CREATE DATABASE {$cible}") . ' 2>&1', $o1, $c1);
        expect($c1)->toBe(0, "Création de la base jetable impossible :\n" . implode("\n", $o1));

        $commandeRestore = sprintf(
            'gunzip -c %s | %s -d %s --single-transaction',
            escapeshellarg($dump),
            $psqlBase,
            escapeshellarg($cible),
        );

        $sortieRestore = [];
        exec($commandeRestore . ' 2>&1', $sortieRestore, $codeRestore);

        $erreurs = array_values(array_filter(
            $sortieRestore,
            static fn (string $ligne): bool => str_starts_with($ligne, 'ERROR') || str_contains($ligne, 'ERREUR'),
        ));

        // `ON_ERROR_STOP=1` + `--single-transaction` : la moindre erreur annule
        // TOUT et sort en code non nul. C'est exactement ce que fait la prod.
        expect($codeRestore)->toBe(
            0,
            "La restauration a échoué. Erreurs distinctes :\n  - " . implode("\n  - ", array_slice($erreurs, 0, 10)),
        );

        // ── 3. Les vérifications qui font la différence ───────────────────────
        $lire = static function (string $sql) use ($psqlBase, $cible): string {
            exec($psqlBase . ' -d ' . escapeshellarg($cible) . ' -tAc ' . escapeshellarg($sql) . ' 2>&1', $o, $c);

            return $c === 0 ? trim(implode('', $o)) : 'ECHEC:' . implode('|', $o);
        };

        // a) le schéma est là ;
        expect((int) $lire("SELECT count(*) FROM information_schema.tables WHERE table_schema='public'"))
            ->toBe($refTables, 'Le nombre de tables restaurées diffère de la source.');

        // b) LES DONNÉES sont là — une restauration « sans erreur » mais à 0
        //    ligne reste un échec, et c'est ce qui s'était produit ;
        expect((int) $lire('SELECT count(*) FROM companies'))
            ->toBe($refCompanies, 'Base restaurée VIDE (ou partielle) : le cas du 2026-08-16.');

        // c) LA BARRIÈRE est là. Un dump qui perd les policies rendrait la base
        //    restaurée exploitable… par tout le monde. `--no-acl` retire les
        //    GRANT, pas les policies : on le vérifie plutôt que de le croire ;
        expect((int) $lire("SELECT count(*) FROM pg_policies WHERE schemaname='public'"))
            ->toBe($refPolicies, 'Des policies RLS ont disparu à la restauration.');

        expect($lire("SELECT relforcerowsecurity FROM pg_class WHERE oid='companies'::regclass"))
            ->toBe('t', 'FORCE ROW LEVEL SECURITY n’a pas survécu à la restauration.');

        // d) 🔴 CE QUE LA RECETTE DE PRODUCTION PERD, et qu'il faut savoir avant
        //    le jour J : `--no-acl` n'écrit AUCUN GRANT dans le dump. La base
        //    restaurée ne donne donc aucun droit au rôle applicatif
        //    `axion_app`. Tant que `CRM_DB_APP_ROLE_ENABLED` vaut false c'est
        //    sans conséquence (l'application se connecte en propriétaire) ;
        //    le jour où le drapeau passe à true, une restauration livre une
        //    application qui ne peut plus rien lire.
        //
        //    Cette attente est ÉCRITE À L'ENVERS exprès : le jour où la recette
        //    de restauration reposera les GRANT, ce test rougira et il faudra
        //    venir ici constater la bonne nouvelle.
        $role = (string) config('database.connections.pgsql_app.username');
        expect($lire("SELECT has_table_privilege('{$role}', 'companies', 'SELECT')::text"))
            ->toBe(
                'false',
                'Les GRANT du rôle applicatif sont désormais restaurés : retirer cette attente '
                . 'inversée et vérifier que `restore-postgres.sh` en est bien la cause.',
            );
    } finally {
        @unlink($dump);
        exec($psqlBase . ' -d postgres -c ' . escapeshellarg("DROP DATABASE IF EXISTS {$cible} WITH (FORCE)") . ' 2>&1');
        nePasRegresserNettoyer([$wsA, $wsB]);
    }
});

test('ACQUIS 2 (suite) — la surveillance et l’exercice de restauration existent toujours, et sont exécutables', function () {
    // La sauvegarde de production a échoué 91 fois sur 91 parce que
    // `backup-postgres.sh` était committé en 100644 : cron mourait sur
    // « Permission denied », dans un journal que personne ne lit. La CI garde
    // déjà le bit d'exécution (job `scripts-executables`) ; ce qu'elle ne garde
    // pas, c'est l'EXISTENCE des trois pièces. Un script supprimé rend le job
    // `scripts-executables` vert par vacuité.
    $racine = dirname(base_path());

    // ⚠️ TÉMOIN DE MONTAGE — VINGT-ET-UNIÈME CAS DE `A-011`, PORTÉ ICI LE 2026-08-21.
    //
    // Ce contrôle accuse un fichier d'avoir DISPARU. Sur le banc `a35r`, `$racine`
    // vaut `/var/www`, et les deux arbres qu'il inspecte n'y arrivent pas de la
    // même façon : `infra/` est un montage BIND (une édition du dépôt y est vue
    // aussitôt), `.github/` est une COPIE `docker cp` qu'il faut rafraîchir à la
    // main. Le 2026-08-20, cette différence a fait rougir ce test en accusant
    // `.github/workflows/surveillance-sauvegarde.yml` d'avoir disparu — le
    // fichier existait, c'est l'arbre qui n'était pas là.
    //
    // *Un contrôle d'existence sans témoin de montage n'accuse pas le produit :
    // il accuse le banc, et il le fait avec les mots du produit.* C'est la forme
    // la plus coûteuse d'un faux rouge, parce qu'elle envoie chercher un défaut
    // qui n'existe pas.
    //
    // La parade existait déjà dans `tests/Feature/Infra/` ; elle n'avait pas été
    // portée ici. On la porte : chaque arbre inspecté doit d'abord se PROUVER
    // présent, et le message dit alors quoi faire — recopier, pas chercher.
    foreach (['infra/scripts', '.github/workflows'] as $arbre) {
        $this->assertDirectoryExists(
            $racine . '/' . $arbre,
            "L'arbre « {$arbre} » n'est pas visible depuis le banc ({$racine}) : ce contrôle "
            . "accuserait des fichiers d'avoir disparu alors qu'il ne les a jamais cherchés au "
            . 'bon endroit.

Sur le banc `a35r`, `/var/www/.github` est une COPIE et non un '
            . 'montage : la rafraîchir par
'
            . '  tar -c --exclude=node_modules .github | docker exec -i a35r tar -x -C /var/www',
        );
    }

    foreach ([
        'infra/scripts/backup-postgres.sh',
        'infra/scripts/restore-postgres.sh',
        'infra/scripts/verifier-sauvegarde.sh',
        'infra/scripts/dr-drill.sh',
        '.github/workflows/surveillance-sauvegarde.yml',
    ] as $chemin) {
        expect(is_file($racine . '/' . $chemin))->toBeTrue(
            "« {$chemin} » a disparu : la chaîne sauvegarde → surveillance → restauration est rompue.",
        );
    }

    // Et le contenu qui compte : la surveillance doit tourner AILLEURS que sur
    // la machine surveillée (sinon elle meurt avec elle), et le drill doit
    // comparer des COMPTAGES (une restauration sans erreur mais vide est un
    // échec).
    $surveillance = (string) file_get_contents($racine . '/.github/workflows/surveillance-sauvegarde.yml');
    expect(str_contains($surveillance, 'schedule'))->toBeTrue('La surveillance n’est plus PLANIFIÉE.')
        ->and(str_contains($surveillance, 'verifier-sauvegarde.sh'))->toBeTrue('La surveillance n’appelle plus le script de contrôle.');

    $drill = (string) file_get_contents($racine . '/infra/scripts/dr-drill.sh');
    expect(str_contains($drill, 'count(*)'))->toBeTrue('dr-drill.sh ne compare plus de COMPTAGES.')
        ->and(str_contains($drill, 'sha256sum'))->toBeTrue('dr-drill.sh ne vérifie plus l’empreinte de la copie hors-site.');
});

// ═════════════════════════════════════════════════════════════════════════════
// ACQUIS 3 — HORODATAGE UTC
// ═════════════════════════════════════════════════════════════════════════════

test('ACQUIS 3 — la session Postgres est alignée sur le fuseau de l’application', function () {
    expect(DB::selectOne('SHOW timezone')->TimeZone)->toBe(config('app.timezone'));
});

test('ACQUIS 3 — une date reçue en UTC survit à l’aller-retour en base', function () {
    // Le deuxième visage du décalage, mesuré en PRODUCTION le 2026-08-17 : le
    // site émet du `Date.toISOString()`, donc de l'UTC ; Postgres relisait
    // « 10:00:00 » nu comme 10 h à Paris et l'instant reculait de 2 h. Sur
    // `consent_at`, c'est la date qui PROUVE le consentement.
    $evenement = SiteSyncEvent::fromArray([
        'schema_version' => 1,
        'event_id' => 'ndpr-' . Str::random(8),
        'event_type' => 'form_submission',
        'occurred_at' => '2026-08-17T10:00:00.000Z',
        'form_type' => 'autre',
        'subject_ref' => 'site:submission:ndpr',
        'person' => [
            'person_key' => hash('sha256', 'ndpr@test.invalid'),
            'email' => 'ndpr@test.invalid',
            'first_name' => 'Ndpr',
            'last_name' => 'Controle',
        ],
        'consent' => ['at' => '2026-08-17T10:00:00.000Z', 'version' => 'contact-v1'],
    ]);

    DB::statement('CREATE TEMP TABLE zz_ndpr_tz (quand timestamptz, consenti timestamptz)');
    DB::table('zz_ndpr_tz')->insert([
        'quand' => $evenement->occurredAt,
        'consenti' => $evenement->consentAt(),
    ]);

    $ligne = DB::table('zz_ndpr_tz')->first();
    $attendu = Carbon::parse('2026-08-17T10:00:00Z');

    expect(abs($attendu->diffInSeconds(Carbon::parse($ligne->quand), false)))->toBeLessThan(2)
        ->and(abs($attendu->diffInSeconds(Carbon::parse($ligne->consenti), false)))->toBeLessThan(2);
});

test('ACQUIS 3 — le réglage qui rend le verrou capable de rougir est toujours en place', function () {
    // `HorodatagesFuseauTest` ne peut rougir que si la session de test est
    // alignée sur `app.timezone`. Ce réglage vit dans DEUX fichiers de
    // configuration (local et CI) et dans `config/database.php`. Le retirer
    // d'un seul des trois désarmerait le verrou sans qu'aucun test ne proteste :
    // exactement la façon dont le défaut avait vécu jusque-là.
    $backend = base_path();

    foreach (['phpunit.xml', 'phpunit-ci.xml'] as $fichier) {
        $contenu = (string) file_get_contents($backend . '/' . $fichier);
        // `toContain` est VARIADIQUE en Pest : un second argument y serait pris
        // pour une seconde aiguille à chercher, pas pour un message. D'où
        // `str_contains` + `toBeTrue`, qui accepte bien un message.
        expect(str_contains($contenu, 'DB_TIMEZONE'))->toBeTrue(
            "« {$fichier} » ne pose plus DB_TIMEZONE : HorodatagesFuseauTest ne peut plus rougir.",
        );
    }

    $database = (string) file_get_contents($backend . '/config/database.php');
    expect($database)->toContain("'timezone' => env('DB_TIMEZONE')");
});

// ═════════════════════════════════════════════════════════════════════════════
// ACQUIS 4 — EXPORT RGPD PAR EMPREINTE
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Crée le workspace commercial et deux fiches DISJOINTES :
 *   - une fiche « synchro » : empreinte connue, adresse DIFFÉRENTE ;
 *   - une fiche « collecte » : bonne adresse, AUCUNE empreinte.
 *
 * @return array{0: string, 1: string, 2: string, 3: bool}
 */
function nePasRegresserSemerRgpd(): array
{
    $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
    $owner = nePasRegresserOwner();

    // Ces écritures sont COMMITTÉES (connexion propriétaire) : si l'on créait le
    // workspace commercial sans le reprendre, il survivrait à tout le reste de
    // l'exécution. On retient donc si on l'a créé, pour ne défaire QUE cela.
    $wsId = $owner->table('workspaces')->where('slug', $slug)->value('id');
    $creePourLeTest = $wsId === null;
    if ($wsId === null) {
        $wsId = (string) Str::uuid();
        $owner->table('workspaces')->insert([
            'id' => $wsId,
            'slug' => $slug,
            'name' => 'ZZ Business',
            'settings' => '{}',
            'cost_cap_eur' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $wsId = (string) $wsId;

    $empreinte = hash('sha256', 'sel-du-site|zz-ndpr-rgpd');
    $emailDemande = 'zz-ndpr-rgpd@test.invalid';

    $companyA = $owner->table('companies')->insertGetId([
        'workspace_id' => $wsId,
        'siren' => str_pad((string) random_int(100000000, 999999999), 9, '0'),
        'denomination' => 'ZZ RGPD SYNCHRO',
    ]);
    $companyB = $owner->table('companies')->insertGetId([
        'workspace_id' => $wsId,
        'siren' => str_pad((string) random_int(100000000, 999999999), 9, '0'),
        'denomination' => 'ZZ RGPD COLLECTE',
    ]);

    // 🔴 LE CŒUR DU TEST : cette fiche ne partage AUCUNE adresse avec la demande.
    // Si l'export retombe sur un filtre par adresse — la régression corrigée —,
    // elle devient introuvable et l'export rend vide.
    $owner->table('contacts')->insert([
        'workspace_id' => $wsId,
        'company_id' => $companyA,
        'last_name' => 'ZZ SYNCHRO',
        'email' => 'une-tout-autre-adresse@test.invalid',
        'person_key' => $empreinte,
    ]);

    // Et la fiche née de la collecte : bonne adresse, pas d'empreinte (le
    // scraping ne connaît pas le sel du site). Les deux chemins doivent vivre.
    $owner->table('contacts')->insert([
        'workspace_id' => $wsId,
        'company_id' => $companyB,
        'last_name' => 'ZZ COLLECTE',
        'email' => $emailDemande,
    ]);

    $owner->table('activities')->insert([
        'workspace_id' => $wsId,
        'type' => 'note',
        'kind' => 'form_submission',
        'person_key' => $empreinte,
        'title' => 'ZZ activité par empreinte',
    ]);

    return [$wsId, $empreinte, $emailDemande, $creePourLeTest];
}

/**
 * Défait exactement ce que `nePasRegresserSemerRgpd()` a écrit.
 */
function nePasRegresserNettoyerRgpd(string $wsId, bool $workspaceCree): void
{
    $owner = nePasRegresserOwner();
    $owner->table('activities')->where('workspace_id', $wsId)->delete();
    $owner->table('contacts')->where('workspace_id', $wsId)->delete();
    $owner->table('companies')->where('workspace_id', $wsId)->delete();

    if ($workspaceCree) {
        $owner->table('workspaces')->where('id', $wsId)->delete();
    }
}

test('ACQUIS 4 — l’export RGPD trouve la fiche par son EMPREINTE, même quand l’adresse ne correspond pas', function () {
    [$wsId, $empreinte, $emailDemande, $wsCree] = nePasRegresserSemerRgpd();

    try {
        /** @var array<string, mixed> $export */
        $export = app(SiteGdprService::class)->export($empreinte, $emailDemande);

        $noms = array_map(
            static fn (array $c): string => (string) $c['last_name'],
            $export['business']['contacts'],
        );
        sort($noms);

        // Les DEUX chemins, nommés : l'empreinte ET l'adresse. Un export qui ne
        // rendrait que « ZZ COLLECTE » est exactement la régression corrigée —
        // et il serait vert si l'on avait semé une fiche portant les deux clés.
        expect($noms)->toBe(
            ['ZZ COLLECTE', 'ZZ SYNCHRO'],
            "L'export ne remonte pas les deux fiches. Chemin manquant : "
            . (in_array('ZZ SYNCHRO', $noms, true) ? 'filtre par ADRESSE' : 'filtre par EMPREINTE (person_key)')
            . '.',
        );

        // La timeline suit la personne par son EMPREINTE, jamais par l'adresse.
        expect($export['business']['activities'])->toHaveCount(1);

        // L'export ne modifie rien (art. 15 ≠ art. 17).
        expect(nePasRegresserOwner()->table('contacts')->where('workspace_id', $wsId)->count())->toBe(2);
    } finally {
        nePasRegresserNettoyerRgpd($wsId, $wsCree);
    }
});

test('ACQUIS 4 (suite) — une empreinte INCONNUE ne rend rien : le filtre filtre vraiment', function () {
    // Le témoin symétrique. Sans lui, un export qui rendrait TOUT le workspace
    // ferait passer le test précédent — et livrerait à chaque demandeur les
    // données de tous les autres.
    [$wsId, , $emailDemande, $wsCree] = nePasRegresserSemerRgpd();

    try {
        $export = app(SiteGdprService::class)->export(
            hash('sha256', 'empreinte-qui-nexiste-pas'),
            'personne-inconnue@test.invalid',
        );

        expect($export['business']['contacts'])->toBe([])
            ->and($export['business']['activities'])->toBe([])
            ->and($export['vivier']['candidates'])->toBe([]);

        // Et l'adresse seule suffit toujours, elle : contrôle croisé.
        $parAdresse = app(SiteGdprService::class)->export(
            hash('sha256', 'empreinte-qui-nexiste-pas'),
            $emailDemande,
        );
        expect($parAdresse['business']['contacts'])->toHaveCount(1);
    } finally {
        nePasRegresserNettoyerRgpd($wsId, $wsCree);
    }
});

<?php

/**
 * LES PURGES AUTOMATIQUES — celles qui detruisent, et celles qui ne tournent jamais.
 *
 * Audit 360, lot « Automatismes ». Plusieurs constats, une seule famille de fautes :
 * une purge que PERSONNE n'a jamais executee en test.
 *
 *   - B15-007 (S2) : l'en-tete de `RetentionPurge` annoncait CINQ politiques de
 *     retention et `handle()` n'en jouait que TROIS. `audit_logs > 24 mois` et
 *     `llm_usage > 12 mois` n'existaient nulle part ailleurs que dans ce
 *     docblock. Les deux gardes en fin de fichier ne jouent aucune purge :
 *     elles LISENT le fichier et comparent la promesse aux appels a `purger()`.
 *
 *   - A08-006 (S0) : `rgpd:anonymize-ips` n'a JAMAIS fonctionne. Son SQL porte
 *     `ip::cidr / CASE WHEN family(ip)=4 THEN 24 ELSE 48 END`. Mesure jouee dans
 *     Postgres 16 :
 *         ERROR: operator does not exist: cidr / integer
 *     Il n'existe aucun operateur `cidr / integer` ; la fonction correcte est
 *     `set_masklen(cidr, int)`. Le defaut a survecu deux ans parce que la seule
 *     branche jamais essayee a la main est `--dry-run`, qui passe par un
 *     `count()` et n'atteint jamais le SQL fautif. Ces gardes jouent donc le
 *     CHEMIN REEL.
 *
 *   - B17-001 / H45-002 (S1) : `retention:purge --dry-run` EXECUTE l'UPDATE
 *     qu'il pretend seulement compter. Cause mesuree : la reecriture
 *     `preg_replace('/^UPDATE (\w+) SET .* WHERE/', ...)` n'a pas le
 *     modificateur `s`, donc `.` ne franchit pas le saut de ligne du SQL ecrit
 *     sur deux lignes ; la reecriture echoue, et l'UPDATE INTACT part dans
 *     `DB::selectOne()`, qui fait prepare() + execute(). Les deux DELETE de la
 *     meme boucle, eux, tiennent sur une ligne et sont correctement transformes
 *     — c'est le TEMOIN NEGATIF tout trouve : une garde qui verrait « rien n'a
 *     bouge » partout ne prouverait rien ; ici on exige que les DELETE ne
 *     bougent pas ET que l'UPDATE ne bouge pas non plus.
 *
 *   - B11-003 (S1) : `retention:purge` n'a aucun filtre d'espace. Elle purge
 *     TOUS les espaces a la fois, ou aucun — un exploitant qui veut nettoyer un
 *     seul locataire n'a aucun moyen de le dire.
 *
 *   - B15-011 (S3) : la meme commande ne touchait PAS `users.last_login_ip`.
 *     Mesure du 2026-08-22 : `AuthService.php:116` l'ecrit a chaque connexion,
 *     et le seul endroit du depot qui la remet a null est un effacement RGPD
 *     *demande* (`GdprErasureService.php:230`). Sans demande, l'IP de derniere
 *     connexion etait conservee sans aucune limite de duree.
 *
 *   - B17-005 (S2) : son predicat ne distinguait pas une IP DEJA tronquee d'une
 *     IP brute. L'operation est idempotente en valeur, pas en ecriture : chaque
 *     nuit a 04:30 reecrivait tout l'historique de plus de 30 jours. Les gardes
 *     ci-dessous jouent DEUX passes et exigent que la seconde n'ecrive rien,
 *     avec un temoin qui interdit d'y arriver en n'ecrivant plus jamais.
 *
 *   - B17-009 (S0) : les deux purges RGPD correctement construites
 *     (`rgpd:purge-vivier`, `rgpd:purge-business-prospects`) sont sautees par
 *     un `skip()` SILENCIEUX tant que `CRM_PURGE_ENABLED` n'est pas a true —
 *     et ce drapeau vaut `false` partout ou il est ecrit (`.env.example:258`,
 *     `config/crm.php:143`) et n'est ecrit NULLE PART ailleurs (ni
 *     docker-compose.prod.yml, ni infra/, ni .github/). L'echeance CNIL n'est
 *     donc tenue par aucun automatisme, et rien ne le dit jamais a personne.
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ── Fabriques minimales ──────────────────────────────────────────────────────

function espaceAutomatisme(string $prefixe = 'auto'): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => $prefixe . '-' . Str::random(8),
        'name' => 'Espace ' . $prefixe,
        'settings' => '{}',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function utilisateurAutomatisme(): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert([
        'id' => $id,
        'email' => 'auto-' . Str::random(10) . '@example.test',
        'name' => 'Compte automatisme',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** Une ligne d'audit avec une IP et une date choisies. Renvoie son id. */
function ligneAudit(string $ip, DateTimeInterface $quand): int
{
    return (int) DB::table('audit_logs')->insertGetId([
        'event_type' => 'test.automatisme',
        'ip' => $ip,
        'prev_hash' => str_repeat('0', 64),
        'current_hash' => hash('sha256', $ip . $quand->format('c')),
        'created_at' => $quand,
    ]);
}

function ipDeLaLigne(int $id): ?string
{
    return DB::table('audit_logs')->where('id', $id)->value('ip');
}

/** Un run de scraping ancien, payload rempli. Renvoie son id. */
function runScraperAncien(string $espace, int $jours = 200): int
{
    return (int) DB::table('scraper_runs')->insertGetId([
        'workspace_id' => $espace,
        'source' => 'test',
        'status' => 'success',
        'payload_path' => '/tmp/charge-utile.json',
        'response_payload' => json_encode(['pii' => 'nom@exemple.test']),
        'created_at' => now()->subDays($jours),
    ]);
}

function notificationAncienne(string $espace, string $utilisateur, int $jours = 200): void
{
    DB::table('notifications')->insert([
        'workspace_id' => $espace,
        'user_id' => $utilisateur,
        'type' => 'test',
        'title' => 'Ancienne',
        'created_at' => now()->subDays($jours),
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// A08-006 — la tache RGPD d'anonymisation des IP
// ═════════════════════════════════════════════════════════════════════════════

test('A08-006 — rgpd:anonymize-ips tronque REELLEMENT les IP de plus de 30 jours', function () {
    $ancienneV4 = ligneAudit('192.168.42.123', now()->subDays(60));
    $ancienneV6 = ligneAudit('2001:db8:1234:5678::1', now()->subDays(60));
    // TEMOIN 1 — une IP RECENTE ne doit PAS etre touchee. Sans lui, une commande
    // qui raserait toutes les IP (ou qui les mettrait toutes a NULL) passerait
    // pour une reussite.
    $recente = ligneAudit('10.0.0.7', now()->subDays(2));

    // TEMOIN 2 — la garde ne passe pas au vert sur une table vide : on exige que
    // les trois lignes existent AVANT, avec leurs IP d'origine.
    expect(ipDeLaLigne($ancienneV4))->toBe('192.168.42.123');
    expect(ipDeLaLigne($ancienneV6))->toBe('2001:db8:1234:5678::1');
    expect(ipDeLaLigne($recente))->toBe('10.0.0.7');

    // CHEMIN REEL, pas --dry-run : c'est la seule branche qui atteint le SQL.
    $code = Artisan::call('rgpd:anonymize-ips');

    expect($code)->toBe(0);
    // IPv4 tronquee au /24, IPv6 au /48 — ce que la docbloc de la commande promet.
    expect(ipDeLaLigne($ancienneV4))->toBe('192.168.42.0');
    expect(ipDeLaLigne($ancienneV6))->toBe('2001:db8:1234::');
    expect(ipDeLaLigne($recente))->toBe('10.0.0.7');
});

test('A08-006 — rgpd:anonymize-ips efface l IP des sessions anciennes, et d elles seules', function () {
    $espace = espaceAutomatisme();
    $utilisateur = utilisateurAutomatisme();

    DB::table('sessions')->insert([
        'id' => 'ancienne', 'user_id' => $utilisateur, 'workspace_id' => $espace,
        'ip_address' => '203.0.113.9', 'payload' => 'x',
        'last_activity' => now()->subDays(60)->timestamp,
    ]);
    // TEMOIN — une session RECENTE garde son IP.
    DB::table('sessions')->insert([
        'id' => 'recente', 'user_id' => $utilisateur, 'workspace_id' => $espace,
        'ip_address' => '203.0.113.10', 'payload' => 'x',
        'last_activity' => now()->subDays(2)->timestamp,
    ]);

    expect(DB::table('sessions')->whereNotNull('ip_address')->count())->toBe(2);

    Artisan::call('rgpd:anonymize-ips');

    expect(DB::table('sessions')->where('id', 'ancienne')->value('ip_address'))->toBeNull();
    expect(DB::table('sessions')->where('id', 'recente')->value('ip_address'))->toBe('203.0.113.10');
});

// ═════════════════════════════════════════════════════════════════════════════
// B15-011 — l'IP de derniere connexion, que PERSONNE n'anonymisait
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Un compte avec une IP de derniere connexion et une date choisies.
 * `$quand` a null = la colonne `last_login_at` reste vide, cas mesure en base.
 */
function utilisateurAvecDerniereIp(string $ip, ?DateTimeInterface $quand): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert([
        'id' => $id,
        'email' => 'ip-' . Str::random(10) . '@example.test',
        'name' => 'Compte derniere IP',
        'last_login_at' => $quand,
        'last_login_ip' => $ip,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function derniereIpDe(string $id): ?string
{
    return DB::table('users')->where('id', $id)->value('last_login_ip');
}

test('B15-011 — rgpd:anonymize-ips tronque AUSSI users.last_login_ip', function () {
    // Mesure du 2026-08-22 : `handle()` ne touchait que `audit_logs` et
    // `sessions`. `AuthService.php:116` ecrit pourtant `last_login_ip` a chaque
    // connexion, et le seul endroit qui la remet a null est l'effacement RGPD
    // *demande* (`GdprErasureService.php:230`) : sans demande, l'IP restait en
    // base SANS LIMITE DE DUREE.
    $ancienV4 = utilisateurAvecDerniereIp('192.168.42.123', now()->subDays(60));
    $ancienV6 = utilisateurAvecDerniereIp('2001:db8:1234:5678::1', now()->subDays(60));
    // TEMOIN 1 — une connexion RECENTE garde son IP entiere : une commande qui
    // raserait toutes les colonnes passerait sinon pour une reussite.
    $recent = utilisateurAvecDerniereIp('10.0.0.7', now()->subDays(2));
    // TEMOIN 2 — une IP SANS date de connexion. Elle n'a aucune echeance
    // opposable ; la garder indefiniment serait exactement le defaut repare.
    $sansDate = utilisateurAvecDerniereIp('203.0.113.77', null);

    // TEMOIN 3 — la garde ne passe pas au vert sur une table vide.
    expect(derniereIpDe($ancienV4))->toBe('192.168.42.123');
    expect(derniereIpDe($sansDate))->toBe('203.0.113.77');

    Artisan::call('rgpd:anonymize-ips');

    expect(derniereIpDe($ancienV4))->toBe('192.168.42.0');
    expect(derniereIpDe($ancienV6))->toBe('2001:db8:1234::');
    expect(derniereIpDe($sansDate))->toBe('203.0.113.0');
    expect(derniereIpDe($recent))->toBe('10.0.0.7');
});

test('B15-011 — le compte-rendu de la commande NOMME la table users', function () {
    utilisateurAvecDerniereIp('192.168.42.123', now()->subDays(60));

    // `Artisan::call()` + `Artisan::output()` : `expectsOutputToContain` compare
    // ligne a ligne et le formateur coupe les messages longs.
    Artisan::call('rgpd:anonymize-ips');
    $sortie = Artisan::output();

    // Une purge qui travaille sans le dire est une purge qu'on ne saura pas
    // diagnostiquer le jour ou elle cessera de travailler.
    expect(str_contains($sortie, 'users=1 '))->toBeTrue(
        'Le compte-rendu doit annoncer le nombre de last_login_ip tronquees ; '
        . 'ajouter le compteur `users=` dans le this->info() de AnonymizeOldIps. Sortie lue : ' . $sortie,
    );
});

// ═════════════════════════════════════════════════════════════════════════════
// B17-005 — la reecriture nocturne de TOUT l'historique
// ═════════════════════════════════════════════════════════════════════════════

test('B17-005 — la seconde nuit ne REECRIT PAS les lignes deja anonymisees', function () {
    // Le predicat etait `WHERE created_at < ? AND ip IS NOT NULL` : il ne
    // distingue pas une IP deja tronquee d'une IP brute. L'operation est
    // idempotente en VALEUR, elle ne l'etait pas en ECRITURE — chaque nuit a
    // 04:30 rejouait l'integralite de l'historique de plus de 30 jours.
    ligneAudit('192.168.42.123', now()->subDays(60));
    ligneAudit('2001:db8:1234:5678::1', now()->subDays(60));
    utilisateurAvecDerniereIp('198.51.100.42', now()->subDays(60));

    // NUIT 1 — le travail est fait, et il est compte.
    Artisan::call('rgpd:anonymize-ips');
    $nuit1 = Artisan::output();
    expect(str_contains($nuit1, 'audit_logs=2 '))->toBeTrue(
        'La premiere passe doit tronquer les 2 lignes anciennes. Sortie lue : ' . $nuit1,
    );
    expect(str_contains($nuit1, 'users=1 '))->toBeTrue(
        'La premiere passe doit tronquer la last_login_ip ancienne. Sortie lue : ' . $nuit1,
    );

    // NUIT 2 — plus rien a ecrire. C'EST LE CONSTAT B17-005 : sur le code
    // d'avant, cette seconde passe reaffichait `audit_logs=2`, c'est-a-dire la
    // reecriture integrale de lignes deja anonymisees.
    Artisan::call('rgpd:anonymize-ips');
    $nuit2 = Artisan::output();
    expect(str_contains($nuit2, 'audit_logs=0 '))->toBeTrue(
        'Une IP deja tronquee ne doit PAS etre reecrite : ajouter au WHERE '
        . '`AND ip <> host(network(set_masklen(ip::cidr, ...)))::inet`. Sortie lue : ' . $nuit2,
    );
    expect(str_contains($nuit2, 'users=0 '))->toBeTrue(
        'Meme exclusion attendue sur users.last_login_ip. Sortie lue : ' . $nuit2,
    );
});

test('B17-005 — TEMOIN : une IP BRUTE arrivee apres coup est bien rattrapee', function () {
    // Sans ce temoin, un predicat trop large (p. ex. « ne rien faire si la table
    // contient deja une IP tronquee ») passerait la garde precedente au vert en
    // laissant des IP brutes en base — l'exact contraire de l'objectif RGPD.
    ligneAudit('192.168.42.123', now()->subDays(60));
    Artisan::call('rgpd:anonymize-ips');

    $retardataire = ligneAudit('198.51.100.200', now()->subDays(90));
    Artisan::call('rgpd:anonymize-ips');
    $sortie = Artisan::output();

    expect(ipDeLaLigne($retardataire))->toBe('198.51.100.0');
    expect(str_contains($sortie, 'audit_logs=1 '))->toBeTrue(
        'La passe suivante doit tronquer LA SEULE ligne restee brute. Sortie lue : ' . $sortie,
    );
});

test('B17-005 — --dry-run annonce le volume REELLEMENT a ecrire, pas l historique', function () {
    ligneAudit('192.168.42.123', now()->subDays(60));
    Artisan::call('rgpd:anonymize-ips');

    // L'essai a blanc est la SEULE fenetre de l'exploitant sur le volume
    // nocturne. S'il continue a compter les lignes deja tronquees, personne ne
    // peut voir que la reecriture integrale a cesse.
    Artisan::call('rgpd:anonymize-ips', ['--dry-run' => true]);
    $sortie = Artisan::output();

    expect(str_contains($sortie, 'audit_logs=0 '))->toBeTrue(
        'Le --dry-run doit porter le MEME predicat que le chemin reel, exclusion '
        . 'B17-005 comprise. Sortie lue : ' . $sortie,
    );
});

// ═════════════════════════════════════════════════════════════════════════════
// B17-001 / H45-002 — l'essai a blanc qui ecrit
// ═════════════════════════════════════════════════════════════════════════════

test('B17-001 — retention:purge --dry-run n ECRIT RIEN, pas meme l UPDATE', function () {
    $espace = espaceAutomatisme();
    $utilisateur = utilisateurAutomatisme();

    $run = runScraperAncien($espace);
    notificationAncienne($espace, $utilisateur);
    DB::table('email_validations')->insert([
        'email' => 'expire-' . Str::random(6) . '@exemple.test',
        'status' => 'invalid',
        'checked_at' => now()->subDays(60),
        'expires_at' => now()->subDays(30),
    ]);

    $sortie = (function () use ($espace) {
        Artisan::call('retention:purge', ['--dry-run' => true, '--workspace' => $espace]);

        return Artisan::output();
    })();

    // LE DEFAUT : l'UPDATE des payloads part reellement, parce que sa reecriture
    // en SELECT COUNT(*) echoue faute de modificateur `s` dans le preg_replace.
    expect(DB::table('scraper_runs')->where('id', $run)->value('response_payload'))->not->toBeNull();
    expect(DB::table('scraper_runs')->where('id', $run)->value('payload_path'))->not->toBeNull();

    // TEMOIN NEGATIF — les deux DELETE de la MEME boucle etaient, eux,
    // correctement transformes en COUNT. Mesure jouee sur le code casse :
    // `notifications` = 1 ligne APRES le dry-run, `response_payload` = NULL.
    // S'ils avaient disparu aussi, le constat serait « la commande detruit tout
    // en dry-run » et non « seul l'UPDATE passe » : la garde distingue les deux,
    // donc elle mesure bien la faute nommee.
    expect(DB::table('notifications')->count())->toBe(1);

    // Meme temoin sur la table GLOBALE, qui n'est atteinte qu'avec la portee
    // totale (email_validations n'a pas de colonne d'espace).
    Artisan::call('retention:purge', ['--dry-run' => true, '--all-workspaces' => true]);
    expect(DB::table('email_validations')->count())->toBe(1);
    expect(DB::table('notifications')->count())->toBe(1);
    expect(DB::table('scraper_runs')->where('id', $run)->value('response_payload'))->not->toBeNull();

    // Sous-chaine SANS ACCENT : le message de la commande en porte.
    $this->assertStringContainsString(
        'RIEN',
        $sortie,
        'L essai a blanc doit DIRE qu il n a rien ecrit.',
    );
});

test('B17-001 — TEMOIN POSITIF : sans --dry-run, la purge FAIT son travail', function () {
    $espace = espaceAutomatisme();
    $utilisateur = utilisateurAutomatisme();

    $run = runScraperAncien($espace);
    notificationAncienne($espace, $utilisateur);

    // Sans ce temoin, une garde qui casserait la commande (par exemple en la
    // faisant sortir avant toute requete) passerait au vert sur le test
    // precedent — et la retention RGPD ne serait plus appliquee du tout.
    Artisan::call('retention:purge', ['--workspace' => $espace, '--force' => true]);

    expect(DB::table('scraper_runs')->where('id', $run)->value('response_payload'))->toBeNull();
    expect(DB::table('scraper_runs')->where('id', $run)->value('payload_path'))->toBeNull();
    expect(DB::table('notifications')->count())->toBe(0);
    // La ligne de run SURVIT : la retention efface la charge utile, pas la meta.
    expect(DB::table('scraper_runs')->where('id', $run)->exists())->toBeTrue();
});

// ═════════════════════════════════════════════════════════════════════════════
// B11-003 — la purge sans filtre d'espace
// ═════════════════════════════════════════════════════════════════════════════

test('B11-003 — retention:purge REFUSE de purger sans portee explicite', function () {
    $a = espaceAutomatisme('a');
    $b = espaceAutomatisme('b');
    $utilisateur = utilisateurAutomatisme();

    $runA = runScraperAncien($a);
    $runB = runScraperAncien($b);
    notificationAncienne($a, $utilisateur);
    notificationAncienne($b, $utilisateur);

    $code = Artisan::call('retention:purge', ['--force' => true]);
    $sortie = Artisan::output();

    // Purger « tous les espaces a la fois » doit rester un geste ECRIT, pas le
    // comportement d'un artisan lance sans y penser.
    expect($code)->not->toBe(0);
    expect(DB::table('scraper_runs')->where('id', $runA)->value('response_payload'))->not->toBeNull();
    expect(DB::table('scraper_runs')->where('id', $runB)->value('response_payload'))->not->toBeNull();
    expect(DB::table('notifications')->count())->toBe(2);
    $this->assertStringContainsString('REFUS', $sortie, 'Le refus doit etre dit, pas silencieux.');
});

test('B11-003 — retention:purge --workspace ne touche QUE l espace vise', function () {
    $a = espaceAutomatisme('a');
    $b = espaceAutomatisme('b');
    $utilisateur = utilisateurAutomatisme();

    $runA = runScraperAncien($a);
    $runB = runScraperAncien($b);
    notificationAncienne($a, $utilisateur);
    notificationAncienne($b, $utilisateur);

    Artisan::call('retention:purge', ['--workspace' => $a, '--force' => true]);

    // L'espace vise est purge...
    expect(DB::table('scraper_runs')->where('id', $runA)->value('response_payload'))->toBeNull();
    // ...et le VOISIN est intact. C'est tout le constat B11-003.
    expect(DB::table('scraper_runs')->where('id', $runB)->value('response_payload'))->not->toBeNull();
    expect(DB::table('notifications')->where('workspace_id', $a)->count())->toBe(0);
    expect(DB::table('notifications')->where('workspace_id', $b)->count())->toBe(1);
});

test('B11-003 — --all-workspaces purge bien les DEUX espaces (temoin)', function () {
    $a = espaceAutomatisme('a');
    $b = espaceAutomatisme('b');
    $utilisateur = utilisateurAutomatisme();

    $runA = runScraperAncien($a);
    $runB = runScraperAncien($b);
    notificationAncienne($a, $utilisateur);
    notificationAncienne($b, $utilisateur);

    Artisan::call('retention:purge', ['--all-workspaces' => true, '--force' => true]);

    // Sans ce temoin, une commande qui refuserait TOUT passerait les deux gardes
    // precedentes — et la retention ne s'appliquerait jamais.
    expect(DB::table('scraper_runs')->where('id', $runA)->value('response_payload'))->toBeNull();
    expect(DB::table('scraper_runs')->where('id', $runB)->value('response_payload'))->toBeNull();
    expect(DB::table('notifications')->count())->toBe(0);
});

test('B11-003 — la ligne PLANIFIEE de retention:purge porte sa portee, et elle marche', function () {
    $espace = espaceAutomatisme();
    $utilisateur = utilisateurAutomatisme();
    $run = runScraperAncien($espace);
    notificationAncienne($espace, $utilisateur);

    // Rendre la portee obligatoire aurait pu FIGER la tache quotidienne : une
    // commande qui refuse tous les jours a 04:00 est un pire defaut que celui
    // qu'on repare. Cette garde joue la ligne planifiee TELLE QU'ELLE EST
    // ECRITE dans routes/console.php.
    $planifiee = evenementPlanifie('retention:purge');
    expect($planifiee)->not->toBeNull();
    $this->assertStringContainsString('--all-workspaces', (string) $planifiee->command);
    $this->assertStringContainsString('--force', (string) $planifiee->command);

    $code = Artisan::call('retention:purge', ['--all-workspaces' => true, '--force' => true]);

    expect($code)->toBe(0);
    expect(DB::table('scraper_runs')->where('id', $run)->value('response_payload'))->toBeNull();
    expect(DB::table('notifications')->count())->toBe(0);
});

// ═════════════════════════════════════════════════════════════════════════════
// B17-009 — les purges RGPD qui ne s'executent jamais
// ═════════════════════════════════════════════════════════════════════════════

/** Retrouve un evenement planifie par le fragment de commande qu'il porte. */
function evenementPlanifie(string $fragment): ?Event
{
    // Force le chargement de routes/console.php (charge paresseusement par le
    // noyau console) avant d'interroger l'ordonnanceur.
    Artisan::call('list', ['--format' => 'txt']);

    foreach (app(Schedule::class)->events() as $evenement) {
        if (str_contains((string) $evenement->command, $fragment)) {
            return $evenement;
        }
    }

    return null;
}

test('B17-009 — les deux purges RGPD sont bien PLANIFIEES', function () {
    expect(evenementPlanifie('rgpd:purge-vivier'))->not->toBeNull();
    expect(evenementPlanifie('rgpd:purge-business-prospects'))->not->toBeNull();
    // TEMOIN : le lecteur d'ordonnanceur trouve aussi une commande dont on sait
    // qu'elle est planifiee ; s'il rendait null pour tout, les deux lignes
    // ci-dessus ne prouveraient rien.
    expect(evenementPlanifie('retention:purge'))->not->toBeNull();
});

test('B17-009 — drapeau ferme : la purge est sautee, mais elle le DIT', function () {
    config()->set('crm.purges_enabled', false);

    $evenement = evenementPlanifie('rgpd:purge-vivier');
    $journal = [];
    Log::listen(function ($message) use (&$journal) {
        $journal[] = $message->message;
    });

    expect($evenement->filtersPass(app()))->toBeFalse();

    // 🔴 LE CONSTAT B17-009 : le saut etait SILENCIEUX. Une echeance legale
    // suspendue sans la moindre trace est indistinguable d'une echeance tenue.
    $this->assertStringContainsString(
        'CRM_PURGE_ENABLED',
        implode("\n", $journal),
        'Le saut d une purge RGPD doit laisser une trace nommant le drapeau qui la retient.',
    );
});

test('B17-009 — TEMOIN : drapeau ouvert, la purge N EST PLUS sautee', function () {
    config()->set('crm.purges_enabled', true);

    // Ce temoin prouve que le cablage de l'ordonnanceur est bon et qu'il ne
    // reste qu'UNE variable d'environnement entre le depot et l'echeance CNIL.
    expect(evenementPlanifie('rgpd:purge-vivier')->filtersPass(app()))->toBeTrue();
    expect(evenementPlanifie('rgpd:purge-business-prospects')->filtersPass(app()))->toBeTrue();
});

// ═════════════════════════════════════════════════════════════════════════════
// B15-007 — l'en-tete annoncait deux purges que personne n'executait
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Cette garde ne joue AUCUNE purge : elle lit le fichier comme un texte. C'est
 * exprès. Le defaut B15-007 n'etait pas dans le comportement — les trois taches
 * qui tournent tournent bien — mais dans l'ECART entre ce que l'en-tete promet
 * et ce que `handle()` fait. Un test qui execute la commande ne peut pas voir
 * cet ecart : il ne lit pas la promesse.
 *
 * Mesure du 2026-08-22, avant correctif : l'en-tete annoncait CINQ politiques,
 * `handle()` en jouait TROIS. `audit_logs > 24 mois` et `llm_usage > 12 mois`
 * n'apparaissaient nulle part ailleurs que dans cet en-tete — une relecture
 * RGPD qui s'y fiait concluait a tort que les deux tables etaient purgees.
 */
test('B15-007 — l en-tete de retention:purge n annonce QUE les purges que handle() joue', function () {
    $chemin = app_path('Console/Commands/RetentionPurge.php');
    $source = (string) file_get_contents($chemin);

    // Ce que l'en-tete ANNONCE : les lignes balisees `[purge] <table>`.
    preg_match_all('/^\s*\*\s*\[purge\]\s+([a-z_]+)/m', $source, $annonces);
    $annoncees = array_values(array_unique($annonces[1]));
    sort($annoncees);

    // Ce que le corps JOUE : le 2e argument de `purger()` est le nom de table.
    preg_match_all('/\$this->purger\(\s*\'[^\']*\',\s*\'([a-z_]+)\'/', $source, $appels);
    $jouees = array_values(array_unique($appels[1]));
    sort($jouees);

    // TEMOIN DE LECTURE — sans lui, un fichier renomme ou une balise changee
    // rendrait deux listes VIDES, donc egales, et la garde certifierait le vide.
    expect(count($jouees) > 0)->toBeTrue(
        'B15-007 : aucun appel a purger() reconnu dans ' . $chemin . '. Soit la commande '
        . 'ne purge plus rien, soit la signature de purger() a change et cette garde ne '
        . 'lit plus rien. GESTE : verifier handle() puis, si la signature a bouge, '
        . 'adapter la regexp de ce test — ne PAS le supprimer.'
    );
    expect(count($annoncees) > 0)->toBeTrue(
        'B15-007 : aucune ligne `[purge] <table>` trouvee dans l en-tete de ' . $chemin
        . '. GESTE : remettre dans le docblock une ligne `[purge] <table> -> <effet>` par '
        . 'tache reellement jouee par handle().'
    );

    expect($annoncees === $jouees)->toBeTrue(
        'B15-007 : l en-tete de RetentionPurge.php et handle() ne disent plus la meme chose. '
        . 'ANNONCE : [' . implode(', ', $annoncees) . '] — JOUE : [' . implode(', ', $jouees) . ']. '
        . 'C est exactement le defaut mesure le 2026-08-22 (cinq politiques annoncees, trois '
        . 'jouees). GESTE : si tu viens d ajouter une purge, ajoute sa ligne `[purge] <table>` '
        . 'dans le docblock ; si tu viens d en retirer une, retire sa ligne. Ne laisse jamais '
        . 'l en-tete promettre une purge que personne n execute : une relecture RGPD s y fie.'
    );
});

test('B15-007 — audit_logs et llm_usage ne sont purges par personne, et le fichier le DIT', function () {
    $source = (string) file_get_contents(app_path('Console/Commands/RetentionPurge.php'));

    preg_match_all('/\$this->purger\(\s*\'[^\']*\',\s*\'([a-z_]+)\'/', $source, $appels);
    $jouees = $appels[1];

    // `audit_logs` est un journal SCELLE par chaine de hachage (CorrigerHorodatages
    // l ecarte pour cette raison) et ses vieilles partitions relevent de pg_partman.
    // `llm_usage` n a jamais eu de destination d archivage decidee. Les deux sont
    // donc hors de cette commande PLANIFIEE tous les jours a 04:00 — et le jour ou
    // quelqu un les y met, ce doit etre une decision ECRITE, pas un ajout de ligne.
    foreach (['audit_logs', 'llm_usage'] as $table) {
        expect(in_array($table, $jouees, true))->toBeFalse(
            'B15-007 : `' . $table . '` est desormais purgee par retention:purge, qui tourne '
            . 'chaque jour a 04:00 sur TOUS les espaces. Pour audit_logs c est une suppression '
            . 'de lignes dans un journal scelle par chaine de hachage — elle en rompt le '
            . 'chainage ; pour llm_usage aucune destination d archivage n a jamais ete '
            . 'decidee. GESTE : STOP & ASK Will et un ADR avant d activer l une des deux ; '
            . 'ensuite seulement, retirer la table de cette liste.'
        );
    }

    // Et l en-tete doit continuer a DIRE pourquoi elles n y sont pas : c est ce
    // silence-la qui avait laisse croire pendant deux ans qu elles etaient purgees.
    foreach (['audit_logs', 'llm_usage'] as $table) {
        expect(str_contains($source, $table))->toBeTrue(
            'B15-007 : `' . $table . '` a disparu de RetentionPurge.php. La note qui explique '
            . 'pourquoi cette table n est PAS purgee ici a ete effacee — le prochain lecteur '
            . 'croira a un oubli et l implementera. GESTE : restaurer la rubrique B15-007 de '
            . 'l en-tete.'
        );
    }
});

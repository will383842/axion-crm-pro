<?php

/**
 * LES PURGES AUTOMATIQUES — celles qui detruisent, et celles qui ne tournent jamais.
 *
 * Audit 360, lot « Automatismes ». Quatre constats, une seule famille de fautes :
 * une purge que PERSONNE n'a jamais executee en test.
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

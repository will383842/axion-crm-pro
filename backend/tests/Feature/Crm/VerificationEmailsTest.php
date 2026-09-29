<?php

/**
 * VÉRIFICATION DES E-MAILS — `crm:emails:verifier` (2026-09-30).
 *
 * Le DNS est SIMULÉ (`Tests\Support\ResolveurDnsSimule`) : aucune requête ne
 * quitte la suite. Fixtures FICTIVES (dépôt public) : domaines `*.example`.
 */

use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\Dns\ResolveurDnsInterdit;
use App\Crm\Emails\Dns\ResultatDns;
use App\Crm\Emails\VerificationEmail;
use App\Crm\FichesProtegees;
use App\Models\Workspace;
use App\Providers\AppServiceProvider;
use App\Services\Waterfall\WaterfallOrchestrator;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const VEM_ANCIEN = '2026-01-15 10:00:00';

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-vem-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ vérification e-mails']);
    config(['crm.ingest.business_workspace' => $this->slug]);

    $this->dns = new ResolveurDnsSimule([
        'zz-recoit.example' => ResultatDns::MX,
        'zz-par-a.example' => ResultatDns::A,
        'zz-mort.example' => ResultatDns::INEXISTANT,
        'zz-muet.example' => ResultatDns::SANS_COURRIER,
        'zz-panne.example' => ResultatDns::INDETERMINE,
        'gmail.com' => ResultatDns::MX,
    ], ResultatDns::INEXISTANT);
    app()->instance(ResolveurDns::class, $this->dns);

    $this->a = vemFiche($this, 'ZZ A', 'contact@zz-recoit.example', [
        'contact_channels' => [
            'emails' => ['bureau@zz-par-a.example', 'Jean.ZZ@zz-mort.example'],
            'details' => ['jean.zz@zz-mort.example' => [
                'type' => 'nominatif', 'domaine_verifie' => true, 'verifie_le' => '2026-09-01', 'source' => 'federations-2026',
            ]],
        ],
        'autre_cle' => ['garde' => true],
    ]);
    $this->b = vemFiche($this, 'ZZ B', 'contact@zz-recoit.example');
    $this->jetable = vemFiche($this, 'ZZ Jetable', 'zz@yopmail.com');
    $this->syntaxe = vemFiche($this, 'ZZ Syntaxe', 'pas une adresse');
    $this->webmail = vemFiche($this, 'ZZ Webmail', 'zz.quelquun@gmail.com');
    $this->muet = vemFiche($this, 'ZZ Muet', 'contact@zz-muet.example');

    $this->pro = vemContact($this, $this->a, 'ZZ Pro', 'pro@zz-recoit.example');
    $this->hunter = vemContact($this, $this->a, 'ZZ Hunter', 'hunter@zz-mort.example', ['email_status' => 'valid']);
    $this->rebond = vemContact($this, $this->a, 'ZZ Rebond', 'rebond@zz-recoit.example', ['email_status' => 'invalid']);
    $this->partage = vemContact($this, $this->b, 'ZZ Partage', 'contact@zz-recoit.example', ['email_status' => 'unknown']);
    $this->panne = vemContact($this, $this->b, 'ZZ Panne', 'panne@zz-panne.example', ['email_status' => 'unknown']);
});

/** @param  array<string, mixed>|null  $signals */
function vemFiche(object $t, string $nom, ?string $email, ?array $signals = null): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $t->espace, 'siren' => (string) random_int(900000000, 999999999), 'denomination' => $nom,
        'email_generic' => $email, 'signals' => json_encode($signals ?? new stdClass),
        'created_at' => VEM_ANCIEN, 'updated_at' => VEM_ANCIEN,
    ]);
}

/** @param  array<string, mixed>  $attrs */
function vemContact(object $t, int $companyId, string $nom, string $email, array $attrs = []): int
{
    return (int) DB::table('contacts')->insertGetId(array_merge([
        'workspace_id' => $t->espace, 'company_id' => $companyId, 'last_name' => $nom, 'email' => $email,
        'created_at' => VEM_ANCIEN, 'updated_at' => VEM_ANCIEN,
    ], $attrs));
}

/**
 * @param  array<string, mixed>  $options
 * @return array{code: int, sortie: string}
 */
function vemVerifier(array $options = []): array
{
    $code = Artisan::call('crm:emails:verifier', $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function vemCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);
    expect($m)->not->toBeEmpty("Compteur « {$cle} » absent de la sortie.");

    return (int) $m[1];
}

/** @return array<string, mixed> */
function vemSignals(int $id): array
{
    return (array) json_decode((string) DB::table('companies')->where('id', $id)->value('signals'), true);
}

/** @return array<string, mixed> */
function vemGenerique(int $id): array
{
    return (array) (vemSignals($id)['email_generic_verification'] ?? []);
}

/** @return array<string, mixed> */
function vemMeta(int $id): array
{
    return (array) json_decode((string) DB::table('contacts')->where('id', $id)->value('metadata'), true);
}

// ── Le verdict, écrit, daté, motivé ─────────────────────────────────────────

test('chaque adresse recoit un statut, une date et un motif ; une invalide reste lisible sur sa fiche', function () {
    $r = vemVerifier();

    expect($r['code'])->toBe(0);
    $gen = vemGenerique($this->a);
    expect($gen)->toMatchArray([
        'statut' => 'valide', 'motif' => 'mx', 'domaine_verifie' => true, 'type' => 'generique', 'webmail' => false,
        'verifie_par' => VerificationEmail::SOURCE, 'empreinte' => VerificationEmail::empreinte('contact@zz-recoit.example'),
    ])->and($gen['verifie_le'])->toBe(now()->toDateString());

    // Domaine sans MX mais avec une adresse : il reçoit (MX implicite).
    $canaux = vemSignals($this->a)['contact_channels'];
    expect($canaux['details']['bureau@zz-par-a.example'])->toMatchArray(['statut' => 'valide', 'motif' => 'a'])
        // Domaine inexistant : invalide… et l'adresse est TOUJOURS là.
        ->and($canaux['details']['jean.zz@zz-mort.example'])->toMatchArray(['statut' => 'invalide', 'motif' => 'inexistant', 'domaine_verifie' => false])
        ->and($canaux['emails'])->toBe(['bureau@zz-par-a.example', 'Jean.ZZ@zz-mort.example'])
        ->and(vemSignals($this->a)['autre_cle'])->toBe(['garde' => true])
        ->and(vemGenerique($this->muet))->toMatchArray(['statut' => 'invalide', 'motif' => 'sans_courrier'])
        ->and(DB::table('companies')->where('id', $this->muet)->value('email_generic'))->toBe('contact@zz-muet.example')
        ->and(DB::table('companies')->where('id', $this->syntaxe)->value('email_generic'))->toBe('pas une adresse')
        ->and(vemGenerique($this->syntaxe))->toMatchArray(['statut' => 'invalide', 'motif' => 'syntaxe'])
        ->and(vemGenerique($this->jetable))->toMatchArray(['statut' => 'jetable', 'motif' => 'jetable']);

    expect(DB::table('contacts')->where('id', $this->hunter)->value('email'))->toBe('hunter@zz-mort.example')
        ->and(DB::table('contacts')->where('id', $this->hunter)->value('email_status'))->toBe('invalid')
        ->and(vemMeta($this->hunter)['email_verification'])->toMatchArray(['statut' => 'invalide', 'motif' => 'inexistant'])
        ->and(DB::table('contacts')->where('id', $this->pro)->value('email_status'))->toBe('valid')
        ->and(DB::table('contacts')->where('id', $this->pro)->value('last_verified_at'))->not->toBeNull();

    expect(vemCompteur($r['sortie'], 'valides'))->toBeGreaterThan(0)
        ->and(vemCompteur($r['sortie'], 'motif_inexistant'))->toBe(2)
        ->and(vemCompteur($r['sortie'], 'motif_syntaxe'))->toBe(1);
});

test('le vocabulaire de l import des federations est repris : provenance et type gardes, cles tenues a jour', function () {
    vemVerifier();

    $detail = vemSignals($this->a)['contact_channels']['details']['jean.zz@zz-mort.example'];
    expect($detail['source'])->toBe('federations-2026')
        ->and($detail['type'])->toBe('nominatif')
        ->and($detail['domaine_verifie'])->toBeFalse()
        ->and($detail['verifie_le'])->toBe(now()->toDateString());

    // Une personne : les clés #255 (`email_type`, `domaine_verifie`,
    // `domaine_verifie_le`) suivent la vérification.
    expect(vemMeta($this->pro))->toMatchArray(['email_type' => 'nominatif', 'domaine_verifie' => true, 'domaine_verifie_le' => now()->toDateString()])
        ->and(vemMeta($this->hunter))->toMatchArray(['domaine_verifie' => false, 'domaine_verifie_le' => null]);
});

test('webmail marque et non rejete ; adresse partagee : nombre de fiches', function () {
    vemVerifier();

    expect(vemGenerique($this->webmail))->toMatchArray(['statut' => 'valide', 'webmail' => true])
        // `contact@zz-recoit.example` : fiches A et B, et le contact « Partage ».
        ->and(vemGenerique($this->a)['fiches'])->toBe(3)
        ->and(vemGenerique($this->b)['fiches'])->toBe(3)
        ->and(vemMeta($this->partage)['email_verification']['fiches'])->toBe(3)
        ->and(vemMeta($this->pro)['email_verification']['fiches'])->toBe(1);
});

test('un rebond dur n est jamais ressuscite par un domaine qui recoit', function () {
    vemVerifier();

    expect(DB::table('contacts')->where('id', $this->rebond)->value('email_status'))->toBe('invalid')
        ->and(vemMeta($this->rebond)['email_verification']['statut'])->toBe('valide');
});

test('une panne DNS ne rend RIEN invalide : l adresse reste telle quelle, et le domaine n est pas mis en cache', function () {
    $r = vemVerifier();

    expect(DB::table('contacts')->where('id', $this->panne)->value('email_status'))->toBe('unknown')
        ->and(vemMeta($this->panne))->not->toHaveKey('email_verification')
        ->and(vemCompteur($r['sortie'], 'indeterminees'))->toBe(1)
        ->and(DB::table('email_domaines')->where('domaine', 'zz-panne.example')->exists())->toBeFalse()
        ->and(DB::table('email_domaines')->where('domaine', 'zz-recoit.example')->value('verdict'))->toBe('mx');
});

test('vérifier n est pas modifier : updated_at des fiches et des personnes inchangé', function () {
    vemVerifier();

    // `muet` : une fiche sans personne (l'insertion d'une personne recalcule
    // le score de sa fiche, et donc son `updated_at`, avant même le test).
    expect(vemGenerique($this->muet)['statut'])->toBe('invalide')
        ->and(substr((string) DB::table('companies')->where('id', $this->muet)->value('updated_at'), 0, 19))->toBe(VEM_ANCIEN)
        ->and(substr((string) DB::table('contacts')->where('id', $this->hunter)->value('updated_at'), 0, 19))->toBe(VEM_ANCIEN)
        ->and(substr((string) DB::table('contacts')->where('id', $this->pro)->value('updated_at'), 0, 19))->toBe(VEM_ANCIEN);
});

// ── Le cache par domaine, l'idempotence, la reprise ─────────────────────────

test('un domaine n est resolu qu UNE fois, meme sur plusieurs lots et plusieurs executions ; revérifié apres N jours', function () {
    vemVerifier(['--lot' => '1']);
    $demandes = $this->dns->demandes;

    expect(array_count_values($demandes))->each->toBe(1)
        ->and($demandes)->toContain('zz-recoit.example', 'zz-mort.example', 'gmail.com')
        // Jetable et syntaxe fautive : jamais de DNS.
        ->and($demandes)->not->toContain('yopmail.com');

    vemVerifier();
    // Seul le domaine en panne est redemandé (un indéterminé n'est pas une réponse).
    expect(array_slice($this->dns->demandes, count($demandes)))->toBe(['zz-panne.example']);

    DB::table('email_domaines')->where('domaine', 'zz-recoit.example')->update(['resolu_le' => now()->subDays(3)]);
    $avant = count($this->dns->demandes);
    vemVerifier(['--revalider-apres' => '2']);

    expect(array_slice($this->dns->demandes, $avant))->toContain('zz-recoit.example')
        ->and(DB::table('email_domaines')->where('domaine', 'zz-recoit.example')->value('resolu_le'))->toBeGreaterThan(now()->subDay()->toDateTimeString());
});

test('idempotente : une seconde execution ne reecrit rien', function () {
    vemVerifier();
    $r = vemVerifier();

    expect(vemCompteur($r['sortie'], 'fiches_modifiees'))->toBe(0)
        ->and(vemCompteur($r['sortie'], 'contacts_modifies'))->toBe(0)
        ->and(vemCompteur($r['sortie'], 'valides'))->toBeGreaterThan(0);
});

test('le domaine revit : l invalid que la verification avait pose redevient valid', function () {
    vemVerifier();
    expect(DB::table('contacts')->where('id', $this->hunter)->value('email_status'))->toBe('invalid');

    $this->dns->repondre(['zz-mort.example' => ResultatDns::MX]);
    DB::table('email_domaines')->update(['resolu_le' => now()->subDays(40)]);
    vemVerifier();

    expect(DB::table('contacts')->where('id', $this->hunter)->value('email_status'))->toBe('valid')
        ->and(vemMeta($this->hunter)['email_verification'])->toMatchArray(['statut' => 'valide', 'motif' => 'mx']);
});

test('--seulement-jamais-verifies ne reprend que les adresses nouvelles', function () {
    vemVerifier();
    $nouveau = vemContact($this, $this->b, 'ZZ Nouveau', 'nouveau@zz-neuf.example');
    $this->dns->repondre(['zz-neuf.example' => ResultatDns::MX]);
    $avant = count($this->dns->demandes);

    $r = vemVerifier(['--seulement-jamais-verifies' => true, '--source' => 'contacts']);

    // Relus : le nouveau, et celui dont le domaine était en panne (jamais
    // vérifié) — toujours en panne, il reste tel quel.
    expect(vemCompteur($r['sortie'], 'contacts_lus'))->toBe(2)
        ->and(vemCompteur($r['sortie'], 'contacts_modifies'))->toBe(1)
        ->and(vemCompteur($r['sortie'], 'indeterminees'))->toBe(1)
        ->and(DB::table('contacts')->where('id', $nouveau)->value('email_status'))->toBe('valid')
        ->and(array_slice($this->dns->demandes, $avant))->toEqualCanonicalizing(['zz-neuf.example', 'zz-panne.example']);
});

test('une fiche modifiee entre la lecture et l ecriture n est pas ecrasee', function () {
    // Pendant la résolution du lot (après sa lecture), un rebond dur arrive.
    $this->dns->pendant = function (): void {
        DB::table('contacts')->where('id', $this->pro)->update(['email_status' => 'invalid']);
    };

    $r = vemVerifier(['--source' => 'contacts']);

    expect(DB::table('contacts')->where('id', $this->pro)->value('email_status'))->toBe('invalid')
        ->and(vemMeta($this->pro))->not->toHaveKey('email_verification')
        ->and(vemCompteur($r['sortie'], 'modifiees_entre_temps'))->toBe(1);
});

test('une ORGANISATION modifiee entre la lecture et l ecriture n est pas ecrasee', function () {
    // Pendant la résolution du lot, un import réécrit les `signals` de A.
    $this->dns->pendant = function (): void {
        DB::table('companies')->where('id', $this->a)
            ->update(['signals' => DB::raw("jsonb_set(signals, '{autre_cle}', '{\"garde\": \"import concurrent\"}'::jsonb)")]);
    };

    $r = vemVerifier(['--source' => 'entreprises']);

    expect(vemSignals($this->a)['autre_cle'])->toBe(['garde' => 'import concurrent'])
        ->and(vemSignals($this->a))->not->toHaveKey('email_generic_verification')
        ->and(vemCompteur($r['sortie'], 'modifiees_entre_temps'))->toBe(1)
        // Les autres fiches du lot, elles, sont écrites.
        ->and(vemGenerique($this->b)['statut'])->toBe('valide');
});

test('a blanc : rien n est ecrit — fiches, cache, audit — et le bilan annonce ce que le reel fera', function () {
    $avant = [
        DB::table('companies')->where('workspace_id', $this->espace)->pluck('signals', 'id')->all(),
        DB::table('contacts')->where('workspace_id', $this->espace)->get(['id', 'email_status', 'metadata', 'last_verified_at'])->toArray(),
        DB::table('email_domaines')->count(),
        DB::table('audit_logs')->count(),
    ];

    $blanc = vemVerifier(['--dry-run' => true]);

    expect([
        DB::table('companies')->where('workspace_id', $this->espace)->pluck('signals', 'id')->all(),
        DB::table('contacts')->where('workspace_id', $this->espace)->get(['id', 'email_status', 'metadata', 'last_verified_at'])->toArray(),
        DB::table('email_domaines')->count(),
        DB::table('audit_logs')->count(),
    ])->toEqual($avant);

    $reel = vemVerifier();
    foreach (['fiches' => 'fiches_a_modifier', 'contacts' => 'contacts_a_modifier', 'statuts' => 'statuts_contacts_changes', 'valides' => 'valides', 'invalides' => 'invalides'] as $cle) {
        expect(vemCompteur($blanc['sortie'], $cle))->toBe(vemCompteur($reel['sortie'], $cle), "à blanc ≠ réel sur « {$cle} »");
    }
    expect(vemCompteur($reel['sortie'], 'fiches_modifiees'))->toBe(vemCompteur($reel['sortie'], 'fiches_a_modifier'))
        ->and(vemCompteur($reel['sortie'], 'fiches_a_modifier'))->toBeGreaterThan(0);
});

test('le journal et l ecran ne citent aucune adresse', function () {
    $r = vemVerifier();

    expect($r['sortie'])->not->toContain('@')
        ->and(DB::table('audit_logs')->where('event_type', 'VERIFICATION_EMAILS_LOT')->count())->toBeGreaterThan(0)
        ->and(DB::table('audit_logs')->where('event_type', 'VERIFICATION_EMAILS_FIN')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event_type', 'like', 'VERIFICATION_EMAILS%')->where('path', 'like', '%@%')->exists())->toBeFalse();
});

// ── Les gardes d'exploitation ───────────────────────────────────────────────

test('en test, sans resolveur simule, la commande REFUSE le reseau et n ecrit rien', function () {
    app()->forgetInstance(ResolveurDns::class);
    app()->offsetUnset(ResolveurDns::class);
    // La liaison du fournisseur de services, telle que la suite la reçoit.
    (new AppServiceProvider(app()))->register();

    expect(app(ResolveurDns::class))->toBeInstanceOf(ResolveurDnsInterdit::class);

    $code = 0;
    try {
        $code = Artisan::call('crm:emails:verifier', ['--source' => 'contacts']);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('refusée');
        $code = 1;
    }

    expect($code)->toBe(1)
        ->and(DB::table('email_domaines')->count())->toBe(0)
        ->and(vemMeta($this->pro))->not->toHaveKey('email_verification');
});

test('aucun sondage SMTP : ni la commande ni ses classes ne parlent a un serveur de messagerie', function () {
    $fichiers = array_merge(
        [app_path('Console/Commands/CrmEmailsVerifier.php')],
        glob(app_path('Crm/Emails/*.php')) ?: [],
        glob(app_path('Crm/Emails/Dns/*.php')) ?: [],
    );
    // TÉMOIN de couverture : les neuf fichiers sont bien lus.
    expect(count($fichiers))->toBe(9);
    foreach ($fichiers as $f) {
        // Le CODE seul : les commentaires, eux, ont le droit d'expliquer
        // pourquoi on ne sonde pas (« pas de RCPT TO »).
        $code = '';
        foreach (token_get_all((string) file_get_contents($f)) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($jeton) ? $jeton[1] : $jeton;
        }
        foreach (['RCPT', 'EHLO', 'HELO', 'MAIL FROM', 'SmtpProber', 'HunterEmailVerifier', 'fsockopen', 'tcp://', 'getmxrr'] as $interdit) {
            expect(stripos($code, $interdit))->toBeFalse("« {$interdit} » dans le code de " . basename($f));
        }
        expect(preg_match('/[,:(]\s*25\s*[,)]/', $code))->toBe(0, 'port 25 dans ' . basename($f));
    }
});

test('--lot et --source hors bornes sont refuses', function () {
    expect(vemVerifier(['--lot' => '0'])['code'])->toBe(1)
        ->and(vemVerifier(['--lot' => '2001'])['code'])->toBe(1)
        ->and(vemVerifier(['--source' => 'journalistes'])['code'])->toBe(1)
        ->and(vemVerifier(['--revalider-apres' => '0'])['code'])->toBe(1)
        ->and($this->dns->demandes)->toBe([]);
});

test('la planification hebdomadaire existe, et elle est SAUTEE par defaut', function () {
    Artisan::call('list', ['--format' => 'txt']);
    $taches = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $e): bool => str_contains((string) $e->command, 'crm:emails:verifier'),
    ));

    expect($taches)->toHaveCount(1)
        ->and($taches[0]->expression)->toBe('0 5 * * 0');

    config(['crm.emails_verification.planifiee' => false]);
    expect($taches[0]->filtersPass(app()))->toBeFalse();

    config(['crm.emails_verification.planifiee' => true]);
    expect($taches[0]->filtersPass(app()))->toBeTrue();
});

// ── Relecture de la PR #261 ─────────────────────────────────────────────────

test('E1 — rebond dur, verification pendant une panne du domaine, domaine revenu : toujours invalid ; un catchall revient catchall', function () {
    $rebond = vemContact($this, $this->b, 'ZZ Rebond Panne', 'rebond@zz-yoyo.example', ['email_status' => 'invalid']);
    $catchall = vemContact($this, $this->b, 'ZZ Catchall', 'tout@zz-yoyo.example', ['email_status' => 'catchall']);

    $this->dns->repondre(['zz-yoyo.example' => ResultatDns::INEXISTANT]);
    vemVerifier(['--source' => 'contacts']);
    expect(DB::table('contacts')->where('id', $rebond)->value('email_status'))->toBe('invalid')
        ->and(vemMeta($rebond)['email_verification']['statut'])->toBe('invalide')
        ->and(DB::table('contacts')->where('id', $catchall)->value('email_status'))->toBe('invalid')
        ->and(vemMeta($catchall)['email_verification']['email_status_avant'])->toBe('catchall');

    // Le domaine revient.
    $this->dns->repondre(['zz-yoyo.example' => ResultatDns::MX]);
    DB::table('email_domaines')->update(['resolu_le' => now()->subDays(40)]);
    vemVerifier(['--source' => 'contacts']);

    expect(DB::table('contacts')->where('id', $rebond)->value('email_status'))->toBe('invalid')
        ->and(vemMeta($rebond)['email_verification']['statut'])->toBe('valide')
        ->and(DB::table('contacts')->where('id', $catchall)->value('email_status'))->toBe('catchall');
});

test('E5 — la memoire DNS d une execution est bornee : videe, elle repasse par le cache (reel) ou le DNS (a blanc)', function () {
    config(['crm.emails_verification.memoire_domaines' => 1]);

    // À blanc (aucun cache écrit) : la mémoire vidée entre les lots fait
    // redemander `zz-recoit.example`, porté par les fiches A et B.
    $blanc = vemVerifier(['--dry-run' => true, '--lot' => '1', '--source' => 'entreprises']);
    expect(vemCompteur($blanc['sortie'], 'memoire_dns_videe'))->toBeGreaterThan(0)
        ->and(array_count_values($this->dns->demandes)['zz-recoit.example'])->toBeGreaterThan(1);

    // En réel, le cache en base prend le relais : une seule question au DNS.
    $avant = count($this->dns->demandes);
    vemVerifier(['--lot' => '1', '--source' => 'entreprises']);
    expect(array_count_values(array_slice($this->dns->demandes, $avant))['zz-recoit.example'] ?? 0)->toBe(1);
});

test('E6 — un resolveur qui dit que le domaine temoin n existe pas est REFUSE avant toute lecture', function () {
    $this->dns->verdictTemoin = ResultatDns::INEXISTANT;

    $r = vemVerifier();

    expect($r['code'])->toBe(1)
        ->and($r['sortie'])->toContain('REFUS')
        ->and($this->dns->demandes)->toBe([])
        ->and(DB::table('email_domaines')->count())->toBe(0)
        ->and(vemMeta($this->pro))->not->toHaveKey('email_verification');
});

test('E6 — un lot aux domaines anormalement « inexistants » rejuge le resolveur ; s il se trompe, le lot est annule', function () {
    for ($i = 1; $i <= 25; $i++) {
        vemContact($this, $this->b, "ZZ Mort {$i}", "p{$i}@zz-disparu-{$i}.example");
    }
    // Le résolveur tombe en panne APRÈS le témoin du démarrage.
    $this->dns->pendant = function (): void {
        $this->dns->verdictTemoin = ResultatDns::INEXISTANT;
    };

    $r = vemVerifier(['--source' => 'contacts']);

    expect($r['code'])->toBe(1)
        ->and($r['sortie'])->toContain('résolveur suspect')
        ->and($r['sortie'])->toContain('Reprendre avec : --source=contacts --depuis-id=0')
        ->and(DB::table('email_domaines')->count())->toBe(0)
        ->and(DB::table('contacts')->where('workspace_id', $this->espace)->whereNotNull('last_verified_at')->count())->toBe(0);
});

test('E6 — TEMOIN : des domaines vraiment morts, avec un resolveur sain, sont bien ecrits invalides', function () {
    for ($i = 1; $i <= 25; $i++) {
        vemContact($this, $this->b, "ZZ Mort {$i}", "p{$i}@zz-disparu-{$i}.example");
    }

    $r = vemVerifier(['--source' => 'contacts']);

    expect($r['code'])->toBe(0)
        ->and(vemCompteur($r['sortie'], 'resolveur_rejuge'))->toBe(1)
        ->and(DB::table('contacts')->where('email', 'like', '%@zz-disparu-%')->where('email_status', 'invalid')->count())->toBe(25);
});

test('S2 — retention:purge retire les domaines resolus il y a trop longtemps, et eux seuls', function () {
    vemVerifier();
    DB::table('email_domaines')->where('domaine', 'zz-mort.example')->update(['resolu_le' => now()->subDays(200)]);
    $total = DB::table('email_domaines')->count();

    Artisan::call('retention:purge', ['--dry-run' => true, '--all-workspaces' => true]);
    expect(DB::table('email_domaines')->count())->toBe($total);

    Artisan::call('retention:purge', ['--all-workspaces' => true, '--force' => true]);
    expect(DB::table('email_domaines')->where('domaine', 'zz-mort.example')->exists())->toBeFalse()
        ->and(DB::table('email_domaines')->count())->toBe($total - 1);
});

test('E2 — l enrichissement ne remplace jamais une adresse verifiee, ni celle d une fiche protegee', function () {
    vemVerifier(['--source' => 'contacts']);
    // Hors protection, jamais vérifiée, invalide : l'enrichissement peut chercher.
    $libre = vemContact($this, $this->b, 'ZZ Libre', 'libre@zz-libre.example', ['email_status' => 'invalid']);
    // Sans adresse : toujours cherchée.
    $sansAdresse = (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $this->a, 'last_name' => 'ZZ Sans', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // La fiche B devient PROTÉGÉE.
    $tag = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAG_FEDERATIONS, 'name' => 'ZZ',
        'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert(['company_id' => $this->b, 'tag_id' => $tag, 'workspace_id' => $this->espace, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);

    $surA = WaterfallOrchestrator::contactsARechercher($this->a)->pluck('id')->all();
    $surB = WaterfallOrchestrator::contactsARechercher($this->b)->pluck('id')->all();

    // `hunter` (fiche A, non protégée) : `invalid` posé par la vérification → son adresse est gardée.
    expect($surA)->toContain($sansAdresse)
        ->and($surA)->not->toContain($this->hunter)
        ->and($surB)->not->toContain($libre);

    // TÉMOIN : la même personne, fiche NON protégée, est bien cherchée.
    DB::table('company_tag')->where('company_id', $this->b)->delete();
    expect(WaterfallOrchestrator::contactsARechercher($this->b)->pluck('id')->all())->toContain($libre);
});

// ── 2e relecture de la PR #261 ──────────────────────────────────────────────

/** Joue `crm:campagne:retours` sur une ligne. */
function vemRetour(string $type, string $email): void
{
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-vem-retours-');
    file_put_contents($fichier, json_encode(['type' => $type, 'email' => $email, 'campagne' => 'zz']) . "\n");
    try {
        Artisan::call('crm:campagne:retours', ['file' => $fichier]);
    } finally {
        @unlink($fichier);
    }
}

test('E1 ORDRE INVERSE — invalid pose par la verification, PUIS rebond dur (meme valeur), domaine revenu : toujours invalid', function () {
    $rebond = vemContact($this, $this->b, 'ZZ Inverse', 'inverse@zz-yoyo.example');
    $oppose = vemContact($this, $this->b, 'ZZ Oppose', 'oppose@zz-yoyo.example');
    // TÉMOIN : même histoire, sans rebond ni opposition.
    $temoin = vemContact($this, $this->b, 'ZZ Temoin', 'temoin@zz-yoyo.example');

    // 1. Panne du domaine : la vérification pose `invalid` (à elle).
    $this->dns->repondre(['zz-yoyo.example' => ResultatDns::INEXISTANT]);
    vemVerifier(['--source' => 'contacts']);
    expect(vemMeta($rebond)['email_verification']['email_status_pose'])->toBe('invalid');

    // 2. Un rebond dur réel, par la commande des retours : elle réécrit
    //    `invalid` — LA MÊME VALEUR. Et une opposition sur l'autre adresse.
    vemRetour('rebond_dur', 'inverse@zz-yoyo.example');
    vemRetour('desinscription', 'oppose@zz-yoyo.example');
    expect(DB::table('contacts')->where('id', $rebond)->value('email_status'))->toBe('invalid');

    // 3. Le domaine revient.
    $this->dns->repondre(['zz-yoyo.example' => ResultatDns::MX]);
    DB::table('email_domaines')->update(['resolu_le' => now()->subDays(40)]);
    vemVerifier(['--source' => 'contacts']);

    expect(DB::table('contacts')->where('id', $rebond)->value('email_status'))->toBe('invalid')
        ->and(vemMeta($rebond)['email_verification']['email_status_pose'])->toBeNull()
        ->and(DB::table('contacts')->where('id', $oppose)->value('email_status'))->toBe('invalid')
        // Le témoin, lui, redevient `valid` : c'est bien le rebond qui retient.
        ->and(DB::table('contacts')->where('id', $temoin)->value('email_status'))->toBe('valid');
});

test('E6 elargi — un lot de UNE fiche dont le seul verdict est « sans courrier » rejuge le resolveur ; s il se trompe, rien n est ecrit', function () {
    $this->dns->pendant = function (): void {
        $this->dns->verdictTemoin = ResultatDns::INEXISTANT;
    };

    $r = vemVerifier(['--source' => 'entreprises', '--lot' => '1', '--depuis-id' => (string) ($this->muet - 1)]);

    expect($r['code'])->toBe(1)
        ->and($r['sortie'])->toContain('résolveur suspect')
        ->and(DB::table('email_domaines')->where('domaine', 'zz-muet.example')->exists())->toBeFalse()
        ->and(vemSignals($this->muet))->not->toHaveKey('email_generic_verification');
});

test('E6 elargi — un MX nul, seul dans son lot, rejuge aussi le resolveur', function () {
    $nul = vemFiche($this, 'ZZ Nul', 'contact@zz-nul.example');
    $this->dns->repondre(['zz-nul.example' => ResultatDns::MX_NUL]);
    $this->dns->pendant = function (): void {
        $this->dns->verdictTemoin = ResultatDns::INEXISTANT;
    };

    $r = vemVerifier(['--source' => 'entreprises', '--lot' => '1', '--depuis-id' => (string) ($nul - 1)]);

    expect($r['code'])->toBe(1)
        ->and(DB::table('email_domaines')->where('domaine', 'zz-nul.example')->exists())->toBeFalse()
        ->and(vemSignals($nul))->not->toHaveKey('email_generic_verification');
});

test('un lot qui n a rien a ecrire n ouvre ni transaction ni entree d audit', function () {
    vemVerifier();
    $lots = DB::table('audit_logs')->where('event_type', 'VERIFICATION_EMAILS_LOT')->count();
    $fins = DB::table('audit_logs')->where('event_type', 'VERIFICATION_EMAILS_FIN')->count();

    $jamais = vemVerifier(['--seulement-jamais-verifies' => true]);
    $refait = vemVerifier();

    expect(vemCompteur($jamais['sortie'], 'deja_verifiees_ignorees'))->toBeGreaterThan(0)
        ->and(vemCompteur($refait['sortie'], 'lots'))->toBeGreaterThan(0)
        ->and(DB::table('audit_logs')->where('event_type', 'VERIFICATION_EMAILS_LOT')->count())->toBe($lots)
        // TÉMOIN : l'entrée de FIN, elle, est toujours écrite.
        ->and(DB::table('audit_logs')->where('event_type', 'VERIFICATION_EMAILS_FIN')->count())->toBe($fins + 2)
        ->and($refait['sortie'])->toContain('Verrous tenus au plus en fin de lot : 0 (dont 0');
});

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
use App\Models\Workspace;
use App\Providers\AppServiceProvider;
use App\Support\ListeSuppression;
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
        'verifie_par' => VerificationEmail::SOURCE, 'empreinte' => ListeSuppression::empreinte('contact@zz-recoit.example'),
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

<?php

/**
 * Lot L5a (PLAN ADRESSES GÉNÉRALES, #680) — la porte unique accepte un verdict `risque/generique` récent SEULEMENT pour une demande de repli
 * ET si l'interrupteur de l'espace est relu à `true`. Toutes les autres règles de la porte passent avant et restent inchangées.
 * Sous le rôle applicatif ET sous le propriétaire. Fixtures fictives (`*.example.invalid`).
 */

use App\Envoi\Campagnes\RepliGenerique;
use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise as P;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => WorkspaceContext::clear());
afterEach(fn () => WorkspaceContext::clear());

const PRG_ADRESSE = 'contact@zz-client.example.invalid';

const PRG_MODES = ['app', 'proprio'];

/** Espace complet : domaine d'envoi prêt, verdict `risque/generique` de 5 jours, interrupteur allumé (posé par le propriétaire). */
function prgEspace(?string $interrupteur = 'true', string $verdict = 'risque', string $motif = 'generique', int $jours = 5): array
{
    $now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    $ws = F::espace('zz-prg');
    FonctionsEspace::regler($ws, 'federations', true);
    prgVerdict($ws, PRG_ADRESSE, $verdict, $motif, $now->modify(sprintf('%+d days', -$jours)));
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $ws, 'nom' => 'zz-envoi.example.invalid', 'achete_le' => $now->modify('-30 days')->format('Y-m-d'),
        'dmarc_verifie_le' => $now->format('c'), 'spf_verifie_le' => $now->format('c'), 'dkim_verifie_le' => $now->format('c'),
    ]);
    if ($interrupteur !== null) {
        prgInterrupteur($ws, $interrupteur);
    }

    return [$ws, $now];
}

function prgVerdict(string $ws, string $email, string $verdict, string $motif, DateTimeInterface $le): void
{
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($email),
        'verdict' => $verdict, 'motif' => $motif, 'verifie_le' => $le->format('c'), 'source' => 'test',
    ]);
}

function prgInterrupteur(string $ws, string $valeur): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_reglages')->updateOrInsert(['workspace_id' => $ws, 'cle' => 'envoi.repli_generique'], ['valeur' => $valeur, 'auteur' => 'fondateur']));
}

/** @param array<string, mixed> $sur */
function prgDemande(string $ws, DateTimeImmutable $now, bool $repli = true, array $sur = []): DemandeEnvoi
{
    $a = $sur + ['email' => PRG_ADRESSE, 'pays' => 'FR'];

    return new DemandeEnvoi(
        $ws,
        $a['email'],
        [['verification' => 'valide']],
        $a['pays'],
        null,
        'zz-envoi.example.invalid',
        'ZZ Expediteur SAS',
        $now->modify('-5 days'),
        null,
        'federations',
        true,
        false,
        $repli,
    );
}

/** « app » = rôle applicatif non propriétaire sous RLS. */
function prgSous(string $mode, string $ws, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

function prgRefus(string $mode, string $ws, DateTimeImmutable $now, bool $repli = true, array $sur = []): ?string
{
    return prgSous($mode, $ws, fn (): ?string => P::verifier(prgDemande($ws, $now, $repli, $sur), $now)->refus);
}

test('L5a 1 avec le drapeau ET l\'interrupteur allumé, un risque/generique récent est autorisé', function () {
    foreach (PRG_MODES as $mode) {
        [$ws, $now] = prgEspace();
        expect(prgRefus($mode, $ws, $now))->toBeNull($mode);
    }
});

test('L5a 2 non-régression : sans le drapeau, risque/generique reste refusé (interrupteur allumé ou non)', function () {
    foreach (PRG_MODES as $mode) {
        foreach (['true', null] as $reglage) {
            [$ws, $now] = prgEspace($reglage);
            expect(prgRefus($mode, $ws, $now, false))->toBe(P::VERDICT_ABSENT, "$mode " . var_export($reglage, true));
        }
    }
});

test('L5a 3 avec le drapeau mais interrupteur éteint, absent ou autre que « true » : refusé', function () {
    foreach (PRG_MODES as $mode) {
        foreach (['false', null] as $reglage) {
            [$ws, $now] = prgEspace($reglage);
            expect(prgRefus($mode, $ws, $now))->toBe(P::VERDICT_ABSENT, "$mode " . var_export($reglage, true));
        }
    }
});

test('L5a 4 valeur illisible posée hors garde (déclencheur coupé le temps du test) : lecteur fermé, refusé', function () {
    foreach (['1', 'TRUE', 'oui', '', ' true'] as $v) {
        [$ws, $now] = prgEspace(null);
        DB::statement('ALTER TABLE envoi_reglages DISABLE TRIGGER envoi_reglages_repli_generique');
        try {
            prgInterrupteur($ws, $v);
        } finally {
            DB::statement('ALTER TABLE envoi_reglages ENABLE TRIGGER envoi_reglages_repli_generique');
        }
        expect(prgRefus('proprio', $ws, $now))->toBe(P::VERDICT_ABSENT, "valeur « $v »");
    }
});

test('L5a 5 erreur de lecture de l\'interrupteur : refusé (échec fermé)', function () {
    [$ws, $now] = prgEspace();
    $refus = 'non-lu';
    try {
        DB::transaction(function () use ($ws, $now, &$refus) {
            DB::statement('ALTER TABLE envoi_reglages RENAME TO envoi_reglages_absente');
            $refus = P::verifier(prgDemande($ws, $now), $now)->refus;
            throw new RuntimeException('__annule__');
        });
    } catch (RuntimeException) {
    }
    expect($refus)->toBe(P::VERDICT_ABSENT)->and(RepliGenerique::ouvert($ws))->toBeTrue();
});

test('L5a 6 l\'interrupteur d\'un autre espace ne compte pas (cloisonnement)', function () {
    foreach (PRG_MODES as $mode) {
        [$ws, $now] = prgEspace(null);
        [$autre] = prgEspace('true');
        expect(RepliGenerique::ouvert($autre))->toBeTrue()->and(prgRefus($mode, $ws, $now))->toBe(P::VERDICT_ABSENT, $mode);
    }
});

test('L5a 7 un risque d\'un autre motif, ou un invalide, est refusé même avec drapeau et interrupteur allumé', function () {
    foreach (PRG_MODES as $mode) {
        foreach ([['risque', 'jetable'], ['risque', 'mx_nul'], ['risque', 'a'], ['invalide', 'generique'], ['invalide', 'mx']] as [$verdict, $motif]) {
            [$ws, $now] = prgEspace('true', $verdict, $motif);
            expect(prgRefus($mode, $ws, $now))->toBe(P::VERDICT_ABSENT, "$mode $verdict/$motif");
        }
    }
});

test('L5a 8 fraîcheur : 29 jours passe, 30 et 31 refusent, verdict daté du futur refusé', function () {
    foreach (PRG_MODES as $mode) {
        foreach ([29 => null, 30 => P::VERDICT_ABSENT, 31 => P::VERDICT_ABSENT, -1 => P::VERDICT_ABSENT] as $jours => $attendu) {
            [$ws, $now] = prgEspace('true', 'risque', 'generique', $jours);
            expect(prgRefus($mode, $ws, $now))->toBe($attendu, "$mode verdict de $jours jours");
        }
    }
});

test('L5a 9 le DERNIER verdict fait foi : un invalide plus récent annule le risque/generique', function () {
    foreach (PRG_MODES as $mode) {
        [$ws, $now] = prgEspace();
        prgVerdict($ws, PRG_ADRESSE, 'invalide', 'mx', $now->modify('-1 days'));
        expect(prgRefus($mode, $ws, $now))->toBe(P::VERDICT_ABSENT, $mode);
    }
});

test('L5a 10 un verdict « valide » reste accepté comme avant, avec ou sans drapeau, interrupteur éteint ou non', function () {
    foreach (PRG_MODES as $mode) {
        foreach ([true, false] as $repli) {
            foreach (['true', null] as $reglage) {
                [$ws, $now] = prgEspace($reglage, 'valide', 'a');
                expect(prgRefus($mode, $ws, $now, $repli))->toBeNull("$mode repli=" . var_export($repli, true));
            }
        }
    }
});

test('L5a 11 les autres règles de la porte passent avant : opposition, pays, webmail, domaine', function () {
    foreach (PRG_MODES as $mode) {
        [$ws, $now] = prgEspace();
        expect(prgRefus($mode, $ws, $now, true, ['pays' => 'BE']))->toBe(P::PAYS_HORS_FRANCE, $mode);

        [$ws, $now] = prgEspace();
        prgVerdict($ws, 'contact@gmail.com', 'risque', 'generique', $now->modify('-1 days'));
        expect(prgRefus($mode, $ws, $now, true, ['email' => 'contact@gmail.com']))->toBe(P::WEBMAIL_PREMIER_CONTACT, $mode);

        [$ws, $now] = prgEspace();
        DB::table('envoi_domaines')->where('workspace_id', $ws)->update(['achete_le' => $now->modify('-5 days')->format('Y-m-d')]);
        expect(prgRefus($mode, $ws, $now))->toBe(P::DOMAINE_TROP_JEUNE, $mode);
    }
});

test('L5a 12 opposition de l\'adresse (STOP) : refusée malgré le drapeau et l\'interrupteur', function () {
    foreach (PRG_MODES as $mode) {
        [$ws, $now] = prgEspace();
        $h = ListeSuppression::empreinte(PRG_ADRESSE);
        $lien = DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $ws, 'empreinte_adresse' => $h, 'portee' => 'business']);
        DB::table('envoi_oppositions')->insert(['workspace_id' => $ws, 'lien_id' => $lien, 'empreinte_adresse' => $h, 'canal' => 'one_click']);
        $refus = prgRefus($mode, $ws, $now);
        expect($refus)->not->toBeNull($mode)->and($refus)->not->toBe(P::VERDICT_ABSENT);
    }
});

<?php

/**
 * Envoi P0 L3 — la porte unique `EnvoiAutorise`, testée aux bornes.
 * Fixtures FICTIVES (dépôt public) : domaines `.example.invalid`.
 */

use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise as P;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const ENVOI_ADRESSE = 'contact@zz-client.example.invalid';

beforeEach(function () {
    $this->ws = F::espace('zz-envoi-l3');
    $this->now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Europe/Paris'));
    // État nominal : tout est en règle ; chaque test casse UNE garde.
    envoiVerdict($this->ws, ENVOI_ADRESSE, 'valide', $this->now->modify('-5 days'));
    envoiDomaine($this->ws, 'zz-envoi.example.invalid', $this->now->modify('-30 days'), $this->now);
});

/** Un espace NEUF (rien n'est effacé) avec seulement ce qu'on demande. */
function envoiEspaceNeuf(object $t, bool $verdict, bool $domaine): void
{
    $t->ws = F::espace('zz-envoi-l3');
    if ($verdict) {
        envoiVerdict($t->ws, ENVOI_ADRESSE, 'valide', $t->now->modify('-5 days'));
    }
    if ($domaine) {
        envoiDomaine($t->ws, 'zz-envoi.example.invalid', $t->now->modify('-30 days'), $t->now);
    }
}

function envoiVerdict(string $ws, string $email, string $verdict, DateTimeInterface $le): void
{
    DB::table('envoi_verdicts_adresse')->insert([
        'workspace_id' => $ws, 'empreinte_adresse' => ListeSuppression::empreinte($email),
        'verdict' => $verdict, 'verifie_le' => $le->format('c'), 'source' => 'test',
    ]);
}

function envoiDomaine(string $ws, string $nom, DateTimeInterface $achete, ?DateTimeInterface $dns): void
{
    $d = $dns?->format('c');
    DB::table('envoi_domaines')->insert([
        'workspace_id' => $ws, 'nom' => $nom, 'achete_le' => $achete->format('Y-m-d'),
        'dmarc_verifie_le' => $d, 'spf_verifie_le' => $d, 'dkim_verifie_le' => $d,
    ]);
}

/** @param array<string, mixed> $sur */
function envoiDemande(object $t, array $sur = []): DemandeEnvoi
{
    $a = array_merge([
        'email' => ENVOI_ADRESSE, 'pays' => 'FR', 'siren' => null, 'domaine' => 'zz-envoi.example.invalid',
        'entite' => 'ZZ Expediteur SAS', 'collecte' => $t->now->modify('-5 days'), 'info' => null,
        'segment' => null, 'premier' => true,
    ], $sur);

    return new DemandeEnvoi(
        $t->ws, $a['email'], [['verification' => 'valide']], $a['pays'], $a['siren'], $a['domaine'],
        $a['entite'], $a['collecte'], $a['info'], $a['segment'], $a['premier'],
    );
}

test('nominal : tout est en règle, la porte s\'ouvre', function () {
    expect(P::verifier(envoiDemande($this), $this->now)->refus)->toBeNull();
});

test('France : pays étranger refusé, pays inconnu refusé, SIREN seul accepté', function () {
    expect(P::verifier(envoiDemande($this, ['pays' => 'BE']), $this->now)->refus)->toBe(P::PAYS_HORS_FRANCE);
    expect(P::verifier(envoiDemande($this, ['pays' => null]), $this->now)->refus)->toBe(P::PAYS_INCONNU);
    expect(P::verifier(envoiDemande($this, ['pays' => '']), $this->now)->refus)->toBe(P::PAYS_INCONNU);
    expect(P::verifier(envoiDemande($this, ['pays' => null, 'siren' => '940000001']), $this->now)->refus)->toBeNull();
    expect(P::verifier(envoiDemande($this, ['pays' => null, 'siren' => '12']), $this->now)->refus)->toBe(P::PAYS_INCONNU);
});

test('webmail refusé en premier contact', function () {
    foreach (['zz@gmail.com', 'zz@outlook.com', 'zz@yahoo.fr'] as $mail) {
        expect(P::verifier(envoiDemande($this, ['email' => $mail]), $this->now)->refus)->toBe(P::WEBMAIL_PREMIER_CONTACT);
    }
});

test('opposition : l\'éligibilité enveloppée refuse', function () {
    DB::table('opt_out')->insert([
        'workspace_id' => $this->ws, 'scope' => 'business', 'email_hash' => ListeSuppression::empreinte(ENVOI_ADRESSE),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(P::verifier(envoiDemande($this), $this->now)->refus)->toBe('opposition');
})->skip(fn () => ! DB::getSchemaBuilder()->hasTable('opt_out'), 'opt_out absente');

test('verdict d\'adresse : 29 jours passe, 30 et 31 refusent, absent refuse, invalide récent annule', function () {
    envoiEspaceNeuf($this, false, true);
    expect(P::verifier(envoiDemande($this), $this->now)->refus)->toBe(P::VERDICT_ABSENT);

    foreach ([29 => null, 30 => P::VERDICT_ABSENT, 31 => P::VERDICT_ABSENT] as $jours => $attendu) {
        envoiEspaceNeuf($this, false, true);
        envoiVerdict($this->ws, ENVOI_ADRESSE, 'valide', $this->now->modify("-{$jours} days"));
        expect(P::verifier(envoiDemande($this), $this->now)->refus)->toBe($attendu, "verdict de {$jours} jours");
    }

    envoiEspaceNeuf($this, false, true);
    envoiVerdict($this->ws, ENVOI_ADRESSE, 'valide', $this->now->modify('-10 days'));
    envoiVerdict($this->ws, ENVOI_ADRESSE, 'invalide', $this->now->modify('-1 days'));
    expect(P::verifier(envoiDemande($this), $this->now)->refus)->toBe(P::VERDICT_ABSENT);
});

test('domaine d\'envoi : 20 jours refusé, 21 jours accepté, inconnu refusé', function () {
    foreach ([20 => P::DOMAINE_TROP_JEUNE, 21 => null] as $age => $attendu) {
        envoiEspaceNeuf($this, true, false);
        envoiDomaine($this->ws, 'zz-envoi.example.invalid', $this->now->modify("-{$age} days"), $this->now);
        expect(P::verifier(envoiDemande($this), $this->now)->refus)->toBe($attendu, "domaine de {$age} jours");
    }
    expect(P::verifier(envoiDemande($this, ['domaine' => 'zz-autre.example.invalid']), $this->now)->refus)->toBe(P::DOMAINE_INCONNU);
});

test('domaine d\'envoi : DMARC, SPF et DKIM doivent avoir été vérifiés le jour même', function () {
    foreach (['dmarc_verifie_le', 'spf_verifie_le', 'dkim_verifie_le'] as $col) {
        DB::table('envoi_domaines')->update(['dmarc_verifie_le' => $this->now->format('c'), 'spf_verifie_le' => $this->now->format('c'), 'dkim_verifie_le' => $this->now->format('c')]);
        DB::table('envoi_domaines')->update([$col => $this->now->modify('-1 day')->format('c')]);
        expect(P::verifier(envoiDemande($this), $this->now)->refus)->toBe(P::DOMAINE_DNS_NON_VERIFIE, $col);
        DB::table('envoi_domaines')->update([$col => null]);
        expect(P::verifier(envoiDemande($this), $this->now)->refus)->toBe(P::DOMAINE_DNS_NON_VERIFIE, $col . ' nul');
    }
});

test('art. 14 : J+20 rien, J+21 alerte, J+29 alerte, J+30 refus ; information donnée = pas de refus', function () {
    $cas = [20 => [null, []], 21 => [null, [P::ART14_ALERTE]], 29 => [null, [P::ART14_ALERTE]], 30 => [P::ART14_DEPASSE, []], 31 => [P::ART14_DEPASSE, []]];
    foreach ($cas as $j => [$refus, $alertes]) {
        $v = P::verifier(envoiDemande($this, ['collecte' => $this->now->modify("-{$j} days")]), $this->now);
        expect($v->refus)->toBe($refus, "J+{$j}");
        expect($v->alertes)->toBe($alertes, "alertes J+{$j}");
    }
    $v = P::verifier(envoiDemande($this, ['collecte' => $this->now->modify('-40 days'), 'info' => $this->now->modify('-20 days')]), $this->now);
    expect($v->refus)->toBeNull();
    expect(P::verifier(envoiDemande($this, ['collecte' => null]), $this->now)->refus)->toBe(P::ART14_DATE_INCONNUE);
});

test('entité légale expéditrice absente refusée', function () {
    foreach ([null, '', '  '] as $vide) {
        expect(P::verifier(envoiDemande($this, ['entite' => $vide]), $this->now)->refus)->toBe(P::ENTITE_ABSENTE);
    }
});

test('segments sensibles fermés par défaut : espace sans réglage, segment inconnu', function () {
    config(['crm.segments_par_espace' => true]);
    expect(P::verifier(envoiDemande($this, ['segment' => 'presse']), $this->now)->refus)->toBe(P::SEGMENT_FERME);
    config(['crm.segments_par_espace' => false, 'crm.segments_ouverts' => 'aucun']);
    expect(P::verifier(envoiDemande($this, ['segment' => 'presse']), $this->now)->refus)->toBe(P::SEGMENT_FERME);
    expect(P::verifier(envoiDemande($this, ['segment' => 'inconnu']), $this->now)->refus)->toBe(P::SEGMENT_FERME);
});

test('la photo du figement ne vaut que 15 minutes avant l\'injection', function () {
    $fige = $this->now;
    expect(P::photoEncoreValable($fige, $fige->modify('+14 minutes')))->toBeTrue();
    expect(P::photoEncoreValable($fige, $fige->modify('+15 minutes')))->toBeFalse();
    expect(P::photoEncoreValable($fige, $fige->modify('-1 minute')))->toBeFalse();
});

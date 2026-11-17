<?php

/**
 * Lot L6 (PLAN ADRESSES GÉNÉRALES, #680) — une opposition reçue sur une adresse de REPLI (adresse générale, un seul message)
 * inscrit AUSSI l'opposition du SIREN de l'entreprise : aucun contact nommé ajouté plus tard ne sera prospecté.
 * Échec fermé : une erreur d'écriture du SIREN n'annule jamais l'opposition de l'adresse, et l'opération est rejouable.
 * Fixtures FICTIVES (dépôt public), propriétaire de la base, un espace par test (cloisonnement `workspace_id`).
 */

use App\Crm\Opposition\StatutOpposition;
use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Envoi\Desinscription\EnregistrerOpposition;
use App\Envoi\Desinscription\OpposerAdresse;
use App\Envoi\Sequence\Evenement;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const DRG_GENERIQUE = 'contact@zz-repli.example.invalid';

/** SIREN fictif valide (clé de Luhn), sur le préfixe 95 pour ne jamais croiser les fixtures 94. */
function drgSiren(int $n): string
{
    $base = '95' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    for ($c = 0; $c <= 9; $c++) {
        $s = $base . $c;
        $somme = 0;
        for ($i = 0; $i < 9; $i++) {
            $d = (int) $s[8 - $i];
            if ($i % 2 === 1 && ($d *= 2) > 9) {
                $d -= 9;
            }
            $somme += $d;
        }
        if ($somme % 10 === 0) {
            return $s;
        }
    }
    throw new LogicException('inatteignable');
}

/** Interrupteur du repli allumé pour l'espace (posé par le propriétaire, comme en production). */
function drgAllumer(string $ws): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => 'envoi.repli_generique', 'valeur' => 'true', 'auteur' => 'fondateur']));
}

function drgSequence(string $ws): string
{
    return WorkspaceContext::run($ws, function () use ($ws): string {
        $id = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'ZZ repli']);

        return $id;
    });
}

/** Une inscription (de repli : une seule étape, `repli_generique = true`) ; retourne son id. */
function drgInscription(string $ws, int $entreprise, string $email, bool $repli, string $statut = 'active'): string
{
    return WorkspaceContext::run($ws, function () use ($ws, $entreprise, $email, $repli, $statut): string {
        $contact = F::contact($ws, $entreprise, 'Zz', 'Repli-' . Str::random(6), ['email' => $email]);
        $id = (string) Str::uuid();
        DB::table('envoi_inscriptions')->insert([
            'id' => $id, 'workspace_id' => $ws, 'sequence_id' => drgSequence($ws), 'contact_id' => $contact, 'entreprise_id' => $entreprise,
            'empreinte_adresse' => ListeSuppression::empreinte($email), 'statut' => $statut, 'repli_generique' => $repli,
            'prochaine_echeance' => now()->addDay(), 'version_figee' => '{"etapes":[{"rang":1}]}',
        ]);

        return $id;
    });
}

function drgOpposer(string $ws, string $adresse = DRG_GENERIQUE, string $canal = EnregistrerOpposition::CANAL_REPONSE): void
{
    (new OpposerAdresse(app(EnregistrerOpposition::class)))->executer($ws, ListeSuppression::empreinte($adresse), $canal);
}

function drgSirenOppose(string $ws, string $siren): bool
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions_registre')->where('workspace_id', $ws)
        ->where('portee', 'organisation')->where('empreinte', RegistreOppositionManuel::empreinte($siren))->exists());
}

function drgAdresseOpposee(string $ws, string $adresse = DRG_GENERIQUE): bool
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions')->where('workspace_id', $ws)
        ->where('empreinte_adresse', ListeSuppression::empreinte($adresse))->exists());
}

function drgStatut(string $ws, string $id): array
{
    $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions')->where('id', $id)->first());

    return [$l->statut, $l->motif_arret];
}

test('L6 : STOP sur l\'adresse de repli ⇒ SIREN opposé, adresse opposée, inscriptions actives de l\'entreprise arrêtées', function () {
    $ws = F::espace('zz-drg-stop');
    $siren = drgSiren(1);
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => $siren]);
    drgAllumer($ws);
    $repli = drgInscription($ws, $ent, DRG_GENERIQUE, true);
    $collegue = drgInscription($ws, $ent, 'collegue@zz-repli.example.invalid', false);

    drgOpposer($ws);

    expect(drgAdresseOpposee($ws))->toBeTrue();
    expect(drgSirenOppose($ws, $siren))->toBeTrue();
    expect(drgStatut($ws, $repli))->toBe(['arretee', Evenement::OPPOSITION]);
    expect(drgStatut($ws, $collegue))->toBe(['arretee', Evenement::OPPOSITION]);
});

test('L6 : campagne suivante — un contact nommé ajouté ensuite est jugé « opposé » (SIREN), pas de nouveau message', function () {
    $ws = F::espace('zz-drg-suite');
    $siren = drgSiren(2);
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => $siren]);
    drgAllumer($ws);
    drgInscription($ws, $ent, DRG_GENERIQUE, true);

    drgOpposer($ws);

    // Un nommé apparaît APRÈS l'opposition : l'adresse est neuve, seul le SIREN peut l'arrêter.
    $statut = WorkspaceContext::run($ws, fn () => (new StatutOpposition)->pourLignes($ws, [1 => ['email' => 'jean.dupont@zz-repli.example.invalid', 'siren' => $siren]]));
    expect($statut[1]['opposee'])->toBeTrue();
    expect(WorkspaceContext::run($ws, fn () => RegistreOppositionManuel::refuse($ws, 'jean.dupont@zz-repli.example.invalid', $siren)))->toBeTrue();
});

test('L6 : pas de second repli — l\'entreprise ne reçoit jamais une deuxième inscription de repli, même après l\'opposition', function () {
    $ws = F::espace('zz-drg-unique');
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => drgSiren(3)]);
    drgAllumer($ws);
    drgInscription($ws, $ent, DRG_GENERIQUE, true);
    drgOpposer($ws);

    $sequence = drgSequence($ws);
    $contact = F::contact($ws, $ent, 'Zz', 'Second', ['email' => 'info@zz-repli.example.invalid']);
    $code = null;
    try {
        WorkspaceContext::set($ws);
        DB::transaction(fn () => DB::table('envoi_inscriptions')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $ws, 'sequence_id' => $sequence, 'contact_id' => $contact, 'entreprise_id' => $ent,
            'empreinte_adresse' => ListeSuppression::empreinte('info@zz-repli.example.invalid'), 'statut' => 'active', 'repli_generique' => true,
            'prochaine_echeance' => now()->addDay(), 'version_figee' => '{"etapes":[{"rang":1}]}',
        ]));
    } catch (QueryException $e) {
        $code = (string) $e->getCode();
    }
    expect($code)->toBe('23505');
});

test('L6 : une plainte relevée dans la boîte (canal relève) sur le repli a le même effet', function () {
    $ws = F::espace('zz-drg-plainte');
    $siren = drgSiren(4);
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => $siren]);
    drgAllumer($ws);
    $repli = drgInscription($ws, $ent, DRG_GENERIQUE, true);

    drgOpposer($ws, DRG_GENERIQUE, EnregistrerOpposition::CANAL_RELEVE);

    expect(drgSirenOppose($ws, $siren))->toBeTrue();
    expect(drgAdresseOpposee($ws))->toBeTrue();
    expect(drgStatut($ws, $repli))->toBe(['arretee', Evenement::OPPOSITION]);
});

test('L6 : erreur pendant l\'écriture du SIREN ⇒ aucune trace partielle, l\'adresse reste opposée, l\'opération se rejoue', function () {
    $ws = F::espace('zz-drg-erreur');
    $sirenA = drgSiren(5);
    $sirenB = drgSiren(6);
    $entA = F::fiche($ws, 'ZZ Entreprise A', ['siren' => $sirenA]);
    $entB = F::fiche($ws, 'ZZ Entreprise B', ['siren' => $sirenB]);
    drgAllumer($ws);
    // Même boîte partagée par deux fiches : deux SIREN à inscrire, le second échoue.
    drgInscription($ws, $entA, DRG_GENERIQUE, true);
    drgInscription($ws, $entB, DRG_GENERIQUE, true, 'terminee'); // une seule inscription ACTIVE par adresse et par espace
    DB::unprepared("CREATE FUNCTION zz_drg_panne() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN
        IF NEW.valeur = '{$sirenB}' THEN RAISE EXCEPTION 'panne simulee'; END IF; RETURN NEW; END \$f\$");
    DB::unprepared('CREATE TRIGGER zz_drg_panne BEFORE INSERT ON envoi_oppositions_registre FOR EACH ROW EXECUTE FUNCTION zz_drg_panne()');

    $leve = false;
    try {
        drgOpposer($ws);
    } catch (Throwable) {
        $leve = true;
    }

    expect($leve)->toBeTrue();                          // jamais avalée : l'appelant sait que ce n'est pas terminé
    expect(drgSirenOppose($ws, $sirenA))->toBeFalse();  // pas de trace partielle
    expect(drgSirenOppose($ws, $sirenB))->toBeFalse();
    expect(drgAdresseOpposee($ws))->toBeTrue();         // l'adresse reste opposée

    DB::unprepared('DROP TRIGGER zz_drg_panne ON envoi_oppositions_registre');
    drgOpposer($ws);                                    // rejeu
    expect(drgSirenOppose($ws, $sirenA))->toBeTrue()->and(drgSirenOppose($ws, $sirenB))->toBeTrue();
    drgOpposer($ws);                                    // idempotent
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions_registre')->where('workspace_id', $ws)->where('portee', 'organisation')->count()))->toBe(2);
});

test('L6 : sans SIREN, la trace par entreprise existante fait foi (rien au registre, aucune erreur)', function () {
    $ws = F::espace('zz-drg-sans-siren');
    $ent = F::sansSiren($ws, 'ZZ Entreprise sans SIREN');
    drgAllumer($ws);
    $repli = drgInscription($ws, $ent, DRG_GENERIQUE, true);
    $collegue = drgInscription($ws, $ent, 'collegue@zz-repli.example.invalid', false);

    drgOpposer($ws);

    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions_registre')->where('workspace_id', $ws)->count()))->toBe(0);
    expect(drgStatut($ws, $repli))->toBe(['arretee', Evenement::OPPOSITION]);
    expect(drgStatut($ws, $collegue))->toBe(['arretee', Evenement::OPPOSITION]);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions_evenements')->where('evenement', Evenement::OPPOSITION)->where('inscription_id', $repli)->count()))->toBe(1);
});

test('L6 non-régression : une adresse qui n\'est PAS de repli (interrupteur éteint, aucune inscription de repli) — comportement inchangé', function () {
    $ws = F::espace('zz-drg-nr');
    $siren = drgSiren(7);
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => $siren]);
    $nominatif = 'jean.dupont@zz-repli.example.invalid';
    $normale = drgInscription($ws, $ent, $nominatif, false);
    $collegue = drgInscription($ws, $ent, 'collegue@zz-repli.example.invalid', false);

    drgOpposer($ws, $nominatif);

    expect(drgAdresseOpposee($ws, $nominatif))->toBeTrue();
    expect(drgSirenOppose($ws, $siren))->toBeFalse();  // pas d'opposition d'entreprise pour une adresse nominative
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions_registre')->where('workspace_id', $ws)->count()))->toBe(0);
    expect(drgStatut($ws, $normale))->toBe(['arretee', Evenement::OPPOSITION]);
    expect(drgStatut($ws, $collegue))->toBe(['arretee', Evenement::OPPOSITION]);
});

test('L6 cloisonnement : l\'opposition d\'un espace n\'inscrit rien dans un autre espace', function () {
    $a = F::espace('zz-drg-a');
    $b = F::espace('zz-drg-b');
    $siren = drgSiren(8);
    $entA = F::fiche($a, 'ZZ Entreprise A', ['siren' => $siren]);
    $entB = F::fiche($b, 'ZZ Entreprise B', ['siren' => $siren]);
    drgAllumer($a);
    drgAllumer($b);
    drgInscription($a, $entA, DRG_GENERIQUE, true);
    $chezB = drgInscription($b, $entB, DRG_GENERIQUE, true);

    drgOpposer($a);

    expect(drgSirenOppose($a, $siren))->toBeTrue();
    expect(drgSirenOppose($b, $siren))->toBeFalse();
    expect(drgStatut($b, $chezB))->toBe(['active', null]);
});

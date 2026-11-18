<?php

/**
 * Lot L6b (PLAN ADRESSES GÉNÉRALES, #680, décision D6) — le CLIC sur le lien de désinscription d'une adresse de REPLI
 * (adresse générale) inscrit AUSSI l'opposition du SIREN de l'entreprise, comme le STOP (L6).
 * Échec fermé : l'adresse est opposée d'abord ; une erreur d'écriture du SIREN remonte (jamais de page de succès)
 * et le rejeu (nouveau clic) reprend l'écriture. Fixtures FICTIVES (dépôt public), un espace par test.
 */

use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Sequence\Evenement;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const DLR_GENERIQUE = 'contact@zz-lien-repli.example.invalid';

beforeEach(function () {
    config(['envoi.cle_desinscription' => 'cle-de-test-seulement-0123456789', 'envoi.desinscription_validite_jours' => 30]);
});

/** SIREN fictif valide (clé de Luhn), préfixe 96 pour ne croiser ni les fixtures 94 ni 95. */
function dlrSiren(int $n): string
{
    $base = '96' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
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

/** Interrupteur du repli allumé pour l'espace (requis par la base pour inscrire un repli). */
function dlrAllumer(string $ws): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => 'envoi.repli_generique', 'valeur' => 'true', 'auteur' => 'fondateur']));
}

/** Une inscription (de repli ou non) sur $email ; retourne son id. */
function dlrInscription(string $ws, int $entreprise, string $email, bool $repli, string $statut = 'active'): string
{
    return WorkspaceContext::run($ws, function () use ($ws, $entreprise, $email, $repli, $statut): string {
        $contact = F::contact($ws, $entreprise, 'Zz', 'Lien-' . Str::random(6), ['email' => $email]);
        $sequence = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sequence, 'workspace_id' => $ws, 'nom' => 'ZZ lien repli']);
        $id = (string) Str::uuid();
        DB::table('envoi_inscriptions')->insert([
            'id' => $id, 'workspace_id' => $ws, 'sequence_id' => $sequence, 'contact_id' => $contact, 'entreprise_id' => $entreprise,
            'empreinte_adresse' => ListeSuppression::empreinte($email), 'statut' => $statut, 'repli_generique' => $repli,
            'prochaine_echeance' => now()->addDay(), 'version_figee' => '{"etapes":[{"rang":1}]}',
        ]);

        return $id;
    });
}

/** Chemin du lien de désinscription de l'adresse (le lien est créé comme à l'envoi). */
function dlrChemin(string $ws, string $adresse = DLR_GENERIQUE): string
{
    return '/desinscription/' . JetonDesinscription::emettre($ws, InscriptionsEnvoi::creer($ws, $adresse));
}

function dlrSirenOppose(string $ws, string $siren): bool
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions_registre')->where('workspace_id', $ws)
        ->where('portee', 'organisation')->where('empreinte', RegistreOppositionManuel::empreinte($siren))->exists());
}

function dlrAdresseOpposee(string $ws, string $adresse = DLR_GENERIQUE): bool
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions')->where('workspace_id', $ws)
        ->where('empreinte_adresse', ListeSuppression::empreinte($adresse))->exists());
}

function dlrRegistre(string $ws): int
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions_registre')->where('workspace_id', $ws)->count());
}

function dlrStatut(string $ws, string $id): array
{
    $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions')->where('id', $id)->first());

    return [$l->statut, $l->motif_arret];
}

test('L6b : clic sur le lien d\'une adresse de repli ⇒ adresse opposée, SIREN opposé, inscriptions de l\'entreprise arrêtées', function () {
    $ws = F::espace('zz-dlr-clic');
    $siren = dlrSiren(1);
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => $siren]);
    dlrAllumer($ws);
    $repli = dlrInscription($ws, $ent, DLR_GENERIQUE, true);
    $collegue = dlrInscription($ws, $ent, 'collegue@zz-lien-repli.example.invalid', false);

    $this->post(dlrChemin($ws))->assertOk()->assertSee('Désinscription confirmée', false);

    expect(dlrAdresseOpposee($ws))->toBeTrue();
    expect(dlrSirenOppose($ws, $siren))->toBeTrue();
    expect(dlrStatut($ws, $repli))->toBe(['arretee', Evenement::OPPOSITION]);
    expect(dlrStatut($ws, $collegue))->toBe(['arretee', Evenement::OPPOSITION]);
});

test('L6b : le « one-click » (RFC 8058) sur le lien d\'un repli inscrit aussi le SIREN, et un rejeu ne double rien', function () {
    $ws = F::espace('zz-dlr-oneclick');
    $siren = dlrSiren(2);
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => $siren]);
    dlrAllumer($ws);
    dlrInscription($ws, $ent, DLR_GENERIQUE, true);
    $chemin = dlrChemin($ws);

    $this->post($chemin, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('', false);
    $this->post($chemin, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(dlrSirenOppose($ws, $siren))->toBeTrue();
    expect(dlrRegistre($ws))->toBe(1);
});

test('L6b non-régression : le lien d\'une adresse qui n\'est PAS de repli garde son comportement (aucun SIREN au registre)', function () {
    $ws = F::espace('zz-dlr-nr');
    $siren = dlrSiren(3);
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => $siren]);
    $nominatif = 'prenom.nom@zz-lien-repli.example.invalid';
    $normale = dlrInscription($ws, $ent, $nominatif, false);
    $collegue = dlrInscription($ws, $ent, 'collegue@zz-lien-repli.example.invalid', false);

    $this->post(dlrChemin($ws, $nominatif))->assertOk()->assertSee('Désinscription confirmée', false);

    expect(dlrAdresseOpposee($ws, $nominatif))->toBeTrue();
    expect(dlrSirenOppose($ws, $siren))->toBeFalse();
    expect(dlrRegistre($ws))->toBe(0);
    expect(dlrStatut($ws, $normale))->toBe(['arretee', Evenement::OPPOSITION]);
    expect(dlrStatut($ws, $collegue))->toBe(['arretee', Evenement::OPPOSITION]);
});

test('L6b : erreur pendant l\'écriture du SIREN ⇒ jamais de page de succès, adresse opposée, rien de partiel, le nouveau clic reprend', function () {
    $ws = F::espace('zz-dlr-erreur');
    $sirenA = dlrSiren(4);
    $sirenB = dlrSiren(5);
    $entA = F::fiche($ws, 'ZZ Entreprise A', ['siren' => $sirenA]);
    $entB = F::fiche($ws, 'ZZ Entreprise B', ['siren' => $sirenB]);
    dlrAllumer($ws);
    dlrInscription($ws, $entA, DLR_GENERIQUE, true);
    dlrInscription($ws, $entB, DLR_GENERIQUE, true, 'terminee'); // une seule inscription ACTIVE par adresse et par espace
    DB::unprepared("CREATE FUNCTION zz_dlr_panne() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN
        IF NEW.valeur = '{$sirenB}' THEN RAISE EXCEPTION 'panne simulee'; END IF; RETURN NEW; END \$f\$");
    DB::unprepared('CREATE TRIGGER zz_dlr_panne BEFORE INSERT ON envoi_oppositions_registre FOR EACH ROW EXECUTE FUNCTION zz_dlr_panne()');
    $chemin = dlrChemin($ws);

    $r = $this->post($chemin);

    expect($r->getStatusCode())->toBe(500);
    expect($r->getContent())->not->toContain('Désinscription confirmée');
    expect(dlrAdresseOpposee($ws))->toBeTrue();         // l'adresse reste opposée
    expect(dlrSirenOppose($ws, $sirenA))->toBeFalse();  // tout ou rien : pas de trace partielle
    expect(dlrSirenOppose($ws, $sirenB))->toBeFalse();

    DB::unprepared('DROP TRIGGER zz_dlr_panne ON envoi_oppositions_registre');
    $this->post($chemin)->assertOk()->assertSee('Désinscription confirmée', false);   // rejeu : même lien, nouveau clic
    expect(dlrSirenOppose($ws, $sirenA))->toBeTrue()->and(dlrSirenOppose($ws, $sirenB))->toBeTrue();
    expect(dlrRegistre($ws))->toBe(2);
});

test('L6b cloisonnement : le clic dans un espace n\'inscrit rien dans un autre espace (deux SIREN différents)', function () {
    $a = F::espace('zz-dlr-a');
    $b = F::espace('zz-dlr-b');
    $sirenA = dlrSiren(6);
    $sirenB = dlrSiren(7);
    $entA = F::fiche($a, 'ZZ Entreprise A', ['siren' => $sirenA]);
    $entB = F::fiche($b, 'ZZ Entreprise B', ['siren' => $sirenB]);
    dlrAllumer($a);
    dlrAllumer($b);
    dlrInscription($a, $entA, DLR_GENERIQUE, true);
    $chezB = dlrInscription($b, $entB, DLR_GENERIQUE, true);

    $this->post(dlrChemin($a))->assertOk();

    expect(dlrSirenOppose($a, $sirenA))->toBeTrue();
    expect(dlrSirenOppose($a, $sirenB))->toBeFalse();   // rien de B n'entre dans A
    expect(dlrSirenOppose($b, $sirenB))->toBeFalse();
    expect(dlrSirenOppose($b, $sirenA))->toBeFalse();
    expect(dlrRegistre($b))->toBe(0);
    expect(dlrStatut($b, $chezB))->toBe(['active', null]);
});

test('L6b sous le rôle applicatif (RLS) : le clic sur le lien d\'un repli oppose l\'adresse et le SIREN', function () {
    $ws = F::espace('zz-dlr-app');
    $siren = dlrSiren(8);
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => $siren]);
    dlrAllumer($ws);
    $repli = dlrInscription($ws, $ent, DLR_GENERIQUE, true);
    $chemin = dlrChemin($ws);

    try {
        WorkspaceContext::set($ws);
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');

        $this->post($chemin)->assertOk()->assertSee('Désinscription confirmée', false);

        expect(dlrAdresseOpposee($ws))->toBeTrue();
        expect(dlrSirenOppose($ws, $siren))->toBeTrue();
        expect(dlrStatut($ws, $repli))->toBe(['arretee', Evenement::OPPOSITION]);
    } finally {
        DB::statement('RESET ROLE');
    }
});

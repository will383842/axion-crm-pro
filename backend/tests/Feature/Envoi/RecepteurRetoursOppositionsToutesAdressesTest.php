<?php

/**
 * Relève : un STOP porte deux adresses (celle du retour, puis celle de l'expéditeur). Depuis le lot L6, une erreur d'écriture
 * du SIREN sur la première ne doit plus empêcher d'opposer la seconde : TOUTES les adresses sont opposées d'abord,
 * l'erreur remonte ensuite (échec fermé, rien d'avalé). Fixtures FICTIVES, aucun réseau, aucun envoi.
 */

use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Envoi\Imap\ClasseRetour;
use App\Envoi\Imap\RecepteurRetoursSequence;
use App\Envoi\Imap\RetourDetecte;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function rtoOpposee(string $ws, string $adresse): bool
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions')->where('workspace_id', $ws)
        ->where('empreinte_adresse', ListeSuppression::empreinte($adresse))->exists());
}

test('erreur d\'écriture du SIREN sur la première adresse ⇒ les deux adresses sont opposées et l\'erreur remonte', function () {
    $ws = F::espace('zz-rto-erreur');
    $siren = '950000018';
    $premiere = 'contact@zz-rto.example.invalid';
    $seconde = 'expediteur@zz-rto.example.invalid';
    $ent = F::fiche($ws, 'ZZ Entreprise', ['siren' => $siren]);
    WorkspaceContext::run($ws, function () use ($ws, $ent, $premiere) {
        DB::table('envoi_reglages')->insert(['workspace_id' => $ws, 'cle' => 'envoi.repli_generique', 'valeur' => 'true', 'auteur' => 'fondateur']);
        $sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'ZZ rto']);
        $contact = F::contact($ws, $ent, 'Zz', 'Rto-' . Str::random(6), ['email' => $premiere]);
        DB::table('envoi_inscriptions')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $ws, 'sequence_id' => $sid, 'contact_id' => $contact, 'entreprise_id' => $ent,
            'empreinte_adresse' => ListeSuppression::empreinte($premiere), 'statut' => 'active', 'repli_generique' => true,
            'prochaine_echeance' => now()->addDay(), 'version_figee' => '{"etapes":[{"rang":1}]}',
        ]);
    });
    DB::unprepared("CREATE FUNCTION zz_rto_panne() RETURNS trigger LANGUAGE plpgsql AS \$f\$ BEGIN
        IF NEW.valeur = '{$siren}' THEN RAISE EXCEPTION 'panne simulee'; END IF; RETURN NEW; END \$f\$");
    DB::unprepared('CREATE TRIGGER zz_rto_panne BEFORE INSERT ON envoi_oppositions_registre FOR EACH ROW EXECUTE FUNCTION zz_rto_panne()');

    $retour = new RetourDetecte(ClasseRetour::Desinscription, (string) Str::uuid(), $premiere, $seconde, 'cle-rto');
    $leve = false;
    try {
        RecepteurRetoursSequence::parDefaut()->recevoir($ws, $retour);
    } catch (Throwable) {
        $leve = true;
    }

    expect($leve)->toBeTrue()                       // jamais avalée
        ->and(rtoOpposee($ws, $premiere))->toBeTrue()
        ->and(rtoOpposee($ws, $seconde))->toBeTrue() // la seconde est opposée malgré l'erreur de la première
        ->and(WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions_registre')->where('workspace_id', $ws)
            ->where('empreinte', RegistreOppositionManuel::empreinte($siren))->exists()))->toBeFalse(); // pas de trace partielle
});

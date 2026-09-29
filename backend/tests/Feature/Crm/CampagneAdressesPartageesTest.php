<?php

/**
 * CAMPAGNES ET ADRESSES PARTAGÉES (chantier 5).
 *
 *  1. une adresse portée par plusieurs fiches ne fait qu'UNE ligne : jamais
 *     deux fois le même message à la même adresse (REQ-CAM-008) ;
 *  2. une adresse de CABINET COMPTABLE ou de DOMICILIATION portée par au
 *     moins N fiches est écartée par défaut (le message n'atteindrait pas le
 *     dirigeant) ; `--avec-adresses-partagees` la garde ; sous le seuil, ou
 *     d'une autre nature, elle part.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\Rapprochement;
use App\Crm\FichesProtegees;
use App\Support\ListeSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->ws = F::espace('zz-camp-partagees');
    config(['crm.ingest.business_workspace' => F::slug($this->ws)]);
    $this->cabinet = 'compta@zz-cabinet.example.invalid';
    $this->groupe = 'contact@zz-groupe.example.invalid';
    // Trois organisateurs derrière la même adresse de cabinet, deux derrière
    // celle d'un groupe, un seul derrière la sienne.
    foreach ([['ZZ Club Un', $this->cabinet], ['ZZ Club Deux', $this->cabinet], ['ZZ Club Trois', 'Compta@zz-cabinet.example.invalid'],
        ['ZZ Club Quatre', $this->groupe], ['ZZ Club Cinq', $this->groupe], ['ZZ Club Six', 'bureau@zz-six.example.invalid']] as [$nom, $email]) {
        $id = F::sansSiren($this->ws, $nom, ['email_generic' => $email]);
        F::proteger($this->ws, $id, FichesProtegees::TAG_ORGANISATEURS);
    }
    foreach ([[$this->cabinet, 3, Rapprochement::CABINET_COMPTABLE], [$this->groupe, 2, Rapprochement::GROUPE]] as [$email, $n, $nature]) {
        DB::table('adresses_partagees')->insert([
            'workspace_id' => $this->ws, 'email_empreinte' => ListeSuppression::empreinte($email), 'nb_fiches' => $n, 'nature' => $nature,
        ]);
    }
});

/** @return array{emails: list<string>, sortie: string} */
function capDestinataires(array $options = []): array
{
    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-cap-');
    try {
        Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $fichier] + $options);
        $emails = [];
        foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
            $emails[] = (string) json_decode($ligne, true)['email'];
        }
        sort($emails);

        return ['emails' => $emails, 'sortie' => Artisan::output()];
    } finally {
        @unlink($fichier);
    }
}

test('l adresse du cabinet comptable est écartée par défaut ; celle du groupe part UNE fois', function () {
    $r = capDestinataires();

    expect($r['emails'])->toBe(['bureau@zz-six.example.invalid', $this->groupe])
        ->and(F::compteur($r['sortie'], 'ecartees_adresse_partagee'))->toBe(1);
});

test('--avec-adresses-partagees la garde — une seule fois malgré ses trois fiches', function () {
    $r = capDestinataires(['--avec-adresses-partagees' => true]);

    expect($r['emails'])->toBe(['bureau@zz-six.example.invalid', $this->cabinet, $this->groupe])
        ->and(F::compteur($r['sortie'], 'ecartees_adresse_partagee'))->toBe(0);
});

test('le seuil est réglable : au-dessus du nombre de fiches, l adresse part', function () {
    config(['crm.doublons.campagne.seuil_fiches' => 4]);

    expect(capDestinataires()['emails'])->toBe(['bureau@zz-six.example.invalid', $this->cabinet, $this->groupe]);
});

test('les natures écartées sont réglables : une domiciliation aussi, un groupe jamais par défaut', function () {
    DB::table('adresses_partagees')->where('workspace_id', $this->ws)->where('nature', Rapprochement::GROUPE)
        ->update(['nature' => Rapprochement::DOMICILIATION, 'nb_fiches' => 3]);

    expect(capDestinataires()['emails'])->toBe(['bureau@zz-six.example.invalid']);

    config(['crm.doublons.campagne.natures_exclues' => []]);
    expect(capDestinataires()['emails'])->toBe(['bureau@zz-six.example.invalid', $this->cabinet, $this->groupe]);
});

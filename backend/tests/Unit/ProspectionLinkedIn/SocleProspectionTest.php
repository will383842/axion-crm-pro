<?php

use App\ProspectionLinkedIn\EtatsProspect;
use App\ProspectionLinkedIn\LienSuivi;
use App\ProspectionLinkedIn\TextePret;

const PL_POLITIQUE = 'https://site.example.test/confidentialite';

beforeEach(function () {
    config([
        'prospection_linkedin.site_url' => 'https://site.example.test',
        'prospection_linkedin.politique_confidentialite_url' => PL_POLITIQUE,
    ]);
});

function plMessage(string $corps = 'Bonjour {{prenom}}, répondez STOP pour ne plus être contacté. ' . PL_POLITIQUE): string
{
    return $corps;
}

it('drapeau absent ⇒ false', function () {
    expect((require base_path('config/prospection_linkedin.php'))['active'])->toBeFalse();
    expect(config('prospection_linkedin.seuil_score'))->toBe(60)
        ->and(config('prospection_linkedin.delai_reinvitation_jours'))->toBe(21)
        ->and(config('prospection_linkedin.jours_contact_courriel'))->toBe(90)
        ->and(config('prospection_linkedin.plafonds.invitations_jour'))->toBe(10);
});

it('table complète des transitions : état × geste', function () {
    $chaine = EtatsProspect::CHAINE;
    $rang = array_flip($chaine);
    $avance = ['invitation_faite' => 'invite', 'invitation_acceptee' => 'connecte', 'message_fait' => 'message_envoye',
        'a_repondu' => 'repondu', 'interesse' => 'humain', 'rdv_pris' => 'rdv', 'non' => 'clos'];
    foreach ($chaine as $etat) {
        foreach ($avance as $geste => $cible) {
            $r = EtatsProspect::appliquer($etat, $geste);
            if ($rang[$cible] > $rang[$etat]) {
                expect($r)->toBe(['etat' => $cible, 'sans_effet' => false], "$etat/$geste");
            } else {
                expect($r)->toBe(['etat' => $etat, 'sans_effet' => true], "$etat/$geste");
            }
        }
        foreach (['opposition', 'hors_cible'] as $g) {
            expect(EtatsProspect::appliquer($etat, $g)['etat'])->toBe('ecarte');
        }
    }
    foreach (['candidat', 'qualifie', 'controle', 'en_file', 'connecte', 'message_envoye', 'repondu', 'humain', 'rdv', 'clos'] as $e) {
        expect(EtatsProspect::appliquer($e, 'invitation_retiree'))->toBe(['etat' => $e, 'sans_effet' => true]);
    }
    expect(EtatsProspect::appliquer('invite', 'invitation_retiree'))->toBe(['etat' => 'invitation_retiree', 'sans_effet' => false])
        ->and(EtatsProspect::appliquer('message_envoye', 'plus_tard'))->toBe(['etat' => 'plus_tard', 'sans_effet' => false])
        ->and(EtatsProspect::appliquer('candidat', 'plus_tard')['sans_effet'])->toBeTrue()
        ->and(EtatsProspect::appliquer('en_pause', 'invitation_faite'))->toBe(['etat' => 'en_pause', 'sans_effet' => true])
        ->and(EtatsProspect::appliquer('sans_suite', 'a_repondu')['etat'])->toBe('repondu')
        ->and(EtatsProspect::appliquer('sans_suite', 'invitation_faite')['sans_effet'])->toBeTrue();
});

it('un recul est sans effet', function () {
    expect(EtatsProspect::appliquer('repondu', 'invitation_faite'))->toBe(['etat' => 'repondu', 'sans_effet' => true]);
});

it('ecarte est absorbant', function () {
    foreach (array_keys(EtatsProspect::GESTES) as $geste) {
        expect(EtatsProspect::appliquer('ecarte', $geste)['etat'])->toBe('ecarte');
    }
    expect(EtatsProspect::appliquer('ecarte', 'invitation_acceptee'))->toBe(['etat' => 'ecarte', 'sans_effet' => true]);
});

it('geste ou état inconnu ⇒ exception', function () {
    expect(fn () => EtatsProspect::appliquer('candidat', 'teleporter'))->toThrow(InvalidArgumentException::class);
    expect(fn () => EtatsProspect::appliquer('nimporte', 'non'))->toThrow(InvalidArgumentException::class);
});

it('message sans STOP ⇒ refus', function () {
    expect(fn () => TextePret::composer('Bonjour {{prenom}} ' . PL_POLITIQUE, ['prenom' => 'Exemple'], false))
        ->toThrow(InvalidArgumentException::class);
});

it('message sans lien de politique ⇒ refus', function () {
    expect(fn () => TextePret::composer('Bonjour, répondez STOP', [], false))->toThrow(InvalidArgumentException::class);
});

it('mots refusés, insensibles à la casse et aux accents', function () {
    foreach (['Certifié', 'CERTIFICATION', 'QUALIOPI', 'certifie', 'Qualiopi'] as $mot) {
        expect(fn () => TextePret::composer(plMessage("Nous sommes $mot. répondez STOP " . PL_POLITIQUE), [], false))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('invitation de 301 caractères ⇒ refus, 300 ⇒ accepté', function () {
    expect(fn () => TextePret::composer(str_repeat('a', 301), [], false, 'invitation'))->toThrow(InvalidArgumentException::class);
    expect(TextePret::composer(str_repeat('a', 300), [], false, 'invitation'))->toHaveLength(300);
});

it('variable hors liste blanche ⇒ refus', function () {
    expect(fn () => TextePret::composer('Bonjour {{email}}', ['email' => 'x@example.test'], false, 'invitation'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => TextePret::composer('Bonjour {{prenom}}', [], false, 'invitation'))->toThrow(InvalidArgumentException::class);
});

it('essai ⇒ préfixe, et substitution correcte', function () {
    expect(TextePret::composer(plMessage(), ['prenom' => 'Exemple'], true))
        ->toBe('[ESSAI] Bonjour Exemple, répondez STOP pour ne plus être contacté. ' . PL_POLITIQUE);
    expect(TextePret::composer(plMessage(), ['prenom' => 'Exemple'], false))->not->toStartWith('[ESSAI]');
});

it('lien suivi exact', function () {
    expect(LienSuivi::pour('a1', 'guide'))->toBe(
        'https://site.example.test/guide?utm_source=linkedin&utm_medium=message&utm_campaign=seq-a1&depuis=message-linkedin',
    );
});

it('lien suivi : domaine absent ⇒ échec fermé', function () {
    config(['prospection_linkedin.site_url' => '']);
    expect(fn () => LienSuivi::pour('a1', 'guide'))->toThrow(InvalidArgumentException::class);
});

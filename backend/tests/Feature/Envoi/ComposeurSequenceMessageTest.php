<?php

/**
 * Envoi L8-COMPO — compositeur de séquence et expéditeur par espace. Données FICTIVES (`*.example.test`).
 * Aucun réseau, aucun envoi réel : composition seule, FauxServeurEnvoi absent.
 */

use App\Envoi\Reglages\DepotReglages;
use App\Envoi\Sequence\ComposeurSequenceMessage;
use App\Envoi\Sequence\DemandeComposition;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\SourceExpeditionDb;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Prépare un espace complet (identité, domaine, boîte) ; retourne son id. */
function compoEspace(string $nom, string $domaine, bool $identite = true, bool $domaineOk = true, bool $boite = true): string
{
    $ws = F::espace($nom);
    config(['envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test']]);
    WorkspaceContext::set($ws);
    if ($identite) {
        app(DepotReglages::class)->enregistrerIdentite($ws, [
            'raison_sociale' => 'ZZ Expediteur', 'forme' => 'SAS', 'adresse_postale' => '1 rue de l\'Exemple, 00000 Ville-Test',
            'signature' => 'Equipe ZZ', 'lien_rendez_vous' => null, 'bloc_information' => 'Vos donnees viennent d\'une source publique (article 14 du RGPD).',
        ], 'test');
    }
    if ($domaineOk) {
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $domaine, 'achete_le' => '2026-01-01']);
    }
    if ($boite) {
        DB::table('envoi_boites')->insert([
            'workspace_id' => $ws, 'adresse' => 'contact@' . $domaine, 'hote' => 'imap.example.test', 'port' => 587, 'identifiants_chiffres' => 'x',
        ]);
    }

    return $ws;
}

function compoDemande(string $ws, string $expediteur, ?string $inReplyTo = null): DemandeComposition
{
    return new DemandeComposition($ws, 'dest@client.example.test', $expediteur, 'Sujet test', "Bonjour,\nCorps test.", '<abc123@zz-a.example.test>', $inReplyTo, $inReplyTo === null ? [] : [$inReplyTo]);
}

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('le message porte les en-têtes de désinscription, l\'entité, l\'adresse postale, l\'article 14 et le Message-ID réservé', function () {
    $ws = compoEspace('zz-compo-a', 'zz-a.example.test');
    $source = app(SourceExpeditionDb::class);
    $composeur = $source->composeurPour($ws);
    expect($composeur)->toBeInstanceOf(ComposeurSequenceMessage::class);

    $brut = $composeur->composer(compoDemande($ws, 'contact@zz-a.example.test', '<prec@zz-a.example.test>'));

    $brut = preg_replace('/\r\n[ \t]+/', ' ', $brut); // en-têtes dépliés (RFC 5322)
    expect($brut)->toContain('List-Unsubscribe: <https://app.example.test/')
        ->and($brut)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click')
        ->and($brut)->toContain('mailto:contact@zz-a.example.test')
        ->and($brut)->toContain("Message-ID: <abc123@zz-a.example.test>\r\n")
        ->and($brut)->toContain('In-Reply-To: <prec@zz-a.example.test>')
        ->and($brut)->toContain('From: ZZ Expediteur <contact@zz-a.example.test>');
    // Corps décodé : mentions légales et article 14 présents (quoted-printable).
    $decode = quoted_printable_decode($brut);
    expect($decode)->toContain('ZZ Expediteur SAS')->and($decode)->toContain('Ville-Test')->and($decode)->toContain('article 14 du RGPD');
    // Aucun suivi d'ouverture : ni pixel, ni image distante.
    expect(strtolower($brut))->not->toContain('<img')->and(strtolower($brut))->not->toContain('pixel');
    // Un seul Message-ID dans l'en-tête.
    expect(substr_count($brut, "\r\nMessage-ID:") + (str_starts_with($brut, 'Message-ID:') ? 1 : 0))->toBe(1);
});

test('espace sans identité, sans domaine ou sans boîte : refus, aucun Sequenceur, aucune injection', function (bool $identite, bool $domaine, bool $boite) {
    $ws = compoEspace('zz-compo-b', 'zz-b.example.test', $identite, $domaine, $boite);
    $faux = new FauxServeurEnvoi;

    expect(fn () => app(SourceExpeditionDb::class)->sequenceurPour($ws, $faux, new LecteurFicheDb))->toThrow(RuntimeException::class)
        ->and($faux->appels)->toBe(0);
})->with([
    'sans identité' => [false, true, true],
    'sans domaine' => [true, false, true],
    'sans boîte' => [true, true, false],
]);

test('la fabrique rend un Sequenceur DISTINCT par espace, chacun avec son expéditeur', function () {
    $a = compoEspace('zz-compo-c1', 'zz-c1.example.test');
    $b = compoEspace('zz-compo-c2', 'zz-c2.example.test');
    $source = app(SourceExpeditionDb::class);
    $faux = new FauxServeurEnvoi;

    $sa = $source->sequenceurPour($a, $faux, new LecteurFicheDb);
    $sb = $source->sequenceurPour($b, $faux, new LecteurFicheDb);
    $sa2 = $source->sequenceurPour($a, $faux, new LecteurFicheDb);

    $expediteur = fn (object $s): string => (new ReflectionProperty($s, 'expedition'))->getValue($s)->expediteur;
    expect($sa)->not->toBe($sb)->and($sa)->not->toBe($sa2)
        ->and($expediteur($sa))->toBe('contact@zz-c1.example.test')
        ->and($expediteur($sb))->toBe('contact@zz-c2.example.test');
});

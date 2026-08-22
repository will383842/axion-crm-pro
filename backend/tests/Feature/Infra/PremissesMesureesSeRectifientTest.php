<?php

/**
 * GARDE : UNE PREMISSE MESUREE QUI A CHANGE PORTE SA RECTIFICATION DATEE
 * — constat H47-003 (S3).
 *
 * CE QUI ETAIT ECRIT, ET QUI NE L ETAIT PLUS VRAI.
 *
 * `.github/dependabot.yml` affirmait, en tete, sous le titre « PREREQUIS NON
 * REMPLI, mesure le 2026-08-18 » :
 *
 *     GET /repos/will383842/axion-crm-pro/vulnerability-alerts -> 404
 *     security_and_analysis.dependabot_security_updates.status -> "disabled"
 *     « Les alertes Dependabot sont DESACTIVEES sur ce depot : il n y a donc,
 *       a ce jour, AUCUN canal de mise a jour de securite a preserver. »
 *     « La precaution ci-dessus est correcte mais INERTE. »
 *
 * Les trois affirmations sont fausses depuis le 2026-08-19. Mesure de ce
 * jour-la, trois releves concordants
 * (`04_PREUVES/agent-47/etat-dependabot-depot.txt` de l audit 360) :
 *
 *     vulnerability-alerts ............ HTTP/2.0 204 No Content   (actives)
 *     dependabot_security_updates ..... {"status":"enabled"}
 *     automated-security-fixes ........ {"enabled":true,"paused":false}
 *
 * Temoin negatif de cette mesure : la MEME reponse rend "disabled" pour
 * `secret_scanning_non_provider_patterns` et `secret_scanning_validity_checks`.
 * L API ne repond donc pas "enabled" par defaut. Corroboration independante :
 * les 57 alertes du depot portent toutes `created_at` au 2026-08-19.
 *
 * POURQUOI CELA MERITE UNE GARDE, ALORS QUE C EST « DU COMMENTAIRE ».
 *
 * Parce que ce commentaire-la est une PROCEDURE. Il enonce les trois criteres
 * d entree du degel du gel Dependabot, dont « les alertes Dependabot sont
 * ACTIVES sur le depot » — critere deja rempli, que le fichier presentait
 * encore comme bloquant. Qui lit ce fichier pour decider du degel raisonne sur
 * une premisse perimee : il croit devoir attendre un geste de Will qui a deja
 * eu lieu. Un document qui ment est pire qu un document absent, parce qu on le
 * suit.
 *
 * CE QUE CETTE GARDE MESURE.
 *
 * Elle ne verifie PAS l etat reel des alertes cote GitHub — aucun test de ce
 * depot ne se connecte, et un test qui interrogerait l API rougirait le jour ou
 * le reseau tousse. Elle verifie ce qui est verifiable ici, et qui EST le
 * constat : que les deux documents portant la premisse perimee portent aussi sa
 * rectification datee, avec les valeurs mesurees, ET que la mesure d origine
 * n a pas ete effacee au passage. Rectifier en reecrivant l histoire ferait
 * perdre la raison pour laquelle le gel a ete redige ainsi.
 */

use PHPUnit\Framework\Assert;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotH47003(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function documentsPremisseH47003(): array
{
    return [
        '.github/dependabot.yml',
        '_REPORTS/2026-08-18_POLITIQUE-DEPENDANCES-ETAPE-0.md',
    ];
}

function lireDocumentH47003(string $relatif): string
{
    $chemin = racineDepotH47003() . '/' . $relatif;

    Assert::assertFileExists(
        $chemin,
        "{$relatif} est introuvable. En local, la copie du depot dans le conteneur de banc se "
        . 'fait effacer par les autres agents : re-copier avant de croire un resultat.',
    );

    $contenu = (string) file_get_contents($chemin);

    Assert::assertNotSame('', trim($contenu), "{$relatif} est vide : la garde ne mesurerait rien.");

    return $contenu;
}

/**
 * Le document porte-t-il une rectification datee du 2026-08-19, avec ses chiffres ?
 *
 * On exige les VALEURS mesurees, pas seulement le mot « rectification » : une
 * note qui affirmerait « les alertes sont actives » sans citer le releve serait
 * une seconde premisse non mesuree, c est-a-dire le defaut d origine repose.
 */
function rectificationComplete2026H47003(string $contenu): bool
{
    foreach ([
        'RECTIFICATION 2026-08-19',
        '204 No Content',
        'enabled',
        'automated-security-fixes',
    ] as $exigence) {
        if (! str_contains($contenu, $exigence)) {
            return false;
        }
    }

    return true;
}

test('H47-003 — TEMOIN NEGATIF : le controle sait refuser une rectification incomplete', function (): void {
    // Sans ce temoin, `rectificationComplete2026H47003()` pourrait rendre vrai
    // sur n importe quoi, et les deux tests suivants passeraient au vert sans
    // rien inspecter. C est le pire des verts, et ce depot l a deja paye.
    expect(rectificationComplete2026H47003('rien du tout'))->toBeFalse();

    // Le mot sans les chiffres ne suffit pas : c est precisement le risque de
    // ce correctif — remplacer une premisse perimee par une premisse affirmee.
    expect(rectificationComplete2026H47003('RECTIFICATION 2026-08-19 : les alertes sont actives.'))
        ->toBeFalse('Une rectification sans le releve cite doit etre REFUSEE.');

    expect(rectificationComplete2026H47003(
        'RECTIFICATION 2026-08-19 : vulnerability-alerts -> HTTP/2.0 204 No Content ; '
        . 'dependabot_security_updates -> {"status":"enabled"} ; automated-security-fixes -> ok',
    ))->toBeTrue('Une rectification citant les trois releves doit etre ACCEPTEE.');
});

test('H47-003 — les deux documents du gel portent la rectification datee du 2026-08-19', function (): void {
    foreach (documentsPremisseH47003() as $relatif) {
        $contenu = lireDocumentH47003($relatif);

        $this->assertTrue(
            rectificationComplete2026H47003($contenu),
            "🔴 {$relatif} enonce une premisse d etat du depot qui n est plus vraie, sans sa "
            . "rectification.\n\n"
            . 'Mesure du 2026-08-18, ecrite dans ce fichier : alertes Dependabot DESACTIVEES '
            . '(404, "disabled"), « AUCUN canal de mise a jour de securite a preserver », '
            . "precaution « INERTE ».\n"
            . 'Mesure du 2026-08-19 : vulnerability-alerts -> 204 No Content, '
            . 'dependabot_security_updates -> "enabled", automated-security-fixes -> '
            . "{\"enabled\":true,\"paused\":false}.\n\n"
            . 'Ce fichier enonce les criteres d entree du DEGEL. Qui le lit croit devoir '
            . "attendre un geste qui a deja eu lieu.\n\n"
            . 'GESTE : ajouter un encart « RECTIFICATION 2026-08-19 » qui CITE le releve '
            . '(204 No Content, "enabled", automated-security-fixes) et dit ce que cela change '
            . 'pour la procedure de degel. Ne PAS effacer la mesure du 2026-08-18.',
        );
    }
});

test('H47-003 — la rectification n a pas efface la mesure d origine', function (): void {
    foreach (documentsPremisseH47003() as $relatif) {
        $contenu = lireDocumentH47003($relatif);

        // La mesure du 2026-08-18 etait JUSTE ce jour-la, et c est elle qui
        // explique pourquoi le gel a ete redige ainsi. La retirer rendrait la
        // rectification incomprehensible : on lirait une correction sans savoir
        // ce qui est corrige, ni pourquoi la precaution `ignore`/`update-types`
        // a ete choisie plutot qu une plage `versions:`.
        $this->assertTrue(
            str_contains($contenu, '2026-08-18'),
            "🔴 {$relatif} ne porte plus la mesure d origine du 2026-08-18.\n\n"
            . 'Rectifier n est pas reecrire : on empile une mesure datee sur une autre, on n en '
            . "remplace jamais une.\n"
            . 'GESTE : restaurer le bloc de mesure du 2026-08-18 au-dessus de la rectification.',
        );
    }
});

test('H47-003 — le critere d entree du degel est marque REMPLI dans dependabot.yml', function (): void {
    $contenu = lireDocumentH47003('.github/dependabot.yml');

    // C est la consequence OPERATIONNELLE du constat, et la seule qui change un
    // geste : la procedure de degel enumere trois criteres cumulatifs, et le
    // deuxieme — « les alertes Dependabot sont ACTIVES sur le depot » — est
    // rempli depuis le 2026-08-19. Une rectification qui laisserait la
    // procedure inchangee corrigerait le constat sans corriger la decision.
    $this->assertTrue(
        str_contains($contenu, 'REMPLI depuis le 2026-08-19'),
        '🔴 La procedure de degel de .github/dependabot.yml presente encore « les alertes '
        . "Dependabot sont ACTIVES » comme un critere a satisfaire.\n\n"
        . 'Il est satisfait depuis le 2026-08-19 (204 No Content / "enabled" / '
        . "automated-security-fixes non pause).\n"
        . 'GESTE : marquer ce critere « REMPLI depuis le 2026-08-19 » en renvoyant a la '
        . 'rectification, sans toucher aux deux autres criteres, qui eux ne sont pas mesures ici.',
    );
});

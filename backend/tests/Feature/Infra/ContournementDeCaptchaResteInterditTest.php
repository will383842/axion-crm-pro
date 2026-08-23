<?php

/**
 * GARDE : LE CONTOURNEMENT DE CAPTCHA RESTE INERTE — constat C19-012 (S3).
 *
 * CE QUE L'AUDIT A TROUVÉ, ET CE QU'IL N'A PAS TROUVÉ.
 *
 * Il n'a PAS trouvé de contournement de captcha en service. `TwoCaptchaSolver`
 * ne fait que jeter une exception, et `MockServicesProvider` refuse tout
 * simulacre en production : la production résout donc `TwoCaptchaSolver`, qui
 * échoue immédiatement. Aucune énigme n'est résolue par un tiers payant.
 *
 * Ce qu'il a trouvé, c'est qu'**aucune règle ne l'interdisait**. La classe est
 * câblée comme implémentation réelle (`MockServicesProvider.php:107`), la clé
 * d'API est déjà prévue (`.env.example:181 TWOCAPTCHA_API_KEY=`), et le seul
 * verrou était une valeur d'exploitation, `MOCK_CAPTCHA=true`. Écrire dix
 * lignes dans le corps de `solve()` suffisait à mettre le service en marche —
 * sans revue, sans décision, sans que personne le sache.
 *
 * Résoudre les captchas d'un tiers par un service payant engage le dépôt
 * juridiquement (conditions d'utilisation des sites visités) et vis-à-vis du
 * RGPD sur la collecte qui en découle. Ce n'est pas une question technique :
 * **c'est un arbitrage, et il appartient à Will.** Cette garde ne tranche donc
 * rien. Elle gèle l'état mesuré le 2026-08-22 — corps inerte — pour que la mise
 * en service ne puisse plus se faire par inadvertance : quiconque implémente
 * `solve()` fait rougir la campagne, et devra dire pourquoi.
 *
 * ⚠️ CE QUI MANQUE ENCORE, et cette garde ne peut pas le fournir : la décision
 * écrite et datée dans `_AUDIT/05_DECISIONS.md` (contournement refusé, ou
 * autorisé sous conditions). Tant qu'elle n'existe pas, ce fichier dit
 * seulement « personne n'a décidé », pas « on a décidé non ».
 */

use App\Contracts\CaptchaSolver;
use App\Services\Captcha\TwoCaptchaSolver;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Le corps de `solve()`, commentaires retirés.
 *
 * On passe par la réflexion plutôt que par une expression régulière sur le
 * fichier : `getStartLine()` / `getEndLine()` désignent la méthode réelle,
 * même si une autre classe du dépôt porte une méthode du même nom.
 */
function corpsDeSolveDuSolveurCaptcha(): string
{
    $methode = new ReflectionMethod(TwoCaptchaSolver::class, 'solve');
    $fichier = (string) $methode->getFileName();

    $lignes = array_slice(
        file($fichier, FILE_IGNORE_NEW_LINES) ?: [],
        $methode->getStartLine() - 1,
        $methode->getEndLine() - $methode->getStartLine() + 1,
    );

    // On découpe entre la PREMIÈRE accolade ouvrante et la DERNIÈRE fermante :
    // se fier au numéro de ligne suivant la signature suppose que l'accolade est
    // sur sa propre ligne, ce qu'un reformatage suffirait à démentir.
    $methodeEntiere = implode("\n", $lignes);
    $ouvrante = strpos($methodeEntiere, '{');
    $fermante = strrpos($methodeEntiere, '}');

    if ($ouvrante === false || $fermante === false || $fermante <= $ouvrante) {
        return '';
    }

    $corps = substr($methodeEntiere, $ouvrante + 1, $fermante - $ouvrante - 1);

    // Les commentaires ne sont pas du code : un `// return $token;` ne met rien
    // en service, et faire rougir dessus serait crier au loup.
    $corps = preg_replace('~/\*.*?\*/~s', '', $corps) ?? $corps;
    $corps = preg_replace('~(^|\s)(//|#)[^\n]*~', '', $corps) ?? $corps;

    return trim((string) preg_replace('/\s+/', ' ', $corps));
}

/**
 * TÉMOIN — sans lui, une réflexion qui rendrait la chaîne vide ferait passer
 * la garde au vert en n'ayant rien lu du tout.
 */
test('C19-012 — TEMOIN : le banc lit bien le corps de TwoCaptchaSolver::solve()', function () {
    $corps = corpsDeSolveDuSolveurCaptcha();

    expect($corps)->not->toBe(
        '',
        'Le banc lit un corps VIDE pour TwoCaptchaSolver::solve(). Une garde qui inspecte le '
        . "neant certifie l'absence d'un defaut qu'elle n'a pas regarde. Repare "
        . '`corpsDeSolveDuSolveurCaptcha()` avant de croire ce vert.',
    );
});

test('C19-012 — solve() ne fait toujours RIEN d autre que jeter une exception', function () {
    $corps = corpsDeSolveDuSolveurCaptcha();

    // Mesure du 2026-08-22 : le corps entier tient en UNE instruction, un `throw`.
    //
    // On compte les instructions plutôt que d'écrire une expression régulière sur
    // la forme exacte du `throw` : le premier essai de cette garde cherchait
    // `\LogicException` et rougissait sur l'échappement de l'antislash, pas sur
    // le code. Une garde qui rougit sur elle-même finit désactivée.
    $instructions = array_values(array_filter(array_map('trim', explode(';', $corps))));

    $inerte = count($instructions) === 1 && str_starts_with($instructions[0], 'throw new ');

    expect($inerte)->toBeTrue(
        "Le corps de `TwoCaptchaSolver::solve()` n'est plus un simple `throw` :\n\n"
        . "    {$corps}\n\n"
        . "C'est le constat C19-012. Resoudre les captchas d'un tiers par un service payant "
        . "engage le depot juridiquement (conditions d'utilisation des sites visites) et sur le "
        . "RGPD de la collecte qui en decoule. Ce n'est PAS un arbitrage technique.\n\n"
        . 'GESTE : si la mise en service est voulue, fais-la trancher par Will et ecris la '
        . 'decision datee dans `_AUDIT/05_DECISIONS.md`, PUIS remplace cette garde par celle qui '
        . "encadre l'usage (quota, journalisation, domaines autorises). Ne la supprime pas "
        . 'seulement pour faire passer la campagne au vert.',
    );

    // Et on le vérifie à l'exécution, pas seulement dans le texte : un corps qui
    // « ressemble » à un throw mais rend un jeton passerait le contrôle statique.
    expect(fn () => (new TwoCaptchaSolver)->solve(['siteKey' => 'x', 'pageUrl' => 'https://exemple.test']))
        ->toThrow(LogicException::class);
});

test('C19-012 — le contrat CaptchaSolver est toujours cable sur la classe inerte', function () {
    $provider = (string) file_get_contents(app_path('Providers/MockServicesProvider.php'));

    // Sans cette assertion, il suffirait d'ecrire un `VraiCaptchaSolver` a cote
    // et de rebrancher la liaison : `solve()` resterait un `throw`, la garde
    // ci-dessus resterait verte, et le contournement serait pourtant en service.
    expect(str_contains($provider, '$bind(CaptchaSolver::class, TwoCaptchaSolver::class,'))->toBeTrue(
        'La liaison de `' . CaptchaSolver::class . '` ne designe plus `TwoCaptchaSolver` comme '
        . 'implementation reelle. Une autre classe a donc pris sa place, et la garde qui verifie '
        . 'que `solve()` jette une exception ne mesure plus le service reellement branche '
        . "(constat C19-012).\n\n"
        . 'GESTE : fais trancher la mise en service par Will et ecris la decision datee dans '
        . '`_AUDIT/05_DECISIONS.md` avant de rebrancher ce contrat.',
    );
});

<?php

/**
 * NR-07 — R1, la liste des tâches planifiées (`routes/console.php`).
 *
 * L’instantané `instantanes/nr07-taches-planifiees.json` fige, pour chaque
 * tâche : sa commande, son horaire, son fuseau, son verrou (durée en minutes),
 * son exécution en arrière-plan, sa limite à un serveur, et le NOMBRE de
 * conditions (`when`) et de sauts (`skip`) — c’est-à-dire les « drapeaux de
 * saut » (`CRM_PURGE_ENABLED`, `CRM_OUTBOUND_ENABLED`…). AJOUTER, RETIRER ou
 * DÉPLACER une tâche fait rougir ce test tant que la photo n’est pas mise à
 * jour DANS LA MÊME PR (« Filet modifié : pourquoi »).
 *
 * Gardes existantes rattachées au groupe : `VerrousDuPlanificateurTest`,
 * `CommentairesDuPlanificateurTest`.
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class);

/** @return list<Event> */
function nr07Evenements(): array
{
    Artisan::call('list', ['--format' => 'txt']); // charge routes/console.php

    return array_values(app(Schedule::class)->events());
}

function nr07Nom(Event $e): string
{
    $brut = trim((string) ($e->command ?? ''));
    if ($brut === '') {
        return 'closure:' . trim((string) $e->description);
    }

    // « '/usr/bin/php' 'artisan' crm:xxx --opt » => « crm:xxx --opt »
    return trim((string) preg_replace("/^.*?['\"]?artisan['\"]?\s+/", '', $brut));
}

function nr07Compte(Event $e, string $propriete): int
{
    $p = new ReflectionProperty(Event::class, $propriete);

    return count($p->getValue($e));
}

/** @return list<array<string, mixed>> */
function nr07Instantane(): array
{
    $lignes = array_map(static fn (Event $e): array => [
        'tache' => nr07Nom($e),
        'horaire' => $e->expression,
        'fuseau' => is_object($e->timezone) ? $e->timezone->getName() : (string) $e->timezone,
        'verrou_minutes' => $e->withoutOverlapping ? (int) $e->expiresAt : null,
        'en_arriere_plan' => (bool) $e->runInBackground,
        'un_seul_serveur' => (bool) $e->onOneServer,
        'conditions' => nr07Compte($e, 'filters'),
        'sauts' => nr07Compte($e, 'rejects'),
    ], nr07Evenements());

    usort($lignes, static fn (array $a, array $b): int => [$a['tache'], $a['horaire']] <=> [$b['tache'], $b['horaire']]);

    return $lignes;
}

test('NR-07 la liste des tâches planifiées est celle de la photo de référence', function () {
    Instantane::verifier('nr07-taches-planifiees', nr07Instantane());
});

test('NR-07 le planificateur contient au moins les 41 tâches restantes (témoin de couverture)', function () {
    // Le relevé (plan 21 § 1) parlait de 44 tâches + le battement ; L3c-0 en a
    // retiré 3 (les purges mensuelles). Un planificateur qui rendrait une liste
    // vide ferait passer la photo pour « trop courte » ici.
    expect(count(nr07Evenements()))->toBeGreaterThanOrEqual(41);
});

test('NR-07 le battement du planificateur tourne chaque minute', function () {
    $battement = array_values(array_filter(nr07Evenements(), fn (Event $e) => str_contains(nr07Nom($e), 'battement') || str_contains(nr07Nom($e), 'planificateur')));

    expect($battement)->not->toBe([])
        ->and($battement[0]->expression)->toBe('* * * * *');
});

test('NR-07 (L3c-0) plus aucune purge de personnes n\'est planifiée', function () {
    $purges = array_values(array_filter(
        nr07Evenements(),
        fn (Event $e) => str_contains(nr07Nom($e), 'rgpd:purge-')
            || str_contains(nr07Nom($e), 'prospection:purge-'),
    ));

    expect($purges)->toBe([]);
});

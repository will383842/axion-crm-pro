<?php

use App\Envoi\Depot\RetentionTentatives;

/**
 * R06 — la décision de conservation de `envoi_tentatives` est documentée et cohérente avec le code.
 * Documentation + non-régression : aucune suppression de données de personnes.
 */
const R06_DOC = __DIR__ . '/../../../../docs/envoi/retention-envoi-tentatives-r06.md';
const R06_MIGRATION = __DIR__ . '/../../../database/migrations/2026_12_03_000060_envoi_tentatives_retention.php';

it('la durée documentée est celle du code et de la migration', function () {
    $doc = (string) file_get_contents(R06_DOC);
    $migration = (string) file_get_contents(R06_MIGRATION);

    expect(RetentionTentatives::CONSERVATION_JOURS)->toBe(400)
        ->and($doc)->toContain((string) RetentionTentatives::CONSERVATION_JOURS . ' jours')
        ->and($doc)->toContain('Aucune suppression de données de personnes')
        ->and($migration)->toContain('- ' . RetentionTentatives::CONSERVATION_JOURS);
});

it('la fonction d\'archivage agrège avant de retirer le détail, et ne touche que les tables techniques', function () {
    $migration = (string) file_get_contents(R06_MIGRATION);
    $insert = strpos($migration, 'INSERT INTO public.envoi_tentatives_jours');
    $delete = strpos($migration, 'DELETE FROM public.envoi_tentatives WHERE');

    expect($insert)->not->toBeFalse()->and($delete)->not->toBeFalse()->and($insert)->toBeLessThan($delete);
    preg_match_all('/DELETE FROM\s+(?:public\.)?(\w+)/i', $migration, $m);
    expect(array_unique($m[1]))->toBe(['envoi_tentatives']);
});

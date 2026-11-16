<?php

/**
 * X7 / M5 — `FormesAdresse::variantes` : pur, sans base. Adresses en `*.example.test` (dépôt PUBLIC).
 */

use App\Crm\Opposition\FormesAdresse;

test('forme d\'origine : minuscule et rognée, toujours en tête', function () {
    expect(FormesAdresse::variantes('  Nom@Exemple.TEST  ')[0])->toBe('nom@exemple.test');
});

test('espaces Unicode retirés partout (insécable, fine, largeur nulle, BOM…)', function () {
    foreach (["\u{00A0}", "\u{2007}", "\u{202F}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}", ' ', "\t"] as $e) {
        expect(FormesAdresse::variantes("nom{$e}@exemple.test{$e}"))->toContain('nom@exemple.test');
    }
});

test('domaine sans point final', function () {
    expect(FormesAdresse::variantes('Nom@Exemple.test.'))->toContain('nom@exemple.test');
});

test('partie locale tronquée avant le premier +', function () {
    expect(FormesAdresse::variantes('nom+x+y@exemple.test'))->toContain('nom@exemple.test')
        ->and(FormesAdresse::variantes('nom+x+y@exemple.test'))->toContain('nom+x+y@exemple.test');
});

test('IDN : forme accentuée et forme xn-- se retrouvent dans les deux sens', function () {
    $ascii = idn_to_ascii('exemplé.test', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    expect($ascii)->toStartWith('xn--')
        ->and(FormesAdresse::variantes('nom@exemplé.test'))->toContain("nom@{$ascii}")
        ->and(FormesAdresse::variantes("nom@{$ascii}"))->toContain('nom@exemplé.test');
});

test('combinaison : alias + point final + IDN + espace', function () {
    $ascii = idn_to_ascii('exemplé.test', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    expect(FormesAdresse::variantes("Nom+x@Exemplé.test.\u{00A0}"))->toContain("nom@{$ascii}")->toContain('nom@exemplé.test');
});

test('liste dédoublonnée, bornée, jamais vide', function () {
    $v = FormesAdresse::variantes("Nom+x@Exemplé.test.\u{00A0}");
    expect($v)->toBe(array_values(array_unique($v)))
        ->and(count($v))->toBeLessThanOrEqual(FormesAdresse::MAX)
        ->and(FormesAdresse::variantes('simple@exemple.test'))->toBe(['simple@exemple.test'])
        ->and(FormesAdresse::variantes(''))->not->toBeEmpty()
        ->and(FormesAdresse::variantes('pas-une-adresse'))->toBe(['pas-une-adresse']);
});

test('domaine IDN invalide : variante sautée, sans exception', function () {
    expect(FormesAdresse::variantes('nom@-.test'))->toContain('nom@-.test')
        ->and(fn () => FormesAdresse::variantes("nom@a\u{0000}b.test"))->not->toThrow(Throwable::class);
});

test('domaines : toutes les formes, sans point final', function () {
    $ascii = idn_to_ascii('exemplé.test', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    expect(FormesAdresse::domaines('a@Exemplé.test.'))->toEqualCanonicalizing(['exemplé.test', $ascii]);
});

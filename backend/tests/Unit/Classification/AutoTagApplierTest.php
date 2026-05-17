<?php

use App\Services\Classification\AutoTagApplier;
use App\Models\Company;

test('matches simple equality rule', function () {
    $applier = new AutoTagApplier();
    $company = new Company(['naf' => '6201Z', 'denomination' => 'Acme']);
    expect($applier->matches($company, ['field' => 'naf', 'op' => '=', 'value' => '6201Z']))->toBeTrue();
    expect($applier->matches($company, ['field' => 'naf', 'op' => '=', 'value' => '4711F']))->toBeFalse();
});

test('matches starts_with rule', function () {
    $applier = new AutoTagApplier();
    $company = new Company(['naf' => '6201Z']);
    expect($applier->matches($company, ['field' => 'naf', 'op' => 'starts_with', 'value' => '62']))->toBeTrue();
    expect($applier->matches($company, ['field' => 'naf', 'op' => 'starts_with', 'value' => '47']))->toBeFalse();
});

test('matches all-of compound rule', function () {
    $applier = new AutoTagApplier();
    $company = new Company(['naf' => '6201Z', 'size_category' => 'pme']);
    expect($applier->matches($company, [
        'all' => [
            ['field' => 'naf', 'op' => 'starts_with', 'value' => '62'],
            ['field' => 'size_category', 'op' => '=', 'value' => 'pme'],
        ],
    ]))->toBeTrue();
});

test('matches any-of compound rule', function () {
    $applier = new AutoTagApplier();
    $company = new Company(['naf' => '6201Z']);
    expect($applier->matches($company, [
        'any' => [
            ['field' => 'naf', 'op' => '=', 'value' => 'XXXX'],
            ['field' => 'naf', 'op' => 'starts_with', 'value' => '62'],
        ],
    ]))->toBeTrue();
});

test('regex operator works', function () {
    $applier = new AutoTagApplier();
    $company = new Company(['denomination' => 'Acme Tech Solutions']);
    expect($applier->matches($company, ['field' => 'denomination', 'op' => 'regex', 'value' => '/Tech/i']))->toBeTrue();
});

test('in operator works on array values', function () {
    $applier = new AutoTagApplier();
    $company = new Company(['size_category' => 'pme']);
    expect($applier->matches($company, ['field' => 'size_category', 'op' => 'in', 'value' => ['pme', 'eti']]))->toBeTrue();
    expect($applier->matches($company, ['field' => 'size_category', 'op' => 'in', 'value' => ['tpe', 'grande_entreprise']]))->toBeFalse();
});

test('numeric comparison operators', function () {
    $applier = new AutoTagApplier();
    $company = new Company(['quality_score' => 85]);
    expect($applier->matches($company, ['field' => 'quality_score', 'op' => '>=', 'value' => 80]))->toBeTrue();
    expect($applier->matches($company, ['field' => 'quality_score', 'op' => '<', 'value' => 50]))->toBeFalse();
});

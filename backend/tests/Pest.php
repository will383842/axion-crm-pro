<?php

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function something(): bool
{
    return true;
}

<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test cases
|--------------------------------------------------------------------------
|
| Feature, Isolation and Architecture tests boot the application against
| the real test databases (tea_test_*). Unit tests are plain PHPUnit.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Isolation', 'Architecture');

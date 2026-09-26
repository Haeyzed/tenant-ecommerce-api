<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

arch('no debugging helpers are left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'ddd'])
    ->not->toBeUsed();

arch('no repository layer (spec §73.2)')
    ->expect('App')
    ->not->toHaveSuffix('Repository');

arch('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();

it('keeps env() calls inside config files', function (): void {
    $offenders = [];

    foreach (Finder::create()->in([app_path(), database_path(), base_path('routes')])->name('*.php')->files() as $file) {
        if (preg_match('/(?<![\w>$:])env\(/', $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('loads migrations only from the documented locations', function (): void {
    $offenders = [];

    foreach (Finder::create()->in(app_path('Modules'))->name('*.php')->files() as $file) {
        if (str_contains($file->getContents(), 'loadMigrationsFrom')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('never logs secrets by key name', function (): void {
    $offenders = [];

    foreach (Finder::create()->in(app_path())->name('*.php')->files() as $file) {
        foreach (preg_split('/\R/', $file->getContents()) ?: [] as $number => $line) {
            if (preg_match('/Log::\w+\(/', $line) && preg_match("/'(password|secret|api_key|access_token|refresh_token|private_key|webhook_secret|credentials)'\s*=>/i", $line)) {
                $offenders[] = $file->getRelativePathname().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

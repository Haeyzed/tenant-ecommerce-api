<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

it('never resolves platform roles through spatie\'s context-dependent role() scope', function (): void {
    $offenders = [];

    foreach (Finder::create()->in(app_path())->name('*.php')->files() as $file) {
        foreach (preg_split('/\R/', $file->getContents()) ?: [] as $number => $line) {
            if (preg_match("/->role\\(.*('platform'|GUARD)/", $line)) {
                $offenders[] = $file->getRelativePathname().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

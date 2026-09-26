<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Finder\Finder;

it('declares tries, timeout and (when retried) backoff on every queued job', function (): void {
    $problems = [];

    foreach (Finder::create()->in(app_path('Modules'))->path('Jobs')->name('*.php')->files() as $file) {
        $class = 'App\\Modules\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $defaults = (new ReflectionClass($class))->getDefaultProperties();

        if (! isset($defaults['tries'], $defaults['timeout'])) {
            $problems[] = $class.': tries and timeout are required';
        } elseif ($defaults['tries'] > 1 && ! isset($defaults['backoff'])) {
            $problems[] = $class.': backoff is required when the job retries';
        }
    }

    expect($problems)->toBe([]);
});

it('keeps landlord jobs off tenant queues and bulk work on tenant-bulk', function (): void {
    $queues = ['landlord-default', 'tenant-critical', 'tenant-default', 'tenant-bulk'];
    $problems = [];

    foreach (Finder::create()->in(app_path('Modules'))->path('Jobs')->name('*.php')->files() as $file) {
        if (preg_match_all("/onQueue\\('([^']+)'\\)/", $file->getContents(), $m)) {
            foreach (array_diff($m[1], $queues) as $queue) {
                $problems[] = $file->getRelativePathname().': unknown queue '.$queue;
            }
        } else {
            $problems[] = $file->getRelativePathname().': no explicit queue';
        }
    }

    expect($problems)->toBe([]);
});

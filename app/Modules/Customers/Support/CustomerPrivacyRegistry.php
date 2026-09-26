<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\Customer;
use Closure;

/**
 * Personal-data hooks per module (spec §26.4). Modules that hold customer
 * personal data register an eraser (run inside the anonymisation
 * transaction) and a section of the personal-data export. The Customers
 * module registers the account and addresses; orders, reviews, wishlists,
 * back-in-stock subscriptions and support messages register theirs as they
 * are built.
 */
final class CustomerPrivacyRegistry
{
    /** @var array<string, Closure(Customer): void> */
    private array $erasers = [];

    /** @var array<string, Closure(Customer): iterable<array<string, mixed>>> */
    private array $sections = [];

    /**
     * @param  Closure(Customer): void  $eraser
     */
    public function registerEraser(string $name, Closure $eraser): void
    {
        $this->erasers[$name] = $eraser;
    }

    /**
     * @param  Closure(Customer): iterable<array<string, mixed>>  $section  the records of this section
     */
    public function registerSection(string $name, Closure $section): void
    {
        $this->sections[$name] = $section;
    }

    public function erase(Customer $customer): void
    {
        foreach ($this->erasers as $eraser) {
            $eraser($customer);
        }
    }

    /**
     * @return iterable<array{section: string, record: array<string, mixed>}>
     */
    public function export(Customer $customer): iterable
    {
        foreach ($this->sections as $name => $section) {
            foreach ($section($customer) as $record) {
                yield ['section' => $name, 'record' => $record];
            }
        }
    }
}

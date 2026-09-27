<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Services;

use App\Modules\Expenses\Models\Biller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Billers (spec §57.4): payees for rent, utilities and one-off vendor bills.
 */
final class BillerService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createBiller(array $data): Biller
    {
        return Biller::query()->create($this->validate($data, true));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBiller(Biller $biller, array $data): Biller
    {
        $biller->fill($this->validate($data, false))->save();

        return $biller;
    }

    public function deactivateBiller(Biller $biller): Biller
    {
        $biller->forceFill(['is_active' => false])->save();

        return $biller;
    }

    /**
     * @param  array{category?: string, is_active?: bool, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Biller>
     */
    public function listBillers(array $filters = []): LengthAwarePaginator
    {
        return Biller::query()
            ->when(isset($filters['category']), static fn ($q) => $q->where('category', $filters['category']))
            ->when(isset($filters['is_active']), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when(isset($filters['search']), static fn ($q) => $q->where('name', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%'))
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return validator($data, [
            'name' => [$req, 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'category' => [$req, Rule::in(Biller::CATEGORIES)],
            'account_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();
    }
}

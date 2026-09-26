<?php

declare(strict_types=1);

namespace App\Modules\Returns\Services;

use App\Modules\Returns\Models\ReturnReason;
use Illuminate\Support\Collection;

/**
 * Return reasons (spec §41.6). Reasons are deactivated, never deleted:
 * returns keep pointing at them.
 */
final readonly class ReturnReasonService
{
    public const array DEFAULTS = [
        ['label' => 'Wrong item received', 'requires_photo' => true],
        ['label' => 'Item damaged or defective', 'requires_photo' => true],
        ['label' => 'Item not as described', 'requires_photo' => false],
        ['label' => 'Changed my mind', 'requires_photo' => false],
    ];

    /**
     * @return Collection<int, ReturnReason>
     */
    public function listReasons(bool $activeOnly = false): Collection
    {
        return ReturnReason::query()->when($activeOnly, static fn ($q) => $q->where('is_active', true))->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createReason(array $data): ReturnReason
    {
        /** @var ReturnReason */
        return ReturnReason::query()->create($this->validate($data, true))->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateReason(ReturnReason $reason, array $data): ReturnReason
    {
        $reason->fill($this->validate($data, false))->save();

        return $reason;
    }

    public function deactivateReason(ReturnReason $reason): ReturnReason
    {
        $reason->forceFill(['is_active' => false])->save();

        return $reason;
    }

    /**
     * Insert-only defaults (§9.5).
     */
    public function seedDefaults(): void
    {
        if (ReturnReason::query()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $reason) {
            ReturnReason::query()->create($reason);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        return validator($data, [
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'requires_photo' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
    }
}

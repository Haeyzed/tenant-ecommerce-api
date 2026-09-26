<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Models\PromotionRedemption;
use App\Modules\Promotions\Models\PromotionTarget;
use App\Modules\Promotions\Support\PromotionCache;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Promotion administration (spec §37.2, §37.8). Every change flushes the
 * automatic-promotion cache.
 */
final readonly class PromotionService
{
    /** Fields frozen once a promotion has redemptions (§37.2). */
    private const array LOCKED_AFTER_USE = ['scope', 'discount_type', 'discount_value', 'trigger'];

    public function __construct(private PromotionCache $cache) {}

    /**
     * @param  array{trigger?: string, scope?: string, is_active?: bool, status?: string, seller_id?: int, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Promotion>
     */
    public function listPromotions(array $filters): LengthAwarePaginator
    {
        $now = now();

        return Promotion::query()
            ->withCount('coupons')
            ->when($filters['trigger'] ?? null, static fn ($q, $v) => $q->where('trigger', $v))
            ->when($filters['scope'] ?? null, static fn ($q, $v) => $q->where('scope', $v))
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when($filters['seller_id'] ?? null, static fn ($q, $v) => $q->where('seller_id', $v))
            ->when(filled($filters['search'] ?? null), static fn ($q) => $q->where('name', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%'))
            ->when(($filters['status'] ?? null) === 'scheduled', static fn ($q) => $q->where('starts_at', '>', $now))
            ->when(($filters['status'] ?? null) === 'running', static fn ($q) => $q->where('is_active', true)
                ->where(static fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(static fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now)))
            ->when(($filters['status'] ?? null) === 'ended', static fn ($q) => $q->where('ends_at', '<=', $now))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @param  array<string, mixed>  $data  includes targets[]
     */
    public function createPromotion(array $data, ?User $by = null): Promotion
    {
        $validated = $this->validate($data, null);
        $targets = $this->validateTargets($data['targets'] ?? [], $validated['seller_id'] ?? null);

        $promotion = DB::connection('tenant')->transaction(function () use ($validated, $targets, $by): Promotion {
            $promotion = new Promotion(Arr::except($validated, ['targets']));
            $promotion->created_by_user_id = $by?->id;
            $promotion->save();
            $this->writeTargets($promotion, $targets);

            return $promotion;
        });

        $this->cache->flush();

        return $promotion->refresh()->load('targets');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePromotion(Promotion $promotion, array $data): Promotion
    {
        $validated = Arr::except($this->validate($data, $promotion), ['targets']);

        if (PromotionRedemption::query()->where('promotion_id', $promotion->id)->exists()) {
            foreach (self::LOCKED_AFTER_USE as $field) {
                if (array_key_exists($field, $validated) && (string) $validated[$field] !== (string) $promotion->getAttribute($field)) {
                    throw ApiException::unprocessable('promotion_in_use', 'This promotion has been used: end it and create a new one to change its rule.', ['field' => $field]);
                }
            }
        }

        if (isset($validated['usage_limit_total']) && $validated['usage_limit_total'] < $promotion->times_redeemed) {
            throw ValidationException::withMessages(['usage_limit_total' => ["The limit cannot be below the {$promotion->times_redeemed} uses so far."]]);
        }

        DB::connection('tenant')->transaction(function () use ($promotion, $validated, $data): void {
            $promotion->fill($validated)->save();

            if (array_key_exists('targets', $data)) {
                $this->writeTargets($promotion, $this->validateTargets((array) $data['targets'], $promotion->seller_id));
            }
        });

        $this->cache->flush();

        return $promotion->load('targets');
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    public function syncTargets(Promotion $promotion, array $targets): Promotion
    {
        $rows = $this->validateTargets($targets, $promotion->seller_id);
        DB::connection('tenant')->transaction(fn () => $this->writeTargets($promotion, $rows));
        $this->cache->flush();

        return $promotion->load('targets');
    }

    /**
     * An inactive copy of the rule and targets, without coupons or usage.
     */
    public function duplicatePromotion(Promotion $promotion, ?User $by = null): Promotion
    {
        $copy = DB::connection('tenant')->transaction(static function () use ($promotion, $by): Promotion {
            $copy = $promotion->replicate(['times_redeemed']);
            $copy->name = mb_substr($promotion->name.' (copy)', 0, 255);
            $copy->is_active = false;
            $copy->times_redeemed = 0;
            $copy->created_by_user_id = $by?->id;
            $copy->save();

            foreach ($promotion->targets as $target) {
                $copy->targets()->create($target->only(['target_type', 'target_id', 'mode']));
            }

            return $copy;
        });

        $this->cache->flush();

        return $copy->load('targets');
    }

    /**
     * A soft delete: redemptions keep their snapshots.
     */
    public function deletePromotion(Promotion $promotion): void
    {
        $promotion->delete();
        $this->cache->flush();
    }

    /**
     * @return array{by_status: array<string, int>, total_discount: string, orders: int}
     */
    public function getUsageStats(Promotion $promotion): array
    {
        $rows = PromotionRedemption::query()->where('promotion_id', $promotion->id)
            ->groupBy('status')->selectRaw('status, COUNT(*) as count, SUM(base_discount_amount) as total')->get();
        $counting = $rows->whereIn('status', PromotionRedemption::COUNTING);

        return [
            'by_status' => $rows->mapWithKeys(static fn ($r): array => [(string) $r->status => (int) $r->count])->all(),
            'total_discount' => bcadd((string) $counting->sum('total'), '0', 4),
            'orders' => (int) $counting->sum('count'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?Promotion $existing): array
    {
        $req = $existing === null ? 'required' : 'sometimes';
        $money = ['nullable', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'];

        $validated = validator($data, [
            'name' => [$req, 'string', 'max:255'],
            'public_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'trigger' => [$req, Rule::in([Promotion::AUTOMATIC, Promotion::COUPON])],
            'scope' => [$req, Rule::in(Promotion::SCOPES)],
            'discount_type' => [$req, Rule::in(Promotion::DISCOUNT_TYPES)],
            'discount_value' => ['sometimes', ...$money],
            'max_discount_amount' => ['sometimes', ...$money],
            'min_subtotal_amount' => ['sometimes', ...$money],
            'max_subtotal_amount' => ['sometimes', ...$money],
            'min_eligible_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'max_eligible_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'valid_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'valid_days.*' => ['integer', 'between:1,7', 'distinct'],
            'valid_time_from' => ['sometimes', 'nullable', 'date_format:H:i'],
            'valid_time_to' => ['sometimes', 'nullable', 'date_format:H:i'],
            'applies_to_online' => ['sometimes', 'boolean'],
            'applies_to_pos' => ['sometimes', 'boolean'],
            'applies_to_sale_items' => ['sometimes', 'boolean'],
            'first_order_only' => ['sometimes', 'boolean'],
            'usage_limit_total' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_exclusive' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'between:-1000,1000'],
            'seller_id' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'targets' => ['sometimes', 'array', 'max:1000'],
        ])->validate();

        // The resulting rule, for the cross-field checks.
        $rule = static fn (string $field): mixed => array_key_exists($field, $validated) ? $validated[$field] : $existing?->getAttribute($field);
        $errors = [];
        $type = $rule('discount_type');
        $value = $rule('discount_value');

        if (($rule('scope') === 'shipping') !== ($type === Promotion::FREE_SHIPPING)) {
            $errors['scope'] = 'Free shipping is the only shipping-scope discount, and it has shipping scope.';
        }

        if ($type === Promotion::FREE_SHIPPING) {
            $validated['discount_value'] = null;
        } elseif ($value === null || bccomp((string) $value, '0', 4) <= 0) {
            $errors['discount_value'] = 'The discount value must be greater than zero.';
        } elseif ($type === Promotion::PERCENTAGE && bccomp((string) $value, '100', 4) > 0) {
            $errors['discount_value'] = 'A percentage is at most 100.';
        }

        foreach ([['min_subtotal_amount', 'max_subtotal_amount'], ['min_eligible_quantity', 'max_eligible_quantity']] as [$min, $max]) {
            if ($rule($min) !== null && $rule($max) !== null && bccomp((string) $rule($min), (string) $rule($max), 4) > 0) {
                $errors[$max] = "The {$max} must be at least the {$min}.";
            }
        }

        if ($rule('starts_at') !== null && $rule('ends_at') !== null && strtotime((string) $rule('starts_at')) >= strtotime((string) $rule('ends_at'))) {
            $errors['ends_at'] = 'The end must be after the start.';
        }

        if (($rule('valid_time_from') === null) !== ($rule('valid_time_to') === null)) {
            $errors['valid_time_to'] = 'Set both ends of the time window, or neither.';
        }

        if (($rule('applies_to_online') ?? true) === false && ($rule('applies_to_pos') ?? true) === false) {
            $errors['applies_to_online'] = 'The promotion must apply to online or POS sales.';
        }

        if ($rule('seller_id') !== null && ! Schema::connection('tenant')->hasTable('sellers')) {
            $errors['seller_id'] = 'Seller-funded promotions need the marketplace.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $m): array => [$m], $errors));
        }

        return $validated;
    }

    /**
     * Each target row must name an existing record. A seller-funded
     * promotion targets only its seller and that seller's products.
     *
     * @param  array<mixed>  $targets
     * @return list<array{target_type: string, target_id: int, mode: string}>
     */
    private function validateTargets(array $targets, ?int $sellerId): array
    {
        $rows = validator(['targets' => $targets], [
            'targets' => ['array', 'max:1000'],
            'targets.*.target_type' => ['required', Rule::in(PromotionTarget::TYPES)],
            'targets.*.target_id' => ['required', 'integer', 'min:1'],
            'targets.*.mode' => ['sometimes', Rule::in(['include', 'exclude'])],
        ])->validate()['targets'] ?? [];

        $normalized = [];

        foreach ($rows as $index => $row) {
            $type = (string) $row['target_type'];
            $table = PromotionTarget::TABLES[$type];

            if (! Schema::connection('tenant')->hasTable($table) || ! DB::connection('tenant')->table($table)->where('id', $row['target_id'])->exists()) {
                throw ValidationException::withMessages(["targets.{$index}.target_id" => ["The {$type} does not exist."]]);
            }

            $key = $type.':'.$row['target_id'];

            if (isset($normalized[$key])) {
                throw ValidationException::withMessages(["targets.{$index}" => ['Each target appears once.']]);
            }

            $normalized[$key] = ['target_type' => $type, 'target_id' => (int) $row['target_id'], 'mode' => (string) ($row['mode'] ?? 'include')];
        }

        if ($sellerId !== null) {
            if (! isset($normalized['seller:'.$sellerId]) || $normalized['seller:'.$sellerId]['mode'] !== 'include') {
                throw ValidationException::withMessages(['targets' => ['A seller-funded promotion must include its seller.']]);
            }

            $productIds = array_column(array_filter($normalized, static fn (array $t): bool => $t['target_type'] === 'product'), 'target_id');

            if ($productIds !== [] && Product::query()->whereKey($productIds)->where(static fn ($q) => $q->whereNull('seller_id')->orWhere('seller_id', '!=', $sellerId))->exists()) {
                throw ValidationException::withMessages(['targets' => ['A seller-funded promotion may target only its seller\'s products.']]);
            }
        }

        return array_values($normalized);
    }

    /**
     * @param  list<array{target_type: string, target_id: int, mode: string}>  $rows
     */
    private function writeTargets(Promotion $promotion, array $rows): void
    {
        $promotion->targets()->delete();

        foreach ($rows as $row) {
            $promotion->targets()->create($row);
        }
    }
}

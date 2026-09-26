<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Services;

use App\Modules\Promotions\Jobs\GenerateCouponCodes;
use App\Modules\Promotions\Models\Coupon;
use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Models\PromotionRedemption;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Coupon codes (spec §37.4, §37.8). Codes are 4–32 characters of A–Z, 0–9
 * and "-", stored upper-cased. Generated codes avoid 0, O, 1 and I.
 */
final readonly class CouponService
{
    public const int SYNC_LIMIT = 1_000;

    public const int MAX_GENERATE = 100_000;

    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return LengthAwarePaginator<int, Coupon>
     */
    public function listCoupons(Promotion $promotion, array $filters = []): LengthAwarePaginator
    {
        return $promotion->coupons()
            ->when(filled($filters['search'] ?? null), static fn ($q) => $q->where('code', 'like', Coupon::normalize((string) $filters['search']).'%'))
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 50));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCoupon(Promotion $promotion, array $data): Coupon
    {
        $this->assertCouponPromotion($promotion);

        if (isset($data['code']) && is_string($data['code'])) {
            $data['code'] = Coupon::normalize($data['code']);
        }

        $validated = validator($data, [
            'code' => ['required', 'string', 'min:4', 'max:32', 'regex:/^[A-Z0-9-]+$/', Rule::unique('tenant.coupons', 'code')],
            ...$this->commonRules(),
        ])->validate();

        $coupon = new Coupon($validated);
        $coupon->promotion_id = $promotion->id;
        $coupon->save();

        return $coupon->refresh();
    }

    /**
     * Up to SYNC_LIMIT codes now; larger batches are queued (202).
     *
     * @param  array{prefix?: string|null, length?: int, usage_limit?: int|null}  $options
     * @return array{queued: bool, count: int}
     */
    public function generateCoupons(Promotion $promotion, int $count, array $options): array
    {
        $this->assertCouponPromotion($promotion);

        $validated = validator(['count' => $count, ...$options], [
            'count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_GENERATE],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9-]*$/'],
            'length' => ['sometimes', 'integer', 'min:6', 'max:20'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();

        $prefix = Coupon::normalize((string) ($validated['prefix'] ?? ''));
        $length = (int) ($validated['length'] ?? 10);

        if (mb_strlen($prefix) + $length > 32) {
            throw ApiException::unprocessable('coupon_code_too_long', 'The prefix and length together exceed 32 characters.');
        }

        if ($count > self::SYNC_LIMIT) {
            GenerateCouponCodes::dispatch((string) tenant()?->getTenantKey(), $promotion->id, $count, $prefix, $length, $validated['usage_limit'] ?? null)->afterCommit();

            return ['queued' => true, 'count' => $count];
        }

        return ['queued' => false, 'count' => $this->insertCodes($promotion, $count, $prefix, $length, $validated['usage_limit'] ?? null)];
    }

    /**
     * Inserts $count unique codes in chunks; a collision with an existing
     * code is retried with fresh codes.
     */
    public function insertCodes(Promotion $promotion, int $count, string $prefix, int $length, ?int $usageLimit): int
    {
        $inserted = 0;
        $attempts = 0;

        while ($inserted < $count) {
            $batch = min(500, $count - $inserted);
            $codes = [];

            while (count($codes) < $batch) {
                $code = $prefix.$this->randomCode($length);
                $codes[$code] = true;
            }

            $now = now();
            $rows = array_map(static fn (string $code): array => [
                'promotion_id' => $promotion->id, 'code' => $code, 'usage_limit' => $usageLimit,
                'times_redeemed' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], array_keys($codes));

            try {
                DB::connection('tenant')->table('coupons')->insert($rows);
                $inserted += count($rows);
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempts > 10) {
                    throw $e;
                }

                // Insert the codes that are still free, one query per chunk.
                $taken = DB::connection('tenant')->table('coupons')->whereIn('code', array_keys($codes))->pluck('code')->all();
                $free = array_values(array_filter($rows, static fn (array $row): bool => ! in_array($row['code'], $taken, true)));

                if ($free !== []) {
                    DB::connection('tenant')->table('coupons')->insert($free);
                    $inserted += count($free);
                }
            }
        }

        return $inserted;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCoupon(Coupon $coupon, array $data): Coupon
    {
        $coupon->fill(validator($data, $this->commonRules())->validate())->save();

        return $coupon;
    }

    /**
     * 409 once redeemed: deactivate instead.
     */
    public function deleteCoupon(Coupon $coupon): void
    {
        if (PromotionRedemption::query()->where('coupon_id', $coupon->id)->exists()) {
            throw ApiException::conflict('coupon_redeemed', 'This coupon has been used. Deactivate it instead.');
        }

        $coupon->delete();
    }

    /**
     * §70.12: one result per id.
     *
     * @param  list<int>  $ids
     * @return list<array{id: int, status: string, error: string|null, message: string|null}>
     */
    public function bulk(string $action, array $ids): array
    {
        $results = [];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            try {
                $coupon = Coupon::query()->findOrFail($id);
                $this->updateCoupon($coupon, ['is_active' => $action === 'activate']);
                $results[] = ['id' => $id, 'status' => 'ok', 'error' => null, 'message' => null];
            } catch (Throwable $e) {
                $results[] = ['id' => $id, 'status' => 'error', 'error' => $e instanceof ApiException ? $e->errorCode : 'not_found', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function findByCode(string $code): ?Coupon
    {
        return Coupon::query()->where('code', Coupon::normalize($code))->first();
    }

    private function assertCouponPromotion(Promotion $promotion): void
    {
        if ($promotion->trigger !== Promotion::COUPON) {
            throw ApiException::unprocessable('promotion_not_coupon', 'Only coupon-triggered promotions have codes.');
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function commonRules(): array
    {
        return [
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assigned_customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.customers', 'id')->whereNull('deleted_at')],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function randomCode(int $length): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}

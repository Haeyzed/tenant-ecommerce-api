<?php

declare(strict_types=1);

use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use Carbon\CarbonImmutable;

$now = CarbonImmutable::parse('2026-09-10 15:00:00', 'UTC');

it('resolves the last 30 days as complete days before today', function () use ($now): void {
    $range = DateRange::fromInput([], 'UTC', $now);

    expect($range->toArray())->toMatchArray(['preset' => 'last_30_days', 'from' => '2026-08-11', 'to' => '2026-09-09', 'interval' => 'day'])
        ->and($range->comparisonToArray())->toBe(['from' => '2026-07-12', 'to' => '2026-08-10'])
        ->and($range->buckets())->toHaveCount(30);
});

it('compares period-to-date ranges like for like', function () use ($now): void {
    $range = DateRange::fromInput(['range' => 'this_month'], 'UTC', $now);

    expect($range->comparisonFrom?->toDateTimeString())->toBe('2026-08-01 00:00:00')
        ->and($range->comparisonTo?->toDateTimeString())->toBe('2026-08-10 15:00:00')
        ->and($range->supportingLabel())->toBe('vs same days last month');

    $yearly = DateRange::fromInput(['range' => 'last_month', 'compare' => 'previous_year'], 'UTC', $now);
    expect($yearly->comparisonToArray())->toBe(['from' => '2025-08-01', 'to' => '2025-08-31']);

    expect(DateRange::fromInput(['compare' => 'none'], 'UTC', $now)->comparisonToArray())->toBeNull();
});

it('resolves ranges in the business timezone', function () use ($now): void {
    // 15:00 UTC is already the 11th in Auckland.
    $range = DateRange::fromInput(['range' => 'today'], 'Pacific/Auckland', $now);

    expect($range->from->toDateString())->toBe('2026-09-11')
        ->and($range->interval)->toBe('hour')
        ->and($range->startUtc()->toDateTimeString())->toBe('2026-09-10 12:00:00');
});

it('chooses and coarsens the interval', function () use ($now): void {
    expect(DateRange::fromInput(['range' => 'this_year'], 'UTC', $now)->interval)->toBe('month')
        ->and(DateRange::fromInput(['range' => 'last_month', 'interval' => 'week'], 'UTC', $now)->interval)->toBe('week')
        // 731 days by the hour would be 17,544 buckets.
        ->and(DateRange::fromInput(['range' => 'custom', 'from' => '2024-09-10', 'to' => '2026-09-09', 'interval' => 'hour'], 'UTC', $now)->interval)->toBe('week');
});

it('never reports a meaningless change percentage', function () use ($now): void {
    $range = DateRange::fromInput([], 'UTC', $now);

    $fromZero = KpiValue::count('orders', 'Orders', 5, $range, 0);
    expect($fromZero->comparison)->toMatchArray(['value' => 0, 'change_percent' => null, 'direction' => 'up', 'sentiment' => 'positive']);

    $flat = KpiValue::money('sales', 'Sales', '100000.0000', 'NGN', $range, '99999.9999');
    expect($flat->comparison)->toMatchArray(['direction' => 'flat', 'sentiment' => 'neutral', 'change_percent' => '0.0']);

    $churn = KpiValue::rate('churn', 'Churn', '3', '10', $range, ['1', '10'], KpiValue::DOWN_IS_GOOD);
    expect($churn->value)->toBe('30.0')
        ->and($churn->comparison)->toMatchArray(['value' => '10.0', 'change_percent' => '200.0', 'direction' => 'up', 'sentiment' => 'negative']);

    expect(KpiValue::rate('conversion', 'Conversion', '0', '0', $range)->value)->toBeNull()
        ->and(KpiValue::percentOf('1', '3'))->toBe('33.3')
        ->and(KpiValue::percentOf('2', '3'))->toBe('66.7');
});

<?php

declare(strict_types=1);

namespace App\Shared\Http\Requests;

use App\Shared\Metrics\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The standard query parameters of every dashboard, KPI and affiliate
 * portal endpoint (spec §22.1).
 */
final class MetricsRangeRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'range' => ['sometimes', Rule::in(DateRange::PRESETS)],
            'from' => ['required_if:range,custom', 'prohibited_unless:range,custom', 'date_format:Y-m-d'],
            'to' => ['required_if:range,custom', 'prohibited_unless:range,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare' => ['sometimes', Rule::in(DateRange::COMPARES)],
            'interval' => ['sometimes', Rule::in(DateRange::INTERVALS)],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            static function (Validator $validator): void {
                $data = $validator->getData();

                if (($data['range'] ?? null) !== 'custom' || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $days = (int) round(abs(strtotime((string) $data['to']) - strtotime((string) $data['from'])) / 86400);

                if ($days > DateRange::MAX_CUSTOM_DAYS) {
                    $validator->errors()->add('to', 'A custom range can span at most '.DateRange::MAX_CUSTOM_DAYS.' days.');
                }
            },
        ];
    }

    /**
     * @return array{range?: string, from?: string, to?: string, compare?: string, interval?: string, currency?: string}
     */
    public function rangeInput(): array
    {
        /** @var array{range?: string, from?: string, to?: string, compare?: string, interval?: string, currency?: string} $input */
        $input = $this->validated();

        return $input;
    }
}

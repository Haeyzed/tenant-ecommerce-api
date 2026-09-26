<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Support;

use App\Modules\CustomFields\Models\CustomFieldDefinition;
use App\Shared\Media\UploadRules;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * The field types (spec §23.3): which definition settings each accepts,
 * which value column it uses, and the rules a value must pass. Radio and
 * checkbox groups are display options of select and multi_select, not
 * types.
 */
final class CustomFieldTypes
{
    public const array TYPES = [
        'text', 'textarea', 'number', 'decimal', 'currency', 'boolean', 'date', 'datetime',
        'select', 'multi_select', 'url', 'email', 'phone', 'file', 'image',
    ];

    public const array FILE_TYPES = ['file', 'image'];

    public const array TEXT_TYPES = ['text', 'textarea'];

    /** Documents and images only; never executables, scripts or HTML. */
    public const array SAFE_FILE_EXTENSIONS = ['pdf', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx'];

    public const int MAX_UPLOAD_KB = 10240;

    public const int MAX_OPTIONS = 100;

    private const array DISPLAYS = [
        'select' => ['dropdown', 'radio'],
        'multi_select' => ['dropdown', 'checkboxes'],
        'boolean' => ['toggle', 'checkbox'],
    ];

    /**
     * Rules for the definition's `validation` object, per type.
     *
     * @return array<string, list<mixed>>
     */
    public static function validationRules(string $type): array
    {
        $minMax = ['validation.min' => ['sometimes', 'nullable', 'numeric'], 'validation.max' => ['sometimes', 'nullable', 'numeric', 'gte:validation.min']];
        $dates = [
            'validation.min_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'validation.max_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:validation.min_date'],
            'validation.not_in_past' => ['sometimes', 'boolean'],
            'validation.not_in_future' => ['sometimes', 'boolean'],
        ];

        return match ($type) {
            'text' => [
                'validation.min_length' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
                'validation.max_length' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
                'validation.pattern' => ['sometimes', 'nullable', 'string', 'max:200'],
            ],
            'textarea' => ['validation.max_length' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000']],
            'number' => ['validation.min' => ['sometimes', 'nullable', 'integer'], 'validation.max' => ['sometimes', 'nullable', 'integer', 'gte:validation.min']],
            'decimal' => [...$minMax, 'validation.decimal_places' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6']],
            'currency' => $minMax,
            'date', 'datetime' => $dates,
            'multi_select' => [
                'validation.min_selected' => ['sometimes', 'nullable', 'integer', 'min:0'],
                'validation.max_selected' => ['sometimes', 'nullable', 'integer', 'min:1', 'gte:validation.min_selected'],
            ],
            'file' => [
                'validation.max_size_kb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_UPLOAD_KB],
                'validation.allowed_extensions' => ['sometimes', 'array'],
                'validation.allowed_extensions.*' => ['string', Rule::in(self::SAFE_FILE_EXTENSIONS)],
            ],
            'image' => ['validation.max_size_kb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_UPLOAD_KB]],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function displays(string $type): array
    {
        return self::DISPLAYS[$type] ?? [];
    }

    public static function hasOptions(string $type): bool
    {
        return in_array($type, ['select', 'multi_select'], true);
    }

    /**
     * A pattern is accepted only if it compiles and has no nested
     * quantifier, the usual source of catastrophic backtracking.
     */
    public static function isSafePattern(string $pattern): bool
    {
        if (preg_match('/\([^)]*[+*}][^)]*\)\s*[+*{]/', $pattern) === 1) {
            return false;
        }

        return @preg_match('~'.str_replace('~', '\~', $pattern).'~u', '') !== false;
    }

    /**
     * The value column of a type; null for files (stored as media).
     */
    public static function column(string $type): ?string
    {
        return match ($type) {
            'text', 'url', 'email', 'phone', 'select' => 'value_string',
            'textarea' => 'value_text',
            'number', 'decimal', 'currency', 'boolean' => 'value_decimal',
            'date' => 'value_date',
            'datetime' => 'value_datetime',
            'multi_select' => 'value_json',
            default => null,
        };
    }

    /**
     * Laravel rules for one value of the definition.
     *
     * @return list<mixed>
     */
    public static function valueRules(CustomFieldDefinition $d, string $timezone): array
    {
        $v = (array) $d->validation;
        $rules = match ($d->field_type) {
            'text' => ['string', 'max:'.min(255, (int) ($v['max_length'] ?? 255)), ...(isset($v['min_length']) ? ['min:'.(int) $v['min_length']] : []),
                ...(filled($v['pattern'] ?? null) ? ['regex:~'.str_replace('~', '\~', (string) $v['pattern']).'~u'] : [])],
            'textarea' => ['string', 'max:'.min(10000, (int) ($v['max_length'] ?? 10000))],
            'number' => ['integer', ...self::bounds($v)],
            'decimal' => ['numeric', 'decimal:0,'.(int) ($v['decimal_places'] ?? 6), ...self::bounds($v)],
            'currency' => ['numeric', 'decimal:0,4', ...self::bounds($v)],
            'boolean' => ['boolean', ...($d->is_required ? ['accepted'] : [])],
            'date' => ['date_format:Y-m-d', ...self::dateBounds($v, $timezone, 'Y-m-d')],
            'datetime' => ['date', ...self::dateBounds($v, $timezone, 'Y-m-d H:i:s')],
            'select' => ['string', Rule::in($d->optionValues())],
            'multi_select' => ['array', ...(isset($v['min_selected']) ? ['min:'.(int) $v['min_selected']] : []), ...(isset($v['max_selected']) ? ['max:'.(int) $v['max_selected']] : [])],
            'url' => ['string', 'max:255', 'url:http,https'],
            'email' => ['string', 'max:255', 'email:rfc'],
            'phone' => ['string', 'max:32', 'regex:/^\+?[0-9\s().-]{7,24}$/'],
            default => [],
        };

        return $rules;
    }

    /**
     * Rules for an uploaded file or image of the definition.
     *
     * @return list<string>
     */
    public static function uploadRules(CustomFieldDefinition $d): array
    {
        $v = (array) $d->validation;
        $max = min(self::MAX_UPLOAD_KB, (int) ($v['max_size_kb'] ?? self::MAX_UPLOAD_KB));

        if ($d->field_type === 'image') {
            return UploadRules::image($max);
        }

        $extensions = array_values(array_intersect((array) ($v['allowed_extensions'] ?? self::SAFE_FILE_EXTENSIONS), self::SAFE_FILE_EXTENSIONS));

        return ['file', 'extensions:'.implode(',', $extensions), 'max:'.$max];
    }

    /**
     * The stored form of a validated value: numbers as decimal strings,
     * booleans as 0/1, datetimes converted from the tenant timezone to
     * UTC, phones to E.164.
     */
    public static function normalize(CustomFieldDefinition $d, mixed $value, string $timezone): mixed
    {
        return match ($d->field_type) {
            'number' => (string) (int) $value,
            'decimal', 'currency' => bcadd((string) $value, '0', 6),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0',
            'datetime' => CarbonImmutable::parse((string) $value, $timezone)->utc()->format('Y-m-d H:i:s'),
            'phone' => self::e164((string) $value),
            'multi_select' => array_values(array_unique(array_map('strval', (array) $value))),
            'text', 'textarea' => trim((string) $value),
            default => $value,
        };
    }

    /**
     * The API form of a stored value.
     */
    public static function present(CustomFieldDefinition $d, mixed $stored, string $timezone): mixed
    {
        if ($stored === null) {
            return null;
        }

        return match ($d->field_type) {
            'number' => (int) $stored,
            'decimal', 'currency' => rtrim(rtrim(bcadd((string) $stored, '0', 6), '0'), '.') ?: '0',
            'boolean' => (bool) (int) $stored,
            'date' => $stored instanceof \DateTimeInterface ? $stored->format('Y-m-d') : (string) $stored,
            'datetime' => CarbonImmutable::parse($stored instanceof \DateTimeInterface ? $stored->format('Y-m-d H:i:s') : (string) $stored, 'UTC')->setTimezone($timezone)->toIso8601String(),
            default => $stored,
        };
    }

    public static function e164(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        return '+'.ltrim($digits, '0');
    }

    /**
     * @param  array<string, mixed>  $v
     * @return list<string>
     */
    private static function bounds(array $v): array
    {
        return [
            ...(isset($v['min']) ? ['min:'.$v['min']] : []),
            ...(isset($v['max']) ? ['max:'.$v['max']] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $v
     * @return list<string>
     */
    private static function dateBounds(array $v, string $timezone, string $format): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return [
            ...(isset($v['min_date']) ? ['after_or_equal:'.$v['min_date']] : []),
            ...(isset($v['max_date']) ? ['before_or_equal:'.$v['max_date'].($format === 'Y-m-d' ? '' : ' 23:59:59')] : []),
            ...(! empty($v['not_in_past']) ? ['after_or_equal:'.$today->format($format)] : []),
            ...(! empty($v['not_in_future']) ? ['before_or_equal:'.$today->endOfDay()->format($format === 'Y-m-d' ? 'Y-m-d' : 'Y-m-d H:i:s')] : []),
        ];
    }
}

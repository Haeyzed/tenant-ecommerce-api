<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Display date formats (spec §5.10). Only these tokens are accepted anywhere;
 * free-form format strings never are. API output never uses them.
 */
enum DisplayFormat: string
{
    case DayMonthYear = 'DD/MM/YYYY';
    case MonthDayYear = 'MM/DD/YYYY';
    case Iso = 'YYYY-MM-DD';
    case DayShortMonthYear = 'DD MMM YYYY';
    case ShortMonthDayYear = 'MMM DD, YYYY';

    /**
     * The PHP date() format used when rendering documents and emails.
     */
    public function php(): string
    {
        return match ($this) {
            self::DayMonthYear => 'd/m/Y',
            self::MonthDayYear => 'm/d/Y',
            self::Iso => 'Y-m-d',
            self::DayShortMonthYear => 'd M Y',
            self::ShortMonthDayYear => 'M d, Y',
        };
    }

    public static function timePhp(string $timeFormat): string
    {
        return $timeFormat === '12h' ? 'g:i A' : 'H:i';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

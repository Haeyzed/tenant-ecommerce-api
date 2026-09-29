<?php

declare(strict_types=1);

namespace App\Modules\Exports\Support;

use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use RuntimeException;

/**
 * One export as an XLSX workbook (Laravel Excel, D-134). Rows stream from
 * the definition's generator. Every text cell is written as an explicit
 * string, so exported data can never become a formula (spreadsheet
 * injection); plain numbers are written as numbers.
 */
final class SpreadsheetExport implements FromGenerator, WithCustomValueBinder, WithHeadings
{
    /** A workbook is built in memory: larger exports use CSV. */
    public const int MAX_ROWS = 50000;

    private int $rows = 0;

    /**
     * @param  array<string, string>  $columns  row key => header label
     * @param  iterable<array<string, mixed>>  $source
     */
    public function __construct(private readonly array $columns, private readonly iterable $source) {}

    public function headings(): array
    {
        return array_values($this->columns);
    }

    public function generator(): Generator
    {
        foreach ($this->source as $row) {
            if (++$this->rows > self::MAX_ROWS) {
                throw new RuntimeException('XLSX exports hold at most '.number_format(self::MAX_ROWS).' rows. Export as CSV instead.');
            }

            $values = [];

            foreach (array_keys($this->columns) as $key) {
                $values[] = $row[$key] ?? null;
            }

            yield $values;
        }
    }

    public function rowCount(): int
    {
        return min($this->rows, self::MAX_ROWS);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        match (true) {
            $value === null => $cell->setValueExplicit(null, DataType::TYPE_NULL),
            is_bool($value) => $cell->setValueExplicit($value, DataType::TYPE_BOOL),
            is_int($value), is_float($value) => $cell->setValueExplicit($value, DataType::TYPE_NUMERIC),
            // Decimal strings (money, quantities) as numbers; codes with leading zeros stay text.
            is_string($value) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $value) === 1 => $cell->setValueExplicit((float) $value, DataType::TYPE_NUMERIC),
            default => $cell->setValueExplicit(is_scalar($value) ? (string) $value : (string) json_encode($value), DataType::TYPE_STRING),
        };

        return true;
    }
}

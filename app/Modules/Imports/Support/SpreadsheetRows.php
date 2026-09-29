<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use Closure;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

/**
 * Reads an uploaded CSV or XLSX row by row (Laravel Excel, D-135): the
 * first row holds the headings (slugged, e.g. "Parent slug" →
 * parent_slug), the sheet is read in chunks so memory stays flat, and
 * formulas are read as their text, never calculated.
 */
final class SpreadsheetRows implements OnEachRow, SkipsEmptyRows, WithChunkReading, WithHeadingRow
{
    public const int CHUNK = 500;

    /**
     * @param  Closure(int, array<string, mixed>): void  $each  row index (the heading is 1) and values
     */
    public function __construct(private readonly Closure $each) {}

    public function onRow(Row $row): void
    {
        ($this->each)($row->getIndex(), $row->toArray(null, false, false));
    }

    public function chunkSize(): int
    {
        return self::CHUNK;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Exports\Support;

use App\Modules\Documents\Contracts\DocumentRenderer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

/**
 * Writes one export to a temporary local file (spec §19.4, D-134), shared
 * by tenant and platform exports. CSV and JSON stream row by row, so memory
 * stays flat at any size; XLSX goes through Laravel Excel (at most
 * SpreadsheetExport::MAX_ROWS); a PDF holds at most PDF_MAX_ROWS rows and
 * says so. The caller moves the file into the media library.
 */
final readonly class ExportWriter
{
    /** Rows a PDF holds at most; the file notes when more were left out. */
    public const int PDF_MAX_ROWS = 2000;

    public const array FORMATS = ['csv', 'xlsx', 'json', 'pdf'];

    public function __construct(private DocumentRenderer $documents) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{path: string, rows: int}
     */
    public function write(ExportDefinition $definition, array $parameters, string $format): array
    {
        $columns = $definition->columns($parameters);
        $rows = ($definition->rows)($parameters);

        return $format === 'xlsx' ? $this->xlsx($columns, $rows) : $this->stream($definition, $columns, $rows, $parameters, $format);
    }

    /**
     * Formulas are neutralised, so a spreadsheet never executes exported
     * text (CSV injection).
     */
    public static function csvCell(mixed $value): string
    {
        $text = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }

    /**
     * @param  array<string, string>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{path: string, rows: int}
     */
    private function xlsx(array $columns, iterable $rows): array
    {
        $relative = 'export-tmp/'.Str::uuid().'.xlsx';
        $export = new SpreadsheetExport($columns, $rows);

        try {
            Excel::store($export, $relative, 'local', ExcelWriter::XLSX);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($relative);

            throw $e;
        }

        return ['path' => Storage::disk('local')->path($relative), 'rows' => $export->rowCount()];
    }

    /**
     * @param  array<string, string>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $parameters
     * @return array{path: string, rows: int}
     */
    private function stream(ExportDefinition $definition, array $columns, iterable $rows, array $parameters, string $format): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'export');
        $handle = fopen($path, 'wb') ?: throw new RuntimeException('Could not open the export file.');
        $count = 0;
        $pdfRows = [];
        $truncated = false;

        try {
            if ($format === 'csv') {
                fputcsv($handle, array_values($columns), escape: '');
            } elseif ($format === 'json') {
                fwrite($handle, '[');
            }

            foreach ($rows as $row) {
                $values = [];

                foreach (array_keys($columns) as $key) {
                    $values[$key] = $row[$key] ?? null;
                }

                if ($format === 'csv') {
                    fputcsv($handle, array_map(self::csvCell(...), array_values($values)), escape: '');
                } elseif ($format === 'json') {
                    fwrite($handle, ($count > 0 ? ',' : '').json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                } elseif ($count < self::PDF_MAX_ROWS) {
                    $pdfRows[] = array_map(static fn (mixed $v): string => is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v), $values);
                } else {
                    // A PDF is for reading, not a data dump: CSV carries every row.
                    $truncated = true;

                    break;
                }

                $count++;
            }

            if ($format === 'json') {
                fwrite($handle, ']');
            }

            if ($format === 'pdf') {
                fwrite($handle, $this->documents->pdf('exports.table', [
                    'title' => $definition->label,
                    'columns' => array_values($columns),
                    'rows' => $pdfRows,
                    'parameters' => $parameters,
                    'generatedAt' => now(),
                    'truncated' => $truncated ? self::PDF_MAX_ROWS : null,
                ], 'a4', count($columns) > 5 ? 'landscape' : 'portrait'));
            }

            fclose($handle);
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($path);

            throw $e;
        }

        return ['path' => $path, 'rows' => $count];
    }
}

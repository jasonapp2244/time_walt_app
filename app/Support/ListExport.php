<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads an admin list as Excel (.xlsx) or PDF, using the same filtered
 * query the page shows. Excel streams every row; PDF stops at PDF_ROW_LIMIT
 * because a PDF of tens of thousands of rows is neither printable nor fast
 * to render, and says so on the page.
 */
class ListExport
{
    public const FORMATS = ['xlsx', 'pdf'];

    public const PDF_ROW_LIMIT = 1000;

    /**
     * @param  array<int, string>  $columns  heading per column
     * @param  callable(mixed): array<int, scalar|null>  $row  maps one model to its cells
     * @param  array<string, string>  $filters  label => value, printed on the PDF
     */
    public static function download(string $format, string $title, Builder $query, array $columns, callable $row, array $filters = []): Response
    {
        $filename = 'time-vault-'.Str::slug($title).'-'.now(config('app.admin_timezone', 'UTC'))->format('Y-m-d-His');

        return $format === 'pdf'
            ? static::pdf($filename, $title, $query, $columns, $row, $filters)
            : static::xlsx($filename, $query, $columns, $row);
    }

    private static function xlsx(string $filename, Builder $query, array $columns, callable $row): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'tv-export-');

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($columns, (new Style)->setFontBold()));

        foreach ($query->lazy(500) as $model) {
            $writer->addRow(new Row(array_map(static::xlsxCell(...), $row($model))));
        }

        $writer->close();

        return response()->download($path, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private static function pdf(string $filename, string $title, Builder $query, array $columns, callable $row, array $filters): Response
    {
        $total = (clone $query)->toBase()->getCountForPagination();

        $rows = $query->limit(self::PDF_ROW_LIMIT)->get()
            ->map(fn ($model) => array_map(static::cell(...), $row($model)))
            ->all();

        return Pdf::loadView('admin.exports.table', [
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'total' => $total,
            'limit' => self::PDF_ROW_LIMIT,
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'generatedAt' => now(config('app.admin_timezone', 'UTC'))->format('d M Y H:i T'),
        ])
            ->setPaper('a4', count($columns) > 6 ? 'landscape' : 'portrait')
            // Embed only the glyphs used; the full DejaVu font makes every PDF ~900 KB.
            ->setOption('isFontSubsettingEnabled', true)
            ->download($filename.'.pdf');
    }

    /**
     * Text is always written as a text cell. OpenSpout would otherwise turn a
     * value starting with "=" into a live formula, so a user could put
     * =HYPERLINK(...) in their name or feedback and have it run in the
     * admin's Excel.
     */
    private static function xlsxCell(mixed $value): Cell
    {
        $value = static::cell($value);

        return is_string($value) && $value !== ''
            ? new StringCell($value, null)
            : Cell::fromValue($value);
    }

    /**
     * Spreadsheet-safe cell: numbers stay numbers, everything else is a string.
     */
    private static function cell(mixed $value): string|int|float|null
    {
        return match (true) {
            $value === null => null,
            is_int($value), is_float($value) => $value,
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof \DateTimeInterface => \Illuminate\Support\Carbon::instance($value)
                ->setTimezone(config('app.admin_timezone', 'UTC'))
                ->format('Y-m-d H:i'),
            default => (string) $value,
        };
    }
}

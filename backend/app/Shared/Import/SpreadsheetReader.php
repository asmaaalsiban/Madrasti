<?php

declare(strict_types=1);

namespace App\Shared\Import;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/** Reads the first sheet of an .xlsx or .csv file into a header row and rows of text values (empty rows are skipped). */
class SpreadsheetReader
{
    public const MAX_ROWS = 1000;

    /** @return array{headers: list<string>, rows: list<array{line: int, cells: list<?string>}>} */
    public function read(UploadedFile $file): array
    {
        $csv = in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true);
        $reader = $csv ? new CsvReader() : new XlsxReader();
        $reader->open($file->getRealPath());

        $headers = [];
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $line = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = array_map(fn ($cell) => $this->text($cell->getValue()), $row->getCells());
                    if ($line === 1) {
                        $headers = array_map(fn ($c) => (string) $c, $cells);

                        continue;
                    }
                    if (array_filter($cells, fn ($c) => $c !== null) === []) {
                        continue;
                    }
                    if (count($rows) >= self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => [__('import.too_many_rows', ['max' => self::MAX_ROWS])]]);
                    }
                    $rows[] = ['line' => $line, 'cells' => array_values($cells)];
                }
                break; // only the first sheet
            }
        } finally {
            $reader->close();
        }

        if ($headers === [] || $rows === []) {
            throw ValidationException::withMessages(['file' => [__('import.empty_file')]]);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** A cell as the text a person typed: dates as Y-m-d, whole numbers without ".0", blanks as null. */
    private function text(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (int) $value;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

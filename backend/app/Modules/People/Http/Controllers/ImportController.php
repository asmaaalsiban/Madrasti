<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\People\Import\Importer;
use App\Modules\People\Import\StaffImporter;
use App\Modules\People\Import\StudentImporter;
use App\Shared\Export\XlsxExport;
use App\Shared\Import\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk import of students and staff from an Excel/CSV file. Two steps with the same file: a preview that checks every
 * row and creates nothing, then the import, which creates the valid rows and reports the ones it skipped.
 */
class ImportController extends Controller
{
    private function importer(string $kind): Importer
    {
        return match ($kind) {
            'students' => app(StudentImporter::class),
            'staff' => app(StaffImporter::class),
            default => abort(404),
        };
    }

    /** An empty file with the right columns (in the interface language) and one example row. */
    public function template(string $kind, XlsxExport $xlsx): BinaryFileResponse
    {
        $columns = $this->importer($kind)->columns();
        $lang = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return $xlsx->download(__("import.template_file_$kind"), __("import.template_sheet_$kind"),
            array_map(fn ($c) => $c[$lang], array_values($columns)),
            [array_map(fn ($c) => $c['example'], array_values($columns))]);
    }

    public function store(Request $request, string $kind, SpreadsheetReader $reader): JsonResponse
    {
        $importer = $this->importer($kind);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:2048'], 'confirm' => ['sometimes', 'boolean']]);
        $confirm = $request->boolean('confirm');

        $sheet = $reader->read($request->file('file'));
        $map = $this->columnMap($importer, $sheet['headers']);

        $valid = [];
        $problems = [];
        foreach ($sheet['rows'] as $line) {
            $row = [];
            foreach ($map as $index => $key) {
                $row[$key] = $line['cells'][$index] ?? null;
            }
            [$data, $errors] = $importer->check($row);
            $data === null ? $problems[] = ['line' => $line['line'], 'errors' => $errors] : $valid[] = ['line' => $line['line'], 'data' => $data];
        }

        $created = [];
        if ($confirm) {
            foreach ($valid as $item) {
                try {
                    $created[] = $importer->create($item['data']);
                } catch (ValidationException $e) {
                    $problems[] = ['line' => $item['line'], 'errors' => collect($e->errors())->flatten()->all()];
                }
            }
        }

        return response()->json(['data' => [
            'total' => count($sheet['rows']),
            'valid' => count($valid),
            'created' => $confirm ? $created : null,
            'problems' => array_slice($problems, 0, 200),
            'problem_count' => count($problems),
        ]]);
    }

    /**
     * Which file column holds which field: a header may be written as the key, or its English or Arabic label.
     *
     * @param  list<string>  $headers
     * @return array<int, string> file column index => field key
     */
    private function columnMap(Importer $importer, array $headers): array
    {
        $norm = fn (string $s) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s));
        $names = [];
        foreach ($importer->columns() as $key => $column) {
            foreach ([$key, $column['en'], $column['ar']] as $name) {
                $names[$norm($name)] = $key;
            }
        }

        $map = [];
        foreach ($headers as $index => $header) {
            if (isset($names[$norm($header)]) && ! in_array($names[$norm($header)], $map, true)) {
                $map[$index] = $names[$norm($header)];
            }
        }

        $missing = collect($importer->columns())->filter(fn ($c, $key) => $c['required'] && ! in_array($key, $map, true))->map(fn ($c) => $c[app()->getLocale() === 'ar' ? 'ar' : 'en'])->values()->all();
        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => [__('import.missing_columns', ['columns' => implode(', ', $missing)])]]);
        }

        return $map;
    }
}

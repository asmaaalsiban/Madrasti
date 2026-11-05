<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Import\ClassroomImporter;
use App\Modules\Academic\Import\GradeLevelImporter;
use App\Modules\Academic\Import\SubjectImporter;
use App\Modules\Academic\Import\TeachingAssignmentImporter;
use App\Modules\Grading\Import\MarksImporter;
use App\Modules\People\Import\GuardianImporter;
use App\Modules\People\Import\Importer;
use App\Modules\People\Import\StaffImporter;
use App\Modules\People\Import\StudentImporter;
use App\Shared\Enums\StaffType;
use App\Shared\Export\XlsxExport;
use App\Shared\Import\ColumnMatcher;
use App\Shared\Import\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk import of students and staff from an Excel/CSV file. Two steps with the same file: a preview that checks every
 * row and creates nothing, then the import, which creates the valid rows and reports the ones it skipped.
 */
class ImportController extends Controller
{
    /** Every kind of file the school can bring, in the order they depend on each other (people last needs the structure first). */
    public const KINDS = [
        'grade_levels' => GradeLevelImporter::class,
        'subjects' => SubjectImporter::class,
        'staff' => StaffImporter::class,
        'classrooms' => ClassroomImporter::class,
        'teaching' => TeachingAssignmentImporter::class,
        'students' => StudentImporter::class,
        'marks' => MarksImporter::class,
        'guardians' => GuardianImporter::class,
    ];

    private function importer(string $kind): Importer
    {
        return isset(self::KINDS[$kind]) ? app(self::KINDS[$kind]) : abort(404);
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

    /**
     * Reads the school's own file and says which column looks like which field (the admin can change it), with the first
     * rows as a sample. Creates nothing.
     */
    public function analyze(Request $request, string $kind, SpreadsheetReader $reader, ColumnMatcher $matcher): JsonResponse
    {
        $importer = $this->importer($kind);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:2048']]);
        $sheet = $reader->read($request->file('file'));
        $sample = array_map(fn ($r) => array_pad($r['cells'], count($sheet['headers']), null), array_slice($sheet['rows'], 0, 5));
        $lang = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return response()->json(['data' => [
            'headers' => $sheet['headers'],
            'sample' => $sample,
            'row_count' => count($sheet['rows']),
            'suggested' => $matcher->suggest($sheet['headers'], $sample, $importer->columns(), $importer->genericName()),
            'fields' => collect($importer->columns())->map(fn ($c, $key) => ['key' => $key, 'label' => $c[$lang], 'required' => $c['required']])->values(),
            'positions' => $kind === 'staff' ? array_column(StaffType::cases(), 'value') : null,
        ]]);
    }

    public function store(Request $request, string $kind, SpreadsheetReader $reader, ColumnMatcher $matcher): JsonResponse
    {
        $importer = $this->importer($kind);
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:2048'], 'confirm' => ['sometimes', 'boolean'],
            'mapping' => ['nullable', 'json'], 'default_staff_type' => ['nullable', Rule::enum(StaffType::class)],
        ]);
        $confirm = $request->boolean('confirm');
        $importer->withDefaults(['staff_type' => $request->input('default_staff_type')]);

        $sheet = $reader->read($request->file('file'));
        $map = $this->columnMap($importer, $matcher, $sheet, $request->input('mapping'));

        $valid = [];
        $problems = [];
        foreach ($sheet['rows'] as $line) {
            $row = array_fill_keys(array_keys($importer->columns()), null);
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
            'created_columns' => $confirm && $created !== [] ? array_keys($created[0]) : [],
            'problems' => array_slice($problems, 0, 200),
            'problem_count' => count($problems),
        ]]);
    }

    /**
     * Which file column holds which field: the admin's mapping when sent, else the matcher's guess.
     *
     * @param  array{headers: list<string>, rows: list<array{line: int, cells: list<?string>}>}  $sheet
     * @return array<int, string> file column index => field key
     */
    private function columnMap(Importer $importer, ColumnMatcher $matcher, array $sheet, ?string $mapping): array
    {
        $guess = $matcher->suggest($sheet['headers'], array_map(fn ($r) => $r['cells'], array_slice($sheet['rows'], 0, 5)), $importer->columns(), $importer->genericName());
        $chosen = $mapping !== null ? (json_decode($mapping, true) ?: []) : $guess;

        $map = [];
        foreach ($chosen as $index => $key) {
            // Only real fields, each used once, only columns that exist in the file.
            if (is_string($key) && isset($importer->columns()[$key]) && isset($sheet['headers'][(int) $index]) && ! in_array($key, $map, true)) {
                $map[(int) $index] = $key;
            }
        }

        $missing = $importer->missingFields(array_values($map));
        if ($missing !== []) {
            $lang = app()->getLocale() === 'ar' ? 'ar' : 'en';
            throw ValidationException::withMessages(['file' => [__('import.missing_columns', ['columns' => implode(', ', array_map(fn ($k) => $importer->columns()[$k][$lang], $missing))])]]);
        }

        return $map;
    }
}

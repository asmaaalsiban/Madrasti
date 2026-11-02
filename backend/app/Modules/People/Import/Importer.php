<?php

declare(strict_types=1);

namespace App\Modules\People\Import;

/**
 * One kind of spreadsheet import (students, staff). It names the columns, checks one row at a time and creates it.
 * A row is checked with the same rules as the matching form, so a file can never create what the form would refuse.
 */
abstract class Importer
{
    /** @var array<string, true> values already seen in this file (to catch the same student number or email twice) */
    protected array $seen = [];

    /**
     * Column key => labels in both languages, whether it is required, and an example for the template.
     *
     * @return array<string, array{en: string, ar: string, required: bool, example: string}>
     */
    abstract public function columns(): array;

    /**
     * Checks one row (keys are the column keys, values text or null).
     *
     * @param  array<string, ?string>  $row
     * @return array{0: ?array<string, mixed>, 1: list<string>} the data ready to create (null when invalid) and the problems
     */
    abstract public function check(array $row): array;

    /**
     * Creates one checked row; returns what to show the admin about it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    abstract public function create(array $data): array;

    /** The name of the screen/table: "students" or "staff". */
    abstract public function kind(): string;

    /** Flattens validation messages into plain strings. */
    protected function messages(\Illuminate\Contracts\Validation\Validator $validator): array
    {
        return collect($validator->errors()->all())->unique()->values()->all();
    }

    /** Remembers a value; true when it was already seen in this file. */
    protected function repeated(string $namespace, string $value): bool
    {
        $key = $namespace.':'.mb_strtolower($value);
        if (isset($this->seen[$key])) {
            return true;
        }
        $this->seen[$key] = true;

        return false;
    }
}

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
     * @return array<string, array{en: string, ar: string, required: bool, example: string, aliases: list<string>}>
     */
    abstract public function columns(): array;

    /**
     * A plain "name" header that may mean one of two fields (decided by the script of its values), or null.
     *
     * @return ?array{keys: array{0: string, 1: string}, aliases: list<string>}
     */
    public function genericName(): ?array
    {
        return null;
    }

    /** Fields that must be present in the mapping (a field may be satisfied by a fallback, so not just the required flag). */
    abstract public function missingFields(array $mapped): array;

    /**
     * Settings from the admin that fill blanks (e.g. the position of staff whose file has no such column).
     *
     * @param  array<string, mixed>  $defaults
     */
    public function withDefaults(array $defaults): static
    {
        return $this;
    }

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

    /** True when the value was already seen in this file (without remembering it). */
    protected function wasSeen(string $namespace, string $value): bool
    {
        return isset($this->seen[$namespace.':'.mb_strtolower($value)]);
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

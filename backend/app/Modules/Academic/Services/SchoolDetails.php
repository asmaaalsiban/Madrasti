<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

use Illuminate\Support\Facades\DB;

/** The details the school's official forms ask for: the directorate of education and the area. */
class SchoolDetails
{
    public const DEFAULTS = ['directorate' => '', 'district' => ''];

    /** @return array{directorate: string, district: string} */
    public function get(): array
    {
        $v = DB::table('school_settings')->where('key', 'school_details')->value('value');

        return array_merge(self::DEFAULTS, array_intersect_key($v ? (array) json_decode((string) $v, true) : [], self::DEFAULTS));
    }

    /** @param array<string, mixed> $data */
    public function save(array $data): void
    {
        DB::table('school_settings')->updateOrInsert(['key' => 'school_details'], ['value' => json_encode(array_map(fn ($v) => trim((string) $v), array_intersect_key($data, self::DEFAULTS))), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** What a form prints: the value, or a dotted line to fill in by hand. */
    public function line(string $key): string
    {
        return $this->get()[$key] !== '' ? $this->get()[$key] : '....................';
    }
}
